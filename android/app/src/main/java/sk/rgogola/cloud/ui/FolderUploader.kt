package sk.rgogola.cloud.ui

import android.net.Uri
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.documentfile.provider.DocumentFile
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.progressindicator.LinearProgressIndicator
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException
import sk.rgogola.cloud.sync.SyncEngine
import java.io.IOException
import java.io.InputStream

/**
 * Jednorazové nahratie celej zložky z telefónu (aj s podzložkami) do zvolenej zložky v cloude.
 * Používa systémový výber zložky (WebView nevie vybrať zložku).
 * Musí sa vytvoriť v onCreate aktivity (registruje výber zložky).
 */
class FolderUploader(private val act: AppCompatActivity, private val onDone: () -> Unit) {

    private var targetFolder = 0
    private var job: Job? = null
    private val picker: ActivityResultLauncher<Uri?> =
        act.registerForActivityResult(ActivityResultContracts.OpenDocumentTree()) { uri -> if (uri != null) start(uri) }

    fun pick(cloudFolder: Int) {
        targetFolder = cloudFolder
        Toast.makeText(act, "Vyber zložku a ťukni na „Použiť túto zložku“", Toast.LENGTH_LONG).show()
        try { picker.launch(null) } catch (e: Exception) { Toast.makeText(act, "Výber zložky nie je dostupný", Toast.LENGTH_LONG).show() }
    }

    private fun start(tree: Uri) {
        val rootName = DocumentFile.fromTreeUri(act, tree)?.name ?: "Zložka"
        val box = LinearLayout(act).apply { orientation = LinearLayout.VERTICAL; setPadding(60, 30, 60, 10) }
        val text = TextView(act).apply { text = "Prehľadávam zložku…" }
        val file = TextView(act).apply { textSize = 12f; alpha = 0.7f }
        val bar = LinearProgressIndicator(act).apply { max = 100; isIndeterminate = true }
        box.addView(text); box.addView(bar); box.addView(file)
        val dlg = MaterialAlertDialogBuilder(act)
            .setTitle("Nahrávam „$rootName“")
            .setView(box)
            .setCancelable(false)
            .setNegativeButton("Zrušiť") { _, _ -> job?.cancel() }
            .show()

        act.window.addFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON) // nahrávanie tisícok súborov – obrazovka nezhasne
        job = act.lifecycleScope.launch {
          try {
            val api = Api(Prefs(act))
            var ok = 0; var unchanged = 0
            val errors = ArrayList<String>()
            val files = withContext(Dispatchers.IO) { runCatching { SyncEngine.scanTree(act, tree) } }
            val list = files.getOrElse {
                dlg.dismiss(); Toast.makeText(act, "Zložku sa nepodarilo prečítať: ${it.message}", Toast.LENGTH_LONG).show(); return@launch
            }
            if (list.isEmpty()) { dlg.dismiss(); Toast.makeText(act, "Zložka „$rootName“ je prázdna.", Toast.LENGTH_LONG).show(); return@launch }
            val total = list.sumOf { it.size }.coerceAtLeast(1)
            var doneBytes = 0L
            for ((i, f) in list.withIndex()) {
                if (!isActive) break
                val name = f.rel.substringAfterLast('/')
                text.text = "Súbor ${i + 1} z ${list.size}"
                file.text = "$rootName/${f.rel}"
                try {
                    val res = withContext(Dispatchers.IO) {
                        uploadOne(api, targetFolder, name, "$rootName/${f.rel}", f.size, { act.contentResolver.openInputStream(Uri.parse(f.docUri)) }) { sent ->
                            val pct = ((doneBytes + sent) * 100 / total).toInt()
                            act.runOnUiThread { bar.isIndeterminate = false; bar.setProgressCompat(pct, false) }
                        }
                    }
                    if (res) unchanged++ else ok++
                } catch (e: ApiException) {
                    if (e.code == 401 || e.message?.contains("kvóta") == true || e.message?.contains("Nedostatok miesta") == true) { errors += e.message ?: "Chyba"; break }
                    errors += "${f.rel}: ${e.message}"
                } catch (e: kotlinx.coroutines.CancellationException) {
                    break
                } catch (e: Exception) {
                    errors += "${f.rel}: ${e.message ?: e.javaClass.simpleName}"
                }
                doneBytes += f.size
            }
            if (dlg.isShowing) dlg.dismiss()
            onDone()
            val msg = buildString {
                append("Nahraté: $ok")
                if (unchanged > 0) append("\nUž boli v cloude: $unchanged")
                if (errors.isNotEmpty()) append("\nChyby: ${errors.size}\n\n" + errors.take(15).joinToString("\n"))
            }
            MaterialAlertDialogBuilder(act)
                .setTitle(if (errors.isEmpty()) "Zložka „$rootName“ je nahratá" else "Nahrávanie dokončené s chybami")
                .setMessage(msg)
                .setPositiveButton("OK", null)
                .show()
          } finally {
            act.window.clearFlags(android.view.WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
          }
        }
    }

    /** Nahrá súbor po častiach. Vráti true, ak rovnaký súbor už v cloude bol. */
    private suspend fun uploadOne(api: Api, folder: Int, name: String, rel: String, size: Long, open: () -> InputStream?, onSent: (Long) -> Unit): Boolean {
        var attempt = 0
        while (true) {
            try {
                val init = api.uploadInit(folder, name, size, rel, null)
                val input = open() ?: throw IOException("Súbor sa nedá otvoriť")
                input.use { ins ->
                    val buf = ByteArray(init.chunk.coerceIn(256 * 1024, 8 * 1024 * 1024))
                    var offset = 0L
                    while (true) {
                        var len = 0
                        while (len < buf.size) { val r = ins.read(buf, len, buf.size - len); if (r < 0) break; len += r }
                        val res = api.uploadChunk(init.id, offset, buf, len)
                        if (res.optBoolean("resync")) throw IOException("Nesúlad pozície pri nahrávaní")
                        offset = res.optLong("received", offset + len)
                        onSent(offset)
                        if (res.optBoolean("done")) return res.optJSONObject("file")?.optBoolean("unchanged") == true
                        if (len == 0) throw IOException("Server neukončil nahrávanie")
                    }
                }
            } catch (e: ApiException) {
                if (e.code in 500..599 && attempt < 3) { attempt++; delay(2000L * attempt); continue }
                throw e
            } catch (e: IOException) {
                if (attempt < 3) { attempt++; delay(2000L * attempt); continue }
                throw e
            }
        }
        @Suppress("UNREACHABLE_CODE")
        throw IOException("Nahrávanie sa nedokončilo")
    }
}
