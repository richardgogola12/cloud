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
  <li><b>Mesiac</b> – prepína svetlý a tmavý vzhľad. Viac možností (farba, hustota, veľkosť náhľadov…) je v <b>avatar → Vzhľad a ovládanie</b> (kapitola <a href="#vzhlad">Vzhľad a ovládanie</a>).</li>
  <li><b>Tvoj avatar</b> – účet, prepínanie medzi účtami, odhlásenie.</li>
  <li><b>Prepínač zobrazenia</b> vpravo nad zoznamom – <i>zoznam</i> alebo <i>mriežka</i> a zoradenie.</li>
</ol>
<?= fig('u-tmavy', 'Tmavý režim – prepneš ho ikonou mesiaca vpravo hore.') ?>

<h3>V mobile</h3>
<p>Na telefóne sa menu skryje – otvoríš ho ikonou <i class="bi bi-list"></i> vľavo hore alebo <b>potiahnutím prsta od ľavého okraja doprava</b>
(zatvoríš potiahnutím doľava). Dole je <b>spodná lišta</b> s položkami Môj disk, Nedávne, Fotky a Zdieľané – vypneš ju vo <a href="#vzhlad">Vzhľad a ovládanie</a>.
Akcie pre súbor nájdeš pod tlačidlom <b>⋮</b> pri každej položke, najčastejšie aj potiahnutím riadku (pozri <a href="#gesta">Gestá</a>).</p>
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
<h3>Nová zložka, dokument, tabuľka a textový súbor</h3>
<p><b>+ Nový → Nová zložka</b> (<kbd>Shift</kbd>+<kbd>F</kbd>), <b>Nový dokument</b> (Word, kapitola <a href="#dokumenty">Dokumenty</a>), <b>Nová tabuľka</b> (Excel, kapitola <a href="#tabulky">Tabuľky</a>) a <b>Nový textový súbor</b> (<kbd>Shift</kbd>+<kbd>T</kbd>).
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
Na počítači môžeš <b>ťahať myšou na prázdnom mieste</b> vedľa položiek – vznikne obdĺžnik a všetko, čoho sa dotkne, sa označí
(s <kbd>Ctrl</kbd> sa pridáva k doterajšiemu výberu). Pri okraji okna sa zoznam sám posúva.
V mobile podrž prst na položke a potom ťukaj na ďalšie – alebo po podržaní <b>neodťahuj prst a posúvaj ho</b> hore či dole: označí sa celý rad položiek naraz.</p>
<h3>Presúvanie myšou</h3>
<p>Položky môžeš potiahnuť na zložku v zozname alebo na niektorú časť cesty hore (napr. „Môj disk“). Fungujú aj
<kbd>Ctrl</kbd>+<kbd>C</kbd> / <kbd>Ctrl</kbd>+<kbd>X</kbd> a <kbd>Ctrl</kbd>+<kbd>V</kbd> do inej zložky.</p>
<h3>Informácie o súbore</h3>
<?= fig('u-info', 'Okno Informácie: všetky údaje o súbore na jednom mieste.') ?>
<h3>Náhľad</h3>
<?= fig('u-nahlad', 'Náhľad fotky. Šípkami listuješ, medzerníkom spustíš prezentáciu.') ?>
<p>Dvojklikom (v mobile ťuknutím) otvoríš náhľad. Obrázky a fotky, videá (aj posúvanie v nich), hudba, PDF a textové súbory sa
zobrazia priamo. Šípkami <kbd>←</kbd> <kbd>→</kbd> (v mobile potiahnutím) prejdeš na ďalší súbor, <kbd>D</kbd> stiahne, <kbd>Esc</kbd> zavrie.
Fotku <b>priblížiš</b> dvoma prstami, dvojitým ťuknutím (dvojklikom), kolieskom myši alebo klávesmi <kbd>+</kbd> / <kbd>−</kbd>; <kbd>0</kbd> alebo ďalšie dvojité ťuknutie
ukáže celú fotku. Priblíženú fotku posúvaš prstom alebo myšou a dole vidíš percentá s tlačidlami − + a „celá fotka“.
Ostatné typy (napr. Word, Excel) si stiahneš alebo v aplikácii otvoríš v inej aplikácii v telefóne.</p>
<h3>Farby, štítky a hviezdičky</h3>
<p><b>Farbu</b> zložky alebo súboru nastavíš v okne <b>Štítky a farba…</b> (ponuka položky) – farebný pásik ju zvýrazní v zozname. <b>Štítky</b> sú farebné menovky (napr. „Dôležité“, „Rodina“) –
jedna položka môže mať viac štítkov a v menu vľavo klikneš na štítok a uvidíš všetko, čo ho má. Štítky spravuješ ikonou ozubeného
kolesa pri nadpise Štítky. <b>Hviezdička</b> označí obľúbené položky – nájdeš ich v sekcii <b>S hviezdičkou</b>.</p>
<?= fig('u-hviezdicky', 'Sekcia S hviezdičkou.') ?>
<h3>Pripnuté zložky</h3>
<?= fig('u-pripnute', 'Pripnuté zložky v menu vľavo.') ?>
<p>Zložky, do ktorých chodíš často, si pripni: v ponuke zložky <b>Pripnúť do menu</b>. Objavia sa v menu vľavo v časti <b>Pripnuté</b> (na webe aj v aplikácii)
a otvoríš ich jedným klikom odkiaľkoľvek. Rovnakou položkou ich odopneš. Pripnúť môžeš najviac 20 zložiek – aj zdieľaných s tebou.</p>
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
<?= fig('u-tabulka-menu', 'Ponuka Formát s podponukami: číslo, text, zarovnanie, zalamovanie, otočenie, veľkosť písma, zlúčenie, podmienené formátovanie a striedavé farby.') ?>
<h3>Ponuky ako v Google Tabuľkách</h3>
<p>Ponuky majú podponuky (prejdi myšou alebo klikni na položku so šípkou). Keď nevieš, kde čo je, stlač <kbd>Alt</kbd>+<kbd>/</kbd> (alebo lupu vľavo na paneli nástrojov) a napíš, čo hľadáš – napr. „tlač“, „ukotviť“, „duplikáty“.</p>
<table class="table table-sm">
  <tr><td><b>Súbor</b></td><td>Nová tabuľka alebo dokument, <b>import</b> (CSV, Excel, ODS – do nového hárka, namiesto aktuálneho alebo na koniec), vytvoriť kópiu, <b>zdieľať</b>,
    stiahnuť ako <b>Excel (.xlsx)</b>, <b>OpenDocument (.ods)</b>, <b>PDF</b>, <b>webová stránka</b>, <b>CSV</b> alebo <b>TSV</b>, premenovať, presunúť, do koša, <b>história verzií</b>, aktivita, podrobnosti, <b>tlačiť</b>.</td></tr>
  <tr><td><b>Úpravy</b></td><td>Späť / znova, vystrihnúť, kopírovať, <b>prilepiť špeciálne</b> (iba hodnoty, iba formát, iba vzorce, transponované), <b>presunúť</b> riadok či stĺpec,
    odstrániť hodnoty, riadky, stĺpce alebo bunky s posunom, <b>nájsť a nahradiť</b>.</td></tr>
  <tr><td><b>Zobraziť</b></td><td>Mriežka, riadok vzorcov, <b>zobraziť vzorce</b> (<kbd>Ctrl</kbd>+<kbd>`</kbd>), <b>ukotvenie</b> 1 – 2 riadkov či stĺpcov alebo po aktuálnu bunku,
    skryť a zobraziť riadky, stĺpce a hárky, priblíženie, celá obrazovka.</td></tr>
  <tr><td><b>Vložiť</b></td><td>Bunky s posunom, riadky, stĺpce, hárok, <b>graf</b>, <b>kontingenčná tabuľka</b>, obrázok, funkcie (SUM, AVERAGE, COUNT, MAX, MIN a všetky ďalšie), odkaz,
    <b>zaškrtávacie políčko</b>, <b>rozbaľovací zoznam</b>, komentár, dnešný dátum a aktuálny čas.</td></tr>
  <tr><td><b>Formát</b></td><td>Formát čísla (automaticky, text, <b>číslo</b>, <b>percentá</b>, vedecký, <b>mena</b>, účtovný, <b>dátum</b>, čas, trvanie, vlastný), text, zarovnanie vodorovné aj zvislé,
    zalamovanie (pretečenie, zalomiť, orezať), otočenie textu, veľkosť písma, <b>zlúčenie</b> (všetko, vodorovne, zvisle), <b>podmienené formátovanie</b>, <b>striedavé farby</b>, vymazanie formátovania.</td></tr>
  <tr><td><b>Údaje</b></td><td>Zoradiť hárok alebo rozsah (aj podľa viacerých stĺpcov, s hlavičkou), <b>filter</b>, <b>ochrana hárka</b> heslom, náhodné poradie,
    <b>štatistiky stĺpca</b> (súčet, priemer, medián, najčastejšie hodnoty), overenie údajov, <b>odstrániť duplikáty</b>, orezať medzery, rozdeliť text do stĺpcov.</td></tr>
  <tr><td><b>Nástroje</b></td><td>Prepočítať vzorce, podmienené formátovanie, ochrana hárka, súčet a počet označených buniek.</td></tr>
</table>
<p>Pod ponukami je <b>panel nástrojov</b> ako v Google Tabuľkách: hľadanie v ponukách, späť, znova, <b>tlač</b>, kopírovanie formátu, <b>priblíženie</b>, mena, percentá, desatinné miesta, formáty, písmo, veľkosť,
tučné, kurzíva, farby, orámovanie, zlúčenie, zarovnanie, zalamovanie, otočenie, odkaz, komentár, graf, filter a funkcie. Pod ním je <b>riadok so vzorcom</b>. Pravým klikom na bunku, riadok, stĺpec alebo hárok
otvoríš ďalšie možnosti (vložiť, odstrániť, skryť, zoradiť, prevrátiť, farba hárka…). <b>Hárky</b> pridáš tlačidlom <b>+</b> vľavo dole. Keď označíš viac buniek, vpravo dole uvidíš <b>súčet, priemer a počet</b>.
<kbd>Ctrl</kbd>+<kbd>C</kbd> / <kbd>Ctrl</kbd>+<kbd>V</kbd> funguje aj medzi Cloudom a Excelom či Google Tabuľkami, <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>V</kbd> prilepí iba hodnoty. Všetky skratky sú v <b>Pomoc → Klávesové skratky</b>.</p>
<h3>Tlač a PDF</h3>
<p><b>Súbor → Tlačiť</b> (<kbd>Ctrl</kbd>+<kbd>P</kbd> alebo tlačiareň na paneli) otvorí rovnaké nastavenia ako v Google Tabuľkách: vľavo <b>ukážka strán</b>, vpravo čo tlačiť (aktuálny hárok, celý zošit
alebo vybrané bunky), veľkosť papiera, orientácia, <b>mierka</b> (normálna, prispôsobiť šírke, výške alebo strane, vlastné percentá), okraje, mriežka, poznámky, poradie strán, zarovnanie na strane,
<b>hlavičky a päty</b> (čísla strán, názov zošita a hárka, dátum, čas) a <b>opakovanie ukotvených riadkov a stĺpcov</b> na každej strane. Tlačidlo <b>Ďalej</b> otvorí okno tlače – tam vyberieš tlačiareň
alebo <b>Uložiť ako PDF</b>. Grafy a obrázky sa vytlačia tam, kde sú v tabuľke.</p>
<h3>Ukladanie a čo sa zachová</h3>
<p>Zmeny sa <b>uložia samé</b> pár sekúnd po úprave (alebo hneď cez <kbd>Ctrl</kbd>+<kbd>S</kbd>) – hore pri názve uvidíš „Všetky zmeny sú uložené v Cloude“. Každé uloženie vytvorí novú verziu –
predchádzajúci stav nájdeš vo <b>Verziách</b>. Do súboru .xlsx sa uloží obsah, vzorce, formáty čísel, písmo a farby, orámovanie, zlúčené bunky, šírky a výšky, ukotvenie, filter,
podmienené formátovanie, overenie údajov, komentáre, odkazy aj obrázky, takže súbor otvoríš rovnako v Exceli. <b>Grafy</b> vytvorené v Cloude si Cloud pamätá pri súbore a ukáže ich pri ďalšom otvorení v Cloude
(Excel ich nezobrazí).</p>
<div class="warn">Grafy a kontingenčné tabuľky vytvorené v Exceli a makrá editor nezobrazí. Ak ich súbor obsahuje, Cloud ťa upozorní a odporučí ho otvoriť <b>iba na čítanie</b>, aby sa uložením nestratili.
Staré formáty <b>.xls</b> a <b>.xlsm</b> sa uložia ako nový súbor <b>.xlsx</b>. Ak súbor medzitým zmenil niekto iný, Cloud ho neprepíše a upozorní ťa. CSV obsahuje iba jeden hárok a hodnoty (bez formátovania).</div>

<?= h2('dokumenty', 'bi-file-earmark-text', 'Dokumenty (Word)') ?>
<?= fig('u-dokument', 'Editor dokumentov vyzerá ako Google Dokumenty: ponuky, panel nástrojov, pravítko a strany.') ?>
<p>Dokumenty <b>.docx</b> (Word) píšeš a upravuješ priamo v Cloude – na počítači aj v mobile. Editor vyzerá a ovláda sa ako Google Dokumenty, je po slovensky
a všetko beží v tvojom prehliadači (nič sa neposiela na cudzie servery).</p>
<ul>
  <li><b>Nový dokument:</b> <b>+ Nový → Nový dokument</b>, zadaj názov (alebo v editore <b>Súbor → Nový → Dokument</b>).</li>
  <li><b>Úprava:</b> v ponuke súboru <b>Upraviť</b> alebo v náhľade tlačidlo <b>Upraviť</b>. Šípka vľavo hore (v telefóne tlačidlo Späť) editor zavrie. Na názov dokumentu klikneš a premenuješ ho.</li>
  <li><b>Strany:</b> dokument je rozdelený na strany ako vo Worde – text, tabuľky aj dlhé odseky sa samé prelejú na ďalšiu stranu, nadpis ostane s textom pod ním. V <b>Zobraziť → Rozloženie bez strán</b> píšeš na jednu dlhú plochu.</li>
  <li><b>Hľadať v ponukách:</b> <kbd>Alt</kbd>+<kbd>/</kbd> alebo lupa vľavo na paneli – napíš napr. „riadkovanie“, „obsah“, „poznámka“.</li>
</ul>
<h3>Ponuky ako v Google Dokumentoch</h3>
<table class="table table-sm">
  <tr><td><b>Súbor</b></td><td>Nový dokument či tabuľka, vytvoriť kópiu, <b>zdieľať</b>, stiahnuť ako <b>Word (.docx)</b>, <b>OpenDocument (.odt)</b>, <b>PDF</b>, <b>RTF</b>, text, webová stránka, <b>EPUB</b> alebo <b>Markdown</b>,
    premenovať, presunúť, do koša, <b>história verzií</b>, aktivita, podrobnosti, <b>nastavenie strany</b> (veľkosť papiera, orientácia, okraje, farba strany), <b>tlačiť</b>.</td></tr>
  <tr><td><b>Úpravy</b></td><td>Späť, znova, vystrihnúť, kopírovať, prilepiť, <b>prilepiť bez formátovania</b>, vybrať všetko, <b>nájsť a nahradiť</b> (aj s rozlišovaním veľkých písmen a regulárnymi výrazmi).</td></tr>
  <tr><td><b>Zobraziť</b></td><td><b>Režim</b> úpravy / navrhovanie / zobrazenie, rozloženie bez strán, pravítko, <b>prehľad dokumentu</b>, netlačiteľné znaky, komentáre, počet slov počas písania, celá obrazovka.</td></tr>
  <tr><td><b>Vložiť</b></td><td>Obrázok (zo súboru, z webovej adresy, fotoaparát), <b>tabuľka</b> (výber veľkosti myšou), <b>kreslenie</b>, <b>graf</b>, vodorovná čiara, <b>špeciálne znaky a emodži</b>,
    <b>hlavičky a päty</b>, <b>čísla strán</b>, zlom strany a stĺpca, odkaz, <b>komentár</b>, <b>záložka</b>, <b>obsah</b>, <b>poznámka pod čiarou</b>, dátum, kontrolný zoznam.</td></tr>
  <tr><td><b>Formát</b></td><td>Text (tučné, kurzíva, podčiarknuté, prečiarknuté, horný a dolný index, veľkosť, VEĽKÉ / malé písmená), <b>štýly odsekov</b> (Normálny text, Názov, Podnadpis, Nadpis 1 – 6, citát, kód),
    zarovnanie a <b>odsadenie</b> (aj prvého riadka a predsadenie), <b>riadkovanie</b> a medzery pred a za odsekom, <b>stĺpce</b>, odrážky a číslovanie (rôzne typy, kontrolný zoznam),
    <b>tabuľka</b> (riadky, stĺpce, zlúčenie, farby, okraje, pripnutá hlavička, zoradenie), obrázok (veľkosť, obtekanie textom, alternatívny text), vymazanie formátovania.</td></tr>
  <tr><td><b>Nástroje</b></td><td>Kontrola pravopisu, <b>počet slov</b>, prezrieť navrhované úpravy, hlasové písanie, predvoľby (automatické veľké písmená, slovenské úvodzovky „…“, odkazy, zoznamy, nahrádzanie (c) → ©).</td></tr>
</table>
<p><b>Panel nástrojov</b> je ako v Google Dokumentoch: hľadanie v ponukách, späť, znova, tlač, pravopis, <b>kopírovať formát</b> (štetec – dvojklikom viackrát), priblíženie, štýl odseku, písmo, veľkosť (− / +),
tučné, kurzíva, podčiarknuté, farba textu a zvýraznenia (paleta farieb), odkaz, komentár, obrázok, zarovnanie, riadkovanie, kontrolný zoznam, odrážky a číslovanie (s výberom typu), odsadenie, vymazanie formátovania a vpravo <b>režim</b>.
Pod ním je <b>pravítko</b> – ťahaním modrých značiek zmeníš odsadenie odseku, ťahaním šedých okrajov okraje strany. Pravým klikom do textu otvoríš rýchlu ponuku (aj pre tabuľku a obrázok).</p>
<ul>
  <li><b>Hlavičky a päty:</b> dvojklik do horného alebo dolného okraja strany (alebo Vložiť → Hlavičky a päty). Môžeš vložiť číslo strany, počet strán a mať inú prvú stranu.</li>
  <li><b>Poznámky pod čiarou</b> (<kbd>Ctrl</kbd>+<kbd>Alt</kbd>+<kbd>F</kbd>) sa číslujú samé a zobrazia sa dole na strane – tam ich aj píšeš.</li>
  <li><b>Obsah</b> sa poskladá z nadpisov; ukážeš naň myšou a tlačidlom ↻ ho aktualizuješ. Čísla strán sa dopĺňajú samé.</li>
  <li><b>Komentáre</b> (<kbd>Ctrl</kbd>+<kbd>Alt</kbd>+<kbd>M</kbd>): označ text, napíš komentár. Na komentáre sa dá odpovedať, upraviť ich, vyriešiť alebo zmazať – sú v paneli vpravo (ikona bubliny hore).</li>
  <li><b>Navrhovanie:</b> v režime <b>Navrhovanie</b> sa tvoje úpravy nezapíšu hneď, ale ako návrhy (pridaný text zelený, vymazaný prečiarknutý). Ty alebo ten, s kým dokument zdieľaš, ich potom
    <b>prijme</b> ✓ alebo <b>odmietne</b> ✗ – jednotlivo alebo všetky naraz. Vo Worde sa zobrazia ako sledované zmeny.</li>
  <li><b>Obrázky</b> vložíš aj prilepením (<kbd>Ctrl</kbd>+<kbd>V</kbd>) alebo potiahnutím. Klikni na obrázok – rohmi zmeníš veľkosť, nad ním vyberieš obtekanie textom. <b>Kreslenie</b> a <b>grafy</b> dvojklikom znova upravíš.</li>
  <li><b>Skratky:</b> všetky sú v <b>Pomoc → Klávesové skratky</b> (<kbd>Ctrl</kbd>+<kbd>/</kbd>) – napr. <kbd>Ctrl</kbd>+<kbd>Alt</kbd>+<kbd>1</kbd>–<kbd>6</kbd> nadpisy, <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>7</kbd>/<kbd>8</kbd>/<kbd>9</kbd> zoznamy,
    <kbd>Ctrl</kbd>+<kbd>Enter</kbd> zlom strany, <kbd>Ctrl</kbd>+<kbd>K</kbd> odkaz, <kbd>Ctrl</kbd>+<kbd>H</kbd> nájsť a nahradiť, <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>V</kbd> prilepiť bez formátovania.</li>
</ul>
<h3>Tlač a PDF</h3>
<p><b>Súbor → Tlačiť</b> (<kbd>Ctrl</kbd>+<kbd>P</kbd> alebo tlačiareň na paneli) vytlačí dokument presne tak, ako ho vidíš: strany, hlavičky a päty s číslami strán, poznámky pod čiarou a farbu strany.
V okne tlače vyberieš tlačiareň alebo <b>Uložiť ako PDF</b> (to isté urobí <b>Súbor → Stiahnuť → PDF</b>). Komentáre a vymazané návrhy sa netlačia.</p>
<p><b>Ukladanie:</b> zmeny sa uložia samé pár sekúnd po úprave (alebo hneď <kbd>Ctrl</kbd>+<kbd>S</kbd>), každé uloženie je nová verzia vo <b>Verziách</b>.
Do súboru Word sa uloží text, štýly, písmo a farby, odsadenie a riadkovanie, zoznamy, tabuľky, obrázky, odkazy, záložky, obsah, hlavičky a päty, čísla strán, poznámky pod čiarou,
komentáre, návrhy (ako sledované zmeny), nastavenie strany a stĺpce – dokument otvoríš rovnako vo Worde.</p>
<div class="warn">Textové polia, grafy z Wordu, rovnice a vložené objekty editor nezobrazí – ak ich dokument má, Cloud ťa upozorní a odporučí ho otvoriť <b>iba na čítanie</b>.
Vyriešené komentáre sa do súboru neukladajú (ostanú v starších verziách). Pri viacerých stĺpcoch editor ukazuje dokument bez zlomov strán, v tlači a vo Worde sú strany správne.
Starý formát .doc treba najprv uložiť ako .docx. Úpravy v rovnakom čase od viacerých ľudí sa nespájajú – ak súbor medzitým zmenil niekto iný, Cloud ťa upozorní.</div>

<?= h2('obrazky', 'bi-magic', 'Úprava fotiek') ?>
<?= fig('u-obrazok', 'Editor obrázkov: orezanie s pomerom strán 16:9.') ?>
<p>Fotky a obrázky (<b>JPG, PNG, WebP</b>, aj GIF a BMP) upravíš priamo v Cloude: v náhľade fotky klikni na <b>Upraviť</b> alebo v ponuke súboru vyber <b>Upraviť</b>.</p>
<ul>
  <li><b>Orezať</b> – potiahni rohy alebo strany rámčeka, celý rámček posunieš ťahaním. Pomer strán: voľne, pôvodný, 1:1 (štvorec), 4:3, 3:4, 16:9, 9:16, 3:2.</li>
  <li><b>Otočiť</b> doľava / doprava o 90°, <b>zrkadliť</b> vodorovne alebo hore-dole a <b>vyrovnať</b> šikmý horizont posuvníkom (±45°).</li>
  <li><b>Úpravy</b> – jas, kontrast, sýtosť a teplota farieb. Dvojklik na posuvník ho vráti na 0.</li>
  <li><b>Filtre</b> – čiernobiely, sépia, teplý, studený, živý, vybledlý, vintage, dráma.</li>
  <li><b>Tvary</b> – vyše 100 hotových tvarov a textové pole (pozri nižšie).</li>
  <li><b>Maska</b> – fotka v tvare kruhu, srdca, hviezdy… s priehľadným, farebným, rozmazaným, čiernobielym alebo stmaveným okolím (pozri nižšie).</li>
  <li><b>Veľkosť</b> – zmenšenie na šírku/výšku v pixeloch, na 75 / 50 / 25 %, na Full HD alebo na web; kvalita JPEG.</li>
</ul>

<h3 id="obrazky-tvary">Tvary a text</h3>
<?= fig('u-obrazok-tvary', 'Bublina s textom a tieňom vložená do fotky.') ?>
<p>Na karte <b>Tvary</b> ťukni na <b>Pridať tvar</b>. Otvorí sa knižnica so <b>140 tvarmi</b> v skupinách
Základné (obdĺžniky, kruhy, mnohouholníky, kríž, prstenec, valec, kocka…), Hviezdy, Šípky, Bubliny, Symboly (srdce, oblak, mesiac, blesk, fajka, smajlík, špendlík…),
Stuhy a rámčeky, Čiary (aj so šípkou, krivka, cikcak, špirála) a Diagramy (vývojové diagramy). Tvar nájdeš aj vyhľadávaním podľa názvu. Tlačidlo <b>Text</b> pridá samostatné textové pole.</p>
<?= fig('u-obrazok-kniznica', 'Knižnica tvarov s vyhľadávaním a skupinami.') ?>
<ul>
  <li><b>Presun, veľkosť, otočenie</b> – tvar ťahaj, rohmi a stranami meníš veľkosť, guľôčkou nad ním otáčaš (pri 0°, 45°, 90°… sa prichytí).
    Na mobile ho <b>dvoma prstami</b> zväčšíš, zmenšíš a otočíš naraz. Na počítači aj kolieskom myši (s <kbd>Alt</kbd> otáča) a šípkami (so <kbd>Shift</kbd> o 10 px).</li>
  <li><b>Výplň</b> – žiadna, farba alebo <b>prechod</b> dvoch farieb s nastaviteľným smerom; krytie výplne.</li>
  <li><b>Obrys</b> – farba, hrúbka, krytie a štýl: plná čiara, čiarky alebo bodky.</li>
  <li><b>Priehľadnosť</b> – celého tvaru a <b>prelínanie</b> s fotkou (násobiť, zosvetliť, prekryť, rozdiel, farba, jas…).</li>
  <li><b>Tieň</b> – farba, krytie, rozmazanie a posun.</li>
  <li><b>Text</b> – do každého tvaru (bubliny, stuhy, diagramy…) môžeš písať; farba, veľkosť, písmo, tučné, kurzíva a zarovnanie. Dvojité ťuknutie na tvar otvorí text.</li>
  <li><b>Tvar</b> – posuvník mení vlastnosť tvaru (zaoblenie rohov, hrúbku šípky, počet cípov hviezdy, chvost bubliny…), presné otočenie, zrkadlenie,
    zámok pomeru strán, <b>Zmeniť tvar</b> (zachová farby aj text) a umiestnenie na stred.</li>
  <li>Pri vybranom tvare hore: <b>Duplikovať</b> (<kbd>Ctrl</kbd>+<kbd>D</kbd>), <b>Dopredu</b>, <b>Dozadu</b> a <b>Zmazať</b> (<kbd>Delete</kbd>).</li>
</ul>

<h3 id="obrazky-maska">Masky</h3>
<?= fig('u-obrazok-maska', 'Maska „Vinetácia“ – stmavené okraje fotky.') ?>
<p>Na karte <b>Maska</b> vyber tvar (kruh, zaoblený obdĺžnik, srdce, hviezda, šesťuholník, oblak… alebo <b>Viac tvarov</b> z celej knižnice).
Fotka ostane viditeľná vo vnútri masky a okolie sa upraví podľa voľby:</p>
<ul>
  <li><b>Priehľadné</b> – vznikne fotka v tvare (obrázok sa uloží ako PNG, pri JPEG ako kópia .png),</li>
  <li><b>Farba</b> – okolie vyplní zvolená farba (silou nastavíš, nakoľko zakryje fotku),</li>
  <li><b>Rozmazané</b>, <b>Čiernobiele</b> alebo <b>Stmavené</b> – okolie sa rozmaže, odfarbí alebo stmaví.</li>
</ul>
<p><b>Mäkký okraj</b> rozmaže prechod, <b>Obrátiť</b> upraví naopak vnútro masky, <b>Aj tvary</b> použije masku aj na vložené tvary a text,
<b>Orezať na masku</b> oreže obrázok na rozmer masky. Masku posúvaš, meníš a otáčaš ťahaním rovnako ako tvary. Hotové voľby: <b>Vinetácia</b> a <b>Rozmazané okolie</b>.</p>
<p>Všetky úpravy vrátiš tlačidlami <b>Späť</b> a <b>Znova</b> hore (<kbd>Ctrl</kbd>+<kbd>Z</kbd>, <kbd>Ctrl</kbd>+<kbd>Y</kbd>). Pri otočení alebo zrkadlení fotky sa tvary a maska otočia s ňou.</p>
<p><b>Uložiť</b> uloží upravený obrázok a pôvodný ostane vo <b>Verziách</b>. Šípka vedľa ponúka <b>Uložiť ako kópiu</b> (vznikne „… (upravené).jpg“) a <b>Stiahnuť upravený</b>. Tlačidlo <b>Pôvodný</b> zruší všetky úpravy. Na počítači: <kbd>Ctrl</kbd>+<kbd>S</kbd> uloží, <kbd>Ctrl</kbd>+<kbd>Z</kbd> vráti krok, <kbd>Esc</kbd> zavrie.</p>
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
Vlastník (ja / iní), Upravené (dnes, 7 dní, 30 dní, rok, vlastný rozsah), Umiestnenie (Môj disk, zdieľané, kôš, s hviezdičkou), Veľkosť, <b>Odfotené</b> (rok, mesiac v ktoromkoľvek roku
alebo <b>„Tento mesiac v minulých rokoch“</b>) a Štítok. Hľadať môžeš aj bez slova – napr. iba typ Obrázky a Odfotené: Rok 2024.
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
    <tr><td><code>odfotene:2024</code>, <code>odfotene:7.2024</code></td><td>fotky a videá odfotené v roku / mesiaci (inak podľa dátumu nahratia)</td></tr>
    <tr><td><code>mesiac:12</code></td><td>odfotené v decembri ktoréhokoľvek roka</td></tr>
    <tr><td><code>-koncept</code></td><td>vylúči slovo</td></tr>
  </tbody>
</table>
<div class="tip">Ak administrátor zapol <b>hľadanie v obsahu</b>, Cloud nájde slová aj vnútri dokumentov (Word, Excel, PowerPoint, OpenDocument, TXT a PDF), nielen v názvoch.
Na hostingu, kde server nemá program na čítanie PDF, text z PDF vytiahne tvoj prehliadač na pozadí, keď máš Cloud otvorený – nové PDF sa tak dajú nájsť o chvíľu po nahratí.</div>

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
<p><b>Ako dlho sa verzie uchovávajú</b> si nastavíš sám v <b>Môj účet → Verzie súborov</b>: bez časového limitu, alebo 1 deň až 10 rokov (aj vlastný počet dní, najmenej 1).
Čas sa počíta od chvíle, keď verzia vznikla – teda keď si súbor zmenil. Staršie verzie sa potom zmažú samé a v okne Verzie pri každej uvidíš, kedy sa zmaže. Pred zmazaním ti príde <b>upozornenie</b> (pri dobe od 7 dní 3 dni vopred, pri 2–6 dňoch deň vopred, pri 1 dni 6 hodín vopred) so zoznamom súborov – kliknutím naň sa otvoria Verzie súboru.</p>
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
<b>Vysypať kôš</b> zmaže všetko naraz. Položky sa z koša samy natrvalo zmažú po nastavenom počte dní (predvolene podľa servera, zvyčajne 30) – 3 dni predtým dostaneš upozornenie.
Vlastnú dobu (1 deň až 10 rokov) si nastavíš v <b>Môj účet → Kôš</b>.</p>
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
<?= fig('u-profil', 'Môj účet – osobné údaje, heslo, zariadenia, odtlačok prsta, zobrazenie, verzie súborov, stĺpce, e-mailový súhrn, sieťový disk.') ?>
<h3>Osobné údaje a profilová fotka</h3>
<p>Zmeníš meno, e-mail (nový e-mail treba potvrdiť) a profilovú fotku. Fotka je na serveri uložená zašifrovaná.</p>
<h3>Heslo a prihlásené zariadenia</h3>
<p>V časti <b>Zmena hesla</b> zadáš súčasné a nové heslo. <b>Prihlásené zariadenia</b> ukazujú, kde všade si prihlásený (prehliadače a aplikácie) –
neznáme zariadenie môžeš odhlásiť. Pri prihlásení z nového zariadenia ti môže prísť upozornenie e-mailom.</p>
<h3>Dvojstupňové overenie (kód z telefónu)</h3>
<?= fig('u-2fa', 'Zapnutie dvojstupňového overenia: QR kód pre aplikáciu v telefóne.') ?>
<p>S dvojstupňovým overením sa pri prihlásení heslom zadáva aj <b>6-miestny kód</b> z aplikácie v telefóne (Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…).
Aj keby niekto zistil tvoje heslo, bez telefónu sa neprihlási.</p>
<ol class="steps">
  <li>V <b>Môj účet → Dvojstupňové overenie</b> klikni na <b>Zapnúť</b>.</li>
  <li>V aplikácii v telefóne pridaj účet a <b>naskenuj QR kód</b> (alebo opíš kľúč pod ním).</li>
  <li>Zadaj 6-miestny kód, ktorý aplikácia ukáže – overenie sa zapne.</li>
  <li><b>Ulož si 10 záložných kódov</b> (vytlač, stiahni alebo daj do správcu hesiel). Každý platí raz a pomôže, keď nemáš pri sebe telefón.</li>
</ol>
<p>Pri prihlásení najprv zadáš e-mail a heslo, potom kód. Aplikácia pre Android sa na kód opýta tiež. Prihlásenie <b>odtlačkom prsta</b> kód nepotrebuje –
je bezpečné samo osebe. Keď ti ostanú posledné 2 záložné kódy, Cloud ťa upozorní; nové vytvoríš tlačidlom <b>Nové záložné kódy</b> (staré prestanú platiť).
Vypnúť overenie sa dá iba so zadaním hesla.</p>
<div class="warn">Stratil si telefón aj záložné kódy? Požiadaj administrátora – môže ti nastaviť nové heslo a dvojstupňové overenie vypnúť.</div>
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
<h3>Zobrazenie – bliknutie pri návrate</h3>
<p>Keď sa vrátiš do nadradenej zložky (šípkou Späť, v telefóne tlačidlom Späť alebo kliknutím v ceste hore), Cloud sa posunie tam, kde si bol, a zložka,
z ktorej prichádzaš, je vždy vidieť a krátko <b>blikne na modro</b>. To isté spraví súbor po zavretí náhľadu či editora. V časti <b>Zobrazenie</b> bliknutie
vypneš alebo zvolíš jeho dĺžku (0,5 s až 8 s, predvolene 2 s) – tlačidlo <b>Vyskúšať</b> ukáže, ako bude vyzerať.</p>
<h3>Verzie súborov a kôš</h3>
<?= fig('u-kos-verzie', 'Ako dlho uchovávať staré verzie a po koľkých dňoch vysypať kôš.') ?>
<p>V časti <b>Kôš</b> nastavíš, po koľkých dňoch sa zmazané položky natrvalo vymažú – podľa servera alebo vlastný počet dní (najmenej 1). V časti <b>Verzie súborov</b> nastavíš, ako dlho sa uchovávajú staré verzie: <b>bez časového limitu</b>, 1 deň až rok, alebo <b>vlastný počet dní</b> (najmenej 1). Čas sa ráta od chvíle, keď verzia vznikla.
Ak skrátiš dobu, Cloud sa najprv opýta, lebo staršie verzie sa zmažú hneď. Pred zmazaním ti príde <b>upozornenie</b> so zoznamom súborov, ktorých verzie sa čoskoro zmažú –
kliknutím naň sa otvoria Verzie prvého z nich. Počet verzií na súbor (predvolene 10) určuje administrátor.</p>
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
<p>Zvonček hore ukazuje nové udalosti: zdieľanie, zapnutie či vypnutie dvojstupňového overenia, nahratie cez verejný odkaz, dochádzajúce miesto, kôš pred vysypaním, staré verzie pred zmazaním, prihlásenie z nového zariadenia.
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
  <li>Ak máš zapnuté <b>dvojstupňové overenie</b>, aplikácia sa opýta na kód z overovacej aplikácie (alebo záložný kód).</li>
</ol>
<p>Aplikácia sa vie <b>sama aktualizovať</b> – keď vyjde nová verzia, ponúkne ju (alebo v menu <b>Aktualizácia aplikácie</b>).</p>
<h3>Automatické nahrávanie fotiek a synchronizácia</h3>
<p>V menu <b>Synchronizácia telefónu</b> ťukni na <b>Pridať zložku</b>, vyber zložku v telefóne (napr. <i>DCIM/Camera</i>) a cieľovú zložku v Cloude.
Nové fotky a súbory sa nahrajú hneď, ako vzniknú. Nastaviť môžeš:</p>
<ul>
  <li><b>Automatická synchronizácia</b> zapnutá / vypnutá,</li>
  <li><b>Nahrávať iba cez Wi-Fi</b> – šetrí mobilné dáta,</li>
  <li><b>Kontrola zmien</b> každých 5 minút až 3 hodiny,</li>
  <li><b>Widget</b> na plochu so stavom synchronizácie,</li>
  <li><b>Po nahratí zmazať z telefónu</b> – nastavuje sa pri každej zložke zvlášť (pri pridaní zložky alebo zaškrtnutím pri zložke). Súbor sa z telefónu zmaže až vtedy,
    keď je v Cloude celý, a iba ak sa odvtedy nezmenil. Pri zapnutí môžeš hneď zmazať aj súbory, ktoré už boli nahraté – uvoľníš tak miesto v telefóne.
    Pri staršie pridanej zložke ťa aplikácia požiada, aby si ju vybral ešte raz (Android tak povolí mazanie).</li>
</ul>
<div class="note">Synchronizácia je jednosmerná (telefón → Cloud): súbory zmazané v telefóne zostanú v Cloude (aj tie, ktoré zmazala voľba „Po nahratí zmazať z telefónu“). Zmenený súbor sa uloží ako nová verzia.</div>
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

<?= h2('vzhlad', 'bi-palette', 'Vzhľad a ovládanie') ?>
<p>Klikni na svoj avatar vpravo hore → <b>Vzhľad a ovládanie</b> (alebo <b>Môj účet → Zobrazenie</b>). Zmeny sa prejavia hneď.</p>
<?= fig('u-vzhlad', 'Okno Vzhľad a ovládanie: téma, farba, hustota, náhľady, animácie a nastavenia pre mobil.') ?>
<table class="table table-sm">
  <thead><tr><th style="width:32%">Nastavenie</th><th>Možnosti</th></tr></thead>
  <tbody>
    <tr><td>Téma</td><td><b>Svetlá</b>, <b>Tmavá</b> alebo <b>Podľa systému</b> (prepína sa sama s telefónom či počítačom). Ikona mesiaca/slnka hore prepína rýchlo medzi svetlou a tmavou.</td></tr>
    <tr><td>Farba zvýraznenia</td><td>Modrá, zelená, fialová, červená, oranžová, tyrkysová alebo ružová – tlačidlá, výber, označené položky a menu.</td></tr>
    <tr><td>Hustota zobrazenia</td><td><b>Kompaktná</b> (viac riadkov na obrazovke), <b>Normálna</b>, <b>Vzdušná</b> (väčšie riadky, ľahšie sa trafí prstom).</td></tr>
    <tr><td>Veľkosť náhľadov</td><td>Malé, stredné alebo veľké dlaždice v mriežke a vo Fotkách.</td></tr>
    <tr><td>Animácie</td><td>Jemné nabehnutie položiek a okien. Vypni, ak chceš všetko okamžite (vypnú sa aj podľa systému, ak máš obmedzené animácie).</td></tr>
    <tr><td>Spodná lišta</td><td>Na mobile Môj disk, Nedávne, Fotky a Zdieľané vždy dole po ruke.</td></tr>
    <tr><td>Potiahnutie riadku</td><td>Na mobile riadok v zozname potiahni doľava = do koša, doprava = hviezdička.</td></tr>
  </tbody>
</table>
<div class="note">Vzhľad, zoznam/mriežka pre každé miesto, zoradenie, šírky stĺpcov aj rozbalené skupiny v menu sa ukladajú <b>do tvojho účtu na serveri</b> –
platia na každom počítači, telefóne aj v aplikácii a v zariadení sa neukladá nič. Tlačidlo <b>Predvolené</b> vráti pôvodný vzhľad.</div>

<h3 id="gesta">Gestá</h3>
<table class="table table-sm">
  <thead><tr><th style="width:40%">Gesto</th><th>Čo robí</th></tr></thead>
  <tbody>
    <tr><td>Podržať prst na položke</td><td>Označí ju (ďalšie označíš ťuknutím)</td></tr>
    <tr><td>Podržať a ťahať prstom</td><td>Označí celý rad položiek; pri okraji obrazovky sa zoznam posúva</td></tr>
    <tr><td>Riadok potiahnuť doľava</td><td>Do koša – v upozornení dole je <b>Späť</b></td></tr>
    <tr><td>Riadok potiahnuť doprava</td><td>Pridá alebo odoberie hviezdičku – tiež sa dá vrátiť</td></tr>
    <tr><td>Od ľavého okraja doprava</td><td>Otvorí menu</td></tr>
    <tr><td>Dva prsty / dvakrát ťuknúť v náhľade fotky</td><td>Priblíži fotku</td></tr>
    <tr><td>Potiahnuť v náhľade do strany</td><td>Ďalší / predchádzajúci súbor (keď fotka nie je priblížená)</td></tr>
    <tr><td>Myšou ťahať na prázdnom mieste</td><td>Výber obdĺžnikom (lasso)</td></tr>
  </tbody>
</table>
<p>Potiahnutie riadku funguje iba v zobrazení <b>zoznam</b> a iba tam, kde na to máš právo (do koša môžeš dať, čo môžeš upravovať; hviezdičku iba na svoje položky).
Zoznam gest nájdeš aj v aplikácii: avatar → <b>Gestá a skratky</b>.</p>

<?= h2('skratky', 'bi-keyboard', 'Klávesové skratky') ?>
<?= fig('u-skratky', 'Zoznam skratiek a gest otvoríš klávesom ?.') ?>
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
    <tr><td><kbd>+</kbd> / <kbd>−</kbd> / <kbd>0</kbd> (v náhľade fotky)</td><td>Priblížiť / oddialiť / celá fotka</td></tr>
  </tbody>
</table>

<?= h2('bezpecnost', 'bi-shield-check', 'Bezpečnosť a súkromie') ?>
<ul>
  <li><b>Šifrovanie</b> – ak ho administrátor zapol, súbory sú na disku servera zašifrované (AES-256); bez kľúča ich nikto neprečíta ani pri krádeži disku.</li>
  <li><b>Žiadne sledovanie</b> – Cloud nepoužíva reklamy, analytiku ani cudzie služby. Okrem tvojho servera komunikuje iba s GitHubom kvôli aktualizáciám.</li>
  <li><b>Nič v zariadení</b> – miniatúry, náhľady, zoznamy ani nastavenia sa neukladajú na disk počítača ani telefónu; aj prerušené nahrávanie si pamätá server.</li>
  <li><b>Silné heslo</b> – používaj dlhé heslo, ktoré nepoužívaš inde, alebo prihlásenie odtlačkom.</li>
  <li><b>Dvojstupňové overenie</b> – zapni si ho v Môj účet: bez kódu z tvojho telefónu sa nikto neprihlási ani so správnym heslom.</li>
  <li><b>Zálohy</b> – administrátor môže zapnúť nočnú šifrovanú zálohu databázy aj súborov na iný počítač, takže dáta prežijú aj pád servera.</li>
  <li><b>Zariadenia</b> – v Môj účet pravidelne skontroluj prihlásené zariadenia a neznáme odhlás.</li>
</ul>

<?= h2('problemy', 'bi-life-preserver', 'Časté otázky a riešenie problémov') ?>
<h3>Súbor sa nenahral / nahrávanie stojí</h3>
<p>Skontroluj pripojenie a voľné miesto (dole v menu). Nahraj súbor znova – pokračuje sa od miesta prerušenia. Ak je súbor väčší než povolený limit, Cloud to napíše.</p>
<h3>Nevidím miniatúry videí</h3>
<p>Ak server nemá program ffmpeg (napr. bežný hosting), náhľad videa vyrobí tvoj prehliadač – stačí mať zložku s videom chvíľu otvorenú, náhľady sa doplnia samé
(potrebuješ právo upravovať). Ak sa video v prehliadači nedá prehrať (nezvyčajný formát), náhľad nevznikne.</p>
<h3>Niečo som omylom zmazal</h3>
<p>Pozri do <b>Koša</b>. Ak tam nie je, skús na nadradenej zložke <b>Vrátiť do stavu k…</b> alebo staršiu <b>verziu</b> súboru.</p>
<h3>Nepríde mi e-mail</h3>
<p>Pozri do priečinka Spam/Nevyžiadaná pošta. Ak nechodí žiadny e-mail, administrátor má skontrolovať nastavenie SMTP.</p>
<h3>Zabudol som heslo</h3>
<p>Na prihlasovacej stránke klikni na <b>Zabudnuté heslo</b>. Ak nemáš prístup k e-mailu, heslo ti nastaví administrátor.</p>
<h3>Aplikácia nesynchronizuje</h3>
<p>V aplikácii otvor <b>Synchronizácia telefónu</b> – pri zložke uvidíš chyby. Skontroluj <b>Beh na pozadí</b>, či nemáš zapnuté „iba cez Wi-Fi“ a či je server dostupný (<b>Server → Otestovať</b>).</p>
