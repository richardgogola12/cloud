package sk.rgogola.cloud.sync

import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.PowerManager
import android.provider.DocumentsContract
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException
import java.io.IOException
import java.io.InputStream

/**
 * Jadro synchronizácie: prehľadá sledované zložky v telefóne a nové alebo zmenené súbory nahrá
 * do zvolenej zložky v cloude (jednosmerne – mazanie v telefóne sa do cloudu neprenáša).
 */
object SyncEngine {
    private val mutex = Mutex()

    @Volatile var status: String = "Pripravené"
        private set
    @Volatile var busy = false
        private set
    /** priebeh aktuálneho súboru (0–100) alebo null */
    @Volatile var progressPct: Int? = null
        private set
    @Volatile var currentFile: String? = null
        private set
    private var lastWidget = 0L

    const val ACTION_CHANGED = "sk.rgogola.cloud.SYNC_CHANGED"

    private data class LocalFile(val rel: String, val docUri: String, val size: Long, val mtime: Long)

    private val IGNORED_SUFFIX = listOf(".tmp", ".part", ".crdownload", ".partial", "~")

    fun networkOk(ctx: Context, wifiOnly: Boolean): Boolean {
        val cm = ctx.getSystemService(ConnectivityManager::class.java) ?: return false
        val caps = cm.getNetworkCapabilities(cm.activeNetwork ?: return false) ?: return false
        if (!caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)) return false
        if (wifiOnly && !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED)) return false
        return true
    }

    private fun setStatus(ctx: Context, s: String) {
        status = s
        ctx.sendBroadcast(Intent(ACTION_CHANGED).setPackage(ctx.packageName))
        widget(ctx, force = true)
    }

    private fun widget(ctx: Context, force: Boolean = false) {
        val now = System.currentTimeMillis()
        if (!force && now - lastWidget < 1500) return
        lastWidget = now
        try { SyncWidget.updateAll(ctx) } catch (_: Exception) {}
    }

    private fun report(ctx: Context, label: String, pct: Int?, progress: ((String, Int?) -> Unit)?) {
        progressPct = pct
        progress?.invoke(label, pct)
        widget(ctx)
    }

    /**
     * Spustí jeden beh synchronizácie. Ak už beží, vráti false.
     * @param progress (text, percentá alebo null) – pre notifikáciu
     */
    suspend fun run(ctx: Context, progress: ((String, Int?) -> Unit)? = null): Boolean {
        if (!mutex.tryLock()) return false
        val app = ctx.applicationContext
        val pm = app.getSystemService(PowerManager::class.java)
        val wl = pm?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "Cloud:sync")
        try {
            busy = true
            wl?.acquire(30 * 60 * 1000L)
            withContext(Dispatchers.IO) { doRun(app, progress) }
            return true
        } finally {
            busy = false
            progressPct = null
            currentFile = null
            if (wl?.isHeld == true) wl.release()
            mutex.unlock()
            setStatus(app, status)
        }
    }

    private suspend fun doRun(ctx: Context, progress: ((String, Int?) -> Unit)?) {
        val prefs = Prefs(ctx)
        val db = SyncDb.get(ctx)
        if (!prefs.loggedIn) { setStatus(ctx, "Nie si prihlásený"); return }
        if (!prefs.syncEnabled) { setStatus(ctx, "Synchronizácia je vypnutá"); return }
        val pairs = db.pairs(onlyEnabled = true)
        if (pairs.isEmpty()) { setStatus(ctx, "Žiadne sledované zložky"); return }
        if (!networkOk(ctx, prefs.wifiOnly)) { setStatus(ctx, if (prefs.wifiOnly) "Čaká sa na Wi-Fi" else "Bez pripojenia na internet"); return }

        val api = Api(prefs)
        val persisted = ctx.contentResolver.persistedUriPermissions.filter { it.isReadPermission }.map { it.uri.toString() }.toSet()

        // 1) prehľadanie zložiek
        setStatus(ctx, "Kontrolujem zmeny…")
        for (pair in pairs) {
            if (pair.treeUri !in persisted) { db.setPairError(pair.id, "Stratený prístup k zložke – odstráň ju a pridaj znova."); continue }
            val list = try { scanTree(ctx, Uri.parse(pair.treeUri)) } catch (e: Exception) {
                db.setPairError(pair.id, "Zložku sa nepodarilo prečítať: ${e.message}"); continue
            }
            val known = db.files(pair.id)
            val wdb = db.writableDatabase
            wdb.beginTransaction()
            try {
                for (f in list) {
                    val k = known[f.rel]
                    if (k == null) {
                        val st = if (!pair.initialized && !pair.includeExisting) SyncDb.ST_SKIPPED else SyncDb.ST_PENDING
                        db.upsertFile(pair.id, f.rel, f.docUri, f.size, f.mtime, st)
                    } else if (k.size != f.size || k.mtime != f.mtime) {
                        db.upsertFile(pair.id, f.rel, f.docUri, f.size, f.mtime, SyncDb.ST_PENDING)
                    }
                }
                db.removeMissing(pair.id, list.map { it.rel }.toSet())
                wdb.setTransactionSuccessful()
            } finally { wdb.endTransaction() }
            if (!pair.initialized) db.setPairInitialized(pair.id)
        }

        // 1b) cieľová zložka v cloude: overenie a oprava ID (staršie verzie servera mohli vrátiť zlé ID)
        val remote = try { api.folders() } catch (e: ApiException) {
            if (e.code == 401) { prefs.authError = true; Notif.authError(ctx); setStatus(ctx, "Prihlásenie vypršalo – prihlás sa znova"); return }
            null
        } catch (e: Exception) { null }
        val validPairs = ArrayList<SyncPair>()
        for (pair in pairs) {
            if (remote == null || remote.any { it.id == pair.remoteId }) { validPairs += pair; continue }
            val match = remote.firstOrNull { it.path == pair.remoteName }
            if (match != null) {
                db.setPairRemote(pair.id, match.id)
                db.setPairError(pair.id, null)
                db.log("Opravená cieľová zložka „${pair.remoteName}“")
                validPairs += pair.copy(remoteId = match.id)
            } else {
                db.setPairError(pair.id, "Cieľová zložka „${pair.remoteName}“ v cloude neexistuje – odstráň túto zložku a pridaj ju znova.")
            }
        }

        // 2) nahrávanie
        val todo = validPairs.flatMap { p -> db.pendingFiles(p.id).map { p to it } }
        var n = 0; var ok = 0; var failed = 0
        for ((pair, row) in todo) {
            if (!prefs.syncEnabled || !prefs.loggedIn) break
            if (!networkOk(ctx, prefs.wifiOnly)) { setStatus(ctx, "Prerušené – čaká sa na pripojenie"); return }
            if (System.currentTimeMillis() - row.mtime in 0 until 5000) continue // súbor sa ešte zapisuje (dátum v budúcnosti neblokuje)
            n++
            val name = row.rel.substringAfterLast('/')
            val label = "Nahrávam $n/${todo.size}: $name"
            currentFile = name
            setStatus(ctx, label)
            report(ctx, label, 0, progress)
            try {
                val versioned = uploadOne(ctx, api, db, pair, row) { pct -> report(ctx, label, pct, progress) }
                db.setFileState(pair.id, row.rel, SyncDb.ST_DONE)
                db.setPairError(pair.id, null)
                db.log("${pair.localName}/${row.rel} → ${pair.remoteName}" + when (versioned) { true -> " (nová verzia)"; null -> " (už v cloude)"; else -> "" })
                ok++
            } catch (e: ApiException) {
                if (e.code == 401) {
                    prefs.authError = true
                    db.log("Prihlásenie vypršalo – synchronizácia zastavená", false)
                    Notif.authError(ctx)
                    setStatus(ctx, "Prihlásenie vypršalo – prihlás sa znova")
                    return
                }
                val msg = e.message ?: "Chyba"
                if (msg.contains("Zložka neexistuje") || msg.contains("prístup")) db.setPairError(pair.id, "Cieľová zložka v cloude: $msg")
                if (msg.contains("kvóta") || msg.contains("Nedostatok miesta")) {
                    db.setFileState(pair.id, row.rel, SyncDb.ST_ERROR, msg)
                    db.log(msg, false)
                    Notif.alert(ctx, 3, "Cloud: plné úložisko", msg)
                    setStatus(ctx, msg)
                    return
                }
                db.setFileState(pair.id, row.rel, SyncDb.ST_ERROR, msg)
                db.log("${row.rel}: $msg", false)
                failed++
            } catch (e: Exception) {
                db.setFileState(pair.id, row.rel, SyncDb.ST_ERROR, e.message)
                db.log("${row.rel}: ${e.message ?: e.javaClass.simpleName}", false)
                failed++
                if (!networkOk(ctx, prefs.wifiOnly)) { setStatus(ctx, "Prerušené – výpadok spojenia"); return }
            }
        }
        prefs.lastSync = System.currentTimeMillis()
        prefs.authError = false
        val pending = db.totalPending()
        setStatus(ctx, when {
            ok == 0 && failed == 0 && pending == 0 -> "Všetko je synchronizované"
            failed > 0 -> "Nahraté: $ok, chyby: $failed (skúsim znova)"
            pending > 0 -> "Nahraté: $ok, čaká: $pending"
            else -> "Nahraté: $ok súborov"
        })
    }

    private fun scanTree(ctx: Context, tree: Uri): List<LocalFile> {
        val out = ArrayList<LocalFile>()
        val cols = arrayOf(
            DocumentsContract.Document.COLUMN_DOCUMENT_ID,
            DocumentsContract.Document.COLUMN_DISPLAY_NAME,
            DocumentsContract.Document.COLUMN_MIME_TYPE,
            DocumentsContract.Document.COLUMN_SIZE,
            DocumentsContract.Document.COLUMN_LAST_MODIFIED,
        )
        fun walk(docId: String, prefix: String, depth: Int) {
            if (depth > 30) return
            val children = DocumentsContract.buildChildDocumentsUriUsingTree(tree, docId)
            ctx.contentResolver.query(children, cols, null, null, null)?.use { c ->
                while (c.moveToNext()) {
                    val id = c.getString(0) ?: continue
                    val name = c.getString(1) ?: continue
                    val mime = c.getString(2) ?: ""
                    if (name.startsWith(".") || IGNORED_SUFFIX.any { name.endsWith(it, ignoreCase = true) }) continue
                    if (mime == DocumentsContract.Document.MIME_TYPE_DIR) {
                        walk(id, "$prefix$name/", depth + 1)
                    } else {
                        val size = if (c.isNull(3)) 0L else c.getLong(3)
                        val mtime = if (c.isNull(4)) 0L else c.getLong(4)
                        out += LocalFile(prefix + name, DocumentsContract.buildDocumentUriUsingTree(tree, id).toString(), size, mtime)
                    }
                }
            }
        }
        walk(DocumentsContract.getTreeDocumentId(tree), "", 0)
        return out
    }

    private fun readFully(input: InputStream, buf: ByteArray): Int {
        var total = 0
        while (total < buf.size) {
            val r = input.read(buf, total, buf.size - total)
            if (r < 0) break
            total += r
        }
        return total
    }

    private fun skipFully(input: InputStream, n: Long) {
        var left = n
        val tmp = ByteArray(64 * 1024)
        while (left > 0) {
            val s = input.skip(left)
            if (s > 0) { left -= s; continue }
            val r = input.read(tmp, 0, minOf(tmp.size.toLong(), left).toInt())
            if (r < 0) throw IOException("Súbor je kratší, ako sa čakalo")
            left -= r
        }
    }

    /** Nahrá jeden súbor po častiach (s pokračovaním). Vráti true = nová verzia, null = rovnaký súbor už v cloude bol. */
    private suspend fun uploadOne(ctx: Context, api: Api, db: SyncDb, pair: SyncPair, row: SyncDb.FileRow, onPct: (Int) -> Unit): Boolean? {
        val name = row.rel.substringAfterLast('/')
        val init = retry { api.uploadInit(pair.remoteId, name, row.size, row.rel, row.uploadId) }
        db.setUploadId(pair.id, row.rel, init.id)
        var offset = init.received
        val input = ctx.contentResolver.openInputStream(Uri.parse(row.docUri)) ?: throw IOException("Súbor sa nedá otvoriť")
        input.use { ins ->
            if (offset > 0) skipFully(ins, offset)
            val buf = ByteArray(init.chunk.coerceIn(256 * 1024, 8 * 1024 * 1024))
            while (true) {
                val len = if (row.size == 0L) 0 else readFully(ins, buf)
                if (len == 0 && offset < row.size) throw IOException("Súbor sa počas nahrávania zmenil")
                val res = retry { api.uploadChunk(init.id, offset, buf, len) }
                if (res.optBoolean("resync")) throw IOException("Nesúlad pozície – nahrávanie bude pokračovať pri ďalšom behu")
                offset = res.optLong("received", offset + len)
                if (row.size > 0) onPct((offset * 100 / row.size).toInt())
                if (res.optBoolean("done")) {
                    db.setUploadId(pair.id, row.rel, null)
                    val f = res.optJSONObject("file")
                    return if (f?.optBoolean("unchanged") == true) null else f?.optBoolean("versioned") == true
                }
                if (len == 0) throw IOException("Server neukončil nahrávanie")
            }
        }
        throw IOException("Neočakávaný koniec nahrávania")
    }

    /** Opakuje požiadavku pri výpadku siete (nie pri chybe vrátenej serverom). */
    private suspend fun <T> retry(block: () -> T): T {
        var wait = 2000L
        repeat(4) {
            try { return block() } catch (e: ApiException) {
                if (e.code in 500..599) { delay(wait); wait *= 2 } else throw e
            } catch (e: IOException) { delay(wait); wait *= 2 }
        }
        return block()
    }
}
