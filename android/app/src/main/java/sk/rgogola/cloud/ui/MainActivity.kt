package sk.rgogola.cloud.ui

import android.Manifest
import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.SystemClock
import android.view.View
import android.webkit.CookieManager
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.databinding.ActivityMainBinding
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.sync.SyncDb
import sk.rgogola.cloud.sync.SyncService

/**
 * Hlavná obrazovka: webové rozhranie Cloudu (rovnaké ako v prehliadači) vo WebView.
 * Prihlásenie prebieha tokenom aplikácie, voliteľne s odomknutím odtlačkom prsta.
 */
class MainActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_UNLOCKED = "unlocked"
        const val EXTRA_CHECK_UPDATE = "check_update"
        private const val LOCK_AFTER_MS = 3 * 60 * 1000L
    }

    private lateinit var b: ActivityMainBinding
    private val prefs by lazy { Prefs(this) }
    private val updater by lazy { UpdateUi(this) }
    private var unlocked = false
    private var pausedAt = 0L
    private var loaded = false
    private var reloginTimes = ArrayList<Long>()
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val fileChooser = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
        val cb = fileCallback ?: return@registerForActivityResult
        fileCallback = null
        val data = res.data
        val uris: Array<Uri>? = when {
            res.resultCode != RESULT_OK -> null
            data?.clipData != null -> Array(data.clipData!!.itemCount) { data.clipData!!.getItemAt(it).uri }
            data?.data != null -> arrayOf(data.data!!)
            else -> null
        }
        cb.onReceiveValue(uris)
    }

    private val notifPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!prefs.loggedIn) { goLogin(null); return }
        b = ActivityMainBinding.inflate(layoutInflater)
        setContentView(b.root)
        setupWeb()

        b.retryBtn.setOnClickListener { b.offline.visibility = View.GONE; loadWeb() }
        b.offlineSyncBtn.setOnClickListener { startActivity(Intent(this, SyncActivity::class.java)) }
        b.unlockBtn.setOnClickListener { askUnlock() }
        b.lockPasswordBtn.setOnClickListener { goLogin(null, keepToken = false) }
        b.lockName.text = prefs.userName.ifBlank { "Cloud" }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (b.web.canGoBack()) b.web.goBack() else moveTaskToBack(true)
            }
        })

        unlocked = intent.getBooleanExtra(EXTRA_UNLOCKED, false) || !prefs.biometric
        if (intent.getBooleanExtra(EXTRA_CHECK_UPDATE, false)) updater.check(manual = true)
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            notifPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        CloudApp.kick(applicationContext)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (intent.getBooleanExtra(EXTRA_UNLOCKED, false)) { unlocked = true; loaded = false }
        if (intent.getBooleanExtra(EXTRA_CHECK_UPDATE, false)) updater.check(manual = true)
    }

    override fun onResume() {
        super.onResume()
        if (!prefs.loggedIn) { goLogin(null); return }
        if (!::b.isInitialized) return
        if (prefs.authError) { goLogin("Prihlásenie vypršalo alebo bolo zrušené. Prihlás sa znova.", keepToken = false); return }
        if (prefs.biometric && pausedAt > 0 && SystemClock.elapsedRealtime() - pausedAt > LOCK_AFTER_MS) unlocked = false
        if (!unlocked && prefs.biometric) {
            b.lock.visibility = View.VISIBLE
            askUnlock()
        } else {
            b.lock.visibility = View.GONE
            if (!loaded) loadWeb()
        }
        updater.resume()
        if (System.currentTimeMillis() - prefs.lastUpdateCheck > 20 * 3600 * 1000L) updater.check(manual = false)
    }

    override fun onPause() {
        super.onPause()
        pausedAt = SystemClock.elapsedRealtime()
        CookieManager.getInstance().flush()
    }

    private fun askUnlock() {
        if (!Biometric.available(this)) { unlocked = true; b.lock.visibility = View.GONE; if (!loaded) loadWeb(); return }
        Biometric.prompt(this, getString(sk.rgogola.cloud.R.string.unlock_title), getString(sk.rgogola.cloud.R.string.unlock_subtitle),
            onOk = {
                unlocked = true
                b.lock.visibility = View.GONE
                if (!loaded) loadWeb()
            },
            onError = { msg -> if (msg != null) Toast.makeText(this, msg, Toast.LENGTH_SHORT).show() })
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWeb() {
        val w = b.web
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(w, false)
        w.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            allowFileAccess = false
            allowContentAccess = true
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            userAgentString = "$userAgentString ${Api.USER_AGENT}"
            setSupportZoom(true)
            builtInZoomControls = true
            displayZoomControls = false
        }
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean = handleUrl(request.url)

            override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                b.progress.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView, url: String) {
                b.progress.visibility = View.GONE
                if (Uri.parse(url).path?.endsWith("/login.php") == true) relogin()
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) {
                    b.offlineText.text = "Server je nedostupný.\n${error.description}"
                    b.offline.visibility = View.VISIBLE
                    loaded = false
                }
            }
        }
        w.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) { b.progress.setProgressCompat(newProgress, true) }

            override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: WebChromeClient.FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                val intent = try { params.createIntent() } catch (e: Exception) { Intent(Intent.ACTION_GET_CONTENT).setType("*/*") }
                intent.addCategory(Intent.CATEGORY_OPENABLE)
                if (params.mode == WebChromeClient.FileChooserParams.MODE_OPEN_MULTIPLE) intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true)
                return try { fileChooser.launch(Intent.createChooser(intent, "Vyber súbory")); true } catch (e: ActivityNotFoundException) {
                    fileCallback = null; false
                }
            }
        }
        w.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            try {
                val name = URLUtil.guessFileName(url, contentDisposition, mimeType)
                val req = DownloadManager.Request(Uri.parse(url))
                    .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
                    .addRequestHeader("User-Agent", userAgent)
                    .setTitle(name)
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
                getSystemService(DownloadManager::class.java)?.enqueue(req)
                Toast.makeText(this, "Sťahujem $name…", Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            }
        }
    }

    /** Prihlási WebView tokenom a otvorí disk. */
    private fun loadWeb(to: String = "index.php") {
        loaded = true
        b.offline.visibility = View.GONE
        val (url, headers) = Api(prefs).webLogin(to)
        b.web.loadUrl(url, headers)
    }

    private fun relogin() {
        val now = SystemClock.elapsedRealtime()
        reloginTimes.removeAll { now - it > 20_000 }
        reloginTimes.add(now)
        if (reloginTimes.size > 2) {
            // token už neplatí
            lifecycleScope.launch {
                val valid = withContext(Dispatchers.IO) { runCatching { Api(prefs).me(); true }.getOrDefault(false) }
                if (!valid) goLogin("Prihlásenie vypršalo. Prihlás sa znova.", keepToken = false)
            }
            return
        }
        loadWeb()
    }

    private fun handleUrl(uri: Uri): Boolean {
        val server = Uri.parse(prefs.server)
        when (uri.scheme) {
            "cloudapp" -> {
                when (uri.host) {
                    "sync" -> startActivity(Intent(this, SyncActivity::class.java))
                    "update" -> updater.check(manual = true)
                    "logout" -> confirmLogout()
                }
                return true
            }
            "http", "https" -> {
                if (uri.host == server.host) {
                    if (uri.path?.endsWith("/login.php") == true) { relogin(); return true }
                    return false
                }
                openExternal(uri)
                return true
            }
            else -> { openExternal(uri); return true }
        }
    }

    private fun openExternal(uri: Uri) {
        try { startActivity(Intent(Intent.ACTION_VIEW, uri)) } catch (_: Exception) {}
    }

    private fun confirmLogout() {
        val pairs = SyncDb.get(this).pairs().size
        MaterialAlertDialogBuilder(this)
            .setTitle("Odhlásiť sa?")
            .setMessage(if (pairs > 0) "Synchronizácia zložiek sa zastaví, kým sa znova neprihlásiš." else "Na ďalšie prihlásenie budeš potrebovať e-mail a heslo.")
            .setPositiveButton("Odhlásiť") { _, _ -> logout() }
            .setNegativeButton("Zrušiť", null)
            .show()
    }

    private fun logout() {
        val p = prefs
        lifecycleScope.launch {
            withContext(Dispatchers.IO) { Api(p).logout() }
            goLogin(null, keepToken = false)
        }
    }

    private fun goLogin(message: String?, keepToken: Boolean = true) {
        val email = prefs.userEmail
        if (!keepToken) {
            SyncService.stop(this)
            prefs.logout()
            CookieManager.getInstance().removeAllCookies(null)
            if (::b.isInitialized) b.web.clearHistory()
        }
        startActivity(Intent(this, LoginActivity::class.java).putExtra("message", message).putExtra("email", email))
        finish()
    }
}
