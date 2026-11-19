package sk.rgogola.cloud

import android.app.Application
import sk.rgogola.cloud.sync.SyncService
import sk.rgogola.cloud.sync.WatchdogWorker

class CloudApp : Application() {
    override fun onCreate() {
        super.onCreate()
        // pád aplikácie sa zapíše a pri ďalšom otvorení sa ukáže (pomáha nájsť chybu)
        val prev = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            try {
                java.io.File(filesDir, "last_crash.txt").writeText("Cloud ${BuildConfig.VERSION_NAME} · Android ${android.os.Build.VERSION.RELEASE} · ${android.os.Build.MANUFACTURER} ${android.os.Build.MODEL}\n\n" + android.util.Log.getStackTraceString(e).take(6000))
            } catch (_: Exception) {}
            prev?.uncaughtException(t, e)
        }
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
