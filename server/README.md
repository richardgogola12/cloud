# ☁ Cloud – osobný disk (PHP + MySQL)

Webová aplikácia na nahrávanie a správu súborov a zložiek v štýle Google Drive.
PHP 8.1+, MySQL/MariaDB, Bootstrap 5, Select2, PWA, prihlásenie odtlačkom prsta (WebAuthn/passkeys).

## Funkcie
- **Súbory a zložky:** nahrávanie viacerých súborov aj celých zložiek (tlačidlo alebo drag & drop), priebeh nahrávania,
  vytváranie zložiek, premenovanie, presun (aj ťahaním na zložku / do cesty), kópia, mazanie, hviezdičky,
  vyhľadávanie, Nedávne, zoradenie, zoznam/mriežka, miniatúry obrázkov, náhľad (obrázky, video, audio, PDF, text)
  so swipe na mobile, stiahnutie zložky alebo výberu ako ZIP, informácie o položke, klávesové skratky (Del, F2, Ctrl+A, Esc).
- **Mobil:** responzívne rozhranie, plávajúce tlačidlo +, dlhé podržanie = výber, odfotiť a nahrať.
- **PWA:** inštalovateľná na plochu, offline stránka, ikony, skratky.
- **Účty:** registrácia, prihlásenie, „Zapamätať si ma“, zabudnuté heslo cez e-mail, zmena hesla/e-mailu,
  **prihlásenie odtlačkom prsta / Face ID / Windows Hello** (správa zariadení v Môj účet).
- **Admin:** prehľad (štatistiky, najväčší používatelia, aktivita), správa používateľov (vytvoriť, upraviť, rola,
  **kvóta miesta** s predvoľbami alebo bez limitu, aktivovať/deaktivovať, schvaľovanie registrácií, poslať odkaz na heslo,
  zmazať aj so súbormi), nastavenia (režim registrácie, predvolená kvóta, max. veľkosť súboru), SMTP s testom, záznamy aktivity.
- **E-maily (SMTP, bez knižníc):** obnova hesla, uvítanie, čakanie na schválenie / aktivácia účtu, notifikácia adminom,
  upozornenie pri 90 % zaplnení, zmena kvóty, zmena hesla/e-mailu.
- **Bezpečnosť:** CSRF tokeny, prepared statements, `password_hash`, obmedzenie pokusov o prihlásenie,
  súbory mimo dosahu webu s náhodnými názvami, sťahovanie s CSP sandbox, kontrola vlastníctva pri každej operácii.

## Novinky vo verzii 2
- **Kôš** – zmazané položky sa dajú obnoviť (aj tlačidlom „Späť“), automatické vysypanie po N dňoch (nastaviteľné).
- **Verejné odkazy** – na súbor aj zložku, voliteľne s heslom a dátumom platnosti, počítadlo stiahnutí (`s.php`).
- **Zdieľanie medzi používateľmi** – čítanie alebo úpravy, sekcia „Zdieľané so mnou“, e-mailové upozornenie.
- **Nahrávanie po častiach** – obchádza limit `upload_max_filesize`, pri výpadku spojenia pokračuje; po zatvorení stránky sa pri opätovnom nahratí toho istého súboru pokračuje od miesta prerušenia.
- **Verzie súborov** – nahratie súboru s rovnakým názvom uloží novú verziu; história, obnovenie, stiahnutie starej verzie.
- **Textový editor** v prehliadači (Ctrl+S) a nový textový súbor.
- **Náhľad Word (.docx) a Excel (.xlsx, .ods, .csv)**.
- **Fotky** – galéria obrázkov zoskupená po mesiacoch podľa dátumu odfotenia (EXIF).
- **Zdieľať → Cloud** z iných aplikácií v mobile (PWA share target).
- **Prihlásené zariadenia** v Môj účet s odhlásením; zmena hesla odhlási ostatné zariadenia.
- **E-mail pri prihlásení z nového zariadenia.**
- **Overenie e-mailu** pri registrácii a potvrdenie pri zmene e-mailu.
- **Admin:** graf využitia v čase, rozdelenie podľa typu súborov, hromadná zmena kvóty a ďalšie hromadné akcie.

Databáza sa pri aktualizácii **upraví automaticky** pri prvom načítaní stránky (nové tabuľky a stĺpce, prefix `cloud_`).

## Session pri viacerých weboch na serveri
Aplikácia má vlastnú, izolovanú session:
- vlastný názov cookie (`session_name` v `config.php` – zmeň na unikátny),
- cookie obmedzená na cestu aplikácie (z `app_url`),
- vlastný adresár na session súbory `storage/sessions` (iné weby ich nečítajú ani nemažú cez GC),
- session je naviazaná na túto aplikáciu (ak by sa cookie dostala inam, údaje sa zahodia).

## Inštalácia
1. Nahraj súbory na server (napr. `/var/www/cloud`).
2. Vytvor databázu (utf8mb4) a používateľa.
3. Uprav `config.php` – hlavne **`app_url`** (presná adresa vrátane `https://`), DB, SMTP a `session_name`.
   Odporúčané: `storage_path` mimo webrootu, napr. `/var/www/cloud-data` (musí byť zapisovateľný pre PHP).
4. Otvor `https://tvoja-domena/install/`, vytvor admin účet.
5. **Zmaž priečinok `install/`.**
6. SMTP môžeš doladiť v Administrácia → E-mail (SMTP) a poslať si test.

Všetky tabuľky majú prefix `cloud_` (schéma: `install/schema.sql`).

### Limity nahrávania
Aplikácia: Administrácia → Nastavenia. Server: `upload_max_filesize` a `post_max_size`
(`.htaccess` pre mod_php alebo `.user.ini` pre PHP-FPM sú priložené). Pri nginx aj `client_max_body_size`.

### Nginx
```nginx
client_max_body_size 520M;
location ~ ^/(inc|storage|install/schema\.sql) { deny all; return 404; }
location = /config.php { deny all; return 404; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.3-fpm.sock; }
location = /manifest.webmanifest { types { application/manifest+json webmanifest; } }
```

## Odtlačok prsta (WebAuthn)
- Funguje **iba cez HTTPS** (alebo `localhost`) a `app_url` sa musí presne zhodovať s adresou v prehliadači.
- Po prihlásení heslom aplikácia ponúkne „Zapnúť prihlásenie odtlačkom“; zariadenia sa spravujú v **Môj účet**.
- Na prihlasovacej stránke stačí ťuknúť na „Prihlásiť sa odtlačkom prsta“ (e-mail netreba).
- Podporované algoritmy ES256 a RS256 (Android, iPhone/iPad, Mac Touch ID, Windows Hello).

## Požiadavky
PHP 8.1+ s rozšíreniami: pdo_mysql, openssl, fileinfo, mbstring, gd (miniatúry), zip (ZIP sťahovanie).
