/* Editor tabuliek (sheet.php): Luckysheet so slovenským prostredím, ponukami ako v Google Tabuľkách
   a automatickým ukladaním. Každé uloženie je nová verzia súboru (staré ostávajú vo Verziách). */
(function () {
  const C = window.CLOUD_SHEET; if (!C) return;
  const IO = window.CloudSheetIO;
  const $ = (s) => document.querySelector(s);
  const base = document.querySelector('meta[name="base-url"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const extOf = (n) => (n.match(/\.([a-z0-9]+)$/i) || [])[1]?.toLowerCase() || '';
  const inFrame = window.parent && window.parent !== window;
  const S = { ext: extOf(C.name), ro: !C.canEdit, dirty: false, saving: false, again: false, updated: C.updated, id: C.id, name: C.name, saveAs: null, csv: null, ready: false, t: null };
  window.CloudSheetEditor = S;

  const post = (m) => { if (inFrame) try { parent.postMessage(Object.assign({ cloudSheet: m.ev }, m), location.origin); } catch (e) {} };
  function status(t, cls) { const e = $('#gsStatus'); e.textContent = t; e.className = 'gs-status ' + (cls || ''); }
  function toast(msg, type) {
    let w = $('#gsToasts'); if (!w) { w = document.createElement('div'); w.id = 'gsToasts'; document.body.appendChild(w); }
    const d = document.createElement('div'); d.className = 'gs-toast ' + (type || ''); d.textContent = msg; w.appendChild(d);
    setTimeout(() => d.classList.add('out'), 3500); setTimeout(() => d.remove(), 4000);
  }
  /** Jednoduché okno s otázkou (Bootstrap). Vráti id stlačeného tlačidla alebo null. */
  function ask(title, text, buttons) {
    return new Promise((res) => {
      const el = document.createElement('div');
      el.className = 'modal fade'; el.tabIndex = -1;
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5></div><div class="modal-body"></div><div class="modal-footer"></div></div></div>';
      el.querySelector('.modal-title').textContent = title; el.querySelector('.modal-body').textContent = text;
      let val = null;
      buttons.forEach(([id, label, cls]) => { const b = document.createElement('button'); b.className = 'btn rounded-pill px-3 ' + (cls || 'btn-outline-secondary'); b.textContent = label; b.onclick = () => { val = id; m.hide(); }; el.querySelector('.modal-footer').appendChild(b); });
      document.body.appendChild(el);
      const m = new bootstrap.Modal(el, { backdrop: 'static' });
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
      let sheets, warn = [];
      if (S.ext === 'csv') { sheets = IO.fromCsv(buf); S.csv = sheets[0]._csv; delete sheets[0]._csv; }
      else if (S.ext === 'xlsx' || S.ext === 'xlsm') {
        warn = IO.unsupported(buf, S.ext);
        try { sheets = buf.byteLength ? await IO.fromXlsx(buf) : [IO.blankSheet('Hárok1', 0)]; }
        catch (e) { sheets = IO.fromSheetJS(XLSX.read(buf, { type: 'array', cellFormula: true, cellNF: true })); warn.push('formátovanie, ktoré sa nedá načítať'); }
      } else sheets = IO.fromSheetJS(XLSX.read(buf, { type: 'array', cellFormula: true, cellNF: true }));
      if (S.ext === 'xls' || S.ext === 'xlsm') S.saveAs = C.name.replace(/\.(xls|xlsm)$/i, '.xlsx');
      if (warn.length && !S.ro) {
        const r = await ask('Súbor obsahuje: ' + warn.join(', '), 'Tieto časti editor nezobrazí a pri uložení by sa zo súboru stratili (pôvodná verzia by ostala vo Verziách). Odporúčam otvoriť iba na čítanie.',
          [['edit', 'Upravovať aj tak'], ['ro', 'Iba na čítanie', 'btn-primary']]);
        S.ro = r !== 'edit';
      }
      // grafy uložené pri poslednej úprave v Cloude
      const ex = C.extras && C.extras.sheets;
      if (ex) sheets.forEach(sh => { const x = ex[sh.name]; if (x && Array.isArray(x.chart) && x.chart.length) sh.chart = x.chart.map(ch => ({ ...ch, sheetIndex: sh.index, chartOptions: ch.chartOptions ? { ...ch.chartOptions, sheetIndex: sh.index } : ch.chartOptions })); });
      sheets.forEach(sh => { // vzorce sa po otvorení prepočítajú
        sh.calcChain = sh.celldata.filter(x => x.v && x.v.f).map(x => ({ r: x.r, c: x.c, index: sh.index }));
        let mr = 0, mc = 0; sh.celldata.forEach(x => { mr = Math.max(mr, x.r); mc = Math.max(mc, x.c); });
        sh.row = Math.max(100, mr + 30); sh.column = Math.max(26, mc + 6);
      });
      if (sheets.length && !sheets.some(s => s.status == 1 && !s.hide)) { const v = sheets.find(s => !s.hide) || sheets[0]; v.status = 1; }
      create(sheets);
    } catch (e) {
      $('#gsLoad').innerHTML = '<i class="bi bi-exclamation-triangle fs-1 text-danger mb-2"></i><div>' + String(e.message || 'Tabuľku sa nepodarilo otvoriť.').replace(/</g, '&lt;') + '</div>';
    }
  }

  /* zobrazenie čísel po slovensky (1 234,56) a čísla zarovnané doprava ako v Exceli */
  const skNum = (m) => String(m).replace(/[\d,.]+/g, s => /\d/.test(s) ? s.replace(/,/g, ' ').replace(/\./g, ',') : s);
  function renderBefore(cell) {
    if (!cell || typeof cell !== 'object' || !cell.ct) return;
    const t = cell.ct.t, num = t === 'n' || (cell.f && typeof cell.v === 'number' && t !== 'd' && t !== 's');
    if (num && cell.m !== undefined && cell.m !== null && cell.m !== cell._sk) cell._sk = cell.m = skNum(cell.m);
    if ((num || t === 'd') && (cell.ht === undefined || cell.ht === null)) { cell.ht = 2; cell._ah = 1; }
    else if (cell._ah && !(num || t === 'd')) { delete cell.ht; delete cell._ah; }
  }
  /* čo človek napíše po slovensky: 3,5 · 1 200 € · 15 % · 24.12.2026 · =SUM(A1;A2) */
  const fixing = new Set();
  function cellUpdated(r, c, old, nv) {
    if (!nv || typeof nv !== 'object' || fixing.has(r + '_' + c)) return;
    let patch = null;
    if (nv.f && /;/.test(nv.f)) patch = IO.fixFormula(nv.f);
    else if (!nv.f && typeof nv.v === 'string') { const p = IO.parseLocal(nv.v); if (p) patch = { v: p.v, ct: p.ct }; }
    if (patch === null) return;
    const k = r + '_' + c; fixing.add(k);
    setTimeout(() => { try { luckysheet.setCellValue(r, c, patch); } catch (e) {} fixing.delete(k); }, 0);
  }

  function create(sheets) {
    const ro = S.ro;
    $('#gsRo').classList.toggle('d-none', !ro);
    document.body.classList.toggle('ro', ro);
    luckysheet.create({
      container: 'gsSheet', lang: 'en', title: C.name, showinfobar: false, allowEdit: !ro, showtoolbar: !ro,
      plugins: ['chart'], forceCalculation: true, enableAddRow: !ro, enableAddBackTop: true, defaultFontSize: 11,
      showtoolbarConfig: { pivotTable: false, screenshot: false, protection: false, print: false },
      showsheetbarConfig: { add: !ro, menu: true, sheet: true },
      sheetRightClickConfig: ro ? { delete: false, copy: false, rename: false, color: false, hide: false, move: false } : {},
      cellRightClickConfig: { matrix: false, copyAs: false, chart: !ro, image: false, link: false, data: false, cellFormat: !ro },
      data: sheets,
      hook: {
        cellRenderBefore: renderBefore,
        cellUpdated,
        updated: () => markDirty(),
        sheetActivate: () => markDirty(true),
        workbookCreateAfter: () => {
          S.ready = true; $('#gsLoad').classList.add('d-none');
          if (S.saveAs) status('Uloží sa ako ' + S.saveAs + ' (formát .' + S.ext + ' sa nedá zapisovať)');
          else status(ro ? '' : 'Zmeny sa ukladajú samy');
          post({ ev: 'ready' });
          setTimeout(() => { S.sig = sig(); }, 300);
        },
      },
    });
  }
  /* grafy, obrázky, ukotvenie, filtre… Luckysheet nehlási ako „updated“ – stačí porovnať ich stav */
  /** Kópia bez Vue „pozorovateľov“, funkcií a cyklov (nastavenia grafov ich obsahujú). */
  function clean(x, stack) {
    if (x === null || typeof x !== 'object') return typeof x === 'function' ? undefined : x;
    stack = stack || [];
    if (stack.includes(x)) return undefined;
    stack.push(x);
    let out;
    if (Array.isArray(x)) out = x.map(v => clean(v, stack));
    else { out = {}; for (const k in x) { if (k === '__ob__') continue; const v = clean(x[k], stack); if (v !== undefined) out[k] = v; } }
    stack.pop();
    return out;
  }
  const safeJson = (x) => JSON.stringify(clean(x));
  function sig() {
    try {
      return safeJson(luckysheet.getluckysheetfile(true).map(s => [s.name, s.order, s.hide, s.color, s.chart, s.images, s.frozen, s.filter_select, s.luckysheet_conditionformat_save, s.dataVerification, s.hyperlink, s.config, s.luckysheet_alternateformat_save]));
    } catch (e) { return ''; }
  }
  setInterval(() => {
    if (!S.ready || S.ro || S.saving) return;
    const x = sig();
    if (S.sig === undefined) return;
    if (x !== S.sig) { S.sig = x; markDirty(); }
  }, 2000);

  /* ---------- ukladanie ---------- */
  function markDirty(soft) {
    if (!S.ready || S.ro || soft === true) return;
    S.dirty = true;
    status('Neuložené zmeny…', 'warn');
    clearTimeout(S.t); S.t = setTimeout(() => save(true), 2500);
  }
  function gather() {
    try { luckysheet.exitEditMode(); } catch (e) {}
    try { luckysheet.getluckysheetfile(true); } catch (e) {} // doplní nastavenia grafov
    return luckysheet.getAllSheets().map(sh => { const o = clean(sh); delete o.data; return o; });
  }
  function extrasOf(sheets) {
    const out = {}; let any = false;
    sheets.forEach(sh => { if (Array.isArray(sh.chart) && sh.chart.length) { out[sh.name] = { chart: sh.chart.map(ch => ({ ...ch, needRangeShow: false })) }; any = true; } });
    return any ? safeJson({ v: 1, sheets: out }) : '';
  }
  async function build(type, sheets) {
    sheets = sheets || gather();
    if (type === 'csv') {
      const cur = luckysheet.getSheet ? luckysheet.getSheet() : null;
      const sh = (cur && sheets.find(x => x.index === cur.index)) || sheets[0];
      return IO.toCsv([sh], S.csv || { FS: ';', comma: true });
    }
    if (type === 'ods') return IO.toOds(sheets);
    return IO.toXlsx(sheets);
  }
  async function save(auto) {
    if (S.ro || !S.ready) return true;
    if (S.saving) { S.again = true; return false; }
    if (auto && !S.dirty) return true;
    clearTimeout(S.t);
    S.saving = true; S.dirty = false; status('Ukladá sa…', 'busy');
    try {
      const sheets = gather();
      const type = S.saveAs ? 'xlsx' : S.ext === 'ods' ? 'ods' : S.ext === 'csv' ? 'csv' : 'xlsx';
      const blob = await build(type, sheets);
      const fd = new FormData();
      fd.append('data', blob, S.saveAs || S.name);
      if (type === 'xlsx') fd.append('extras', extrasOf(sheets));
      if (S.saveAs) { fd.append('parent', C.folder || ''); fd.append('name', S.saveAs); }
      else { fd.append('id', S.id); fd.append('updated', S.updated || ''); }
      const res = await fetch(base + 'api.php?action=save_binary', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
      const r = await res.json().catch(() => ({ ok: false, error: 'Neočakávaná odpoveď servera (' + res.status + ')' }));
      if (!r.ok) throw new Error(r.error || 'Uloženie zlyhalo');
      if (S.saveAs) { S.id = r.id; S.name = r.name; S.ext = 'xlsx'; S.saveAs = null; $('#gsName').textContent = r.name; document.title = r.name; }
      S.updated = r.updated || ''; S.sig = sig();
      status('Všetky zmeny sú uložené v Cloude', 'ok');
      post({ ev: 'saved', id: S.id, name: S.name, updated: S.updated });
      return true;
    } catch (e) {
      S.dirty = true;
      status(e.message || 'Uloženie zlyhalo', 'err');
      if (!auto) toast(e.message || 'Uloženie zlyhalo', 'err');
      return false;
    } finally {
      S.saving = false;
      if (S.again) { S.again = false; if (S.dirty) { clearTimeout(S.t); S.t = setTimeout(() => save(true), 300); } }
    }
  }
  async function download(type) {
    const blob = await build(type);
    const nm = (S.saveAs || S.name).replace(/\.[^.]+$/, '') + '.' + type;
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nm; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }
  async function close() {
    if (S.dirty || S.saving) {
      status('Ukladá sa…', 'busy');
      for (let i = 0; i < 50 && S.saving; i++) await new Promise(r => setTimeout(r, 100));
      if (S.dirty && !(await save(false))) {
        const r = await ask('Zmeny sa nepodarilo uložiť', 'Ak teraz zavrieš tabuľku, posledné zmeny sa stratia. Môžeš si ich stiahnuť cez Súbor → Stiahnuť ako.', [['stay', 'Ostať', 'btn-primary'], ['leave', 'Zavrieť bez uloženia']]);
        if (r !== 'leave') { post({ ev: 'stay' }); return; }
      }
    }
    S.dirty = false;
    if (inFrame) post({ ev: 'close' }); else location.href = C.drive;
  }
  window.addEventListener('message', (e) => { if (e.origin === location.origin && e.data && e.data.cloudSheetCmd === 'close') close(); });
  window.addEventListener('beforeunload', (e) => { if (S.dirty && !inFrame) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- ponuky ---------- */
  const range = () => { const r = luckysheet.getRange(); return r && r[0] ? r[0] : { row: [0, 0], column: [0, 0] }; };
  const click = (sel) => { const el = document.querySelector(sel); if (el) { window.jQuery ? jQuery(el).trigger('click') : el.click(); } };
  const fmtType = (fa) => fa === '@' ? 's' : fa === 'General' ? 'g' : IO.isDateFmt(fa) ? 'd' : 'n';
  function setFmt(fa) {
    const rg = range();
    if ((rg.row[1] - rg.row[0] + 1) * (rg.column[1] - rg.column[0] + 1) > 20000) return toast('Vyber menšiu oblasť', 'err');
    luckysheet.setRangeFormat('ct', { fa, t: fmtType(fa) });
  }
  function toggle(attr) {
    const rg = range(); const cell = luckysheet.getCellValue(rg.row[0], rg.column[0], { type: attr });
    luckysheet.setRangeFormat(attr, cell == 1 ? 0 : 1);
  }
  function clearFormat() {
    const rg = range();
    for (const a of ['bl', 'it', 'un', 'cl', 'fc', 'bg', 'fs', 'ff', 'ht', 'vt', 'tb', 'tr']) { try { luckysheet.setRangeFormat(a, a === 'fc' ? '#000000' : a === 'bg' ? null : a === 'fs' ? 10 : a === 'ff' ? 0 : a === 'tb' ? '1' : a === 'tr' ? '0' : a === 'vt' ? 0 : a === 'ht' ? 1 : 0); } catch (e) {} }
    luckysheet.setRangeFormat('ct', { fa: 'General', t: 'g' });
    markDirty();
  }
  function insertFn(name) {
    const rg = range(), r = rg.row[1] + 1, c = rg.column[0];
    if (rg.row[0] === rg.row[1] && rg.column[0] === rg.column[1]) {
      // bez výberu: súčet stĺpca nad bunkou
      let top = rg.row[0] - 1; while (top >= 0 && luckysheet.getCellValue(top, c) !== null && luckysheet.getCellValue(top, c) !== '') top--;
      if (top + 1 > rg.row[0] - 1) { luckysheet.setCellValue(rg.row[0], c, '=' + name + '()'); return; }
      luckysheet.setCellValue(rg.row[0], c, '=' + name + '(' + IO.addr(top + 1, c) + ':' + IO.addr(rg.row[0] - 1, c) + ')');
      return;
    }
    luckysheet.setCellValue(r, c, '=' + name + '(' + IO.addr(rg.row[0], c) + ':' + IO.addr(rg.row[1], c) + ')');
    luckysheet.setRangeShow(IO.addr(r, c));
  }
  const ACT = {
    close, save: () => save(false), undo: () => luckysheet.undo(), redo: () => luckysheet.redo(),
    find: () => { click('#luckysheet-icon-seachmore'); setTimeout(() => click('#luckysheet-icon-seachmore-menuButton .luckysheet-cols-menuitem:nth-child(2), #luckysheet-icon-seachmore-menuButton [itemvalue="replace"]'), 50); },
    clear: () => luckysheet.clearRange(),
    delrow: () => { const rg = range(); luckysheet.deleteRow(rg.row[0], rg.row[1]); },
    delcol: () => { const rg = range(); luckysheet.deleteColumn(rg.column[0], rg.column[1]); },
    'freeze:row': () => luckysheet.setHorizontalFrozen(false), 'freeze:col': () => luckysheet.setVerticalFrozen(false),
    'freeze:rows': () => { const rg = range(); luckysheet.setHorizontalFrozen(true, { range: { row_focus: rg.row[0], column_focus: 0 } }); },
    'freeze:both': () => { const rg = range(); luckysheet.setBothFrozen(true, { range: { row_focus: rg.row[0], column_focus: rg.column[0] } }); }, 'freeze:off': () => luckysheet.cancelFrozen(),
    'row:above': () => luckysheet.insertRow(range().row[0]), 'row:below': () => luckysheet.insertRow(range().row[1] + 1),
    'col:left': () => luckysheet.insertColumn(range().column[0]), 'col:right': () => luckysheet.insertColumn(range().column[1] + 1),
    chart: () => click('#luckysheet-chart-btn-title'), image: () => click('#luckysheet-insertImg-btn-title'), link: () => click('#luckysheet-insertLink-btn-title'),
    comment: () => { click('#luckysheet-icon-postil'); setTimeout(() => click('#luckysheet-icon-postil-menuButton .luckysheet-cols-menuitem:first-child'), 50); },
    'fn:SUM': () => insertFn('SUM'), 'fn:AVERAGE': () => insertFn('AVERAGE'), 'fn:more': () => { click('#luckysheet-icon-function-menu'); setTimeout(() => click('#luckysheet-icon-function-menu-menuButton .luckysheet-cols-menuitem:last-child'), 50); },
    dropdown: () => click('#luckysheet-dataVerification-btn-title'), split: () => click('#luckysheet-splitColumn-btn-title'),
    sheet: () => luckysheet.setSheetAdd(), 'fmt:more': () => click('#luckysheet-icon-fmt-other'),
    wrap: () => luckysheet.setRangeFormat('tb', '2'), merge: () => luckysheet.setRangeMerge('all'), unmerge: () => luckysheet.cancelRangeMerge(),
    cf: () => click('#luckysheet-icon-conditionformat'), clearfmt: clearFormat,
    'sort:asc': () => luckysheet.setRangeSort('asc'), 'sort:desc': () => luckysheet.setRangeSort('desc'),
    'filter:open': () => luckysheet.setRangeFilter('open'), 'filter:close': () => luckysheet.setRangeFilter('close'),
    keys: () => bootstrap.Modal.getOrCreateInstance($('#gsKeys')).show(),
  };
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-gs]'); if (!b) return;
    e.preventDefault();
    const a = b.dataset.gs;
    if (!S.ready && a !== 'close') return;
    if (S.ro && b.hasAttribute('data-rw')) return;
    try {
      if (a.startsWith('dl:')) return download(a.slice(3));
      if (a.startsWith('fmt:') && a !== 'fmt:more') return setFmt(a.slice(4));
      if (a.startsWith('st:')) return toggle(a.slice(3));
      if (a.startsWith('zoom:')) return luckysheet.setSheetZoom(+a.slice(5));
      ACT[a] && ACT[a]();
    } catch (er) { toast(er.message || String(er), 'err'); }
  });
  document.addEventListener('keydown', (e) => {
    const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
    if (ctrl && k === 's') { e.preventDefault(); save(false); }
    else if (ctrl && k === 'k' && !S.ro) { e.preventDefault(); ACT.link(); }
    else if (ctrl && e.key === '\\' && !S.ro) { e.preventDefault(); clearFormat(); }
    else if (ctrl && k === 'h') { e.preventDefault(); ACT.find(); }
  }, true);
  // Android: tlačidlo Späť zatvorí editor
  window.cloudBack = () => { close(); return true; };

  open();
})();
