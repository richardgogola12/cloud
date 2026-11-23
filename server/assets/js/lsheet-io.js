/* Prevod tabuliek medzi súbormi (.xlsx cez ExcelJS, .ods/.xls/.csv cez SheetJS) a editorom Luckysheet.
   Používa ho editor tabuliek (sheet.php) aj testy. Všetko beží v prehliadači – nič nejde na cudzie servery. */
(function () {
  const FONTS = ['Arial', 'Times New Roman', 'Tahoma', 'Verdana', 'Calibri', 'Courier New', 'Georgia'];
  const THEME = ['FFFFFF', '000000', 'E7E6E6', '44546A', '4472C4', 'ED7D31', 'A5A5A5', 'FFC000', '5B9BD5', '70AD47', '0563C1', '954F72'];
  const BORDER = ['', 'thin', 'hair', 'dotted', 'dashed', 'dashDot', 'dashDotDot', 'double', 'medium', 'mediumDashed', 'mediumDashDot', 'mediumDashDotDot', 'slantDashDot', 'thick'];
  const OPS = { between: 'bw', notBetween: 'nb', equal: 'eq', notEqual: 'ne', greaterThan: 'gt', lessThan: 'lt', greaterThanOrEqual: 'gte', lessThanOrEqual: 'lte' };
  const OPS_R = Object.fromEntries(Object.entries(OPS).map(([a, b]) => [b, a]));
  const DATE_FMT = 'd\\.m\\.yyyy';
  const EXCEL_EPOCH = 25569; // 1.1.1970 ako poradové číslo dňa v Exceli

  /* ---------- pomocné ---------- */
  const colName = (i) => { let s = ''; i++; while (i > 0) { const m = (i - 1) % 26; s = String.fromCharCode(65 + m) + s; i = Math.floor((i - 1) / 26); } return s; };
  const addr = (r, c) => colName(c) + (r + 1);
  function parseAddr(a) { const m = /^\$?([A-Z]+)\$?(\d+)$/i.exec(String(a).trim()); if (!m) return null; let c = 0; for (const ch of m[1].toUpperCase()) c = c * 26 + ch.charCodeAt(0) - 64; return [+m[2] - 1, c - 1]; }
  function parseRange(ref) { // "A1:C3" → {r1,c1,r2,c2} (0-based)
    const [a, b] = String(ref).replace(/^.*!/, '').split(':'); const p = parseAddr(a), q = parseAddr(b || a);
    return p && q ? { r1: Math.min(p[0], q[0]), c1: Math.min(p[1], q[1]), r2: Math.max(p[0], q[0]), c2: Math.max(p[1], q[1]) } : null;
  }
  const rangeRef = (rg) => addr(rg.row[0], rg.column[0]) + ':' + addr(rg.row[1], rg.column[1]);
  const hex2 = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
  function xColor(c) { // farba z Excelu (argb / téma s odtieňom) → #rrggbb
    if (!c) return null;
    let h = null;
    if (c.argb && /^[0-9a-f]{6,8}$/i.test(c.argb)) h = c.argb.slice(-6);
    else if (c.theme !== undefined && THEME[c.theme]) h = THEME[c.theme];
    else if (c.indexed !== undefined) h = ({ 8: '000000', 9: 'FFFFFF', 10: 'FF0000', 11: '00FF00', 12: '0000FF', 13: 'FFFF00', 64: null, 65: null })[c.indexed] ?? null;
    if (!h) return null;
    let rgb = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16));
    if (c.tint) rgb = rgb.map(v => c.tint < 0 ? v * (1 + c.tint) : v + (255 - v) * c.tint);
    return '#' + rgb.map(hex2).join('');
  }
  function argb(css) { // #rgb, #rrggbb, rgb(…) → { argb: 'FFRRGGBB' }
    if (!css) return null;
    let s = String(css).trim(), m;
    if ((m = /^#([0-9a-f]{3})$/i.exec(s))) s = '#' + m[1].split('').map(x => x + x).join('');
    if ((m = /^#([0-9a-f]{6})$/i.exec(s))) return { argb: 'FF' + m[1].toUpperCase() };
    if ((m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(s))) return { argb: 'FF' + [m[1], m[2], m[3]].map(hex2).join('').toUpperCase() };
    return null;
  }
  const cssRgb = (h) => { const m = /^#?([0-9a-f]{6})$/i.exec(h || ''); if (!m) return h; const n = parseInt(m[1], 16); return 'rgb(' + (n >> 16) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ')'; };

  /* formát čísla z Excelu → bezpečný pre Luckysheet (bodky v dátume, miestne značky) */
  const BUILTIN = { 'mm-dd-yy': DATE_FMT, 'm/d/yy': DATE_FMT, 'm/d/yyyy': DATE_FMT, 'd-mmm-yy': 'd\\. mmm yyyy', 'd-mmm': 'd\\. mmm', 'mmm-yy': 'mmm yyyy', 'm/d/yy h:mm': DATE_FMT + ' h:mm', 'm/d/yy\\ h:mm': DATE_FMT + ' h:mm' };
  function isDateFmt(f) { const bare = String(f || '').replace(/"[^"]*"|\\.|\[[^\]]*\]/g, ''); return /[dmyhs]/i.test(bare) && !/[0#?]/.test(bare.replace(/s\.0+/i, '')) && !/^general$/i.test(bare.trim()); }
  function fixFmt(f) {
    if (!f || /^general$/i.test(f)) return 'General';
    f = String(f);
    if (BUILTIN[f]) return BUILTIN[f];
    f = f.replace(/\[\$([^\]\-]*)-[0-9A-Fa-f]+\]/g, (m, sym) => sym ? '"' + sym + '"' : '').replace(/^\s+/, '');
    if (isDateFmt(f)) f = f.replace(/("[^"]*"|\\.|\[[^\]]*\])|\./g, (m, keep) => keep || '\\.');
    return f || 'General';
  }
  function outFmt(f) { // formát pre Excel: € v úvodzovkách
    if (!f || f === 'General') return null;
    return String(f).replace(/("[^"]*"|\\.)|€/g, (m, keep) => keep || '"€"');
  }
  const serialToDate = (v) => new Date(Math.round((v - EXCEL_EPOCH) * 864e5));
  const dateToSerial = (d) => d.getTime() / 864e5 + EXCEL_EPOCH; // ExcelJS dáva dátumy v UTC

  /** Text, ako ho píše človek po slovensky → hodnota bunky (číslo s čiarkou, %, €, dátum). Inak null. */
  function parseLocal(s) {
    const t = String(s ?? '').trim().replace(/ /g, ' ');
    let m;
    if ((m = /^(-?)(\d{1,3}(?: \d{3})+|\d+)(?:,(\d+))?(?:\.(\d+))?\s*(%|€|eur)?$/i.exec(t))) {
      if (m[3] !== undefined && m[4] !== undefined) return null;
      const dec = m[3] ?? m[4];
      if (m[4] !== undefined && !m[5] && /\s/.test(m[2])) return null;
      let v = parseFloat(m[1] + m[2].replace(/ /g, '') + (dec !== undefined ? '.' + dec : ''));
      if (!isFinite(v)) return null;
      const places = dec ? dec.length : 0, z = places ? '.' + '0'.repeat(places) : '';
      if (m[5] === '%') return { v: v / 100, ct: { fa: '0' + z + '%', t: 'n' } };
      if (m[5]) return { v, ct: { fa: '#,##0' + (places ? '.00' : '') + ' €', t: 'n' } };
      if (/ /.test(m[2])) return { v, ct: { fa: '#,##0' + z, t: 'n' } };
      if (m[3] === undefined && m[4] === undefined) return null; // celé číslo zvládne Luckysheet sám
      return { v, ct: { fa: 'General', t: 'n' } };
    }
    if ((m = /^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/.exec(t))) {
      const d = Date.UTC(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
      const chk = new Date(d); if (chk.getUTCDate() !== +m[1] || chk.getUTCMonth() !== +m[2] - 1) return null;
      return { v: d / 864e5 + EXCEL_EPOCH, ct: { fa: DATE_FMT + (m[4] ? (m[6] ? ' h:mm:ss' : ' h:mm') : ''), t: 'd' } };
    }
    return null;
  }
  /** „;“ medzi argumentmi (slovenský Excel) → „,“; mimo textu v úvodzovkách. */
  function fixFormula(f) {
    let q = false, out = '';
    for (const ch of String(f)) { if (ch === '"') q = !q; out += !q && ch === ';' ? ',' : ch; }
    return out;
  }

  /* ---------- štýly ---------- */
  function styleFromExcel(cell, o) {
    const f = cell.font || {}, a = cell.alignment || {}, fill = cell.fill;
    if (f.bold) o.bl = 1; if (f.italic) o.it = 1; if (f.underline) o.un = 1; if (f.strike) o.cl = 1;
    const fc = xColor(f.color); if (fc && fc !== '#000000') o.fc = fc;
    if (f.size && +f.size !== 11) o.fs = f.size;
    if (f.name && !/^calibri$/i.test(f.name)) o.ff = f.name;
    if (fill && fill.type === 'pattern' && fill.pattern && fill.pattern !== 'none') { const bg = xColor(fill.fgColor) || xColor(fill.bgColor); if (bg && bg !== '#ffffff' || bg && fill.pattern === 'solid') o.bg = bg; }
    if (fill && fill.type === 'gradient' && fill.stops && fill.stops[0]) { const bg = xColor(fill.stops[0].color); if (bg) o.bg = bg; }
    const ht = { left: 1, center: 0, centerContinuous: 0, right: 2, justify: 1, distributed: 0 }[a.horizontal]; if (ht !== undefined) o.ht = ht;
    const vt = { top: 1, middle: 0, bottom: 2, justify: 0, distributed: 0 }[a.vertical]; if (vt !== undefined && vt !== 2) o.vt = vt;
    if (a.wrapText) o.tb = '2';
    const tr = a.textRotation; if (tr) o.tr = tr === 'vertical' || tr === 255 ? '3' : tr === 90 ? '4' : tr === -90 || tr === 180 ? '5' : tr > 0 ? '1' : '2';
    return o;
  }
  function styleToExcel(v, cell) {
    const font = {}, al = {};
    if (v.bl == 1) font.bold = true; if (v.it == 1) font.italic = true; if (v.un && v.un != 0) font.underline = true; if (v.cl == 1) font.strike = true;
    const fc = argb(v.fc); if (fc && fc.argb !== 'FF000000') font.color = fc;
    if (v.fs && +v.fs !== 11) font.size = +v.fs;
    if (v.ff !== undefined && v.ff !== null && v.ff !== '') { const n = typeof v.ff === 'number' || /^\d+$/.test(v.ff) ? FONTS[+v.ff] : String(v.ff); if (n && n !== 'Arial') font.name = n; }
    if (Object.keys(font).length) cell.font = font;
    const bg = argb(v.bg); if (bg && v.bg !== '#ffffff' && v.bg !== '#fff') cell.fill = { type: 'pattern', pattern: 'solid', fgColor: bg };
    const ht = v.ht !== undefined && v.ht !== null && !(v._ah && v.ht == 2) ? ({ 0: 'center', 1: 'left', 2: 'right' })[v.ht] : null; if (ht) al.horizontal = ht;
    const vt = v.vt !== undefined && v.vt !== null ? ({ 0: 'middle', 1: 'top', 2: null })[v.vt] : null; if (vt) al.vertical = vt;
    if (v.tb == 2) al.wrapText = true;
    if (v.tr && v.tr != 0) al.textRotation = ({ 1: 45, 2: -45, 3: 'vertical', 4: 90, 5: -90 })[v.tr];
    if (Object.keys(al).length) cell.alignment = al;
  }

  /* ---------- .xlsx → Luckysheet ---------- */
  async function fromXlsx(buf) {
    const wb = new ExcelJS.Workbook();
    await wb.xlsx.load(buf);
    const sheets = [];
    let order = 0;
    wb.eachSheet((ws) => {
      if (ws.state === 'veryHidden') return;
      const sh = blankSheet(ws.name, order++), cfg = sh.config, cd = sh.celldata;
      if (ws.state === 'hidden') sh.hide = 1;
      const tab = xColor(ws.properties && ws.properties.tabColor); if (tab) sh.color = tab;
      if (ws.views && ws.views[0] && ws.views[0].showGridLines === false) sh.showGridLines = 0;
      // stĺpce a riadky
      const maxC = Math.max(ws.columnCount || 0, 1);
      for (let c = 1; c <= Math.min(maxC, 500); c++) {
        const col = ws.getColumn(c);
        if (col.width) cfg.columnlen[c - 1] = Math.round(col.width * 7 + 5);
        if (col.hidden) cfg.colhidden[c - 1] = 0;
      }
      const merges = {};
      (ws.model.merges || []).forEach(m => { const rg = parseRange(m); if (rg) merges[rg.r1 + '_' + rg.c1] = { r: rg.r1, c: rg.c1, rs: rg.r2 - rg.r1 + 1, cs: rg.c2 - rg.c1 + 1 }; });
      cfg.merge = merges;
      const inMerge = {};
      Object.values(merges).forEach(m => { for (let r = m.r; r < m.r + m.rs; r++) for (let c = m.c; c < m.c + m.cs; c++) if (r !== m.r || c !== m.c) inMerge[r + '_' + c] = m; });
      ws.eachRow({ includeEmpty: true }, (row, rn) => {
        const r = rn - 1;
        if (row.height && Math.abs(row.height - 15) > 0.1) cfg.rowlen[r] = Math.round(row.height * 4 / 3);
        if (row.hidden) cfg.rowhidden[r] = 0;
        row.eachCell({ includeEmpty: true }, (cell, cn) => {
          const c = cn - 1, key = r + '_' + c;
          if (inMerge[key]) { const m = inMerge[key]; cd.push({ r, c, v: { mc: { r: m.r, c: m.c } } }); return; }
          const v = cellFromExcel(cell, sh, r, c);
          if (merges[key]) v.mc = { ...merges[key] };
          borderFromExcel(cell.border, cfg, r, c);
          if (Object.keys(v).length) cd.push({ r, c, v });
        });
      });
      // ukotvenie
      const fv = (ws.views || []).find(v => v.state === 'frozen');
      if (fv && (fv.xSplit || fv.ySplit)) {
        const x = fv.xSplit || 0, y = fv.ySplit || 0;
        sh.frozen = x && y ? { type: 'rangeBoth', range: { row_focus: y - 1, column_focus: x - 1 } } : y ? { type: 'rangeRow', range: { row_focus: y - 1, column_focus: 0 } } : { type: 'rangeColumn', range: { row_focus: 0, column_focus: x - 1 } };
      }
      // filter
      if (ws.autoFilter) {
        const rg = typeof ws.autoFilter === 'string' ? parseRange(ws.autoFilter) : ws.autoFilter.from ? { r1: ws.autoFilter.from.row - 1, c1: ws.autoFilter.from.column - 1, r2: ws.autoFilter.to.row - 1, c2: ws.autoFilter.to.column - 1 } : null;
        if (rg) sh.filter_select = { row: [rg.r1, rg.r2], column: [rg.c1, rg.c2] };
      }
      cfFromExcel(ws, sh);
      dvFromExcel(ws, sh);
      imagesFromExcel(wb, ws, sh);
      sheets.push(sh);
    });
    if (!sheets.length) sheets.push(blankSheet('Hárok1', 0));
    return sheets;
  }
  function cellFromExcel(cell, sh, r, c) {
    const o = {};
    styleFromExcel(cell, o);
    let val = cell.value;
    const fmt = fixFmt(cell.numFmt);
    if (val && typeof val === 'object' && val.hyperlink !== undefined) {
      const h = String(val.hyperlink || '');
      sh.hyperlink[r + '_' + c] = h[0] === '#' ? { linkType: 'internal', linkAddress: h.slice(1), linkTooltip: val.tooltip || '' } : { linkType: 'external', linkAddress: h, linkTooltip: val.tooltip || '' };
      val = val.text && val.text.richText ? val.text.richText.map(t => t.text).join('') : val.text;
      if (!o.fc) o.fc = 'rgb(0, 0, 255)'; if (!o.un) o.un = 1;
    }
    const formula = cell.formula || (val && typeof val === 'object' && (val.formula || val.sharedFormula) ? cell.formula : null);
    if (formula) {
      o.f = '=' + formula;
      let res = val && typeof val === 'object' ? val.result : undefined;
      if (res && typeof res === 'object' && res.error) res = res.error;
      if (res instanceof Date) { o.v = dateToSerial(res); o.ct = { fa: isDateFmt(fmt) ? fmt : DATE_FMT, t: 'd' }; }
      else { if (res !== undefined) o.v = res; o.ct = { fa: fmt, t: isDateFmt(fmt) ? 'd' : 'n' }; }
    } else if (val === null || val === undefined) {
      if (fmt !== 'General') o.ct = { fa: fmt, t: isDateFmt(fmt) ? 'd' : 'n' };
    } else if (val instanceof Date) {
      o.v = dateToSerial(val); o.ct = { fa: isDateFmt(fmt) ? fmt : DATE_FMT, t: 'd' };
    } else if (typeof val === 'number') {
      o.v = val; o.ct = { fa: fmt, t: isDateFmt(fmt) ? 'd' : 'n' };
    } else if (typeof val === 'boolean') {
      o.v = val ? 'TRUE' : 'FALSE'; o.ct = { fa: 'General', t: 'b' };
    } else if (typeof val === 'object' && val.richText) {
      o.ct = { fa: 'General', t: 'inlineStr', s: val.richText.map(t => { const p = { v: t.text }; if (t.font) { const q = {}; styleFromExcel({ font: t.font }, q); ['bl', 'it', 'un', 'cl', 'fc', 'fs', 'ff'].forEach(k => { if (q[k] !== undefined) p[k] = q[k]; }); } if (p.fs === undefined && o.fs) p.fs = o.fs; return p; }) };
    } else if (typeof val === 'object' && val.error) {
      o.v = val.error; o.ct = { fa: 'General', t: 'e' };
    } else {
      o.v = String(val); o.ct = { fa: fmt === '@' ? '@' : 'General', t: fmt === '@' ? 's' : 'g' };
    }
    const note = cell.note;
    if (note) { const t = typeof note === 'string' ? note : (note.texts || []).map(x => x.text).join(''); if (t) o.ps = { left: null, top: null, width: null, height: null, value: t, isshow: false }; }
    return o;
  }
  function borderFromExcel(b, cfg, r, c) {
    if (!b) return;
    const side = (x) => x && x.style && BORDER.indexOf(x.style) > 0 ? { style: BORDER.indexOf(x.style), color: xColor(x.color) || '#000000' } : null;
    const v = { row_index: r, col_index: c }; let any = false;
    [['l', 'left'], ['r', 'right'], ['t', 'top'], ['b', 'bottom']].forEach(([k, n]) => { const s = side(b[n]); if (s) { v[k] = s; any = true; } });
    if (any) cfg.borderInfo.push({ rangeType: 'cell', value: v });
  }
  function dxf(style) {
    const out = { textColor: null, cellColor: null };
    if (!style) return out;
    const fc = style.font && xColor(style.font.color); if (fc) out.textColor = fc;
    const f = style.fill; if (f) { const bg = xColor(f.bgColor) || xColor(f.fgColor); if (bg) out.cellColor = bg; }
    return out;
  }
  function cfFromExcel(ws, sh) {
    const list = [];
    (ws.conditionalFormattings || []).forEach(cf => {
      const ranges = String(cf.ref || '').split(/\s+/).map(parseRange).filter(Boolean).map(g => ({ row: [g.r1, g.r2], column: [g.c1, g.c2] }));
      if (!ranges.length) return;
      (cf.rules || []).forEach(rule => {
        const base = { cellrange: ranges };
        const fm = (rule.formulae || []).map(x => String(x).replace(/^"(.*)"$/, '$1'));
        if (rule.type === 'cellIs') {
          const name = { greaterThan: 'greaterThan', lessThan: 'lessThan', between: 'betweenness', equal: 'equal' }[rule.operator];
          if (name) list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: name, conditionRange: [], conditionValue: name === 'betweenness' ? fm.slice(0, 2) : fm.slice(0, 1) });
        } else if (rule.type === 'containsText' && rule.text !== undefined) {
          list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: 'textContains', conditionRange: [], conditionValue: [rule.text] });
        } else if (rule.type === 'top10') {
          const name = (rule.bottom ? 'last10' : 'top10') + (rule.percent ? '%' : '');
          list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: name, conditionRange: [], conditionValue: [String(rule.rank || 10)] });
        } else if (rule.type === 'aboveAverage') {
          const below = rule.aboveAverage === false;
          list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: below ? 'SubAverage' : 'AboveAverage', conditionRange: [], conditionValue: [below ? 'SubAverage' : 'AboveAverage'] });
        } else if (rule.type === 'duplicateValues' || rule.type === 'uniqueValues') {
          list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: 'duplicateValue', conditionRange: [], conditionValue: [rule.type === 'uniqueValues' ? '1' : '0'] });
        } else if (rule.type === 'expression' && /^COUNTIF\(/i.test(fm[0] || '') && />1$/.test(fm[0])) {
          list.push({ ...base, type: 'default', format: dxf(rule.style), conditionName: 'duplicateValue', conditionRange: [], conditionValue: ['0'] });
        } else if (rule.type === 'colorScale' && rule.color && rule.color.length >= 2) {
          list.push({ ...base, type: 'colorGradation', format: rule.color.map(x => cssRgb(xColor(x) || '#ffffff')).reverse() });
        } else if (rule.type === 'dataBar') {
          const col = xColor(rule.color) || '#638ec6';
          list.push({ ...base, type: 'dataBar', format: rule.gradient === false ? [col] : [col, '#ffffff'] });
        }
      });
    });
    if (list.length) sh.luckysheet_conditionformat_save = list;
  }
  function eachAddr(key, fn) {
    String(key).split(/\s+/).forEach(part => { const g = parseRange(part); if (!g) return; for (let r = g.r1; r <= Math.min(g.r2, g.r1 + 5000); r++) for (let c = g.c1; c <= g.c2; c++) fn(r, c); });
  }
  function dvFromExcel(ws, sh) {
    const model = ws.dataValidations && ws.dataValidations.model; if (!model) return;
    Object.entries(model).forEach(([key, dv]) => {
      if (!dv) return;
      const fm = (dv.formulae || []).map(x => x instanceof Date ? (x.getUTCFullYear() + '-' + String(x.getUTCMonth() + 1).padStart(2, '0') + '-' + String(x.getUTCDate()).padStart(2, '0')) : String(x));
      let item = null;
      const common = { checked: false, remote: false, prohibitInput: !!dv.showErrorMessage, hintShow: !!dv.prompt, hintText: dv.prompt || '' };
      if (dv.type === 'list') {
        const f = fm[0] || ''; const lit = /^"(.*)"$/.exec(f);
        item = { type: 'dropdown', type2: null, value1: lit ? lit[1] : f, value2: '', ...common };
      } else if (dv.type === 'whole' || dv.type === 'decimal') {
        item = { type: dv.type === 'whole' ? 'number_integer' : 'number_decimal', type2: OPS[dv.operator || 'between'] || 'bw', value1: fm[0] || '', value2: fm[1] || '', ...common };
      } else if (dv.type === 'textLength') {
        item = { type: 'text_length', type2: OPS[dv.operator || 'between'] || 'bw', value1: fm[0] || '', value2: fm[1] || '', ...common };
      } else if (dv.type === 'date') {
        item = { type: 'date', type2: OPS[dv.operator || 'between'] || 'bw', value1: fm[0] || '', value2: fm[1] || '', ...common };
      }
      if (item) eachAddr(key, (r, c) => { sh.dataVerification[r + '_' + c] = { ...item }; });
    });
  }
  function colLeft(cfg, n, def) { let x = 0; for (let i = 0; i < n; i++) x += cfg.colhidden && cfg.colhidden[i] === 0 ? 0 : (cfg.columnlen[i] ?? def); return x; }
  function rowTop(cfg, n, def) { let y = 0; for (let i = 0; i < n; i++) y += cfg.rowhidden && cfg.rowhidden[i] === 0 ? 0 : (cfg.rowlen[i] ?? def); return y; }
  function imagesFromExcel(wb, ws, sh) {
    let imgs = [];
    try { imgs = ws.getImages() || []; } catch (e) { return; }
    imgs.forEach((im, i) => {
      try {
        const media = wb.getImage(+im.imageId); if (!media) return;
        const ext = (media.extension || 'png').toLowerCase().replace('jpg', 'jpeg');
        let b64 = media.base64;
        if (!b64 && media.buffer) { let s = ''; const u = new Uint8Array(media.buffer); for (let k = 0; k < u.length; k += 0x8000) s += String.fromCharCode.apply(null, u.subarray(k, k + 0x8000)); b64 = btoa(s); }
        if (!b64) return;
        if (!/^data:/.test(b64)) b64 = 'data:image/' + ext + ';base64,' + b64;
        const tl = im.range.tl, br = im.range.br;
        const left = colLeft(sh.config, tl.nativeCol, 73) + (tl.nativeColOff || 0) / 9525;
        const top = rowTop(sh.config, tl.nativeRow, 20) + (tl.nativeRowOff || 0) / 9525;
        let w, h;
        if (im.range.ext && im.range.ext.width) { w = im.range.ext.width; h = im.range.ext.height; }
        else if (br) { w = colLeft(sh.config, br.nativeCol, 73) + (br.nativeColOff || 0) / 9525 - left; h = rowTop(sh.config, br.nativeRow, 20) + (br.nativeRowOff || 0) / 9525 - top; }
        else return;
        sh.images['img_' + i + '_' + Math.random().toString(36).slice(2, 8)] = imageObj(b64, Math.round(left), Math.round(top), Math.round(w), Math.round(h));
      } catch (e) {}
    });
  }
  function imageObj(src, left, top, w, h) {
    return { type: '3', src, originWidth: w, originHeight: h, default: { width: w, height: h, left, top }, crop: { width: w, height: h, offsetLeft: 0, offsetTop: 0 }, isFixedPos: false, fixedLeft: 0, fixedTop: 0, border: { width: 0, radius: 0, style: 'solid', color: '#000' } };
  }

  /* ---------- .ods / .xls / .csv → Luckysheet (SheetJS) ---------- */
  function fromSheetJS(wb) {
    return wb.SheetNames.map((n, i) => {
      const ws = wb.Sheets[n], sh = blankSheet(n, i), cd = sh.celldata;
      const rng = XLSX.utils.decode_range(ws['!ref'] || 'A1');
      for (let r = rng.s.r; r <= Math.min(rng.e.r, 100000); r++) for (let c = rng.s.c; c <= rng.e.c; c++) {
        const x = ws[XLSX.utils.encode_cell({ r, c })]; if (!x) continue;
        const v = {};
        const z = fixFmt(x.z);
        if (x.f) { v.f = '=' + x.f; if (x.v !== undefined) v.v = x.v; v.ct = { fa: z, t: isDateFmt(z) ? 'd' : 'n' }; }
        else if (x.t === 'n') { v.v = x.v; v.ct = { fa: z, t: isDateFmt(z) ? 'd' : 'n' }; }
        else if (x.t === 'd') { v.v = dateToSerial(x.v); v.ct = { fa: isDateFmt(z) ? z : DATE_FMT, t: 'd' }; }
        else if (x.t === 'b') { v.v = x.v ? 'TRUE' : 'FALSE'; v.ct = { fa: 'General', t: 'b' }; }
        else if (x.t === 'e') { v.v = x.w || '#N/A'; v.ct = { fa: 'General', t: 'e' }; }
        else if (x.v !== undefined && x.v !== '') { v.v = String(x.v); v.ct = { fa: 'General', t: 'g' }; }
        else continue;
        if (x.c && x.c.length) { const t = x.c.map(k => k.t || '').join('\n').trim(); if (t) v.ps = { left: null, top: null, width: null, height: null, value: t, isshow: false }; }
        if (x.l && x.l.Target) sh.hyperlink[r + '_' + c] = { linkType: 'external', linkAddress: x.l.Target, linkTooltip: x.l.Tooltip || '' };
        cd.push({ r, c, v });
      }
      (ws['!merges'] || []).forEach(m => {
        const mm = { r: m.s.r, c: m.s.c, rs: m.e.r - m.s.r + 1, cs: m.e.c - m.s.c + 1 };
        sh.config.merge[mm.r + '_' + mm.c] = mm;
        let master = cd.find(x => x.r === mm.r && x.c === mm.c); if (!master) cd.push(master = { r: mm.r, c: mm.c, v: {} });
        master.v.mc = { ...mm };
        for (let r = mm.r; r < mm.r + mm.rs; r++) for (let c = mm.c; c < mm.c + mm.cs; c++) if (r !== mm.r || c !== mm.c) { const k = cd.findIndex(x => x.r === r && x.c === c); const cell = { r, c, v: { mc: { r: mm.r, c: mm.c } } }; if (k >= 0) cd[k] = cell; else cd.push(cell); }
      });
      (ws['!cols'] || []).forEach((c, k) => { if (!c) return; if (c.wpx) sh.config.columnlen[k] = Math.round(c.wpx); else if (c.wch) sh.config.columnlen[k] = Math.round(c.wch * 7 + 5); if (c.hidden) sh.config.colhidden[k] = 0; });
      (ws['!rows'] || []).forEach((rw, k) => { if (!rw) return; if (rw.hpx) sh.config.rowlen[k] = Math.round(rw.hpx); if (rw.hidden) sh.config.rowhidden[k] = 0; });
      return sh;
    });
  }
  /** CSV: oddeľovač (; alebo ,), čísla s desatinnou čiarkou, dátumy d.m.rrrr. */
  function fromCsv(buf) {
    let txt = new TextDecoder('utf-8').decode(buf);
    if (txt.includes('�')) txt = new TextDecoder('windows-1250').decode(buf);
    txt = txt.replace(/^﻿/, '');
    const first = txt.split(/\r?\n/)[0] || '';
    const cnt = (ch) => first.split(ch).length - 1;
    const FS = cnt(';') >= cnt(',') && cnt(';') >= cnt('\t') ? ';' : cnt('\t') > cnt(',') ? '\t' : ',';
    const wb = XLSX.read(txt, { type: 'string', raw: true, FS });
    const sh = fromSheetJS(wb).slice(0, 1)[0];
    sh.name = 'Hárok1';
    sh.celldata.forEach(x => {
      const v = x.v; if (!v || typeof v.v !== 'string') return;
      const p = FS === ',' ? null : parseLocal(v.v);
      if (p) { x.v = { ...v, ...p }; return; }
      const t = v.v.trim();
      if (/^-?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(t) && !/^0\d/.test(t)) x.v = { ...v, v: +t, ct: { fa: 'General', t: 'n' } };
      else if (/^-?\d+$/.test(t) && !/^0\d/.test(t)) x.v = { ...v, v: +t, ct: { fa: 'General', t: 'n' } };
      else { const d = parseLocal(t); if (d && d.ct.t === 'd') x.v = { ...v, ...d }; }
    });
    sh._csv = { FS, comma: FS !== ',' };
    return [sh];
  }

  function blankSheet(name, order) {
    return { name, index: 'sh' + order, order, status: order === 0 ? 1 : 0, celldata: [], row: 100, column: 26,
      config: { merge: {}, columnlen: {}, rowlen: {}, borderInfo: [], colhidden: {}, rowhidden: {} }, hyperlink: {}, dataVerification: {}, images: {}, luckysheet_conditionformat_save: [] };
  }

  /* ---------- Luckysheet → súbory ---------- */
  /** Bunky hárka ako [{r,c,v}] – z mriežky (ak bol hárok otvorený), inak z celldata. */
  function cellsOf(sh) {
    const out = [];
    if (Array.isArray(sh.data) && sh.data.length) {
      sh.data.forEach((row, r) => (row || []).forEach((v, c) => { if (v !== null && v !== undefined && v !== '') out.push({ r, c, v: typeof v === 'object' ? v : { v, ct: { fa: 'General', t: typeof v === 'number' ? 'n' : 'g' } } }); }));
    } else (sh.celldata || []).forEach(x => { if (x && x.v !== null && x.v !== undefined) out.push({ r: x.r, c: x.c, v: typeof x.v === 'object' ? x.v : { v: x.v } }); });
    return out;
  }
  const sorted = (sheets) => sheets.slice().sort((a, b) => (+a.order || 0) - (+b.order || 0));
  function cellValue(v) { // hodnota bunky pre Excel
    const ct = v.ct || {};
    if (ct.t === 'inlineStr' && Array.isArray(ct.s)) {
      return { richText: ct.s.map(p => { const cell = {}; styleToExcel(p, cell); return cell.font ? { text: String(p.v ?? ''), font: cell.font } : { text: String(p.v ?? '') }; }) };
    }
    let x = v.v;
    if (x === undefined || x === null || x === '') return null;
    if (ct.t === 'd' && (typeof x === 'number' || /^-?\d+(\.\d+)?$/.test(x))) return serialToDate(+x);
    if (ct.t === 'b' || x === true || x === false) return x === true || String(x).toUpperCase() === 'TRUE';
    if (ct.t === 'e' && /^#/.test(String(x))) return { error: String(x) };
    if (typeof x === 'number') return x;
    if (ct.t === 'n' && /^-?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(String(x).trim())) return +x;
    if (ct.t === 's' || ct.fa === '@') return String(x);
    return String(x);
  }
  async function toXlsx(sheetsIn) {
    const wb = new ExcelJS.Workbook();
    wb.creator = 'Cloud'; wb.calcProperties.fullCalcOnLoad = true;
    for (const sh of sorted(sheetsIn)) {
      const cfg = sh.config || {};
      const name = String(sh.name || 'Hárok').replace(/[\[\]:*?\/\\]/g, ' ').slice(0, 31) || 'Hárok';
      const views = [{ showGridLines: sh.showGridLines !== 0 && sh.showGridLines !== '0' }];
      const fz = sh.frozen; let xs = 0, ys = 0;
      if (fz && fz.type) {
        const rf = fz.range || {};
        if (fz.type === 'row') ys = 1; else if (fz.type === 'column') xs = 1; else if (fz.type === 'both') { xs = 1; ys = 1; }
        else if (fz.type === 'rangeRow') ys = (rf.row_focus ?? 0) + 1; else if (fz.type === 'rangeColumn') xs = (rf.column_focus ?? 0) + 1;
        else if (fz.type === 'rangeBoth') { ys = (rf.row_focus ?? 0) + 1; xs = (rf.column_focus ?? 0) + 1; }
      }
      if (xs || ys) Object.assign(views[0], { state: 'frozen', xSplit: xs, ySplit: ys });
      const ws = wb.addWorksheet(name, { views, properties: sh.color ? { tabColor: argb(sh.color) || undefined } : {} });
      if (sh.hide == 1) ws.state = 'hidden';
      const au = cfg.authority; // ochrana hárka – heslo platí aj v Exceli
      if (au && au.sheet) try { await ws.protect(au.password || '', { selectLockedCells: au.selectLockedCells !== 0, selectUnlockedCells: au.selectunLockedCells !== 0, formatCells: !!au.formatCells, formatColumns: !!au.formatColumns, formatRows: !!au.formatRows, insertColumns: !!au.insertColumns, insertRows: !!au.insertRows, insertHyperlinks: !!au.insertHyperlinks, deleteColumns: !!au.deleteColumns, deleteRows: !!au.deleteRows, sort: !!au.sort, autoFilter: !!au.filter, pivotTables: !!au.usePivotTablereports, objects: !!au.editObjects, scenarios: !!au.editScenarios }); } catch (e) {}
      Object.entries(cfg.columnlen || {}).forEach(([c, px]) => { if (+px > 0) ws.getColumn(+c + 1).width = Math.max(1, Math.round((px - 5) / 7 * 100) / 100); });
      Object.keys(cfg.colhidden || {}).forEach(c => { ws.getColumn(+c + 1).hidden = true; });
      Object.entries(cfg.rowlen || {}).forEach(([r, px]) => { if (+px > 0) ws.getRow(+r + 1).height = Math.round(px * 0.75 * 100) / 100; });
      Object.keys(cfg.rowhidden || {}).forEach(r => { ws.getRow(+r + 1).hidden = true; });
      const links = sh.hyperlink || {}, inner = new Set();
      Object.values(cfg.merge || {}).forEach(m => { for (let r = m.r; r < m.r + m.rs && r < m.r + 5000; r++) for (let c = m.c; c < m.c + m.cs; c++) if (r !== m.r || c !== m.c) inner.add(r + '_' + c); });
      cellsOf(sh).forEach(({ r, c, v }) => {
        if ((v.mc && v.mc.rs === undefined) || inner.has(r + '_' + c)) return; // vnútro zlúčenej bunky
        const cell = ws.getCell(r + 1, c + 1);
        let val = cellValue(v);
        const fmt = outFmt(v.ct && v.ct.fa);
        if (v.f) {
          let res = val; if (res && typeof res === 'object' && !(res instanceof Date) && !res.error) res = undefined;
          cell.value = { formula: fixFormula(String(v.f).replace(/^=/, '')), result: res === null ? undefined : res };
        } else if (links[r + '_' + c]) {
          const l = links[r + '_' + c];
          cell.value = { text: val === null ? '' : String(v.m ?? val), hyperlink: l.linkType === 'internal' ? '#' + l.linkAddress : l.linkAddress, tooltip: l.linkTooltip || undefined };
        } else if (val !== null) cell.value = val;
        if (fmt) cell.numFmt = fmt;
        else if (val instanceof Date && !v.f) cell.numFmt = outFmt(DATE_FMT);
        styleToExcel(v, cell);
        if (v.ps && v.ps.value) cell.note = String(v.ps.value);
      });
      Object.values(cfg.merge || {}).forEach(m => { if (m.rs > 1 || m.cs > 1) try { ws.mergeCells(m.r + 1, m.c + 1, m.r + m.rs, m.c + m.cs); } catch (e) {} });
      bordersToExcel(cfg.borderInfo || [], ws);
      if (sh.filter_select && sh.filter_select.row) ws.autoFilter = { from: { row: sh.filter_select.row[0] + 1, column: sh.filter_select.column[0] + 1 }, to: { row: sh.filter_select.row[1] + 1, column: sh.filter_select.column[1] + 1 } };
      cfToExcel(sh.luckysheet_conditionformat_save || [], ws);
      dvToExcel(sh.dataVerification || {}, ws);
      imagesToExcel(sh, wb, ws);
    }
    if (!wb.worksheets.length) wb.addWorksheet('Hárok1');
    return new Blob([await wb.xlsx.writeBuffer()], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
  }
  function bordersToExcel(list, ws) {
    const side = (s) => s && s.style ? { style: BORDER[+s.style] || 'thin', color: argb(s.color) || { argb: 'FF000000' } } : undefined;
    const set = (r, c, k, s) => { if (!s) return; const cell = ws.getCell(r + 1, c + 1); const b = { ...(cell.border || {}) }; b[k] = s; cell.border = b; };
    list.forEach(bi => {
      if (bi.rangeType === 'cell' && bi.value) {
        const v = bi.value; [['l', 'left'], ['r', 'right'], ['t', 'top'], ['b', 'bottom']].forEach(([k, n]) => set(v.row_index, v.col_index, n, side(v[k])));
      } else if (bi.rangeType === 'range') {
        const s = side({ style: bi.style, color: bi.color }), t = bi.borderType;
        (bi.range || []).forEach(rg => {
          const [r1, r2] = rg.row, [c1, c2] = rg.column;
          if (r2 - r1 > 5000 || c2 - c1 > 500) return;
          for (let r = r1; r <= r2; r++) for (let c = c1; c <= c2; c++) {
            if (t === 'border-none') { const cell = ws.getCell(r + 1, c + 1); cell.border = {}; continue; }
            const all = t === 'border-all', out = t === 'border-outside', inn = t === 'border-inside';
            if (all || (out && c === c1) || t === 'border-left' && c === c1 || (inn || t === 'border-vertical') && c > c1) set(r, c, 'left', s);
            if (all || (out && c === c2) || t === 'border-right' && c === c2 || (inn || t === 'border-vertical') && c < c2) set(r, c, 'right', s);
            if (all || (out && r === r1) || t === 'border-top' && r === r1 || (inn || t === 'border-horizontal') && r > r1) set(r, c, 'top', s);
            if (all || (out && r === r2) || t === 'border-bottom' && r === r2 || (inn || t === 'border-horizontal') && r < r2) set(r, c, 'bottom', s);
          }
        });
      }
    });
  }
  function dxfOut(f) {
    const st = {}; if (!f) return st;
    const tc = argb(f.textColor); if (tc) st.font = { color: tc };
    const cc = argb(f.cellColor); if (cc) st.fill = { type: 'pattern', pattern: 'solid', bgColor: cc };
    return st;
  }
  function cfToExcel(list, ws) {
    let prio = 1;
    list.forEach(cf => {
      const ref = (cf.cellrange || []).map(rangeRef).join(' '); if (!ref) return;
      const first = cf.cellrange[0], tl = addr(first.row[0], first.column[0]);
      const val = (x) => { const s = String(x ?? ''); return /^-?\d+(\.\d+)?$/.test(s) ? s : /^\$?[A-Z]+\$?\d+$/i.test(s) ? s : '"' + s.replace(/"/g, '""') + '"'; };
      let rule = null;
      if (cf.type === 'default') {
        const st = dxfOut(cf.format), cv = cf.conditionValue || [];
        switch (cf.conditionName) {
          case 'greaterThan': rule = { type: 'cellIs', operator: 'greaterThan', formulae: [val(cv[0])], style: st }; break;
          case 'lessThan': rule = { type: 'cellIs', operator: 'lessThan', formulae: [val(cv[0])], style: st }; break;
          case 'equal': rule = { type: 'cellIs', operator: 'equal', formulae: [val(cv[0])], style: st }; break;
          case 'betweenness': rule = { type: 'cellIs', operator: 'between', formulae: [val(cv[0]), val(cv[1])], style: st }; break;
          case 'textContains': rule = { type: 'containsText', operator: 'containsText', text: String(cv[0] ?? ''), style: st }; break;
          case 'duplicateValue': { const abs = (cf.cellrange || []).map(g => '$' + colName(g.column[0]) + '$' + (g.row[0] + 1) + ':$' + colName(g.column[1]) + '$' + (g.row[1] + 1))[0]; rule = { type: 'expression', formulae: ['COUNTIF(' + abs + ',' + tl + ')' + (cv[0] == 1 ? '=1' : '>1')], style: st }; break; }
          case 'top10': case 'top10%': case 'last10': case 'last10%':
            rule = { type: 'top10', rank: parseInt(cv[0], 10) || 10, percent: /%$/.test(cf.conditionName), bottom: /^last/.test(cf.conditionName), style: st }; break;
          case 'AboveAverage': rule = { type: 'aboveAverage', aboveAverage: true, style: st }; break;
          case 'SubAverage': rule = { type: 'aboveAverage', aboveAverage: false, style: st }; break;
        }
      } else if (cf.type === 'colorGradation' && Array.isArray(cf.format)) {
        const cols = cf.format.map(x => argb(x)).filter(Boolean).reverse();
        rule = { type: 'colorScale', cfvo: cols.length === 3 ? [{ type: 'min' }, { type: 'percentile', value: 50 }, { type: 'max' }] : [{ type: 'min' }, { type: 'max' }], color: cols };
      } else if (cf.type === 'dataBar' && Array.isArray(cf.format)) {
        rule = { type: 'dataBar', minLength: 0, maxLength: 100, gradient: cf.format.length > 1, cfvo: [{ type: 'min' }, { type: 'max' }], color: argb(cf.format[0]) || { argb: 'FF638EC6' } };
      }
      if (rule) { rule.priority = prio++; try { ws.addConditionalFormatting({ ref, rules: [rule] }); } catch (e) {} }
    });
  }
  function dvToExcel(dvs, ws) {
    Object.entries(dvs).forEach(([k, d]) => {
      if (!d || !d.type) return;
      const [r, c] = k.split('_').map(Number), a = addr(r, c);
      const base = { allowBlank: true, showErrorMessage: !!d.prohibitInput, showInputMessage: !!d.hintShow, prompt: d.hintShow ? d.hintText || '' : undefined };
      const two = (t) => ({ ...base, type: t, operator: OPS_R[d.type2] || 'between', formulae: OPS_R[d.type2] === 'between' || OPS_R[d.type2] === 'notBetween' || !OPS_R[d.type2] ? [d.value1, d.value2] : [d.value1] });
      let dv = null;
      if (d.type === 'dropdown') dv = /!|^\$?[A-Z]+\$?\d+:/i.test(d.value1) ? { ...base, type: 'list', formulae: [d.value1] } : { ...base, type: 'list', formulae: ['"' + String(d.value1 || '').replace(/"/g, '') + '"'] };
      else if (d.type === 'number_integer') dv = two('whole');
      else if (d.type === 'number_decimal' || d.type === 'number') dv = two('decimal');
      else if (d.type === 'text_length') dv = two('textLength');
      else if (d.type === 'date') { dv = two('date'); dv.formulae = dv.formulae.map(x => { const m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(String(x)); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : x; }); }
      if (dv) try { ws.dataValidations.add(a, dv); } catch (e) {}
    });
  }
  function imagesToExcel(sh, wb, ws) {
    const cfg = sh.config || {};
    Object.values(sh.images || {}).forEach(im => {
      try {
        const m = /^data:image\/(png|jpe?g|gif);base64,(.*)$/i.exec(im.src || ''); if (!m) return;
        const d = im.default || {}, left = +d.left || 0, top = +d.top || 0;
        let col = 0, x = 0; while (col < 2000) { const w = cfg.colhidden && cfg.colhidden[col] === 0 ? 0 : (cfg.columnlen && cfg.columnlen[col]) || 73; if (x + w > left) break; x += w; col++; }
        const cw = (cfg.columnlen && cfg.columnlen[col]) || 73;
        let row = 0, y = 0; while (row < 100000) { const h = cfg.rowhidden && cfg.rowhidden[row] === 0 ? 0 : (cfg.rowlen && cfg.rowlen[row]) || 19; if (y + h > top) break; y += h; row++; }
        const rh = (cfg.rowlen && cfg.rowlen[row]) || 19;
        const id = wb.addImage({ base64: m[2], extension: m[1].toLowerCase() === 'jpg' ? 'jpeg' : m[1].toLowerCase() });
        ws.addImage(id, { tl: { col: col + (left - x) / cw, row: row + (top - y) / rh }, ext: { width: Math.round(+d.width || 100), height: Math.round(+d.height || 100) }, editAs: 'oneCell' });
      } catch (e) {}
    });
  }
  /** Hodnota bunky do CSV/ODS. */
  function plain(v, comma) {
    const val = cellValue(v);
    if (val === null || val === undefined) return '';
    if (val instanceof Date) return v.m || '';
    if (typeof val === 'number') return comma ? String(val).replace('.', ',') : String(val);
    if (typeof val === 'boolean') return val ? 'TRUE' : 'FALSE';
    if (val.richText) return val.richText.map(t => t.text).join('');
    if (val.error) return val.error;
    return String(val);
  }
  function toCsv(sheetsIn, opt) {
    const sh = sorted(sheetsIn)[0], FS = (opt && opt.FS) || ';', comma = opt ? opt.comma !== false : true;
    const rows = [];
    cellsOf(sh).forEach(({ r, c, v }) => { (rows[r] ||= [])[c] = plain(v, comma); });
    const q = (s) => /[";\n\r,\t]/.test(s) && (s.includes(FS) || /["\n\r]/.test(s)) ? '"' + s.replace(/"/g, '""') + '"' : s;
    const txt = Array.from(rows, (row) => Array.from(row || [], x => q(x ?? '')).join(FS)).join('\r\n');
    return new Blob(['﻿' + txt + '\r\n'], { type: 'text/csv;charset=utf-8' });
  }
  function toOds(sheetsIn) {
    const wb = XLSX.utils.book_new();
    sorted(sheetsIn).forEach(sh => {
      const ws = {}; let maxR = 0, maxC = 0;
      cellsOf(sh).forEach(({ r, c, v }) => {
        if (v.mc && v.mc.rs === undefined) return;
        const val = cellValue(v), fa = v.ct && v.ct.fa && v.ct.fa !== 'General' ? v.ct.fa : undefined;
        let x = null;
        if (v.f) x = { t: typeof val === 'number' ? 'n' : 's', v: val ?? '', f: fixFormula(String(v.f).replace(/^=/, '')), z: fa };
        else if (val === null) return;
        else if (val instanceof Date) x = { t: 'n', v: dateToSerial(val), z: fa || 'd.m.yyyy' };
        else if (typeof val === 'number') x = { t: 'n', v: val, z: fa };
        else if (typeof val === 'boolean') x = { t: 'b', v: val };
        else x = { t: 's', v: plain(v, false) };
        if (x.z) x.z = String(x.z).replace(/\\\./g, '.');
        ws[XLSX.utils.encode_cell({ r, c })] = x; maxR = Math.max(maxR, r); maxC = Math.max(maxC, c);
      });
      ws['!ref'] = XLSX.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: maxR, c: maxC } });
      ws['!merges'] = Object.values((sh.config || {}).merge || {}).map(m => ({ s: { r: m.r, c: m.c }, e: { r: m.r + m.rs - 1, c: m.c + m.cs - 1 } }));
      const cols = []; Object.entries((sh.config || {}).columnlen || {}).forEach(([c, px]) => { cols[+c] = { wpx: +px }; }); ws['!cols'] = cols;
      XLSX.utils.book_append_sheet(wb, ws, String(sh.name || 'Hárok').slice(0, 31));
    });
    return new Blob([XLSX.write(wb, { bookType: 'ods', type: 'array' })], { type: 'application/vnd.oasis.opendocument.spreadsheet' });
  }

  /** Čo editor neudrží v súbore (cudzie grafy, kontingenčné tabuľky, makrá). */
  function unsupported(buf, ext) {
    const warn = [];
    if (ext === 'xlsm') warn.push('makrá');
    if (ext !== 'xlsx' && ext !== 'xlsm') return warn;
    try {
      const z = XLSX.read(buf, { type: 'array', bookFiles: true });
      const files = Object.keys(z.files || {}).join('\n') + '\n' + (z.keys || []).join('\n');
      if (/xl\/charts\//.test(files)) warn.push('grafy z Excelu');
      if (/xl\/pivotTables\//.test(files)) warn.push('kontingenčné tabuľky');
      if (/vbaProject\.bin/.test(files)) warn.push('makrá');
    } catch (e) {}
    return [...new Set(warn)];
  }
  async function blankXlsx() { return toXlsx([blankSheet('Hárok1', 0)]); }

  window.CloudSheetIO = { fromXlsx, fromSheetJS, fromCsv, toXlsx, toCsv, toOds, unsupported, blankSheet, blankXlsx, parseLocal, fixFormula, fixFmt, isDateFmt, cellsOf, colName, addr, parseRange, xColor, argb, DATE_FMT };
})();
