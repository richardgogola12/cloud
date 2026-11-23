/* Tlač tabuľky ako v Google Tabuľkách: nastavenia vpravo (čo tlačiť, papier, orientácia, mierka, okraje, mriežka,
   poznámky, poradie strán, zarovnanie, hlavičky a päty, opakovanie ukotvených riadkov), vľavo živá ukážka strán.
   Strany sa skladajú v prehliadači do samostatného rámu a tlačia (alebo uložia ako PDF) cez okno tlače prehliadača. */
(function () {
  const S = window.CloudSheetEditor, C = window.CLOUD_SHEET; if (!S || !C) return;
  const UI = () => window.CloudSheetUI;
  const esc = (x) => String(x ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const MM = 96 / 25.4;
  const PAPER = { A4: [210, 297], Letter: [215.9, 279.4], A3: [297, 420], A5: [148, 210], Legal: [215.9, 355.6], Tabloid: [279.4, 431.8] };
  const MARG = { normal: [19.05, 17.78], narrow: [6.35, 6.35], wide: [25.4, 25.4] };
  const FONTS = ['Times New Roman', 'Arial', 'Tahoma', 'Verdana', 'Microsoft Yahei', 'SimSun', 'SimHei', 'KaiTi', 'FangSong', 'NSimSun', 'STXinwei', 'STXingkai', 'STLiti', 'HanaMinA', 'Helvetica', 'Courier New', 'Georgia', 'Calibri'];
  const BSTYLE = { 1: '1px solid', 2: '1px solid', 3: '1px dotted', 4: '1px dashed', 5: '1px dashed', 6: '1px dashed', 7: '3px double', 8: '2px solid', 9: '2px dashed', 10: '2px dashed', 11: '2px dashed', 12: '2px dashed', 13: '3px solid' };
  const store = { get(k, d) { try { const v = localStorage.getItem('cloud.sheet.print.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }, set(k, v) { try { localStorage.setItem('cloud.sheet.print.' + k, JSON.stringify(v)); } catch (e) {} } };
  const skNum = (m) => String(m).replace(/[\d,.]+/g, s => /\d/.test(s) ? s.replace(/,/g, ' ').replace(/\./g, ',') : s);
  const colName = (c) => { let s = '', n = c + 1; while (n > 0) { const k = (n - 1) % 26; s = String.fromCharCode(65 + k) + s; n = Math.floor((n - 1) / 26); } return s; };

  /** okraje buniek z config.borderInfo → mapa "r_c" → {t,b,l,r} */
  function borders(cfg) {
    const map = new Map(), get = (r, c) => { const k = r + '_' + c; if (!map.has(k)) map.set(k, {}); return map.get(k); };
    const css = (st, col) => (BSTYLE[st] || '1px solid') + ' ' + (col || '#000');
    (cfg.borderInfo || []).forEach(b => {
      if (b.rangeType === 'cell' && b.value) { const v = b.value, x = get(v.row_index, v.col_index); ['l', 'r', 't', 'b'].forEach(s => { if (v[s] && v[s].style) x[s] = css(v[s].style, v[s].color); }); return; }
      const st = css(b.style, b.color), t = b.borderType;
      (b.range || []).forEach(rg => {
        const [r0, r1] = rg.row, [c0, c1] = rg.column;
        for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
          const x = get(r, c);
          if (t === 'border-none') { delete x.t; delete x.b; delete x.l; delete x.r; continue; }
          if (t === 'border-all') { x.t = x.b = x.l = x.r = st; continue; }
          if (t === 'border-outside') { if (r === r0) x.t = st; if (r === r1) x.b = st; if (c === c0) x.l = st; if (c === c1) x.r = st; }
          if (t === 'border-inside') { if (r < r1) x.b = st; if (c < c1) x.r = st; }
          if (t === 'border-horizontal') { if (r < r1) x.b = st; }
          if (t === 'border-vertical') { if (c < c1) x.r = st; }
          if (t === 'border-top' && r === r0) x.t = st; if (t === 'border-bottom' && r === r1) x.b = st; if (t === 'border-left' && c === c0) x.l = st; if (t === 'border-right' && c === c1) x.r = st;
        }
      });
    });
    return map;
  }
  function cellText(v) {
    if (!v) return '';
    if (v.ct && v.ct.t === 'inlineStr' && Array.isArray(v.ct.s)) return v.ct.s.map(s => { let t = esc(s.v || '').replace(/\r?\n/g, '<br>'); const st = (s.bl == 1 ? 'font-weight:700;' : '') + (s.it == 1 ? 'font-style:italic;' : '') + (s.fc ? 'color:' + s.fc + ';' : '') + (s.fs ? 'font-size:' + s.fs + 'pt;' : '') + (s.un == 1 ? 'text-decoration:underline;' : '') + (s.cl == 1 ? 'text-decoration:line-through;' : ''); return st ? '<span style="' + st + '">' + t + '</span>' : t; }).join('');
    let m = v.m ?? (v.v ?? '');
    const num = v.ct && v.ct.t === 'n' || (typeof v.v === 'number' && !(v.ct && /[dsg]/.test(v.ct.t || '') && v.ct.t !== 'g'));
    if (num && v.m !== undefined && v.m !== v._sk && /^[-\d,.\s%€$£E+]+$/.test(String(m))) m = skNum(m);
    if (v.ct && v.ct.t === 'b') m = v.v ? 'TRUE' : 'FALSE';
    return esc(m).replace(/\r?\n/g, '<br>');
  }
  /** Údaje hárka na tlač: oblasť, šírky, výšky, bunky. */
  function sheetModel(sh, area, o) {
    const d = sh.data || (luckysheet.transToData ? luckysheet.transToData(sh.celldata || []) : []);
    const cfg = sh.config || {};
    let r0 = 0, c0 = 0, r1 = -1, c1 = -1;
    if (area) { [r0, r1] = area.row; [c0, c1] = area.column; }
    else {
      d.forEach((row, r) => (row || []).forEach((x, c) => { if (x && ((x.v !== null && x.v !== undefined && x.v !== '') || x.bg || x.f || (x.ct && x.ct.s))) { if (r > r1) r1 = r; if (c > c1) c1 = c; } }));
      Object.values(cfg.merge || {}).forEach(m => { r1 = Math.max(r1, m.r + m.rs - 1); c1 = Math.max(c1, m.c + m.cs - 1); });
      (cfg.borderInfo || []).forEach(b => { if (b.rangeType === 'cell' && b.value) { r1 = Math.max(r1, b.value.row_index); c1 = Math.max(c1, b.value.col_index); return; } (b.range || []).forEach(rg => { if (rg && rg.row && rg.column && rg.row[1] - rg.row[0] < 2000 && rg.column[1] - rg.column[0] < 60) { r1 = Math.max(r1, rg.row[1]); c1 = Math.max(c1, rg.column[1]); } }); });
      Object.values(sh.images || {}).forEach(im => { const df = im.default || {}; let x = 0, c = 0; while (x < (df.left || 0) + (df.width || 0) && c < 300) { x += (cfg.columnlen && cfg.columnlen[c]) || sh.defaultColWidth || 73; c++; } let y = 0, r = 0; while (y < (df.top || 0) + (df.height || 0) && r < 5000) { y += (cfg.rowlen && cfg.rowlen[r]) || sh.defaultRowHeight || 19; r++; } r1 = Math.max(r1, r - 1); c1 = Math.max(c1, c - 1); });
    }
    if (r1 < r0 || c1 < c0) return null;
    const dw = sh.defaultColWidth || 73, dh = sh.defaultRowHeight || 19;
    const hidR = cfg.rowhidden || {}, hidC = cfg.colhidden || {};
    const cols = [], rows = [];
    for (let c = c0; c <= c1; c++) if (hidC[c] === undefined) cols.push({ i: c, w: (cfg.columnlen && cfg.columnlen[c]) ?? dw });
    for (let r = r0; r <= r1; r++) if (hidR[r] === undefined) rows.push({ i: r, h: (cfg.rowlen && cfg.rowlen[r]) ?? dh });
    const fz = sh.frozen || {}, rf = fz.range || {};
    const frozenRows = /row|Both/i.test(fz.type || '') ? (fz.type === 'row' || fz.type === 'both' ? 1 : (rf.row_focus ?? -1) + 1) : 0;
    const frozenCols = /column|Both/i.test(fz.type || '') ? (fz.type === 'column' || fz.type === 'both' ? 1 : (rf.column_focus ?? -1) + 1) : 0;
    const merges = {}; Object.values(cfg.merge || {}).forEach(m => { merges[m.r + '_' + m.c] = m; });
    return { sh, d, cfg, cols, rows, bmap: borders(cfg), merges, frozenRows: area ? 0 : frozenRows, frozenCols: area ? 0 : frozenCols, origin: { r: r0, c: c0 }, dw, dh };
  }
  function cellStyle(v, b, grid) {
    let s = '';
    if (v) {
      if (v.bg) s += 'background:' + v.bg + ';';
      if (v.fc) s += 'color:' + v.fc + ';';
      if (v.bl == 1) s += 'font-weight:700;'; if (v.it == 1) s += 'font-style:italic;';
      const deco = (v.un == 1 ? 'underline ' : '') + (v.cl == 1 ? 'line-through' : ''); if (deco) s += 'text-decoration:' + deco.trim() + ';';
      if (v.fs) s += 'font-size:' + v.fs + 'pt;';
      if (v.ff !== undefined && v.ff !== null && v.ff !== '') s += 'font-family:\'' + (typeof v.ff === 'number' || /^\d+$/.test(v.ff) ? FONTS[+v.ff] || 'Arial' : String(v.ff).replace(/'/g, '')) + '\',Arial,sans-serif;';
      const num = v.ct && (v.ct.t === 'n' || v.ct.t === 'd') || typeof v.v === 'number';
      const ht = v.ht ?? (num ? 2 : null); if (ht == 0) s += 'text-align:center;'; else if (ht == 2) s += 'text-align:right;';
      const vt = v.vt; s += 'vertical-align:' + (vt == 0 ? 'middle' : vt == 1 ? 'top' : 'bottom') + ';';
      if (v.tb == 2 || v.tb === '2') s += 'white-space:pre-wrap;word-break:break-word;'; else if (v.tb == 0 || v.tb === '0') s += 'overflow:hidden;';
      if (v.tr == 3 || v.tr === '3') s += 'writing-mode:vertical-rl;text-orientation:upright;';
    } else s += 'vertical-align:bottom;';
    if (b) { if (b.t) s += 'border-top:' + b.t + ';'; if (b.b) s += 'border-bottom:' + b.b + ';'; if (b.l) s += 'border-left:' + b.l + ';'; if (b.r) s += 'border-right:' + b.r + ';'; }
    void grid;
    return s;
  }
  /** Tabuľka pre jednu stranu: vybrané riadky a stĺpce (indexy do model.rows/cols). */
  function tableHtml(M, rIdx, cIdx, o) {
    const rowSet = new Set(rIdx), colSet = new Set(cIdx);
    const rowsOut = rIdx.map(i => M.rows[i]), colsOut = cIdx.map(i => M.cols[i]);
    const rPos = new Map(rowsOut.map((r, k) => [r.i, k])), cPos = new Map(colsOut.map((c, k) => [c.i, k]));
    const covered = new Set();
    let h = '<table class="sp-t' + (o.grid ? ' grid' : '') + '" style="width:' + colsOut.reduce((a, c) => a + c.w, 0) + 'px"><colgroup>' + colsOut.map(c => '<col style="width:' + c.w + 'px">').join('') + '</colgroup><tbody>';
    rowsOut.forEach(row => {
      h += '<tr style="height:' + row.h + 'px">';
      colsOut.forEach(col => {
        const key = row.i + '_' + col.i; if (covered.has(key)) return;
        const v = M.d[row.i] && M.d[row.i][col.i];
        let span = '';
        const m = M.merges[key];
        if (m) {
          let rs = 0, cs = 0; for (let r = m.r; r < m.r + m.rs; r++) if (rPos.has(r)) rs++; for (let c = m.c; c < m.c + m.cs; c++) if (cPos.has(c)) cs++;
          for (let r = m.r; r < m.r + m.rs; r++) for (let c = m.c; c < m.c + m.cs; c++) if (r !== m.r || c !== m.c) covered.add(r + '_' + c);
          if (rs > 1) span += ' rowspan="' + rs + '"'; if (cs > 1) span += ' colspan="' + cs + '"';
        } else if (v && v.mc && v.mc.r !== undefined && (v.mc.r !== row.i || v.mc.c !== col.i)) {
          // vnútro zlúčenej bunky, ktorej začiatok je na inej strane
          const top = M.merges[v.mc.r + '_' + v.mc.c];
          if (top && (!rPos.has(top.r) || !cPos.has(top.c))) {
            let rs = 0, cs = 0; for (let r = row.i; r < top.r + top.rs; r++) if (rPos.has(r)) rs++; for (let c = col.i; c < top.c + top.cs; c++) if (cPos.has(c)) cs++;
            for (let r = row.i; r < top.r + top.rs; r++) for (let c = col.i; c < top.c + top.cs; c++) if (r !== row.i || c !== col.i) covered.add(r + '_' + c);
            if (rs > 1) span += ' rowspan="' + rs + '"'; if (cs > 1) span += ' colspan="' + cs + '"';
            const tv = M.d[top.r] && M.d[top.r][top.c];
            h += '<td' + span + ' style="' + cellStyle(tv, M.bmap.get(key), o.grid) + '"></td>'; return;
          }
          return;
        }
        h += '<td' + span + ' style="' + cellStyle(v, M.bmap.get(key), o.grid) + '">' + cellText(v) + (o.notes && v && v.ps && v.ps.value ? '<sup class="sp-n">' + (M.noteNo[key]) + '</sup>' : '') + '</td>';
      });
      h += '</tr>';
    });
    h += '</tbody></table>';
    void rowSet; void colSet;
    return h;
  }
  /** obrázky a grafy nad bunkami (iba ak je ich ľavý horný roh na strane) */
  function overlays(M, rIdx, cIdx, sheetIsActive) {
    const out = [];
    const x0 = (ci) => { let x = 0; for (let c = 0; c < ci; c++) x += (M.cfg.colhidden || {})[c] !== undefined ? 0 : ((M.cfg.columnlen && M.cfg.columnlen[c]) ?? M.dw); return x; };
    const y0 = (ri) => { let y = 0; for (let r = 0; r < ri; r++) y += (M.cfg.rowhidden || {})[r] !== undefined ? 0 : ((M.cfg.rowlen && M.cfg.rowlen[r]) ?? M.dh); return y; };
    const rows = rIdx.map(i => M.rows[i]), cols = cIdx.map(i => M.cols[i]);
    if (!rows.length || !cols.length) return '';
    const left = x0(cols[0].i), top = y0(rows[0].i);
    const right = left + cols.reduce((a, c) => a + c.w, 0), bottom = top + rows.reduce((a, r) => a + r.h, 0);
    // pri opakovaných riadkoch/stĺpcoch posun
    const items = [];
    Object.values(M.sh.images || {}).forEach(im => { const df = im.default || {}; if (im.src) items.push({ src: im.src, l: df.left || 0, t: df.top || 0, w: df.width || im.originWidth || 100, h: df.height || im.originHeight || 100 }); });
    if (sheetIsActive) document.querySelectorAll('#luckysheet-cell-main .luckysheet-modal-dialog-chart, #luckysheet-cell-main [id^="luckysheet_chart"]').forEach(el => {
      const cv = el.querySelector('canvas'); if (!cv) return;
      const z = (M.sh.zoomRatio || 1);
      try { items.push({ src: cv.toDataURL('image/png'), l: (parseFloat(el.style.left) || 0) / z, t: (parseFloat(el.style.top) || 0) / z, w: (parseFloat(el.style.width) || cv.width) / z, h: (parseFloat(el.style.height) || cv.height) / z }); } catch (e) {}
    });
    items.forEach(it => { if (it.l >= left - 1 && it.l < right && it.t >= top - 1 && it.t < bottom) out.push('<img class="sp-img" src="' + it.src + '" style="left:' + (it.l - left) + 'px;top:' + (it.t - top) + 'px;width:' + it.w + 'px;height:' + it.h + 'px">'); });
    return out.join('');
  }
  /** Rozdelenie na strany. */
  function paginate(M, o, availW, availH) {
    const totalW = M.cols.reduce((a, c) => a + c.w, 0), totalH = M.rows.reduce((a, r) => a + r.h, 0);
    let sc = 1;
    if (o.scale === 'width') sc = Math.min(1, availW / totalW);
    else if (o.scale === 'height') sc = Math.min(1, availH / totalH);
    else if (o.scale === 'page') sc = Math.min(1, availW / totalW, availH / totalH);
    else if (o.scale === 'custom') sc = Math.max(0.1, Math.min(4, (+o.custom || 100) / 100));
    const W = availW / sc, H = availH / sc;
    const repR = o.repeatRows ? Math.min(M.frozenRows, M.rows.findIndex(r => r.i >= M.frozenRows) < 0 ? M.rows.length : M.rows.findIndex(r => r.i >= M.frozenRows)) : 0;
    const repC = o.repeatCols ? Math.min(M.frozenCols, M.cols.findIndex(c => c.i >= M.frozenCols) < 0 ? M.cols.length : M.cols.findIndex(c => c.i >= M.frozenCols)) : 0;
    const chunk = (arr, size, rep, key) => {
      const out = []; let cur = [], s = 0; const repSize = arr.slice(0, rep).reduce((a, x) => a + x[key], 0);
      arr.forEach((x, i) => {
        if (cur.length && s + x[key] > size + 0.5) { out.push(cur); cur = []; s = 0; if (rep && i >= rep) { for (let k = 0; k < rep; k++) cur.push(k); s = repSize; } }
        cur.push(i); s += x[key];
      });
      if (cur.length) out.push(cur);
      return out;
    };
    const rch = chunk(M.rows, H, repR, 'h'), cch = chunk(M.cols, W, repC, 'w');
    const pages = [];
    if (o.order === 'over') rch.forEach(r => cch.forEach(c => pages.push({ r, c })));
    else cch.forEach(c => rch.forEach(r => pages.push({ r, c })));
    return { pages, sc };
  }
  function build(o) {
    const P = PAPER[o.paper] || PAPER.A4, land = o.orient === 'land';
    const pw = (land ? P[1] : P[0]) * MM, ph = (land ? P[0] : P[1]) * MM;
    const [mv, mh] = MARG[o.margins] || MARG.normal;
    const hasHF = o.pnum || o.wbname || o.shname || o.date || o.time;
    const mt = mv * MM, mb = mv * MM, ml = mh * MM, mr = mh * MM;
    const availW = pw - ml - mr, availH = ph - mt - mb;
    try { luckysheet.exitEditMode(); } catch (e) {}
    try { luckysheet.getluckysheetfile(true); } catch (e) {}
    const all = luckysheet.getAllSheets();
    const cur = luckysheet.getSheet();
    let list = o.what === 'book' ? all.filter(s => s.hide != 1).sort((a, b) => a.order - b.order) : [all.find(s => s.index === cur.index) || all[0]];
    const area = o.what === 'sel' ? UI().range() : null;
    const pages = [];
    list.forEach(sh => {
      const M = sheetModel(sh, area, o); if (!M) return;
      M.noteNo = {}; let nn = 0; const notes = [];
      if (o.notes) M.rows.forEach(r => M.cols.forEach(c => { const v = M.d[r.i] && M.d[r.i][c.i]; if (v && v.ps && v.ps.value) { M.noteNo[r.i + '_' + c.i] = ++nn; notes.push({ n: nn, a: colName(c.i) + (r.i + 1), t: v.ps.value }); } }));
      const { pages: pp, sc } = paginate(M, o, availW, availH);
      pp.forEach((pg, k) => pages.push({ sh, M, pg, sc, notes: k === pp.length - 1 ? notes : [] }));
    });
    if (!pages.length) return { html: '', count: 0, pw, ph };
    const now = new Date(), dt = now.toLocaleDateString('sk-SK'), tm = now.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
    const book = (S.saveAs || S.name).replace(/\.[^.]+$/, '');
    const hal = { left: 'flex-start', center: 'center', right: 'flex-end' }[o.halign] || 'flex-start', val = { top: 'flex-start', middle: 'center', bottom: 'flex-end' }[o.valign] || 'flex-start';
    const body = pages.map((p, i) => {
      const t = tableHtml(p.M, p.pg.r, p.pg.c, o), ov = overlays(p.M, p.pg.r, p.pg.c, p.sh.index === cur.index);
      const head = [o.wbname ? esc(book) : '', o.shname ? esc(p.sh.name) : ''].filter(Boolean).join(' · ');
      const foot = [o.date ? dt : '', o.time ? tm : ''].filter(Boolean).join(' ');
      return '<section class="sp-p" style="width:' + pw + 'px;height:' + ph + 'px">' +
        (head ? '<div class="sp-h" style="top:' + (mt / 2 - 8) + 'px;left:' + ml + 'px;right:' + mr + 'px">' + head + '</div>' : '') +
        '<div class="sp-a" style="left:' + ml + 'px;top:' + mt + 'px;width:' + availW + 'px;height:' + availH + 'px;justify-content:' + hal + ';align-items:' + val + '"><div class="sp-z" style="zoom:' + p.sc + '"><div class="sp-rel">' + t + ov + '</div>' +
        (p.notes.length ? '<div class="sp-notes"><b>Poznámky</b>' + p.notes.map(n => '<div><sup>' + n.n + '</sup> ' + n.a + ': ' + esc(n.t) + '</div>').join('') + '</div>' : '') + '</div></div>' +
        ((o.pnum || foot) ? '<div class="sp-f" style="bottom:' + (mb / 2 - 8) + 'px;left:' + ml + 'px;right:' + mr + 'px"><span>' + foot + '</span><span>' + (o.pnum ? (i + 1) + ' / ' + pages.length : '') + '</span></div>' : '') +
        '</section>';
    }).join('');
    void hasHF;
    const css = '*{box-sizing:border-box}html,body{margin:0;padding:0}body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
      '.sp-p{position:relative;background:#fff;overflow:hidden;break-after:page;page-break-after:always}.sp-p:last-child{break-after:auto;page-break-after:auto}' +
      '.sp-a{position:absolute;display:flex;overflow:hidden}.sp-z{display:inline-block}.sp-rel{position:relative;display:inline-block}' +
      '.sp-t{border-collapse:collapse;table-layout:fixed}.sp-t td{padding:0 3px;overflow:visible;white-space:nowrap;line-height:1.2;font-size:11pt;vertical-align:bottom}' +
      '.sp-t.grid td{border:1px solid #c0c0c0}.sp-img{position:absolute}.sp-n{color:#c5221f;font-size:7pt}' +
      '.sp-h,.sp-f{position:absolute;font-size:9pt;color:#444;display:flex;justify-content:space-between;gap:12px}.sp-h{justify-content:center}' +
      '.sp-notes{margin-top:12px;font-size:9pt;border-top:1px solid #ccc;padding-top:6px}.sp-notes b{display:block;margin-bottom:2px}' +
      '@page{size:' + (land ? P[1] : P[0]) + 'mm ' + (land ? P[0] : P[1]) + 'mm;margin:0}' +
      '@media screen{body{background:#e8eaed;padding:16px 0}.sp-p{margin:0 auto 16px;box-shadow:0 1px 3px rgba(0,0,0,.3)}}';
    return { html: '<!doctype html><html lang="sk"><head><meta charset="utf-8"><title>' + esc(book) + '</title><style>' + css + '</style></head><body>' + body + '</body></html>', count: pages.length, pw, ph };
  }

  /* ---------- okno s nastaveniami ---------- */
  const DEF = { what: 'sheet', paper: 'A4', orient: 'port', scale: 'width', custom: 100, margins: 'normal', grid: false, notes: false, order: 'down', halign: 'left', valign: 'top', pnum: true, wbname: false, shname: false, date: false, time: false, repeatRows: true, repeatCols: true };
  let modal = null;
  function open(mode) {
    if (!S.ready) return;
    const o = Object.assign({}, DEF, store.get('opts', {}));
    const rg = UI().range();
    o.what = (rg.row[1] > rg.row[0] || rg.column[1] > rg.column[0]) && store.get('sel', false) ? 'sel' : o.what === 'sel' ? 'sheet' : o.what;
    const opt = (n, v, t) => '<option value="' + v + '"' + (o[n] === v ? ' selected' : '') + '>' + t + '</option>';
    const chk = (n, t) => '<label class="form-check"><input type="checkbox" class="form-check-input" data-o="' + n + '"' + (o[n] ? ' checked' : '') + '> <span class="form-check-label">' + t + '</span></label>';
    const el = document.createElement('div'); el.className = 'modal fade sp-modal'; el.tabIndex = -1;
    el.innerHTML = '<div class="modal-dialog modal-fullscreen"><div class="modal-content"><div class="sp-wrap"><div class="sp-prev"><div class="sp-info" id="spInfo"></div><iframe id="spFrame" title="Ukážka tlače"></iframe></div>' +
      '<aside class="sp-side"><div class="d-flex align-items-center mb-3"><h5 class="m-0 me-auto"><i class="bi bi-printer me-2"></i>Nastavenia tlače</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>' +
      '<label class="form-label">Tlačiť</label><select class="form-select form-select-sm mb-3" data-o="what">' + opt('what', 'sheet', 'Aktuálny hárok') + opt('what', 'book', 'Celý zošit (všetky hárky)') + opt('what', 'sel', 'Vybrané bunky (' + colName(rg.column[0]) + (rg.row[0] + 1) + ':' + colName(rg.column[1]) + (rg.row[1] + 1) + ')') + '</select>' +
      '<label class="form-label">Veľkosť papiera</label><select class="form-select form-select-sm mb-3" data-o="paper">' + Object.keys(PAPER).map(k => opt('paper', k, k + ' (' + String(PAPER[k][0] / 10).replace('.', ',') + ' × ' + String(PAPER[k][1] / 10).replace('.', ',') + ' cm)')).join('') + '</select>' +
      '<label class="form-label">Orientácia strany</label><div class="btn-group w-100 mb-3" role="group"><input type="radio" class="btn-check" name="spO" id="spOl" value="land"' + (o.orient === 'land' ? ' checked' : '') + '><label class="btn btn-sm btn-outline-primary" for="spOl"><i class="bi bi-phone-landscape"></i> Na šírku</label><input type="radio" class="btn-check" name="spO" id="spOp" value="port"' + (o.orient !== 'land' ? ' checked' : '') + '><label class="btn btn-sm btn-outline-primary" for="spOp"><i class="bi bi-phone"></i> Na výšku</label></div>' +
      '<label class="form-label">Mierka</label><select class="form-select form-select-sm mb-1" data-o="scale">' + opt('scale', 'normal', 'Normálna (100 %)') + opt('scale', 'width', 'Prispôsobiť šírke') + opt('scale', 'height', 'Prispôsobiť výške') + opt('scale', 'page', 'Prispôsobiť strane') + opt('scale', 'custom', 'Vlastné číslo') + '</select>' +
      '<div class="input-group input-group-sm mb-3' + (o.scale === 'custom' ? '' : ' d-none') + '" id="spCust"><input type="number" class="form-control" min="10" max="400" data-o="custom" value="' + o.custom + '"><span class="input-group-text">%</span></div>' +
      '<label class="form-label">Okraje</label><select class="form-select form-select-sm mb-3" data-o="margins">' + opt('margins', 'normal', 'Normálne') + opt('margins', 'narrow', 'Úzke') + opt('margins', 'wide', 'Široké') + '</select>' +
      '<div class="sp-sec">Formátovanie</div>' + chk('grid', 'Zobraziť mriežku') + chk('notes', 'Zobraziť poznámky') +
      '<label class="form-label mt-2">Poradie strán</label><select class="form-select form-select-sm mb-2" data-o="order">' + opt('order', 'down', 'Zhora nadol, potom doprava') + opt('order', 'over', 'Zľava doprava, potom nadol') + '</select>' +
      '<div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Vodorovne</label><select class="form-select form-select-sm" data-o="halign">' + opt('halign', 'left', 'Vľavo') + opt('halign', 'center', 'Na stred') + opt('halign', 'right', 'Vpravo') + '</select></div><div class="col-6"><label class="form-label">Zvisle</label><select class="form-select form-select-sm" data-o="valign">' + opt('valign', 'top', 'Hore') + opt('valign', 'middle', 'Na stred') + opt('valign', 'bottom', 'Dole') + '</select></div></div>' +
      '<div class="sp-sec">Hlavičky a päty</div>' + chk('pnum', 'Čísla strán') + chk('wbname', 'Názov zošita') + chk('shname', 'Názov hárka') + chk('date', 'Aktuálny dátum') + chk('time', 'Aktuálny čas') +
      '<div class="sp-sec">Ukotvené riadky a stĺpce</div>' + chk('repeatRows', 'Opakovať ukotvené riadky na každej strane') + chk('repeatCols', 'Opakovať ukotvené stĺpce na každej strane') +
      '<div class="sp-btns"><button class="btn btn-outline-secondary rounded-pill px-3" data-sp="html"><i class="bi bi-filetype-html"></i> Stiahnuť HTML</button><button class="btn btn-primary rounded-pill px-4" data-sp="print"><i class="bi bi-printer"></i> ' + (mode === 'pdf' ? 'Uložiť ako PDF' : 'Ďalej') + '</button></div>' +
      (mode === 'pdf' ? '<div class="small text-body-secondary mt-2">V okne tlače vyber ako tlačiareň „Uložiť ako PDF“.</div>' : '') +
      '</aside></div></div></div>';
    document.body.appendChild(el);
    const read = () => { el.querySelectorAll('[data-o]').forEach(i => { o[i.dataset.o] = i.type === 'checkbox' ? i.checked : i.value; }); o.orient = el.querySelector('[name=spO]:checked').value; el.querySelector('#spCust').classList.toggle('d-none', o.scale !== 'custom'); };
    let html = '';
    const draw = () => {
      read();
      const b = build(o); html = b.html;
      el.querySelector('#spInfo').textContent = b.count ? 'Strán: ' + b.count : 'Nie je čo tlačiť – hárok je prázdny';
      const f = el.querySelector('#spFrame');
      f.srcdoc = html || '<!doctype html><body style="font-family:sans-serif;color:#666;padding:40px;text-align:center">Prázdne</body>';
      f.onload = () => { try { const d = f.contentDocument, pr = el.querySelector('.sp-prev'); const z = Math.min(1, (pr.clientWidth - 40) / (b.pw + 10)); d.body.style.zoom = z; } catch (e) {} };
    };
    let t = null; el.addEventListener('input', () => { clearTimeout(t); t = setTimeout(draw, 150); }); el.addEventListener('change', () => { clearTimeout(t); t = setTimeout(draw, 50); });
    el.querySelector('[data-sp="print"]').onclick = () => { read(); store.set('opts', o); store.set('sel', o.what === 'sel'); printHtml(build(o).html); };
    el.querySelector('[data-sp="html"]').onclick = () => { read(); const b = build(o); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([b.html], { type: 'text/html;charset=utf-8' })); a.download = (S.saveAs || S.name).replace(/\.[^.]+$/, '') + '.html'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000); };
    modal = new bootstrap.Modal(el);
    el.addEventListener('shown.bs.modal', draw);
    el.addEventListener('hidden.bs.modal', () => { el.remove(); modal = null; });
    modal.show();
  }
  function printHtml(html) {
    if (!html) return UI().toast('Nie je čo tlačiť', 'err');
    let f = document.getElementById('spPrintFrame'); if (f) f.remove();
    f = document.createElement('iframe'); f.id = 'spPrintFrame'; f.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0'; f.title = 'tlač';
    document.body.appendChild(f);
    f.onload = () => { setTimeout(() => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { UI().toast('Tlač sa nepodarilo spustiť', 'err'); } }, 250); };
    f.srcdoc = html;
  }
  window.CloudSheetPrint = {
    open: () => open(), build,
    download: (t) => {
      if (t === 'pdf') return open('pdf');
      const o = Object.assign({}, DEF, store.get('opts', {}), { what: 'book' }); const b = build(o);
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([b.html], { type: 'text/html;charset=utf-8' })); a.download = (S.saveAs || S.name).replace(/\.[^.]+$/, '') + '.html'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 4000);
    },
  };
})();
