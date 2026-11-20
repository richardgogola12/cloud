<?php
/* Príručka pre používateľov a vývojárska príručka (iba administrátor). */
require __DIR__ . '/inc/core.php';
$user = require_login();
$doc = ($_GET['doc'] ?? '') === 'dev' ? 'dev' : 'user';
if ($doc === 'dev' && $user['role'] !== 'admin') { http_response_code(403); $doc = 'user'; }
session_write_close();
$active = $doc === 'dev' ? 'devhelp' : 'help';
$title = $doc === 'dev' ? 'Vývojárska príručka' : 'Príručka';

/** Snímka obrazovky z assets/help (ak existuje). */
function fig(string $name, string $caption = '', string $cls = ''): string {
    $f = APP_ROOT . '/assets/help/' . $name . '.jpg';
    if (!is_file($f)) return '';
    return '<figure class="' . e($cls) . '"><img loading="lazy" src="' . e(url('assets/help/' . $name . '.jpg')) . '?v=' . e(APP_VERSION) . '" alt="' . e($caption) . '">'
        . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
}
/** Nadpis kapitoly s ikonou. */
function h2(string $id, string $icon, string $text): string {
    return '<h2 id="' . e($id) . '"><span class="hn"><i class="bi ' . e($icon) . '"></i></span>' . e($text) . '</h2>';
}
$app = e(cfg('app_name'));
require __DIR__ . '/inc/views/layout_start.php';
?>
<div class="help-hero d-flex flex-wrap align-items-center gap-3">
  <div class="me-auto">
    <h1 class="h4 fw-bold mb-1"><i class="bi <?= $doc === 'dev' ? 'bi-code-slash' : 'bi-book' ?> me-2"></i><?= e($title) ?></h1>
    <div class="small text-body-secondary"><?= $doc === 'dev' ? 'Ako je ' . $app . ' postavený, ako ho nainštalovať, spravovať a rozvíjať. Viditeľné iba pre administrátorov.' : 'Všetko, čo ' . $app . ' vie, krok za krokom – na webe aj v aplikácii pre Android.' ?> · verzia <?= e(APP_VERSION) ?></div>
  </div>
  <form class="search-box help-search" role="search" onsubmit="return false"><i class="bi bi-search search-ic"></i>
    <input type="search" class="form-control" id="helpSearch" placeholder="Hľadať v príručke…" autocomplete="off" aria-label="Hľadať v príručke"></form>
  <?php if ($doc === 'user'): ?><button class="btn btn-primary btn-sm rounded-pill px-3" data-action="tour"><i class="bi bi-signpost-split me-1"></i>Rýchly sprievodca</button><?php endif; ?>
  <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" onclick="print()"><i class="bi bi-printer me-1"></i>Tlačiť / PDF</button>
</div>
<div class="help-wrap">
  <aside class="help-toc" id="helpToc"><button class="btn btn-outline-secondary btn-sm w-100 d-lg-none mb-2" onclick="this.parentNode.classList.toggle('open')"><i class="bi bi-list-ul me-1"></i>Obsah</button><nav></nav></aside>
  <article class="help-doc" id="helpDoc">
    <?php require __DIR__ . '/inc/help/' . $doc . '.php'; ?>
    <div class="text-center text-body-secondary py-5 d-none" id="helpNone"><i class="bi bi-search fs-1 d-block mb-2"></i>V príručke sa nič nenašlo. Skús iné slovo.</div>
  </article>
</div>
<div class="modal fade" id="helpZoom" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content bg-transparent border-0">
  <img class="w-100 rounded-3" alt="" data-bs-dismiss="modal" style="cursor:zoom-out"></div></div></div>
<script>
(() => {
  const doc = document.getElementById('helpDoc'), nav = document.querySelector('#helpToc nav');
  const slug = (t) => t.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  const heads = [...doc.querySelectorAll('h2, h3')];
  heads.forEach(h => { if (!h.id) h.id = slug(h.textContent); });
  nav.innerHTML = heads.map(h => '<a href="#' + h.id + '" class="' + (h.tagName === 'H3' ? 'sub' : '') + '">' + h.textContent.replace(/[<>&]/g, '') + '</a>').join('');
  const links = [...nav.querySelectorAll('a')];
  const io = new IntersectionObserver((es) => es.forEach(e => { if (e.isIntersecting) { links.forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + e.target.id)); } }), { rootMargin: '0px 0px -75% 0px' });
  heads.forEach(h => io.observe(h));
  // zväčšenie obrázka
  doc.addEventListener('click', (e) => { const im = e.target.closest('figure img'); if (!im) return; const z = document.getElementById('helpZoom'); z.querySelector('img').src = im.src; bootstrap.Modal.getOrCreateInstance(z).show(); });
  // hľadanie: skryje kapitoly bez zhody a zvýrazní slová
  const sections = []; let cur = null;
  [...doc.children].forEach(el => { if (el.id === 'helpNone') return; if (el.tagName === 'H2') { cur = { h: el, els: [] }; sections.push(cur); } else if (cur) cur.els.push(el); });
  const q = document.getElementById('helpSearch');
  q.addEventListener('input', () => {
    const t = q.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    doc.querySelectorAll('mark.hit').forEach(m => m.replaceWith(document.createTextNode(m.textContent)));
    doc.normalize();
    sections.forEach(s => {
      const txt = (s.h.textContent + ' ' + s.els.map(e => e.textContent).join(' ')).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      const show = !t || txt.includes(t);
      [s.h, ...s.els].forEach(e => e.classList.toggle('d-none', !show));
    });
    document.getElementById('helpNone').classList.toggle('d-none', sections.some(s => !s.h.classList.contains('d-none')));
    if (t.length < 2) return;
    const walker = document.createTreeWalker(doc, NodeFilter.SHOW_TEXT);
    const hits = [];
    while (walker.nextNode()) { const n = walker.currentNode; const v = n.nodeValue.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); const i = v.indexOf(t); if (i >= 0 && !n.parentNode.closest('.d-none')) hits.push([n, i]); }
    hits.slice(0, 300).forEach(([n, i]) => { const r = document.createRange(); r.setStart(n, i); r.setEnd(n, Math.min(n.length, i + t.length)); const m = document.createElement('mark'); m.className = 'hit'; r.surroundContents(m); });
  });
  if (location.hash && location.hash !== '#sprievodca') setTimeout(() => document.getElementById(location.hash.slice(1))?.scrollIntoView(), 50);
})();
</script>
<?php require __DIR__ . '/inc/views/layout_end.php'; ?>
