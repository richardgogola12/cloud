/* Prevod dokumentov Word (.docx) ↔ HTML pre editor dokumentov (doc.php).
   Načítanie: vlastný prevod word/document.xml – štýly, písmo, farby, zarovnanie, odsadenie, riadkovanie, zoznamy
   (aj typ odrážok a kontrolné zoznamy), tabuľky (zlúčené bunky, okraje, šírky), obrázky (aj obtekanie), odkazy,
   záložky, obsah, hlavičky a päty s číslami strán, poznámky pod čiarou, komentáre, sledované zmeny, nastavenie strany.
   Uloženie: knižnica docx (zo servera, nič sa neposiela inam). */
(function () {
  const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
  const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
  const WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const kids = (el, name) => el ? [...el.childNodes].filter(n => n.nodeType === 1 && (!name || n.localName === name)) : [];
  const kid = (el, name) => kids(el, name)[0] || null;
  const att = (el, name) => el ? (el.getAttributeNS(W, name) ?? el.getAttribute('w:' + name)) : null;
  const rid = (el, name) => el ? (el.getAttributeNS(R, name) || el.getAttribute('r:' + name)) : null;
  const HL = { yellow: '#ffff00', green: '#00ff00', cyan: '#00ffff', magenta: '#ff00ff', blue: '#0000ff', red: '#ff0000', darkBlue: '#000080', darkCyan: '#008080', darkGreen: '#008000', darkMagenta: '#800080', darkRed: '#800000', darkYellow: '#808000', darkGray: '#808080', lightGray: '#c0c0c0', black: '#000000' };
  const OLFMT = { decimal: 'decimal', lowerLetter: 'lower-alpha', upperLetter: 'upper-alpha', lowerRoman: 'lower-roman', upperRoman: 'upper-roman', decimalZero: 'decimal-leading-zero' };
  const bulletKind = (t) => /[○o◦]/.test(t) ? 'circle' : /[■▪§]/.test(t) ? 'square' : /[–\-−]/.test(t) ? 'dash' : /[➢Ø►▶➤>]/.test(t) ? 'arrow' : /[★☆]/.test(t) ? 'star' : 'disc';
  const twCm = (v) => Math.round(+v / 567 * 100) / 100;

  /* ---------- .docx → HTML ---------- */
  async function fromDocx(buf) {
    const zip = await JSZip.loadAsync(buf);
    const read = async (p) => { const f = zip.file(p); return f ? new DOMParser().parseFromString(await f.async('string'), 'application/xml') : null; };
    const doc = await read('word/document.xml');
    if (!doc) throw new Error('Súbor nie je dokument Word (.docx).');
    const relsOf = async (part) => {
      const out = {}, p = part.replace(/([^/]+)$/, '_rels/$1.rels'), d = await read(p);
      if (d) [...d.getElementsByTagName('Relationship')].forEach(r => { out[r.getAttribute('Id')] = { target: r.getAttribute('Target'), mode: r.getAttribute('TargetMode') }; });
      return out;
    };
    // štýly odsekov podľa ID aj podľa názvu (napr. „heading 1“, „Nadpis 1“, „Title“)
    const styles = {}, stDoc = await read('word/styles.xml');
    if (stDoc) [...stDoc.getElementsByTagNameNS(W, 'style')].forEach(s => {
      const id = att(s, 'styleId'), nm = (att(kid(s, 'name'), 'val') || '').toLowerCase();
      const m = /^(heading|nadpis)\s*(\d)$/.exec(nm) || /^(heading|nadpis)(\d)$/i.exec(id || '');
      styles[id] = m ? 'h' + Math.min(+m[2], 6) : /^(title|názov)$/.test(nm) || /^title$/i.test(id) ? 'title' : /^(subtitle|podnadpis)$/.test(nm) || /^subtitle$/i.test(id) ? 'subtitle'
        : /^(quote|intense quote|citát)$/.test(nm) ? 'quote' : /^(code|html preformatted|source code)$/.test(nm) ? 'code' : /^toc ?\d$/.test(nm) || /^(toc|obsah)\d$/i.test(id || '') ? 'toc' : null;
    });
    // číslovanie: druh zoznamu a vzhľad odrážok pre každú úroveň
    const num = {}, numDoc = await read('word/numbering.xml');
    if (numDoc) {
      const abs = {};
      [...numDoc.getElementsByTagNameNS(W, 'abstractNum')].forEach(a => { const o = abs[att(a, 'abstractNumId')] = {}; kids(a, 'lvl').forEach(l => { o[att(l, 'ilvl')] = { fmt: att(kid(l, 'numFmt'), 'val') || 'bullet', text: att(kid(l, 'lvlText'), 'val') || '' }; }); });
      [...numDoc.getElementsByTagNameNS(W, 'num')].forEach(n => { num[att(n, 'numId')] = abs[att(kid(n, 'abstractNumId'), 'val')] || {}; });
    }
    const plain = (el) => kids(el, 'p').map(p => [...p.getElementsByTagNameNS(W, 't')].map(t => t.textContent).join('')).join('\n');
    const footnotes = {}, fnDoc = await read('word/footnotes.xml');
    if (fnDoc) [...fnDoc.getElementsByTagNameNS(W, 'footnote')].forEach(f => { if (!att(f, 'type') || att(f, 'type') === 'normal') footnotes[att(f, 'id')] = plain(f); });
    const comments = {}, cmDoc = await read('word/comments.xml');
    if (cmDoc) [...cmDoc.getElementsByTagNameNS(W, 'comment')].forEach(c => { comments[att(c, 'id')] = { author: att(c, 'author') || '', date: att(c, 'date') || '', text: plain(c) }; });
    const warn = new Set(), usedCm = new Set();

    function parser(part, rels) {
      const media = {};
      const image = async (id) => {
        if (media[id] !== undefined) return media[id];
        const r = rels[id]; if (!r) return (media[id] = null);
        const dir = part.replace(/[^/]+$/, '');
        const path = r.target.startsWith('/') ? r.target.slice(1) : (dir + r.target.replace(/^\.\//, '')).replace(/[^/]+\/\.\.\//g, '');
        const f = zip.file(path); if (!f) return (media[id] = null);
        const ext = path.split('.').pop().toLowerCase(), mime = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', bmp: 'image/bmp', webp: 'image/webp' }[ext];
        return (media[id] = mime ? 'data:' + mime + ';base64,' + await f.async('base64') : null);
      };
      const runCss = (rPr) => {
        if (!rPr) return { css: '', tags: [] };
        const css = [], tags = [];
        const on = (n) => { const e = kid(rPr, n); return e && !['0', 'false'].includes(att(e, 'val') || ''); };
        if (on('b')) tags.push('strong'); if (on('i')) tags.push('em');
        const u = kid(rPr, 'u'); if (u && (att(u, 'val') || 'single') !== 'none') tags.push('u');
        if (on('strike') || on('dstrike')) tags.push('s');
        const va = att(kid(rPr, 'vertAlign'), 'val'); if (va === 'superscript') tags.push('sup'); if (va === 'subscript') tags.push('sub');
        const col = att(kid(rPr, 'color'), 'val'); if (col && /^[0-9a-f]{6}$/i.test(col) && col.toLowerCase() !== '000000') css.push('color:#' + col.toLowerCase());
        const hl = att(kid(rPr, 'highlight'), 'val'); if (hl && HL[hl]) css.push('background-color:' + HL[hl]);
        const sh = att(kid(rPr, 'shd'), 'fill'); if (!hl && sh && /^[0-9a-f]{6}$/i.test(sh) && sh.toLowerCase() !== 'ffffff') css.push('background-color:#' + sh.toLowerCase());
        const sz = att(kid(rPr, 'sz'), 'val'); if (sz && +sz !== 22) css.push('font-size:' + (+sz / 2) + 'pt');
        const fn = att(kid(rPr, 'rFonts'), 'ascii') || att(kid(rPr, 'rFonts'), 'hAnsi'); if (fn && !/^(calibri|aptos)$/i.test(fn)) css.push('font-family:' + fn.replace(/[;"<>]/g, ''));
        if (on('caps')) css.push('text-transform:uppercase'); if (on('smallCaps')) css.push('font-variant:small-caps');
        return { css: css.join(';'), tags };
      };
      async function drawing(c) {
        const blip = c.getElementsByTagNameNS(A, 'blip')[0];
        const id = blip ? (blip.getAttributeNS(R, 'embed') || blip.getAttribute('r:embed')) : null;
        const ext = c.getElementsByTagNameNS(WP, 'extent')[0];
        const src = id ? await image(id) : null;
        if (!src) { if (c.getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/chart', 'chart').length) warn.add('grafy'); if (c.getElementsByTagNameNS('http://schemas.microsoft.com/office/word/2010/wordprocessingShape', 'txbx').length || c.getElementsByTagName('w:txbxContent').length) warn.add('textové polia'); return ''; }
        const w = ext ? Math.round(+ext.getAttribute('cx') / 9525) : null, hh = ext ? Math.round(+ext.getAttribute('cy') / 9525) : null;
        const pr = c.getElementsByTagNameNS(WP, 'docPr')[0], descr = pr ? (pr.getAttribute('descr') || '') : '';
        let cls = '';
        const anc = c.getElementsByTagNameNS(WP, 'anchor')[0];
        if (anc) {
          const al = (anc.getElementsByTagNameNS(WP, 'align')[0] || {}).textContent || '';
          if (anc.getElementsByTagNameNS(WP, 'wrapTopAndBottom').length) cls = 'wb' + (al === 'center' ? ' c' : al === 'right' ? ' r' : '');
          else if (anc.getElementsByTagNameNS(WP, 'wrapSquare').length || anc.getElementsByTagNameNS(WP, 'wrapTight').length || anc.getElementsByTagNameNS(WP, 'wrapThrough').length) cls = al === 'right' ? 'wr' : 'wl';
        }
        let extra = '';
        const m = /^cloud-(chart|draw):(.*)$/s.exec(descr);
        if (m) extra = ' data-' + m[1] + '="' + esc(m[2]) + '"';
        return '<img src="' + src + '"' + (w ? ' width="' + w + '" height="' + hh + '"' : '') + (cls ? ' class="' + cls + '"' : '') + ' alt="' + (m ? '' : esc(descr)) + '"' + extra + '>';
      }
      // vnorené značky v odseku; st = stav (otvorené komentáre, polia)
      async function runs(p, st) {
        let h = '';
        const wrapCm = (t) => { for (const id of st.cm) t = '<span class="gd-cm" data-c="c' + id + '">' + t + '</span>'; return t; };
        for (const n of kids(p)) {
          const ln = n.localName;
          if (ln === 'r') {
            const { css, tags } = runCss(kid(n, 'rPr'));
            let t = '';
            for (const c of kids(n)) {
              const cl = c.localName;
              if (cl === 'fldChar') {
                const ty = att(c, 'fldCharType');
                if (ty === 'begin') { st.fld = 'instr'; st.instr = ''; }
                else if (ty === 'separate') { const m = /^\s*(PAGE|NUMPAGES|SECTIONPAGES)\b/.exec(st.instr); if (m) { t += '<span class="gd-fld" contenteditable="false" data-f="' + (m[1] === 'PAGE' ? 'PAGE' : 'NUMPAGES') + '">#</span>'; st.fld = 'skip'; } else st.fld = 'result'; }
                else if (ty === 'end') { if (st.fld === 'instr') { const m = /^\s*(PAGE|NUMPAGES)\b/.exec(st.instr); if (m) t += '<span class="gd-fld" contenteditable="false" data-f="' + m[1] + '">#</span>'; } st.fld = null; }
                continue;
              }
              if (cl === 'instrText') { st.instr += c.textContent; continue; }
              if (st.fld === 'instr' || st.fld === 'skip') continue;
              if (cl === 't') t += esc(c.textContent);
              else if (cl === 'delText') t += esc(c.textContent);
              else if (cl === 'tab') t += '&emsp;';
              else if (cl === 'noBreakHyphen') t += '‑';
              else if (cl === 'sym') { const ch = att(c, 'char'); t += ch ? '&#x' + (parseInt(ch, 16) >= 0xF000 ? (parseInt(ch, 16) - 0xF000).toString(16) : ch) + ';' : ''; }
              else if (cl === 'br') { const ty = att(c, 'type'); t += ty === 'page' ? '\u0000PB\u0000' : ty === 'column' ? '\u0000CB\u0000' : '<br>'; }
              else if (cl === 'drawing' || cl === 'pict') t += await drawing(c);
              else if (cl === 'footnoteReference') { const tx = footnotes[att(c, 'id')]; if (tx !== undefined) t += '<sup class="gd-fn" contenteditable="false" data-fn="' + esc(tx) + '"></sup>'; }
              else if (cl === 'endnoteReference') warn.add('vysvetlivky');
              else if (cl === 'object') warn.add('vložené objekty');
            }
            if (!t) continue;
            const isFn = /^<sup class="gd-fn"/.test(t);
            if (!isFn) tags.forEach(tg => { t = '<' + tg + '>' + t + '</' + tg + '>'; });
            if (css && !isFn) t = '<span style="' + css + '">' + t + '</span>';
            h += wrapCm(t);
          } else if (ln === 'hyperlink') {
            const id = rid(n, 'id');
            const href = id && rels[id] ? rels[id].target : (att(n, 'anchor') ? '#' + att(n, 'anchor') : '');
            h += '<a href="' + esc(href) + '">' + await runs(n, st) + '</a>';
          } else if (ln === 'ins') h += '<ins class="gd-ins" data-a="' + esc(att(n, 'author') || '') + '" data-t="' + esc(att(n, 'date') || '') + '">' + await runs(n, st) + '</ins>';
          else if (ln === 'del') h += '<del class="gd-del" data-a="' + esc(att(n, 'author') || '') + '" data-t="' + esc(att(n, 'date') || '') + '">' + await runs(n, st) + '</del>';
          else if (ln === 'fldSimple') {
            const m = /^\s*(PAGE|NUMPAGES|SECTIONPAGES)\b/.exec(att(n, 'instr') || '');
            h += m ? '<span class="gd-fld" contenteditable="false" data-f="' + (m[1] === 'PAGE' ? 'PAGE' : 'NUMPAGES') + '">#</span>' : await runs(n, st);
          } else if (ln === 'bookmarkStart') {
            const nm = att(n, 'name') || ''; if (nm && nm !== '_GoBack') h += '<a class="' + (nm[0] === '_' || /^h\./.test(nm) ? 'gd-anc' : 'gd-bm') + '" id="' + esc(nm) + '"></a>';
          } else if (ln === 'commentRangeStart') { const id = att(n, 'id'); if (comments[id]) { st.cm.push(id); usedCm.add(id); } }
          else if (ln === 'commentRangeEnd') { const id = att(n, 'id'); st.cm = st.cm.filter(x => x !== id); }
          else if (['smartTag', 'sdtContent', 'customXml'].includes(ln)) h += await runs(n, st);
          else if (ln === 'sdt') h += await runs(kid(n, 'sdtContent'), st);
          else if (ln === 'oMath' || ln === 'oMathPara') warn.add('rovnice');
        }
        return h;
      }
      const st = { cm: [], fld: null, instr: '' };
      async function block(body) {
        let h = ''; const stack = []; // otvorené zoznamy [{tag, lvl, key}]
        const closeTo = (lvl) => { while (stack.length && stack[stack.length - 1].lvl >= lvl) h += '</li></' + stack.pop().tag + '>'; };
        for (const n of kids(body)) {
          if (n.localName === 'p') {
            const pPr = kid(n, 'pPr');
            const sty = styles[att(kid(pPr, 'pStyle'), 'val')] || null;
            const numPr = kid(pPr, 'numPr');
            const align = { center: 'center', right: 'right', end: 'right', both: 'justify', distribute: 'justify' }[att(kid(pPr, 'jc'), 'val')];
            const ind = kid(pPr, 'ind'), sp = kid(pPr, 'spacing');
            const left = ind ? +(att(ind, 'left') || att(ind, 'start') || 0) : 0, right = ind ? +(att(ind, 'right') || att(ind, 'end') || 0) : 0;
            const first = ind ? +(att(ind, 'firstLine') || 0) - +(att(ind, 'hanging') || 0) : 0;
            let css = (align ? 'text-align:' + align + ';' : '') + (!numPr && left > 0 ? 'margin-left:' + Math.round(left / 15) + 'px;' : '') + (right > 0 ? 'margin-right:' + Math.round(right / 15) + 'px;' : '') + (!numPr && first ? 'text-indent:' + Math.round(first / 15) + 'px;' : '');
            if (sp) {
              const line = att(sp, 'line'), rule = att(sp, 'lineRule');
              if (line && (!rule || rule === 'auto')) css += 'line-height:' + Math.round(+line / 240 * 100) / 100 + ';';
              if (att(sp, 'before') !== null) css += 'margin-top:' + (+att(sp, 'before') / 20) + 'pt;';
              if (att(sp, 'after') !== null) css += 'margin-bottom:' + (+att(sp, 'after') / 20) + 'pt;';
            }
            let inner = await runs(n, st);
            const bdr = kid(kid(pPr, 'pBdr'), 'bottom');
            if (kid(pPr, 'pageBreakBefore')) { closeTo(0); h += '<hr class="page-break">'; }
            if (bdr && !inner.replace(/<[^>]+>/g, '').trim() && !/<img/.test(inner)) { closeTo(0); h += '<hr class="gd-hr">'; continue; }
            // zlom strany / stĺpca v strede odseku → odsek sa rozdelí
            const parts = inner.split(/\u0000(PB|CB)\u0000/);
            if (parts.length > 1) {
              closeTo(0);
              for (let i = 0; i < parts.length; i += 2) {
                if (parts[i].replace(/<[^>]+>/g, '').trim() || /<img/.test(parts[i]) || i === 0) h += '<p' + (css ? ' style="' + css + '"' : '') + '>' + (parts[i] || '<br>') + '</p>';
                if (parts[i + 1]) h += parts[i + 1] === 'PB' ? '<hr class="page-break">' : '<hr class="col-break">';
              }
              continue;
            }
            inner = inner || '<br>';
            if (numPr) {
              const id = att(kid(numPr, 'numId'), 'val'), lvl = +(att(kid(numPr, 'ilvl'), 'val') || 0);
              if (id && id !== '0') {
                const L = (num[id] || {})[lvl] || { fmt: 'bullet', text: '●' };
                const tag = L.fmt === 'bullet' || L.fmt === 'none' ? 'ul' : 'ol';
                const ls = tag === 'ol' ? (OLFMT[L.fmt] || 'decimal') : bulletKind(L.text);
                const la = tag === 'ol' ? (ls !== 'decimal' ? ' style="list-style-type:' + ls + '"' : '') : (ls !== 'disc' ? ' class="ls-' + ls + '"' : '');
                const key = tag + ls;
                const top = stack[stack.length - 1];
                const liCss = css.replace(/text-indent:[^;]+;/, '');
                if (top && top.lvl === lvl && top.key === key) h += '</li><li' + (liCss ? ' style="' + liCss + '"' : '') + '>' + inner;
                else if (top && top.lvl < lvl) { stack.push({ tag, lvl, key }); h += '<' + tag + la + '><li' + (liCss ? ' style="' + liCss + '"' : '') + '>' + inner; }
                else { closeTo(lvl); stack.push({ tag, lvl, key }); h += '<' + tag + la + '><li' + (liCss ? ' style="' + liCss + '"' : '') + '>' + inner; }
                continue;
              }
            }
            closeTo(0);
            if (sty === 'toc') { h += '<p data-tocp="' + (/\t|&emsp;/.test(inner) || kids(n).some(x => x.localName === 'r' && kid(x, 'tab')) ? 'num' : 'link') + '"></p>'; continue; }
            const tag = sty === 'title' || sty === 'subtitle' ? 'p' : sty === 'quote' ? 'blockquote' : sty === 'code' ? 'pre' : sty || 'p';
            const cls = sty === 'title' ? ' class="gd-title"' : sty === 'subtitle' ? ' class="gd-subtitle"' : '';
            h += '<' + tag + cls + (css ? ' style="' + css + '"' : '') + '>' + inner + '</' + tag + '>';
          } else if (n.localName === 'tbl') {
            closeTo(0);
            h += await table(n);
          } else if (n.localName === 'sdt') {
            closeTo(0);
            const gal = n.getElementsByTagNameNS(W, 'docPartGallery')[0];
            if (gal && /table of contents/i.test(att(gal, 'val') || '')) {
              const links = [...n.getElementsByTagNameNS(W, 'hyperlink')].length;
              h += '<div class="gd-toc" contenteditable="false" data-toc="' + (links && !/PAGEREF/.test(n.textContent) && ![...n.getElementsByTagNameNS(W, 'instrText')].some(x => /PAGEREF/.test(x.textContent)) ? 'link' : 'num') + '"></div>';
            } else h += await block(kid(n, 'sdtContent'));
          }
        }
        closeTo(0);
        return h;
      }
      async function table(n) {
        const tPr = kid(n, 'tblPr'), bd = kid(tPr, 'tblBorders');
        let tattr = '';
        if (bd) {
          const all = kids(bd), vis = all.filter(b => !['nil', 'none'].includes(att(b, 'val')));
          if (all.length && !vis.length) tattr += ' data-bw="0"';
          else if (vis.length) { const b = vis.find(x => x.localName === 'insideH') || vis[0]; const sz = +(att(b, 'sz') || 4), col = att(b, 'color'); if (sz !== 4) tattr += ' data-bw="' + Math.max(1, Math.round(sz / 8 * 1.333)) + '"'; if (col && /^[0-9a-f]{6}$/i.test(col) && col.toLowerCase() !== '000000') tattr += ' data-bc="#' + col.toLowerCase() + '"'; }
        }
        const rows = kids(n, 'tr');
        if (rows.length && kid(kid(rows[0], 'trPr'), 'tblHeader')) tattr += ' data-hdr="1"';
        // mriežka: zvislo zlúčené bunky → rowspan
        const grid = []; const out = [];
        for (let ri = 0; ri < rows.length; ri++) {
          const row = []; let gc = 0;
          for (const tc of kids(rows[ri], 'tc')) {
            const tcPr = kid(tc, 'tcPr'), span = +(att(kid(tcPr, 'gridSpan'), 'val') || 1);
            const vm = kid(tcPr, 'vMerge');
            if (vm && (att(vm, 'val') || 'continue') === 'continue') { const up = grid[gc]; if (up) up.rowspan++; gc += span; continue; }
            const fill = att(kid(tcPr, 'shd'), 'fill'), tw = kid(tcPr, 'tcW'), va = att(kid(tcPr, 'vAlign'), 'val');
            let css = '';
            if (fill && /^[0-9a-f]{6}$/i.test(fill) && fill.toLowerCase() !== 'ffffff') css += 'background-color:#' + fill.toLowerCase() + ';';
            if (tw && att(tw, 'type') === 'dxa' && +att(tw, 'w') > 0) css += 'width:' + Math.round(+att(tw, 'w') / 15) + 'px;';
            if (va === 'center' || va === 'bottom') css += 'vertical-align:' + (va === 'center' ? 'middle' : 'bottom') + ';';
            const cell = { html: await block(tc), colspan: span, rowspan: 1, css };
            for (let k = 0; k < span; k++) grid[gc + k] = cell;
            row.push(cell); gc += span;
          }
          out.push(row);
        }
        return '<table' + tattr + '><tbody>' + out.map(r => '<tr>' + r.map(c => '<td' + (c.colspan > 1 ? ' colspan="' + c.colspan + '"' : '') + (c.rowspan > 1 ? ' rowspan="' + c.rowspan + '"' : '') + (c.css ? ' style="' + c.css + '"' : '') + '>' + (c.html || '<p><br></p>') + '</td>').join('') + '</tr>').join('') + '</tbody></table>';
      }
      return { block };
    }

    const body = kid(doc.documentElement, 'body');
    const docRels = await relsOf('word/document.xml');
    let html = await parser('word/document.xml', docRels).block(body);
    // nastavenie strany, hlavičky a päty z (poslednej) sekcie
    const sect = body ? kid(body, 'sectPr') : null;
    const pg = sect ? kid(sect, 'pgSz') : null, mar = sect ? kid(sect, 'pgMar') : null;
    const page = {};
    if (pg) { const w = +att(pg, 'w'), h = +att(pg, 'h'); page.w = Math.round(Math.min(w, h) / 56.693 * 10) / 10; page.h = Math.round(Math.max(w, h) / 56.693 * 10) / 10; page.landscape = att(pg, 'orient') === 'landscape' || w > h; }
    if (mar) { page.mt = twCm(att(mar, 'top') || 1440); page.mb = twCm(att(mar, 'bottom') || 1440); page.ml = twCm(att(mar, 'left') || 1440); page.mr = twCm(att(mar, 'right') || 1440); page.hd = twCm(att(mar, 'header') || 720); page.fd = twCm(att(mar, 'footer') || 720); }
    const cols = sect ? kid(sect, 'cols') : null; if (cols && +(att(cols, 'num') || 1) > 1) page.cols = Math.min(3, +att(cols, 'num'));
    const bg = kid(doc.documentElement, 'background'); if (bg && /^[0-9a-f]{6}$/i.test(att(bg, 'color') || '') && att(bg, 'color').toLowerCase() !== 'ffffff') page.color = '#' + att(bg, 'color').toLowerCase();
    const hf = { header: {}, footer: {} };
    if (sect) {
      page.diffFirst = !!kid(sect, 'titlePg') && !['0', 'false'].includes(att(kid(sect, 'titlePg'), 'val') || '');
      for (const kind of ['header', 'footer']) for (const ref of kids(sect, kind + 'Reference')) {
        const ty = att(ref, 'type') || 'default', r = docRels[rid(ref, 'id')]; if (!r || (ty !== 'default' && ty !== 'first')) continue;
        const part = 'word/' + r.target.replace(/^\.?\//, '').replace(/^word\//, '');
        const d = await read(part); if (!d) continue;
        const h = await parser(part, await relsOf(part)).block(d.documentElement);
        if (h.replace(/<(?!img|span class="gd-fld)[^>]+>/g, '').replace(/<br>/g, '').trim()) hf[kind][ty === 'first' ? 'first' : 'def'] = h;
      }
    }
    // kontrolné zoznamy: odseky začínajúce ☐ / ☑
    const tpl = document.createElement('template'); tpl.innerHTML = html;
    const CK = /^\s*([☐☑☒])\s?/;
    tpl.content.querySelectorAll('li').forEach(li => { const m = CK.exec(li.textContent); if (!m) return; stripPrefix(li, CK); li.parentElement.classList.add('gd-check'); li.parentElement.classList.remove('ls-disc'); if (m[1] !== '☐') li.dataset.ck = '1'; });
    let group = null;
    [...tpl.content.children].forEach(el => {
      const m = el.tagName === 'P' && CK.exec(el.textContent);
      if (!m) { group = null; return; }
      if (!group) { group = document.createElement('ul'); group.className = 'gd-check'; el.before(group); }
      stripPrefix(el, CK); const li = document.createElement('li'); if (m[1] !== '☐') li.dataset.ck = '1';
      li.append(...el.childNodes); el.remove(); group.appendChild(li);
    });
    // obsah (odseky so štýlom „toc 1…“) → jeden blok, ktorý editor znova vytvorí
    [...tpl.content.querySelectorAll('p[data-tocp]')].forEach(p => { const prev = p.previousElementSibling; if (prev && prev.classList.contains('gd-toc')) { p.remove(); return; } const d = document.createElement('div'); d.className = 'gd-toc'; d.setAttribute('contenteditable', 'false'); d.dataset.toc = p.dataset.tocp; p.replaceWith(d); });
    // záložka na začiatku nadpisu = id nadpisu (odkazy a obsah)
    tpl.content.querySelectorAll('h1 a.gd-anc, h2 a.gd-anc, h3 a.gd-anc, h4 a.gd-anc, h5 a.gd-anc, h6 a.gd-anc, h1 a.gd-bm, h2 a.gd-bm, h3 a.gd-bm, h4 a.gd-bm, h5 a.gd-bm, h6 a.gd-bm').forEach(a => { const h = a.closest('h1,h2,h3,h4,h5,h6'); if (!h.id && (a.classList.contains('gd-anc') || /^h\./.test(a.id))) { h.id = a.id; a.remove(); } });
    html = tpl.innerHTML;
    if (Object.keys(comments).some(id => !usedCm.has(id))) warn.add('komentáre bez označeného textu');
    const files = Object.keys(zip.files).join('\n');
    if (/word\/charts\//.test(files)) warn.add('grafy');
    const cm = {}; usedCm.forEach(id => { const c = comments[id]; cm['c' + id] = { id: 'c' + id, author: c.author, date: c.date, text: c.text, replies: [], resolved: false }; });
    return { html: html || '<p><br></p>', warn: [...warn], page, header: hf.header, footer: hf.footer, comments: cm };
  }
  function stripPrefix(el, re) {
    const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    while (w.nextNode()) { const t = w.currentNode; if (!t.nodeValue.trim()) continue; t.nodeValue = t.nodeValue.replace(re, ''); return; }
  }

  /* ---------- HTML → .docx ---------- */
  function hex(c) {
    if (!c) return null;
    let m = /^#([0-9a-f]{3})$/i.exec(c); if (m) return m[1].split('').map(x => x + x).join('').toUpperCase();
    m = /^#([0-9a-f]{6})$/i.exec(c); if (m) return m[1].toUpperCase();
    m = /rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(c); if (m && (m[4] === undefined || +m[4] > 0)) return [m[1], m[2], m[3]].map(x => (+x).toString(16).padStart(2, '0')).join('').toUpperCase();
    return null;
  }
  function ptSize(v) { const m = /^([\d.]+)(pt|px)$/.exec(v || ''); if (!m) return null; return Math.round((m[2] === 'px' ? +m[1] * 0.75 : +m[1]) * 2); }
  const toTw = (v) => { const m = /^(-?[\d.]+)(px|pt|cm|mm|in)?$/.exec(String(v || '').trim()); if (!m) return 0; const k = { px: 15, pt: 20, cm: 567, mm: 56.7, in: 1440 }[m[2] || 'px']; return Math.round(+m[1] * k); };
  function dataToBytes(src) { const b = atob(src.split(',')[1] || ''); const u = new Uint8Array(b.length); for (let i = 0; i < b.length; i++) u[i] = b.charCodeAt(i); return u; }
  async function toPng(src) {
    return new Promise((res) => { const i = new Image(); i.onload = () => { const c = document.createElement('canvas'); c.width = i.naturalWidth; c.height = i.naturalHeight; c.getContext('2d').drawImage(i, 0, 0); res(c.toDataURL('image/png')); }; i.onerror = () => res(null); i.src = src; });
  }
  const BUL = { disc: ['●', '○', '■'], square: ['■', '■', '■'], circle: ['○', '○', '○'], dash: ['–', '–', '–'], arrow: ['➢', '➢', '➢'], star: ['★', '★', '★'] };
  const listKey = (el) => {
    if (el.tagName === 'OL') { const t = el.style.listStyleType || 'decimal'; return 'ol-' + (['lower-alpha', 'upper-alpha', 'lower-roman', 'upper-roman', 'lower-latin', 'upper-latin'].includes(t) ? t.replace('latin', 'alpha') : 'decimal'); }
    const c = [...el.classList].find(x => /^ls-/.test(x)); if (c && BUL[c.slice(3)]) return 'ul-' + c.slice(3);
    const t = el.style.listStyleType; return 'ul-' + (t === 'square' || t === 'circle' ? t : 'disc');
  };

  /** opt: { title, page, header:{def,first}, footer:{def,first}, comments:[{id,author,date,text,replies}] } */
  async function toDocx(root, opt) {
    opt = opt || {};
    const D = window.docx;
    const AL = { center: D.AlignmentType.CENTER, right: D.AlignmentType.RIGHT, justify: D.AlignmentType.JUSTIFIED, left: D.AlignmentType.LEFT, start: D.AlignmentType.LEFT, end: D.AlignmentType.RIGHT };
    const HEAD = { H1: D.HeadingLevel.HEADING_1, H2: D.HeadingLevel.HEADING_2, H3: D.HeadingLevel.HEADING_3, H4: D.HeadingLevel.HEADING_4, H5: D.HeadingLevel.HEADING_5, H6: D.HeadingLevel.HEADING_6 };
    // obrázky, ktoré Word nepozná (webp…) → png
    for (const img of root.querySelectorAll('img')) if (!/^data:image\/(png|jpe?g|gif|bmp)/i.test(img.getAttribute('src') || '')) { const p = await toPng(img.getAttribute('src')); if (p) img.setAttribute('src', p); else img.remove(); }
    let revId = 1, fnId = 0;
    const footnotes = {}, usedLists = new Set();
    // komentáre: každý komentár aj odpoveď dostane číslo; rozsah = prvý až posledný označený úsek
    const cmIds = {}, cmFirst = {}, cmLast = {}, cmList = [];
    let cn = 0;
    (opt.comments || []).forEach(c => { if (c.resolved) return; const ids = []; [c, ...(c.replies || [])].forEach(x => { ids.push(cn); cmList.push({ id: cn, author: x.author || '', date: x.date ? new Date(x.date) : new Date(), initials: (x.author || '?').split(/\s+/).map(s => s[0]).join('').slice(0, 3), children: String(x.text || '').split('\n').map(t => new D.Paragraph({ children: [new D.TextRun(t)] })) }); cn++; }); cmIds[c.id] = ids; });
    root.querySelectorAll('span.gd-cm[data-c]').forEach(s => { const id = s.dataset.c; if (!cmIds[id]) return; if (!cmFirst[id]) cmFirst[id] = s; cmLast[id] = s; });
    const runOf = (fmt, props) => {
      const o = { ...fmt, ...props }; const rev = o.rev; delete o.rev;
      if (rev && props.text !== undefined) return rev.type === 'ins' ? new D.InsertedTextRun({ ...o, id: revId++, author: rev.author || 'Cloud', date: rev.date || new Date().toISOString() }) : new D.DeletedTextRun({ ...o, id: revId++, author: rev.author || 'Cloud', date: rev.date || new Date().toISOString() });
      return new D.TextRun(o);
    };
    // vnorené formátovanie textu → TextRun
    function inline(node, fmt, out) {
      for (const n of node.childNodes) {
        if (n.nodeType === 3) {
          const t = n.nodeValue.replace(/ /g, '\t'); if (!t) continue;
          t.split('\t').forEach((p, i) => { if (i) out.push(runOf(fmt, { children: [new D.Tab()] })); if (p) out.push(runOf(fmt, { text: p.replace(/ /g, ' ') })); });
          continue;
        }
        if (n.nodeType !== 1) continue;
        const tag = n.tagName, f = { ...fmt }, st = n.style || {}, cls = n.classList;
        if (tag === 'BR') { out.push(runOf(fmt, { break: 1 })); continue; }
        if (cls.contains('gd-lb') || cls.contains('gd-hit')) { if (cls.contains('gd-hit')) inline(n, fmt, out); continue; }
        if (tag === 'SUP' && cls.contains('gd-fn')) { fnId++; footnotes[fnId] = { children: String(n.dataset.fn || '').split('\n').map(t => new D.Paragraph({ children: [new D.TextRun(t)] })) }; out.push(new D.FootnoteReferenceRun(fnId)); continue; }
        if (cls.contains('gd-fld')) { out.push(new D.TextRun({ ...fmt, rev: undefined, children: [n.dataset.f === 'NUMPAGES' ? D.PageNumber.TOTAL_PAGES : D.PageNumber.CURRENT] })); continue; }
        if (tag === 'A' && (cls.contains('gd-bm') || cls.contains('gd-anc')) && n.id) { out.push(new D.Bookmark({ id: n.id, children: [] })); continue; }
        if (tag === 'IMG') {
          const mt = /^data:image\/(png|jpe?g|gif|bmp)/i.exec(n.getAttribute('src') || ''); if (!mt) continue;
          const w = +n.getAttribute('width') || n.naturalWidth || 300, h = +n.getAttribute('height') || n.naturalHeight || 200;
          const max = opt.maxImg || 620, k = w > max ? max / w : 1;
          const o = { type: mt[1].toLowerCase() === 'jpeg' ? 'jpg' : mt[1].toLowerCase(), data: dataToBytes(n.getAttribute('src')), transformation: { width: Math.round(w * k), height: Math.round(h * k) } };
          const descr = n.dataset.chart ? 'cloud-chart:' + n.dataset.chart : n.dataset.draw ? 'cloud-draw:' + n.dataset.draw : (n.getAttribute('alt') || '');
          if (descr) o.altText = { name: 'Obrázok', title: n.getAttribute('alt') || '', description: descr };
          if (cls.contains('wl') || cls.contains('wr')) o.floating = { horizontalPosition: { relative: D.HorizontalPositionRelativeFrom.COLUMN, align: cls.contains('wr') ? D.HorizontalPositionAlign.RIGHT : D.HorizontalPositionAlign.LEFT }, verticalPosition: { relative: D.VerticalPositionRelativeFrom.PARAGRAPH, offset: 0 }, wrap: { type: D.TextWrappingType.SQUARE, side: D.TextWrappingSide.BOTH_SIDES }, margins: { left: 114300, right: 114300, top: 0, bottom: 0 } };
          out.push(new D.ImageRun(o));
          continue;
        }
        if (cls.contains('gd-cm') && n.dataset.c && cmIds[n.dataset.c]) {
          const id = n.dataset.c;
          if (cmFirst[id] === n) cmIds[id].forEach(x => out.push(new D.CommentRangeStart(x)));
          inline(n, f, out);
          if (cmLast[id] === n) cmIds[id].forEach(x => { out.push(new D.CommentRangeEnd(x)); out.push(new D.TextRun({ children: [new D.CommentReference(x)] })); });
          continue;
        }
        if (tag === 'INS' && cls.contains('gd-ins')) f.rev = { type: 'ins', author: n.dataset.a, date: n.dataset.t };
        if (tag === 'DEL' && cls.contains('gd-del')) f.rev = { type: 'del', author: n.dataset.a, date: n.dataset.t };
        if (tag === 'B' || tag === 'STRONG' || +st.fontWeight >= 600 || st.fontWeight === 'bold') f.bold = true;
        if (st.fontWeight === 'normal' || st.fontWeight === '400') f.bold = false;
        if (tag === 'I' || tag === 'EM' || st.fontStyle === 'italic') f.italics = true;
        if (tag === 'U' || /underline/.test(st.textDecoration || st.textDecorationLine || '')) f.underline = {};
        if (tag === 'S' || tag === 'STRIKE' || (tag === 'DEL' && !cls.contains('gd-del')) || /line-through/.test(st.textDecoration || st.textDecorationLine || '')) f.strike = true;
        if (tag === 'SUP') f.superScript = true; if (tag === 'SUB') f.subScript = true;
        if (tag === 'CODE') f.font = 'Courier New';
        if (st.textTransform === 'uppercase') f.allCaps = true; if (st.fontVariant === 'small-caps') f.smallCaps = true;
        const col = hex(st.color || (tag === 'FONT' ? n.getAttribute('color') : null)); if (col) f.color = col;
        const bg = hex(st.backgroundColor); if (bg && bg !== 'FFFFFF') f.shading = { type: D.ShadingType.CLEAR, color: 'auto', fill: bg };
        const sz = ptSize(st.fontSize); if (sz) f.size = sz;
        if (st.fontFamily) f.font = st.fontFamily.split(',')[0].replace(/["']/g, '').trim();
        if (tag === 'A' && n.getAttribute('href')) {
          const ch = []; inline(n, { ...f, style: 'Hyperlink' }, ch);
          const href = n.getAttribute('href');
          out.push(href.startsWith('#') ? new D.InternalHyperlink({ children: ch, anchor: href.slice(1) }) : new D.ExternalHyperlink({ children: ch, link: href }));
          continue;
        }
        inline(n, f, out);
      }
    }
    const spacing = (st) => {
      const o = {};
      const lh = parseFloat(st.lineHeight); if (lh > 0 && /^[\d.]+$/.test(st.lineHeight)) { o.line = Math.round(lh * 240); o.lineRule = D.LineRuleType ? D.LineRuleType.AUTO : 'auto'; }
      if (st.marginTop) o.before = toTw(st.marginTop);
      if (st.marginBottom) o.after = toTw(st.marginBottom);
      return Object.keys(o).length ? o : null;
    };
    const para = (el, extra, fmt) => {
      const ch = []; inline(el, fmt || {}, ch);
      const st = el.style || {};
      const o = Object.assign({ children: el.id ? [new D.Bookmark({ id: el.id, children: ch })] : ch }, extra || {});
      const al = AL[st.textAlign]; if (al) o.alignment = al;
      const ind = Object.assign({}, o.indent || {});
      const ml = toTw(st.marginLeft); if (ml > 0 && !o.numbering) ind.left = (ind.left || 0) + ml;
      const mr = toTw(st.marginRight); if (mr > 0) ind.right = mr;
      const ti = toTw(st.textIndent); if (ti > 0) ind.firstLine = ti; else if (ti < 0) ind.hanging = -ti;
      if (Object.keys(ind).length) o.indent = ind;
      const sp = spacing(st); if (sp) o.spacing = Object.assign({}, o.spacing || {}, sp);
      return new D.Paragraph(o);
    };
    let listInst = 0;
    function list(el, level, out, inst) {
      const check = el.classList.contains('gd-check');
      const key = listKey(el); if (!check) usedLists.add(key);
      for (const li of el.children) {
        if (li.tagName !== 'LI') continue;
        const clone = li.cloneNode(true); clone.querySelectorAll('ul,ol').forEach(x => x.remove());
        if (check) {
          const box = document.createTextNode((li.dataset.ck === '1' ? '☑' : '☐') + ' '); clone.prepend(box);
          out.push(para(clone, { indent: { left: 360 * level, hanging: 0 } }, li.dataset.ck === '1' ? { strike: true } : {}));
        } else out.push(para(clone, { numbering: { reference: key, level: Math.min(level, 8), instance: inst } }));
        li.querySelectorAll(':scope > ul, :scope > ol').forEach(sub => list(sub, level + 1, out, sub.tagName === 'OL' ? ++listInst : inst));
      }
    }
    function table(el, out) {
      const rows = [];
      const bw = el.dataset.bw !== undefined ? +el.dataset.bw : 1, bc = hex(el.dataset.bc) || '000000';
      const b = bw > 0 ? { style: D.BorderStyle.SINGLE, size: Math.max(2, Math.round(bw * 0.75 * 8)), color: bc } : { style: D.BorderStyle.NONE, size: 0, color: 'FFFFFF' };
      const hdr = el.dataset.hdr === '1';
      el.querySelectorAll(':scope > tbody > tr, :scope > thead > tr, :scope > tr').forEach((tr, ri) => {
        const cells = [];
        tr.querySelectorAll(':scope > td, :scope > th').forEach(td => {
          const sub = []; walk(td, sub);
          const fill = hex(td.style.backgroundColor), w = toTw(td.style.width);
          const va = { middle: D.VerticalAlign.CENTER, bottom: D.VerticalAlign.BOTTOM }[td.style.verticalAlign];
          cells.push(new D.TableCell({ children: sub.length ? sub : [new D.Paragraph('')], columnSpan: +td.colSpan > 1 ? +td.colSpan : undefined, rowSpan: +td.rowSpan > 1 ? +td.rowSpan : undefined,
            shading: fill ? { fill, type: D.ShadingType.CLEAR, color: 'auto' } : undefined, width: w ? { size: w, type: D.WidthType.DXA } : undefined, verticalAlign: va }));
        });
        if (cells.length) rows.push(new D.TableRow({ children: cells, tableHeader: hdr && ri === 0 }));
      });
      if (rows.length) out.push(new D.Table({ rows, width: { size: 100, type: D.WidthType.PERCENTAGE }, borders: { top: b, bottom: b, left: b, right: b, insideHorizontal: b, insideVertical: b } }));
    }
    function toc(el, out) {
      const num = el.dataset.toc !== 'link';
      el.querySelectorAll('[data-to]').forEach(e => {
        const lvl = +(e.dataset.l || 1) - 1, ch = [new D.InternalHyperlink({ anchor: e.dataset.to, children: [new D.TextRun({ text: e.dataset.text || e.textContent, style: num ? undefined : 'Hyperlink' })] })];
        if (num) ch.push(new D.TextRun({ children: [new D.Tab(), String(e.dataset.pg || '')] }));
        out.push(new D.Paragraph({ style: 'TOC' + Math.min(lvl + 1, 6), children: ch, indent: { left: 360 * lvl }, spacing: { after: 60 }, tabStops: num ? [{ type: D.TabStopType.RIGHT, position: D.TabStopPosition.MAX, leader: 'dot' }] : undefined }));
      });
    }
    function walk(container, out) {
      let loose = null; // text bez odseku
      const flush = () => { if (loose) { out.push(para(loose)); loose = null; } };
      for (const n of container.childNodes) {
        const blockImg = n.nodeType === 1 && n.tagName === 'IMG' && n.classList.contains('wb');
        if (n.nodeType === 3 || (n.nodeType === 1 && !blockImg && !/^(P|DIV|H[1-6]|UL|OL|TABLE|HR|BLOCKQUOTE|PRE)$/.test(n.tagName))) {
          if (n.nodeType === 3 && !n.nodeValue.trim() && !loose) continue;
          if (n.nodeType === 1 && n.classList.contains('gd-lb')) continue;
          loose = loose || document.createElement('p'); loose.appendChild(n.cloneNode(true)); continue;
        }
        flush();
        const t = n.tagName, cls = n.classList;
        if (blockImg) { const p = document.createElement('p'); p.style.textAlign = cls.contains('c') ? 'center' : cls.contains('r') ? 'right' : 'left'; const c = n.cloneNode(true); c.classList.remove('wb'); p.appendChild(c); out.push(para(p)); }
        else if (/^H[1-6]$/.test(t)) out.push(para(n, { heading: HEAD[t] }));
        else if (t === 'P' && cls.contains('gd-title')) out.push(para(n, { heading: D.HeadingLevel.TITLE }));
        else if (t === 'P' && cls.contains('gd-subtitle')) out.push(para(n, { style: 'Subtitle' }));
        else if (t === 'UL' || t === 'OL') list(n, 0, out, ++listInst);
        else if (t === 'TABLE') table(n, out);
        else if (t === 'HR') out.push(cls.contains('col-break') ? new D.Paragraph({ children: [new D.ColumnBreak()] }) : cls.contains('gd-hr') ? new D.Paragraph({ children: [], border: { bottom: { style: D.BorderStyle.SINGLE, size: 6, color: 'auto', space: 1 } } }) : new D.Paragraph({ children: [new D.PageBreak()] }));
        else if (t === 'DIV' && cls.contains('gd-toc')) toc(n, out);
        else if (t === 'DIV' && n.querySelector('p,h1,h2,h3,h4,h5,h6,ul,ol,table,blockquote,pre')) walk(n, out);
        else if (t === 'BLOCKQUOTE') out.push(para(n, { style: 'Quote' }));
        else if (t === 'PRE') n.textContent.split('\n').forEach(line => out.push(new D.Paragraph({ style: 'Code', children: [new D.TextRun(line)] })));
        else out.push(para(n));
      }
      flush();
    }
    const blocks = []; walk(root, blocks);
    const hfPart = (html) => { if (!html) return null; const d = document.createElement('div'); d.innerHTML = html; const o = []; walk(d, o); return o.length ? o : [new D.Paragraph('')]; };
    const P = Object.assign({ w: 210, h: 297, landscape: false, mt: 2.54, mb: 2.54, ml: 2.54, mr: 2.54, hd: 1.27, fd: 1.27, cols: 1, diffFirst: false }, opt.page || {});
    const hd = opt.header || {}, ft = opt.footer || {};
    const headers = {}, footers = {};
    if (hd.def) headers.default = new D.Header({ children: hfPart(hd.def) });
    if (ft.def) footers.default = new D.Footer({ children: hfPart(ft.def) });
    if (P.diffFirst) { headers.first = new D.Header({ children: hfPart(hd.first) || [new D.Paragraph('')] }); footers.first = new D.Footer({ children: hfPart(ft.first) || [new D.Paragraph('')] }); }
    const lvls = (key) => {
      const [kind, style] = [key.slice(0, 2), key.slice(3)];
      const F = { decimal: D.LevelFormat.DECIMAL, 'lower-alpha': D.LevelFormat.LOWER_LETTER, 'upper-alpha': D.LevelFormat.UPPER_LETTER, 'lower-roman': D.LevelFormat.LOWER_ROMAN, 'upper-roman': D.LevelFormat.UPPER_ROMAN };
      const cyc = [F[style] || D.LevelFormat.DECIMAL, D.LevelFormat.LOWER_LETTER, D.LevelFormat.LOWER_ROMAN];
      return Array.from({ length: 9 }, (_, i) => ({ level: i, format: kind === 'ul' ? D.LevelFormat.BULLET : cyc[i % 3], text: kind === 'ul' ? BUL[style][i % 3] : '%' + (i + 1) + '.', alignment: D.AlignmentType.LEFT, style: { paragraph: { indent: { left: 720 * (i + 1), hanging: 360 } } } }));
    };
    const twMm = (mm) => Math.round(mm * 56.693), twCm2 = (cm) => Math.round(cm * 567);
    const sectProps = { page: { size: { width: twMm(P.w), height: twMm(P.h), orientation: P.landscape ? D.PageOrientation.LANDSCAPE : D.PageOrientation.PORTRAIT },
      margin: { top: twCm2(P.mt), bottom: twCm2(P.mb), left: twCm2(P.ml), right: twCm2(P.mr), header: twCm2(P.hd), footer: twCm2(P.fd) } }, titlePage: !!P.diffFirst };
    if (P.cols > 1) sectProps.column = { count: P.cols, space: 720 };
    const docOpt = {
      creator: 'Cloud', title: opt.title || '',
      styles: {
        default: {
          document: { run: { font: 'Calibri', size: 22 }, paragraph: { spacing: { after: 160, line: 276 } } },
          title: { run: { size: 52, color: '000000', font: 'Calibri' }, paragraph: { spacing: { after: 60 } } },
          heading1: { run: { size: 40, color: '000000', bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 400, after: 120 } } },
          heading2: { run: { size: 32, color: '000000', bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 360, after: 120 } } },
          heading3: { run: { size: 28, color: '434343', bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 320, after: 80 } } },
          heading4: { run: { size: 24, color: '666666', bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 280, after: 80 } } },
          heading5: { run: { size: 22, color: '666666', bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 240, after: 80 } } },
          heading6: { run: { size: 22, color: '666666', italics: true, bold: false, font: 'Calibri' }, paragraph: { spacing: { before: 240, after: 80 } } },
        },
        paragraphStyles: [
          { id: 'Subtitle', name: 'Subtitle', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 30, color: '666666' }, paragraph: { spacing: { after: 320 } } },
          { id: 'Quote', name: 'Quote', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { italics: true, color: '555555' }, paragraph: { indent: { left: 720 }, border: { left: { style: D.BorderStyle.SINGLE, size: 18, color: 'CCCCCC', space: 8 } } } },
          ...[1, 2, 3, 4, 5, 6].map(i => ({ id: 'TOC' + i, name: 'toc ' + i, basedOn: 'Normal', next: 'Normal', paragraph: { spacing: { after: 60 } } })),
          { id: 'Code', name: 'Code', basedOn: 'Normal', run: { font: 'Courier New', size: 20 }, paragraph: { spacing: { after: 0, line: 240 }, shading: { type: D.ShadingType.CLEAR, color: 'auto', fill: 'F1F3F4' } } },
        ],
      },
      numbering: { config: [...usedLists, 'ul-disc', 'ol-decimal'].filter((v, i, a) => a.indexOf(v) === i).map(k => ({ reference: k, levels: lvls(k) })) },
      sections: [{ properties: sectProps, headers, footers, children: blocks.length ? blocks : [new D.Paragraph('')] }],
    };
    if (P.color && hex(P.color) && hex(P.color) !== 'FFFFFF') docOpt.background = { color: hex(P.color) };
    if (Object.keys(footnotes).length) docOpt.footnotes = footnotes;
    if (cmList.length) docOpt.comments = { children: cmList };
    if (root.querySelector('.gd-toc')) docOpt.features = { updateFields: false };
    return D.Packer.toBlob(new D.Document(docOpt));
  }

  window.CloudDocIO = { fromDocx, toDocx, hex };
})();
