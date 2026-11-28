/* Knižnica tvarov pre editor obrázkov. Každý tvar je funkcia (w, h, p) → SVG cesta v rámčeku w × h.
   p = nastaviteľný parameter tvaru 0…1 (zaoblenie, hrúbka šípky, vnútorný polomer hviezdy…), ak ho tvar má.
   Kreslí sa cez Path2D (canvas) aj ako <svg> v ponuke – jedna definícia pre oboje. */
(function () {
  const r2 = (n) => Math.round(n * 100) / 100;
  const P = (pts, close = true) => 'M' + pts.map(([x, y]) => r2(x) + ' ' + r2(y)).join(' L') + (close ? ' Z' : '');
  const ell = (cx, cy, rx, ry) => 'M' + r2(cx - rx) + ' ' + r2(cy) + ' A' + r2(rx) + ' ' + r2(ry) + ' 0 1 0 ' + r2(cx + rx) + ' ' + r2(cy) + ' A' + r2(rx) + ' ' + r2(ry) + ' 0 1 0 ' + r2(cx - rx) + ' ' + r2(cy) + ' Z';
  const reg = (w, h, n, rot = -90) => Array.from({ length: n }, (_, i) => { const a = (rot + i * 360 / n) * Math.PI / 180; return [w / 2 + Math.cos(a) * w / 2, h / 2 + Math.sin(a) * h / 2]; });
  const star = (w, h, n, inner) => P(Array.from({ length: n * 2 }, (_, i) => { const a = (-90 + i * 180 / n) * Math.PI / 180, k = i % 2 ? inner : 1; return [w / 2 + Math.cos(a) * w / 2 * k, h / 2 + Math.sin(a) * h / 2 * k]; }));
  const rrect = (x, y, w, h, r) => { r = Math.max(0, Math.min(r, w / 2, h / 2)); if (!r) return P([[x, y], [x + w, y], [x + w, y + h], [x, y + h]]);
    return 'M' + r2(x + r) + ' ' + r2(y) + ' H' + r2(x + w - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(x + w) + ' ' + r2(y + r) + ' V' + r2(y + h - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(x + w - r) + ' ' + r2(y + h)
      + ' H' + r2(x + r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(x) + ' ' + r2(y + h - r) + ' V' + r2(y + r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(x + r) + ' ' + r2(y) + ' Z'; };
  const arcPt = (cx, cy, rx, ry, deg) => { const a = deg * Math.PI / 180; return [cx + Math.cos(a) * rx, cy + Math.sin(a) * ry]; };
  const pie = (w, h, a0, a1, inner = 0) => { // výseč / oblúk (uhly v stupňoch, 0 = vpravo, v smere hodín)
    const cx = w / 2, cy = h / 2, big = ((a1 - a0 + 360) % 360) > 180 ? 1 : 0;
    const [x0, y0] = arcPt(cx, cy, w / 2, h / 2, a0), [x1, y1] = arcPt(cx, cy, w / 2, h / 2, a1);
    if (!inner) return 'M' + r2(cx) + ' ' + r2(cy) + ' L' + r2(x0) + ' ' + r2(y0) + ' A' + r2(w / 2) + ' ' + r2(h / 2) + ' 0 ' + big + ' 1 ' + r2(x1) + ' ' + r2(y1) + ' Z';
    const ix = w / 2 * inner, iy = h / 2 * inner, [x2, y2] = arcPt(cx, cy, ix, iy, a1), [x3, y3] = arcPt(cx, cy, ix, iy, a0);
    return 'M' + r2(x0) + ' ' + r2(y0) + ' A' + r2(w / 2) + ' ' + r2(h / 2) + ' 0 ' + big + ' 1 ' + r2(x1) + ' ' + r2(y1) + ' L' + r2(x2) + ' ' + r2(y2) + ' A' + r2(ix) + ' ' + r2(iy) + ' 0 ' + big + ' 0 ' + r2(x3) + ' ' + r2(y3) + ' Z';
  };
  const wave = (x0, x1, y, amp, n, dir = 1) => { let d = '', seg = (x1 - x0) / n; for (let i = 0; i < n; i++) d += ' Q' + r2(x0 + seg * (i + .5) * dir) + ' ' + r2(y + (i % 2 ? -amp : amp)) + ' ' + r2(x0 + seg * (i + 1) * dir) + ' ' + r2(y); return d; };
  const arrowR = (w, h, p) => { const t = h * (0.2 + p * 0.6) / 2, hw = Math.min(w * 0.6, h * 0.9); return P([[0, h / 2 - t], [w - hw, h / 2 - t], [w - hw, 0], [w, h / 2], [w - hw, h], [w - hw, h / 2 + t], [0, h / 2 + t]]); };

  const S = [];
  const add = (cat, id, name, f, o = {}) => S.push(Object.assign({ cat, id, name, f }, o));

  /* ---------- Základné ---------- */
  const B = 'Základné';
  add(B, 'rect', 'Obdĺžnik', (w, h) => P([[0, 0], [w, 0], [w, h], [0, h]]));
  add(B, 'rrect', 'Zaoblený obdĺžnik', (w, h, p) => rrect(0, 0, w, h, Math.min(w, h) / 2 * p), { p: 0.3, pn: 'Zaoblenie' });
  add(B, 'ellipse', 'Kruh / elipsa', (w, h) => ell(w / 2, h / 2, w / 2, h / 2));
  add(B, 'tri', 'Trojuholník', (w, h, p) => P([[w * p, 0], [w, h], [0, h]]), { p: 0.5, pn: 'Vrchol' });
  add(B, 'rtri', 'Pravouhlý trojuholník', (w, h) => P([[0, 0], [w, h], [0, h]]));
  add(B, 'diamond', 'Kosoštvorec', (w, h) => P([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]));
  add(B, 'para', 'Rovnobežník', (w, h, p) => P([[w * p * 0.5, 0], [w, 0], [w - w * p * 0.5, h], [0, h]]), { p: 0.4, pn: 'Sklon', a: 1.5 });
  add(B, 'trap', 'Lichobežník', (w, h, p) => P([[w * p * 0.5, 0], [w - w * p * 0.5, 0], [w, h], [0, h]]), { p: 0.4, pn: 'Zúženie', a: 1.4 });
  [[5, 'Päťuholník'], [6, 'Šesťuholník'], [7, 'Sedemuholník'], [8, 'Osemuholník'], [10, 'Desaťuholník'], [12, 'Dvanásťuholník']].forEach(([n, nm]) =>
    add(B, 'poly' + n, nm, (w, h) => P(reg(w, h, n, n % 2 ? -90 : -90 + 180 / n))));
  add(B, 'cross', 'Kríž', (w, h, p) => { const tx = w * (0.15 + p * 0.35), ty = h * (0.15 + p * 0.35), x0 = (w - tx) / 2, y0 = (h - ty) / 2;
    return P([[x0, 0], [x0 + tx, 0], [x0 + tx, y0], [w, y0], [w, y0 + ty], [x0 + tx, y0 + ty], [x0 + tx, h], [x0, h], [x0, y0 + ty], [0, y0 + ty], [0, y0], [x0, y0]]); }, { p: 0.3, pn: 'Hrúbka' });
  add(B, 'ring', 'Prstenec', (w, h, p) => ell(w / 2, h / 2, w / 2, h / 2) + ' ' + ell(w / 2, h / 2, w / 2 * (0.9 - p * 0.8), h / 2 * (0.9 - p * 0.8)), { p: 0.3, pn: 'Hrúbka', eo: true });
  add(B, 'half', 'Polkruh', (w, h) => 'M0 ' + r2(h) + ' A' + r2(w / 2) + ' ' + r2(h) + ' 0 0 1 ' + r2(w) + ' ' + r2(h) + ' Z', { a: 2 });
  add(B, 'quarter', 'Štvrťkruh', (w, h) => 'M0 ' + r2(h) + ' V0 A' + r2(w) + ' ' + r2(h) + ' 0 0 1 ' + r2(w) + ' ' + r2(h) + ' Z');
  add(B, 'pie', 'Výseč (koláč)', (w, h, p) => pie(w, h, -90 + 360 * (0.05 + p * 0.9), 270), { p: 0.25, pn: 'Uhol' });
  add(B, 'chord', 'Odsek kruhu', (w, h, p) => { const a = 20 + p * 140, [x0, y0] = arcPt(w / 2, h / 2, w / 2, h / 2, -90 - a), [x1, y1] = arcPt(w / 2, h / 2, w / 2, h / 2, -90 + a); return 'M' + r2(x0) + ' ' + r2(y0) + ' A' + r2(w / 2) + ' ' + r2(h / 2) + ' 0 1 0 ' + r2(x1) + ' ' + r2(y1) + ' Z'; }, { p: 0.4, pn: 'Odrez' });
  add(B, 'barc', 'Oblúk (blok)', (w, h, p) => pie(w, h, 180, 0, 0.9 - p * 0.8), { p: 0.3, pn: 'Hrúbka' });
  add(B, 'tear', 'Slza', (w, h, p) => 'M' + r2(w / 2) + ' 0 H' + r2(w / 2 + w / 2 * p) + ' L' + r2(w) + ' ' + r2(h / 2 - h / 2 * p) + ' V' + r2(h / 2) + ' A' + r2(w / 2) + ' ' + r2(h / 2) + ' 0 1 1 ' + r2(w / 2) + ' 0 Z', { p: 1, pn: 'Hrot' });
  add(B, 'snip', 'Odrezaný roh', (w, h, p) => { const c = Math.min(w, h) * p * 0.5; return P([[0, 0], [w - c, 0], [w, c], [w, h], [0, h]]); }, { p: 0.4, pn: 'Roh' });
  add(B, 'snip2', 'Odrezané rohy', (w, h, p) => { const c = Math.min(w, h) * p * 0.5; return P([[c, 0], [w - c, 0], [w, c], [w, h - c], [w - c, h], [c, h], [0, h - c], [0, c]]); }, { p: 0.4, pn: 'Rohy' });
  add(B, 'frame', 'Rámik', (w, h, p) => { const t = Math.min(w, h) * (0.04 + p * 0.4); return P([[0, 0], [w, 0], [w, h], [0, h]]) + ' ' + P([[t, t], [w - t, t], [w - t, h - t], [t, h - t]]); }, { p: 0.2, pn: 'Hrúbka', eo: true });
  add(B, 'cube', 'Kocka', (w, h, p) => { const d = Math.min(w, h) * (0.1 + p * 0.4);
    return P([[0, d], [d, 0], [w, 0], [w, h - d], [w - d, h], [0, h]]) + ' M0 ' + r2(d) + ' H' + r2(w - d) + ' L' + r2(w) + ' 0 M' + r2(w - d) + ' ' + r2(d) + ' V' + r2(h); }, { p: 0.4, pn: 'Hĺbka' });
  add(B, 'cyl', 'Valec', (w, h, p) => { const e = h * (0.05 + p * 0.2); return 'M0 ' + r2(e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 1 ' + r2(w) + ' ' + r2(e) + ' V' + r2(h - e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 1 0 ' + r2(h - e) + ' Z M0 ' + r2(e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 0 ' + r2(w) + ' ' + r2(e); }, { p: 0.4, pn: 'Perspektíva', a: 0.75 });

  /* ---------- Hviezdy a výbuchy ---------- */
  const H = 'Hviezdy';
  [[4, .4], [5, .45], [6, .55], [7, .55], [8, .6], [10, .6], [12, .65], [16, .7], [24, .75], [32, .8]].forEach(([n, i]) =>
    add(H, 'star' + n, n + '-cípa hviezda', (w, h, p) => star(w, h, n, 0.15 + p * 0.8), { p: (i - 0.15) / 0.8, pn: 'Vnútro' }));
  add(H, 'burst', 'Výbuch', (w, h) => P([[.08, .13], [.37, .24], [.5, .02], [.6, .26], [.9, .08], [.78, .38], [1, .5], [.8, .62], [.94, .92], [.6, .76], [.48, 1], [.38, .76], [.07, .88], [.22, .6], [0, .44], [.24, .36]].map(([x, y]) => [x * w, y * h])), { a: 1.2 });
  add(H, 'burst2', 'Výbuch 2', (w, h) => P([[.15, .2], [.33, .27], [.44, 0], [.55, .24], [.75, .07], [.73, .3], [.97, .26], [.82, .45], [1, .62], [.77, .65], [.84, .95], [.6, .76], [.43, 1], [.37, .78], [.12, .9], [.2, .66], [0, .55], [.2, .43]].map(([x, y]) => [x * w, y * h])), { a: 1.2 });
  add(H, 'seal', 'Pečať', (w, h, p) => { let d = '', n = 20; for (let i = 0; i <= n; i++) { const a = (i / n) * Math.PI * 2 - Math.PI / 2, m = (i + .5) / n * Math.PI * 2 - Math.PI / 2, k = 0.86 + p * 0.1;
      const x = w / 2 + Math.cos(a) * w / 2 * k, y = h / 2 + Math.sin(a) * h / 2 * k; d += (i ? ' Q' + r2(w / 2 + Math.cos(m - Math.PI / n) * w / 2 * 1.04) + ' ' + r2(h / 2 + Math.sin(m - Math.PI / n) * h / 2 * 1.04) + ' ' : 'M') + r2(x) + ' ' + r2(y); } return d + ' Z'; }, { p: 0.5, pn: 'Vlnky' });
  add(H, 'sparkle', 'Iskra', (w, h, p) => { const k = 0.05 + p * 0.3; return 'M' + r2(w / 2) + ' 0 Q' + r2(w / 2 + w * k) + ' ' + r2(h / 2 - h * k) + ' ' + r2(w) + ' ' + r2(h / 2) + ' Q' + r2(w / 2 + w * k) + ' ' + r2(h / 2 + h * k) + ' ' + r2(w / 2) + ' ' + r2(h) + ' Q' + r2(w / 2 - w * k) + ' ' + r2(h / 2 + h * k) + ' 0 ' + r2(h / 2) + ' Q' + r2(w / 2 - w * k) + ' ' + r2(h / 2 - h * k) + ' ' + r2(w / 2) + ' 0 Z'; }, { p: 0.2, pn: 'Hrúbka' });

  /* ---------- Šípky ---------- */
  const A = 'Šípky';
  add(A, 'arR', 'Šípka vpravo', arrowR, { p: 0.4, pn: 'Hrúbka', a: 2 });
  add(A, 'arL', 'Šípka vľavo', (w, h, p) => { const t = h * (0.2 + p * 0.6) / 2, hw = Math.min(w * 0.6, h * 0.9); return P([[w, h / 2 - t], [hw, h / 2 - t], [hw, 0], [0, h / 2], [hw, h], [hw, h / 2 + t], [w, h / 2 + t]]); }, { p: 0.4, pn: 'Hrúbka', a: 2 });
  add(A, 'arU', 'Šípka hore', (w, h, p) => { const t = w * (0.2 + p * 0.6) / 2, hh = Math.min(h * 0.6, w * 0.9); return P([[w / 2 - t, h], [w / 2 - t, hh], [0, hh], [w / 2, 0], [w, hh], [w / 2 + t, hh], [w / 2 + t, h]]); }, { p: 0.4, pn: 'Hrúbka', a: 0.5 });
  add(A, 'arD', 'Šípka dole', (w, h, p) => { const t = w * (0.2 + p * 0.6) / 2, hh = Math.min(h * 0.6, w * 0.9); return P([[w / 2 - t, 0], [w / 2 - t, h - hh], [0, h - hh], [w / 2, h], [w, h - hh], [w / 2 + t, h - hh], [w / 2 + t, 0]]); }, { p: 0.4, pn: 'Hrúbka', a: 0.5 });
  add(A, 'arLR', 'Obojsmerná šípka', (w, h, p) => { const t = h * (0.2 + p * 0.6) / 2, hw = Math.min(w * 0.4, h * 0.9); return P([[0, h / 2], [hw, 0], [hw, h / 2 - t], [w - hw, h / 2 - t], [w - hw, 0], [w, h / 2], [w - hw, h], [w - hw, h / 2 + t], [hw, h / 2 + t], [hw, h]]); }, { p: 0.4, pn: 'Hrúbka', a: 2.4 });
  add(A, 'arUD', 'Šípka hore-dole', (w, h, p) => { const t = w * (0.2 + p * 0.6) / 2, hh = Math.min(h * 0.4, w * 0.9); return P([[w / 2, 0], [w, hh], [w / 2 + t, hh], [w / 2 + t, h - hh], [w, h - hh], [w / 2, h], [0, h - hh], [w / 2 - t, h - hh], [w / 2 - t, hh], [0, hh]]); }, { p: 0.4, pn: 'Hrúbka', a: 0.42 });
  add(A, 'ar4', 'Štvorsmerná šípka', (w, h, p) => { const t = Math.min(w, h) * (0.06 + p * 0.14), a = Math.min(w, h) * 0.22, cx = w / 2, cy = h / 2;
    return P([[cx, 0], [cx + a, a], [cx + t, a], [cx + t, cy - t], [w - a, cy - t], [w - a, cy - a], [w, cy], [w - a, cy + a], [w - a, cy + t], [cx + t, cy + t], [cx + t, h - a], [cx + a, h - a], [cx, h], [cx - a, h - a], [cx - t, h - a], [cx - t, cy + t], [a, cy + t], [a, cy + a], [0, cy], [a, cy - a], [a, cy - t], [cx - t, cy - t], [cx - t, a], [cx - a, a]]); }, { p: 0.4, pn: 'Hrúbka' });
  add(A, 'arBent', 'Zalomená šípka', (w, h, p) => { const t = Math.min(w, h) * (0.12 + p * 0.2), a = Math.min(w, h) * 0.42, cy = a / 2;
    return P([[0, h], [0, cy - t / 2], [w - a * 0.75, cy - t / 2], [w - a * 0.75, 0], [w, cy], [w - a * 0.75, a], [w - a * 0.75, cy + t / 2], [t, cy + t / 2], [t, h]]); }, { p: 0.4, pn: 'Hrúbka' });
  add(A, 'arUturn', 'Otočka (U)', (w, h, p) => { const t = w * (0.1 + p * 0.15), r = (w * 0.7) / 2, hw = w * 0.3;
    return 'M0 ' + r2(h) + ' V' + r2(r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 1 ' + r2(2 * r) + ' ' + r2(r) + ' V' + r2(h * 0.6) + ' H' + r2(2 * r + hw / 2) + ' L' + r2(2 * r - t / 2) + ' ' + r2(h * 0.6 + hw) + ' L' + r2(2 * r - t - hw / 2) + ' ' + r2(h * 0.6) + ' H' + r2(2 * r - t) + ' V' + r2(r) + ' A' + r2(r - t) + ' ' + r2(r - t) + ' 0 0 0 ' + r2(t) + ' ' + r2(r) + ' V' + r2(h) + ' Z'; }, { p: 0.4, pn: 'Hrúbka', a: 0.9 });
  add(A, 'chevron', 'Krokvica (chevron)', (w, h, p) => { const d = w * (0.15 + p * 0.5); return P([[0, 0], [w - d, 0], [w, h / 2], [w - d, h], [0, h], [d, h / 2]]); }, { p: 0.4, pn: 'Hrot', a: 1.5 });
  add(A, 'home', 'Päťuholníková šípka', (w, h, p) => { const d = w * (0.1 + p * 0.5); return P([[0, 0], [w - d, 0], [w, h / 2], [w - d, h], [0, h]]); }, { p: 0.4, pn: 'Hrot', a: 2 });
  add(A, 'arNotch', 'Šípka so zárezom', (w, h, p) => { const t = h * (0.2 + p * 0.6) / 2, hw = Math.min(w * 0.5, h * 0.8); return P([[0, h / 2 - t], [w - hw, h / 2 - t], [w - hw, 0], [w, h / 2], [w - hw, h], [w - hw, h / 2 + t], [0, h / 2 + t], [hw * 0.4, h / 2]]); }, { p: 0.4, pn: 'Hrúbka', a: 2 });
  add(A, 'arStripe', 'Pruhovaná šípka', (w, h, p) => { const t = h * (0.2 + p * 0.6) / 2, hw = Math.min(w * 0.5, h * 0.8), s = w * 0.06;
    return P([[0, h / 2 - t], [s, h / 2 - t], [s, h / 2 + t], [0, h / 2 + t]]) + ' ' + P([[s * 2, h / 2 - t], [s * 3, h / 2 - t], [s * 3, h / 2 + t], [s * 2, h / 2 + t]]) + ' ' + P([[s * 4, h / 2 - t], [w - hw, h / 2 - t], [w - hw, 0], [w, h / 2], [w - hw, h], [w - hw, h / 2 + t], [s * 4, h / 2 + t]]); }, { p: 0.4, pn: 'Hrúbka', a: 2 });
  add(A, 'arCirc', 'Kruhová šípka', (w, h, p) => { const cx = w / 2, cy = h / 2, R = w / 2 * 0.78, Ry = h / 2 * 0.78, t = 0.12 + p * 0.2, r = R * (1 - t * 1.4), ry = Ry * (1 - t * 1.4), a0 = 150, a1 = 390;
    const [x0, y0] = arcPt(cx, cy, R, Ry, a0), [x1, y1] = arcPt(cx, cy, R, Ry, a1), [x3, y3] = arcPt(cx, cy, r, ry, a0), [x2, y2] = arcPt(cx, cy, r, ry, a1);
    const m = (R + r) / 2, my = (Ry + ry) / 2, hw = (R - r) * 1.1, [ox, oy] = arcPt(cx, cy, m + hw, my + hw * Ry / R, a1), [ix, iy] = arcPt(cx, cy, m - hw, my - hw * Ry / R, a1), [tx, ty] = arcPt(cx, cy, m, my, a1 + 32);
    return 'M' + r2(x0) + ' ' + r2(y0) + ' A' + r2(R) + ' ' + r2(Ry) + ' 0 1 1 ' + r2(x1) + ' ' + r2(y1) + ' L' + r2(ox) + ' ' + r2(oy) + ' L' + r2(tx) + ' ' + r2(ty) + ' L' + r2(ix) + ' ' + r2(iy) + ' L' + r2(x2) + ' ' + r2(y2) + ' A' + r2(r) + ' ' + r2(ry) + ' 0 1 0 ' + r2(x3) + ' ' + r2(y3) + ' Z'; }, { p: 0.4, pn: 'Hrúbka' });
  add(A, 'arCorner', 'Šípka do rohu', (w, h, p) => { const t = Math.min(w, h) * (0.12 + p * 0.2), a = Math.min(w, h) * 0.42, cx = w - a / 2;
    return P([[0, h - t], [cx - t / 2, h - t], [cx - t / 2, a * 0.75], [w - a, a * 0.75], [cx, 0], [w, a * 0.75], [cx + t / 2, a * 0.75], [cx + t / 2, h], [0, h]]); }, { p: 0.4, pn: 'Hrúbka' });
  add(A, 'arCurve', 'Zakrivená šípka', (w, h, p) => { const t = h * (0.12 + p * 0.2), hw = w * 0.28;
    return 'M0 ' + r2(h) + ' Q' + r2(w * 0.25) + ' ' + r2(h * 0.2) + ' ' + r2(w - hw) + ' ' + r2(h * 0.25 - t / 2) + ' V0 L' + r2(w) + ' ' + r2(h * 0.25 + t / 2) + ' L' + r2(w - hw) + ' ' + r2(h * 0.5 + t) + ' V' + r2(h * 0.25 + t * 1.5) + ' Q' + r2(w * 0.3) + ' ' + r2(h * 0.35 + t) + ' ' + r2(t * 1.4) + ' ' + r2(h) + ' Z'; }, { p: 0.4, pn: 'Hrúbka', a: 1.3 });

  /* ---------- Bubliny ---------- */
  const C = 'Bubliny';
  const tail = (w, h, p) => [w * (0.15 + p * 0.5), h];
  add(C, 'callRect', 'Bublina hranatá', (w, h, p) => { const b = h * 0.78, [tx] = tail(w, h, p); return P([[0, 0], [w, 0], [w, b], [tx + w * 0.18, b], [tx, h], [tx + w * 0.05, b], [0, b]]); }, { p: 0.2, pn: 'Chvost', a: 1.4, tb: [0, 0, 1, 0.78] });
  add(C, 'callRound', 'Bublina zaoblená', (w, h, p) => { const b = h * 0.78, r = Math.min(w, b) * 0.18, tx = w * (0.15 + p * 0.5);
    return 'M' + r2(r) + ' 0 H' + r2(w - r) + ' Q' + r2(w) + ' 0 ' + r2(w) + ' ' + r2(r) + ' V' + r2(b - r) + ' Q' + r2(w) + ' ' + r2(b) + ' ' + r2(w - r) + ' ' + r2(b) + ' H' + r2(tx + w * 0.18) + ' L' + r2(tx) + ' ' + r2(h) + ' L' + r2(tx + w * 0.05) + ' ' + r2(b) + ' H' + r2(r) + ' Q0 ' + r2(b) + ' 0 ' + r2(b - r) + ' V' + r2(r) + ' Q0 0 ' + r2(r) + ' 0 Z'; }, { p: 0.2, pn: 'Chvost', a: 1.4, tb: [0, 0, 1, 0.78] });
  add(C, 'callOval', 'Bublina oválna', (w, h, p) => { const b = h * 0.82, cx = w / 2, cy = b / 2, tx = w * (0.1 + p * 0.4);
    const [x0, y0] = arcPt(cx, cy, w / 2, b / 2, 115), [x1, y1] = arcPt(cx, cy, w / 2, b / 2, 95);
    return 'M' + r2(x1) + ' ' + r2(y1) + ' A' + r2(w / 2) + ' ' + r2(b / 2) + ' 0 1 0 ' + r2(x0) + ' ' + r2(y0) + ' L' + r2(tx) + ' ' + r2(h) + ' Z'; }, { p: 0.3, pn: 'Chvost', a: 1.4, tb: [0.12, 0.1, 0.76, 0.62] });
  add(C, 'callCloud', 'Myšlienka', (w, h) => { const b = h * 0.8; return 'M' + r2(w * 0.22) + ' ' + r2(b) + ' A' + r2(w * 0.22) + ' ' + r2(b * 0.3) + ' 0 0 1 ' + r2(w * 0.15) + ' ' + r2(b * 0.44) + ' A' + r2(w * 0.2) + ' ' + r2(b * 0.28) + ' 0 0 1 ' + r2(w * 0.45) + ' ' + r2(b * 0.18) + ' A' + r2(w * 0.24) + ' ' + r2(b * 0.32) + ' 0 0 1 ' + r2(w * 0.86) + ' ' + r2(b * 0.4) + ' A' + r2(w * 0.17) + ' ' + r2(b * 0.3) + ' 0 0 1 ' + r2(w * 0.8) + ' ' + r2(b) + ' Z '
    + ell(w * 0.26, h * 0.88, w * 0.05, h * 0.045) + ' ' + ell(w * 0.16, h * 0.97, w * 0.03, h * 0.028); }, { a: 1.4, tb: [0.12, 0.3, 0.76, 0.48] });
  add(C, 'callShout', 'Výkrik', (w, h) => P([[.1, .1], [.3, .18], [.38, 0], [.5, .16], [.66, .02], [.7, .2], [.92, .1], [.84, .32], [1, .42], [.86, .55], [.96, .74], [.72, .7], [.6, .86], [.48, .72], [.2, 1], [.3, .7], [.06, .74], [.14, .55], [0, .4], [.16, .3]].map(([x, y]) => [x * w, y * h])), { a: 1.3, tb: [0.22, 0.25, 0.56, 0.4] });
  add(C, 'callLeft', 'Bublina vľavo', (w, h, p) => { const l = w * 0.14, ty = h * (0.3 + p * 0.5); return P([[l, 0], [w, 0], [w, h], [l, h], [l, Math.min(h, ty + h * 0.12)], [0, ty], [l, Math.max(0, ty - h * 0.05)]]); }, { p: 0.3, pn: 'Chvost', a: 1.6, tb: [0.14, 0, 0.86, 1] });
  add(C, 'callRight', 'Bublina vpravo', (w, h, p) => { const r = w * 0.86, ty = h * (0.3 + p * 0.5); return P([[0, 0], [r, 0], [r, Math.max(0, ty - h * 0.05)], [w, ty], [r, Math.min(h, ty + h * 0.12)], [r, h], [0, h]]); }, { p: 0.3, pn: 'Chvost', a: 1.6, tb: [0, 0, 0.86, 1] });
  add(C, 'callChat', 'Chat', (w, h) => rrect(0, 0, w, h * 0.8, Math.min(w, h) * 0.18).replace(/ Z$/, '') + ' Z M' + r2(w * 0.72) + ' ' + r2(h * 0.78) + ' L' + r2(w * 0.86) + ' ' + r2(h) + ' L' + r2(w * 0.86) + ' ' + r2(h * 0.78) + ' Z', { a: 1.4, tb: [0, 0, 1, 0.8] });

  /* ---------- Symboly ---------- */
  const Y = 'Symboly';
  add(Y, 'heart', 'Srdce', (w, h) => 'M' + r2(w / 2) + ' ' + r2(h * 0.28) + ' C' + r2(w / 2) + ' ' + r2(h * 0.02) + ' 0 ' + r2(-h * 0.02) + ' 0 ' + r2(h * 0.32) + ' C0 ' + r2(h * 0.6) + ' ' + r2(w * 0.38) + ' ' + r2(h * 0.78) + ' ' + r2(w / 2) + ' ' + r2(h) + ' C' + r2(w * 0.62) + ' ' + r2(h * 0.78) + ' ' + r2(w) + ' ' + r2(h * 0.6) + ' ' + r2(w) + ' ' + r2(h * 0.32) + ' C' + r2(w) + ' ' + r2(-h * 0.02) + ' ' + r2(w / 2) + ' ' + r2(h * 0.02) + ' ' + r2(w / 2) + ' ' + r2(h * 0.28) + ' Z', { a: 1.1 });
  add(Y, 'cloud', 'Oblak', (w, h) => 'M' + r2(w * 0.22) + ' ' + r2(h) + ' A' + r2(w * 0.22) + ' ' + r2(h * 0.3) + ' 0 0 1 ' + r2(w * 0.15) + ' ' + r2(h * 0.44) + ' A' + r2(w * 0.2) + ' ' + r2(h * 0.28) + ' 0 0 1 ' + r2(w * 0.45) + ' ' + r2(h * 0.18) + ' A' + r2(w * 0.24) + ' ' + r2(h * 0.32) + ' 0 0 1 ' + r2(w * 0.86) + ' ' + r2(h * 0.4) + ' A' + r2(w * 0.17) + ' ' + r2(h * 0.3) + ' 0 0 1 ' + r2(w * 0.8) + ' ' + r2(h) + ' Z', { a: 1.6 });
  add(Y, 'moon', 'Mesiac', (w, h, p) => { const R = w * 0.62, ri = R * (0.85 - p * 0.75); return 'M' + r2(R) + ' 0 A' + r2(R) + ' ' + r2(h / 2) + ' 0 0 0 ' + r2(R) + ' ' + r2(h) + ' A' + r2(ri) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(R) + ' 0 Z'; }, { p: 0.75, pn: 'Kosák', a: 0.62 });
  add(Y, 'sun', 'Slnko', (w, h) => { let d = ell(w / 2, h / 2, w * 0.24, h * 0.24); for (let i = 0; i < 12; i++) { const a = i * 30 * Math.PI / 180, c = Math.cos(a), s = Math.sin(a), q = 0.06;
      d += ' ' + P([[w / 2 + c * w * 0.33 - s * w * q * 0.5, h / 2 + s * h * 0.33 + c * h * q * 0.5], [w / 2 + c * w * 0.5, h / 2 + s * h * 0.5], [w / 2 + c * w * 0.33 + s * w * q * 0.5, h / 2 + s * h * 0.33 - c * h * q * 0.5]]); } return d; });
  add(Y, 'bolt', 'Blesk', (w, h) => P([[.38, 0], [.82, 0], [.58, .38], [.9, .38], [.24, 1], [.4, .56], [.1, .56]].map(([x, y]) => [x * w, y * h])), { a: 0.65 });
  add(Y, 'check', 'Fajka', (w, h, p) => { const t = Math.min(w, h) * (0.1 + p * 0.15); return P([[0, h * 0.55], [t, h * 0.55 - t], [w * 0.38, h - t * 1.9], [w - t, 0], [w, t], [w * 0.38, h]]); }, { p: 0.4, pn: 'Hrúbka', a: 1.2 });
  add(Y, 'xmark', 'Krížik', (w, h, p) => { const t = Math.min(w, h) * (0.08 + p * 0.15); return P([[t, 0], [w / 2, h / 2 - t], [w - t, 0], [w, t], [w / 2 + t, h / 2], [w, h - t], [w - t, h], [w / 2, h / 2 + t], [t, h], [0, h - t], [w / 2 - t, h / 2], [0, t]]); }, { p: 0.4, pn: 'Hrúbka' });
  add(Y, 'plus', 'Plus', (w, h, p) => { const t = Math.min(w, h) * (0.08 + p * 0.15); return P([[w / 2 - t, 0], [w / 2 + t, 0], [w / 2 + t, h / 2 - t], [w, h / 2 - t], [w, h / 2 + t], [w / 2 + t, h / 2 + t], [w / 2 + t, h], [w / 2 - t, h], [w / 2 - t, h / 2 + t], [0, h / 2 + t], [0, h / 2 - t], [w / 2 - t, h / 2 - t]]); }, { p: 0.4, pn: 'Hrúbka' });
  add(Y, 'minus', 'Mínus', (w, h) => P([[0, h * 0.35], [w, h * 0.35], [w, h * 0.65], [0, h * 0.65]]), { a: 3 });
  add(Y, 'equal', 'Rovná sa', (w, h) => P([[0, 0], [w, 0], [w, h * 0.36], [0, h * 0.36]]) + ' ' + P([[0, h * 0.64], [w, h * 0.64], [w, h], [0, h]]), { a: 1.6 });
  add(Y, 'notequal', 'Nerovná sa', (w, h) => P([[0, h * 0.2], [w, h * 0.2], [w, h * 0.38], [0, h * 0.38]]) + ' ' + P([[0, h * 0.62], [w, h * 0.62], [w, h * 0.8], [0, h * 0.8]]) + ' ' + P([[w * 0.62, 0], [w * 0.74, 0], [w * 0.38, h], [w * 0.26, h]]), { a: 1.2 });
  add(Y, 'divide', 'Deliť', (w, h) => P([[0, h * 0.42], [w, h * 0.42], [w, h * 0.58], [0, h * 0.58]]) + ' ' + ell(w / 2, h * 0.15, w * 0.11, h * 0.11) + ' ' + ell(w / 2, h * 0.85, w * 0.11, h * 0.11));
  add(Y, 'smile', 'Smajlík', (w, h, p) => ell(w / 2, h / 2, w / 2, h / 2) + ' ' + ell(w * 0.34, h * 0.38, w * 0.07, h * 0.09) + ' ' + ell(w * 0.66, h * 0.38, w * 0.07, h * 0.09)
    + ' M' + r2(w * 0.26) + ' ' + r2(h * 0.62) + ' Q' + r2(w / 2) + ' ' + r2(h * (0.62 + (p - 0.5) * 0.5)) + ' ' + r2(w * 0.74) + ' ' + r2(h * 0.62) + ' Q' + r2(w / 2) + ' ' + r2(h * (0.66 + (p - 0.5) * 0.5)) + ' ' + r2(w * 0.26) + ' ' + r2(h * 0.62) + ' Z', { p: 0.9, pn: 'Úsmev', eo: true });
  add(Y, 'drop', 'Kvapka', (w, h) => 'M' + r2(w / 2) + ' 0 C' + r2(w * 0.6) + ' ' + r2(h * 0.25) + ' ' + r2(w) + ' ' + r2(h * 0.45) + ' ' + r2(w) + ' ' + r2(h * 0.66) + ' A' + r2(w / 2) + ' ' + r2(h * 0.34) + ' 0 0 1 0 ' + r2(h * 0.66) + ' C0 ' + r2(h * 0.45) + ' ' + r2(w * 0.4) + ' ' + r2(h * 0.25) + ' ' + r2(w / 2) + ' 0 Z', { a: 0.72 });
  add(Y, 'leaf', 'List', (w, h) => 'M0 ' + r2(h) + ' C0 ' + r2(h * 0.3) + ' ' + r2(w * 0.4) + ' 0 ' + r2(w) + ' 0 C' + r2(w) + ' ' + r2(h * 0.6) + ' ' + r2(w * 0.7) + ' ' + r2(h) + ' 0 ' + r2(h) + ' Z');
  add(Y, 'flower', 'Kvet', (w, h, p) => { let d = ''; const n = 5 + Math.round(p * 5); for (let i = 0; i < n; i++) { const a = (i / n) * Math.PI * 2 - Math.PI / 2, c = Math.cos(a), s = Math.sin(a);
      d += ' M' + r2(w / 2) + ' ' + r2(h / 2) + ' C' + r2(w / 2 + (c * 0.5 - s * 0.32) * w * 0.55) + ' ' + r2(h / 2 + (s * 0.5 + c * 0.32) * h * 0.55) + ' ' + r2(w / 2 + (c * 0.9 - s * 0.2) * w * 0.55) + ' ' + r2(h / 2 + (s * 0.9 + c * 0.2) * h * 0.55) + ' ' + r2(w / 2 + c * w * 0.5) + ' ' + r2(h / 2 + s * h * 0.5)
        + ' C' + r2(w / 2 + (c * 0.9 + s * 0.2) * w * 0.55) + ' ' + r2(h / 2 + (s * 0.9 - c * 0.2) * h * 0.55) + ' ' + r2(w / 2 + (c * 0.5 + s * 0.32) * w * 0.55) + ' ' + r2(h / 2 + (s * 0.5 - c * 0.32) * h * 0.55) + ' ' + r2(w / 2) + ' ' + r2(h / 2) + ' Z'; }
    return d.trim(); }, { p: 0, pn: 'Lupene' });
  add(Y, 'gear', 'Ozubené koleso', (w, h, p) => { const n = 8 + Math.round(p * 8), pts = []; for (let i = 0; i < n * 4; i++) { const a = (i / (n * 4)) * Math.PI * 2, k = (i % 4 < 2) ? 1 : 0.8; pts.push([w / 2 + Math.cos(a) * w / 2 * k, h / 2 + Math.sin(a) * h / 2 * k]); }
    return P(pts) + ' ' + ell(w / 2, h / 2, w * 0.18, h * 0.18); }, { p: 0, pn: 'Zuby', eo: true });
  add(Y, 'nosym', 'Zákaz', (w, h, p) => { const k = 0.92 - p * 0.3, rx = w / 2 * k, ry = h / 2 * k, g = 8 + p * 14;
    const half = (s) => { const [x0, y0] = arcPt(w / 2, h / 2, rx, ry, s + 45 + g), [x1, y1] = arcPt(w / 2, h / 2, rx, ry, s + 225 - g); return 'M' + r2(x0) + ' ' + r2(y0) + ' A' + r2(rx) + ' ' + r2(ry) + ' 0 0 1 ' + r2(x1) + ' ' + r2(y1) + ' Z'; };
    return ell(w / 2, h / 2, w / 2, h / 2) + ' ' + half(0) + ' ' + half(180); }, { p: 0.3, pn: 'Hrúbka', eo: true });
  add(Y, 'house', 'Domček', (w, h) => P([[w / 2, 0], [w, h * 0.45], [w * 0.86, h * 0.45], [w * 0.86, h], [w * 0.14, h], [w * 0.14, h * 0.45], [0, h * 0.45]]));
  add(Y, 'pin', 'Špendlík (miesto)', (w, h) => 'M' + r2(w / 2) + ' ' + r2(h) + ' C' + r2(w * 0.3) + ' ' + r2(h * 0.7) + ' 0 ' + r2(h * 0.55) + ' 0 ' + r2(h * 0.36) + ' A' + r2(w / 2) + ' ' + r2(h * 0.36) + ' 0 1 1 ' + r2(w) + ' ' + r2(h * 0.36) + ' C' + r2(w) + ' ' + r2(h * 0.55) + ' ' + r2(w * 0.7) + ' ' + r2(h * 0.7) + ' ' + r2(w / 2) + ' ' + r2(h) + ' Z ' + ell(w / 2, h * 0.36, w * 0.18, h * 0.13), { a: 0.72, eo: true });
  add(Y, 'shield', 'Štít', (w, h) => 'M' + r2(w / 2) + ' 0 L' + r2(w) + ' ' + r2(h * 0.15) + ' V' + r2(h * 0.45) + ' C' + r2(w) + ' ' + r2(h * 0.75) + ' ' + r2(w * 0.75) + ' ' + r2(h * 0.9) + ' ' + r2(w / 2) + ' ' + r2(h) + ' C' + r2(w * 0.25) + ' ' + r2(h * 0.9) + ' 0 ' + r2(h * 0.75) + ' 0 ' + r2(h * 0.45) + ' V' + r2(h * 0.15) + ' Z', { a: 0.82 });
  add(Y, 'crown', 'Koruna', (w, h) => P([[0, h * 0.2], [w * 0.28, h * 0.55], [w / 2, 0], [w * 0.72, h * 0.55], [w, h * 0.2], [w * 0.88, h], [w * 0.12, h]]), { a: 1.3 });
  add(Y, 'gem', 'Diamant', (w, h) => P([[w * 0.22, 0], [w * 0.78, 0], [w, h * 0.32], [w / 2, h], [0, h * 0.32]]) + ' M0 ' + r2(h * 0.32) + ' H' + r2(w) + ' M' + r2(w * 0.22) + ' 0 L' + r2(w * 0.36) + ' ' + r2(h * 0.32) + ' L' + r2(w / 2) + ' ' + r2(h) + ' M' + r2(w * 0.78) + ' 0 L' + r2(w * 0.64) + ' ' + r2(h * 0.32) + ' L' + r2(w / 2) + ' ' + r2(h), { a: 1.2 });
  add(Y, 'bell', 'Zvonček', (w, h) => 'M' + r2(w / 2) + ' 0 C' + r2(w * 0.8) + ' 0 ' + r2(w * 0.85) + ' ' + r2(h * 0.3) + ' ' + r2(w * 0.85) + ' ' + r2(h * 0.5) + ' C' + r2(w * 0.85) + ' ' + r2(h * 0.7) + ' ' + r2(w) + ' ' + r2(h * 0.75) + ' ' + r2(w) + ' ' + r2(h * 0.82) + ' H0 C0 ' + r2(h * 0.75) + ' ' + r2(w * 0.15) + ' ' + r2(h * 0.7) + ' ' + r2(w * 0.15) + ' ' + r2(h * 0.5) + ' C' + r2(w * 0.15) + ' ' + r2(h * 0.3) + ' ' + r2(w * 0.2) + ' 0 ' + r2(w / 2) + ' 0 Z ' + ell(w / 2, h * 0.91, w * 0.12, h * 0.09));
  add(Y, 'person', 'Postava', (w, h) => ell(w / 2, h * 0.2, w * 0.2, h * 0.2) + ' M0 ' + r2(h) + ' V' + r2(h * 0.75) + ' C0 ' + r2(h * 0.5) + ' ' + r2(w * 0.25) + ' ' + r2(h * 0.45) + ' ' + r2(w / 2) + ' ' + r2(h * 0.45) + ' C' + r2(w * 0.75) + ' ' + r2(h * 0.45) + ' ' + r2(w) + ' ' + r2(h * 0.5) + ' ' + r2(w) + ' ' + r2(h * 0.75) + ' V' + r2(h) + ' Z', { a: 0.8 });
  add(Y, 'puzzle', 'Puzzle', (w, h) => 'M0 ' + r2(h * 0.2) + ' H' + r2(w * 0.32) + ' A' + r2(w * 0.12) + ' ' + r2(h * 0.12) + ' 0 1 1 ' + r2(w * 0.56) + ' ' + r2(h * 0.2) + ' H' + r2(w * 0.8) + ' V' + r2(h * 0.42) + ' A' + r2(w * 0.12) + ' ' + r2(h * 0.12) + ' 0 1 1 ' + r2(w * 0.8) + ' ' + r2(h * 0.66) + ' V' + r2(h) + ' H0 Z');

  /* ---------- Stuhy a rámčeky ---------- */
  const R = 'Stuhy a rámčeky';
  add(R, 'ribbon', 'Stuha', (w, h) => P([[0, h * 0.25], [w * 0.18, h * 0.25], [w * 0.18, 0], [w * 0.82, 0], [w * 0.82, h * 0.25], [w, h * 0.25], [w * 0.92, h * 0.55], [w, h * 0.85], [w * 0.82, h * 0.85], [w * 0.82, h * 0.6], [w * 0.18, h * 0.6], [w * 0.18, h * 0.85], [0, h * 0.85], [w * 0.08, h * 0.55]]), { a: 3, tb: [0.18, 0, 0.64, 0.6] });
  add(R, 'banner', 'Banner', (w, h, p) => { const a = h * 0.18 * p; return 'M0 ' + r2(h * 0.15) + ' C' + r2(w * 0.33) + ' ' + r2(h * 0.15 - a * 2) + ' ' + r2(w * 0.66) + ' ' + r2(h * 0.15 + a * 2) + ' ' + r2(w) + ' ' + r2(h * 0.15)
    + ' V' + r2(h * 0.85) + ' C' + r2(w * 0.66) + ' ' + r2(h * 0.85 + a * 2) + ' ' + r2(w * 0.33) + ' ' + r2(h * 0.85 - a * 2) + ' 0 ' + r2(h * 0.85) + ' Z'; }, { p: 0.5, pn: 'Vlnenie', a: 2.5, tb: [0, 0.15, 1, 0.7] });
  add(R, 'wave', 'Vlna', (w, h, p) => { const amp = h * (0.05 + p * 0.25); return 'M0 ' + r2(amp) + ' Q' + r2(w * 0.25) + ' ' + r2(-amp) + ' ' + r2(w / 2) + ' ' + r2(amp) + ' T' + r2(w) + ' ' + r2(amp) + ' V' + r2(h - amp) + ' Q' + r2(w * 0.75) + ' ' + r2(h + amp) + ' ' + r2(w / 2) + ' ' + r2(h - amp) + ' T0 ' + r2(h - amp) + ' Z'; }, { p: 0.4, pn: 'Vlna', a: 1.6 });
  add(R, 'wave2', 'Dvojitá vlna', (w, h, p) => { const amp = h * (0.05 + p * 0.2); return 'M0 ' + r2(amp) + ' Q' + r2(w / 8) + ' ' + r2(-amp) + ' ' + r2(w / 4) + ' ' + r2(amp) + ' T' + r2(w / 2) + ' ' + r2(amp) + ' T' + r2(w * 0.75) + ' ' + r2(amp) + ' T' + r2(w) + ' ' + r2(amp) + ' V' + r2(h - amp) + ' Q' + r2(w * 7 / 8) + ' ' + r2(h + amp) + ' ' + r2(w * 0.75) + ' ' + r2(h - amp) + ' T' + r2(w / 2) + ' ' + r2(h - amp) + ' T' + r2(w / 4) + ' ' + r2(h - amp) + ' T0 ' + r2(h - amp) + ' Z'; }, { p: 0.4, pn: 'Vlna', a: 1.8 });
  add(R, 'bookmark', 'Záložka', (w, h, p) => P([[0, 0], [w, 0], [w, h], [w / 2, h - h * (0.1 + p * 0.4)], [0, h]]), { p: 0.4, pn: 'Zárez', a: 0.6 });
  add(R, 'flag', 'Vlajka', (w, h) => 'M0 0 V' + r2(h) + ' H' + r2(w * 0.08) + ' V' + r2(h * 0.6) + ' Q' + r2(w * 0.35) + ' ' + r2(h * 0.48) + ' ' + r2(w * 0.55) + ' ' + r2(h * 0.6) + ' T' + r2(w) + ' ' + r2(h * 0.58) + ' V0 Q' + r2(w * 0.75) + ' ' + r2(h * 0.08) + ' ' + r2(w * 0.55) + ' ' + r2(h * 0.02) + ' T' + r2(w * 0.08) + ' 0 Z', { a: 1.2 });
  add(R, 'tag', 'Visačka', (w, h, p) => { const d = w * (0.15 + p * 0.25); return P([[d, 0], [w, 0], [w, h], [d, h], [0, h / 2]]) + ' ' + ell(d * 0.75, h / 2, h * 0.09, h * 0.09); }, { p: 0.3, pn: 'Hrot', a: 2, eo: true, tb: [0.25, 0, 0.75, 1] });
  add(R, 'ticket', 'Lístok', (w, h, p) => { const r = Math.min(w, h) * (0.06 + p * 0.2); return 'M0 0 H' + r2(w) + ' V' + r2(h / 2 - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 ' + r2(w) + ' ' + r2(h / 2 + r) + ' V' + r2(h) + ' H0 V' + r2(h / 2 + r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 0 ' + r2(h / 2 - r) + ' Z'; }, { p: 0.4, pn: 'Výrez', a: 2 });
  add(R, 'plaque', 'Plaketa', (w, h, p) => { const r = Math.min(w, h) * (0.06 + p * 0.25); return 'M' + r2(r) + ' 0 H' + r2(w - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 ' + r2(w) + ' ' + r2(r) + ' V' + r2(h - r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 ' + r2(w - r) + ' ' + r2(h) + ' H' + r2(r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 0 ' + r2(h - r) + ' V' + r2(r) + ' A' + r2(r) + ' ' + r2(r) + ' 0 0 0 ' + r2(r) + ' 0 Z'; }, { p: 0.3, pn: 'Rohy', a: 1.6 });
  add(R, 'brackets', 'Hranaté zátvorky', (w, h) => 'M' + r2(w * 0.15) + ' 0 H0 V' + r2(h) + ' H' + r2(w * 0.15) + ' M' + r2(w * 0.85) + ' 0 H' + r2(w) + ' V' + r2(h) + ' H' + r2(w * 0.85), { line: true, a: 1.6 });
  add(R, 'braces', 'Zložené zátvorky', (w, h) => 'M' + r2(w * 0.12) + ' 0 Q' + r2(w * 0.04) + ' 0 ' + r2(w * 0.04) + ' ' + r2(h * 0.1) + ' V' + r2(h * 0.4) + ' Q' + r2(w * 0.04) + ' ' + r2(h / 2) + ' 0 ' + r2(h / 2) + ' Q' + r2(w * 0.04) + ' ' + r2(h / 2) + ' ' + r2(w * 0.04) + ' ' + r2(h * 0.6) + ' V' + r2(h * 0.9) + ' Q' + r2(w * 0.04) + ' ' + r2(h) + ' ' + r2(w * 0.12) + ' ' + r2(h)
    + ' M' + r2(w * 0.88) + ' 0 Q' + r2(w * 0.96) + ' 0 ' + r2(w * 0.96) + ' ' + r2(h * 0.1) + ' V' + r2(h * 0.4) + ' Q' + r2(w * 0.96) + ' ' + r2(h / 2) + ' ' + r2(w) + ' ' + r2(h / 2) + ' Q' + r2(w * 0.96) + ' ' + r2(h / 2) + ' ' + r2(w * 0.96) + ' ' + r2(h * 0.6) + ' V' + r2(h * 0.9) + ' Q' + r2(w * 0.96) + ' ' + r2(h) + ' ' + r2(w * 0.88) + ' ' + r2(h), { line: true, a: 1.6 });
  add(R, 'corners', 'Rohy rámčeka', (w, h, p) => { const c = Math.min(w, h) * (0.1 + p * 0.3); return 'M0 ' + r2(c) + ' V0 H' + r2(c) + ' M' + r2(w - c) + ' 0 H' + r2(w) + ' V' + r2(c) + ' M' + r2(w) + ' ' + r2(h - c) + ' V' + r2(h) + ' H' + r2(w - c) + ' M' + r2(c) + ' ' + r2(h) + ' H0 V' + r2(h - c); }, { p: 0.3, pn: 'Dĺžka', line: true, a: 1.4 });

  /* ---------- Čiary ---------- */
  const L = 'Čiary';
  add(L, 'line', 'Čiara', (w, h) => 'M0 ' + r2(h / 2) + ' H' + r2(w), { line: true, a: 4 });
  add(L, 'lineArrow', 'Čiara so šípkou', (w, h) => { const a = Math.min(h * 0.45, w * 0.2); return 'M0 ' + r2(h / 2) + ' H' + r2(w) + ' M' + r2(w - a) + ' ' + r2(h / 2 - a * 0.7) + ' L' + r2(w) + ' ' + r2(h / 2) + ' L' + r2(w - a) + ' ' + r2(h / 2 + a * 0.7); }, { line: true, a: 4 });
  add(L, 'lineArrow2', 'Obojstranná šípka', (w, h) => { const a = Math.min(h * 0.45, w * 0.2); return 'M0 ' + r2(h / 2) + ' H' + r2(w) + ' M' + r2(w - a) + ' ' + r2(h / 2 - a * 0.7) + ' L' + r2(w) + ' ' + r2(h / 2) + ' L' + r2(w - a) + ' ' + r2(h / 2 + a * 0.7) + ' M' + r2(a) + ' ' + r2(h / 2 - a * 0.7) + ' L0 ' + r2(h / 2) + ' L' + r2(a) + ' ' + r2(h / 2 + a * 0.7); }, { line: true, a: 4 });
  add(L, 'curve', 'Krivka', (w, h) => 'M0 ' + r2(h * 0.8) + ' C' + r2(w * 0.3) + ' ' + r2(-h * 0.3) + ' ' + r2(w * 0.7) + ' ' + r2(h * 1.3) + ' ' + r2(w) + ' ' + r2(h * 0.2), { line: true, a: 2.5 });
  add(L, 'curveArrow', 'Krivka so šípkou', (w, h) => { const a = Math.min(h * 0.35, w * 0.12); return 'M0 ' + r2(h) + ' Q' + r2(w * 0.5) + ' ' + r2(-h * 0.6) + ' ' + r2(w) + ' ' + r2(h * 0.8) + ' M' + r2(w - a * 1.2) + ' ' + r2(h * 0.8 - a * 0.3) + ' L' + r2(w) + ' ' + r2(h * 0.8) + ' L' + r2(w - a * 0.1) + ' ' + r2(h * 0.8 - a * 1.2); }, { line: true, a: 2 });
  add(L, 'zigzag', 'Cikcak', (w, h, p) => { const n = 3 + Math.round(p * 9), pts = []; for (let i = 0; i <= n * 2; i++) pts.push([w * i / (n * 2), i % 2 ? 0 : h]); return P(pts, false); }, { p: 0.3, pn: 'Zuby', line: true, a: 3 });
  add(L, 'wavy', 'Vlnovka', (w, h, p) => { const n = 2 + Math.round(p * 8); return 'M0 ' + r2(h / 2) + wave(0, w, h / 2, h / 2, n * 2); }, { p: 0.3, pn: 'Vlny', line: true, a: 3 });
  add(L, 'arc', 'Oblúk', (w, h) => 'M0 ' + r2(h) + ' A' + r2(w / 2) + ' ' + r2(h) + ' 0 0 1 ' + r2(w) + ' ' + r2(h), { line: true, a: 2 });
  add(L, 'spiral', 'Špirála', (w, h, p) => { const n = 2 + Math.round(p * 4), steps = n * 36, pts = []; for (let i = 0; i <= steps; i++) { const a = i / 36 * Math.PI * 2, k = i / steps; pts.push([w / 2 + Math.cos(a) * w / 2 * k, h / 2 + Math.sin(a) * h / 2 * k]); } return P(pts, false); }, { p: 0.25, pn: 'Otáčky', line: true });

  /* ---------- Vývojové diagramy ---------- */
  const F = 'Diagramy';
  add(F, 'fProcess', 'Proces', (w, h) => P([[0, 0], [w, 0], [w, h], [0, h]]), { a: 1.6 });
  add(F, 'fAlt', 'Alternatívny proces', (w, h) => rrect(0, 0, w, h, Math.min(w, h) * 0.18), { a: 1.6 });
  add(F, 'fDecision', 'Rozhodnutie', (w, h) => P([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]), { a: 1.6, tb: [0.25, 0.25, 0.5, 0.5] });
  add(F, 'fTerm', 'Začiatok / koniec', (w, h) => rrect(0, 0, w, h, h / 2), { a: 2.4 });
  add(F, 'fData', 'Dáta (vstup/výstup)', (w, h) => P([[w * 0.2, 0], [w, 0], [w * 0.8, h], [0, h]]), { a: 1.6, tb: [0.15, 0, 0.7, 1] });
  add(F, 'fPredef', 'Preddefinovaný proces', (w, h) => P([[0, 0], [w, 0], [w, h], [0, h]]) + ' M' + r2(w * 0.1) + ' 0 V' + r2(h) + ' M' + r2(w * 0.9) + ' 0 V' + r2(h), { a: 1.6, tb: [0.1, 0, 0.8, 1] });
  add(F, 'fInternal', 'Vnútorné úložisko', (w, h) => P([[0, 0], [w, 0], [w, h], [0, h]]) + ' M' + r2(w * 0.15) + ' 0 V' + r2(h) + ' M0 ' + r2(h * 0.2) + ' H' + r2(w), { a: 1.3, tb: [0.15, 0.2, 0.85, 0.8] });
  add(F, 'fDoc', 'Dokument', (w, h) => 'M0 0 H' + r2(w) + ' V' + r2(h * 0.82) + ' Q' + r2(w * 0.75) + ' ' + r2(h * 0.64) + ' ' + r2(w / 2) + ' ' + r2(h * 0.82) + ' T0 ' + r2(h * 0.82) + ' Z', { a: 1.4, tb: [0, 0, 1, 0.8] });
  add(F, 'fDocs', 'Viac dokumentov', (w, h) => { const o = Math.min(w, h) * 0.07, d = (x, y, ww, hh) => 'M' + r2(x) + ' ' + r2(y) + ' H' + r2(x + ww) + ' V' + r2(y + hh * 0.82) + ' Q' + r2(x + ww * 0.75) + ' ' + r2(y + hh * 0.64) + ' ' + r2(x + ww / 2) + ' ' + r2(y + hh * 0.82) + ' T' + r2(x) + ' ' + r2(y + hh * 0.82) + ' Z';
    return d(o * 2, 0, w - o * 2, h - o * 2) + ' ' + d(o, o, w - o * 2, h - o * 2) + ' ' + d(0, o * 2, w - o * 2, h - o * 2); }, { a: 1.4, tb: [0, 0.14, 0.86, 0.7] });
  add(F, 'fManual', 'Ručný vstup', (w, h) => P([[0, h * 0.25], [w, 0], [w, h], [0, h]]), { a: 1.6, tb: [0, 0.25, 1, 0.75] });
  add(F, 'fPrep', 'Príprava', (w, h) => P([[w * 0.2, 0], [w * 0.8, 0], [w, h / 2], [w * 0.8, h], [w * 0.2, h], [0, h / 2]]), { a: 1.8, tb: [0.15, 0, 0.7, 1] });
  add(F, 'fManOp', 'Ručná operácia', (w, h) => P([[0, 0], [w, 0], [w * 0.8, h], [w * 0.2, h]]), { a: 1.6, tb: [0.15, 0, 0.7, 1] });
  add(F, 'fConn', 'Spojka', (w, h) => ell(w / 2, h / 2, w / 2, h / 2));
  add(F, 'fOffpage', 'Spojka na inú stranu', (w, h) => P([[0, 0], [w, 0], [w, h * 0.7], [w / 2, h], [0, h * 0.7]]), { a: 0.9, tb: [0, 0, 1, 0.7] });
  add(F, 'fCard', 'Karta', (w, h) => P([[w * 0.2, 0], [w, 0], [w, h], [0, h], [0, h * 0.25]]), { a: 1.6 });
  add(F, 'fTape', 'Dierna páska', (w, h) => 'M0 ' + r2(h * 0.1) + ' Q' + r2(w / 4) + ' ' + r2(h * 0.3) + ' ' + r2(w / 2) + ' ' + r2(h * 0.1) + ' T' + r2(w) + ' ' + r2(h * 0.1) + ' V' + r2(h * 0.9) + ' Q' + r2(w * 0.75) + ' ' + r2(h * 0.7) + ' ' + r2(w / 2) + ' ' + r2(h * 0.9) + ' T0 ' + r2(h * 0.9) + ' Z', { a: 1.6, tb: [0, 0.2, 1, 0.6] });
  add(F, 'fSum', 'Súčtový uzol', (w, h) => { const k = Math.SQRT1_2 / 2; return ell(w / 2, h / 2, w / 2, h / 2) + ' M' + r2(w / 2 - w * k) + ' ' + r2(h / 2 - h * k) + ' L' + r2(w / 2 + w * k) + ' ' + r2(h / 2 + h * k) + ' M' + r2(w / 2 + w * k) + ' ' + r2(h / 2 - h * k) + ' L' + r2(w / 2 - w * k) + ' ' + r2(h / 2 + h * k); });
  add(F, 'fOr', 'Alebo', (w, h) => ell(w / 2, h / 2, w / 2, h / 2) + ' M' + r2(w / 2) + ' 0 V' + r2(h) + ' M0 ' + r2(h / 2) + ' H' + r2(w));
  add(F, 'fCollate', 'Zoradenie', (w, h) => P([[0, 0], [w, 0], [0, h], [w, h]]), { a: 0.8 });
  add(F, 'fSort', 'Triedenie', (w, h) => P([[w / 2, 0], [w, h / 2], [w / 2, h], [0, h / 2]]) + ' M0 ' + r2(h / 2) + ' H' + r2(w), { a: 0.8 });
  add(F, 'fExtract', 'Extrakcia', (w, h) => P([[w / 2, 0], [w, h], [0, h]]));
  add(F, 'fMerge', 'Zlúčenie', (w, h) => P([[0, 0], [w, 0], [w / 2, h]]));
  add(F, 'fStored', 'Uložené dáta', (w, h) => 'M' + r2(w * 0.15) + ' 0 H' + r2(w) + ' A' + r2(w * 0.15) + ' ' + r2(h / 2) + ' 0 0 0 ' + r2(w) + ' ' + r2(h) + ' H' + r2(w * 0.15) + ' A' + r2(w * 0.15) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(w * 0.15) + ' 0 Z', { a: 1.6, tb: [0.1, 0, 0.75, 1] });
  add(F, 'fDelay', 'Oneskorenie', (w, h) => 'M0 0 H' + r2(w / 2) + ' A' + r2(w / 2) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(w / 2) + ' ' + r2(h) + ' H0 Z', { a: 1.6, tb: [0, 0, 0.8, 1] });
  add(F, 'fDisplay', 'Zobrazenie', (w, h) => 'M0 ' + r2(h / 2) + ' L' + r2(w * 0.17) + ' 0 H' + r2(w * 0.83) + ' A' + r2(w * 0.17) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(w * 0.83) + ' ' + r2(h) + ' H' + r2(w * 0.17) + ' Z', { a: 1.6, tb: [0.15, 0, 0.7, 1] });
  add(F, 'fDb', 'Databáza', (w, h) => { const e = h * 0.12; return 'M0 ' + r2(e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 1 ' + r2(w) + ' ' + r2(e) + ' V' + r2(h - e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 1 0 ' + r2(h - e) + ' Z M0 ' + r2(e) + ' A' + r2(w / 2) + ' ' + r2(e) + ' 0 0 0 ' + r2(w) + ' ' + r2(e); }, { a: 0.8, tb: [0, 0.25, 1, 0.65] });
  add(F, 'fDirect', 'Priamy prístup', (w, h) => { const e = w * 0.1; return 'M' + r2(e) + ' 0 H' + r2(w - e) + ' A' + r2(e) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(w - e) + ' ' + r2(h) + ' H' + r2(e) + ' A' + r2(e) + ' ' + r2(h / 2) + ' 0 0 1 ' + r2(e) + ' 0 Z M' + r2(w - e) + ' 0 A' + r2(e) + ' ' + r2(h / 2) + ' 0 0 0 ' + r2(w - e) + ' ' + r2(h); }, { a: 1.8, tb: [0.1, 0, 0.7, 1] });

  /* ---------- Text ---------- */
  add('Text', 'text', 'Textové pole', (w, h) => P([[0, 0], [w, 0], [w, h], [0, h]]), { a: 2.5, text: true });

  const CATS = [...new Set(S.map(s => s.cat))];
  const byId = Object.fromEntries(S.map(s => [s.id, s]));
  /** Náhľad tvaru ako <svg> (do ponuky). */
  function svg(sh, size = 44) {
    const a = sh.a || 1, w = a >= 1 ? 100 : 100 * a, h = a >= 1 ? 100 / a : 100, d = sh.f(w, h, sh.p ?? 0.5);
    return '<svg viewBox="-6 -6 ' + (w + 12) + ' ' + (h + 12) + '" width="' + size + '" height="' + size + '" aria-hidden="true"><path d="' + d + '" fill="' + (sh.line ? 'none' : 'currentColor') + '" fill-opacity=".22" fill-rule="' + (sh.eo ? 'evenodd' : 'nonzero') + '" stroke="currentColor" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/></svg>';
  }
  window.IE_SHAPES = { list: S, cats: CATS, byId, svg };
})();
