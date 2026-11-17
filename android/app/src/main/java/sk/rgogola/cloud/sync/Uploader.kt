package sk.rgogola.cloud.sync

import kotlinx.coroutines.delay
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException
import java.io.IOException
import java.io.InputStream

/** Nahratie jedného súboru po častiach s opakovaním pri výpadku. */
object Uploader {
    /** Vráti true, ak rovnaký súbor už v cloude bol. */
    suspend fun uploadOne(api: Api, folder: Int, name: String, rel: String, size: Long, open: () -> InputStream?, onSent: (Long) -> Unit): Boolean {
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
                if (attempt < 5) { attempt++; delay(3000L * attempt); continue }
                throw e
            }
        }
    }
}
