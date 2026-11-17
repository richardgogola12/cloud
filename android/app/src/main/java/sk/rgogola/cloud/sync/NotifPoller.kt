package sk.rgogola.cloud.sync

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.net.Api
import sk.rgogola.cloud.net.ApiException

/** Stiahne nové upozornenia zo servera a zobrazí ich ako Android notifikácie. */
object NotifPoller {
    private val mutex = Mutex()

    suspend fun poll(ctx: Context) = mutex.withLock {
        val prefs = Prefs(ctx)
        if (!prefs.loggedIn || !prefs.notifyEnabled) return@withLock
        withContext(Dispatchers.IO) {
            try {
                val j = Api(prefs).notifications(prefs.lastNotifId)
                val items = j.optJSONArray("items")
                // pri prvom spustení nezobrazuj staré upozornenia
                if (prefs.lastNotifId > 0 && items != null) {
                    for (i in 0 until items.length()) {
                        val n = items.getJSONObject(i)
                        Notif.server(ctx, n.getInt("id"), n.optString("title"), n.optString("body"), n.optString("link").takeIf { it.isNotBlank() && it != "null" })
                    }
                }
                prefs.lastNotifId = maxOf(prefs.lastNotifId, j.optInt("last", 0))
            } catch (e: ApiException) {
                if (e.code == 401) prefs.authError = true
            } catch (_: Exception) { /* bez siete – skúsi sa neskôr */ }
        }
    }
}
