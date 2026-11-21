/* Editor tabuliek v Cloude (.xlsx, .ods, .csv, čítanie .xls/.xlsm): beží celý v prehliadači.
   Čítanie a zápis .xlsx so štýlmi: ExcelJS. Mriežka, vzorce, kopírovanie: Jspreadsheet CE + funkcie Excelu z formula.js.
   Ukladá sa ako nová verzia súboru (staré verzie ostávajú vo Verziách). */
(function () {
  const L = () => window.CLOUD_LIB || {};
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const extOf = (n) => (n.match(/\.([a-z0-9]+)$/i) || [])[1]?.toLowerCase() || '';
  const loaded = {};
  const script = (src) => loaded[src] ??= new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('Nepodarilo sa načítať editor tabuliek.')); document.head.appendChild(s); });
  const css = (href) => { if (!document.querySelector('link[href="' + href + '"]')) document.head.insertAdjacentHTML('beforeend', '<link rel="stylesheet" href="' + href + '">'); };
  async function libs(withExcel) {
    css(L().jsuites_css); css(L().jss_css);
    await script(L().jsuites); await script(L().jss); await script(L().formula);
    if (withExcel !== false) await script(L().exceljs);
    await script(L().xlsx);
    if (!window._cloudFx) { // funkcie Excelu (SUM, IF, VLOOKUP, COUNTIF…) – čísla z buniek ako čísla, dátumy ako text
      window._cloudFx = true;
      const num = (v) => Array.isArray(v) ? v.map(num) : (typeof v === 'string' && /^-?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(v.trim()) ? +v : v);
      for (const k of Object.keys(window.formulajs || {})) {
        if (!/^[A-Z][A-Z0-9.]*$/.test(k) || typeof formulajs[k] !== 'function') continue;
        const fn = formulajs[k];
        window[k] = function () { const r = fn.apply(null, [...arguments].map(num)); return r instanceof Error ? (r.message || '#CHYBA') : r instanceof Date ? fmtDate(r) : r; };
      }
    }
  }
  const pad = (n) => String(n).padStart(2, '0');
  const fmtDate = (d) => d.getDate() + '.' + (d.getMonth() + 1) + '.' + d.getFullYear() + (d.getHours() || d.getMinutes() ? ' ' + d.getHours() + ':' + pad(d.getMinutes()) : '');
  const colName = (i) => { let s = ''; i++; while (i > 0) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; };
  const cellName = (c, r) => colName(c) + (r + 1);
  const parseName = (n) => { const m = /^([A-Z]+)(\d+)$/.exec(n); if (!m) return null; let c = 0; for (const ch of m[1]) c = c * 26 + ch.charCodeAt(0) - 64; return [c - 1, +m[2] - 1]; };
  const argbToHex = (c) => c && c.argb && c.argb.length >= 6 ? '#' + c.argb.slice(-6).toLowerCase() : null;

  /* ---------- prevod zo súboru do modelu { name, data, style, merge, widths, fmt } ---------- */
  function fromExcel(wb) {
    const sheets = [];
    wb.eachSheet((ws) => {
      if (ws.state === 'veryHidden') return;
      const sh = { name: ws.name, data: [], style: {}, merge: {}, widths: [], fmt: {} };
      const maxC = Math.max(ws.columnCount, 1), maxR = Math.max(ws.rowCount, 1);
      for (let c = 1; c <= maxC; c++) { const w = ws.getColumn(c).width; sh.widths[c - 1] = w ? Math.round(w * 7.2 + 5) : 100; }
      for (let r = 1; r <= maxR; r++) {
        const row = [];
        const xr = ws.getRow(r);
        for (let c = 1; c <= maxC; c++) {
          const cell = xr.getCell(c), v = cell.value, nm = cellName(c - 1, r - 1);
          let out = '';
          if (cell.formula) out = '=' + cell.formula;
          else if (v === null || v === undefined) out = '';
          else if (v instanceof Date) { out = fmtDate(new Date(v.getTime() + v.getTimezoneOffset() * 60000)); sh.fmt[nm] = 'date'; }
          else if (typeof v === 'object' && v.richText) out = v.richText.map(t => t.text).join('');
          else if (typeof v === 'object' && v.text !== undefined) out = String(v.text?.richText ? v.text.richText.map(t => t.text).join('') : v.text);
          else if (typeof v === 'object' && v.error) out = v.error;
          else if (typeof v === 'boolean') out = v ? 'TRUE' : 'FALSE';
          else if (typeof v === 'number' && cell.numFmt && /%/.test(cell.numFmt)) { const d = (cell.numFmt.split('.')[1] || '').replace(/[^0]/g, '').length; out = (v * 100).toFixed(d) + '%'; sh.fmt[nm] = cell.numFmt; }
          else if (typeof v === 'number') { out = String(Math.round(v * 1e10) / 1e10); if (cell.numFmt && cell.numFmt !== 'General') sh.fmt[nm] = cell.numFmt; }
          else out = String(v);
          if (cell.formula && cell.numFmt && cell.numFmt !== 'General') sh.fmt[nm] = /[dmy]{2}/i.test(cell.numFmt) && !/0/.test(cell.numFmt) ? 'date' : cell.numFmt;
          row.push(out);
          const s = [], f = cell.font || {}, a = cell.alignment || {}, fill = cell.fill;
          if (f.bold) s.push('font-weight:bold'); if (f.italic) s.push('font-style:italic');
          if (f.underline || f.strike) s.push('text-decoration:' + [f.underline && 'underline', f.strike && 'line-through'].filter(Boolean).join(' '));
          const fc = argbToHex(f.color); if (fc && fc !== '#000000') s.push('color:' + fc);
          if (f.size && f.size !== 11) s.push('font-size:' + Math.round(f.size * 1.333) + 'px');
          if (fill && fill.type === 'pattern' && fill.pattern === 'solid') { const bg = argbToHex(fill.fgColor); if (bg && bg !== '#ffffff') s.push('background-color:' + bg); }
          if (a.horizontal && ['left', 'center', 'right'].includes(a.horizontal)) s.push('text-align:' + a.horizontal);
          if (a.wrapText) s.push('white-space:normal');
          if (s.length) sh.style[nm] = s.join(';');
        }
        sh.data.push(row);
      }
      (ws.model.merges || []).forEach(m => { // "A1:C2"
        const [a, b] = m.split(':').map(parseName); if (!a || !b) return;
        sh.merge[cellName(a[0], a[1])] = [b[0] - a[0] + 1, b[1] - a[1] + 1];
      });
      sh.frozen = (ws.views || []).find(v => v.state === 'frozen')?.ySplit || 0;
      sheets.push(sh);
    });
    return sheets.length ? sheets : [blank('Hárok1')];
  }
  function fromSheetJS(wb) {
    return wb.SheetNames.map(n => {
      const ws = wb.Sheets[n], rng = XLSX.utils.decode_range(ws['!ref'] || 'A1');
      const sh = { name: n, data: [], style: {}, merge: {}, widths: [], fmt: {} };
      for (let r = 0; r <= rng.e.r; r++) {
        const row = [];
        for (let c = 0; c <= rng.e.c; c++) { const x = ws[XLSX.utils.encode_cell({ r, c })]; row.push(!x ? '' : x.f ? '=' + x.f : x.t === 'd' ? fmtDate(x.v) : x.v === undefined ? '' : String(x.w !== undefined && x.t === 'n' && /%/.test(x.z || '') ? x.w : x.v)); }
        sh.data.push(row);
      }
      (ws['!merges'] || []).forEach(m => { sh.merge[cellName(m.s.c, m.s.r)] = [m.e.c - m.s.c + 1, m.e.r - m.s.r + 1]; });
      (ws['!cols'] || []).forEach((c, i) => { if (c && c.wpx) sh.widths[i] = c.wpx; else if (c && c.wch) sh.widths[i] = Math.round(c.wch * 7.2 + 5); });
      return sh;
    });
  }
  const blank = (name) => ({ name, data: [['']], style: {}, merge: {}, widths: [], fmt: {} });

  /* ---------- prevod z modelu do súboru ---------- */
  const reDate = /^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})(?:\s+(\d{1,2}):(\d{2}))?$/;
  function typed(raw, fmt) { // text z bunky → hodnota pre Excel
    const s = String(raw ?? '');
    if (s === '') return null;
    if (s[0] === '=') return { formula: s.slice(1) };
    const t = s.trim();
    let m;
    if (/^-?\d+(\.\d+)?%$/.test(t)) return { v: parseFloat(t) / 100, fmt: fmt && /%/.test(fmt) ? fmt : (t.includes('.') ? '0.' + '0'.repeat(t.split('.')[1].length - 1) + '%' : '0%') };
    if (/^-?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(t)) return { v: +t, fmt: fmt && fmt !== 'date' && !/%/.test(fmt) ? fmt : null };
    if ((m = reDate.exec(t))) { const d = new Date(Date.UTC(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0))); if (!isNaN(d)) return { v: d, fmt: m[4] ? 'd.m.yyyy h:mm' : 'd.m.yyyy' }; }
    if (t === 'TRUE' || t === 'FALSE') return { v: t === 'TRUE' };
    return { v: s };
  }
  function cssToExcel(css) {
    const st = {}; const font = {}; const al = {};
    String(css || '').split(';').forEach(p => {
      const i = p.indexOf(':'); if (i < 0) return;
      const k = p.slice(0, i).trim(), v = p.slice(i + 1).trim();
      if (k === 'font-weight' && /bold|[6-9]00/.test(v)) font.bold = true;
      if (k === 'font-style' && v === 'italic') font.italic = true;
      if (k === 'text-decoration') { if (v.includes('underline')) font.underline = true; if (v.includes('line-through')) font.strike = true; }
      if (k === 'color' && /^#[0-9a-f]{6}$/i.test(v)) font.color = { argb: 'FF' + v.slice(1).toUpperCase() };
      if (k === 'font-size' && parseFloat(v)) font.size = Math.round(parseFloat(v) * 0.75);
      if (k === 'background-color' && /^#[0-9a-f]{6}$/i.test(v)) st.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + v.slice(1).toUpperCase() } };
      if (k === 'text-align' && ['left', 'center', 'right'].includes(v)) al.horizontal = v;
      if (k === 'white-space' && v === 'normal') al.wrapText = true;
    });
    if (Object.keys(font).length) st.font = font;
    if (Object.keys(al).length) st.alignment = al;
    return st;
  }
  async function toXlsx(sheets) {
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Cloud'; wb.calcProperties.fullCalcOnLoad = true; // Excel vzorce po otvorení prepočíta
    sheets.forEach(sh => {
      const ws = wb.addWorksheet(sh.name.slice(0, 31) || 'Hárok', sh.frozen ? { views: [{ state: 'frozen', ySplit: sh.frozen }] } : {});
      sh.widths.forEach((w, i) => { if (w) ws.getColumn(i + 1).width = Math.max(2, (w - 5) / 7.2); });
      sh.data.forEach((row, r) => row.forEach((raw, c) => {
        const nm = cellName(c, r), t = typed(raw, sh.fmt[nm]), style = sh.style[nm];
        if (!t && !style) return;
        const cell = ws.getCell(r + 1, c + 1);
        if (t) {
          if (t.formula !== undefined) { cell.value = { formula: t.formula.replace(/;/g, ','), result: sh.results?.[nm] }; const f0 = sh.fmt[nm]; if (f0) cell.numFmt = f0 === 'date' ? 'd.m.yyyy' : f0; }
          else cell.value = t.v;
          if (t.fmt) cell.numFmt = t.fmt;
        }
        if (style) Object.assign(cell, cssToExcel(style));
      }));
      Object.entries(sh.merge || {}).forEach(([nm, [cs, rs]]) => { const p = parseName(nm); if (p && (cs > 1 || rs > 1)) try { ws.mergeCells(p[1] + 1, p[0] + 1, p[1] + rs, p[0] + cs); } catch (e) {} });
    });
    return new Blob([await wb.xlsx.writeBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }
  function toSheetJS(sheets, type) {
    const wb = XLSX.utils.book_new();
    sheets.forEach(sh => {
      const ws = {}; let maxR = 0, maxC = 0;
      sh.data.forEach((row, r) => row.forEach((raw, c) => {
        const nm = cellName(c, r);
        if (type === 'csv' && sh.values) raw = sh.values[r]?.[c] ?? raw;
        const t = typed(raw, sh.fmt[nm]); if (!t) return;
        maxR = Math.max(maxR, r); maxC = Math.max(maxC, c);
        ws[XLSX.utils.encode_cell({ r, c })] = t.formula !== undefined ? { t: 'n', f: t.formula.replace(/;/g, ',') } : t.v instanceof Date ? { t: 'd', v: t.v, z: t.fmt } : typeof t.v === 'number' ? { t: 'n', v: t.v, z: t.fmt || undefined } : typeof t.v === 'boolean' ? { t: 'b', v: t.v } : { t: 's', v: t.v };
      }));
      ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: maxR, c: maxC } });
      ws['!merges'] = Object.entries(sh.merge || {}).map(([nm, [cs, rs]]) => { const p = parseName(nm); return { s: { c: p[0], r: p[1] }, e: { c: p[0] + cs - 1, r: p[1] + rs - 1 } }; });
      ws['!cols'] = sh.widths.map(w => ({ wpx: w || 100 }));
      XLSX.utils.book_append_sheet(wb, ws, sh.name.slice(0, 31) || 'Hárok');
    });
    if (type === 'csv') return new Blob(['﻿' + XLSX.utils.sheet_to_csv(wb.Sheets[wb.SheetNames[0]], { FS: ';' })], { type: 'text/csv;charset=utf-8' });
    return new Blob([XLSX.write(wb, { bookType: 'ods', type: 'array' })], { type: 'application/vnd.oasis.opendocument.spreadsheet' });
  }

  /* ---------- okno editora ---------- */
  const TPL = '<div class="modal fade" id="sheetModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-fullscreen"><div class="modal-content sh-wrap">'
    + '<div class="sh-top"><button class="btn btn-icon" data-sh="close" title="Zavrieť"><i class="bi bi-arrow-left"></i></button>'
    + '<i class="bi bi-file-earmark-spreadsheet-fill ic-sheet fs-4"></i><div class="sh-title"><div class="sh-name text-truncate"></div><div class="sh-status small text-body-secondary"></div></div>'
    + '<span class="ms-auto"></span><span class="badge rounded-pill text-bg-warning sh-ro d-none">Iba na čítanie</span>'
    + '<button class="btn btn-sm btn-outline-secondary rounded-pill px-3 d-none d-sm-inline-block" data-sh="download"><i class="bi bi-download me-1"></i>Stiahnuť</button>'
    + '<button class="btn btn-sm btn-primary rounded-pill px-3" data-sh="save"><i class="bi bi-cloud-check me-1"></i>Uložiť</button></div>'
    + '<div class="sh-tools">'
    + '<button class="sh-b" data-sh="undo" title="Späť (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button><button class="sh-b" data-sh="redo" title="Znova (Ctrl+Y)"><i class="bi bi-arrow-clockwise"></i></button><span class="sh-sep"></span>'
    + '<button class="sh-b" data-st="font-weight:bold" title="Tučné (Ctrl+B)"><i class="bi bi-type-bold"></i></button><button class="sh-b" data-st="font-style:italic" title="Kurzíva (Ctrl+I)"><i class="bi bi-type-italic"></i></button><button class="sh-b" data-st="text-decoration:underline" title="Podčiarknuté (Ctrl+U)"><i class="bi bi-type-underline"></i></button>'
    + '<label class="sh-b sh-color" title="Farba písma"><i class="bi bi-fonts"></i><input type="color" data-color="color" value="#d93025"></label>'
    + '<label class="sh-b sh-color" title="Farba výplne"><i class="bi bi-paint-bucket"></i><input type="color" data-color="background-color" value="#fff2cc"></label><span class="sh-sep"></span>'
    + '<button class="sh-b" data-st="text-align:left" title="Zarovnať doľava"><i class="bi bi-text-left"></i></button><button class="sh-b" data-st="text-align:center" title="Na stred"><i class="bi bi-text-center"></i></button><button class="sh-b" data-st="text-align:right" title="Doprava"><i class="bi bi-text-right"></i></button>'
    + '<button class="sh-b" data-st="white-space:normal" title="Zalamovať text"><i class="bi bi-text-wrap"></i></button><button class="sh-b" data-sh="clearfmt" title="Vymazať formátovanie"><i class="bi bi-eraser"></i></button><span class="sh-sep"></span>'
    + '<button class="sh-b" data-sh="merge" title="Zlúčiť bunky"><i class="bi bi-arrows-angle-expand"></i></button><button class="sh-b" data-sh="unmerge" title="Zrušiť zlúčenie"><i class="bi bi-arrows-angle-contract"></i></button><span class="sh-sep"></span>'
    + '<button class="sh-b" data-sh="addrow" title="Vložiť riadok"><i class="bi bi-plus-square"></i><small>riadok</small></button><button class="sh-b" data-sh="addcol" title="Vložiť stĺpec"><i class="bi bi-plus-square"></i><small>stĺpec</small></button>'
    + '<button class="sh-b" data-sh="delrow" title="Zmazať riadok"><i class="bi bi-dash-square"></i><small>riadok</small></button><button class="sh-b" data-sh="delcol" title="Zmazať stĺpec"><i class="bi bi-dash-square"></i><small>stĺpec</small></button><span class="sh-sep"></span>'
    + '<button class="sh-b" data-sh="sortasc" title="Zoradiť stĺpec A → Z"><i class="bi bi-sort-alpha-down"></i></button><button class="sh-b" data-sh="sortdesc" title="Zoradiť stĺpec Z → A"><i class="bi bi-sort-alpha-up-alt"></i></button>'
    + '<button class="sh-b" data-sh="sum" title="Súčet pod výberom"><i class="bi bi-plus-slash-minus"></i><small>Σ</small></button>'
    + '</div>'
    + '<div class="sh-fbar"><span class="sh-ref">A1</span><span class="sh-fx">fx</span><input class="sh-formula" spellcheck="false" placeholder="Hodnota alebo vzorec, napr. =SUM(A1:A10)"></div>'
    + '<div class="sh-grid"></div>'
    + '<div class="sh-bottom"><button class="sh-b" data-sh="addsheet" title="Pridať hárok"><i class="bi bi-plus-lg"></i></button><div class="sh-tabs"></div><button class="sh-b" data-sh="delsheet" title="Zmazať tento hárok"><i class="bi bi-trash3"></i></button><div class="sh-info small text-body-secondary ms-auto"></div></div>'
    + '</div></div></div>';

  let S = null; // stav otvoreného editora

  async function open(it, o) {
    if (!document.getElementById('sheetModal')) { document.body.insertAdjacentHTML('beforeend', TPL); wire(); }
    const M = document.getElementById('sheetModal'), $ = (s) => M.querySelector(s);
    if (S) destroyAll();
    S = { it, o, ext: extOf(it.name), sheets: [], cur: 0, t: null, insts: [], dirty: false, saving: false, ro: !!o.readOnly, updated: it.updated || '', modal: bootstrap.Modal.getOrCreateInstance(M) };
    $('.sh-name').textContent = it.name;
    $('.sh-grid').innerHTML = '<div class="sh-load"><div class="spinner-border text-primary mb-2"></div><div>Otváram tabuľku…</div></div>';
    $('.sh-tabs').innerHTML = ''; status('');
    S.modal.show();
    try {
      await libs(S.ext !== 'csv');
      const buf = o.buffer || await (await fetch(o.url, { credentials: 'same-origin', cache: 'no-store' })).arrayBuffer();
      const warn = [];
      if (S.ext === 'xlsx' || S.ext === 'xlsm') {
        // čo editor nevie a pri uložení by sa stratilo
        try {
          const z = XLSX.read(buf, { type: 'array', bookFiles: true });
          const files = Object.keys(z.files || {}).join('\n') + '\n' + (z.keys || []).join('\n');
          if (/xl\/charts\//.test(files)) warn.push('grafy');
          if (/xl\/pivotTables\//.test(files)) warn.push('kontingenčné tabuľky');
          if (/vbaProject\.bin/.test(files)) warn.push('makrá');
          if (/xl\/media\//.test(files)) warn.push('obrázky');
        } catch (e) {}
        try {
          const wb = new ExcelJS.Workbook();
          await wb.xlsx.load(buf);
          let cf = false; wb.eachSheet(ws => { if ((ws.conditionalFormattings || []).length) cf = true; });
          if (cf) warn.push('podmienené formátovanie');
          S.sheets = fromExcel(wb);
        } catch (er) { // niektoré súbory (napr. s grafmi) ExcelJS neprečíta – aspoň hodnoty a vzorce cez SheetJS
          S.sheets = fromSheetJS(XLSX.read(buf, { type: 'array', cellFormula: true, cellDates: true }));
          warn.push('formátovanie, ktoré sa nedá načítať');
        }
      } else if (S.ext === 'csv') {
        const txt = new TextDecoder('utf-8').decode(buf);
        const wb = XLSX.read(txt.replace(/^﻿/, ''), { type: 'string', raw: true, FS: (txt.split('\n')[0].split(';').length > txt.split('\n')[0].split(',').length) ? ';' : ',' });
        S.sheets = fromSheetJS(wb).slice(0, 1);
      } else if (S.ext === 'ods' || S.ext === 'xls') {
        S.sheets = fromSheetJS(XLSX.read(buf, { type: 'array', cellFormula: true, cellDates: true }));
        if (S.ext === 'xls') S.saveAs = it.name.replace(/\.xls$/i, '.xlsx');
      } else S.sheets = [blank('Hárok1')];
      if (S.ext === 'xlsm') warn.push('makrá');
      if (warn.length && !S.ro) {
        const go = await Cloud.confirm('Súbor obsahuje: ' + [...new Set(warn)].join(', '),
          'Tieto časti editor nezobrazí a pri uložení by sa zo súboru stratili (pôvodná verzia by ostala vo Verziách). Odporúčam otvoriť iba na čítanie.', 'Iba na čítanie', false, 'Upravovať aj tak');
        S.ro = go !== 'alt';
      }
      S.sheets.forEach(sh => { sh.uid = Math.random(); });
      $('.sh-ro').classList.toggle('d-none', !S.ro);
      M.classList.toggle('readonly', S.ro);
      $('[data-sh="save"]').classList.toggle('d-none', S.ro);
      if (S.saveAs) status('Uloží sa ako ' + S.saveAs + ' (starý formát .xls sa nedá zapisovať)');
      $('.sh-grid').innerHTML = ''; tabs();
      if (!M.classList.contains('show')) await new Promise(r => M.addEventListener('shown.bs.modal', r, { once: true }));
      show(0);
    } catch (e) {
      $('.sh-grid').innerHTML = '<div class="sh-load text-danger"><i class="bi bi-exclamation-triangle fs-2 mb-2"></i><div>' + esc(e.message || 'Tabuľku sa nepodarilo otvoriť.') + '</div></div>';
    }
  }

  function status(t, cls) { const e = document.querySelector('#sheetModal .sh-status'); if (e) { e.textContent = t; e.className = 'sh-status small ' + (cls || 'text-body-secondary'); } }
  function markDirty() { if (S.ro || !S.t) return; S.dirty = true; status('Neuložené zmeny', 'text-warning-emphasis'); clearTimeout(S.autoT); S.autoT = setTimeout(() => save(true), 4000); }

  /* hárky z mriežok späť do modelu */
  function pull() { S.insts.forEach((t, i) => { if (t) pullOne(t, S.sheets[i]); }); }
  function pullOne(t, sh) {
    const data = t.getData();
    let lastR = 0, lastC = 0;
    data.forEach((row, r) => row.forEach((v, c) => { if (String(v ?? '') !== '') { lastR = Math.max(lastR, r); lastC = Math.max(lastC, c); } }));
    const style = t.getStyle(); const st = {};
    Object.entries(style || {}).forEach(([k, v]) => { const p = parseName(k); if (v && p && v.replace(/[\s;]/g, '')) { st[k] = v; lastR = Math.max(lastR, p[1]); lastC = Math.max(lastC, p[0]); } });
    const merge = t.getMerge() || {};
    Object.keys(merge).forEach(k => { const p = parseName(k); if (p) { lastR = Math.max(lastR, p[1] + merge[k][1] - 1); lastC = Math.max(lastC, p[0] + merge[k][0] - 1); } });
    sh.data = data.slice(0, lastR + 1).map(r => r.slice(0, lastC + 1).map(v => v ?? ''));
    sh.style = st; sh.merge = merge;
    const w = t.getWidth(); sh.widths = (Array.isArray(w) ? w : []).slice(0, lastC + 1).map(Number);
    sh.results = {}; sh.values = [];
    sh.data.forEach((row, r) => { sh.values[r] = row.map((v, c) => { const x = String(v)[0] === '=' ? t.getValueFromCoords(c, r, true) : v; if (String(v)[0] === '=') sh.results[cellName(c, r)] = isNaN(+x) || x === '' ? x : +x; return x; }); });
  }
  function destroyAll() {
    (S && S.insts || []).forEach(t => { try { if (t) jspreadsheet.destroy(t.el); } catch (e) {} });
    if (S) { S.insts = []; S.t = null; }
    const g = document.querySelector('#sheetModal .sh-grid'); if (g) g.innerHTML = '';
  }
  /* zobrazenie čísla v bunke: desatinná čiarka, percentá podľa formátu, bez dlhých chvostov */
  function display(sh) {
    return function (inst, cell, x, y, val, label, name) {
      cell.classList.remove('num');
      if (val === '' || val === null || val === undefined) return;
      const shown = cell.textContent.trim();
      const stl = (inst && inst.jexcel && inst.jexcel.getStyle ? inst.jexcel.getStyle(name) : sh.style[name]) || '';
      const own = /text-align/.test(stl) && !/text-align:\s*left/.test(stl) || /text-align:\s*(center|right)/.test(stl);
      const numLike = reDate.test(shown) || (shown !== '' && !isNaN(+shown) && !/[a-z]/i.test(shown));
      if (numLike && !own) cell.classList.add('num'); // čísla a dátumy doprava ako v Exceli
      if (!numLike || reDate.test(shown)) return;
      const n = +shown, f = sh.fmt[name];
      if (f && /%/.test(f) && f !== 'date') { const d = (f.split('.')[1] || '').replace(/[^0]/g, '').length; cell.textContent = (n * 100).toFixed(d).replace('.', ',') + ' %'; return; }
      const r = Math.round(n * 1e9) / 1e9;
      cell.textContent = String(r).replace('.', ',');
    };
  }

  function show(i) {
    const M = document.getElementById('sheetModal'), grid = M.querySelector('.sh-grid');
    S.cur = i;
    [...grid.children].forEach(el => { el.style.display = 'none'; });
    if (S.insts[i]) { S.t = S.insts[i]; S.t.el.parentNode.style.display = ''; jspreadsheet.current = S.t; tabs(); S.sel = [0, 0, 0, 0]; fbar(); return; }
    const sh = S.sheets[i];
    const cols = Math.max(26, (sh.data[0] || []).length + 5, sh.widths.length + 3), rows = Math.max(100, sh.data.length + 30);
    const box = document.createElement('div'); box.className = 'sh-sheet'; grid.appendChild(box);
    const host = document.createElement('div'); box.appendChild(host);
    const h = grid.clientHeight || 500;
    S.t = S.insts[i] = jspreadsheet(host, {
      data: sh.data.map(r => r.slice()), style: { ...sh.style }, mergeCells: { ...sh.merge },
      columns: Array.from({ length: cols }, (_, c) => ({ type: 'text', width: sh.widths[c] || 100, align: 'left', wordWrap: true })),
      minDimensions: [cols, rows], defaultColWidth: 100, tableOverflow: true, tableWidth: grid.clientWidth + 'px', tableHeight: h + 'px', lazyLoading: rows > 300, loadingSpin: false,
      editable: !S.ro, allowExport: false, allowComments: false, columnSorting: false, parseFormulas: true, autoIncrement: true, wordWrap: true,
      text: SK, contextMenu: ctxMenu, updateTable: display(sh),
      onchange: markDirty, onchangestyle: markDirty, onmerge: markDirty, oninsertrow: markDirty, ondeleterow: markDirty, oninsertcolumn: markDirty, ondeletecolumn: markDirty, onresizecolumn: markDirty, onmoverow: markDirty, onmovecolumn: markDirty, onpaste: markDirty, onundo: markDirty, onredo: markDirty,
      onbeforechange: (el, cell, x, y, v) => normalize(v),
      onselection: (el, x1, y1, x2, y2) => { S.sel = [x1, y1, x2, y2]; fbar(); },
    });
    S.sel = [0, 0, 0, 0]; fbar(); tabs();
    M.querySelector('.sh-info').textContent = '';
  }
  /* čo používateľ napísal: desatinná čiarka → bodka, „;“ vo vzorci → „,“ */
  function normalize(v) {
    const s = String(v ?? '');
    if (s[0] === '=') { let q = false, out = ''; for (const ch of s) { if (ch === '"') q = !q; out += !q && ch === ';' ? ',' : ch; } return out.replace(/(\d),(\d)/g, (m, a, b, i, all) => m); }
    if (/^-?\d{1,3}(\s\d{3})*(,\d+)?%?$|^-?\d+,\d+%?$/.test(s.trim())) return s.trim().replace(/\s/g, '').replace(',', '.');
    return v;
  }
  function fbar() {
    const M = document.getElementById('sheetModal'), [x1, y1, x2, y2] = S.sel || [0, 0, 0, 0];
    M.querySelector('.sh-ref').textContent = cellName(x1, y1) + (x1 !== x2 || y1 !== y2 ? ':' + cellName(x2, y2) : '');
    const inp = M.querySelector('.sh-formula');
    if (document.activeElement !== inp) inp.value = S.t ? (S.t.getValueFromCoords(x1, y1) ?? '') : '';
    inp.disabled = S.ro;
    // súčet výberu ako v Exceli
    if (S.t && (x1 !== x2 || y1 !== y2)) {
      let sum = 0, n = 0, cnt = 0;
      for (let y = Math.min(y1, y2); y <= Math.max(y1, y2); y++) for (let x = Math.min(x1, x2); x <= Math.max(x1, x2); x++) { const v = S.t.getValueFromCoords(x, y, true); if (String(v) !== '') cnt++; if (String(v).trim() !== '' && !isNaN(+v)) { sum += +v; n++; } }
      M.querySelector('.sh-info').textContent = n ? 'Súčet: ' + (Math.round(sum * 1e6) / 1e6).toLocaleString('sk') + ' · Priemer: ' + (Math.round(sum / n * 1e4) / 1e4).toLocaleString('sk') + ' · Počet: ' + cnt : cnt ? 'Počet: ' + cnt : '';
    } else M.querySelector('.sh-info').textContent = '';
  }
  function tabs() {
    const box = document.querySelector('#sheetModal .sh-tabs');
    box.innerHTML = S.sheets.map((sh, i) => '<button class="sh-tab' + (i === S.cur ? ' active' : '') + '" data-tab="' + i + '">' + esc(sh.name) + '</button>').join('');
  }

  const SK = { noRecordsFound: 'Nič sa nenašlo', insertANewColumnBefore: 'Vložiť stĺpec pred', insertANewColumnAfter: 'Vložiť stĺpec za', deleteSelectedColumns: 'Zmazať vybrané stĺpce', renameThisColumn: 'Premenovať stĺpec',
    orderAscending: 'Zoradiť A → Z', orderDescending: 'Zoradiť Z → A', insertANewRowBefore: 'Vložiť riadok nad', insertANewRowAfter: 'Vložiť riadok pod', deleteSelectedRows: 'Zmazať vybrané riadky', copy: 'Kopírovať', paste: 'Prilepiť',
    areYouSureToDeleteTheSelectedRows: 'Naozaj zmazať vybrané riadky?', areYouSureToDeleteTheSelectedColumns: 'Naozaj zmazať vybrané stĺpce?', thisActionWillDestroyAnyExistingMergedCellsAreYouSure: 'Zrušia sa zlúčené bunky. Pokračovať?',
    thisActionWillClearYourSearchResultsAreYouSure: 'Zruší sa hľadanie. Pokračovať?', thereIsAConflictWithAnotherMergedCell: 'Prekrýva sa s inými zlúčenými bunkami', invalidMergeProperties: 'Bunky sa nedajú zlúčiť', cellAlreadyMerged: 'Bunka je už zlúčená', noCellsSelected: 'Nie sú vybrané bunky' };
  function ctxMenu(obj, x, y) {
    const items = [];
    if (y !== null && !S.ro) {
      items.push({ title: SK.insertANewRowBefore, onclick: () => obj.insertRow(1, +y, 1) }, { title: SK.insertANewRowAfter, onclick: () => obj.insertRow(1, +y) }, { title: SK.deleteSelectedRows, onclick: () => obj.deleteRow(obj.getSelectedRows().length ? undefined : y) });
    }
    if (x !== null && y === null && !S.ro) {
      items.push({ title: SK.insertANewColumnBefore, onclick: () => obj.insertColumn(1, +x, 1) }, { title: SK.insertANewColumnAfter, onclick: () => obj.insertColumn(1, +x) }, { title: SK.deleteSelectedColumns, onclick: () => obj.deleteColumn(obj.getSelectedColumns().length ? undefined : x) });
      items.push({ type: 'line' }, { title: SK.orderAscending, onclick: () => obj.orderBy(x, 0) }, { title: SK.orderDescending, onclick: () => obj.orderBy(x, 1) });
    }
    items.push({ type: 'line' }, { title: SK.copy, shortcut: 'Ctrl + C', onclick: () => obj.copy(true) });
    if (!S.ro && document.queryCommandSupported && document.queryCommandSupported('paste')) items.push({ title: SK.paste, shortcut: 'Ctrl + V', onclick: () => { if (obj.selectedCell) navigator.clipboard?.readText().then(t => obj.paste(obj.selectedCell[0], obj.selectedCell[1], t)); } });
    return items;
  }

  /* ---------- ovládanie ---------- */
  function sel() { const [x1, y1, x2, y2] = S.sel || [0, 0, 0, 0]; return [Math.min(x1, x2), Math.min(y1, y2), Math.max(x1, x2), Math.max(y1, y2)]; }
  function cellsInSel() { const [a, b, c, d] = sel(), out = []; for (let y = b; y <= d; y++) for (let x = a; x <= c; x++) out.push(cellName(x, y)); return out; }
  function toggleStyle(decl) {
    if (S.ro) return;
    const [k, v] = decl.split(':'), cells = cellsInSel(), st = S.t.getStyle();
    const on = cells.every(n => new RegExp('(^|;)\\s*' + k + '\\s*:\\s*' + v.replace(/[()]/g, '\\$&')).test(st[n] || ''));
    const upd = {};
    cells.forEach(n => { upd[n] = k + ':' + (on ? (k === 'text-align' ? 'left' : k === 'white-space' ? 'nowrap' : k === 'text-decoration' ? 'none' : 'normal') : v); });
    S.t.setStyle(upd); markDirty();
  }
  function wire() {
    const M = document.getElementById('sheetModal'), $ = (s) => M.querySelector(s);
    M.addEventListener('click', (e) => {
      const tab = e.target.closest('[data-tab]');
      if (tab) { const i = +tab.dataset.tab; if (i !== S.cur) show(i); return; }
      const st = e.target.closest('[data-st]'); if (st && S.t) return toggleStyle(st.dataset.st);
      const b = e.target.closest('[data-sh]'); if (!b) return;
      const a = b.dataset.sh, t = S.t;
      const [x1, y1, x2, y2] = sel();
      ({
        close, save: () => save(false), download,
        undo: () => t?.undo(), redo: () => t?.redo(),
        clearfmt: () => { if (S.ro) return; const u = {}; cellsInSel().forEach(n => { u[n] = 'font-weight:normal;font-style:normal;text-decoration:none;color:inherit;background-color:transparent;text-align:left'; }); t.setStyle(u); setTimeout(() => { const s2 = t.getStyle(); cellsInSel().forEach(n => { s2[n] = ''; }); t.resetStyle(cellsInSel().reduce((o, n) => (o[n] = '', o), {})); markDirty(); }, 0); },
        merge: () => { if (S.ro) return; if (x1 === x2 && y1 === y2) return Cloud.toast('Najprv označ viac buniek', 'warning'); try { t.setMerge(cellName(x1, y1), x2 - x1 + 1, y2 - y1 + 1); } catch (er) { Cloud.toast('Bunky sa nedajú zlúčiť', 'warning'); } },
        unmerge: () => { if (S.ro) return; try { t.removeMerge(cellName(x1, y1)); markDirty(); } catch (er) {} },
        addrow: () => !S.ro && t.insertRow(1, y2), addcol: () => !S.ro && t.insertColumn(1, x2),
        delrow: () => !S.ro && t.deleteRow(y1, y2 - y1 + 1), delcol: () => !S.ro && t.deleteColumn(x1, x2 - x1 + 1),
        sortasc: () => !S.ro && t.orderBy(x1, 0), sortdesc: () => !S.ro && t.orderBy(x1, 1),
        sum: () => { if (S.ro) return; const yEnd = y2 + 1; t.setValueFromCoords(x1, yEnd, '=SUM(' + cellName(x1, y1) + ':' + cellName(x1, y2) + ')'); t.updateSelectionFromCoords(x1, yEnd, x1, yEnd); },
        delsheet: async () => {
          if (S.ro) return;
          if (S.sheets.length < 2) return Cloud.toast('Tabuľka musí mať aspoň jeden hárok', 'warning');
          if (!await Cloud.confirm('Zmazať hárok „' + S.sheets[S.cur].name + '“?', 'Všetky údaje v ňom sa odstránia.', 'Zmazať')) return;
          const i = S.cur;
          try { jspreadsheet.destroy(S.insts[i].el); } catch (er) {}
          S.insts[i].el.parentNode.remove(); S.insts.splice(i, 1); S.sheets.splice(i, 1); S.t = null; show(Math.max(0, i - 1)); S.dirty = false; markDirty();
        },
        addsheet: async () => { if (S.ro) return; const n = await Cloud.prompt('Nový hárok', 'Hárok' + (S.sheets.length + 1), 'Pridať'); if (!n) return; S.sheets.push(blank(n.trim().slice(0, 31))); show(S.sheets.length - 1); markDirty(); },
      })[a]?.();
    });
    M.addEventListener('input', (e) => {
      const c = e.target.closest('[data-color]'); if (!c || !S.t || S.ro) return;
      const u = {}; cellsInSel().forEach(n => { u[n] = c.dataset.color + ':' + c.value; }); S.t.setStyle(u); markDirty();
    });
    // dvojklik / podržanie na hárku: premenovať, zmazať
    M.querySelector('.sh-tabs').addEventListener('dblclick', async (e) => {
      const tab = e.target.closest('[data-tab]'); if (!tab || S.ro) return;
      const i = +tab.dataset.tab;
      const n = await Cloud.prompt('Premenovať hárok', S.sheets[i].name, 'Uložiť');
      if (!n || !String(n).trim()) return;
      S.sheets[i].name = String(n).trim().slice(0, 31); tabs(); markDirty();
    });
    // riadok so vzorcom
    const inp = $('.sh-formula');
    inp.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        if (S.t && !S.ro) { const [x, y] = S.sel; S.t.setValueFromCoords(x, y, normalize(inp.value)); S.t.updateSelectionFromCoords(x, y + (e.key === 'Enter' ? 1 : 0) , x, y + (e.key === 'Enter' ? 1 : 0)); }
        inp.blur();
      }
      if (e.key === 'Escape') { inp.blur(); fbar(); }
    });
    // klávesové skratky
    document.addEventListener('keydown', (e) => {
      if (!M.classList.contains('show') || !S || !S.t) return;
      const k = e.key.toLowerCase(), ctrl = e.ctrlKey || e.metaKey;
      if (ctrl && k === 's') { e.preventDefault(); save(false); }
      if (ctrl && ['b', 'i', 'u'].includes(k) && !e.target.closest('input')) { e.preventDefault(); toggleStyle({ b: 'font-weight:bold', i: 'font-style:italic', u: 'text-decoration:underline' }[k]); }
    });
    window.addEventListener('resize', () => { if (S && S.t && M.classList.contains('show')) { const g = $('.sh-grid'); g.querySelectorAll('.jexcel_content').forEach(c => { c.style.width = g.clientWidth + 'px'; c.style.maxHeight = g.clientHeight + 'px'; c.style.height = g.clientHeight + 'px'; }); } });
    window.addEventListener('beforeunload', (e) => { if (S && S.dirty && M.classList.contains('show')) { e.preventDefault(); e.returnValue = ''; } });
  }

  async function build() {
    pull();
    const type = S.saveAs ? 'xlsx' : S.ext === 'xlsm' ? 'xlsx' : S.ext;
    if (type === 'csv' || type === 'ods') return toSheetJS(S.sheets, type);
    return toXlsx(S.sheets);
  }
  async function save(auto) {
    if (!S || S.ro || S.saving) return;
    if (auto && !S.dirty) return;
    clearTimeout(S.autoT);
    S.saving = true; status('Ukladá sa…');
    try {
      const blob = await build();
      const fd = new FormData();
      fd.append('data', blob, S.saveAs || S.it.name);
      if (S.saveAs) { fd.append('parent', S.it.folder_id || S.o.folder || ''); fd.append('name', S.saveAs); }
      else { fd.append('id', S.it.id); fd.append('updated', S.updated || ''); }
      const r = await Cloud.api('api.php?action=save_binary', fd);
      if (!r.ok) throw new Error(r.error || 'Uloženie zlyhalo');
      if (S.saveAs) { S.it = { ...S.it, id: r.id, name: r.name }; S.ext = 'xlsx'; S.saveAs = null; document.querySelector('#sheetModal .sh-name').textContent = r.name; }
      S.updated = r.updated || ''; S.dirty = false;
      status('Uložené ' + new Date().toLocaleTimeString('sk', { hour: '2-digit', minute: '2-digit' }) + ' · predchádzajúci stav je vo Verziách', 'text-success');
      S.o.onSaved && S.o.onSaved(r);
    } catch (e) {
      status(e.message, 'text-danger');
      if (!auto) Cloud.toast(e.message, 'danger');
    } finally { S.saving = false; }
  }
  async function download() {
    const blob = await build(), a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = S.saveAs || S.it.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }
  async function close() {
    if (S && S.dirty) {
      const r = await Cloud.confirm('Uložiť zmeny?', 'V tabuľke sú neuložené zmeny.', 'Uložiť', false, 'Zahodiť');
      if (r === true) { await save(false); if (S.dirty) return; }
      else if (r !== 'alt') return;
    }
    clearTimeout(S?.autoT);
    S.modal.hide();
    destroyAll();
    S.o.onClose && S.o.onClose();
  }

  /** Prázdna tabuľka .xlsx na vytvorenie nového súboru. */
  async function blankXlsx() { await libs(true); return toXlsx([blank('Hárok1')]); }

  window.CloudSheet = { open, blankXlsx, _test: { typed, normalize, fromExcel, toXlsx, cssToExcel } };
})();
