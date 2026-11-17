package sk.rgogola.cloud.net

import android.content.Context
import android.net.http.SslCertificate
import android.os.Build
import java.io.ByteArrayInputStream
import java.security.KeyStore
import java.security.MessageDigest
import java.security.cert.CertificateException
import java.security.cert.CertificateFactory
import java.security.cert.X509Certificate
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.concurrent.TimeUnit
import javax.net.ssl.HostnameVerifier
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.SSLSocketFactory
import javax.net.ssl.TrustManagerFactory
import javax.net.ssl.X509TrustManager
import okhttp3.OkHttpClient
import okhttp3.Request

/**
 * Dôvera k vlastnému (self-signed) certifikátu servera, napr. https://192.168.1.50/cloud.
 * Používateľ certifikát raz potvrdí (odtlačok SHA-256) a potom sa prijíma – ostatné certifikáty
 * sa overujú bežne cez systém.
 */
object Trust {
    private var app: Context? = null

    fun init(ctx: Context) { app = ctx.applicationContext }

    private fun sp() = app?.getSharedPreferences("cloud_trust", Context.MODE_PRIVATE)

    fun trusted(): Set<String> = sp()?.getStringSet("sha256", emptySet())?.toSet() ?: emptySet()
    fun add(fp: String) { sp()?.edit()?.putStringSet("sha256", trusted() + fp)?.apply() }
    fun clear() { sp()?.edit()?.remove("sha256")?.apply() }
    fun isTrusted(cert: X509Certificate?) = cert != null && fingerprint(cert) in trusted()

    fun fingerprint(cert: X509Certificate): String =
        MessageDigest.getInstance("SHA-256").digest(cert.encoded).joinToString(":") { "%02X".format(it) }

    private val systemTm: X509TrustManager by lazy {
        val f = TrustManagerFactory.getInstance(TrustManagerFactory.getDefaultAlgorithm())
        f.init(null as KeyStore?)
        f.trustManagers.filterIsInstance<X509TrustManager>().first()
    }

    /** Systémové overenie + výnimka pre certifikáty potvrdené používateľom. */
    val trustManager: X509TrustManager = object : X509TrustManager {
        override fun checkClientTrusted(chain: Array<out X509Certificate>, authType: String) = systemTm.checkClientTrusted(chain, authType)
        override fun checkServerTrusted(chain: Array<out X509Certificate>, authType: String) {
            try {
                systemTm.checkServerTrusted(chain, authType)
            } catch (e: CertificateException) {
                if (chain.isNotEmpty() && isTrusted(chain[0])) return
                throw e
            }
        }
        override fun getAcceptedIssuers(): Array<X509Certificate> = systemTm.acceptedIssuers
    }

    val socketFactory: SSLSocketFactory by lazy {
        SSLContext.getInstance("TLS").apply { init(null, arrayOf(trustManager), null) }.socketFactory
    }

    /** Pri potvrdenom certifikáte nevadí, že je vystavený na iné meno (napr. prístup cez IP adresu). */
    val hostnameVerifier = HostnameVerifier { host, session ->
        HttpsURLConnection.getDefaultHostnameVerifier().verify(host, session) ||
            (try { session.peerCertificates.firstOrNull() as? X509Certificate } catch (_: Exception) { null }).let { isTrusted(it) }
    }

    /** Certifikát z chyby SSL vo WebView. */
    fun fromWebView(c: SslCertificate?): X509Certificate? {
        if (c == null) return null
        if (Build.VERSION.SDK_INT >= 29) return c.x509Certificate
        return try {
            val bytes = SslCertificate.saveState(c).getByteArray("x509-certificate") ?: return null
            CertificateFactory.getInstance("X.509").generateCertificate(ByteArrayInputStream(bytes)) as X509Certificate
        } catch (_: Exception) { null }
    }

    /**
     * Zistí certifikát servera (bez toho, aby sa mu dôverovalo) – na zobrazenie používateľovi.
     * Spojenie sa po prečítaní certifikátu úmyselne preruší.
     */
    fun peekCertificate(url: String): X509Certificate? {
        var seen: X509Certificate? = null
        val capture = object : X509TrustManager {
            override fun checkClientTrusted(chain: Array<out X509Certificate>, authType: String) = systemTm.checkClientTrusted(chain, authType)
            override fun checkServerTrusted(chain: Array<out X509Certificate>, authType: String) {
                seen = chain.firstOrNull()
                throw CertificateException("Certifikát iba na kontrolu")
            }
            override fun getAcceptedIssuers(): Array<X509Certificate> = systemTm.acceptedIssuers
        }
        val sf = SSLContext.getInstance("TLS").apply { init(null, arrayOf(capture), null) }.socketFactory
        val c = OkHttpClient.Builder().sslSocketFactory(sf, capture).connectTimeout(10, TimeUnit.SECONDS).build()
        try { c.newCall(Request.Builder().url(url).head().build()).execute().close() } catch (_: Exception) {}
        return seen
    }

    fun describe(cert: X509Certificate): String {
        val df = SimpleDateFormat("d.M.yyyy", Locale.getDefault())
        val cn = Regex("CN=([^,]+)").find(cert.subjectX500Principal.name)?.groupValues?.get(1) ?: cert.subjectX500Principal.name
        val issuer = Regex("CN=([^,]+)").find(cert.issuerX500Principal.name)?.groupValues?.get(1) ?: cert.issuerX500Principal.name
        return "Vystavený pre: $cn\nVydavateľ: $issuer\nPlatný do: ${df.format(cert.notAfter)}\n\nOdtlačok SHA-256:\n${fingerprint(cert)}"
    }
}
