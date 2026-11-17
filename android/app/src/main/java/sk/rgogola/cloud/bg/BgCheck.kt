package sk.rgogola.cloud.bg

import android.annotation.SuppressLint
import android.app.Activity
import android.app.ActivityManager
import android.app.NotificationManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.ConnectivityManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.core.app.NotificationManagerCompat
import sk.rgogola.cloud.Notif
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.sync.SyncDb
import sk.rgogola.cloud.sync.SyncService

/** Kontrola všetkého, čo potrebuje nahrávanie a synchronizácia na pozadí. */
object BgCheck {

    enum class State { OK, PROBLEM, CONFIRM, INFO }

    /**
     * @param action hlavné tlačidlo (otvorí príslušné nastavenie)
     * @param confirmKey pri nastaveniach výrobcu, ktoré systém nevie overiť – používateľ potvrdí „Mám zapnuté“
     */
    class Item(
        val key: String,
        val title: String,
        val desc: String,
        val state: State,
        val actionLabel: String?,
        val confirmKey: String? = null,
        val action: ((Activity) -> Unit)?,
    )

    private val maker get() = Build.MANUFACTURER.lowercase()
    val isXiaomi get() = maker.contains("xiaomi") || maker.contains("redmi") || maker.contains("poco")
    private val isHuawei get() = maker.contains("huawei")
    private val isHonor get() = maker.contains("honor")
    private val isOppo get() = maker.contains("oppo") || maker.contains("realme") || maker.contains("oneplus")
    private val isVivo get() = maker.contains("vivo") || maker.contains("iqoo")
    private val isSamsung get() = maker.contains("samsung")
    private val isAsus get() = maker.contains("asus")

    fun items(ctx: Context): List<Item> {
        val p = Prefs(ctx)
        val pkg = ctx.packageName
        val out = ArrayList<Item>()
        val pairs = SyncDb.get(ctx).pairs()

        // 1. upozornenia (bez nich nevidno priebeh a Android službu ľahšie zastaví)
        val notifOn = NotificationManagerCompat.from(ctx).areNotificationsEnabled() && Notif.canPost(ctx)
        val chOn = ctx.getSystemService(NotificationManager::class.java)?.getNotificationChannel(Notif.CH_SYNC)?.importance != NotificationManager.IMPORTANCE_NONE
        out += Item("notif", "Upozornenia",
            if (notifOn && chOn) "Priebeh nahrávania sa zobrazuje v notifikácii." else "Povoľ upozornenia aj kanál „Synchronizácia“ – bez nich nevidíš priebeh a systém nahrávanie ľahšie zastaví.",
            if (notifOn && chOn) State.OK else State.PROBLEM, "Povoliť") { a -> openNotifSettings(a) }

        // 2. optimalizácia batérie (Android)
        val pm = ctx.getSystemService(PowerManager::class.java)
        val batOk = pm?.isIgnoringBatteryOptimizations(pkg) == true
        out += Item("battery", "Batéria bez obmedzení",
            if (batOk) "Android aplikáciu na pozadí neuspáva." else "Android aplikáciu na pozadí uspáva a nahrávanie sa zastaví. Ťukni na „Povoliť“ a potvrď.",
            if (batOk) State.OK else State.PROBLEM, "Povoliť") { a -> requestBattery(a) }

        // 3. obmedzenie na pozadí (Android 9+: „Obmedzené“ využitie batérie)
        val am = ctx.getSystemService(ActivityManager::class.java)
        if (Build.VERSION.SDK_INT >= 28 && am != null && am.isBackgroundRestricted) {
            out += Item("restricted", "Obmedzená činnosť na pozadí",
                "V nastaveniach aplikácie je využitie batérie nastavené na „Obmedzené“. Zmeň ho na „Bez obmedzení“.",
                State.PROBLEM, "Otvoriť") { a -> openAppDetails(a) }
        }

        // 4. šetrič dát
        val cm = ctx.getSystemService(ConnectivityManager::class.java)
        val dataRestricted = cm?.restrictBackgroundStatus == ConnectivityManager.RESTRICT_BACKGROUND_STATUS_ENABLED
        out += Item("data", "Mobilné dáta na pozadí",
            if (dataRestricted) "Šetrič dát blokuje aplikácii internet na pozadí. Povoľ „Neobmedzené dáta“ pre Cloud." else "Aplikácia môže na pozadí používať internet.",
            if (dataRestricted) State.PROBLEM else State.OK, "Povoliť") { a -> openDataSettings(a) }

        // 5. nastavenia výrobcu (systém ich nevie overiť → potvrdenie)
        if (isXiaomi || isHuawei || isHonor || isOppo || isVivo || isAsus) {
            val ok = p.bgConfirmed("autostart")
            out += Item("autostart", "Automatické spustenie",
                if (ok) "Potvrdené – aplikácia sa môže spustiť sama (aj po reštarte)." else "Tento telefón (${Build.MANUFACTURER}) bráni aplikáciám spúšťať sa samé. Zapni pre Cloud „Autoštart“ a potom ťukni na „Mám zapnuté“.",
                if (ok) State.OK else State.CONFIRM, "Otvoriť autoštart", "autostart") { a -> openAutostart(a) }
        }
        if (isXiaomi) {
            val ok = p.bgConfirmed("miui_battery")
            out += Item("miui_battery", "Šetrič batérie Xiaomi",
                if (ok) "Potvrdené – nastavené „Bez obmedzení“." else "Xiaomi má vlastný šetrič batérie. V Nastaveniach aplikácie → Šetrič batérie zvoľ „Bez obmedzení“ a potom ťukni na „Mám zapnuté“.",
                if (ok) State.OK else State.CONFIRM, "Otvoriť šetrič", "miui_battery") { a -> openMiuiBattery(a) }
        }
        if (isSamsung) {
            val ok = p.bgConfirmed("samsung_sleep")
            out += Item("samsung_sleep", "Uspávanie aplikácií Samsung",
                if (ok) "Potvrdené – Cloud nie je medzi uspanými aplikáciami." else "V Starostlivosti o zariadenie → Batéria → Obmedzenia na pozadí pridaj Cloud do „Nikdy neuspávané aplikácie“.",
                if (ok) State.OK else State.CONFIRM, "Otvoriť", "samsung_sleep") { a -> openSamsung(a) }
        }

        // 6. prístup k sledovaným zložkám
        if (pairs.isNotEmpty()) {
            val granted = ctx.contentResolver.persistedUriPermissions.filter { it.isReadPermission }.map { it.uri.toString() }.toSet()
            val lost = pairs.filter { it.treeUri !in granted }
            out += Item("folders", "Prístup k zložkám",
                if (lost.isEmpty()) "Aplikácia má prístup ku všetkým ${pairs.size} sledovaným zložkám." else "Stratený prístup k: ${lost.joinToString { it.localName }}. Odstráň ich v Synchronizácii a pridaj znova.",
                if (lost.isEmpty()) State.OK else State.PROBLEM, "Synchronizácia") { a -> a.startActivity(Intent(a, sk.rgogola.cloud.ui.SyncActivity::class.java)) }
        }

        // 7. stav služby
        val should = SyncService.shouldRun(ctx)
        out += Item("service", "Synchronizácia zložiek",
            when {
                !p.syncEnabled -> "Automatická synchronizácia je vypnutá."
                pairs.isEmpty() -> "Zatiaľ nesleduješ žiadnu zložku z telefónu."
                p.authError -> "Prihlásenie vypršalo – prihlás sa znova."
                SyncService.running -> "Služba beží a sleduje ${pairs.count { it.enabled }} zložiek."
                else -> "Služba práve nebeží – ťukni na „Spustiť“."
            },
            when { !should -> State.INFO; SyncService.running -> State.OK; else -> State.PROBLEM },
            if (pairs.isEmpty()) "Pridať zložku" else "Spustiť") { a ->
                if (pairs.isEmpty()) a.startActivity(Intent(a, sk.rgogola.cloud.ui.SyncActivity::class.java))
                else sk.rgogola.cloud.CloudApp.kick(a.applicationContext, syncNow = true)
            }
        return out
    }

    /** počet vecí, ktoré treba nastaviť */
    fun problems(ctx: Context) = items(ctx).count { it.state == State.PROBLEM || it.state == State.CONFIRM }

    // ---------------- otváranie nastavení ----------------

    private fun tryStart(a: Activity, vararg intents: Intent): Boolean {
        for (i in intents) {
            try { a.startActivity(i); return true } catch (_: Exception) {}
        }
        return false
    }

    fun openAppDetails(a: Activity) {
        tryStart(a, Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${a.packageName}")))
    }

    private fun openNotifSettings(a: Activity) {
        if (Build.VERSION.SDK_INT >= 33 && !Notif.canPost(a)) {
            a.requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 77)
            return
        }
        tryStart(a, Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, a.packageName)) || run { openAppDetails(a); true }
    }

    @SuppressLint("BatteryLife")
    private fun requestBattery(a: Activity) {
        tryStart(a,
            Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${a.packageName}")),
            Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) || run { openAppDetails(a); true }
    }

    private fun openDataSettings(a: Activity) {
        tryStart(a, Intent(Settings.ACTION_IGNORE_BACKGROUND_DATA_RESTRICTIONS_SETTINGS, Uri.parse("package:${a.packageName}"))) || run { openAppDetails(a); true }
    }

    private fun cmp(pkg: String, cls: String) = Intent().setComponent(ComponentName(pkg, cls)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    private fun openAutostart(a: Activity) {
        val list = when {
            isXiaomi -> listOf(cmp("com.miui.securitycenter", "com.miui.permcenter.autostart.AutoStartManagementActivity"))
            isHuawei -> listOf(cmp("com.huawei.systemmanager", "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity"),
                cmp("com.huawei.systemmanager", "com.huawei.systemmanager.optimize.process.ProtectActivity"))
            isHonor -> listOf(cmp("com.hihonor.systemmanager", "com.hihonor.systemmanager.startupmgr.ui.StartupNormalAppListActivity"))
            isOppo -> listOf(cmp("com.coloros.safecenter", "com.coloros.safecenter.permission.startup.StartupAppListActivity"),
                cmp("com.coloros.safecenter", "com.coloros.safecenter.startupapp.StartupAppListActivity"),
                cmp("com.oppo.safe", "com.oppo.safe.permission.startup.StartupAppListActivity"),
                cmp("com.oneplus.security", "com.oneplus.security.chainlaunch.view.ChainLaunchAppListActivity"))
            isVivo -> listOf(cmp("com.vivo.permissionmanager", "com.vivo.permissionmanager.activity.BgStartUpManagerActivity"),
                cmp("com.iqoo.secure", "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity"))
            isAsus -> listOf(cmp("com.asus.mobilemanager", "com.asus.mobilemanager.entry.FunctionActivity"))
            else -> emptyList()
        }
        if (!tryStart(a, *list.toTypedArray())) openAppDetails(a)
    }

    private fun openMiuiBattery(a: Activity) {
        val label = a.applicationInfo.loadLabel(a.packageManager).toString()
        val ok = tryStart(a,
            cmp("com.miui.powerkeeper", "com.miui.powerkeeper.ui.HiddenAppsConfigActivity").putExtra("package_name", a.packageName).putExtra("package_label", label))
        if (!ok) openAppDetails(a)
    }

    private fun openSamsung(a: Activity) {
        if (!tryStart(a, cmp("com.samsung.android.lool", "com.samsung.android.sm.battery.ui.BatteryActivity"),
                cmp("com.samsung.android.lool", "com.samsung.android.sm.ui.battery.BatteryActivity"))) openAppDetails(a)
    }
}
