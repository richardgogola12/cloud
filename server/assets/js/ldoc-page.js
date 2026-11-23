/* Editor dokumentov – strany ako v Google Dokumentoch: stránkovanie (odseky a riadky sa presúvajú na ďalšiu stranu),
   hlavičky a päty s číslami strán, poznámky pod čiarou dole na strane, pravítko s odsadením a okrajmi, priblíženie,
   nastavenie strany (veľkosť papiera, orientácia, okraje, farba), rozloženie bez strán, stĺpce, obsah a tlač. */
(function () {
  const GD = window.GD; if (!GD) return;
  const { S, page, $, esc } = GD;
  const MM = 96 / 25.4, CM = 96 / 2.54, GAP = 14;
  const over = $('#gdOver'), main = $('#gdMain'), zoomBox = $('#gdZoomBox');
  const PAPER = { A4: [210, 297], A3: [297, 420], A5: [148, 210], B5: [176, 250], Letter: [215.9, 279.4], Legal: [215.9, 355.6], Tabloid: [279.4, 431.8], Executive: [184.2, 266.7], Statement: [139.7, 215.9] };
  let G = GAP, printing = false, pages = 1, fnPage = [], hdrEdit = null;

  const dims = () => {
    const P = S.page, w = (P.landscape ? P.h : P.w) * MM, h = (P.landscape ? P.w : P.h) * MM;
    return { W: w, H: h, mt: P.mt * CM, mb: P.mb * CM, ml: P.ml * CM, mr: P.mr * CM, hd: P.hd * CM, fd: P.fd * CM };
  };
  GD.dims = dims;
  const paged = () => !S.view.pageless && (S.page.cols || 1) === 1;
  GD.pageCount = () => paged() ? pages : 1;

  /* ---------- CSS strany ---------- */
  function pageCss() {
    const d = dims(), P = S.page;
    let css;
    if (S.view.pageless) css = '.gd-page{width:auto;max-width:min(100% - 32px,1100px);padding:48px 64px 96px;min-height:calc(100vh - 160px);background:' + (P.color || '#fff') + '}';
    else css = '.gd-page{width:' + d.W + 'px;padding:' + d.mt + 'px ' + d.mr + 'px ' + (paged() ? 0 : d.mb) + 'px ' + d.ml + 'px;background:' + (P.color || '#fff') + '}' +
      (P.cols > 1 ? '.gd-page{column-count:' + P.cols + ';column-gap:' + (1.27 * CM) + 'px;min-height:' + d.H + 'px}' : '');
    css += '@page{size:' + (P.landscape ? P.h : P.w) + 'mm ' + (P.landscape ? P.w : P.h) + 'mm;margin:' + (paged() ? '0' : P.mt + 'cm ' + P.mr + 'cm ' + P.mb + 'cm ' + P.ml + 'cm') + '}';
    $('#gdPageCss').textContent = css;
    document.body.classList.toggle('pageless', !!S.view.pageless);
    document.body.classList.toggle('paged', paged());
  }

  /* ---------- stránkovanie ---------- */
  function reset() {
    page.querySelectorAll('tr.gd-pgrow').forEach(x => x.remove());
    page.querySelectorAll('.gd-lb').forEach(x => { const p = x.parentNode; x.remove(); p && p.normalize && null; });
    page.querySelectorAll('[data-pg]').forEach(x => { if (x.dataset.pg === 'td') x.querySelectorAll(':scope > td, :scope > th').forEach(td => { td.style.paddingTop = td.dataset.pgo || ''; td.style.removeProperty('--pgp'); delete td.dataset.pgo; if (!td.getAttribute('style')) td.removeAttribute('style'); }); else { x.style.paddingTop = x.dataset.pgo || ''; x.style.removeProperty('--pgp'); delete x.dataset.pgo; } delete x.dataset.pg; delete x.dataset.pgb; if (!x.getAttribute('style')) x.removeAttribute('style'); });
  }
  function units() {
    const out = [];
    for (const el of page.children) {
      if (el.tagName === 'UL' || el.tagName === 'OL') { for (const li of el.children) if (li.tagName === 'LI') out.push(li); }
      else if (el.tagName === 'TABLE') { el.querySelectorAll(':scope > tbody > tr, :scope > thead > tr, :scope > tr').forEach(tr => { if (!tr.classList.contains('gd-pgrow')) out.push(tr); }); }
      else out.push(el);
    }
    return out;
  }
  /** Posunie úsek na ďalšiu stranu. Pri tlači sa navyše použije zlom strany (prehliadač by inak blok posunul sám). */
  function pad(u, px, newPage) {
    if (px <= 0.5) return;
    const mt = dims().mt;
    if (u.tagName === 'TR') {
      // riadok tabuľky: pred neho sa vloží neviditeľný riadok-medzera (a pri pripnutej hlavičke jej kópia)
      const t = u.closest('table'), cols = Math.max(1, ...[...t.rows].map(r => [...r.cells].reduce((a, c) => a + (c.colSpan || 1), 0)));
      const sp = document.createElement('tr'); sp.className = 'gd-pgrow'; sp.contentEditable = 'false'; if (newPage) sp.dataset.pgb = '1';
      sp.innerHTML = '<td colspan="' + cols + '"></td>'; sp.firstChild.style.height = px + 'px'; sp.style.setProperty('--pgp', mt + 'px');
      u.before(sp);
      if (t.dataset.hdr === '1' && newPage) { const first = [...t.rows].find(r => !r.classList.contains('gd-pgrow')); if (first && first !== u) { const h = first.cloneNode(true); h.className = 'gd-pgrow hdr'; h.contentEditable = 'false'; h.querySelectorAll('[id]').forEach(x => x.removeAttribute('id')); u.before(h); } }
      return;
    }
    u._pg = (u._pg || 0) + px;
    if (u.dataset.pg === undefined) u.dataset.pgo = u.style.paddingTop || '';
    u.dataset.pg = '1'; if (newPage) u.dataset.pgb = '1';
    u.style.paddingTop = ((parseFloat(getComputedStyle(u).paddingTop) || 0) + px) + 'px';
    u.style.setProperty('--pgp', ((parseFloat(u.dataset.pgo) || 0) + mt) + 'px');
  }
  const SPLIT = /^(P|H[1-6]|LI|BLOCKQUOTE|PRE|DIV)$/;
  /** Nájde začiatok riadka, ktorý prechádza cez hranicu y (súradnice strany) – vráti {range, top} alebo null.
      Hľadá sa binárne podľa polohy znakov (funguje aj mimo viditeľnej časti). */
  function lineAt(u, y, ar, sc) {
    const nodes = [], w = document.createTreeWalker(u, NodeFilter.SHOW_TEXT, { acceptNode: (n) => n.parentNode.closest('.gd-lb, sup.gd-fn, .gd-toc') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT });
    let total = 0; while (w.nextNode()) { const n = w.currentNode; if (!n.nodeValue.length) continue; nodes.push([n, total]); total += n.nodeValue.length; }
    if (!total) return null;
    const r = document.createRange();
    const loc = (i) => { let lo = 0, hi = nodes.length - 1; while (lo < hi) { const m = (lo + hi + 1) >> 1; if (nodes[m][1] <= i) lo = m; else hi = m - 1; } return [nodes[lo][0], i - nodes[lo][1]]; };
    const rect = (i) => { const [n, o] = loc(i); r.setStart(n, o); r.setEnd(n, o + 1); const rs = [...r.getClientRects()].filter(x => x.height > 0); const x = rs[rs.length - 1] || r.getBoundingClientRect(); return { top: (x.top - ar.top) / sc, bottom: (x.bottom - ar.top) / sc, h: x.height }; };
    if (rect(total - 1).bottom <= y) return null;
    let lo = 0, hi = total - 1;
    while (lo < hi) { const m = (lo + hi) >> 1; const x = rect(m); if (x.h && x.bottom > y) hi = m; else lo = m + 1; }
    const lineTop = rect(lo).top;
    let a = 0, b = lo;
    while (a < b) { const m = (a + b) >> 1; const x = rect(m); if (x.h && x.top >= lineTop - 2) b = m; else a = m + 1; }
    const [n, o] = loc(a);
    const out = document.createRange(); out.setStart(n, o); out.collapse(true);
    return { range: out, top: lineTop };
  }
  function fnHeights() {
    const list = [...page.querySelectorAll('sup.gd-fn')];
    if (!list.length) return { list, h: new Map() };
    const d = dims(), m = document.createElement('div');
    m.className = 'gd-fns'; m.style.cssText = 'position:absolute;visibility:hidden;left:-9999px;top:0;width:' + (d.W - d.ml - d.mr) + 'px';
    m.innerHTML = list.map((s, i) => '<div class="gd-fnt"><sup>' + (i + 1) + '</sup> ' + esc(s.dataset.fn || '') + '</div>').join('');
    document.body.appendChild(m);
    const h = new Map(); [...m.children].forEach((c, i) => h.set(list[i], c.offsetHeight));
    m.remove();
    return { list, h };
  }
  let layT = null, laying = false;
  function layout() {
    if (laying || !S.ready && !page.childNodes.length) return;
    laying = true;
    try {
      pageCss();
      // uloženie výberu (vkladanie zlomov rozdeľuje textové uzly)
      const s = getSelection(); const sr = s.rangeCount && page.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange() : null;
      const keep = sr ? { sc: sr.startContainer, so: sr.startOffset, ec: sr.endContainer, eo: sr.endOffset } : null;
      reset();
      units().forEach(u => { u._pg = 0; });
      if (!paged()) { pages = 1; page.style.minHeight = ''; overlays(); return; }
      const d = dims(), H = d.H, cTop = (p) => p * (H + G) + d.mt, pBot = (p) => p * (H + G) + H - d.mb;
      const ar = page.getBoundingClientRect(), sc = ar.width / page.offsetWidth || 1;
      const live = (u) => { const r = u.getBoundingClientRect(); return { top: (r.top - ar.top) / sc + (u._pg || 0), bottom: (r.bottom - ar.top) / sc }; };
      const fh = fnHeights(), reserve = [], fnOf = (u) => fh.list.filter(x => u.contains(x)).reduce((a, x) => a + fh.h.get(x), 0);
      let p = 0, forced = false, prev = null;
      const us = units();
      const isHead = (e) => e && /^H[1-6]$/.test(e.tagName) || (e && e.classList && e.classList.contains('gd-title'));
      for (const u of us) {
        if (u.classList && u.classList.contains('page-break')) { forced = true; continue; }
        let guard = 0, split = false;
        while (guard++ < 60) {
          let { top, bottom } = live(u);
          if (bottom - top < 0.5 && u.tagName !== 'HR') break;
          while (!split && top >= (p + 1) * (H + G) - d.mb + 0.5 && p < 2000) p++;
          if (forced) { forced = false; if (top > cTop(p) + 1 || p === 0 && top > cTop(0) + 1 || u !== us[0]) { pad(u, cTop(p + 1) - top, true); p++; continue; } }
          if (split) top = Math.max(top, cTop(p));
          else if (top < cTop(p) - 0.5) { pad(u, cTop(p) - top); continue; }
          const fn = fnOf(u), res = (reserve[p] || 0), extra = fn ? fn + (res ? 0 : 18) : 0;
          const cb = pBot(p) - res - extra;
          if (bottom <= cb + 0.5) { if (fn) reserve[p] = res + extra; break; }
          const room = cb - top, hgt = bottom - top, full = H - d.mt - d.mb;
          const splittable = SPLIT.test(u.tagName) && !u.classList.contains('gd-toc') && hgt > 48 && (hgt > full * 0.35 || hgt > full - 4);
          if (splittable && room > 20) {
            const L = lineAt(u, cb, ar, sc);
            if (L && L.top > top + 4) {
              const lb = document.createElement('span'); lb.className = 'gd-lb'; lb.contentEditable = 'false';
              lb.style.height = Math.max(1, cTop(p + 1) - L.top) + 'px'; lb.style.setProperty('--pgp', d.mt + 'px');
              L.range.insertNode(lb);
              if (fn) reserve[p] = res + extra;
              p++; split = true; continue;
            }
          }
          if (!split && top > cTop(p) + 1) {
            // nadpis ostáva s nasledujúcim textom
            if (prev && isHead(prev.u) && prev.p === p && prev.top > cTop(p) + 1) pad(prev.u, cTop(p + 1) - prev.top, true); else pad(u, cTop(p + 1) - top, true);
            p++; continue;
          }
          break; // vyšší ako strana – nedá sa
        }
        if (!(u.classList && u.classList.contains('page-break'))) prev = { u, p, top: live(u).top };
      }
      // počet strán podľa konca obsahu
      const last = page.lastElementChild ? live(page.lastElementChild).bottom : d.mt;
      pages = Math.max(1, Math.floor((last + d.mb - 1) / (H + G)) + 1, forced ? p + 2 : p + 1);
      page.style.minHeight = (pages * (H + G) - G - (printing ? 2 : 0)) + 'px';
      // poznámky pod čiarou: na stranu, kde je značka
      fnPage = fh.list.map(sup => { const r = sup.getBoundingClientRect(); return Math.max(0, Math.floor(((r.top - ar.top) / sc) / (H + G))); });
      if (keep) try { const r = document.createRange(); r.setStart(keep.sc, Math.min(keep.so, keep.sc.nodeType === 3 ? keep.sc.nodeValue.length : keep.sc.childNodes.length)); r.setEnd(keep.ec, Math.min(keep.eo, keep.ec.nodeType === 3 ? keep.ec.nodeValue.length : keep.ec.childNodes.length)); if (page.contains(r.startContainer)) { s.removeAllRanges(); s.addRange(r); } } catch (e) {}
      overlays(fh);
      tocPages();
    } finally { laying = false; ruler(); }
  }
  GD.relayout = () => { clearTimeout(layT); layT = setTimeout(layout, 30); };
  const later = () => { clearTimeout(layT); layT = setTimeout(layout, 350); };
  GD.on('change', later); GD.on('structure', later); GD.on('view', () => layout());
  addEventListener('resize', () => { if (S.zoomFit) setZoom('fit', true); later(); });
  if (document.fonts) document.fonts.ready.then(() => S.ready && layout());

  /* ---------- prekryvy: medzery strán, hlavičky, päty, poznámky ---------- */
  function fieldHtml(h, p) { return String(h || '').replace(/(<span class="gd-fld"[^>]*data-f="(PAGE|NUMPAGES)"[^>]*>)[^<]*(<\/span>)/g, (m, a, f, b) => a + (f === 'PAGE' ? p + 1 : pages) + b); }
  function hfFor(kind, p) { const o = S[kind] || {}; return S.page.diffFirst && p === 0 ? (o.first || '') : (o.def || ''); }
  function overlays(fh) {
    // rozpísaná poznámka pod čiarou ostane rozpísaná
    const ae = document.activeElement, fx = ae && ae.classList && ae.classList.contains('gd-fntx') ? { i: ae.parentNode.dataset.i, off: (() => { const s = getSelection(); if (!s.rangeCount) return null; const r = document.createRange(); r.selectNodeContents(ae); r.setEnd(s.anchorNode, s.anchorOffset); return r.toString().length; })() } : null;
    over.innerHTML = '';
    try { overlaysIn(fh); } finally {
      if (fx) { const t = over.querySelector('.gd-fnt[data-i="' + fx.i + '"] .gd-fntx'); if (t) { t.focus({ preventScroll: true }); const tn = t.firstChild; const r = document.createRange(); if (tn && tn.nodeType === 3 && fx.off !== null) r.setStart(tn, Math.min(fx.off, tn.nodeValue.length)); else { r.selectNodeContents(t); r.collapse(false); } const s = getSelection(); s.removeAllRanges(); s.addRange(r); } }
    }
  }
  function overlaysIn(fh) {
    over.style.width = page.offsetWidth + 'px';
    if (!paged()) { over.style.height = '0'; return; }
    const d = dims(), H = d.H, frag = document.createDocumentFragment();
    const list = fh ? fh.list : [...page.querySelectorAll('sup.gd-fn')];
    for (let p = 0; p < pages; p++) {
      const y = p * (H + G);
      if (p < pages - 1 && G) { const g = document.createElement('div'); g.className = 'gd-gap'; g.style.top = (y + H) + 'px'; g.style.height = G + 'px'; frag.appendChild(g); }
      for (const kind of ['header', 'footer']) {
        const b = document.createElement('div'); b.className = 'gd-hf ' + (kind === 'header' ? 'h' : 'f'); b.dataset.kind = kind; b.dataset.p = p;
        b.style.left = d.ml + 'px'; b.style.width = (d.W - d.ml - d.mr) + 'px';
        if (kind === 'header') b.style.top = (y + d.hd) + 'px'; else b.style.bottom = 'auto', b.style.top = (y + H - d.fd) + 'px';
        const html = hfFor(kind, p);
        b.innerHTML = html ? fieldHtml(html, p) : '';
        if (!html) b.classList.add('empty');
        b.title = 'Dvojklikom upravíš ' + (kind === 'header' ? 'hlavičku' : 'pätu');
        if (hdrEdit && hdrEdit.kind === kind && hdrEdit.p === p) continue;
        frag.appendChild(b);
      }
      const fns = list.map((s, i) => ({ s, i })).filter(x => (fnPage[x.i] || 0) === p);
      if (fns.length) {
        const box = document.createElement('div'); box.className = 'gd-fns';
        box.style.left = d.ml + 'px'; box.style.width = (d.W - d.ml - d.mr) + 'px';
        box.innerHTML = fns.map(x => '<div class="gd-fnt" data-i="' + x.i + '"><sup>' + (x.i + 1) + '</sup> <span class="gd-fntx"' + (GD.canEdit() ? ' contenteditable="true"' : '') + ' spellcheck="true">' + esc(x.s.dataset.fn || '') + '</span></div>').join('');
        frag.appendChild(box);
        box.style.top = '0px';
        box._p = p;
      }
    }
    over.appendChild(frag);
    over.querySelectorAll('.gd-fns').forEach(b => { b.style.top = (b._p * (H + G) + H - d.mb - b.offsetHeight) + 'px'; });
    over.style.height = (pages * (H + G) - G) + 'px';
    if (hdrEdit) over.appendChild(hdrEdit.el);
  }
  // poznámky pod čiarou – úprava priamo dole na strane
  over.addEventListener('input', (e) => {
    const t = e.target.closest('.gd-fntx'); if (!t) return;
    const sup = page.querySelectorAll('sup.gd-fn')[+t.parentNode.dataset.i]; if (!sup) return;
    sup.dataset.fn = t.innerText.replace(/\n+$/, ''); GD.markDirty();
  });
  over.addEventListener('keydown', (e) => { if (e.target.closest('.gd-fntx') && e.key === 'Enter') { e.preventDefault(); } if (e.target.closest('.gd-fntx') && e.key === 'Escape') { e.target.blur(); } });
  over.addEventListener('focusout', (e) => { if (e.target.closest('.gd-fntx')) later(); });
  page.addEventListener('click', (e) => {
    const sup = e.target.closest('sup.gd-fn'); if (!sup) return;
    const i = [...page.querySelectorAll('sup.gd-fn')].indexOf(sup);
    const t = over.querySelector('.gd-fnt[data-i="' + i + '"] .gd-fntx');
    if (t) { GD.scrollToEl(t); setTimeout(() => { t.focus(); GD.placeCaretIn(t); }, 250); }
  });
  GD.placeCaretIn = (el) => { const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); const s = getSelection(); s.removeAllRanges(); s.addRange(r); };
  function insertFootnote() {
    if (!GD.canEdit()) return;
    if (!paged()) return GD.toast('Poznámky pod čiarou sa zobrazujú iba v rozložení so stranami', 'err');
    GD.restore();
    document.execCommand('insertHTML', false, '<sup class="gd-fn" contenteditable="false" data-fn=""></sup>&#8203;');
    GD.markDirty(); layout();
    const all = [...page.querySelectorAll('sup.gd-fn')], r = GD.range();
    let idx = all.findIndex(s => r && s.compareDocumentPosition(r.startContainer) & Node.DOCUMENT_POSITION_FOLLOWING && !(all[all.indexOf(s) + 1] && all[all.indexOf(s) + 1].compareDocumentPosition(r.startContainer) & Node.DOCUMENT_POSITION_FOLLOWING));
    if (idx < 0) idx = all.length - 1;
    const t = over.querySelector('.gd-fnt[data-i="' + idx + '"] .gd-fntx');
    if (t) { GD.scrollToEl(t); setTimeout(() => t.focus(), 200); }
  }

  /* ---------- úprava hlavičky a päty ---------- */
  function editHF(kind, p) {
    if (!GD.canEdit()) return;
    if (!paged()) { GD.toast('Hlavičky a päty sa zobrazujú v rozložení so stranami (Zobraziť → Rozloženie bez strán)'); }
    doneHF();
    p = p || 0;
    const d = dims(), y = p * (dims().H + G);
    const which = S.page.diffFirst && p === 0 ? 'first' : 'def';
    const el = document.createElement('div'); el.className = 'gd-hfed';
    el.innerHTML = '<div class="gd-hfbox gd-hf ' + (kind === 'header' ? 'h' : 'f') + '" contenteditable="true" spellcheck="true"></div>' +
      '<div class="gd-hfbar"><span class="gd-hftitle">' + (kind === 'header' ? 'Hlavička' : 'Päta') + (which === 'first' ? ' – prvá strana' : '') + '</span>' +
      '<label><input type="checkbox" data-hf="first"' + (S.page.diffFirst ? ' checked' : '') + '> Iná prvá strana</label>' +
      '<button data-hf="pn" title="Vložiť číslo strany"><i class="bi bi-123"></i> Číslo strany</button><button data-hf="pc" title="Vložiť počet strán"><i class="bi bi-hash"></i> Počet strán</button>' +
      '<button data-hf="opts"><i class="bi bi-sliders"></i> Možnosti</button><button data-hf="del"><i class="bi bi-trash3"></i> Odstrániť</button><button data-hf="done" class="ok">Hotovo</button></div>';
    const box = el.querySelector('.gd-hfbox');
    box.innerHTML = fieldHtml((S[kind] || {})[which] || '', p) || '<p><br></p>';
    el.style.left = d.ml + 'px'; el.style.width = (d.W - d.ml - d.mr) + 'px';
    if (kind === 'header') el.style.top = (y + d.hd) + 'px'; else { el.style.top = (y + d.H - d.fd) + 'px'; el.classList.add('foot'); }
    hdrEdit = { kind, p, which, el, box };
    GD.altEdit = box;
    document.body.classList.add('hf-editing');
    overlays(); over.appendChild(el);
    box.focus(); GD.placeCaretIn(box);
    box.addEventListener('input', () => { (S[kind] = S[kind] || {})[which] = cleanHF(box.innerHTML); GD.markDirty(); });
    el.addEventListener('mousedown', (e) => { if (e.target.closest('.gd-hfbar button')) e.preventDefault(); });
    el.addEventListener('click', (e) => {
      const b = e.target.closest('[data-hf]'); if (!b) return;
      const a = b.dataset.hf;
      if (a === 'first') { S.page.diffFirst = b.checked; GD.markDirty(); const k = kind, pp = p; doneHF(); editHF(k, pp); return; }
      if (a === 'pn' || a === 'pc') { box.focus(); document.execCommand('insertHTML', false, '<span class="gd-fld" contenteditable="false" data-f="' + (a === 'pn' ? 'PAGE' : 'NUMPAGES') + '">' + (a === 'pn' ? p + 1 : pages) + '</span>&#8203;'); box.dispatchEvent(new Event('input')); }
      if (a === 'opts') pageSetup(true);
      if (a === 'del') { (S[kind] = S[kind] || {})[which] = ''; GD.markDirty(); doneHF(); }
      if (a === 'done') doneHF();
    });
  }
  const cleanHF = (h) => { const t = document.createElement('div'); t.innerHTML = h; t.querySelectorAll('[contenteditable]').forEach(x => { if (!x.classList.contains('gd-fld')) x.removeAttribute('contenteditable'); }); const txt = t.textContent.replace(/[​\s]/g, ''); return txt || t.querySelector('img,.gd-fld') ? t.innerHTML : ''; };
  function doneHF() {
    if (!hdrEdit) return;
    hdrEdit = null; GD.altEdit = null;
    document.body.classList.remove('hf-editing');
    overlays(); layout();
  }
  GD.doneHF = doneHF;
  over.addEventListener('dblclick', (e) => { const b = e.target.closest('.gd-hf:not(.gd-hfbox)'); if (b) editHF(b.dataset.kind, +b.dataset.p); });
  page.addEventListener('mousedown', () => { if (hdrEdit) doneHF(); });
  document.addEventListener('keydown', (e) => { if (hdrEdit && e.key === 'Escape') { e.preventDefault(); doneHF(); } }, true);
  // dvojklik do okraja strany nad / pod textom = úprava hlavičky / päty
  main.addEventListener('dblclick', (e) => {
    if (!paged() || e.target.closest('.gd-hf, .gd-hfed, .gd-fns') || !GD.canEdit()) return;
    const ar = page.getBoundingClientRect(), sc = ar.width / page.offsetWidth, d = dims();
    const y = (e.clientY - ar.top) / sc, p = Math.floor(y / (d.H + G)), py = y - p * (d.H + G);
    if (p < 0 || p >= pages) return;
    if (py < d.mt - 4) { e.preventDefault(); editHF('header', p); } else if (py > d.H - d.mb + 4 && py < d.H) { e.preventDefault(); editHF('footer', p); }
  });
  function pageNums(pos) {
    const fld = '<span class="gd-fld" contenteditable="false" data-f="PAGE">1</span>';
    const kind = pos[0] === 't' ? 'header' : 'footer', al = pos[1] === 'c' ? 'center' : 'right';
    const cur = (S[kind] || {}).def || '';
    const t = document.createElement('div'); t.innerHTML = cur;
    if (!t.querySelector('[data-f="PAGE"]')) { const p = document.createElement('p'); p.style.textAlign = al; p.innerHTML = fld; t.appendChild(p); }
    (S[kind] = S[kind] || {}).def = t.innerHTML;
    if (pos.endsWith('1')) { S.page.diffFirst = true; S[kind].first = S[kind].first || ''; }
    GD.markDirty(); layout();
    GD.toast('Čísla strán sú v ' + (kind === 'header' ? 'hlavičke' : 'päte') + ' – dvojklikom ich upravíš');
  }
  async function pageNumsDialog() {
    let r = null;
    await GD.dlg('Čísla strán', '<div class="row g-3"><div class="col-6"><label class="form-label">Umiestnenie</label><select class="form-select" id="pnPos"><option value="f">Päta (dole)</option><option value="h">Hlavička (hore)</option></select></div>' +
      '<div class="col-6"><label class="form-label">Zarovnanie</label><select class="form-select" id="pnAl"><option value="right">Vpravo</option><option value="center">Na stred</option><option value="left">Vľavo</option></select></div>' +
      '<div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" id="pnFirst" checked> <span class="form-check-label">Zobraziť na prvej strane</span></label>' +
      '<label class="form-check"><input type="checkbox" class="form-check-input" id="pnCount"> <span class="form-check-label">Tvar „strana 1 z 5“</span></label></div></div>',
    { onOk: (el) => { r = { pos: el.querySelector('#pnPos').value, al: el.querySelector('#pnAl').value, first: el.querySelector('#pnFirst').checked, count: el.querySelector('#pnCount').checked }; } });
    if (!r) return;
    const kind = r.pos === 'h' ? 'header' : 'footer';
    const html = '<p style="text-align:' + r.al + '">' + (r.count ? 'strana ' : '') + '<span class="gd-fld" contenteditable="false" data-f="PAGE">1</span>' + (r.count ? ' z <span class="gd-fld" contenteditable="false" data-f="NUMPAGES">1</span>' : '') + '</p>';
    const t = document.createElement('div'); t.innerHTML = (S[kind] || {}).def || ''; t.querySelectorAll('p').forEach(p => { if (p.querySelector('[data-f="PAGE"]')) p.remove(); });
    (S[kind] = S[kind] || {}).def = t.innerHTML + html;
    S.page.diffFirst = !r.first; if (!r.first) S[kind].first = S[kind].first || '';
    GD.markDirty(); layout();
  }

  /* ---------- obsah ---------- */
  const uid = () => 'h.' + Math.random().toString(36).slice(2, 10);
  function tocBuild(div) {
    const hs = [...page.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(h => !div.contains(h) && h.textContent.trim());
    const num = div.dataset.toc !== 'link';
    div.innerHTML = '<div class="gd-toc-bar"><button data-toc-up title="Aktualizovať obsah"><i class="bi bi-arrow-clockwise"></i></button><button data-toc-del title="Odstrániť obsah"><i class="bi bi-trash3"></i></button></div>' +
      (hs.length ? hs.map(h => { if (!h.id) h.id = uid(); const l = +h.tagName[1], t = h.textContent.trim(); return '<div class="gd-toc-e l' + l + '" data-to="' + esc(h.id) + '" data-l="' + l + '" data-text="' + esc(t) + '" data-pg=""><a href="#' + esc(h.id) + '">' + esc(t) + '</a>' + (num ? '<span class="pg"></span>' : '') + '</div>'; }).join('')
        : '<div class="gd-toc-empty">Pridaj nadpisy (Formát → Štýly odsekov), ktoré sa zobrazia v obsahu.</div>');
  }
  function tocPages() {
    const tocs = page.querySelectorAll('.gd-toc'); if (!tocs.length) return;
    const ar = page.getBoundingClientRect(), sc = ar.width / page.offsetWidth || 1, d = dims();
    tocs.forEach(div => div.querySelectorAll('[data-to]').forEach(e => {
      const h = document.getElementById(e.dataset.to); if (!h) return;
      const p = paged() ? Math.floor(((h.getBoundingClientRect().top - ar.top) / sc) / (d.H + G)) + 1 : '';
      e.dataset.pg = p; const s = e.querySelector('.pg'); if (s) s.textContent = p;
    }));
  }
  function insertToc(kind) {
    if (!GD.canEdit()) return;
    GD.restore();
    document.execCommand('insertHTML', false, '<div class="gd-toc" contenteditable="false" data-toc="' + kind + '"></div><p><br></p>');
    page.querySelectorAll('.gd-toc').forEach(tocBuild);
    GD.markDirty(); layout();
  }
  GD.on('open', () => { page.querySelectorAll('.gd-toc').forEach(tocBuild); });
  page.addEventListener('click', (e) => {
    const t = e.target.closest('.gd-toc'); if (!t) return;
    if (e.target.closest('[data-toc-up]')) { e.preventDefault(); tocBuild(t); GD.markDirty(); layout(); return; }
    if (e.target.closest('[data-toc-del]') && GD.canEdit()) { e.preventDefault(); t.remove(); GD.markDirty(); layout(); return; }
    const a = e.target.closest('a[href^="#"]'); if (a) { e.preventDefault(); const h = document.getElementById(a.getAttribute('href').slice(1)); h && (GD.scrollToEl(h), GD.placeCaret(h)); }
  });

  /* ---------- priblíženie ---------- */
  function setZoom(v, quiet) {
    S.zoomFit = v === 'fit';
    let z = S.zoomFit ? Math.max(0.3, Math.min(2, (main.clientWidth - 24) / (page.offsetWidth + 32))) : +v || 1;
    if (S.view.pageless) z = S.zoomFit ? 1 : z;
    S.zoom = z; zoomBox.style.zoom = z;
    $('#gdZoomL').textContent = S.zoomFit ? 'Prispôsobiť' : Math.round(z * 100) + ' %';
    if (!quiet) GD.store.set('zoom', v);
    ruler();
  }
  GD.setZoom = setZoom;
  main.addEventListener('wheel', (e) => { if (!(e.ctrlKey || e.metaKey) || document.querySelector('img.sel')) return; e.preventDefault(); setZoom(Math.max(0.5, Math.min(2, Math.round((S.zoom + (e.deltaY < 0 ? 0.1 : -0.1)) * 10) / 10))); }, { passive: false });

  /* ---------- pravítko ---------- */
  const rin = $('#gdRin');
  function ruler() {
    if (!S.view.ruler || !S.ready) return;
    const ar = page.getBoundingClientRect(), d = dims(), sc = S.zoom || 1;
    const wpx = ar.width, left = ar.left;
    rin.style.left = left + 'px'; rin.style.width = wpx + 'px';
    const ml = S.view.pageless ? 0 : d.ml * sc, mr = S.view.pageless ? 0 : d.mr * sc;
    $('#gdRmL').style.width = ml + 'px'; $('#gdRmR').style.width = mr + 'px';
    const key = wpx + '|' + ml + '|' + sc;
    if (rin._k !== key) {
      rin._k = key;
      let h = ''; const step = CM * sc;
      for (let i = -Math.floor(ml / step); i * step + ml < wpx - mr + step; i++) { const x = ml + i * step; if (x < 0 || x > wpx) continue; h += '<i class="t" style="left:' + x + 'px"></i>' + (i ? '<b style="left:' + x + 'px">' + Math.abs(i) + '</b>' : '') + '<i class="h" style="left:' + (x + step / 2) + 'px"></i>'; }
      $('#gdRt').innerHTML = h;
    }
    const r = GD.range(), b = r && GD.blockOf(r.startContainer);
    const bl = b ? (b.getBoundingClientRect().left - ar.left) : ml, br = b ? (ar.right - b.getBoundingClientRect().right) : mr;
    const ti = b ? (parseFloat(getComputedStyle(b).textIndent) || 0) * sc : 0;
    $('#gdRkL').style.left = bl + 'px'; $('#gdRkF').style.left = (bl + ti) + 'px'; $('#gdRkR').style.left = (wpx - br) + 'px';
  }
  GD.ruler = ruler;
  GD.on('sel', ruler); main.addEventListener('scroll', () => {}, { passive: true });
  // ťahanie značiek odsadenia a okrajov
  rin.addEventListener('mousedown', (e) => {
    const k = e.target.closest('.gd-rk, .gd-rm'); if (!k || !GD.canEdit()) return;
    e.preventDefault();
    const ar = page.getBoundingClientRect(), sc = S.zoom || 1, d = dims();
    const blocks = GD.selBlocks();
    const snap = (px) => Math.round(px / (CM * sc / 4)) * (CM * sc / 4);
    const tip = document.createElement('div'); tip.className = 'gd-rtip'; document.body.appendChild(tip);
    const move = (ev) => {
      const x = Math.max(0, Math.min(ar.width, ev.clientX - ar.left));
      tip.style.left = ev.clientX + 'px'; tip.style.top = (rin.getBoundingClientRect().bottom + 4) + 'px';
      if (k.id === 'gdRmL' || k.id === 'gdRmR') {
        const v = Math.max(0.5 * CM * sc, k.id === 'gdRmL' ? snap(x) : snap(ar.width - x));
        const cm = Math.round(v / sc / CM * 100) / 100; tip.textContent = (k.id === 'gdRmL' ? 'Ľavý' : 'Pravý') + ' okraj: ' + String(cm).replace('.', ',') + ' cm';
        S.page[k.id === 'gdRmL' ? 'ml' : 'mr'] = cm; pageCss(); ruler();
        return;
      }
      const ml = d.ml * sc, mr = d.mr * sc;
      if (k.id === 'gdRkR') { const v = Math.max(0, snap(ar.width - mr - x)); blocks.forEach(b => b.style.marginRight = v ? Math.round(v / sc) + 'px' : ''); tip.textContent = 'Pravé odsadenie: ' + String(Math.round(v / sc / CM * 100) / 100).replace('.', ',') + ' cm'; }
      else if (k.id === 'gdRkL') { const v = Math.max(0, snap(x - ml)); const ti = blocks[0] ? parseFloat(blocks[0].style.textIndent) || 0 : 0; blocks.forEach(b => { if (b.tagName !== 'LI') b.style.marginLeft = v ? Math.round(v / sc) + 'px' : ''; }); void ti; tip.textContent = 'Ľavé odsadenie: ' + String(Math.round(v / sc / CM * 100) / 100).replace('.', ',') + ' cm'; }
      else { const bl = blocks[0] ? blocks[0].getBoundingClientRect().left - ar.left : ml; const v = snap(x - bl); blocks.forEach(b => b.style.textIndent = v ? Math.round(v / sc) + 'px' : ''); tip.textContent = 'Odsadenie prvého riadka: ' + String(Math.round(v / sc / CM * 100) / 100).replace('.', ',') + ' cm'; }
      ruler();
    };
    const up = () => { removeEventListener('mousemove', move); removeEventListener('mouseup', up); tip.remove(); GD.markDirty(); layout(); };
    addEventListener('mousemove', move); addEventListener('mouseup', up);
  });
  rin.addEventListener('dblclick', (e) => { if (e.target.closest('.gd-rk')) GD.indentDialog(); else if (e.target.closest('.gd-rm')) pageSetup(); });

  /* ---------- nastavenie strany ---------- */
  async function pageSetup() {
    if (!GD.canEdit() && !S.ro) return;
    const P = S.page;
    const cur = Object.entries(PAPER).find(([, v]) => Math.abs(v[0] - P.w) < 1 && Math.abs(v[1] - P.h) < 1);
    const num = (id, v, lab) => '<div class="col-6 col-md-3"><label class="form-label small">' + lab + '</label><div class="input-group input-group-sm"><input class="form-control" type="number" step="0.1" min="0" max="15" id="' + id + '" value="' + v + '"><span class="input-group-text">cm</span></div></div>';
    let res = null;
    await GD.dlg('Nastavenie strany',
      '<ul class="nav nav-pills mb-3" role="tablist"><li class="nav-item"><button class="nav-link' + (S.view.pageless ? '' : ' active') + '" data-bs-toggle="pill" data-bs-target="#psPages" type="button">Strany</button></li><li class="nav-item"><button class="nav-link' + (S.view.pageless ? ' active' : '') + '" data-bs-toggle="pill" data-bs-target="#psLess" type="button">Bez strán</button></li></ul>' +
      '<div class="tab-content"><div class="tab-pane fade' + (S.view.pageless ? '' : ' show active') + '" id="psPages"><div class="row g-3">' +
      '<div class="col-12"><label class="form-label">Orientácia</label><div><label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="psO" value="p"' + (P.landscape ? '' : ' checked') + '> Na výšku</label><label class="form-check form-check-inline"><input class="form-check-input" type="radio" name="psO" value="l"' + (P.landscape ? ' checked' : '') + '> Na šírku</label></div></div>' +
      '<div class="col-md-6"><label class="form-label">Veľkosť papiera</label><select class="form-select" id="psSize">' + Object.entries(PAPER).map(([k, v]) => '<option value="' + k + '"' + (cur && cur[0] === k ? ' selected' : '') + '>' + k + ' (' + String(v[0] / 10).replace('.', ',') + ' × ' + String(v[1] / 10).replace('.', ',') + ' cm)</option>').join('') + (cur ? '' : '<option value="" selected>Vlastná (' + P.w + ' × ' + P.h + ' mm)</option>') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Farba strany</label><div class="d-flex gap-2 align-items-center"><input type="color" class="form-control form-control-color" id="psColor" value="' + (P.color || '#ffffff') + '"><button type="button" class="btn btn-sm btn-light" id="psColorX">Biela</button></div></div>' +
      '<div class="col-12"><label class="form-label mb-1">Okraje</label><div class="row g-2">' + num('psT', P.mt, 'Hore') + num('psB', P.mb, 'Dole') + num('psL', P.ml, 'Vľavo') + num('psR', P.mr, 'Vpravo') + '</div></div>' +
      '<div class="col-12"><div class="row g-2">' + num('psH', P.hd, 'Hlavička od okraja') + num('psF', P.fd, 'Päta od okraja') + '</div></div>' +
      '</div></div><div class="tab-pane fade' + (S.view.pageless ? ' show active' : '') + '" id="psLess"><p class="text-body-secondary small mb-0">Bez strán sa dokument zobrazuje ako jedna dlhá strana – bez zlomov, hlavičiek, piat a čísel strán. Hodí sa na písanie na šírku obrazovky. Pri tlači a v súbore Word sa strany použijú podľa nastavení na karte „Strany“.</p></div></div>' +
      '<label class="form-check mt-3"><input type="checkbox" class="form-check-input" id="psDef"> <span class="form-check-label">Nastaviť ako predvolené pre nové dokumenty</span></label>',
    { size: 'modal-lg', ok: 'OK',
      init: (el) => { el.querySelector('#psColorX').onclick = () => { el.querySelector('#psColor').value = '#ffffff'; }; },
      onOk: (el) => { const v = (id) => Math.max(0, Math.min(15, +String(el.querySelector(id).value).replace(',', '.') || 0)); res = { less: el.querySelector('#psLess').classList.contains('active'), land: el.querySelector('[name=psO]:checked').value === 'l', size: el.querySelector('#psSize').value, color: el.querySelector('#psColor').value, mt: v('#psT'), mb: v('#psB'), ml: v('#psL'), mr: v('#psR'), hd: v('#psH'), fd: v('#psF'), def: el.querySelector('#psDef').checked }; } });
    if (!res) return;
    S.view.pageless = res.less;
    if (!res.less) {
      if (res.size && PAPER[res.size]) { P.w = PAPER[res.size][0]; P.h = PAPER[res.size][1]; }
      Object.assign(P, { landscape: res.land, color: res.color.toLowerCase() === '#ffffff' ? null : res.color, mt: res.mt, mb: res.mb, ml: res.ml, mr: res.mr, hd: res.hd, fd: res.fd });
      if (res.def) GD.store.set('pagedef', { w: P.w, h: P.h, landscape: P.landscape, mt: P.mt, mb: P.mb, ml: P.ml, mr: P.mr });
    }
    if (!S.ro) GD.markDirty();
    GD.applyView(); if (S.zoomFit) setZoom('fit', true);
  }
  GD.pageSetup = pageSetup;
  GD.on('open', (doc) => {
    if (doc && (!doc.page || !Object.keys(doc.page).length)) { const d = GD.store.get('pagedef', null); if (d) Object.assign(S.page, d); }
    const z = GD.store.get('zoom', innerWidth < 900 ? 'fit' : 1); setZoom(innerWidth < 900 ? 'fit' : z, true);
    setTimeout(layout, 0); setTimeout(layout, 400);
  });
  GD.on('chk', (k) => k === 'pageless' ? !!S.view.pageless : undefined);

  /* ---------- tlač ---------- */
  addEventListener('beforeprint', () => { if (hdrEdit) doneHF(); printing = true; G = 0; document.body.classList.add('printing'); layout(); });
  addEventListener('afterprint', () => { printing = false; G = GAP; document.body.classList.remove('printing'); layout(); });

  Object.assign(GD.act, {
    pagesetup: pageSetup, pageless: () => { S.view.pageless = !S.view.pageless; GD.applyView(); setZoom(S.zoomFit ? 'fit' : S.zoom, true); },
    header: () => editHF('header', curPage()), footer: () => editHF('footer', curPage()),
    footnote: insertFootnote, pagenums: pageNumsDialog,
    'pn:*': (v) => v === 'count' ? (GD.canEdit() && (GD.restore(), document.execCommand('insertHTML', false, '<span class="gd-fld" contenteditable="false" data-f="NUMPAGES">' + pages + '</span>&#8203;'), GD.markDirty())) : pageNums(v),
    'toc:*': insertToc,
    'cols:*': (n) => { if (!GD.canEdit()) return; S.page.cols = +n; GD.markDirty(); GD.applyView(); if (+n > 1) GD.toast('Pri stĺpcoch sa dokument zobrazuje bez zlomov strán; v tlači a vo Worde budú strany aj stĺpce správne.'); },
    pagebreak: () => { if (!GD.canEdit()) return; GD.restore(); document.execCommand('insertHTML', false, '<hr class="page-break"><p><br></p>'); GD.markDirty(); layout(); },
    colbreak: () => { if (!GD.canEdit()) return; if ((S.page.cols || 1) < 2) return GD.toast('Zlom stĺpca funguje, keď má dokument viac stĺpcov (Formát → Stĺpce)'); GD.restore(); document.execCommand('insertHTML', false, '<hr class="col-break"><p><br></p>'); GD.markDirty(); },
    print: () => { GD.restore(); setTimeout(() => window.print(), 0); },
  });
  function curPage() { const r = GD.range(); if (!r || !paged()) return 0; const ar = page.getBoundingClientRect(), sc = ar.width / page.offsetWidth, rc = r.getBoundingClientRect(); return Math.max(0, Math.min(pages - 1, Math.floor(((rc.top || ar.top) - ar.top) / sc / (dims().H + G)))); }
  GD.on('clean', (c) => {
    c.querySelectorAll('.gd-lb, tr.gd-pgrow').forEach(x => x.remove());
    c.querySelectorAll('[data-pg]').forEach(x => { const fix = (e) => { e.style.paddingTop = e.dataset.pgo || ''; e.style.removeProperty('--pgp'); delete e.dataset.pgo; delete e.dataset.pgb; if (!e.getAttribute('style')) e.removeAttribute('style'); }; if (x.dataset.pg === 'td') x.querySelectorAll(':scope > td, :scope > th').forEach(fix); else fix(x); delete x.dataset.pg; delete x.dataset.pgb; });
    c.querySelectorAll('.gd-toc-bar, .gd-toc-empty').forEach(x => x.remove());
    c.normalize();
  });
  GD.on('mode', () => layout());
})();
