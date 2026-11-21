package sk.rgogola.cloud.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.view.inputmethod.EditorInfo
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.databinding.ActivityLoginBinding
import sk.rgogola.cloud.net.Api

/** Prihlásenie e-mailom a heslom (rovnaké ako na webe); potom voliteľne odtlačok prsta. */
class LoginActivity : AppCompatActivity() {
    companion object { const val EXTRA_ADD = "add_account" }
    private lateinit var b: ActivityLoginBinding
    private val prefs by lazy { Prefs(this) }
    private val addMode by lazy { intent.getBooleanExtra(EXTRA_ADD, false) && prefs.loggedIn }
    private var origServer = ""
    private var done = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)

        origServer = prefs.server
        b.server.setText(prefs.server)
        b.email.setText(if (addMode) "" else prefs.userEmail.ifBlank { intent.getStringExtra("email") ?: "" })
        intent.getStringExtra("message")?.let { showError(it) }
        if (addMode) {
            b.addInfo.visibility = View.VISIBLE
            b.addInfo.text = "Pridanie ďalšieho účtu. Účet ${prefs.userEmail} ostane uložený – prepneš sa naň v menu pod avatarom."
            b.addCancel.visibility = View.VISIBLE
            b.addCancel.setOnClickListener { finish() }
            b.email.requestFocus()
        }

        b.serverBtn.setOnClickListener {
            prefs.server = serverUrl().ifBlank { prefs.server }
            serverLauncher.launch(Intent(this, ServerActivity::class.java).putExtra(ServerActivity.EXTRA_FROM_LOGIN, true))
        }
        b.loginBtn.setOnClickListener { doLogin() }
        b.password.setOnEditorActionListener { _, id, _ -> if (id == EditorInfo.IME_ACTION_DONE) { doLogin(); true } else false }
        b.forgotBtn.setOnClickListener { openWeb("forgot.php") }
        b.registerBtn.setOnClickListener { openWeb("register.php") }
    }

    private val serverLauncher = registerForActivityResult(androidx.activity.result.contract.ActivityResultContracts.StartActivityForResult()) {
        b.server.setText(prefs.server)
        b.serverLayout.visibility = View.VISIBLE
        showError(null)
    }

    private fun serverUrl(): String = ServerActivity.normalize(b.server.text?.toString().orEmpty())

    private fun openWeb(path: String) {
        startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(serverUrl() + "/" + path)))
    }

    private fun showError(msg: String?) {
        b.error.text = msg
        b.error.visibility = if (msg.isNullOrBlank()) View.GONE else View.VISIBLE
    }

    /** Dvojstupňové overenie: server chce kód z overovacej aplikácie (alebo záložný kód). */
    private fun askCode(msg: String) {
        val input = android.widget.EditText(this).apply {
            inputType = android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD
            hint = "123456"
            textSize = 22f
            gravity = android.view.Gravity.CENTER
        }
        val pad = (20 * resources.displayMetrics.density).toInt()
        val box = android.widget.FrameLayout(this).apply { setPadding(pad, pad / 2, pad, 0); addView(input) }
        MaterialAlertDialogBuilder(this)
            .setTitle("Overovací kód")
            .setMessage(msg + "\n\nNemáš telefón s aplikáciou? Zadaj záložný kód.")
            .setView(box)
            .setPositiveButton("Overiť") { _, _ -> doLogin(input.text?.toString()?.trim().orEmpty()) }
            .setNegativeButton("Zrušiť", null)
            .show()
        input.requestFocus()
    }

    private fun doLogin(code: String = "") {
        val server = serverUrl()
        val email = b.email.text?.toString()?.trim().orEmpty()
        val pass = b.password.text?.toString().orEmpty()
        if (!Regex("^https?://[^/\\s]+").containsMatchIn(server)) { showError("Zadaj platnú adresu servera."); b.serverLayout.visibility = View.VISIBLE; return }
        if (email.isEmpty() || pass.isEmpty()) { showError("Vyplň e-mail a heslo."); return }
        showError(null)
        b.loginBtn.isEnabled = false
        b.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) { runCatching { Api(prefs).login(server, email, pass, code) } }
            b.loginBtn.isEnabled = true
            b.progress.visibility = View.INVISIBLE
            res.onFailure { e ->
                if (e is sk.rgogola.cloud.net.ApiException && e.code == 428) { askCode(e.message ?: "Zadaj overovací kód."); return@onFailure }
                val ssl = generateSequence(e as Throwable?) { it.cause }.any { it is javax.net.ssl.SSLException || it is java.security.cert.CertificateException }
                showError(when {
                    ssl -> "Certifikát servera nie je dôveryhodný. Ťukni na „Server“ a over ho."
                    e is java.net.UnknownHostException || e is java.net.ConnectException -> "Server je nedostupný. Skontroluj adresu (tlačidlo „Server“)."
                    else -> e.message ?: "Prihlásenie zlyhalo."
                })
            }
            res.onSuccess { j ->
                val u = j.getJSONObject("user")
                // predchádzajúci účet ostane v zozname, jeho synchronizácia a nahrávanie sa zastavia
                if (addMode) prefs.server = origServer
                prefs.rememberCurrent()
                if (prefs.loggedIn) {
                    sk.rgogola.cloud.sync.SyncService.stop(this@LoginActivity)
                    if (sk.rgogola.cloud.sync.UploadQueue.size(this@LoginActivity) > 0) {
                        sk.rgogola.cloud.sync.UploadQueue.clear(this@LoginActivity)
                        try { startService(Intent(this@LoginActivity, sk.rgogola.cloud.sync.UploadService::class.java).setAction(sk.rgogola.cloud.sync.UploadService.ACTION_CANCEL)) } catch (_: Exception) {}
                    }
                }
                if (prefs.server != server) prefs.serverHistory = listOf(server, prefs.server) + prefs.serverHistory
                prefs.activate(Prefs.Account(server, j.getString("token"), u.optString("name"), u.optString("email")))
                prefs.rememberCurrent()
                done = true
                android.webkit.CookieManager.getInstance().removeAllCookies(null)
                CloudApp.kick(applicationContext)
                offerBiometric()
            }
        }
    }

    private fun offerBiometric() {
        if (!Biometric.available(this) || prefs.biometric) { goMain(); return }
        MaterialAlertDialogBuilder(this)
            .setTitle("Prihlasovať sa odtlačkom prsta?")
            .setMessage("Aplikáciu potom odomkneš jedným dotykom – heslo už nebudeš musieť zadávať.")
            .setPositiveButton("Zapnúť") { _, _ ->
                Biometric.prompt(this, "Zapnúť odtlačok prsta", "Over sa odtlačkom", onOk = { prefs.biometric = true; goMain() }, onError = { goMain() })
            }
            .setNegativeButton("Teraz nie") { _, _ -> goMain() }
            .setCancelable(false)
            .show()
    }

    override fun onDestroy() {
        // zrušené pridávanie účtu: aktuálny účet ostáva na pôvodnom serveri
        if (addMode && !done && isFinishing) prefs.server = origServer
        super.onDestroy()
    }

    private fun goMain() {
        startActivity(Intent(this, MainActivity::class.java).putExtra(MainActivity.EXTRA_UNLOCKED, true)
            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }
}
