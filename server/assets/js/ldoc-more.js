/* Editor dokumentov – komentáre, navrhovanie (sledované zmeny), tabuľky, obrázky, odkazy, záložky,
   kontextová ponuka, vodorovná čiara, dátum, hlasové písanie. */
(function () {
  const GD = window.GD; if (!GD) return;
  const { S, C, page, $, $$, esc } = GD;
  const me = () => C.user || 'Ja';
  const nowIso = () => new Date().toISOString().replace(/\.\d+Z$/, 'Z');
  const rid = (p) => p + Math.random().toString(36).slice(2, 9);
  const fmtDate = (d) => { const x = new Date(d); return isNaN(x) ? '' : x.toLocaleString('sk-SK', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const initials = (n) => String(n || '?').split(/\s+/).map(s => s[0]).join('').slice(0, 2).toUpperCase();
  const hue = (n) => { let h = 0; for (const c of String(n)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };

  /** Obalí text v rozsahu prvkami z make() (iba textové uzly – bezpečné aj cez viac odsekov). */
  function wrapRange(r, make, skip) {
    const root = r.commonAncestorContainer.nodeType === 3 ? r.commonAncestorContainer.parentNode : r.commonAncestorContainer;
    const w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT); const nodes = [];
    while (w.nextNode()) { const t = w.currentNode; if (r.intersectsNode(t) && t.nodeValue.length && !(skip && skip(t))) nodes.push(t); }
    const out = [];
    nodes.forEach(t => {
      let s = t === r.startContainer ? r.startOffset : 0, e = t === r.endContainer ? r.endOffset : t.nodeValue.length;
      if (e <= s) return;
      let x = t; if (s > 0) x = x.splitText(s); if (e - s < x.nodeValue.length) x.splitText(e - s);
      const el = make(); x.parentNode.insertBefore(el, x); el.appendChild(x); out.push(el);
    });
    return out;
  }
  GD.wrapRange = wrapRange;
  const unwrap = (el) => { const p = el.parentNode; if (!p) return; el.replaceWith(...el.childNodes); p.normalize(); };

  /* ======================= KOMENTÁRE A NÁVRHY ======================= */
  const side = $('#gdSide');
  let sideMode = 'all', activeId = null, newId = null;
  function cmAnchors(id) { return [...page.querySelectorAll('span.gd-cm[data-c="' + id + '"]')]; }
  function suggestions() {
    const map = new Map();
    page.querySelectorAll('ins.gd-ins, del.gd-del').forEach(el => {
      if (!el.dataset.s) el.dataset.s = rid('s');
      const k = el.dataset.s; if (!map.has(k)) map.set(k, { id: k, els: [], author: el.dataset.a || '', date: el.dataset.t || '' }); map.get(k).els.push(el);
    });
    return [...map.values()];
  }
  function countBadge() {
    const n = Object.values(S.comments).filter(c => !c.resolved && cmAnchors(c.id).length).length + suggestions().length;
    const b = $('#gdCmN'); b.textContent = n; b.classList.toggle('d-none', !n);
  }
  function openSide(mode, focusId) {
    sideMode = mode || 'all'; S.view.comments = true; activeId = focusId || activeId;
    drawSide();
    if (focusId) setTimeout(() => { const c = side.querySelector('[data-id="' + focusId + '"]'); if (c) { c.scrollIntoView({ block: 'nearest' }); const t = c.querySelector('textarea'); t && t.focus(); } }, 50);
  }
  function closeSide() { S.view.comments = false; side.classList.add('d-none'); document.body.classList.remove('side-open'); activeId = null; markActive(); }
  function markActive() {
    page.querySelectorAll('.gd-cm.on, .gd-ins.on, .gd-del.on').forEach(x => x.classList.remove('on'));
    if (!activeId) return;
    page.querySelectorAll('[data-c="' + activeId + '"], [data-s="' + activeId + '"]').forEach(x => x.classList.add('on'));
  }
  function drawSide() {
    countBadge();
    if (!S.view.comments) return;
    side.classList.remove('d-none'); document.body.classList.add('side-open');
    const order = (els) => els.length ? els[0] : null;
    const items = [];
    if (sideMode !== 'sugg') Object.values(S.comments).forEach(c => { const a = cmAnchors(c.id); if ((a.length || c.id === newId) && (!c.resolved || sideMode === 'resolved')) items.push({ kind: 'c', c, el: order(a) }); });
    if (sideMode !== 'resolved') suggestions().forEach(s => items.push({ kind: 's', s, el: s.els[0] }));
    items.sort((a, b) => !a.el ? 1 : !b.el ? -1 : (a.el.compareDocumentPosition(b.el) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
    const sugg = suggestions().length;
    let h = '<div class="gd-side-h"><b>' + (sideMode === 'sugg' ? 'Navrhované úpravy' : 'Komentáre a návrhy') + '</b>' +
      '<select class="form-select form-select-sm" data-side="mode"><option value="all"' + (sideMode === 'all' ? ' selected' : '') + '>Všetko</option><option value="sugg"' + (sideMode === 'sugg' ? ' selected' : '') + '>Iba návrhy</option><option value="resolved"' + (sideMode === 'resolved' ? ' selected' : '') + '>Vyriešené</option></select>' +
      '<button class="btn-close btn-sm" data-side="close"></button></div>';
    if (sugg && GD.canEdit() && sideMode !== 'resolved') h += '<div class="gd-side-all"><button class="btn btn-sm btn-primary rounded-pill" data-side="acceptall"><i class="bi bi-check2-all"></i> Prijať všetko</button><button class="btn btn-sm btn-outline-secondary rounded-pill" data-side="rejectall"><i class="bi bi-x-lg"></i> Odmietnuť všetko</button></div>';
    if (!items.length) h += '<div class="gd-side-empty"><i class="bi bi-chat-square-text"></i><div>' + (sideMode === 'resolved' ? 'Žiadne vyriešené komentáre' : 'Zatiaľ žiadne komentáre ani návrhy.<br>Označ text a stlač <b>Ctrl+Alt+M</b>.') + '</div></div>';
    for (const it of items) {
      if (it.kind === 'c') {
        const c = it.c, isNew = c.id === newId;
        h += '<div class="gd-card' + (activeId === c.id ? ' on' : '') + (c.resolved ? ' res' : '') + '" data-id="' + c.id + '">' + head(c.author, c.date,
          isNew ? '' : (c.resolved ? '<button title="Znova otvoriť" data-cm="reopen"><i class="bi bi-arrow-counterclockwise"></i></button>' : '<button title="Vyriešiť a skryť" data-cm="resolve"><i class="bi bi-check2"></i></button>') + (isNew ? '' : '<button title="Upraviť" data-cm="edit"><i class="bi bi-pencil"></i></button><button title="Odstrániť" data-cm="del"><i class="bi bi-trash3"></i></button>'));
        if (isNew) h += '<textarea class="form-control form-control-sm" rows="2" placeholder="Komentár…" data-cm="text"></textarea><div class="gd-card-b"><button class="btn btn-sm btn-light rounded-pill" data-cm="cancel">Zrušiť</button><button class="btn btn-sm btn-primary rounded-pill" data-cm="save">Komentovať</button></div>';
        else {
          const q = cmAnchors(c.id).map(x => x.textContent).join(' ').trim();
          if (q) h += '<div class="gd-card-q">„' + esc(q.length > 80 ? q.slice(0, 80) + '…' : q) + '“</div>';
          h += '<div class="gd-card-t">' + esc(c.text).replace(/\n/g, '<br>') + '</div>';
          (c.replies || []).forEach((r, i) => { h += '<div class="gd-reply">' + head(r.author, r.date, '<button title="Odstrániť odpoveď" data-cm="delreply" data-i="' + i + '"><i class="bi bi-x"></i></button>') + '<div class="gd-card-t">' + esc(r.text).replace(/\n/g, '<br>') + '</div></div>'; });
          if (!c.resolved && !S.ro) h += '<textarea class="form-control form-control-sm gd-replyin" rows="1" placeholder="Odpovedať…" data-cm="reply"></textarea><div class="gd-card-b d-none"><button class="btn btn-sm btn-light rounded-pill" data-cm="rcancel">Zrušiť</button><button class="btn btn-sm btn-primary rounded-pill" data-cm="rsave">Odpovedať</button></div>';
        }
        h += '</div>';
      } else {
        const s = it.s, ins = s.els.filter(e => e.tagName === 'INS').map(e => e.textContent).join(''), del = s.els.filter(e => e.tagName === 'DEL').map(e => e.textContent).join('');
        h += '<div class="gd-card sug' + (activeId === s.id ? ' on' : '') + '" data-id="' + s.id + '">' + head(s.author, s.date, GD.canEdit() ? '<button title="Prijať návrh" data-sg="accept"><i class="bi bi-check2"></i></button><button title="Odmietnuť návrh" data-sg="reject"><i class="bi bi-x-lg"></i></button>' : '') +
          '<div class="gd-card-t">' + (ins && del ? 'Nahradiť: <del>' + esc(del.slice(0, 120)) + '</del> za <ins>' + esc(ins.slice(0, 120)) + '</ins>' : ins ? 'Pridať: <ins>„' + esc(ins.slice(0, 160)) + '“</ins>' : 'Odstrániť: <del>„' + esc(del.slice(0, 160)) + '“</del>') + '</div></div>';
      }
    }
    side.innerHTML = h;
    markActive();
  }
  function head(author, date, btns) {
    return '<div class="gd-card-h"><span class="gd-av" style="background:hsl(' + hue(author) + ',55%,45%)">' + esc(initials(author)) + '</span><div class="gd-card-who"><b>' + esc(author || 'Neznámy') + '</b><small>' + esc(fmtDate(date)) + '</small></div><div class="gd-card-acts">' + btns + '</div></div>';
  }
  GD.on('open', () => { drawSide(); });
  GD.on('change', () => { clearTimeout(drawSide._t); drawSide._t = setTimeout(drawSide, 400); });
  function addComment() {
    if (S.ro) return;
    GD.restore();
    let r = GD.range(); if (!r) return;
    if (r.collapsed) { // bez výberu: celé slovo pri kurzore
      const s = getSelection(); s.modify('move', 'backward', 'word'); s.modify('extend', 'forward', 'word'); r = s.getRangeAt(0);
      if (!r.toString().trim()) return GD.toast('Najprv označ text, ku ktorému chceš pridať komentár');
    }
    const id = rid('c');
    const els = wrapRange(r, () => { const s = document.createElement('span'); s.className = 'gd-cm'; s.dataset.c = id; return s; });
    if (!els.length) return GD.toast('Najprv označ text');
    S.comments[id] = { id, author: me(), date: nowIso(), text: '', replies: [], resolved: false };
    newId = id; openSide(sideMode === 'sugg' ? 'all' : sideMode, id);
  }
  side.addEventListener('change', (e) => { if (e.target.dataset.side === 'mode') { sideMode = e.target.value; drawSide(); } });
  side.addEventListener('input', (e) => { if (e.target.dataset.cm === 'reply') { const b = e.target.nextElementSibling; b && b.classList.toggle('d-none', !e.target.value.trim()); } });
  side.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { const c = e.target.closest('.gd-card'); const b = c && c.querySelector('[data-cm="save"], [data-cm="rsave"]:not(.d-none *)'); b && b.click(); } });
  side.addEventListener('click', (e) => {
    const card = e.target.closest('.gd-card'), sb = e.target.closest('[data-side]');
    if (sb) {
      const a = sb.dataset.side;
      if (a === 'close') closeSide();
      if (a === 'acceptall' || a === 'rejectall') { suggestions().forEach(s => resolveSug(s, a === 'acceptall')); GD.markDirty(); drawSide(); GD.toast(a === 'acceptall' ? 'Všetky návrhy prijaté' : 'Všetky návrhy odmietnuté'); }
      return;
    }
    if (!card) return;
    const id = card.dataset.id, c = S.comments[id];
    const b = e.target.closest('[data-cm], [data-sg]');
    if (!b) { activeId = id; markActive(); side.querySelectorAll('.gd-card').forEach(x => x.classList.toggle('on', x === card)); const a = page.querySelector('[data-c="' + id + '"], [data-s="' + id + '"]'); a && GD.scrollToEl(a); return; }
    const a = b.dataset.cm || b.dataset.sg;
    if (b.dataset.sg) { const s = suggestions().find(x => x.id === id); if (s) { resolveSug(s, a === 'accept'); GD.markDirty(); drawSide(); } return; }
    if (a === 'save') { const t = card.querySelector('textarea').value.trim(); if (!t) return; c.text = t; newId = null; GD.markDirty(); drawSide(); }
    else if (a === 'cancel') { cmAnchors(id).forEach(unwrap); delete S.comments[id]; newId = null; drawSide(); }
    else if (a === 'resolve') { c.resolved = true; cmAnchors(id).forEach(x => x.classList.add('res')); GD.markDirty(); drawSide(); GD.toast('Komentár je vyriešený', '', ['Späť', () => { c.resolved = false; cmAnchors(id).forEach(x => x.classList.remove('res')); GD.markDirty(); drawSide(); }]); }
    else if (a === 'reopen') { c.resolved = false; cmAnchors(id).forEach(x => x.classList.remove('res')); GD.markDirty(); drawSide(); }
    else if (a === 'del') { cmAnchors(id).forEach(unwrap); delete S.comments[id]; GD.markDirty(); drawSide(); }
    else if (a === 'edit') { newId = id; const t = c.text; drawSide(); const ta = side.querySelector('[data-id="' + id + '"] textarea'); if (ta) { ta.value = t; ta.focus(); } }
    else if (a === 'rsave') { const t = card.querySelector('[data-cm="reply"]').value.trim(); if (!t) return; c.replies.push({ author: me(), date: nowIso(), text: t }); GD.markDirty(); drawSide(); }
    else if (a === 'rcancel') { card.querySelector('[data-cm="reply"]').value = ''; b.parentNode.classList.add('d-none'); }
    else if (a === 'delreply') { c.replies.splice(+b.dataset.i, 1); GD.markDirty(); drawSide(); }
  });
  page.addEventListener('click', (e) => {
    const m = e.target.closest('.gd-cm:not(.res), .gd-ins, .gd-del'); if (!m) return;
    const id = m.dataset.c || m.dataset.s; if (!id) return;
    activeId = id; if (!S.view.comments) openSide('all'); else drawSide();
    const card = side.querySelector('[data-id="' + id + '"]'); card && card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });
  GD.on('open', () => { Object.values(S.comments).forEach(c => { if (c.resolved) cmAnchors(c.id).forEach(x => x.classList.add('res')); }); });
  GD.on('clean', (c) => {
    // vyriešené komentáre sa do súboru neukladajú (ostávajú v starších verziách)
    c.querySelectorAll('span.gd-cm').forEach(s => { const cm = S.comments[s.dataset.c]; s.classList.remove('on', 'res'); if (!cm || cm.resolved || !cm.text) s.replaceWith(...s.childNodes); });
    c.querySelectorAll('.gd-ins.on, .gd-del.on').forEach(x => x.classList.remove('on'));
  });
  GD.on('chk', (k) => k === 'comments' ? !!S.view.comments : undefined);

  /* návrhy */
  function resolveSug(s, accept) {
    s.els.forEach(el => { if (!el.isConnected) return; if ((el.tagName === 'INS') === accept) unwrap(el); else el.remove(); });
  }
  function caretAt(node, off) { const r = document.createRange(); r.setStart(node, off); r.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
  function sugInsert(text) {
    let r = GD.range(); if (!r) return;
    if (!r.collapsed) { sugDelete(r, false); r = GD.range(); }
    let n = r.startContainer, o = r.startOffset;
    const mine = (n.nodeType === 3 ? n.parentNode : n).closest('ins.gd-ins');
    if (mine && mine.dataset.a === me() && page.contains(mine)) {
      if (n.nodeType === 3) { n.insertData(o, text); caretAt(n, o + text.length); }
      else { const t = document.createTextNode(text); n.insertBefore(t, n.childNodes[o] || null); caretAt(t, text.length); }
      return;
    }
    // hneď za mojím návrhom → pokračuj v ňom
    if (n.nodeType === 3 && o === 0 && n.previousSibling && n.previousSibling.nodeType === 1 && n.previousSibling.matches('ins.gd-ins') && n.previousSibling.dataset.a === me()) { const p = n.previousSibling; const t = document.createTextNode(text); p.appendChild(t); p.normalize(); const last = p.lastChild; caretAt(last, last.nodeValue.length); return; }
    if ((n.nodeType === 3 ? n.parentNode : n).closest('del.gd-del')) { const d = (n.nodeType === 3 ? n.parentNode : n).closest('del.gd-del'); const rr = document.createRange(); rr.setStartAfter(d); rr.collapse(true); n = rr.startContainer; o = rr.startOffset; r = rr; }
    const ins = document.createElement('ins'); ins.className = 'gd-ins'; ins.dataset.a = me(); ins.dataset.t = nowIso(); ins.dataset.s = rid('s');
    ins.textContent = text;
    const rr = document.createRange(); rr.setStart(n, o); rr.insertNode(ins);
    caretAt(ins.firstChild, text.length);
  }
  function sugDelete(r, backward) {
    const start = r.startContainer, so = r.startOffset;
    const sid = rid('s');
    // vlastné vložené návrhy sa zmažú naozaj
    const myIns = [];
    wrapRange(r, () => { const d = document.createElement('del'); d.className = 'gd-del'; d.dataset.a = me(); d.dataset.t = nowIso(); d.dataset.s = sid; return d; },
      (t) => { const p = t.parentNode; if (p.closest('del.gd-del')) return true; const i = p.closest('ins.gd-ins'); if (i && i.dataset.a === me()) { myIns.push([t, t === r.startContainer ? r.startOffset : 0, t === r.endContainer ? r.endOffset : t.nodeValue.length]); return true; } return false; });
    let caret = null;
    myIns.reverse().forEach(([t, a, b]) => { t.deleteData(a, b - a); caret = caret || [t, a]; const i = t.parentNode && t.parentNode.closest('ins.gd-ins'); if (i && !i.textContent) { caret = null; i.remove(); } });
    // zlúč susedné vymazania toho istého autora
    page.querySelectorAll('del.gd-del[data-s="' + sid + '"]').forEach(d => { const p = d.previousSibling; if (p && p.nodeType === 1 && p.matches('del.gd-del') && p.dataset.a === d.dataset.a) { p.append(...d.childNodes); d.remove(); } });
    const dels = page.querySelectorAll('del.gd-del[data-s="' + sid + '"]');
    if (backward && dels.length) { const d = dels[0]; const rr = document.createRange(); rr.setStartBefore(d); rr.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(rr); }
    else if (dels.length) { const d = dels[dels.length - 1]; const rr = document.createRange(); rr.setStartAfter(d); rr.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(rr); }
    else if (caret && caret[0].isConnected) caretAt(caret[0], caret[1]);
    else try { caretAt(start, Math.min(so, start.nodeType === 3 ? start.nodeValue.length : start.childNodes.length)); } catch (e) {}
  }
  page.addEventListener('beforeinput', (e) => {
    if (S.mode !== 'suggest' || S.ro || GD.altEdit) return;
    const t = e.inputType;
    if (t === 'historyUndo' || t === 'historyRedo' || t === 'insertParagraph' || t === 'insertLineBreak' || t.startsWith('format')) return;
    if (t === 'insertText' || t === 'insertReplacementText' || t === 'insertFromPaste' || t === 'insertFromDrop' || t === 'insertCompositionText') {
      if (t === 'insertCompositionText') return;
      e.preventDefault();
      let txt = e.data;
      if (txt == null && e.dataTransfer) txt = e.dataTransfer.getData('text/plain');
      if (txt) sugInsert(txt.replace(/\r?\n/g, ' '));
      GD.markDirty(); drawSide();
      return;
    }
    if (t.startsWith('delete')) {
      e.preventDefault();
      const s = getSelection(); if (!s.rangeCount) return;
      let r = s.getRangeAt(0);
      if (r.collapsed) {
        const back = /Backward/.test(t);
        s.modify('extend', back ? 'backward' : 'forward', /Word/.test(t) ? 'word' : /Line/.test(t) ? 'lineboundary' : 'character');
        r = s.getRangeAt(0);
        if (r.collapsed) return;
        sugDelete(r, back);
      } else sugDelete(r, true);
      GD.markDirty(); drawSide();
    }
  });
  GD.on('cmd', (c) => { if (S.mode === 'suggest' && c === 'delete') { const r = GD.range(); if (r && !r.collapsed) { sugDelete(r, true); GD.markDirty(); drawSide(); } return false; } });
  GD.on('mode', (m) => { if (m === 'suggest' && !S.view.comments) openSide('all'); });

  /* ======================= TABUĽKY ======================= */
  const grid = $('#gdGrid');
  if (grid) {
    grid.innerHTML = '<div class="gm-gridin">' + Array.from({ length: 100 }, (_, i) => '<span data-r="' + (Math.floor(i / 10) + 1) + '" data-c="' + (i % 10 + 1) + '"></span>').join('') + '</div><div class="gm-gridl">1 × 1</div>';
    grid.addEventListener('mouseover', (e) => { const s = e.target.closest('[data-r]'); if (!s) return; const r = +s.dataset.r, c = +s.dataset.c; grid.querySelectorAll('[data-r]').forEach(x => x.classList.toggle('on', +x.dataset.r <= r && +x.dataset.c <= c)); grid.querySelector('.gm-gridl').textContent = c + ' × ' + r; });
    grid.addEventListener('mousedown', (e) => e.preventDefault());
    grid.addEventListener('click', (e) => { const s = e.target.closest('[data-r]'); if (!s) return; GMenu.closeAll(); insertTable(+s.dataset.r, +s.dataset.c); });
  }
  function insertTable(rows, cols) {
    if (!GD.canEdit()) return;
    GD.restore();
    const before = new Set(page.querySelectorAll('table'));
    document.execCommand('insertHTML', false, '<table><tbody>' + ('<tr>' + '<td><br></td>'.repeat(cols) + '</tr>').repeat(rows) + '</tbody></table><p><br></p>');
    const t = [...page.querySelectorAll('table')].find(x => !before.has(x));
    if (t) { const td = t.querySelector('td'); const r = document.createRange(); r.selectNodeContents(td); r.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    GD.markDirty(); GD.emit('structure');
  }
  const curCell = () => { const r = GD.range(); if (!r) return null; const n = r.startContainer.nodeType === 3 ? r.startContainer.parentNode : r.startContainer; const td = n.closest && n.closest('td,th'); return td && page.contains(td) ? td : null; };
  GD.curCell = curCell;
  function needCell() { const td = curCell(); if (!td) GD.toast('Kurzor nie je v tabuľke'); return td; }
  const rowsOf = (t) => [...t.querySelectorAll(':scope > tbody > tr, :scope > thead > tr, :scope > tr')];
  /** mapa mriežky: [riadok][stĺpec] → bunka (so zlúčenými bunkami) */
  function gridMap(t) {
    const rows = rowsOf(t), m = rows.map(() => []);
    rows.forEach((tr, ri) => { let ci = 0; [...tr.children].forEach(td => { while (m[ri][ci]) ci++; for (let a = 0; a < (td.rowSpan || 1); a++) for (let b = 0; b < (td.colSpan || 1); b++) if (m[ri + a]) m[ri + a][ci + b] = td; ci += td.colSpan || 1; }); });
    return { rows, m };
  }
  const colOf = (td) => { const t = td.closest('table'), { m } = gridMap(t); for (const row of m) { const i = row.indexOf(td); if (i >= 0) return i; } return 0; };
  const newCell = () => { const td = document.createElement('td'); td.innerHTML = '<br>'; return td; };
  const TBL = {
    rowabove: (td) => { const tr = td.parentNode, n = document.createElement('tr'); const cols = gridMap(tr.closest('table')).m[0].length; for (let i = 0; i < cols; i++) n.appendChild(newCell()); tr.before(n); },
    rowbelow: (td) => { const tr = td.parentNode, n = document.createElement('tr'); const cols = gridMap(tr.closest('table')).m[0].length; for (let i = 0; i < cols; i++) n.appendChild(newCell()); tr.after(n); },
    colleft: (td) => addCol(td, 0), colright: (td) => addCol(td, 1),
    delrow: (td) => { const t = td.closest('table'); td.parentNode.remove(); if (!rowsOf(t).length) t.remove(); },
    delcol: (td) => { const t = td.closest('table'), c = colOf(td), { m } = gridMap(t); const seen = new Set(); m.forEach(row => { const x = row[c]; if (!x || seen.has(x)) return; seen.add(x); if ((x.colSpan || 1) > 1) x.colSpan--; else x.remove(); }); rowsOf(t).forEach(tr => { if (!tr.children.length) tr.remove(); }); if (!rowsOf(t).length) t.remove(); },
    del: (td) => td.closest('table').remove(),
    merge: () => mergeCells(), unmerge: (td) => { const t = td.closest('table'); const rs = td.rowSpan || 1, cs = td.colSpan || 1; if (rs === 1 && cs === 1) return; const { rows } = gridMap(t); const r0 = rows.indexOf(td.parentNode), c0 = colOf(td); td.rowSpan = 1; td.colSpan = 1; for (let b = 1; b < cs; b++) td.after(newCell()); for (let a = 1; a < rs; a++) { const tr = rows[r0 + a]; if (!tr) continue; const { m } = gridMap(t); let before = null; for (let c = c0 + cs; c < m[r0 + a].length; c++) { const x = m[r0 + a][c]; if (x && x.parentNode === tr) { before = x; break; } } for (let b = 0; b < cs; b++) tr.insertBefore(newCell(), before); } },
    header: (td) => { const t = td.closest('table'); if (t.dataset.hdr === '1') delete t.dataset.hdr; else t.dataset.hdr = '1'; },
    distribute: (td) => { const t = td.closest('table'); t.querySelectorAll('td,th').forEach(c => c.style.width = ''); t.style.tableLayout = ''; },
    sortasc: (td) => sortTable(td, 1), sortdesc: (td) => sortTable(td, -1),
  };
  function addCol(td, after) {
    const t = td.closest('table'), c = colOf(td) + (after ? (td.colSpan || 1) : 0), { rows, m } = gridMap(t);
    rows.forEach((tr, ri) => {
      const row = m[ri]; const x = row[c];
      if (x && row[c - 1] === x && x.parentNode === tr) { if (ri === rows.indexOf(x.parentNode)) x.colSpan = (x.colSpan || 1) + 1; return; }
      let before = null; for (let k = c; k < row.length; k++) if (row[k] && row[k].parentNode === tr) { before = row[k]; break; }
      tr.insertBefore(newCell(), before);
    });
  }
  function selCells() {
    const r = GD.range(), td = curCell(); if (!td || !r) return [];
    const t = td.closest('table');
    return [...t.querySelectorAll('td,th')].filter(c => c.closest('table') === t && r.intersectsNode(c));
  }
  function mergeCells() {
    const cells = selCells(); if (cells.length < 2) return GD.toast('Označ viac buniek (ťahaním myšou)');
    const t = cells[0].closest('table'), { rows, m } = gridMap(t);
    let r0 = 1e9, r1 = -1, c0 = 1e9, c1 = -1;
    m.forEach((row, ri) => row.forEach((x, ci) => { if (cells.includes(x)) { r0 = Math.min(r0, ri); r1 = Math.max(r1, ri); c0 = Math.min(c0, ci); c1 = Math.max(c1, ci); } }));
    const first = m[r0][c0];
    const seen = new Set([first]);
    for (let a = r0; a <= r1; a++) for (let b = c0; b <= c1; b++) { const x = m[a][b]; if (!x || seen.has(x)) continue; seen.add(x); const txt = x.textContent.trim(); if (txt || x.querySelector('img')) { first.insertAdjacentHTML('beforeend', '<br>'); first.append(...x.childNodes); } x.remove(); }
    first.rowSpan = r1 - r0 + 1; first.colSpan = c1 - c0 + 1;
    rows.forEach(tr => { if (!tr.children.length) tr.remove(); });
  }
  function sortTable(td, dir) {
    const t = td.closest('table'), c = colOf(td), { rows, m } = gridMap(t);
    if ([...t.querySelectorAll('td,th')].some(x => (x.rowSpan || 1) > 1)) return GD.toast('Tabuľku so zvislo zlúčenými bunkami nie je možné zoradiť', 'err');
    const head = t.dataset.hdr === '1' ? 1 : 0;
    const body = rows.slice(head).map((tr, i) => ({ tr, k: (m[i + head][c] || {}).textContent || '' }));
    body.sort((a, b) => dir * a.k.trim().localeCompare(b.k.trim(), 'sk', { numeric: true, sensitivity: 'base' }));
    const parent = rows[0].parentNode; body.forEach(x => parent.appendChild(x.tr));
  }
  async function tableProps() {
    const td = needCell(); if (!td || !GD.canEdit()) return;
    const t = td.closest('table'), cells = selCells().length ? selCells() : [td];
    const bw = t.dataset.bw !== undefined ? +t.dataset.bw : 1, bc = t.dataset.bc || '#000000';
    const bg = GD.IO.hex(td.style.backgroundColor) ? '#' + GD.IO.hex(td.style.backgroundColor).toLowerCase() : '#ffffff';
    const w = parseFloat(td.style.width) ? Math.round(parseFloat(td.style.width) / 37.795 * 100) / 100 : '';
    let res = null;
    await GD.dlg('Vlastnosti tabuľky', '<div class="row g-3"><div class="col-12"><b class="small text-uppercase text-body-secondary">Tabuľka</b></div>' +
      '<div class="col-6"><label class="form-label">Hrúbka okraja</label><select class="form-select" id="tpW">' + [0, 1, 2, 3, 4, 6].map(v => '<option value="' + v + '"' + (v === bw ? ' selected' : '') + '>' + (v ? v + ' px' : 'Bez okraja') + '</option>').join('') + '</select></div>' +
      '<div class="col-6"><label class="form-label">Farba okraja</label><input type="color" class="form-control form-control-color w-100" id="tpC" value="' + bc + '"></div>' +
      '<div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" id="tpH"' + (t.dataset.hdr === '1' ? ' checked' : '') + '> <span class="form-check-label">Pripnúť riadok hlavičky (opakuje sa na každej strane)</span></label></div>' +
      '<div class="col-12"><b class="small text-uppercase text-body-secondary">Označené bunky</b></div>' +
      '<div class="col-6"><label class="form-label">Farba pozadia</label><div class="d-flex gap-2"><input type="color" class="form-control form-control-color" id="tpB" value="' + bg + '"><button type="button" class="btn btn-sm btn-light" id="tpBx">Žiadna</button></div></div>' +
      '<div class="col-6"><label class="form-label">Zvislé zarovnanie</label><select class="form-select" id="tpV"><option value="">Hore</option><option value="middle"' + (td.style.verticalAlign === 'middle' ? ' selected' : '') + '>Na stred</option><option value="bottom"' + (td.style.verticalAlign === 'bottom' ? ' selected' : '') + '>Dole</option></select></div>' +
      '<div class="col-6"><label class="form-label">Šírka stĺpca (cm)</label><input type="number" step="0.1" min="0" class="form-control" id="tpCw" value="' + w + '" placeholder="automaticky"></div></div>',
    { init: (el) => { el.querySelector('#tpBx').onclick = () => { el.querySelector('#tpB').value = '#ffffff'; }; },
      onOk: (el) => { res = { w: +el.querySelector('#tpW').value, c: el.querySelector('#tpC').value, h: el.querySelector('#tpH').checked, b: el.querySelector('#tpB').value, v: el.querySelector('#tpV').value, cw: parseFloat(el.querySelector('#tpCw').value) }; } });
    if (!res) return;
    if (res.w === 1) delete t.dataset.bw; else t.dataset.bw = res.w;
    if (res.c.toLowerCase() === '#000000') delete t.dataset.bc; else t.dataset.bc = res.c;
    if (res.h) t.dataset.hdr = '1'; else delete t.dataset.hdr;
    cells.forEach(c => { c.style.backgroundColor = res.b.toLowerCase() === '#ffffff' ? '' : res.b; c.style.verticalAlign = res.v; });
    if (res.cw > 0) setColWidth(td, Math.round(res.cw * 37.795));
    tblStyle(t); GD.markDirty(); GD.emit('structure');
  }
  function setColWidth(td, px) { const t = td.closest('table'), c = colOf(td), { m } = gridMap(t); m.forEach(row => { const x = row[c]; if (x && (x.colSpan || 1) === 1) x.style.width = px + 'px'; }); t.style.tableLayout = 'fixed'; }
  function tblStyle(t) { const bw = t.dataset.bw !== undefined ? +t.dataset.bw : 1; t.style.setProperty('--tb-w', bw + 'px'); t.style.setProperty('--tb-c', t.dataset.bc || '#000'); }
  GD.on('open', () => page.querySelectorAll('table').forEach(tblStyle));
  GD.on('clean', (c) => c.querySelectorAll('table').forEach(t => { t.style.removeProperty('--tb-w'); t.style.removeProperty('--tb-c'); if (!t.getAttribute('style')) t.removeAttribute('style'); }));
  GD.on('chk', (k) => { if (k === 'tbl:header') { const td = curCell(); return !!(td && td.closest('table').dataset.hdr === '1'); } });
  GD.act['tbl:*'] = (op) => {
    if (op === 'props') return tableProps();
    if (!GD.canEdit()) return;
    const td = needCell(); if (!td) return;
    const t = td.closest('table');
    TBL[op] && TBL[op](td);
    if (t.isConnected) tblStyle(t);
    GD.markDirty(); GD.emit('structure');
  };
  // Tab v tabuľke: ďalšia bunka (na konci pridá riadok)
  GD.on('tab', (e) => {
    const td = curCell(); if (!td || !GD.canEdit()) return;
    e.preventDefault();
    const all = [...td.closest('table').querySelectorAll('td,th')].filter(x => x.closest('table') === td.closest('table'));
    let i = all.indexOf(td) + (e.shiftKey ? -1 : 1);
    if (i >= all.length) { TBL.rowbelow(all[all.length - 1]); GD.markDirty(); i = all.length; }
    const next = [...td.closest('table').querySelectorAll('td,th')].filter(x => x.closest('table') === td.closest('table'))[Math.max(0, i)];
    if (next) { const r = document.createRange(); r.selectNodeContents(next); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    return true;
  });
  // ťahanie okraja stĺpca
  let colDrag = null;
  page.addEventListener('mousemove', (e) => {
    if (colDrag || !GD.canEdit()) return;
    const td = e.target.closest && e.target.closest('td,th');
    const r = td && td.getBoundingClientRect();
    const near = td && r.right - e.clientX < 5 && r.right - e.clientX >= -2 && td.nextElementSibling;
    page.classList.toggle('col-resize', !!near);
  });
  page.addEventListener('mousedown', (e) => {
    if (!page.classList.contains('col-resize')) return;
    const td = e.target.closest('td,th'); if (!td) return;
    e.preventDefault();
    const t = td.closest('table'), sc = S.zoom || 1, x0 = e.clientX;
    const nxt = td.nextElementSibling;
    const w0 = td.getBoundingClientRect().width / sc, n0 = nxt.getBoundingClientRect().width / sc;
    // zafixuj všetky šírky v prvom riadku
    const { m } = gridMap(t); const widths = m[0].map((x, i) => { const cs = m[0].filter(y => y === x).length; return x.getBoundingClientRect().width / sc / cs; void i; });
    t.style.tableLayout = 'fixed';
    m.forEach(row => row.forEach((x, i) => { if ((x.colSpan || 1) === 1) x.style.width = Math.round(widths[i]) + 'px'; }));
    colDrag = true;
    const move = (ev) => { const dx = (ev.clientX - x0) / sc; const a = Math.max(24, w0 + dx), b = Math.max(24, n0 - dx); setColWidth(td, Math.round(a)); setColWidth(nxt, Math.round(b)); };
    const up = () => { removeEventListener('mousemove', move); removeEventListener('mouseup', up); colDrag = null; page.classList.remove('col-resize'); GD.markDirty(); GD.emit('structure'); };
    addEventListener('mousemove', move); addEventListener('mouseup', up);
  });

  /* ======================= OBRÁZKY ======================= */
  function insertImageFile(file) {
    if (!file || !/^image\//.test(file.type) || !GD.canEdit()) return;
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = () => {
      const max = 1600, k = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      const data = c.toDataURL(file.type === 'image/png' || file.type === 'image/gif' ? 'image/png' : 'image/jpeg', 0.88);
      const cw = (GD.dims().W - GD.dims().ml - GD.dims().mr) || 624, w = Math.min(Math.round(cw), c.width), h = Math.round(c.height * w / c.width);
      GD.restore(); document.execCommand('insertHTML', false, '<img src="' + data + '" width="' + w + '" height="' + h + '" alt="">');
      URL.revokeObjectURL(url); GD.markDirty(); GD.emit('structure');
    };
    img.onerror = () => GD.toast('Obrázok sa nepodarilo načítať', 'err');
    img.src = url;
  }
  GD.insertImageFile = insertImageFile;
  GD.insertImageData = (data, w, h, attrs) => { GD.restore(); document.execCommand('insertHTML', false, '<img src="' + data + '" width="' + w + '" height="' + h + '" alt=""' + (attrs || '') + '>'); GD.markDirty(); GD.emit('structure'); };
  $('#gdImg').addEventListener('change', (e) => { insertImageFile(e.target.files[0]); e.target.value = ''; });
  $('#gdCam').addEventListener('change', (e) => { insertImageFile(e.target.files[0]); e.target.value = ''; });
  page.addEventListener('drop', (e) => { const f = [...(e.dataTransfer && e.dataTransfer.files || [])].find(x => /^image\//.test(x.type)); if (f && GD.canEdit()) { e.preventDefault(); const r = document.caretRangeFromPoint && document.caretRangeFromPoint(e.clientX, e.clientY); if (r) { const s = getSelection(); s.removeAllRanges(); s.addRange(r); } insertImageFile(f); } });
  async function imageUrl() {
    if (!GD.canEdit()) return;
    const u = await GD.ask('Vložiť obrázok podľa adresy', 'Webová adresa obrázka (obrázok sa stiahne a uloží do dokumentu)', [['cancel', 'Zrušiť'], ['ok', 'Vložiť', 'btn-primary']], 'https://');
    if (!u || u === 'cancel' || !/^https?:\/\/./i.test(u)) return;
    try { const r = await fetch(u, { mode: 'cors' }); if (!r.ok) throw new Error(); const b = await r.blob(); if (!/^image\//.test(b.type)) throw new Error(); insertImageFile(new File([b], 'obrazok', { type: b.type })); }
    catch (e) { GD.toast('Obrázok z tejto adresy sa nedá vložiť (stránka to nedovolí). Ulož si ho do počítača a vlož ako súbor.', 'err'); }
  }
  // výber obrázka, posúvanie úchytov, panel s obtekaním
  const bar = $('#gdImgBar');
  let selBox = null;
  const selImg = () => page.querySelector('img.sel');
  function placeImgUi() {
    const img = selImg();
    if (!img) { bar.classList.add('d-none'); if (selBox) { selBox.remove(); selBox = null; } return; }
    const r = img.getBoundingClientRect();
    if (!selBox) { selBox = document.createElement('div'); selBox.className = 'gd-imgsel'; selBox.innerHTML = '<i data-h="nw"></i><i data-h="ne"></i><i data-h="sw"></i><i data-h="se"></i><span></span>'; document.body.appendChild(selBox); selBox.addEventListener('mousedown', resizeStart); }
    Object.assign(selBox.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' });
    selBox.querySelector('span').textContent = Math.round(img.width) + ' × ' + Math.round(img.height);
    const mr = $('#gdMain').getBoundingClientRect();
    bar.classList.toggle('d-none', !GD.canEdit());
    bar.style.left = Math.max(mr.left + 4, Math.min(r.left, innerWidth - 260)) + 'px';
    bar.style.top = Math.max(mr.top + 4, r.top - 44) + 'px';
    const mode = img.classList.contains('wl') ? 'left' : img.classList.contains('wr') ? 'right' : img.classList.contains('wb') ? 'break' : 'inline';
    bar.querySelectorAll('[data-gs^="img:"]').forEach(b => b.classList.toggle('on', b.dataset.gs === 'img:' + mode));
  }
  GD.placeImgUi = placeImgUi;
  function resizeStart(e) {
    const h = e.target.dataset.h; const img = selImg(); if (!h || !img || !GD.canEdit()) return;
    e.preventDefault();
    const x0 = e.clientX, w0 = img.width, h0 = img.height, ratio = h0 / w0, sc = S.zoom || 1, dir = /w/.test(h) ? -1 : 1;
    const move = (ev) => { const w = Math.max(20, Math.min(1600, w0 + dir * (ev.clientX - x0) / sc)); img.width = Math.round(w); img.height = Math.round(ev.shiftKey ? h0 : w * ratio); placeImgUi(); };
    const up = () => { removeEventListener('mousemove', move); removeEventListener('mouseup', up); GD.markDirty(); GD.emit('structure'); };
    addEventListener('mousemove', move); addEventListener('mouseup', up);
  }
  page.addEventListener('click', (e) => {
    page.querySelectorAll('img.sel').forEach(i => i !== e.target && i.classList.remove('sel'));
    if (e.target.tagName === 'IMG') { e.target.classList.add('sel'); const r = document.createRange(); r.selectNode(e.target); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    placeImgUi();
  });
  page.addEventListener('dblclick', (e) => { const img = e.target.closest('img'); if (!img || !GD.canEdit()) return; if (img.dataset.chart && GD.chartDialog) GD.chartDialog(img); else if (img.dataset.draw && GD.drawDialog) GD.drawDialog(img); else imgOpts(); });
  $('#gdMain').addEventListener('scroll', placeImgUi, { passive: true }); addEventListener('resize', placeImgUi);
  GD.on('change', () => setTimeout(placeImgUi, 400));
  document.addEventListener('keydown', (e) => {
    const img = selImg(); if (!img || !GD.canEdit() || document.activeElement !== page) return;
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); e.stopPropagation(); img.remove(); placeImgUi(); GD.markDirty(); GD.emit('structure'); }
  }, true);
  function imgWrap(mode) {
    const img = selImg(); if (!img || !GD.canEdit()) return GD.toast('Najprv klikni na obrázok');
    img.classList.remove('wl', 'wr', 'wb', 'c', 'r');
    if (mode === 'left') img.classList.add('wl'); else if (mode === 'right') img.classList.add('wr'); else if (mode === 'break') img.classList.add('wb');
    img.classList.add('sel'); GD.markDirty(); GD.emit('structure'); setTimeout(placeImgUi, 50);
  }
  async function imgOpts() {
    const img = selImg(); if (!img || !GD.canEdit()) return GD.toast('Najprv klikni na obrázok');
    const mode = img.classList.contains('wl') ? 'left' : img.classList.contains('wr') ? 'right' : img.classList.contains('wb') ? 'break' : 'inline';
    const al = img.classList.contains('c') ? 'c' : img.classList.contains('r') ? 'r' : 'l';
    let res = null;
    await GD.dlg('Možnosti obrázka', '<div class="row g-3"><div class="col-6"><label class="form-label">Šírka (px)</label><input type="number" class="form-control" id="ioW" value="' + img.width + '" min="10" max="2000"></div><div class="col-6"><label class="form-label">Výška (px)</label><input type="number" class="form-control" id="ioH" value="' + img.height + '" min="10" max="3000"></div>' +
      '<div class="col-12"><label class="form-check"><input type="checkbox" class="form-check-input" id="ioL" checked> <span class="form-check-label">Zachovať pomer strán</span></label></div>' +
      '<div class="col-6"><label class="form-label">Obtekanie textu</label><select class="form-select" id="ioM"><option value="inline">V riadku textu</option><option value="left">Obtekanie – obrázok vľavo</option><option value="right">Obtekanie – obrázok vpravo</option><option value="break">Zalomiť text (samostatne)</option></select></div>' +
      '<div class="col-6"><label class="form-label">Zarovnanie (pri zalomení)</label><select class="form-select" id="ioA"><option value="l">Vľavo</option><option value="c">Na stred</option><option value="r">Vpravo</option></select></div>' +
      '<div class="col-12"><label class="form-label">Alternatívny text (pre čítačky obrazovky)</label><input class="form-control" id="ioAlt" value="' + esc(img.getAttribute('alt') || '') + '"></div>' +
      '<div class="col-12"><button type="button" class="btn btn-sm btn-light" id="ioReset"><i class="bi bi-arrow-counterclockwise"></i> Pôvodná veľkosť</button></div></div>',
    { init: (el) => {
      el.querySelector('#ioM').value = mode; el.querySelector('#ioA').value = al;
      const W = el.querySelector('#ioW'), H = el.querySelector('#ioH'), L = el.querySelector('#ioL'), ratio = img.height / img.width;
      W.oninput = () => { if (L.checked) H.value = Math.round(W.value * ratio); }; H.oninput = () => { if (L.checked) W.value = Math.round(H.value / ratio); };
      el.querySelector('#ioReset').onclick = () => { const cw = GD.dims().W - GD.dims().ml - GD.dims().mr; const w = Math.min(img.naturalWidth, Math.round(cw)); W.value = w; H.value = Math.round(img.naturalHeight * w / img.naturalWidth); };
    }, onOk: (el) => { res = { w: +el.querySelector('#ioW').value, h: +el.querySelector('#ioH').value, m: el.querySelector('#ioM').value, a: el.querySelector('#ioA').value, alt: el.querySelector('#ioAlt').value }; } });
    if (!res) return;
    if (res.w > 0) img.width = res.w; if (res.h > 0) img.height = res.h;
    img.classList.remove('wl', 'wr', 'wb', 'c', 'r');
    if (res.m === 'left') img.classList.add('wl'); if (res.m === 'right') img.classList.add('wr'); if (res.m === 'break') { img.classList.add('wb'); if (res.a !== 'l') img.classList.add(res.a); }
    img.setAttribute('alt', res.alt);
    GD.markDirty(); GD.emit('structure'); setTimeout(placeImgUi, 50);
  }
  GD.on('cmd', (c) => {
    const img = selImg();
    if (img && /^justify(Left|Center|Right)$/.test(c) && img.classList.contains('wb')) { img.classList.remove('c', 'r'); if (c === 'justifyCenter') img.classList.add('c'); if (c === 'justifyRight') img.classList.add('r'); GD.markDirty(); return false; }
  });
  GD.on('clean', (c) => c.querySelectorAll('img.sel').forEach(i => i.classList.remove('sel')));
  Object.assign(GD.act, {
    image: () => $('#gdImg').click(), camera: () => $('#gdCam').click(), imageurl: imageUrl,
    'img:inline': () => imgWrap('inline'), 'img:left': () => imgWrap('left'), 'img:right': () => imgWrap('right'), 'img:break': () => imgWrap('break'),
    'img:opts': imgOpts, 'img:alt': imgOpts,
    'img:reset': () => { const img = selImg(); if (!img) return GD.toast('Najprv klikni na obrázok'); const cw = GD.dims().W - GD.dims().ml - GD.dims().mr; const w = Math.min(img.naturalWidth, Math.round(cw)); img.width = w; img.height = Math.round(img.naturalHeight * w / img.naturalWidth); GD.markDirty(); placeImgUi(); },
  });

  /* ======================= ODKAZY A ZÁLOŽKY ======================= */
  const linkOf = (n) => { if (!n) return null; const e = n.nodeType === 3 ? n.parentNode : n; const a = e.closest && e.closest('a[href]'); return a && page.contains(a) ? a : null; };
  async function linkDialog() {
    if (!GD.canEdit()) return;
    GD.restore();
    const r = GD.range(); const a = r && linkOf(r.startContainer);
    const targets = [...page.querySelectorAll('h1,h2,h3,h4,h5,h6,p.gd-title,a.gd-bm')].filter(x => x.tagName === 'A' || x.textContent.trim());
    const txt = a ? a.textContent : (r ? r.toString() : '');
    let res = null;
    await GD.dlg(a ? 'Upraviť odkaz' : 'Vložiť odkaz', '<div class="mb-3"><label class="form-label">Text</label><input class="form-control" id="lkT" value="' + esc(txt) + '"' + (r && !r.collapsed && !a ? ' disabled' : '') + '></div>' +
      '<div class="mb-2"><label class="form-label">Odkaz</label><input class="form-control" id="lkU" placeholder="Webová adresa alebo e-mail" value="' + esc(a ? a.getAttribute('href') : '') + '" autofocus></div>' +
      (targets.length ? '<div class="small text-body-secondary mb-1">Nadpisy a záložky v dokumente</div><div class="gd-lklist">' + targets.map((x, i) => '<button type="button" class="gd-lkt" data-i="' + i + '"><i class="bi bi-' + (x.tagName === 'A' ? 'bookmark' : 'type-h' + (x.tagName[1] || 1)) + '"></i>' + esc(x.tagName === 'A' ? 'Záložka: ' + (x.nextSibling ? (x.nextSibling.textContent || '').slice(0, 40) : x.id) : x.textContent.trim().slice(0, 60)) + '</button>').join('') + '</div>' : '') +
      (a ? '<button type="button" class="btn btn-sm btn-outline-danger mt-3" id="lkDel"><i class="bi bi-link-45deg"></i> Odstrániť odkaz</button>' : ''),
    { init: (el, m) => {
      el.querySelectorAll('.gd-lkt').forEach(b => b.onclick = () => { const x = targets[+b.dataset.i]; if (!x.id) x.id = (x.tagName === 'A' ? 'bm.' : 'h.') + Math.random().toString(36).slice(2, 10); el.querySelector('#lkU').value = '#' + x.id; if (!el.querySelector('#lkT').value && !el.querySelector('#lkT').disabled) el.querySelector('#lkT').value = x.tagName === 'A' ? 'záložka' : x.textContent.trim(); });
      const d = el.querySelector('#lkDel'); if (d) d.onclick = () => { res = { del: true }; m.hide(); };
    }, onOk: (el) => { res = { t: el.querySelector('#lkT').value, u: el.querySelector('#lkU').value.trim() }; } });
    if (!res) return;
    GD.restore();
    if (res.del && a) { const rr = document.createRange(); rr.selectNodeContents(a); const s = getSelection(); s.removeAllRanges(); s.addRange(rr); document.execCommand('unlink'); GD.markDirty(); return; }
    if (!res.u) return;
    let href = res.u;
    if (!/^(https?:|mailto:|tel:|#)/i.test(href)) href = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(href) ? 'mailto:' + href : 'https://' + href;
    if (a) { a.setAttribute('href', href); if (res.t && res.t !== a.textContent) a.textContent = res.t; }
    else if (r && !r.collapsed) document.execCommand('createLink', false, href);
    else document.execCommand('insertHTML', false, '<a href="' + esc(href) + '">' + esc(res.t || res.u) + '</a>&nbsp;');
    GD.markDirty();
  }
  // bublina pri odkaze
  const cbox = $('#gdCbox');
  GD.on('sel', (r) => {
    const a = r && r.collapsed && linkOf(r.startContainer);
    if (!a) { cbox.classList.add('d-none'); return; }
    const rc = a.getBoundingClientRect(), h = a.getAttribute('href');
    cbox.innerHTML = '<i class="bi bi-' + (h.startsWith('#') ? 'bookmark' : 'globe2') + '"></i><a href="' + esc(h.startsWith('#') ? '#' : a.href) + '" target="_blank" rel="noopener" data-lk="open">' + esc(h.startsWith('#') ? 'Odkaz v dokumente' : h.replace(/^https?:\/\//, '').slice(0, 48)) + '</a>' +
      '<button data-lk="copy" title="Kopírovať odkaz"><i class="bi bi-copy"></i></button>' + (GD.canEdit() ? '<button data-lk="edit" title="Upraviť odkaz"><i class="bi bi-pencil"></i></button><button data-lk="del" title="Odstrániť odkaz"><i class="bi bi-link-45deg"></i></button>' : '');
    cbox.style.left = Math.min(innerWidth - 340, rc.left) + 'px'; cbox.style.top = (rc.bottom + 6) + 'px';
    cbox.classList.remove('d-none'); cbox._a = a;
  });
  cbox.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  cbox.addEventListener('click', (e) => {
    const b = e.target.closest('[data-lk]'); if (!b) return; const a = cbox._a; if (!a) return;
    const k = b.dataset.lk, h = a.getAttribute('href');
    if (k === 'open' && h.startsWith('#')) { e.preventDefault(); const t = document.getElementById(h.slice(1)); t && GD.scrollToEl(t); }
    if (k === 'copy') { navigator.clipboard && navigator.clipboard.writeText(a.href).then(() => GD.toast('Odkaz skopírovaný')); }
    if (k === 'edit') linkDialog();
    if (k === 'del') { const r = document.createRange(); r.selectNodeContents(a); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('unlink'); GD.markDirty(); cbox.classList.add('d-none'); }
  });
  $('#gdMain').addEventListener('scroll', () => cbox.classList.add('d-none'), { passive: true });
  function bookmark() {
    if (!GD.canEdit()) return;
    GD.restore();
    const id = 'bm.' + Math.random().toString(36).slice(2, 10);
    document.execCommand('insertHTML', false, '<a class="gd-bm" id="' + id + '" contenteditable="false"></a>&#8203;');
    GD.markDirty(); GD.toast('Záložka je vložená – odkaz na ňu vytvoríš cez Vložiť → Odkaz');
  }

  /* ======================= KONTEXTOVÁ PONUKA ======================= */
  const ctx = document.createElement('ul'); ctx.className = 'gm-menu'; ctx.id = 'gdCtx'; document.body.appendChild(ctx);
  const anchor = document.createElement('div'); anchor.style.cssText = 'position:fixed;width:1px;height:1px'; document.body.appendChild(anchor);
  const it = (a, icon, t, kbd) => '<li><button class="gm-i" data-gs="' + a + '"><i class="bi bi-' + icon + '"></i><span>' + t + '</span>' + (kbd ? '<kbd>' + kbd + '</kbd>' : '') + '</button></li>';
  page.addEventListener('contextmenu', (e) => {
    if (e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    const r = GD.range(); const ed = GD.canEdit();
    // pravý klik mimo výberu presunie kurzor
    if (r && r.collapsed || !r) { const p = document.caretRangeFromPoint && document.caretRangeFromPoint(e.clientX, e.clientY); if (p && page.contains(p.startContainer)) { const s = getSelection(); s.removeAllRanges(); s.addRange(p); } }
    const td = curCell(), a = linkOf((GD.range() || {}).startContainer), img = e.target.tagName === 'IMG';
    let h = '';
    if (ed) h += it('cut', 'scissors', 'Vystrihnúť', 'Ctrl+X');
    h += it('copytext', 'copy', 'Kopírovať', 'Ctrl+C');
    if (ed) h += it('paste', 'clipboard', 'Prilepiť', 'Ctrl+V') + it('pasteplain', 'clipboard-minus', 'Prilepiť bez formátovania', 'Ctrl+Shift+V') + it('delete', 'backspace', 'Odstrániť');
    h += '<li class="gm-sep"></li>';
    if (!S.ro) h += it('comment', 'chat-square-text', 'Komentár', 'Ctrl+Alt+M');
    if (ed) h += a ? it('link', 'pencil', 'Upraviť odkaz', 'Ctrl+K') : it('link', 'link-45deg', 'Vložiť odkaz', 'Ctrl+K');
    if (img && ed) h += '<li class="gm-sep"></li>' + it('img:opts', 'sliders', 'Možnosti obrázka') + it('img:reset', 'arrow-counterclockwise', 'Obnoviť veľkosť');
    if (td && ed) h += '<li class="gm-sep"></li>' + it('tbl:rowabove', 'arrow-bar-up', 'Vložiť riadok nad') + it('tbl:rowbelow', 'arrow-bar-down', 'Vložiť riadok pod') + it('tbl:colleft', 'arrow-bar-left', 'Vložiť stĺpec vľavo') + it('tbl:colright', 'arrow-bar-right', 'Vložiť stĺpec vpravo') +
      it('tbl:delrow', 'dash-square', 'Odstrániť riadok') + it('tbl:delcol', 'dash-square', 'Odstrániť stĺpec') + it('tbl:del', 'trash3', 'Odstrániť tabuľku') + it('tbl:merge', 'arrows-angle-expand', 'Zlúčiť bunky') + it('tbl:unmerge', 'arrows-angle-contract', 'Zrušiť zlúčenie') + it('tbl:props', 'sliders', 'Vlastnosti tabuľky');
    if (ed) h += '<li class="gm-sep"></li>' + it('clearfmt', 'eraser', 'Vymazať formátovanie', 'Ctrl+\\');
    ctx.innerHTML = h;
    anchor.style.left = e.clientX + 'px'; anchor.style.top = (e.clientY - 2) + 'px';
    GMenu.openMenu(anchor, ctx, 0, false);
  });

  /* ======================= ĎALŠIE VKLADANIE ======================= */
  const months = ['januára', 'februára', 'marca', 'apríla', 'mája', 'júna', 'júla', 'augusta', 'septembra', 'októbra', 'novembra', 'decembra'];
  async function insertDate() {
    if (!GD.canEdit()) return;
    const d = new Date(), opts = [d.getDate() + '. ' + months[d.getMonth()] + ' ' + d.getFullYear(), d.toLocaleDateString('sk-SK'), d.getDate() + '. ' + (d.getMonth() + 1) + '. ' + d.getFullYear(), d.toISOString().slice(0, 10), d.toLocaleDateString('sk-SK', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })];
    let pick = null;
    await GD.dlg('Vložiť dátum', '<input type="date" class="form-control mb-3" id="dtD" value="' + d.toISOString().slice(0, 10) + '"><div class="list-group" id="dtL"></div>', { noFooter: true,
      init: (el, m) => {
        const draw = () => { const x = new Date(el.querySelector('#dtD').value + 'T12:00'); if (isNaN(x)) return; const o = [x.getDate() + '. ' + months[x.getMonth()] + ' ' + x.getFullYear(), x.getDate() + '. ' + (x.getMonth() + 1) + '. ' + x.getFullYear(), x.toISOString().slice(0, 10), x.toLocaleDateString('sk-SK', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })]; el.querySelector('#dtL').innerHTML = o.map(t => '<button type="button" class="list-group-item list-group-item-action">' + esc(t) + '</button>').join(''); el.querySelectorAll('#dtL button').forEach(b => b.onclick = () => { pick = b.textContent; m.hide(); }); };
        el.querySelector('#dtD').oninput = draw; draw();
      } });
    void opts;
    if (pick) { GD.restore(); document.execCommand('insertText', false, pick); GD.markDirty(); }
  }
  // hlasové písanie (rozpoznávanie reči robí prehliadač)
  let rec = null;
  function voice() {
    if (!GD.canEdit()) return;
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) return GD.toast('Tento prehliadač hlasové písanie nepodporuje (funguje v Chrome a Edge)', 'err');
    if (rec) { rec.stop(); return; }
    if (!GD.store.get('voiceok', false)) {
      GD.ask('Hlasové písanie', 'Reč rozpoznáva prehliadač – Chrome a Edge pri tom posielajú zvuk na svoje servery (Google / Microsoft). Cloud sám nič neposiela. Pokračovať?', [['no', 'Zrušiť'], ['yes', 'Zapnúť', 'btn-primary']]).then(r => { if (r === 'yes') { GD.store.set('voiceok', true); voice(); } });
      return;
    }
    rec = new SR(); rec.lang = 'sk-SK'; rec.continuous = true; rec.interimResults = false;
    const b = document.createElement('button'); b.className = 'gd-mic'; b.innerHTML = '<i class="bi bi-mic-fill"></i><span>Počúvam… klikni na ukončenie</span>'; b.onclick = () => rec && rec.stop(); document.body.appendChild(b);
    rec.onresult = (ev) => { for (let i = ev.resultIndex; i < ev.results.length; i++) if (ev.results[i].isFinal) { let t = ev.results[i][0].transcript.trim(); t = t.replace(/\s*(bodka)\s*/gi, '. ').replace(/\s*(čiarka)\s*/gi, ', ').replace(/\s*(otáznik)\s*/gi, '? ').replace(/\s*(nový riadok)\s*/gi, '\n'); GD.restore(); const r = GD.range(); const prev = r && r.startContainer.nodeType === 3 ? r.startContainer.nodeValue.slice(0, r.startOffset) : ''; if (prev && !/\s$/.test(prev)) t = ' ' + t; t.split('\n').forEach((p, j) => { if (j) document.execCommand('insertParagraph'); if (p) document.execCommand('insertText', false, p); }); GD.markDirty(); } };
    rec.onerror = (ev) => { if (ev.error !== 'no-speech' && ev.error !== 'aborted') GD.toast('Hlasové písanie: ' + (ev.error === 'not-allowed' ? 'prehliadač nemá povolený mikrofón' : ev.error), 'err'); };
    rec.onend = () => { rec = null; b.remove(); };
    try { rec.start(); } catch (e) { rec = null; b.remove(); }
  }

  Object.assign(GD.act, {
    comment: addComment, comments: () => S.view.comments ? closeSide() : openSide('all'), review: () => openSide('sugg'),
    link: linkDialog, bookmark, date: insertDate, voice,
    hrule: () => { if (!GD.canEdit()) return; GD.restore(); document.execCommand('insertHTML', false, '<hr class="gd-hr"><p><br></p>'); GD.markDirty(); GD.emit('structure'); },
  });
  GD.countBadge = countBadge;
})();
