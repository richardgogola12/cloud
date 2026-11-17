package sk.rgogola.cloud

import android.app.Application
import sk.rgogola.cloud.sync.SyncService
import sk.rgogola.cloud.sync.WatchdogWorker

class CloudApp : Application() {
    override fun onCreate() {
        super.onCreate()
        sk.rgogola.cloud.net.Trust.init(this)
        Notif.createChannels(this)
        if (Prefs(this).loggedIn) {
            WatchdogWorker.schedule(this)
            sk.rgogola.cloud.sync.UploadService.resumeIfPending(this)
        }
    }

    companion object {
        /** Po prihlásení / zmene nastavení: naplánuj strážcu a spusti službu. */
        fun kick(app: android.content.Context, syncNow: Boolean = false) {
            WatchdogWorker.schedule(app)
            SyncService.start(app, syncNow)
            sk.rgogola.cloud.sync.SyncWidget.updateAll(app)
        }
    }
}
