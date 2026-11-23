/* Ponuky editorov (tabuľky aj dokumenty) ako v Google: rozbaľovacie ponuky s podponukami, po otvorení jednej
   sa ďalšie otvárajú iba prejdením myšou, Esc zatvorí. Plus „Hľadať v ponukách“ (Alt+/) a paleta farieb.
   Značky: <div class="gm"><button data-gm>Súbor</button><ul class="gm-menu">…</ul></div>,
   položka <li><button class="gm-i" data-gs="akcia"><i class="bi …"></i><span>Text</span><kbd>Ctrl+S</kbd></button></li>,
   podponuka <li class="gm-sub"><button class="gm-i">…</button><ul class="gm-menu">…</ul></li>. */
(function () {
  const open = []; // otvorené ponuky (najvyššia úroveň prvá)
  let bar = false; // otvorená ponuka z hlavného riadku – ostatné sa otvárajú prejdením
  const G = window.GMenu = { onOpen: null, closeAll, openMenu, search: null };

  function place(menu, anchor, side) {
    menu.classList.add('show');
    menu.style.position = 'fixed'; menu.style.left = '0px'; menu.style.top = '0px'; menu.style.maxHeight = '';
    const a = anchor.getBoundingClientRect(), vw = innerWidth, vh = innerHeight;
    const m = menu.getBoundingClientRect();
    let x, y;
    if (side) { x = a.right - 2; y = a.top - 6; if (x + m.width > vw - 4) x = Math.max(4, a.left - m.width + 2); }
    else { x = a.left; y = a.bottom + 2; if (x + m.width > vw - 4) x = Math.max(4, vw - m.width - 4); }
    const maxH = vh - 8; if (m.height > maxH) menu.style.maxHeight = maxH + 'px';
    const h = Math.min(m.height, maxH);
    if (y + h > vh - 4) y = side ? Math.max(4, vh - h - 4) : (a.top - h - 2 > 4 ? a.top - h - 2 : Math.max(4, vh - h - 4));
    menu.style.left = Math.round(x) + 'px'; menu.style.top = Math.round(y) + 'px';
  }
  function closeFrom(level) {
    while (open.length > level) { const o = open.pop(); o.menu.classList.remove('show'); o.btn.classList.remove('show'); }
    if (!open.length) bar = false;
  }
  function closeAll() { closeFrom(0); }
  function openMenu(btn, menu, level, side) {
    closeFrom(level);
    if (G.onOpen) try { G.onOpen(menu, btn); } catch (e) {}
    btn.classList.add('show');
    place(menu, btn, side);
    open.push({ btn, menu });
    const f = menu.querySelector('[data-gm-focus]'); if (f) setTimeout(() => f.focus(), 0);
  }
  const levelOf = (el) => { const i = open.findIndex(o => o.menu.contains(el) && !o.menu.querySelector('.gm-menu.show')?.contains(el)); return i; };

  document.addEventListener('mousedown', (e) => {
    if (!open.length) return;
    if (e.target.closest('.gm-menu.show') || e.target.closest('[data-gm]')) return;
    closeAll();
  }, true);
  document.addEventListener('click', (e) => {
    const top = e.target.closest('[data-gm]');
    if (top) {
      e.preventDefault();
      const menu = top.parentElement.querySelector(':scope > .gm-menu');
      if (!menu || top.disabled) return;
      if (menu.classList.contains('show')) { closeAll(); return; }
      bar = !!top.closest('.gs-menu');
      openMenu(top, menu, 0, false);
      return;
    }
    const sub = e.target.closest('.gm-sub > .gm-i');
    if (sub) { e.preventDefault(); const m = sub.parentElement.querySelector(':scope > .gm-menu'); const lv = levelOf(sub) + 1; if (m.classList.contains('show')) closeFrom(lv); else openMenu(sub, m, lv, true); return; }
    const it = e.target.closest('.gm-menu [data-gs], .gm-menu [data-close]');
    if (it && !it.hasAttribute('data-keep')) setTimeout(closeAll, 0);
  });
  let hoverT = null;
  document.addEventListener('mouseover', (e) => {
    if (!open.length) return;
    const top = e.target.closest('.gs-menu [data-gm]');
    if (top && bar && !top.classList.contains('show')) { const m = top.parentElement.querySelector(':scope > .gm-menu'); if (m) openMenu(top, m, 0, false); return; }
    const li = e.target.closest('.gm-menu.show > li'); if (!li) return;
    clearTimeout(hoverT);
    hoverT = setTimeout(() => {
      const lv = levelOf(li) + 1; if (lv <= 0) return;
      if (li.classList.contains('gm-sub')) { const m = li.querySelector(':scope > .gm-menu'); const b = li.querySelector(':scope > .gm-i'); if (!m.classList.contains('show')) openMenu(b, m, lv, true); }
      else closeFrom(lv);
    }, 120);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && open.length) { e.stopPropagation(); e.preventDefault(); closeFrom(open.length - 1); return; }
    if (e.altKey && (e.key === '/' || e.code === 'Slash')) { e.preventDefault(); searchMenus(); return; }
    if (!open.length) return;
    const m = open[open.length - 1].menu;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const items = [...m.querySelectorAll(':scope > li > .gm-i:not([disabled])')].filter(x => x.offsetParent);
      const i = items.indexOf(document.activeElement);
      const n = items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length]; n && n.focus();
    } else if (e.key === 'ArrowRight' && document.activeElement && document.activeElement.closest('.gm-sub')) {
      e.preventDefault(); const li = document.activeElement.closest('.gm-sub'); openMenu(li.querySelector(':scope > .gm-i'), li.querySelector(':scope > .gm-menu'), open.length, true);
      const f = li.querySelector(':scope > .gm-menu > li > .gm-i'); f && f.focus();
    } else if (e.key === 'ArrowLeft' && open.length > 1) { e.preventDefault(); const b = open[open.length - 1].btn; closeFrom(open.length - 1); b.focus(); }
  }, true);
  addEventListener('resize', closeAll);
  addEventListener('blur', () => setTimeout(() => { if (document.activeElement && document.activeElement.tagName === 'IFRAME') closeAll(); }, 0));

  /* ---------- Hľadať v ponukách (Alt+/) ---------- */
  function allItems() {
    const out = [];
    const walk = (ul, path) => {
      for (const li of ul.children) {
        const b = li.querySelector(':scope > .gm-i'); if (!b) continue;
        const t = (b.querySelector('span') || b).textContent.trim();
        if (li.classList.contains('gm-sub')) walk(li.querySelector(':scope > .gm-menu'), path.concat(t));
        else if (b.dataset.gs) out.push({ text: t, path: path.join(' › '), icon: (b.querySelector('i') || {}).className || '', kbd: (b.querySelector('kbd') || {}).textContent || '', btn: b });
      }
    };
    document.querySelectorAll('.gs-menu > .gm').forEach(g => walk(g.querySelector(':scope > .gm-menu'), [g.querySelector('[data-gm]').textContent.trim()]));
    return out;
  }
  const fold = (s) => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  function searchMenus() {
    closeAll();
    let box = document.getElementById('gmSearch');
    if (!box) {
      box = document.createElement('div'); box.id = 'gmSearch'; box.className = 'gm-search';
      box.innerHTML = '<div class="gm-search-in"><i class="bi bi-search"></i><input placeholder="Hľadať v ponukách (Alt+/)" autocomplete="off"></div><div class="gm-search-res"></div>';
      document.body.appendChild(box);
      const inp = box.querySelector('input'), res = box.querySelector('.gm-search-res');
      let list = [], sel = 0;
      const draw = () => {
        const q = fold(inp.value.trim());
        const items = allItems().filter(x => !x.btn.closest('[data-rw]') || !document.body.classList.contains('ro'));
        list = q ? items.filter(x => fold(x.text + ' ' + x.path).includes(q)).slice(0, 12) : items.slice(0, 0);
        sel = 0;
        res.innerHTML = list.length ? list.map((x, i) => '<button class="gm-sr' + (i === sel ? ' on' : '') + '" data-i="' + i + '"><i class="' + x.icon + '"></i><span>' + x.text.replace(/</g, '&lt;') + '<small>' + x.path.replace(/</g, '&lt;') + '</small></span>' + (x.kbd ? '<kbd>' + x.kbd + '</kbd>' : '') + '</button>').join('') : (q ? '<div class="gm-sr-none">Nič sa nenašlo</div>' : '<div class="gm-sr-none">Napíš, čo hľadáš – napr. „tlač“, „zlúčiť“, „riadkovanie“</div>');
      };
      const run = (i) => { const x = list[i]; if (!x) return; hide(); setTimeout(() => x.btn.click(), 0); };
      const hide = () => { box.classList.remove('show'); };
      inp.addEventListener('input', draw);
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { e.preventDefault(); hide(); }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (!list.length) return; sel = (sel + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length; res.querySelectorAll('.gm-sr').forEach((b, i) => b.classList.toggle('on', i === sel)); }
        else if (e.key === 'Enter') { e.preventDefault(); run(sel); }
      });
      res.addEventListener('mousedown', (e) => e.preventDefault());
      res.addEventListener('click', (e) => { const b = e.target.closest('.gm-sr'); if (b) run(+b.dataset.i); });
      inp.addEventListener('blur', () => setTimeout(hide, 150));
      box._draw = draw;
    }
    box.classList.add('show');
    const inp = box.querySelector('input'); inp.value = ''; box._draw(); inp.focus();
  }
  G.search = searchMenus;

  /* ---------- paleta farieb ako v Google ---------- */
  const PAL = [
    ['#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef', '#f3f3f3', '#ffffff'],
    ['#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff', '#9900ff', '#ff00ff'],
    ['#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3', '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc'],
    ['#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599', '#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8', '#b4a7d6', '#d5a6bd'],
    ['#cc4125', '#e06666', '#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6d9eeb', '#6fa8dc', '#8e7cc3', '#c27ba0'],
    ['#a61c00', '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3c78d8', '#3d85c6', '#674ea7', '#a64d79'],
    ['#85200c', '#990000', '#b45f06', '#bf9000', '#38761d', '#134f5c', '#1155cc', '#0b5394', '#351c75', '#741b47'],
    ['#5b0f00', '#660000', '#783f04', '#7f6000', '#274e13', '#0c343d', '#1c4587', '#073763', '#20124d', '#4c1130'],
  ];
  /** Vloží paletu do prvku; pick(farba alebo null pre „žiadna“) */
  G.palette = function (el, pick, noneLabel) {
    el.classList.add('gm-pal');
    el.innerHTML = (noneLabel ? '<button class="gm-pal-none" data-c="">' + noneLabel + '</button>' : '') +
      PAL.map((row, i) => '<div class="gm-pal-row' + (i === 1 ? ' gap' : '') + '">' + row.map(c => '<button class="gm-sw" data-c="' + c + '" style="background:' + c + '" title="' + c + '"></button>').join('') + '</div>').join('') +
      '<label class="gm-pal-custom"><i class="bi bi-plus-circle"></i>Vlastná farba<input type="color" value="#1a73e8"></label>';
    el.addEventListener('mousedown', (e) => { if (!e.target.closest('input')) e.preventDefault(); });
    el.addEventListener('click', (e) => { const b = e.target.closest('[data-c]'); if (!b) return; e.preventDefault(); closeAll(); pick(b.dataset.c || null); });
    el.querySelector('input').addEventListener('change', (e) => { closeAll(); pick(e.target.value); });
  };
})();
