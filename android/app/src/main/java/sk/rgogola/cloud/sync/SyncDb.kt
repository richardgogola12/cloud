package sk.rgogola.cloud.sync

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

data class SyncPair(
    val id: Long,
    val treeUri: String,
    val localName: String,
    val remoteId: Int,
    val remoteName: String,
    val enabled: Boolean,
    val includeExisting: Boolean,
    val initialized: Boolean,
    val lastError: String?,
    /** po úspešnom nahratí súbor zmazať z telefónu */
    val deleteAfter: Boolean = false,
)

data class PairStats(val done: Int, val pending: Int, val errors: Int, val skipped: Int = 0)

data class LogEntry(val ts: Long, val msg: String, val ok: Boolean)

/** Lokálna databáza: páry zložiek (telefón → cloud) a stav nahratých súborov. */
class SyncDb private constructor(ctx: Context, name: String) : SQLiteOpenHelper(ctx.applicationContext, name, null, 2) {

    companion object {
        @Volatile private var inst: SyncDb? = null
        @Volatile private var instName: String? = null
        /** každý účet v aplikácii má vlastné páry zložiek a stav synchronizácie */
        private fun nameFor(ctx: Context): String {
            val p = sk.rgogola.cloud.Prefs(ctx)
            val key = p.currentKey
            if (p.syncOwner.isBlank() && p.userEmail.isNotBlank()) p.syncOwner = key
            return if (p.syncOwner.isBlank() || key == p.syncOwner) "sync.db" else "sync_$key.db"
        }
        fun get(ctx: Context): SyncDb {
            val n = nameFor(ctx)
            inst?.let { if (instName == n) return it }
            return synchronized(this) {
                inst?.takeIf { instName == n } ?: SyncDb(ctx, n).also { inst = it; instName = n }
            }
        }
        const val ST_PENDING = 0
        const val ST_DONE = 1
        const val ST_ERROR = 2
        const val ST_SKIPPED = 3 // existovali pred pridaním zložky a nemali sa nahrať
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("""CREATE TABLE pairs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tree_uri TEXT NOT NULL, local_name TEXT NOT NULL,
            remote_id INTEGER NOT NULL, remote_name TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1, include_existing INTEGER NOT NULL DEFAULT 0,
            initialized INTEGER NOT NULL DEFAULT 0, last_error TEXT, created INTEGER NOT NULL,
            delete_after INTEGER NOT NULL DEFAULT 0)""")
        db.execSQL("""CREATE TABLE files (
            pair_id INTEGER NOT NULL, rel TEXT NOT NULL, doc_uri TEXT NOT NULL,
            size INTEGER NOT NULL, mtime INTEGER NOT NULL, state INTEGER NOT NULL DEFAULT 0,
            upload_id TEXT, err TEXT, updated INTEGER NOT NULL,
            PRIMARY KEY (pair_id, rel))""")
        db.execSQL("CREATE TABLE log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, msg TEXT NOT NULL, ok INTEGER NOT NULL)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        if (oldVersion < 2) db.execSQL("ALTER TABLE pairs ADD COLUMN delete_after INTEGER NOT NULL DEFAULT 0")
    }

    // ---------------- páry ----------------
    fun pairs(onlyEnabled: Boolean = false): List<SyncPair> {
        val out = ArrayList<SyncPair>()
        readableDatabase.rawQuery("SELECT * FROM pairs" + (if (onlyEnabled) " WHERE enabled = 1" else "") + " ORDER BY id", null).use { c ->
            while (c.moveToNext()) {
                out += SyncPair(
                    c.getLong(c.getColumnIndexOrThrow("id")),
                    c.getString(c.getColumnIndexOrThrow("tree_uri")),
                    c.getString(c.getColumnIndexOrThrow("local_name")),
                    c.getInt(c.getColumnIndexOrThrow("remote_id")),
                    c.getString(c.getColumnIndexOrThrow("remote_name")),
                    c.getInt(c.getColumnIndexOrThrow("enabled")) == 1,
                    c.getInt(c.getColumnIndexOrThrow("include_existing")) == 1,
                    c.getInt(c.getColumnIndexOrThrow("initialized")) == 1,
                    c.getString(c.getColumnIndexOrThrow("last_error")),
                    c.getInt(c.getColumnIndexOrThrow("delete_after")) == 1,
                )
            }
        }
        return out
    }

    fun addPair(treeUri: String, localName: String, remoteId: Int, remoteName: String, includeExisting: Boolean, deleteAfter: Boolean = false): Long =
        writableDatabase.insert("pairs", null, ContentValues().apply {
            put("tree_uri", treeUri); put("local_name", localName)
            put("remote_id", remoteId); put("remote_name", remoteName)
            put("include_existing", if (includeExisting) 1 else 0)
            put("delete_after", if (deleteAfter) 1 else 0)
            put("created", System.currentTimeMillis())
        })

    fun setPairEnabled(id: Long, enabled: Boolean) {
        writableDatabase.execSQL("UPDATE pairs SET enabled = ? WHERE id = ?", arrayOf<Any>(if (enabled) 1 else 0, id))
    }

    fun setPairDeleteAfter(id: Long, on: Boolean) {
        writableDatabase.execSQL("UPDATE pairs SET delete_after = ? WHERE id = ?", arrayOf<Any>(if (on) 1 else 0, id))
    }

    /** Nahraté súbory zložky (na zmazanie z telefónu). */
    fun doneFiles(pairId: Long): List<FileRow> {
        val out = ArrayList<FileRow>()
        readableDatabase.rawQuery("SELECT rel, doc_uri, size, mtime, state, upload_id FROM files WHERE pair_id = ? AND state = ?", arrayOf(pairId.toString(), ST_DONE.toString())).use { c ->
            while (c.moveToNext()) out += FileRow(c.getString(0), c.getString(1), c.getLong(2), c.getLong(3), c.getInt(4), c.getString(5))
        }
        return out
    }

    fun forgetFile(pairId: Long, rel: String) {
        writableDatabase.delete("files", "pair_id = ? AND rel = ?", arrayOf(pairId.toString(), rel))
    }

    fun setPairInitialized(id: Long) {
        writableDatabase.execSQL("UPDATE pairs SET initialized = 1 WHERE id = ?", arrayOf<Any>(id))
    }

    fun setPairRemote(id: Long, remoteId: Int) {
        writableDatabase.execSQL("UPDATE pairs SET remote_id = ? WHERE id = ?", arrayOf<Any>(remoteId, id))
        writableDatabase.execSQL("UPDATE files SET state = ?, upload_id = NULL WHERE pair_id = ? AND state = ?", arrayOf<Any>(ST_PENDING, id, ST_ERROR))
    }

    fun setPairError(id: Long, err: String?) {
        writableDatabase.execSQL("UPDATE pairs SET last_error = ? WHERE id = ?", arrayOf<Any?>(err, id))
    }

    fun deletePair(id: Long) {
        writableDatabase.delete("files", "pair_id = ?", arrayOf(id.toString()))
        writableDatabase.delete("pairs", "id = ?", arrayOf(id.toString()))
    }

    fun stats(pairId: Long): PairStats {
        var done = 0; var pending = 0; var err = 0; var skipped = 0
        readableDatabase.rawQuery("SELECT state, COUNT(*) FROM files WHERE pair_id = ? GROUP BY state", arrayOf(pairId.toString())).use { c ->
            while (c.moveToNext()) when (c.getInt(0)) {
                ST_DONE -> done = c.getInt(1)
                ST_PENDING -> pending = c.getInt(1)
                ST_ERROR -> err = c.getInt(1)
                ST_SKIPPED -> skipped = c.getInt(1)
            }
        }
        return PairStats(done, pending, err, skipped)
    }

    // ---------------- súbory ----------------
    data class FileRow(val rel: String, val docUri: String, val size: Long, val mtime: Long, val state: Int, val uploadId: String?)

    fun files(pairId: Long): Map<String, FileRow> {
        val out = HashMap<String, FileRow>()
        readableDatabase.rawQuery("SELECT rel, doc_uri, size, mtime, state, upload_id FROM files WHERE pair_id = ?", arrayOf(pairId.toString())).use { c ->
            while (c.moveToNext()) out[c.getString(0)] = FileRow(c.getString(0), c.getString(1), c.getLong(2), c.getLong(3), c.getInt(4), c.getString(5))
        }
        return out
    }

    fun pendingFiles(pairId: Long): List<FileRow> {
        val out = ArrayList<FileRow>()
        readableDatabase.rawQuery("SELECT rel, doc_uri, size, mtime, state, upload_id FROM files WHERE pair_id = ? AND state IN (?, ?) ORDER BY mtime",
            arrayOf(pairId.toString(), ST_PENDING.toString(), ST_ERROR.toString())).use { c ->
            while (c.moveToNext()) out += FileRow(c.getString(0), c.getString(1), c.getLong(2), c.getLong(3), c.getInt(4), c.getString(5))
        }
        return out
    }

    fun upsertFile(pairId: Long, rel: String, docUri: String, size: Long, mtime: Long, state: Int) {
        writableDatabase.insertWithOnConflict("files", null, ContentValues().apply {
            put("pair_id", pairId); put("rel", rel); put("doc_uri", docUri)
            put("size", size); put("mtime", mtime); put("state", state)
            putNull("upload_id"); putNull("err")
            put("updated", System.currentTimeMillis())
        }, SQLiteDatabase.CONFLICT_REPLACE)
    }

    fun setFileState(pairId: Long, rel: String, state: Int, err: String? = null) {
        writableDatabase.execSQL("UPDATE files SET state = ?, err = ?, updated = ? WHERE pair_id = ? AND rel = ?",
            arrayOf<Any?>(state, err, System.currentTimeMillis(), pairId, rel))
    }

    /** Zaradí na nahratie všetky súbory zložky (aj tie, čo boli preskočené alebo už nahraté – server duplicity rozpozná). */
    fun requeueAll(pairId: Long): Int {
        val db = writableDatabase
        db.execSQL("UPDATE files SET state = ?, upload_id = NULL, err = NULL WHERE pair_id = ? AND state IN (?, ?)",
            arrayOf<Any>(ST_PENDING, pairId, ST_DONE, ST_SKIPPED))
        db.execSQL("UPDATE pairs SET include_existing = 1 WHERE id = ?", arrayOf<Any>(pairId))
        return stats(pairId).pending
    }

    fun setUploadId(pairId: Long, rel: String, uploadId: String?) {
        writableDatabase.execSQL("UPDATE files SET upload_id = ? WHERE pair_id = ? AND rel = ?", arrayOf<Any?>(uploadId, pairId, rel))
    }

    fun removeMissing(pairId: Long, present: Set<String>) {
        val db = writableDatabase
        files(pairId).keys.filter { it !in present }.forEach { db.delete("files", "pair_id = ? AND rel = ?", arrayOf(pairId.toString(), it)) }
    }

    fun totalPending(): Int = readableDatabase.rawQuery(
        "SELECT COUNT(*) FROM files f JOIN pairs p ON p.id = f.pair_id WHERE p.enabled = 1 AND f.state IN (?, ?)", arrayOf(ST_PENDING.toString(), ST_ERROR.toString())
    ).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    /** Súbory s chybou a jej popisom. */
    fun errorFiles(pairId: Long, limit: Int = 100): List<kotlin.Pair<String, String>> {
        val out = ArrayList<kotlin.Pair<String, String>>()
        readableDatabase.rawQuery("SELECT rel, COALESCE(err, '') FROM files WHERE pair_id = ? AND state = ? ORDER BY updated DESC LIMIT $limit",
            arrayOf(pairId.toString(), ST_ERROR.toString())).use { c -> while (c.moveToNext()) out += kotlin.Pair(c.getString(0), c.getString(1)) }
        return out
    }

    fun retryErrors(pairId: Long) {
        writableDatabase.execSQL("UPDATE files SET state = ?, upload_id = NULL WHERE pair_id = ? AND state = ?", arrayOf<Any>(ST_PENDING, pairId, ST_ERROR))
    }

    fun totalErrors(): Int = readableDatabase.rawQuery(
        "SELECT COUNT(*) FROM files f JOIN pairs p ON p.id = f.pair_id WHERE p.enabled = 1 AND f.state = ?", arrayOf(ST_ERROR.toString())
    ).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    /** Počet súborov nahratých od daného času (pre widget „dnes nahraté“). */
    fun uploadedSince(ts: Long): Int = readableDatabase.rawQuery(
        "SELECT COUNT(*) FROM log WHERE ok = 1 AND ts >= ? AND msg LIKE '%→%'", arrayOf(ts.toString())
    ).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    // ---------------- záznam ----------------
    fun log(msg: String, ok: Boolean = true) {
        val db = writableDatabase
        db.insert("log", null, ContentValues().apply { put("ts", System.currentTimeMillis()); put("msg", msg); put("ok", if (ok) 1 else 0) })
        db.execSQL("DELETE FROM log WHERE id NOT IN (SELECT id FROM log ORDER BY id DESC LIMIT 200)")
    }

    fun recentLog(limit: Int = 40): List<LogEntry> {
        val out = ArrayList<LogEntry>()
        readableDatabase.rawQuery("SELECT ts, msg, ok FROM log ORDER BY id DESC LIMIT $limit", null).use { c ->
            while (c.moveToNext()) out += LogEntry(c.getLong(0), c.getString(1), c.getInt(2) == 1)
        }
        return out
    }
}
