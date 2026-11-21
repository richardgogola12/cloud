<?php /* Používateľská príručka – obsah. Snímky: assets/help/*.jpg (vytvárajú sa automaticky v testoch: tests/help-shots.mjs). */ ?>
<p class="lead"><?= $app ?> je tvoj súkromný disk na internete – podobný Google Disku. Súbory, fotky a dokumenty máš na jednom mieste,
dostupné z počítača, tabletu aj mobilu. Táto príručka popisuje všetky funkcie, od úplných základov až po pokročilé triky.</p>
<div class="note">Najrýchlejší začiatok: klikni na <b>Rýchly sprievodca</b> hore – za minútu ťa prevedie desiatimi najdôležitejšími vecami.
Na počítači kedykoľvek stlač <kbd>?</kbd> a uvidíš všetky klávesové skratky.</div>

<?= h2('zaciatok', 'bi-door-open', 'Prvé kroky') ?>
<h3>Prihlásenie a registrácia</h3>
<p>Otvor adresu Cloudu v prehliadači a prihlás sa e-mailom a heslom. Ak ešte nemáš účet, klikni na <b>Vytvoriť účet</b>.
Podľa nastavenia servera môže byť potrebné:</p>
<ul>
  <li><b>overiť e-mail</b> – príde ti správa s odkazom, na ktorý klikneš,</li>
  <li><b>počkať na schválenie</b> administrátorom – o schválení dostaneš e-mail.</li>
</ul>
<p>Zaškrtni <b>Zapamätať si ma</b>, ak nechceš zadávať heslo pri každej návšteve (na cudzom počítači to nerob).
Ak si zabudol heslo, použi <b>Zabudnuté heslo</b> – príde ti odkaz na nastavenie nového.</p>
<div class="tip">Na počítači alebo v mobile s odtlačkom prsta sa môžeš prihlasovať aj <b>bez hesla</b> – nastavíš to v <b>Môj účet → Prihlásenie odtlačkom prsta</b> (kapitola <a href="#ucet">Môj účet</a>).</div>

<h3>Ako vyzerá obrazovka</h3>
<?= fig('u-disk', 'Hlavná obrazovka: vľavo menu, hore hľadanie, uprostred tvoje zložky a súbory.') ?>
<ol class="steps">
  <li><b>Menu vľavo</b> – tlačidlo <b>+ Nový</b>, sekcie Môj disk, Nedávne, S hviezdičkou, Fotky, Aktivita, zdieľanie, Duplicitné súbory, Kôš, štítky, nastavenia a Pomoc. Dole vidíš, koľko miesta ti ostáva.</li>
  <li><b>Hľadanie</b> – pole <i>Hľadať v disku</i> s návrhmi a filtrami (kapitola <a href="#hladanie">Vyhľadávanie</a>).</li>
  <li><b>Zvonček</b> – upozornenia (niekto s tebou zdieľal, dochádza miesto, nové prihlásenie…).</li>
  <li><b>Mesiac</b> – prepína svetlý a tmavý vzhľad.</li>
  <li><b>Tvoj avatar</b> – účet, prepínanie medzi účtami, odhlásenie.</li>
  <li><b>Prepínač zobrazenia</b> vpravo nad zoznamom – <i>zoznam</i> alebo <i>mriežka</i> a zoradenie.</li>
</ol>
<?= fig('u-tmavy', 'Tmavý režim – prepneš ho ikonou mesiaca vpravo hore.') ?>

<h3>V mobile</h3>
<p>Na telefóne sa menu skryje – otvoríš ho ikonou <i class="bi bi-list"></i> vľavo hore alebo <b>potiahnutím prsta doprava</b>
(zatvoríš potiahnutím doľava). Akcie pre súbor nájdeš pod tlačidlom <b>⋮</b> pri každej položke.</p>
<div>
<?= fig('m-disk', 'Môj disk v mobile.', 'phone half') ?>
<?= fig('m-menu', 'Menu otvorené potiahnutím prsta.', 'phone half') ?>
</div>

<?= h2('subory', 'bi-cloud-arrow-up', 'Nahrávanie a vytváranie') ?>
<?= fig('u-novy', 'Tlačidlo + Nový: nová zložka, textový súbor, nahranie súborov alebo celej zložky.') ?>
<h3>Nahranie súborov</h3>
<ol class="steps">
  <li>Otvor zložku, do ktorej chceš nahrávať (alebo zostaň v Môj disk).</li>
  <li>Klikni na <b>+ Nový → Nahrať súbory</b> (skratka <kbd>U</kbd>) a vyber jeden alebo viac súborov.</li>
  <li>Priebeh vidíš v okne vpravo dole. Môžeš ďalej pracovať – nahrávanie beží na pozadí.</li>
</ol>
<p><b>Ťahaj a pusť:</b> súbory alebo celé zložky môžeš jednoducho potiahnuť myšou z počítača do okna prehliadača – aj priamo na konkrétnu zložku v zozname.</p>
<h3>Nahranie celej zložky</h3>
<p><b>+ Nový → Nahrať zložku</b> nahrá zložku so všetkými podzložkami a zachová ich štruktúru.</p>
<h3>Veľké súbory a výpadok siete</h3>
<p>Súbory sa nahrávajú po častiach. Ak vypadne internet alebo zavrieš okno, pri ďalšom nahratí toho istého súboru sa pokračuje tam,
kde sa skončilo. Najväčšiu povolenú veľkosť súboru určuje administrátor.</p>
<h3>Rovnaký názov = nová verzia</h3>
<p>Ak nahráš súbor s rovnakým názvom, aký už v zložke je, pôvodný sa <b>nestratí</b> – uloží sa ako staršia verzia
(pozri <a href="#verzie">Verzie a história</a>). Ak má súbor úplne rovnaký obsah, nič sa nezmení a miesto sa neminie.</p>
<h3>Nová zložka a textový súbor</h3>
<p><b>+ Nový → Nová zložka</b> (<kbd>Shift</kbd>+<kbd>F</kbd>) a <b>Nový textový súbor</b> (<kbd>Shift</kbd>+<kbd>T</kbd>).
Textové súbory (.txt, .md, .csv, kód…) vieš upravovať priamo v Cloude cez <b>Upraviť text</b> – zmeny sa ukladajú ako nové verzie.</p>
<div class="tip">Cez verejný odkaz môžu súbory nahrávať aj ľudia bez účtu (kapitola <a href="#zdielanie">Zdieľanie</a> → „Povoliť nahrávanie“).</div>

<?= h2('praca', 'bi-folder2-open', 'Práca so súbormi a zložkami') ?>
<?= fig('u-disk-zoznam', 'Zobrazenie zoznamu so stĺpcami – klikni na názov stĺpca a zoradíš podľa neho.') ?>
<h3>Zoznam a mriežka</h3>
<p>Ikony vpravo nad zoznamom prepínajú <b>zoznam</b> (riadky so stĺpcami) a <b>mriežku</b> (dlaždice s náhľadmi). Každé miesto si pamätá
svoje nastavenie zvlášť – napríklad Fotky z dovolenky môžeš mať v mriežke a Dokumenty v zozname. Podzložky preberú nastavenie
nadradenej zložky, kým ich nezmeníš. Skratka <kbd>V</kbd> prepína zobrazenie tam, kde práve si.</p>
<h3>Zoradenie a stĺpce</h3>
<p>V zozname klikni na názov ľubovoľného stĺpca (Názov, Vlastník, Upravené, Veľkosť, Typ…) – ďalším kliknutím obrátiš poradie.
V mriežke použi ikonu zoradenia vpravo hore. Ktoré stĺpce sa majú ukazovať, nastavíš ikonou stĺpcov nad zoznamom alebo v
<b>Môj účet → Stĺpce v zozname</b>. Šírku stĺpca zmeníš potiahnutím jeho okraja.</p>
<h3>Ponuka akcií</h3>
<?= fig('u-kontext', 'Pravým klikom na súbor (v mobile ⋮) otvoríš všetky akcie.') ?>
<table class="table table-sm">
  <thead><tr><th>Akcia</th><th>Čo robí</th></tr></thead>
  <tbody>
    <tr><td>Otvoriť / Náhľad</td><td>Zobrazí obrázok, video, zvuk, PDF alebo text priamo v Cloude.</td></tr>
    <tr><td>Upraviť text</td><td>Úprava textových súborov priamo v prehliadači.</td></tr>
    <tr><td>Stiahnuť</td><td>Stiahne súbor; viac položiek alebo zložku stiahne ako ZIP.</td></tr>
    <tr><td>Zdieľať</td><td>Zdieľanie s ľuďmi alebo verejným odkazom.</td></tr>
    <tr><td>Štítky a farba…</td><td>Pridá alebo odoberie štítky a nastaví farbu položky.</td></tr>
    <tr><td>Premenovať</td><td>Zmena názvu (<kbd>F2</kbd>).</td></tr>
    <tr><td>Presunúť / Vytvoriť kópiu</td><td>Presunie položku do inej zložky (výber v strome zložiek) alebo vytvorí jej kópiu.</td></tr>
    <tr><td>Pridať / odstrániť hviezdičku</td><td>Položka sa objaví v sekcii S hviezdičkou (<kbd>S</kbd>).</td></tr>
    <tr><td>Verzie</td><td>Staršie verzie súboru – stiahnuť, obnoviť, zmazať.</td></tr>
    <tr><td>Informácie</td><td>Veľkosť, typ, umiestnenie, vlastník, dátumy, zdieľanie (<kbd>I</kbd>).</td></tr>
    <tr><td>Aktivita</td><td>História zmien – kto a kedy čo s položkou robil (<kbd>H</kbd>).</td></tr>
    <tr><td>Vrátiť do stavu k…</td><td>Obnoví obsah zložky k zvolenému dátumu a času.</td></tr>
    <tr><td>Zamknúť heslom…</td><td>Zložka sa otvorí až po zadaní hesla.</td></tr>
    <tr><td>Presunúť do koša</td><td>Položka sa presunie do Koša (<kbd>Delete</kbd>).</td></tr>
    <tr><td>Otvoriť v aplikácii… / Poslať…</td><td>Iba v aplikácii pre Android: otvorí súbor v inej aplikácii v telefóne alebo ho pošle (e-mail, chat…).</td></tr>
  </tbody>
</table>
<h3>Výber viacerých položiek</h3>
<p>Klikni na zaškrtávacie políčko pri položkách (alebo <kbd>Ctrl</kbd>+klik, <kbd>Shift</kbd>+klik pre rozsah, <kbd>Ctrl</kbd>+<kbd>A</kbd> pre všetko).
Hore sa objaví modrý panel s akciami pre všetky vybrané položky naraz: štítky, stiahnuť, presunúť, hviezdička, zmazať.
V mobile podrž prst na položke a potom ťukaj na ďalšie.</p>
<h3>Presúvanie myšou</h3>
<p>Položky môžeš potiahnuť na zložku v zozname alebo na niektorú časť cesty hore (napr. „Môj disk“). Fungujú aj
<kbd>Ctrl</kbd>+<kbd>C</kbd> / <kbd>Ctrl</kbd>+<kbd>X</kbd> a <kbd>Ctrl</kbd>+<kbd>V</kbd> do inej zložky.</p>
<h3>Informácie o súbore</h3>
<?= fig('u-info', 'Okno Informácie: všetky údaje o súbore na jednom mieste.') ?>
<h3>Náhľad</h3>
<?= fig('u-nahlad', 'Náhľad fotky. Šípkami listuješ, medzerníkom spustíš prezentáciu.') ?>
<p>Dvojklikom (v mobile ťuknutím) otvoríš náhľad. Obrázky a fotky, videá (aj posúvanie v nich), hudba, PDF a textové súbory sa
zobrazia priamo. Šípkami <kbd>←</kbd> <kbd>→</kbd> (v mobile potiahnutím) prejdeš na ďalší súbor, <kbd>D</kbd> stiahne, <kbd>Esc</kbd> zavrie.
Ostatné typy (napr. Word, Excel) si stiahneš alebo v aplikácii otvoríš v inej aplikácii v telefóne.</p>
<h3>Farby, štítky a hviezdičky</h3>
<p><b>Farbu</b> zložky alebo súboru nastavíš v okne <b>Štítky a farba…</b> (ponuka položky) – farebný pásik ju zvýrazní v zozname. <b>Štítky</b> sú farebné menovky (napr. „Dôležité“, „Rodina“) –
jedna položka môže mať viac štítkov a v menu vľavo klikneš na štítok a uvidíš všetko, čo ho má. Štítky spravuješ ikonou ozubeného
kolesa pri nadpise Štítky. <b>Hviezdička</b> označí obľúbené položky – nájdeš ich v sekcii <b>S hviezdičkou</b>.</p>
<?= fig('u-hviezdicky', 'Sekcia S hviezdičkou.') ?>
<h3>Nedávne a duplicity</h3>
<p><b>Nedávne</b> ukazuje, s čím si naposledy pracoval. <b>Duplicitné súbory</b> nájdu súbory s rovnakým obsahom (aj s iným názvom),
aby si mohol uvoľniť miesto.</p>
<?= fig('u-nedavne', 'Nedávne súbory.') ?>

<div class="tip">Keď sa vrátiš do nadradenej zložky (šípkou Späť alebo cez cestu hore), Cloud ťa posunie tam, kde si bol, a zložka, z ktorej prichádzaš, krátko <b>blikne na modro</b>.
Rovnako blikne súbor po zavretí náhľadu či editora. Bliknutie vypneš alebo mu nastavíš dĺžku v <b>Môj účet → Zobrazenie</b>.</div>

<?= h2('tabulky', 'bi-file-earmark-spreadsheet', 'Tabuľky (Excel)') ?>
<?= fig('u-tabulka', 'Editor tabuliek vyzerá a ovláda sa ako Google Tabuľky: ponuky hore, panel s formátovaním, riadok so vzorcom, hárky dole.') ?>
<p>Tabuľky <b>.xlsx</b> (Excel), <b>.ods</b> (LibreOffice) a <b>.csv</b> upravíš priamo v Cloude – na počítači aj v mobile. Editor vyzerá a funguje
ako Google Tabuľky, je celý po slovensky a beží v tvojom prehliadači (nič sa neposiela na cudzie servery).</p>
<ul>
  <li><b>Nová tabuľka:</b> <b>+ Nový → Nová tabuľka</b>, zadaj názov.</li>
  <li><b>Úprava:</b> v ponuke súboru <b>Upraviť</b> alebo v náhľade tlačidlo <b>Upraviť</b>. Šípka vľavo hore (alebo tlačidlo Späť v telefóne) editor zavrie.</li>
  <li><b>Písanie:</b> klikni do bunky a píš, <kbd>Enter</kbd> = potvrdiť, <kbd>Tab</kbd> = bunka vedľa, <kbd>F2</kbd> alebo dvojklik = upraviť obsah.
    Čísla píš po slovensky: <code>85,5</code>, <code>1 200</code>, <code>15 %</code>, <code>120 €</code>, dátum <code>24.12.2026</code> – Cloud ich rozpozná a správne naformátuje.</li>
  <li><b>Vzorce:</b> začínajú <code>=</code>, napr. <code>=SUM(B2:B5)</code>, <code>=AVERAGE(…)</code>, <code>=IF(B2&gt;100;"veľa";"málo")</code>, <code>=VLOOKUP(…)</code>, <code>=COUNTIF(…)</code>, <code>=ROUND(…)</code>, <code>=TODAY()</code> – stovky funkcií Excelu.
    Pri písaní sa ukazuje <b>nápoveda funkcie</b> s popisom argumentov. Oddeľovať môžeš bodkočiarkou aj čiarkou. <b>Vložiť → Súčet</b> spočíta stĺpec nad bunkou.</li>
</ul>
<?= fig('u-tabulka-menu', 'Ponuka Formát: formáty čísel (mena, percentá, dátum), písmo, zlúčenie a podmienené formátovanie.') ?>
<h3>Ponuky ako v Google Tabuľkách</h3>
<table class="table table-sm">
  <tr><td><b>Súbor</b></td><td>Uložiť teraz, stiahnuť ako <b>Excel (.xlsx)</b>, <b>OpenDocument (.ods)</b> alebo <b>CSV</b>, zavrieť.</td></tr>
  <tr><td><b>Úpravy</b></td><td>Späť / znova, <b>Nájsť a nahradiť</b> (aj regulárnym výrazom), vymazať obsah, odstrániť riadky a stĺpce.</td></tr>
  <tr><td><b>Zobraziť</b></td><td><b>Ukotvenie</b> 1. riadka, 1. stĺpca alebo všetkého po aktuálnu bunku (ostane na mieste pri posúvaní), priblíženie.</td></tr>
  <tr><td><b>Vložiť</b></td><td>Riadky a stĺpce, <b>graf</b>, obrázok, odkaz, <b>komentár</b>, funkcie (súčet, priemer, ďalšie…), rozbaľovací zoznam, nový hárok.</td></tr>
  <tr><td><b>Formát</b></td><td>Formát čísla: <b>číslo</b> (1 234,56), <b>mena</b> (1 234,56 €), <b>percentá</b>, <b>dátum</b>, čas, text; tučné, kurzíva, podčiarknuté, prečiarknuté, zalamovanie, zlúčenie buniek,
    <b>podmienené formátovanie</b> (zvýraznenie hodnôt väčších než…, duplicít, najvyšších hodnôt, farebné škály, dátové pruhy), vymazanie formátovania.</td></tr>
  <tr><td><b>Údaje</b></td><td>Zoradenie A → Z / Z → A, <b>filter</b> (šípky v hlavičke – podľa hodnôt, podmienky alebo farby), <b>overenie údajov</b> (rozbaľovací zoznam, zaškrtávacie políčko, rozsah čísel či dátumov), rozdelenie textu do stĺpcov.</td></tr>
</table>
<p>Pod ponukami je <b>panel nástrojov</b> (písmo, veľkosť, farby, orámovanie, zarovnanie, otočenie textu…) a <b>riadok so vzorcom</b>. Pravým klikom na bunku, riadok, stĺpec alebo hárok
otvoríš ďalšie možnosti (vložiť, odstrániť, skryť, zoradiť, farba hárka…). <b>Hárky</b> pridáš tlačidlom <b>+</b> vľavo dole. Keď označíš viac buniek, vpravo dole uvidíš <b>súčet, priemer a počet</b>.
<kbd>Ctrl</kbd>+<kbd>C</kbd> / <kbd>Ctrl</kbd>+<kbd>V</kbd> funguje aj medzi Cloudom a Excelom či Google Tabuľkami. Všetky skratky sú v <b>Pomoc → Klávesové skratky</b>.</p>
<h3>Ukladanie a čo sa zachová</h3>
<p>Zmeny sa <b>uložia samé</b> pár sekúnd po úprave (alebo hneď cez <kbd>Ctrl</kbd>+<kbd>S</kbd>) – hore pri názve uvidíš „Všetky zmeny sú uložené v Cloude“. Každé uloženie vytvorí novú verziu –
predchádzajúci stav nájdeš vo <b>Verziách</b>. Do súboru .xlsx sa uloží obsah, vzorce, formáty čísel, písmo a farby, orámovanie, zlúčené bunky, šírky a výšky, ukotvenie, filter,
podmienené formátovanie, overenie údajov, komentáre, odkazy aj obrázky, takže súbor otvoríš rovnako v Exceli. <b>Grafy</b> vytvorené v Cloude si Cloud pamätá pri súbore a ukáže ich pri ďalšom otvorení v Cloude
(Excel ich nezobrazí).</p>
<div class="warn">Grafy a kontingenčné tabuľky vytvorené v Exceli a makrá editor nezobrazí. Ak ich súbor obsahuje, Cloud ťa upozorní a odporučí ho otvoriť <b>iba na čítanie</b>, aby sa uložením nestratili.
Staré formáty <b>.xls</b> a <b>.xlsm</b> sa uložia ako nový súbor <b>.xlsx</b>. Ak súbor medzitým zmenil niekto iný, Cloud ho neprepíše a upozorní ťa. CSV obsahuje iba jeden hárok a hodnoty (bez formátovania).</div>

<?= h2('obrazky', 'bi-magic', 'Úprava fotiek') ?>
<?= fig('u-obrazok', 'Editor obrázkov: orezanie s pomerom strán 16:9.') ?>
<p>Fotky a obrázky (<b>JPG, PNG, WebP</b>, aj GIF a BMP) upravíš priamo v Cloude: v náhľade fotky klikni na <b>Upraviť</b> alebo v ponuke súboru vyber <b>Upraviť</b>.</p>
<ul>
  <li><b>Orezať</b> – potiahni rohy alebo strany rámčeka, celý rámček posunieš ťahaním. Pomer strán: voľne, pôvodný, 1:1 (štvorec), 4:3, 3:4, 16:9, 9:16, 3:2.</li>
  <li><b>Otočiť</b> doľava / doprava o 90°, <b>zrkadliť</b> vodorovne alebo hore-dole a <b>vyrovnať</b> šikmý horizont posuvníkom (±45°).</li>
  <li><b>Úpravy</b> – jas, kontrast, sýtosť a teplota farieb. Dvojklik na posuvník ho vráti na 0.</li>
  <li><b>Filtre</b> – čiernobiely, sépia, teplý, studený, živý, vybledlý, vintage, dráma.</li>
  <li><b>Veľkosť</b> – zmenšenie na šírku/výšku v pixeloch, na 75 / 50 / 25 %, na Full HD alebo na web; kvalita JPEG.</li>
</ul>
<p><b>Uložiť</b> uloží upravený obrázok a pôvodný ostane vo <b>Verziách</b>. Šípka vedľa ponúka <b>Uložiť ako kópiu</b> (vznikne „… (upravené).jpg“) a <b>Stiahnuť upravený</b>. Tlačidlo <b>Pôvodný</b> zruší všetky úpravy. Na počítači: <kbd>Ctrl</kbd>+<kbd>S</kbd> uloží, <kbd>Esc</kbd> zavrie.</p>
<div class="note">Dátum odfotenia sa pri úprave zachová, takže fotka ostane vo Fotkách na svojom mieste. GIF a BMP sa uložia ako kópia vo formáte PNG (animácia GIF sa stratí).</div>

<?= h2('zdielanie', 'bi-share', 'Zdieľanie') ?>
<h3>Zdieľanie s ľuďmi v Cloude</h3>
<?= fig('u-zdielanie', 'Zdieľanie zložky s konkrétnymi ľuďmi a výber oprávnenia.') ?>
<ol class="steps">
  <li>Na zložke alebo súbore vyber <b>Zdieľať</b> (skratka <kbd>.</kbd>).</li>
  <li>V karte <b>Používatelia</b> napíš meno alebo e-mail a vyber človeka zo zoznamu.</li>
  <li>Zvoľ oprávnenie: <b>Iba čítanie</b> (môže prezerať a sťahovať) alebo <b>Môže upravovať</b> (môže aj nahrávať, premenovať, mazať).</li>
  <li>Klikni na <b>Zdieľať</b>. Človek dostane upozornenie (a podľa voľby aj e-mail).</li>
</ol>
<p>Oprávnenie neskôr zmeníš alebo prístup odoberieš v tom istom okne v časti <b>Ľudia s prístupom</b>.</p>
<h3>Verejný odkaz</h3>
<?= fig('u-odkaz', 'Verejný odkaz: platnosť, heslo a pri zložke aj nahrávanie.') ?>
<p>V karte <b>Verejný odkaz</b> vytvoríš adresu, ktorú môžeš poslať komukoľvek – aj bez účtu. Nastaviť môžeš:</p>
<ul>
  <li><b>Heslo</b> – kto ho nepozná, odkaz neotvorí.</li>
  <li><b>Platnosť do</b> – po tomto dni odkaz prestane fungovať.</li>
  <li><b>Režim pri zložke</b> – iba prezeranie, <b>iba nahrávanie</b> (napr. aby ti ľudia poslali fotky zo svadby a nevideli cudzie) alebo oboje.</li>
</ul>
<div class="warn">Ktokoľvek s odkazom (a heslom, ak je nastavené) uvidí zdieľaný obsah. Odkaz kedykoľvek zrušíš tlačidlom <b>Zrušiť odkaz</b>.</div>
<h3>Zdieľané so mnou a Zdieľané mnou</h3>
<?= fig('u-zdielane-so-mnou', 'Zdieľané so mnou – čo ti sprístupnili iní.') ?>
<p><b>Zdieľané so mnou</b> obsahuje všetko, čo s tebou zdieľali ostatní. Ak to už nepotrebuješ, daj <b>Odstrániť zo zdieľaných</b>.
<b>Zdieľané mnou</b> je prehľad toho, čo zdieľaš ty – s kým a či má verejný odkaz.</p>
<?= fig('u-zdielane-mnou', 'Zdieľané mnou – prehľad tvojich zdieľaní a odkazov.') ?>

<?= h2('hladanie', 'bi-search', 'Vyhľadávanie') ?>
<?= fig('u-hladanie-navrhy', 'Počas písania sa ukazujú návrhy – stačí kliknúť.') ?>
<p>Klikni do poľa <b>Hľadať v disku</b> (alebo stlač <kbd>/</kbd>) a začni písať. Hneď sa ukážu návrhy súborov a zložiek,
posledné hľadania a rýchle filtre. <kbd>Enter</kbd> zobrazí všetky výsledky.</p>
<?= fig('u-hladanie-vysledky', 'Výsledky hľadania s filtrami (čipy) – typ, vlastník, dátum, umiestnenie, veľkosť, štítok.') ?>
<h3>Filtre</h3>
<p>Pod nadpisom výsledkov sú <b>čipy</b>: Typ (zložky, dokumenty, tabuľky, prezentácie, PDF, obrázky, videá, zvuk, archívy…),
Vlastník (ja / iní), Upravené (dnes, 7 dní, 30 dní, rok, vlastný rozsah), Umiestnenie (Môj disk, zdieľané, kôš, s hviezdičkou), Veľkosť a Štítok.
Ikona <i class="bi bi-sliders"></i> v poli hľadania otvorí <b>rozšírené hľadanie</b> so všetkými možnosťami naraz.</p>
<?= fig('u-hladanie-rozsirene', 'Rozšírené hľadanie.') ?>
<h3>Skratky do poľa hľadania</h3>
<table class="table table-sm">
  <thead><tr><th>Napíš</th><th>Nájde</th></tr></thead>
  <tbody>
    <tr><td><code>zmluva byt</code></td><td>položky, ktoré obsahujú obe slová</td></tr>
    <tr><td><code>"nájomná zmluva"</code></td><td>presne túto frázu</td></tr>
    <tr><td><code>type:pdf</code>, <code>type:foto</code>, <code>type:zlozka</code>, <code>type:excel</code></td><td>iba daný typ</td></tr>
    <tr><td><code>owner:ja</code> / <code>owner:others</code></td><td>moje / od iných</td></tr>
    <tr><td><code>po:1.1.2026</code>, <code>pred:2026-06-30</code></td><td>upravené po / pred dátumom</td></tr>
    <tr><td><code>size&gt;10mb</code>, <code>size&lt;500kb</code></td><td>podľa veľkosti</td></tr>
    <tr><td><code>is:hviezdicka</code></td><td>iba s hviezdičkou</td></tr>
    <tr><td><code>in:kos</code>, <code>in:shared</code></td><td>v koši / v zdieľaných</td></tr>
    <tr><td><code>tag:Rodina</code></td><td>so štítkom</td></tr>
    <tr><td><code>-koncept</code></td><td>vylúči slovo</td></tr>
  </tbody>
</table>
<div class="tip">Ak administrátor zapol <b>hľadanie v obsahu</b>, Cloud nájde slová aj vnútri dokumentov (Word, Excel, PowerPoint, OpenDocument, TXT a PDF), nielen v názvoch.</div>

<?= h2('fotky', 'bi-images', 'Fotky, albumy a spomienky') ?>
<?= fig('u-fotky', 'Sekcia Fotky – všetky obrázky a videá podľa dátumu odfotenia.') ?>
<p><b>Fotky</b> zhromaždia všetky obrázky a videá zo všetkých zložiek a zoradia ich podľa dátumu odfotenia (z fotoaparátu),
rozdelené po mesiacoch. Hore sú karty <b>Fotky</b> a <b>Albumy</b>; vpravo prepínaš zobrazenie <i>fotky / mriežka / zoznam</i> a zoradenie.</p>
<h3>V tento deň</h3>
<p>Ak máš fotky z rovnakého dňa v minulých rokoch, nad fotkami sa ukáže pás <b>„V tento deň“</b> – klikni a zaspomínaj si.</p>
<h3>Albumy</h3>
<?= fig('u-albumy', 'Albumy s obálkou a počtom položiek.') ?>
<ol class="steps">
  <li>V karte <b>Albumy</b> klikni na <b>Nový album</b> a pomenuj ho.</li>
  <li>Vyber fotky (kdekoľvek v Cloude) a v ponuke daj <b>Pridať do albumu</b>.</li>
  <li>V albume môžeš fotku <b>Nastaviť ako obal albumu</b> alebo ju <b>Odstrániť z albumu</b> (súbor tým nezmizne).</li>
</ol>
<p>Album je len „výber“ – tá istá fotka môže byť vo viacerých albumoch a stále zaberá miesto iba raz.</p>
<?= fig('u-album', 'Obsah albumu.') ?>
<h3>Prezentácia</h3>
<p>V náhľade fotky klikni na <i class="bi bi-play-fill"></i> alebo stlač <b>medzerník</b> – fotky sa budú samy striedať na celej obrazovke.</p>

<?= h2('verzie', 'bi-clock-history', 'Verzie, história a kôš') ?>
<h3>Verzie súboru</h3>
<?= fig('u-verzie', 'Staršie verzie súboru – obnovenie jedným klikom.') ?>
<p>Pri každej zmene súboru (nahratie s rovnakým názvom, úprava textu, synchronizácia z mobilu) sa predchádzajúci obsah uloží ako verzia.
V ponuke <b>Verzie</b> ich vidíš s dátumom a veľkosťou – môžeš ich stiahnuť, <b>obnoviť</b> (aktuálny stav sa pritom tiež uloží ako verzia) alebo zmazať.
Počet uchovaných verzií nastavuje administrátor (predvolene 10).</p>
<h3>História zmien a Aktivita</h3>
<?= fig('u-historia', 'Aktivita jednej položky – história zmien.') ?>
<p>Položka ponuky <b>Aktivita</b> pri súbore či zložke ukáže, kto ju kedy vytvoril, upravil, premenoval, presunul, zdieľal či zmazal. Sekcia <b>Aktivita</b>
v menu je to isté pre celý tvoj disk – ako tabuľka, ktorú môžeš zoraďovať podľa stĺpcov a filtrovať.</p>
<?= fig('u-aktivita', 'Aktivita – všetky udalosti na disku.') ?>
<h3>Vrátiť zložku do stavu k dátumu</h3>
<?= fig('u-rewind', 'Vrátenie zložky do stavu k vybranému dňu a času.') ?>
<p>Ak sa v zložke niečo pokazilo (omylom zmazané, prepísané, presunuté), daj na zložke <b>Vrátiť do stavu k…</b>, vyber dátum a čas
a Cloud ukáže, čo všetko sa zmení. Po potvrdení sa obnovia zmazané položky a staré verzie súborov; to, čo pribudlo neskôr, sa presunie do koša.</p>
<h3>Kôš</h3>
<?= fig('u-kos', 'Kôš – obnovenie alebo trvalé zmazanie.') ?>
<p>Zmazané položky sú v <b>Koši</b>. Odtiaľ ich <b>obnovíš</b> na pôvodné miesto alebo <b>zmažeš natrvalo</b> (<kbd>Shift</kbd>+<kbd>Delete</kbd>).
<b>Vysypať kôš</b> zmaže všetko naraz. Položky sa z koša samy natrvalo zmažú po nastavenom počte dní (predvolene 30) – 3 dni predtým dostaneš upozornenie.</p>
<div class="warn">Natrvalo zmazané súbory sa už obnoviť nedajú. Položky v koši sa započítavajú do obsadeného miesta.</div>

<?= h2('zamky', 'bi-lock-fill', 'Zamknuté zložky') ?>
<?= fig('u-zamknut', 'Zamknutie zložky heslom a výber, kedy sa má znova pýtať.') ?>
<p>Citlivé dokumenty môžeš dať do zložky zamknutej <b>vlastným heslom</b> (iným ako heslo k účtu). Kým ju neodomkneš, nevidno jej obsah –
ani v hľadaní, Fotkách či Nedávnych.</p>
<ol class="steps">
  <li>Na zložke vyber <b>Zamknúť heslom…</b>, zadaj heslo dvakrát.</li>
  <li>Zvoľ, <b>kedy sa má heslo pýtať znova</b>: <i>vždy</i> (pri každom otvorení), <i>po zatvorení prehliadača / aplikácie</i>, alebo <i>po odhlásení</i>.</li>
  <li>Hotovo – zložka má ikonu zámku.</li>
</ol>
<?= fig('u-zamknuta', 'Zamknutá zložka čaká na heslo.') ?>
<p>V aplikácii pre Android môžeš zložku odomykať <b>odtlačkom prsta</b> – po prvom zadaní hesla ti to aplikácia ponúkne.
Zložku kedykoľvek hneď zamkneš cez <b>Zamknúť teraz</b> (v ponuke zložky alebo tlačidlom so zámkom hore). Heslo, režim alebo zrušenie zámku nájdeš v <b>Heslo zložky…</b>.</p>
<div class="tip">Zabudol si heslo zložky? V okne <b>Heslo zložky…</b> ho môžeš zmeniť zadaním <b>hesla k svojmu účtu</b>.</div>

<?= h2('ucet', 'bi-person-gear', 'Môj účet') ?>
<?= fig('u-profil', 'Môj účet – osobné údaje, heslo, zariadenia, odtlačok prsta, stĺpce, e-mailový súhrn, sieťový disk.') ?>
<h3>Osobné údaje a profilová fotka</h3>
<p>Zmeníš meno, e-mail (nový e-mail treba potvrdiť) a profilovú fotku. Fotka je na serveri uložená zašifrovaná.</p>
<h3>Heslo a prihlásené zariadenia</h3>
<p>V časti <b>Zmena hesla</b> zadáš súčasné a nové heslo. <b>Prihlásené zariadenia</b> ukazujú, kde všade si prihlásený (prehliadače a aplikácie) –
neznáme zariadenie môžeš odhlásiť. Pri prihlásení z nového zariadenia ti môže prísť upozornenie e-mailom.</p>
<h3>Prihlásenie odtlačkom prsta (passkey)</h3>
<p>Klikni na <b>Pridať</b> a potvrď odtlačkom, tvárou alebo PIN-om zariadenia. Potom sa na prihlasovacej stránke prihlásiš tlačidlom
<b>Prihlásiť sa odtlačkom prsta</b> bez hesla. Funguje na počítačoch s Windows Hello / Touch ID aj v mobile.</p>
<h3>E-mailový súhrn</h3>
<?= fig('u-suhrn', 'Nastavenie e-mailového súhrnu: ako často, v ktorý deň a o koľkej.') ?>
<p>Súhrn je e-mail s prehľadom: čo pribudlo, kto ti čo nahral alebo s tebou zdieľal, koľko miesta ostáva a spomienky z Fotiek.
Nastavíš si:</p>
<ul>
  <li><b>Ako často</b> – každý deň, raz týždenne, každé 2 týždne, raz mesačne alebo vypnutý,</li>
  <li><b>V deň</b> – pri týždennom súhrne deň v týždni, pri mesačnom deň v mesiaci (1.–28.),</li>
  <li><b>O koľkej</b> – hodina, kedy má prísť.</li>
</ul>
<p>Pod výberom vidíš, kedy príde najbližší. <b>Poslať ukážku teraz</b> ti ho pošle hneď. Ak sa za obdobie nič nestalo, e-mail nepríde.</p>
<h3>Stĺpce v zozname</h3>
<?= fig('u-stlpce', 'Výber stĺpcov pre jednotlivé zobrazenia.') ?>
<h3>Sieťový disk (WebDAV)</h3>
<?= fig('u-webdav', 'Údaje na pripojenie Cloudu ako sieťového disku.') ?>
<p>Cloud si môžeš pripojiť ako bežný disk vo <b>Windows</b> (Tento počítač → Pripojiť sieťovú jednotku), na <b>Macu</b> (Finder → Pripojiť k serveru)
alebo v správcovi súborov v mobile. Adresu nájdeš v Môj účet. Prihlasuješ sa e-mailom a <b>samostatným heslom pre sieťový disk</b>, ktoré si tam vytvoríš (pomenuj ho napr. „Notebook“ –
heslo sa zobrazí iba raz, tak si ho skopíruj). Tvoje hlavné heslo sa tak v počítači nikde neukladá a každé heslo pre sieťový disk vieš zvlášť zrušiť. Súbory potom kopíruješ myšou ako na USB kľúč.</p>
<h3>Viac účtov a prepínanie</h3>
<?= fig('u-ucty', 'Menu účtu: prepnutie na iný účet, pridanie účtu, odhlásenie.') ?>
<p>Kliknutím na avatar vpravo hore vidíš uložené účty. <b>Pridať účet</b> ťa prihlási do ďalšieho účtu bez odhlásenia z prvého;
medzi účtami potom prepínaš jedným klikom. <b>Odhlásiť všetky</b> odhlási všetky účty v tomto prehliadači.</p>
<h3>Upozornenia</h3>
<p>Zvonček hore ukazuje nové udalosti: zdieľanie, nahratie cez verejný odkaz, dochádzajúce miesto, kôš pred vysypaním, prihlásenie z nového zariadenia.
Kliknutím na upozornenie sa presunieš na dané miesto.</p>
<h3>Miesto na disku</h3>
<p>Dole v menu vidíš obsadené miesto a limit. Pri 80 % ťa Cloud upozorní. Miesto uvoľníš zmazaním súborov, <b>vysypaním koša</b>,
zmazaním starých verzií a v sekcii <b>Duplicitné súbory</b>.</p>

<?= h2('aplikacia', 'bi-android2', 'Aplikácia pre Android') ?>
<?= fig('m-fotky', 'Fotky v aplikácii.', 'phone') ?>
<h3>Inštalácia a prihlásenie</h3>
<ol class="steps">
  <li>V <b>Môj účet</b> klikni na <b>Stiahnuť aplikáciu pre Android</b> a otvor stiahnutý súbor.</li>
  <li>Ak to telefón vyžaduje, povoľ inštaláciu z tohto zdroja.</li>
  <li>V aplikácii zadaj adresu servera (ak sa nevyplnila sama), e-mail a heslo.</li>
</ol>
<p>Aplikácia sa vie <b>sama aktualizovať</b> – keď vyjde nová verzia, ponúkne ju (alebo v menu <b>Aktualizácia aplikácie</b>).</p>
<h3>Automatické nahrávanie fotiek a synchronizácia</h3>
<p>V menu <b>Synchronizácia telefónu</b> ťukni na <b>Pridať zložku</b>, vyber zložku v telefóne (napr. <i>DCIM/Camera</i>) a cieľovú zložku v Cloude.
Nové fotky a súbory sa nahrajú hneď, ako vzniknú. Nastaviť môžeš:</p>
<ul>
  <li><b>Automatická synchronizácia</b> zapnutá / vypnutá,</li>
  <li><b>Nahrávať iba cez Wi-Fi</b> – šetrí mobilné dáta,</li>
  <li><b>Kontrola zmien</b> každých 5 minút až 3 hodiny,</li>
  <li><b>Widget</b> na plochu so stavom synchronizácie.</li>
</ul>
<div class="note">Synchronizácia je jednosmerná (telefón → Cloud): súbory zmazané v telefóne zostanú v Cloude. Zmenený súbor sa uloží ako nová verzia.</div>
<h3>Poslať do Cloudu z iných aplikácií</h3>
<p>V galérii, správcovi súborov či inej aplikácii daj <b>Zdieľať → Cloud</b>, vyber cieľovú zložku (alebo vytvor novú) a ťukni na <b>Nahrať</b>.
Nahrávanie pokračuje aj po zatvorení aplikácie – priebeh vidíš v notifikácii.</p>
<h3>Otváranie súborov</h3>
<p>Fotky, videá a PDF sa otvoria priamo v aplikácii. Ostatné súbory (Word, Excel…) cez <b>Otvoriť v aplikácii…</b> v ponuke otvoríš v programe v telefóne.</p>
<h3>Beh na pozadí</h3>
<p>Aby synchronizácia a upozornenia fungovali spoľahlivo, v menu <b>Beh na pozadí</b> skontroluj odporúčané nastavenia (vypnutá optimalizácia batérie a pod.).
Tip: v zozname otvorených aplikácií podrž kartu Cloud a ťukni na zámok.</p>
<h3>Viac účtov a odtlačok prsta</h3>
<p>Aj v aplikácii môžeš mať viac účtov a prepínať medzi nimi cez avatar. Zamknuté zložky odomkneš odtlačkom prsta.
V nastaveniach aplikácie je aj voľba <b>Odomykať odtlačkom prsta</b> pri otvorení aplikácie.</p>
<h3>Server</h3>
<p>V menu <b>Server</b> zmeníš adresu servera (napr. doma cez Wi-Fi a mimo domu cez Tailscale) a tlačidlom <b>Otestovať</b> overíš spojenie.</p>
<div class="note">Aplikácia si na disk telefónu neukladá miniatúry ani zoznamy súborov – všetko zostáva na serveri. V telefóne sú iba súbory, ktoré si sám stiahneš alebo synchronizuješ.</div>

<?= h2('skratky', 'bi-keyboard', 'Klávesové skratky') ?>
<?= fig('u-skratky', 'Zoznam skratiek otvoríš klávesom ?.') ?>
<table class="table table-sm">
  <thead><tr><th style="width:40%">Kláves</th><th>Čo robí</th></tr></thead>
  <tbody>
    <tr><td><kbd>/</kbd></td><td>Hľadať</td></tr>
    <tr><td><kbd>?</kbd></td><td>Zoznam skratiek</td></tr>
    <tr><td><kbd>U</kbd></td><td>Nahrať súbory</td></tr>
    <tr><td><kbd>Shift</kbd>+<kbd>F</kbd> / <kbd>Shift</kbd>+<kbd>T</kbd></td><td>Nová zložka / nový textový súbor</td></tr>
    <tr><td><kbd>V</kbd></td><td>Zoznam ↔ mriežka</td></tr>
    <tr><td><kbd>G</kbd> potom <kbd>D</kbd> / <kbd>R</kbd> / <kbd>S</kbd> / <kbd>P</kbd> / <kbd>A</kbd> / <kbd>K</kbd></td><td>Môj disk / Nedávne / S hviezdičkou / Fotky / Aktivita / Kôš</td></tr>
    <tr><td><kbd>Alt</kbd>+<kbd>↑</kbd> alebo <kbd>Backspace</kbd></td><td>O úroveň vyššie</td></tr>
    <tr><td>Šípky, <kbd>Home</kbd>, <kbd>End</kbd></td><td>Pohyb medzi položkami</td></tr>
    <tr><td><kbd>Medzerník</kbd>, <kbd>Shift</kbd>+šípky, <kbd>Ctrl</kbd>+<kbd>A</kbd>, <kbd>Esc</kbd></td><td>Označiť, rozšíriť výber, všetko, zrušiť výber</td></tr>
    <tr><td><kbd>Enter</kbd></td><td>Otvoriť</td></tr>
    <tr><td><kbd>F2</kbd> alebo <kbd>N</kbd></td><td>Premenovať</td></tr>
    <tr><td><kbd>Delete</kbd> / <kbd>Shift</kbd>+<kbd>Delete</kbd></td><td>Do koša / natrvalo (v koši)</td></tr>
    <tr><td><kbd>S</kbd>, <kbd>I</kbd>, <kbd>.</kbd>, <kbd>D</kbd>, <kbd>Z</kbd>, <kbd>H</kbd></td><td>Hviezdička, informácie, zdieľať, stiahnuť, presunúť, história</td></tr>
    <tr><td><kbd>Ctrl</kbd>+<kbd>C</kbd> / <kbd>X</kbd> / <kbd>V</kbd></td><td>Kopírovať / vystrihnúť / vložiť</td></tr>
    <tr><td><kbd>Shift</kbd>+<kbd>F10</kbd></td><td>Ponuka položky</td></tr>
  </tbody>
</table>

<?= h2('bezpecnost', 'bi-shield-check', 'Bezpečnosť a súkromie') ?>
<ul>
  <li><b>Šifrovanie</b> – ak ho administrátor zapol, súbory sú na disku servera zašifrované (AES-256); bez kľúča ich nikto neprečíta ani pri krádeži disku.</li>
  <li><b>Žiadne sledovanie</b> – Cloud nepoužíva reklamy, analytiku ani cudzie služby. Okrem tvojho servera komunikuje iba s GitHubom kvôli aktualizáciám.</li>
  <li><b>Nič v zariadení</b> – miniatúry, náhľady a zoznamy sa neukladajú na disk počítača ani telefónu.</li>
  <li><b>Silné heslo</b> – používaj dlhé heslo, ktoré nepoužívaš inde, alebo prihlásenie odtlačkom.</li>
  <li><b>Zariadenia</b> – v Môj účet pravidelne skontroluj prihlásené zariadenia a neznáme odhlás.</li>
</ul>

<?= h2('problemy', 'bi-life-preserver', 'Časté otázky a riešenie problémov') ?>
<h3>Súbor sa nenahral / nahrávanie stojí</h3>
<p>Skontroluj pripojenie a voľné miesto (dole v menu). Nahraj súbor znova – pokračuje sa od miesta prerušenia. Ak je súbor väčší než povolený limit, Cloud to napíše.</p>
<h3>Nevidím miniatúry videí</h3>
<p>Miniatúry videí vyžadujú na serveri program ffmpeg – povedz administrátorovi.</p>
<h3>Niečo som omylom zmazal</h3>
<p>Pozri do <b>Koša</b>. Ak tam nie je, skús na nadradenej zložke <b>Vrátiť do stavu k…</b> alebo staršiu <b>verziu</b> súboru.</p>
<h3>Nepríde mi e-mail</h3>
<p>Pozri do priečinka Spam/Nevyžiadaná pošta. Ak nechodí žiadny e-mail, administrátor má skontrolovať nastavenie SMTP.</p>
<h3>Zabudol som heslo</h3>
<p>Na prihlasovacej stránke klikni na <b>Zabudnuté heslo</b>. Ak nemáš prístup k e-mailu, heslo ti nastaví administrátor.</p>
<h3>Aplikácia nesynchronizuje</h3>
<p>V aplikácii otvor <b>Synchronizácia telefónu</b> – pri zložke uvidíš chyby. Skontroluj <b>Beh na pozadí</b>, či nemáš zapnuté „iba cez Wi-Fi“ a či je server dostupný (<b>Server → Otestovať</b>).</p>
