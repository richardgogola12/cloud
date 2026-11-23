<?php
/* Editor dokumentov Word (.docx) v štýle Google Dokumentov. Otvára sa z disku v okne (iframe) alebo samostatne.
   Súbor sa načíta a uloží cez api.php (download / save_binary) – každé uloženie je nová verzia. */
require __DIR__ . '/inc/core.php';
$user = require_login();
$uid = (int)$user['id'];
$err = null; $f = null; $canEdit = false; $own = false;
try {
    $a = file_access($uid, (int)($_GET['id'] ?? 0));
    $f = $a['row'];
    if (!preg_match('/\.docx$/i', $f['name'])) throw new RuntimeException('Tento súbor nie je dokument Word (.docx).');
    $canEdit = PERM_LEVEL[$a['perm']] >= PERM_LEVEL['edit'] && empty($_GET['ro']);
    $own = $a['perm'] === 'owner';
} catch (Throwable $e) { $err = $e->getMessage(); }
session_write_close();
$embed = !empty($_GET['embed']);
$title = $f ? $f['name'] : 'Dokument';
$V = '?v=' . APP_VERSION;
$cfg = $f ? [
    'id' => (int)$f['id'], 'name' => $f['name'], 'updated' => $f['updated_at'], 'created' => $f['created_at'] ?? null, 'folder' => $f['folder_id'] ? (int)$f['folder_id'] : null,
    'size' => (int)$f['size'], 'canEdit' => $canEdit, 'embed' => $embed, 'own' => $own, 'starred' => !empty($f['starred']),
    'user' => (string)($user['name'] ?? ''),
    'url' => url('api.php?action=download&id=' . (int)$f['id']), 'drive' => url('index.php') . ($f['folder_id'] ? '#folder/' . (int)$f['folder_id'] : ''),
    'help' => url('help.php#dokumenty'), 'chart' => lib('chartjs/chart.umd.min.js', ''),
] : null;
/** položka ponuky */
function mi(string $act, string $icon, string $text, string $kbd = '', string $attr = ''): string {
    return '<li><button class="gm-i" data-gs="' . e($act) . '"' . $attr . '><i class="bi bi-' . $icon . '"></i><span>' . $text . '</span>' . ($kbd ? '<kbd>' . $kbd . '</kbd>' : '') . '</button></li>';
}
/** podponuka */
function sub(string $icon, string $text, string $inner, string $attr = ''): string {
    return '<li class="gm-sub"' . $attr . '><button class="gm-i"><i class="bi bi-' . $icon . '"></i><span>' . $text . '</span><i class="bi bi-caret-right-fill gm-arr"></i></button><ul class="gm-menu">' . $inner . '</ul></li>';
}
const SEP = '<li class="gm-sep"></li>';
function hd(string $t): string { return '<li class="gm-h">' . $t . '</li>'; }
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
<style id="gdPageCss"></style>
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
    <div class="gs-title"><span class="gs-name" id="gsName" title="Premenovať" data-gs="rename" data-rw><?= e($f['name']) ?></span>
      <?php if ($own): ?><button class="gs-ib gs-star<?= !empty($f['starred']) ? ' on' : '' ?>" id="gsStar" data-gs="star" title="Pridať hviezdičku"><i class="bi bi-star"></i></button><?php endif; ?>
      <span class="badge rounded-pill text-bg-warning gs-ro d-none" id="gsRo">Iba na čítanie</span>
      <span class="gs-status" id="gsStatus"></span></div>
    <nav class="gs-menu" id="gsMenu">
      <div class="gm"><button class="gs-mb" data-gm>Súbor</button><ul class="gm-menu">
        <?= sub('file-earmark-plus', 'Nový', mi('new', 'file-earmark-text', 'Dokument') . mi('newsheet', 'file-earmark-spreadsheet', 'Tabuľka'), ' data-rw') ?>
        <?= mi('copy', 'files', 'Vytvoriť kópiu') ?>
        <?= SEP ?>
        <?= mi('share', 'person-plus', 'Zdieľať') ?>
        <?= sub('download', 'Stiahnuť',
            mi('dl:docx', 'file-earmark-word', 'Microsoft Word (.docx)') . mi('dl:odt', 'file-earmark-text', 'Dokument OpenDocument (.odt)') . mi('dl:pdf', 'file-earmark-pdf', 'Dokument PDF (.pdf)') .
            mi('dl:rtf', 'file-earmark-richtext', 'RTF (.rtf)') . mi('dl:txt', 'filetype-txt', 'Obyčajný text (.txt)') . mi('dl:html', 'filetype-html', 'Webová stránka (.html)') .
            mi('dl:epub', 'book', 'Publikácia EPUB (.epub)') . mi('dl:md', 'markdown', 'Markdown (.md)')) ?>
        <?= SEP ?>
        <?= mi('rename', 'pencil', 'Premenovať', '', ' data-rw') ?>
        <?= mi('move', 'folder-symlink', 'Presunúť', '', ' data-rw') ?>
        <?= mi('trash', 'trash3', 'Presunúť do koša', '', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('versions', 'clock-history', 'História verzií') ?>
        <?= mi('activity', 'activity', 'Aktivita') ?>
        <?= SEP ?>
        <?= mi('info', 'info-circle', 'Podrobnosti') ?>
        <?= mi('pagesetup', 'file-earmark-ruled', 'Nastavenie strany', '', ' data-rw') ?>
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
        <?= mi('copytext', 'copy', 'Kopírovať', 'Ctrl+C') ?>
        <?= mi('paste', 'clipboard', 'Prilepiť', 'Ctrl+V', ' data-rw') ?>
        <?= mi('pasteplain', 'clipboard-minus', 'Prilepiť bez formátovania', 'Ctrl+Shift+V', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('selectall', 'textarea-t', 'Vybrať všetko', 'Ctrl+A') ?>
        <?= mi('delete', 'backspace', 'Odstrániť', '', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('find', 'search', 'Nájsť a nahradiť', 'Ctrl+H') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Zobraziť</button><ul class="gm-menu">
        <?= sub('pencil-square', 'Režim', mi('mode:edit', 'pencil', 'Úpravy<small>Upravuj dokument priamo</small>', '', ' data-chk="mode:edit" data-rw') . mi('mode:suggest', 'pencil-fill', 'Navrhovanie<small>Úpravy sa stanú návrhmi</small>', '', ' data-chk="mode:suggest" data-rw') . mi('mode:view', 'eye', 'Zobrazenie<small>Čítaj alebo vytlač konečnú podobu</small>', '', ' data-chk="mode:view"')) ?>
        <?= SEP ?>
        <?= mi('pageless', 'file-earmark', 'Rozloženie bez strán', '', ' data-chk="pageless"') ?>
        <?= mi('ruler', 'rulers', 'Zobraziť pravítko', '', ' data-chk="ruler"') ?>
        <?= mi('outline', 'list-nested', 'Zobraziť prehľad', 'Ctrl+Alt+A', ' data-chk="outline"') ?>
        <?= mi('marks', 'paragraph', 'Zobraziť netlačiteľné znaky', 'Ctrl+Shift+P', ' data-chk="marks"') ?>
        <?= mi('comments', 'chat-left-text', 'Zobraziť komentáre a návrhy', '', ' data-chk="comments"') ?>
        <?= mi('wcbox', '123', 'Zobrazovať počet slov', '', ' data-chk="wcbox"') ?>
        <?= SEP ?>
        <?= mi('fullscreen', 'fullscreen', 'Celá obrazovka') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm data-rw>Vložiť</button><ul class="gm-menu">
        <?= sub('image', 'Obrázok', mi('image', 'upload', 'Nahrať z počítača') . mi('imageurl', 'link-45deg', 'Podľa webovej adresy (URL)') . mi('camera', 'camera', 'Fotoaparát')) ?>
        <?= sub('table', 'Tabuľka', '<li class="gm-grid" id="gdGrid"></li>') ?>
        <?= mi('drawing', 'brush', 'Kreslenie') ?>
        <?= mi('chart', 'bar-chart-line', 'Graf') ?>
        <?= mi('hrule', 'dash-lg', 'Vodorovná čiara') ?>
        <?= SEP ?>
        <?= mi('special', 'emoji-smile', 'Špeciálne znaky a emodži') ?>
        <?= SEP ?>
        <?= sub('layout-text-window', 'Hlavičky a päty', mi('header', 'layout-text-window', 'Hlavička', 'Ctrl+Alt+O Ctrl+Alt+H') . mi('footer', 'layout-text-window-reverse', 'Päta', 'Ctrl+Alt+O Ctrl+Alt+F')) ?>
        <?= sub('123', 'Čísla strán',
            mi('pn:tr', 'align-top', 'Hore vpravo – na všetkých stranách') . mi('pn:tr1', 'align-top', 'Hore vpravo – okrem prvej strany') .
            mi('pn:br', 'align-bottom', 'Dole vpravo – na všetkých stranách') . mi('pn:br1', 'align-bottom', 'Dole vpravo – okrem prvej strany') .
            mi('pn:bc', 'align-bottom', 'Dole v strede') . SEP . mi('pn:count', 'hash', 'Počet strán (na mieste kurzora)')) ?>
        <?= sub('file-break', 'Zlom', mi('pagebreak', 'file-break', 'Zlom strany', 'Ctrl+Enter') . mi('colbreak', 'layout-split', 'Zlom stĺpca')) ?>
        <?= SEP ?>
        <?= mi('link', 'link-45deg', 'Odkaz', 'Ctrl+K') ?>
        <?= mi('comment', 'chat-square-text', 'Komentár', 'Ctrl+Alt+M') ?>
        <?= SEP ?>
        <?= mi('bookmark', 'bookmark', 'Záložka') ?>
        <?= sub('list-columns', 'Obsah', mi('toc:num', 'list-ol', 'S číslami strán') . mi('toc:link', 'link', 'S modrými odkazmi')) ?>
        <?= mi('footnote', 'superscript', 'Poznámka pod čiarou', 'Ctrl+Alt+F') ?>
        <?= SEP ?>
        <?= mi('date', 'calendar3', 'Dátum') ?>
        <?= mi('checklist', 'ui-checks', 'Kontrolný zoznam', 'Ctrl+Shift+9') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm data-rw>Formát</button><ul class="gm-menu">
        <?= sub('type', 'Text',
            mi('cmd:bold', 'type-bold', 'Tučné', 'Ctrl+B') . mi('cmd:italic', 'type-italic', 'Kurzíva', 'Ctrl+I') . mi('cmd:underline', 'type-underline', 'Podčiarknuté', 'Ctrl+U') .
            mi('cmd:strikeThrough', 'type-strikethrough', 'Prečiarknuté', 'Alt+Shift+5') . mi('cmd:superscript', 'superscript', 'Horný index', 'Ctrl+.') . mi('cmd:subscript', 'subscript', 'Dolný index', 'Ctrl+,') .
            SEP . sub('fonts', 'Veľkosť', mi('size:1', 'plus', 'Zväčšiť písmo', 'Ctrl+Shift+.') . mi('size:-1', 'dash', 'Zmenšiť písmo', 'Ctrl+Shift+,')) .
            sub('alphabet', 'Veľké a malé písmená', mi('case:lower', 'alphabet', 'malé písmená') . mi('case:upper', 'alphabet-uppercase', 'VEĽKÉ PÍSMENÁ') . mi('case:title', 'type', 'Prvé Písmeno Veľké'))) ?>
        <?= sub('paragraph', 'Štýly odsekov',
            mi('block:p', 'paragraph', 'Normálny text', 'Ctrl+Alt+0') . mi('block:title', 'type-h1', 'Názov') . mi('block:subtitle', 'type', 'Podnadpis') .
            mi('block:h1', 'type-h1', 'Nadpis 1', 'Ctrl+Alt+1') . mi('block:h2', 'type-h2', 'Nadpis 2', 'Ctrl+Alt+2') . mi('block:h3', 'type-h3', 'Nadpis 3', 'Ctrl+Alt+3') .
            mi('block:h4', 'type-h4', 'Nadpis 4', 'Ctrl+Alt+4') . mi('block:h5', 'type-h5', 'Nadpis 5', 'Ctrl+Alt+5') . mi('block:h6', 'type-h6', 'Nadpis 6', 'Ctrl+Alt+6') .
            SEP . mi('block:quote', 'quote', 'Citát') . mi('block:code', 'code', 'Kód')) ?>
        <?= sub('text-left', 'Zarovnanie a odsadenie',
            mi('cmd:justifyLeft', 'text-left', 'Doľava', 'Ctrl+Shift+L') . mi('cmd:justifyCenter', 'text-center', 'Na stred', 'Ctrl+Shift+E') . mi('cmd:justifyRight', 'text-right', 'Doprava', 'Ctrl+Shift+R') . mi('cmd:justifyFull', 'justify', 'Do bloku', 'Ctrl+Shift+J') .
            SEP . mi('indent:1', 'text-indent-left', 'Zväčšiť odsadenie', 'Ctrl+]') . mi('indent:-1', 'text-indent-right', 'Zmenšiť odsadenie', 'Ctrl+[') . SEP . mi('indentopts', 'sliders', 'Možnosti odsadenia…')) ?>
        <?= sub('distribute-vertical', 'Riadkovanie a medzery odsekov',
            mi('lh:1', 'check2', 'Jednoduché', '', ' data-chk="lh:1"') . mi('lh:1.15', 'check2', '1,15', '', ' data-chk="lh:1.15"') . mi('lh:1.5', 'check2', '1,5', '', ' data-chk="lh:1.5"') . mi('lh:2', 'check2', 'Dvojité', '', ' data-chk="lh:2"') .
            SEP . mi('sp:before', 'arrow-bar-up', 'Pridať/odstrániť medzeru pred odsekom') . mi('sp:after', 'arrow-bar-down', 'Pridať/odstrániť medzeru za odsekom') . SEP . mi('spacingopts', 'sliders', 'Vlastné medzery…')) ?>
        <?= sub('layout-three-columns', 'Stĺpce', mi('cols:1', 'square', 'Jeden stĺpec', '', ' data-chk="cols:1"') . mi('cols:2', 'layout-split', 'Dva stĺpce', '', ' data-chk="cols:2"') . mi('cols:3', 'layout-three-columns', 'Tri stĺpce', '', ' data-chk="cols:3"')) ?>
        <?= sub('list-ul', 'Odrážky a číslovanie',
            hd('Číslovaný zoznam') . mi('ol:decimal', 'list-ol', '1. 2. 3.') . mi('ol:lower-alpha', 'list-ol', 'a. b. c.') . mi('ol:upper-alpha', 'list-ol', 'A. B. C.') . mi('ol:lower-roman', 'list-ol', 'i. ii. iii.') . mi('ol:upper-roman', 'list-ol', 'I. II. III.') .
            hd('Zoznam s odrážkami') . mi('ul:disc', 'list-ul', '● ○ ■') . mi('ul:square', 'list-ul', '■ ■ ■') . mi('ul:circle', 'list-ul', '○ ○ ○') . mi('ul:dash', 'list-ul', '– – –') . mi('ul:arrow', 'list-ul', '➢ ➢ ➢') . mi('ul:star', 'list-ul', '★ ★ ★') .
            SEP . mi('checklist', 'ui-checks', 'Kontrolný zoznam', 'Ctrl+Shift+9') . mi('listrestart', 'arrow-repeat', 'Začať číslovanie od 1')) ?>
        <?= SEP ?>
        <?= mi('header', 'layout-text-window', 'Hlavičky a päty…') ?>
        <?= mi('pagenums', '123', 'Čísla strán…') ?>
        <?= sub('table', 'Tabuľka',
            mi('tbl:props', 'sliders', 'Vlastnosti tabuľky…') . SEP .
            mi('tbl:rowabove', 'arrow-bar-up', 'Vložiť riadok nad') . mi('tbl:rowbelow', 'arrow-bar-down', 'Vložiť riadok pod') . mi('tbl:colleft', 'arrow-bar-left', 'Vložiť stĺpec vľavo') . mi('tbl:colright', 'arrow-bar-right', 'Vložiť stĺpec vpravo') . SEP .
            mi('tbl:delrow', 'dash-square', 'Odstrániť riadok') . mi('tbl:delcol', 'dash-square', 'Odstrániť stĺpec') . mi('tbl:del', 'trash3', 'Odstrániť tabuľku') . SEP .
            mi('tbl:merge', 'arrows-angle-expand', 'Zlúčiť bunky') . mi('tbl:unmerge', 'arrows-angle-contract', 'Zrušiť zlúčenie buniek') . SEP .
            mi('tbl:header', 'pin', 'Pripnúť riadok hlavičky', '', ' data-chk="tbl:header"') . mi('tbl:distribute', 'distribute-horizontal', 'Rozložiť stĺpce rovnomerne') . mi('tbl:sortasc', 'sort-alpha-down', 'Zoradiť vzostupne') . mi('tbl:sortdesc', 'sort-alpha-up-alt', 'Zoradiť zostupne')) ?>
        <?= sub('image', 'Obrázok', mi('img:opts', 'sliders', 'Možnosti obrázka…') . mi('img:alt', 'card-text', 'Alternatívny text') . mi('img:reset', 'arrow-counterclockwise', 'Obnoviť veľkosť')) ?>
        <?= SEP ?>
        <?= mi('clearfmt', 'eraser', 'Vymazať formátovanie', 'Ctrl+\\') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Nástroje</button><ul class="gm-menu">
        <?= mi('spell', 'spellcheck', 'Kontrola pravopisu', '', ' data-chk="spell"') ?>
        <?= mi('count', '123', 'Počet slov', 'Ctrl+Shift+C') ?>
        <?= mi('review', 'pencil-fill', 'Prezrieť navrhované úpravy', 'Ctrl+Alt+O Ctrl+Alt+U') ?>
        <?= mi('voice', 'mic', 'Hlasové písanie', 'Ctrl+Shift+S', ' data-rw') ?>
        <?= SEP ?>
        <?= mi('prefs', 'gear', 'Predvoľby') ?>
      </ul></div>
      <div class="gm"><button class="gs-mb" data-gm>Pomoc</button><ul class="gm-menu">
        <?= mi('menusearch', 'search', 'Hľadať v ponukách', 'Alt+/') ?>
        <?= mi('keys', 'keyboard', 'Klávesové skratky', 'Ctrl+/') ?>
        <li><a class="gm-i" href="<?= e(url('help.php#dokumenty')) ?>" target="_blank" rel="noopener" data-close><i class="bi bi-book"></i><span>Príručka: dokumenty</span></a></li>
      </ul></div>
    </nav>
  </div>
  <button class="gs-ib d-none d-sm-grid" data-gs="versions" title="História verzií"><i class="bi bi-clock-history"></i></button>
  <button class="gs-ib" data-gs="comments" title="Komentáre a návrhy" id="gdCmBtn"><i class="bi bi-chat-left-text"></i><span class="gd-cmn d-none" id="gdCmN"></span></button>
  <button class="btn btn-sm rounded-pill px-3 gs-share d-none d-sm-inline-flex" data-gs="share"><i class="bi bi-lock"></i><span class="ms-1">Zdieľať</span></button>
  <button class="btn btn-primary btn-sm rounded-pill px-3 gs-savebtn" data-gs="save" id="gsSave" title="Uložiť teraz (Ctrl+S)"><i class="bi bi-cloud-check"></i><span class="d-none d-md-inline ms-1">Uložiť</span></button>
</header>
<div class="gd-tools" id="gdTools">
  <button class="gd-b" data-gs="menusearch" title="Hľadať v ponukách (Alt+/)"><i class="bi bi-search"></i></button>
  <button class="gd-b" data-gs="undo" title="Späť (Ctrl+Z)" data-rw><i class="bi bi-arrow-counterclockwise"></i></button>
  <button class="gd-b" data-gs="redo" title="Znova (Ctrl+Y)" data-rw><i class="bi bi-arrow-clockwise"></i></button>
  <button class="gd-b" data-gs="print" title="Tlačiť (Ctrl+P)"><i class="bi bi-printer"></i></button>
  <button class="gd-b" data-gs="spell" data-st2="spell" title="Kontrola pravopisu"><i class="bi bi-spellcheck"></i></button>
  <button class="gd-b" data-gs="paintfmt" data-st2="paint" title="Kopírovať formát (dvojklik = viackrát)" data-rw><i class="bi bi-brush"></i></button>
  <div class="gm"><button class="gd-b gd-dd" data-gm title="Priblíženie"><span id="gdZoomL">100 %</span><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu">
    <?= mi('zoom:fit', 'arrows-angle-expand', 'Prispôsobiť') ?><?php foreach ([50, 75, 90, 100, 125, 150, 200] as $z) echo mi('zoom:' . ($z / 100), 'zoom-in', $z . ' %', '', ' data-chk="zoom:' . ($z / 100) . '"'); ?>
  </ul></div>
  <span class="gd-sep"></span>
  <div class="gm" data-rw><button class="gd-b gd-dd gd-wide" data-gm title="Štýly"><span id="gdBlockL">Normálny text</span><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu gd-stylemenu">
    <li><button class="gm-i" data-gs="block:p"><span class="st-p">Normálny text</span></button></li>
    <li><button class="gm-i" data-gs="block:title"><span class="st-title">Názov</span></button></li>
    <li><button class="gm-i" data-gs="block:subtitle"><span class="st-subtitle">Podnadpis</span></button></li>
    <?php for ($i = 1; $i <= 6; $i++) echo '<li><button class="gm-i" data-gs="block:h' . $i . '"><span class="st-h' . $i . '">Nadpis ' . $i . '</span></button></li>'; ?>
  </ul></div>
  <span class="gd-sep"></span>
  <div class="gm" data-rw><button class="gd-b gd-dd gd-wide" data-gm title="Písmo"><span id="gdFontL">Calibri</span><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu gd-fontmenu">
    <?php foreach (['Arial', 'Calibri', 'Cambria', 'Comic Sans MS', 'Courier New', 'Georgia', 'Garamond', 'Helvetica', 'Impact', 'Lato', 'Merriweather', 'Montserrat', 'Open Sans', 'Roboto', 'Segoe UI', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana'] as $fn) echo '<li><button class="gm-i" data-gs="font:' . e($fn) . '" data-chk="font:' . e($fn) . '"><span style="font-family:\'' . e($fn) . '\'">' . e($fn) . '</span></button></li>'; ?>
  </ul></div>
  <span class="gd-sep"></span>
  <span class="gd-sizebox" data-rw><button class="gd-b" data-gs="size:-1" title="Zmenšiť písmo (Ctrl+Shift+,)"><i class="bi bi-dash"></i></button><div class="gm d-inline-block"><input class="gd-size" id="gdSize" value="11" inputmode="numeric" title="Veľkosť písma" data-gm><ul class="gm-menu gd-sizemenu"><?php foreach ([8, 9, 10, 11, 12, 14, 18, 24, 30, 36, 48, 60, 72, 96] as $sz) echo '<li><button class="gm-i" data-gs="fs:' . $sz . '"><span>' . $sz . '</span></button></li>'; ?></ul></div><button class="gd-b" data-gs="size:1" title="Zväčšiť písmo (Ctrl+Shift+.)"><i class="bi bi-plus"></i></button></span>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="cmd:bold" data-st="bold" title="Tučné (Ctrl+B)" data-rw><i class="bi bi-type-bold"></i></button>
  <button class="gd-b" data-gs="cmd:italic" data-st="italic" title="Kurzíva (Ctrl+I)" data-rw><i class="bi bi-type-italic"></i></button>
  <button class="gd-b" data-gs="cmd:underline" data-st="underline" title="Podčiarknuté (Ctrl+U)" data-rw><i class="bi bi-type-underline"></i></button>
  <div class="gm" data-rw><button class="gd-b gd-color" data-gm title="Farba textu"><i class="bi bi-fonts"></i><span class="gd-cbar" id="gdFc" style="background:#000"></span></button><div class="gm-menu" id="gdFcPal"></div></div>
  <div class="gm" data-rw><button class="gd-b gd-color" data-gm title="Farba zvýraznenia"><i class="bi bi-highlighter"></i><span class="gd-cbar" id="gdBc" style="background:transparent"></span></button><div class="gm-menu" id="gdBcPal"></div></div>
  <span class="gd-sep"></span>
  <button class="gd-b" data-gs="link" title="Vložiť odkaz (Ctrl+K)" data-rw><i class="bi bi-link-45deg"></i></button>
  <button class="gd-b" data-gs="comment" title="Pridať komentár (Ctrl+Alt+M)"><i class="bi bi-chat-square-text"></i></button>
  <button class="gd-b" data-gs="image" title="Vložiť obrázok" data-rw><i class="bi bi-image"></i></button>
  <span class="gd-sep"></span>
  <div class="gm" data-rw><button class="gd-b gd-dd" data-gm title="Zarovnanie"><i class="bi bi-text-left" id="gdAlignI"></i><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu gm-row">
    <li><button class="gm-i" data-gs="cmd:justifyLeft" title="Doľava"><i class="bi bi-text-left"></i></button></li><li><button class="gm-i" data-gs="cmd:justifyCenter" title="Na stred"><i class="bi bi-text-center"></i></button></li>
    <li><button class="gm-i" data-gs="cmd:justifyRight" title="Doprava"><i class="bi bi-text-right"></i></button></li><li><button class="gm-i" data-gs="cmd:justifyFull" title="Do bloku"><i class="bi bi-justify"></i></button></li></ul></div>
  <div class="gm" data-rw><button class="gd-b gd-dd" data-gm title="Riadkovanie a medzery odsekov"><i class="bi bi-distribute-vertical"></i><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu">
    <?= mi('lh:1', 'check2', 'Jednoduché', '', ' data-chk="lh:1"') . mi('lh:1.15', 'check2', '1,15', '', ' data-chk="lh:1.15"') . mi('lh:1.5', 'check2', '1,5', '', ' data-chk="lh:1.5"') . mi('lh:2', 'check2', 'Dvojité', '', ' data-chk="lh:2"') . SEP . mi('sp:before', 'arrow-bar-up', 'Pridať/odstrániť medzeru pred odsekom') . mi('sp:after', 'arrow-bar-down', 'Pridať/odstrániť medzeru za odsekom') . SEP . mi('spacingopts', 'sliders', 'Vlastné medzery…') ?></ul></div>
  <button class="gd-b" data-gs="checklist" title="Kontrolný zoznam (Ctrl+Shift+9)" data-rw><i class="bi bi-ui-checks"></i></button>
  <span class="gd-split" data-rw><button class="gd-b" data-gs="ul:disc" title="Zoznam s odrážkami (Ctrl+Shift+8)"><i class="bi bi-list-ul"></i></button><div class="gm d-inline-block"><button class="gd-b gd-ddx" data-gm title="Typ odrážok"><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu gm-grid3">
    <li><button class="gm-i" data-gs="ul:disc">●<br>○<br>■</button></li><li><button class="gm-i" data-gs="ul:square">■<br>■<br>■</button></li><li><button class="gm-i" data-gs="ul:circle">○<br>○<br>○</button></li>
    <li><button class="gm-i" data-gs="ul:dash">–<br>–<br>–</button></li><li><button class="gm-i" data-gs="ul:arrow">➢<br>➢<br>➢</button></li><li><button class="gm-i" data-gs="ul:star">★<br>★<br>★</button></li></ul></div></span>
  <span class="gd-split" data-rw><button class="gd-b" data-gs="ol:decimal" title="Číslovaný zoznam (Ctrl+Shift+7)"><i class="bi bi-list-ol"></i></button><div class="gm d-inline-block"><button class="gd-b gd-ddx" data-gm title="Typ číslovania"><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu gm-grid3">
    <li><button class="gm-i" data-gs="ol:decimal">1.<br>2.<br>3.</button></li><li><button class="gm-i" data-gs="ol:lower-alpha">a.<br>b.<br>c.</button></li><li><button class="gm-i" data-gs="ol:upper-alpha">A.<br>B.<br>C.</button></li>
    <li><button class="gm-i" data-gs="ol:lower-roman">i.<br>ii.<br>iii.</button></li><li><button class="gm-i" data-gs="ol:upper-roman">I.<br>II.<br>III.</button></li></ul></div></span>
  <button class="gd-b" data-gs="indent:-1" title="Zmenšiť odsadenie (Ctrl+[)" data-rw><i class="bi bi-text-indent-right"></i></button>
  <button class="gd-b" data-gs="indent:1" title="Zväčšiť odsadenie (Ctrl+])" data-rw><i class="bi bi-text-indent-left"></i></button>
  <button class="gd-b" data-gs="clearfmt" title="Vymazať formátovanie (Ctrl+\)" data-rw><i class="bi bi-eraser"></i></button>
  <span class="gd-fill"></span>
  <div class="gm"><button class="gd-b gd-dd gd-mode" data-gm title="Režim"><i class="bi bi-pencil" id="gdModeI"></i><span id="gdModeL" class="d-none d-lg-inline">Úpravy</span><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu">
    <?= mi('mode:edit', 'pencil', 'Úpravy<small>Upravuj dokument priamo</small>', '', ' data-chk="mode:edit" data-rw') . mi('mode:suggest', 'pencil-fill', 'Navrhovanie<small>Úpravy sa stanú návrhmi</small>', '', ' data-chk="mode:suggest" data-rw') . mi('mode:view', 'eye', 'Zobrazenie<small>Čítaj alebo vytlač konečnú podobu</small>', '', ' data-chk="mode:view"') ?></ul></div>
</div>
<div class="gd-ruler" id="gdRuler"><div class="gd-rin" id="gdRin"><div class="gd-rm l" id="gdRmL" title="Ľavý okraj"></div><div class="gd-rm r" id="gdRmR" title="Pravý okraj"></div><div class="gd-rt" id="gdRt"></div>
  <div class="gd-rk fl" id="gdRkF" title="Odsadenie prvého riadka"></div><div class="gd-rk li" id="gdRkL" title="Ľavé odsadenie"></div><div class="gd-rk ri" id="gdRkR" title="Pravé odsadenie"></div></div></div>
<main class="gd-main" id="gdMain"><div class="gd-zoom" id="gdZoomBox"><div class="gd-over" id="gdOver"></div><article class="gd-page" id="gdPage" spellcheck="true" lang="sk"></article></div>
  <aside class="gd-outline d-none" id="gdOutline"><div class="d-flex align-items-center mb-2"><b class="small me-auto">Prehľad dokumentu</b><button class="btn-close btn-sm" data-gs="outline"></button></div><nav></nav></aside>
  <aside class="gd-side d-none" id="gdSide"></aside>
  <div class="gs-load" id="gsLoad"><div class="spinner-border text-primary mb-2"></div><div>Otváram dokument…</div></div></main>
<div class="gd-wc d-none" id="gdWc"></div>
<div class="gd-imgbar d-none" id="gdImgBar">
  <button data-gs="img:inline" title="V riadku textu"><i class="bi bi-text-paragraph"></i></button><button data-gs="img:left" title="Obtekanie – vľavo"><i class="bi bi-align-start"></i></button><button data-gs="img:right" title="Obtekanie – vpravo"><i class="bi bi-align-end"></i></button><button data-gs="img:break" title="Zalomiť text (na samostatnom riadku)"><i class="bi bi-align-center"></i></button>
  <span></span><button data-gs="img:opts" title="Možnosti obrázka"><i class="bi bi-three-dots-vertical"></i></button>
</div>
<div class="gd-cbox d-none" id="gdCbox"></div>
<input type="file" id="gdImg" accept="image/png,image/jpeg,image/gif,image/webp" class="d-none">
<input type="file" id="gdCam" accept="image/*" capture="environment" class="d-none">
<div class="gd-find d-none" id="gdFind">
  <div class="d-flex gap-2 align-items-center"><input class="form-control form-control-sm" id="gdFindQ" placeholder="Nájsť"><span class="small text-body-secondary text-nowrap" id="gdFindN"></span>
    <button class="btn btn-sm btn-light" data-gs="find:prev" title="Predchádzajúci"><i class="bi bi-chevron-up"></i></button><button class="btn btn-sm btn-light" data-gs="find:next" title="Ďalší"><i class="bi bi-chevron-down"></i></button><button class="btn-close btn-sm" data-gs="find"></button></div>
  <div class="d-flex gap-2 align-items-center mt-2"><input class="form-control form-control-sm" id="gdFindR" placeholder="Nahradiť za"><button class="btn btn-sm btn-light" data-gs="find:one" data-rw>Nahradiť</button><button class="btn btn-sm btn-light text-nowrap" data-gs="find:all" data-rw>Nahradiť všetko</button></div>
  <div class="d-flex gap-3 mt-2 small"><label><input type="checkbox" id="gdFindCase"> Rozlišovať veľké a malé písmená</label><label><input type="checkbox" id="gdFindRe"> Regulárne výrazy</label></div>
</div>

<div class="modal fade" id="gsKeys" tabindex="-1"><div class="modal-dialog modal-dialog-scrollable modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="bi bi-keyboard me-2"></i>Klávesové skratky</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-4"><div class="col-md-6"><table class="table table-sm gs-keys"><tbody>
    <tr><th colspan="2">Bežné</th></tr>
    <tr><td>Ctrl+S</td><td>Uložiť hneď (inak sa ukladá samo)</td></tr>
    <tr><td>Ctrl+Z / Ctrl+Y</td><td>Späť / Znova</td></tr>
    <tr><td>Ctrl+Shift+V</td><td>Prilepiť bez formátovania</td></tr>
    <tr><td>Ctrl+Alt+C / Ctrl+Alt+V</td><td>Kopírovať / prilepiť formát</td></tr>
    <tr><td>Ctrl+F / Ctrl+H</td><td>Nájsť / nájsť a nahradiť</td></tr>
    <tr><td>Ctrl+P</td><td>Tlačiť</td></tr>
    <tr><td>Alt+/</td><td>Hľadať v ponukách</td></tr>
    <tr><td>Ctrl+Shift+C</td><td>Počet slov</td></tr>
    <tr><td>Ctrl+Alt+A</td><td>Prehľad dokumentu</td></tr>
    <tr><td>Ctrl+Shift+P</td><td>Netlačiteľné znaky</td></tr>
    <tr><th colspan="2">Vkladanie</th></tr>
    <tr><td>Ctrl+K</td><td>Odkaz</td></tr>
    <tr><td>Ctrl+Alt+M</td><td>Komentár</td></tr>
    <tr><td>Ctrl+Alt+F</td><td>Poznámka pod čiarou</td></tr>
    <tr><td>Ctrl+Enter</td><td>Zlom strany</td></tr>
  </tbody></table></div><div class="col-md-6"><table class="table table-sm gs-keys"><tbody>
    <tr><th colspan="2">Formát textu</th></tr>
    <tr><td>Ctrl+B / I / U</td><td>Tučné / kurzíva / podčiarknuté</td></tr>
    <tr><td>Alt+Shift+5</td><td>Prečiarknuté</td></tr>
    <tr><td>Ctrl+. / Ctrl+,</td><td>Horný / dolný index</td></tr>
    <tr><td>Ctrl+Shift+. / ,</td><td>Väčšie / menšie písmo</td></tr>
    <tr><td>Ctrl+\</td><td>Vymazať formátovanie</td></tr>
    <tr><th colspan="2">Odseky</th></tr>
    <tr><td>Ctrl+Alt+0 … 6</td><td>Normálny text, Nadpis 1 – 6</td></tr>
    <tr><td>Ctrl+Shift+L / E / R / J</td><td>Doľava / na stred / doprava / do bloku</td></tr>
    <tr><td>Ctrl+] / Ctrl+[</td><td>Zväčšiť / zmenšiť odsadenie</td></tr>
    <tr><td>Ctrl+Shift+7 / 8 / 9</td><td>Číslovaný / odrážky / kontrolný zoznam</td></tr>
    <tr><td>Tab / Shift+Tab</td><td>V zozname úroveň nižšie / vyššie, v tabuľke ďalšia bunka</td></tr>
  </tbody></table></div></div>
  <div class="small text-body-secondary">Obrázok vložíš aj prilepením (Ctrl+V) alebo potiahnutím do dokumentu. Ctrl+klik otvorí odkaz.</div>
</div></div></div></div>

<script>window.CLOUD_DOC = <?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(lib('bootstrap/bootstrap.bundle.min.js', '')) ?>"></script>
<script src="<?= e(lib('jszip/jszip.min.js', '')) ?>"></script>
<script src="<?= e(lib('docx/docx.js', '')) ?>"></script>
<script src="<?= e(url('assets/js/gmenu.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc-io.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc-page.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc-more.js') . $V) ?>"></script>
<script src="<?= e(url('assets/js/ldoc-x.js') . $V) ?>"></script>
<?php endif; ?>
</body>
</html>
