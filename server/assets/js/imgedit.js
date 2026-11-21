/* Editor obrázkov v Cloude: orezanie (aj s pomerom strán), otočenie, preklopenie, vyrovnanie, jas, kontrast,
   sýtosť, teplota, filtre a zmena veľkosti. Beží v prehliadači (canvas), ukladá ako novú verziu alebo kópiu. */
(function () {
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const RATIOS = [['free', 'Voľne'], ['orig', 'Pôvodný'], [1, '1:1'], [4 / 3, '4:3'], [3 / 4, '3:4'], [16 / 9, '16:9'], [9 / 16, '9:16'], [3 / 2, '3:2']];
  const FILTERS = [
    ['none', 'Žiadny', {}], ['bw', 'Čiernobiely', { saturation: -100, contrast: 10 }], ['sepia', 'Sépia', { sepia: 80 }],
    ['warm', 'Teplý', { warmth: 35, saturation: 10 }], ['cool', 'Studený', { warmth: -35 }], ['vivid', 'Živý', { saturation: 45, contrast: 15 }],
    ['fade', 'Vybledlý', { contrast: -25, brightness: 8, saturation: -20 }], ['vintage', 'Vintage', { sepia: 35, contrast: -10, brightness: 5, saturation: -15 }],
    ['drama', 'Dráma', { contrast: 40, brightness: -8, saturation: -10 }],
  ];
  const ADJ = [['brightness', 'Jas', 'brightness-high'], ['contrast', 'Kontrast', 'circle-half'], ['saturation', 'Sýtosť', 'droplet-half'], ['warmth', 'Teplota', 'thermometer-half']];

  const TPL = '<div class="modal fade" id="imgEditModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-fullscreen"><div class="modal-content ie-wrap">'
    + '<div class="ie-top"><button class="btn btn-icon text-white" data-ie="close" title="Zavrieť"><i class="bi bi-x-lg"></i></button><span class="ie-name text-truncate"></span><span class="ms-auto"></span>'
    + '<button class="btn btn-sm btn-outline-light rounded-pill px-3" data-ie="reset"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-sm-inline">Pôvodný</span></button>'
    + '<div class="btn-group"><button class="btn btn-sm btn-primary rounded-start-pill px-3" data-ie="save"><i class="bi bi-cloud-check me-1"></i>Uložiť</button>'
    + '<button class="btn btn-sm btn-primary rounded-end-pill dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="Ďalšie možnosti"></button>'
    + '<ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" data-ie="save"><i class="bi bi-cloud-check me-2"></i>Uložiť (pôvodný ostane vo Verziách)</button></li><li><button class="dropdown-item" data-ie="copy"><i class="bi bi-files me-2"></i>Uložiť ako kópiu</button></li><li><button class="dropdown-item" data-ie="download"><i class="bi bi-download me-2"></i>Stiahnuť upravený</button></li></ul></div></div>'
    + '<div class="ie-stage"><canvas class="ie-canvas"></canvas><div class="ie-crop d-none"><div class="ie-box">' + ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map(h => '<span class="ie-h ' + h + '" data-h="' + h + '"></span>').join('') + '<i class="ie-grid"></i></div></div><div class="ie-spin spinner-border text-light"></div><div class="ie-info"></div></div>'
    + '<div class="ie-panel">'
    + '<div class="ie-sub" data-p="crop">'
    + '<div class="ie-chips">' + RATIOS.map(([v, l]) => '<button class="ie-chip" data-ratio="' + v + '">' + l + '</button>').join('') + '</div>'
    + '<div class="ie-row"><button class="ie-b" data-ie="rotl" title="Otočiť doľava"><i class="bi bi-arrow-counterclockwise"></i><small>Doľava</small></button><button class="ie-b" data-ie="rotr" title="Otočiť doprava"><i class="bi bi-arrow-clockwise"></i><small>Doprava</small></button>'
    + '<button class="ie-b" data-ie="fliph" title="Preklopiť vodorovne"><i class="bi bi-symmetry-vertical"></i><small>Zrkadliť</small></button><button class="ie-b" data-ie="flipv" title="Preklopiť zvisle"><i class="bi bi-symmetry-horizontal"></i><small>Hore-dole</small></button>'
    + '<label class="ie-slider"><span><i class="bi bi-rulers me-1"></i>Vyrovnať <b data-val="angle">0°</b></span><input type="range" min="-45" max="45" step="0.5" value="0" data-adj="angle"></label></div></div>'
    + '<div class="ie-sub d-none" data-p="adjust"><div class="ie-sliders">' + ADJ.map(([k, l, ic]) => '<label class="ie-slider"><span><i class="bi bi-' + ic + ' me-1"></i>' + l + ' <b data-val="' + k + '">0</b></span><input type="range" min="-100" max="100" value="0" data-adj="' + k + '"></label>').join('') + '</div></div>'
    + '<div class="ie-sub d-none" data-p="filter"><div class="ie-filters">' + FILTERS.map(([k, l]) => '<button class="ie-filter" data-filter="' + k + '"><canvas width="72" height="72"></canvas><span>' + l + '</span></button>').join('') + '</div></div>'
    + '<div class="ie-sub d-none" data-p="size"><div class="ie-size"><label>Šírka<input type="number" min="1" class="form-control form-control-sm" data-size="w"></label><span class="ie-x">×</span><label>Výška<input type="number" min="1" class="form-control form-control-sm" data-size="h"></label>'
    + '<div class="ie-chips">' + [100, 75, 50, 25].map(p => '<button class="ie-chip" data-pct="' + p + '">' + p + ' %</button>').join('') + '<button class="ie-chip" data-max="1920">Full HD</button><button class="ie-chip" data-max="1080">Na web</button></div></div>'
    + '<div class="ie-row small text-white-50 ie-qual"><span>Kvalita JPEG</span><input type="range" min="50" max="100" value="90" data-q><b data-val="q">90 %</b></div></div>'
    + '<div class="ie-tabs"><button class="ie-tab active" data-tab="crop"><i class="bi bi-crop"></i><span>Orezať</span></button><button class="ie-tab" data-tab="adjust"><i class="bi bi-sliders"></i><span>Úpravy</span></button><button class="ie-tab" data-tab="filter"><i class="bi bi-stars"></i><span>Filtre</span></button><button class="ie-tab" data-tab="size"><i class="bi bi-aspect-ratio"></i><span>Veľkosť</span></button></div>'
    + '</div></div></div></div>';

  let E = null; // stav

  const fresh = () => ({ rot: 0, flipH: false, flipV: false, angle: 0, brightness: 0, contrast: 0, saturation: 0, warmth: 0, sepia: 0, filter: 'none', crop: null, ratio: 'free', outW: 0, quality: 90 });
  function filterCss(s) {
    const f = [];
    if (s.brightness) f.push('brightness(' + (100 + s.brightness) + '%)');
    if (s.contrast) f.push('contrast(' + (100 + s.contrast) + '%)');
    if (s.saturation) f.push('saturate(' + (100 + s.saturation) + '%)');
    if (s.sepia) f.push('sepia(' + s.sepia + '%)');
    if (s.warmth > 0) f.push('sepia(' + (s.warmth * 0.35) + '%) saturate(' + (100 + s.warmth * 0.4) + '%)');
    if (s.warmth < 0) f.push('hue-rotate(' + (s.warmth * -0.18) + 'deg) saturate(' + (100 + s.warmth * 0.2) + '%)');
    return f.join(' ') || 'none';
  }
  const effective = () => { const p = (FILTERS.find(x => x[0] === E.s.filter) || FILTERS[0])[2]; const s = { ...E.s }; for (const k in p) s[k] = (s[k] || 0) + p[k]; return s; };

  /** Obrázok po otočení / preklopení / vyrovnaní (bez orezania) v mierke scale. */
  function oriented(scale) {
    const s = E.s, iw = E.img.naturalWidth, ih = E.img.naturalHeight;
    const quarter = (s.rot / 90) % 2 !== 0;
    const W = Math.round((quarter ? ih : iw) * scale), H = Math.round((quarter ? iw : ih) * scale);
    const c = document.createElement('canvas'); c.width = W; c.height = H;
    const x = c.getContext('2d');
    x.imageSmoothingQuality = 'high';
    x.translate(W / 2, H / 2);
    const a = s.angle * Math.PI / 180;
    if (a) { // vyrovnanie: otočiť a zväčšiť, aby v rohoch nebolo prázdno
      const cover = Math.abs(Math.cos(a)) + Math.abs(Math.sin(a)) * Math.max(W / H, H / W);
      x.rotate(a); x.scale(cover, cover);
    }
    x.rotate(s.rot * Math.PI / 180);
    x.scale(s.flipH ? -1 : 1, s.flipV ? -1 : 1);
    const dw = iw * scale, dh = ih * scale;
    x.drawImage(E.img, -dw / 2, -dh / 2, dw, dh);
    return c;
  }
  /** Výsledný obrázok (s orezaním, úpravami a veľkosťou). */
  function render(scale, forPreview) {
    const o = oriented(scale), s = E.s;
    const cr = !forPreview && s.crop ? s.crop : { x: 0, y: 0, w: 1, h: 1 };
    const sx = cr.x * o.width, sy = cr.y * o.height, sw = Math.max(1, cr.w * o.width), sh = Math.max(1, cr.h * o.height);
    let ow = Math.round(sw), oh = Math.round(sh);
    if (!forPreview && s.outW && s.outW < ow) { oh = Math.round(oh * s.outW / ow); ow = s.outW; }
    const c = document.createElement('canvas'); c.width = ow; c.height = oh;
    const x = c.getContext('2d');
    x.imageSmoothingQuality = 'high';
    x.filter = filterCss(effective());
    x.drawImage(o, sx, sy, sw, sh, 0, 0, ow, oh);
    return c;
  }
  /** Rozmery výsledku v plnej veľkosti. */
  function outSize() {
    const quarter = (E.s.rot / 90) % 2 !== 0, iw = E.img.naturalWidth, ih = E.img.naturalHeight;
    const W = quarter ? ih : iw, H = quarter ? iw : ih, cr = E.s.crop || { w: 1, h: 1 };
    let w = Math.round(W * cr.w), h = Math.round(H * cr.h);
    if (E.s.outW && E.s.outW < w) { h = Math.round(h * E.s.outW / w); w = E.s.outW; }
    return [w, h];
  }

  /* ---------- náhľad ---------- */
  function draw() {
    const M = E.M, stage = M.querySelector('.ie-stage'), cv = M.querySelector('.ie-canvas');
    const maxW = stage.clientWidth - 32, maxH = stage.clientHeight - 32;
    const quarter = (E.s.rot / 90) % 2 !== 0, iw = E.img.naturalWidth, ih = E.img.naturalHeight;
    const W = quarter ? ih : iw, H = quarter ? iw : ih;
    const cropMode = E.tab === 'crop';
    const cr = cropMode || !E.s.crop ? { x: 0, y: 0, w: 1, h: 1 } : E.s.crop;
    const scale = Math.min(maxW / (W * cr.w), maxH / (H * cr.h), 1) * (window.devicePixelRatio > 1 ? Math.min(2, devicePixelRatio) : 1);
    const prev = cropMode ? render(Math.min(scale, 1), true) : (() => { const keep = E.s.outW; E.s.outW = 0; const c = render(Math.min(scale, 1), false); E.s.outW = keep; return c; })();
    cv.width = prev.width; cv.height = prev.height;
    cv.getContext('2d').drawImage(prev, 0, 0);
    const dpr = window.devicePixelRatio > 1 ? Math.min(2, devicePixelRatio) : 1;
    cv.style.width = prev.width / dpr + 'px'; cv.style.height = prev.height / dpr + 'px';
    M.querySelector('.ie-crop').classList.toggle('d-none', !cropMode);
    if (cropMode) placeCrop();
    const [ow, oh] = outSize();
    M.querySelector('.ie-info').textContent = ow + ' × ' + oh + ' px';
    if (E.tab === 'size') { M.querySelector('[data-size="w"]').value = ow; M.querySelector('[data-size="h"]').value = oh; }
  }
  function placeCrop() {
    const M = E.M, cv = M.querySelector('.ie-canvas'), box = M.querySelector('.ie-box'), wrap = M.querySelector('.ie-crop');
    const r = cv.getBoundingClientRect(), sr = M.querySelector('.ie-stage').getBoundingClientRect();
    wrap.style.left = (r.left - sr.left) + 'px'; wrap.style.top = (r.top - sr.top) + 'px'; wrap.style.width = r.width + 'px'; wrap.style.height = r.height + 'px';
    const c = E.s.crop || { x: 0, y: 0, w: 1, h: 1 };
    box.style.left = c.x * 100 + '%'; box.style.top = c.y * 100 + '%'; box.style.width = c.w * 100 + '%'; box.style.height = c.h * 100 + '%';
  }
  function applyRatio() {
    const quarter = (E.s.rot / 90) % 2 !== 0, iw = E.img.naturalWidth, ih = E.img.naturalHeight;
    const W = quarter ? ih : iw, H = quarter ? iw : ih;
    let r = E.s.ratio;
    if (r === 'free') return;
    if (r === 'orig') r = iw / ih;
    r = +r;
    // najväčší výrez so zvoleným pomerom, na stred
    let w = W, h = W / r; if (h > H) { h = H; w = H * r; }
    E.s.crop = { x: (W - w) / 2 / W, y: (H - h) / 2 / H, w: w / W, h: h / H };
  }
  function filterThumbs() {
    const M = E.M, t = document.createElement('canvas'), sz = 72;
    const iw = E.img.naturalWidth, ih = E.img.naturalHeight, m = Math.min(iw, ih);
    t.width = sz; t.height = sz; t.getContext('2d').drawImage(E.img, (iw - m) / 2, (ih - m) / 2, m, m, 0, 0, sz, sz);
    M.querySelectorAll('.ie-filter').forEach(b => {
      const p = (FILTERS.find(x => x[0] === b.dataset.filter) || FILTERS[0])[2];
      const x = b.querySelector('canvas').getContext('2d'); x.clearRect(0, 0, sz, sz);
      x.filter = filterCss({ brightness: 0, contrast: 0, saturation: 0, sepia: 0, warmth: 0, ...p }); x.drawImage(t, 0, 0);
      b.classList.toggle('active', b.dataset.filter === E.s.filter);
    });
  }
  function syncUi() {
    const M = E.M;
    ['angle', ...ADJ.map(a => a[0])].forEach(k => { const i = M.querySelector('[data-adj="' + k + '"]'); if (i) i.value = E.s[k]; const v = M.querySelector('[data-val="' + k + '"]'); if (v) v.textContent = k === 'angle' ? E.s.angle + '°' : (E.s[k] > 0 ? '+' : '') + E.s[k]; });
    M.querySelectorAll('[data-ratio]').forEach(b => b.classList.toggle('active', String(E.s.ratio) === b.dataset.ratio));
    M.querySelectorAll('.ie-filter').forEach(b => b.classList.toggle('active', b.dataset.filter === E.s.filter));
    M.querySelector('[data-q]').value = E.s.quality; M.querySelector('[data-val="q"]').textContent = E.s.quality + ' %';
    M.querySelector('.ie-qual').classList.toggle('d-none', !/jpe?g|webp/.test(E.mime));
    const changed = JSON.stringify(E.s) !== JSON.stringify(fresh());
    M.querySelector('[data-ie="reset"]').disabled = !changed;
  }
  let rq = 0;
  const redraw = () => { cancelAnimationFrame(rq); rq = requestAnimationFrame(() => { draw(); syncUi(); }); };

  /* ---------- otvorenie ---------- */
  async function open(it, o) {
    if (!document.getElementById('imgEditModal')) { document.body.insertAdjacentHTML('beforeend', TPL); wire(); }
    const M = document.getElementById('imgEditModal');
    E = { it, o, M, s: fresh(), tab: 'crop', mime: it.mime || 'image/jpeg', modal: bootstrap.Modal.getOrCreateInstance(M), dirty: false };
    M.querySelector('.ie-name').textContent = it.name;
    M.querySelector('.ie-canvas').width = 0;
    M.classList.add('loading');
    tab('crop');
    E.modal.show();
    const img = new Image();
    img.decoding = 'async';
    try {
      const blob = await (await fetch(o.url, { credentials: 'same-origin', cache: 'no-store' })).blob();
      E.blobUrl = URL.createObjectURL(blob);
      img.src = E.blobUrl;
      await img.decode();
    } catch (e) {
      M.classList.remove('loading');
      Cloud.toast('Obrázok sa nepodarilo otvoriť v editore (formát nie je podporovaný).', 'danger');
      E.modal.hide(); return;
    }
    E.img = img;
    if (!M.classList.contains('show')) await new Promise(r => M.addEventListener('shown.bs.modal', r, { once: true }));
    M.classList.remove('loading');
    filterThumbs(); redraw();
  }
  function tab(t) {
    const M = E.M; E.tab = t;
    M.querySelectorAll('.ie-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
    M.querySelectorAll('.ie-sub').forEach(p => p.classList.toggle('d-none', p.dataset.p !== t));
    if (E.img) redraw();
  }

  /* ---------- uloženie ---------- */
  function outType() {
    if (/png/.test(E.mime)) return ['image/png', 'png'];
    if (/webp/.test(E.mime)) return ['image/webp', 'webp'];
    if (/jpe?g/.test(E.mime)) return ['image/jpeg', 'jpg'];
    return ['image/png', 'png']; // gif, bmp → png (ako kópia)
  }
  async function blob() {
    const [mime] = outType();
    const c = render(1, false);
    return new Promise((res, rej) => c.toBlob(b => b ? res(b) : rej(new Error('Obrázok sa nepodarilo vytvoriť.')), mime, E.s.quality / 100));
  }
  async function save(asCopy) {
    const M = E.M, [mime, ext] = outType();
    const sameType = mime === E.mime || (mime === 'image/jpeg' && /jpe?g/.test(E.mime));
    if (!sameType) asCopy = true; // gif/bmp sa uloží ako kópia .png
    M.classList.add('loading');
    try {
      const b = await blob(), fd = new FormData();
      const base = E.it.name.replace(/\.[^.]+$/, '');
      fd.append('data', b, base + '.' + ext);
      if (asCopy) { fd.append('parent', E.it.folder_id || E.o.folder || ''); fd.append('name', base + ' (upravené).' + (sameType ? E.it.name.split('.').pop() : ext)); fd.append('from', E.it.id); }
      else { fd.append('id', E.it.id); fd.append('updated', E.it.updated || ''); }
      const r = await Cloud.api('api.php?action=save_binary', fd);
      if (!r.ok) throw new Error(r.error || 'Uloženie zlyhalo');
      Cloud.toast(asCopy ? 'Uložené ako ' + r.name : 'Uložené – pôvodný obrázok je vo Verziách', 'success');
      E.dirty = false;
      E.o.onSaved && E.o.onSaved(r);
      close(true);
    } catch (e) { Cloud.toast(e.message, 'danger'); }
    finally { M.classList.remove('loading'); }
  }
  async function download() {
    const b = await blob(), a = document.createElement('a');
    a.href = URL.createObjectURL(b); a.download = E.it.name.replace(/\.[^.]+$/, '') + ' (upravené).' + outType()[1];
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  }
  async function close(force) {
    if (!force && JSON.stringify(E.s) !== JSON.stringify(fresh())) {
      const r = await Cloud.confirm('Zahodiť úpravy?', 'Obrázok má neuložené úpravy.', 'Zahodiť');
      if (!r) return;
    }
    E.modal.hide();
    if (E.blobUrl) URL.revokeObjectURL(E.blobUrl);
    E.o.onClose && E.o.onClose();
  }

  /* ---------- ovládanie ---------- */
  function wire() {
    const M = document.getElementById('imgEditModal');
    M.addEventListener('click', (e) => {
      const t = e.target.closest('[data-tab]'); if (t) return tab(t.dataset.tab);
      const r = e.target.closest('[data-ratio]'); if (r) { E.s.ratio = isNaN(+r.dataset.ratio) ? r.dataset.ratio : +r.dataset.ratio; if (E.s.ratio === 'free') E.s.crop = null; else applyRatio(); return redraw(); }
      const f = e.target.closest('[data-filter]'); if (f) { E.s.filter = f.dataset.filter; return redraw(); }
      const p = e.target.closest('[data-pct]'); if (p) { const [w] = outSizeNoResize(); E.s.outW = +p.dataset.pct === 100 ? 0 : Math.round(w * p.dataset.pct / 100); return redraw(); }
      const mx = e.target.closest('[data-max]'); if (mx) { const [w, h] = outSizeNoResize(); const m = +mx.dataset.max; E.s.outW = Math.max(w, h) > m ? Math.round(w * m / Math.max(w, h)) : 0; return redraw(); }
      const b = e.target.closest('[data-ie]'); if (!b || !E || !E.img && b.dataset.ie !== 'close') return;
      const s = E.s;
      ({
        close: () => close(false), save: () => save(false), copy: () => save(true), download,
        reset: () => { E.s = fresh(); redraw(); },
        rotl: () => { s.rot = (s.rot + 270) % 360; s.crop = null; if (s.ratio !== 'free') applyRatio(); redraw(); },
        rotr: () => { s.rot = (s.rot + 90) % 360; s.crop = null; if (s.ratio !== 'free') applyRatio(); redraw(); },
        fliph: () => { s.flipH = !s.flipH; redraw(); }, flipv: () => { s.flipV = !s.flipV; redraw(); },
      })[b.dataset.ie]?.();
    });
    M.addEventListener('input', (e) => {
      const a = e.target.closest('[data-adj]'); if (a) { E.s[a.dataset.adj] = +a.value; return redraw(); }
      if (e.target.matches('[data-q]')) { E.s.quality = +e.target.value; return syncUi(); }
    });
    M.addEventListener('change', (e) => {
      const z = e.target.closest('[data-size]'); if (!z) return;
      const [w, h] = outSizeNoResize(), v = Math.max(1, +z.value || 1);
      const nw = z.dataset.size === 'w' ? v : Math.round(v * w / h);
      E.s.outW = nw >= w ? 0 : nw;
      if (nw > w) Cloud.toast('Obrázok sa dá iba zmenšiť – zväčšenie by zhoršilo kvalitu.', 'warning');
      redraw();
    });
    M.addEventListener('dblclick', (e) => { const a = e.target.closest('.ie-slider'); if (a) { const i = a.querySelector('input'); E.s[i.dataset.adj] = 0; redraw(); } });
    // ťahanie výrezu
    let drag = null;
    M.querySelector('.ie-crop').addEventListener('pointerdown', (e) => {
      const h = e.target.dataset.h || (e.target.closest('.ie-box') ? 'move' : null);
      if (!h) return;
      e.preventDefault();
      const wrap = M.querySelector('.ie-crop').getBoundingClientRect();
      drag = { h, x0: e.clientX, y0: e.clientY, c0: { ...(E.s.crop || { x: 0, y: 0, w: 1, h: 1 }) }, W: wrap.width, H: wrap.height };
      e.target.setPointerCapture(e.pointerId);
    });
    M.querySelector('.ie-crop').addEventListener('pointermove', (e) => {
      if (!drag) return;
      const dx = (e.clientX - drag.x0) / drag.W, dy = (e.clientY - drag.y0) / drag.H, c = { ...drag.c0 }, min = 0.04;
      if (drag.h === 'move') { c.x = Math.min(1 - c.w, Math.max(0, c.x + dx)); c.y = Math.min(1 - c.h, Math.max(0, c.y + dy)); }
      else {
        if (drag.h.includes('w')) { const nx = Math.min(c.x + c.w - min, Math.max(0, c.x + dx)); c.w += c.x - nx; c.x = nx; }
        if (drag.h.includes('e')) c.w = Math.min(1 - c.x, Math.max(min, c.w + dx));
        if (drag.h.includes('n')) { const ny = Math.min(c.y + c.h - min, Math.max(0, c.y + dy)); c.h += c.y - ny; c.y = ny; }
        if (drag.h.includes('s')) c.h = Math.min(1 - c.y, Math.max(min, c.h + dy));
        if (E.s.ratio !== 'free') { // drží pomer strán
          const r = (E.s.ratio === 'orig' ? E.img.naturalWidth / E.img.naturalHeight : +E.s.ratio) * drag.H / drag.W;
          if (/^[ns]$/.test(drag.h)) c.w = Math.min(1 - c.x, c.h * r); else c.h = Math.min(1 - c.y, c.w / r);
          if (c.h * r > c.w + 1e-6) c.h = c.w / r; else c.w = c.h * r;
          if (drag.h.includes('n')) c.y = drag.c0.y + drag.c0.h - c.h;
          if (drag.h.includes('w')) c.x = drag.c0.x + drag.c0.w - c.w;
        }
      }
      E.s.crop = c; placeCrop();
      const [ow, oh] = outSize(); M.querySelector('.ie-info').textContent = ow + ' × ' + oh + ' px';
    });
    const end = () => { if (drag) { drag = null; syncUi(); } };
    M.querySelector('.ie-crop').addEventListener('pointerup', end);
    M.querySelector('.ie-crop').addEventListener('pointercancel', end);
    // pri zmene veľkosti plochy (okno, otočenie mobilu, iná výška panelu) prekresliť
    let lastSz = '';
    new ResizeObserver(() => { const st = M.querySelector('.ie-stage'), k = st.clientWidth + 'x' + st.clientHeight; if (k !== lastSz && E && E.img && M.classList.contains('show')) { lastSz = k; redraw(); } }).observe(M.querySelector('.ie-stage'));
    document.addEventListener('keydown', (e) => {
      if (!M.classList.contains('show') || !E || e.target.matches('input[type=number]')) return;
      if (e.key === 'Escape') { e.preventDefault(); close(false); }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(false); }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') { e.preventDefault(); E.s = fresh(); redraw(); }
    });
  }
  function outSizeNoResize() { const k = E.s.outW; E.s.outW = 0; const r = outSize(); E.s.outW = k; return r; }

  const canEdit = (it) => it && it.type === 'f' && /^image\/(jpeg|png|webp|gif|bmp)$/.test(it.mime || '');
  window.CloudImageEditor = { open, canEdit };
})();
