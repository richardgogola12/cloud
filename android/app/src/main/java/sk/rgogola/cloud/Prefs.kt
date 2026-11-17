package sk.rgogola.cloud

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKeys

/** Nastavenia aplikácie. Prístupový token je uložený šifrovane (Android Keystore). */
class Prefs(ctx: Context) {
    private val app = ctx.applicationContext
    private val p: SharedPreferences = app.getSharedPreferences("cloud", Context.MODE_PRIVATE)
    private val secure: SharedPreferences = try {
        EncryptedSharedPreferences.create(
            "cloud_secure",
            MasterKeys.getOrCreate(MasterKeys.AES256_GCM_SPEC),
            app,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    } catch (e: Exception) {
        app.getSharedPreferences("cloud_secure_fallback", Context.MODE_PRIVATE)
    }

    var server: String
        get() = p.getString("server", BuildConfig.DEFAULT_SERVER) ?: BuildConfig.DEFAULT_SERVER
        set(v) = p.edit().putString("server", v.trim().trimEnd('/')).apply()

    var token: String?
        get() = secure.getString("token", null)
        set(v) = secure.edit().putString("token", v).apply()

    var userName: String
        get() = p.getString("user_name", "") ?: ""
        set(v) = p.edit().putString("user_name", v).apply()

    var userEmail: String
        get() = p.getString("user_email", "") ?: ""
        set(v) = p.edit().putString("user_email", v).apply()

    var biometric: Boolean
        get() = p.getBoolean("biometric", false)
        set(v) = p.edit().putBoolean("biometric", v).apply()

    var syncEnabled: Boolean
        get() = p.getBoolean("sync_enabled", true)
        set(v) = p.edit().putBoolean("sync_enabled", v).apply()

    var wifiOnly: Boolean
        get() = p.getBoolean("wifi_only", false)
        set(v) = p.edit().putBoolean("wifi_only", v).apply()

    /** interval kontroly zmien v minútach */
    var intervalMin: Int
        get() = p.getInt("interval", 15)
        set(v) = p.edit().putInt("interval", v).apply()

    var lastSync: Long
        get() = p.getLong("last_sync", 0)
        set(v) = p.edit().putLong("last_sync", v).apply()

    var lastUpdateCheck: Long
        get() = p.getLong("last_update_check", 0)
        set(v) = p.edit().putLong("last_update_check", v).apply()

    var skippedVersion: Int
        get() = p.getInt("skipped_version", 0)
        set(v) = p.edit().putInt("skipped_version", v).apply()

    var lastNotifId: Int
        get() = p.getInt("last_notif_id", 0)
        set(v) = p.edit().putInt("last_notif_id", v).apply()

    var notifyEnabled: Boolean
        get() = p.getBoolean("notify_enabled", true)
        set(v) = p.edit().putBoolean("notify_enabled", v).apply()

    var authError: Boolean
        get() = p.getBoolean("auth_error", false)
        set(v) = p.edit().putBoolean("auth_error", v).apply()

    /** naposledy použité adresy servera (najnovšia prvá) */
    var serverHistory: List<String>
        get() = (p.getString("server_history", "") ?: "").split("\n").filter { it.isNotBlank() }
        set(v) = p.edit().putString("server_history", v.distinct().take(6).joinToString("\n")).apply()

    /** ručne vybrané súbory nahrávať natívne na pozadí (pokračuje aj po zatvorení aplikácie) */
    var bgUpload: Boolean
        get() = p.getBoolean("bg_upload", true)
        set(v) = p.edit().putBoolean("bg_upload", v).apply()

    var uploadTrees: Set<String>
        get() = p.getStringSet("upload_trees", emptySet())?.toSet() ?: emptySet()
        set(v) = p.edit().putStringSet("upload_trees", v).apply()

    var uploadReport: String
        get() = p.getString("upload_report", "") ?: ""
        set(v) = p.edit().putString("upload_report", v).apply()

    /** ručne potvrdené nastavenia výrobcu (autoštart, šetrič batérie) – systém ich nevie overiť */
    fun bgConfirmed(key: String) = p.getBoolean("bg_ok_$key", false)
    fun setBgConfirmed(key: String, v: Boolean) = p.edit().putBoolean("bg_ok_$key", v).apply()

    var bgPromptTs: Long
        get() = p.getLong("bg_prompt_ts", 0)
        set(v) = p.edit().putLong("bg_prompt_ts", v).apply()

    var bgPromptNever: Boolean
        get() = p.getBoolean("bg_prompt_never", false)
        set(v) = p.edit().putBoolean("bg_prompt_never", v).apply()

    val loggedIn get() = !token.isNullOrBlank()

    fun logout() {
        secure.edit().remove("token").apply()
        p.edit().remove("user_name").remove("user_email").remove("last_notif_id").putBoolean("biometric", false).apply()
    }
}
