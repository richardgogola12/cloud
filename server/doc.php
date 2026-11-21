<?php
/* Editor dokumentov Word (.docx) v štýle Google Dokumentov. Otvára sa z disku v okne (iframe) alebo samostatne.
   Súbor sa načíta a uloží cez api.php (download / save_binary) – každé uloženie je nová verzia. */
require __DIR__ . '/inc/core.php';
$user = require_login();
$uid = (int)$user['id'];
$err = null; $f = null; $canEdit = false; $extras = null;
try {
    $a = file_access($uid, (int)($_GET['id'] ?? 0));
    $f = $a['row'];
    if (!preg_match('/\.docx$/i', $f['name'])) throw new RuntimeException('Tento súbor nie je dokument Word (.docx).');
    $canEdit = PERM_LEVEL[$a['perm']] >= PERM_LEVEL['edit'] && empty($_GET['ro']);
} catch (Throwable $e) { $err = $e->getMessage(); }
session_write_close();
$embed = !empty($_GET['embed']);
$title = $f ? $f['name'] : 'Dokument';
$V = '?v=' . APP_VERSION;
$cfg = $f ? [
    'id' => (int)$f['id'], 'name' => $f['name'], 'updated' => $f['updated_at'], 'folder' => $f['folder_id'] ? (int)$f['folder_id'] : null,
    'size' => (int)$f['size'], 'canEdit' => $canEdit, 'embed' => $embed,
    'url' => url('api.php?action=download&id=' . (int)$f['id']), 'drive' => url('index.php') . ($f['folder_id'] ? '#folder/' . (int)$f['folder_id'] : ''),
    'help' => url('help.php#dokumenty'),
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
<link href="<?= e(url('assets/css/sheet.css') . $V) ?>" rel="stylesheet">
<link href="<?= e(url('assets/css/doc.css') . $V) ?>" rel="stylesheet">
</head>
<body class="gs gd<?= $embed ? ' embed' : '' ?>">
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
  <i class="bi bi-file-earmark-text-fill gs-logo gd-logo"></i>
  <div class="gs-tw">
    <div class="gs-title"><span class="gs-name" id="gsName"><?= e($f['name']) ?></span>
      <span class="badge rounded-pill text-bg-warning gs-ro d-none" id="gsRo">Iba na čítanie</span>
      <span class="gs-status" id="gsStatus"></span></div>
    <nav class="gs-menu" id="gsMenu">
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Súbor</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="save"><i class="bi bi-cloud-arrow-up"></i>Uložiť teraz<kbd>Ctrl+S</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Stiahnuť ako</h6></li>
        <li><button class="dropdown-item" data-gs="dl:docx"><i class="bi bi-file-earmark-word"></i>Microsoft Word (.docx)</button></li>
        <li><button class="dropdown-item" data-gs="dl:html"><i class="bi bi-filetype-html"></i>Webová stránka (.html)</button></li>
        <li><button class="dropdown-item" data-gs="dl:txt"><i class="bi bi-filetype-txt"></i>Obyčajný text (.txt)</button></li>
        <li><button class="dropdown-item" data-gs="print"><i class="bi bi-printer"></i>Tlačiť / uložiť ako PDF<kbd>Ctrl+P</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="landscape" data-rw><i class="bi bi-phone-landscape"></i>Orientácia na šírku / na výšku</button></li>
        <li><button class="dropdown-item" data-gs="close"><i class="bi bi-x-lg"></i>Zavrieť</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Úpravy</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="undo" data-rw><i class="bi bi-arrow-counterclockwise"></i>Späť<kbd>Ctrl+Z</kbd></button></li>
        <li><button class="dropdown-item" data-gs="redo" data-rw><i class="bi bi-arrow-clockwise"></i>Znova<kbd>Ctrl+Y</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="selectall"><i class="bi bi-textarea-t"></i>Vybrať všetko<kbd>Ctrl+A</kbd></button></li>
        <li><button class="dropdown-item" data-gs="find"><i class="bi bi-search"></i>Nájsť a nahradiť<kbd>Ctrl+H</kbd></button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' data-rw>Vložiť</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="image"><i class="bi bi-image"></i>Obrázok</button></li>
        <li><button class="dropdown-item" data-gs="table"><i class="bi bi-table"></i>Tabuľka 3 × 3</button></li>
        <li><button class="dropdown-item" data-gs="link"><i class="bi bi-link-45deg"></i>Odkaz<kbd>Ctrl+K</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="hr"><i class="bi bi-file-break"></i>Zlom strany<kbd>Ctrl+Enter</kbd></button></li>
        <li><button class="dropdown-item" data-gs="date"><i class="bi bi-calendar3"></i>Dnešný dátum</button></li>
        <li><button class="dropdown-item" data-gs="char:—"><i class="bi bi-dash-lg"></i>Dlhá pomlčka —</button></li>
        <li><button class="dropdown-item" data-gs="char:„“"><i class="bi bi-quote"></i>Slovenské úvodzovky „“</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' data-rw>Formát</button><ul class="dropdown-menu">
        <li><h6 class="dropdown-header">Text</h6></li>
        <li><button class="dropdown-item" data-gs="cmd:bold"><i class="bi bi-type-bold"></i>Tučné<kbd>Ctrl+B</kbd></button></li>
        <li><button class="dropdown-item" data-gs="cmd:italic"><i class="bi bi-type-italic"></i>Kurzíva<kbd>Ctrl+I</kbd></button></li>
        <li><button class="dropdown-item" data-gs="cmd:underline"><i class="bi bi-type-underline"></i>Podčiarknuté<kbd>Ctrl+U</kbd></button></li>
        <li><button class="dropdown-item" data-gs="cmd:strikeThrough"><i class="bi bi-type-strikethrough"></i>Prečiarknuté</button></li>
        <li><button class="dropdown-item" data-gs="cmd:superscript"><i class="bi bi-superscript"></i>Horný index</button></li>
        <li><button class="dropdown-item" data-gs="cmd:subscript"><i class="bi bi-subscript"></i>Dolný index</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><h6 class="dropdown-header">Štýly odsekov</h6></li>
        <li><button class="dropdown-item" data-gs="block:p"><i class="bi bi-paragraph"></i>Normálny text<kbd>Ctrl+Alt+0</kbd></button></li>
        <li><button class="dropdown-item" data-gs="block:h1"><i class="bi bi-type-h1"></i>Nadpis 1<kbd>Ctrl+Alt+1</kbd></button></li>
        <li><button class="dropdown-item" data-gs="block:h2"><i class="bi bi-type-h2"></i>Nadpis 2<kbd>Ctrl+Alt+2</kbd></button></li>
        <li><button class="dropdown-item" data-gs="block:h3"><i class="bi bi-type-h3"></i>Nadpis 3<kbd>Ctrl+Alt+3</kbd></button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="cmd:justifyLeft"><i class="bi bi-text-left"></i>Zarovnať doľava</button></li>
        <li><button class="dropdown-item" data-gs="cmd:justifyCenter"><i class="bi bi-text-center"></i>Na stred</button></li>
        <li><button class="dropdown-item" data-gs="cmd:justifyRight"><i class="bi bi-text-right"></i>Doprava</button></li>
        <li><button class="dropdown-item" data-gs="cmd:justifyFull"><i class="bi bi-justify"></i>Do bloku</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="cmd:insertUnorderedList"><i class="bi bi-list-ul"></i>Zoznam s odrážkami</button></li>
        <li><button class="dropdown-item" data-gs="cmd:insertOrderedList"><i class="bi bi-list-ol"></i>Číslovaný zoznam</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item" data-gs="clearfmt"><i class="bi bi-eraser"></i>Vymazať formátovanie<kbd>Ctrl+\</kbd></button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Nástroje</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="count"><i class="bi bi-123"></i>Počet slov</button></li>
        <li><button class="dropdown-item" data-gs="outline"><i class="bi bi-list-nested"></i>Prehľad dokumentu (nadpisy)</button></li>
      </ul></div>
      <div class="dropdown"><button class="gs-mb" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'>Pomoc</button><ul class="dropdown-menu">
        <li><button class="dropdown-item" data-gs="keys"><i class="bi bi-keyboard"></i>Klávesové skratky</button></li>
        <li><a class="dropdown-item" href="<?= e(url('help.php#dokumenty')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i>Príručka: dokumenty</a></li>
      </ul></div>
    </nav>
  </div>
  <button class="btn btn-primary btn-sm rounded-pill px-3 gs-savebtn" data-gs="save" id="gsSave" title="Uložiť teraz (Ctrl+S)"><i class="bi bi-cloud-check"></i><span class="d-none d-md-inline ms-1">Uložiť</span></button>
</header>
<div class="gd-tools" id="gdTools">
  <button class="gd-b" data-gs="undo" title="Späť (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
  <button class="gd-b" data-gs="redo" title="Znova (Ctrl+Y)"><i class="bi bi-arrow-clockwise"></i></button>
  <button class="gd-b" data-gs="print" title="Tlačiť (Ctrl+P)"><i class="bi bi-printer"></i></button>
  <select class="gd-sel" id="gdZoom" title="Priblíženie"><option value="0.75">75 %</option><option value="0.9">90 %</option><option value="1" selected>100 %</option><option value="1.25">125 %</option><option value="1.5">150 %</option></select>
  <span class="gd-sep"></span>
  <select class="gd-sel" id="gdBlock" title="Štýl odseku"><option value="p">Normálny text</option><option value="h1">Nadpis 1</option><option value="h2">Nadpis 2</option><option value="h3">Nadpis 3</option><option value="h4">Nadpis 4</option></select>
  <span class="gd-sep"></span>
  <select class="gd-sel" id="gdFont" title="Písmo"><option>Calibri</option><option>Arial</option><option>Times New Roman</option><option>Georgia</option><option>Verdana</option><option>Courier New</option></select>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="size:-1" title="Zmenšiť písmo"><i class="bi bi-dash"></i></button><input class="gd-size" id="gdSize" value="11" inputmode="numeric" title="Veľkosť písma"><button class="gd-b" data-gs="size:1" title="Zväčšiť písmo"><i class="bi bi-plus"></i></button>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="cmd:bold" data-st="bold" title="Tučné (Ctrl+B)"><i class="bi bi-type-bold"></i></button>
  <button class="gd-b" data-gs="cmd:italic" data-st="italic" title="Kurzíva (Ctrl+I)"><i class="bi bi-type-italic"></i></button>
  <button class="gd-b" data-gs="cmd:underline" data-st="underline" title="Podčiarknuté (Ctrl+U)"><i class="bi bi-type-underline"></i></button>
  <label class="gd-b gd-color" title="Farba textu"><i class="bi bi-fonts"></i><input type="color" data-color="foreColor" value="#d93025"></label>
  <label class="gd-b gd-color" title="Farba zvýraznenia"><i class="bi bi-highlighter"></i><input type="color" data-color="hiliteColor" value="#fff475"></label>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="link" title="Vložiť odkaz (Ctrl+K)"><i class="bi bi-link-45deg"></i></button>
  <button class="gd-b" data-gs="image" title="Vložiť obrázok"><i class="bi bi-image"></i></button>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="cmd:justifyLeft" data-st="justifyLeft" title="Zarovnať doľava"><i class="bi bi-text-left"></i></button>
  <button class="gd-b" data-gs="cmd:justifyCenter" data-st="justifyCenter" title="Na stred"><i class="bi bi-text-center"></i></button>
  <button class="gd-b" data-gs="cmd:justifyRight" data-st="justifyRight" title="Doprava"><i class="bi bi-text-right"></i></button>
  <button class="gd-b" data-gs="cmd:justifyFull" data-st="justifyFull" title="Do bloku"><i class="bi bi-justify"></i></button>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="cmd:insertOrderedList" title="Číslovaný zoznam"><i class="bi bi-list-ol"></i></button>
  <button class="gd-b" data-gs="cmd:insertUnorderedList" title="Zoznam s odrážkami"><i class="bi bi-list-ul"></i></button>
  <button class="gd-b" data-gs="cmd:outdent" title="Zmenšiť odsadenie"><i class="bi bi-text-indent-right"></i></button>
  <button class="gd-b" data-gs="cmd:indent" title="Zväčšiť odsadenie"><i class="bi bi-text-indent-left"></i></button>
  <button class="gd-b" data-gs="clearfmt" title="Vymazať formátovanie (Ctrl+\\)"><i class="bi bi-eraser"></i></button>
</div>
<main class="gd-main" id="gdMain"><div class="gd-zoom" id="gdZoomBox"><article class="gd-page" id="gdPage" spellcheck="true" lang="sk"></article></div>
  <aside class="gd-outline d-none" id="gdOutline"><div class="d-flex align-items-center mb-2"><b class="small me-auto">Prehľad</b><button class="btn-close btn-sm" data-gs="outline"></button></div><nav></nav></aside>
  <div class="gs-load" id="gsLoad"><div class="spinner-border text-primary mb-2"></div><div>Otváram dokument…</div></div></main>
<input type="file" id="gdImg" accept="image/png,image/jpeg,image/gif" class="d-none">
<div class="gd-find d-none" id="gdFind"><input class="form-control form-control-sm" id="gdFindQ" placeholder="Nájsť"><input class="form-control form-control-sm" id="gdFindR" placeholder="Nahradiť za">
  <button class="btn btn-sm btn-light" data-gs="find:next">Ďalší</button><button class="btn btn-sm btn-light" data-gs="find:one" data-rw>Nahradiť</button><button class="btn btn-sm btn-light" data-gs="find:all" data-rw>Všetko</button><span class="small text-body-secondary" id="gdFindN"></span><button class="btn-close btn-sm" data-gs="find"></button></div>

<div class="modal fade" id="gsKeys" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-keyboard me-2"></i>Klávesové skratky</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><table class="table table-sm gs-keys"><tbody>
    <tr><td>Ctrl+S</td><td>Uložiť hneď (inak sa ukladá samo)</td></tr>
    <tr><td>Ctrl+Z / Ctrl+Y</td><td>Späť / Znova</td></tr>
    <tr><td>Ctrl+B / Ctrl+I / Ctrl+U</td><td>Tučné / kurzíva / podčiarknuté</td></tr>
    <tr><td>Ctrl+Alt+0 … 3</td><td>Normálny text, Nadpis 1 – 3</td></tr>
    <tr><td>Ctrl+Shift+7 / 8</td><td>Číslovaný zoznam / odrážky</td></tr>
    <tr><td>Tab / Shift+Tab</td><td>V zozname: posunúť o úroveň nižšie / vyššie</td></tr>
    <tr><td>Ctrl+K</td><td>Vložiť odkaz</td></tr>
    <tr><td>Ctrl+Enter</td><td>Zlom strany</td></tr>
    <tr><td>Ctrl+H</td><td>Nájsť a nahradiť</td></tr>
    <tr><td>Ctrl+\</td><td>Vymazať formátovanie</td></tr>
    <tr><td>Ctrl+P</td><td>Tlačiť / uložiť ako PDF</td></tr>
  </tbody></table>
  <div class="small text-body-secondary">Obrázok vložíš aj prilepením (Ctrl+V) alebo potiahnutím do dokumentu.</div>
</div></div></div>

<script>window.CLOUD_DOC = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(lib('bootstrap/bootstrap.bundle.min.js', '')) ?>"></script>
<script src="<?= e(lib('jszip/jszip.min.js', '')) ?>"></script>
<script src="<?= e(lib('docx/docx.js', '')) ?>"></script>
<script src="<?= e(url('assets/js/ldoc-io.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc.js') . $V) ?>"></script>
<?php endif; ?>
</body>
</html>
