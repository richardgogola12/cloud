package sk.rgogola.cloud.ui

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.OpenableColumns
import android.text.Editable
import android.text.InputType
import android.text.TextWatcher
import android.view.View
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.progressindicator.LinearProgressIndicator
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.databinding.ActivityShareBinding
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.RemoteFolder
import sk.rgogola.cloud.sync.UploadService
import java.io.File

/**
 * „Zdieľať → Cloud“ z iných aplikácií (galéria, WhatsApp, Gmail…): výber zložky a nahratie na pozadí.
 * Súbory sa najprv skopírujú do aplikácie, aby nahrávanie pokračovalo aj po zatvorení.
 */
class ShareActivity : AppCompatActivity() {

    private lateinit var b: ActivityShareBinding
    private val prefs by lazy { Prefs(this) }
    private var uris: List<Uri> = emptyList()
    private var all: List<RemoteFolder> = emptyList()
    private var shown: List<RemoteFolder> = emptyList()
    private var selected: RemoteFolder? = null
    private lateinit var adapter: ArrayAdapter<String>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!prefs.loggedIn) {
            Toast.makeText(this, "Najprv sa prihlás v aplikácii Cloud.", Toast.LENGTH_LONG).show()
            startActivity(Intent(this, LoginActivity::class.java)); finish(); return
        }
        b = ActivityShareBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        b.toolbar.setNavigationOnClickListener { finish() }

        uris = readUris(intent)
        if (uris.isEmpty()) { Toast.makeText(this, "Nič na nahratie.", Toast.LENGTH_SHORT).show(); finish(); return }
        val names = uris.map { nameOf(it) }
        b.count.text = if (uris.size == 1) "1 súbor" else "${uris.size} ${if (uris.size in 2..4) "súbory" else "súborov"}"
        b.names.text = names.joinToString(", ")

        adapter = ArrayAdapter(this, android.R.layout.simple_list_item_single_choice, mutableListOf())
        b.list.adapter = adapter
        b.list.setOnItemClickListener { _, _, pos, _ -> selected = shown[pos]; b.uploadBtn.isEnabled = true }
        b.search.addTextChangedListener(object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun onTextChanged(s: CharSequence?, a: Int, c: Int, d: Int) {}
            override fun afterTextChanged(s: Editable?) { filter() }
        })
        b.uploadBtn.setOnClickListener { upload() }
        b.newFolderBtn.setOnClickListener { newFolder() }
        loadFolders(prefs.shareFolder)
    }

    private fun readUris(i: Intent): List<Uri> {
        val out = ArrayList<Uri>()
        if (i.action == Intent.ACTION_SEND) {
            @Suppress("DEPRECATION")
            val u = if (Build.VERSION.SDK_INT >= 33) i.getParcelableExtra(Intent.EXTRA_STREAM, Uri::class.java) else i.getParcelableExtra(Intent.EXTRA_STREAM)
            if (u != null) out += u
        } else if (i.action == Intent.ACTION_SEND_MULTIPLE) {
            @Suppress("DEPRECATION")
            val l = if (Build.VERSION.SDK_INT >= 33) i.getParcelableArrayListExtra(Intent.EXTRA_STREAM, Uri::class.java) else i.getParcelableArrayListExtra(Intent.EXTRA_STREAM)
            if (l != null) out += l
        }
        if (out.isEmpty()) i.clipData?.let { c -> for (k in 0 until c.itemCount) c.getItemAt(k).uri?.let { out += it } }
        // zdieľaný iba text (odkaz, poznámka) → uloží sa ako .txt
        if (out.isEmpty()) i.getStringExtra(Intent.EXTRA_TEXT)?.takeIf { it.isNotBlank() }?.let { t ->
            val subj = i.getStringExtra(Intent.EXTRA_SUBJECT)?.replace(Regex("[\\\\/:*?\"<>|\\n]"), " ")?.trim()?.take(60)
            val name = (subj?.ifBlank { null } ?: ("Poznámka " + java.text.SimpleDateFormat("yyyy-MM-dd HH-mm", java.util.Locale.ROOT).format(java.util.Date()))) + ".txt"
            val f = File(File(cacheDir, "share_text").apply { mkdirs() }, name)
            f.writeText(t)
            out += Uri.fromFile(f)
        }
        return out.distinct()
    }

    private fun nameOf(u: Uri): String {
        try {
            contentResolver.query(u, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c -> if (c.moveToFirst() && !c.isNull(0)) return c.getString(0) }
        } catch (_: Exception) {}
        return u.lastPathSegment?.substringAfterLast('/') ?: "subor"
    }

    private fun loadFolders(selectId: Int) {
        b.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            val r = withContext(Dispatchers.IO) { runCatching { Api(prefs).folders() } }
            b.progress.visibility = View.GONE
            r.onSuccess { list ->
                all = list
                selected = list.firstOrNull { it.id == selectId } ?: list.firstOrNull()
                filter()
                b.uploadBtn.isEnabled = selected != null
            }.onFailure {
                MaterialAlertDialogBuilder(this@ShareActivity).setTitle("Cloud je nedostupný")
                    .setMessage(it.message ?: "Skontroluj pripojenie.")
                    .setPositiveButton("Skúsiť znova") { _, _ -> loadFolders(selectId) }
                    .setNegativeButton("Zavrieť") { _, _ -> finish() }.show()
            }
        }
    }

    private fun filter() {
        val q = b.search.text?.toString()?.trim().orEmpty()
        shown = if (q.isEmpty()) all else all.filter { it.path.contains(q, ignoreCase = true) }
        adapter.clear(); adapter.addAll(shown.map { it.path + if (it.shared) "  (zdieľané)" else "" })
        b.list.clearChoices()
        val idx = shown.indexOf(selected)
        if (idx >= 0) { b.list.setItemChecked(idx, true); if (q.isEmpty()) b.list.setSelection(maxOf(0, idx - 2)) }
    }

    private fun newFolder() {
        val parent = selected ?: return
        val input = EditText(this).apply { inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_CAP_SENTENCES; hint = "Názov zložky" }
        val box = FrameLayout(this).apply { setPadding(60, 20, 60, 0); addView(input) }
        MaterialAlertDialogBuilder(this).setTitle("Nová zložka v „${parent.path.substringAfterLast(" / ")}“")
            .setView(box)
            .setPositiveButton("Vytvoriť") { _, _ ->
                val name = input.text.toString().trim()
                if (name.isEmpty()) return@setPositiveButton
                lifecycleScope.launch {
                    val r = withContext(Dispatchers.IO) { runCatching { Api(prefs).mkdir(parent.id, name) } }
                    r.onSuccess { loadFolders(it) }.onFailure { Toast.makeText(this@ShareActivity, it.message, Toast.LENGTH_LONG).show() }
                }
            }
            .setNegativeButton("Zrušiť", null).show()
    }

    /** Skopíruje zdieľané súbory k sebe (povolenie od inej aplikácie po zatvorení zaniká) a pošle ich na nahratie. */
    private fun upload() {
        val target = selected ?: return
        prefs.shareFolder = target.id
        b.uploadBtn.isEnabled = false
        val bar = LinearProgressIndicator(this).apply { max = uris.size; setPadding(60, 40, 60, 20) }
        val dlg = MaterialAlertDialogBuilder(this).setTitle("Pripravujem súbory…").setView(bar).setCancelable(false).show()
        lifecycleScope.launch {
            val items = withContext(Dispatchers.IO) {
                val dir = File(filesDir, "share_in").apply { mkdirs() }
                uris.mapIndexedNotNull { i, u ->
                    val name = nameOf(u).replace(Regex("[\\\\/:*?\"<>|]"), "_")
                    val f = File(dir, System.nanoTime().toString() + "_" + name)
                    val ok = try { contentResolver.openInputStream(u)?.use { ins -> f.outputStream().use { ins.copyTo(it, 256 * 1024) } }; f.exists() } catch (_: Exception) { false }
                    withContext(Dispatchers.Main) { bar.setProgressCompat(i + 1, true) }
                    if (ok) Triple(f, name, f.length()) else null
                }
            }
            dlg.dismiss()
            if (items.isEmpty()) { Toast.makeText(this@ShareActivity, "Súbory sa nepodarilo načítať.", Toast.LENGTH_LONG).show(); b.uploadBtn.isEnabled = true; return@launch }
            UploadService.enqueueLocal(applicationContext, target.id, items)
            val skipped = uris.size - items.size
            Toast.makeText(this@ShareActivity, "Nahrávam ${items.size} do „${target.path.substringAfterLast(" / ")}“ – priebeh je v notifikácii" +
                (if (skipped > 0) " ($skipped sa nedalo načítať)" else ""), Toast.LENGTH_LONG).show()
            finish()
        }
    }
}
