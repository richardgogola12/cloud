package sk.rgogola.cloud.ui

import android.annotation.SuppressLint
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import sk.rgogola.cloud.CloudApp
import sk.rgogola.cloud.Prefs
import sk.rgogola.cloud.R
import sk.rgogola.cloud.bg.BgCheck
import sk.rgogola.cloud.databinding.ActivityBackgroundBinding
import sk.rgogola.cloud.databinding.ItemBgCheckBinding
import sk.rgogola.cloud.sync.SyncService
import sk.rgogola.cloud.sync.SyncWidget
import sk.rgogola.cloud.sync.UploadQueue
import sk.rgogola.cloud.sync.UploadService

/** Prehľad a nastavenie všetkého, čo potrebuje nahrávanie na pozadí. */
class BackgroundActivity : AppCompatActivity() {

    private lateinit var b: ActivityBackgroundBinding
    private val prefs by lazy { Prefs(this) }
    private val handler = Handler(Looper.getMainLooper())
    private val ticker = object : Runnable { override fun run() { refresh(); handler.postDelayed(this, 3000) } }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        b = ActivityBackgroundBinding.inflate(layoutInflater)
        setContentView(b.root)
        applySystemInsets(b.root)
        b.toolbar.setNavigationOnClickListener { finish() }

        b.swBgUpload.isChecked = prefs.bgUpload
        b.swBgUpload.setOnCheckedChangeListener { _, on -> prefs.bgUpload = on }
        b.swSync.isChecked = prefs.syncEnabled
        b.swSync.setOnCheckedChangeListener { _, on ->
            prefs.syncEnabled = on
            if (on) CloudApp.kick(applicationContext, true) else SyncService.stop(this)
            SyncWidget.updateAll(this)
            handler.postDelayed({ refresh() }, 800)
        }
        b.swWifi.isChecked = prefs.wifiOnly
        b.swWifi.setOnCheckedChangeListener { _, on -> prefs.wifiOnly = on; CloudApp.kick(applicationContext, true) }
        b.appSettingsBtn.setOnClickListener { BgCheck.openAppDetails(this) }
        b.syncNowBtn.setOnClickListener {
            prefs.authError = false
            CloudApp.kick(applicationContext, syncNow = true)
            Toast.makeText(this, "Synchronizácia sa spustila", Toast.LENGTH_SHORT).show()
            handler.postDelayed({ refresh() }, 1500)
        }
    }

    override fun onResume() {
        super.onResume()
        handler.post(ticker)
    }

    override fun onPause() {
        super.onPause()
        handler.removeCallbacks(ticker)
    }

    @Deprecated("Deprecated in Java")
    @Suppress("DEPRECATION")
    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        refresh()
    }

    @SuppressLint("SetTextI18n")
    private fun refresh() {
        val items = BgCheck.items(this)
        val todo = items.count { it.state == BgCheck.State.PROBLEM || it.state == BgCheck.State.CONFIRM }
        if (todo == 0) {
            b.summary.text = "✓ Všetko je nastavené"
            b.summarySub.text = "Nahrávanie a synchronizácia pobežia na pozadí aj po zatvorení aplikácie a po reštarte telefónu."
            b.summaryCard.setCardBackgroundColor(0x1A188038)
        } else {
            b.summary.text = "Treba nastaviť: $todo"
            b.summarySub.text = "Prejdi body označené oranžovo. Po návrate do aplikácie sa stav sám aktualizuje."
            b.summaryCard.setCardBackgroundColor(0x1AE37400)
        }

        b.checks.removeAllViews()
        for (it in items) {
            val ib = ItemBgCheckBinding.inflate(layoutInflater, b.checks, false)
            ib.title.text = it.title
            ib.desc.text = it.desc
            val (sym, color) = when (it.state) {
                BgCheck.State.OK -> "✓" to R.color.ok
                BgCheck.State.PROBLEM -> "!" to R.color.warn
                BgCheck.State.CONFIRM -> "?" to R.color.warn
                BgCheck.State.INFO -> "i" to R.color.ink_muted
            }
            ib.icon.text = sym
            ib.icon.background = GradientDrawable().apply { shape = GradientDrawable.OVAL; setColor(ContextCompat.getColor(this@BackgroundActivity, color)) }
            val showAction = it.action != null && it.state != BgCheck.State.OK
            ib.action.visibility = if (showAction) View.VISIBLE else View.GONE
            ib.action.text = it.actionLabel
            ib.action.setOnClickListener { _ -> it.action?.invoke(this) }
            val key = it.confirmKey
            ib.confirm.visibility = if (key != null && it.state == BgCheck.State.CONFIRM) View.VISIBLE else View.GONE
            ib.confirm.setOnClickListener { prefs.setBgConfirmed(key ?: return@setOnClickListener, true); refresh() }
            // pri potvrdenom nastavení výrobcu dovoľ otvoriť znova (dlhým podržaním)
            if (key != null && it.state == BgCheck.State.OK) {
                ib.root.setOnLongClickListener { _ -> prefs.setBgConfirmed(key, false); refresh(); true }
            }
            ib.buttons.visibility = if (ib.action.visibility == View.VISIBLE || ib.confirm.visibility == View.VISIBLE) View.VISIBLE else View.GONE
            b.checks.addView(ib.root)
        }

        val q = UploadQueue.size(this)
        b.queueInfo.text = when {
            UploadService.running && q > 0 -> "Práve sa nahráva – vo fronte zostáva $q."
            q > 0 -> "Vo fronte čaká $q súborov."
            else -> "Žiadne nahrávanie neprebieha."
        }
        b.swSync.isChecked = prefs.syncEnabled
    }
}
