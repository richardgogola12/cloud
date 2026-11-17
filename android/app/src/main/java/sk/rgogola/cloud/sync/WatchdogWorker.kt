package sk.rgogola.cloud.sync

import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.update.Updater
import java.util.concurrent.TimeUnit

/**
 * Strážca: každých 15 minút overí, že synchronizačná služba beží (inak ju spustí,
 * prípadne synchronizuje priamo), a raz denne skontroluje aktualizáciu aplikácie.
 */
class WatchdogWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {

    override suspend fun doWork(): Result {
        val ctx = applicationContext
        val prefs = Prefs(ctx)
        if (SyncService.shouldRun(ctx) && !SyncService.running) {
            val started = try {
                ContextCompat.startForegroundService(ctx, Intent(ctx, SyncService::class.java)); true
            } catch (e: Exception) { false }
            if (!started) SyncEngine.run(ctx)
        }
        NotifPoller.poll(ctx)
        SyncWidget.updateAll(ctx)
        if (prefs.loggedIn && System.currentTimeMillis() - prefs.lastUpdateCheck > TimeUnit.HOURS.toMillis(20)) {
            prefs.lastUpdateCheck = System.currentTimeMillis()
            try {
                val rel = Updater.latest()
                if (rel != null && Updater.isNewer(rel) && rel.versionCode != prefs.skippedVersion) Notif.update(ctx, rel)
            } catch (_: Exception) {}
        }
        return Result.success()
    }

    companion object {
        fun schedule(ctx: Context) {
            val req = PeriodicWorkRequestBuilder<WatchdogWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork("cloud-watchdog", ExistingPeriodicWorkPolicy.KEEP, req)
        }

        fun runOnce(ctx: Context) {
            WorkManager.getInstance(ctx).enqueueUniqueWork("cloud-sync-once", ExistingWorkPolicy.KEEP,
                OneTimeWorkRequestBuilder<WatchdogWorker>().build())
        }
    }
}
