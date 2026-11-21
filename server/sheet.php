<?php
/* Editor tabuliek v štýle Google Tabuliek (Luckysheet). Otvára sa z disku v okne (iframe) alebo samostatne.
   Súbor sa načíta a uloží cez api.php (download / save_binary) – každé uloženie je nová verzia. */
require __DIR__ . '/inc/core.php';
$user = require_login();
$uid = (int)$user['id'];
$err = null; $f = null; $canEdit = false; $extras = null;
try {
    $a = file_access($uid, (int)($_GET['id'] ?? 0));
    $f = $a['row'];
    if (!preg_match('/\.(xlsx|xlsm|xls|ods|csv)$/i', $f['name'])) throw new RuntimeException('Tento súbor nie je tabuľka.');
    $canEdit = PERM_LEVEL[$a['perm']] >= PERM_LEVEL['edit'] && empty($_GET['ro']);
    $extras = sheet_extras_get((int)$f['id'], (string)$f['updated_at']);
} catch (Throwable $e) { $err = $e->getMessage(); }
session_write_close();
$embed = !empty($_GET['embed']);
$title = $f ? $f['name'] : 'Tabuľka';
$V = '?v=' . APP_VERSION;
$LS = url('assets/vendor/luckysheet/');
$cfg = $f ? [
    'id' => (int)$f['id'], 'name' => $f['name'], 'updated' => $f['updated_at'], 'folder' => $f['folder_id'] ? (int)$f['folder_id'] : null,
    'size' => (int)$f['size'], 'canEdit' => $canEdit, 'embed' => $embed, 'extras' => $extras,
    'url' => url('api.php?action=download&id=' . (int)$f['id']), 'drive' => url('index.php') . ($f['folder_id'] ? '#folder/' . (int)$f['folder_id'] : ''),
    'help' => url('help.php#tabulky'),
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
    <div class="gs-title"><span class="gs-name" id="gsName"><?= e($f['name']) ?></span>
      <span class="badge rounded-pill text-bg-warning gs-ro d-none" id="gsRo">Iba na čítanie</span>
      <span class="gs-status" id="gsStatus"></span></div>
    <nav class="gs-menu" id="gsMenu">
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Súbor</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="save"><i class="bi bi-cloud-arrow-up"></i>Uložiť teraz<kbd>Ctrl+S</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Stiahnuť ako</h6></li>
        <li><button class="dropdown-item" data-gs="dl:xlsx"><i class="bi bi-file-earmark-excel"></i>Microsoft Excel (.xlsx)</button></li>
        <li><button class="dropdown-item" data-gs="dl:ods"><i class="bi bi-file-earmark-spreadsheet"></i>OpenDocument (.ods)</button></li>
        <li><button class="dropdown-item" data-gs="dl:csv"><i class="bi bi-filetype-csv"></i>CSV – aktuálny hárok (.csv)</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="close"><i class="bi bi-x-lg"></i>Zavrieť</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Úpravy</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="undo" data-rw><i class="bi bi-arrow-counterclockwise"></i>Späť<kbd>Ctrl+Z</kbd></button></li>
        <li><button class="dropdown-item" data-gs="redo" data-rw><i class="bi bi-arrow-clockwise"></i>Znova<kbd>Ctrl+Y</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="find"><i class="bi bi-search"></i>Nájsť a nahradiť<kbd>Ctrl+H</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="clear" data-rw><i class="bi bi-eraser"></i>Vymazať obsah<kbd>Delete</kbd></button></li>
        <li><button class="dropdown-item" data-gs="delrow" data-rw><i class="bi bi-dash-square"></i>Odstrániť riadky</button></li>
        <li><button class="dropdown-item" data-gs="delcol" data-rw><i class="bi bi-dash-square"></i>Odstrániť stĺpce</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Zobraziť</button><ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Ukotviť</h6></li>
        <li><button class="dropdown-item" data-gs="freeze:row" data-rw><i class="bi bi-layout-text-window"></i>1. riadok</button></li>
        <li><button class="dropdown-item" data-gs="freeze:rows" data-rw><i class="bi bi-layout-text-window-reverse"></i>Riadky po aktuálny</button></li>
        <li><button class="dropdown-item" data-gs="freeze:col" data-rw><i class="bi bi-layout-sidebar"></i>1. stĺpec</button></li>
        <li><button class="dropdown-item" data-gs="freeze:both" data-rw><i class="bi bi-grid-1x2"></i>Riadky a stĺpce po aktuálnu bunku</button></li>
        <li><button class="dropdown-item" data-gs="freeze:off" data-rw><i class="bi bi-x-square"></i>Zrušiť ukotvenie</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="zoom:1.25"><i class="bi bi-zoom-in"></i>Priblížiť (125 %)</button></li>
        <li><button class="dropdown-item" data-gs="zoom:1"><i class="bi bi-aspect-ratio"></i>Normálna veľkosť (100 %)</button></li>
        <li><button class="dropdown-item" data-gs="zoom:0.75"><i class="bi bi-zoom-out"></i>Oddialiť (75 %)</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' data-rw>Vložiť</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="row:above"><i class="bi bi-arrow-bar-up"></i>Riadok nad</button></li>
        <li><button class="dropdown-item" data-gs="row:below"><i class="bi bi-arrow-bar-down"></i>Riadok pod</button></li>
        <li><button class="dropdown-item" data-gs="col:left"><i class="bi bi-arrow-bar-left"></i>Stĺpec vľavo</button></li>
        <li><button class="dropdown-item" data-gs="col:right"><i class="bi bi-arrow-bar-right"></i>Stĺpec vpravo</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="chart"><i class="bi bi-bar-chart-line"></i>Graf</button></li>
        <li><button class="dropdown-item" data-gs="image"><i class="bi bi-image"></i>Obrázok</button></li>
        <li><button class="dropdown-item" data-gs="link"><i class="bi bi-link-45deg"></i>Odkaz<kbd>Ctrl+K</kbd></button></li>
        <li><button class="dropdown-item" data-gs="comment"><i class="bi bi-chat-square-text"></i>Komentár</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="fn:SUM"><i class="bi bi-plus-slash-minus"></i>Súčet (SUM)</button></li>
        <li><button class="dropdown-item" data-gs="fn:AVERAGE"><i class="bi bi-calculator"></i>Priemer (AVERAGE)</button></li>
        <li><button class="dropdown-item" data-gs="fn:more"><i class="bi bi-function"></i>Ďalšie funkcie…</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="dropdown"><i class="bi bi-menu-button-wide"></i>Rozbaľovací zoznam / zaškrtávacie políčko</button></li>
        <li><button class="dropdown-item" data-gs="sheet"><i class="bi bi-file-plus"></i>Nový hárok</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' data-rw>Formát</button><ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Číslo</h6></li>
        <li><button class="dropdown-item" data-gs="fmt:General"><i class="bi bi-123"></i>Automaticky</button></li>
        <li><button class="dropdown-item" data-gs="fmt:#,##0.00"><i class="bi bi-hash"></i>Číslo<span class="ex">1 234,56</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:#,##0.00 €"><i class="bi bi-currency-euro"></i>Mena<span class="ex">1 234,56 €</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:0.00%"><i class="bi bi-percent"></i>Percentá<span class="ex">12,34 %</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:d\.m\.yyyy"><i class="bi bi-calendar3"></i>Dátum<span class="ex">24.12.2026</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:h:mm"><i class="bi bi-clock"></i>Čas<span class="ex">15:30</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:d\.m\.yyyy h:mm"><i class="bi bi-calendar-event"></i>Dátum a čas<span class="ex">24.12.2026 15:30</span></button></li>
        <li><button class="dropdown-item" data-gs="fmt:@"><i class="bi bi-fonts"></i>Obyčajný text</button></li>
        <li><button class="dropdown-item" data-gs="fmt:more"><i class="bi bi-three-dots"></i>Ďalšie formáty…</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="st:bl"><i class="bi bi-type-bold"></i>Tučné<kbd>Ctrl+B</kbd></button></li>
        <li><button class="dropdown-item" data-gs="st:it"><i class="bi bi-type-italic"></i>Kurzíva<kbd>Ctrl+I</kbd></button></li>
        <li><button class="dropdown-item" data-gs="st:un"><i class="bi bi-type-underline"></i>Podčiarknuté<kbd>Ctrl+U</kbd></button></li>
        <li><button class="dropdown-item" data-gs="st:cl"><i class="bi bi-type-strikethrough"></i>Prečiarknuté</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="wrap"><i class="bi bi-text-wrap"></i>Zalomiť text</button></li>
        <li><button class="dropdown-item" data-gs="merge"><i class="bi bi-arrows-angle-expand"></i>Zlúčiť bunky</button></li>
        <li><button class="dropdown-item" data-gs="unmerge"><i class="bi bi-arrows-angle-contract"></i>Zrušiť zlúčenie</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="cf"><i class="bi bi-palette"></i>Podmienené formátovanie…</button></li>
        <li><button class="dropdown-item" data-gs="clearfmt"><i class="bi bi-eraser"></i>Vymazať formátovanie<kbd>Ctrl+\</kbd></button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Údaje</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="sort:asc" data-rw><i class="bi bi-sort-alpha-down"></i>Zoradiť A → Z</button></li>
        <li><button class="dropdown-item" data-gs="sort:desc" data-rw><i class="bi bi-sort-alpha-up-alt"></i>Zoradiť Z → A</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="filter:open"><i class="bi bi-funnel"></i>Vytvoriť filter</button></li>
        <li><button class="dropdown-item" data-gs="filter:close"><i class="bi bi-funnel-fill"></i>Zrušiť filter</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="dropdown" data-rw><i class="bi bi-check2-square"></i>Overenie údajov…</button></li>
        <li><button class="dropdown-item" data-gs="split" data-rw><i class="bi bi-layout-split"></i>Rozdeliť text do stĺpcov…</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Pomoc</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="keys"><i class="bi bi-keyboard"></i>Klávesové skratky</button></li>
        <li><a class="dropdown-item" href="<?= e(url('help.php#tabulky')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i>Príručka: tabuľky</a></li>
      </ul></div>
    </nav>
  </div>
  <button class="btn btn-primary btn-sm rounded-pill px-3 gs-savebtn" data-gs="save" id="gsSave" title="Uložiť teraz (Ctrl+S)"><i class="bi bi-cloud-check"></i><span class="d-none d-md-inline ms-1">Uložiť</span></button>
</header>
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
<script src="<?= e(url('assets/js/lsheet-io.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/lsheet.js') . $V) ?>"></script>
<?php endif; ?>
</body>
</html>
