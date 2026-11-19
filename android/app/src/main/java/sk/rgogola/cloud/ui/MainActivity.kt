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
import android.webkit.JavascriptInterface
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
import sk.rgogola.cloud.sync.UploadService

/**
 * Hlavná obrazovka: webové rozhranie Cloudu (rovnaké ako v prehliadači) vo WebView.
 * Prihlásenie prebieha tokenom aplikácie, voliteľne s odomknutím odtlačkom prsta.
 */
class MainActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_UNLOCKED = "unlocked"
        const val EXTRA_CHECK_UPDATE = "check_update"
        const val EXTRA_OPEN_LINK = "open_link"
        const val EXTRA_UPLOAD_REPORT = "upload_report"
        private const val LOCK_AFTER_MS = 3 * 60 * 1000L

        /** Obnovenie potiahnutím nadol: v disku znovu načíta aktuálnu zložku, inde obnoví stránku. */
        private const val REFRESH_JS = "(function(){ if (document.getElementById('items')) { window.dispatchEvent(new HashChangeEvent('hashchange')); } else { location.reload(); } })();"

        /** Hlási aplikácii, či je otvorené okno/menu (vtedy potiahnutie nadol neobnovuje). */
        private const val WATCH_JS = "(function(){ if (window.__cloudWatch) return; window.__cloudWatch = 1;" +
            " var f = function(){ var b = document.body.classList.contains('modal-open') || !!document.querySelector('.offcanvas.show, .offcanvas-lg.show, .dropdown-menu.show, .ctx-menu.show');" +
            " if (window.CloudAndroid) CloudAndroid.setRefreshBlocked(b); };" +
            " new MutationObserver(f).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class'] }); f(); })();"

        /** Doplní do webového menu položky aplikácie (funguje s každou verziou servera). */
        private val INJECT_JS = """
            (function(){
              function link(key, icon, label, cls){
                var a = document.createElement('a');
                a.className = cls; a.href = 'cloudapp://' + key; a.setAttribute('data-nav', 'app-' + key);
                a.innerHTML = '<i class="bi ' + icon + (cls.indexOf('dropdown') >= 0 ? ' me-2' : '') + '"></i>' + label;
                return a;
              }
              var items = [['sync','bi-arrow-repeat','Synchronizácia telefónu'], ['background','bi-shield-check','Beh na pozadí'], ['server','bi-hdd-network','Server'], ['update','bi-box-arrow-in-down','Aktualizácia aplikácie']];
              var nav = document.querySelector('.side-nav');
              if (nav && !document.querySelector('.side-nav [href^="cloudapp:"]')) {
                items.forEach(function(i){ nav.appendChild(link(i[0], i[1], i[2], 'nav-link')); });
              }
              var dd = document.querySelector('.app-topbar .dropdown-menu');
              if (dd && !dd.querySelector('[href^="cloudapp://sync"]')) {
                var hr = dd.querySelector('hr');
                items.forEach(function(i){
                  var li = document.createElement('li'); li.appendChild(link(i[0], i[1], i[2], 'dropdown-item'));
                  dd.insertBefore(li, hr ? hr.parentNode.nextSibling : null);
                });
              }
              if (!window.__cloudFolderHook) {
                window.__cloudFolderHook = 1;
                document.addEventListener('click', function(e){
                  var a = e.target.closest && e.target.closest('[data-action="upload-folder"], [data-action="upload-files"]');
                  if (!a) return;
                  var files = a.getAttribute('data-action') === 'upload-files';
                  if (files && !window.__cloudBgUpload) return; // nahrávanie cez web
                  e.preventDefault(); e.stopImmediatePropagation();
                  var m = location.hash.match(/^#folder\/(\d+)/);
                  location.href = 'cloudapp://' + (files ? 'upload-files' : 'upload-folder') + '?folder=' + (m ? m[1] : 0);
                }, true);
              }
              document.querySelectorAll('form[action$="logout.php"]').forEach(function(f){
                var li = f.parentNode, a = link('logout', 'bi-box-arrow-right', 'Odhlásiť sa z aplikácie', 'dropdown-item text-danger');
                li.replaceChild(a, f);
              });
            })();
        """.trimIndent()
    }

    private lateinit var b: ActivityMainBinding
    private val prefs by lazy { Prefs(this) }
    private val updater by lazy { UpdateUi(this) }
    private val opener by lazy { FileOpener(this) }
    private var pendingLink: String? = null
    private var unlocked = false
    private var pausedAt = 0L
    private var loaded = false
    private var reloginTimes = ArrayList<Long>()
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    @Volatile private var refreshBlocked = false

    inner class JsBridge {
        @JavascriptInterface
        fun setRefreshBlocked(blocked: Boolean) { refreshBlocked = blocked }
    }

    private val fileChooser = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { res ->
        val cb = fileCallback ?: return@registerForActivityResult
        fileCallback = null
        val data = res.data
        val cam = cameraUri
        cameraUri = null
        val uris: Array<Uri>? = when {
            res.resultCode != RESULT_OK -> null
            cam != null && data?.data == null && data?.clipData == null -> arrayOf(cam)
            data?.clipData != null -> Array(data.clipData!!.itemCount) { data.clipData!!.getItemAt(it).uri }
            data?.data != null -> arrayOf(data.data!!)
            else -> null
        }
        cb.onReceiveValue(uris)
    }

    private val notifPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    /** Nahrávanie na pozadí: výber súborov / zložky v systéme, nahráva UploadService. */
    private val serverLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        if (r.resultCode == RESULT_OK) {
            Toast.makeText(this, "Pripájam sa na ${prefs.server}", Toast.LENGTH_SHORT).show()
            b.web.clearHistory()
            loadWeb()
        }
    }
    private var uploadTarget = 0
    private var seenUploadFinish = 0L
    private val pickFiles = registerForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNullOrEmpty()) return@registerForActivityResult
        UploadService.enqueueFiles(applicationContext, uploadTarget, uris)
        Toast.makeText(this, "Nahrávam ${uris.size} ${if (uris.size == 1) "súbor" else if (uris.size in 2..4) "súbory" else "súborov"} na pozadí – priebeh je v notifikácii", Toast.LENGTH_LONG).show()
    }
    private val pickFolder = registerForActivityResult(ActivityResultContracts.OpenDocumentTree()) { uri ->
        if (uri == null) return@registerForActivityResult
        UploadService.enqueueTree(applicationContext, uploadTarget, uri)
        Toast.makeText(this, "Zložka sa nahráva na pozadí – priebeh je v notifikácii. Aplikáciu môžeš zavrieť.", Toast.LENGTH_LONG).show()
    }
    private var cameraUri: Uri? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!prefs.loggedIn) { goLogin(null); return }
        prefs.rememberCurrent()
        b = ActivityMainBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        setupWeb()

        b.retryBtn.setOnClickListener { b.offline.visibility = View.GONE; loadWeb() }
        b.offlineSyncBtn.setOnClickListener { startActivity(Intent(this, SyncActivity::class.java)) }
        b.unlockBtn.setOnClickListener { askUnlock() }
        b.lockPasswordBtn.setOnClickListener { goLogin(null, keepToken = false) }
        b.lockName.text = prefs.userName.ifBlank { "Cloud" }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                // najprv zavrieť otvorené okno na stránke (náhľad fotky/videa, menu, dialóg)
                if (!loaded) { if (b.web.canGoBack()) b.web.goBack() else moveTaskToBack(true); return }
                b.web.evaluateJavascript("(function(){try{return window.cloudBack&&window.cloudBack()?1:0}catch(e){return 0}})()") { r ->
                    if (r == "1") return@evaluateJavascript
                    if (b.web.canGoBack()) b.web.goBack() else moveTaskToBack(true)
                }
            }
        })

        unlocked = intent.getBooleanExtra(EXTRA_UNLOCKED, false) || !prefs.biometric
        pendingLink = intent.getStringExtra(EXTRA_OPEN_LINK)
        if (intent.getBooleanExtra(EXTRA_CHECK_UPDATE, false)) updater.check(manual = true)
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            notifPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        CloudApp.kick(applicationContext)
        if (intent.getBooleanExtra(EXTRA_UPLOAD_REPORT, false)) showUploadReport()
        seenUploadFinish = UploadService.finishedAt
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (intent.getBooleanExtra(EXTRA_UNLOCKED, false)) { unlocked = true; loaded = false }
        intent.getStringExtra(EXTRA_OPEN_LINK)?.let { pendingLink = it; loaded = false }
        if (intent.getBooleanExtra(EXTRA_CHECK_UPDATE, false)) updater.check(manual = true)
        if (intent.getBooleanExtra(EXTRA_UPLOAD_REPORT, false)) showUploadReport()
    }

    private fun showUploadReport() {
        val r = prefs.uploadReport
        if (r.isBlank()) return
        val title = r.substringBefore("\n")
        MaterialAlertDialogBuilder(this).setTitle(title).setMessage(r.substringAfter("\n").trim()).setPositiveButton("OK", null).show()
    }

    private fun reloadListing() {
        if (::b.isInitialized && loaded) b.web.evaluateJavascript(REFRESH_JS, null)
    }

    /** Raz za čas upozorní, ak niečo bráni behu na pozadí (napr. optimalizácia batérie). */
    private fun maybeAskBackground() {
        if (prefs.bgPromptNever || System.currentTimeMillis() - prefs.bgPromptTs < 3 * 86_400_000L) return
        if (sk.rgogola.cloud.sync.SyncDb.get(this).pairs().isEmpty() && UploadService.finishedAt == 0L) return
        val n = sk.rgogola.cloud.bg.BgCheck.problems(this)
        if (n == 0) return
        prefs.bgPromptTs = System.currentTimeMillis()
        MaterialAlertDialogBuilder(this)
            .setTitle("Nahrávanie na pozadí")
            .setMessage("Aby sa súbory nahrávali aj pri zatvorenej aplikácii, treba v telefóne nastaviť ešte $n ${if (n == 1) "vec" else if (n in 2..4) "veci" else "vecí"}.")
            .setPositiveButton("Nastaviť") { _, _ -> startActivity(Intent(this, BackgroundActivity::class.java)) }
            .setNegativeButton("Neskôr", null)
            .setNeutralButton("Nepýtať sa") { _, _ -> prefs.bgPromptNever = true }
            .show()
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
        UploadService.onFinished = { runOnUiThread { seenUploadFinish = UploadService.finishedAt; reloadListing() } }
        if (UploadService.finishedAt > seenUploadFinish) { seenUploadFinish = UploadService.finishedAt; reloadListing() }
        if (loaded) b.web.evaluateJavascript("window.__cloudBgUpload = ${prefs.bgUpload};", null)
        if (unlocked && loaded) maybeAskBackground()
        if (System.currentTimeMillis() - prefs.lastUpdateCheck > 6 * 3600 * 1000L) updater.check(manual = false)
    }

    override fun onPause() {
        super.onPause()
        pausedAt = SystemClock.elapsedRealtime()
        UploadService.onFinished = null
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
        w.addJavascriptInterface(JsBridge(), "CloudAndroid")
        b.swipe.setColorSchemeResources(sk.rgogola.cloud.R.color.brand)
        b.swipe.setOnChildScrollUpCallback { _, _ -> refreshBlocked || w.scrollY > 0 }
        b.swipe.setOnRefreshListener {
            if (!loaded || b.offline.visibility == View.VISIBLE) { b.swipe.isRefreshing = false; loadWeb(); return@setOnRefreshListener }
            w.evaluateJavascript(REFRESH_JS, null)
            w.postDelayed({ b.swipe.isRefreshing = false }, 900)
        }
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean = handleUrl(request.url)

            override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                b.progress.visibility = View.VISIBLE
            }

            override fun onPageFinished(view: WebView, url: String) {
                b.progress.visibility = View.GONE
                b.swipe.isRefreshing = false
                if (Uri.parse(url).host == Uri.parse(prefs.server).host) {
                    view.evaluateJavascript("window.__cloudBgUpload = ${prefs.bgUpload};", null)
                    view.evaluateJavascript(INJECT_JS, null)
                    view.evaluateJavascript(accountsJs(), null)
                    view.evaluateJavascript(WATCH_JS, null)
                }
                if (Uri.parse(url).path?.endsWith("/login.php") == true) relogin()
            }

            @SuppressLint("WebViewClientOnReceivedSslError")
            override fun onReceivedSslError(view: WebView, handler: android.webkit.SslErrorHandler, error: android.net.http.SslError) {
                // vlastný certifikát servera potvrdený v Nastaveniach servera
                if (sk.rgogola.cloud.net.Trust.isTrusted(sk.rgogola.cloud.net.Trust.fromWebView(error.certificate))) { handler.proceed(); return }
                handler.cancel()
                if (Uri.parse(error.url).host == Uri.parse(prefs.server).host) {
                    b.offlineText.text = "Certifikát servera nie je dôveryhodný.\nOver ho v menu → Server."
                    b.offline.visibility = View.VISIBLE
                    loaded = false
                }
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
                if (params.isCaptureEnabled) { // „Odfotiť a nahrať“
                    try {
                        val dir = java.io.File(cacheDir, "camera").apply { mkdirs() }
                        dir.listFiles()?.forEach { if (System.currentTimeMillis() - it.lastModified() > 86_400_000L) it.delete() }
                        val video = params.acceptTypes.any { it.startsWith("video") } && params.acceptTypes.none { it.startsWith("image") }
                        val stamp = java.text.SimpleDateFormat("yyyyMMdd_HHmmss", java.util.Locale.US).format(java.util.Date())
                        val f = java.io.File(dir, (if (video) "VID_" else "IMG_") + stamp + (if (video) ".mp4" else ".jpg"))
                        val uri = androidx.core.content.FileProvider.getUriForFile(this@MainActivity, "$packageName.files", f)
                        cameraUri = uri
                        val cam = Intent(if (video) android.provider.MediaStore.ACTION_VIDEO_CAPTURE else android.provider.MediaStore.ACTION_IMAGE_CAPTURE)
                            .putExtra(android.provider.MediaStore.EXTRA_OUTPUT, uri)
                            .addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION or Intent.FLAG_GRANT_READ_URI_PERMISSION)
                        fileChooser.launch(cam)
                        return true
                    } catch (e: Exception) { cameraUri = null }
                }
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
                val name = downloadName(url, contentDisposition, mimeType)
                val req = DownloadManager.Request(Uri.parse(url))
                    .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url) ?: "")
                    .addRequestHeader("User-Agent", userAgent)
                    .setTitle(name)
                    .setMimeType(mimeType?.takeIf { it.isNotBlank() } ?: "application/octet-stream")
                    .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
                getSystemService(DownloadManager::class.java)?.enqueue(req)
                Toast.makeText(this, "Sťahujem $name…", Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
            }
        }
    }

    /** Názov sťahovaného súboru z hlavičky Content-Disposition (aj filename*=UTF-8''… s diakritikou). */
    private fun downloadName(url: String, cd: String?, mime: String?): String {
        val clean = { n: String -> n.trim().trim('"').replace(Regex("[\\\\/:*?\"<>|\\x00-\\x1f]"), "_").ifBlank { "subor" } }
        if (!cd.isNullOrBlank()) {
            Regex("filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)", RegexOption.IGNORE_CASE).find(cd)?.let { return clean(Uri.decode(it.groupValues[1])) }
            Regex("filename\\s*=\\s*\"([^\"]+)\"", RegexOption.IGNORE_CASE).find(cd)?.let { return clean(it.groupValues[1]) }
            Regex("filename\\s*=\\s*([^;]+)", RegexOption.IGNORE_CASE).find(cd)?.let { return clean(it.groupValues[1]) }
        }
        return URLUtil.guessFileName(url, cd, mime)
    }

    /** Prihlási WebView tokenom a otvorí disk. */
    private fun loadWeb(target: String? = null) {
        val to = target ?: pendingLink?.takeIf { Regex("^[a-z0-9_-]+\\.php([?#].*)?$", RegexOption.IGNORE_CASE).matches(it) } ?: "index.php"
        pendingLink = null
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
                    "account" -> prefs.otherAccounts().firstOrNull { it.key == uri.getQueryParameter("k") }?.let { switchAccount(it) }
                    "account-add" -> addAccount()
                    "open", "send", "pdf" -> opener.handle(uri)
                    "background" -> startActivity(Intent(this, BackgroundActivity::class.java))
                    "server" -> serverLauncher.launch(Intent(this, ServerActivity::class.java))
                    "upload-files" -> {
                        uploadTarget = uri.getQueryParameter("folder")?.toIntOrNull() ?: 0
                        try { pickFiles.launch(arrayOf("*/*")) } catch (e: Exception) { Toast.makeText(this, "Výber súborov nie je dostupný", Toast.LENGTH_LONG).show() }
                    }
                    "upload-folder" -> {
                        uploadTarget = uri.getQueryParameter("folder")?.toIntOrNull() ?: 0
                        Toast.makeText(this, "Vyber zložku a ťukni na „Použiť túto zložku“", Toast.LENGTH_LONG).show()
                        try { pickFolder.launch(null) } catch (e: Exception) { Toast.makeText(this, "Výber zložky nie je dostupný", Toast.LENGTH_LONG).show() }
                    }
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
            val next = p.otherAccounts().firstOrNull()
            if (next == null) { goLogin(null, keepToken = false); return@launch }
            // v aplikácii ostal ďalší účet → prepni naň namiesto prihlasovacej obrazovky
            stopAccountWork()
            p.logout()
            p.activate(next)
            afterAccountChange("Prihlásený ako ${next.email}")
        }
    }

    /* ---------- viac účtov ---------- */
    private fun accountsJs(): String {
        val arr = org.json.JSONArray()
        prefs.otherAccounts().forEach { arr.put(org.json.JSONObject().put("k", it.key).put("n", it.name).put("e", it.email).put("s", Uri.parse(it.server).host ?: "")) }
        val ms = prefs.accounts().map { it.server.lowercase() }.distinct().size > 1
        return """(function(acc, ms){
          var dd = document.querySelector('.app-topbar .dropdown-menu');
          if (!dd || dd.querySelector('#appAccounts')) return;
          var first = dd.querySelector('li'); if (!first) return;
          function li(h){ var l = document.createElement('li'); l.innerHTML = h; return l; }
          var out = [li('<hr class="dropdown-divider">')]; out[0].id = 'appAccounts';
          if (acc.length) out.push(li('<h6 class="dropdown-header">Prepnúť účet</h6>'));
          acc.forEach(function(a){
            var l = li('<a class="dropdown-item d-flex align-items-center gap-2"><span class="avatar avatar-sm"></span><span class="min-w-0"><span class="d-block text-truncate"></span><span class="d-block small text-body-secondary text-truncate"></span></span></a>');
            l.firstChild.href = 'cloudapp://account?k=' + encodeURIComponent(a.k);
            l.querySelector('.avatar').textContent = (a.n || a.e).charAt(0).toUpperCase();
            var t = l.querySelectorAll('.text-truncate'); t[1].textContent = a.n || a.e; t[2].textContent = a.e + (ms && a.s ? ' · ' + a.s : '');
            out.push(l);
          });
          out.push(li('<a class="dropdown-item" href="cloudapp://account-add"><i class="bi bi-person-plus me-2"></i>Pridať ďalší účet</a>'));
          var ref = first.nextSibling;
          out.forEach(function(x){ dd.insertBefore(x, ref); });
        })($arr, $ms);"""
    }

    /** Zastaví synchronizáciu a nahrávanie aktuálneho účtu (pred prepnutím). */
    private fun stopAccountWork() {
        SyncService.stop(this)
        if (sk.rgogola.cloud.sync.UploadQueue.size(this) > 0 || UploadService.running) {
            sk.rgogola.cloud.sync.UploadQueue.clear(this)
            try { startService(Intent(this, UploadService::class.java).setAction(UploadService.ACTION_CANCEL)) } catch (_: Exception) {}
        }
    }

    private fun afterAccountChange(msg: String) {
        b.lockName.text = prefs.userName.ifBlank { "Cloud" }
        CookieManager.getInstance().removeAllCookies {
            runOnUiThread { b.web.clearHistory(); loadWeb() ; b.web.postDelayed({ b.web.clearHistory() }, 1500) }
        }
        CloudApp.kick(applicationContext)
        Toast.makeText(this, msg, Toast.LENGTH_SHORT).show()
    }

    /** Ak beží nahrávanie, opýta sa pred prepnutím účtu. */
    private fun confirmStopUploads(action: () -> Unit) {
        val n = sk.rgogola.cloud.sync.UploadQueue.size(this)
        if (n == 0 && !UploadService.running) { action(); return }
        MaterialAlertDialogBuilder(this)
            .setTitle("Prebieha nahrávanie")
            .setMessage("Do účtu ${prefs.userEmail} sa ešte nahráva" + (if (n > 0) " ($n v poradí)" else "") + ". Prepnutím účtu sa nahrávanie zruší.")
            .setPositiveButton("Prepnúť a zrušiť") { _, _ -> action() }
            .setNegativeButton("Počkať", null)
            .show()
    }

    private fun switchAccount(a: sk.rgogola.cloud.Prefs.Account) = confirmStopUploads {
        lifecycleScope.launch {
            val old = prefs.accounts().firstOrNull { it.key == prefs.currentKey }
            stopAccountWork()
            prefs.activate(a)
            // overí, či uložené prihlásenie ešte platí
            val ok = withContext(Dispatchers.IO) { runCatching { Api(prefs).me(); true }.getOrElse { e -> !(e is sk.rgogola.cloud.net.ApiException && e.code == 401) } }
            if (!ok) {
                prefs.forgetCurrentAccount()
                old?.let { prefs.activate(it) }
                afterAccountChange("Prihlásenie do ${a.email} vypršalo – pridaj účet znova.")
                return@launch
            }
            afterAccountChange("Prepnuté na ${a.email}")
        }
    }

    private fun addAccount() = confirmStopUploads {
        prefs.rememberCurrent()
        startActivity(Intent(this, LoginActivity::class.java).putExtra(LoginActivity.EXTRA_ADD, true))
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
