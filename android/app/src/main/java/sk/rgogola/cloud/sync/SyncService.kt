package sk.rgogola.cloud.sync

import android.app.Notification
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.database.ContentObserver
import android.net.ConnectivityManager
import android.net.Network
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.provider.MediaStore
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Služba v popredí: beží nepretržite, sleduje zmeny a periodicky synchronizuje. */
class SyncService : Service() {

    companion object {
        const val NOTIF_ID = 1
        const val ACTION_SYNC_NOW = "sk.rgogola.cloud.SYNC_NOW"
        @Volatile var running = false
            private set

        fun shouldRun(ctx: Context): Boolean {
            val p = Prefs(ctx)
            return p.loggedIn && p.syncEnabled && !p.authError && SyncDb.get(ctx).pairs(onlyEnabled = true).isNotEmpty()
        }

        fun start(ctx: Context, syncNow: Boolean = false) {
            if (!shouldRun(ctx)) { stop(ctx); return }
            val i = Intent(ctx, SyncService::class.java)
            if (syncNow) i.action = ACTION_SYNC_NOW
            try {
                ContextCompat.startForegroundService(ctx, i)
            } catch (e: Exception) {
                // Android nedovolil štart z pozadia → synchronizácia cez WorkManager
                WatchdogWorker.runOnce(ctx)
            }
        }

        fun stop(ctx: Context) { ctx.stopService(Intent(ctx, SyncService::class.java)) }
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val trigger = Channel<Unit>(Channel.CONFLATED)
    private var observer: ContentObserver? = null
    private var netCb: ConnectivityManager.NetworkCallback? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        running = true
        startFg(Notif.service(this, "Sledujem zložky…", null))

        observer = object : ContentObserver(Handler(Looper.getMainLooper())) {
            override fun onChange(selfChange: Boolean) { trigger.trySend(Unit) }
        }.also {
            try { contentResolver.registerContentObserver(MediaStore.Files.getContentUri("external"), true, it) } catch (_: Exception) {}
        }
        netCb = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { trigger.trySend(Unit) }
        }.also {
            try { getSystemService(ConnectivityManager::class.java)?.registerDefaultNetworkCallback(it) } catch (_: Exception) {}
        }
        scope.launch { loop() }
    }

    private fun startFg(n: Notification) {
        val type = if (Build.VERSION.SDK_INT >= 34) ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE else 0
        ServiceCompat.startForeground(this, NOTIF_ID, n, type)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startFg(Notif.service(this, idleText(), null))
        if (intent?.action == ACTION_SYNC_NOW) trigger.trySend(Unit)
        return START_STICKY
    }

    private fun idleText(): String {
        val p = Prefs(this)
        val last = if (p.lastSync > 0) SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date(p.lastSync)) else "–"
        val n = SyncDb.get(this).pairs(onlyEnabled = true).size
        return "Sledujem $n ${if (n == 1) "zložku" else if (n in 2..4) "zložky" else "zložiek"} · posledná synchronizácia $last"
    }

    private suspend fun loop() {
        trigger.trySend(Unit) // prvý beh hneď po štarte
        while (scope.isActive) {
            val prefs = Prefs(this)
            withTimeoutOrNull(prefs.intervalMin.coerceAtLeast(1) * 60_000L) { trigger.receive() }
            delay(4000) // zoskup viac zmien naraz
            while (trigger.tryReceive().isSuccess) { /* vyprázdni */ }
            if (!shouldRun(this)) { stopSelf(); return }
            SyncEngine.run(this) { text, pct -> Notif.updateService(this, text, pct) }
            Notif.updateService(this, idleText(), null)
        }
    }

    override fun onDestroy() {
        running = false
        scope.cancel()
        observer?.let { try { contentResolver.unregisterContentObserver(it) } catch (_: Exception) {} }
        netCb?.let { try { getSystemService(ConnectivityManager::class.java)?.unregisterNetworkCallback(it) } catch (_: Exception) {} }
        super.onDestroy()
    }
}
