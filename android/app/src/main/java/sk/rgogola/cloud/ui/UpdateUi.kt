package sk.rgogola.cloud.ui

import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.progressindicator.LinearProgressIndicator
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.BuildConfig
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.update.Updater

/** Spoločné UI pre aktualizáciu aplikácie (dialóg, sťahovanie s priebehom, inštalácia). */
class UpdateUi(private val act: AppCompatActivity) {
    private var pending: Updater.Release? = null

    fun check(manual: Boolean) {
        val prefs = Prefs(act)
        act.lifecycleScope.launch {
            val rel = withContext(Dispatchers.IO) { runCatching { Updater.latest() } }
            prefs.lastUpdateCheck = System.currentTimeMillis()
            val r = rel.getOrNull()
            if (r == null) {
                if (manual) toast(rel.exceptionOrNull()?.message?.let { "Aktualizáciu sa nepodarilo overiť: $it" } ?: "Zatiaľ nie je zverejnená žiadna verzia.")
                return@launch
            }
            if (!Updater.isNewer(r)) { if (manual) toast("Máš najnovšiu verziu ${BuildConfig.VERSION_NAME}"); return@launch }
            if (!manual && r.versionCode == prefs.skippedVersion) return@launch
            if (act.isFinishing) return@launch
            MaterialAlertDialogBuilder(act)
                .setTitle("Nová verzia ${r.versionName}")
                .setMessage("Nainštalovaná: ${BuildConfig.VERSION_NAME}\n\n" + r.notes.ifBlank { "Opravy a vylepšenia." }.take(1500))
                .setPositiveButton("Aktualizovať") { _, _ -> install(r) }
                .setNegativeButton("Neskôr", null)
                .setNeutralButton("Preskočiť") { _, _ -> prefs.skippedVersion = r.versionCode }
                .show()
        }
    }

    /** Volaj z onResume – po povolení inštalácie pokračuje. */
    fun resume() {
        val r = pending ?: return
        if (Updater.canInstall(act)) { pending = null; install(r) }
    }

    private fun install(r: Updater.Release) {
        if (!Updater.canInstall(act)) {
            pending = r
            MaterialAlertDialogBuilder(act)
                .setTitle("Povolenie na inštaláciu")
                .setMessage("Aby sa aplikácia mohla aktualizovať, povoľ pre Cloud „Inštalovať neznáme aplikácie“. Potom sa vráť späť.")
                .setPositiveButton("Otvoriť nastavenia") { _, _ -> Updater.openInstallPermission(act) }
                .setNegativeButton("Zrušiť") { _, _ -> pending = null }
                .show()
            return
        }
        val bar = LinearProgressIndicator(act).apply { max = 100; isIndeterminate = true; setPadding(60, 40, 60, 20) }
        val dlg = MaterialAlertDialogBuilder(act).setTitle("Sťahujem ${r.versionName}…").setView(bar).setCancelable(false).show()
        act.lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) {
                runCatching { Updater.download(act, r) { p -> act.runOnUiThread { bar.isIndeterminate = false; bar.setProgressCompat(p, true) } } }
            }
            dlg.dismiss()
            res.onSuccess { Updater.install(act, it) }.onFailure { toast("Sťahovanie zlyhalo: ${it.message}") }
        }
    }

    private fun toast(s: String) = Toast.makeText(act, s, Toast.LENGTH_LONG).show()
}
