# ☁ Cloud

Súkromný cloudový disk (podobný Google Drive) – webová aplikácia v PHP/MySQL a Android aplikácia.

| Časť | Priečinok | Verzia | Vydania |
|---|---|---|---|
| Webová aplikácia (server) | [`server/`](server) | [`server/VERSION`](server/VERSION) | tagy `server-vX.Y.Z` |
| Android aplikácia | [`android/`](android) | `MAJOR.MINOR` v [`android/version.properties`](android/version.properties) + číslo buildu | tagy `android-vX.Y.N` |

## Android aplikácia

**Stiahnuť:** [cloud-android.apk (najnovšia verzia)](https://github.com/richardgogola12/cloud/releases/latest/download/cloud-android.apk)

- rovnaké rozhranie ako web (WebView), prihlásenie e-mailom a heslom + **odomykanie odtlačkom prsta**
- **synchronizácia zložiek**: vyberieš zložky v telefóne (napr. DCIM/Camera) a cieľovú zložku v cloude;
  nové a zmenené súbory sa nahrajú automaticky (zmenený súbor = nová verzia v cloude)
- služba beží **nepretržite** a **spustí sa sama po reštarte** telefónu (+ strážca cez WorkManager)
- nahrávanie po častiach s pokračovaním po výpadku, voľba „iba Wi-Fi“
- **automatické aktualizácie** z GitHub Releases (kontrola raz denne + ručne v menu)

Build prebieha automaticky v **GitHub Actions** pri každej zmene v `android/` – vznikne podpísané APK a vydanie.
Lokálne: Android Studio (JDK 17) → otvor priečinok `android/`.

Verzie nástrojov: Gradle 8.11.1 · AGP 8.7.3 · Kotlin 2.0.21 · compileSdk/targetSdk 35 · minSdk 26 (Android 8.0+).

> **Podpisový kľúč:** pre jednoduchosť je v `android/keystore/`. Repozitár je verejný, preto ho odporúčam nahradiť
> vlastným cez GitHub Secrets (`SIGNING_KEYSTORE_BASE64`, `SIGNING_STORE_PASSWORD`, `SIGNING_KEY_ALIAS`, `SIGNING_KEY_PASSWORD`).
> Pozor: po zmene kľúča treba aplikáciu raz odinštalovať a nainštalovať znova.

## Webová aplikácia

Návod na inštaláciu je v [`server/README.md`](server/README.md). Skopíruj `config.sample.php` ako `config.php`.

**Aktualizácia:** Administrácia → **Aktualizácia** – stiahne nové vydanie z GitHubu, zálohuje súbory
a prepíše ich (config.php a storage/ zostanú). Databáza sa upraví automaticky.

Nové vydanie servera vznikne, keď sa zvýši číslo v `server/VERSION`.
