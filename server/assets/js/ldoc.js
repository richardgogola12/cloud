/* Editor dokumentov (doc.php) v štýle Google Dokumentov – jadro: otvorenie, ukladanie, ponuky, formát textu
   a odsekov, zoznamy, hľadanie, klávesové skratky. Strany a tlač sú v ldoc-page.js, ďalšie nástroje v ldoc-more.js. */
(function () {
  const C = window.CLOUD_DOC; if (!C) return;
  const IO = window.CloudDocIO;
  const $ = (s) => document.querySelector(s), $$ = (s) => [...document.querySelectorAll(s)];
  const base = document.querySelector('meta[name="base-url"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const inFrame = window.parent && window.parent !== window;
  const page = $('#gdPage');
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  // nastavenia editora sa ukladajú na server (Môj účet), nie do zariadenia
  const P0 = C.prefs && typeof C.prefs === 'object' && !Array.isArray(C.prefs) ? C.prefs : {};
  const store = { get(k, d) { return Object.prototype.hasOwnProperty.call(P0, k) ? P0[k] : d; }, set(k, v) { if (JSON.stringify(P0[k]) === JSON.stringify(v)) return; P0[k] = v; clearTimeout(store.t); store.t = setTimeout(() => { api('prefs_save', { key: 'editor', ns: 'doc', value: JSON.stringify(P0) }).catch(() => {}); }, 1200); } };
  const PAGE0 = { w: 210, h: 297, landscape: false, mt: 2.54, mb: 2.54, ml: 2.54, mr: 2.54, hd: 1.27, fd: 1.27, color: null, cols: 1, diffFirst: false };
  const S = {
    ro: !C.canEdit, dirty: false, saving: false, again: false, updated: C.updated, id: C.id, name: C.name, ready: false, t: null,
    mode: 'edit', page: Object.assign({}, PAGE0), header: {}, footer: {}, comments: {}, zoom: 1,
    view: Object.assign({ ruler: true, outline: false, marks: false, comments: false, wcbox: false, spell: true, pageless: false }, store.get('view', {})),
    prefs: Object.assign({ quotes: true, links: true, lists: true, caps: true, subst: true }, store.get('prefs', {})),
  };
  window.CloudDocEditor = S;
  const handlers = {};
  const GD = window.GD = {
    C, S, page, $, $$, esc, store, IO, PAGE0, act: {}, exporters: {},
    on(ev, fn) { (handlers[ev] = handlers[ev] || []).push(fn); },
    emit(ev, ...a) { let r; (handlers[ev] || []).forEach(fn => { const x = fn(...a); if (x !== undefined) r = x; }); return r; },
  };

  const post = (m) => { if (inFrame) try { parent.postMessage(Object.assign({ cloudSheet: m.ev }, m), location.origin); } catch (e) {} };
  GD.post = post;
  function status(t, cls) { const e = $('#gsStatus'); e.textContent = t; e.className = 'gs-status ' + (cls || ''); }
  function toast(msg, type, action) {
    let w = $('#gsToasts'); if (!w) { w = document.createElement('div'); w.id = 'gsToasts'; document.body.appendChild(w); }
    const d = document.createElement('div'); d.className = 'gs-toast ' + (type || ''); d.textContent = msg;
    if (action) { const b = document.createElement('button'); b.textContent = action[0]; b.onclick = action[1]; d.appendChild(b); d.style.pointerEvents = 'auto'; }
    w.appendChild(d);
    setTimeout(() => d.classList.add('out'), action ? 6500 : 3500); setTimeout(() => d.remove(), action ? 7000 : 4000);
  }
  /** Okno s otázkou. buttons: [[id, text, trieda]]; input → vráti text poľa pri „ok“. */
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
  /** Okno s vlastným obsahom. onOk(root) → false = nezatvárať. Vráti Promise(true/false). */
  function dlg(title, html, opt) {
    opt = opt || {};
    return new Promise((res) => {
      const el = document.createElement('div'); el.className = 'modal fade'; el.tabIndex = -1;
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered ' + (opt.size || '') + (opt.scroll ? ' modal-dialog-scrollable' : '') + '"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">' + html + '</div>' +
        (opt.noFooter ? '' : '<div class="modal-footer"><button class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-3" data-ok>' + (opt.ok || 'Použiť') + '</button></div>') + '</div></div>';
      el.querySelector('.modal-title').textContent = title;
      document.body.appendChild(el);
      const m = new bootstrap.Modal(el, { backdrop: opt.static ? 'static' : true });
      let ok = false;
      const okb = el.querySelector('[data-ok]');
      if (okb) okb.onclick = async () => { if (opt.onOk && (await opt.onOk(el)) === false) return; ok = true; m.hide(); };
      el.addEventListener('keydown', (e) => { if (e.key === 'Enter' && okb && e.target.tagName === 'INPUT' && e.target.type !== 'color') { e.preventDefault(); okb.click(); } });
      el.addEventListener('shown.bs.modal', () => { const f = el.querySelector('[autofocus], .modal-body input:not([type=checkbox]):not([type=radio]):not([type=color]), .modal-body select'); f && f.focus(); opt.onShow && opt.onShow(el); });
      el.addEventListener('hidden.bs.modal', () => { opt.onHide && opt.onHide(el); el.remove(); res(ok); });
      opt.init && opt.init(el, m);
      m.show();
    });
  }
  async function api(action, data) {
    const fd = new FormData();
    Object.entries(data || {}).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x)); else fd.append(k, v ?? ''); });
    const res = await fetch(base + 'api.php?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    return res.json().catch(() => ({ ok: false, error: 'Neočakávaná odpoveď servera (' + res.status + ')' }));
  }
  Object.assign(GD, { status, toast, ask, dlg, api, base });

  /* ---------- otvorenie ---------- */
  async function open() {
    try {
      const res = await fetch(C.url, { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) throw new Error('Súbor sa nepodarilo načítať (' + res.status + ').');
      const buf = await res.arrayBuffer();
      const d = buf.byteLength ? await IO.fromDocx(buf) : { html: '<p><br></p>', warn: [], page: {}, header: {}, footer: {}, comments: {} };
      if (d.warn.length && !S.ro) {
        const r = await ask('Dokument obsahuje: ' + d.warn.join(', '), 'Tieto časti editor nezobrazí a pri uložení by sa z dokumentu stratili (pôvodná verzia by ostala vo Verziách). Odporúčam otvoriť iba na čítanie.',
          [['edit', 'Upravovať aj tak'], ['ro', 'Iba na čítanie', 'btn-primary']]);
        S.ro = r !== 'edit';
      }
      page.innerHTML = d.html;
      Object.assign(S.page, d.page || {});
      S.header = d.header || {}; S.footer = d.footer || {}; S.comments = d.comments || {};
      document.body.classList.toggle('ro', S.ro);
      $('#gsRo').classList.toggle('d-none', !S.ro);
      try { document.execCommand('styleWithCSS', false, true); document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
      setMode(S.ro ? 'view' : 'edit', true);
      applyView();
      GD.emit('open', d);
      $('#gsLoad').classList.add('d-none');
      S.ready = true;
      status(S.ro ? '' : 'Zmeny sa ukladajú samy');
      post({ ev: 'ready' });
      if (!S.ro) { page.focus(); placeCaret(page.firstElementChild || page); }
      if (!buf.byteLength && !S.ro) markDirty(); // nový prázdny súbor → hneď platný .docx
    } catch (e) {
      console.error(e);
      $('#gsLoad').innerHTML = '<i class="bi bi-exclamation-triangle fs-1 text-danger mb-2"></i><div>' + esc(e.message || 'Dokument sa nepodarilo otvoriť.') + '</div>';
    }
  }
  function placeCaret(el, end) { try { const r = document.createRange(); r.selectNodeContents(el); r.collapse(!end); const s = getSelection(); s.removeAllRanges(); s.addRange(r); savedRange = r.cloneRange(); } catch (e) {} }
  GD.placeCaret = placeCaret;

  /* ---------- ukladanie ---------- */
  function markDirty() {
    if (!S.ready || S.ro) return;
    S.dirty = true; status('Neuložené zmeny…', 'warn');
    clearTimeout(S.t); S.t = setTimeout(() => save(true), 2500);
    GD.emit('change');
  }
  GD.markDirty = markDirty;
  page.addEventListener('input', markDirty);
  /** Kópia obsahu bez pomôcok editora (zvýraznenia, odsadenia strán…). */
  function cleanClone() {
    const c = page.cloneNode(true);
    c.querySelectorAll('mark.gd-hit').forEach(m => m.replaceWith(...m.childNodes));
    c.querySelectorAll('.sel').forEach(x => x.classList.remove('sel'));
    c.querySelectorAll('[contenteditable="true"]').forEach(x => x.removeAttribute('contenteditable'));
    GD.emit('clean', c);
    return c;
  }
  GD.cleanClone = cleanClone;
  const docOpts = () => ({ title: S.name.replace(/\.docx$/i, ''), page: S.page, header: S.header, footer: S.footer, comments: Object.values(S.comments) });
  async function save(auto) {
    if (S.ro || !S.ready) return true;
    if (S.saving) { S.again = true; return false; }
    if (auto && !S.dirty) return true;
    clearTimeout(S.t); S.saving = true; S.dirty = false; status('Ukladá sa…', 'busy');
    try {
      const blob = await IO.toDocx(cleanClone(), docOpts());
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
      console.error(e);
      S.dirty = true; status(e.message || 'Uloženie zlyhalo', 'err');
      if (!auto) toast(e.message || 'Uloženie zlyhalo', 'err');
      return false;
    } finally {
      S.saving = false;
      if (S.again) { S.again = false; if (S.dirty) { clearTimeout(S.t); S.t = setTimeout(() => save(true), 300); } }
    }
  }
  GD.save = save;
  function saveBlob(blob, nm) {
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nm; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }
  GD.saveBlob = saveBlob;
  async function download(type) {
    if (type === 'pdf') { toast('V okne tlače vyber „Uložiť ako PDF“'); return GD.act.print(); }
    const nm = S.name.replace(/\.docx$/i, '') + '.' + type;
    try {
      let blob;
      if (type === 'docx') blob = await IO.toDocx(cleanClone(), docOpts());
      else if (type === 'txt') blob = new Blob([GD.plainText(cleanClone())], { type: 'text/plain;charset=utf-8' });
      else if (GD.exporters[type]) blob = await GD.exporters[type](cleanClone(), docOpts());
      if (blob) saveBlob(blob, nm);
    } catch (e) { console.error(e); toast('Stiahnutie zlyhalo: ' + (e.message || e), 'err'); }
  }
  /** Obyčajný text s odsekmi a zoznamami. */
  GD.plainText = (root) => {
    const out = [];
    const walk = (el, pre) => {
      for (const n of el.children) {
        if (/^(UL|OL)$/.test(n.tagName)) { let i = 0; for (const li of n.children) { i++; const c = li.cloneNode(true); c.querySelectorAll('ul,ol').forEach(x => x.remove()); out.push(pre + (n.classList.contains('gd-check') ? (li.dataset.ck === '1' ? '☑ ' : '☐ ') : n.tagName === 'OL' ? i + '. ' : '• ') + c.innerText.trim()); li.querySelectorAll(':scope > ul, :scope > ol').forEach(s => walk({ children: [s] }, pre + '    ')); } }
        else if (n.tagName === 'TABLE') n.querySelectorAll('tr').forEach(tr => out.push([...tr.children].map(td => td.innerText.trim().replace(/\s+/g, ' ')).join('\t')));
        else if (n.tagName === 'HR') out.push(n.classList.contains('gd-hr') ? '────────' : '');
        else out.push((n.innerText !== undefined ? n.innerText : n.textContent).replace(/\n$/, ''));
      }
    };
    const tmp = document.createElement('div'); tmp.style.cssText = 'position:fixed;left:-9999px;top:0;width:800px'; tmp.appendChild(root); document.body.appendChild(tmp);
    root.querySelectorAll('sup.gd-fn').forEach((s, i) => { s.textContent = '[' + (i + 1) + ']'; });
    walk(root, ''); tmp.remove();
    const fns = [...root.querySelectorAll('sup.gd-fn')].map((s, i) => '[' + (i + 1) + '] ' + s.dataset.fn);
    return out.join('\n') + (fns.length ? '\n\n' + fns.join('\n') : '') + '\n';
  };
  async function close() {
    if (S.dirty || S.saving) {
      status('Ukladá sa…', 'busy');
      for (let i = 0; i < 50 && S.saving; i++) await new Promise(r => setTimeout(r, 100));
      if (S.dirty && !(await save(false))) {
        const r = await ask('Zmeny sa nepodarilo uložiť', 'Ak teraz zavrieš dokument, posledné zmeny sa stratia. Môžeš si ich stiahnuť cez Súbor → Stiahnuť.', [['stay', 'Ostať', 'btn-primary'], ['leave', 'Zavrieť bez uloženia']]);
        if (r !== 'leave') { post({ ev: 'stay' }); return; }
      }
    }
    S.dirty = false;
    if (inFrame) post({ ev: 'close' }); else location.href = C.drive;
  }
  GD.close = close;
  window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.cloudSheetCmd === 'close') close(); });
  window.addEventListener('beforeunload', (e) => { if (S.dirty && !inFrame) { e.preventDefault(); e.returnValue = ''; } });
  window.cloudBack = () => { if ($('.modal.show')) { bootstrap.Modal.getInstance($('.modal.show'))?.hide(); return true; } close(); return true; };

  /* ---------- výber ---------- */
  let savedRange = null;
  document.addEventListener('selectionchange', () => {
    const s = getSelection(); if (s.rangeCount && page.contains(s.anchorNode)) { savedRange = s.getRangeAt(0).cloneRange(); toolState(); GD.emit('sel', savedRange); }
  });
  const restore = () => { if (GD.altEdit) { if (!GD.altEdit.contains(getSelection().anchorNode)) { GD.altEdit.focus(); GD.placeCaretIn && GD.placeCaretIn(GD.altEdit); } return; } if (document.activeElement !== page) page.focus({ preventScroll: true }); if (savedRange && page.contains(savedRange.startContainer)) { const s = getSelection(); s.removeAllRanges(); s.addRange(savedRange); } };
  GD.restore = restore;
  GD.range = () => { const s = getSelection(); if (s.rangeCount && page.contains(s.anchorNode)) return s.getRangeAt(0); return savedRange; };
  const canEdit = () => !S.ro && S.mode !== 'view';
  GD.canEdit = canEdit;
  const cmd = (c, v) => { if (!canEdit()) return; restore(); if (GD.emit('cmd', c, v) === false) return; document.execCommand(c, false, v ?? null); markDirty(); toolState(); };
  GD.cmd = cmd;
  const BLK = /^(P|H[1-6]|LI|BLOCKQUOTE|PRE)$/;
  function blockOf(n) { while (n && n !== page) { if (n.nodeType === 1 && BLK.test(n.tagName)) return n; if (n.nodeType === 1 && n.tagName === 'DIV' && n.parentNode === page) return n; n = n.parentNode; } return null; }
  GD.blockOf = blockOf;
  /** Odseky, ktorých sa týka výber. */
  function selBlocks() {
    const r = GD.range(); if (!r) return [];
    if (r.collapsed) { const b = blockOf(r.startContainer); return b ? [b] : []; }
    const all = [...page.querySelectorAll('p,h1,h2,h3,h4,h5,h6,li,blockquote,pre')].filter(el => r.intersectsNode(el));
    const out = all.filter(el => el.tagName === 'LI' || !all.some(o => o !== el && el.contains(o)));
    if (!out.length) { const b = blockOf(r.startContainer); if (b) out.push(b); }
    return out;
  }
  GD.selBlocks = selBlocks;

  /* ---------- formát ---------- */
  const STYLE_TAG = { p: 'p', title: 'p', subtitle: 'p', quote: 'blockquote', code: 'pre' };
  function setBlock(kind) {
    if (!canEdit()) return;
    restore();
    const tag = STYLE_TAG[kind] || kind;
    document.execCommand('formatBlock', false, '<' + tag + '>');
    selBlocks().forEach(b => { b.classList.remove('gd-title', 'gd-subtitle'); if (kind === 'title' || kind === 'subtitle') b.classList.add('gd-' + kind); if (!b.className) b.removeAttribute('class'); });
    markDirty(); toolState(); GD.emit('structure');
  }
  GD.setBlock = setBlock;
  function fontSize(pt) {
    if (!canEdit()) return;
    pt = Math.max(1, Math.min(400, Math.round(pt * 2) / 2));
    restore();
    const r = GD.range();
    if (r && r.collapsed) { // bez výberu: ďalší písaný text bude v novej veľkosti
      const sp = document.createElement('span'); sp.style.fontSize = pt + 'pt'; sp.textContent = '​';
      r.insertNode(sp); const nr = document.createRange(); nr.setStart(sp.firstChild, 1); nr.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(nr);
    } else {
      document.execCommand('styleWithCSS', false, false);
      document.execCommand('fontSize', false, '7');
      page.querySelectorAll('font[size="7"]').forEach(f => { const sp = document.createElement('span'); sp.style.fontSize = pt + 'pt'; sp.append(...f.childNodes); f.replaceWith(sp); sp.querySelectorAll('[style*="font-size"]').forEach(x => x.style.fontSize = ''); });
      page.querySelectorAll('span[style*="xxx-large"]').forEach(sp => { sp.style.fontSize = pt + 'pt'; });
      document.execCommand('styleWithCSS', false, true);
    }
    $('#gdSize').value = String(pt).replace('.', ','); markDirty();
  }
  GD.fontSize = fontSize;
  const curSize = () => parseFloat(String($('#gdSize').value).replace(',', '.')) || 11;
  const SIZES = [6, 7, 8, 9, 10, 11, 12, 14, 16, 18, 20, 22, 24, 28, 32, 36, 40, 48, 60, 72, 96];
  const stepSize = (d) => { const c = curSize(); const n = d > 0 ? (SIZES.find(s => s > c) || c + 12) : ([...SIZES].reverse().find(s => s < c) || Math.max(1, c - 1)); fontSize(n); };

  function setColor(kind, c) {
    if (!canEdit()) return;
    if (kind === 'fore') { cmd('foreColor', c || '#000000'); $('#gdFc').style.background = c || '#000'; }
    else { cmd('hiliteColor', c || 'transparent'); $('#gdBc').style.background = c || 'transparent'; }
  }
  GMenu.palette($('#gdFcPal'), (c) => setColor('fore', c), '<i class="bi bi-arrow-counterclockwise"></i> Obnoviť');
  GMenu.palette($('#gdBcPal'), (c) => setColor('back', c), '<i class="bi bi-slash-circle"></i> Žiadna');

  /* zoznamy */
  function listOf(n) { while (n && n !== page) { if (n.nodeType === 1 && (n.tagName === 'UL' || n.tagName === 'OL')) return n; n = n.parentNode; } return null; }
  GD.listOf = listOf;
  function styleList(l, kind, style) {
    if (!l) return;
    l.classList.remove('gd-check', 'ls-disc', 'ls-dash', 'ls-arrow', 'ls-star', 'ls-square', 'ls-circle');
    l.style.listStyleType = '';
    if (kind === 'check') l.classList.add('gd-check');
    else if (kind === 'ol') { if (style && style !== 'decimal') l.style.listStyleType = style; }
    else if (style && style !== 'disc') l.classList.add('ls-' + style);
    if (!l.className) l.removeAttribute('class'); if (!l.getAttribute('style')) l.removeAttribute('style');
  }
  function list(kind, style) {
    if (!canEdit()) return;
    restore();
    const r = GD.range(); const cur = r ? listOf(r.startContainer) : null;
    const tag = kind === 'ol' ? 'OL' : 'UL';
    const curKind = cur ? (cur.tagName === 'OL' ? 'ol' : cur.classList.contains('gd-check') ? 'check' : 'ul') : null;
    const curStyle = cur ? (cur.tagName === 'OL' ? (cur.style.listStyleType || 'decimal') : ([...cur.classList].find(c => /^ls-/.test(c)) || 'ls-disc').slice(3)) : null;
    if (cur && cur.tagName === tag && curKind === kind && (kind === 'check' || curStyle === (style || (kind === 'ol' ? 'decimal' : 'disc')))) {
      document.execCommand(tag === 'OL' ? 'insertOrderedList' : 'insertUnorderedList'); // vypnúť
    } else if (cur && cur.tagName === tag) styleList(cur, kind, style);
    else {
      document.execCommand(tag === 'OL' ? 'insertOrderedList' : 'insertUnorderedList');
      const nr = GD.range(); styleList(nr ? listOf(nr.startContainer) : null, kind, style);
    }
    markDirty(); toolState(); GD.emit('structure');
  }
  GD.list = list;
  function indent(dir) {
    if (!canEdit()) return;
    restore();
    const bl = selBlocks();
    if (bl.some(b => b.tagName === 'LI')) { document.execCommand(dir > 0 ? 'indent' : 'outdent'); }
    else bl.forEach(b => { const v = Math.max(0, (parseFloat(b.style.marginLeft) || 0) + dir * 48); b.style.marginLeft = v ? v + 'px' : ''; });
    markDirty(); GD.emit('structure');
  }
  GD.indent = indent;
  function lineHeight(v) { if (!canEdit()) return; selBlocks().forEach(b => { b.style.lineHeight = v; }); markDirty(); GD.emit('structure'); }
  function spaceToggle(side) {
    if (!canEdit()) return;
    selBlocks().forEach(b => { const prop = side === 'before' ? 'marginTop' : 'marginBottom'; const cur = parseFloat(getComputedStyle(b)[prop]) || 0; b.style[prop] = cur > 0.5 ? '0pt' : (side === 'before' ? '10pt' : '8pt'); });
    markDirty(); GD.emit('structure');
  }
  async function spacingDialog() {
    const b = selBlocks()[0]; if (!b || !canEdit()) return;
    const cs = getComputedStyle(b), lh = b.style.lineHeight || '1.15';
    const pt = (v) => Math.round(parseFloat(v) * 0.75 * 10) / 10;
    let res = null;
    await dlg('Vlastné medzery', '<div class="row g-3"><div class="col-12"><label class="form-label">Riadkovanie</label><input class="form-control" id="spL" type="number" step="0.05" min="0.5" max="5" value="' + esc(lh) + '"></div>' +
      '<div class="col-6"><label class="form-label">Pred odsekom (pt)</label><input class="form-control" id="spB" type="number" step="1" min="0" value="' + pt(cs.marginTop) + '"></div><div class="col-6"><label class="form-label">Za odsekom (pt)</label><input class="form-control" id="spA" type="number" step="1" min="0" value="' + pt(cs.marginBottom) + '"></div></div>',
    { onOk: (el) => { res = { l: el.querySelector('#spL').value, b: el.querySelector('#spB').value, a: el.querySelector('#spA').value }; } });
    if (!res) return;
    selBlocks().forEach(x => { if (+res.l > 0) x.style.lineHeight = res.l; x.style.marginTop = (+res.b || 0) + 'pt'; x.style.marginBottom = (+res.a || 0) + 'pt'; });
    markDirty(); GD.emit('structure');
  }
  async function indentDialog() {
    const b = selBlocks()[0]; if (!b || !canEdit()) return;
    const cm = (px) => Math.round((parseFloat(px) || 0) / 37.795 * 100) / 100;
    const ti = parseFloat(b.style.textIndent) || 0;
    let res = null;
    await dlg('Možnosti odsadenia', '<div class="row g-3"><div class="col-6"><label class="form-label">Vľavo (cm)</label><input class="form-control" id="inL" type="number" step="0.1" min="0" value="' + cm(b.style.marginLeft) + '"></div><div class="col-6"><label class="form-label">Vpravo (cm)</label><input class="form-control" id="inR" type="number" step="0.1" min="0" value="' + cm(b.style.marginRight) + '"></div>' +
      '<div class="col-6"><label class="form-label">Špeciálne odsadenie</label><select class="form-select" id="inS"><option value="none">Žiadne</option><option value="first"' + (ti > 0 ? ' selected' : '') + '>Prvý riadok</option><option value="hang"' + (ti < 0 ? ' selected' : '') + '>Predsadenie</option></select></div><div class="col-6"><label class="form-label">o (cm)</label><input class="form-control" id="inV" type="number" step="0.1" min="0" value="' + (cm(Math.abs(ti)) || 1.27) + '"></div></div>',
    { onOk: (el) => { res = { l: +el.querySelector('#inL').value, r: +el.querySelector('#inR').value, s: el.querySelector('#inS').value, v: +el.querySelector('#inV').value }; } });
    if (!res) return;
    const px = (c) => Math.round(c * 37.795);
    selBlocks().forEach(x => {
      x.style.marginLeft = res.l > 0 ? px(res.l + (res.s === 'hang' ? res.v : 0)) + 'px' : (res.s === 'hang' ? px(res.v) + 'px' : '');
      x.style.marginRight = res.r > 0 ? px(res.r) + 'px' : '';
      x.style.textIndent = res.s === 'first' ? px(res.v) + 'px' : res.s === 'hang' ? -px(res.v) + 'px' : '';
    });
    markDirty(); GD.emit('structure');
  }
  GD.indentDialog = indentDialog;

  /* veľké / malé písmená */
  function changeCase(kind) {
    if (!canEdit()) return;
    restore();
    const r = GD.range(); if (!r || r.collapsed) return toast('Najprv označ text');
    const w = document.createTreeWalker(r.commonAncestorContainer.nodeType === 3 ? r.commonAncestorContainer.parentNode : r.commonAncestorContainer, NodeFilter.SHOW_TEXT);
    const nodes = []; while (w.nextNode()) if (r.intersectsNode(w.currentNode)) nodes.push(w.currentNode);
    let prev = ' ';
    const sr = r.cloneRange();
    nodes.forEach(t => {
      const a = t === r.startContainer ? r.startOffset : 0, b = t === r.endContainer ? r.endOffset : t.nodeValue.length;
      const part = t.nodeValue.slice(a, b);
      let x;
      if (kind === 'lower') x = part.toLocaleLowerCase('sk');
      else if (kind === 'upper') x = part.toLocaleUpperCase('sk');
      else x = [...part].map(ch => { const out = /[\s„“"(\-–—/]/.test(prev) ? ch.toLocaleUpperCase('sk') : ch.toLocaleLowerCase('sk'); prev = ch; return out; }).join('');
      if (kind !== 'title') prev = part.slice(-1) || prev;
      t.nodeValue = t.nodeValue.slice(0, a) + x + t.nodeValue.slice(b);
    });
    try { const s = getSelection(); s.removeAllRanges(); s.addRange(sr); } catch (e) {}
    markDirty();
  }

  /* kopírovať formát */
  function captureFormat() {
    const r = GD.range(); if (!r) return null;
    let n = r.startContainer; if (n.nodeType === 3) n = n.parentNode;
    if (!page.contains(n)) return null;
    const cs = getComputedStyle(n);
    let bg = null; for (let e = n; e && e !== page; e = e.parentElement) { const b = getComputedStyle(e).backgroundColor; if (b && !/rgba\(0, 0, 0, 0\)|transparent/.test(b)) { bg = b; break; } if (BLK.test(e.tagName)) break; }
    return { bold: +cs.fontWeight >= 600, italic: cs.fontStyle === 'italic', underline: /underline/.test(cs.textDecorationLine), strike: /line-through/.test(cs.textDecorationLine), color: cs.color, bg, size: Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2, font: cs.fontFamily.split(',')[0].replace(/["']/g, '').trim(), sup: !!n.closest('sup'), sub: !!n.closest('sub') };
  }
  function applyFormat(f) {
    if (!f || !canEdit()) return;
    restore();
    document.execCommand('removeFormat');
    const set = (c, want) => { let on = false; try { on = document.queryCommandState(c); } catch (e) {} if (on !== want) document.execCommand(c); };
    set('bold', f.bold); set('italic', f.italic); set('underline', f.underline); set('strikeThrough', f.strike); set('superscript', f.sup); set('subscript', f.sub);
    document.execCommand('foreColor', false, f.color);
    if (f.bg) document.execCommand('hiliteColor', false, f.bg);
    document.execCommand('fontName', false, f.font);
    fontSize(f.size);
    markDirty(); toolState();
  }
  S.paint = null;
  function paintStart(sticky) {
    if (S.paint) { S.paint = null; document.body.classList.remove('painting'); toolState(); return; }
    const f = captureFormat(); if (!f) return;
    S.paint = { f, sticky }; document.body.classList.add('painting'); toolState();
  }
  page.addEventListener('mouseup', () => {
    if (!S.paint) return;
    setTimeout(() => {
      const r = GD.range(); if (!r || r.collapsed) return;
      applyFormat(S.paint.f);
      if (!S.paint.sticky) { S.paint = null; document.body.classList.remove('painting'); toolState(); }
    }, 0);
  });
  let fmtClip = null;

  /* ---------- stav panela nástrojov ---------- */
  const BLOCK_NAMES = { p: 'Normálny text', title: 'Názov', subtitle: 'Podnadpis', h1: 'Nadpis 1', h2: 'Nadpis 2', h3: 'Nadpis 3', h4: 'Nadpis 4', h5: 'Nadpis 5', h6: 'Nadpis 6', quote: 'Citát', code: 'Kód' };
  function blockKind(b) { if (!b) return 'p'; const t = b.tagName.toLowerCase(); if (t === 'p') return b.classList.contains('gd-title') ? 'title' : b.classList.contains('gd-subtitle') ? 'subtitle' : 'p'; return t === 'blockquote' ? 'quote' : t === 'pre' ? 'code' : /^h[1-6]$/.test(t) ? t : 'p'; }
  GD.blockKind = blockKind;
  function toolState() {
    $$('[data-st]').forEach(b => { let on = false; try { on = document.queryCommandState(b.dataset.st); } catch (e) {} b.classList.toggle('on', on); });
    $$('[data-st2]').forEach(b => b.classList.toggle('on', b.dataset.st2 === 'spell' ? S.view.spell : b.dataset.st2 === 'paint' ? !!S.paint : false));
    const r = GD.range(); if (!r) return;
    let n = r.startContainer; if (n && n.nodeType === 3) n = n.parentNode;
    const blk = blockOf(n);
    $('#gdBlockL').textContent = BLOCK_NAMES[blockKind(blk)];
    if (n && n.nodeType === 1 && page.contains(n)) {
      const cs = getComputedStyle(n);
      const pt = Math.round(parseFloat(cs.fontSize) * 0.75 * 2) / 2; if (document.activeElement !== $('#gdSize')) $('#gdSize').value = String(pt).replace('.', ',');
      $('#gdFontL').textContent = cs.fontFamily.split(',')[0].replace(/["']/g, '').trim();
      $('#gdFc').style.background = cs.color;
      const al = blk ? getComputedStyle(blk).textAlign : 'left';
      $('#gdAlignI').className = 'bi bi-' + ({ center: 'text-center', right: 'text-right', end: 'text-right', justify: 'justify' }[al] || 'text-left');
    }
  }
  GD.toolState = toolState;
  /* zaškrtnutia v ponukách */
  GMenu.onOpen = (menu) => {
    const b = selBlocks()[0], lh = b ? (b.style.lineHeight || '1.15') : '1.15';
    const fam = $('#gdFontL').textContent;
    menu.querySelectorAll('[data-chk]').forEach(x => {
      const k = x.dataset.chk; let on = false;
      if (k.startsWith('mode:')) on = S.mode === k.slice(5);
      else if (k.startsWith('lh:')) on = String(parseFloat(lh)) === k.slice(3);
      else if (k.startsWith('zoom:')) on = Math.abs(S.zoom - +k.slice(5)) < 0.001;
      else if (k.startsWith('font:')) on = fam.toLowerCase() === k.slice(5).toLowerCase();
      else if (k.startsWith('cols:')) on = (S.page.cols || 1) === +k.slice(5);
      else if (k in S.view) on = !!S.view[k];
      else on = !!GD.emit('chk', k);
      x.classList.toggle('on', on);
    });
    GD.emit('menu', menu);
  };

  /* ---------- režim, zobrazenie ---------- */
  function setMode(m, quiet) {
    if (S.ro && m !== 'view') m = 'view';
    S.mode = m;
    page.contentEditable = m === 'view' ? 'false' : 'true';
    document.body.classList.toggle('mode-view', m === 'view');
    document.body.classList.toggle('mode-suggest', m === 'suggest');
    $('#gdModeL').textContent = { edit: 'Úpravy', suggest: 'Navrhovanie', view: 'Zobrazenie' }[m];
    $('#gdModeI').className = 'bi bi-' + { edit: 'pencil', suggest: 'pencil-fill', view: 'eye' }[m];
    if (!quiet) { toast({ edit: 'Režim úprav', suggest: 'Navrhovanie – tvoje úpravy budú návrhy, ktoré sa dajú prijať alebo odmietnuť', view: 'Režim zobrazenia – dokument sa nedá meniť' }[m]); GD.emit('mode', m); }
  }
  GD.setMode = setMode;
  function applyView() {
    const v = S.view;
    document.body.classList.toggle('no-ruler', !v.ruler);
    document.body.classList.toggle('marks', !!v.marks);
    $('#gdWc').classList.toggle('d-none', !v.wcbox);
    page.spellcheck = !!v.spell;
    store.set('view', { ruler: v.ruler, marks: v.marks, wcbox: v.wcbox, spell: v.spell, pageless: v.pageless, outline: v.outline });
    GD.emit('view');
    toolState();
  }
  GD.applyView = applyView;
  const toggleView = (k) => { S.view[k] = !S.view[k]; applyView(); };

  /* ---------- hľadanie a nahrádzanie ---------- */
  let hits = [], hitI = -1;
  const HL = window.CSS && CSS.highlights && window.Highlight;
  function findAll() {
    const q = $('#gdFindQ').value; hits = [];
    if (HL) { CSS.highlights.delete('gd-find'); CSS.highlights.delete('gd-find-cur'); }
    if (!q) { $('#gdFindN').textContent = ''; return; }
    let re;
    try { re = $('#gdFindRe').checked ? new RegExp(q, 'g' + ($('#gdFindCase').checked ? '' : 'i')) : new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g' + ($('#gdFindCase').checked ? '' : 'i')); }
    catch (e) { $('#gdFindN').textContent = 'chybný výraz'; return; }
    const w = document.createTreeWalker(page, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) { const t = w.currentNode; if (t.parentNode.closest('del.gd-del, .gd-toc')) continue; re.lastIndex = 0; let m; while ((m = re.exec(t.nodeValue))) { if (!m[0].length) { re.lastIndex++; continue; } const r = document.createRange(); r.setStart(t, m.index); r.setEnd(t, m.index + m[0].length); hits.push(r); } }
    if (HL && hits.length) CSS.highlights.set('gd-find', new Highlight(...hits));
    $('#gdFindN').textContent = hits.length ? (hitI >= 0 && hitI < hits.length ? (hitI + 1) + ' z ' + hits.length : hits.length + '×') : 'nič';
  }
  function findGo(dir) {
    const prevI = hitI; findAll(); if (!hits.length) return;
    const r = GD.range();
    if (prevI < 0 && r) { hitI = hits.findIndex(h => h.compareBoundaryPoints(Range.START_TO_START, r) >= 0); if (dir < 0) hitI = hitI <= 0 ? hits.length - 1 : hitI - 1; if (hitI < 0) hitI = 0; }
    else hitI = ((prevI < 0 ? -1 : prevI) + dir + hits.length) % hits.length;
    const h = hits[hitI];
    if (HL) CSS.highlights.set('gd-find-cur', new Highlight(h));
    const s = getSelection(); s.removeAllRanges(); s.addRange(h.cloneRange()); savedRange = h.cloneRange();
    const rect = h.getBoundingClientRect(), m = $('#gdMain').getBoundingClientRect();
    if (rect.top < m.top + 40 || rect.bottom > m.bottom - 40) $('#gdMain').scrollTop += rect.top - m.top - m.height / 3;
    $('#gdFindN').textContent = (hitI + 1) + ' z ' + hits.length;
  }
  function replaceOne() {
    if (!canEdit()) return;
    const r = GD.range();
    if (r && hits[hitI] && r.toString() === hits[hitI].toString() && !r.collapsed) { cmd('insertText', $('#gdFindR').value); hitI--; }
    findGo(1);
  }
  function replaceAll() {
    if (!canEdit()) return;
    findAll(); if (!hits.length) return;
    const rep = $('#gdFindR').value, re = $('#gdFindRe').checked;
    let n = 0;
    for (let i = hits.length - 1; i >= 0; i--) {
      const h = hits[i], s = getSelection(); s.removeAllRanges(); s.addRange(h);
      let txt = rep;
      if (re) try { txt = h.toString().replace(new RegExp($('#gdFindQ').value, $('#gdFindCase').checked ? '' : 'i'), rep); } catch (e) {}
      document.execCommand('insertText', false, txt); n++;
    }
    toast('Nahradené: ' + n); markDirty(); hitI = -1; findAll();
  }
  $('#gdFindQ').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); findGo(e.shiftKey ? -1 : 1); } if (e.key === 'Escape') ACT.find(); });
  $('#gdFindQ').addEventListener('input', () => { hitI = -1; findAll(); });
  ['#gdFindCase', '#gdFindRe'].forEach(s => $(s).addEventListener('change', () => { hitI = -1; findAll(); }));

  /* ---------- počet slov ---------- */
  function counts(text) { const t = text.replace(/​/g, '').trim(); return { words: t ? t.split(/\s+/).filter(w => /[\p{L}\p{N}]/u.test(w)).length : 0, chars: t.replace(/\n/g, '').length, nospace: t.replace(/\s/g, '').length }; }
  GD.counts = counts;
  function bodyText() { const c = page.cloneNode(true); c.querySelectorAll('del.gd-del, .gd-toc, .gd-lb, sup.gd-fn').forEach(x => x.remove()); return c.innerText || c.textContent; }
  GD.bodyText = bodyText;
  function wordCount() {
    const all = counts(bodyText()), r = GD.range(), sel = r && !r.collapsed ? counts(r.toString()) : null;
    const row = (l, k) => '<tr><td>' + l + '</td><td class="text-end">' + (sel ? sel[k].toLocaleString('sk') + ' z ' : '') + all[k].toLocaleString('sk') + '</td></tr>';
    dlg('Počet slov', '<table class="table table-sm mb-2"><tbody><tr><td>Strany</td><td class="text-end">' + (GD.pageCount ? GD.pageCount() : '–') + '</td></tr>' + row('Slová', 'words') + row('Znaky', 'chars') + row('Znaky bez medzier', 'nospace') + '</tbody></table>' +
      '<label class="form-check"><input type="checkbox" class="form-check-input" id="wcShow"' + (S.view.wcbox ? ' checked' : '') + '> <span class="form-check-label">Zobrazovať počet slov počas písania</span></label>',
    { ok: 'OK', onOk: (el) => { S.view.wcbox = el.querySelector('#wcShow').checked; applyView(); updWc(); } });
  }
  let wcT = null;
  function updWc() {
    if (!S.view.wcbox) return;
    clearTimeout(wcT); wcT = setTimeout(() => { const all = counts(bodyText()), r = GD.range(), sel = r && !r.collapsed ? counts(r.toString()) : null; $('#gdWc').textContent = (sel ? sel.words.toLocaleString('sk') + ' z ' : '') + all.words.toLocaleString('sk') + ' slov'; }, 200);
  }
  GD.on('change', updWc); GD.on('sel', updWc); GD.on('open', updWc);
  $('#gdWc').addEventListener('click', wordCount);

  /* ---------- prehľad dokumentu ---------- */
  function outlineDraw() {
    const box = $('#gdOutline'); box.classList.toggle('d-none', !S.view.outline);
    document.body.classList.toggle('has-outline', !!S.view.outline);
    if (!S.view.outline) return;
    const hs = [...page.querySelectorAll('h1,h2,h3,h4,h5,h6,p.gd-title')];
    box.querySelector('nav').innerHTML = hs.length ? hs.map((h, i) => '<a href="#" class="l' + (h.tagName[1] || 1) + '" data-h="' + i + '">' + esc(h.textContent.trim() || '(prázdny nadpis)') + '</a>').join('') : '<div class="small text-body-secondary">Nadpisy, ktoré pridáš do dokumentu, sa zobrazia tu.</div>';
    box.querySelectorAll('[data-h]').forEach(a => a.onclick = (e) => { e.preventDefault(); GD.scrollToEl(hs[+a.dataset.h]); placeCaret(hs[+a.dataset.h]); });
  }
  GD.scrollToEl = (el) => { const m = $('#gdMain'), r = el.getBoundingClientRect(), mr = m.getBoundingClientRect(); m.scrollTo({ top: m.scrollTop + r.top - mr.top - 40, behavior: 'smooth' }); };
  let olT = null;
  GD.on('change', () => { if (S.view.outline) { clearTimeout(olT); olT = setTimeout(outlineDraw, 600); } });
  GD.on('view', outlineDraw); GD.on('structure', () => S.view.outline && outlineDraw());

  /* ---------- schránka ---------- */
  async function pasteFrom(plain) {
    if (!canEdit()) return;
    try {
      if (!plain && navigator.clipboard.read) {
        const items = await navigator.clipboard.read();
        for (const it of items) {
          const img = it.types.find(t => /^image\//.test(t));
          if (img) { const b = await it.getType(img); GD.insertImageFile && GD.insertImageFile(new File([b], 'obrazok.png', { type: img })); return; }
          if (it.types.includes('text/html')) { const h = await (await it.getType('text/html')).text(); restore(); document.execCommand('insertHTML', false, sanitize(h)); markDirty(); return; }
        }
      }
      const t = await navigator.clipboard.readText(); restore(); document.execCommand('insertText', false, t); markDirty();
    } catch (e) { toast('Prehliadač nedovolil čítať schránku – použi Ctrl+V' + (plain ? ' (alebo Ctrl+Shift+V)' : ''), 'err'); }
  }
  function sanitize(h) {
    const t = document.createElement('template'); t.innerHTML = h;
    t.content.querySelectorAll('script,style,meta,link,iframe,object,embed').forEach(x => x.remove());
    t.content.querySelectorAll('*').forEach(el => [...el.attributes].forEach(a => { if (/^on/i.test(a.name) || (a.name === 'href' && /^\s*javascript:/i.test(a.value))) el.removeAttribute(a.name); }));
    return t.innerHTML;
  }
  page.addEventListener('paste', (e) => {
    if (!canEdit()) { e.preventDefault(); return; }
    const f = [...(e.clipboardData && e.clipboardData.files || [])].find(x => /^image\//.test(x.type));
    if (f) { e.preventDefault(); GD.insertImageFile && GD.insertImageFile(f); return; }
    const h = e.clipboardData && e.clipboardData.getData('text/html');
    if (h && /<script|\son\w+=/i.test(h)) { e.preventDefault(); document.execCommand('insertHTML', false, sanitize(h)); }
  });

  /* ---------- automatické úpravy pri písaní (Nástroje → Predvoľby) ---------- */
  const SUBST = [['(c)', '©'], ['(r)', '®'], ['(tm)', '™'], ['->', '→'], ['<-', '←'], ['=>', '⇒'], ['--', '–'], ['...', '…'], ['1/2', '½'], ['1/4', '¼'], ['3/4', '¾'], ['!=', '≠'], ['<=', '≤'], ['>=', '≥']];
  function textBefore(n) { const r = GD.range(); if (!r || !r.collapsed || r.startContainer.nodeType !== 3) return null; return { node: r.startContainer, off: r.startOffset, text: r.startContainer.nodeValue.slice(0, r.startOffset) }; }
  function replaceBack(node, from, to, txt) { const r = document.createRange(); r.setStart(node, from); r.setEnd(node, to); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('insertText', false, txt); }
  page.addEventListener('beforeinput', (e) => {
    if (!canEdit() || S.mode === 'suggest') return;
    if (e.inputType === 'insertText' && e.data === '"' && S.prefs.quotes) {
      const tb = textBefore(); const prev = tb ? tb.text.slice(-1) : '';
      e.preventDefault(); document.execCommand('insertText', false, !prev || /[\s(\[{„]/.test(prev) ? '„' : '“');
    } else if (e.inputType === 'insertText' && e.data === "'" && S.prefs.quotes) {
      const tb = textBefore(); const prev = tb ? tb.text.slice(-1) : '';
      e.preventDefault(); document.execCommand('insertText', false, !prev || /[\s(\[{]/.test(prev) ? '‚' : '‘');
    }
  });
  page.addEventListener('input', (e) => {
    if (!canEdit() || S.mode === 'suggest' || e.inputType !== 'insertText') return;
    const tb = textBefore(); if (!tb) return;
    const d = e.data || '';
    if (S.prefs.subst && /[\s.,;:!?)]/.test(d)) {
      const body = tb.text.slice(0, -d.length);
      for (const [a, b] of SUBST) if (body.endsWith(a) && !(a === '--' && body.endsWith('---'))) { replaceBack(tb.node, tb.off - d.length - a.length, tb.off, b + d); return; }
    }
    if (S.prefs.links && /\s/.test(d)) {
      const m = /(?:^|\s)((?:https?:\/\/|www\.)[^\s<>"]+[^\s<>".,;:!?)])$/.exec(tb.text.slice(0, -1));
      if (m && !tb.node.parentNode.closest('a')) {
        const start = tb.off - 1 - m[1].length, r = document.createRange(); r.setStart(tb.node, start); r.setEnd(tb.node, tb.off - 1);
        const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('createLink', false, /^www\./.test(m[1]) ? 'https://' + m[1] : m[1]);
        const after = document.createRange(); const sel = getSelection(); const an = sel.focusNode; after.setStartAfter(an.nodeType === 3 ? an.parentNode.closest('a') || an : an); after.collapse(true);
        try { const a = (an.nodeType === 3 ? an.parentNode : an).closest('a'); if (a && a.nextSibling) { after.setStart(a.nextSibling, Math.min(1, a.nextSibling.nodeType === 3 ? a.nextSibling.nodeValue.length : 0)); } } catch (x) {}
        sel.removeAllRanges(); sel.addRange(after);
        return;
      }
    }
    if (S.prefs.lists && d === ' ') {
      const blk = blockOf(tb.node);
      if (blk && blk.tagName === 'P' && blk.textContent.replace(/​/g, '') === tb.text) {
        const t = tb.text.trim(); let k = null;
        if (/^(1[.)]|a[.)]|i[.)])$/.test(t)) k = ['ol', t[0] === 'a' ? 'lower-alpha' : t[0] === 'i' ? 'lower-roman' : 'decimal'];
        else if (t === '*' || t === '•') k = ['ul', 'disc']; else if (t === '-') k = ['ul', 'dash']; else if (t === '[]' || t === '[ ]') k = ['check'];
        if (k) { replaceBack(tb.node, 0, tb.off, ''); list(k[0], k[1]); return; }
      }
    }
    if (S.prefs.caps && /\p{Ll}/u.test(d) && d.length === 1) {
      const before = tb.text.slice(0, -1), blk = blockOf(tb.node);
      if (blk && blk.tagName !== 'PRE') {
        const full = (() => { const r = document.createRange(); r.setStart(blk, 0); r.setEnd(tb.node, tb.off - 1); return r.toString(); })();
        if (/(^|[.!?]\s+)$/.test(full) && !/(\b\w{1,3}\.|\d\.)\s+$/.test(full) && !(full === '' && blk.tagName === 'LI' && false)) {
          if (full === '' || /[.!?]\s+$/.test(full)) replaceBack(tb.node, tb.off - 1, tb.off, d.toLocaleUpperCase('sk'));
        }
      }
      void before;
    }
  });
  async function prefsDialog() {
    const p = S.prefs, ch = (k, t) => '<label class="form-check mb-2"><input type="checkbox" class="form-check-input" data-p="' + k + '"' + (p[k] ? ' checked' : '') + '> <span class="form-check-label">' + t + '</span></label>';
    await dlg('Predvoľby', ch('caps', 'Automaticky písať veľké písmeno na začiatku vety') + ch('quotes', 'Používať slovenské úvodzovky „…“') + ch('links', 'Automaticky rozpoznávať odkazy') + ch('lists', 'Automaticky rozpoznávať zoznamy (1. , * , - , [] )') + ch('subst', 'Automatické nahrádzanie: (c) → ©, -> → →, -- → –, ... → … a ďalšie'),
      { ok: 'OK', onOk: (el) => { el.querySelectorAll('[data-p]').forEach(i => { p[i.dataset.p] = i.checked; }); store.set('prefs', p); } });
  }

  /* ---------- súbor ---------- */
  async function rename() {
    if (S.ro) return;
    const cur = S.name.replace(/\.docx$/i, '');
    const v = await ask('Premenovať', 'Nový názov dokumentu', [['cancel', 'Zrušiť'], ['ok', 'OK', 'btn-primary']], cur);
    if (!v || v === 'cancel' || v.trim() === cur) return;
    const name = v.trim().replace(/\.docx$/i, '') + '.docx';
    const r = await api('rename', { type: 'f', id: S.id, name });
    if (!r.ok) return toast(r.error || 'Premenovanie zlyhalo', 'err');
    S.name = name; $('#gsName').textContent = name; document.title = name; post({ ev: 'renamed', name });
  }
  async function newFile(kind) {
    const nm = kind === 'sheet' ? 'Tabuľka bez názvu' : 'Dokument bez názvu', ext = kind === 'sheet' ? '.xlsx' : '.docx';
    let r = null;
    for (let i = 1; i < 30; i++) { r = await api('mkfile', { parent: C.folder || '', name: nm + (i > 1 ? ' (' + i + ')' : '') + ext }); if (r.ok || !/existuje/.test(r.error || '')) break; }
    if (!r || !r.ok) return toast((r && r.error) || 'Nepodarilo sa vytvoriť súbor', 'err');
    await save(false);
    location.href = base + (kind === 'sheet' ? 'sheet.php' : 'doc.php') + '?id=' + r.id + (C.embed ? '&embed=1' : '');
  }
  async function copyFile() {
    await save(false);
    const r = await api('copy', { target: C.folder || '', items: ['f:' + S.id] });
    r.ok ? toast('Kópia sa vytvorila v rovnakej zložke') : toast(r.error || 'Kópiu sa nepodarilo vytvoriť', 'err');
  }
  async function trash() {
    const r0 = await ask('Presunúť do koša?', 'Dokument „' + S.name + '“ sa presunie do koša. Odtiaľ ho môžeš obnoviť.', [['no', 'Zrušiť'], ['yes', 'Presunúť do koša', 'btn-danger']]);
    if (r0 !== 'yes') return;
    await save(false);
    const r = await api('delete', { items: ['f:' + S.id] });
    if (!r.ok) return toast(r.error || 'Nepodarilo sa', 'err');
    S.dirty = false; S.ro = true;
    if (inFrame) post({ ev: 'close' }); else location.href = C.drive;
  }
  async function star() {
    const on = !$('#gsStar').classList.contains('on');
    const r = await api('star', { items: ['f:' + S.id], value: on ? 1 : 0 });
    if (r.ok) { $('#gsStar').classList.toggle('on', on); $('#gsStar i').className = 'bi bi-star' + (on ? '-fill' : ''); toast(on ? 'Pridané medzi S hviezdičkou' : 'Odstránené z S hviezdičkou'); }
  }
  if ($('#gsStar') && C.starred) $('#gsStar i').className = 'bi bi-star-fill';
  const parentAct = async (act) => {
    if (!inFrame) { toast('Otvor dokument z disku, aby si mohol použiť túto funkciu', 'err'); return; }
    if (act === 'versions') await save(false);
    post({ ev: 'action', act });
  };

  /* ---------- akcie ---------- */
  const ACT = GD.act;
  Object.assign(ACT, {
    close, save: () => save(false), print: () => window.print(),
    undo: () => cmd('undo'), redo: () => cmd('redo'),
    cut: () => { restore(); document.execCommand('cut') || toast('Použi Ctrl+X'); markDirty(); }, copytext: () => { restore(); document.execCommand('copy') || toast('Použi Ctrl+C'); },
    paste: () => pasteFrom(false), pasteplain: () => pasteFrom(true), delete: () => cmd('delete'),
    selectall: () => { page.focus(); document.execCommand('selectAll'); },
    find: () => { const f = $('#gdFind'); f.classList.toggle('d-none'); if (HL && f.classList.contains('d-none')) { CSS.highlights.delete('gd-find'); CSS.highlights.delete('gd-find-cur'); } if (!f.classList.contains('d-none')) { const sel = getSelection().toString(); if (sel && sel.length < 80) $('#gdFindQ').value = sel; $('#gdFindQ').focus(); $('#gdFindQ').select(); hitI = -1; findAll(); } else page.focus(); },
    'find:next': () => findGo(1), 'find:prev': () => findGo(-1), 'find:one': replaceOne, 'find:all': replaceAll,
    clearfmt: () => { cmd('removeFormat'); },
    count: wordCount, prefs: prefsDialog,
    keys: () => bootstrap.Modal.getOrCreateInstance($('#gsKeys')).show(),
    menusearch: () => GMenu.search(),
    spell: () => toggleView('spell'), ruler: () => toggleView('ruler'), marks: () => toggleView('marks'), wcbox: () => { toggleView('wcbox'); updWc(); },
    outline: () => toggleView('outline'),
    fullscreen: () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.().catch(() => toast('Celá obrazovka nie je dostupná', 'err')); },
    paintfmt: () => paintStart(false),
    rename, star, trash, copy: copyFile, new: () => newFile('doc'), newsheet: () => newFile('sheet'),
    share: () => parentAct('share'), move: () => parentAct('move'), versions: () => parentAct('versions'), info: () => parentAct('info'), activity: () => parentAct('activity'),
    indentopts: indentDialog, spacingopts: spacingDialog,
    'sp:before': () => spaceToggle('before'), 'sp:after': () => spaceToggle('after'),
    checklist: () => list('check'),
    listrestart: () => { const r = GD.range(), l = r && listOf(r.startContainer); if (!l || l.tagName !== 'OL') return toast('Kurzor nie je v číslovanom zozname'); const li = blockOf(r.startContainer).closest('li'); if (li && li !== l.firstElementChild && canEdit()) { const nl = l.cloneNode(false); let x = li; while (x) { const nx = x.nextElementSibling; nl.appendChild(x); x = nx; } l.after(nl); markDirty(); } },
  });
  // panel: dvojklik na štetec = kopírovať formát viackrát
  $('[data-gs="paintfmt"]').addEventListener('dblclick', (e) => { e.preventDefault(); S.paint = null; paintStart(true); });
  document.addEventListener('mousedown', (e) => { if (e.target.closest('.gd-tools button, .gd-tools label, .gm-menu, .gd-imgbar')) { if (!e.target.closest('input')) e.preventDefault(); } }); // výber v texte ostane
  function run(a, b) {
    if (!S.ready && a !== 'close') return;
    const rw = b && (b.hasAttribute('data-rw') || b.closest('[data-rw]'));
    if (S.ro && rw) return;
    if (a.startsWith('dl:')) return download(a.slice(3));
    if (a.startsWith('cmd:')) return cmd(a.slice(4));
    if (a.startsWith('block:')) return setBlock(a.slice(6));
    if (a.startsWith('size:')) return stepSize(+a.slice(5));
    if (a.startsWith('fs:')) return fontSize(+a.slice(3));
    if (a.startsWith('font:')) { cmd('fontName', a.slice(5)); $('#gdFontL').textContent = a.slice(5); return; }
    if (a.startsWith('mode:')) return setMode(a.slice(5));
    if (a.startsWith('zoom:')) return GD.setZoom && GD.setZoom(a.slice(5));
    if (a.startsWith('lh:')) return lineHeight(a.slice(3));
    if (a.startsWith('indent:')) return indent(+a.slice(7));
    if (a.startsWith('case:')) return changeCase(a.slice(5));
    if (a.startsWith('ol:')) return list('ol', a.slice(3));
    if (a.startsWith('ul:')) return list('ul', a.slice(3));
    const fn = ACT[a] || (a.includes(':') && ACT[a.split(':')[0] + ':*'] ? (() => ACT[a.split(':')[0] + ':*'](a.slice(a.indexOf(':') + 1))) : null);
    if (fn) { try { const r = fn(); if (r && r.catch) r.catch(err => { console.error(err); toast(err.message || String(err), 'err'); }); } catch (err) { console.error(err); toast(err.message || String(err), 'err'); } }
  }
  GD.run = run;
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-gs]'); if (!b || b.tagName === 'SELECT' || b.tagName === 'INPUT') return;
    if (b.closest('#gdPage')) return;
    e.preventDefault();
    run(b.dataset.gs, b);
  });
  $('#gdSize').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); GMenu.closeAll(); fontSize(curSize()); } });
  $('#gdSize').addEventListener('focus', (e) => e.target.select());

  /* ---------- klávesové skratky ---------- */
  let chord = null; // Ctrl+Alt+O → ďalší kláves
  document.addEventListener('keydown', (e) => {
    if (e.target.closest && e.target.closest('.modal, .gm-search, #gdFind, .gd-hfed, .gd-side textarea, .gd-side input')) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(false); } return; }
    const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey, ed = canEdit();
    if (S.paint && e.key === 'Escape') { S.paint = null; document.body.classList.remove('painting'); toolState(); return; }
    if (chord && ctrl && e.altKey) { const c = chord; chord = null; const m = { h: 'header', f: 'footer', u: 'review' }[k]; if (m) { e.preventDefault(); run(m); return; } void c; }
    const go = (a) => { e.preventDefault(); run(a); };
    if (ctrl && e.altKey && k === 'o') { e.preventDefault(); chord = 'o'; setTimeout(() => { chord = null; }, 2000); return; }
    if (ctrl && !e.altKey && !e.shiftKey && k === 's') return go('save');
    if (ctrl && !e.altKey && k === 'p' && !e.shiftKey) return go('print');
    if (ctrl && e.shiftKey && k === 'p') return go('marks');
    if (ctrl && !e.altKey && (k === 'h' || k === 'f') && !e.shiftKey) return go('find');
    if (ctrl && k === '/' ) return go('keys');
    if (ctrl && e.shiftKey && k === 'c' && !e.altKey) return go('count');
    if (ctrl && e.altKey && k === 'a') return go('outline');
    if (!ed) return;
    if (ctrl && e.altKey && k === 'c') { e.preventDefault(); fmtClip = captureFormat(); toast('Formát skopírovaný'); return; }
    if (ctrl && e.altKey && k === 'v') { e.preventDefault(); applyFormat(fmtClip); return; }
    if (ctrl && k === 'k') return go('link');
    if (ctrl && e.key === '\\') return go('clearfmt');
    if (ctrl && e.key === 'Enter' && page.contains(document.activeElement)) return go('pagebreak');
    if (ctrl && e.altKey && /^[0-6]$/.test(e.key)) return go('block:' + (e.key === '0' ? 'p' : 'h' + e.key));
    if (ctrl && e.altKey && k === 'm') return go('comment');
    if (ctrl && e.altKey && k === 'f') return go('footnote');
    if (ctrl && e.shiftKey && (e.code === 'Digit7' || e.code === 'Digit8' || e.code === 'Digit9')) return go(e.code === 'Digit7' ? 'ol:decimal' : e.code === 'Digit8' ? 'ul:disc' : 'checklist');
    if (ctrl && e.shiftKey && ['l', 'e', 'r', 'j'].includes(k)) return go('cmd:' + { l: 'justifyLeft', e: 'justifyCenter', r: 'justifyRight', j: 'justifyFull' }[k]);
    if (ctrl && e.shiftKey && (e.key === '>' || e.code === 'Period')) return go('size:1');
    if (ctrl && e.shiftKey && (e.key === '<' || e.code === 'Comma')) return go('size:-1');
    if (ctrl && !e.shiftKey && e.code === 'Period') return go('cmd:superscript');
    if (ctrl && !e.shiftKey && e.code === 'Comma') return go('cmd:subscript');
    if (e.altKey && e.shiftKey && e.code === 'Digit5') return go('cmd:strikeThrough');
    if (ctrl && (e.code === 'BracketRight' || e.code === 'BracketLeft')) return go('indent:' + (e.code === 'BracketRight' ? 1 : -1));
    if (ctrl && e.shiftKey && k === 's') return go('voice');
    if (e.key === 'Tab' && page.contains(getSelection().anchorNode)) {
      if (GD.emit('tab', e)) return;
      e.preventDefault();
      const r = GD.range(), blk = r && blockOf(r.startContainer);
      if (blk && blk.tagName === 'LI') { const atStart = (() => { const x = document.createRange(); x.setStart(blk, 0); x.setEnd(r.startContainer, r.startOffset); return !x.toString().replace(/​/g, ''); })(); if (atStart || !r.collapsed || e.shiftKey) return indent(e.shiftKey ? -1 : 1); }
      if (!e.shiftKey) cmd('insertText', ' ');
    }
  }, true);

  // kontrolný zoznam: klik na políčko
  page.addEventListener('click', (e) => {
    const li = e.target.closest('ul.gd-check > li');
    if (li && e.target === li && e.offsetX < 0 && canEdit()) { if (li.dataset.ck === '1') delete li.dataset.ck; else li.dataset.ck = '1'; markDirty(); }
    const a = e.target.closest('a[href]'); if (a && (e.ctrlKey || e.metaKey || !canEdit())) { e.preventDefault(); const h = a.getAttribute('href'); if (h.startsWith('#')) { const t = document.getElementById(h.slice(1)); t && GD.scrollToEl(t); } else window.open(a.href, '_blank', 'noopener'); }
  });

  GD.boot = open;
})();
