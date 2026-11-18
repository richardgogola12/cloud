package sk.rgogola.cloud.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Color
import android.graphics.pdf.PdfRenderer
import android.os.Bundle
import android.os.ParcelFileDescriptor
import android.util.LruCache
import android.view.Menu
import android.view.ViewGroup
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.RecyclerView
import androidx.viewpager2.widget.ViewPager2
import kotlinx.coroutines.asCoroutineDispatcher
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.databinding.ActivityPdfBinding
import java.io.File
import java.util.concurrent.Executors

/** Vstavaný prehliadač PDF (Android PdfRenderer) – stránky sa listujú do strán, dajú sa priblížiť. */
class PdfActivity : AppCompatActivity() {

    companion object {
        const val EXTRA_PATH = "path"
        const val EXTRA_NAME = "name"
    }

    private lateinit var b: ActivityPdfBinding
    private var renderer: PdfRenderer? = null
    private var pfd: ParcelFileDescriptor? = null
    private val renderThread = Executors.newSingleThreadExecutor().asCoroutineDispatcher()
    private val cache = object : LruCache<Int, Bitmap>(48 * 1024 * 1024) {
        override fun sizeOf(key: Int, value: Bitmap) = value.byteCount
    }
    private lateinit var file: File

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityPdfBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        file = File(intent.getStringExtra(EXTRA_PATH) ?: run { finish(); return })
        b.toolbar.title = intent.getStringExtra(EXTRA_NAME) ?: file.name
        b.toolbar.setNavigationOnClickListener { finish() }
        val menu: Menu = b.toolbar.menu
        menu.add("Otvoriť v inej aplikácii").setOnMenuItemClickListener { external(false); true }
        menu.add("Poslať").setOnMenuItemClickListener { external(true); true }

        try {
            pfd = ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)
            renderer = PdfRenderer(pfd!!)
        } catch (e: Exception) {
            Toast.makeText(this, "PDF sa nedá otvoriť (môže byť chránené heslom).", Toast.LENGTH_LONG).show()
            external(false); finish(); return
        }
        val count = renderer!!.pageCount
        b.toolbar.subtitle = "1 / $count"
        b.pager.offscreenPageLimit = 1
        b.pager.adapter = PageAdapter(count)
        b.pager.registerOnPageChangeCallback(object : ViewPager2.OnPageChangeCallback() {
            override fun onPageSelected(position: Int) { b.toolbar.subtitle = "${position + 1} / $count" }
        })
    }

    private suspend fun render(index: Int, targetW: Int): Bitmap? = withContext(renderThread) {
        cache.get(index)?.let { return@withContext it }
        val r = renderer ?: return@withContext null
        try {
            r.openPage(index).use { p ->
                // dvojnásobné rozlíšenie, aby bol text ostrý aj po priblížení
                val w = (targetW * 2).coerceIn(600, 2600)
                val h = (w.toFloat() * p.height / p.width).toInt().coerceAtMost(4000)
                val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
                bmp.eraseColor(Color.WHITE)
                p.render(bmp, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                cache.put(index, bmp)
                bmp
            }
        } catch (e: Exception) { null }
    }

    private inner class PageAdapter(val count: Int) : RecyclerView.Adapter<PageAdapter.VH>() {
        inner class VH(val v: ZoomImageView) : RecyclerView.ViewHolder(v)
        override fun getItemCount() = count
        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(ZoomImageView(parent.context).apply {
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
        })
        override fun onBindViewHolder(h: VH, position: Int) {
            h.v.setImageDrawable(null)
            h.v.tag = position
            val w = resources.displayMetrics.widthPixels
            lifecycleScope.launch {
                val bmp = render(position, w)
                if (h.v.tag == position && bmp != null) h.v.setImageBitmap(bmp)
            }
        }
    }

    private fun external(send: Boolean) {
        val uri = FileProvider.getUriForFile(this, "$packageName.files", file)
        val i = if (send) Intent(Intent.ACTION_SEND).setType("application/pdf").putExtra(Intent.EXTRA_STREAM, uri)
        else Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/pdf")
        try {
            startActivity(Intent.createChooser(i.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), if (send) "Poslať cez…" else "Otvoriť v…"))
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, "Nie je k dispozícii iná aplikácia.", Toast.LENGTH_LONG).show()
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        val r = renderer; val f = pfd
        renderer = null
        // zatvoriť v tom istom vlákne, kde sa kreslí (PdfRenderer nie je vláknovo bezpečný)
        renderThread.executor.execute {
            try { r?.close() } catch (_: Exception) {}
            try { f?.close() } catch (_: Exception) {}
        }
        renderThread.close()
        cache.evictAll()
    }
}
