/* Editor dokumentov (doc.php) v štýle Google Dokumentov: strana A4, ponuky a panel nástrojov po slovensky,
   automatické ukladanie ako nová verzia súboru .docx. */
(function () {
  const C = window.CLOUD_DOC; if (!C) return;
  const IO = window.CloudDocIO;
  const $ = (s) => document.querySelector(s), $$ = (s) => [...document.querySelectorAll(s)];
  const base = document.querySelector('meta[name="base-url"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const inFrame = window.parent && window.parent !== window;
  const page = $('#gdPage');
  const S = { ro: !C.canEdit, dirty: false, saving: false, again: false, updated: C.updated, id: C.id, name: C.name, ready: false, t: null, landscape: false };
  window.CloudDocEditor = S;

  const post = (m) => { if (inFrame) try { parent.postMessage(Object.assign({ cloudSheet: m.ev }, m), location.origin); } catch (e) {} };
  function status(t, cls) { const e = $('#gsStatus'); e.textContent = t; e.className = 'gs-status ' + (cls || ''); }
  function toast(msg, type) {
    let w = $('#gsToasts'); if (!w) { w = document.createElement('div'); w.id = 'gsToasts'; document.body.appendChild(w); }
    const d = document.createElement('div'); d.className = 'gs-toast ' + (type || ''); d.textContent = msg; w.appendChild(d);
    setTimeout(() => d.classList.add('out'), 3500); setTimeout(() => d.remove(), 4000);
  }
  function ask(title, text, buttons, input) {
    return new Promise((res) => {
      const el = document.createElement('div'); el.className = 'modal fade'; el.tabIndex = -1;
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5></div><div class="modal-body"><div class="gd-msg"></div></div><div class="modal-footer"></div></div></div>';
      el.querySelector('.modal-title').textContent = title; el.querySelector('.gd-msg').textContent = text;
      let inp = null;
      if (input !== undefined) { inp = document.createElement('input'); inp.className = 'form-control mt-2'; inp.value = input; el.querySelector('.modal-body').appendChild(inp); }
      let val = null;
      buttons.forEach(([id, label, cls]) => { const b = document.createElement('button'); b.className = 'btn rounded-pill px-3 ' + (cls || 'btn-outline-secondary'); b.textContent = label; b.onclick = () => { val = inp && id === 'ok' ? inp.value : id; m.hide(); }; el.querySelector('.modal-footer').appendChild(b); });
      document.body.appendChild(el);
      const m = new bootstrap.Modal(el, { backdrop: 'static' });
      if (inp) { el.addEventListener('shown.bs.modal', () => { inp.focus(); inp.select(); }); inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { val = inp.value; m.hide(); } }); }
      el.addEventListener('hidden.bs.modal', () => { el.remove(); res(val); });
      m.show();
    });
  }

  /* ---------- otvorenie ---------- */
  async function open() {
    try {
      const res = await fetch(C.url, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('Súbor sa nepodarilo načítať (' + res.status + ').');
      const buf = await res.arrayBuffer();
      const d = buf.byteLength ? await IO.fromDocx(buf) : { html: '<p><br></p>', warn: [] };
      if (d.warn.length && !S.ro) {
        const r = await ask('Dokument obsahuje: ' + d.warn.join(', '), 'Tieto časti editor nezobrazí a pri uložení by sa z dokumentu stratili (pôvodná verzia by ostala vo Verziách). Odporúčam otvoriť iba na čítanie.',
          [['edit', 'Upravovať aj tak'], ['ro', 'Iba na čítanie', 'btn-primary']]);
        S.ro = r !== 'edit';
      }
      page.innerHTML = d.html;
      S.landscape = !!d.landscape; page.classList.toggle('landscape', S.landscape);
      page.contentEditable = S.ro ? 'false' : 'true';
      document.body.classList.toggle('ro', S.ro);
      $('#gsRo').classList.toggle('d-none', !S.ro);
      try { document.execCommand('styleWithCSS', false, true); document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
      $('#gsLoad').classList.add('d-none');
      S.ready = true;
      status(S.ro ? '' : 'Zmeny sa ukladajú samy');
      post({ ev: 'ready' });
      if (!S.ro) { page.focus(); placeCaret(page.firstElementChild || page); }
      if (!buf.byteLength && !S.ro) markDirty(); // nový prázdny súbor → hneď platný .docx
    } catch (e) {
      $('#gsLoad').innerHTML = '<i class="bi bi-exclamation-triangle fs-1 text-danger mb-2"></i><div>' + String(e.message || 'Dokument sa nepodarilo otvoriť.').replace(/</g, '&lt;') + '</div>';
    }
  }
  function placeCaret(el) { try { const r = document.createRange(); r.selectNodeContents(el); r.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(r); } catch (e) {} }

  /* ---------- ukladanie ---------- */
  function markDirty() {
    if (!S.ready || S.ro) return;
    S.dirty = true; status('Neuložené zmeny…', 'warn');
    clearTimeout(S.t); S.t = setTimeout(() => save(true), 2500);
  }
  page.addEventListener('input', markDirty);
  function cleanClone() {
    const c = page.cloneNode(true);
    c.querySelectorAll('mark.gd-hit').forEach(m => m.replaceWith(...m.childNodes));
    c.querySelectorAll('.sel').forEach(x => x.classList.remove('sel'));
    return c;
  }
  async function save(auto) {
    if (S.ro || !S.ready) return true;
    if (S.saving) { S.again = true; return false; }
    if (auto && !S.dirty) return true;
    clearTimeout(S.t); S.saving = true; S.dirty = false; status('Ukladá sa…', 'busy');
    try {
      const blob = await IO.toDocx(cleanClone(), { title: S.name.replace(/\.docx$/i, ''), landscape: S.landscape });
      const fd = new FormData();
      fd.append('data', blob, S.name); fd.append('id', S.id); fd.append('updated', S.updated || '');
      const res = await fetch(base + 'api.php?action=save_binary', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
      const r = await res.json().catch(() => ({ ok: false, error: 'Neočakávaná odpoveď servera (' + res.status + ')' }));
      if (!r.ok) throw new Error(r.error || 'Uloženie zlyhalo');
      S.updated = r.updated || '';
      status('Všetky zmeny sú uložené v Cloude', 'ok');
      post({ ev: 'saved', id: S.id, name: S.name, updated: S.updated });
      return true;
    } catch (e) {
      S.dirty = true; status(e.message || 'Uloženie zlyhalo', 'err');
      if (!auto) toast(e.message || 'Uloženie zlyhalo', 'err');
      return false;
    } finally {
      S.saving = false;
      if (S.again) { S.again = false; if (S.dirty) { clearTimeout(S.t); S.t = setTimeout(() => save(true), 300); } }
    }
  }
  async function download(type) {
    const nm = S.name.replace(/\.docx$/i, '') + '.' + type;
    let blob;
    if (type === 'docx') blob = await IO.toDocx(cleanClone(), { landscape: S.landscape });
    else if (type === 'html') blob = new Blob(['<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>' + S.name.replace(/</g, '') + '</title><style>body{font-family:Calibri,Arial,sans-serif;max-width:800px;margin:40px auto;line-height:1.4}table{border-collapse:collapse}td{border:1px solid #000;padding:4px 6px}</style></head><body>' + cleanClone().innerHTML + '</body></html>'], { type: 'text/html' });
    else blob = new Blob([page.innerText], { type: 'text/plain;charset=utf-8' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nm; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }
  async function close() {
    if (S.dirty || S.saving) {
      status('Ukladá sa…', 'busy');
      for (let i = 0; i < 50 && S.saving; i++) await new Promise(r => setTimeout(r, 100));
      if (S.dirty && !(await save(false))) {
        const r = await ask('Zmeny sa nepodarilo uložiť', 'Ak teraz zavrieš dokument, posledné zmeny sa stratia. Môžeš si ich stiahnuť cez Súbor → Stiahnuť ako.', [['stay', 'Ostať', 'btn-primary'], ['leave', 'Zavrieť bez uloženia']]);
        if (r !== 'leave') return;
      }
    }
    S.dirty = false;
    if (inFrame) post({ ev: 'close' }); else location.href = C.drive;
  }
  window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.cloudSheetCmd === 'close') close(); });
  window.addEventListener('beforeunload', (e) => { if (S.dirty && !inFrame) { e.preventDefault(); e.returnValue = ''; } });
  window.cloudBack = () => { close(); return true; };

  /* ---------- úpravy ---------- */
  let savedRange = null;
  document.addEventListener('selectionchange', () => {
    const s = getSelection(); if (s.rangeCount && page.contains(s.anchorNode)) { savedRange = s.getRangeAt(0).cloneRange(); toolState(); }
  });
  const restore = () => { page.focus(); if (savedRange) { const s = getSelection(); s.removeAllRanges(); s.addRange(savedRange); } };
  const cmd = (c, v) => { if (S.ro) return; restore(); document.execCommand(c, false, v ?? null); markDirty(); toolState(); };
  function blockOf(n) { while (n && n !== page) { if (n.nodeType === 1 && /^(P|H[1-6]|LI|DIV|TD|BLOCKQUOTE)$/.test(n.tagName)) return n; n = n.parentNode; } return null; }
  function toolState() {
    $$('[data-st]').forEach(b => { let on = false; try { on = document.queryCommandState(b.dataset.st); } catch (e) {} b.classList.toggle('on', on); });
    const s = getSelection(); if (!s.rangeCount) return;
    let n = s.anchorNode; if (n && n.nodeType === 3) n = n.parentNode;
    const blk = blockOf(n); const tag = blk ? blk.tagName.toLowerCase() : 'p';
    $('#gdBlock').value = /^h[1-4]$/.test(tag) ? tag : 'p';
    if (n && n.nodeType === 1 && page.contains(n)) {
      const cs = getComputedStyle(n);
      const pt = Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2; if (document.activeElement !== $('#gdSize')) $('#gdSize').value = pt;
      const fam = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(); const opt = [...$('#gdFont').options].find(o => o.value.toLowerCase() === fam.toLowerCase());
      if (opt) $('#gdFont').value = opt.value;
    }
  }
  /** Veľkosť písma v bodoch na výber (execCommand vie iba 1–7 → nahradíme span s pt). */
  function fontSize(pt) {
    if (S.ro) return;
    pt = Math.max(6, Math.min(96, Math.round(pt)));
    restore();
    document.execCommand('styleWithCSS', false, false);
    document.execCommand('fontSize', false, '7');
    page.querySelectorAll('font[size="7"]').forEach(f => { const sp = document.createElement('span'); sp.style.fontSize = pt + 'pt'; sp.append(...f.childNodes); f.replaceWith(sp); });
    page.querySelectorAll('span[style*="xxx-large"]').forEach(sp => { sp.style.fontSize = pt + 'pt'; });
    document.execCommand('styleWithCSS', false, true);
    $('#gdSize').value = pt; markDirty();
  }
  async function insertLink() {
    if (S.ro) return;
    const sel = savedRange ? savedRange.toString() : '';
    const url = await ask('Vložiť odkaz', sel ? 'Odkaz na text „' + sel.slice(0, 60) + '“' : 'Adresa stránky', [['cancel', 'Zrušiť'], ['ok', 'Použiť', 'btn-primary']], 'https://');
    if (!url || url === 'cancel' || url === 'https://') return;
    const href = /^(https?:|mailto:|#)/i.test(url) ? url : 'https://' + url;
    if (sel) cmd('createLink', href); else cmd('insertHTML', '<a href="' + href.replace(/"/g, '%22') + '">' + href.replace(/</g, '&lt;') + '</a>&nbsp;');
  }
  function insertImageFile(file) {
    if (!file || !/^image\//.test(file.type)) return;
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = () => {
      const max = 1600, k = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      const data = c.toDataURL(file.type === 'image/png' ? 'image/png' : 'image/jpeg', 0.85);
      const w = Math.min(624, c.width), h = Math.round(c.height * w / c.width);
      cmd('insertHTML', '<img src="' + data + '" width="' + w + '" height="' + h + '" alt="">');
      URL.revokeObjectURL(url);
    };
    img.src = url;
  }
  $('#gdImg').addEventListener('change', (e) => { insertImageFile(e.target.files[0]); e.target.value = ''; });
  page.addEventListener('paste', (e) => {
    if (S.ro) return;
    const f = [...(e.clipboardData && e.clipboardData.files || [])].find(x => /^image\//.test(x.type));
    if (f) { e.preventDefault(); insertImageFile(f); }
  });
  page.addEventListener('drop', (e) => {
    const f = [...(e.dataTransfer && e.dataTransfer.files || [])].find(x => /^image\//.test(x.type));
    if (f && !S.ro) { e.preventDefault(); insertImageFile(f); }
  });
  // obrázok: klik vyberie, potom sa dá zmenšiť/zväčšiť cez menu kolieskom s Ctrl alebo zmazať klávesom Delete
  page.addEventListener('click', (e) => {
    page.querySelectorAll('img.sel').forEach(i => i !== e.target && i.classList.remove('sel'));
    if (e.target.tagName === 'IMG' && !S.ro) e.target.classList.add('sel');
    const a = e.target.closest('a'); if (a && (e.ctrlKey || e.metaKey)) window.open(a.href, '_blank', 'noopener');
  });
  page.addEventListener('wheel', (e) => {
    const img = page.querySelector('img.sel'); if (!img || !(e.ctrlKey || e.metaKey) || S.ro) return;
    e.preventDefault();
    const w = Math.max(40, Math.min(1000, (img.width || 300) * (e.deltaY < 0 ? 1.1 : 0.9))); img.height = Math.round((img.height || 200) * w / (img.width || 300)); img.width = Math.round(w); markDirty();
  }, { passive: false });

  function wordCount() {
    const t = page.innerText.trim(), words = t ? t.split(/\s+/).length : 0;
    ask('Počet slov', 'Slová: ' + words.toLocaleString('sk') + ' · Znaky: ' + t.replace(/\n/g, '').length.toLocaleString('sk') + ' · Znaky bez medzier: ' + t.replace(/\s/g, '').length.toLocaleString('sk') + ' · Strany (odhad): ' + Math.max(1, Math.ceil(words / 400)), [['ok', 'OK', 'btn-primary']]);
  }
  function outline() {
    const box = $('#gdOutline'); const on = box.classList.toggle('d-none') === false;
    if (!on) return;
    const hs = [...page.querySelectorAll('h1,h2,h3,h4')];
    box.querySelector('nav').innerHTML = hs.length ? hs.map((h, i) => '<a href="#" class="l' + h.tagName[1] + '" data-h="' + i + '">' + (h.textContent.trim() || '(prázdny nadpis)').replace(/</g, '&lt;') + '</a>').join('') : '<div class="small text-body-secondary">Pridaj nadpisy (Formát → Nadpis 1, 2, 3) a zobrazia sa tu.</div>';
    box.querySelectorAll('[data-h]').forEach(a => a.onclick = (e) => { e.preventDefault(); hs[+a.dataset.h].scrollIntoView({ block: 'start', behavior: 'smooth' }); });
  }

  /* nájsť a nahradiť */
  let hits = [], hitI = -1;
  function findAll(q) {
    hits = []; hitI = -1;
    if (!q) { $('#gdFindN').textContent = ''; return; }
    const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT), ql = q.toLowerCase();
    while (w.nextNode()) { const t = w.currentNode.nodeValue.toLowerCase(); let i = -1; while ((i = t.indexOf(ql, i + 1)) >= 0) hits.push([w.currentNode, i]); }
    $('#gdFindN').textContent = hits.length ? hits.length + '×' : 'nič';
  }
  let lastQ = '';
  function findNext() {
    const q = $('#gdFindQ').value; if (q !== lastQ) { hitI = -1; lastQ = q; }
    const prev = hitI; findAll(q); if (!hits.length) return;
    hitI = (prev + 1) % hits.length;
    const [n, i] = hits[hitI], s = getSelection();
    const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + q.length);
    s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange();
    (n.parentElement || page).scrollIntoView({ block: 'center' });
    $('#gdFindN').textContent = (hitI + 1) + ' / ' + hits.length;
  }
  function replaceOne() {
    const q = $('#gdFindQ').value, s = getSelection();
    if (s.rangeCount && s.toString().toLowerCase() === q.toLowerCase() && q) { cmd('insertText', $('#gdFindR').value); hitI--; }
    findNext();
  }
  function replaceAll() {
    const q = $('#gdFindQ').value, rep = $('#gdFindR').value; if (!q || S.ro) return;
    const re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'); let n = 0;
    const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT), nodes = []; while (w.nextNode()) nodes.push(w.currentNode);
    nodes.forEach(t => { const v = t.nodeValue.replace(re, () => { n++; return rep; }); if (v !== t.nodeValue) t.nodeValue = v; });
    toast('Nahradené: ' + n); if (n) markDirty(); findAll(q);
  }
  $('#gdFindQ').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); findNext(); } if (e.key === 'Escape') $('#gdFind').classList.add('d-none'); });
  $('#gdFindQ').addEventListener('input', (e) => findAll(e.target.value));

  const ACT = {
    close, save: () => save(false), print: () => window.print(),
    undo: () => cmd('undo'), redo: () => cmd('redo'),
    selectall: () => { page.focus(); document.execCommand('selectAll'); },
    find: () => { const f = $('#gdFind'); f.classList.toggle('d-none'); if (!f.classList.contains('d-none')) { const sel = getSelection().toString(); if (sel && sel.length < 80) $('#gdFindQ').value = sel; $('#gdFindQ').focus(); $('#gdFindQ').select(); findAll($('#gdFindQ').value); } },
    'find:next': findNext, 'find:one': replaceOne, 'find:all': replaceAll,
    image: () => $('#gdImg').click(), link: insertLink,
    table: () => cmd('insertHTML', '<table><tbody>' + '<tr><td><br></td><td><br></td><td><br></td></tr>'.repeat(3) + '</tbody></table><p><br></p>'),
    hr: () => cmd('insertHTML', '<hr class="page-break"><p><br></p>'),
    date: () => cmd('insertText', new Date().toLocaleDateString('sk-SK')),
    clearfmt: () => { cmd('removeFormat'); cmd('unlink'); },
    landscape: () => { S.landscape = !S.landscape; page.classList.toggle('landscape', S.landscape); markDirty(); },
    count: wordCount, outline,
    keys: () => bootstrap.Modal.getOrCreateInstance($('#gsKeys')).show(),
  };
  document.addEventListener('mousedown', (e) => { if (e.target.closest('.gd-tools button, .gd-tools label')) e.preventDefault(); }); // výber v texte ostane
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-gs]'); if (!b || b.tagName === 'SELECT') return;
    e.preventDefault();
    const a = b.dataset.gs;
    if (!S.ready && a !== 'close') return;
    if (S.ro && (b.hasAttribute('data-rw') || /^(cmd|block|size|char):|^(image|link|table|hr|date|clearfmt|undo|redo)$/.test(a))) return;
    if (a.startsWith('dl:')) return download(a.slice(3));
    if (a.startsWith('cmd:')) return cmd(a.slice(4));
    if (a.startsWith('block:')) return cmd('formatBlock', '<' + a.slice(6) + '>');
    if (a.startsWith('char:')) return cmd('insertText', a.slice(5));
    if (a.startsWith('size:')) return fontSize((parseFloat($('#gdSize').value) || 11) + +a.slice(5));
    ACT[a] && ACT[a]();
  });
  $('#gdBlock').addEventListener('change', (e) => cmd('formatBlock', '<' + e.target.value + '>'));
  $('#gdFont').addEventListener('change', (e) => cmd('fontName', e.target.value));
  $('#gdSize').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); fontSize(parseFloat(e.target.value) || 11); } });
  $('#gdZoom').addEventListener('change', (e) => { $('#gdZoomBox').style.zoom = e.target.value; });
  document.querySelectorAll('[data-color]').forEach(inp => inp.addEventListener('input', () => cmd(inp.dataset.color, inp.value)));
  document.addEventListener('keydown', (e) => {
    const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && k === 's') { e.preventDefault(); save(false); }
    else if (ctrl && k === 'p') { e.preventDefault(); window.print(); }
    else if (ctrl && k === 'h') { e.preventDefault(); ACT.find(); }
    else if (ctrl && k === 'k' && !S.ro) { e.preventDefault(); insertLink(); }
    else if (ctrl && e.key === '\\' && !S.ro) { e.preventDefault(); ACT.clearfmt(); }
    else if (ctrl && e.key === 'Enter' && !S.ro && page.contains(document.activeElement)) { e.preventDefault(); ACT.hr(); }
    else if (ctrl && e.altKey && /^[0-4]$/.test(e.key) && !S.ro) { e.preventDefault(); cmd('formatBlock', e.key === '0' ? '<p>' : '<h' + e.key + '>'); }
    else if (ctrl && e.shiftKey && (e.code === 'Digit7' || e.code === 'Digit8') && !S.ro) { e.preventDefault(); cmd(e.code === 'Digit7' ? 'insertOrderedList' : 'insertUnorderedList'); }
    else if (e.key === 'Tab' && !S.ro && page.contains(getSelection().anchorNode)) {
      e.preventDefault();
      const li = blockOf(getSelection().anchorNode && getSelection().anchorNode.nodeType === 3 ? getSelection().anchorNode.parentNode : getSelection().anchorNode);
      if (li && li.tagName === 'LI') cmd(e.shiftKey ? 'outdent' : 'indent'); else cmd('insertText', ' ');
    }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && page.querySelector('img.sel') && !S.ro) { e.preventDefault(); page.querySelector('img.sel').remove(); markDirty(); }
  }, true);

  open();
})();
