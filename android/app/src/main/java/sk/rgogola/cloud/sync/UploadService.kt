package sk.rgogola.cloud.sync

import android.annotation.SuppressLint
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.net.wifi.WifiManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.provider.OpenableColumns
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import androidx.documentfile.provider.DocumentFile
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException

/**
 * Nahrávanie ručne vybraných súborov a zložiek na pozadí.
 * Beží ako služba v popredí – pokračuje aj po zatvorení aplikácie či zhasnutí obrazovky.
 */
class UploadService : Service() {

    companion object {
        const val NOTIF_ID = 3
        const val ACTION_CANCEL = "sk.rgogola.cloud.UPLOAD_CANCEL"
        @Volatile var running = false
            private set
        /** čas posledného dokončenia (aplikácia podľa neho obnoví zoznam) */
        @Volatile var finishedAt = 0L
            private set
        @Volatile var onFinished: (() -> Unit)? = null

        fun enqueueFiles(ctx: Context, folder: Int, uris: List<Uri>) {
            if (uris.isEmpty()) return
            UploadQueue.add(ctx, uris.map { UploadQueue.Item(it.toString(), folder, "", -1, false) })
            start(ctx)
        }

        fun enqueueTree(ctx: Context, folder: Int, tree: Uri) {
            try { ctx.contentResolver.takePersistableUriPermission(tree, Intent.FLAG_GRANT_READ_URI_PERMISSION) } catch (_: Exception) {}
            val p = Prefs(ctx)
            p.uploadTrees = p.uploadTrees + tree.toString()
            UploadQueue.add(ctx, listOf(UploadQueue.Item(tree.toString(), folder, "", 0, true)))
            start(ctx)
        }

        fun start(ctx: Context) {
            try { ContextCompat.startForegroundService(ctx, Intent(ctx, UploadService::class.java)) } catch (_: Exception) {}
        }

        /** po reštarte aplikácie/telefónu: dokonči nedokončenú frontu */
        fun resumeIfPending(ctx: Context) { if (UploadQueue.size(ctx) > 0 && !running) start(ctx) }
    }

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var job: Job? = null
    private var ok = 0
    private var unchanged = 0
    private var processed = 0
    private val errors = ArrayList<String>()
    private var wake: PowerManager.WakeLock? = null
    private var wifi: WifiManager.WifiLock? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        running = true
        startFg(Notif.upload(this, "Pripravujem nahrávanie…", null, null))
    }

    private fun startFg(n: android.app.Notification) {
        val type = if (Build.VERSION.SDK_INT >= 34) ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE else 0
        try { ServiceCompat.startForeground(this, NOTIF_ID, n, type) } catch (_: Exception) {}
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_CANCEL) {
            UploadQueue.clear(this)
            job?.cancel()
            finish(cancelled = true)
            return START_NOT_STICKY
        }
        startFg(Notif.upload(this, "Pripravujem nahrávanie…", null, null))
        if (job?.isActive != true) job = scope.launch { loop() }
        return START_STICKY
    }

    @SuppressLint("WakelockTimeout")
    private fun lock() {
        try {
            wake = getSystemService(PowerManager::class.java)?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "Cloud:upload")?.apply { setReferenceCounted(false); acquire(60 * 60 * 1000L) }
        } catch (_: Exception) {}
        try {
            @Suppress("DEPRECATION")
            wifi = applicationContext.getSystemService(WifiManager::class.java)?.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, "Cloud:upload")?.apply { setReferenceCounted(false); acquire() }
        } catch (_: Exception) {}
    }

    private fun unlock() {
        try { wake?.let { if (it.isHeld) it.release() } } catch (_: Exception) {}
        try { wifi?.let { if (it.isHeld) it.release() } } catch (_: Exception) {}
        wake = null; wifi = null
    }

    private fun online(): Boolean {
        val cm = getSystemService(ConnectivityManager::class.java) ?: return true
        val n = cm.activeNetwork ?: return false
        val c = cm.getNetworkCapabilities(n) ?: return false
        return c.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private suspend fun loop() {
        lock()
        val api = Api(Prefs(this))
        try {
            while (currentCoroutineContext().isActive) {
                currentCoroutineContext().ensureActive()
                val item = UploadQueue.first(this) ?: break
                if (item.tree) { expandTree(item); continue }
                if (!online()) {
                    Notif.updateUpload(this, "Čakám na pripojenie k internetu…", "Zostáva ${UploadQueue.size(this)}", null)
                    while (!online()) delay(5000)
                }
                wake?.acquire(60 * 60 * 1000L) // predĺženie
                val uri = Uri.parse(item.uri)
                var name = item.rel.substringAfterLast('/')
                var size = item.size
                if (name.isBlank() || size < 0) {
                    val (n, s) = meta(uri)
                    if (name.isBlank()) name = n
                    if (size < 0) size = s
                }
                val rel = item.rel.ifBlank { name }
                val left = UploadQueue.size(this)
                val title = "Nahrávam ${processed + 1} z ${processed + left}"
                Notif.updateUpload(this, title, rel, 0)
                try {
                    var lastPct = -1
                    val same = Uploader.uploadOne(api, item.folder, name, rel, size, { contentResolver.openInputStream(uri) }) { sent ->
                        val pct = if (size > 0) (sent * 100 / size).toInt() else 0
                        if (pct != lastPct && (pct - lastPct >= 5 || pct == 100)) { lastPct = pct; Notif.updateUpload(this, title, rel, pct) }
                    }
                    if (same) unchanged++ else ok++
                } catch (e: CancellationException) {
                    throw e
                } catch (e: ApiException) {
                    val msg = e.message ?: "Chyba"
                    if (e.code == 401 || msg.contains("kvóta", true) || msg.contains("Nedostatok miesta", true)) {
                        errors += msg
                        processed += UploadQueue.size(this)
                        UploadQueue.clear(this)
                        break
                    }
                    errors += "$rel: $msg"
                } catch (e: SecurityException) {
                    errors += "$rel: k súboru už nie je prístup (vyber ho znova)"
                } catch (e: Exception) {
                    errors += "$rel: ${e.message ?: e.javaClass.simpleName}"
                }
                UploadQueue.removeFirst(this)
                processed++
            }
        } finally {
            UploadQueue.flush(this)
            unlock()
        }
        if (currentCoroutineContext().isActive) finish(cancelled = false)
    }

    /** Zložku z telefónu rozbalí na jednotlivé súbory (s cestou podzložiek). */
    private fun expandTree(item: UploadQueue.Item) {
        val tree = Uri.parse(item.uri)
        val root = try { DocumentFile.fromTreeUri(this, tree)?.name } catch (_: Exception) { null } ?: "Zložka"
        Notif.updateUpload(this, "Prehľadávam zložku „$root“…", null, null)
        val files = try { SyncEngine.scanTree(this, tree) } catch (e: Exception) {
            errors += "$root: zložku sa nepodarilo prečítať (${e.message})"
            UploadQueue.removeFirst(this); return
        }
        if (files.isEmpty()) errors += "Zložka „$root“ je prázdna"
        UploadQueue.replaceFirst(this, files.map { UploadQueue.Item(it.docUri, item.folder, "$root/${it.rel}", it.size, false) })
    }

    private fun meta(uri: Uri): Pair<String, Long> {
        var name = "subor"; var size = 0L
        try {
            contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { c ->
                if (c.moveToFirst()) {
                    c.getString(0)?.let { if (it.isNotBlank()) name = it }
                    if (!c.isNull(1)) size = c.getLong(1)
                }
            }
        } catch (_: Exception) {}
        if (name == "subor") uri.lastPathSegment?.substringAfterLast('/')?.let { if (it.isNotBlank()) name = it }
        return name to size
    }

    private fun finish(cancelled: Boolean) {
        val p = Prefs(this)
        // uvoľni prístup k zložkám, ktoré sa iba jednorazovo nahrávali (nie sledované)
        if (UploadQueue.size(this) == 0) {
            val synced = SyncDb.get(this).pairs().map { it.treeUri }.toSet()
            for (t in p.uploadTrees) if (t !in synced) {
                try { contentResolver.releasePersistableUriPermission(Uri.parse(t), Intent.FLAG_GRANT_READ_URI_PERMISSION) } catch (_: Exception) {}
            }
            p.uploadTrees = emptySet()
        }
        if (processed > 0 || errors.isNotEmpty() || cancelled) {
            val title = when {
                cancelled -> "Nahrávanie zrušené"
                errors.isEmpty() -> "Nahrávanie dokončené"
                else -> "Nahrávanie dokončené s chybami"
            }
            val text = buildString {
                append("Nahraté: $ok")
                if (unchanged > 0) append(" · už boli v cloude: $unchanged")
                if (errors.isNotEmpty()) append(" · chyby: ${errors.size}")
            }
            p.uploadReport = "$title\n\n$text" + if (errors.isNotEmpty()) "\n\n" + errors.take(50).joinToString("\n") else ""
            Notif.uploadDone(this, title, text)
        }
        finishedAt = System.currentTimeMillis()
        try { onFinished?.invoke() } catch (_: Exception) {}
        ok = 0; unchanged = 0; processed = 0; errors.clear()
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    override fun onDestroy() {
        running = false
        unlock()
        scope.cancel()
        super.onDestroy()
    }
}
