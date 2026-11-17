package sk.rgogola.cloud.ui

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.view.View
import android.view.inputmethod.EditorInfo
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.chip.Chip
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.BuildConfig
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.R
import sk.rgogola.cloud.databinding.ActivityServerBinding
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException
import sk.rgogola.cloud.net.Trust
import sk.rgogola.cloud.sync.SyncService
import javax.net.ssl.SSLException

/**
 * Nastavenie adresy servera – napr. verejná doména alebo http(s)://192.168.1.50/cloud v domácej sieti.
 * Výsledok RESULT_OK = adresa sa zmenila (aplikácia sa znova načíta).
 */
class ServerActivity : AppCompatActivity() {

    companion object {
        /** z prihlasovacej obrazovky – iba uložiť adresu, bez overovania prihlásenia */
        const val EXTRA_FROM_LOGIN = "from_login"

        /** doplní https://, odstráni koncové lomky a názov stránky */
        fun normalize(input: String): String {
            var s = input.trim().replace(" ", "")
            if (s.isEmpty()) return s
            if (!Regex("^https?://", RegexOption.IGNORE_CASE).containsMatchIn(s)) s = "https://$s"
            s = s.replace(Regex("^(https?)://", RegexOption.IGNORE_CASE)) { it.value.lowercase() }
            s = s.substringBefore('#').substringBefore('?').trimEnd('/')
            s = s.replace(Regex("/(index|login|app|api)\\.php$", RegexOption.IGNORE_CASE), "")
            return s.trimEnd('/')
        }
    }

    private lateinit var b: ActivityServerBinding
    private val prefs by lazy { Prefs(this) }
    private val fromLogin by lazy { intent.getBooleanExtra(EXTRA_FROM_LOGIN, false) }
    private var busy = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityServerBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        b.toolbar.setNavigationOnClickListener { finish() }

        b.current.text = prefs.server
        b.url.setText(prefs.server)
        b.url.setOnEditorActionListener { _, id, _ -> if (id == EditorInfo.IME_ACTION_GO) { save(); true } else false }
        b.testBtn.setOnClickListener { test(save = false) }
        b.saveBtn.setOnClickListener { save() }
        b.defaultBtn.setOnClickListener { b.url.setText(BuildConfig.DEFAULT_SERVER); test(save = false) }
        b.forgetCertsBtn.setOnClickListener {
            MaterialAlertDialogBuilder(this).setTitle("Zabudnúť certifikáty?")
                .setMessage("Vlastné certifikáty serverov, ktoré si potvrdil(a), bude treba pri ďalšom pripojení potvrdiť znova.")
                .setPositiveButton("Zabudnúť") { _, _ -> Trust.clear(); renderCerts() }
                .setNegativeButton("Zrušiť", null).show()
        }
        renderRecent()
        renderCerts()
    }

    private fun renderRecent() {
        b.recent.removeAllViews()
        val list = (listOf(prefs.server) + prefs.serverHistory + BuildConfig.DEFAULT_SERVER).distinct()
        if (list.size <= 1) { b.recent.visibility = View.GONE; return }
        b.recent.visibility = View.VISIBLE
        for (s in list) {
            val c = Chip(this)
            c.text = s.removePrefix("https://").let { if (s.startsWith("http://")) "⚠ $it" else it }
            c.setOnClickListener { b.url.setText(s); b.url.setSelection(s.length); showStatus(null, false) }
            b.recent.addView(c)
        }
    }

    @SuppressLint("SetTextI18n")
    private fun renderCerts() {
        val n = Trust.trusted().size
        b.certInfo.text = if (n == 0) "Používajú sa iba certifikáty, ktorým dôveruje Android." else "Potvrdené vlastné certifikáty: $n"
        b.forgetCertsBtn.visibility = if (n == 0) View.GONE else View.VISIBLE
    }

    private fun showStatus(msg: String?, ok: Boolean) {
        if (msg == null) { b.status.visibility = View.GONE; return }
        b.status.visibility = View.VISIBLE
        b.status.text = msg
        b.status.background = GradientDrawable().apply {
            cornerRadius = 24f
            setColor(if (ok) 0x1A188038 else 0x1AD93025)
        }
        b.status.setTextColor(ContextCompat.getColor(this, if (ok) R.color.ok else R.color.err))
    }

    private fun setBusy(on: Boolean) {
        busy = on
        b.progress.visibility = if (on) View.VISIBLE else View.INVISIBLE
        b.testBtn.isEnabled = !on
        b.saveBtn.isEnabled = !on
    }

    private fun save() = test(save = true)

    /** Overí, či na adrese beží Cloud; pri vlastnom certifikáte ponúkne jeho potvrdenie. */
    private fun test(save: Boolean) {
        if (busy) return
        val url = normalize(b.url.text?.toString().orEmpty())
        if (url.isEmpty()) { b.urlLayout.error = "Zadaj adresu servera"; return }
        if (!Regex("^https?://[^/\\s]+").containsMatchIn(url)) { b.urlLayout.error = "Neplatná adresa"; return }
        b.urlLayout.error = null
        b.url.setText(url)
        setBusy(true)
        showStatus(null, false)
        lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) { runCatching { Api(prefs).info(url) } }
            res.onSuccess { j ->
                val name = j.optString("name", "Cloud")
                val ver = j.optString("version")
                val msg = StringBuilder("✓ Server „$name“ odpovedá")
                if (ver.isNotBlank()) msg.append(" · verzia $ver")
                if (j.optBoolean("old")) msg.append("\nServer je starší – pre plnú podporu ho aktualizuj (Administrácia → Aktualizácia).")
                if (url.startsWith("http://")) msg.append("\n⚠ Spojenie nie je šifrované (http). Používaj ho iba v domácej sieti.")
                showStatus(msg.toString(), true)
                if (save) apply(url) else setBusy(false)
            }
            res.onFailure { e ->
                setBusy(false)
                if (url.startsWith("https://") && isCertProblem(e)) offerTrust(url, save)
                else showStatus("✗ " + describeError(e, url), false)
            }
        }
    }

    private fun isCertProblem(e: Throwable): Boolean {
        var t: Throwable? = e
        while (t != null) { if (t is SSLException || t is java.security.cert.CertificateException) return true; t = t.cause }
        return false
    }

    private fun describeError(e: Throwable, url: String): String = when (e) {
        is ApiException -> e.message ?: "Chyba servera"
        is java.net.UnknownHostException -> "Adresa sa nenašla. Skontroluj ju, prípadne či si v správnej sieti (Wi-Fi)."
        is java.net.ConnectException -> "Server neodpovedá na ${android.net.Uri.parse(url).host}. Beží a si v rovnakej sieti?" +
            if (url.startsWith("https://")) " Ak server nemá https, skús http://." else ""
        is java.net.SocketTimeoutException -> "Server neodpovedal včas."
        else -> e.message ?: e.javaClass.simpleName
    }

    private fun offerTrust(url: String, save: Boolean) {
        setBusy(true)
        lifecycleScope.launch {
            val cert = withContext(Dispatchers.IO) { runCatching { Trust.peekCertificate("$url/app.php?action=info") }.getOrNull() }
            setBusy(false)
            if (cert == null) { showStatus("✗ Zabezpečené spojenie sa nepodarilo nadviazať. Skús adresu s http://.", false); return@launch }
            MaterialAlertDialogBuilder(this@ServerActivity)
                .setTitle("Vlastný certifikát servera")
                .setMessage("Server používa certifikát, ktorému Android nedôveruje (bežné pri domácom serveri alebo prístupe cez IP adresu).\n\n" +
                    Trust.describe(cert) + "\n\nDôveruj mu iba vtedy, ak je to tvoj server.")
                .setPositiveButton("Dôverovať") { _, _ -> Trust.add(Trust.fingerprint(cert)); renderCerts(); test(save) }
                .setNegativeButton("Zrušiť") { _, _ -> showStatus("✗ Certifikátu servera sa nedôveruje.", false) }
                .show()
        }
    }

    /** Uloženie: ak platí prihlásenie aj na novej adrese, pokračuje sa bez odhlásenia. */
    private fun apply(url: String) {
        val old = prefs.server
        if (fromLogin || prefs.token == null) {
            commit(url, old); setBusy(false); finishChanged(); return
        }
        lifecycleScope.launch {
            val r = withContext(Dispatchers.IO) { runCatching { Api(prefs).meAt(url) } }
            setBusy(false)
            if (r.isSuccess) {
                commit(url, old)
                CloudApp.kick(applicationContext, syncNow = true)
                finishChanged()
                return@launch
            }
            val e = r.exceptionOrNull()
            if (e is ApiException && e.code == 401) {
                MaterialAlertDialogBuilder(this@ServerActivity)
                    .setTitle("Iný server")
                    .setMessage("Na tomto serveri nie si prihlásený(á). Po uložení sa prihlásiš znova. Synchronizované zložky sa budú nahrávať na nový server.")
                    .setPositiveButton("Uložiť a prihlásiť") { _, _ ->
                        commit(url, old)
                        SyncService.stop(this@ServerActivity)
                        prefs.logout()
                        android.webkit.CookieManager.getInstance().removeAllCookies(null)
                        startActivity(Intent(this@ServerActivity, LoginActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK))
                        finish()
                    }
                    .setNegativeButton("Zrušiť", null).show()
            } else {
                showStatus("✗ " + (e?.let { describeError(it, url) } ?: "Chyba"), false)
            }
        }
    }

    private fun commit(url: String, old: String) {
        prefs.server = url
        prefs.serverHistory = listOf(url, old) + prefs.serverHistory
    }

    private fun finishChanged() {
        setResult(Activity.RESULT_OK)
        finish()
    }
}
