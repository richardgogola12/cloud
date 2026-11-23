/* Editor dokumentov – špeciálne znaky a emodži, kreslenie, grafy a stiahnutie v ďalších formátoch
   (OpenDocument .odt, RTF, webová stránka, EPUB, Markdown). Všetko sa robí v prehliadači, nič sa neposiela inam. */
(function () {
  const GD = window.GD; if (!GD) return;
  const { S, C, page, $, esc } = GD;

  /* ======================= ŠPECIÁLNE ZNAKY ======================= */
  const CHARS = {
    'Interpunkcia': '„ “ ‚ ‘ » « › ‹ — – … • · ¶ § † ‡ ‰ ′ ″ ¡ ¿ ‽ ※ ¦ ‖ ⁂',
    'Šípky': '← → ↑ ↓ ↔ ↕ ⇐ ⇒ ⇑ ⇓ ⇔ ↖ ↗ ↘ ↙ ➔ ➜ ➢ ➤ ⟵ ⟶ ↩ ↪ ⤴ ⤵ ▲ ▼ ◀ ▶',
    'Matematika': '± × ÷ ≈ ≠ ≤ ≥ ∞ √ ∑ ∏ ∫ ∂ ∆ ∇ ∈ ∉ ∩ ∪ ⊂ ⊃ ⊆ ⊇ ∀ ∃ ¬ ∧ ∨ ° ¹ ² ³ ⁴ ₀ ₁ ₂ ₃ ½ ⅓ ⅔ ¼ ¾ π µ ∝ ∠ ⊥ ∥ ≡ ≅ ∴ ∵',
    'Meny': '€ $ £ ¥ ¢ ₿ ₽ ₹ ₩ ₺ ₴ ₸ ₪ ₫ ฿ ¤',
    'Písmená': 'á ä č ď é ě í ĺ ľ ň ó ô ŕ ř š ť ú ů ý ž Á Ä Č Ď É Ě Í Ĺ Ľ Ň Ó Ô Ŕ Ř Š Ť Ú Ů Ý Ž ß ø æ œ å ñ ç ü ö ë ï ğ ł ő ű ş Ø Æ Œ Å Ñ Ç Ü Ö',
    'Grécke': 'α β γ δ ε ζ η θ ι κ λ μ ν ξ ο π ρ σ τ υ φ χ ψ ω Α Β Γ Δ Ε Ζ Η Θ Ι Κ Λ Μ Ν Ξ Ο Π Ρ Σ Τ Υ Φ Χ Ψ Ω',
    'Symboly': '© ® ™ ✓ ✔ ✗ ✘ ☐ ☑ ☒ ★ ☆ ♥ ♡ ♦ ♣ ♠ ☺ ☻ ☀ ☁ ☂ ☎ ✉ ✂ ✏ ✎ ⚠ ⚡ ♻ ♪ ♫ ⌘ ⌥ ⏎ ⌫ ⌛ ⏰ ☯ ☮ ✿ ❀ ❖ ◆ ◇ ■ □ ● ○ ◎',
    'Emodži': '😀 😃 😄 😁 😆 😅 😂 🙂 😉 😊 😇 😍 🥰 😘 😋 😎 🤓 🤔 🤗 😐 😴 😮 😢 😭 😡 🥳 🤩 👍 👎 👏 🙌 🙏 💪 👋 ✌️ 👌 ❤️ 🧡 💛 💚 💙 💜 💔 🔥 ⭐ ✨ 🎉 🎂 🎁 🎄 ☕ 🍕 🍎 🍰 🍷 🌞 🌧️ ❄️ 🌈 🌍 🌸 🐶 🐱 🏠 🚗 🚲 ✈️ 🏖️ ⛰️ 📅 📌 📎 📝 📚 📞 📷 💻 💡 🔑 🛒 💶 ✅ ❌ ⚠️ ❓ ❗ 💯 🔔 🏆 ⚽',
  };
  async function specialChars() {
    if (!GD.canEdit()) return;
    const recent = GD.store.get('chars', []);
    const cats = Object.keys(CHARS);
    await GD.dlg('Špeciálne znaky a emodži', '<div class="d-flex gap-2 mb-2"><select class="form-select form-select-sm" id="scC" style="max-width:180px">' + (recent.length ? '<option value="_r">Nedávne</option>' : '') + cats.map(c => '<option>' + c + '</option>').join('') + '</select><input class="form-control form-control-sm" id="scQ" placeholder="Hľadať alebo vložiť znak"></div><div class="gd-chars" id="scG"></div>',
      { size: 'modal-lg', noFooter: true, init: (el) => {
        const draw = () => {
          const c = el.querySelector('#scC').value, q = el.querySelector('#scQ').value.trim();
          const list = q ? Object.values(CHARS).join(' ').split(' ').filter(ch => ch.includes(q) || ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '') === q) : (c === '_r' ? recent : CHARS[c].split(' '));
          el.querySelector('#scG').innerHTML = list.map(ch => '<button type="button" title="U+' + ch.codePointAt(0).toString(16).toUpperCase().padStart(4, '0') + '">' + esc(ch) + '</button>').join('') || '<div class="text-body-secondary small p-2">Nič sa nenašlo</div>';
        };
        el.querySelector('#scC').onchange = draw; el.querySelector('#scQ').oninput = draw; draw();
        el.querySelector('#scG').addEventListener('click', (e) => {
          const b = e.target.closest('button'); if (!b) return; const ch = b.textContent;
          GD.restore(); document.execCommand('insertText', false, ch); GD.markDirty();
          const r = [ch, ...GD.store.get('chars', []).filter(x => x !== ch)].slice(0, 30); GD.store.set('chars', r);
        });
      } });
  }

  /* ======================= KRESLENIE ======================= */
  async function drawDialog(img) {
    if (!GD.canEdit()) return;
    let shapes = []; try { shapes = img && img.dataset.draw ? JSON.parse(img.dataset.draw).s || [] : []; } catch (e) {}
    let out = null;
    await GD.dlg('Kreslenie', '<div class="gd-drawbar"><div class="btn-group btn-group-sm" id="drT">' +
      [['pen', 'pencil', 'Pero'], ['line', 'slash-lg', 'Čiara'], ['arrow', 'arrow-up-right', 'Šípka'], ['rect', 'square', 'Obdĺžnik'], ['ellipse', 'circle', 'Elipsa'], ['text', 'fonts', 'Text']].map(([k, i, t], n) => '<button type="button" class="btn btn-outline-secondary' + (n ? '' : ' active') + '" data-t="' + k + '" title="' + t + '"><i class="bi bi-' + i + '"></i></button>').join('') +
      '</div><input type="color" id="drC" value="#1a73e8" class="form-control form-control-color form-control-sm" title="Farba"><select id="drW" class="form-select form-select-sm" title="Hrúbka" style="width:auto"><option value="2">tenká</option><option value="4" selected>stredná</option><option value="8">hrubá</option></select>' +
      '<label class="small"><input type="checkbox" id="drF"> výplň</label><button type="button" class="btn btn-sm btn-light" id="drU" title="Späť"><i class="bi bi-arrow-counterclockwise"></i></button><button type="button" class="btn btn-sm btn-light" id="drX" title="Vymazať všetko"><i class="bi bi-trash3"></i></button></div>' +
      '<div class="gd-drawwrap"><canvas id="drCv" width="800" height="450"></canvas></div>',
    { size: 'modal-xl', ok: 'Uložiť a zavrieť', static: true,
      init: (el) => {
        const cv = el.querySelector('#drCv'), ctx = cv.getContext('2d');
        let tool = 'pen', cur = null;
        el.querySelectorAll('#drT button').forEach(b => b.onclick = () => { tool = b.dataset.t; el.querySelectorAll('#drT button').forEach(x => x.classList.toggle('active', x === b)); });
        const pt = (e) => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
        const render = () => { ctx.clearRect(0, 0, cv.width, cv.height); [...shapes, cur].filter(Boolean).forEach(s => drawShape(ctx, s)); };
        cv.onpointerdown = (e) => {
          const [x, y] = pt(e), c = el.querySelector('#drC').value, w = +el.querySelector('#drW').value, f = el.querySelector('#drF').checked;
          if (tool === 'text') { GD.ask('Text', 'Napíš text', [['cancel', 'Zrušiť'], ['ok', 'OK', 'btn-primary']], '').then(t => { if (t && t !== 'cancel') { shapes.push({ t: 'text', c, s: 12 + w * 3, x, y, text: t }); render(); } }); return; }
          cv.setPointerCapture(e.pointerId);
          cur = tool === 'pen' ? { t: 'pen', c, w, p: [[x, y]] } : { t: tool, c, w, f, x1: x, y1: y, x2: x, y2: y };
        };
        cv.onpointermove = (e) => { if (!cur) return; const [x, y] = pt(e); if (cur.t === 'pen') cur.p.push([Math.round(x), Math.round(y)]); else { cur.x2 = x; cur.y2 = y; } render(); };
        cv.onpointerup = () => { if (cur) { shapes.push(cur); cur = null; render(); } };
        el.querySelector('#drU').onclick = () => { shapes.pop(); render(); };
        el.querySelector('#drX').onclick = () => { shapes = []; render(); };
        render();
      },
      onOk: () => {
        if (!shapes.length) { out = 'empty'; return; }
        const cv = document.createElement('canvas'); cv.width = 800; cv.height = 450; const ctx = cv.getContext('2d'); shapes.forEach(s => drawShape(ctx, s));
        // orez na obsah
        const d = ctx.getImageData(0, 0, 800, 450).data; let x0 = 800, y0 = 450, x1 = 0, y1 = 0;
        for (let y = 0; y < 450; y++) for (let x = 0; x < 800; x++) if (d[(y * 800 + x) * 4 + 3]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
        x0 = Math.max(0, x0 - 8); y0 = Math.max(0, y0 - 8); x1 = Math.min(799, x1 + 8); y1 = Math.min(449, y1 + 8);
        const o = document.createElement('canvas'); o.width = x1 - x0 + 1; o.height = y1 - y0 + 1; o.getContext('2d').drawImage(cv, -x0, -y0);
        out = { src: o.toDataURL('image/png'), w: o.width, h: o.height };
      } });
    if (!out) return;
    if (out === 'empty') { if (img) { img.remove(); GD.markDirty(); } return; }
    const json = JSON.stringify({ v: 1, s: shapes });
    if (img) { img.src = out.src; img.width = out.w; img.height = out.h; img.dataset.draw = json; GD.markDirty(); GD.emit('structure'); }
    else GD.insertImageData(out.src, out.w, out.h, ' data-draw="' + esc(json) + '"');
  }
  function drawShape(ctx, s) {
    ctx.save(); ctx.strokeStyle = ctx.fillStyle = s.c; ctx.lineWidth = s.w || 2; ctx.lineCap = ctx.lineJoin = 'round';
    if (s.t === 'pen') { ctx.beginPath(); s.p.forEach(([x, y], i) => i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)); if (s.p.length === 1) ctx.lineTo(s.p[0][0] + 0.1, s.p[0][1]); ctx.stroke(); }
    else if (s.t === 'line' || s.t === 'arrow') {
      ctx.beginPath(); ctx.moveTo(s.x1, s.y1); ctx.lineTo(s.x2, s.y2); ctx.stroke();
      if (s.t === 'arrow') { const a = Math.atan2(s.y2 - s.y1, s.x2 - s.x1), L = 10 + s.w * 2; ctx.beginPath(); ctx.moveTo(s.x2, s.y2); ctx.lineTo(s.x2 - L * Math.cos(a - 0.45), s.y2 - L * Math.sin(a - 0.45)); ctx.lineTo(s.x2 - L * Math.cos(a + 0.45), s.y2 - L * Math.sin(a + 0.45)); ctx.closePath(); ctx.fill(); }
    } else if (s.t === 'rect') { const x = Math.min(s.x1, s.x2), y = Math.min(s.y1, s.y2), w = Math.abs(s.x2 - s.x1), h = Math.abs(s.y2 - s.y1); if (s.f) { ctx.globalAlpha = 0.35; ctx.fillRect(x, y, w, h); ctx.globalAlpha = 1; } ctx.strokeRect(x, y, w, h); }
    else if (s.t === 'ellipse') { ctx.beginPath(); ctx.ellipse((s.x1 + s.x2) / 2, (s.y1 + s.y2) / 2, Math.abs(s.x2 - s.x1) / 2, Math.abs(s.y2 - s.y1) / 2, 0, 0, Math.PI * 2); if (s.f) { ctx.globalAlpha = 0.35; ctx.fill(); ctx.globalAlpha = 1; } ctx.stroke(); }
    else if (s.t === 'text') { ctx.font = s.s + 'px Arial, sans-serif'; ctx.textBaseline = 'top'; ctx.fillText(s.text, s.x, s.y); }
    ctx.restore();
  }
  GD.drawDialog = drawDialog;

  /* ======================= GRAFY ======================= */
  const loadChart = () => window.Chart ? Promise.resolve() : new Promise((res, rej) => { const s = document.createElement('script'); s.src = C.chart; s.onload = res; s.onerror = () => rej(new Error('Knižnicu grafov sa nepodarilo načítať')); document.head.appendChild(s); });
  const PALETTE = ['#4285f4', '#ea4335', '#fbbc04', '#34a853', '#ff6d01', '#46bdc6', '#7baaf7', '#f07b72'];
  function chartConfig(d, anim) {
    const pie = d.type === 'pie' || d.type === 'doughnut';
    return {
      type: pie ? d.type : d.type === 'barh' ? 'bar' : d.type === 'area' ? 'line' : d.type,
      data: { labels: d.labels, datasets: (pie ? d.series.slice(0, 1) : d.series).map((s, i) => ({ label: s.name, data: s.data.map(v => +String(v).replace(',', '.') || 0), backgroundColor: pie ? d.labels.map((_, j) => PALETTE[j % PALETTE.length]) : PALETTE[i % PALETTE.length] + (d.type === 'area' ? '55' : ''), borderColor: pie ? '#fff' : PALETTE[i % PALETTE.length], fill: d.type === 'area', tension: 0.25, borderWidth: pie ? 2 : d.type === 'line' || d.type === 'area' ? 2.5 : 0, borderRadius: d.type === 'bar' || d.type === 'barh' ? 3 : 0 })) },
      options: { responsive: false, animation: anim ? undefined : false, indexAxis: d.type === 'barh' ? 'y' : 'x', plugins: { title: { display: !!d.title, text: d.title, font: { size: 18, weight: '500' }, color: '#202124' }, legend: { display: pie || d.series.length > 1, position: 'bottom' } }, scales: pie ? {} : { x: { grid: { display: d.type === 'barh' } }, y: { beginAtZero: true, grid: { display: d.type !== 'barh' } } } },
      plugins: [{ id: 'bg', beforeDraw: (c) => { const x = c.ctx; x.save(); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.restore(); } }],
    };
  }
  async function chartDialog(img) {
    if (!GD.canEdit()) return;
    try { await loadChart(); } catch (e) { return GD.toast(e.message, 'err'); }
    let d = { type: 'bar', title: '', labels: ['Jan', 'Feb', 'Mar', 'Apr'], series: [{ name: 'Séria 1', data: [12, 19, 8, 15] }] };
    try { if (img && img.dataset.chart) d = JSON.parse(img.dataset.chart); } catch (e) {}
    let ch = null, out = null;
    const table = (el) => {
      const t = el.querySelector('#chT');
      t.innerHTML = '<thead><tr><th></th>' + d.series.map((s, i) => '<th><input class="form-control form-control-sm" data-sn="' + i + '" value="' + esc(s.name) + '"></th>').join('') + '<th><button type="button" class="btn btn-sm btn-light" data-add="col" title="Pridať sériu"><i class="bi bi-plus"></i></button></th></tr></thead><tbody>' +
        d.labels.map((l, r) => '<tr><td><input class="form-control form-control-sm" data-l="' + r + '" value="' + esc(l) + '"></td>' + d.series.map((s, i) => '<td><input class="form-control form-control-sm text-end" inputmode="decimal" data-v="' + r + ',' + i + '" value="' + esc(String(s.data[r] ?? '')) + '"></td>').join('') + '<td><button type="button" class="btn btn-sm btn-light" data-del="' + r + '" title="Odstrániť riadok"><i class="bi bi-x"></i></button></td></tr>').join('') +
        '</tbody><tfoot><tr><td colspan="9"><button type="button" class="btn btn-sm btn-light" data-add="row"><i class="bi bi-plus"></i> Pridať riadok</button>' + (d.series.length > 1 ? ' <button type="button" class="btn btn-sm btn-light" data-add="delcol"><i class="bi bi-dash"></i> Odobrať poslednú sériu</button>' : '') + '</td></tr></tfoot>';
    };
    const redraw = (el) => { if (ch) ch.destroy(); ch = new Chart(el.querySelector('#chCv'), chartConfig(d, false)); };
    await GD.dlg('Graf', '<div class="row g-3"><div class="col-lg-5"><div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">Typ grafu</label><select class="form-select form-select-sm" id="chType"><option value="bar">Stĺpcový</option><option value="barh">Pruhový</option><option value="line">Čiarový</option><option value="area">Plošný</option><option value="pie">Koláčový</option><option value="doughnut">Prstencový</option></select></div><div class="col-6"><label class="form-label small">Názov</label><input class="form-control form-control-sm" id="chTitle" value="' + esc(d.title) + '"></div></div>' +
      '<div class="gd-chtab"><table class="table table-sm mb-0" id="chT"></table></div></div><div class="col-lg-7"><canvas id="chCv" width="640" height="360" style="width:100%;height:auto;border:1px solid #e0e0e0;border-radius:8px"></canvas></div></div>',
    { size: 'modal-xl', ok: img ? 'Aktualizovať' : 'Vložiť',
      init: (el) => {
        el.querySelector('#chType').value = d.type;
        table(el); setTimeout(() => redraw(el), 50);
        el.querySelector('#chType').onchange = (e) => { d.type = e.target.value; redraw(el); };
        el.querySelector('#chTitle').oninput = (e) => { d.title = e.target.value; redraw(el); };
        el.querySelector('#chT').addEventListener('input', (e) => { const t = e.target; if (t.dataset.l !== undefined) d.labels[+t.dataset.l] = t.value; if (t.dataset.sn !== undefined) d.series[+t.dataset.sn].name = t.value; if (t.dataset.v) { const [r, i] = t.dataset.v.split(',').map(Number); d.series[i].data[r] = t.value; } redraw(el); });
        el.querySelector('#chT').addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; if (b.dataset.add === 'row') { d.labels.push('Položka ' + (d.labels.length + 1)); d.series.forEach(s => s.data.push(0)); } else if (b.dataset.add === 'col') d.series.push({ name: 'Séria ' + (d.series.length + 1), data: d.labels.map(() => 0) }); else if (b.dataset.add === 'delcol') d.series.pop(); else if (b.dataset.del !== undefined) { const r = +b.dataset.del; d.labels.splice(r, 1); d.series.forEach(s => s.data.splice(r, 1)); } table(el); redraw(el); });
      },
      onOk: () => {
        const cv = document.createElement('canvas'); cv.width = 1200; cv.height = 675; cv.style.cssText = 'position:fixed;left:-9999px'; document.body.appendChild(cv);
        const c2 = new Chart(cv, chartConfig(d, false)); c2.options.devicePixelRatio = 1; c2.resize(1200, 675);
        out = cv.toDataURL('image/png'); c2.destroy(); cv.remove();
      },
      onHide: () => { if (ch) ch.destroy(); } });
    if (!out) return;
    const json = JSON.stringify(d);
    if (img) { img.src = out; img.dataset.chart = json; GD.markDirty(); }
    else GD.insertImageData(out, 600, 338, ' data-chart="' + esc(json) + '"');
  }
  GD.chartDialog = chartDialog;

  /* ======================= STIAHNUŤ AKO … ======================= */
  /** Spoločný prechod: odseky (blocks) a ich úseky textu s formátom. */
  function fmtOf(el, f) {
    f = { ...f }; const t = el.tagName, st = el.style || {};
    if (t === 'B' || t === 'STRONG' || +st.fontWeight >= 600 || st.fontWeight === 'bold') f.b = true;
    if (t === 'I' || t === 'EM' || st.fontStyle === 'italic') f.i = true;
    if (t === 'U' || /underline/.test(st.textDecoration || st.textDecorationLine || '')) f.u = true;
    if (t === 'S' || t === 'STRIKE' || (t === 'DEL') || /line-through/.test(st.textDecoration || st.textDecorationLine || '')) f.s = true;
    if (t === 'SUP') f.sup = true; if (t === 'SUB') f.sub = true; if (t === 'CODE') f.code = true;
    if (t === 'INS') f.ins = true;
    const c = GD.IO.hex(st.color); if (c) f.color = c;
    const bg = GD.IO.hex(st.backgroundColor); if (bg && bg !== 'FFFFFF') f.bg = bg;
    const m = /^([\d.]+)(pt|px)$/.exec(st.fontSize || ''); if (m) f.size = m[2] === 'px' ? +m[1] * 0.75 : +m[1];
    if (st.fontFamily) f.font = st.fontFamily.split(',')[0].replace(/["']/g, '').trim();
    if (t === 'A' && el.getAttribute('href')) f.href = el.getAttribute('href');
    return f;
  }
  function runs(el, f, out) {
    out = out || []; f = f || {};
    for (const n of el.childNodes) {
      if (n.nodeType === 3) { const t = n.nodeValue.replace(/\u200b/g, ''); if (t) out.push({ t, f }); continue; }
      if (n.nodeType !== 1) continue;
      if (n.tagName === 'BR') { out.push({ br: true }); continue; }
      if (n.tagName === 'IMG') { out.push({ img: n }); continue; }
      if (n.matches('sup.gd-fn')) { out.push({ fn: n.dataset.fn || '' }); continue; }
      if (n.matches('.gd-fld')) { out.push({ fld: n.dataset.f }); continue; }
      if (n.matches('a.gd-bm, a.gd-anc')) { out.push({ bm: n.id }); continue; }
      if (n.matches('ul,ol,table')) continue;
      runs(n, fmtOf(n, f), out);
    }
    return out;
  }
  /** Bloky: {k: 'p'|'h1'…|'title'|'subtitle'|'quote'|'code'|'li'|'table'|'hr'|'pb'|'toc', el, lvl, ord, num, ck, align, cells} */
  function blocks(root) {
    const out = [];
    const list = (l, lvl) => { let i = 0; for (const li of l.children) { if (li.tagName !== 'LI') continue; i++; out.push({ k: 'li', el: li, lvl, ord: l.tagName === 'OL', num: i, style: l.style.listStyleType || (l.className.match(/ls-(\w+)/) || [])[1], check: l.classList.contains('gd-check'), ck: li.dataset.ck === '1' }); li.querySelectorAll(':scope > ul, :scope > ol').forEach(s => list(s, lvl + 1)); } };
    const walk = (c) => {
      for (const n of c.children) {
        const t = n.tagName;
        if (t === 'UL' || t === 'OL') list(n, 0);
        else if (t === 'TABLE') out.push({ k: 'table', el: n });
        else if (t === 'HR') out.push({ k: n.classList.contains('gd-hr') ? 'hr' : n.classList.contains('col-break') ? 'cb' : 'pb', el: n });
        else if (t === 'DIV' && n.classList.contains('gd-toc')) out.push({ k: 'toc', el: n });
        else if (t === 'DIV') walk(n);
        else if (/^H[1-6]$/.test(t)) out.push({ k: t.toLowerCase(), el: n });
        else if (t === 'BLOCKQUOTE') out.push({ k: 'quote', el: n });
        else if (t === 'PRE') out.push({ k: 'code', el: n });
        else if (t === 'IMG') out.push({ k: 'p', el: n, single: true });
        else out.push({ k: n.classList.contains('gd-title') ? 'title' : n.classList.contains('gd-subtitle') ? 'subtitle' : 'p', el: n });
      }
    };
    walk(root);
    return out;
  }
  const alignOf = (el) => (el.style && el.style.textAlign) || '';
  const imgData = (img) => { const m = /^data:image\/(\w+);base64,(.*)$/.exec(img.getAttribute('src') || ''); return m ? { ext: m[1] === 'jpeg' ? 'jpg' : m[1], b64: m[2], w: +img.getAttribute('width') || img.naturalWidth || 300, h: +img.getAttribute('height') || img.naturalHeight || 200 } : null; };
  const romans = (n) => { const r = [[1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'], [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'], [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i']]; let s = ''; for (const [v, k] of r) while (n >= v) { s += k; n -= v; } return s; };
  const marker = (b) => b.check ? (b.ck ? '☑ ' : '☐ ') : !b.ord ? ({ dash: '– ', arrow: '➢ ', star: '★ ', square: '■ ', circle: '○ ' }[b.style] || '• ') : (b.style === 'lower-alpha' ? String.fromCharCode(96 + b.num) : b.style === 'upper-alpha' ? String.fromCharCode(64 + b.num) : b.style === 'lower-roman' ? romans(b.num) : b.style === 'upper-roman' ? romans(b.num).toUpperCase() : b.num) + '. ';

  /* --- webová stránka --- */
  GD.exporters.html = (root) => {
    const fns = [];
    root.querySelectorAll('sup.gd-fn').forEach((s, i) => { fns.push(s.dataset.fn || ''); s.outerHTML = '<sup><a href="#fn' + (i + 1) + '" id="fnr' + (i + 1) + '">' + (i + 1) + '</a></sup>'; });
    root.querySelectorAll('ul.gd-check > li').forEach(li => li.prepend(li.dataset.ck === '1' ? '☑ ' : '☐ '));
    root.querySelectorAll('.gd-fld').forEach(f => f.replaceWith('1'));
    root.querySelectorAll('table').forEach(t => { const bw = t.dataset.bw !== undefined ? +t.dataset.bw : 1; t.querySelectorAll('td,th').forEach(td => { td.style.border = bw ? bw + 'px solid ' + (t.dataset.bc || '#000') : 'none'; }); });
    const P = S.page, cw = Math.round(((P.landscape ? P.h : P.w) - (P.ml + P.mr) * 10) * 96 / 25.4);
    const css = 'body{font-family:Calibri,Carlito,Arial,sans-serif;font-size:11pt;line-height:1.15;max-width:' + cw + 'px;margin:40px auto;padding:0 16px;color:#000' + (P.color ? ';background:' + P.color : '') + '}p{margin:0 0 8pt}h1{font-size:20pt;font-weight:400}h2{font-size:16pt;font-weight:400}h3{font-size:14pt;font-weight:400;color:#434343}h4{font-size:12pt;font-weight:400;color:#666}h5,h6{font-size:11pt;color:#666}.gd-title{font-size:26pt}.gd-subtitle{font-size:15pt;color:#666}table{border-collapse:collapse;width:100%}td,th{padding:5px 7px;vertical-align:top}img{max-width:100%;height:auto}img.wl{float:left;margin:0 12px 8px 0}img.wr{float:right;margin:0 0 8px 12px}img.wb{display:block}img.wb.c{margin:0 auto}img.wb.r{margin-left:auto}ul.gd-check{list-style:none;padding-left:8px}blockquote{border-left:3px solid #ccc;margin:0 0 8pt;padding-left:12px;color:#555}pre{background:#f1f3f4;padding:8px;font-family:"Courier New",monospace;white-space:pre-wrap}hr.page-break,hr.col-break{border:0;margin:0}ins{color:#188038}del{color:#d93025}.fn{font-size:9pt;border-top:1px solid #ccc;margin-top:24px;padding-top:8px}.gd-toc-e.l2{padding-left:16px}.gd-toc-e.l3{padding-left:32px}.gd-toc-e .pg{float:right}';
    const hf = (h) => h ? '<div class="hf">' + h + '</div>' : '';
    const html = '<!doctype html><html lang="sk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + esc(S.name.replace(/\.docx$/i, '')) + '</title><style>' + css + '</style></head><body>' + hf(GD.S.header.def) + root.innerHTML +
      (fns.length ? '<ol class="fn">' + fns.map((t, i) => '<li id="fn' + (i + 1) + '">' + esc(t) + ' <a href="#fnr' + (i + 1) + '">↩</a></li>').join('') + '</ol>' : '') + hf(GD.S.footer.def) + '</body></html>';
    return new Blob([html.replace(/ contenteditable="(true|false)"/g, '')], { type: 'text/html;charset=utf-8' });
  };

  /* --- Markdown --- */
  GD.exporters.md = (root) => {
    const fns = [];
    const inl = (el) => runs(el).map(r => {
      if (r.br) return '  \n'; if (r.fld) return '1';
      if (r.fn !== undefined) { fns.push(r.fn); return '[^' + fns.length + ']'; }
      if (r.bm !== undefined) return '<a id="' + r.bm + '"></a>';
      if (r.img) return '![' + (r.img.getAttribute('alt') || '') + '](' + r.img.getAttribute('src') + ')';
      let t = r.t.replace(/([\\`*_[\]#<>|])/g, '\\$1'); const f = r.f;
      if (f.code) t = '`' + r.t + '`'; if (f.b) t = '**' + t + '**'; if (f.i) t = '*' + t + '*'; if (f.s) t = '~~' + t + '~~'; if (f.href) t = '[' + t + '](' + f.href + ')';
      return t;
    }).join('').replace(/\*\*\*\*|~~~~/g, '');
    const out = [];
    blocks(root).forEach(b => {
      const k = b.k;
      if (/^h[1-6]$/.test(k)) out.push('#'.repeat(+k[1]) + ' ' + inl(b.el), '');
      else if (k === 'title') out.push('# ' + inl(b.el), ''); else if (k === 'subtitle') out.push('*' + inl(b.el) + '*', '');
      else if (k === 'li') { const c = b.el.cloneNode(true); c.querySelectorAll('ul,ol').forEach(x => x.remove()); out.push('    '.repeat(b.lvl) + (b.check ? '- [' + (b.ck ? 'x' : ' ') + '] ' : b.ord ? b.num + '. ' : '- ') + inl(c)); }
      else if (k === 'table') { const rows = [...b.el.querySelectorAll('tr')].map(tr => [...tr.children].map(td => inl(td).replace(/\n/g, ' ').replace(/\|/g, '\\|'))); if (rows.length) { out.push('', '| ' + rows[0].join(' | ') + ' |', '|' + rows[0].map(() => ' --- ').join('|') + '|'); rows.slice(1).forEach(r => out.push('| ' + r.join(' | ') + ' |')); out.push(''); } }
      else if (k === 'hr') out.push('', '---', ''); else if (k === 'pb' || k === 'cb') out.push('');
      else if (k === 'quote') out.push('> ' + inl(b.el), '');
      else if (k === 'code') out.push('```', b.el.textContent, '```', '');
      else if (k === 'toc') b.el.querySelectorAll('[data-to]').forEach(e => out.push('    '.repeat(+e.dataset.l - 1) + '- [' + e.dataset.text + '](#' + e.dataset.to + ')'));
      else { if (out.length && out[out.length - 1] !== '' && !/^(\s*[-\d])/.test(out[out.length - 1])) {} if (out.length && /^\s*(- |\d+\. )/.test(out[out.length - 1])) out.push(''); out.push(inl(b.el), ''); }
    });
    if (fns.length) out.push('', ...fns.map((t, i) => '[^' + (i + 1) + ']: ' + t));
    return new Blob([out.join('\n').replace(/\n{3,}/g, '\n\n').trim() + '\n'], { type: 'text/markdown;charset=utf-8' });
  };

  /* --- RTF --- */
  GD.exporters.rtf = (root) => {
    const colors = ['000000'], ci = (h) => { if (!h) return 0; let i = colors.indexOf(h); if (i < 0) { colors.push(h); i = colors.length - 1; } return i + 1; };
    const fonts = ['Calibri', 'Courier New'], fi = (f) => { if (!f) return 0; let i = fonts.indexOf(f); if (i < 0) { fonts.push(f); i = fonts.length - 1; } return i; };
    const tx = (s) => { let o = ''; for (const ch of s) { const c = ch.codePointAt(0); if (ch === '\\' || ch === '{' || ch === '}') o += '\\' + ch; else if (ch === '\t' || ch === '\u2003') o += '\\tab '; else if (c > 127) { const u = String.fromCodePoint(c); for (let i = 0; i < u.length; i++) { let v = u.charCodeAt(i); if (v > 32767) v -= 65536; o += '\\u' + v + '?'; } } else o += ch; } return o; };
    const hex = (b64) => { const s = atob(b64); let o = ''; for (let i = 0; i < s.length; i++) o += s.charCodeAt(i).toString(16).padStart(2, '0'); return o; };
    let fnN = 0;
    const inl = (el, base) => runs(el).map(r => {
      if (r.br) return '\\line '; if (r.fld) return r.fld === 'PAGE' ? '{\\field{\\*\\fldinst PAGE}{\\fldrslt 1}}' : '{\\field{\\*\\fldinst NUMPAGES}{\\fldrslt 1}}';
      if (r.bm !== undefined) return '';
      if (r.fn !== undefined) { fnN++; return '{\\super\\chftn}{\\footnote\\pard\\plain\\fs20 {\\super\\chftn} ' + tx(r.fn) + '}'; }
      if (r.img) { const d = imgData(r.img); if (!d || !/png|jpg/.test(d.ext)) return ''; return '{\\pict\\' + (d.ext === 'png' ? 'pngblip' : 'jpegblip') + '\\picwgoal' + Math.round(d.w * 15) + '\\pichgoal' + Math.round(d.h * 15) + ' ' + hex(d.b64) + '}'; }
      const f = r.f; let p = '';
      if (f.b) p += '\\b'; if (f.i) p += '\\i'; if (f.u || f.href) p += '\\ul'; if (f.s) p += '\\strike'; if (f.sup) p += '\\super'; if (f.sub) p += '\\sub';
      if (f.color || f.href) p += '\\cf' + ci(f.color || '1155CC'); if (f.bg) p += '\\chcbpat' + ci(f.bg) + '\\highlight' + ci(f.bg);
      if (f.size) p += '\\fs' + Math.round(f.size * 2); if (f.font || f.code) p += '\\f' + fi(f.code ? 'Courier New' : f.font);
      const body = tx(r.t);
      const run = p ? '{' + p + ' ' + body + '}' : body;
      return f.href ? '{\\field{\\*\\fldinst HYPERLINK "' + tx(f.href) + '"}{\\fldrslt ' + run + '}}' : run;
    }).join('');
    const SZ = { title: 52, subtitle: 30, h1: 40, h2: 32, h3: 28, h4: 24, h5: 22, h6: 22 };
    const pPr = (el, extra) => { const a = { center: '\\qc', right: '\\qr', justify: '\\qj' }[alignOf(el)] || '\\ql'; const ml = parseFloat(el.style && el.style.marginLeft) || 0, ti = parseFloat(el.style && el.style.textIndent) || 0, lh = parseFloat(el.style && el.style.lineHeight) || 1.15; return '\\pard\\plain' + a + (ml ? '\\li' + Math.round(ml * 15) : '') + (ti ? '\\fi' + Math.round(ti * 15) : '') + '\\sa160\\sl' + Math.round(lh * 240) + '\\slmult1' + (extra || '') + ' '; };
    const body = [];
    blocks(root).forEach(b => {
      const k = b.k;
      if (SZ[k]) body.push(pPr(b.el, k.startsWith('h') ? '\\sb240' : '') + '{\\fs' + SZ[k] + (k === 'subtitle' || +k[1] >= 3 ? '\\cf' + ci('666666') : '') + ' ' + inl(b.el) + '}\\par');
      else if (k === 'li') { const c = b.el.cloneNode(true); c.querySelectorAll('ul,ol').forEach(x => x.remove()); body.push('\\pard\\plain\\ql\\li' + (720 * (b.lvl + 1)) + '\\fi-360\\sa60 ' + tx(marker(b)) + '\\tab ' + inl(c) + '\\par'); }
      else if (k === 'table') {
        const bw = b.el.dataset.bw !== undefined ? +b.el.dataset.bw : 1, br = bw ? '\\brdrs\\brdrw' + Math.max(5, bw * 10) : '\\brdrnone';
        b.el.querySelectorAll('tr').forEach(tr => {
          const cells = [...tr.children]; const total = 9000; let x = 0;
          let row = '\\trowd\\trgaph108';
          cells.forEach(td => { const w = Math.round(total * (td.colSpan || 1) / Math.max(1, [...tr.children].reduce((a, c) => a + (c.colSpan || 1), 0))); x += w; const bg = GD.IO.hex(td.style.backgroundColor); row += '\\clbrdrt' + br + '\\clbrdrl' + br + '\\clbrdrb' + br + '\\clbrdrr' + br + (bg ? '\\clcbpat' + ci(bg) : '') + '\\cellx' + x; });
          row += cells.map(td => '\\pard\\intbl ' + (td.querySelector('p,h1,h2,h3,li') ? [...td.querySelectorAll('p,h1,h2,h3,h4,li')].map(p => inl(p)).join('\\line ') : inl(td)) + '\\cell').join('') + '\\row';
          body.push(row);
        });
        body.push('\\pard\\par');
      }
      else if (k === 'hr') body.push('\\pard\\brdrb\\brdrs\\brdrw10\\brsp20 \\par');
      else if (k === 'pb') body.push('\\page'); else if (k === 'cb') body.push('\\column');
      else if (k === 'quote') body.push(pPr(b.el, '\\li720') + '{\\i\\cf' + ci('555555') + ' ' + inl(b.el) + '}\\par');
      else if (k === 'code') b.el.textContent.split('\n').forEach(l => body.push('\\pard\\plain\\ql\\sa0{\\f' + fi('Courier New') + '\\fs20 ' + tx(l) + '}\\par'));
      else if (k === 'toc') b.el.querySelectorAll('[data-to]').forEach(e => body.push('\\pard\\plain\\ql\\li' + (360 * (+e.dataset.l - 1)) + '\\tqr\\tldot\\tx9000 ' + tx(e.dataset.text) + '\\tab ' + tx(e.dataset.pg || '') + '\\par'));
      else body.push(pPr(b.el) + inl(b.el) + '\\par');
    });
    const P = S.page, tw = (mm) => Math.round(mm * 56.693), tc = (cm) => Math.round(cm * 567);
    const hdr = (h, kind) => { if (!h) return ''; const d = document.createElement('div'); d.innerHTML = h; return '{\\' + kind + ' ' + blocks(d).map(b => pPr(b.el) + inl(b.el) + '\\par').join('') + '}'; };
    const head = '{\\rtf1\\ansi\\ansicpg1250\\deff0\\uc1{\\fonttbl' + fonts.map((f, i) => '{\\f' + i + ' ' + f + ';}').join('') + '}{\\colortbl;' + colors.map(c => '\\red' + parseInt(c.slice(0, 2), 16) + '\\green' + parseInt(c.slice(2, 4), 16) + '\\blue' + parseInt(c.slice(4, 6), 16) + ';').join('') + '}' +
      '\\paperw' + tw(P.landscape ? P.h : P.w) + '\\paperh' + tw(P.landscape ? P.w : P.h) + (P.landscape ? '\\landscape' : '') + '\\margl' + tc(P.ml) + '\\margr' + tc(P.mr) + '\\margt' + tc(P.mt) + '\\margb' + tc(P.mb) + (P.cols > 1 ? '\\cols' + P.cols : '') + '\\fs22\n' +
      hdr(S.header.def, 'header') + hdr(S.footer.def, 'footer');
    // farby a písma sa zbierajú počas prechodu → hlavičku skladáme až na konci
    const doc = body.join('\n');
    const head2 = '{\\rtf1\\ansi\\ansicpg1250\\deff0\\uc1{\\fonttbl' + fonts.map((f, i) => '{\\f' + i + ' ' + f + ';}').join('') + '}{\\colortbl;' + colors.map(c => '\\red' + parseInt(c.slice(0, 2), 16) + '\\green' + parseInt(c.slice(2, 4), 16) + '\\blue' + parseInt(c.slice(4, 6), 16) + ';').join('') + '}' + head.slice(head.indexOf('\\paperw'));
    return new Blob([head2 + doc + '\n}'], { type: 'application/rtf' });
  };

  /* --- OpenDocument (.odt) --- */
  GD.exporters.odt = async (root) => {
    const X = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const zip = new JSZip(); zip.file('mimetype', 'application/vnd.oasis.opendocument.text', { compression: 'STORE' });
    const auto = [], tStyles = new Map(), pStyles = new Map(), pics = [];
    let fnN = 0, tblN = 0;
    const tStyle = (f) => {
      const p = [];
      if (f.b) p.push('fo:font-weight="bold"'); if (f.i) p.push('fo:font-style="italic"'); if (f.u || f.href) p.push('style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"');
      if (f.s) p.push('style:text-line-through-style="solid"'); if (f.sup) p.push('style:text-position="super 58%"'); if (f.sub) p.push('style:text-position="sub 58%"');
      if (f.color || f.href) p.push('fo:color="#' + (f.color || '1155CC') + '"'); if (f.bg) p.push('fo:background-color="#' + f.bg + '"'); if (f.size) p.push('fo:font-size="' + f.size + 'pt"');
      if (f.font || f.code) p.push('style:font-name="' + X(f.code ? 'Courier New' : f.font) + '" fo:font-family="' + X(f.code ? 'Courier New' : f.font) + '"');
      if (!p.length) return null; const k = p.join(' '); if (!tStyles.has(k)) { const n = 'T' + (tStyles.size + 1); tStyles.set(k, n); auto.push('<style:style style:name="' + n + '" style:family="text"><style:text-properties ' + k + '/></style:style>'); } return tStyles.get(k);
    };
    const pStyle = (el, parent, extra) => {
      const p = [], st = el.style || {};
      const a = { center: 'center', right: 'end', justify: 'justify' }[alignOf(el)]; if (a) p.push('fo:text-align="' + a + '"');
      const ml = parseFloat(st.marginLeft) || 0, ti = parseFloat(st.textIndent) || 0, lh = parseFloat(st.lineHeight) || 0;
      if (ml) p.push('fo:margin-left="' + (ml / 96 * 2.54).toFixed(2) + 'cm"'); if (ti) p.push('fo:text-indent="' + (ti / 96 * 2.54).toFixed(2) + 'cm"'); if (lh) p.push('fo:line-height="' + Math.round(lh * 100) + '%"');
      if (extra) p.push(extra);
      if (!p.length) return parent; const k = parent + '|' + p.join(' '); if (!pStyles.has(k)) { const n = 'P' + (pStyles.size + 1); pStyles.set(k, n); auto.push('<style:style style:name="' + n + '" style:family="paragraph" style:parent-style-name="' + parent + '"><style:paragraph-properties ' + p.join(' ') + '/></style:style>'); } return pStyles.get(k);
    };
    const inl = (el) => runs(el).map(r => {
      if (r.br) return '<text:line-break/>'; if (r.fld) return r.fld === 'PAGE' ? '<text:page-number text:select-page="current">1</text:page-number>' : '<text:page-count>1</text:page-count>';
      if (r.bm !== undefined) return '<text:bookmark text:name="' + X(r.bm) + '"/>';
      if (r.fn !== undefined) { fnN++; return '<text:note text:id="ftn' + fnN + '" text:note-class="footnote"><text:note-citation>' + fnN + '</text:note-citation><text:note-body><text:p text:style-name="Footnote">' + X(r.fn) + '</text:p></text:note-body></text:note>'; }
      if (r.img) { const d = imgData(r.img); if (!d) return ''; const nm = 'Pictures/img' + (pics.length + 1) + '.' + d.ext; pics.push([nm, d.b64, d.ext]); const wl = r.img.classList.contains('wl'), wr = r.img.classList.contains('wr'); return '<draw:frame draw:name="img' + pics.length + '" text:anchor-type="' + (wl || wr ? 'paragraph' : 'as-char') + '"' + (wl || wr ? ' draw:style-name="' + (wl ? 'frL' : 'frR') + '"' : '') + ' svg:width="' + (d.w / 96 * 2.54).toFixed(2) + 'cm" svg:height="' + (d.h / 96 * 2.54).toFixed(2) + 'cm"><draw:image xlink:href="' + nm + '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame>'; }
      const n = tStyle(r.f), t = X(r.t).replace(/\t|\u2003/g, '<text:tab/>').replace(/ {2,}/g, (m) => ' <text:s text:c="' + (m.length - 1) + '"/>');
      const span = n ? '<text:span text:style-name="' + n + '">' + t + '</text:span>' : t;
      return r.f.href ? '<text:a xlink:type="simple" xlink:href="' + X(r.f.href) + '">' + span + '</text:a>' : span;
    }).join('');
    const NAMES = { title: 'Title', subtitle: 'Subtitle', quote: 'Quotations', code: 'Preformatted_20_Text', p: 'Standard' };
    const content = (bl) => {
      let h = '', open = [];
      const closeLists = (lvl) => { while (open.length > lvl) { h += '</text:list-item></text:list>'; open.pop(); } };
      bl.forEach(b => {
        const k = b.k;
        if (k !== 'li') closeLists(0);
        if (/^h[1-6]$/.test(k)) h += '<text:h text:style-name="' + pStyle(b.el, 'Heading_20_' + k[1]) + '" text:outline-level="' + k[1] + '">' + inl(b.el) + '</text:h>';
        else if (k === 'li') {
          const c = b.el.cloneNode(true); c.querySelectorAll('ul,ol').forEach(x => x.remove());
          const ls = b.check ? 'LC' : b.ord ? 'LN' : 'LB';
          if (open.length > b.lvl + 1) closeLists(b.lvl + 1);
          if (open.length === b.lvl + 1 && open[b.lvl] === ls) h += '</text:list-item><text:list-item>';
          else { if (open.length === b.lvl + 1) closeLists(b.lvl); while (open.length < b.lvl + 1) { h += '<text:list' + (open.length ? '' : ' text:style-name="' + ls + '"') + '><text:list-item>'; open.push(ls); } }
          h += '<text:p text:style-name="Standard">' + (b.check ? (b.ck ? '☑ ' : '☐ ') : '') + inl(c) + '</text:p>';
        }
        else if (k === 'table') {
          tblN++; const rows = [...b.el.querySelectorAll('tr')]; const cols = Math.max(...rows.map(tr => [...tr.children].reduce((a, c) => a + (c.colSpan || 1), 0)));
          const bw = b.el.dataset.bw !== undefined ? +b.el.dataset.bw : 1;
          auto.push('<style:style style:name="Tc' + tblN + '" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="' + (bw ? (bw * 0.75) + 'pt solid ' + (b.el.dataset.bc || '#000000') : 'none') + '"/></style:style>');
          h += '<table:table table:name="Tabuľka' + tblN + '"><table:table-column table:number-columns-repeated="' + cols + '"/>' + rows.map(tr => '<table:table-row>' + [...tr.children].map(td => {
            const inner = td.querySelector('p,h1,h2,h3,ul,ol') ? content(blocks(td)) : '<text:p>' + inl(td) + '</text:p>';
            const cs = td.colSpan || 1;
            return '<table:table-cell table:style-name="Tc' + tblN + '" office:value-type="string"' + (cs > 1 ? ' table:number-columns-spanned="' + cs + '"' : '') + ((td.rowSpan || 1) > 1 ? ' table:number-rows-spanned="' + td.rowSpan + '"' : '') + '>' + inner + '</table:table-cell>' + '<table:covered-table-cell/>'.repeat(cs - 1);
          }).join('') + '</table:table-row>').join('') + '</table:table>';
        }
        else if (k === 'hr') h += '<text:p text:style-name="HR"/>';
        else if (k === 'pb') h += '<text:p text:style-name="PB"/>';
        else if (k === 'cb') h += '<text:p text:style-name="CB"/>';
        else if (k === 'code') b.el.textContent.split('\n').forEach(l => { h += '<text:p text:style-name="Preformatted_20_Text">' + X(l).replace(/ {2,}/g, (m) => ' <text:s text:c="' + (m.length - 1) + '"/>') + '</text:p>'; });
        else if (k === 'toc') b.el.querySelectorAll('[data-to]').forEach(e => { h += '<text:p text:style-name="' + pStyle({ style: { marginLeft: (24 * (+e.dataset.l - 1)) + 'px' } }, 'TOC') + '"><text:a xlink:type="simple" xlink:href="#' + X(e.dataset.to) + '">' + X(e.dataset.text) + '</text:a><text:tab/>' + X(e.dataset.pg || '') + '</text:p>'; });
        else h += '<text:p text:style-name="' + pStyle(b.el, NAMES[k] || 'Standard') + '">' + inl(b.el) + '</text:p>';
      });
      closeLists(0);
      return h;
    };
    const body = content(blocks(root));
    const hf = (html) => { if (!html) return ''; const d = document.createElement('div'); d.innerHTML = html; return content(blocks(d)); };
    const hdr = hf(S.header.def), ftr = hf(S.footer.def);
    const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.3"';
    const lists = '<text:list-style style:name="LB">' + [0, 1, 2, 3, 4].map(i => '<text:list-level-style-bullet text:level="' + (i + 1) + '" text:bullet-char="' + ['●', '○', '■'][i % 3] + '"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.635cm" fo:margin-left="' + (1.27 * (i + 1)).toFixed(2) + 'cm"/></style:list-level-properties></text:list-level-style-bullet>').join('') + '</text:list-style>' +
      '<text:list-style style:name="LN">' + [0, 1, 2, 3, 4].map(i => '<text:list-level-style-number text:level="' + (i + 1) + '" style:num-suffix="." style:num-format="' + ['1', 'a', 'i'][i % 3] + '"><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="listtab" fo:text-indent="-0.635cm" fo:margin-left="' + (1.27 * (i + 1)).toFixed(2) + 'cm"/></style:list-level-properties></text:list-level-style-number>').join('') + '</text:list-style>' +
      '<text:list-style style:name="LC"><text:list-level-style-bullet text:level="1" text:bullet-char=" "><style:list-level-properties text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment text:label-followed-by="nothing" fo:text-indent="0cm" fo:margin-left="0.3cm"/></style:list-level-properties></text:list-level-style-bullet></text:list-style>';
    const contentXml = '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' + NS + '><office:automatic-styles>' + auto.join('') +
      '<style:style style:name="PB" style:family="paragraph"><style:paragraph-properties fo:break-before="page"/></style:style><style:style style:name="CB" style:family="paragraph"><style:paragraph-properties fo:break-before="column"/></style:style>' +
      '<style:style style:name="HR" style:family="paragraph"><style:paragraph-properties fo:border-bottom="0.5pt solid #000000" fo:padding="0.05cm"/></style:style>' +
      '<style:style style:name="frL" style:family="graphic"><style:graphic-properties style:wrap="right" style:horizontal-pos="left" style:horizontal-rel="paragraph"/></style:style><style:style style:name="frR" style:family="graphic"><style:graphic-properties style:wrap="left" style:horizontal-pos="right" style:horizontal-rel="paragraph"/></style:style>' +
      lists + '</office:automatic-styles><office:body><office:text>' + body + '</office:text></office:body></office:document-content>';
    const P = S.page, W = P.landscape ? P.h : P.w, H = P.landscape ? P.w : P.h;
    const ps = (n, size, extra, col, para) => '<style:style style:name="' + n + '" style:display-name="' + n.replace(/_20_/g, ' ') + '" style:family="paragraph" style:parent-style-name="Standard"' + (n.startsWith('Heading') ? ' style:default-outline-level="' + n.slice(-1) + '"' : '') + '><style:paragraph-properties fo:margin-top="' + (para || '0.2cm') + '" fo:margin-bottom="0.14cm"/><style:text-properties fo:font-size="' + size + 'pt"' + (col ? ' fo:color="' + col + '"' : '') + (extra || '') + '/></style:style>';
    const stylesXml = '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' + NS + '><office:font-face-decls><style:font-face style:name="Calibri" svg:font-family="Calibri"/><style:font-face style:name="Courier New" svg:font-family="\'Courier New\'"/></office:font-face-decls><office:styles>' +
      '<style:default-style style:family="paragraph"><style:paragraph-properties fo:line-height="115%"/><style:text-properties style:font-name="Calibri" fo:font-size="11pt" fo:language="sk" fo:country="SK"/></style:default-style>' +
      '<style:style style:name="Standard" style:family="paragraph"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.28cm"/></style:style>' +
      ps('Title', 26) + ps('Subtitle', 15, '', '#666666') + ps('Heading_20_1', 20, '', '', '0.7cm') + ps('Heading_20_2', 16, '', '', '0.63cm') + ps('Heading_20_3', 14, '', '#434343', '0.56cm') + ps('Heading_20_4', 12, '', '#666666', '0.49cm') + ps('Heading_20_5', 11, '', '#666666') + ps('Heading_20_6', 11, ' fo:font-style="italic"', '#666666') +
      ps('Quotations', 11, ' fo:font-style="italic"', '#555555') + ps('Preformatted_20_Text', 10, ' style:font-name="Courier New"') + ps('Footnote', 9) + ps('TOC', 11) +
      '</office:styles><office:automatic-styles><style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="' + W + 'mm" fo:page-height="' + H + 'mm" style:print-orientation="' + (P.landscape ? 'landscape' : 'portrait') + '" fo:margin-top="' + Math.max(0.2, P.mt - (hdr ? P.mt - P.hd : 0)).toFixed(2) + 'cm" fo:margin-bottom="' + Math.max(0.2, P.mb - (ftr ? P.mb - P.fd : 0)).toFixed(2) + 'cm" fo:margin-left="' + P.ml + 'cm" fo:margin-right="' + P.mr + 'cm"' + (P.color ? ' fo:background-color="' + P.color + '"' : '') + '>' + (P.cols > 1 ? '<style:columns fo:column-count="' + P.cols + '" fo:column-gap="1.27cm"/>' : '') + '</style:page-layout-properties>' +
      (hdr ? '<style:header-style><style:header-footer-properties fo:min-height="0cm" fo:margin-bottom="' + Math.max(0.1, P.mt - P.hd - 0.5).toFixed(2) + 'cm"/></style:header-style>' : '') + (ftr ? '<style:footer-style><style:header-footer-properties fo:min-height="0cm" fo:margin-top="' + Math.max(0.1, P.mb - P.fd - 0.5).toFixed(2) + 'cm"/></style:footer-style>' : '') + '</style:page-layout></office:automatic-styles>' +
      '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1">' + (hdr ? '<style:header>' + hdr + '</style:header>' : '') + (ftr ? '<style:footer>' + ftr + '</style:footer>' : '') + '</style:master-page></office:master-styles></office:document-styles>';
    zip.file('content.xml', contentXml); zip.file('styles.xml', stylesXml);
    zip.file('meta.xml', '<?xml version="1.0" encoding="UTF-8"?><office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" office:version="1.3"><office:meta><meta:generator>Cloud</meta:generator><dc:title>' + X(S.name.replace(/\.docx$/i, '')) + '</dc:title><dc:language>sk-SK</dc:language></office:meta></office:document-meta>');
    pics.forEach(([nm, b64]) => zip.file(nm, b64, { base64: true }));
    zip.file('META-INF/manifest.xml', '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3"><manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>' +
      pics.map(([nm, , ext]) => '<manifest:file-entry manifest:full-path="' + nm + '" manifest:media-type="image/' + (ext === 'jpg' ? 'jpeg' : ext) + '"/>').join('') + '</manifest:manifest>');
    return zip.generateAsync({ type: 'blob', mimeType: 'application/vnd.oasis.opendocument.text', compression: 'DEFLATE' });
  };

  /* --- EPUB --- */
  GD.exporters.epub = async (root) => {
    const zip = new JSZip(); zip.file('mimetype', 'application/epub+zip', { compression: 'STORE' });
    const title = S.name.replace(/\.docx$/i, ''), id = 'urn:uuid:' + (crypto.randomUUID ? crypto.randomUUID() : Date.now());
    const imgs = [];
    root.querySelectorAll('img').forEach((im, i) => { const d = imgData(im); if (!d) { im.remove(); return; } const nm = 'img' + (i + 1) + '.' + d.ext; imgs.push([nm, d]); im.removeAttribute('src'); im.setAttribute('data-epsrc', nm); [...im.attributes].forEach(a => { if (/^data-/.test(a.name) && a.name !== 'data-epsrc') im.removeAttribute(a.name); }); });
    const fns = []; root.querySelectorAll('sup.gd-fn').forEach((s, i) => { fns.push(s.dataset.fn || ''); const a = document.createElement('sup'); a.innerHTML = '<a href="#fn' + (i + 1) + '" id="fnr' + (i + 1) + '">' + (i + 1) + '</a>'; s.replaceWith(a); });
    root.querySelectorAll('[contenteditable]').forEach(x => x.removeAttribute('contenteditable'));
    root.querySelectorAll('.gd-fld').forEach(x => x.replaceWith(''));
    root.querySelectorAll('ul.gd-check > li').forEach(li => li.prepend(li.dataset.ck === '1' ? '☑ ' : '☐ '));
    root.querySelectorAll('[data-ck],[data-c],[data-s],[data-a],[data-t],[data-fn],[data-bw],[data-bc],[data-hdr],[data-toc],[data-to],[data-l],[data-text],[data-pg]').forEach(el => [...el.attributes].forEach(a => { if (/^data-/.test(a.name)) el.removeAttribute(a.name); }));
    const hs = [...root.querySelectorAll('h1,h2,h3')];
    hs.forEach((h, i) => { if (!h.id) h.id = 'h' + i; });
    const ser = new XMLSerializer();
    const xhtml = (t, b) => '<?xml version="1.0" encoding="UTF-8"?>\n<!DOCTYPE html>\n<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="sk" lang="sk"><head><meta charset="utf-8"/><title>' + esc(t) + '</title><link rel="stylesheet" href="style.css"/></head><body>' + b + '</body></html>';
    const docBody = [...root.childNodes].map(n => ser.serializeToString(n)).join('').replace(/ xmlns="http:\/\/www\.w3\.org\/1999\/xhtml"/g, '').replace(/data-epsrc=/g, 'src=') + (fns.length ? '<section epub:type="footnotes"><ol>' + fns.map((t, i) => '<li id="fn' + (i + 1) + '">' + esc(t) + ' <a href="#fnr' + (i + 1) + '">↩</a></li>').join('') + '</ol></section>' : '');
    zip.file('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
    zip.file('OEBPS/style.css', 'body{font-family:serif;line-height:1.4}h1,h2,h3{font-family:sans-serif;font-weight:500}.gd-title{font-size:2em}.gd-subtitle{font-size:1.3em;color:#666}img{max-width:100%;height:auto}table{border-collapse:collapse}td,th{border:1px solid #888;padding:4px}blockquote{border-left:3px solid #ccc;padding-left:1em;color:#555}hr.page-break{border:0;page-break-after:always}ul.gd-check{list-style:none}.gd-toc-e .pg{display:none}');
    zip.file('OEBPS/text.xhtml', xhtml(title, docBody));
    zip.file('OEBPS/nav.xhtml', xhtml('Obsah', '<nav epub:type="toc" id="toc"><h1>Obsah</h1><ol>' + (hs.length ? hs.map(h => '<li><a href="text.xhtml#' + h.id + '">' + esc(h.textContent.trim() || '…') + '</a></li>').join('') : '<li><a href="text.xhtml">' + esc(title) + '</a></li>') + '</ol></nav>'));
    imgs.forEach(([nm, d]) => zip.file('OEBPS/' + nm, d.b64, { base64: true }));
    const mt = (e) => 'image/' + (e === 'jpg' ? 'jpeg' : e);
    zip.file('OEBPS/content.opf', '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bid" xml:lang="sk"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="bid">' + id + '</dc:identifier><dc:title>' + esc(title) + '</dc:title><dc:language>sk</dc:language><dc:creator>' + esc(C.user || 'Cloud') + '</dc:creator><meta property="dcterms:modified">' + new Date().toISOString().replace(/\.\d+Z$/, 'Z') + '</meta></metadata>' +
      '<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/><item id="text" href="text.xhtml" media-type="application/xhtml+xml"/><item id="css" href="style.css" media-type="text/css"/>' + imgs.map(([nm, d], i) => '<item id="i' + i + '" href="' + nm + '" media-type="' + mt(d.ext) + '"/>').join('') + '</manifest><spine><itemref idref="text"/></spine></package>');
    return zip.generateAsync({ type: 'blob', mimeType: 'application/epub+zip', compression: 'DEFLATE' });
  };

  Object.assign(GD.act, { special: specialChars, drawing: () => drawDialog(null), chart: () => chartDialog(null) });
  GD.boot();
})();
