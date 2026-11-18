import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

// ---- verzia: MAJOR.MINOR z version.properties, číslo buildu z CI (GitHub Actions run_number) ----
val versionProps = Properties().apply { rootProject.file("version.properties").inputStream().use { load(it) } }
val buildNumber = (System.getenv("BUILD_NUMBER") ?: project.findProperty("buildNumber")?.toString() ?: "1").toInt()
val appVersionName = "${versionProps["MAJOR"]}.${versionProps["MINOR"]}.$buildNumber"

android {
    namespace = "sk.rgogola.cloud"
    compileSdk = 35

    defaultConfig {
        applicationId = "sk.rgogola.cloud"
        minSdk = 26
        targetSdk = 35
        versionCode = buildNumber
        versionName = appVersionName
        buildConfigField("String", "DEFAULT_SERVER", "\"https://rgogola-wms.eu/cloud\"")
        buildConfigField("String", "UPDATE_REPO", "\"richardgogola12/cloud\"")
    }

    signingConfigs {
        create("release") {
            // Kľúč z GitHub Secrets (ak sú nastavené), inak kľúč z repozitára
            val envStore = System.getenv("SIGNING_STORE_FILE")
            storeFile = if (!envStore.isNullOrBlank()) file(envStore) else rootProject.file("keystore/cloud-release.jks")
            storePassword = System.getenv("SIGNING_STORE_PASSWORD")?.takeIf { it.isNotBlank() } ?: "cloudapp2026"
            keyAlias = System.getenv("SIGNING_KEY_ALIAS")?.takeIf { it.isNotBlank() } ?: "cloud"
            keyPassword = System.getenv("SIGNING_KEY_PASSWORD")?.takeIf { it.isNotBlank() } ?: "cloudapp2026"
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.getByName("release")
        }
        debug {
            signingConfig = signingConfigs.getByName("release") // rovnaký podpis → aktualizácie fungujú aj z debug buildov
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }

    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.2.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("androidx.viewpager2:viewpager2:1.1.0")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.work:work-runtime-ktx:2.10.0")
    implementation("androidx.biometric:biometric:1.1.0")
    implementation("androidx.security:security-crypto:1.0.0")
    implementation("androidx.documentfile:documentfile:1.0.1")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")
}
