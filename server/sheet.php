<?php
/* Editor tabuliek v štýle Google Tabuliek (Luckysheet). Otvára sa z disku v okne (iframe) alebo samostatne.
   Súbor sa načíta a uloží cez api.php (download / save_binary) – každé uloženie je nová verzia. */
require __DIR__ . '/inc/core.php';
$user = require_login();
$uid = (int)$user['id'];
$err = null; $f = null; $canEdit = false; $extras = null; $own = false;
try {
    $a = file_access($uid, (int)($_GET['id'] ?? 0));
    $f = $a['row'];
    if (!preg_match('/\.(xlsx|xlsm|xls|ods|csv)$/i', $f['name'])) throw new RuntimeException('Tento súbor nie je tabuľka.');
    $canEdit = PERM_LEVEL[$a['perm']] >= PERM_LEVEL['edit'] && empty($_GET['ro']);
    $own = $a['perm'] === 'owner';
    $extras = sheet_extras_get((int)$f['id'], (string)$f['updated_at']);
} catch (Throwable $e) { $err = $e->getMessage(); }
session_write_close();
$embed = !empty($_GET['embed']);
$title = $f ? $f['name'] : 'Tabuľka';
$V = '?v=' . APP_VERSION;
$LS = url('assets/vendor/luckysheet/');
require __DIR__ . '/inc/views/gm.php';
$cfg = $f ? [
    'id' => (int)$f['id'], 'name' => $f['name'], 'updated' => $f['updated_at'], 'folder' => $f['folder_id'] ? (int)$f['folder_id'] : null,
    'size' => (int)$f['size'], 'canEdit' => $canEdit, 'embed' => $embed, 'extras' => $extras,
    'url' => url('api.php?action=download&id=' . (int)$f['id']), 'drive' => url('index.php') . ($f['folder_id'] ? '#folder/' . (int)$f['folder_id'] : ''),
    'help' => url('help.php#tabulky'), 'own' => $own, 'starred' => !empty($f['starred']), 'user' => (string)($user['name'] ?? ''),
] : null;
?><!doctype html>
<html lang="sk" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1a73e8">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(url()) ?>">
<title><?= e($title . ' · ' . cfg('app_name')) ?></title>
<link rel="icon" href="<?= e(url('assets/icons/icon-192.png')) ?>">
<link href="<?= e(lib('bootstrap/bootstrap.min.css', '')) ?>" rel="stylesheet">
<link href="<?= e(lib('bootstrap-icons/bootstrap-icons.min.css', '')) ?>" rel="stylesheet">
<link href="<?= e($LS . 'plugins/css/pluginsCss.css' . $V) ?>" rel="stylesheet">
<link href="<?= e($LS . 'plugins/plugins.css' . $V) ?>" rel="stylesheet">
<link href="<?= e($LS . 'css/luckysheet.css' . $V) ?>" rel="stylesheet">
<link href="<?= e($LS . 'assets/iconfont/iconfont.css' . $V) ?>" rel="stylesheet">
<link href="<?= e(url('assets/css/sheet.css') . $V) ?>" rel="stylesheet">
</head>
<body class="gs<?= $embed ? ' embed' : '' ?>">
<?php if ($err): ?>
<div class="gs-err"><i class="bi bi-exclamation-triangle"></i><div><?= e($err) ?></div>
  <a class="btn btn-primary rounded-pill px-4 mt-3" href="<?= e(url('index.php')) ?>" data-gs="close">Späť na disk</a></div>
<script>
  const gsBack = () => parent.postMessage({ cloudSheet: 'close' }, location.origin);
  document.querySelector('[data-gs="close"]').onclick = (e) => { if (parent !== window) { e.preventDefault(); gsBack(); } };
  addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.cloudSheetCmd === 'close') gsBack(); });
</script>
<?php else: ?>
<header class="gs-head">
  <button class="gs-ib" data-gs="close" title="Späť na disk"><i class="bi bi-arrow-left"></i></button>
  <i class="bi bi-file-earmark-spreadsheet-fill gs-logo"></i>
  <div class="gs-tw">
    <div class="gs-title"><span class="gs-name" id="gsName" title="Premenovať" data-gs="rename" data-rw><?= e($f['name']) ?></span>
      <?php if ($own): ?><button class="gs-ib gs-star<?= !empty($f['starred']) ? ' on' : '' ?>" id="gsStar" data-gs="star" title="Pridať hviezdičku"><i class="bi bi-star<?= !empty($f['starred']) ? '-fill' : '' ?>"></i></button><?php endif; ?>
      <span class="badge rounded-pill text-bg-warning gs-ro d-none" id="gsRo">Iba na čítanie</span>
      <span class="gs-status" id="gsStatus"></span></div>
    <nav class="gs-menu" id="gsMenu">
      <div class="gm"><button class="gs-mb" data-gm>Súbor</button><ul class="gm-menu">
        <?= sub('file-earmark-plus', 'Nový', mi('newsheet', 'file-earmark-spreadsheet', 'Tabuľka') . mi('newdoc', 'file-earmark-text', 'Dokument'), ' data-rw') ?>
        <?= sub('box-arrow-in-down', 'Importovať', mi('import:new', 'plus-square', 'Do nového hárka…') . mi('import:replace', 'arrow-repeat', 'Nahradiť aktuálny hárok…') . mi('import:append', 'arrow-bar-down', 'Pridať riadky do aktuálneho hárka…'), ' data-rw') ?>
        <?= mi('copy', 'files', 'Vytvoriť kópiu') ?>
        <?= SEP ?>
        <?= mi('share', 'person-plus', 'Zdieľať') ?>
        <?= sub('download', 'Stiahnuť',
            mi('dl:xlsx', 'file-earmark-excel', 'Microsoft Excel (.xlsx)') . mi('dl:ods', 'file-earmark-spreadsheet', 'OpenDocument (.ods)') . mi('dl:pdf', 'file-earmark-pdf', 'Dokument PDF (.pdf)') .
            mi('dl:html', 'filetype-html', 'Webová stránka (.html)') . mi('dl:csv', 'filetype-csv', 'Hodnoty oddelené čiarkou (.csv, aktuálny hárok)') . mi('dl:tsv', 'filetype-tsx', 'Hodnoty oddelené tabulátorom (.tsv, aktuálny hárok)')) ?>
        <?= SEP ?>
        <?= mi('rename', 'pencil', 'Premenovať', '', ' data-rw') ?>
        <?= mi('move', 'folder-symlink', 'Presunúť', '', ' data-rw') ?>
        <?= mi('trash', 'trash3', 'Presunúť do koša', '', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('versions', 'clock-history', 'História verzií') ?>
        <?= mi('activity', 'activity', 'Aktivita') ?>
        <?= SEP ?>
        <?= mi('info', 'info-circle', 'Podrobnosti') ?>
        <?= mi('print', 'printer', 'Tlačiť', 'Ctrl+P') ?>
        <?= SEP ?>
        <?= mi('save', 'cloud-arrow-up', 'Uložiť teraz', 'Ctrl+S', ' data-rw') ?>
        <?= mi('close', 'x-lg', 'Zavrieť') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Úpravy</button><ul class="gm-menu">
        <?= mi('undo', 'arrow-counterclockwise', 'Späť', 'Ctrl+Z', ' data-rw') ?>
        <?= mi('redo', 'arrow-clockwise', 'Znova', 'Ctrl+Y', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('cut', 'scissors', 'Vystrihnúť', 'Ctrl+X', ' data-rw') ?>
        <?= mi('copycells', 'copy', 'Kopírovať', 'Ctrl+C') ?>
        <?= mi('paste', 'clipboard', 'Prilepiť', 'Ctrl+V', ' data-rw') ?>
        <?= sub('clipboard-check', 'Prilepiť špeciálne', mi('ps:values', '123', 'Iba hodnoty', 'Ctrl+Shift+V') . mi('ps:format', 'palette', 'Iba formát') . mi('ps:formula', 'function', 'Iba vzorce') . mi('ps:noborder', 'border', 'Všetko okrem okrajov') . mi('ps:transpose', 'arrow-left-right', 'Transponované'), ' data-rw') ?>
        <?= SEP ?>
        <?= sub('arrows-move', 'Presunúť', mi('mv:up', 'arrow-up', 'Riadok hore') . mi('mv:down', 'arrow-down', 'Riadok dole') . mi('mv:left', 'arrow-left', 'Stĺpec vľavo') . mi('mv:right', 'arrow-right', 'Stĺpec vpravo'), ' data-rw') ?>
        <?= sub('backspace', 'Odstrániť', mi('clear', 'eraser', 'Hodnoty', 'Delete') . mi('delrow', 'dash-square', 'Riadky') . mi('delcol', 'dash-square', 'Stĺpce') . mi('delcells:up', 'arrow-bar-up', 'Bunky a posunúť nahor') . mi('delcells:left', 'arrow-bar-left', 'Bunky a posunúť doľava'), ' data-rw') ?>
        <?= SEP ?>
        <?= mi('find', 'search', 'Nájsť a nahradiť', 'Ctrl+H') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Zobraziť</button><ul class="gm-menu">
        <?= sub('eye', 'Zobraziť', mi('grid', 'grid-3x3', 'Mriežka', '', ' data-chk="grid"') . mi('fbar', 'input-cursor-text', 'Riadok vzorcov', '', ' data-chk="fbar"') . mi('formulas', 'function', 'Vzorce', 'Ctrl+`', ' data-chk="formulas"')) ?>
        <?= sub('layout-text-window', 'Ukotviť',
            mi('freeze:r0', 'x-square', 'Bez riadkov') . mi('freeze:r1', 'layout-text-window', '1 riadok') . mi('freeze:r2', 'layout-text-window', '2 riadky') . mi('freeze:rows', 'layout-text-window-reverse', 'Po aktuálny riadok') . SEP .
            mi('freeze:c0', 'x-square', 'Bez stĺpcov') . mi('freeze:c1', 'layout-sidebar', '1 stĺpec') . mi('freeze:c2', 'layout-sidebar', '2 stĺpce') . mi('freeze:cols', 'layout-sidebar-inset', 'Po aktuálny stĺpec'), ' data-rw') ?>
        <?= sub('eye-slash', 'Skryť a zobraziť', mi('hide:rows', 'eye-slash', 'Skryť označené riadky') . mi('show:rows', 'eye', 'Zobraziť skryté riadky v oblasti') . mi('hide:cols', 'eye-slash', 'Skryť označené stĺpce') . mi('show:cols', 'eye', 'Zobraziť skryté stĺpce v oblasti') . SEP . mi('hidesheet', 'file-earmark-x', 'Skryť hárok') . mi('showsheets', 'files', 'Zobraziť skryté hárky…'), ' data-rw') ?>
        <?= sub('zoom-in', 'Priblíženie', implode('', array_map(fn($z) => mi('zoom:' . ($z / 100), 'zoom-in', $z . ' %', '', ' data-chk="zoom:' . ($z / 100) . '"'), [50, 75, 90, 100, 125, 150, 200]))) ?>
        <?= SEP ?>
        <?= mi('fullscreen', 'fullscreen', 'Celá obrazovka') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm data-rw>Vložiť</button><ul class="gm-menu">
        <?= sub('grid-3x2-gap', 'Bunky', mi('inscells:right', 'arrow-bar-right', 'Vložiť bunky a posunúť doprava') . mi('inscells:down', 'arrow-bar-down', 'Vložiť bunky a posunúť nadol')) ?>
        <?= sub('distribute-vertical', 'Riadky', mi('row:above', 'arrow-bar-up', 'Vložiť riadok nad') . mi('row:below', 'arrow-bar-down', 'Vložiť riadok pod')) ?>
        <?= sub('distribute-horizontal', 'Stĺpce', mi('col:left', 'arrow-bar-left', 'Vložiť stĺpec vľavo') . mi('col:right', 'arrow-bar-right', 'Vložiť stĺpec vpravo')) ?>
        <?= mi('sheet', 'file-plus', 'Hárok', 'Shift+F11') ?>
        <?= SEP ?>
        <?= mi('chart', 'bar-chart-line', 'Graf') ?>
        <?= mi('pivot', 'table', 'Kontingenčná tabuľka') ?>
        <?= mi('image', 'image', 'Obrázok') ?>
        <?= SEP ?>
        <?= sub('function', 'Funkcia', mi('fn:SUM', 'plus-slash-minus', 'SUM – súčet') . mi('fn:AVERAGE', 'calculator', 'AVERAGE – priemer') . mi('fn:COUNT', 'hash', 'COUNT – počet čísel') . mi('fn:MAX', 'arrow-up', 'MAX – maximum') . mi('fn:MIN', 'arrow-down', 'MIN – minimum') . SEP . mi('fn:more', 'list', 'Všetky funkcie…')) ?>
        <?= mi('link', 'link-45deg', 'Odkaz', 'Ctrl+K') ?>
        <?= SEP ?>
        <?= mi('checkbox', 'check-square', 'Zaškrtávacie políčko') ?>
        <?= mi('dropdown', 'menu-button-wide', 'Rozbaľovací zoznam') ?>
        <?= SEP ?>
        <?= mi('comment', 'chat-square-text', 'Komentár / poznámka', 'Shift+F2') ?>
        <?= mi('date', 'calendar3', 'Dnešný dátum', 'Ctrl+;') ?>
        <?= mi('time', 'clock', 'Aktuálny čas', 'Ctrl+Shift+;') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm data-rw>Formát</button><ul class="gm-menu">
        <?= sub('123', 'Číslo',
            mi('fmt:General', '123', 'Automaticky') . mi('fmt:@', 'fonts', 'Obyčajný text') . SEP .
            mi('fmt:#,##0.00', 'hash', 'Číslo<span class="ex">1 234,56</span>') . mi('fmt:0.00%', 'percent', 'Percentá<span class="ex">12,34 %</span>') . mi('fmt:0.00E+00', 'superscript', 'Vedecký zápis<span class="ex">1,01E+03</span>') . SEP .
            mi('fmt:#,##0.00 €', 'currency-euro', 'Mena<span class="ex">1 234,56 €</span>') . mi('fmt:#,##0 €', 'currency-euro', 'Mena zaokrúhlená<span class="ex">1 235 €</span>') . mi('fmt:#,##0.00 €;[Red]-#,##0.00 €', 'currency-euro', 'Účtovný<span class="ex">-1 234,56 €</span>') . SEP .
            mi('fmt:d\.m\.yyyy', 'calendar3', 'Dátum<span class="ex">24.12.2026</span>') . mi('fmt:h:mm', 'clock', 'Čas<span class="ex">15:30</span>') . mi('fmt:d\.m\.yyyy h:mm', 'calendar-event', 'Dátum a čas<span class="ex">24.12.2026 15:30</span>') . mi('fmt:[h]:mm:ss', 'stopwatch', 'Trvanie<span class="ex">26:15:00</span>') . SEP .
            mi('fmt:more', 'three-dots', 'Vlastný formát čísla…')) ?>
        <?= sub('type', 'Text', mi('st:bl', 'type-bold', 'Tučné', 'Ctrl+B') . mi('st:it', 'type-italic', 'Kurzíva', 'Ctrl+I') . mi('st:un', 'type-underline', 'Podčiarknuté', 'Ctrl+U') . mi('st:cl', 'type-strikethrough', 'Prečiarknuté', 'Alt+Shift+5')) ?>
        <?= sub('text-left', 'Zarovnanie', mi('ht:1', 'text-left', 'Vľavo', 'Ctrl+Shift+L') . mi('ht:0', 'text-center', 'Na stred', 'Ctrl+Shift+E') . mi('ht:2', 'text-right', 'Vpravo', 'Ctrl+Shift+R') . SEP . mi('vt:1', 'align-top', 'Hore') . mi('vt:0', 'align-middle', 'Na stred') . mi('vt:2', 'align-bottom', 'Dole')) ?>
        <?= sub('text-wrap', 'Zalamovanie', mi('tb:1', 'arrow-bar-right', 'Pretečenie') . mi('tb:2', 'text-wrap', 'Zalomiť') . mi('tb:0', 'scissors', 'Orezať')) ?>
        <?= sub('arrow-clockwise', 'Otočenie', mi('tr:0', 'fonts', 'Žiadne') . mi('tr:1', 'arrow-up-right', 'Nakloniť nahor') . mi('tr:2', 'arrow-down-right', 'Nakloniť nadol') . mi('tr:3', 'arrow-down', 'Zvislo') . mi('tr:4', 'arrow-90deg-up', 'Otočiť nahor') . mi('tr:5', 'arrow-90deg-down', 'Otočiť nadol')) ?>
        <?= sub('fonts', 'Veľkosť písma', implode('', array_map(fn($z) => mi('fs:' . $z, 'fonts', (string)$z), [6, 7, 8, 9, 10, 11, 12, 14, 18, 24, 36]))) ?>
        <?= sub('arrows-angle-expand', 'Zlúčiť bunky', mi('merge:all', 'arrows-angle-expand', 'Zlúčiť všetko') . mi('merge:horizontal', 'arrows-expand-vertical', 'Zlúčiť vodorovne') . mi('merge:vertical', 'arrows-expand', 'Zlúčiť zvislo') . mi('unmerge', 'arrows-angle-contract', 'Zrušiť zlúčenie')) ?>
        <?= SEP ?>
        <?= mi('cf', 'palette', 'Podmienené formátovanie') ?>
        <?= mi('alt', 'distribute-vertical', 'Striedavé farby') ?>
        <?= SEP ?>
        <?= mi('clearfmt', 'eraser', 'Vymazať formátovanie', 'Ctrl+\\') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Údaje</button><ul class="gm-menu">
        <?= mi('sortsheet:asc', 'sort-alpha-down', 'Zoradiť hárok podľa stĺpca A → Z', '', ' data-rw') ?>
        <?= mi('sortsheet:desc', 'sort-alpha-up-alt', 'Zoradiť hárok podľa stĺpca Z → A', '', ' data-rw') ?>
        <?= sub('sort-down', 'Zoradiť rozsah', mi('sort:asc', 'sort-alpha-down', 'Zoradiť rozsah A → Z') . mi('sort:desc', 'sort-alpha-up-alt', 'Zoradiť rozsah Z → A') . SEP . mi('sort:adv', 'sliders', 'Rozšírené možnosti zoradenia…'), ' data-rw') ?>
        <?= SEP ?>
        <?= mi('filter:open', 'funnel', 'Vytvoriť filter') ?>
        <?= mi('filter:close', 'funnel-fill', 'Odstrániť filter') ?>
        <?= SEP ?>
        <?= mi('protect', 'shield-lock', 'Chrániť hárky a rozsahy', '', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('shuffle', 'shuffle', 'Náhodné poradie rozsahu', '', ' data-rw') ?>
        <?= mi('stats', 'bar-chart', 'Štatistiky stĺpca') ?>
        <?= mi('dropdown', 'check2-square', 'Overenie údajov', '', ' data-rw') ?>
        <?= sub('magic', 'Vyčistenie údajov', mi('dedupe', 'files', 'Odstrániť duplikáty…') . mi('trim', 'scissors', 'Orezať medzery'), ' data-rw') ?>
        <?= mi('split', 'layout-split', 'Rozdeliť text do stĺpcov…', '', ' data-rw') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Nástroje</button><ul class="gm-menu">
        <?= mi('recalc', 'arrow-repeat', 'Prepočítať vzorce') ?>
        <?= mi('cf', 'palette', 'Podmienené formátovanie', '', ' data-rw') ?>
        <?= mi('protect', 'shield-lock', 'Chrániť hárok', '', ' data-rw') ?>
        <?= mi('count', '123', 'Počet buniek a súčet výberu') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Pomoc</button><ul class="gm-menu">
        <?= mi('menusearch', 'search', 'Hľadať v ponukách', 'Alt+/') ?>
        <?= mi('keys', 'keyboard', 'Klávesové skratky', 'Ctrl+/') ?>
        <?= mi('fn:more', 'function', 'Zoznam funkcií') ?>
        <li><a class="gm-i" href="<?= e(url('help.php#tabulky')) ?>" target="_blank" rel="noopener" data-close><i class="bi bi-book"></i><span>Príručka: tabuľky</span></a></li>
      </ul></div>
    </nav>
  </div>
  <button class="gs-ib d-none d-sm-grid" data-gs="versions" title="História verzií"><i class="bi bi-clock-history"></i></button>
  <button class="btn btn-sm rounded-pill px-3 gs-share d-none d-sm-inline-flex" data-gs="share"><i class="bi bi-lock"></i><span class="ms-1">Zdieľať</span></button>
  <button class="btn btn-primary btn-sm rounded-pill px-3 gs-savebtn" data-gs="save" id="gsSave" title="Uložiť teraz (Ctrl+S)"><i class="bi bi-cloud-check"></i><span class="d-none d-md-inline ms-1">Uložiť</span></button>
</header>
<input type="file" id="gsImport" accept=".xlsx,.xls,.ods,.csv,.tsv,.txt" class="d-none">
<main class="gs-main"><div id="gsSheet"></div><div class="gs-load" id="gsLoad"><div class="spinner-border text-success mb-2"></div><div>Otváram tabuľku…</div></div></main>

<div class="modal fade" id="gsKeys" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-keyboard me-2"></i>Klávesové skratky</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm gs-keys"><tbody>
    <tr><td>Ctrl+S</td><td>Uložiť hneď (inak sa ukladá samo)</td></tr>
    <tr><td>Ctrl+Z / Ctrl+Y</td><td>Späť / Znova</td></tr>
    <tr><td>Ctrl+C / Ctrl+X / Ctrl+V</td><td>Kopírovať / vystrihnúť / prilepiť</td></tr>
    <tr><td>Ctrl+B / Ctrl+I / Ctrl+U</td><td>Tučné / kurzíva / podčiarknuté</td></tr>
    <tr><td>Ctrl+F, Ctrl+H</td><td>Nájsť, nájsť a nahradiť</td></tr>
    <tr><td>Ctrl+K</td><td>Vložiť odkaz</td></tr>
    <tr><td>Ctrl+\</td><td>Vymazať formátovanie</td></tr>
    <tr><td>Enter / Tab</td><td>Potvrdiť a ísť nižšie / vpravo</td></tr>
    <tr><td>F2</td><td>Upraviť bunku</td></tr>
    <tr><td>Delete</td><td>Vymazať obsah buniek</td></tr>
    <tr><td>Ctrl+Shift+V</td><td>Prilepiť iba hodnoty</td></tr>
    <tr><td>Ctrl+; / Ctrl+Shift+;</td><td>Dnešný dátum / aktuálny čas</td></tr>
    <tr><td>Ctrl+P</td><td>Tlačiť</td></tr>
    <tr><td>Alt+/</td><td>Hľadať v ponukách</td></tr>
    <tr><td>Ctrl+Shift+L / E / R</td><td>Zarovnať vľavo / na stred / vpravo</td></tr>
    <tr><td>Ctrl+`</td><td>Zobraziť vzorce</td></tr>
    <tr><td>Shift+F11</td><td>Nový hárok</td></tr>
    <tr><td>=</td><td>Začať vzorec, napr. <code>=SUM(A1:A10)</code> – argumenty oddeľ <b>;</b> alebo <b>,</b></td></tr>
  </tbody></table>
  <div class="small text-body-secondary">Čísla píš s desatinnou čiarkou (<code>3,5</code>), dátumy ako <code>24.12.2026</code>, percentá <code>15 %</code>, sumy <code>120 €</code>.</div></div>
</div></div></div>

<script>window.CLOUD_SHEET = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e($LS . 'plugins/js/plugin.js' . $V) ?>"></script>
<script src="<?= e($LS . 'luckysheet.umd.js' . $V) ?>"></script>
<script src="<?= e(lib('bootstrap/bootstrap.bundle.min.js', '')) ?>"></script>
<script src="<?= e(lib('exceljs/exceljs.min.js', '')) ?>"></script>
<script src="<?= e(lib('xlsx/xlsx.full.min.js', '')) ?>"></script>
<script src="<?= e(url('assets/js/gmenu.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/lsheet-io.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/lsheet.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/lsheet-print.js') . $V) ?>"></script>
<?php endif; ?>
</body>
</html>
