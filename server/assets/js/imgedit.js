/* Editor obrázkov v Cloude: orezanie (aj s pomerom strán), otočenie, preklopenie, vyrovnanie, jas, kontrast,
   sýtosť, teplota, filtre, tvary (vyše 100 – výplň, prechod, obrys, priehľadnosť, prelínanie, tieň, text),
   masky (tvar, mäkký okraj, okolie priehľadné / farba / rozmazané / čiernobiele / stmavené) a zmena veľkosti.
   Beží v prehliadači (canvas), ukladá ako novú verziu alebo kópiu. Späť / Znova pre všetko. Ovládanie myšou aj prstom. */
(function () {
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const LIB = () => window.IE_SHAPES;
  const RATIOS = [['free', 'Voľne'], ['orig', 'Pôvodný'], [1, '1:1'], [4 / 3, '4:3'], [3 / 4, '3:4'], [16 / 9, '16:9'], [9 / 16, '9:16'], [3 / 2, '3:2']];
  const FILTERS = [
    ['none', 'Žiadny', {}], ['bw', 'Čiernobiely', { saturation: -100, contrast: 10 }], ['sepia', 'Sépia', { sepia: 80 }],
    ['warm', 'Teplý', { warmth: 35, saturation: 10 }], ['cool', 'Studený', { warmth: -35 }], ['vivid', 'Živý', { saturation: 45, contrast: 15 }],
    ['fade', 'Vybledlý', { contrast: -25, brightness: 8, saturation: -20 }], ['vintage', 'Vintage', { sepia: 35, contrast: -10, brightness: 5, saturation: -15 }],
    ['drama', 'Dráma', { contrast: 40, brightness: -8, saturation: -10 }],
  ];
  const ADJ = [['brightness', 'Jas', 'brightness-high'], ['contrast', 'Kontrast', 'circle-half'], ['saturation', 'Sýtosť', 'droplet-half'], ['warmth', 'Teplota', 'thermometer-half']];
  const SWATCH = ['#ffffff', '#000000', '#5f6368', '#d93025', '#f9ab00', '#188038', '#1a73e8', '#9334e6', '#e52592', '#ff6d01'];
  const FONTS = [['sans', 'Bez pätiek', 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'], ['serif', 'S pätkami', 'Georgia, "Times New Roman", serif'],
    ['mono', 'Strojové', '"Courier New", Consolas, monospace'], ['impact', 'Impact', 'Impact, "Arial Black", sans-serif'], ['hand', 'Rukopis', '"Comic Sans MS", "Segoe Print", "Bradley Hand", cursive']];
  const BLENDS = [['source-over', 'Normálne'], ['multiply', 'Násobiť'], ['screen', 'Zosvetliť'], ['overlay', 'Prekryť'], ['darken', 'Tmavšie'], ['lighten', 'Svetlejšie'], ['color-burn', 'Ztmaviť farby'], ['color-dodge', 'Zosvetliť farby'], ['difference', 'Rozdiel'], ['hue', 'Odtieň'], ['color', 'Farba'], ['luminosity', 'Jas']];
  const MASK_OUT = [['transparent', 'Priehľadné', 'transparency'], ['color', 'Farba', 'paint-bucket'], ['blur', 'Rozmazané', 'droplet'], ['gray', 'Čiernobiele', 'circle-half'], ['dark', 'Stmavené', 'moon']];
  const MASK_QUICK = ['ellipse', 'rrect', 'heart', 'star5', 'poly6', 'cloud', 'diamond', 'tri', 'seal', 'flower'];

  /* ---------- šablóna ---------- */
  const colorCtl = (key, label) => '<div class="ie-ctl ie-color"><span>' + label + '</span><input type="color" data-k="' + key + '"><div class="ie-sw">' + SWATCH.map(c => '<button type="button" data-sw="' + key + '" data-c="' + c + '" style="background:' + c + '" title="' + c + '"></button>').join('') + '</div></div>';
  const slider = (key, label, min, max, step = 1, unit = '') => '<label class="ie-ctl ie-slider"><span>' + label + ' <b data-v="' + key + '"></b></span><input type="range" min="' + min + '" max="' + max + '" step="' + step + '" data-k="' + key + '" data-u="' + unit + '"></label>';
  const seg = (key, opts) => '<div class="ie-ctl ie-seg" data-seg="' + key + '">' + opts.map(([v, l, ic]) => '<button type="button" data-sv="' + v + '" title="' + esc(l) + '">' + (ic ? '<i class="bi bi-' + ic + '"></i>' : '') + '<span>' + esc(l) + '</span></button>').join('') + '</div>';
  const GROUPS = [
    ['fill', 'Výplň', 'paint-bucket', seg('ft', [['none', 'Žiadna', 'slash-circle'], ['solid', 'Farba', 'square-fill'], ['grad', 'Prechod', 'circle-half']]) + colorCtl('fill', 'Farba') + '<div class="ie-grad">' + colorCtl('f2', 'Druhá') + slider('ga', 'Smer', 0, 360, 5, '°') + '</div>' + slider('fa', 'Krytie', 0, 100, 1, ' %')],
    ['stroke', 'Obrys', 'border-width', colorCtl('sc', 'Farba') + slider('sw', 'Hrúbka', 0, 60, 0.5, ' px') + slider('sa', 'Krytie', 0, 100, 1, ' %') + seg('dash', [['solid', 'Plná', 'dash-lg'], ['dash', 'Čiarky', 'three-dots'], ['dot', 'Bodky', 'dot']])],
    ['look', 'Priehľadnosť', 'transparency', slider('op', 'Priehľadnosť', 0, 100, 1, ' %') + '<label class="ie-ctl"><span>Prelínanie</span><select class="form-select form-select-sm" data-k="blend">' + BLENDS.map(([v, l]) => '<option value="' + v + '">' + l + '</option>').join('') + '</select></label>'],
    ['shadow', 'Tieň', 'shadows', '<label class="ie-ctl ie-check"><input type="checkbox" class="form-check-input" data-k="shadow"><span>Tieň</span></label>' + colorCtl('shc', 'Farba') + slider('sha', 'Krytie', 0, 100, 1, ' %') + slider('shb', 'Rozmazanie', 0, 80, 1, ' px') + slider('shx', 'Posun X', -60, 60, 1, ' px') + slider('shy', 'Posun Y', -60, 60, 1, ' px')],
    ['text', 'Text', 'fonts', '<label class="ie-ctl ie-text"><span>Text</span><textarea rows="2" class="form-control form-control-sm" data-k="text" placeholder="Napíš text…"></textarea></label>' + colorCtl('tc', 'Farba') + slider('ts', 'Veľkosť', 4, 400, 1, ' px')
      + '<label class="ie-ctl"><span>Písmo</span><select class="form-select form-select-sm" data-k="tf">' + FONTS.map(([v, l]) => '<option value="' + v + '">' + l + '</option>').join('') + '</select></label>'
      + '<div class="ie-ctl ie-seg ie-tog"><button type="button" data-tog="tb" title="Tučné"><i class="bi bi-type-bold"></i></button><button type="button" data-tog="ti" title="Kurzíva"><i class="bi bi-type-italic"></i></button></div>'
      + seg('tal', [['left', 'Vľavo', 'text-left'], ['center', 'Na stred', 'text-center'], ['right', 'Vpravo', 'text-right']])],
    ['shape', 'Tvar', 'bounding-box', '<div class="ie-ctl ie-pwrap">' + slider('p', 'Tvar', 0, 1, 0.01) + '</div>' + slider('rot', 'Otočenie', -180, 180, 1, '°')
      + '<div class="ie-ctl ie-seg"><button type="button" data-ie="sfh" title="Zrkadliť"><i class="bi bi-symmetry-vertical"></i><span>Zrkadliť</span></button><button type="button" data-ie="sfv" title="Hore-dole"><i class="bi bi-symmetry-horizontal"></i><span>Hore-dole</span></button><button type="button" data-tog="lock" title="Pri zmene veľkosti zachovať pomer strán"><i class="bi bi-lock"></i><span>Pomer</span></button></div>'
      + '<div class="ie-ctl ie-seg"><button type="button" data-ie="swap"><i class="bi bi-arrow-repeat"></i><span>Zmeniť tvar</span></button><button type="button" data-ie="center"><i class="bi bi-align-center"></i><span>Na stred</span></button></div>'],
  ];
  const TPL = '<div class="modal fade" id="imgEditModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-fullscreen"><div class="modal-content ie-wrap">'
    + '<div class="ie-top"><button class="btn btn-icon text-white" data-ie="close" title="Zavrieť"><i class="bi bi-x-lg"></i></button><span class="ie-name text-truncate"></span><span class="ms-auto"></span>'
    + '<button class="btn btn-icon text-white" data-ie="undo" title="Späť (Ctrl+Z)"><i class="bi bi-arrow-90deg-left"></i></button><button class="btn btn-icon text-white" data-ie="redo" title="Znova (Ctrl+Y)"><i class="bi bi-arrow-90deg-right"></i></button>'
    + '<button class="btn btn-sm btn-outline-light rounded-pill px-3" data-ie="reset"><i class="bi bi-arrow-counterclockwise me-1"></i><span class="d-none d-sm-inline">Pôvodný</span></button>'
    + '<div class="btn-group"><button class="btn btn-sm btn-primary rounded-start-pill px-3" data-ie="save"><i class="bi bi-cloud-check me-1"></i>Uložiť</button>'
    + '<button class="btn btn-sm btn-primary rounded-end-pill dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="Ďalšie možnosti"></button>'
    + '<ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" data-ie="save"><i class="bi bi-cloud-check me-2"></i>Uložiť (pôvodný ostane vo Verziách)</button></li><li><button class="dropdown-item" data-ie="copy"><i class="bi bi-files me-2"></i>Uložiť ako kópiu</button></li><li><button class="dropdown-item" data-ie="download"><i class="bi bi-download me-2"></i>Stiahnuť upravený</button></li></ul></div></div>'
    + '<div class="ie-stage"><canvas class="ie-canvas"></canvas><div class="ie-crop d-none"><div class="ie-box">' + ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'].map(h => '<span class="ie-h ' + h + '" data-h="' + h + '"></span>').join('') + '<i class="ie-grid"></i></div></div>'
    + '<div class="ie-objs d-none"><div class="ie-sel d-none"><i class="ie-rotline"></i>' + ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w', 'rot'].map(h => '<span class="ie-oh ' + h + '" data-oh="' + h + '"></span>').join('') + '</div></div>'
    + '<div class="ie-lib d-none"><div class="ie-lib-head"><div class="ie-lib-title"></div><input type="search" class="form-control form-control-sm" placeholder="Hľadať tvar…" data-lib-q><button class="btn btn-icon text-white" data-ie="libclose" title="Zavrieť"><i class="bi bi-x-lg"></i></button></div><div class="ie-lib-cats"></div><div class="ie-lib-grid"></div></div>'
    + '<div class="ie-spin spinner-border text-light"></div><div class="ie-info"></div></div>'
    + '<div class="ie-panel">'
    + '<div class="ie-sub" data-p="crop">'
    + '<div class="ie-chips">' + RATIOS.map(([v, l]) => '<button class="ie-chip" data-ratio="' + v + '">' + l + '</button>').join('') + '</div>'
    + '<div class="ie-row"><button class="ie-b" data-ie="rotl" title="Otočiť doľava"><i class="bi bi-arrow-counterclockwise"></i><small>Doľava</small></button><button class="ie-b" data-ie="rotr" title="Otočiť doprava"><i class="bi bi-arrow-clockwise"></i><small>Doprava</small></button>'
    + '<button class="ie-b" data-ie="fliph" title="Preklopiť vodorovne"><i class="bi bi-symmetry-vertical"></i><small>Zrkadliť</small></button><button class="ie-b" data-ie="flipv" title="Preklopiť zvisle"><i class="bi bi-symmetry-horizontal"></i><small>Hore-dole</small></button>'
    + '<label class="ie-slider"><span><i class="bi bi-rulers me-1"></i>Vyrovnať <b data-val="angle">0°</b></span><input type="range" min="-45" max="45" step="0.5" value="0" data-adj="angle"></label></div></div>'
    + '<div class="ie-sub d-none" data-p="adjust"><div class="ie-sliders">' + ADJ.map(([k, l, ic]) => '<label class="ie-slider"><span><i class="bi bi-' + ic + ' me-1"></i>' + l + ' <b data-val="' + k + '">0</b></span><input type="range" min="-100" max="100" value="0" data-adj="' + k + '"></label>').join('') + '</div></div>'
    + '<div class="ie-sub d-none" data-p="filter"><div class="ie-filters">' + FILTERS.map(([k, l]) => '<button class="ie-filter" data-filter="' + k + '"><canvas width="72" height="72"></canvas><span>' + l + '</span></button>').join('') + '</div></div>'
    + '<div class="ie-sub d-none ie-objsub" data-p="shapes">'
    + '<div class="ie-acts"><button class="ie-chip ie-add" data-ie="lib"><i class="bi bi-plus-lg me-1"></i>Pridať tvar</button><button class="ie-chip" data-ie="addtext"><i class="bi bi-fonts me-1"></i>Text</button>'
    + '<span class="ie-selacts"><button class="ie-ib" data-ie="dup" title="Duplikovať (Ctrl+D)"><i class="bi bi-copy"></i></button><button class="ie-ib" data-ie="front" title="Dopredu"><i class="bi bi-front"></i></button><button class="ie-ib" data-ie="back" title="Dozadu"><i class="bi bi-back"></i></button><button class="ie-ib" data-ie="del" title="Zmazať (Delete)"><i class="bi bi-trash3"></i></button></span></div>'
    + '<div class="ie-hint">Pridaj tvar alebo ťukni na tvar na obrázku. Ťahaním ho presunieš, rohmi zmeníš veľkosť, guľôčkou hore otočíš – na mobile aj dvoma prstami.</div>'
    + '<div class="ie-groups">' + GROUPS.map(([k, l, ic]) => '<button class="ie-gtab" data-g="' + k + '"><i class="bi bi-' + ic + '"></i><span>' + l + '</span></button>').join('') + '</div>'
    + GROUPS.map(([k, , , html]) => '<div class="ie-props d-none" data-gp="' + k + '">' + html + '</div>').join('') + '</div>'
    + '<div class="ie-sub d-none ie-objsub" data-p="mask">'
    + '<div class="ie-acts"><button class="ie-chip" data-mq="">Bez masky</button>' + MASK_QUICK.map(id => '<button class="ie-chip ie-mq" data-mq="' + id + '"></button>').join('') + '<button class="ie-chip" data-ie="libmask"><i class="bi bi-grid-3x3-gap me-1"></i>Viac tvarov</button>'
    + '<button class="ie-chip" data-mpre="vignette"><i class="bi bi-vignette me-1"></i>Vinetácia</button><button class="ie-chip" data-mpre="focus"><i class="bi bi-bullseye me-1"></i>Rozmazané okolie</button></div>'
    + '<div class="ie-mprops"><div class="ie-props">' + seg('out', MASK_OUT.map(([v, l, ic]) => [v, l, ic])) + '<div class="ie-mcol">' + colorCtl('mcolor', 'Farba') + '</div><div class="ie-mamt">' + slider('amt', 'Sila', 0, 100, 1, ' %') + '</div>'
    + slider('feather', 'Mäkký okraj', 0, 200, 1, ' px') + '<div class="ie-ctl ie-seg"><button type="button" data-tog="inv" title="Obrátiť masku – upraví sa vnútro"><i class="bi bi-subtract"></i><span>Obrátiť</span></button><button type="button" data-tog="all" title="Maska sa použije aj na tvary a text"><i class="bi bi-layers"></i><span>Aj tvary</span></button><button type="button" data-ie="mcrop" title="Orezať obrázok na rozmer masky"><i class="bi bi-crop"></i><span>Orezať na masku</span></button></div>'
    + '<div class="ie-ctl ie-pwrap">' + slider('mp', 'Tvar', 0, 1, 0.01) + '</div>' + slider('mrot', 'Otočenie', -180, 180, 1, '°') + '</div>'
    + '<div class="ie-hint ie-mhint">Masku posúvaš a meníš ťahaním na obrázku. Pri „Priehľadné“ sa uloží ako PNG.</div></div></div>'
    + '<div class="ie-sub d-none" data-p="size"><div class="ie-size"><label>Šírka<input type="number" min="1" class="form-control form-control-sm" data-size="w"></label><span class="ie-x">×</span><label>Výška<input type="number" min="1" class="form-control form-control-sm" data-size="h"></label>'
    + '<div class="ie-chips">' + [100, 75, 50, 25].map(p => '<button class="ie-chip" data-pct="' + p + '">' + p + ' %</button>').join('') + '<button class="ie-chip" data-max="1920">Full HD</button><button class="ie-chip" data-max="1080">Na web</button></div></div>'
    + '<div class="ie-row small text-white-50 ie-qual"><span>Kvalita JPEG</span><input type="range" min="50" max="100" value="90" data-q><b data-val="q">90 %</b></div></div>'
    + '<div class="ie-tabs"><button class="ie-tab active" data-tab="crop"><i class="bi bi-crop"></i><span>Orezať</span></button><button class="ie-tab" data-tab="adjust"><i class="bi bi-sliders"></i><span>Úpravy</span></button><button class="ie-tab" data-tab="filter"><i class="bi bi-stars"></i><span>Filtre</span></button>'
    + '<button class="ie-tab" data-tab="shapes"><i class="bi bi-pentagon"></i><span>Tvary</span></button><button class="ie-tab" data-tab="mask"><i class="bi bi-mask"></i><span>Maska</span></button><button class="ie-tab" data-tab="size"><i class="bi bi-aspect-ratio"></i><span>Veľkosť</span></button></div>'
    + '</div></div></div></div>';

  let E = null; // stav

  const fresh = () => ({ rot: 0, flipH: false, flipV: false, angle: 0, brightness: 0, contrast: 0, saturation: 0, warmth: 0, sepia: 0, filter: 'none', crop: null, ratio: 'free', outW: 0, quality: 90, shapes: [], mask: null });
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
  const canvas = (w, h) => { const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w)); c.height = Math.max(1, Math.round(h)); return c; };
  const rgba = (hex, a) => { const m = /^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(hex || '') || [0, 'ff', 'ff', 'ff']; return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + (a / 100) + ')'; };
  /** Rozmery otočeného obrázka v plnej veľkosti – v tomto priestore sú uložené tvary aj maska. */
  const baseDims = () => { const q = (E.s.rot / 90) % 2 !== 0, iw = E.img.naturalWidth, ih = E.img.naturalHeight; return q ? [ih, iw] : [iw, ih]; };

  /** Obrázok po otočení / preklopení / vyrovnaní (bez orezania) v mierke scale. */
  function oriented(scale) {
    const s = E.s, iw = E.img.naturalWidth, ih = E.img.naturalHeight;
    const quarter = (s.rot / 90) % 2 !== 0;
    const W = Math.round((quarter ? ih : iw) * scale), H = Math.round((quarter ? iw : ih) * scale);
    const c = canvas(W, H), x = c.getContext('2d');
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
  /** Obrázok s orezaním, úpravami a veľkosťou – bez tvarov a masky. */
  function renderBase(scale, forPreview) {
    const o = oriented(scale), s = E.s;
    const cr = !forPreview && s.crop ? s.crop : { x: 0, y: 0, w: 1, h: 1 };
    const sx = cr.x * o.width, sy = cr.y * o.height, sw = Math.max(1, cr.w * o.width), sh = Math.max(1, cr.h * o.height);
    let ow = Math.round(sw), oh = Math.round(sh);
    if (!forPreview && s.outW && s.outW < ow) { oh = Math.round(oh * s.outW / ow); ow = s.outW; }
    const c = canvas(ow, oh), x = c.getContext('2d');
    x.imageSmoothingQuality = 'high';
    x.filter = filterCss(effective());
    x.drawImage(o, sx, sy, sw, sh, 0, 0, ow, oh);
    return c;
  }
  /** Výsledný obrázok (s tvarmi a maskou). */
  function render(scale, forPreview) {
    const c = renderBase(scale, forPreview);
    overlays(c, !forPreview && E.s.crop ? E.s.crop : { x: 0, y: 0, w: 1, h: 1 });
    return c;
  }
  /** Maska a tvary do plátna c, ktoré zobrazuje výrez cr (časti 0–1) otočeného obrázka. */
  function overlays(c, cr) {
    const [W, H] = baseDims(), k = c.width / (cr.w * W), ox = cr.x * W, oy = cr.y * H, m = E.s.mask;
    if (m && !m.all) applyMask(c, k, ox, oy); // maska iba na fotku – tvary ostanú navrchu
    if (E.s.shapes.length) {
      const x = c.getContext('2d');
      x.save(); x.setTransform(k, 0, 0, k, -ox * k, -oy * k); x.filter = 'none';
      E.s.shapes.forEach(sh => drawShape(x, sh, k));
      x.restore();
    }
    if (m && m.all) applyMask(c, k, ox, oy); // maska aj na tvary
  }
  function shapePath(o) { const d = LIB().byId[o.t] || LIB().byId.rect; return new Path2D(d.f(Math.max(1, o.w), Math.max(1, o.h), o.p ?? d.p ?? 0.5)); }
  function place(x, o, flip = true) { x.translate(o.cx, o.cy); x.rotate((o.rot || 0) * Math.PI / 180); if (flip) x.scale(o.fh ? -1 : 1, o.fv ? -1 : 1); x.translate(-o.w / 2, -o.h / 2); }
  function drawShape(x, o, k) {
    const def = LIB().byId[o.t] || LIB().byId.rect, path = shapePath(o);
    x.save();
    place(x, o);
    x.globalAlpha = (o.op ?? 100) / 100;
    x.globalCompositeOperation = o.blend || 'source-over';
    let shadowOn = !!o.shadow;
    const sh = () => { if (!shadowOn) return; x.shadowColor = rgba(o.shc || '#000000', o.sha ?? 50); x.shadowBlur = (o.shb || 0) * k; x.shadowOffsetX = (o.shx || 0) * k; x.shadowOffsetY = (o.shy || 0) * k; };
    const noSh = () => { shadowOn = false; x.shadowColor = 'transparent'; x.shadowBlur = 0; x.shadowOffsetX = 0; x.shadowOffsetY = 0; };
    if (!def.line && o.ft !== 'none' && (o.fa ?? 100) > 0) {
      if (o.ft === 'grad') {
        const a = (o.ga || 0) * Math.PI / 180, cx = o.w / 2, cy = o.h / 2, r = Math.abs(Math.cos(a)) * o.w / 2 + Math.abs(Math.sin(a)) * o.h / 2;
        const g = x.createLinearGradient(cx - Math.cos(a) * r, cy - Math.sin(a) * r, cx + Math.cos(a) * r, cy + Math.sin(a) * r);
        g.addColorStop(0, rgba(o.fill, o.fa ?? 100)); g.addColorStop(1, rgba(o.f2 || '#ffffff', o.fa ?? 100)); x.fillStyle = g;
      } else x.fillStyle = rgba(o.fill, o.fa ?? 100);
      sh(); x.fill(path, def.eo ? 'evenodd' : 'nonzero'); noSh();
    }
    if ((o.sw || 0) > 0 && (o.sa ?? 100) > 0) {
      x.strokeStyle = rgba(o.sc, o.sa ?? 100); x.lineWidth = o.sw; x.lineJoin = 'round'; x.lineCap = o.dash === 'dot' ? 'round' : (def.line ? 'round' : 'butt');
      x.setLineDash(o.dash === 'dash' ? [o.sw * 3, o.sw * 2] : o.dash === 'dot' ? [0.01, o.sw * 2] : []);
      sh(); x.stroke(path); noSh();
    }
    x.restore();
    if (o.text && String(o.text).trim()) { // text sa nezrkadlí
      x.save(); place(x, o, false);
      x.globalAlpha = (o.op ?? 100) / 100;
      const tb = def.tb || [0, 0, 1, 1], pad = Math.min(o.w, o.h) * 0.06, bx = tb[0] * o.w + pad, by = tb[1] * o.h + pad, bw = Math.max(4, tb[2] * o.w - pad * 2), bh = tb[3] * o.h - pad * 2;
      const fs = o.ts || 40, fam = (FONTS.find(f => f[0] === o.tf) || FONTS[0])[2];
      x.font = (o.ti ? 'italic ' : '') + (o.tb ? '700 ' : '400 ') + fs + 'px ' + fam;
      x.fillStyle = o.tc || '#ffffff'; x.textBaseline = 'middle'; x.textAlign = o.tal || 'center';
      if (o.shadow && def.id === 'text') { x.shadowColor = rgba(o.shc || '#000000', o.sha ?? 50); x.shadowBlur = (o.shb || 0) * k; x.shadowOffsetX = (o.shx || 0) * k; x.shadowOffsetY = (o.shy || 0) * k; }
      const lines = [];
      String(o.text).split('\n').forEach(par => { let cur = ''; par.split(/(\s+)/).forEach(wd => { const t = cur + wd; if (x.measureText(t).width > bw && cur.trim()) { lines.push(cur.trim()); cur = wd.trimStart(); } else cur = t; }); lines.push(cur.trim()); });
      const lh = fs * 1.2, y0 = by + bh / 2 - (lines.length - 1) * lh / 2, tx = o.tal === 'left' ? bx : o.tal === 'right' ? bx + bw : bx + bw / 2;
      lines.forEach((ln, i) => x.fillText(ln, tx, y0 + i * lh));
      x.restore();
    }
  }
  function applyMask(c, k, ox, oy) {
    const m = E.s.mask, W = c.width, H = c.height, cx = c.getContext('2d');
    const mc = canvas(W, H), mx = mc.getContext('2d');
    mx.save(); mx.setTransform(k, 0, 0, k, -ox * k, -oy * k);
    if (m.feather > 0) mx.filter = 'blur(' + (m.feather * k / 2) + 'px)';
    place(mx, m); mx.fillStyle = '#fff'; mx.fill(shapePath(m), (LIB().byId[m.t] || {}).eo ? 'evenodd' : 'nonzero');
    mx.restore();
    if (m.inv) { mx.globalCompositeOperation = 'xor'; mx.fillStyle = '#fff'; mx.fillRect(0, 0, W, H); mx.globalCompositeOperation = 'source-over'; }
    cx.save(); cx.setTransform(1, 0, 0, 1, 0, 0); cx.filter = 'none';
    if (m.out === 'transparent') { cx.globalCompositeOperation = 'destination-in'; cx.drawImage(mc, 0, 0); }
    else {
      const o = canvas(W, H), x = o.getContext('2d'), amt = (m.amt ?? 60) / 100;
      if (m.out === 'color') { x.fillStyle = rgba(m.color || '#ffffff', 100); x.fillRect(0, 0, W, H); x.globalAlpha = 1 - amt; x.drawImage(c, 0, 0); x.globalAlpha = 1; }
      else if (m.out === 'blur') { x.drawImage(c, 0, 0); x.filter = 'blur(' + Math.max(1, amt * Math.max(W, H) * 0.025) + 'px)'; x.drawImage(c, 0, 0); x.filter = 'none'; }
      else if (m.out === 'gray') { x.filter = 'grayscale(' + amt + ')'; x.drawImage(c, 0, 0); x.filter = 'none'; }
      else { x.drawImage(c, 0, 0); x.fillStyle = 'rgba(0,0,0,' + amt + ')'; x.fillRect(0, 0, W, H); }
      x.globalCompositeOperation = 'destination-out'; x.drawImage(mc, 0, 0);
      cx.drawImage(o, 0, 0);
    }
    cx.restore();
  }
  /** Rozmery výsledku v plnej veľkosti. */
  function outSize() {
    const [W, H] = baseDims(), cr = E.s.crop || { w: 1, h: 1 };
    let w = Math.round(W * cr.w), h = Math.round(H * cr.h);
    if (E.s.outW && E.s.outW < w) { h = Math.round(h * E.s.outW / w); w = E.s.outW; }
    return [w, h];
  }

  /* ---------- náhľad ---------- */
  const objTab = () => E.tab === 'shapes' || E.tab === 'mask';
  function view() { // výrez, ktorý je práve na obrazovke
    return E.tab === 'crop' || !E.s.crop ? { x: 0, y: 0, w: 1, h: 1 } : E.s.crop;
  }
  function draw() {
    const M = E.M, stage = M.querySelector('.ie-stage'), cv = M.querySelector('.ie-canvas');
    const maxW = stage.clientWidth - 32, maxH = stage.clientHeight - 32;
    const [W, H] = baseDims(), cropMode = E.tab === 'crop', cr = view();
    const dpr = window.devicePixelRatio > 1 ? Math.min(2, devicePixelRatio) : 1;
    const scale = Math.min(maxW / (W * cr.w), maxH / (H * cr.h), 1) * dpr;
    // obrázok s úpravami sa počíta iba pri ich zmene – tvary a maska sa dokresľujú (rýchle ťahanie aj na mobile)
    const key = JSON.stringify([scale, cropMode, { ...E.s, shapes: 0, mask: 0, outW: 0, quality: 0 }]);
    if (E.cacheKey !== key) {
      const keep = E.s.outW; E.s.outW = 0;
      E.cache = renderBase(Math.min(scale, 1), cropMode); E.s.outW = keep; E.cacheKey = key;
    }
    cv.width = E.cache.width; cv.height = E.cache.height;
    const x = cv.getContext('2d'); x.clearRect(0, 0, cv.width, cv.height); x.drawImage(E.cache, 0, 0);
    overlays(cv, cr);
    cv.style.width = cv.width / dpr + 'px'; cv.style.height = cv.height / dpr + 'px';
    M.querySelector('.ie-crop').classList.toggle('d-none', !cropMode);
    M.querySelector('.ie-objs').classList.toggle('d-none', !objTab());
    if (cropMode) placeCrop();
    placeObjs();
    const [ow, oh] = outSize();
    M.querySelector('.ie-info').textContent = ow + ' × ' + oh + ' px' + (needsAlpha() && !/png|webp/.test(E.mime) ? ' · uloží sa ako PNG' : '');
    if (E.tab === 'size') { M.querySelector('[data-size="w"]').value = ow; M.querySelector('[data-size="h"]').value = oh; }
  }
  function overlayRect(cls) {
    const M = E.M, cv = M.querySelector('.ie-canvas'), wrap = M.querySelector(cls);
    const r = cv.getBoundingClientRect(), sr = M.querySelector('.ie-stage').getBoundingClientRect();
    wrap.style.left = (r.left - sr.left) + 'px'; wrap.style.top = (r.top - sr.top) + 'px'; wrap.style.width = r.width + 'px'; wrap.style.height = r.height + 'px';
    return r;
  }
  function placeCrop() {
    const box = E.M.querySelector('.ie-box');
    overlayRect('.ie-crop');
    const c = E.s.crop || { x: 0, y: 0, w: 1, h: 1 };
    box.style.left = c.x * 100 + '%'; box.style.top = c.y * 100 + '%'; box.style.width = c.w * 100 + '%'; box.style.height = c.h * 100 + '%';
  }
  /** Prevod: body obrázka ↔ obrazovka. */
  function mapper() {
    const r = E.M.querySelector('.ie-canvas').getBoundingClientRect(), [W, H] = baseDims(), cr = view(), d = r.width / (cr.w * W);
    return { d, r, toB: (cx, cy) => [cr.x * W + (cx - r.left) / d, cr.y * H + (cy - r.top) / d], toS: (bx, by) => [(bx - cr.x * W) * d, (by - cr.y * H) * d] };
  }
  const target = () => E.tab === 'mask' ? E.s.mask : E.tab === 'shapes' ? E.s.shapes.find(o => o.id === E.sel) : null;
  function placeObjs() {
    const M = E.M, sel = M.querySelector('.ie-sel'), o = objTab() ? target() : null;
    if (!objTab()) return;
    overlayRect('.ie-objs');
    sel.classList.toggle('d-none', !o);
    if (!o) return;
    const mp = mapper(), [sx, sy] = mp.toS(o.cx, o.cy), w = o.w * mp.d, h = o.h * mp.d;
    Object.assign(sel.style, { left: (sx - w / 2) + 'px', top: (sy - h / 2) + 'px', width: w + 'px', height: h + 'px', transform: 'rotate(' + (o.rot || 0) + 'deg)' });
    sel.classList.toggle('is-line', !!(LIB().byId[o.t] || {}).line && E.tab === 'shapes');
    sel.classList.toggle('is-mask', E.tab === 'mask');
  }
  function applyRatio() {
    const [W, H] = baseDims();
    let r = E.s.ratio;
    if (r === 'free') return;
    if (r === 'orig') r = E.img.naturalWidth / E.img.naturalHeight;
    r = +r;
    // najväčší výrez so zvoleným pomerom, na stred
    let w = W, h = W / r; if (h > H) { h = H; w = H * r; }
    E.s.crop = { x: (W - w) / 2 / W, y: (H - h) / 2 / H, w: w / W, h: h / H };
  }
  function filterThumbs() {
    const M = E.M, t = canvas(72, 72), sz = 72;
    const iw = E.img.naturalWidth, ih = E.img.naturalHeight, m = Math.min(iw, ih);
    t.getContext('2d').drawImage(E.img, (iw - m) / 2, (ih - m) / 2, m, m, 0, 0, sz, sz);
    M.querySelectorAll('.ie-filter').forEach(b => {
      const p = (FILTERS.find(x => x[0] === b.dataset.filter) || FILTERS[0])[2];
      const x = b.querySelector('canvas').getContext('2d'); x.clearRect(0, 0, sz, sz);
      x.filter = filterCss({ brightness: 0, contrast: 0, saturation: 0, sepia: 0, warmth: 0, ...p }); x.drawImage(t, 0, 0);
      b.classList.toggle('active', b.dataset.filter === E.s.filter);
    });
  }
  /* hodnoty ovládačov tvarov a masky ↔ objekt (niektoré sú v px obrázka, iné v %) */
  const fmt = (k, v, u) => (u === '°' ? Math.round(v) : u === ' px' ? Math.round(v * 10) / 10 : k === 'p' || k === 'mp' ? Math.round(v * 100) : Math.round(v)) + (k === 'p' || k === 'mp' ? ' %' : u);
  function syncProps() {
    const M = E.M;
    if (E.tab === 'shapes') {
      const o = target(), def = o && LIB().byId[o.t];
      if (def && def.line && (E.group === 'fill' || E.group === 'text')) E.group = 'stroke';
      M.querySelector('[data-p="shapes"]').classList.toggle('has-sel', !!o);
      M.querySelectorAll('.ie-gtab').forEach(b => { b.classList.toggle('active', b.dataset.g === E.group); b.classList.toggle('d-none', !!(def && def.line && (b.dataset.g === 'fill' || b.dataset.g === 'text'))); });
      M.querySelectorAll('[data-gp]').forEach(g => g.classList.toggle('d-none', !o || g.dataset.gp !== E.group));
      if (!o) return;
      const [W, H] = baseDims(), mx = Math.max(60, Math.round(Math.min(W, H) * 0.05));
      M.querySelector('[data-gp="stroke"] [data-k="sw"]').max = mx;
      M.querySelector('[data-gp="text"] [data-k="ts"]').max = Math.max(200, Math.round(Math.min(W, H) * 0.4));
      ['shb', 'shx', 'shy'].forEach(k2 => { const i = M.querySelector('[data-k="' + k2 + '"]'); const lim = Math.max(60, Math.round(Math.min(W, H) * 0.06)); i.max = lim; if (k2 !== 'shb') i.min = -lim; });
      M.querySelector('[data-gp="shape"] .ie-pwrap').classList.toggle('d-none', def.p === undefined);
      if (def.pn) M.querySelector('[data-gp="shape"] [data-v="p"]').previousSibling.textContent = def.pn + ' ';
      M.querySelector('[data-gp="fill"] .ie-grad').classList.toggle('d-none', o.ft !== 'grad');
      fillCtls(M.querySelector('[data-p="shapes"]'), o);
    } else if (E.tab === 'mask') {
      const m = E.s.mask, box = M.querySelector('[data-p="mask"]');
      box.classList.toggle('has-sel', !!m);
      M.querySelectorAll('[data-mq]').forEach(b => b.classList.toggle('active', (m ? m.t : '') === b.dataset.mq));
      if (!m) return;
      const def = LIB().byId[m.t];
      box.querySelector('.ie-pwrap').classList.toggle('d-none', def.p === undefined);
      box.querySelector('.ie-mcol').classList.toggle('d-none', m.out !== 'color');
      box.querySelector('.ie-mamt').classList.toggle('d-none', m.out === 'transparent');
      const [W, H] = baseDims(); box.querySelector('[data-k="feather"]').max = Math.max(50, Math.round(Math.max(W, H) * 0.15));
      fillCtls(box, { out: m.out, mcolor: m.color, amt: m.amt, feather: m.feather, inv: m.inv, all: m.all, mp: m.p ?? def.p ?? 0.5, mrot: m.rot || 0 });
    }
  }
  function fillCtls(root, o) {
    root.querySelectorAll('[data-k]').forEach(i => {
      const k = i.dataset.k; let v = o[k];
      if (k === 'p' && v === undefined) v = (LIB().byId[o.t] || {}).p ?? 0.5;
      if (i.type === 'checkbox') i.checked = !!v;
      else if (document.activeElement !== i) i.value = v ?? (i.type === 'color' ? '#000000' : '');
      const b = root.querySelector('[data-v="' + k + '"]'); if (b) b.textContent = fmt(k, +i.value, i.dataset.u || '');
    });
    root.querySelectorAll('[data-seg]').forEach(sg => sg.querySelectorAll('[data-sv]').forEach(b => b.classList.toggle('active', String(o[sg.dataset.seg] ?? (sg.dataset.seg === 'dash' ? 'solid' : sg.dataset.seg === 'tal' ? 'center' : '')) === b.dataset.sv)));
    root.querySelectorAll('[data-tog]').forEach(b => b.classList.toggle('active', !!o[b.dataset.tog]));
    root.querySelectorAll('[data-sw]').forEach(b => b.classList.toggle('active', String(o[b.dataset.sw === 'mcolor' ? 'mcolor' : b.dataset.sw] || '').toLowerCase() === b.dataset.c));
  }
  function syncUi() {
    const M = E.M;
    ['angle', ...ADJ.map(a => a[0])].forEach(k => { const i = M.querySelector('[data-adj="' + k + '"]'); if (i) i.value = E.s[k]; const v = M.querySelector('[data-val="' + k + '"]'); if (v) v.textContent = k === 'angle' ? E.s.angle + '°' : (E.s[k] > 0 ? '+' : '') + E.s[k]; });
    M.querySelectorAll('[data-ratio]').forEach(b => b.classList.toggle('active', String(E.s.ratio) === b.dataset.ratio));
    M.querySelectorAll('.ie-filter').forEach(b => b.classList.toggle('active', b.dataset.filter === E.s.filter));
    M.querySelector('[data-q]').value = E.s.quality; M.querySelector('[data-val="q"]').textContent = E.s.quality + ' %';
    M.querySelector('.ie-qual').classList.toggle('d-none', !/jpe?g|webp/.test(E.mime) || needsAlpha());
    const changed = JSON.stringify(E.s) !== JSON.stringify(fresh());
    M.querySelector('[data-ie="reset"]').disabled = !changed;
    M.querySelector('[data-ie="undo"]').disabled = E.hi <= 0;
    M.querySelector('[data-ie="redo"]').disabled = E.hi >= E.hist.length - 1;
    syncProps();
  }
  let rq = 0;
  const redraw = () => { cancelAnimationFrame(rq); rq = requestAnimationFrame(() => { if (E && E.img) { draw(); syncUi(); } }); };

  /* ---------- história (Späť / Znova) ---------- */
  function commit() {
    const j = JSON.stringify(E.s);
    if (j === E.hist[E.hi]) return;
    E.hist = E.hist.slice(0, E.hi + 1); E.hist.push(j);
    if (E.hist.length > 100) E.hist.shift();
    E.hi = E.hist.length - 1;
    syncUi();
  }
  let cT = 0; const commitSoon = () => { clearTimeout(cT); cT = setTimeout(commit, 400); };
  function histGo(d) {
    commit();
    const i = E.hi + d; if (i < 0 || i >= E.hist.length) return;
    E.hi = i; E.s = JSON.parse(E.hist[i]);
    if (E.sel && !E.s.shapes.some(o => o.id === E.sel)) E.sel = null;
    redraw();
  }

  /* ---------- tvary ---------- */
  let uid = 0;
  const nid = () => 's' + Date.now().toString(36) + (uid++);
  function addShape(t, at) {
    const def = LIB().byId[t]; if (!def) return;
    const [W, H] = baseDims(), cr = view(), vw = cr.w * W, vh = cr.h * H, m = Math.min(vw, vh), a = def.a || 1;
    let w = m * (def.text ? 0.6 : 0.35), h = w / a; if (h > vh * 0.6) { h = vh * 0.6; w = h * a; }
    const n = E.s.shapes.length % 6;
    const o = { id: nid(), t, cx: cr.x * W + vw / 2 + n * m * 0.03, cy: cr.y * H + vh / 2 + n * m * 0.03, w, h, rot: 0, op: 100, blend: 'source-over',
      ft: def.line || def.text ? 'none' : 'solid', fill: E.lastFill || '#1a73e8', f2: '#ffffff', ga: 90, fa: 100,
      sc: def.line ? (E.lastStroke || '#ffffff') : '#ffffff', sw: def.line ? Math.max(2, Math.round(m * 0.012)) : 0, sa: 100, dash: 'solid',
      shadow: false, shc: '#000000', sha: 45, shb: Math.round(m * 0.02), shx: Math.round(m * 0.008), shy: Math.round(m * 0.01),
      text: def.text ? 'Text' : '', tc: '#ffffff', ts: Math.max(12, Math.round(m * (def.text ? 0.09 : 0.06))), tf: 'sans', tb: !!def.text, ti: false, tal: 'center' };
    if (def.p !== undefined) o.p = def.p;
    if (at) Object.assign(o, at);
    E.s.shapes.push(o); E.sel = o.id;
    E.group = def.text ? 'text' : def.line ? 'stroke' : 'fill';
    commit(); redraw();
    return o;
  }
  function addMask(t, preset) {
    const [W, H] = baseDims(), cr = view(), vw = cr.w * W, vh = cr.h * H, m = Math.min(vw, vh), def = LIB().byId[t] || LIB().byId.ellipse, a = def.a || 1;
    const old = E.s.mask || {};
    let w = m * 0.8, h = w / a;
    if (preset) { w = vw * 0.8; h = vh * 0.8; }
    E.s.mask = { t: def.id, cx: old.t ? old.cx : cr.x * W + vw / 2, cy: old.t ? old.cy : cr.y * H + vh / 2, w: old.t && !preset ? old.w : w, h: old.t && !preset ? old.w / a : h, rot: old.rot || 0,
      out: old.out || 'transparent', color: old.color || '#ffffff', amt: old.amt ?? 60, feather: old.feather ?? 0, inv: !!old.inv, all: !!old.all };
    if (def.p !== undefined) E.s.mask.p = def.p;
    if (preset === 'vignette') Object.assign(E.s.mask, { out: 'dark', amt: 70, feather: Math.round(Math.max(vw, vh) * 0.15), w: vw * 0.85, h: vh * 0.85, cx: cr.x * W + vw / 2, cy: cr.y * H + vh / 2, inv: false });
    if (preset === 'focus') Object.assign(E.s.mask, { out: 'blur', amt: 50, feather: Math.round(Math.max(vw, vh) * 0.06), w: vw * 0.55, h: vh * 0.55, cx: cr.x * W + vw / 2, cy: cr.y * H + vh / 2, inv: false });
    commit(); redraw();
  }
  function xformObjs(fn) { // pri otočení / preklopení obrázka idú tvary a maska s ním
    const [W, H] = baseDims();
    [...E.s.shapes, ...(E.s.mask ? [E.s.mask] : [])].forEach(o => fn(o, W, H));
  }
  function hitTest(cx, cy) {
    const mp = mapper(), [bx, by] = mp.toB(cx, cy), x = canvas(1, 1).getContext('2d');
    for (let i = E.s.shapes.length - 1; i >= 0; i--) {
      const o = E.s.shapes[i], def = LIB().byId[o.t] || {}, path = shapePath(o);
      x.setTransform(1, 0, 0, 1, 0, 0); place(x, o);
      x.lineWidth = Math.max(o.sw || 0, 16 / mp.d);
      if (def.line ? x.isPointInStroke(path, bx, by) : (x.isPointInPath(path, bx, by, def.eo ? 'evenodd' : 'nonzero') || x.isPointInStroke(path, bx, by))) return o;
      // vnútro rámčeka tvaru – ľahšie sa trafí malý alebo dierovaný tvar
      const lx = bx - o.cx, ly = by - o.cy, a = -(o.rot || 0) * Math.PI / 180, rx = lx * Math.cos(a) - ly * Math.sin(a), ry = lx * Math.sin(a) + ly * Math.cos(a);
      if (Math.abs(rx) <= o.w / 2 && Math.abs(ry) <= o.h / 2 && (o.w * mp.d < 60 || o.h * mp.d < 60 || def.eo || o.ft === 'none')) return o;
    }
    return null;
  }
  function openLib(mode) {
    const M = E.M, lib = M.querySelector('.ie-lib'), L = LIB();
    E.libMode = mode;
    lib.querySelector('.ie-lib-title').textContent = mode === 'mask' ? 'Tvar masky' : mode === 'swap' ? 'Zmeniť tvar' : 'Pridať tvar';
    const cats = mode === 'mask' ? L.cats.filter(c => c !== 'Čiary' && c !== 'Text') : L.cats;
    lib.querySelector('.ie-lib-cats').innerHTML = '<button class="ie-chip active" data-lcat="">Všetky (' + L.list.filter(s => cats.includes(s.cat)).length + ')</button>' + cats.map(c => '<button class="ie-chip" data-lcat="' + esc(c) + '">' + esc(c) + '</button>').join('');
    lib.querySelector('[data-lib-q]').value = '';
    libFill('');
    lib.classList.remove('d-none');
    if (matchMedia('(hover: hover)').matches) lib.querySelector('[data-lib-q]').focus();
  }
  const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function libFill(cat) {
    const M = E.M, lib = M.querySelector('.ie-lib'), L = LIB(), q = norm(lib.querySelector('[data-lib-q]').value.trim());
    const cats = E.libMode === 'mask' ? L.cats.filter(c => c !== 'Čiary' && c !== 'Text') : L.cats;
    lib.querySelectorAll('[data-lcat]').forEach(b => b.classList.toggle('active', b.dataset.lcat === cat));
    const list = L.list.filter(s => cats.includes(s.cat) && (!cat || s.cat === cat) && (!q || norm(s.name).includes(q) || norm(s.cat).includes(q)));
    let h = '', last = '';
    list.forEach(s => { if (!cat && !q && s.cat !== last) { h += '<div class="ie-lib-cat">' + esc(s.cat) + '</div>'; last = s.cat; } h += '<button class="ie-shape" data-shape="' + s.id + '" title="' + esc(s.name) + '">' + L.svg(s, 40) + '<span>' + esc(s.name) + '</span></button>'; });
    lib.querySelector('.ie-lib-grid').innerHTML = h || '<div class="ie-hint p-3">Nič sa nenašlo.</div>';
  }

  /* ---------- otvorenie ---------- */
  async function open(it, o) {
    if (!document.getElementById('imgEditModal')) { document.body.insertAdjacentHTML('beforeend', TPL); wire(); }
    const M = document.getElementById('imgEditModal');
    E = { it, o, M, s: fresh(), tab: 'crop', group: 'fill', sel: null, mime: it.mime || 'image/jpeg', modal: bootstrap.Modal.getOrCreateInstance(M), hist: [], hi: -1 };
    E.hist = [JSON.stringify(E.s)]; E.hi = 0;
    M.querySelector('.ie-name').textContent = it.name;
    M.querySelector('.ie-canvas').width = 0;
    M.querySelector('.ie-lib').classList.add('d-none');
    M.querySelectorAll('.ie-mq').forEach(b => { const s = LIB().byId[b.dataset.mq]; b.innerHTML = LIB().svg(s, 22); b.title = s.name; });
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
    M.querySelector('.ie-lib').classList.add('d-none');
    if (t === 'mask' && E.img && !E.s.mask) { /* maska sa pridá až výberom tvaru */ }
    if (E.img) redraw(); else syncUi();
  }

  /* ---------- uloženie ---------- */
  const needsAlpha = () => !!(E.s.mask && E.s.mask.out === 'transparent');
  function outType() {
    if (/png/.test(E.mime)) return ['image/png', 'png'];
    if (/webp/.test(E.mime)) return ['image/webp', 'webp'];
    if (needsAlpha()) return ['image/png', 'png']; // priehľadné okolie – JPEG priehľadnosť nevie
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
    if (!sameType) asCopy = true; // gif/bmp, alebo JPEG s priehľadnou maskou → kópia .png
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
    E.cache = null;
    E.o.onClose && E.o.onClose();
  }

  /* ---------- ovládanie ---------- */
  function setProp(k, v) { // zmena vlastnosti vybraného tvaru alebo masky
    if (E.tab === 'mask') {
      const m = E.s.mask; if (!m) return;
      const map = { mcolor: 'color', mp: 'p', mrot: 'rot' };
      m[map[k] || k] = v;
    } else {
      const o = target(); if (!o) return;
      o[k] = v;
      if (k === 'fill') E.lastFill = v;
      if (k === 'sc') E.lastStroke = v;
      if (k === 'fill' && o.ft === 'none') o.ft = 'solid';
      if (k === 'sw' && v > 0 && (o.sa ?? 100) === 0) o.sa = 100;
      if (k === 'text' && v && !o.ts) o.ts = 40;
      if (['shc', 'sha', 'shb', 'shx', 'shy'].includes(k)) o.shadow = true;
    }
    redraw();
  }
  function wire() {
    const M = document.getElementById('imgEditModal');
    M.addEventListener('click', (e) => {
      if (!E) return;
      const t = e.target.closest('[data-tab]'); if (t) return tab(t.dataset.tab);
      const r = e.target.closest('[data-ratio]'); if (r) { E.s.ratio = isNaN(+r.dataset.ratio) ? r.dataset.ratio : +r.dataset.ratio; if (E.s.ratio === 'free') E.s.crop = null; else applyRatio(); commit(); return redraw(); }
      const f = e.target.closest('[data-filter]'); if (f) { E.s.filter = f.dataset.filter; commit(); return redraw(); }
      const p = e.target.closest('[data-pct]'); if (p) { const [w] = outSizeNoResize(); E.s.outW = +p.dataset.pct === 100 ? 0 : Math.round(w * p.dataset.pct / 100); commit(); return redraw(); }
      const mx = e.target.closest('[data-max]'); if (mx) { const [w, h] = outSizeNoResize(); const m = +mx.dataset.max; E.s.outW = Math.max(w, h) > m ? Math.round(w * m / Math.max(w, h)) : 0; commit(); return redraw(); }
      const g = e.target.closest('[data-g]'); if (g) { E.group = g.dataset.g; return syncProps(); }
      const sh = e.target.closest('[data-shape]');
      if (sh) {
        M.querySelector('.ie-lib').classList.add('d-none');
        if (E.libMode === 'mask') return addMask(sh.dataset.shape);
        if (E.libMode === 'swap') { const o = target(); if (o) { const def = LIB().byId[sh.dataset.shape]; o.t = def.id; o.p = def.p; if (def.a) o.h = o.w / def.a; commit(); redraw(); } return; }
        return addShape(sh.dataset.shape);
      }
      const lc = e.target.closest('[data-lcat]'); if (lc) return libFill(lc.dataset.lcat);
      const mq = e.target.closest('[data-mq]'); if (mq) { if (!mq.dataset.mq) { E.s.mask = null; commit(); return redraw(); } return addMask(mq.dataset.mq); }
      const mp = e.target.closest('[data-mpre]'); if (mp) return addMask('ellipse', mp.dataset.mpre);
      const sw = e.target.closest('[data-sw]'); if (sw) { setProp(sw.dataset.sw, sw.dataset.c); return commit(); }
      const sv = e.target.closest('[data-sv]');
      if (sv) {
        const k = sv.closest('[data-seg]').dataset.seg;
        if (E.tab === 'mask') setProp(k, sv.dataset.sv);
        else { setProp(k, sv.dataset.sv); if (k === 'ft' && sv.dataset.sv !== 'none' && (target().fa ?? 100) === 0) target().fa = 100; }
        return commit();
      }
      const tg = e.target.closest('[data-tog]'); if (tg) { const o = E.tab === 'mask' ? E.s.mask : target(); if (o) { setProp(tg.dataset.tog, !o[tg.dataset.tog]); commit(); } return; }
      const b = e.target.closest('[data-ie]'); if (!b || !E.img && b.dataset.ie !== 'close') return;
      const s = E.s, o = target();
      ({
        close: () => close(false), save: () => save(false), copy: () => save(true), download,
        undo: () => histGo(-1), redo: () => histGo(1),
        reset: () => { E.s = fresh(); E.sel = null; commit(); redraw(); },
        rotl: () => { xformObjs((q, W) => { const x0 = q.cx; q.cx = q.cy; q.cy = W - x0; q.rot = (q.rot || 0) - 90; }); s.rot = (s.rot + 270) % 360; s.crop = null; if (s.ratio !== 'free') applyRatio(); commit(); redraw(); },
        rotr: () => { xformObjs((q, W, H) => { const x0 = q.cx; q.cx = H - q.cy; q.cy = x0; q.rot = (q.rot || 0) + 90; }); s.rot = (s.rot + 90) % 360; s.crop = null; if (s.ratio !== 'free') applyRatio(); commit(); redraw(); },
        fliph: () => { xformObjs((q, W) => { q.cx = W - q.cx; q.rot = -(q.rot || 0); q.fh = !q.fh; }); s.flipH = !s.flipH; commit(); redraw(); },
        flipv: () => { xformObjs((q, W, H) => { q.cy = H - q.cy; q.rot = -(q.rot || 0); q.fv = !q.fv; }); s.flipV = !s.flipV; commit(); redraw(); },
        lib: () => openLib('add'), libmask: () => openLib('mask'), swap: () => openLib('swap'),
        libclose: () => M.querySelector('.ie-lib').classList.add('d-none'),
        addtext: () => addShape('text'),
        dup: () => { if (!o) return; const c = JSON.parse(JSON.stringify(o)); const [W, H] = baseDims(), d = Math.min(W, H) * 0.03; c.id = nid(); c.cx += d; c.cy += d; E.s.shapes.splice(E.s.shapes.indexOf(o) + 1, 0, c); E.sel = c.id; commit(); redraw(); },
        front: () => { if (!o) return; const i = E.s.shapes.indexOf(o); if (i < E.s.shapes.length - 1) { E.s.shapes.splice(i, 1); E.s.shapes.splice(i + 1, 0, o); commit(); redraw(); } },
        back: () => { if (!o) return; const i = E.s.shapes.indexOf(o); if (i > 0) { E.s.shapes.splice(i, 1); E.s.shapes.splice(i - 1, 0, o); commit(); redraw(); } },
        del: () => { if (!o) return; E.s.shapes = E.s.shapes.filter(x => x !== o); E.sel = null; commit(); redraw(); },
        sfh: () => { if (o) { o.fh = !o.fh; commit(); redraw(); } }, sfv: () => { if (o) { o.fv = !o.fv; commit(); redraw(); } },
        center: () => { if (!o) return; const [W, H] = baseDims(), cr = view(); o.cx = (cr.x + cr.w / 2) * W; o.cy = (cr.y + cr.h / 2) * H; commit(); redraw(); },
        mcrop: () => { const m = s.mask; if (!m) return; const [W, H] = baseDims(), a = (m.rot || 0) * Math.PI / 180, bw = Math.abs(m.w * Math.cos(a)) + Math.abs(m.h * Math.sin(a)), bh = Math.abs(m.w * Math.sin(a)) + Math.abs(m.h * Math.cos(a));
          const x0 = Math.max(0, m.cx - bw / 2) / W, y0 = Math.max(0, m.cy - bh / 2) / H, x1 = Math.min(W, m.cx + bw / 2) / W, y1 = Math.min(H, m.cy + bh / 2) / H;
          s.crop = { x: x0, y: y0, w: Math.max(0.01, x1 - x0), h: Math.max(0.01, y1 - y0) }; s.ratio = 'free'; commit(); redraw(); Cloud.toast('Obrázok je orezaný na masku.', 'success'); },
      })[b.dataset.ie]?.();
    });
    M.addEventListener('input', (e) => {
      if (!E) return;
      const a = e.target.closest('[data-adj]'); if (a) { E.s[a.dataset.adj] = +a.value; return redraw(); }
      if (e.target.matches('[data-q]')) { E.s.quality = +e.target.value; return syncUi(); }
      if (e.target.matches('[data-lib-q]')) return libFill('');
      const k = e.target.closest('[data-k]');
      if (k) {
        const v = k.type === 'checkbox' ? k.checked : k.type === 'range' ? +k.value : k.value;
        setProp(k.dataset.k, v);
        const bv = k.closest('.ie-props, .ie-mprops')?.querySelector('[data-v="' + k.dataset.k + '"]'); if (bv) bv.textContent = fmt(k.dataset.k, +k.value, k.dataset.u || '');
        if (k.tagName === 'TEXTAREA' || k.type === 'color') commitSoon();
      }
    });
    M.addEventListener('change', (e) => {
      if (!E) return;
      if (e.target.closest('[data-adj], [data-k]')) return commit();
      const z = e.target.closest('[data-size]'); if (!z) return;
      const [w, h] = outSizeNoResize(), v = Math.max(1, +z.value || 1);
      const nw = z.dataset.size === 'w' ? v : Math.round(v * w / h);
      E.s.outW = nw >= w ? 0 : nw;
      if (nw > w) Cloud.toast('Obrázok sa dá iba zmenšiť – zväčšenie by zhoršilo kvalitu.', 'warning');
      commit(); redraw();
    });
    M.addEventListener('dblclick', (e) => { const a = e.target.closest('.ie-slider'); if (a && a.querySelector('[data-adj]')) { const i = a.querySelector('input'); E.s[i.dataset.adj] = 0; commit(); redraw(); } });
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
    const end = () => { if (drag) { drag = null; commit(); syncUi(); } };
    M.querySelector('.ie-crop').addEventListener('pointerup', end);
    M.querySelector('.ie-crop').addEventListener('pointercancel', end);

    /* ťahanie tvarov a masky: presun, rohy = veľkosť, guľôčka = otočenie, dva prsty = veľkosť + otočenie */
    const objs = M.querySelector('.ie-objs'), pts = new Map();
    let od = null;
    objs.addEventListener('pointerdown', (e) => {
      if (!E || !E.img) return;
      e.preventDefault();
      pts.set(e.pointerId, [e.clientX, e.clientY]);
      try { objs.setPointerCapture(e.pointerId); } catch (x) {}
      if (pts.size === 2 && target()) { // dva prsty
        const o = target(), [a, b] = [...pts.values()];
        od = { pinch: true, o0: { ...o }, d0: Math.hypot(a[0] - b[0], a[1] - b[1]) || 1, a0: Math.atan2(b[1] - a[1], b[0] - a[0]), m0: [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2] };
        return;
      }
      if (pts.size > 1) return;
      const h = e.target.dataset.oh;
      let o = target();
      if (!h && E.tab === 'shapes') {
        const hit = hitTest(e.clientX, e.clientY);
        if (hit !== o) { E.sel = hit ? hit.id : null; if (hit && !LIB().byId[hit.t].line && E.group === 'stroke' && !(hit.sw > 0)) E.group = 'fill'; syncUi(); placeObjs(); }
        o = hit;
        if (!o) return;
      }
      if (!o) { // maska ešte nie je – ťuknutím ju pridáš (kruh)
        if (E.tab === 'mask') { addMask('ellipse'); o = E.s.mask; const mp = mapper(), [bx, by] = mp.toB(e.clientX, e.clientY); o.cx = bx; o.cy = by; redraw(); }
        return;
      }
      const now = Date.now();
      if (!h && E.tab === 'shapes' && E.lastTapId === o.id && now - E.lastTap < 320) { E.group = 'text'; syncProps(); setTimeout(() => M.querySelector('[data-gp="text"] textarea')?.focus(), 30); }
      E.lastTap = now; E.lastTapId = o.id;
      od = { h: h || 'move', o0: { ...o }, p0: [e.clientX, e.clientY], mp: mapper(), shift: e.shiftKey };
    });
    objs.addEventListener('pointermove', (e) => {
      if (!pts.has(e.pointerId)) return;
      pts.set(e.pointerId, [e.clientX, e.clientY]);
      const o = target(); if (!od || !o) return;
      if (od.pinch) {
        if (pts.size < 2) return;
        const [a, b] = [...pts.values()], d = Math.hypot(a[0] - b[0], a[1] - b[1]), ang = Math.atan2(b[1] - a[1], b[0] - a[0]), mp = mapper(), m = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2], f = d / od.d0;
        o.w = Math.max(4, od.o0.w * f); o.h = Math.max(4, od.o0.h * f);
        o.rot = Math.round((od.o0.rot || 0) + (ang - od.a0) * 180 / Math.PI);
        o.cx = od.o0.cx + (m[0] - od.m0[0]) / mp.d; o.cy = od.o0.cy + (m[1] - od.m0[1]) / mp.d;
        return redraw();
      }
      const mp = od.mp, dx = (e.clientX - od.p0[0]) / mp.d, dy = (e.clientY - od.p0[1]) / mp.d, o0 = od.o0;
      if (od.h === 'move') { o.cx = o0.cx + dx; o.cy = o0.cy + dy; }
      else if (od.h === 'rot') {
        const [sx, sy] = mp.toS(o0.cx, o0.cy), r = mp.r, ang = Math.atan2(e.clientY - (r.top + sy), e.clientX - (r.left + sx)) * 180 / Math.PI + 90;
        let a = Math.round(ang); if (!e.shiftKey) { const sn = Math.round(a / 45) * 45; if (Math.abs(a - sn) < 4) a = sn; } // prichytí sa k 0°, 45°, 90°…
        o.rot = ((a + 540) % 360) - 180;
      } else {
        const a = (o0.rot || 0) * Math.PI / 180, lx = dx * Math.cos(a) + dy * Math.sin(a), ly = -dx * Math.sin(a) + dy * Math.cos(a);
        const h = od.h, sx = h.includes('e') ? 1 : h.includes('w') ? -1 : 0, sy = h.includes('s') ? 1 : h.includes('n') ? -1 : 0;
        let nw = Math.max(4, o0.w + sx * lx), nh = Math.max(4, o0.h + sy * ly);
        if ((o.lock || e.shiftKey || E.tab === 'mask' && o.lock) && sx && sy) { const k = Math.max(nw / o0.w, nh / o0.h); nw = o0.w * k; nh = o0.h * k; }
        const cw = (nw - o0.w) / 2 * sx, ch = (nh - o0.h) / 2 * sy;
        o.w = nw; o.h = nh;
        o.cx = o0.cx + cw * Math.cos(a) - ch * Math.sin(a); o.cy = o0.cy + cw * Math.sin(a) + ch * Math.cos(a);
      }
      redraw();
    });
    const oend = (e) => { pts.delete(e.pointerId); if (pts.size < 2 && od && od.pinch) od = null; if (!pts.size && od) { od = null; commit(); } };
    objs.addEventListener('pointerup', oend); objs.addEventListener('pointercancel', oend);
    objs.addEventListener('wheel', (e) => { const o = target(); if (!o) return; e.preventDefault(); const f = Math.exp(-e.deltaY * 0.0015); if (e.altKey) o.rot = Math.round((o.rot || 0) + e.deltaY * 0.1); else { o.w *= f; o.h *= f; } redraw(); commitSoon(); }, { passive: false });

    // pri zmene veľkosti plochy (okno, otočenie mobilu, iná výška panelu) prekresliť
    let lastSz = '';
    new ResizeObserver(() => { const st = M.querySelector('.ie-stage'), k = st.clientWidth + 'x' + st.clientHeight; if (k !== lastSz && E && E.img && M.classList.contains('show')) { lastSz = k; redraw(); } }).observe(M.querySelector('.ie-stage'));
    document.addEventListener('keydown', (e) => {
      if (!M.classList.contains('show') || !E) return;
      const typing = e.target.matches('input[type=number], input[type=search], input[type=text], textarea');
      const mod = e.ctrlKey || e.metaKey, key = e.key.toLowerCase();
      if (e.key === 'Escape') {
        e.preventDefault();
        if (!M.querySelector('.ie-lib').classList.contains('d-none')) return M.querySelector('.ie-lib').classList.add('d-none');
        if (typing) return e.target.blur();
        if (E.tab === 'shapes' && E.sel) { E.sel = null; return redraw(); }
        return close(false);
      }
      if (mod && key === 's') { e.preventDefault(); return save(false); }
      if (typing) return;
      if (mod && key === 'z') { e.preventDefault(); return histGo(e.shiftKey ? 1 : -1); }
      if (mod && key === 'y') { e.preventDefault(); return histGo(1); }
      const o = target();
      if (!o || E.tab !== 'shapes' && E.tab !== 'mask') return;
      if (E.tab === 'shapes' && (e.key === 'Delete' || e.key === 'Backspace')) { e.preventDefault(); M.querySelector('[data-ie="del"]').click(); return; }
      if (E.tab === 'shapes' && mod && key === 'd') { e.preventDefault(); M.querySelector('[data-ie="dup"]').click(); return; }
      const st = (e.shiftKey ? 10 : 1) / mapper().d, mv = { ArrowLeft: [-st, 0], ArrowRight: [st, 0], ArrowUp: [0, -st], ArrowDown: [0, st] }[e.key];
      if (mv) { e.preventDefault(); o.cx += mv[0]; o.cy += mv[1]; redraw(); commitSoon(); }
    });
  }
  function outSizeNoResize() { const k = E.s.outW; E.s.outW = 0; const r = outSize(); E.s.outW = k; return r; }

  const canEdit = (it) => it && it.type === 'f' && /^image\/(jpeg|png|webp|gif|bmp)$/.test(it.mime || '');
  window.CloudImageEditor = { open, canEdit };
})();
