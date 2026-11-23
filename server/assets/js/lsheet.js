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
      if (ex) sheets.forEach(sh => { const x = ex[sh.name]; if (x && x.authority) { sh.config = sh.config || {}; sh.config.authority = x.authority; } });
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
    if (!cell || typeof cell !== 'object') return;
    if (S.showF && cell.f) { if (cell._m === undefined) cell._m = cell.m ?? null; cell.m = cell.f; return; }
    if (!S.showF && cell._m !== undefined) { cell.m = cell._m; delete cell._m; delete cell._sk; }
    if (!cell.ct) return;
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
      showtoolbarConfig: { pivotTable: !ro, screenshot: false, protection: !ro, print: false },
      showsheetbarConfig: { add: !ro, menu: true, sheet: true },
      sheetRightClickConfig: ro ? { delete: false, copy: false, rename: false, color: false, hide: false, move: false } : {},
      cellRightClickConfig: { matrix: !ro, copyAs: false, chart: !ro, image: false, link: false, data: false, cellFormat: !ro },
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
          setTimeout(toolbarExtras, 0);
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
    sheets.forEach(sh => {
      const o = {};
      if (Array.isArray(sh.chart) && sh.chart.length) o.chart = sh.chart.map(ch => ({ ...ch, needRangeShow: false }));
      const au = sh.config && sh.config.authority; if (au && au.sheet) o.authority = au;
      if (Object.keys(o).length) { out[sh.name] = o; any = true; }
    });
    return any ? safeJson({ v: 1, sheets: out }) : '';
  }
  async function build(type, sheets) {
    sheets = sheets || gather();
    if (type === 'csv' || type === 'tsv') {
      const cur = luckysheet.getSheet ? luckysheet.getSheet() : null;
      const sh = (cur && sheets.find(x => x.index === cur.index)) || sheets[0];
      return IO.toCsv([sh], type === 'tsv' ? { FS: '\t', comma: true } : (S.csv || { FS: ';', comma: true }));
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
  const click = (sel) => { const el = document.querySelector(sel); if (el) { window.jQuery ? jQuery(el).trigger('click') : el.click(); } return !!el; };
  const fmtType = (fa) => fa === '@' ? 's' : fa === 'General' ? 'g' : IO.isDateFmt(fa) ? 'd' : 'n';
  const esc = (x) => String(x ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const canEdit = () => !S.ro;
  const cells = () => { const rg = range(); return (rg.row[1] - rg.row[0] + 1) * (rg.column[1] - rg.column[0] + 1); };
  const big = (n) => { if (cells() > (n || 20000)) { toast('Vyber menšiu oblasť', 'err'); return true; } return false; };
  const data = () => luckysheet.getSheetData() || [];
  const clone = (x) => x == null ? null : JSON.parse(JSON.stringify(clean(x)));
  const sheetObj = () => luckysheet.getSheet ? luckysheet.getSheet() : null;
  function usedSize() { const d = data(); let mr = -1, mc = -1; d.forEach((row, r) => (row || []).forEach((c, ci) => { if (c && (c.v !== undefined && c.v !== null && c.v !== '' || c.f)) { if (r > mr) mr = r; if (ci > mc) mc = ci; } })); return { r: mr, c: mc }; }
  /** setRangeValue, ktorý hárok najprv zväčší (Luckysheet inak zlyhá mimo hárka) */
  function setRange(arr, opt) {
    const rg = opt.range, d = data(), rows = d.length, cols = (d[0] || []).length;
    if (rg.row[1] >= rows) luckysheet.insertRow(Math.max(0, rows - 1), { number: rg.row[1] - rows + 20 });
    if (rg.column[1] >= cols) luckysheet.insertColumn(Math.max(0, cols - 1), { number: rg.column[1] - cols + 5 });
    return luckysheet.setRangeValue(arr, opt);
  }
  window.CloudSheetSet = setRange;
  function setFmt(fa) { if (big()) return; luckysheet.setRangeFormat('ct', { fa, t: fmtType(fa) }); }
  function toggle(attr) { const rg = range(); const cell = luckysheet.getCellValue(rg.row[0], rg.column[0], { type: attr }); luckysheet.setRangeFormat(attr, cell == 1 ? 0 : 1); }
  function clearFormat() {
    if (big()) return;
    for (const a of ['bl', 'it', 'un', 'cl', 'fc', 'bg', 'fs', 'ff', 'ht', 'vt', 'tb', 'tr']) { try { luckysheet.setRangeFormat(a, a === 'fc' ? '#000000' : a === 'bg' ? null : a === 'fs' ? 11 : a === 'ff' ? 0 : a === 'tb' ? '1' : a === 'tr' ? '0' : a === 'vt' ? 0 : a === 'ht' ? 1 : 0); } catch (e) {} }
    luckysheet.setRangeFormat('ct', { fa: 'General', t: 'g' });
    markDirty();
  }
  function insertFn(name) {
    const rg = range(), r = rg.row[1] + 1, c = rg.column[0];
    if (rg.row[0] === rg.row[1] && rg.column[0] === rg.column[1]) {
      let top = rg.row[0] - 1; while (top >= 0 && luckysheet.getCellValue(top, c) !== null && luckysheet.getCellValue(top, c) !== '') top--;
      if (top + 1 > rg.row[0] - 1) { luckysheet.setCellValue(rg.row[0], c, '=' + name + '()'); return; }
      luckysheet.setCellValue(rg.row[0], c, '=' + name + '(' + IO.addr(top + 1, c) + ':' + IO.addr(rg.row[0] - 1, c) + ')');
      return;
    }
    luckysheet.setCellValue(r, c, '=' + name + '(' + IO.addr(rg.row[0], c) + ':' + IO.addr(rg.row[1], c) + ')');
    luckysheet.setRangeShow(IO.addr(r, c));
  }
  /** Okno s vlastným obsahom (Bootstrap). */
  function dlg(title, html, opt) {
    opt = opt || {};
    return new Promise((res) => {
      const el = document.createElement('div'); el.className = 'modal fade'; el.tabIndex = -1;
      el.innerHTML = '<div class="modal-dialog modal-dialog-centered ' + (opt.size || '') + ' modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">' + html + '</div>' +
        (opt.noFooter ? '' : '<div class="modal-footer"><button class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">' + (opt.cancel || 'Zrušiť') + '</button>' + (opt.ok === false ? '' : '<button class="btn btn-primary rounded-pill px-3" data-ok>' + (opt.ok || 'Použiť') + '</button>') + '</div>') + '</div></div>';
      el.querySelector('.modal-title').textContent = title;
      document.body.appendChild(el);
      const m = new bootstrap.Modal(el);
      let ok = false;
      const okb = el.querySelector('[data-ok]'); if (okb) okb.onclick = async () => { if (opt.onOk && (await opt.onOk(el)) === false) return; ok = true; m.hide(); };
      el.addEventListener('hidden.bs.modal', () => { opt.onHide && opt.onHide(el); el.remove(); res(ok); });
      el.addEventListener('shown.bs.modal', () => { const f = el.querySelector('.modal-body input:not([type=checkbox]):not([type=radio]), .modal-body select'); f && f.focus(); });
      opt.init && opt.init(el, m);
      m.show();
    });
  }
  async function api(action, d) {
    const fd = new FormData(); Object.entries(d || {}).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach(x => fd.append(k + '[]', x)); else fd.append(k, v ?? ''); });
    const res = await fetch(base + 'api.php?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
    return res.json().catch(() => ({ ok: false, error: 'Neočakávaná odpoveď servera (' + res.status + ')' }));
  }
  window.CloudSheetUI = { dlg, toast, range, esc, data, usedSize, clean, status };

  /* --- súbor --- */
  async function rename() {
    if (S.ro) return;
    const cur = (S.saveAs || S.name).replace(/\.[^.]+$/, ''), ext = (S.saveAs || S.name).match(/\.[^.]+$/)[0];
    let v = null;
    await dlg('Premenovať', '<input class="form-control" id="rnN" value="' + esc(cur) + '">', { ok: 'OK', onOk: (el) => { v = el.querySelector('#rnN').value.trim(); } });
    if (!v || v === cur) return;
    if (S.saveAs) { S.saveAs = v + ext; $('#gsName').textContent = S.saveAs; return; }
    const r = await api('rename', { type: 'f', id: S.id, name: v + ext });
    if (!r.ok) return toast(r.error || 'Premenovanie zlyhalo', 'err');
    S.name = v + ext; $('#gsName').textContent = S.name; document.title = S.name; post({ ev: 'renamed', name: S.name });
  }
  async function newFile(kind) {
    const nm = kind === 'sheet' ? 'Tabuľka bez názvu' : 'Dokument bez názvu', ext = kind === 'sheet' ? '.xlsx' : '.docx';
    let r = null;
    for (let i = 1; i < 30; i++) { r = await api('mkfile', { parent: C.folder || '', name: nm + (i > 1 ? ' (' + i + ')' : '') + ext }); if (r.ok || !/existuje/.test(r.error || '')) break; }
    if (!r || !r.ok) return toast((r && r.error) || 'Nepodarilo sa vytvoriť súbor', 'err');
    await save(false);
    location.href = base + (kind === 'sheet' ? 'sheet.php' : 'doc.php') + '?id=' + r.id + (C.embed ? '&embed=1' : '');
  }
  async function copyFile() { await save(false); const r = await api('copy', { target: C.folder || '', items: ['f:' + S.id] }); r.ok ? toast('Kópia sa vytvorila v rovnakej zložke') : toast(r.error || 'Kópiu sa nepodarilo vytvoriť', 'err'); }
  async function trash() {
    const r0 = await ask('Presunúť do koša?', 'Tabuľka „' + S.name + '“ sa presunie do koša. Odtiaľ ju môžeš obnoviť.', [['no', 'Zrušiť'], ['yes', 'Presunúť do koša', 'btn-danger']]);
    if (r0 !== 'yes') return;
    await save(false);
    const r = await api('delete', { items: ['f:' + S.id] }); if (!r.ok) return toast(r.error || 'Nepodarilo sa', 'err');
    S.dirty = false; S.ro = true; if (inFrame) post({ ev: 'close' }); else location.href = C.drive;
  }
  async function star() {
    const b = $('#gsStar'); const on = !b.classList.contains('on');
    const r = await api('star', { items: ['f:' + S.id], value: on ? 1 : 0 });
    if (r.ok) { b.classList.toggle('on', on); b.querySelector('i').className = 'bi bi-star' + (on ? '-fill' : ''); toast(on ? 'Pridané medzi S hviezdičkou' : 'Odstránené z S hviezdičkou'); }
  }
  const parentAct = async (act) => { if (!inFrame) return toast('Otvor tabuľku z disku, aby si mohol použiť túto funkciu', 'err'); if (act === 'versions') await save(false); post({ ev: 'action', act }); };
  /* import */
  let importMode = 'new';
  $('#gsImport').addEventListener('change', async (e) => {
    const f = e.target.files[0]; e.target.value = ''; if (!f) return;
    try {
      const buf = await f.arrayBuffer(); const ext = (f.name.split('.').pop() || '').toLowerCase();
      let shs;
      if (ext === 'csv' || ext === 'tsv' || ext === 'txt') shs = IO.fromCsv(buf);
      else if (ext === 'xlsx') shs = await IO.fromXlsx(buf);
      else shs = IO.fromSheetJS(XLSX.read(buf, { type: 'array', cellFormula: true, cellNF: true }));
      const src = shs[0]; delete src._csv;
      const cells = (src.celldata || []);
      if (importMode === 'new') {
        luckysheet.setSheetAdd({ sheetObject: { name: f.name.replace(/\.[^.]+$/, '').slice(0, 28) || 'Import', celldata: cells, config: src.config || {}, row: Math.max(100, (cells.reduce((a, x) => Math.max(a, x.r), 0)) + 30), column: Math.max(26, cells.reduce((a, x) => Math.max(a, x.c), 0) + 6) } });
      } else {
        const off = importMode === 'append' ? usedSize().r + 1 : 0;
        if (importMode === 'replace') { const u = usedSize(); if (u.r >= 0) luckysheet.clearRange({ range: { row: [0, u.r], column: [0, Math.max(0, u.c)] } }); }
        const mr = cells.reduce((a, x) => Math.max(a, x.r), 0), mc = cells.reduce((a, x) => Math.max(a, x.c), 0);
        const grid = Array.from({ length: mr + 1 }, () => Array(mc + 1).fill(null)); cells.forEach(x => { grid[x.r][x.c] = x.v; });
        setRange(grid, { range: { row: [off, off + mr], column: [0, mc] } });
      }
      markDirty(); toast('Importované: ' + f.name);
    } catch (er) { console.error(er); toast('Súbor sa nepodarilo importovať', 'err'); }
  });

  /* --- schránka a prilepiť špeciálne --- */
  let clip = null;
  function rememberClip() {
    setTimeout(() => {
      try {
        const rg = range(), d = data(); if ((rg.row[1] - rg.row[0] + 1) * (rg.column[1] - rg.column[0] + 1) > 50000) { clip = null; return; }
        const rows = []; for (let r = rg.row[0]; r <= rg.row[1]; r++) { const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) row.push(clone(d[r] && d[r][c])); rows.push(row); }
        clip = { r0: rg.row[0], c0: rg.column[0], rows };
      } catch (e) { clip = null; }
    }, 0);
  }
  const FMT_KEYS = ['bl', 'it', 'un', 'cl', 'fc', 'bg', 'fs', 'ff', 'ht', 'vt', 'tb', 'tr'];
  function shiftFormula(f, dr, dc) {
    return f.replace(/(\$?)([A-Z]{1,3})(\$?)(\d+)(?![\w(])/g, (m, a, col, b, row) => {
      let ci = 0; for (const ch of col) ci = ci * 26 + ch.charCodeAt(0) - 64; ci--;
      const nc = a ? ci : ci + dc, nr = b ? +row : +row + dr; if (nc < 0 || nr < 1) return '#REF!';
      let s = ''; let n = nc + 1; while (n > 0) { const k = (n - 1) % 26; s = String.fromCharCode(65 + k) + s; n = Math.floor((n - 1) / 26); }
      return a + s + b + nr;
    });
  }
  function pasteSpecial(kind) {
    if (!canEdit()) return;
    if (!clip) return toast('Najprv skopíruj bunky (Ctrl+C)');
    const rg = range(), R0 = rg.row[0], C0 = rg.column[0];
    let rows = clip.rows;
    if (kind === 'transpose') rows = rows[0].map((_, c) => rows.map(r => r[c]));
    const d = data();
    const out = rows.map((row, i) => row.map((src, j) => {
      const r = R0 + i, c = C0 + j, cur = clone(d[r] && d[r][c]) || {};
      if (kind === 'values') { const v = src ? (src.v ?? null) : null; if (v === null) { delete cur.v; delete cur.m; delete cur.f; return Object.keys(cur).length ? cur : null; } const o = { ...cur, v }; delete o.f; if (!o.ct && src.ct) o.ct = src.ct; delete o.m; return o; }
      if (kind === 'format') { const o = { ...cur }; FMT_KEYS.forEach(k => { if (src && src[k] !== undefined) o[k] = src[k]; else delete o[k]; }); if (src && src.ct) o.ct = { ...(o.ct || {}), fa: src.ct.fa, t: o.v !== undefined ? (o.ct && o.ct.t) || src.ct.t : src.ct.t }; return Object.keys(o).length ? o : null; }
      if (kind === 'formula') { if (!src) return cur.v !== undefined ? cur : null; const o = { ...cur }; if (src.f) { o.f = shiftFormula(src.f, r - (clip.r0 + i), c - (clip.c0 + j)); delete o.v; delete o.m; } else { o.v = src.v; delete o.f; delete o.m; } return o; }
      // všetko okrem okrajov / transponované = celá bunka
      if (!src) return null; const o = clone(src); delete o.mc; if (o.f) o.f = shiftFormula(o.f, kind === 'transpose' ? 0 : r - (clip.r0 + i), kind === 'transpose' ? 0 : c - (clip.c0 + j)); return o;
    }));
    setRange(out, { range: { row: [R0, R0 + out.length - 1], column: [C0, C0 + out[0].length - 1] } });
    if (out.some(r => r.some(x => x && x.f))) setTimeout(() => luckysheet.refreshFormula(), 50);
    markDirty();
  }
  async function pasteValuesFromClipboard() {
    if (clip) return pasteSpecial('values');
    try {
      const t = await navigator.clipboard.readText(); if (!t) return;
      const rows = t.replace(/\r/g, '').replace(/\n$/, '').split('\n').map(l => l.split('\t'));
      const rg = range(), w = Math.max(...rows.map(r => r.length));
      const out = rows.map(r => Array.from({ length: w }, (_, i) => { const v = r[i] ?? ''; if (v === '') return null; const p = IO.parseLocal(v); return p ? { v: p.v, ct: p.ct } : { v, ct: { fa: 'General', t: 'g' } }; }));
      setRange(out, { range: { row: [rg.row[0], rg.row[0] + out.length - 1], column: [rg.column[0], rg.column[0] + w - 1] } }); markDirty();
    } catch (e) { toast('Prehliadač nedovolil čítať schránku – použi Ctrl+V', 'err'); }
  }
  function keyToSheet(code, ctrl, shift) { if (!window.jQuery) return; const ev = jQuery.Event('keydown', { keyCode: code, which: code, ctrlKey: !!ctrl, metaKey: false, shiftKey: !!shift }); jQuery(document).trigger(ev); }

  /* --- presun riadkov a stĺpcov, bunky --- */
  function moveRow(dir) {
    const rg = range(), d = data(), n = rg.row[1] - rg.row[0] + 1, other = dir < 0 ? rg.row[0] - 1 : rg.row[1] + 1;
    if (other < 0 || other >= d.length) return;
    const cols = Math.max(...d.slice(Math.min(other, rg.row[0]), Math.max(other, rg.row[1]) + 1).map(r => (r || []).length)) - 1;
    const block = [], lo = Math.min(other, rg.row[0]), hi = Math.max(other, rg.row[1]);
    for (let r = lo; r <= hi; r++) block.push((d[r] || []).slice(0, cols + 1).map(clone));
    const moved = dir < 0 ? [...block.slice(1), block[0]] : [block[block.length - 1], ...block.slice(0, -1)];
    setRange(moved, { range: { row: [lo, hi], column: [0, cols] } });
    luckysheet.setRangeShow({ row: [rg.row[0] + dir, rg.row[1] + dir], column: rg.column }); void n; markDirty();
  }
  function moveCol(dir) {
    const rg = range(), d = data(), other = dir < 0 ? rg.column[0] - 1 : rg.column[1] + 1;
    if (other < 0) return;
    const lo = Math.min(other, rg.column[0]), hi = Math.max(other, rg.column[1]), rows = Math.max(0, usedSize().r);
    const block = []; for (let r = 0; r <= rows; r++) { const row = []; for (let c = lo; c <= hi; c++) row.push(clone(d[r] && d[r][c])); block.push(dir < 0 ? [...row.slice(1), row[0]] : [row[row.length - 1], ...row.slice(0, -1)]); }
    setRange(block, { range: { row: [0, rows], column: [lo, hi] } });
    luckysheet.setRangeShow({ row: rg.row, column: [rg.column[0] + dir, rg.column[1] + dir] }); markDirty();
  }
  function insertCells(dir) {
    const rg = range(), d = data(), u = usedSize();
    const nr = rg.row[1] - rg.row[0] + 1, nc = rg.column[1] - rg.column[0] + 1;
    if (dir === 'down') {
      const last = Math.max(u.r, rg.row[1]); const blk = []; for (let r = rg.row[0]; r <= last; r++) { const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) row.push(clone(d[r] && d[r][c])); blk.push(row); }
      const empty = Array.from({ length: nr }, () => Array(nc).fill(null));
      setRange(empty.concat(blk), { range: { row: [rg.row[0], last + nr], column: rg.column } });
    } else {
      const last = Math.max(u.c, rg.column[1]); const blk = []; for (let r = rg.row[0]; r <= rg.row[1]; r++) { const row = []; for (let c = rg.column[0]; c <= last; c++) row.push(clone(d[r] && d[r][c])); blk.push(Array(nc).fill(null).concat(row)); }
      setRange(blk, { range: { row: rg.row, column: [rg.column[0], last + nc] } });
    }
    markDirty();
  }
  /* --- ukotvenie --- */
  function freeze(k) {
    const sh = sheetObj(), fz = (sh && sh.frozen) || {}, rf = fz.range || {};
    let rows = /row|Both/i.test(fz.type || '') ? (fz.type === 'row' || fz.type === 'both' ? 1 : (rf.row_focus ?? -1) + 1) : 0;
    let cols = /column|Both/i.test(fz.type || '') ? (fz.type === 'column' || fz.type === 'both' ? 1 : (rf.column_focus ?? -1) + 1) : 0;
    const rg = range();
    if (k === 'r0') rows = 0; else if (k === 'r1') rows = 1; else if (k === 'r2') rows = 2; else if (k === 'rows') rows = rg.row[1] + 1;
    if (k === 'c0') cols = 0; else if (k === 'c1') cols = 1; else if (k === 'c2') cols = 2; else if (k === 'cols') cols = rg.column[1] + 1;
    luckysheet.cancelFrozen();
    // Luckysheet berie stĺpec (a niekedy aj riadok) z aktuálneho výberu – výber sa preto dočasne presunie
    try { luckysheet.setRangeShow({ row: [Math.max(0, rows - 1), Math.max(0, rows - 1)], column: [Math.max(0, cols - 1), Math.max(0, cols - 1)] }, { show: false }); } catch (e) {}
    if (rows && cols) luckysheet.setBothFrozen(true, { range: { row_focus: rows - 1, column_focus: cols - 1 } });
    else if (rows) luckysheet.setHorizontalFrozen(true, { range: { row_focus: rows - 1, column_focus: 0 } });
    else if (cols) luckysheet.setVerticalFrozen(true, { range: { row_focus: 0, column_focus: cols - 1 } });
    try { luckysheet.setRangeShow(rg); } catch (e) {}
    markDirty();
  }
  /* --- zobrazenie --- */
  const V = Object.assign({ fbar: true }, (() => { try { return JSON.parse(localStorage.getItem('cloud.sheet.view') || '{}'); } catch (e) { return {}; } })());
  function applyV() { document.body.classList.toggle('no-fbar', !V.fbar); try { localStorage.setItem('cloud.sheet.view', JSON.stringify({ fbar: V.fbar })); } catch (e) {} setTimeout(() => { try { luckysheet.resize(); } catch (e) {} }, 50); }
  applyV();
  function gridOn() { const sh = sheetObj(); return !sh || sh.showGridLines === undefined || (sh.showGridLines !== 0 && sh.showGridLines !== '0' && sh.showGridLines !== false); }
  GMenu.onOpen = (menu) => {
    menu.querySelectorAll('[data-chk]').forEach(x => {
      const k = x.dataset.chk; let on = false;
      if (k === 'grid') on = gridOn(); else if (k === 'fbar') on = !!V.fbar; else if (k === 'formulas') on = !!S.showF;
      else if (k.startsWith('zoom:')) { const z = (sheetObj() || {}).zoomRatio || 1; on = Math.abs(z - +k.slice(5)) < 0.01; }
      x.classList.toggle('on', on);
    });
  };
  async function showSheets() {
    const hidden = luckysheet.getAllSheets().filter(s => s.hide == 1);
    if (!hidden.length) return toast('Žiadne skryté hárky');
    await dlg('Skryté hárky', '<div class="list-group">' + hidden.map(s => '<button type="button" class="list-group-item list-group-item-action" data-o="' + s.order + '"><i class="bi bi-eye me-2"></i>' + esc(s.name) + '</button>').join('') + '</div>', { ok: false, cancel: 'Zavrieť',
      init: (el, m) => el.querySelectorAll('[data-o]').forEach(b => b.onclick = () => { luckysheet.setSheetShow({ order: +b.dataset.o }); markDirty(); m.hide(); }) });
  }
  /* --- údaje --- */
  /** Zoradenie riadkov oblasti (vlastné – po slovensky, čísla pred textom, prázdne vždy na konci). */
  const COLL = new Intl.Collator('sk', { numeric: true, sensitivity: 'base' });
  function sortRows(rg, keys, header) {
    const d = data(); if ((rg.row[1] - rg.row[0] + 1) * (rg.column[1] - rg.column[0] + 1) > 200000) return toast('Vyber menšiu oblasť', 'err');
    const start = rg.row[0] + (header ? 1 : 0);
    if (Object.values((sheetObj().config || {}).merge || {}).some(m => m.r + m.rs - 1 >= start && m.r <= rg.row[1] && m.c + m.cs - 1 >= rg.column[0] && m.c <= rg.column[1])) return toast('Oblasť so zlúčenými bunkami sa nedá zoradiť', 'err');
    const rows = []; for (let r = start; r <= rg.row[1]; r++) { const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) row.push(clone(d[r] && d[r][c])); rows.push(row); }
    const val = (x) => { if (!x || x.v === null || x.v === undefined || x.v === '') return null; return x.v; };
    rows.sort((A, B) => {
      for (const k of keys) {
        const a = val(A[k.i - rg.column[0]]), b = val(B[k.i - rg.column[0]]);
        if (a === null && b === null) continue; if (a === null) return 1; if (b === null) return -1;
        let r;
        if (typeof a === 'number' && typeof b === 'number') r = a - b;
        else if (typeof a === 'number') r = -1; else if (typeof b === 'number') r = 1;
        else r = COLL.compare(String(a), String(b));
        if (r) return k.sort === 'desc' ? -r : r;
      }
      return 0;
    });
    if (!rows.length) return;
    setRange(rows, { range: { row: [start, rg.row[1]], column: rg.column } });
    if (rows.some(r => r.some(x => x && x.f))) setTimeout(() => luckysheet.refreshFormula(), 50);
    markDirty();
  }
  function sortSheet(dir) {
    const u = usedSize(); if (u.r < 0) return;
    const sh = sheetObj(), fz = (sh && sh.frozen) || {}, head = /row|Both/i.test(fz.type || '') ? (fz.type === 'row' || fz.type === 'both' ? 1 : (fz.range || {}).row_focus + 1) : 0;
    const c = range().column[0];
    if (head > u.r) return;
    sortRows({ row: [head, u.r], column: [0, Math.max(u.c, c)] }, [{ i: c, sort: dir }], false);
  }
  async function sortAdvanced() {
    const rg = range(); const cols = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) cols.push(c);
    const colName = (c) => IO.addr(0, c).replace(/\d+$/, '');
    let res = null;
    const row = (i) => '<div class="d-flex gap-2 mb-2" data-k="' + i + '"><span class="small pt-2" style="width:90px">' + (i ? 'potom podľa' : 'zoradiť podľa') + '</span><select class="form-select form-select-sm">' + (i ? '<option value="">—</option>' : '') + cols.map(c => '<option value="' + c + '">stĺpca ' + colName(c) + '</option>').join('') + '</select><select class="form-select form-select-sm" style="width:110px"><option value="asc">A → Z</option><option value="desc">Z → A</option></select></div>';
    await dlg('Zoradiť rozsah ' + IO.addr(rg.row[0], rg.column[0]) + ':' + IO.addr(rg.row[1], rg.column[1]), '<label class="form-check mb-3"><input type="checkbox" class="form-check-input" id="soH"> <span class="form-check-label">Údaje obsahujú riadok hlavičky</span></label>' + row(0) + row(1) + row(2),
      { ok: 'Zoradiť', onOk: (el) => { res = { h: el.querySelector('#soH').checked, k: [...el.querySelectorAll('[data-k]')].map(d => { const s = d.querySelectorAll('select'); return s[0].value === '' ? null : { i: +s[0].value, sort: s[1].value }; }).filter(Boolean) }; } });
    if (!res || !res.k.length) return;
    sortRows(rg, res.k, res.h);
  }
  function shuffle() {
    const rg = range(), d = data(); if (big(50000)) return;
    const rows = []; for (let r = rg.row[0]; r <= rg.row[1]; r++) { const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) row.push(clone(d[r] && d[r][c])); rows.push(row); }
    for (let i = rows.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [rows[i], rows[j]] = [rows[j], rows[i]]; }
    setRange(rows, { range: rg }); markDirty();
  }
  const disp = (c) => c ? (c.m ?? c.v ?? '') : '';
  async function stats() {
    const rg = range(), d = data(), c = rg.column[0], u = usedSize();
    const vals = []; for (let r = 0; r <= Math.max(u.r, 0); r++) vals.push(d[r] && d[r][c]);
    const filled = vals.filter(x => x && x.v !== null && x.v !== undefined && x.v !== '');
    const nums = filled.map(x => x.v).filter(v => typeof v === 'number' || (typeof v === 'string' && v.trim() !== '' && !isNaN(+v))).map(Number).sort((a, b) => a - b);
    const freq = new Map(); filled.forEach(x => { const k = String(disp(x)); freq.set(k, (freq.get(k) || 0) + 1); });
    const top = [...freq.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8);
    const f = (x) => (Math.round(x * 100) / 100).toLocaleString('sk');
    const sum = nums.reduce((a, b) => a + b, 0), med = nums.length ? (nums.length % 2 ? nums[(nums.length - 1) / 2] : (nums[nums.length / 2 - 1] + nums[nums.length / 2]) / 2) : 0;
    const max = Math.max(1, ...top.map(t => t[1]));
    await dlg('Štatistiky stĺpca ' + IO.addr(0, c).replace(/\d+$/, ''), '<table class="table table-sm"><tbody>' +
      [['Riadky s údajmi', filled.length], ['Prázdne', vals.length - filled.length], ['Jedinečné hodnoty', freq.size]].concat(nums.length ? [['Súčet', f(sum)], ['Priemer', f(sum / nums.length)], ['Medián', f(med)], ['Minimum', f(nums[0])], ['Maximum', f(nums[nums.length - 1])]] : []).map(([a, b]) => '<tr><td>' + a + '</td><td class="text-end">' + b + '</td></tr>').join('') + '</tbody></table>' +
      '<div class="small fw-semibold mb-1">Najčastejšie hodnoty</div>' + top.map(([k, n]) => '<div class="d-flex align-items-center gap-2 small mb-1"><span class="text-truncate" style="width:40%">' + esc(k || '(prázdne)') + '</span><span class="flex-grow-1"><span class="d-block bg-primary rounded" style="height:10px;width:' + Math.round(n / max * 100) + '%"></span></span><span>' + n + '</span></div>').join(''), { ok: false, cancel: 'Zavrieť' });
  }
  async function dedupe() {
    const rg = range(), d = data(); if (big(100000)) return;
    const cols = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) cols.push(c);
    let res = null;
    await dlg('Odstrániť duplikáty', '<div class="small text-body-secondary mb-2">Oblasť ' + IO.addr(rg.row[0], rg.column[0]) + ':' + IO.addr(rg.row[1], rg.column[1]) + '</div><label class="form-check mb-2"><input type="checkbox" class="form-check-input" id="ddH"> <span class="form-check-label">Údaje obsahujú riadok hlavičky</span></label><div class="small fw-semibold mb-1">Porovnávať stĺpce</div>' +
      cols.map(c => '<label class="form-check"><input type="checkbox" class="form-check-input" data-c="' + c + '" checked> <span class="form-check-label">Stĺpec ' + IO.addr(0, c).replace(/\d+$/, '') + (d[rg.row[0]] && d[rg.row[0]][c] ? ' – ' + esc(String(disp(d[rg.row[0]][c])).slice(0, 30)) : '') + '</span></label>').join(''),
    { ok: 'Odstrániť duplikáty', onOk: (el) => { res = { h: el.querySelector('#ddH').checked, c: [...el.querySelectorAll('[data-c]:checked')].map(x => +x.dataset.c) }; } });
    if (!res || !res.c.length) return;
    const start = rg.row[0] + (res.h ? 1 : 0), seen = new Set(), keep = [];
    let removed = 0;
    for (let r = start; r <= rg.row[1]; r++) {
      const key = res.c.map(c => String(disp(d[r] && d[r][c])).trim().toLowerCase()).join('\u0001');
      if (seen.has(key)) { removed++; continue; } seen.add(key);
      const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) row.push(clone(d[r] && d[r][c])); keep.push(row);
    }
    while (keep.length < rg.row[1] - start + 1) keep.push(Array(rg.column[1] - rg.column[0] + 1).fill(null));
    if (removed) { setRange(keep, { range: { row: [start, rg.row[1]], column: rg.column } }); markDirty(); }
    toast(removed ? 'Odstránené duplicitné riadky: ' + removed + ' · ostáva ' + (seen.size) : 'Nenašli sa žiadne duplicitné riadky');
  }
  function trimSpaces() {
    const rg = range(), d = data(); if (big(100000)) return; let n = 0;
    const out = []; for (let r = rg.row[0]; r <= rg.row[1]; r++) { const row = []; for (let c = rg.column[0]; c <= rg.column[1]; c++) { const x = clone(d[r] && d[r][c]); if (x && typeof x.v === 'string' && !x.f) { const t = x.v.replace(/\s+/g, ' ').trim(); if (t !== x.v) { x.v = t; x.m = t; n++; } } row.push(x); } out.push(row); }
    if (n) { setRange(out, { range: rg }); markDirty(); }
    toast(n ? 'Upravené bunky: ' + n : 'Nič na orezanie');
  }
  function selCount() {
    const rg = range(), d = data(); let n = 0, cnt = 0, sum = 0, mn = Infinity, mx = -Infinity;
    for (let r = rg.row[0]; r <= rg.row[1] && r < d.length; r++) for (let c = rg.column[0]; c <= rg.column[1]; c++) { const x = d[r] && d[r][c]; if (x && x.v !== null && x.v !== undefined && x.v !== '') { n++; if (typeof x.v === 'number') { cnt++; sum += x.v; mn = Math.min(mn, x.v); mx = Math.max(mx, x.v); } } }
    const f = (v) => (Math.round(v * 100) / 100).toLocaleString('sk');
    dlg('Výber ' + IO.addr(rg.row[0], rg.column[0]) + ':' + IO.addr(rg.row[1], rg.column[1]), '<table class="table table-sm mb-0"><tbody><tr><td>Bunky s hodnotou</td><td class="text-end">' + n + '</td></tr><tr><td>Čísla</td><td class="text-end">' + cnt + '</td></tr>' + (cnt ? '<tr><td>Súčet</td><td class="text-end">' + f(sum) + '</td></tr><tr><td>Priemer</td><td class="text-end">' + f(sum / cnt) + '</td></tr><tr><td>Minimum</td><td class="text-end">' + f(mn) + '</td></tr><tr><td>Maximum</td><td class="text-end">' + f(mx) + '</td></tr>' : '') + '</tbody></table>', { ok: false, cancel: 'Zavrieť' });
  }
  function nowCell(kind) {
    const rg = range(), d = new Date(), p = (n) => String(n).padStart(2, '0');
    if (kind === 'date') { luckysheet.setCellValue(rg.row[0], rg.column[0], d.getDate() + '.' + (d.getMonth() + 1) + '.' + d.getFullYear()); }
    else luckysheet.setCellValue(rg.row[0], rg.column[0], p(d.getHours()) + ':' + p(d.getMinutes()));
    markDirty();
  }
  function checkbox() {
    try { luckysheet.setDataVerification({ type: 'checkbox', type2: null, value1: 'TRUE', value2: 'FALSE', checked: false, remote: false, prohibitInput: false, hintShow: false, hintText: '' }, { range: range() }); markDirty(); }
    catch (e) { click('#luckysheet-dataVerification-btn-title'); }
  }

  const ACT = {
    close, save: () => save(false), undo: () => luckysheet.undo(), redo: () => luckysheet.redo(),
    find: () => { click('#luckysheet-icon-seachmore'); setTimeout(() => click('#luckysheet-icon-seachmore-menuButton .luckysheet-cols-menuitem:nth-child(2), #luckysheet-icon-seachmore-menuButton [itemvalue="replace"]'), 50); },
    clear: () => luckysheet.clearRange(),
    delrow: () => { const rg = range(); luckysheet.deleteRow(rg.row[0], rg.row[1]); },
    delcol: () => { const rg = range(); luckysheet.deleteColumn(rg.column[0], rg.column[1]); },
    'delcells:up': () => { luckysheet.deleteRange('up', { range: range() }); markDirty(); }, 'delcells:left': () => { luckysheet.deleteRange('left', { range: range() }); markDirty(); },
    'inscells:down': () => insertCells('down'), 'inscells:right': () => insertCells('right'),
    'mv:up': () => moveRow(-1), 'mv:down': () => moveRow(1), 'mv:left': () => moveCol(-1), 'mv:right': () => moveCol(1),
    'row:above': () => { const rg = range(); luckysheet.insertRow(rg.row[0], { number: rg.row[1] - rg.row[0] + 1 }); }, 'row:below': () => { const rg = range(); luckysheet.insertRow(rg.row[1] + 1, { number: rg.row[1] - rg.row[0] + 1 }); },
    'col:left': () => { const rg = range(); luckysheet.insertColumn(rg.column[0], { number: rg.column[1] - rg.column[0] + 1 }); }, 'col:right': () => { const rg = range(); luckysheet.insertColumn(rg.column[1] + 1, { number: rg.column[1] - rg.column[0] + 1 }); },
    chart: () => click('#luckysheet-chart-btn-title'), image: () => click('#luckysheet-insertImg-btn-title'), link: () => click('#luckysheet-insertLink-btn-title'),
    pivot: () => click('#luckysheet-pivot-btn-title') || toast('Kontingenčná tabuľka nie je dostupná', 'err'),
    comment: () => { click('#luckysheet-icon-postil'); setTimeout(() => click('#luckysheet-icon-postil-menuButton .luckysheet-cols-menuitem:first-child'), 50); },
    'fn:SUM': () => insertFn('SUM'), 'fn:AVERAGE': () => insertFn('AVERAGE'), 'fn:COUNT': () => insertFn('COUNT'), 'fn:MAX': () => insertFn('MAX'), 'fn:MIN': () => insertFn('MIN'),
    'fn:more': () => { click('#luckysheet-icon-function-menu'); setTimeout(() => click('#luckysheet-icon-function-menu-menuButton .luckysheet-cols-menuitem:last-child'), 50); },
    dropdown: () => click('#luckysheet-dataVerification-btn-title'), split: () => click('#luckysheet-splitColumn-btn-title'), checkbox,
    sheet: () => luckysheet.setSheetAdd(), 'fmt:more': () => click('#luckysheet-icon-fmt-other'),
    unmerge: () => luckysheet.cancelRangeMerge(),
    cf: () => click('#luckysheet-icon-conditionformat'),
    alt: () => { click('#luckysheet-icon-conditionformat'); setTimeout(() => { const it = [...document.querySelectorAll('#luckysheet-icon-conditionformat-menuButton .luckysheet-cols-menuitem')].find(x => /strieda|altern/i.test(x.textContent)); if (it) jQuery(it).trigger('click'); else toast('Striedavé farby nastavíš cez Podmienené formátovanie'); }, 80); },
    clearfmt: clearFormat,
    'sort:asc': () => sortRows(range(), [{ i: range().column[0], sort: 'asc' }], false), 'sort:desc': () => sortRows(range(), [{ i: range().column[0], sort: 'desc' }], false), 'sort:adv': sortAdvanced,
    'sortsheet:asc': () => sortSheet('asc'), 'sortsheet:desc': () => sortSheet('desc'),
    'filter:open': () => luckysheet.setRangeFilter('open'), 'filter:close': () => luckysheet.setRangeFilter('close'),
    protect: () => click('#luckysheet-icon-protection') || toast('Ochrana hárka nie je dostupná', 'err'),
    shuffle, stats, dedupe, trim: trimSpaces, count: selCount, recalc: () => { luckysheet.refreshFormula(); toast('Vzorce sú prepočítané'); },
    date: () => nowCell('date'), time: () => nowCell('time'),
    grid: () => { gridOn() ? luckysheet.hideGridLines() : luckysheet.showGridLines(); markDirty(); },
    fbar: () => { V.fbar = !V.fbar; applyV(); },
    formulas: () => { S.showF = !S.showF; try { luckysheet.refresh(); } catch (e) {} },
    'hide:rows': () => { const rg = range(); luckysheet.hideRow(rg.row[0], rg.row[1]); markDirty(); }, 'show:rows': () => { const rg = range(); luckysheet.showRow(Math.max(0, rg.row[0] - 1), rg.row[1] + 1); markDirty(); },
    'hide:cols': () => { const rg = range(); luckysheet.hideColumn(rg.column[0], rg.column[1]); markDirty(); }, 'show:cols': () => { const rg = range(); luckysheet.showColumn(Math.max(0, rg.column[0] - 1), rg.column[1] + 1); markDirty(); },
    hidesheet: () => { if (luckysheet.getAllSheets().filter(s => s.hide != 1).length < 2) return toast('Posledný viditeľný hárok sa nedá skryť'); luckysheet.setSheetHide(); markDirty(); }, showsheets: showSheets,
    fullscreen: () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen?.().catch(() => {}); },
    keys: () => bootstrap.Modal.getOrCreateInstance($('#gsKeys')).show(), menusearch: () => GMenu.search(),
    cut: () => { rememberClip(); keyToSheet(88, true); }, copycells: () => { rememberClip(); keyToSheet(67, true); }, paste: () => toast('Na prilepenie použi Ctrl+V (prehliadač nedovolí prilepiť z ponuky)'),
    'ps:values': () => pasteSpecial('values'), 'ps:format': () => pasteSpecial('format'), 'ps:formula': () => pasteSpecial('formula'), 'ps:noborder': () => pasteSpecial('all'), 'ps:transpose': () => pasteSpecial('transpose'),
    rename, star, copy: copyFile, trash, newsheet: () => newFile('sheet'), newdoc: () => newFile('doc'),
    share: () => parentAct('share'), move: () => parentAct('move'), versions: () => parentAct('versions'), info: () => parentAct('info'), activity: () => parentAct('activity'),
    'import:new': () => { importMode = 'new'; $('#gsImport').click(); }, 'import:replace': () => { importMode = 'replace'; $('#gsImport').click(); }, 'import:append': () => { importMode = 'append'; $('#gsImport').click(); },
    print: () => window.CloudSheetPrint ? CloudSheetPrint.open() : toast('Tlač nie je dostupná', 'err'),
  };
  const RW = /^(undo|redo|clear|delrow|delcol|delcells|inscells|mv|row|col|chart|image|link|pivot|comment|fn:(SUM|AVERAGE|COUNT|MAX|MIN)|dropdown|split|checkbox|sheet|fmt|st|ht|vt|tb|tr|fs|merge|unmerge|cf|alt|clearfmt|sort|sortsheet|protect|shuffle|dedupe|trim|date|time|grid|hide|show:|hidesheet|showsheets|cut|paste|ps|rename|trash|import|freeze)/;
  function run(a, b) {
    if (!S.ready && a !== 'close') return;
    if (S.ro && ((b && (b.hasAttribute('data-rw') || b.closest('[data-rw]'))) || RW.test(a))) return;
    try {
      if (a.startsWith('dl:')) { const t = a.slice(3); if (t === 'pdf' || t === 'html') return window.CloudSheetPrint && CloudSheetPrint.download(t); return download(t); }
      if (a.startsWith('fmt:') && a !== 'fmt:more') return setFmt(a.slice(4));
      if (a.startsWith('st:')) return toggle(a.slice(3));
      if (/^(ht|vt|tr|fs):/.test(a)) { if (big()) return; return luckysheet.setRangeFormat(a.slice(0, 2), +a.slice(3)); }
      if (a.startsWith('tb:')) { if (big()) return; return luckysheet.setRangeFormat('tb', a.slice(3)); }
      if (a.startsWith('merge:')) return luckysheet.setRangeMerge(a.slice(6));
      if (a.startsWith('zoom:')) return luckysheet.setSheetZoom(+a.slice(5));
      if (a.startsWith('freeze:')) return freeze(a.slice(7));
      const r = ACT[a] && ACT[a](); if (r && r.catch) r.catch(er => toast(er.message || String(er), 'err'));
    } catch (er) { console.error(er); toast(er.message || String(er), 'err'); }
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-gs]'); if (!b) return;
    e.preventDefault(); run(b.dataset.gs, b);
  });
  document.addEventListener('keydown', (e) => {
    if (e.target.closest && e.target.closest('.modal, .gm-search')) return;
    const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
    const go = (a) => { e.preventDefault(); e.stopPropagation(); run(a); };
    if (ctrl && k === 's') return go('save');
    if (ctrl && k === 'p') return go('print');
    if (ctrl && k === 'k' && !S.ro) return go('link');
    if (ctrl && e.key === '\\' && !S.ro) return go('clearfmt');
    if (ctrl && k === 'h') return go('find');
    if (ctrl && (k === '/' || e.code === 'Slash') && !e.altKey) return go('keys');
    if (ctrl && e.code === 'Backquote') return go('formulas');
    if (S.ro) return;
    if (ctrl && (k === 'c' || k === 'x') && !e.shiftKey && !e.altKey) { rememberClip(); return; }
    if (ctrl && e.shiftKey && k === 'v') { e.preventDefault(); e.stopPropagation(); pasteValuesFromClipboard(); return; }
    if (ctrl && !e.shiftKey && (e.key === ';' || e.code === 'Semicolon') && !document.querySelector('#luckysheet-rich-text-editor:focus')) return go('date');
    if (ctrl && e.shiftKey && (e.key === ':' || e.code === 'Semicolon')) return go('time');
    if (ctrl && e.shiftKey && ['l', 'e', 'r'].includes(k)) return go({ l: 'ht:1', e: 'ht:0', r: 'ht:2' }[k]);
    if (e.altKey && e.shiftKey && e.code === 'Digit5') return go('st:cl');
    if (e.shiftKey && e.key === 'F11') return go('sheet');
    if (e.shiftKey && e.key === 'F2') return go('comment');
  }, true);
  // Android: tlačidlo Späť zatvorí editor
  window.cloudBack = () => { if ($('.modal.show')) { bootstrap.Modal.getInstance($('.modal.show'))?.hide(); return true; } close(); return true; };

  /* --- doplnky v paneli nástrojov Luckysheet (ako v Google Tabuľkách) --- */
  function toolbarExtras() {
    if (document.querySelector('.gs-tbx') || S.ro) return;
    const bar = document.getElementById('luckysheet-wa-editor'); if (!bar) return;
    const mk = (html) => { const t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; };
    const b = (gs, icon, tip) => mk('<button class="gs-tb gs-tbx" data-gs="' + gs + '" title="' + tip + '"><i class="bi bi-' + icon + '"></i></button>');
    const undo = document.getElementById('luckysheet-icon-undo'), redo = document.getElementById('luckysheet-icon-redo'), paint = document.getElementById('luckysheet-icon-paintformat');
    const added = [];
    if (undo) { const x = b('menusearch', 'search', 'Hľadať v ponukách (Alt+/)'); undo.before(x); added.push(x); }
    if (redo) { const x = b('print', 'printer', 'Tlačiť (Ctrl+P)'); redo.after(x); added.push(x); }
    if (paint) {
      const z = mk('<div class="gm gs-zoomdd gs-tbx"><button class="gs-zb" data-gm title="Priblíženie"><span id="gsZoomL">100 %</span><i class="bi bi-caret-down-fill"></i></button><ul class="gm-menu">' + [50, 75, 90, 100, 125, 150, 200].map(v => '<li><button class="gm-i" data-gs="zoom:' + (v / 100) + '" data-chk="zoom:' + (v / 100) + '"><i class="bi bi-zoom-in"></i><span>' + v + ' %</span></button></li>').join('') + '</ul></div>');
      paint.after(z); added.push(z);
    }
    // Luckysheet počíta, čo sa zmestí, bez našich tlačidiel → posun o ich šírku (úprava v tools/patch-luckysheet.py)
    window.LS_TB_OFFSET = added.reduce((a, x) => a + x.getBoundingClientRect().width + 4, 0);
    try { window.dispatchEvent(new Event('resize')); } catch (e) {}
  }
  ACT.paint = () => click('#luckysheet-icon-paintformat');
  setInterval(() => { const l = document.getElementById('gsZoomL'); if (l && S.ready) { const z = Math.round(((sheetObj() || {}).zoomRatio || 1) * 100) + ' %'; if (l.textContent !== z) l.textContent = z; } }, 700);

  open();
})();
