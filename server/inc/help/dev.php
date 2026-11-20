<?php /* Vývojárska príručka – iba pre administrátorov. */ ?>
<p class="lead">Táto príručka je pre administrátora a vývojára: ako je <?= $app ?> postavený, ako ho nainštalovať na Raspberry Pi,
Linux, Docker aj Windows, ako ho spravovať, zálohovať, aktualizovať a ako do neho pridávať nové funkcie.</p>

<?= h2('architektura', 'bi-diagram-3', 'Architektúra') ?>
<ul>
  <li><b>Server</b> – PHP 8.2+ bez frameworku, MySQL 8 / MariaDB 10.6+ (všetky tabuľky s prefixom, predvolene <code>cloud_</code>), Bootstrap 5, jQuery + Select2. Žiadne požiadavky na cudzie servery – knižnice sú v <code>assets/vendor</code>.</li>
  <li><b>Frontend</b> – jedna stránka disku (<code>index.php</code> + <code>assets/js/drive.js</code>), ktorá sa rozpráva s JSON API <code>api.php</code>. Ostatné stránky (účet, administrácia, príručka) sú klasické PHP stránky. PWA so <code>sw.js</code> (offline stránka, zdieľanie do aplikácie).</li>
  <li><b>Android aplikácia</b> – Kotlin, WebView, ktorý zobrazuje ten istý web + natívne časti: prihlásenie, viac účtov, synchronizácia zložiek, nahrávanie na pozadí, zdieľanie do Cloudu, odtlačok prsta, upozornenia, samoaktualizácia.</li>
  <li><b>Úložisko</b> – súbory na disku servera v <code>storage/files/&lt;id používateľa&gt;/&lt;náhodný názov&gt;</code>, voliteľne šifrované AES-256-GCM. Názvy, zložky a metadáta sú v databáze.</li>
  <li><b>Aktualizácie</b> – vydania na GitHube (balík servera <code>cloud-server-X.Y.Z.zip</code> a <code>cloud-android.apk</code>); server sa aktualizuje z Administrácie jedným tlačidlom.</li>
</ul>
<h3>Priečinky</h3>
<table class="table table-sm">
  <tbody>
    <tr><td><code>server/*.php</code></td><td>Vstupné stránky: <code>index.php</code> (disk), <code>api.php</code> (JSON API), <code>app.php</code> (API Android aplikácie), <code>admin.php</code>, <code>profile.php</code>, <code>help.php</code>, <code>s.php</code> (verejné odkazy), <code>dav.php</code> (WebDAV), <code>login/register/forgot/reset/verify.php</code>, <code>passkey.php</code>, <code>switch.php</code> (viac účtov), <code>avatar.php</code>, <code>share-target.php</code></td></tr>
    <tr><td><code>server/inc/</code></td><td>Logika (cez web nedostupná): <code>core.php</code> (štart, DB, session, prihlásenie, načítanie modulov), <code>files.php</code> (úložisko, prístupy, miniatúry, údržba), <code>crypto.php</code>, <code>migrate.php</code>, <code>search.php</code>, <code>lock.php</code>, <code>activity.php</code>, <code>photos.php</code>, <code>digest.php</code>, <code>platform.php</code>, <code>maintenance.php</code>, <code>updater.php</code>, <code>mailer.php</code>, <code>notify.php</code>, <code>avatar.php</code>, <code>views/</code> (spoločné časti stránok), <code>help/</code> (táto príručka)</td></tr>
    <tr><td><code>server/assets/</code></td><td><code>js/</code> (drive.js, common.js, tour.js, admin-*.js, passkey.js, profile.js), <code>css/app.css</code>, <code>vendor/</code> (knižnice), <code>help/</code> (snímky príručky), ikony</td></tr>
    <tr><td><code>server/install/</code></td><td>Inštalátor a <code>schema.sql</code> (základné tabuľky; novšie pridávajú migrácie)</td></tr>
    <tr><td><code>server/storage/</code></td><td>Predvolené úložisko (lepšie mimo webového priečinka – <code>storage_path</code>)</td></tr>
    <tr><td><code>android/</code></td><td>Projekt aplikácie (Gradle)</td></tr>
    <tr><td><code>tests/</code></td><td><code>e2e.php</code> (end-to-end testy), <code>screens.mjs</code> (snímky a kontroly v Playwright), <code>help-demo.php</code> + <code>help-shots.mjs</code> (snímky príručky), <code>setup.php</code>, <code>housekeeping.php</code></td></tr>
    <tr><td><code>.github/workflows/</code></td><td><code>tests.yml</code> (testy: MySQL, MariaDB ako na Pi, Windows, Android lint), <code>server.yml</code> (vydanie servera), <code>android.yml</code> (zostavenie APK), <code>vendor.yml</code> (kontrola knižníc)</td></tr>
    <tr><td><code>tools/fetch-vendor.sh</code></td><td>Stiahne knižnice do <code>assets/vendor</code></td></tr>
  </tbody>
</table>

<?= h2('instalacia', 'bi-download', 'Inštalácia') ?>
<h3>Požiadavky</h3>
<ul>
  <li>PHP 8.2+ s rozšíreniami <code>pdo_mysql, gd, zip, mbstring, openssl, fileinfo, exif, curl</code>; odporúčaný <b>OPcache</b>.</li>
  <li>MySQL 8 alebo MariaDB 10.6+ (testované MySQL 8.4 a MariaDB 10.11).</li>
  <li>Webový server: Apache (pravidlá v <code>.htaccess</code>), IIS (pravidlá v <code>web.config</code>), nginx (pravidlá treba doplniť ručne – Prehľad v administrácii ukáže presné riadky), alebo LiteSpeed / Caddy.</li>
  <li>Voliteľne: <code>ffmpeg</code> (miniatúry a dátum videí), <code>pdftotext</code> z balíka poppler (hľadanie v PDF).</li>
</ul>
<h3>Postup</h3>
<ol class="steps">
  <li>Nahraj obsah priečinka <code>server/</code> (alebo rozbaľ balík z vydania) do webového priečinka, napr. <code>/var/www/html/cloud</code>.</li>
  <li>Skopíruj <code>config.sample.php</code> na <code>config.php</code> a vyplň databázu, <code>app_url</code>, <code>storage_path</code>, názov session a SMTP.</li>
  <li>Vytvor prázdnu databázu a používateľa s právami na ňu.</li>
  <li>Otvor <code>https://server/cloud/install/</code>, vytvor administrátora a potom priečinok <code>install/</code> zmaž.</li>
  <li>V <b>Administrácii → Prehľad</b> skontroluj kartu <b>Beží na</b> (ochrana úložiska, programy) a <b>Stav servera</b>.</li>
</ol>
<h3>Raspberry Pi (Docker / Portainer)</h3>
<p>Odporúčaná zostava: kontajner PHP-Apache (napr. vlastný obraz s rozšíreniami), <code>mariadb:10.11</code> a voliteľne phpMyAdmin. Úložisko daj na
<b>USB/SSD disk</b>, nie na SD kartu (Prehľad to sám zistí a upozorní). Programy sa inštalujú do kontajnera:</p>
<pre>docker exec -u 0 &lt;php-kontajner&gt; sh -c "apt-get update &amp;&amp; apt-get install -y ffmpeg poppler-utils"</pre>
<p>Po novom vytvorení kontajnera (update stacku) treba príkaz zopakovať – natrvalo ich pridaj do Dockerfile obrazu. Reštart Pi ani kontajnera ich nezmaže.
Prístup zvonku je najjednoduchší cez <b>Tailscale</b> (HTTPS certifikát z <code>*.ts.net</code>).</p>
<h3>Windows Server</h3>
<ul>
  <li><b>IIS</b>: nainštaluj PHP (FastCGI) a modul URL Rewrite je voliteľný; súbor <code>web.config</code> je súčasťou balíka (zakáže <code>inc/</code>, <code>storage/</code>, citlivé súbory, nastaví typy a limit nahrávania, vypne WebDAV modul IIS). Obslužnej rutine PHP povoľ všetky metódy (kvôli sieťovému disku).</li>
  <li><b>XAMPP / WAMP (Apache)</b>: stačí <code>.htaccess</code> a <code>AllowOverride All</code>.</li>
  <li>ffmpeg rozbaľ tak, aby bol <code>C:\ffmpeg\bin\ffmpeg.exe</code>, poppler do <code>C:\poppler\Library\bin</code> – Cloud ich nájde sám (aj v PATH). Iný priečinok nastavíš v <code>config.php</code>: <code>'tools_path' =&gt; 'D:\\nastroje\\ffmpeg\\bin'</code>.</li>
  <li>Stav servera (RAM, CPU, čas behu, typ disku) sa číta cez CIM/PowerShell; ak ho správca zakáže, údaje ostanú prázdne.</li>
</ul>
<h3>nginx</h3>
<pre>location ~ ^/(.*/)?(inc|storage)/ { deny all; return 404; }
location ~ /(config|secret\.key)\.php$ { deny all; }
client_max_body_size 520m;   # aspoň veľkosť jednej časti nahrávania</pre>

<?= h2('konfiguracia', 'bi-sliders', 'Konfigurácia') ?>
<h3>config.php</h3>
<table class="table table-sm">
  <thead><tr><th>Kľúč</th><th>Význam</th></tr></thead>
  <tbody>
    <tr><td><code>app_name</code>, <code>app_url</code></td><td>Názov a verejná adresa (bez lomky na konci) – pre e-maily a passkeys</td></tr>
    <tr><td><code>timezone</code></td><td>Časové pásmo (napr. Europe/Bratislava) – dôležité pre e-mailové súhrny</td></tr>
    <tr><td><code>db_host, db_name, db_user, db_pass, db_prefix</code></td><td>Databáza; prefix umožní zdieľať DB s inými webmi</td></tr>
    <tr><td><code>storage_path</code></td><td>Úložisko – ideálne mimo webového priečinka (napr. <code>/srv/cloud-data</code> alebo <code>D:\cloud-data</code>)</td></tr>
    <tr><td><code>session_name</code>, <code>session_lifetime</code></td><td>Vlastná session cookie (izolácia od iných webov na doméne)</td></tr>
    <tr><td><code>default_quota</code></td><td>Predvolená kvóta (v Administrácii sa dá prepísať)</td></tr>
    <tr><td><code>smtp_*</code></td><td>Odosielanie e-mailov (dá sa nastaviť aj v Administrácii → E-mail)</td></tr>
    <tr><td><code>encryption_key</code></td><td>Voliteľný hlavný kľúč šifrovania; inak sa vytvorí <code>secret.key.php</code></td></tr>
    <tr><td><code>tools_path</code></td><td>Voliteľné priečinky s ffmpeg / pdftotext (oddelené <code>;</code>)</td></tr>
    <tr><td><code>update_repo</code></td><td>GitHub repozitár s vydaniami (predvolene richardgogola12/cloud)</td></tr>
  </tbody>
</table>
<div class="warn"><code>config.php</code> a <code>secret.key.php</code> nikdy nedávaj do verejného repozitára (sú v .gitignore) a zálohuj ich.</div>
<h3>Nastavenia v Administrácii</h3>
<?= fig('a-nastavenia', 'Administrácia → Nastavenia.') ?>
<p>Verejná adresa, registrácia (otvorená / so schválením / vypnutá), predvolená kvóta, maximálna veľkosť súboru, overenie e-mailu, e-mail adminom pri registrácii,
upozornenie na nové zariadenie, počet dní v koši a počet verzií. Hodnoty sú v tabuľke <code>cloud_settings</code> (funkcie <code>setting()</code> / <code>setting_set()</code>).</p>

<?= h2('administracia', 'bi-shield-lock', 'Administrácia') ?>
<h3>Prehľad</h3>
<?= fig('a-prehlad', 'Prehľad: disk servera, platforma, stav servera, grafy využitia.') ?>
<p>Disk servera (zaplnenie diskov s úložiskom), karta <b>Beží na</b> – Cloud sám zistí systém (Raspberry Pi / Linux / Docker / Windows / macOS), webový server
(Apache / nginx / IIS / LiteSpeed / Caddy) a režim PHP, overí ochranu úložiska (skúšobný súbor sa pokúsi stiahnuť cez web; výsledok platí 6 hodín) a vypíše nájdené programy.</p>
<?= fig('a-platforma', 'Karta Beží na: platforma, webový server, ochrana úložiska, programy a čo sa prispôsobilo.') ?>
<?= fig('a-stav', 'Stav servera s odporúčaniami na zrýchlenie.') ?>
<h3>Používatelia</h3>
<?= fig('a-pouzivatelia', 'Správa používateľov.') ?>
<p>Schvaľovanie registrácií, rola (používateľ / administrátor), aktívny účet, kvóta (aj hromadne), nové heslo (voliteľne poslať e-mailom),
odhlásenie zo všetkých zariadení, aktivita a zmazanie používateľa so všetkými súbormi.</p>
<h3>E-mail (SMTP)</h3>
<?= fig('a-smtp', 'Nastavenie SMTP a testovací e-mail.') ?>
<h3>Záznamy</h3>
<?= fig('a-zaznamy', 'Záznamy udalostí (prihlásenia, zmeny, chyby).') ?>
<h3>Údržba</h3>
<?= fig('a-udrzba', 'Údržba: šifrovanie, hľadanie v obsahu, kontrola integrity.') ?>
<ul>
  <li><b>Šifrovanie súborov</b> – zapnutie pre nové súbory, dávkové zašifrovanie existujúcich, stiahnutie kľúča (záloha!).</li>
  <li><b>Hľadanie v obsahu</b> – index textu dokumentov (<code>cloud_file_text</code>, FULLTEXT); dopĺňa sa na pozadí.</li>
  <li><b>Kontrola integrity</b> – súbory v DB bez dát na disku a „siroty“ na disku bez záznamu v DB (môžeš ich zmazať).</li>
</ul>
<h3>Aktualizácia</h3>
<?= fig('a-aktualizacia', 'Aktualizácia servera a stav Android aplikácie.') ?>
<p>Server si raz denne overí nové vydanie na GitHube a upozorní administrátorov. Tlačidlo <b>Aktualizovať</b> stiahne balík, overí ho, urobí zálohu
(<code>storage/backups</code>, posledných 5), prepíše súbory a spustí migrácie. Nemení <code>config.php</code>, <code>secret.key.php</code>, <code>storage/</code>,
<code>install/</code> a existujúce <code>.htaccess</code>, <code>.user.ini</code>, <code>web.config</code>. Súbory, ktoré už v novej verzii nie sú, zmaže až migrácia.</p>

<?= h2('databaza', 'bi-database', 'Databáza a migrácie') ?>
<table class="table table-sm">
  <tbody>
    <tr><td><code>users</code></td><td>účty (rola, kvóta, <code>prefs</code> JSON – stĺpce, súhrn, sprievodca…)</td></tr>
    <tr><td><code>folders</code>, <code>files</code></td><td>strom zložiek a súbory (<code>stored_name</code>, <code>enc_key</code>, <code>sha256</code>, <code>taken_at</code>, <code>deleted_at</code>, farba, hviezdička, <code>lock_hash</code>/<code>lock_mode</code> pri zložkách)</td></tr>
    <tr><td><code>file_versions</code></td><td>staršie verzie súborov</td></tr>
    <tr><td><code>shares</code>, <code>user_shares</code></td><td>verejné odkazy (token, heslo, platnosť, režim) a zdieľanie s používateľmi</td></tr>
    <tr><td><code>tags</code>, <code>item_tags</code></td><td>štítky</td></tr>
    <tr><td><code>albums</code>, <code>album_items</code></td><td>albumy fotiek</td></tr>
    <tr><td><code>activity</code></td><td>história zmien (pre Aktivitu a vrátenie zložky v čase)</td></tr>
    <tr><td><code>uploads</code></td><td>rozpracované nahrávania po častiach</td></tr>
    <tr><td><code>file_text</code></td><td>index obsahu dokumentov</td></tr>
    <tr><td><code>notifications</code>, <code>devices</code>, <code>sessions</code>, <code>tokens</code>, <code>webauthn</code>, <code>dav_passwords</code></td><td>upozornenia, zariadenia aplikácie, relácie, jednorazové tokeny (overenie, prepínanie účtov), passkeys, heslá sieťového disku</td></tr>
    <tr><td><code>settings</code>, <code>log</code>, <code>login_attempts</code>, <code>password_resets</code>, <code>usage_daily</code></td><td>nastavenia, záznamy, ochrana pred hádaním hesla, obnova hesla, štatistika pre graf</td></tr>
  </tbody>
</table>
<h3>Ako pridať migráciu</h3>
<ol class="steps">
  <li>V <code>inc/migrate.php</code> zvýš <code>SCHEMA_VERSION</code> (aktuálne <?= (int)SCHEMA_VERSION ?>).</li>
  <li>Pridaj riadok <code>if ($v &lt; N) migrate_vN();</code> a funkciu <code>migrate_vN()</code>. Používaj <code>add_col()</code>, <code>idx_exists()</code>, <code>CREATE TABLE IF NOT EXISTS</code> – migrácia musí byť opakovateľná.</li>
  <li>Migrácie sa spúšťajú samé pri prvej požiadavke po aktualizácii (so zámkom <code>migrate.lock</code>). Do <code>schema.sql</code> nové tabuľky nepridávaj – inštalátor po ňom volá <code>migrate()</code>.</li>
  <li>Ak treba zmazať starý súbor aplikácie, sprav to v migrácii (aktualizácia súbory sama nemaže).</li>
</ol>

<?= h2('bezpecnost-dev', 'bi-shield-lock-fill', 'Bezpečnosť') ?>
<ul>
  <li><b>Prihlásenie</b> – <code>password_hash</code>, limit pokusov podľa IP/e-mailu, passkeys (WebAuthn), „zapamätať si ma“ cez vlastné tokeny, vlastný názov session.</li>
  <li><b>CSRF</b> – každý POST nesie token (<code>csrf_field()</code>, hlavička <code>X-CSRF-Token</code> v <code>Cloud.api</code>); <code>csrf_check()</code>.</li>
  <li><b>Prístup k položkám</b> – vždy cez <code>folder_access()</code> / <code>file_access()</code> a <code>need($acc, 'view'|'edit'|'owner')</code>; zamknuté zložky stráži <code>lock_guard()</code> (výnimka <code>FolderLocked</code>) a <code>filter_locked()</code> v zoznamoch.</li>
  <li><b>Šifrovanie</b> – <code>crypto.php</code>: každý súbor má vlastný kľúč zašifrovaný hlavným kľúčom (envelope), AES-256-GCM po blokoch 64 KB s overením každého bloku – umožňuje čítanie od ľubovoľného miesta (video, Range).</li>
  <li><b>Nič v zariadení</b> – súbory, náhľady, miniatúry a avatary sa posielajú s <code>Cache-Control: private, no-store</code>; service worker drží iba súbory aplikácie.</li>
  <li><b>Ochrana úložiska</b> – <code>.htaccess</code>/<code>web.config</code> v koreni aj v <code>storage/</code>; najlepšie je úložisko mimo webového priečinka.</li>
  <li><b>Externé spojenia</b> – iba GitHub (aktualizácie) a SMTP. jsDelivr je len záloha, ak chýbajú súbory v <code>assets/vendor</code>.</li>
</ul>

<?= h2('api', 'bi-braces', 'API') ?>
<h3>Web – api.php</h3>
<p>Volanie: <code>POST api.php?action=&lt;akcia&gt;</code> (formulár), hlavičky <code>X-Requested-With: XMLHttpRequest</code> a <code>X-CSRF-Token</code>; odpoveď
<code>{"ok":true,…}</code> alebo <code>{"ok":false,"error":"…"}</code>. Vo frontende <code>Cloud.api(url, data)</code> (rieši aj odomknutie zamknutej zložky a opakovanie).</p>
<table class="table table-sm">
  <tbody>
    <tr><td>Zoznamy</td><td><code>list</code> (view: folder, recent, starred, trash, shared, byme, photos, album, tag, search, dupes, activity), <code>folders</code>, <code>info</code>, <code>suggest</code>, <code>search_meta</code></td></tr>
    <tr><td>Súbory</td><td><code>mkdir, mkfile, upload_init, upload_chunk, upload_cancel, rename, move, copy, delete, restore, purge, empty_trash, star, text, save_text</code>; GET <code>download, thumb, zip</code></td></tr>
    <tr><td>Verzie a história</td><td><code>versions, version_restore, version_delete, activity, folder_rewind</code></td></tr>
    <tr><td>Zdieľanie</td><td><code>share_get, share_link_save, share_link_delete, user_search, share_user_add, share_user_update, share_user_remove, share_leave</code></td></tr>
    <tr><td>Štítky a farby</td><td><code>tags, tag_save, tag_delete, tags_apply, color_set</code></td></tr>
    <tr><td>Fotky</td><td><code>albums, album_save, album_delete, album_add, album_remove, album_cover, memories</code></td></tr>
    <tr><td>Zámky</td><td><code>folder_lock</code> (op: set, change, mode, remove), <code>folder_unlock, folder_relock</code></td></tr>
    <tr><td>Účet</td><td><code>prefs_save</code> (columns, digest), <code>digest_preview, digest_test, tour, notifications*</code></td></tr>
  </tbody>
</table>
<h3>Android – app.php</h3>
<p><code>info</code> (overenie servera), <code>login</code> (vráti token), <code>web</code> (token → session pre WebView), <code>me</code>, <code>notifications</code>, <code>logout</code>.
Aplikácia posiela token v hlavičke <code>X-Cloud-Token</code> a User-Agent <code>CloudAndroid/&lt;verzia&gt;</code>; WebView nastavuje cookie <code>cloud_app=1</code>
(service worker požiadavky nemajú vlastný User-Agent) – podľa toho <code>is_android_app()</code> ukáže natívne položky menu.</p>
<h3>WebDAV – dav.php</h3>
<p>Adresa <code>https://server/cloud/dav.php</code> (alebo <code>/dav/</code> pri prepise). Overenie Basic: e-mail + heslo pre sieťový disk z <code>dav_passwords</code>.
Podporuje PROPFIND, PROPPATCH, HEAD, GET (Range), PUT, MKCOL, MOVE, COPY, DELETE, LOCK/UNLOCK (pre Windows a macOS).</p>

<?= h2('frontend', 'bi-window', 'Frontend') ?>
<ul>
  <li><code>drive.js</code> – stav (<code>state</code>: view, folder, items, výber, zoradenie), smerovanie cez <code>location.hash</code> (<code>#folder/ID</code>, <code>#recent</code>, <code>#album/ID</code>…), vykresľovanie zoznamu/mriežky (postupne po 300 položkách), zoradenie v prehliadači pre zobrazenia mimo zložky, kontextová ponuka, dialógy, nahrávanie po častiach, náhľad, fotky, klávesové skratky.</li>
  <li>Cache zoznamov je <b>iba v pamäti</b> (<code>lcMem</code>), prednačítanie zložiek pri prejdení myšou.</li>
  <li>Rozloženie zoznam/mriežka sa pamätá pre každé miesto (<code>cloud-layouts</code> v localStorage); podzložky dedia.</li>
  <li><code>common.js</code> – <code>Cloud.api</code>, toasty, potvrdenia, menu účtov, gesto potiahnutia pre menu v mobile.</li>
  <li><code>tour.js</code> – rýchly sprievodca (10 krokov), <code>window.cloudTour()</code>; „Viac nezobrazovať“ uloží <code>prefs.tour = 'done'</code>.</li>
  <li>Texty sú po slovensky priamo v kóde; dizajn podľa Google Disku (premenné <code>--c-*</code> v <code>app.css</code>, tmavý režim cez <code>data-bs-theme</code>).</li>
</ul>

<?= h2('android-dev', 'bi-android2', 'Android aplikácia') ?>
<ul>
  <li><code>ui/MainActivity</code> – WebView s webom, JS most <code>CloudApp</code> (odtlačok pre zamknuté zložky: <code>hasFolderKey, unlockFolder, offerFolderKey, forgetFolderKey</code>; účty; blokovanie potiahnutia na obnovenie), odkazy <code>cloudapp://</code> na natívne obrazovky, otváranie súborov, sťahovanie, kamera. Pri studenom štarte maže HTTP cache WebView.</li>
  <li><code>ui/LoginActivity</code>, <code>Prefs</code> – viac účtov (<code>Prefs.Account</code>), kľúče zložiek <code>fk_&lt;účet&gt;_&lt;id&gt;</code> v Keystore.</li>
  <li><code>sync/</code> – <code>SyncEngine</code> (sledované zložky, jednosmerne telefón → Cloud), <code>SyncDb</code> (databáza pre každý účet), <code>UploadService</code>/<code>UploadQueue</code> (nahrávanie na pozadí po častiach), <code>NotifPoller</code>, <code>WatchdogWorker</code>, <code>BootReceiver</code>, <code>SyncWidget</code>.</li>
  <li><code>ui/ShareActivity</code> – „Zdieľať → Cloud“ z iných aplikácií; <code>ui/PdfActivity</code>, <code>ZoomImageView</code> – vstavaný náhľad.</li>
  <li><code>update/Updater</code> – kontrola vydaní na GitHube a inštalácia APK.</li>
  <li>Verzia: <code>MAJOR.MINOR</code> v <code>android/version.properties</code>, tretie číslo je poradové číslo zostavenia v GitHub Actions (<code>BUILD_NUMBER</code>); zostavenie a podpis robí workflow <code>android.yml</code>.</li>
</ul>

<?= h2('procesy', 'bi-gear-wide-connected', 'Úlohy na pozadí') ?>
<ul>
  <li><b>Denná údržba</b> (<code>housekeeping()</code>, prvá požiadavka dňa): vysypanie koša po N dňoch, upozornenie 3 dni vopred, staré nahrávania a dočasné súbory, štatistika, kontrola nových verzií servera.</li>
  <li><b>E-mailové súhrny</b> (<code>send_due_digests()</code>, najviac raz za 10 minút pri návšteve webu): každý používateľ má v <code>prefs.digest</code> frekvenciu (daily / weekly / biweekly / monthly / off), deň v týždni, deň v mesiaci a hodinu; <code>digest_slot()</code> vypočíta posledný termín, <code>prefs.digest_last</code> zabráni dvojitému odoslaniu.</li>
  <li><b>Úlohy po odpovedi</b> – <code>defer_task()</code> spustí kód až po odoslaní odpovede (miniatúry, index obsahu), aby používateľ nečakal.</li>
</ul>
<div class="note">Ak web nikto nenavštívi, úlohy sa spustia pri najbližšej návšteve. Pre presný čas súhrnov môžeš pridať cron: <code>*/10 * * * * curl -s https://server/cloud/login.php &gt;/dev/null</code>.</div>

<?= h2('testy', 'bi-check2-circle', 'Testy a vydania') ?>
<ul>
  <li><b>tests.yml</b> – pri každom pushi: end-to-end testy na PHP 8.3 + MySQL 8.4, na PHP 8.2 + MariaDB 10.11 (ako Raspberry Pi), na <b>Windows</b> (PHP 8.3 + MySQL 8.4), snímky obrazovky v Playwright (mobil, veľký mobil, počítač) a Android lint. Snímky sa ukladajú do vetvy <code>screens</code>.</li>
  <li><b>Snímky príručky</b> – <code>help-demo.php</code> pripraví ukážkovú používateľku a <code>help-shots.mjs</code> nafotí obrazovky do <code>screens/help/</code>; tie sa (zmenšené do JPEG) kopírujú do <code>server/assets/help/</code>.</li>
  <li><b>server.yml</b> – keď sa zmení <code>server/VERSION</code>, vytvorí vydanie <code>server-vX.Y.Z</code> s balíkom ZIP.</li>
  <li><b>android.yml</b> – zostaví podpísané APK a vydanie <code>android-vX.Y.Z</code>.</li>
</ul>
<h3>Ako vydať novú verziu</h3>
<ol class="steps">
  <li>Urob zmeny, doplň testy do <code>tests/e2e.php</code> alebo <code>tests/screens.mjs</code>.</li>
  <li>Zvýš <code>server/VERSION</code> (číslo verzie aplikácie sa zvyšuje samo pri každom zostavení; <code>MAJOR.MINOR</code> meníš v <code>android/version.properties</code>).</li>
  <li>Push na <code>main</code> – po zelených testoch vznikne vydanie a servery ho ponúknu v Administrácii.</li>
</ol>

<?= h2('zalohy', 'bi-hdd-stack', 'Zálohy a obnova') ?>
<p>Na úplnú zálohu treba štyri veci:</p>
<ol class="steps">
  <li><b>Databáza</b> – <code>mysqldump --single-transaction cloud &gt; cloud.sql</code> (v Dockeri: <code>docker exec cluud-db mariadb-dump …</code>).</li>
  <li><b>Úložisko</b> – celý <code>storage_path</code> (súbory, miniatúry netreba – vytvoria sa znova).</li>
  <li><b>Šifrovací kľúč</b> – <code>secret.key.php</code> (alebo <code>encryption_key</code> z config.php). <b>Bez neho sa zašifrované súbory nedajú obnoviť!</b></li>
  <li><b>config.php</b>.</li>
</ol>
<p>Obnova: nahraj aplikáciu, vráť <code>config.php</code> a kľúč, importuj databázu a skopíruj úložisko na rovnakú cestu (alebo uprav <code>storage_path</code>).
Kontrola integrity v Údržbe potom overí, že všetky súbory sedia.</p>

<?= h2('riesenie', 'bi-tools', 'Riešenie problémov') ?>
<table class="table table-sm">
  <thead><tr><th>Problém</th><th>Riešenie</th></tr></thead>
  <tbody>
    <tr><td>Miniatúry videí / hľadanie v PDF nefungujú</td><td>Prehľad → Beží na → Programy ukáže, čo chýba a ako to doinštalovať (pre Docker, Linux aj Windows).</td></tr>
    <tr><td>„Úložisko sa dá stiahnuť cez web“</td><td>Apache: <code>AllowOverride All</code>; IIS: <code>web.config</code>; nginx: pravidlá vyššie; najlepšie úložisko mimo webového priečinka.</td></tr>
    <tr><td>Nahrávanie veľkých súborov padá</td><td>Skontroluj <code>upload_max_filesize</code>, <code>post_max_size</code> (stačí na jednu časť – aplikácia nahráva po častiach), limit webového servera (nginx <code>client_max_body_size</code>, IIS <code>maxAllowedContentLength</code>).</td></tr>
    <tr><td>Nechodia e-maily</td><td>Administrácia → E-mail: test; skontroluj port/šifrovanie (587 STARTTLS / 465 SSL) a heslo aplikácie u poskytovateľa.</td></tr>
    <tr><td>Pomalý server</td><td>Zapni OPcache, úložisko na SSD/USB namiesto SD karty, PHP 8.2+. Prehľad → Ako zrýchliť server.</td></tr>
    <tr><td>Aktualizácia zlyhala</td><td>Priečinok aplikácie musí byť zapisovateľný pre PHP. Záloha je v <code>storage/backups</code>.</td></tr>
    <tr><td>Aplikácia sa nepripojí</td><td>Na telefóne Server → Otestovať; pri vlastnom certifikáte potvrď jeho odtlačok; skontroluj, že <code>app_url</code>/verejná adresa sedí.</td></tr>
  </tbody>
</table>
