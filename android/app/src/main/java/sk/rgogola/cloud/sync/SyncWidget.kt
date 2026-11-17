package sk.rgogola.cloud.sync

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.view.View
import android.widget.RemoteViews
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.R
import sk.rgogola.cloud.ui.MainActivity
import sk.rgogola.cloud.ui.SyncActivity
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/** Widget na ploche: stav synchronizácie, detaily a tlačidlá (synchronizovať, pozastaviť/spustiť, otvoriť). */
class SyncWidget : AppWidgetProvider() {

    companion object {
        const val ACTION_TOGGLE = "sk.rgogola.cloud.WIDGET_TOGGLE"

        fun updateAll(ctx: Context) {
            val mgr = AppWidgetManager.getInstance(ctx) ?: return
            val ids = mgr.getAppWidgetIds(ComponentName(ctx, SyncWidget::class.java))
            if (ids.isEmpty()) return
            val rv = build(ctx)
            ids.forEach { mgr.updateAppWidget(it, rv) }
        }

        private fun build(ctx: Context): RemoteViews {
            val prefs = Prefs(ctx)
            val db = SyncDb.get(ctx)
            val rv = RemoteViews(ctx.packageName, R.layout.widget_sync)
            val pairs = db.pairs(onlyEnabled = true)

            val (state, stateBg) = when {
                !prefs.loggedIn -> "Neprihlásený" to R.drawable.widget_chip_err
                prefs.authError -> "Prihlás sa" to R.drawable.widget_chip_err
                !prefs.syncEnabled -> "Pozastavené" to R.drawable.widget_chip_warn
                pairs.isEmpty() -> "Bez zložiek" to R.drawable.widget_chip_warn
                SyncEngine.busy -> "Synchronizujem" to R.drawable.widget_chip_ok
                SyncService.running -> "Aktívne" to R.drawable.widget_chip_ok
                else -> "Čaká" to R.drawable.widget_chip_warn
            }
            rv.setTextViewText(R.id.w_state, state)
            rv.setInt(R.id.w_state, "setBackgroundResource", stateBg)

            val status = when {
                !prefs.loggedIn -> "Otvor aplikáciu a prihlás sa."
                pairs.isEmpty() -> "Pridaj zložku, ktorú chceš zálohovať."
                else -> SyncEngine.status
            }
            rv.setTextViewText(R.id.w_status, status)

            val pct = SyncEngine.progressPct
            if (SyncEngine.busy && pct != null) {
                rv.setViewVisibility(R.id.w_progress, View.VISIBLE)
                rv.setProgressBar(R.id.w_progress, 100, pct, false)
            } else rv.setViewVisibility(R.id.w_progress, View.GONE)

            val pending = db.totalPending()
            val errors = db.totalErrors()
            val n = pairs.size
            rv.setTextViewText(R.id.w_details,
                "$n ${if (n == 1) "zložka" else if (n in 2..4) "zložky" else "zložiek"}" +
                " · čaká $pending" + (if (errors > 0) " · chyby $errors" else ""))

            val midnight = Calendar.getInstance().apply { set(Calendar.HOUR_OF_DAY, 0); set(Calendar.MINUTE, 0); set(Calendar.SECOND, 0); set(Calendar.MILLISECOND, 0) }.timeInMillis
            val last = if (prefs.lastSync > 0) SimpleDateFormat(if (prefs.lastSync >= midnight) "HH:mm" else "d.M. HH:mm", Locale.getDefault()).format(Date(prefs.lastSync)) else "–"
            rv.setTextViewText(R.id.w_last, "Posledná $last · dnes nahraté ${db.uploadedSince(midnight)}" + (if (prefs.wifiOnly) " · iba Wi-Fi" else ""))

            // tlačidlá
            val syncPi = PendingIntent.getForegroundService(ctx, 20,
                Intent(ctx, SyncService::class.java).setAction(SyncService.ACTION_SYNC_NOW),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            rv.setOnClickPendingIntent(R.id.w_sync, syncPi)

            val togglePi = PendingIntent.getBroadcast(ctx, 21,
                Intent(ctx, SyncWidget::class.java).setAction(ACTION_TOGGLE),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            rv.setOnClickPendingIntent(R.id.w_toggle, togglePi)
            rv.setTextViewText(R.id.w_toggle, if (prefs.syncEnabled) "Pozastaviť" else "Spustiť")

            val openPi = PendingIntent.getActivity(ctx, 22,
                Intent(ctx, if (prefs.loggedIn) SyncActivity::class.java else MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
            rv.setOnClickPendingIntent(R.id.w_open, openPi)
            rv.setOnClickPendingIntent(R.id.w_header, openPi)
            return rv
        }
    }

    override fun onUpdate(context: Context, mgr: AppWidgetManager, ids: IntArray) {
        val rv = build(context)
        ids.forEach { mgr.updateAppWidget(it, rv) }
    }

    override fun onReceive(context: Context, intent: Intent) {
        super.onReceive(context, intent)
        if (intent.action == ACTION_TOGGLE) {
            val prefs = Prefs(context)
            prefs.syncEnabled = !prefs.syncEnabled
            if (prefs.syncEnabled) {
                prefs.authError = false
                CloudApp.kick(context.applicationContext, syncNow = true)
            } else SyncService.stop(context)
            updateAll(context)
        }
    }
}
