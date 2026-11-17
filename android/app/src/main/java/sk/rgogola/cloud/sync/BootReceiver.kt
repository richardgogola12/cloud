package sk.rgogola.cloud.sync

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Po reštarte telefónu (a po aktualizácii aplikácie) znovu spustí synchronizáciu. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED, Intent.ACTION_MY_PACKAGE_REPLACED,
            "android.intent.action.QUICKBOOT_POWERON", "com.htc.intent.action.QUICKBOOT_POWERON" -> {
                WatchdogWorker.schedule(context)
                SyncService.start(context)
            }
        }
    }
}
