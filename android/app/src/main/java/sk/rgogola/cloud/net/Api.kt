package sk.rgogola.cloud.net

import android.os.Build
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import sk.rgogola.cloud.BuildConfig
import sk.rgogola.cloud.Prefs
import java.io.IOException
import java.util.concurrent.TimeUnit

class ApiException(message: String, val code: Int = 0) : IOException(message)

data class RemoteFolder(val id: Int, val path: String, val shared: Boolean)
data class UploadInit(val id: String, val received: Long, val chunk: Int)

/** Komunikácia so serverom Cloud (app.php + api.php) cez token v hlavičke X-Cloud-Token. */
class Api(private val prefs: Prefs) {

    companion object {
        val USER_AGENT = "CloudAndroid/${BuildConfig.VERSION_NAME} (Android ${Build.VERSION.RELEASE}; ${Build.MANUFACTURER} ${Build.MODEL})"
        val client: OkHttpClient = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(120, TimeUnit.SECONDS)
            .writeTimeout(180, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .sslSocketFactory(Trust.socketFactory, Trust.trustManager)
            .hostnameVerifier(Trust.hostnameVerifier)
            .build()
        private val JSON = "application/json; charset=utf-8".toMediaType()
        private val OCTET = "application/octet-stream".toMediaType()
    }

    private fun url(path: String, server: String = prefs.server) = server.trimEnd('/') + "/" + path

    private fun builder(path: String, server: String = prefs.server): Request.Builder {
        val b = Request.Builder().url(url(path, server))
            .header("User-Agent", USER_AGENT)
            .header("X-Requested-With", "XMLHttpRequest")
            .header("Accept", "application/json")
        prefs.token?.let { b.header("X-Cloud-Token", it) }
        return b
    }

    private fun exec(req: Request): JSONObject {
        client.newCall(req).execute().use { resp ->
            val body = resp.body?.string().orEmpty()
            if (resp.code == 401) throw ApiException("Prihlásenie vypršalo – prihlás sa znova.", 401)
            val json = try { JSONObject(body) } catch (e: Exception) {
                throw ApiException("Server vrátil neplatnú odpoveď (HTTP ${resp.code}). Skontroluj adresu servera.", resp.code)
            }
            if (!json.optBoolean("ok")) throw ApiException(json.optString("error", "Neznáma chyba servera"), resp.code)
            return json
        }
    }

    private fun post(path: String, vararg params: Pair<String, String>): JSONObject {
        val form = FormBody.Builder()
        params.forEach { (k, v) -> form.add(k, v) }
        return exec(builder(path).post(form.build()).build())
    }

    /** Prihlásenie e-mailom a heslom → vráti token. */
    fun login(server: String, email: String, password: String): JSONObject {
        val body = JSONObject()
            .put("email", email).put("password", password)
            .put("device", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
        val req = Request.Builder().url(url("app.php?action=login", server))
            .header("User-Agent", USER_AGENT)
            .header("Accept", "application/json")
            .post(body.toString().toRequestBody(JSON))
            .build()
        return exec(req)
    }

    fun me(): JSONObject = exec(builder("app.php?action=me").get().build())

    /** Platí aktuálny token aj na inej adrese servera? */
    fun meAt(server: String): JSONObject = exec(builder("app.php?action=me", server).get().build())

    /** Overenie adresy servera bez prihlásenia. */
    fun info(server: String): JSONObject {
        val req = Request.Builder().url(url("app.php?action=info", server)).header("User-Agent", USER_AGENT).header("Accept", "application/json").get().build()
        client.newCall(req).execute().use { resp ->
            val body = resp.body?.string().orEmpty()
            val json = try { JSONObject(body) } catch (e: Exception) {
                throw ApiException("Na tejto adrese nie je Cloud server (HTTP ${resp.code}).", resp.code)
            }
            // starší server nepozná akciu info, ale odpovedá vo formáte Cloud
            if (!json.optBoolean("ok")) return JSONObject().put("ok", true).put("app", "cloud").put("name", "Cloud").put("version", "").put("old", true)
            return json
        }
    }

    fun logout() { try { exec(builder("app.php?action=logout").post(FormBody.Builder().build()).build()) } catch (_: Exception) {} }

    fun folders(): List<RemoteFolder> {
        val arr = post("api.php?action=folders").getJSONArray("results")
        return (0 until arr.length()).map {
            val o = arr.getJSONObject(it)
            RemoteFolder(o.getInt("id"), o.getString("text"), o.optBoolean("shared"))
        }
    }

    fun mkdir(parent: Int, name: String): Int = post("api.php?action=mkdir", "parent" to parent.toString(), "name" to name).getInt("id")

    fun uploadInit(folder: Int, name: String, size: Long, relPath: String, resume: String?): UploadInit {
        val j = post("api.php?action=upload_init",
            "folder" to folder.toString(), "name" to name, "size" to size.toString(),
            "relpath" to relPath, "resume" to (resume ?: ""))
        return UploadInit(j.getString("id"), j.optLong("received", 0), j.optInt("chunk", 2 * 1024 * 1024))
    }

    /** Odošle jednu časť súboru; vráti JSON (received, done, file). */
    fun uploadChunk(id: String, offset: Long, data: ByteArray, len: Int): JSONObject {
        val body = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("id", id)
            .addFormDataPart("offset", offset.toString())
            .addFormDataPart("chunk", "chunk", data.toRequestBody(OCTET, 0, len))
            .build()
        return exec(builder("api.php?action=upload_chunk").post(body).build())
    }

    fun uploadCancel(id: String) { try { post("api.php?action=upload_cancel", "id" to id) } catch (_: Exception) {} }

    /** Stiahne súbor z cloudu do [out]; progress v percentách. */
    fun download(id: Int, out: java.io.File, progress: (Int) -> Unit) {
        val req = builder("api.php?action=download&id=$id").get().build()
        client.newCall(req).execute().use { resp ->
            if (resp.code == 401) throw ApiException("Prihlásenie vypršalo – prihlás sa znova.", 401)
            if (!resp.isSuccessful) throw ApiException("Súbor sa nepodarilo stiahnuť (HTTP ${resp.code}).", resp.code)
            val body = resp.body ?: throw ApiException("Prázdna odpoveď servera")
            val total = body.contentLength()
            val tmp = java.io.File(out.parentFile, out.name + ".part")
            body.byteStream().use { input ->
                tmp.outputStream().use { o ->
                    val buf = ByteArray(64 * 1024)
                    var done = 0L
                    while (true) {
                        val n = input.read(buf)
                        if (n < 0) break
                        o.write(buf, 0, n)
                        done += n
                        if (total > 0) progress((done * 100 / total).toInt())
                    }
                }
            }
            if (!tmp.renameTo(out)) throw ApiException("Uloženie súboru zlyhalo")
        }
    }

    /** Nové upozornenia od [after]. */
    fun notifications(after: Int): JSONObject = exec(builder("app.php?action=notifications&after=$after").get().build())

    /** URL a hlavičky pre prihlásenie WebView do webového rozhrania. */
    fun webLogin(to: String = "index.php"): Pair<String, Map<String, String>> =
        url("app.php?action=web&to=" + android.net.Uri.encode(to)) to mapOf("X-Cloud-Token" to (prefs.token ?: ""))
}
