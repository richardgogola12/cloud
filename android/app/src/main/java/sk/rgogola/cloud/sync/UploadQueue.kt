package sk.rgogola.cloud.sync

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * Fronta ručne vybraných súborov/zložiek na nahratie na pozadí.
 * Ukladá sa do súboru, takže prežije aj reštart aplikácie.
 */
object UploadQueue {
    data class Item(val uri: String, val folder: Int, val rel: String, val size: Long, val tree: Boolean)

    private var list: ArrayList<Item>? = null
    private var dirty = 0

    private fun file(ctx: Context) = File(ctx.filesDir, "upload_queue.json")

    @Synchronized private fun items(ctx: Context): ArrayList<Item> {
        list?.let { return it }
        val out = ArrayList<Item>()
        try {
            val f = file(ctx)
            if (f.exists()) {
                val arr = JSONArray(f.readText())
                for (i in 0 until arr.length()) {
                    val o = arr.getJSONObject(i)
                    out += Item(o.getString("u"), o.getInt("f"), o.optString("r"), o.optLong("s", -1), o.optBoolean("t"))
                }
            }
        } catch (_: Exception) {}
        list = out
        return out
    }

    @Synchronized private fun save(ctx: Context, force: Boolean = true) {
        if (!force && ++dirty < 20) return
        dirty = 0
        val arr = JSONArray()
        for (it in items(ctx)) arr.put(JSONObject().put("u", it.uri).put("f", it.folder).put("r", it.rel).put("s", it.size).put("t", it.tree))
        try { file(ctx).writeText(arr.toString()) } catch (_: Exception) {}
    }

    @Synchronized fun add(ctx: Context, add: List<Item>) { items(ctx).addAll(add); save(ctx) }
    @Synchronized fun first(ctx: Context): Item? = items(ctx).firstOrNull()
    @Synchronized fun size(ctx: Context): Int = items(ctx).size
    @Synchronized fun removeFirst(ctx: Context) { val l = items(ctx); if (l.isNotEmpty()) l.removeAt(0); save(ctx, force = l.isEmpty()) }
    @Synchronized fun replaceFirst(ctx: Context, with: List<Item>) { val l = items(ctx); if (l.isNotEmpty()) l.removeAt(0); l.addAll(0, with); save(ctx) }
    @Synchronized fun clear(ctx: Context) { items(ctx).clear(); save(ctx) }
    @Synchronized fun flush(ctx: Context) { if (list != null) save(ctx) }
}
