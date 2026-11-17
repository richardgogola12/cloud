package sk.rgogola.cloud.ui

import android.annotation.SuppressLint
import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.Editable
import android.text.TextWatcher
import android.text.format.DateUtils
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.documentfile.provider.DocumentFile
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.BuildConfig
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.databinding.ActivitySyncBinding
import sk.rgogola.cloud.databinding.DialogPickFolderBinding
import sk.rgogola.cloud.databinding.ItemPairBinding
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.RemoteFolder
import sk.rgogola.cloud.sync.SyncDb
import sk.rgogola.cloud.sync.SyncEngine
import sk.rgogola.cloud.sync.SyncService
import sk.rgogola.cloud.sync.SyncWidget
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Nastavenie synchronizácie: ktoré zložky v telefóne sa nahrávajú do ktorých zložiek v cloude. */
class SyncActivity : AppCompatActivity() {
    private lateinit var b: ActivitySyncBinding
    private val prefs by lazy { Prefs(this) }
    private val db by lazy { SyncDb.get(this) }
    private val updater by lazy { UpdateUi(this) }
    private val handler = Handler(Looper.getMainLooper())
    private val ticker = object : Runnable { override fun run() { refreshStatus(); handler.postDelayed(this, 3000) } }
    private val intervals = listOf(5 to "5 minút", 15 to "15 minút", 30 to "30 minút", 60 to "1 hodinu", 180 to "3 hodiny")

    private val receiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) { refreshStatus() }
    }

    private val pickTree = registerForActivityResult(ActivityResultContracts.OpenDocumentTree()) { uri -> if (uri != null) onTreePicked(uri) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivitySyncBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        b.toolbar.setNavigationOnClickListener { finish() }

        b.addBtn.setOnClickListener {
            Toast.makeText(this, "Vyber zložku v telefóne a ťukni na „Použiť túto zložku“", Toast.LENGTH_LONG).show()
            pickTree.launch(null)
        }
        b.syncNowBtn.setOnClickListener {
            prefs.authError = false
            CloudApp.kick(applicationContext, syncNow = true)
            Toast.makeText(this, "Synchronizácia sa spustila", Toast.LENGTH_SHORT).show()
            handler.postDelayed({ refreshStatus() }, 1500)
        }
        b.swipe.setOnRefreshListener { refreshAll(); b.swipe.isRefreshing = false }

        b.swEnabled.isChecked = prefs.syncEnabled
        b.swEnabled.setOnCheckedChangeListener { _, on ->
            prefs.syncEnabled = on
            if (on) CloudApp.kick(applicationContext, true) else SyncService.stop(this)
            SyncWidget.updateAll(this)
            refreshStatus()
        }
        b.swWifi.isChecked = prefs.wifiOnly
        b.swWifi.setOnCheckedChangeListener { _, on -> prefs.wifiOnly = on; CloudApp.kick(applicationContext, true) }

        b.interval.setSimpleItems(intervals.map { it.second }.toTypedArray())
        b.interval.setText(intervals.firstOrNull { it.first == prefs.intervalMin }?.second ?: "${prefs.intervalMin} minút", false)
        b.interval.setOnItemClickListener { _, _, pos, _ -> prefs.intervalMin = intervals[pos].first; SyncService.start(applicationContext) }

        b.batteryBtn.setOnClickListener { startActivity(Intent(this, BackgroundActivity::class.java)) }
        b.appSettingsBtn.setOnClickListener {
            startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$packageName")))
        }

        b.account.text = "${prefs.userName} · ${prefs.userEmail}\n${prefs.server}"
        b.version.text = "Verzia ${BuildConfig.VERSION_NAME} (build ${BuildConfig.VERSION_CODE})"
        b.swBiometric.isEnabled = Biometric.available(this)
        b.swBiometric.isChecked = prefs.biometric
        b.swBiometric.setOnCheckedChangeListener { v, on ->
            if (!on) { prefs.biometric = false; return@setOnCheckedChangeListener }
            Biometric.prompt(this, "Zapnúť odtlačok prsta", "Over sa odtlačkom",
                onOk = { prefs.biometric = true },
                onError = { v.isChecked = false })
        }
        b.updateBtn.setOnClickListener { updater.check(manual = true) }
        b.swNotify.isChecked = prefs.notifyEnabled
        b.swNotify.setOnCheckedChangeListener { _, on -> prefs.notifyEnabled = on }
        val wm = AppWidgetManager.getInstance(this)
        b.widgetBtn.visibility = if (wm.isRequestPinAppWidgetSupported) View.VISIBLE else View.GONE
        b.widgetBtn.setOnClickListener {
            if (!wm.requestPinAppWidget(ComponentName(this, SyncWidget::class.java), null, null)) {
                Toast.makeText(this, "Widget pridáš podržaním prsta na ploche → Widgety → Cloud.", Toast.LENGTH_LONG).show()
            }
        }
        b.logoutBtn.setOnClickListener {
            MaterialAlertDialogBuilder(this).setTitle("Odhlásiť sa?").setMessage("Synchronizácia sa zastaví.")
                .setPositiveButton("Odhlásiť") { _, _ ->
                    lifecycleScope.launch {
                        withContext(Dispatchers.IO) { Api(prefs).logout() }
                        SyncService.stop(this@SyncActivity)
                        prefs.logout()
                        startActivity(Intent(this@SyncActivity, LoginActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK or Intent.FLAG_ACTIVITY_NEW_TASK))
                        finish()
                    }
                }.setNegativeButton("Zrušiť", null).show()
        }
    }

    override fun onResume() {
        super.onResume()
        ContextCompat.registerReceiver(this, receiver, IntentFilter(SyncEngine.ACTION_CHANGED), ContextCompat.RECEIVER_NOT_EXPORTED)
        refreshAll()
        handler.post(ticker)
        updater.resume()
    }

    override fun onPause() {
        super.onPause()
        try { unregisterReceiver(receiver) } catch (_: Exception) {}
        handler.removeCallbacks(ticker)
    }

    private fun refreshAll() {
        refreshPairs()
        refreshStatus()
        val n = sk.rgogola.cloud.bg.BgCheck.problems(this)
        if (n == 0) {
            b.bgTitle.text = "✓ Beh na pozadí je nastavený"
            b.bgDesc.text = "Nahrávanie pobeží aj pri zatvorenej aplikácii a po reštarte telefónu."
            b.batteryBtn.text = "Spravovať"
            b.batteryCard.setCardBackgroundColor(0x1A188038)
        } else {
            b.bgTitle.text = "Beh na pozadí – treba nastaviť: $n"
            b.bgDesc.text = "Aby sa súbory nahrávali aj keď je aplikácia zatvorená, povoľ v telefóne potrebné nastavenia (batéria, autoštart, upozornenia…)."
            b.batteryBtn.text = "Nastaviť"
            b.batteryCard.setCardBackgroundColor(0x1AE37400)
        }
    }

    @SuppressLint("SetTextI18n")
    private fun refreshStatus() {
        val pairs = db.pairs(onlyEnabled = true)
        b.status.text = when {
            prefs.authError -> "Prihlásenie vypršalo – prihlás sa znova"
            !prefs.syncEnabled -> "Synchronizácia je vypnutá"
            pairs.isEmpty() -> "Pridaj zložku, ktorú chceš zálohovať"
            else -> SyncEngine.status
        }
        val last = if (prefs.lastSync > 0) DateUtils.getRelativeTimeSpanString(prefs.lastSync).toString() else "zatiaľ nikdy"
        val pending = db.totalPending()
        b.statusSub.text = "Posledná synchronizácia: $last" +
            (if (pending > 0) " · čaká na nahratie: $pending" else "") +
            (if (SyncService.running) " · služba beží" else if (pairs.isNotEmpty() && prefs.syncEnabled) " · služba nebeží" else "")
        b.busy.visibility = if (SyncEngine.busy) View.VISIBLE else View.GONE
        val fmt = SimpleDateFormat("dd.MM. HH:mm", Locale.getDefault())
        val log = db.recentLog(30)
        b.log.text = if (log.isEmpty()) "Zatiaľ žiadna aktivita." else log.joinToString("\n") { (if (it.ok) "✓ " else "✗ ") + fmt.format(Date(it.ts)) + "  " + it.msg }
        // štatistiky párov
        for (i in 0 until b.pairs.childCount) {
            val v = b.pairs.getChildAt(i)
            val id = v.tag as? Long ?: continue
            val st = db.stats(id)
            val ib = ItemPairBinding.bind(v)
            ib.stats.text = "Nahraté ${st.done}" + (if (st.pending > 0) " · čaká ${st.pending}" else "") +
                (if (st.errors > 0) " · chyby ${st.errors} (ťukni pre detail)" else "") + (if (st.skipped > 0) " · nenahraté staršie ${st.skipped}" else "")
            ib.pairRequeue.text = if (st.skipped > 0) "Nahrať aj ${st.skipped} starších súborov" else "Nahrať všetky súbory znova"
            ib.pairRequeue.visibility = if (st.pending == 0) View.VISIBLE else View.GONE
        }
    }

    private fun refreshPairs() {
        val pairs = db.pairs()
        b.emptyPairs.visibility = if (pairs.isEmpty()) View.VISIBLE else View.GONE
        b.pairs.removeAllViews()
        for (p in pairs) {
            val ib = ItemPairBinding.inflate(layoutInflater, b.pairs, false)
            ib.root.tag = p.id
            ib.localName.text = p.localName
            ib.remoteName.text = p.remoteName
            ib.pairError.text = p.lastError
            ib.pairError.visibility = if (p.lastError.isNullOrBlank()) View.GONE else View.VISIBLE
            ib.pairEnabled.isChecked = p.enabled
            ib.pairEnabled.setOnCheckedChangeListener { _, on -> db.setPairEnabled(p.id, on); CloudApp.kick(applicationContext, on) }
            ib.root.setOnClickListener { showErrors(p.id, p.localName) }
            ib.pairRequeue.setOnClickListener {
                MaterialAlertDialogBuilder(this)
                    .setTitle("Nahrať všetky súbory?")
                    .setMessage("Do cloudu sa nahrajú všetky súbory zo zložky „${p.localName}“. Súbory, ktoré tam už rovnaké sú, sa preskočia.")
                    .setPositiveButton("Nahrať") { _, _ ->
                        val n = db.requeueAll(p.id)
                        prefs.authError = false
                        CloudApp.kick(applicationContext, syncNow = true)
                        Toast.makeText(this, "Na nahratie čaká $n súborov", Toast.LENGTH_SHORT).show()
                        refreshStatus()
                    }
                    .setNegativeButton("Zrušiť", null).show()
            }
            ib.pairDelete.setOnClickListener {
                MaterialAlertDialogBuilder(this)
                    .setTitle("Prestať sledovať „${p.localName}“?")
                    .setMessage("Súbory, ktoré už sú v cloude, tam zostanú.")
                    .setPositiveButton("Prestať sledovať") { _, _ ->
                        db.deletePair(p.id)
                        try { contentResolver.releasePersistableUriPermission(Uri.parse(p.treeUri), Intent.FLAG_GRANT_READ_URI_PERMISSION) } catch (_: Exception) {}
                        SyncService.start(applicationContext)
                        refreshAll()
                    }
                    .setNegativeButton("Zrušiť", null).show()
            }
            b.pairs.addView(ib.root)
        }
    }

    /** Zoznam súborov, ktoré sa nepodarilo nahrať, s presnou chybou. */
    private fun showErrors(pairId: Long, name: String) {
        val errs = db.errorFiles(pairId)
        if (errs.isEmpty()) { Toast.makeText(this, "V zložke „$name“ nie sú žiadne chyby.", Toast.LENGTH_SHORT).show(); return }
        val text = errs.joinToString("\n\n") { (rel, err) -> "• $rel\n   ${err.ifBlank { "neznáma chyba" }}" }
        MaterialAlertDialogBuilder(this)
            .setTitle("Chyby v „$name“ (${errs.size})")
            .setMessage(text)
            .setPositiveButton("Skúsiť znova") { _, _ ->
                db.retryErrors(pairId)
                prefs.authError = false
                CloudApp.kick(applicationContext, syncNow = true)
                refreshStatus()
            }
            .setNeutralButton("Kopírovať") { _, _ ->
                val cm = getSystemService(android.content.ClipboardManager::class.java)
                cm?.setPrimaryClip(android.content.ClipData.newPlainText("Chyby Cloud", text))
                Toast.makeText(this, "Skopírované", Toast.LENGTH_SHORT).show()
            }
            .setNegativeButton("Zavrieť", null)
            .show()
    }

    // ---------------- pridanie zložky ----------------
    private fun onTreePicked(uri: Uri) {
        try {
            contentResolver.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION)
        } catch (e: Exception) {
            Toast.makeText(this, "K tejto zložke sa nedá získať trvalý prístup.", Toast.LENGTH_LONG).show(); return
        }
        if (db.pairs().any { it.treeUri == uri.toString() }) { Toast.makeText(this, "Túto zložku už sleduješ.", Toast.LENGTH_SHORT).show(); return }
        val localName = DocumentFile.fromTreeUri(this, uri)?.name ?: "Zložka"
        lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) { runCatching { Api(prefs).folders() } }
            res.onFailure {
                Toast.makeText(this@SyncActivity, "Zložky v cloude sa nepodarilo načítať: ${it.message}", Toast.LENGTH_LONG).show()
                try { contentResolver.releasePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) } catch (_: Exception) {}
            }
            res.onSuccess { pickRemote(uri, localName, it) }
        }
    }

    private fun pickRemote(uri: Uri, localName: String, folders: List<RemoteFolder>) {
        val newOpt = RemoteFolder(-1, "＋ Nová zložka „$localName“ v Môj disk", false)
        val all = listOf(newOpt) + folders
        var shown = all
        var selected: RemoteFolder? = newOpt
        val db2 = DialogPickFolderBinding.inflate(layoutInflater)
        db2.pickInfo.text = "Súbory zo zložky „$localName“ v telefóne sa budú nahrávať do:"
        val adapter = ArrayAdapter(this, android.R.layout.simple_list_item_single_choice, shown.map { it.path }.toMutableList())
        db2.list.adapter = adapter
        db2.list.setItemChecked(0, true)
        db2.list.setOnItemClickListener { _, _, pos, _ -> selected = shown[pos] }
        db2.search.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun onTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun afterTextChanged(s: Editable?) {
                val q = s?.toString()?.trim().orEmpty()
                shown = if (q.isEmpty()) all else all.filter { it.id == -1 || it.path.contains(q, ignoreCase = true) }
                adapter.clear(); adapter.addAll(shown.map { it.path })
                val idx = shown.indexOf(selected)
                db2.list.clearChoices()
                if (idx >= 0) db2.list.setItemChecked(idx, true)
            }
        })
        MaterialAlertDialogBuilder(this)
            .setTitle("Kam nahrávať?")
            .setView(db2.root)
            .setPositiveButton("Uložiť") { _, _ -> savePair(uri, localName, selected ?: newOpt, db2.includeExisting.isChecked) }
            .setNegativeButton("Zrušiť") { _, _ ->
                try { contentResolver.releasePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) } catch (_: Exception) {}
            }
            .show()
    }

    private fun savePair(uri: Uri, localName: String, target: RemoteFolder, includeExisting: Boolean) {
        lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) {
                runCatching {
                    if (target.id == -1) {
                        val api = Api(prefs)
                        val id = try { api.mkdir(0, localName) } catch (e: Exception) {
                            api.folders().firstOrNull { it.path == "Môj disk / $localName" }?.id ?: throw e
                        }
                        RemoteFolder(id, "Môj disk / $localName", false)
                    } else target
                }
            }
            res.onFailure { Toast.makeText(this@SyncActivity, "Chyba: ${it.message}", Toast.LENGTH_LONG).show() }
            res.onSuccess { f ->
                db.addPair(uri.toString(), localName, f.id, f.path, includeExisting)
                prefs.syncEnabled = true
                b.swEnabled.isChecked = true
                CloudApp.kick(applicationContext, syncNow = true)
                refreshAll()
                Toast.makeText(this@SyncActivity, "Zložka „$localName“ sa bude synchronizovať", Toast.LENGTH_SHORT).show()
            }
        }
    }
}
