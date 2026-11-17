package sk.rgogola.cloud.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.webkit.MimeTypeMap
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.progressindicator.LinearProgressIndicator
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.net.Api
import java.io.File

/**
 * „Otvoriť v aplikácii“ a „Poslať“: stiahne súbor z cloudu do dočasnej pamäte
 * a odovzdá ho inej aplikácii (Word, PDF čítačka, galéria, e-mail, WhatsApp…).
 */
class FileOpener(private val act: AppCompatActivity) {

    private var job: Job? = null

    /** uri: cloudapp://open?id=..&name=..&mime=..&size=..  (alebo cloudapp://send?...) */
    fun handle(uri: Uri) {
        val id = uri.getQueryParameter("id")?.toIntOrNull() ?: return
        val name = (uri.getQueryParameter("name") ?: "subor").replace(Regex("[\\\\/:*?\"<>|]"), "_")
        val size = uri.getQueryParameter("size")?.toLongOrNull() ?: -1L
        val send = uri.host == "send"
        val mime = mimeFor(name, uri.getQueryParameter("mime"))

        val dir = File(act.cacheDir, "open").apply { mkdirs() }
        dir.listFiles()?.forEach { if (System.currentTimeMillis() - it.lastModified() > 24 * 3600 * 1000L) it.delete() }
        val file = File(dir, "${id}_$name")
        if (file.exists() && size >= 0 && file.length() == size) { deliver(file, mime, send); return }

        val bar = LinearProgressIndicator(act).apply { max = 100; isIndeterminate = true; setPadding(60, 40, 60, 20) }
        val dlg = MaterialAlertDialogBuilder(act)
            .setTitle(if (send) "Pripravujem „$name“…" else "Otváram „$name“…")
            .setView(bar)
            .setNegativeButton("Zrušiť") { _, _ -> job?.cancel() }
            .setCancelable(false)
            .show()
        job = act.lifecycleScope.launch {
            val res = withContext(Dispatchers.IO) {
                runCatching {
                    Api(Prefs(act)).download(id, file) { p -> act.runOnUiThread { bar.isIndeterminate = false; bar.setProgressCompat(p, true) } }
                }
            }
            if (dlg.isShowing) dlg.dismiss()
            res.onSuccess { deliver(file, mime, send) }
                .onFailure { if (it !is kotlinx.coroutines.CancellationException) Toast.makeText(act, it.message ?: "Sťahovanie zlyhalo", Toast.LENGTH_LONG).show() }
        }
    }

    private fun mimeFor(name: String, given: String?): String {
        val ext = name.substringAfterLast('.', "").lowercase()
        val byExt = if (ext.isNotEmpty()) MimeTypeMap.getSingleton().getMimeTypeFromExtension(ext) else null
        return when {
            !byExt.isNullOrBlank() -> byExt
            !given.isNullOrBlank() && given != "application/octet-stream" -> given
            else -> "*/*"
        }
    }

    private fun deliver(file: File, mime: String, send: Boolean) {
        val uri = FileProvider.getUriForFile(act, "${act.packageName}.files", file)
        val intent = if (send) {
            Intent(Intent.ACTION_SEND).setType(mime).putExtra(Intent.EXTRA_STREAM, uri)
        } else {
            Intent(Intent.ACTION_VIEW).setDataAndType(uri, mime)
        }.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        try {
            act.startActivity(Intent.createChooser(intent, if (send) "Poslať cez…" else "Otvoriť v…").addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(act, "V telefóne nie je aplikácia, ktorá vie otvoriť tento typ súboru.", Toast.LENGTH_LONG).show()
        }
    }
}
