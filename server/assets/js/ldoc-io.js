/* Prevod dokumentov Word (.docx) ↔ HTML pre editor dokumentov (doc.php).
   Načítanie: vlastný prevod word/document.xml (nadpisy, písmo, farby, zarovnanie, zoznamy, tabuľky, obrázky, odkazy).
   Uloženie: knižnica docx (zo servera, nič sa neposiela inam). */
(function () {
  const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const kids = (el, name) => el ? [...el.childNodes].filter(n => n.nodeType === 1 && (!name || n.localName === name)) : [];
  const kid = (el, name) => kids(el, name)[0] || null;
  const att = (el, name) => el ? (el.getAttributeNS(W, name) ?? el.getAttribute('w:' + name)) : null;
  const HL = { yellow: '#ffff00', green: '#00ff00', cyan: '#00ffff', magenta: '#ff00ff', blue: '#0000ff', red: '#ff0000', darkBlue: '#000080', darkCyan: '#008080', darkGreen: '#008000', darkMagenta: '#800080', darkRed: '#800000', darkYellow: '#808000', darkGray: '#808080', lightGray: '#c0c0c0', black: '#000000' };

  /* ---------- .docx → HTML ---------- */
  async function fromDocx(buf) {
    const zip = await JSZip.loadAsync(buf);
    const read = async (p) => { const f = zip.file(p); return f ? new DOMParser().parseFromString(await f.async('string'), 'application/xml') : null; };
    const doc = await read('word/document.xml');
    if (!doc) throw new Error('Súbor nie je dokument Word (.docx).');
    const rels = {}, relDoc = await read('word/_rels/document.xml.rels');
    if (relDoc) [...relDoc.getElementsByTagName('Relationship')].forEach(r => { rels[r.getAttribute('Id')] = { target: r.getAttribute('Target'), mode: r.getAttribute('TargetMode') }; });
    // štýly: nadpisy podľa ID aj podľa názvu (napr. „heading 1“, „Nadpis 1“)
    const styles = {}, stDoc = await read('word/styles.xml');
    if (stDoc) [...stDoc.getElementsByTagNameNS(W, 'style')].forEach(s => {
      const id = att(s, 'styleId'), nm = (att(kid(s, 'name'), 'val') || '').toLowerCase();
      let m = /^(heading|nadpis)\s*(\d)$/.exec(nm) || /^(heading|nadpis)(\d)$/i.exec(id || '');
      styles[id] = m ? 'h' + Math.min(+m[2], 4) : nm === 'title' || /^title$/i.test(id) ? 'h1' : null;
    });
    // číslovanie: ktoré zoznamy sú odrážky a ktoré čísla
    const num = {}, numDoc = await read('word/numbering.xml');
    if (numDoc) {
      const abs = {};
      [...numDoc.getElementsByTagNameNS(W, 'abstractNum')].forEach(a => { abs[att(a, 'abstractNumId')] = {}; kids(a, 'lvl').forEach(l => { abs[att(a, 'abstractNumId')][att(l, 'ilvl')] = att(kid(l, 'numFmt'), 'val') || 'bullet'; }); });
      [...numDoc.getElementsByTagNameNS(W, 'num')].forEach(n => { num[att(n, 'numId')] = abs[att(kid(n, 'abstractNumId'), 'val')] || {}; });
    }
    const media = {};
    const image = async (rid) => {
      if (media[rid] !== undefined) return media[rid];
      const r = rels[rid]; if (!r) return (media[rid] = null);
      const path = r.target.startsWith('/') ? r.target.slice(1) : 'word/' + r.target.replace(/^\.\//, '');
      const f = zip.file(path); if (!f) return (media[rid] = null);
      const ext = path.split('.').pop().toLowerCase(), mime = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', bmp: 'image/bmp', webp: 'image/webp' }[ext];
      return (media[rid] = mime ? 'data:' + mime + ';base64,' + await f.async('base64') : null);
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
      return { css: css.join(';'), tags };
    };
    async function runs(p) {
      let h = '';
      for (const n of kids(p)) {
        if (n.localName === 'r') {
          const { css, tags } = runCss(kid(n, 'rPr'));
          let t = '';
          for (const c of kids(n)) {
            if (c.localName === 't') t += esc(c.textContent);
            else if (c.localName === 'tab') t += '&emsp;';
            else if (c.localName === 'br') t += att(c, 'type') === 'page' ? '</p><hr class="page-break"><p>' : '<br>';
            else if (c.localName === 'drawing' || c.localName === 'pict') {
              const blip = c.getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 'blip')[0];
              const rid = blip ? (blip.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed') || blip.getAttribute('r:embed')) : null;
              const ext = c.getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing', 'extent')[0];
              const src = rid ? await image(rid) : null;
              if (src) { const w = ext ? Math.round(+ext.getAttribute('cx') / 9525) : null, hh = ext ? Math.round(+ext.getAttribute('cy') / 9525) : null; t += '<img src="' + src + '"' + (w ? ' width="' + w + '" height="' + hh + '"' : '') + ' alt="">'; }
            }
          }
          if (!t) continue;
          tags.forEach(tg => { t = '<' + tg + '>' + t + '</' + tg + '>'; });
          h += css ? '<span style="' + css + '">' + t + '</span>' : t;
        } else if (n.localName === 'hyperlink') {
          const rid = n.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id') || n.getAttribute('r:id');
          const href = rid && rels[rid] ? rels[rid].target : (att(n, 'anchor') ? '#' + att(n, 'anchor') : '');
          h += '<a href="' + esc(href) + '">' + await runs(n) + '</a>';
        } else if (['ins', 'smartTag', 'fldSimple', 'sdt', 'sdtContent', 'customXml'].includes(n.localName)) h += await runs(n.localName === 'sdt' ? kid(n, 'sdtContent') : n);
      }
      return h;
    }
    const warn = new Set();
    async function block(body) {
      let h = ''; const stack = []; // otvorené zoznamy [{tag, lvl}]
      const closeTo = (lvl) => { while (stack.length && stack[stack.length - 1].lvl >= lvl) h += '</li></' + stack.pop().tag + '>'; };
      for (const n of kids(body)) {
        if (n.localName === 'p') {
          const pPr = kid(n, 'pPr');
          const st = styles[att(kid(pPr, 'pStyle'), 'val')] || null;
          const numPr = kid(pPr, 'numPr');
          const jc = att(kid(pPr, 'jc'), 'val');
          const align = { center: 'center', right: 'right', end: 'right', both: 'justify', distribute: 'justify' }[jc];
          const ind = kid(pPr, 'ind'), left = ind ? +(att(ind, 'left') || att(ind, 'start') || 0) : 0;
          const css = (align ? 'text-align:' + align + ';' : '') + (!numPr && left > 0 ? 'margin-left:' + Math.round(left / 15) + 'px;' : '');
          const inner = (await runs(n)) || '<br>';
          if (kid(pPr, 'pageBreakBefore')) { closeTo(0); h += '<hr class="page-break">'; }
          if (numPr) {
            const id = att(kid(numPr, 'numId'), 'val'), lvl = +(att(kid(numPr, 'ilvl'), 'val') || 0);
            if (id && id !== '0') {
              const fmt = (num[id] || {})[lvl] || 'bullet', tag = fmt === 'bullet' || fmt === 'none' ? 'ul' : 'ol';
              const top = stack[stack.length - 1];
              if (top && top.lvl === lvl && top.tag === tag) h += '</li><li' + (css ? ' style="' + css + '"' : '') + '>' + inner;
              else if (top && top.lvl < lvl) { stack.push({ tag, lvl }); h += '<' + tag + '><li>' + inner; }
              else { closeTo(lvl); stack.push({ tag, lvl }); h += '<' + tag + '><li' + (css ? ' style="' + css + '"' : '') + '>' + inner; }
              continue;
            }
          }
          closeTo(0);
          const tag = st || 'p';
          h += '<' + tag + (css ? ' style="' + css + '"' : '') + '>' + inner + '</' + tag + '>';
        } else if (n.localName === 'tbl') {
          closeTo(0);
          h += '<table><tbody>';
          for (const tr of kids(n, 'tr')) {
            h += '<tr>';
            for (const tc of kids(tr, 'tc')) {
              const tcPr = kid(tc, 'tcPr'), span = +(att(kid(tcPr, 'gridSpan'), 'val') || 1);
              const vm = kid(tcPr, 'vMerge'); if (vm && (att(vm, 'val') || 'continue') === 'continue') { h += '<td class="vmerge"></td>'; continue; }
              const fill = att(kid(tcPr, 'shd'), 'fill');
              h += '<td' + (span > 1 ? ' colspan="' + span + '"' : '') + (fill && /^[0-9a-f]{6}$/i.test(fill) && fill.toLowerCase() !== 'ffffff' ? ' style="background-color:#' + fill.toLowerCase() + '"' : '') + '>' + (await block(tc)) + '</td>';
            }
            h += '</tr>';
          }
          h += '</tbody></table>';
        } else if (n.localName === 'sdt') h += await block(kid(n, 'sdtContent'));
        else if (n.localName === 'sectPr') { /* nastavenie strany */ }
      }
      closeTo(0);
      return h;
    }
    const body = kid(doc.documentElement, 'body');
    const html = await block(body);
    // čo editor nezobrazí
    const files = Object.keys(zip.files).join('\n');
    if (/word\/header\d*\.xml/.test(files) && /<w:t[ >]/.test(await zip.file(/word\/header\d*\.xml/)[0].async('string'))) warn.add('hlavička');
    if (zip.file(/word\/footer\d*\.xml/).length) { for (const f of zip.file(/word\/footer\d*\.xml/)) if (/<w:t[ >]/.test(await f.async('string'))) { warn.add('päta'); break; } }
    if (zip.file('word/comments.xml')) warn.add('komentáre');
    if (zip.file('word/footnotes.xml') && (await zip.file('word/footnotes.xml').async('string')).split('<w:footnote ').length > 3) warn.add('poznámky pod čiarou');
    const xml = await zip.file('word/document.xml').async('string');
    if (/<w:del\b|<w:ins\b/.test(xml)) warn.add('sledované zmeny');
    if (/<w:txbxContent\b/.test(xml)) warn.add('textové polia');
    if (/word\/charts\//.test(files)) warn.add('grafy');
    if (/<m:oMath/.test(xml)) warn.add('rovnice');
    const sect = body ? kid(body, 'sectPr') : null, pg = sect ? kid(sect, 'pgSz') : null;
    return { html: html || '<p><br></p>', warn: [...warn], landscape: pg ? att(pg, 'orient') === 'landscape' : false };
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
  function dataToBytes(src) { const b = atob(src.split(',')[1] || ''); const u = new Uint8Array(b.length); for (let i = 0; i < b.length; i++) u[i] = b.charCodeAt(i); return u; }

  async function toDocx(root, opt) {
    const D = window.docx;
    const AL = { center: D.AlignmentType.CENTER, right: D.AlignmentType.RIGHT, justify: D.AlignmentType.JUSTIFIED, left: D.AlignmentType.LEFT };
    const HEAD = { H1: D.HeadingLevel.HEADING_1, H2: D.HeadingLevel.HEADING_2, H3: D.HeadingLevel.HEADING_3, H4: D.HeadingLevel.HEADING_4 };
    // vnorené formátovanie textu → TextRun
    function inline(node, fmt, out) {
      for (const n of node.childNodes) {
        if (n.nodeType === 3) {
          const t = n.nodeValue.replace(/ /g, '\t'); if (!t) continue;
          const parts = t.split('\t');
          parts.forEach((p, i) => { if (i) out.push(new D.TextRun({ ...fmt, children: [new D.Tab()] })); if (p) out.push(new D.TextRun({ ...fmt, text: p.replace(/ /g, ' ') })); });
          continue;
        }
        if (n.nodeType !== 1) continue;
        const tag = n.tagName, f = { ...fmt }, st = n.style || {};
        if (tag === 'BR') { out.push(new D.TextRun({ ...fmt, break: 1 })); continue; }
        if (tag === 'IMG') {
          const mt = /^data:image\/(png|jpe?g|gif|bmp)/i.exec(n.src); if (!mt) continue;
          const w = n.width || n.naturalWidth || 300, h = n.height || n.naturalHeight || 200, max = 620, k = w > max ? max / w : 1;
          out.push(new D.ImageRun({ type: mt[1].toLowerCase() === 'jpeg' ? 'jpg' : mt[1].toLowerCase(), data: dataToBytes(n.src), transformation: { width: Math.round(w * k), height: Math.round(h * k) } }));
          continue;
        }
        if (tag === 'B' || tag === 'STRONG' || +st.fontWeight >= 600 || st.fontWeight === 'bold') f.bold = true;
        if (tag === 'I' || tag === 'EM' || st.fontStyle === 'italic') f.italics = true;
        if (tag === 'U' || /underline/.test(st.textDecoration || st.textDecorationLine || '')) f.underline = {};
        if (tag === 'S' || tag === 'STRIKE' || tag === 'DEL' || /line-through/.test(st.textDecoration || st.textDecorationLine || '')) f.strike = true;
        if (tag === 'SUP') f.superScript = true; if (tag === 'SUB') f.subScript = true;
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
    const blocks = [];
    const para = (el, extra) => {
      const ch = []; inline(el, {}, ch);
      const st = el.style || {};
      const o = Object.assign({ children: ch }, extra || {});
      const al = AL[st.textAlign]; if (al) o.alignment = al;
      const ml = parseFloat(st.marginLeft); if (ml > 0 && !o.numbering) o.indent = { left: Math.round(ml * 15) };
      return new D.Paragraph(o);
    };
    function list(el, level) {
      const ref = el.tagName === 'OL' ? 'cloud-numbers' : 'cloud-bullets';
      for (const li of el.children) {
        if (li.tagName !== 'LI') continue;
        const clone = li.cloneNode(true); clone.querySelectorAll('ul,ol').forEach(x => x.remove());
        blocks.push(para(clone, { numbering: { reference: ref, level: Math.min(level, 8) } }));
        li.querySelectorAll(':scope > ul, :scope > ol').forEach(sub => list(sub, level + 1));
      }
    }
    function table(el) {
      const rows = [];
      el.querySelectorAll(':scope > tbody > tr, :scope > thead > tr, :scope > tr').forEach(tr => {
        const cells = [];
        tr.querySelectorAll(':scope > td, :scope > th').forEach(td => {
          if (td.classList.contains('vmerge')) return;
          const sub = []; const saved = blocks.length;
          walk(td); sub.push(...blocks.splice(saved));
          const fill = hex(td.style.backgroundColor);
          cells.push(new D.TableCell({ children: sub.length ? sub : [new D.Paragraph('')], columnSpan: +td.colSpan > 1 ? +td.colSpan : undefined, shading: fill ? { fill, type: D.ShadingType.CLEAR, color: 'auto' } : undefined }));
        });
        if (cells.length) rows.push(new D.TableRow({ children: cells }));
      });
      if (rows.length) blocks.push(new D.Table({ rows, width: { size: 100, type: D.WidthType.PERCENTAGE } }));
    }
    function walk(container) {
      let loose = null; // text bez odseku
      const flush = () => { if (loose) { blocks.push(para(loose)); loose = null; } };
      for (const n of container.childNodes) {
        if (n.nodeType === 3 || (n.nodeType === 1 && !/^(P|DIV|H[1-6]|UL|OL|TABLE|HR|BLOCKQUOTE|PRE)$/.test(n.tagName))) {
          if (n.nodeType === 3 && !n.nodeValue.trim() && !loose) continue;
          loose = loose || document.createElement('p'); loose.appendChild(n.cloneNode(true)); continue;
        }
        flush();
        const t = n.tagName;
        if (/^H[1-6]$/.test(t)) blocks.push(para(n, { heading: HEAD[t] || D.HeadingLevel.HEADING_4 }));
        else if (t === 'UL' || t === 'OL') list(n, 0);
        else if (t === 'TABLE') table(n);
        else if (t === 'HR') blocks.push(new D.Paragraph({ children: [new D.PageBreak()] }));
        else if (t === 'DIV' && n.querySelector('p,h1,h2,h3,h4,ul,ol,table')) walk(n);
        else if (t === 'BLOCKQUOTE') blocks.push(para(n, { indent: { left: 720 } }));
        else blocks.push(para(n));
      }
      flush();
    }
    walk(root);
    const lvls = (fmt) => Array.from({ length: 9 }, (_, i) => ({ level: i, format: fmt === 'bullet' ? D.LevelFormat.BULLET : [D.LevelFormat.DECIMAL, D.LevelFormat.LOWER_LETTER, D.LevelFormat.LOWER_ROMAN][i % 3],
      text: fmt === 'bullet' ? ['●', '○', '■'][i % 3] : '%' + (i + 1) + '.', alignment: D.AlignmentType.LEFT, style: { paragraph: { indent: { left: 720 * (i + 1), hanging: 360 } } } }));
    const doc = new D.Document({
      creator: 'Cloud', title: (opt && opt.title) || '',
      styles: { default: { document: { run: { font: 'Calibri', size: 22 } } } },
      numbering: { config: [{ reference: 'cloud-bullets', levels: lvls('bullet') }, { reference: 'cloud-numbers', levels: lvls('number') }] },
      sections: [{ properties: { page: { margin: { top: 1440, bottom: 1440, left: 1440, right: 1440 }, size: opt && opt.landscape ? { orientation: D.PageOrientation.LANDSCAPE } : undefined } }, children: blocks.length ? blocks : [new D.Paragraph('')] }],
    });
    return D.Packer.toBlob(doc);
  }

  window.CloudDocIO = { fromDocx, toDocx };
})();
