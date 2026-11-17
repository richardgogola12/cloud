package sk.rgogola.cloud.update

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import androidx.core.content.FileProvider
import okhttp3.Request
import org.json.JSONArray
import sk.rgogola.cloud.BuildConfig
import sk.rgogola.cloud.net.Api
import java.io.File
import java.io.IOException

/**
 * Aktualizácia aplikácie z GitHub Releases (tagy android-vX.Y.Z, súbor cloud-android-<versionCode>.apk).
 */
object Updater {
    data class Release(val versionCode: Int, val versionName: String, val apkUrl: String, val notes: String, val size: Long)

    private val ASSET = Regex("^cloud-android-(\\d+)\\.apk$")
    private val TAG = Regex("^android-v(\\d+\\.\\d+\\.\\d+)$")

    /** Najnovšie vydanie (blokujúce volanie – spúšťať mimo hlavného vlákna). */
    fun latest(): Release? {
        val req = Request.Builder()
            .url("https://api.github.com/repos/${BuildConfig.UPDATE_REPO}/releases?per_page=20")
            .header("Accept", "application/vnd.github+json")
            .header("User-Agent", Api.USER_AGENT)
            .build()
        Api.client.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) throw IOException("GitHub odpovedal ${resp.code}")
            val arr = JSONArray(resp.body?.string() ?: "[]")
            var best: Release? = null
            for (i in 0 until arr.length()) {
                val r = arr.getJSONObject(i)
                if (r.optBoolean("draft") || r.optBoolean("prerelease")) continue
                val tag = TAG.find(r.optString("tag_name")) ?: continue
                val assets = r.optJSONArray("assets") ?: continue
                for (j in 0 until assets.length()) {
                    val a = assets.getJSONObject(j)
                    val m = ASSET.find(a.optString("name")) ?: continue
                    val code = m.groupValues[1].toInt()
                    if (best == null || code > best.versionCode) {
                        best = Release(code, tag.groupValues[1], a.getString("browser_download_url"), r.optString("body"), a.optLong("size"))
                    }
                }
            }
            return best
        }
    }

    fun isNewer(r: Release) = r.versionCode > BuildConfig.VERSION_CODE

    /** Stiahne APK; progress v percentách. */
    fun download(ctx: Context, r: Release, progress: (Int) -> Unit): File {
        val dir = File(ctx.getExternalFilesDir(null), "updates").apply { mkdirs() }
        dir.listFiles()?.forEach { it.delete() }
        val out = File(dir, "cloud-${r.versionCode}.apk")
        val req = Request.Builder().url(r.apkUrl).header("User-Agent", Api.USER_AGENT).build()
        Api.client.newCall(req).execute().use { resp ->
            if (!resp.isSuccessful) throw IOException("Sťahovanie zlyhalo (HTTP ${resp.code})")
            val body = resp.body ?: throw IOException("Prázdna odpoveď")
            val total = body.contentLength().takeIf { it > 0 } ?: r.size
            body.byteStream().use { input ->
                out.outputStream().use { o ->
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
        }
        return out
    }

    fun canInstall(ctx: Context) = ctx.packageManager.canRequestPackageInstalls()

    fun openInstallPermission(ctx: Context) {
        ctx.startActivity(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${ctx.packageName}")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    fun install(ctx: Context, apk: File) {
        val uri = FileProvider.getUriForFile(ctx, "${ctx.packageName}.files", apk)
        ctx.startActivity(Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
