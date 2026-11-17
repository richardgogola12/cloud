package sk.rgogola.cloud

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import sk.rgogola.cloud.sync.SyncService
import sk.rgogola.cloud.ui.MainActivity
import sk.rgogola.cloud.ui.SyncActivity
import sk.rgogola.cloud.update.Updater

object Notif {
    const val CH_SYNC = "sync"
    const val CH_ALERT = "alerts"

    fun createChannels(ctx: Context) {
        val nm = ctx.getSystemService(NotificationManager::class.java) ?: return
        nm.createNotificationChannel(NotificationChannel(CH_SYNC, ctx.getString(R.string.channel_sync), NotificationManager.IMPORTANCE_LOW).apply {
            description = "Trvalá notifikácia synchronizácie zložiek"
            setShowBadge(false)
        })
        nm.createNotificationChannel(NotificationChannel(CH_ALERT, ctx.getString(R.string.channel_alerts), NotificationManager.IMPORTANCE_DEFAULT).apply {
            description = "Chyby synchronizácie a nové verzie aplikácie"
        })
    }

    fun canPost(ctx: Context) = Build.VERSION.SDK_INT < 33 ||
        ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    private fun activityPi(ctx: Context, cls: Class<*>, req: Int, extra: String? = null): PendingIntent {
        val i = Intent(ctx, cls).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        if (extra != null) i.putExtra(extra, true)
        return PendingIntent.getActivity(ctx, req, i, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    fun service(ctx: Context, text: String, progress: Int?): Notification {
        val syncNow = PendingIntent.getForegroundService(ctx, 10,
            Intent(ctx, SyncService::class.java).setAction(SyncService.ACTION_SYNC_NOW),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        val b = NotificationCompat.Builder(ctx, CH_SYNC)
            .setSmallIcon(R.drawable.ic_stat_cloud)
            .setContentTitle("Cloud synchronizácia")
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .setContentIntent(activityPi(ctx, SyncActivity::class.java, 1))
            .addAction(0, "Synchronizovať teraz", syncNow)
        if (progress != null) b.setProgress(100, progress.coerceIn(0, 100), false)
        return b.build()
    }

    @SuppressLint("MissingPermission")
    fun updateService(ctx: Context, text: String, progress: Int?) {
        if (!canPost(ctx)) return
        try { NotificationManagerCompat.from(ctx).notify(SyncService.NOTIF_ID, service(ctx, text, progress)) } catch (_: Exception) {}
    }

    @SuppressLint("MissingPermission")
    fun alert(ctx: Context, id: Int, title: String, text: String, target: Class<*> = SyncActivity::class.java, extra: String? = null) {
        if (!canPost(ctx)) return
        val n = NotificationCompat.Builder(ctx, CH_ALERT)
            .setSmallIcon(R.drawable.ic_stat_cloud)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setAutoCancel(true)
            .setContentIntent(activityPi(ctx, target, 100 + id, extra))
            .build()
        try { NotificationManagerCompat.from(ctx).notify(id, n) } catch (_: Exception) {}
    }

    fun authError(ctx: Context) =
        alert(ctx, 2, "Cloud: prihlás sa znova", "Prihlásenie v aplikácii vypršalo alebo bolo zrušené. Synchronizácia je pozastavená.", MainActivity::class.java)

    fun update(ctx: Context, rel: Updater.Release) =
        alert(ctx, 4, "Nová verzia aplikácie Cloud", "Je dostupná verzia ${rel.versionName}. Ťukni pre aktualizáciu.", MainActivity::class.java, MainActivity.EXTRA_CHECK_UPDATE)
}
