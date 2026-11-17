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
    private lateinit var b: ActivityLoginBinding
    private val prefs by lazy { Prefs(this) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(b.root)

        b.server.setText(prefs.server)
        b.email.setText(prefs.userEmail.ifBlank { intent.getStringExtra("email") ?: "" })
        intent.getStringExtra("message")?.let { showError(it) }

        b.serverBtn.setOnClickListener { b.serverLayout.visibility = if (b.serverLayout.visibility == View.VISIBLE) View.GONE else View.VISIBLE }
        b.loginBtn.setOnClickListener { doLogin() }
        b.password.setOnEditorActionListener { _, id, _ -> if (id == EditorInfo.IME_ACTION_DONE) { doLogin(); true } else false }
        b.forgotBtn.setOnClickListener { openWeb("forgot.php") }
        b.registerBtn.setOnClickListener { openWeb("register.php") }
    }

    private fun serverUrl(): String {
        var s = b.server.text?.toString()?.trim().orEmpty().trimEnd('/')
        if (s.isNotEmpty() && !s.startsWith("http")) s = "https://$s"
        return s
    }

    private fun openWeb(path: String) {
        startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(serverUrl() + "/" + path)))
    }

    private fun showError(msg: String?) {
        b.error.text = msg
        b.error.visibility = if (msg.isNullOrBlank()) View.GONE else View.VISIBLE
    }

    private fun doLogin() {
        val server = serverUrl()
        val email = b.email.text?.toString()?.trim().orEmpty()
        val pass = b.password.text?.toString().orEmpty()
        if (!server.startsWith("https://")) { showError("Adresa servera musí začínať https://"); b.serverLayout.visibility = View.VISIBLE; return }
        if (email.isEmpty() || pass.isEmpty()) { showError("Vyplň e-mail a heslo."); return }
        showError(null)
        b.loginBtn.isEnabled = false
        b.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) { runCatching { Api(prefs).login(server, email, pass) } }
            b.loginBtn.isEnabled = true
            b.progress.visibility = View.INVISIBLE
            res.onFailure { showError(it.message ?: "Prihlásenie zlyhalo.") }
            res.onSuccess { j ->
                prefs.server = server
                prefs.token = j.getString("token")
                val u = j.getJSONObject("user")
                prefs.userName = u.optString("name")
                prefs.userEmail = u.optString("email")
                prefs.authError = false
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

    private fun goMain() {
        startActivity(Intent(this, MainActivity::class.java).putExtra(MainActivity.EXTRA_UNLOCKED, true)
            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK))
        finish()
    }
}
