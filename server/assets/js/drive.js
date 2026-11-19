/* Disk – správa súborov a zložiek */
(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const { api, toast, esc, formatBytes, base } = Cloud;
  const PERM = { view: 1, edit: 2, owner: 3 };
  const IN_APP = navigator.userAgent.includes('CloudAndroid/');
  const PALETTE = ['#d93025', '#e8710a', '#f9ab00', '#188038', '#12b5cb', '#1a73e8', '#6c4cf5', '#e52592', '#795548', '#5f6368'];
  const textOn = (hex) => { const n = parseInt(String(hex).slice(1), 16); return (0.299 * (n >> 16 & 255) + 0.587 * (n >> 8 & 255) + 0.114 * (n & 255)) > 170 ? '#202124' : '#fff'; };
  const safeColor = (c) => /^#[0-9a-f]{6}$/i.test(c || '') ? c : null;
  const appUrl = (act, it) => 'cloudapp://' + act + '?id=' + it.id + '&name=' + encodeURIComponent(it.name) + '&mime=' + encodeURIComponent(it.mime || '') + '&size=' + (it.size || 0);

  const state = {
    view: 'folder', folder: null, q: '', items: [], tags: [], tagId: null, crumbs: [], perm: 'owner', root: 'drive', trashDays: 30,
    sel: new Set(),
    layout: localStorage.getItem('cloud-layout') || (innerWidth < 576 ? 'list' : 'grid'),
    limit: 300, // koľko položiek sa vykreslí (pri posúvaní sa pridávajú ďalšie)
    sort: localStorage.getItem('cloud-sort') || 'name',
    dir: localStorage.getItem('cloud-dir') || 'asc',
  };
  const key = (it) => it.type + ':' + it.id;
  const isPhotoView = () => state.view === 'photos' || state.view === 'album';
  const CUSTOM_VIEWS = ['albums', 'map'];
  // zoradenie a rozloženie, ktoré sa robí v prehliadači (Aktivita, Fotky, album)
  const lsGet = (k, d) => { try { return localStorage.getItem(k) || d; } catch (e) { return d; } };
  state.actSort = { k: 'when', dir: 'desc' };
  state.photoSort = { k: lsGet('cloud-psort', 'taken'), dir: lsGet('cloud-pdir', 'desc') };
  state.photoLayout = lsGet('cloud-playout', 'photos');
  // zobrazenia mimo zložky (Nedávne, Kôš, Zdieľané, Hľadanie…) sa zoraďujú v prehliadači, každé si pamätá svoje
  state.vsort = {};
  const VSORT_DEF = { recent: ['updated', 'desc'], trash: ['deleted', 'desc'], search: ['rel', 'asc'], shared: ['updated', 'desc'], byme: ['updated', 'desc'] };
  const clientSortOf = () => {
    if (state.view === 'activity') return state.actSort;
    if (isPhotoView()) return state.photoSort;
    if (state.view === 'folder' || state.view === 'dupes' || CUSTOM_VIEWS.includes(state.view)) return null;
    if (!state.vsort[state.view]) { const d = VSORT_DEF[state.view] || [state.sort, state.dir]; state.vsort[state.view] = { k: d[0], dir: d[1] }; }
    return state.vsort[state.view];
  };
  const collSk = new Intl.Collator('sk', { numeric: true, sensitivity: 'base' });
  function clientSort() {
    const s0 = clientSortOf(); if (!s0) return;
    const get = { name: i => (i.name || '').toLowerCase(), when: i => (i.act || {}).date || '', actor: i => ((i.act || {}).actor || '').toLowerCase(),
      activity: i => (i.act || {}).label || '', location: i => (i.path || '').toLowerCase(), taken: i => i.taken || i.created || i.updated || '',
      updated: i => i.updated || '', created: i => i.created || '', size: i => i.type === 'd' ? -1 : (+i.size || 0), kind: i => i.type === 'd' ? '' : (i.mime || ''),
      deleted: i => i.deleted || '', rel: i => i._ord || 0 }[s0.k] || (() => '');
    const d = s0.dir === 'asc' ? 1 : -1, tie = (a, b) => s0.k === 'rel' ? 0 : ((b.act || {}).ev || b.id) - ((a.act || {}).ev || a.id);
    const foldersFirst = state.view !== 'activity';
    state.items.sort((a, b) => {
      if (foldersFirst && a.type !== b.type) return a.type === 'd' ? -1 : 1;
      const x = get(a), y = get(b);
      const c = typeof x === 'string' && typeof y === 'string' && !['when', 'taken', 'updated', 'created', 'deleted'].includes(s0.k) ? collSk.compare(x, y) : (x < y ? -1 : x > y ? 1 : 0);
      return c * d || tie(a, b);
    });
  }
  const byKey = (k) => state.items.find(i => key(i) === k);
  const canEditHere = () => state.view !== 'folder' || PERM[state.perm] >= 2; // mimo zložky sa nahráva do Môj disk

  /* ---------------- ikony a formáty ---------------- */
  function fileIcon(it) {
    if (it.type === 'd') return ['bi-folder-fill', 'ic-folder'];
    const m = it.mime || '', ext = (it.name.split('.').pop() || '').toLowerCase();
    if (m.startsWith('image/')) return ['bi-file-earmark-image-fill', 'ic-image'];
    if (m.startsWith('video/')) return ['bi-file-earmark-play-fill', 'ic-video'];
    if (m.startsWith('audio/')) return ['bi-file-earmark-music-fill', 'ic-audio'];
    if (m === 'application/pdf') return ['bi-file-earmark-pdf-fill', 'ic-pdf'];
    if (/^(doc|docx|odt|rtf)$/.test(ext)) return ['bi-file-earmark-word-fill', 'ic-word'];
    if (/^(xls|xlsx|ods|csv)$/.test(ext)) return ['bi-file-earmark-excel-fill', 'ic-excel'];
    if (/^(ppt|pptx|odp)$/.test(ext)) return ['bi-file-earmark-ppt-fill', 'ic-ppt'];
    if (/^(zip|rar|7z|tar|gz|bz2|xz)$/.test(ext)) return ['bi-file-earmark-zip-fill', 'ic-zip'];
    if (/^(js|ts|php|py|html|css|json|xml|sql|sh|java|c|cpp|cs|go|rb)$/.test(ext)) return ['bi-file-earmark-code-fill', 'ic-code'];
    if (m.startsWith('text/')) return ['bi-file-earmark-text-fill', 'ic-text'];
    return ['bi-file-earmark-fill', 'ic-file'];
  }
  const ext = (it) => (it.name.split('.').pop() || '').toLowerCase();
  const isText = (it) => it.type === 'f' && (/^text\//.test(it.mime) || /json|xml|javascript|x-php|x-sh|sql|x-empty/.test(it.mime)
    || /^(txt|md|csv|json|xml|html|css|js|ts|php|py|sql|sh|ini|conf|log|yml|yaml|env)$/.test(ext(it)));
  const isDocx = (it) => ext(it) === 'docx';
  const isSheet = (it) => /^(xlsx|xls|ods|csv)$/.test(ext(it));
  const fmtDate = (s) => {
    if (!s) return '';
    const d = new Date(s.replace(' ', 'T')), now = new Date();
    return d.toDateString() === now.toDateString() ? d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('sk-SK', { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
  };
  const fmtFull = (s) => new Date(s.replace(' ', 'T')).toLocaleString('sk-SK', { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const fileUrl = (id, inline, v) => base + 'api.php?action=download&id=' + id + (inline ? '&inline=1' : '') + (v ? '&v=' + v : '');
  const loadScript = (src) => new Promise((res, rej) => { if ($$('script').some(s => s.src === src)) return res(); const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('Nepodarilo sa načítať prehliadač dokumentov.')); document.head.appendChild(s); });

  /* ---------------- načítanie ---------------- */
  // kostra zoznamu, kým sa načítava (blikajúce plochy namiesto prázdnej stránky)
  function skeleton() {
    const box = $('#items');
    const layout = isPhotoView() ? (state.photoLayout === 'list' ? 'list' : state.photoLayout === 'grid' ? 'grid' : 'photos') : (state.view === 'dupes' || state.view === 'activity') ? 'list' : state.layout;
    box.className = 'items items-' + layout;
    $('#empty').classList.add('d-none');
    const n = (k) => Array.from({ length: k });
    if (layout === 'photos') box.innerHTML = '<div class="photo-grid mt-3">' + n(18).map(() => '<div class="photo-item thumb-loading"></div>').join('') + '</div>';
    else if (layout === 'grid') box.innerHTML = '<div class="sk-line thumb-loading mb-3" style="width:80px"></div><div class="grid grid-files">'
      + n(8).map(() => '<div class="file-card sk-card"><div class="d-flex align-items-center gap-2 p-2"><span class="sk-icon thumb-loading"></span><span class="sk-line thumb-loading flex-grow-1"></span></div><div class="file-thumb thumb-loading"></div></div>').join('') + '</div>';
    else box.innerHTML = n(10).map(() => '<div class="sk-row"><span class="sk-icon thumb-loading"></span><span class="sk-line thumb-loading" style="width:' + (35 + Math.round(Math.random() * 35)) + '%"></span></div>').join('');
  }
  /* ---------------- cache zoznamov: zložka sa ukáže hneď z pamäte a potichu sa overí na serveri ---------------- */
  const LC_KEY = 'cloud-lc-' + (window.CLOUD_UID || 0), LC_PERSIST = ['folder', 'recent', 'starred', 'photos', 'shared', 'byme', 'tag'];
  const lcMem = new Map();
  try { Object.entries(JSON.parse(localStorage.getItem(LC_KEY) || '{}')).forEach(([k, v]) => lcMem.set(k, v)); } catch (e) {}
  const lcKey = (p) => p.view === 'search' || p.view === 'dupes' ? null : [p.view, p.folder || 0, p.view === 'tag' ? p.tag : p.view === 'album' ? p.album : 0, p.sort, p.dir].join('|');
  let lcT;
  function lcPut(k, r) {
    if (!k) return;
    if (r.no_cache) { lcMem.delete(k); return; } // obsah zamknutých zložiek sa nikam neukladá
    lcMem.delete(k);
    lcMem.set(k, { t: Date.now(), r: { items: r.items, crumbs: r.crumbs, perm: r.perm, root: r.root, trash_days: r.trash_days, nohash: r.nohash, total: r.total, quota: r.quota } });
    while (lcMem.size > 60) lcMem.delete(lcMem.keys().next().value);
    clearTimeout(lcT);
    lcT = setTimeout(() => { // do prehliadača sa uloží iba posledných 25 zoznamov (bez názvov sa nič neukáže, kým sa neoverí)
      const o = {};
      [...lcMem.entries()].filter(([k]) => LC_PERSIST.includes(k.split('|')[0])).slice(-25).forEach(([k, v]) => { o[k] = { t: v.t, r: Object.assign({}, v.r, { items: v.r.items.slice(0, 150), total: Math.max(v.r.total || 0, v.r.items.length) }) }; });
      try { localStorage.setItem(LC_KEY, JSON.stringify(o)); } catch (e) { try { localStorage.removeItem(LC_KEY); } catch (e2) {} }
    }, 800);
  }
  const lcSame = (a, b) => a.items.length === b.items.length && (a.total || 0) === (b.total || 0) && JSON.stringify([a.items, a.crumbs, a.perm]) === JSON.stringify([b.items, b.crumbs, b.perm]);
  function listParams() {
    const params = { view: state.view, folder: state.folder || 0, q: state.q, tag: state.tagId || 0, sort: state.sort, dir: state.dir };
    if (state.view === 'album') params.album = state.albumId || 0;
    if (state.view === 'search') { Object.assign(params, searchParams(state.sf || {})); params.folder = 0; }
    return params;
  }
  /* ---------------- zložky zamknuté heslom ---------------- */
  async function relock(id) {
    const r = await api('api.php?action=folder_relock', { id });
    if (!r.ok) return toast(r.error, 'danger');
    for (const k of [...lcMem.keys()]) lcMem.delete(k);
    toast('Zložka je zamknutá', 'success');
    load();
  }
  $('#relockBtn').addEventListener('click', () => state.lockHere && relock(state.lockHere.id));
  function lockDialog(it, mode) {
    const set = mode === 'set', A = window.CloudAndroid;
    const id = 'lk' + Date.now();
    document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title"><i class="bi bi-${set ? 'lock' : 'key'} me-2"></i>${set ? 'Zamknúť zložku heslom' : 'Heslo zložky'}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="fw-semibold text-break mb-2" data-name></div>
        ${set ? '<p class="small text-body-secondary">Obsah zložky (aj podzložiek) uvidí iba ten, kto zadá heslo – aj ľudia, s ktorými ju zdieľaš. Po odomknutí ostane otvorená 2 hodiny alebo kým ju nezamkneš. Verejné odkazy a sieťový disk (WebDAV) k nej prístup nemajú.</p>'
              : '<label class="form-label small mb-1">Súčasné heslo zložky <span class="text-body-secondary">(alebo heslo k účtu, ak si ho zabudol)</span></label><input type="password" class="form-control mb-3" name="cur" autocomplete="current-password" required>'}
        <label class="form-label small mb-1">${set ? 'Heslo zložky' : 'Nové heslo'} <span class="text-body-secondary">${set ? '(aspoň 4 znaky)' : '(nechaj prázdne, ak meníš iba voľbu nižšie)'}</span></label>
        <input type="password" class="form-control mb-2" name="new" autocomplete="new-password" ${set ? 'required minlength="4"' : ''}>
        <input type="password" class="form-control" name="new2" autocomplete="new-password" placeholder="Zopakuj heslo" ${set ? 'required' : ''}>
        <div class="fw-semibold small mt-3 mb-1">Pýtať heslo znova</div>
        ${[['always', 'Vždy pri otvorení zložky', 'po odchode zo zložky sa hneď zamkne'], ['close', 'Po zatvorení prehliadača alebo aplikácie', 'kým je web / aplikácia otvorená, ostane odomknutá'], ['logout', 'Až po odhlásení', 'ostane odomknutá aj po zatvorení, kým sa neodhlásiš']].map(([v, l, d]) =>
          '<div class="form-check"><input class="form-check-input" type="radio" name="mode" id="' + id + v + '" value="' + v + '"' + ((it.lock_mode || 'close') === v ? ' checked' : '') + '><label class="form-check-label" for="' + id + v + '">' + l + '<span class="d-block small text-body-secondary">' + d + '</span></label></div>').join('')}
        <div class="small text-danger mt-2 d-none" data-err></div>
      </div>
      <div class="modal-footer border-0">${set ? '' : '<button type="button" class="btn btn-outline-danger rounded-pill me-auto" data-remove><i class="bi bi-unlock me-1"></i>Zrušiť heslo</button>'}
        <button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zavrieť</button><button class="btn btn-primary rounded-pill px-4">${set ? 'Zamknúť' : 'Zmeniť heslo'}</button></div></form></div></div>`);
    const el = document.getElementById(id), form = el.querySelector('form'), err = el.querySelector('[data-err]');
    el.querySelector('[data-name]').textContent = it.name;
    const v = (n) => (form.querySelector('[name="' + n + '"]') || {}).value || '';
    const fail = (m) => { err.textContent = m; err.classList.remove('d-none'); };
    const m = new bootstrap.Modal(el);
    const send = async (op) => {
      const mode = (form.querySelector('[name="mode"]:checked') || {}).value || 'close';
      if (op === 'change' && !v('new') && !v('new2')) op = 'mode';
      if (op === 'set' || op === 'change') {
        if (v('new').length < 4) return fail('Heslo musí mať aspoň 4 znaky.');
        if (v('new') !== v('new2')) return fail('Heslá sa nezhodujú.');
      }
      if (op !== 'set' && !v('cur')) return fail('Zadaj súčasné heslo zložky alebo heslo k účtu.');
      const r = await api('api.php?action=folder_lock', { id: it.id, op, password: v('cur'), new: v('new'), mode });
      if (!r.ok) return fail(r.error);
      if (op !== 'mode' && A && A.forgetFolderKey) { try { A.forgetFolderKey(String(it.id)); } catch (e) {} }
      if ((op === 'set' || op === 'change') && A && A.offerFolderKey) { try { A.offerFolderKey(String(it.id), it.name, v('new')); } catch (e) {} }
      m.hide();
      toast(op === 'remove' ? 'Heslo zložky je zrušené' : op === 'set' ? 'Zložka je zamknutá heslom' + (r.links ? ' – verejný odkaz na ňu už nefunguje' : '') : op === 'mode' ? 'Nastavenie uložené' : 'Heslo zložky je zmenené', 'success');
      for (const k of [...lcMem.keys()]) lcMem.delete(k);
      try { localStorage.removeItem(LC_KEY); } catch (e) {}
      load(true);
    };
    form.addEventListener('submit', (e) => { e.preventDefault(); send(set ? 'set' : 'change'); });
    el.querySelector('[data-remove]')?.addEventListener('click', () => send('remove'));
    el.addEventListener('shown.bs.modal', () => form.querySelector('input').focus());
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
  }

  /** obrazovka zamknutej zložky (keď sa okno s heslom zavrie bez odomknutia) */
  function lockedScreen(lk) {
    Object.assign(state, { items: [], crumbs: [{ id: lk.id, name: lk.name }], partial: false, lockHere: null });
    state.sel.clear();
    render();
    $('#empty')?.classList.add('d-none');
    $('#items').innerHTML = '<div class="locked-screen text-center py-5"><div class="lock-ic lock-ic-lg mb-3"><i class="bi bi-lock-fill"></i></div>'
      + '<h5 class="mb-1">Zložka je zamknutá</h5><div class="text-body-secondary mb-4 text-break">' + esc(lk.name) + '</div>'
      + '<button class="btn btn-primary rounded-pill px-4" data-unlock-now><i class="bi bi-unlock me-2"></i>Odomknúť</button></div>';
    $('#items [data-unlock-now]').onclick = async () => { if (await Cloud.unlockFolder(lk)) load(); };
  }
  function applyList(r, keepSel) {
    r.items.forEach((x, i) => { x._ord = i; });
    Object.assign(state, { items: r.items.slice(), crumbs: r.crumbs || [], perm: r.perm || 'owner', root: r.root || 'drive', trashDays: r.trash_days, nohash: r.nohash || 0, partial: r.total > r.items.length });
    if (!keepSel) state.limit = 300;
    if (!keepSel) state.sel.clear(); else if (!state.partial) state.sel = new Set([...state.sel].filter(byKey));
    clientSort();
    render(); if (r.quota) updateQuota(r.quota);
  }
  // prednačítanie zložky, keď na ňu ukážeš myšou / položíš prst
  const pfBusy = new Set();
  async function prefetchFolder(id) {
    const p = { view: 'folder', folder: id, q: '', tag: 0, sort: state.sort, dir: state.dir };
    const k = lcKey(p), c = lcMem.get(k);
    if (pfBusy.has(k) || (c && Date.now() - c.t < 30000) || pfBusy.size > 1) return;
    pfBusy.add(k);
    try { const r = await api('api.php?action=list', Object.assign({ limit: 250, __nolock: 1 }, p)); if (r.ok) lcPut(k, r); } finally { pfBusy.delete(k); }
  }

  async function load(keepSel) {
    if (CUSTOM_VIEWS.includes(state.view)) return loadCustom();
    const params = listParams(), ck = lcKey(params);
    const cached = !keepSel && ck ? lcMem.get(ck) : null;
    const ticket = state.loadTicket = (state.loadTicket || 0) + 1;
    let skT = null;
    if (cached) applyList(cached.r, false); // okamžite z pamäte
    else {
      skT = keepSel ? null : setTimeout(() => { $('#loading').classList.add('d-none'); skeleton(); }, 120);
      if (keepSel) $('#loading').classList.remove('d-none');
    }
    // najprv prvých 250 položiek → zoznam je hneď na obrazovke, zvyšok sa dotiahne na pozadí
    const r = await api('api.php?action=list', Object.assign({ limit: 250 }, params));
    if (ticket !== state.loadTicket) return; // medzitým sa otvorila iná zložka
    clearTimeout(skT);
    $('#loading').classList.add('d-none');
    if (!r.ok && r.locked) { lcMem.delete(ck); lockedScreen(r.locked); return; }
    if (!r.ok) { lcMem.delete(ck); toast(r.error, 'danger'); if (state.view === 'folder' && state.folder) go('#'); return; }
    state.lockHere = r.lock_here || null;
    state.actMore = r.more || 0;
    lcPut(ck, r);
    if (cached && lcSame(cached.r, r)) { if (r.quota) updateQuota(r.quota); }
    else applyList(r, keepSel || !!cached);
    // duplicity: kým server dopočítava kontrolné súčty, obnoviť o chvíľu
    clearTimeout(state.dupT);
    if (state.view === 'dupes' && r.nohash) state.dupT = setTimeout(() => { if (state.view === 'dupes') load(true); }, 15000);
    if (r.total > r.items.length) {
      const rest = await api('api.php?action=list', Object.assign({ offset: r.items.length }, params));
      if (ticket !== state.loadTicket || !rest.ok) return;
      state.items = state.items.concat(rest.items);
      state.partial = false;
      clientSort();
      if (keepSel || cached) state.sel = new Set([...state.sel].filter(byKey));
      render();
    }
  }

  function renderCrumbs() {
    const c = $('#crumbs');
    const titles = { album: 'Album: ' + ((state.crumbs[0] || {}).name || ''), albums: 'Albumy', map: 'Mapa fotiek', activity: 'Aktivita', recent: 'Nedávne', starred: 'S hviezdičkou', trash: 'Kôš', shared: 'Zdieľané so mnou', byme: 'Zdieľané mnou', dupes: 'Duplicitné súbory', photos: 'Fotky', search: state.q ? 'Výsledky pre „' + state.q + '“' : 'Výsledky hľadania' };
    const txt = (s) => '<span class="crumb-text" title="' + esc(s) + '">' + esc(s) + '</span>';
    if (state.view === 'tag') {
      const t = state.crumbs[0] || {};
      c.innerHTML = '<li class="breadcrumb-item active"><span class="tag-chip crumb-chip" style="background:' + (safeColor(t.color) || '#5f6368') + ';color:' + textOn(safeColor(t.color) || '#5f6368') + '">' + esc(t.name || 'Štítok') + '</span></li>';
      return;
    }
    if (state.view !== 'folder') { c.innerHTML = '<li class="breadcrumb-item active">' + txt(titles[state.view]) + '</li>'; return; }
    // cesta sa prispôsobí skutočnému miestu: najprv celé, potom „…“ v strede, nakoniec ikona namiesto „Môj disk“
    const rootLabel = state.root === 'shared' ? 'Zdieľané so mnou' : 'Môj disk';
    const all = state.crumbs.map((cr, i) => ({ ...cr, last: i === state.crumbs.length - 1 }));
    const build = (keep, icon) => {
      const rootInner = icon ? '<i class="bi ' + (state.root === 'shared' ? 'bi-people' : 'bi-hdd-stack') + '" title="' + rootLabel + '"></i>' : txt(rootLabel);
      let h = state.root === 'shared'
        ? '<li class="breadcrumb-item crumb-root"><a href="#shared">' + rootInner + '</a></li>'
        : '<li class="breadcrumb-item crumb-root' + (all.length ? '' : ' active') + '"><a href="#" data-drop-folder="0">' + rootInner + '</a></li>';
      let list = all;
      if (list.length > keep) {
        const hidden = list.slice(0, list.length - keep);
        const up = hidden[hidden.length - 1];
        h += '<li class="breadcrumb-item crumb-more"><a href="#folder/' + up.id + '" title="' + esc(hidden.map(x => x.name).join(' / ')) + '">…</a></li>';
        list = list.slice(-keep);
      }
      list.forEach(cr => {
        h += '<li class="breadcrumb-item' + (cr.last ? ' active' : '') + '">'
          + (cr.last ? txt(cr.name) : '<a href="#folder/' + cr.id + '" data-drop-folder="' + cr.id + '">' + txt(cr.name) + '</a>') + '</li>';
      });
      return h;
    };
    const fits = () => {
      const W = c.clientWidth;
      if (!W) return false;
      if (c.scrollWidth > W + 1) return false;
      const texts = [...c.querySelectorAll('.crumb-text')];
      for (const t of texts) {
        const isLast = t.closest('li') === c.lastElementChild;
        if (t.scrollWidth > t.clientWidth + 1 && !(isLast && t.clientWidth >= W * 0.45)) return false;
      }
      return true;
    };
    const configs = [];
    for (let k = Math.min(all.length, 4); k >= 1; k--) configs.push([k, false]);
    if (all.length) configs.push([1, true]); else configs.push([0, false]);
    for (const [k, icon] of configs) { c.innerHTML = build(k, icon); if (fits()) return; }
  }
  addEventListener('resize', () => { clearTimeout(window.__crT); window.__crT = setTimeout(renderCrumbs, 150); });
  // v aplikácii môže byť stránka vykreslená ešte skrytá (zámok odtlačkom) alebo s iným písmom → prepočítať pri zmene šírky
  (() => {
    const box = $('#crumbs')?.parentElement; if (!box) return;
    let lastW = -1;
    if ('ResizeObserver' in window) new ResizeObserver(() => {
      const w = Math.round(box.clientWidth); if (w === lastW) return; lastW = w;
      clearTimeout(window.__crR); window.__crR = setTimeout(() => { if (state.view) renderCrumbs(); }, 60);
    }).observe(box);
    document.fonts?.ready.then(() => { if (state.view) renderCrumbs(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && state.view) renderCrumbs(); });
  })();

  function render() {
    renderCrumbs();
    renderSearchChips();
    $('#relockBtn').classList.toggle('d-none', !(state.view === 'folder' && state.lockHere));
    document.title = (state.view === 'folder' ? (state.crumbs.at(-1)?.name || (state.root === 'shared' ? 'Zdieľané' : 'Môj disk')) : $('#crumbs').textContent) + ' · Cloud';
    const navKey = state.view === 'folder' ? (state.root === 'shared' ? 'shared' : 'drive') : state.view === 'search' ? 'drive' : ['album', 'albums', 'map'].includes(state.view) ? 'photos' : state.view;
    renderPhotoBar();
    $$('.drive-head .view-switch, .drive-head #sortMenu').forEach(el => (el.closest('.dropdown') || el).classList.toggle('d-none', CUSTOM_VIEWS.includes(state.view) || (state.view === 'activity' && el.classList.contains('view-switch'))));
    const ph = isPhotoView();
    $$('[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === (ph ? (state.photoLayout === 'list' ? 'list' : state.photoLayout === 'grid' ? 'grid' : 'photos') : state.layout)));
    $$('[data-view="photos"]').forEach(b => b.classList.toggle('d-none', !ph));
    $$('#sortMenu .sort-photo').forEach(a => a.closest('li').classList.toggle('d-none', !ph));
    $$('#sortMenu .sort-act').forEach(a => a.closest('li').classList.toggle('d-none', state.view !== 'activity'));
    $$('#sortMenu .sort-std').forEach(a => a.closest('li').classList.toggle('d-none', state.view === 'activity'));
    $$('#sortMenu .sort-trash').forEach(a => a.closest('li').classList.toggle('d-none', state.view !== 'trash'));
    $$('#sortMenu .sort-search').forEach(a => a.closest('li').classList.toggle('d-none', state.view !== 'search'));
    const csr = clientSortOf();
    $$('#sortMenu [data-sort]').forEach(a => a.classList.toggle('active', a.dataset.sort === (csr ? csr.k : state.sort)));
    $$('#sortMenu [data-dir]').forEach(a => a.classList.toggle('active', a.dataset.dir === (csr ? csr.dir : state.dir)));
    $$('.side-nav [data-nav]').forEach(a => a.classList.toggle('active', a.dataset.nav === navKey));
    $$('#sideTags [data-tag]').forEach(a => a.classList.toggle('active', state.view === 'tag' && +a.dataset.tag === state.tagId));
    document.body.classList.toggle('readonly', !canEditHere());

    // pruhy nad zoznamom
    $('#trashBar').classList.toggle('d-none', state.view !== 'trash' || !state.items.length);
    $('#trashInfo').textContent = state.trashDays > 0 ? 'Položky v koši sa natrvalo zmažú po ' + state.trashDays + ' dňoch. Stále sa započítavajú do úložiska.' : 'Položky v koši sa započítavajú do úložiska.';
    const sb = $('#sharedBar');
    if (state.view === 'shared') { sb.classList.remove('d-none'); sb.querySelector('span').textContent = 'Súbory a zložky, ktoré s tebou zdieľajú iní používatelia.'; }
    else if (state.view === 'folder' && state.root === 'shared') { sb.classList.remove('d-none'); sb.querySelector('span').textContent = state.perm === 'edit' ? 'Zdieľaná zložka – môžeš pridávať a upravovať.' : 'Zdieľaná zložka – iba na čítanie.'; }
    else if (state.view === 'dupes') { sb.classList.remove('d-none'); sb.querySelector('span').textContent = 'Súbory s rovnakým obsahom. Vyber kópie, ktoré nepotrebuješ, a zmaž ich – uvoľníš miesto.' + (state.nohash ? ' (' + state.nohash + ' súborov sa ešte kontroluje na pozadí – výsledok sa o chvíľu obnoví.)' : ''); }
    else sb.classList.add('d-none');

    const box = $('#items');
    const layout = isPhotoView() ? 'photos' : (state.view === 'dupes' || state.view === 'activity') ? 'list' : state.layout;
    box.className = 'items items-' + layout;
    $('#listHeader').classList.toggle('d-none', layout !== 'list' || !state.items.length);
    $('#colsDrop').classList.toggle('d-none', layout !== 'list');
    state.cols = colsNow();
    state.colsShown = fitCols(state.cols);
    renderHeader(box);
    $('#empty').classList.toggle('d-none', state.items.length > 0);
    const emptyTxt = { search: ['Nič sa nenašlo', 'Skús iný výraz.'], starred: ['Žiadne položky s hviezdičkou', 'Označ dôležité súbory hviezdičkou.'],
      trash: ['Kôš je prázdny', 'Zmazané položky sa sem presunú a dajú sa obnoviť.'], shared: ['Nikto s tebou zatiaľ nič nezdieľa', ''], byme: ['Zatiaľ nič nezdieľaš', 'Súbor alebo zložku zdieľaš cez ⋮ → Zdieľať.'],
      tag: ['S týmto štítkom zatiaľ nič nie je', 'Štítok pridáš cez ⋮ → Štítky a farba.'],
      activity: ['Zatiaľ žiadna aktivita', 'Tu uvidíš súbory a zložky podľa poslednej zmeny – kto ich nahral, upravil, presunul alebo zdieľal.'],
      photos: ['Zatiaľ žiadne fotky', 'Nahraj obrázky – zoradia sa podľa dátumu.'], album: ['Album je prázdny', 'Fotky pridáš cez ⋮ → Pridať do albumu.'], dupes: ['Žiadne duplicity', 'Každý súbor máš iba raz. 👍'], recent: ['Žiadne nedávne súbory', ''] }[state.view] || ['Zatiaľ tu nič nie je', 'Pretiahni sem súbory alebo použi tlačidlo Nový.'];
    $('#empty h5').textContent = emptyTxt[0];
    $('#empty p').textContent = emptyTxt[1];
    $('#empty .btn').classList.toggle('d-none', !canEditHere() || !['folder', 'photos'].includes(state.view));

    const visible = state.items.slice(0, state.limit);
    const folders = visible.filter(i => i.type === 'd'), files = visible.filter(i => i.type === 'f');
    let h = '';
    if (state.view === 'dupes') {
      let g = null, idx = 0;
      visible.forEach(it => {
        const d = it.dup || {};
        if (d.g !== g) {
          g = d.g; idx = 0;
          h += '<div class="dup-head"><span><b>' + d.n + '×</b> rovnaký súbor · ' + formatBytes(it.size) + ' každý · uvoľníš <b>' + formatBytes(it.size * (d.n - 1)) + '</b></span>'
            + '<button class="btn btn-sm btn-light rounded-pill" data-dup-keep="' + esc(g) + '">Vybrať kópie</button></div>';
        }
        idx++;
        h += listItem(it);
      });
    } else if (layout === 'photos') {
      if (state.view === 'photos') h += memoriesHtml();
      let month = '';
      const years = [...new Set(state.items.map(it => new Date((it.taken || it.updated).replace(' ', 'T')).getFullYear()))].filter(y => !isNaN(y));
      if (years.length > 1 && state.photoSort.k === 'taken') h += '<div class="photo-years">' + years.map(y => '<button class="btn btn-sm btn-light rounded-pill" data-year-jump="' + y + '">' + y + '</button>').join('') + '</div>';
      files.forEach(it => {
        const d = new Date((it.taken || it.updated).replace(' ', 'T'));
        const m = d.toLocaleDateString('sk-SK', { month: 'long', year: 'numeric' });
        if (state.photoSort.k !== 'taken') { if (!month) { month = '-'; h += '<div class="photo-grid">'; } }
        else if (m !== month) { if (month) h += '</div>'; month = m; h += '<div class="section-title photo-month">' + esc(m) + '</div><div class="photo-grid">'; }
        const vid = (it.mime || '').startsWith('video/');
        h += '<div class="item photo-item' + (it.thumb ? ' thumb-loading' : ' photo-noimg') + '" data-key="' + key(it) + '" data-year="' + d.getFullYear() + '"><input type="checkbox" class="form-check-input item-check" tabindex="-1">'
          + (it.thumb ? '<img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + it.id + '&v=' + encodeURIComponent(it.updated || '') + '" alt="" onload="thumbOk(this)" onerror="thumbErr(this,\'' + (vid ? 'bi-film' : 'bi-image') + ' text-body-secondary\')">' : '<i class="bi ' + (vid ? 'bi-film' : 'bi-image') + ' text-body-secondary"></i>')
          + (vid ? '<span class="photo-play"><i class="bi bi-play-fill"></i></span>' : '') + '</div>';
      });
      if (month) h += '</div>';
    } else if (layout === 'grid') {
      if (folders.length) h += '<div class="section-title">Zložky</div><div class="grid grid-folders">' + folders.map(gridItem).join('') + '</div>';
      if (files.length) h += '<div class="section-title">Súbory</div><div class="grid grid-files">' + files.map(gridItem).join('') + '</div>';
    } else {
      if (state.view === 'activity') {
        let day = '';
        visible.forEach(it => {
          const d = new Date(it.act.date.replace(' ', 'T')), dk = d.toDateString();
          if (state.actSort.k === 'when' && dk !== day) { day = dk; h += '<div class="act-day act-day-row">' + esc(d.toLocaleDateString('sk-SK', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })) + '</div>'; }
          h += listItem(it);
        });
        if (state.actMore && visible.length >= state.items.length) h += '<div class="text-center my-3"><button class="btn btn-light rounded-pill" data-act-more>Načítať staršie</button></div>';
      } else h = visible.map(listItem).join('');
    }
    if (state.items.length > visible.length) {
      h += '<div id="moreItems" class="text-center text-body-secondary small py-4"><div class="spinner-border spinner-border-sm me-2"></div>Zobrazených ' + visible.length + ' z ' + state.items.length + '</div>';
    }
    box.innerHTML = h;
    const more = document.getElementById('moreItems');
    if (more) {
      const io = new IntersectionObserver((en) => {
        if (en.some(x => x.isIntersecting)) { io.disconnect(); state.limit += 300; render(); }
      }, { rootMargin: '600px' });
      io.observe(more);
    }
    updateSel();
  }

  const badges = (it) => (it.locked ? '<i class="bi ' + (it.unlocked ? 'bi-unlock text-success' : 'bi-lock-fill text-body-secondary') + ' small ms-1" title="' + (it.unlocked ? 'Zamknutá heslom – teraz odomknutá' : 'Zamknutá heslom') + '"></i>' : '')
    + (it.starred ? '<i class="bi bi-star-fill text-warning small ms-1" title="S hviezdičkou"></i>' : '')
    + (it.share_info ? ((it.share_info.users || []).length ? '<span class="share-badge ms-1" title="' + esc(shareSummary(it)) + '"><i class="bi bi-people-fill"></i>' + it.share_info.users.length + '</span>' : '')
        + (it.share_info.link ? '<i class="bi bi-link-45deg small ms-1 ' + (it.share_info.link === 'expired' ? 'text-danger' : 'text-primary') + '" title="' + (it.share_info.link === 'expired' ? 'Verejný odkaz vypršal' : 'Verejný odkaz') + '"></i>' : '')
      : (it.shared ? '<i class="bi bi-people-fill text-body-secondary small ms-1" title="Zdieľané"></i>' : ''))
    + (it.versions ? '<span class="badge text-bg-light border ms-1" title="Počet starších verzií">v' + (it.versions + 1) + '</span>' : '');
  function tagChips(it) {
    if (!it.tags || !it.tags.length) return '';
    return '<span class="tag-chips">' + it.tags.map(t => '<span class="tag-chip" style="background:' + safeColor(t.color) + ';color:' + textOn(t.color) + '" title="' + esc(t.name) + '">' + esc(t.name) + '</span>').join('') + '</span>'
      + '<span class="tag-dots">' + it.tags.map(t => '<span style="background:' + safeColor(t.color) + '"></span>').join('') + '</span>';
  }
  const colorAttr = (it) => safeColor(it.color) ? ' style="--item-color:' + it.color + '"' : '';
  const colorCls = (it) => safeColor(it.color) ? ' has-color' : '';
  const iconStyle = (it) => safeColor(it.color) ? ' style="color:' + it.color + '"' : '';
  const hasThumb = (it) => it.type === 'f' && it.thumb && !it.deleted;
  // načítanie miniatúry: kým nepríde, bliká sivá plocha (thumb-loading)
  window.thumbOk = (img) => { img.classList.add('ok'); img.parentNode && img.parentNode.classList.remove('thumb-loading'); };
  window.thumbErr = (img, cls) => { const p = img.parentNode; if (p) p.classList.remove('thumb-loading'); img.replaceWith(Object.assign(document.createElement('i'), { className: 'bi ' + cls })); };
  function thumbHtml(it, big) {
    const [ic, cls] = fileIcon(it);
    if (it.type === 'f' && it.thumb && !it.deleted) return '<img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + it.id + '&v=' + encodeURIComponent(it.updated || '') + '" alt="" onload="thumbOk(this)" onerror="thumbErr(this,\'' + ic + ' ' + cls + '\')">';
    return '<i class="bi ' + ic + ' ' + cls + (big ? ' big' : '') + '"' + iconStyle(it) + '></i>';
  }
  function shareSummary(it) {
    const si = it.share_info; if (!si) return '';
    const parts = [];
    if (si.users && si.users.length) parts.push(si.users.join(', '));
    if (si.link) parts.push(si.link === 'expired' ? 'odkaz (vypršal)' : si.mode === 'upload' ? 'odkaz na nahrávanie' : si.mode === 'both' ? 'verejný odkaz + nahrávanie' : 'verejný odkaz');
    return parts.join(' · ');
  }
  function subText(it) {
    if (state.view === 'trash') return 'zmazané ' + fmtDate(it.deleted);
    if (state.view === 'byme' && it.share_info) return shareSummary(it);
    if (state.view === 'dupes' && it.dup) return it.dup.path;
    if (state.view === 'activity' && it.act) return it.act.actor + ' · ' + it.act.label.replace(/\(a\)/g, '') + ' · ' + fmtDate(it.act.date);
    if (it.owner) return it.owner;
    return fmtDate(it.updated);
  }
  /* ---------- stĺpce v zozname ---------- */
  const COLDEF = window.CLOUD_COLS || { labels: { updated: 'Zmenené', size: 'Veľkosť' }, views: {}, defaults: {}, mine: {} };
  if (Array.isArray(COLDEF.mine)) COLDEF.mine = {};
  const COLW = { size: '96px', kind: '150px', updated: '120px', created: '120px', taken: '120px', location: 'minmax(120px, .9fr)',
    owner: '140px', sharing: 'minmax(120px, .8fr)', versions: '64px', tags: 'minmax(100px, .6fr)', deleted: '120px',
    activity: 'minmax(150px, 1fr)', actor: '130px', when: '120px' };
  const SORTCOL0 = { size: 'size', updated: 'updated', created: 'created', kind: 'kind' };
  const colView = () => state.view === 'folder' && state.root === 'shared' ? 'shared' : (COLDEF.views[state.view] ? state.view : 'folder');
  function colsNow() {
    const v = colView();
    let c = COLDEF.mine[v] || COLDEF.defaults[v] || ['updated', 'size'];
    return c.filter(x => COLDEF.labels[x] && (x !== 'deleted' || v === 'trash'));
  }
  const KINDS = { jpg: 'Obrázok JPEG', jpeg: 'Obrázok JPEG', png: 'Obrázok PNG', gif: 'Obrázok GIF', webp: 'Obrázok WebP', heic: 'Fotka HEIC', heif: 'Fotka HEIF',
    bmp: 'Obrázok BMP', svg: 'Obrázok SVG', tif: 'Obrázok TIFF', tiff: 'Obrázok TIFF', raw: 'Fotka RAW', dng: 'Fotka RAW', cr2: 'Fotka RAW', nef: 'Fotka RAW',
    pdf: 'Dokument PDF', doc: 'Dokument Word', docx: 'Dokument Word', odt: 'Dokument ODT', rtf: 'Dokument RTF', pages: 'Dokument Pages',
    xls: 'Tabuľka Excel', xlsx: 'Tabuľka Excel', ods: 'Tabuľka ODS', csv: 'Tabuľka CSV', numbers: 'Tabuľka Numbers',
    ppt: 'Prezentácia PowerPoint', pptx: 'Prezentácia PowerPoint', odp: 'Prezentácia ODP', key: 'Prezentácia Keynote',
    txt: 'Textový súbor', md: 'Markdown', log: 'Záznam (log)', json: 'Dáta JSON', xml: 'Dáta XML', yml: 'Konfigurácia YAML', yaml: 'Konfigurácia YAML',
    html: 'Webová stránka', htm: 'Webová stránka', css: 'Štýly CSS', js: 'JavaScript', ts: 'TypeScript', php: 'Skript PHP', py: 'Skript Python', sql: 'Databáza SQL',
    sh: 'Skript Shell', kt: 'Kód Kotlin', java: 'Kód Java', c: 'Kód C', cpp: 'Kód C++', cs: 'Kód C#',
    zip: 'Archív ZIP', rar: 'Archív RAR', '7z': 'Archív 7z', gz: 'Archív GZ', tar: 'Archív TAR',
    mp4: 'Video MP4', mov: 'Video MOV', mkv: 'Video MKV', avi: 'Video AVI', webm: 'Video WebM', '3gp': 'Video 3GP', m4v: 'Video M4V',
    mp3: 'Zvuk MP3', wav: 'Zvuk WAV', m4a: 'Zvuk M4A', flac: 'Zvuk FLAC', ogg: 'Zvuk OGG', opus: 'Zvuk Opus', aac: 'Zvuk AAC',
    apk: 'Aplikácia Android', exe: 'Program Windows', msi: 'Inštalátor Windows', dmg: 'Obraz disku macOS', iso: 'Obraz disku ISO',
    ttf: 'Písmo', otf: 'Písmo', woff2: 'Písmo', ics: 'Kalendár', vcf: 'Kontakt', eml: 'E-mail', epub: 'E-kniha', stl: '3D model', psd: 'Photoshop', ai: 'Illustrator' };
  function kindOf(it) {
    if (it.type === 'd') return 'Zložka';
    const ext = it.name.includes('.') ? it.name.split('.').pop().toLowerCase() : '';
    if (KINDS[ext]) return KINDS[ext];
    const m = it.mime || '';
    if (m.startsWith('image/')) return 'Obrázok'; if (m.startsWith('video/')) return 'Video'; if (m.startsWith('audio/')) return 'Zvuk'; if (m.startsWith('text/')) return 'Text';
    return ext ? 'Súbor ' + ext.toUpperCase() : 'Súbor';
  }
  const herePath = () => (state.root === 'shared' ? 'Zdieľané so mnou' : 'Môj disk') + state.crumbs.map(c => ' / ' + c.name).join('');
  /** hodnota stĺpca ako HTML (null = prázdne) */
  function colVal(c, it) {
    switch (c) {
      case 'size': return it.type === 'f' ? formatBytes(it.size) : (it.count != null ? it.count + ' pol.' : null);
      case 'kind': return esc(kindOf(it));
      case 'updated': return it.updated ? '<span title="' + esc(fmtFull(it.updated)) + '">' + esc(fmtDate(it.updated)) + '</span>' : null;
      case 'created': return it.created ? '<span title="' + esc(fmtFull(it.created)) + '">' + esc(fmtDate(it.created)) + '</span>' : null;
      case 'taken': return it.taken ? '<span title="' + esc(fmtFull(it.taken)) + '">' + esc(fmtDate(it.taken)) + '</span>' : null;
      case 'deleted': return it.deleted ? esc(fmtDate(it.deleted)) : null;
      case 'location': { const p = it.path || (state.view === 'folder' ? herePath() : (it.owner ? 'Zdieľané so mnou' : null)); return p ? '<span title="' + esc(p) + '">' + esc(p) + '</span>' : null; }
      case 'owner': return esc(it.owner || 'ja');
      case 'sharing': { const t = shareSummary(it) || (it.shared ? 'Zdieľané' : ''); return t ? '<span title="' + esc(t) + '">' + esc(t) + '</span>' : null; }
      case 'versions': return it.type === 'f' && it.versions ? String(it.versions + 1) : null;
      case 'tags': return (it.tags || []).length ? tagChips(it) : null;
      case 'activity': {
        if (!it.act) return null;
        const x = it.act.extra || {};
        let t = it.act.label.replace(/\(a\)/g, '');
        if (it.act.action === 'rename' && x.from) t += ' (z „' + x.from + '“)';
        if ((it.act.action === 'share' || it.act.action === 'unshare') && x.to) t += ' – ' + x.to + (x.perm === 'edit' ? ' (úpravy)' : '');
        if (it.act.action === 'rewind' && x.to) t += ' ' + fmtFull(x.to);
        return '<span class="act-cell" title="' + esc(it.act.actor + ' ' + t) + '"><i class="bi ' + it.act.icon + ' text-' + it.act.color + ' me-1"></i>' + esc(t) + '</span>';
      }
      case 'actor': return it.act ? esc(it.act.actor) : null;
      case 'when': return it.act ? '<span title="' + esc(fmtFull(it.act.date)) + '">' + esc(new Date(it.act.date.replace(' ', 'T')).toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' })) + '</span>' : null;
    }
    return null;
  }
  const colText = (c, it) => { const v = colVal(c, it); return v == null ? null : v.replace(/<[^>]+>/g, ''); };
  // koľko stĺpcov sa zmestí vedľa názvu (na užšej obrazovke len prvé; v mobile sú pod názvom)
  // najmenšie šírky stĺpcov (px) – podľa nich sa určí, koľko stĺpcov sa zmestí vedľa názvu
  const COLMIN = { size: 96, kind: 150, updated: 120, created: 120, taken: 120, location: 150, owner: 140, sharing: 150, versions: 64, tags: 110, deleted: 120, activity: 170, actor: 120, when: 110 };
  const nameMin = () => colWidths.name || 240;
  /** stĺpce, ktoré sa zmestia (názov má prednosť) – ostatné sa ukážu na širšej obrazovke */
  function fitCols(cols) {
    const avail = ($('#items').clientWidth || $('.drive-head')?.clientWidth || innerWidth) - 36 - 44 - 16 - 24;
    let used = nameMin(); const out = [];
    for (const c of cols) {
      const w = (colWidths[c] || COLMIN[c] || 120) + 12;
      if (used + w > avail) break;
      used += w; out.push(c);
    }
    return out;
  }
  let lastW = innerWidth, rT;
  addEventListener('resize', () => { clearTimeout(rT); rT = setTimeout(() => { if (innerWidth !== lastW && state.items.length) { lastW = innerWidth; render(); } }, 120); });
  // šírky stĺpcov upravené ťahaním (uložené v tomto zariadení – závisia od veľkosti obrazovky)
  let colWidths = {};
  try { colWidths = JSON.parse(localStorage.getItem('cloud-colw') || '{}') || {}; } catch (e) {}
  const colTpl = (cols) => cols.map(c => colWidths[c] ? colWidths[c] + 'px' : (COLW[c] || '120px')).join(' ');
  function applyColWidths() {
    const w = colTpl(state.colsShown || []);
    const nm = colWidths.name ? 'minmax(' + colWidths.name + 'px, 1fr)' : 'minmax(200px, 1.6fr)';
    [$('#items'), $('#listHeader')].forEach(el => { el.style.setProperty('--cols', w); el.style.setProperty('--name-col', nm); });
  }
  function renderHeader(box) {
    const cols = state.colsShown || [];
    applyColWidths();
    const noSort = false;
    const cs = clientSortOf();
    const SORTCOL = state.view === 'activity' ? { activity: 'activity', actor: 'actor', when: 'when', location: 'location' }
      : cs ? Object.assign({ taken: 'taken', deleted: 'deleted', location: 'location' }, SORTCOL0) : SORTCOL0;
    const curK = cs ? cs.k : state.sort, curD = cs ? cs.dir : state.dir;
    const arrow = (k) => curK === k ? ' <i class="bi bi-arrow-' + (curD === 'asc' ? 'up' : 'down') + ' small"></i>' : '';
    const html = '<div class="c-check"><input type="checkbox" class="form-check-input" id="checkAll" aria-label="Vybrať všetko"></div>'
      + '<div class="c-name' + (noSort ? '' : ' sortable') + '" data-sort-col="' + (noSort ? '' : 'name') + '"><span class="col-label">Názov' + arrow('name') + '</span><span class="col-resize" data-resize="name" title="Potiahni na zmenu šírky · dvojklik = pôvodná"></span></div>'
      + cols.map(c => '<div class="c-col c-' + c + (SORTCOL[c] ? ' sortable' : '') + '" data-sort-col="' + (SORTCOL[c] || '') + '" data-col="' + c + '">'
          + '<span class="col-label">' + esc(COLDEF.labels[c]) + (SORTCOL[c] ? arrow(SORTCOL[c]) : '') + '</span>'
          + '<span class="col-resize" data-resize="' + c + '" title="Potiahni na zmenu šírky · dvojklik = pôvodná"></span></div>').join('')
      + '<div class="c-act"></div>';
    const h = $('#listHeader');
    if (h.dataset.html !== html) { h.innerHTML = html; h.dataset.html = html; }
  }
  /** zvýrazní hľadané slová (iba vo výsledkoch hľadania) */
  function hl(t) {
    const words = state.view === 'search' ? searchWords() : [];
    if (!words.length) return esc(t);
    const fold = (x) => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const src = String(t), f = fold(src);
    if (f.length !== src.length) return esc(src);
    const marks = new Array(src.length).fill(false);
    words.forEach(w => { const fw = fold(w); if (!fw) return; let i = f.indexOf(fw); while (i >= 0) { for (let j = i; j < i + fw.length; j++) marks[j] = true; i = f.indexOf(fw, i + fw.length); } });
    let out = '', open = false;
    for (let i = 0; i < src.length; i++) {
      if (marks[i] && !open) { out += '<mark>'; open = true; } else if (!marks[i] && open) { out += '</mark>'; open = false; }
      out += esc(src[i]);
    }
    return out + (open ? '</mark>' : '');
  }
  function searchWords() {
    return (state.q || '').replace(/(^|\s)-?\w+(:|>=|<=|>|<)("[^"]*"|\S+)/g, ' ').match(/"[^"]+"|\S+/g)?.map(w => w.replace(/"/g, '')).filter(w => w.length > 1) || [];
  }
  function listItem(it) {
    const k = key(it), cols = state.cols || [];
    const meta = cols.filter(c => c !== 'tags').map(c => colText(c, it)).filter(Boolean).slice(0, 3).join(' · ');
    return '<div class="item row-item' + colorCls(it) + '"' + colorAttr(it) + ' data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '"' + (it.type === 'd' && !it.deleted ? ' data-drop-folder="' + it.id + '"' : '') + '>'
      + '<div class="c-check"><input type="checkbox" class="form-check-input item-check" tabindex="-1"></div>'
      + '<div class="c-name"><span class="item-icon' + (hasThumb(it) ? ' thumb-loading' : '') + '">' + thumbHtml(it) + '</span>'
      + '<div class="name-wrap"><div class="name-line"><span class="item-name text-truncate" title="' + esc(it.name) + '">' + hl(it.name) + '</span>' + badges(it) + ((state.colsShown || []).includes('tags') ? '<span class="d-md-none d-inline-flex">' + tagChips(it) + '</span>' : tagChips(it)) + '</div>'
      + (meta ? '<div class="item-meta text-truncate">' + esc(meta) + '</div>' : '')
      + (it.snip ? '<div class="item-snip text-truncate" title="' + esc(it.snip) + '"><i class="bi bi-text-paragraph me-1"></i>…' + hl(it.snip) + '…</div>' : '') + '</div></div>'
      + (state.colsShown || []).map(c => '<div class="c-col c-' + c + '">' + (colVal(c, it) ?? '<span class="opacity-50">—</span>') + '</div>').join('')
      + '<div class="c-act"><button class="btn btn-icon btn-sm item-menu" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div></div>';
  }
  // výber stĺpcov (tlačidlo v hornej lište) – uloží sa do účtu, platí na webe aj v aplikácii
  let colsSaveT;
  function saveCols() {
    clearTimeout(colsSaveT);
    colsSaveT = setTimeout(async () => {
      const r = await api('api.php?action=prefs_save', { key: 'columns', value: JSON.stringify(COLDEF.mine) });
      if (!r.ok) toast(r.error || 'Nastavenie sa nepodarilo uložiť', 'danger');
    }, 400);
  }
  $('#colsDrop').addEventListener('show.bs.dropdown', () => {
    const v = colView(), cur = colsNow();
    const avail = Object.keys(COLDEF.labels).filter(c => c !== 'deleted' || v === 'trash');
    $('#colsMenu').innerHTML = '<h6 class="dropdown-header">Stĺpce – ' + esc(COLDEF.views[v] || '') + '</h6>'
      + avail.map(c => '<label class="dropdown-item d-flex align-items-center gap-2"><input type="checkbox" class="form-check-input m-0" data-col="' + c + '"' + (cur.includes(c) ? ' checked' : '') + '>' + esc(COLDEF.labels[c]) + '</label>').join('')
      + '<div class="dropdown-divider"></div><button class="dropdown-item small" data-cols-reset>Predvolené pre tento pohľad</button>'
      + '<a class="dropdown-item small" href="' + base + 'profile.php#columns">Nastaviť všetky pohľady…</a>';
  });
  $('#colsMenu').addEventListener('change', (e) => {
    const cb = e.target.closest('[data-col]'); if (!cb) return;
    const v = colView();
    const sel = [...$$('#colsMenu [data-col]')].filter(x => x.checked).map(x => x.dataset.col);
    COLDEF.mine[v] = sel; saveCols(); render();
  });
  $('#colsMenu').addEventListener('click', (e) => {
    if (!e.target.closest('[data-cols-reset]')) return;
    delete COLDEF.mine[colView()]; saveCols(); render();
    const cur = colsNow(); $$('#colsMenu [data-col]').forEach(x => { x.checked = cur.includes(x.dataset.col); });
  });
  // zmena šírky stĺpca ťahaním za okraj hlavičky (iba myšou na počítači)
  let resizing = null;
  $('#listHeader').addEventListener('pointerdown', (e) => {
    const hnd = e.target.closest('.col-resize'); if (!hnd || e.pointerType === 'touch') return;
    e.preventDefault(); e.stopPropagation();
    const cell = hnd.parentElement;
    resizing = { col: hnd.dataset.resize, x: e.clientX, w: cell.getBoundingClientRect().width };
    hnd.setPointerCapture(e.pointerId);
    document.body.classList.add('col-resizing');
  });
  $('#listHeader').addEventListener('pointermove', (e) => {
    if (!resizing) return;
    colWidths[resizing.col] = Math.round(Math.max(resizing.col === 'name' ? 140 : 56, Math.min(resizing.col === 'name' ? 1200 : 640, resizing.w + e.clientX - resizing.x)));
    applyColWidths();
  });
  const endResize = () => {
    if (!resizing) return;
    resizing = null; document.body.classList.remove('col-resizing');
    try { localStorage.setItem('cloud-colw', JSON.stringify(colWidths)); } catch (e) {}
    render(); // prepočítať, koľko stĺpcov sa zmestí
    setTimeout(() => { justResized = false; }, 0);
  };
  let justResized = false;
  $('#listHeader').addEventListener('pointerup', () => { if (resizing) justResized = true; endResize(); });
  $('#listHeader').addEventListener('pointercancel', endResize);
  $('#listHeader').addEventListener('dblclick', (e) => {
    const hnd = e.target.closest('.col-resize'); if (!hnd) return;
    delete colWidths[hnd.dataset.resize];
    try { localStorage.setItem('cloud-colw', JSON.stringify(colWidths)); } catch (x) {}
    render();
  });
  // triedenie kliknutím na hlavičku stĺpca
  $('#listHeader').addEventListener('click', (e) => {
    if (justResized || e.target.closest('.col-resize')) return;
    const h = e.target.closest('.sortable'); if (!h || !h.dataset.sortCol) return;
    const k = h.dataset.sortCol;
    if (clientSortOf()) return setSort(k);
    if (state.sort === k) state.dir = state.dir === 'asc' ? 'desc' : 'asc';
    else { state.sort = k; state.dir = ['updated', 'created', 'size'].includes(k) ? 'desc' : 'asc'; }
    localStorage.setItem('cloud-sort', state.sort); localStorage.setItem('cloud-dir', state.dir);
    load(true);
  });
  function gridItem(it) {
    const k = key(it);
    if (it.type === 'd') {
      return '<div class="item folder-card' + colorCls(it) + '"' + colorAttr(it) + ' data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '"' + (it.deleted ? '' : ' data-drop-folder="' + it.id + '"') + '>'
        + '<input type="checkbox" class="form-check-input item-check" tabindex="-1"><i class="bi ' + (it.shared || it.owner ? 'bi-folder-symlink-fill' : 'bi-folder-fill') + ' ic-folder"' + iconStyle(it) + '></i>'
        + '<span class="item-name text-truncate">' + hl(it.name) + '</span>' + badges(it) + tagChips(it)
        + '<button class="btn btn-icon btn-sm item-menu ms-auto" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div>';
    }
    const [ic, cls] = fileIcon(it);
    return '<div class="item file-card' + colorCls(it) + '"' + colorAttr(it) + ' data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '">'
      + '<input type="checkbox" class="form-check-input item-check" tabindex="-1">'
      + '<div class="file-thumb' + (hasThumb(it) ? ' thumb-loading' : '') + '">' + thumbHtml(it, true) + '</div>'
      + '<div class="file-meta"><i class="bi ' + ic + ' ' + cls + '"' + iconStyle(it) + '></i><span class="item-name text-truncate">' + hl(it.name) + '</span>'
      + '<button class="btn btn-icon btn-sm item-menu ms-auto" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div>'
      + '<div class="file-sub text-truncate">' + formatBytes(it.size) + ' · ' + esc(subText(it)) + ' ' + badges(it) + '</div>' + tagChips(it) + '</div>';
  }

  function updateQuota(q) {
    if (!q) return;
    const bar = $('#quotaBar'), txt = $('#quotaText');
    if (q.quota > 0 && bar) {
      const p = Math.min(100, q.used / q.quota * 100);
      bar.style.width = p + '%';
      bar.className = 'progress-bar ' + (p >= 90 ? 'bg-danger' : p >= 75 ? 'bg-warning' : 'bg-primary');
      txt.textContent = 'Využité ' + q.used_h + ' z ' + q.quota_h;
    } else if (txt) txt.textContent = 'Využité ' + q.used_h + ' (bez limitu)';
  }

  /* ---------------- výber ---------------- */
  function updateSel() {
    $$('#items .item').forEach(el => {
      const on = state.sel.has(el.dataset.key);
      el.classList.toggle('selected', on);
      el.querySelector('.item-check').checked = on;
    });
    const n = state.sel.size, items = selItems();
    $('#selBar').classList.toggle('d-none', n === 0);
    document.body.classList.toggle('has-selection', n > 0);
    $('#selCount').textContent = n + (n === 1 ? ' vybratá' : n < 5 ? ' vybraté' : ' vybratých');
    const minPerm = items.reduce((m, i) => Math.min(m, PERM[i.perm] || 1), 3);
    $$('#selBar [data-sel]').forEach(b => {
      let show = true;
      if (b.dataset.in === 'trash') show = state.view === 'trash';
      if (b.dataset.in === 'shared-root') show = state.view === 'shared';
      if (b.dataset.not === 'trash' && state.view === 'trash') show = false;
      if (b.dataset.perm && minPerm < PERM[b.dataset.perm]) show = false;
      if (b.dataset.sel === 'copy' && items.some(i => i.type === 'd')) show = false;
      b.classList.toggle('d-none', !show);
    });
    const ca = $('#checkAll'); ca.checked = n > 0 && n === state.items.length; ca.indeterminate = n > 0 && n < state.items.length;
  }
  const toggleSel = (k) => { state.sel.has(k) ? state.sel.delete(k) : state.sel.add(k); updateSel(); };
  const selItems = () => [...state.sel].map(byKey).filter(Boolean);
  const keysOf = (items) => items.map(key);

  /* ---------------- navigácia (hash) ---------------- */
  function go(h) { if (location.hash === h || (h === '#' && !location.hash)) route(); else location.hash = h; }
  function route() {
    const h = decodeURIComponent(location.hash.slice(1));
    if (h.startsWith('folder/')) { state.view = 'folder'; state.folder = parseInt(h.slice(7)) || null; }
    else if (['recent', 'starred', 'trash', 'shared', 'byme', 'dupes', 'photos', 'albums', 'map', 'activity'].includes(h)) { state.view = h; state.folder = null; }
    else if (h.startsWith('album/')) { state.view = 'album'; state.albumId = parseInt(h.slice(6)) || 0; state.folder = null; }
    else if (h.startsWith('tag/')) { state.view = 'tag'; state.tagId = parseInt(h.slice(4)) || null; state.folder = null; }
    else if (h.startsWith('search/')) {
      const raw = location.hash.slice(8), qi = raw.indexOf('?');
      state.view = 'search';
      state.q = decodeURIComponent(qi >= 0 ? raw.slice(0, qi) : raw);
      state.sf = Object.fromEntries(new URLSearchParams(qi >= 0 ? raw.slice(qi + 1) : ''));
      $('#searchInput').value = state.q;
    }
    else { state.view = 'folder'; state.folder = null; }
    if (state.view !== 'search') { $('#searchInput').value = ''; state.sf = {}; }
    const oc = bootstrap.Offcanvas.getInstance($('#sidebar')); oc && oc.hide();
    load();
  }
  window.addEventListener('hashchange', route);
  document.addEventListener('click', (e) => {
    const y = e.target.closest('[data-year-jump]');
    if (y) {
      const yr = y.dataset.yearJump;
      const go2 = () => { const el = document.querySelector('.photo-item[data-year="' + yr + '"]'); if (el) { el.closest('.photo-grid').previousElementSibling.scrollIntoView({ behavior: 'smooth', block: 'start' }); return true; } return false; };
      if (!go2()) { state.limit = state.items.length; render(); setTimeout(go2, 50); }
      return;
    }
    const b = e.target.closest('[data-dup-keep]'); if (!b) return;
    const grp = state.items.filter(i => i.dup && i.dup.g === b.dataset.dupKeep);
    grp.slice(1).forEach(i => state.sel.add(key(i)));
    render();
    toast('Vybraté kópie (najstarší ostáva). Zmaž ich tlačidlom v hornej lište.');
  });

  function open(it) {
    if (it.gone) { toast('Táto položka už bola natrvalo zmazaná.'); return; }
    if (state.view === 'trash' || it.deleted) { toast(state.view === 'activity' ? 'Položka je v koši – obnovíš ju v Koši alebo cez ⋮ → Obnoviť.' : 'Položku v koši najprv obnov.'); return; }
    if (it.type === 'd') go('#folder/' + it.id);
    else preview(it);
  }

  /* ---------------- akcie ---------------- */
  const curFolder = () => state.view === 'folder' ? (state.folder || 0) : 0;
  async function newFolder() {
    const name = await Cloud.prompt('Nová zložka', 'Bez názvu', 'Vytvoriť');
    if (!name) return;
    const r = await api('api.php?action=mkdir', { parent: curFolder(), name });
    r.ok ? (toast('Zložka vytvorená', 'success'), state.view === 'folder' ? load() : go('#')) : toast(r.error, 'danger');
  }
  async function newText() {
    const name = await Cloud.prompt('Nový textový súbor', 'Poznámky.txt', 'Vytvoriť');
    if (!name) return;
    const r = await api('api.php?action=mkfile', { parent: curFolder(), name });
    if (!r.ok) return toast(r.error, 'danger');
    if (state.view !== 'folder') go('#');
    await load();
    const it = state.items.find(i => i.type === 'f' && i.id === r.id);
    if (it) editText(it);
  }
  async function rename(it) {
    const name = await Cloud.prompt('Premenovať', it.name, 'Uložiť');
    if (!name || name === it.name) return;
    const r = await api('api.php?action=rename', { type: it.type, id: it.id, name });
    r.ok ? load() : toast(r.error, 'danger');
  }
  const whatTxt = (items) => items.length === 1 ? '„' + items[0].name + '“' : items.length + ' položiek';
  async function del(items) {
    const r = await api('api.php?action=delete', { items: keysOf(items) });
    if (!r.ok) return toast(r.error, 'danger');
    toastUndo(whatTxt(items) + ' presunuté do koša', async () => {
      const u = await api('api.php?action=restore', { items: keysOf(items) });
      u.ok ? (toast('Obnovené', 'success'), load()) : toast(u.error, 'danger');
    });
    load();
  }
  function toastUndo(msg, undo) {
    const wrap = $('#toasts');
    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 text-bg-dark';
    el.innerHTML = '<div class="d-flex align-items-center"><div class="toast-body flex-grow-1"></div><button class="btn btn-link text-info fw-semibold text-decoration-none">Späť</button><button type="button" class="btn-close btn-close-white me-2" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.toast-body').textContent = msg;
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 6000 }); t.show();
    el.querySelector('.btn-link').onclick = () => { t.hide(); undo(); };
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }
  async function restore(items) {
    const r = await api('api.php?action=restore', { items: keysOf(items) });
    r.ok ? (toast('Obnovené: ' + whatTxt(items), 'success'), load()) : toast(r.error, 'danger');
  }
  async function purge(items) {
    if (!await Cloud.confirm('Zmazať natrvalo ' + whatTxt(items) + '?', 'Túto akciu nie je možné vrátiť späť. Zmažú sa aj všetky verzie.', 'Zmazať natrvalo')) return;
    const r = await api('api.php?action=purge', { items: keysOf(items) });
    r.ok ? (toast('Natrvalo zmazané', 'success'), updateQuota(r.quota), load()) : toast(r.error, 'danger');
  }
  $('#emptyTrash').onclick = async () => {
    if (!await Cloud.confirm('Vysypať kôš?', 'Všetky položky v koši sa natrvalo zmažú. Akcia je nevratná.', 'Vysypať')) return;
    const r = await api('api.php?action=empty_trash');
    r.ok ? (toast('Kôš bol vysypaný (' + r.count + ')', 'success'), updateQuota(r.quota), load()) : toast(r.error, 'danger');
  };
  async function leave(items) {
    if (!await Cloud.confirm('Odstrániť zo zdieľaných?', 'Položky zmiznú z tvojho zoznamu. Vlastníkovi zostanú.', 'Odstrániť')) return;
    const r = await api('api.php?action=share_leave', { items: keysOf(items) });
    r.ok ? load() : toast(r.error, 'danger');
  }
  async function star(items) {
    const val = items.some(i => !i.starred) ? 1 : 0;
    const r = await api('api.php?action=star', { items: keysOf(items), value: val });
    r.ok ? (toast(val ? 'Pridaná hviezdička' : 'Hviezdička odstránená'), load(true)) : toast(r.error, 'danger');
  }
  function download(items) {
    if (items.length === 1 && items[0].type === 'f') { location.href = fileUrl(items[0].id); return; }
    location.href = base + 'api.php?action=zip&items=' + encodeURIComponent(keysOf(items).join(','));
    toast('Pripravujem ZIP archív…');
  }
  async function info(it) {
    const r = await api('api.php?action=info', { type: it.type, id: it.id });
    if (!r.ok) return toast(r.error, 'danger');
    const row = (k, v) => '<dt class="col-5 text-body-secondary fw-normal">' + esc(k) + '</dt><dd class="col-7 text-break">' + v + '</dd>';
    const rows = Object.entries(r.info).map(([k, v]) => row(k, esc(v)));
    if (r.location) { // umiestnenie hneď pod názvom – s odkazom do zložky
      const href = r.location.folder ? '#folder/' + r.location.folder : '#';
      rows.splice(1, 0, row('Umiestnenie', '<i class="bi bi-folder2 me-1 text-body-secondary"></i>' + esc(r.location.path)
        + '<br><a href="' + href + '" class="small" data-info-open>Otvoriť zložku</a>'));
    }
    $('#infoBody').innerHTML = rows.join('');
    $('#infoAct').innerHTML = '';
    loadActivity($('#infoAct'), { type: it.type, id: it.id }, 5, true);
    $$('#infoBody [data-info-open]').forEach(a => a.addEventListener('click', () => bootstrap.Modal.getInstance($('#infoModal'))?.hide()));
    bootstrap.Modal.getOrCreateInstance($('#infoModal')).show();
  }

  // presun / kópia – Select2
  let moveCtx = null;
  async function moveDialog(items, mode) {
    if (mode === 'copy' && items.some(i => i.type === 'd')) { toast('Kopírovať sa dajú iba súbory.', 'warning'); items = items.filter(i => i.type === 'f'); if (!items.length) return; }
    moveCtx = { items, mode };
    $('#moveTitle').textContent = (mode === 'copy' ? 'Kopírovať ' : 'Presunúť ') + whatTxt(items);
    $('#moveOk').textContent = mode === 'copy' ? 'Kopírovať' : 'Presunúť';
    const r = await api('api.php?action=folders', { exclude: mode === 'move' ? items.filter(i => i.type === 'd').map(i => i.id) : [] });
    if (!r.ok) return toast(r.error, 'danger');
    const $sel = jQuery('#moveTarget');
    if ($sel.data('select2')) $sel.select2('destroy');
    $sel.empty();
    r.results.forEach(o => { const opt = new Option(o.text, o.id, false, o.id === (state.folder || 0)); opt.dataset.shared = o.shared ? 1 : ''; $sel.append(opt); });
    $sel.select2({ theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#moveModal'), placeholder: 'Vyber zložku',
      templateResult: (o) => o.id === undefined ? o.text : jQuery('<span><i class="bi ' + (o.id == 0 ? 'bi-hdd-stack' : o.element?.dataset.shared ? 'bi-folder-symlink-fill ic-folder' : 'bi-folder-fill ic-folder') + ' me-2"></i>' + esc(o.text) + '</span>') });
    bootstrap.Modal.getOrCreateInstance($('#moveModal')).show();
  }
  $('#moveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('api.php?action=' + moveCtx.mode, { items: keysOf(moveCtx.items), target: $('#moveTarget').value });
    bootstrap.Modal.getInstance($('#moveModal')).hide();
    if (r.ok) { toast(moveCtx.mode === 'copy' ? 'Skopírované' : 'Presunuté', 'success'); load(); } else toast(r.error, 'danger');
  });
  async function moveTo(items, target) {
    if (items.some(i => i.type === 'd' && i.id === target)) return;
    const r = await api('api.php?action=move', { items: keysOf(items), target });
    r.ok ? (toast('Presunuté', 'success'), load()) : toast(r.error, 'danger');
  }

  /* ---------------- zdieľanie ---------------- */
  let shareIt = null;
  const shUsers = jQuery('#shUserSel');
  shUsers.select2({
    theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#shareModal'), placeholder: 'Napíš meno alebo e-mail…', minimumInputLength: 2,
    ajax: { delay: 250, transport: (params, success, failure) => { api('api.php?action=user_search', { q: params.data.term || '' }).then(r => r.ok ? success({ results: r.results }) : failure()); return { abort() {} }; } },
    language: { inputTooShort: () => 'Napíš aspoň 2 znaky', noResults: () => 'Nikto sa nenašiel', searching: () => 'Hľadám…', errorLoading: () => 'Chyba načítania' },
    templateResult: (o) => o.loading || !o.email ? o.text : jQuery('<div><div class="fw-semibold">' + esc(o.name) + '</div><div class="small text-body-secondary">' + esc(o.email) + '</div></div>'),
  });
  jQuery('#shPerm').select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity, dropdownParent: jQuery('#shareModal') });

  async function share(it) {
    shareIt = it;
    $('#shareTitle').textContent = 'Zdieľať „' + it.name + '“';
    $('[data-what]').textContent = it.type === 'd' ? 'zložku a celý jej obsah' : 'súbor';
    shUsers.val(null).trigger('change');
    await loadShare();
    bootstrap.Modal.getOrCreateInstance($('#shareModal')).show();
  }
  async function loadShare() {
    const r = await api('api.php?action=share_get', { type: shareIt.type, id: shareIt.id });
    if (!r.ok) return toast(r.error, 'danger');
    const ul = $('#shList');
    ul.innerHTML = r.users.length ? '' : '<li class="list-group-item px-0 small text-body-secondary">Zatiaľ nikto. Prístup máš iba ty.</li>';
    r.users.forEach(u => {
      const li = document.createElement('li');
      li.className = 'list-group-item px-0 d-flex align-items-center gap-2';
      li.innerHTML = '<span class="avatar avatar-sm"></span><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"></div><div class="small text-body-secondary text-truncate"></div></div>'
        + '<select class="form-select form-select-sm" style="width:auto"><option value="view">Čítanie</option><option value="edit">Úpravy</option></select>'
        + '<button class="btn btn-icon text-danger" title="Odobrať prístup"><i class="bi bi-x-lg"></i></button>';
      const av = li.querySelector('.avatar');
      if (u.avatar) { av.classList.add('has-img'); const im = document.createElement('img'); im.src = u.avatar; im.alt = ''; av.appendChild(im); }
      else av.textContent = u.name.charAt(0).toUpperCase();
      li.querySelector('.fw-semibold').textContent = u.name;
      li.querySelector('.small').textContent = u.email;
      const sel = li.querySelector('select'); sel.value = u.permission;
      sel.onchange = async () => { const x = await api('api.php?action=share_user_update', { sid: u.id, permission: sel.value }); x.ok ? toast('Oprávnenie zmenené') : toast(x.error, 'danger'); };
      li.querySelector('button').onclick = async () => { const x = await api('api.php?action=share_user_remove', { sid: u.id }); x.ok ? (li.remove(), toast('Prístup odobratý')) : toast(x.error, 'danger'); };
      ul.appendChild(li);
    });
    const l = r.link;
    $('#shLinkBox').classList.toggle('d-none', !l);
    $('#shLinkNone').classList.toggle('d-none', !!l);
    $('#shDelete').classList.toggle('d-none', !l);
    $('#shNative').classList.toggle('d-none', !l || !navigator.share);
    $('#shSave span').textContent = l ? 'Uložiť nastavenia' : 'Vytvoriť odkaz';
    $('#shUrl').value = l ? l.url : '';
    $('#shExpires').value = l ? l.expires : '';
    $('#shModeWrap').classList.toggle('d-none', shareIt.type !== 'd');
    jQuery('#shMode').val(l ? (l.mode || 'view') : 'view').trigger('change');
    $('#shPassword').value = '';
    $('#shPassword').placeholder = l && l.has_password ? '•••• (nastavené – nechaj prázdne)' : 'bez hesla';
    $('#shPwClearWrap').classList.toggle('d-none', !(l && l.has_password));
    $('#shPwClear').checked = false;
    $('#shStats').textContent = l ? (l.expired ? '⚠ Platnosť vypršala · ' : '') + (l.mode !== 'upload' ? 'Stiahnutia: ' + l.downloads : '') + (l.mode !== 'view' ? (l.mode === 'both' ? ' · ' : '') + 'Nahraté cez odkaz: ' + (l.uploads || 0) : '') + (l.has_password ? ' · chránené heslom' : '') : '';
    $('#shStats').classList.toggle('text-danger', !!(l && l.expired));
  }
  $('#shAdd').onclick = async () => {
    const users = shUsers.val() || [];
    if (!users.length) return toast('Vyber aspoň jedného používateľa', 'warning');
    const r = await api('api.php?action=share_user_add', { type: shareIt.type, id: shareIt.id, users, permission: $('#shPerm').value, notify: $('#shNotify').checked ? 1 : '' });
    if (!r.ok) return toast(r.error, 'danger');
    shUsers.val(null).trigger('change'); toast('Zdieľané', 'success'); loadShare(); load(true);
  };
  $('#shSave').onclick = async () => {
    const pw = $('#shPassword').value;
    const mode = $('#shPwClear').checked ? 'clear' : pw ? 'set' : 'keep';
    const r = await api('api.php?action=share_link_save', { type: shareIt.type, id: shareIt.id, expires: $('#shExpires').value, password: pw, password_mode: mode, mode: $('#shMode').value });
    if (!r.ok) return toast(r.error, 'danger');
    await loadShare(); load(true);
    try { await navigator.clipboard.writeText(r.url); toast('Odkaz uložený a skopírovaný', 'success'); } catch (e) { toast('Odkaz uložený', 'success'); }
  };
  $('#shDelete').onclick = async () => {
    if (!await Cloud.confirm('Zrušiť verejný odkaz?', 'Odkaz prestane fungovať pre všetkých, ktorí ho majú.', 'Zrušiť odkaz')) return;
    const r = await api('api.php?action=share_link_delete', { type: shareIt.type, id: shareIt.id });
    r.ok ? (toast('Odkaz zrušený'), loadShare(), load(true)) : toast(r.error, 'danger');
  };
  $('#shCopy').onclick = async () => { try { await navigator.clipboard.writeText($('#shUrl').value); toast('Skopírované', 'success'); } catch (e) { $('#shUrl').select(); document.execCommand('copy'); toast('Skopírované', 'success'); } };
  $('#shNative').onclick = () => navigator.share({ title: shareIt.name, url: $('#shUrl').value }).catch(() => {});

  /* ---------------- štítky a farby ---------------- */
  async function loadTags() {
    const r = await api('api.php?action=tags');
    if (!r.ok) return;
    state.tags = r.tags;
    const nav = $('#sideTags');
    if (!nav) return;
    nav.innerHTML = state.tags.length ? state.tags.map(t => '<a class="nav-link' + (state.view === 'tag' && state.tagId === t.id ? ' active' : '') + '" href="#tag/' + t.id + '" data-tag="' + t.id + '">'
      + '<span class="tag-dot" style="background:' + safeColor(t.color) + '"></span><span class="text-truncate">' + esc(t.name) + '</span>'
      + '<i class="bi ' + (t.scope === 'd' ? 'bi-folder' : 'bi-file-earmark') + ' small opacity-50" title="' + (t.scope === 'd' ? 'pre zložky' : 'pre súbory') + '"></i>'
      + '<span class="tag-count">' + t.count + '</span></a>').join('')
      : '<span class="small text-body-secondary px-3 d-block">Zatiaľ žiadne štítky</span>';
  }
  function palette(box, current, withNone, onPick) {
    box.innerHTML = (withNone ? '<button type="button" class="color-swatch none" data-c="" title="Bez farby"><i class="bi bi-slash-lg"></i></button>' : '')
      + PALETTE.map(c => '<button type="button" class="color-swatch" data-c="' + c + '" style="background:' + c + '" title="' + c + '"></button>').join('');
    const mark = (c) => $$('.color-swatch', box).forEach(b => { const on = b.dataset.c === (c ?? '\u0000'); b.classList.toggle('active', on); b.innerHTML = on && b.dataset.c ? '<i class="bi bi-check-lg"></i>' : (b.dataset.c ? '' : '<i class="bi bi-slash-lg"></i>'); });
    mark(current);
    box.onclick = (e) => { const b = e.target.closest('.color-swatch'); if (!b) return; mark(b.dataset.c); onPick(b.dataset.c); };
  }
  const tagOpt = (o) => {
    if (o.loading || !o.text) return o.text;
    const t = state.tags.find(x => String(x.id) === String(o.id));
    const c = t ? t.color : '#5f6368';
    return jQuery('<span><span class="tag-dot d-inline-block me-2" style="width:10px;height:10px;border-radius:3px;background:' + safeColor(c) + '"></span>' + esc(o.text) + (o.newTag ? ' <span class="text-body-secondary small">(nový)</span>' : '') + '</span>');
  };
  let tagCtx = null, pickedColor;
  async function tagsDialog(items) {
    await loadTags();
    tagCtx = { items, common: {} };
    pickedColor = undefined;
    $('#tagsTitle').textContent = items.length === 1 ? 'Štítky a farba „' + items[0].name + '“' : 'Štítky a farba (' + items.length + ' položiek)';
    const canColor = items.every(i => PERM[i.perm] >= 2);
    $('#colorBlock').classList.toggle('d-none', !canColor);
    const colors = [...new Set(items.map(i => i.color || ''))];
    $('#colorLabel').textContent = items.some(i => i.type === 'd') && items.some(i => i.type === 'f') ? 'Farba zložiek a súborov' : items[0].type === 'd' ? 'Farba zložky' : 'Farba súboru';
    palette($('#itemColors'), colors.length === 1 ? colors[0] : null, true, (c) => { pickedColor = c; });
    ['d', 'f'].forEach(scope => {
      const its = items.filter(i => i.type === scope);
      $('#tagBlock' + scope.toUpperCase()).classList.toggle('d-none', !its.length);
      if (!its.length) return;
      const common = its.map(i => (i.tags || []).map(t => t.id)).reduce((a, b) => a.filter(x => b.includes(x)));
      tagCtx.common[scope] = common;
      const $sel = jQuery('#tagSel' + scope.toUpperCase());
      if ($sel.data('select2')) $sel.select2('destroy');
      $sel.empty();
      state.tags.filter(t => t.scope === scope).forEach(t => $sel.append(new Option(t.name, t.id, false, common.includes(t.id))));
      $sel.select2({
        theme: 'bootstrap-5', width: '100%', dropdownParent: jQuery('#tagsModal'), tags: true,
        placeholder: 'Vyber štítky alebo napíš nový…',
        createTag: (p) => { const t = (p.term || '').trim(); return t ? { id: 'new:' + t, text: t, newTag: true } : null; },
        templateResult: tagOpt,
        templateSelection: (o, li) => { const t = state.tags.find(x => String(x.id) === String(o.id)); const c = t ? t.color : PALETTE[5]; jQuery(li).css({ background: c, color: textOn(c) }); return o.text; },
        language: { noResults: () => 'Napíš názov nového štítka a stlač Enter' },
      });
    });
    bootstrap.Modal.getOrCreateInstance($('#tagsModal')).show();
  }
  $('#tagsSave').addEventListener('click', async () => {
    const btn = $('#tagsSave'); btn.disabled = true;
    try {
      for (const scope of ['d', 'f']) {
        const its = tagCtx.items.filter(i => i.type === scope);
        if (!its.length) continue;
        const ids = [];
        for (const v of (jQuery('#tagSel' + scope.toUpperCase()).val() || [])) {
          if (String(v).startsWith('new:')) {
            const r = await api('api.php?action=tag_save', { name: v.slice(4), scope, color: PALETTE[(state.tags.length + ids.length) % PALETTE.length] });
            if (!r.ok) { toast(r.error, 'danger'); continue; }
            ids.push(r.id);
          } else ids.push(parseInt(v));
        }
        const remove = (tagCtx.common[scope] || []).filter(x => !ids.includes(x));
        const r = await api('api.php?action=tags_apply', { items: keysOf(its), add: ids, remove });
        if (!r.ok) toast(r.error, 'danger');
      }
      if (pickedColor !== undefined) {
        const r = await api('api.php?action=color_set', { items: keysOf(tagCtx.items), color: pickedColor });
        if (!r.ok) toast(r.error, 'danger');
      }
      bootstrap.Modal.getInstance($('#tagsModal')).hide();
      toast('Uložené', 'success');
      await loadTags(); load(true);
    } finally { btn.disabled = false; }
  });

  // správa štítkov
  let newTagColor = PALETTE[5];
  async function manageTags() {
    await loadTags();
    ['d', 'f'].forEach(scope => {
      const ul = $('#tagList' + scope.toUpperCase());
      const list = state.tags.filter(t => t.scope === scope);
      ul.innerHTML = list.length ? '' : '<li class="list-group-item px-0 small text-body-secondary">Žiadne</li>';
      list.forEach(t => {
        const li = document.createElement('li');
        li.className = 'list-group-item px-0 d-flex align-items-center gap-2 tag-row';
        li.innerHTML = '<input type="color" class="form-control form-control-color p-1" style="width:38px;height:34px" title="Farba"><input class="form-control form-control-sm" maxlength="50"><span class="small text-body-secondary text-nowrap">' + t.count + '×</span><button class="btn btn-icon btn-sm text-danger" title="Zmazať štítok"><i class="bi bi-trash3"></i></button>';
        const [col, name] = li.querySelectorAll('input');
        col.value = t.color; name.value = t.name;
        const save = async () => {
          const r = await api('api.php?action=tag_save', { id: t.id, name: name.value, color: col.value });
          if (!r.ok) { toast(r.error, 'danger'); name.value = t.name; return; }
          t.name = name.value; t.color = col.value; loadTags(); load(true);
        };
        col.addEventListener('change', save);
        name.addEventListener('change', save);
        li.querySelector('button').onclick = async () => {
          if (!await Cloud.confirm('Zmazať štítok „' + t.name + '“?', 'Štítok zmizne zo všetkých položiek. Súbory a zložky zostanú.', 'Zmazať')) return;
          const r = await api('api.php?action=tag_delete', { id: t.id });
          if (r.ok) { li.remove(); await loadTags(); if (state.view === 'tag' && state.tagId === t.id) go('#'); else load(true); } else toast(r.error, 'danger');
        };
        ul.appendChild(li);
      });
    });
    palette($('#tagNewColors'), newTagColor, false, (c) => { newTagColor = c; });
    bootstrap.Modal.getOrCreateInstance($('#tagManageModal')).show();
  }
  $('#tagNewForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await api('api.php?action=tag_save', { name: $('#tagNewName').value, scope: $('#tagNewScope').value, color: newTagColor });
    if (!r.ok) return toast(r.error, 'danger');
    $('#tagNewName').value = '';
    toast('Štítok pridaný', 'success');
    manageTags();
  });
  $('#tagManageBtn')?.addEventListener('click', () => { bootstrap.Offcanvas.getInstance($('#sidebar'))?.hide(); manageTags(); });

  /* ---------------- verzie ---------------- */
  let verIt = null;
  async function versions(it) {
    verIt = it;
    const r = await api('api.php?action=versions', { id: it.id });
    if (!r.ok) return toast(r.error, 'danger');
    $('#verTitle').textContent = 'Verzie „' + r.name + '“';
    $('#verHint').textContent = 'Pri nahratí súboru s rovnakým názvom sa uloží nová verzia. Uchováva sa ' + r.max + ' posledných verzií.';
    $('#verUploadBtn').classList.toggle('d-none', !r.can_edit);
    const ul = $('#verList'); ul.innerHTML = '';
    r.versions.forEach((v, i) => {
      const li = document.createElement('li');
      li.className = 'list-group-item px-0 d-flex align-items-center gap-2';
      li.innerHTML = '<i class="bi ' + (v.current ? 'bi-check-circle-fill text-success' : 'bi-clock-history text-body-secondary') + ' fs-5"></i>'
        + '<div class="flex-grow-1"><div class="fw-semibold">' + esc(fmtFull(v.date)) + (v.current ? ' <span class="badge text-bg-success">aktuálna</span>' : '') + '</div><div class="small text-body-secondary">' + esc(v.size_h) + '</div></div>'
        + '<a class="btn btn-icon" title="Stiahnuť" href="' + fileUrl(it.id, false, v.current ? 0 : v.id) + '"><i class="bi bi-download"></i></a>'
        + (!v.current && r.can_edit ? '<button class="btn btn-icon" data-act="restore" title="Obnoviť túto verziu"><i class="bi bi-arrow-counterclockwise"></i></button><button class="btn btn-icon text-danger" data-act="del" title="Zmazať verziu"><i class="bi bi-trash3"></i></button>' : '');
      li.querySelector('[data-act="restore"]')?.addEventListener('click', async () => {
        const x = await api('api.php?action=version_restore', { vid: v.id });
        x.ok ? (toast('Verzia obnovená', 'success'), versions(it), load(true)) : toast(x.error, 'danger');
      });
      li.querySelector('[data-act="del"]')?.addEventListener('click', async () => {
        const x = await api('api.php?action=version_delete', { vid: v.id });
        x.ok ? (li.remove(), updateQuota(x.quota), load(true)) : toast(x.error, 'danger');
      });
      ul.appendChild(li);
    });
    bootstrap.Modal.getOrCreateInstance($('#versionsModal')).show();
  }
  $('#verUpload').onchange = (e) => {
    const f = e.target.files[0]; e.target.value = '';
    if (!f || !verIt) return;
    bootstrap.Modal.getInstance($('#versionsModal')).hide();
    enqueue([{ file: f, rel: '', name: verIt.name, folder: verIt.folder_id || 0 }]);
  };

  /* ---------------- textový editor ---------------- */
  let edIt = null, edUpdated = null, edDirty = false;
  async function editText(it) {
    const r = await api('api.php?action=text', { id: it.id });
    if (!r.ok) return toast(r.error, 'danger');
    if (!r.editable) return toast(r.truncated ? 'Súbor je príliš veľký na úpravu.' : 'Tento súbor môžeš iba čítať.', 'warning');
    edIt = it; edUpdated = r.updated; edDirty = false;
    $('#edName').textContent = it.name; $('#edText').value = r.text; $('#edStatus').textContent = '';
    bootstrap.Modal.getInstance($('#previewModal'))?.hide();
    bootstrap.Modal.getOrCreateInstance($('#editorModal')).show();
  }
  $('#editorModal').addEventListener('shown.bs.modal', () => $('#edText').focus());
  $('#edText').addEventListener('input', () => { edDirty = true; $('#edStatus').textContent = 'Neuložené zmeny'; });
  $('#edText').addEventListener('keydown', (e) => {
    if (e.key === 'Tab') { e.preventDefault(); const t = e.target, s = t.selectionStart; t.setRangeText('    ', s, t.selectionEnd, 'end'); edDirty = true; }
  });
  async function saveText() {
    $('#edStatus').textContent = 'Ukladám…';
    const r = await api('api.php?action=save_text', { id: edIt.id, content: $('#edText').value, updated: edUpdated });
    if (!r.ok) { $('#edStatus').textContent = 'Neuložené'; return toast(r.error, 'danger'); }
    edUpdated = r.updated; edDirty = false;
    $('#edStatus').textContent = 'Uložené ' + new Date().toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' });
    load(true);
  }
  $('#edSave').onclick = saveText;
  document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 's' && $('#editorModal').classList.contains('show')) { e.preventDefault(); saveText(); } });
  $('#edClose').onclick = async () => {
    if (edDirty && !await Cloud.confirm('Zahodiť zmeny?', 'Máš neuložené zmeny.', 'Zahodiť')) return;
    bootstrap.Modal.getInstance($('#editorModal')).hide();
  };

  /* ---------------- náhľad ---------------- */
  let pvIdx = -1;
  const pvList = () => (typeof pvOverride !== 'undefined' && pvOverride) || state.items.filter(i => i.type === 'f');
  async function preview(it) {
    const files = pvList();
    pvIdx = files.findIndex(f => f.id === it.id);
    const body = $('#previewBody');
    $('#previewName').textContent = it.name;
    $('#previewDownload').href = fileUrl(it.id);
    if (IN_APP) { $('#previewOpenApp').classList.remove('d-none'); $('#previewOpenApp').href = appUrl('open', it); $('#previewSend').classList.remove('d-none'); $('#previewSend').href = appUrl('send', it); }
    $('#previewPrev').style.visibility = pvIdx > 0 ? 'visible' : 'hidden';
    $('#previewNext').style.visibility = pvIdx < files.length - 1 ? 'visible' : 'hidden';
    $('#previewEdit')?.remove();
    const m = it.mime, u = fileUrl(it.id, true);
    bootstrap.Modal.getOrCreateInstance($('#previewModal')).show();
    if (/^image\/(jpeg|png|gif|webp|bmp)/.test(m)) {
      // najprv rozmazaná miniatúra + spinner, potom plná fotka
      body.innerHTML = '<div class="pv-img">' + (it.thumb ? '<img class="pv-low" src="' + base + 'api.php?action=thumb&id=' + it.id + '&v=' + encodeURIComponent(it.updated || '') + '" alt="">' : '')
        + '<div class="pv-spin spinner-border text-light"></div><img class="pv-full" src="' + u + '" alt=""></div>';
      const box = body.firstChild, full = box.querySelector('.pv-full');
      const done = () => box.classList.add('loaded');
      if (full.complete && full.naturalWidth) done(); else { full.onload = done; full.onerror = () => { done(); toast('Fotku sa nepodarilo načítať', 'danger'); }; }
    }
    else if (m.startsWith('video/')) body.innerHTML = '<video src="' + u + '" controls autoplay playsinline></video>';
    else if (m.startsWith('audio/')) body.innerHTML = '<div class="text-center"><i class="bi bi-music-note-beamed display-1 d-block mb-4 text-white-50"></i><audio src="' + u + '" controls autoplay></audio></div>';
    else if (m === 'application/pdf' && (IN_APP || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent))) {
      // mobil: prehliadač PDF v stránke nezobrazí → v aplikácii vstavaný prehliadač, inak nová karta
      const av = (navigator.userAgent.match(/CloudAndroid\/(\d+)\.(\d+)\.(\d+)/) || []).slice(1).map(Number);
      const native = IN_APP && av.length === 3 && (av[0] > 1 || av[1] > 0 || av[2] >= 19);
      const href = IN_APP ? appUrl(native ? 'pdf' : 'open', it) : u;
      body.innerHTML = '<div class="text-center text-white px-3"><i class="bi bi-file-earmark-pdf display-1 d-block mb-3 text-danger"></i>'
        + '<div class="mb-3 text-white-50">' + esc(it.name) + ' · ' + formatBytes(it.size) + '</div>'
        + '<a class="btn btn-light rounded-pill px-4" href="' + esc(href) + '"' + (IN_APP ? '' : ' target="_blank" rel="noopener"') + '><i class="bi bi-book me-2"></i>Otvoriť PDF</a></div>';
      if (IN_APP) location.href = href;
    }
    else if (m === 'application/pdf') body.innerHTML = '<iframe src="' + u + '" title="PDF"></iframe>';
    else if (isDocx(it) || (isSheet(it) && ext(it) !== 'csv')) {
      body.innerHTML = '<div class="spinner-border text-light"></div>';
      try {
        const buf = await (await fetch(fileUrl(it.id), { credentials: 'same-origin' })).arrayBuffer();
        if (isDocx(it)) {
          await loadScript('https://cdn.jsdelivr.net/npm/mammoth@1.8.0/mammoth.browser.min.js');
          const res = await mammoth.convertToHtml({ arrayBuffer: buf });
          body.innerHTML = '<div class="doc-preview"></div>';
          body.firstChild.innerHTML = res.value || '<p class="text-muted">Dokument je prázdny.</p>';
          body.firstChild.querySelectorAll('script,iframe,object,embed').forEach(x => x.remove());
        } else {
          await loadScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');
          renderSheet(body, XLSX.read(buf, { type: 'array' }));
        }
      } catch (e) { body.innerHTML = '<div class="text-white text-center">' + esc(e.message || 'Náhľad zlyhal') + '</div>'; }
    } else if ((isText(it) || ext(it) === 'csv') && it.size < 2e6) {
      body.innerHTML = '<div class="spinner-border text-light"></div>';
      const r = await api('api.php?action=text', { id: it.id });
      if (ext(it) === 'csv' && r.ok) {
        try { await loadScript('https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js'); renderSheet(body, XLSX.read(r.text, { type: 'string' })); }
        catch (e) { body.innerHTML = '<pre class="preview-text"></pre>'; body.firstChild.textContent = r.text; }
      } else {
        body.innerHTML = '<pre class="preview-text"></pre>';
        body.firstChild.textContent = r.ok ? r.text + (r.truncated ? '\n\n… (skrátené)' : '') : r.error;
      }
      if (r.ok && r.editable) {
        $('#previewDownload').insertAdjacentHTML('beforebegin', '<button class="btn btn-sm btn-light rounded-pill px-3" id="previewEdit"><i class="bi bi-pencil-square me-1"></i>Upraviť</button>');
        $('#previewEdit').onclick = () => editText(it);
      }
    } else {
      const [ic, cls] = fileIcon(it);
      body.innerHTML = '<div class="text-center text-white"><i class="bi ' + ic + ' ' + cls + '" style="font-size:6rem"></i><p class="mt-3 mb-1 fw-semibold">' + esc(it.name) + '</p><p class="text-white-50">' + formatBytes(it.size) + ' · náhľad nie je dostupný</p>'
        + (IN_APP ? '<a class="btn btn-primary rounded-pill px-4 me-2 mb-2" href="' + appUrl('open', it) + '"><i class="bi bi-box-arrow-up-right me-2"></i>Otvoriť v aplikácii</a>' : '')
        + '<a class="btn btn-light rounded-pill px-4 mb-2" href="' + fileUrl(it.id) + '"><i class="bi bi-download me-2"></i>Stiahnuť</a></div>';
    }
  }
  function renderSheet(body, wb) {
    body.innerHTML = '<div class="sheet-preview"><div class="sheet-tabs"></div><div class="sheet-body"></div></div>';
    const tabs = body.querySelector('.sheet-tabs'), sb = body.querySelector('.sheet-body');
    const show = (n) => {
      sb.innerHTML = XLSX.utils.sheet_to_html(wb.Sheets[n], { header: '', footer: '' });
      sb.querySelectorAll('script').forEach(x => x.remove());
      $$('button', tabs).forEach(b => b.classList.toggle('active', b.dataset.n === n));
    };
    wb.SheetNames.forEach(n => { const b = document.createElement('button'); b.dataset.n = n; b.textContent = n; b.onclick = () => show(n); tabs.appendChild(b); });
    tabs.classList.toggle('d-none', wb.SheetNames.length < 2);
    show(wb.SheetNames[0]);
  }
  const pvStep = (d) => { const n = pvList()[pvIdx + d]; if (n) preview(n); };
  $('#previewPrev').onclick = () => pvStep(-1);
  $('#previewNext').onclick = () => pvStep(1);
  $('#previewModal').addEventListener('hidden.bs.modal', () => { $('#previewBody').innerHTML = ''; });
  document.addEventListener('keydown', (e) => {
    if (!$('#previewModal').classList.contains('show')) return;
    if (e.key === 'ArrowLeft') pvStep(-1); if (e.key === 'ArrowRight') pvStep(1);
  });
  (() => { // swipe na mobile
    let x0 = null;
    $('#previewBody').addEventListener('touchstart', e => x0 = e.touches[0].clientX, { passive: true });
    $('#previewBody').addEventListener('touchend', e => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 60 && !e.target.closest('.sheet-preview,.doc-preview,.preview-text')) pvStep(dx < 0 ? 1 : -1); x0 = null; });
  })();

  /* ---------------- kontextové menu ---------------- */
  const ctx = $('#ctxMenu');
  let ctxItems = [];
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act-more]'); if (!b) return;
    b.disabled = true;
    const r = await api('api.php?action=list', { view: 'activity', before: state.actMore });
    if (!r.ok) { b.disabled = false; return toast(r.error, 'danger'); }
    state.items = state.items.concat(r.items); state.actMore = r.more || 0; state.limit = state.items.length;
    render();
  });
  function showCtx(items, x, y) {
    if (items.some(i => i.gone)) { toast('Táto položka už bola natrvalo zmazaná.'); return; }
    ctxItems = items;
    const single = items.length === 1 ? items[0] : null;
    const trash = state.view === 'trash';
    const minPerm = items.reduce((m, i) => Math.min(m, PERM[i.perm] || 1), 3);
    const vis = {
      open: single && !trash, openapp: IN_APP && single && !trash && single.type === 'f', send: IN_APP && single && !trash && single.type === 'f', edit: single && !trash && isText(single) && minPerm >= 2, download: !trash,
      share: single && !trash && minPerm === 3, rename: single && !trash && minPerm >= 2, move: !trash && minPerm >= 2,
      copy: !trash && !items.some(i => i.type === 'd'), star: !trash && minPerm === 3, versions: single && !trash && single.type === 'f',
      info: single && !trash, tags: !trash, restore: trash, leave: state.view === 'shared', delete: !trash && minPerm >= 2, purge: trash,
      albumadd: !trash && items.every(i => i.type === 'f' && /^(image|video)\//.test(i.mime || '')),
      albumremove: state.view === 'album', albumcover: state.view === 'album' && single && single.thumb,
      activity: single && !trash, rewind: single && !trash && single.type === 'd' && minPerm >= 2,
      lock: single && !trash && single.type === 'd' && single.perm === 'owner' && !single.locked,
      lockedit: single && !trash && single.type === 'd' && single.perm === 'owner' && !!single.locked,
      relock: single && !trash && single.type === 'd' && !!single.unlocked,
    };
    Object.entries(vis).forEach(([k, v]) => $('[data-ctx="' + k + '"]', ctx).parentElement.classList.toggle('d-none', !v));
    $('[data-sep]', ctx).classList.toggle('d-none', trash);
    $('[data-sep2]', ctx).classList.toggle('d-none', !(vis.delete || vis.purge));
    $('[data-ctx="star"] span', ctx).textContent = items.every(i => i.starred) ? 'Odstrániť hviezdičku' : 'Pridať hviezdičku';
    ctx.classList.add('show');
    const w = ctx.offsetWidth, h = ctx.offsetHeight;
    ctx.style.left = Math.max(8, Math.min(x, innerWidth - w - 8)) + 'px';
    ctx.style.top = Math.max(8, Math.min(y, innerHeight - h - 8)) + 'px';
  }
  const hideCtx = () => ctx.classList.remove('show');
  document.addEventListener('click', (e) => { if (!e.target.closest('#ctxMenu') && !e.target.closest('.item-menu')) hideCtx(); });
  addEventListener('scroll', hideCtx, true); addEventListener('resize', hideCtx);
  ctx.addEventListener('click', (e) => {
    const a = e.target.closest('[data-ctx]'); if (!a) return;
    e.preventDefault(); hideCtx();
    const it = ctxItems[0], items = ctxItems;
    ({ open: () => open(it), openapp: () => { location.href = appUrl('open', it); }, send: () => { location.href = appUrl('send', it); }, edit: () => editText(it), download: () => download(items), share: () => share(it), rename: () => rename(it),
       move: () => moveDialog(items, 'move'), copy: () => moveDialog(items, 'copy'), star: () => star(items), versions: () => versions(it),
       info: () => info(it), tags: () => tagsDialog(items), restore: () => restore(items), leave: () => leave(items), delete: () => del(items), purge: () => purge(items),
       albumadd: () => albumAddDialog(items), albumremove: () => albumRemove(items), albumcover: () => albumCover(it),
       activity: () => activityDialog(it), rewind: () => rewindDialog(it),
       lock: () => lockDialog(it, 'set'), lockedit: () => lockDialog(it, 'edit'), relock: () => relock(it.id) })[a.dataset.ctx]();
  });

  /* ---------------- udalosti v zozname ---------------- */
  const itemsBox = $('#items');
  let lpTimer = null, lpFired = false;
  let pfT;
  const pfStart = (e) => {
    const el = e.target.closest('.item[data-key^="d:"]');
    if (!el || state.view === 'trash') return;
    const fit = byKey(el.dataset.key);
    if (fit && fit.locked && !fit.unlocked) return;
    clearTimeout(pfT);
    pfT = setTimeout(() => prefetchFolder(+el.dataset.key.slice(2)), e.type === 'touchstart' ? 0 : 120);
  };
  itemsBox.addEventListener('pointerover', pfStart);
  itemsBox.addEventListener('touchstart', pfStart, { passive: true });
  itemsBox.addEventListener('pointerout', () => clearTimeout(pfT));
  itemsBox.addEventListener('click', (e) => {
    const el = e.target.closest('.item'); if (!el) return;
    const k = el.dataset.key, it = byKey(k);
    if (lpFired) { lpFired = false; return; }
    if (e.target.closest('.item-menu')) {
      e.stopPropagation();
      const items = state.sel.has(k) && state.sel.size > 1 ? selItems() : [it];
      const r = e.target.closest('.item-menu').getBoundingClientRect();
      if (ctx.classList.contains('show') && ctxItems[0] === it) return hideCtx();
      showCtx(items, r.right - 220, r.bottom + 4); return;
    }
    if (e.target.classList.contains('item-check') || e.ctrlKey || e.metaKey || e.shiftKey || state.sel.size > 0) { toggleSel(k); return; }
    open(it);
  });
  itemsBox.addEventListener('contextmenu', (e) => {
    const el = e.target.closest('.item'); if (!el) return;
    e.preventDefault();
    const k = el.dataset.key;
    if (!state.sel.has(k)) { state.sel.clear(); state.sel.add(k); updateSel(); }
    showCtx(selItems(), e.clientX, e.clientY);
  });
  itemsBox.addEventListener('touchstart', (e) => {
    const el = e.target.closest('.item'); if (!el || e.target.closest('.item-menu')) return;
    lpFired = false;
    lpTimer = setTimeout(() => { lpFired = true; navigator.vibrate && navigator.vibrate(30); toggleSel(el.dataset.key); }, 500);
  }, { passive: true });
  ['touchend', 'touchmove', 'touchcancel'].forEach(ev => itemsBox.addEventListener(ev, () => clearTimeout(lpTimer), { passive: true }));

  // ťahanie položiek do zložky
  let dragKeys = null;
  itemsBox.addEventListener('dragstart', (e) => {
    const el = e.target.closest('.item'); if (!el) return;
    const k = el.dataset.key;
    dragKeys = state.sel.has(k) ? [...state.sel] : [k];
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/x-cloud', dragKeys.join(','));
  });
  itemsBox.addEventListener('dragend', () => { dragKeys = null; $$('.drop-target').forEach(x => x.classList.remove('drop-target')); });
  document.addEventListener('dragover', (e) => {
    const t = e.target.closest('[data-drop-folder]');
    if (dragKeys && t) { e.preventDefault(); $$('.drop-target').forEach(x => x !== t && x.classList.remove('drop-target')); t.classList.add('drop-target'); }
  });
  document.addEventListener('drop', (e) => {
    const t = e.target.closest('[data-drop-folder]');
    if (dragKeys && t) { e.preventDefault(); moveTo(dragKeys.map(byKey).filter(Boolean), parseInt(t.dataset.dropFolder) || 0); dragKeys = null; }
  });

  $('#listHeader').addEventListener('change', (e) => { if (e.target.id !== 'checkAll') return; state.sel = new Set(e.target.checked ? state.items.map(key) : []); updateSel(); });
  $('#selBar').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sel]'); if (!b) return;
    const items = selItems();
    ({ clear: () => { state.sel.clear(); updateSel(); }, download: () => download(items), move: () => moveDialog(items, 'move'),
       copy: () => moveDialog(items, 'copy'), star: () => star(items), delete: () => del(items), restore: () => restore(items), tags: () => tagsDialog(items),
       purge: () => purge(items), leave: () => leave(items) })[b.dataset.sel]();
  });
  /* ---------------- klávesové skratky ---------------- */
  const SHORTCUTS = [
    ['Všeobecné', [['/', 'Hľadať'], ['?', 'Zoznam skratiek'], ['Shift + F', 'Nová zložka'], ['Shift + T', 'Nový textový súbor'], ['U', 'Nahrať súbory'], ['V', 'Prepnúť zoznam / mriežku'],
      ['Alt + ↑  /  Backspace', 'Do nadradenej zložky'], ['G potom D / R / S / P / A / K', 'Môj disk / Nedávne / S hviezdičkou / Fotky / Aktivita / Kôš']]],
    ['Výber a pohyb', [['↑ ↓ ← →', 'Pohyb medzi položkami'], ['Shift + šípky', 'Rozšíriť výber'], ['Medzerník', 'Označiť / odznačiť'], ['Ctrl + A', 'Vybrať všetko'], ['Esc', 'Zrušiť výber'],
      ['Shift + klik', 'Vybrať rozsah'], ['Home / End', 'Prvá / posledná položka']]],
    ['Položky', [['Enter', 'Otvoriť'], ['F2  /  N', 'Premenovať'], ['Delete', 'Do koša'], ['Shift + Delete', 'Natrvalo zmazať (v koši)'], ['S', 'Hviezdička'], ['I', 'Informácie'],
      ['.', 'Zdieľať'], ['D', 'Stiahnuť'], ['Z', 'Presunúť do…'], ['H', 'História zmien'], ['Ctrl + C / X / V', 'Kopírovať / vystrihnúť / vložiť sem'], ['Shift + F10', 'Menu položky']]],
    ['Náhľad', [['← →', 'Predchádzajúci / ďalší'], ['Medzerník', 'Prezentácia spustiť / zastaviť'], ['D', 'Stiahnuť'], ['Esc', 'Zavrieť']]],
  ];
  function showShortcuts() {
    let m = $('#kbdModal');
    if (!m) {
      document.body.insertAdjacentHTML('beforeend', '<div class="modal fade" id="kbdModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
        + '<div class="modal-header border-0"><h5 class="modal-title"><i class="bi bi-keyboard me-2"></i>Klávesové skratky</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
        + '<div class="modal-body pt-0"><div class="kbd-grid">' + SHORTCUTS.map(([g, list]) => '<div><div class="kbd-group">' + esc(g) + '</div>' + list.map(([k, t]) => '<div class="kbd-row"><span>' + esc(t) + '</span><span class="kbd-keys">'
          + k.split(/\s+(\/|potom)\s+|\s*\+\s*/).filter(x => x && x.trim()).map(x => x === '/' || x === 'potom' ? '<span class="kbd-sep">' + (x === '/' ? 'alebo' : 'potom') + '</span>' : '<kbd>' + esc(x) + '</kbd>').join('') + '</span></div>').join('') + '</div>').join('') + '</div></div></div></div></div>');
      m = $('#kbdModal');
    }
    bootstrap.Modal.getOrCreateInstance(m).show();
  }
  window.cloudShortcuts = showShortcuts;
  // kurzor (zameraná položka) – pohyb šípkami
  let cursor = null, anchor = null, gPending = 0, clip = null;
  const itemEls = () => $$('#items .item[data-key]');
  function setCursor(k, extend) {
    if (!k) return;
    const els = itemEls(), keys = els.map(el => el.dataset.key);
    if (extend && anchor) {
      const a = keys.indexOf(anchor), b = keys.indexOf(k);
      state.sel = new Set(keys.slice(Math.min(a, b), Math.max(a, b) + 1));
    } else if (extend !== null) { state.sel = new Set([k]); anchor = k; }
    cursor = k;
    updateSel();
    els.forEach(el => el.classList.toggle('kb-cursor', el.dataset.key === k));
    const el = els[keys.indexOf(k)];
    if (el) el.scrollIntoView({ block: 'nearest' });
  }
  function moveCursor(dir, extend) {
    const els = itemEls(); if (!els.length) return;
    let i = els.findIndex(el => el.dataset.key === cursor);
    if (i < 0) return setCursor(els[0].dataset.key, extend);
    const r0 = els[i].getBoundingClientRect();
    let j = i;
    if (dir === 'left') j = Math.max(0, i - 1);
    else if (dir === 'right') j = Math.min(els.length - 1, i + 1);
    else if (dir === 'home') j = 0;
    else if (dir === 'end') j = els.length - 1;
    else { // hore / dole: najbližšia položka v susednom riadku
      const step = dir === 'down' ? 1 : -1;
      let best = -1, bestRow = null, bestDx = Infinity;
      for (let k = i + step; k >= 0 && k < els.length; k += step) {
        const r = els[k].getBoundingClientRect();
        if (Math.abs(r.top - r0.top) < 4) continue;
        if (bestRow === null) bestRow = r.top;
        if (Math.abs(r.top - bestRow) > 4) break;
        const dx = Math.abs(r.left - r0.left);
        if (dx < bestDx) { bestDx = dx; best = k; }
      }
      if (best >= 0) j = best;
    }
    setCursor(els[j].dataset.key, extend);
  }
  // Shift + klik = rozsah
  itemsBox.addEventListener('click', (e) => {
    const el = e.target.closest('.item[data-key]'); if (!el) return;
    if (e.shiftKey && anchor && anchor !== el.dataset.key) { e.stopImmediatePropagation(); setCursor(el.dataset.key, true); return; }
    anchor = cursor = el.dataset.key;
  }, true);
  const parentHash = () => {
    if (state.view !== 'folder' || !state.folder) return null;
    const c = state.crumbs; const p = c.length > 1 ? c[c.length - 2] : null;
    return p && p.id ? '#folder/' + p.id : (state.root === 'shared' ? '#shared' : '#');
  };
  async function paste() {
    if (!clip || !clip.items.length) return;
    if (!['folder'].includes(state.view)) return toast('Vložiť sa dá iba do zložky.', 'warning');
    if (PERM[state.perm] < 2) return toast('Do tejto zložky nemôžeš vkladať.', 'warning');
    const target = state.folder || 0;
    let items = clip.items;
    if (clip.mode === 'copy') { items = items.filter(i => i.type === 'f'); if (!items.length) return toast('Kopírovať sa dajú iba súbory.', 'warning'); }
    const r = await api('api.php?action=' + clip.mode, { items: keysOf(items), target });
    if (!r.ok) return toast(r.error, 'danger');
    toast((clip.mode === 'copy' ? 'Skopírované: ' : 'Presunuté: ') + items.length, 'success');
    if (clip.mode === 'move') clip = null;
    load(true);
  }
  document.addEventListener('keydown', (e) => {
    const t = e.target;
    if (t.matches('input, textarea, select, [contenteditable]') || t.closest('.select2-container')) return;
    // náhľad
    if ($('#previewModal').classList.contains('show')) {
      if (e.key === ' ') { e.preventDefault(); $('#previewPlay').click(); }
      else if (e.key.toLowerCase() === 'd' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); $('#previewDownload').click(); }
      return;
    }
    if (document.querySelector('.modal.show')) return;
    const k = e.key, ctrl = e.ctrlKey || e.metaKey, low = k.length === 1 ? k.toLowerCase() : k;
    const sel = selItems(), one = sel.length === 1 ? sel[0] : null;
    if (k === '?' || (k === '/' && e.shiftKey)) { e.preventDefault(); return showShortcuts(); }
    // G potom písmeno: prechod na zobrazenie
    if (gPending && Date.now() - gPending < 1200 && !ctrl) {
      gPending = 0;
      const to = { d: '#', r: '#recent', s: '#starred', p: '#photos', a: '#activity', k: '#trash', z: '#shared', t: '#trash' }[low];
      if (to) { e.preventDefault(); return go(to); }
    }
    if (low === 'g' && !ctrl && !e.shiftKey && !e.altKey) { gPending = Date.now(); return; }
    if (ctrl && low === 'a') { e.preventDefault(); state.sel = new Set(state.items.map(key)); return updateSel(); }
    if (ctrl && (low === 'c' || low === 'x')) {
      if (!sel.length || window.getSelection().toString()) return;
      e.preventDefault();
      if (low === 'x' && sel.some(i => PERM[i.perm] < 2)) return toast('Niektoré položky nemôžeš presúvať.', 'warning');
      clip = { mode: low === 'c' ? 'copy' : 'move', items: sel };
      return toast((low === 'c' ? 'Skopírované do schránky: ' : 'Vystrihnuté: ') + sel.length + ' – vlož v cieľovej zložke Ctrl + V');
    }
    if (ctrl && low === 'v') { if (clip) { e.preventDefault(); paste(); } return; }
    if (ctrl || e.altKey && k !== 'ArrowUp') return;
    if (k === 'Escape') { state.sel.clear(); cursor = null; $$('.kb-cursor').forEach(x => x.classList.remove('kb-cursor')); updateSel(); hideCtx(); return; }
    if (k === 'ArrowUp' && e.altKey || (k === 'Backspace' && !state.sel.size)) { const p = parentHash(); if (p) { e.preventDefault(); go(p); } return; }
    const arrows = { ArrowUp: 'up', ArrowDown: 'down', ArrowLeft: 'left', ArrowRight: 'right', Home: 'home', End: 'end' };
    if (arrows[k]) { e.preventDefault(); return moveCursor(arrows[k], e.shiftKey); }
    if (k === ' ') { if (!cursor) return; e.preventDefault(); toggleSel(cursor); return; }
    if (k === 'Enter') { const it = byKey(cursor) || one; if (it) { e.preventDefault(); open(it); } return; }
    if (k === 'F10' && e.shiftKey) { if (!sel.length) return; e.preventDefault(); const el = $('#items .item[data-key="' + cssEsc(key(sel[0])) + '"]'); const r = el ? el.getBoundingClientRect() : { left: 100, bottom: 100 }; return showCtx(sel, r.left + 40, r.bottom - 10); }
    if (k === 'Delete' || (k === 'Backspace' && state.sel.size)) {
      if (!sel.length) return; e.preventDefault();
      if (state.view === 'trash' || (e.shiftKey && sel.every(i => i.deleted))) return purge(sel);
      if (e.shiftKey && state.view !== 'trash') return toast('Natrvalo sa mazať dá iba v koši (Shift + Delete).', 'warning');
      return del(sel.filter(i => PERM[i.perm] >= 2));
    }
    if (e.shiftKey && low === 'f') { e.preventDefault(); return newFolder(); }
    if (e.shiftKey && low === 't') { e.preventDefault(); return newText(); }
    if (e.shiftKey) return;
    if (low === 'u') { e.preventDefault(); return $('#fileInput').click(); }
    if (low === 'v') { e.preventDefault(); const L0 = isPhotoView() ? (state.photoLayout === 'photos' ? 'grid' : state.photoLayout === 'grid' ? 'list' : 'photos') : (state.layout === 'list' ? 'grid' : 'list'); return setLayout(L0); }
    if (!sel.length) return;
    const acts = {
      f2: () => one && rename(one), n: () => one && rename(one), s: () => star(sel), i: () => one && info(one), '.': () => one && share(one),
      d: () => download(sel), z: () => moveDialog(sel, 'move'), h: () => one && activityDialog(one),
    };
    const fn = acts[k === 'F2' ? 'f2' : low];
    if (fn) { e.preventDefault(); fn(); }
  });
  const cssEsc = (s0) => (window.CSS && CSS.escape) ? CSS.escape(s0) : s0.replace(/"/g, '\\"');

  // prepínače
  function setLayout(v) {
    if (isPhotoView()) { state.photoLayout = v; try { localStorage.setItem('cloud-playout', v); } catch (e) {} render(); return; }
    if (v === 'photos') return;
    state.layout = v; localStorage.setItem('cloud-layout', state.layout); render();
  }
  $$('[data-view]').forEach(b => b.onclick = () => setLayout(b.dataset.view));
  function setSort(k, dir) {
    const cs = clientSortOf();
    if (cs) { // Aktivita a Fotky sa zoraďujú v prehliadači
      if (k) { cs.dir = dir || (cs.k === k ? (cs.dir === 'asc' ? 'desc' : 'asc') : (['when', 'taken', 'updated', 'created', 'size', 'deleted'].includes(k) ? 'desc' : 'asc')); cs.k = k; }
      else if (dir) cs.dir = dir;
      if (isPhotoView()) { try { localStorage.setItem('cloud-psort', cs.k); localStorage.setItem('cloud-pdir', cs.dir); } catch (e) {} }
      clientSort(); render(); return;
    }
    if (k) { state.sort = k; } if (dir) state.dir = dir;
    localStorage.setItem('cloud-sort', state.sort); localStorage.setItem('cloud-dir', state.dir);
    load();
  }
  $$('#sortMenu [data-sort]').forEach(a => a.onclick = (e) => { e.preventDefault(); const cs = clientSortOf(); if (cs && cs.k === a.dataset.sort) return; setSort(a.dataset.sort); });
  $$('#sortMenu [data-dir]').forEach(a => a.onclick = (e) => { e.preventDefault(); setSort(null, a.dataset.dir); });
  $('#crumbs').addEventListener('click', (e) => { const a = e.target.closest('a'); if (a && a.dataset.dropFolder === '0') { e.preventDefault(); go('#'); } });
  $$('.side-nav [data-nav]').forEach(a => {
    const map = { drive: '#', recent: '#recent', starred: '#starred', trash: '#trash', shared: '#shared', byme: '#byme', dupes: '#dupes', photos: '#photos', activity: '#activity' };
    if (map[a.dataset.nav] !== undefined) a.addEventListener('click', (e) => { e.preventDefault(); go(map[a.dataset.nav]); });
  });

  /* ================= Fotky: lišta, spomienky, albumy, mapa, prezentácia ================= */
  function renderPhotoBar() {
    const bar = $('#photoBar');
    if (state.view === 'activity' && false) {
      bar.classList.remove('d-none');
      bar.innerHTML = '<div class="small text-body-secondary">Súbory a zložky podľa poslednej zmeny. Celú históriu konkrétnej položky nájdeš cez ⋮ → Aktivita.</div>'
        + '<div class="pb-right"><button class="btn btn-sm btn-light rounded-pill" data-pb="history"><i class="bi bi-clock-history me-1"></i>Celá história</button></div>';
      return;
    }
    const show = ['photos', 'album', 'albums', 'map'].includes(state.view);
    bar.classList.toggle('d-none', !show);
    if (!show) return;
    const tab = (v, href, ic, t) => '<a class="pb-tab' + (state.view === v || (v === 'albums' && state.view === 'album') ? ' active' : '') + '" href="' + href + '"><i class="bi ' + ic + '"></i><span>' + t + '</span></a>';
    let right = '';
    if (isPhotoView() && state.items.some(i => i.type === 'f')) right += '<button class="btn btn-sm btn-light rounded-pill" data-pb="slideshow"><i class="bi bi-play-fill me-1"></i>Prezentácia</button>';
    if (state.view === 'albums') right += '<button class="btn btn-sm btn-primary rounded-pill" data-pb="newalbum"><i class="bi bi-plus-lg me-1"></i>Nový album</button>';
    if (state.view === 'album') right += '<button class="btn btn-sm btn-primary rounded-pill" data-pb="pick"><i class="bi bi-plus-lg me-1"></i>Pridať fotky</button>';
    if (state.view === 'album') right += '<div class="dropdown d-inline-block"><button class="btn btn-sm btn-light rounded-pill" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end shadow">'
      + '<li><a class="dropdown-item" href="#" data-pb="renamealbum"><i class="bi bi-input-cursor-text me-2"></i>Premenovať album</a></li>'
      + '<li><a class="dropdown-item text-danger" href="#" data-pb="delalbum"><i class="bi bi-trash3 me-2"></i>Zmazať album</a></li></ul></div>';
    bar.innerHTML = '<div class="pb-tabs">' + tab('photos', '#photos', 'bi-images', 'Fotky') + tab('albums', '#albums', 'bi-journals', 'Albumy') + tab('map', '#map', 'bi-geo-alt', 'Mapa') + '</div><div class="pb-right">' + right + '</div>';
  }
  $('#photoBar').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-pb]'); if (!b) return;
    e.preventDefault();
    const act = b.dataset.pb;
    if (act === 'slideshow') return startSlideshow();
    if (act === 'history') return activityDialog(null);
    if (act === 'pick') return pickPhotos(state.albumId);
    if (act === 'newalbum') { const n = await Cloud.prompt('Nový album', '', 'Vytvoriť', 'Názov albumu'); if (!n) return; const r = await api('api.php?action=album_save', { name: n }); if (!r.ok) return toast(r.error, 'danger'); go('#album/' + r.id); setTimeout(() => pickPhotos(r.id), 400); return; }
    if (act === 'renamealbum') { const n = await Cloud.prompt('Premenovať album', (state.crumbs[0] || {}).name || '', 'Uložiť'); if (!n) return; const r = await api('api.php?action=album_save', { id: state.albumId, name: n }); r.ok ? load(true) : toast(r.error, 'danger'); return; }
    if (act === 'delalbum') { if (!await Cloud.confirm('Zmazať album?', 'Fotky ostanú na disku – zmaže sa iba album.', 'Zmazať')) return; const r = await api('api.php?action=album_delete', { id: state.albumId }); r.ok ? (toast('Album zmazaný', 'success'), go('#albums')) : toast(r.error, 'danger'); }
  });

  // „V tento deň“
  let memories = null, memDay = '';
  function memoriesHtml() {
    const today = new Date().toDateString();
    if (memDay !== today) { memDay = today; memories = null; api('api.php?action=memories', {}).then(r => { memories = r.ok ? r.groups : []; if (state.view === 'photos' && memories.length) render(); }); }
    if (!memories || !memories.length) return '';
    return '<div class="memories"><div class="section-title mt-0"><i class="bi bi-clock-history me-1"></i>V tento deň</div><div class="mem-row">' + memories.map(g => {
      const c = g.items[0];
      return '<button class="mem-card" data-mem="' + g.year + '"><img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + c.id + '&v=' + encodeURIComponent(c.updated || '') + '" alt=""><span><b>Pred ' + g.ago + ' ' + (g.ago === 1 ? 'rokom' : 'rokmi') + '</b><small>' + g.year + ' · ' + g.items.length + ' ' + (g.items.length === 1 ? 'fotka' : g.items.length < 5 ? 'fotky' : 'fotiek') + '</small></span></button>';
    }).join('') + '</div></div>';
  }
  document.addEventListener('click', (e) => {
    const m = e.target.closest('[data-mem]'); if (!m || !memories) return;
    const g = memories.find(x => String(x.year) === m.dataset.mem); if (!g) return;
    pvOverride = g.items; preview(g.items[0]); startSlideshow(true);
  });

  // vlastné zobrazenia bez bežného zoznamu
  async function loadCustom() {
    const ticket = state.loadTicket = (state.loadTicket || 0) + 1;
    Object.assign(state, { items: [], crumbs: [], partial: false, lockHere: null, perm: 'owner' });
    state.sel.clear();
    render();
    const box = $('#items');
    $('#empty').classList.add('d-none');
    $('#listHeader').classList.add('d-none');
    $('#colsDrop').classList.add('d-none');
    box.className = 'items items-custom';
    box.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    if (state.view === 'albums') {
      const r = await api('api.php?action=albums', {});
      if (ticket !== state.loadTicket) return;
      if (!r.ok) return toast(r.error, 'danger');
      box.innerHTML = r.albums.length ? '<div class="album-grid">' + r.albums.map(a => '<a class="album-card" href="#album/' + a.id + '"><div class="album-cover">'
        + (a.cover ? '<img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + a.cover.id + '&v=' + encodeURIComponent(a.cover.v || '') + '" alt="">' : '<i class="bi bi-journals"></i>')
        + '</div><div class="album-name text-truncate">' + esc(a.name) + '</div><div class="small text-body-secondary">' + a.count + ' ' + (a.count === 1 ? 'položka' : a.count > 1 && a.count < 5 ? 'položky' : 'položiek') + '</div></a>').join('') + '</div>'
        : '<div class="text-center text-body-secondary py-5"><i class="bi bi-journals display-4 d-block mb-3"></i><h5>Zatiaľ žiadne albumy</h5><p>Vytvor album tlačidlom <b>Nový album</b> alebo vyber fotky a daj ⋮ → Pridať do albumu.</p></div>';
    } else if (state.view === 'map') {
      await renderMap(box, ticket);
    } else if (state.view === 'activity') {
      box.innerHTML = '';
      loadActivity(box, {}, 60, false);
    }
  }

  async function renderMap(box, ticket) {
    const L0 = window.CLOUD_LIB || {};
    const css = (href) => { if (!$$('link').some(l => l.href === href)) { const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = href; document.head.appendChild(l); } };
    try {
      css(L0.leafletCss); css(L0.clusterCss);
      await loadScript(L0.leafletJs); await loadScript(L0.clusterJs);
    } catch (e) { box.innerHTML = '<div class="alert alert-warning">Mapu sa nepodarilo načítať (potrebuje pripojenie na internet).</div>'; return; }
    const r = await api('api.php?action=photo_map', {});
    if (ticket !== state.loadTicket) return;
    if (!r.ok) return toast(r.error, 'danger');
    box.innerHTML = (r.pending ? '<div class="small text-body-secondary mb-2"><i class="bi bi-hourglass-split me-1"></i>Polohu zisťujem ešte z ' + r.pending + ' starších fotiek – mapa sa doplní, obnov ju o chvíľu.</div>' : '')
      + (r.points.length ? '' : '<div class="small text-body-secondary mb-2">Zatiaľ žiadne fotky s polohou. Poloha sa berie z fotiek s GPS (zapni ukladanie polohy v aplikácii Fotoaparát).</div>')
      + '<div id="photoMap" class="photo-map"></div>';
    const items = r.points.map(p => ({ type: 'f', id: p[0], name: p[3], taken: p[4], updated: p[5], mime: 'image/jpeg', thumb: true, size: 0, lat: p[1], lng: p[2] }));
    state.items = items;
    const map = L.map('photoMap', { worldCopyJump: true }).setView([48.7, 19.5], 7);
    // OpenStreetMap vyžaduje adresu stránky (Referer) – stránka ju inak neposiela; pri odmietnutí sa prepne na CARTO
    let tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, referrerPolicy: 'strict-origin-when-cross-origin', attribution: '&copy; OpenStreetMap' }).addTo(map);
    let tileErr = 0;
    tiles.on('tileerror', () => {
      if (++tileErr !== 3) return;
      map.removeLayer(tiles);
      tiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 19, subdomains: 'abcd', attribution: '&copy; OpenStreetMap &copy; CARTO' }).addTo(map);
    });
    const thumb = (it) => base + 'api.php?action=thumb&id=' + it.id + '&v=' + encodeURIComponent(it.updated || '');
    const cl = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50,
      iconCreateFunction: (c) => { const first = c.getAllChildMarkers()[0].options.item; return L.divIcon({ className: 'map-pin', html: '<img src="' + thumb(first) + '" alt=""><b>' + c.getChildCount() + '</b>', iconSize: [52, 52] }); } });
    items.forEach(it => {
      const m = L.marker([it.lat, it.lng], { item: it, icon: L.divIcon({ className: 'map-pin', html: '<img src="' + thumb(it) + '" alt="">', iconSize: [46, 46] }) });
      m.on('click', () => preview(it));
      cl.addLayer(m);
    });
    cl.on('clusterclick', (e) => { if (map.getZoom() >= 17) { const list = e.layer.getAllChildMarkers().map(m => m.options.item); pvOverride = list; preview(list[0]); } });
    map.addLayer(cl);
    if (items.length) map.fitBounds(cl.getBounds(), { padding: [30, 30], maxZoom: 14 });
    setTimeout(() => map.invalidateSize(), 200);
  }

  // albumy z kontextového menu
  async function albumAddDialog(items) {
    const r = await api('api.php?action=albums', {});
    if (!r.ok) return toast(r.error, 'danger');
    const id = 'aa' + Date.now();
    document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Pridať do albumu</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="list-group mb-3 album-pick">${r.albums.map(a => '<label class="list-group-item d-flex align-items-center gap-2"><input class="form-check-input m-0" type="radio" name="al" value="' + a.id + '"><span class="flex-grow-1 text-truncate">' + esc(a.name) + '</span><small class="text-body-secondary">' + a.count + '</small></label>').join('')}
        <label class="list-group-item d-flex align-items-center gap-2"><input class="form-check-input m-0" type="radio" name="al" value="new" ${r.albums.length ? '' : 'checked'}><input class="form-control form-control-sm" name="newname" placeholder="Nový album – názov"></label></div></div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4">Pridať</button></div></form></div></div>`);
    const el = document.getElementById(id), form = el.querySelector('form'), m = new bootstrap.Modal(el);
    form.newname.addEventListener('focus', () => { form.querySelector('[value="new"]').checked = true; });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      let al = (form.querySelector('[name="al"]:checked') || {}).value;
      if (!al) return toast('Vyber album.', 'warning');
      if (al === 'new') {
        const n = form.newname.value.trim(); if (!n) { form.newname.focus(); return; }
        const c = await api('api.php?action=album_save', { name: n }); if (!c.ok) return toast(c.error, 'danger'); al = c.id;
      }
      const x = await api('api.php?action=album_add', { album: al, items: keysOf(items) });
      if (!x.ok) return toast(x.error, 'danger');
      m.hide(); state.sel.clear(); render();
      toast('Pridané do albumu „' + x.name + '“', 'success');
    });
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
  }
  /** výber fotiek do albumu (ako v Google Fotkách) */
  async function pickPhotos(albumId) {
    const id = 'pp' + Date.now();
    document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title me-auto"><i class="bi bi-images me-2"></i>Pridať fotky do albumu</h5><span class="small text-body-secondary me-3" data-cnt>Nič nevybraté</span>
        <button class="btn btn-primary rounded-pill px-4 me-2" data-add disabled>Pridať</button><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="pick-grid" data-grid><div class="text-center py-5 w-100"><div class="spinner-border text-primary"></div></div></div></div></div></div></div>`);
    const el = document.getElementById(id), grid = el.querySelector('[data-grid]'), cnt = el.querySelector('[data-cnt]'), add = el.querySelector('[data-add]');
    const m = new bootstrap.Modal(el), sel = new Set();
    const r = await api('api.php?action=list', { view: 'photos', limit: 100000 });
    const inAlbum = new Set(state.view === 'album' && state.albumId === albumId ? state.items.map(i => i.id) : []);
    const photos = (r.items || []).filter(i => i.type === 'f' && !inAlbum.has(i.id));
    if (!photos.length) grid.innerHTML = '<div class="text-center text-body-secondary py-5 w-100">Žiadne ďalšie fotky na pridanie.</div>';
    else {
      let shown = 0;
      const more = () => {
        const chunk = photos.slice(shown, shown + 300); shown += chunk.length;
        grid.querySelector('.pick-more')?.remove();
        grid.insertAdjacentHTML('beforeend', chunk.map(p => '<button type="button" class="pick-item" data-id="' + p.id + '" title="' + esc(p.name) + '"><img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + p.id + '&v=' + encodeURIComponent(p.updated || '') + '" alt="" onerror="this.style.visibility=\'hidden\'">'
          + (/^video\//.test(p.mime || '') ? '<i class="bi bi-play-circle-fill pick-video"></i>' : '') + '<i class="bi bi-check-circle-fill pick-check"></i></button>').join(''));
        if (shown < photos.length) grid.insertAdjacentHTML('beforeend', '<div class="pick-more w-100 text-center py-3"><button type="button" class="btn btn-light rounded-pill">Načítať ďalšie</button></div>');
      };
      grid.innerHTML = ''; more();
      grid.addEventListener('click', (e) => {
        if (e.target.closest('.pick-more')) return more();
        const b = e.target.closest('.pick-item'); if (!b) return;
        const pid = +b.dataset.id;
        sel.has(pid) ? sel.delete(pid) : sel.add(pid);
        b.classList.toggle('on', sel.has(pid));
        cnt.textContent = sel.size ? 'Vybraté: ' + sel.size : 'Nič nevybraté';
        add.disabled = !sel.size;
      });
    }
    add.onclick = async () => {
      add.disabled = true;
      const x = await api('api.php?action=album_add', { album: albumId, items: [...sel].map(i => 'f:' + i) });
      if (!x.ok) { add.disabled = false; return toast(x.error, 'danger'); }
      m.hide();
      toast('Pridané do albumu „' + x.name + '“: ' + x.added, 'success');
      if (state.view === 'album') load(true);
    };
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
  }
  async function albumRemove(items) {
    const r = await api('api.php?action=album_remove', { album: state.albumId, items: keysOf(items) });
    r.ok ? (toast('Odstránené z albumu (súbory ostali na disku)', 'success'), load(true)) : toast(r.error, 'danger');
  }
  async function albumCover(it) {
    const r = await api('api.php?action=album_cover', { album: state.albumId, file: it.id });
    r.ok ? toast('Obal albumu nastavený', 'success') : toast(r.error, 'danger');
  }

  // prezentácia
  let slideT = null, slideOn = false, pvOverride = null;
  function startSlideshow(alreadyOpen) {
    const list = pvList().filter(i => /^(image|video)\//.test(i.mime || ''));
    if (!list.length) return toast('Žiadne fotky na prezentáciu', 'warning');
    slideOn = true; $('#previewPlay i').className = 'bi bi-pause-circle';
    if (!alreadyOpen) preview(list[0]);
    scheduleSlide();
  }
  function stopSlideshow() { slideOn = false; clearTimeout(slideT); $('#previewPlay i').className = 'bi bi-play-circle'; }
  function scheduleSlide() {
    clearTimeout(slideT);
    if (!slideOn) return;
    const vid = $('#previewBody video');
    if (vid) { vid.onended = () => slideOn && slideNext(); return; }
    slideT = setTimeout(slideNext, 4000);
  }
  function slideNext() {
    const list = pvList(), n = list[pvIdx + 1];
    if (!n) { stopSlideshow(); return; }
    preview(n).then(scheduleSlide);
  }
  $('#previewPlay').addEventListener('click', () => slideOn ? stopSlideshow() : startSlideshow(true));
  $('#previewModal').addEventListener('hidden.bs.modal', () => { stopSlideshow(); pvOverride = null; });
  ['#previewPrev', '#previewNext'].forEach(s => $(s).addEventListener('click', () => { if (slideOn) setTimeout(scheduleSlide, 50); }));

  /* ================= Aktivita ================= */
  /** text jednej udalosti; withName = ukázať aj názov položky (v prehľade celej zložky / disku) */
  function actHtml(a, withName) {
    const x = a.extra || {};
    const verb = esc(a.label.replace(/\(a\)/g, ''));
    const ic = a.type === 'd' ? '<i class="bi bi-folder-fill ic-folder"></i>' : '<i class="bi bi-file-earmark text-body-secondary"></i>';
    const href = a.type === 'd' && !['purge', 'trash'].includes(a.action) ? '#folder/' + a.item : a.action !== 'purge' ? (a.folder ? '#folder/' + a.folder : '#') : '';
    const name = withName ? (href ? '<a class="act-item" href="' + href + '">' + ic + '<span>' + esc(a.name) + '</span></a>' : '<span class="act-item">' + ic + '<span>' + esc(a.name) + '</span></span>') : '';
    const det = [];
    if (a.action === 'rename' && x.from) det.push('<span class="act-old">' + esc(x.from) + '</span> <i class="bi bi-arrow-right"></i> ' + esc(a.name));
    if (a.action === 'move') det.push('<i class="bi bi-folder2"></i> ' + esc(x.from_name || '') + ' <i class="bi bi-arrow-right"></i> <i class="bi bi-folder2-open"></i> ' + esc(x.to_name || ''));
    if ((a.action === 'share' || a.action === 'unshare') && x.to) det.push('<i class="bi bi-person"></i> ' + esc(x.to) + (x.perm === 'edit' ? ' · môže upravovať' : x.perm ? ' · iba čítanie' : ''));
    if (a.action === 'link' && x.mode) det.push({ view: 'na zobrazenie', upload: 'na nahrávanie', both: 'na zobrazenie aj nahrávanie' }[x.mode] || '');
    if (['upload', 'update', 'copy', 'edit'].includes(a.action) && x.size) det.push(formatBytes(x.size));
    if (a.action === 'rewind' && x.to) det.push('stav k ' + esc(fmtFull(x.to)));
    if (withName && a.folder_name && !['move', 'rewind'].includes(a.action)) det.push('<i class="bi bi-folder2"></i> ' + esc(a.folder_name));
    return '<div class="act-line"><b>' + esc(a.actor) + '</b> ' + verb + '</div>' + name + (det.length ? '<div class="act-det">' + det.filter(Boolean).join(' · ') + '</div>' : '');
  }
  async function loadActivity(box, scope, limit, compact, before) {
    const r = await api('api.php?action=activity', Object.assign({ before: before || 0 }, scope));
    if (!r.ok) { box.innerHTML = '<div class="small text-danger">' + esc(r.error) + '</div>'; return; }
    box.querySelector('.act-more')?.remove();
    const withName = !scope.type || scope.type === 'd';
    if (!before) {
      delete box.dataset.lastDay;
      box.innerHTML = compact ? '<div class="act-title"><i class="bi bi-clock-history me-1"></i>História zmien</div>' : '';
      box.classList.add('act-timeline');
    }
    if (!r.items.length && !before) { box.insertAdjacentHTML('beforeend', '<div class="act-empty"><i class="bi bi-clock-history"></i><div>Zatiaľ žiadne zaznamenané zmeny.</div></div>'); return; }
    let day = box.dataset.lastDay || '', h = '';
    const today = new Date().toDateString(), yest = new Date(Date.now() - 86400000).toDateString();
    r.items.slice(0, compact ? limit : 999).forEach(a => {
      const d = new Date(a.date.replace(' ', 'T')), dk = d.toDateString();
      if (dk !== day) {
        day = dk;
        h += '<div class="act-daypill">' + (dk === today ? 'Dnes' : dk === yest ? 'Včera' : esc(d.toLocaleDateString('sk-SK', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' }))) + '</div>';
      }
      h += '<div class="act-ev"><span class="act-dot bg-' + a.color + '-subtle text-' + a.color + '-emphasis"><i class="bi ' + a.icon + '"></i></span><div class="act-body">' + actHtml(a, withName)
        + '</div><time class="act-time" title="' + esc(fmtFull(a.date)) + '">' + esc(d.toLocaleTimeString('sk-SK', { hour: '2-digit', minute: '2-digit' })) + '</time></div>';
    });
    box.dataset.lastDay = day;
    box.insertAdjacentHTML('beforeend', h);
    if (compact && r.items.length > limit) box.insertAdjacentHTML('beforeend', '<div class="act-more text-center mt-2"><button class="btn btn-sm btn-light rounded-pill" data-act-all>Celá história</button></div>');
    else if (r.more && !compact) {
      box.insertAdjacentHTML('beforeend', '<div class="text-center my-3 act-more"><button class="btn btn-light rounded-pill">Načítať staršie</button></div>');
      box.querySelector('.act-more button').onclick = () => loadActivity(box, scope, limit, compact, r.more);
    }
    const all = box.querySelector('[data-act-all]');
    if (all) all.onclick = () => { bootstrap.Modal.getInstance(box.closest('.modal'))?.hide(); activityDialog(byKey(scope.type + ':' + scope.id) || { type: scope.type, id: scope.id, name: '' }); };
    $$('a[href^="#"]', box).forEach(a => a.addEventListener('click', () => { bootstrap.Modal.getInstance(a.closest('.modal'))?.hide(); }));
  }
  function activityDialog(it) {
    const id = 'ad' + Date.now();
    const icon = !it ? '<i class="bi bi-hdd-stack"></i>' : it.type === 'd' ? '<i class="bi bi-folder-fill ic-folder"></i>'
      : it.thumb ? '<img src="' + base + 'api.php?action=thumb&id=' + it.id + '&v=' + encodeURIComponent(it.updated || '') + '" alt="">' : '<i class="bi bi-file-earmark-text text-body-secondary"></i>';
    document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg"><div class="modal-content act-modal">
      <div class="modal-header border-0 pb-2"><div class="act-head"><span class="act-head-ic">${icon}</span><div class="min-w-0"><div class="act-head-title text-truncate" data-n></div><div class="small text-body-secondary" data-s></div></div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body pt-0"><div data-box></div></div></div></div></div>`);
    const el = document.getElementById(id);
    el.querySelector('[data-n]').textContent = it ? it.name : 'Celý disk';
    el.querySelector('[data-s]').textContent = !it ? 'Všetky zmeny – kto čo nahral, upravil, presunul, zmazal alebo zdieľal' : it.type === 'd' ? 'História zmien zložky a jej obsahu' : 'História zmien súboru';
    loadActivity(el.querySelector('[data-box]'), it ? { type: it.type, id: it.id } : {}, 60, false);
    el.addEventListener('hidden.bs.modal', () => el.remove());
    new bootstrap.Modal(el).show();
  }

  // vrátenie zložky do stavu k dátumu
  function rewindDialog(it) {
    const id = 'rw' + Date.now();
    const pad = (n) => String(n).padStart(2, '0'), d = new Date(Date.now() - 86400000);
    const def = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content">
      <div class="modal-header border-0 pb-0"><h5 class="modal-title"><i class="bi bi-rewind me-2"></i>Vrátiť zložku do stavu k…</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="fw-semibold text-break mb-2" data-n></div>
        <p class="small text-body-secondary">Obnoví z koša, čo sa po tomto čase zmazalo, vráti staršie verzie zmenených súborov a vráti premenovania a presuny. Natrvalo zmazané položky sa obnoviť nedajú.</p>
        <label class="form-label small mb-1">Dátum a čas</label><input type="datetime-local" class="form-control mb-2" name="at" value="${def}" required>
        <div class="d-flex flex-wrap gap-1 mb-3">${[['1 h', 3600], ['dnes ráno', 'm'], ['včera', 86400], ['týždeň', 604800]].map(([l, v]) => '<button type="button" class="btn btn-sm btn-light rounded-pill" data-q="' + v + '">' + l + '</button>').join('')}</div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="trash_new" id="${id}t"><label class="form-check-label" for="${id}t">Presunúť do koša aj súbory a zložky vytvorené po tomto čase</label></div>
        <div class="rw-res small mt-3" data-res></div></div>
      <div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button type="button" class="btn btn-outline-primary rounded-pill" data-prev>Ukázať zmeny</button><button class="btn btn-warning rounded-pill px-4" disabled data-go>Vrátiť</button></div></form></div></div>`);
    const el = document.getElementById(id), form = el.querySelector('form'), res = el.querySelector('[data-res]'), goBtn = el.querySelector('[data-go]');
    el.querySelector('[data-n]').textContent = it.name;
    const m = new bootstrap.Modal(el);
    const params = (apply) => ({ id: it.id, at: form.at.value.replace('T', ' ') + ':00', trash_new: form.trash_new.checked ? 1 : 0, apply: apply ? 1 : 0 });
    const lbl = { restore: 'obnoviť z koša', content: 'vrátiť obsah', rename: 'vrátiť názov', move: 'vrátiť na pôvodné miesto', trash: 'do koša' };
    const previewRun = async () => {
      res.innerHTML = '<div class="spinner-border spinner-border-sm text-primary"></div>';
      const r = await api('api.php?action=folder_rewind', params(false));
      if (!r.ok) { res.innerHTML = '<div class="text-danger">' + esc(r.error) + '</div>'; goBtn.disabled = true; return; }
      const x = r.result, total = x.restore + x.content + x.renames + x.trash;
      res.innerHTML = total ? '<div class="fw-semibold mb-1">Zmení sa ' + total + ' ' + (total === 1 ? 'položka' : total < 5 ? 'položky' : 'položiek') + ':</div><ul class="mb-1 ps-3">'
        + (x.restore ? '<li>obnoviť z koša: <b>' + x.restore + '</b></li>' : '') + (x.content ? '<li>vrátiť staršiu verziu: <b>' + x.content + '</b></li>' : '')
        + (x.renames ? '<li>vrátiť premenovanie / presun: <b>' + x.renames + '</b></li>' : '') + (x.trash ? '<li>presunúť do koša (nové): <b>' + x.trash + '</b></li>' : '') + '</ul>'
        + '<div class="rw-list">' + x.items.map(([w, n]) => '<div><span class="badge text-bg-light me-1">' + esc(lbl[w] || w) + '</span>' + esc(n) + '</div>').join('') + '</div>'
        + (x.missing ? '<div class="text-body-secondary mt-1">' + x.missing + ' súborov nemá z toho času zachovanú verziu – ostanú ako sú.</div>' : '')
        : '<div class="text-body-secondary">Od tohto času sa v zložke nič, čo sa dá vrátiť, nezmenilo.</div>';
      goBtn.disabled = !total;
    };
    el.querySelectorAll('[data-q]').forEach(b => b.onclick = () => {
      const now = new Date(); let t;
      if (b.dataset.q === 'm') { t = new Date(now); t.setHours(6, 0, 0, 0); } else t = new Date(now - b.dataset.q * 1000);
      form.at.value = t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()) + 'T' + pad(t.getHours()) + ':' + pad(t.getMinutes());
      previewRun();
    });
    form.at.addEventListener('change', previewRun); form.trash_new.addEventListener('change', previewRun);
    el.querySelector('[data-prev]').onclick = previewRun;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      goBtn.disabled = true;
      const r = await api('api.php?action=folder_rewind', params(true));
      if (!r.ok) { goBtn.disabled = false; return toast(r.error, 'danger'); }
      m.hide();
      toast('Zložka je vrátená do stavu k ' + fmtFull(params(true).at), 'success');
      for (const k of [...lcMem.keys()]) lcMem.delete(k);
      load(true);
    });
    el.addEventListener('shown.bs.modal', previewRun);
    el.addEventListener('hidden.bs.modal', () => el.remove());
    m.show();
  }

  /* ---------------- vyhľadávanie ako v Google Disku ---------------- */
  const SF = {
    owner: { '': 'Ktokoľvek', me: 'Vlastním ja', others: 'Nevlastním ja' },
    where: { '': 'Kdekoľvek', drive: 'Môj disk', shared: 'Zdieľané so mnou', starred: 'S hviezdičkou', trash: 'Kôš' },
    modified: { '': 'Kedykoľvek', today: 'Dnes', '7d': 'Posledných 7 dní', '30d': 'Posledných 30 dní', '90d': 'Posledné 3 mesiace', year: 'Tento rok', lastyear: 'Minulý rok', custom: 'Vlastný rozsah…' },
    size: { '': 'Ľubovoľná', lt1: 'Menej ako 1 MB', '1-10': '1 – 10 MB', '10-100': '10 – 100 MB', '100-1g': '100 MB – 1 GB', gt1g: 'Viac ako 1 GB' },
  };
  const MB = 1048576;
  const SIZE_RANGE = { lt1: [null, MB], '1-10': [MB, 10 * MB], '10-100': [10 * MB, 100 * MB], '100-1g': [100 * MB, 1024 * MB], gt1g: [1024 * MB, null] };
  let smeta = null;
  async function searchMeta() {
    if (!smeta) { const r = await api('api.php?action=search_meta', {}); smeta = r.ok ? r : { types: [], tags: [], content: false }; }
    return smeta;
  }
  function searchParams(sf) {
    const p = {};
    ['type', 'owner', 'modified', 'tag'].forEach(k => { if (sf[k]) p[k] = sf[k]; });
    if (sf.where) p.where = sf.where === 'here' ? (sf.wid || '') : sf.where;
    if (sf.modified === 'custom') { p.after = sf.after || ''; p.before = sf.before || ''; }
    const sr = SIZE_RANGE[sf.size];
    if (sr) { if (sr[0] != null) p.size_min = sr[0]; if (sr[1] != null) p.size_max = sr[1]; }
    if (sf.content === '0') p.content = '0';
    return p;
  }
  function goSearch(q, sf) {
    q = (q || '').trim();
    const clean = {};
    Object.entries(sf || {}).forEach(([k, v]) => { if (v !== '' && v != null) clean[k] = v; });
    if (clean.modified !== 'custom') { delete clean.after; delete clean.before; }
    if (clean.where !== 'here') { delete clean.wid; delete clean.wn; }
    const qs = new URLSearchParams(clean).toString();
    if (!q && !qs) { go('#'); return; }
    if (q) rememberSearch(q);
    closeSearchPop(); closeAdv();
    go('#search/' + encodeURIComponent(q) + (qs ? '?' + qs : ''));
  }
  const RS_KEY = 'cloud-recent-search';
  const recentSearches = () => { try { return JSON.parse(localStorage.getItem(RS_KEY) || '[]'); } catch (e) { return []; } };
  function rememberSearch(q) {
    const l = recentSearches().filter(x => x !== q); l.unshift(q);
    try { localStorage.setItem(RS_KEY, JSON.stringify(l.slice(0, 6))); } catch (e) {}
  }
  const typeLabel = (id) => ((smeta && smeta.types.find(t => t.id === id)) || {}).label || id;
  const typeIcon = (id) => ((smeta && smeta.types.find(t => t.id === id)) || {}).icon || 'bi-file-earmark';

  // návrhy pod políčkom
  const sInput = $('#searchInput'), sPop = $('#searchPop'), sAdv = $('#searchAdv'), sClear = $('#searchClear');
  let sugT, sugTicket = 0, liveT;
  function closeSearchPop() { sPop.classList.add('d-none'); sPop.innerHTML = ''; }
  function closeAdv() { sAdv.classList.add('d-none'); }
  function popRows() { return [...sPop.querySelectorAll('.sp-row')]; }
  async function showSuggest() {
    const q = sInput.value.trim();
    sClear.classList.toggle('d-none', !q);
    const meta = await searchMeta();
    const t = ++sugTicket;
    let h = '';
    if (!q) {
      h += '<div class="sp-chips">' + meta.types.filter(x => ['doc', 'sheet', 'slides', 'pdf', 'image', 'video', 'folder', 'archive'].includes(x.id))
        .map(x => '<button type="button" class="sp-chip" data-sp-type="' + x.id + '"><i class="bi ' + x.icon + '"></i>' + esc(x.label) + '</button>').join('') + '</div>';
      const rs = recentSearches();
      if (rs.length) h += '<div class="sp-head">Nedávne vyhľadávania</div>' + rs.map(r => '<div class="sp-row" data-sp-q="' + esc(r) + '"><i class="bi bi-clock-history"></i><span class="flex-grow-1 text-truncate">' + esc(r) + '</span><button type="button" class="btn btn-icon btn-sm" data-sp-forget="' + esc(r) + '" title="Odstrániť"><i class="bi bi-x"></i></button></div>').join('');
    } else {
      h += '<div class="sp-row" data-sp-q="' + esc(q) + '"><i class="bi bi-search"></i><span class="flex-grow-1 text-truncate">Hľadať „<b>' + esc(q) + '</b>“</span><span class="small text-body-secondary d-none d-sm-inline">Enter</span></div>';
      const r = await api('api.php?action=suggest', { q });
      if (t !== sugTicket || sInput.value.trim() !== q) return;
      if (r.ok) {
        (r.tags || []).forEach(tg => { h += '<div class="sp-row" data-sp-tag="' + tg.id + '"><i class="bi bi-tag-fill" style="color:' + (safeColor(tg.color) || '#5f6368') + '"></i><span class="flex-grow-1 text-truncate">Štítok: ' + esc(tg.name) + '</span></div>'; });
        state.sugItems = r.items || [];
        state.sugItems.forEach((it, i) => {
          const sub = it.owner ? it.owner : (it.path || (it.type === 'd' ? 'Zložka' : ''));
          h += '<div class="sp-row" data-sp-item="' + i + '"><span class="sp-ic">' + (it.type === 'd' ? '<i class="bi bi-folder-fill" style="color:#5f6368"></i>' : '<i class="bi ' + spIcon(it) + '"></i>') + '</span><span class="flex-grow-1 min-w-0"><span class="d-block text-truncate">' + hlq(it.name, q) + '</span>'
            + (sub ? '<span class="d-block small text-body-secondary text-truncate">' + esc(sub) + '</span>' : '') + '</span><span class="small text-body-secondary d-none d-sm-inline">' + esc(fmtDate(it.updated)) + '</span></div>';
        });
      }
    }
    h += '<div class="sp-foot"><button type="button" class="btn btn-link btn-sm text-decoration-none" data-sp-adv><i class="bi bi-sliders2 me-1"></i>Rozšírené vyhľadávanie</button>'
      + '<span class="small text-body-secondary d-none d-md-inline">Tip: type:pdf, owner:me, after:2024-01-01, size>10mb</span></div>';
    sPop.innerHTML = h;
    sPop.classList.remove('d-none');
  }
  function hlq(name, q) {
    const i = name.toLowerCase().indexOf(q.toLowerCase());
    return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<b>' + esc(name.slice(i, i + q.length)) + '</b>' + esc(name.slice(i + q.length));
  }
  function spIcon(it) { const [ic, cls] = fileIcon(it); return ic + ' ' + cls; }
  sInput.addEventListener('focus', () => { if (sAdv.classList.contains('d-none')) showSuggest(); });
  sInput.addEventListener('input', () => {
    clearTimeout(sugT); sugT = setTimeout(showSuggest, 180);
    // vo výsledkoch hľadania sa zoznam spresňuje priebežne
    clearTimeout(liveT);
    if (state.view === 'search') liveT = setTimeout(() => { const v = sInput.value.trim(); if (v !== state.q) { const sf = state.sf; go('#search/' + encodeURIComponent(v) + (Object.keys(sf || {}).length ? '?' + new URLSearchParams(sf) : '')); } }, 700);
  });
  sInput.addEventListener('keydown', (e) => {
    const rows = popRows(), cur = sPop.querySelector('.sp-row.active'), idx = rows.indexOf(cur);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      if (!rows.length) return;
      e.preventDefault();
      const n = rows[(idx + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length];
      rows.forEach(r => r.classList.toggle('active', r === n)); n.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault(); clearTimeout(liveT);
      if (cur) cur.click(); else goSearch(sInput.value, state.view === 'search' ? state.sf : {});
      sInput.blur();
    } else if (e.key === 'Escape') { closeSearchPop(); closeAdv(); sInput.blur(); }
  });
  $('#searchForm').addEventListener('submit', (e) => { e.preventDefault(); goSearch(sInput.value, state.view === 'search' ? state.sf : {}); });
  sClear.addEventListener('click', () => { sInput.value = ''; sClear.classList.add('d-none'); if (state.view === 'search') go('#'); else sInput.focus(); });
  sPop.addEventListener('mousedown', (e) => e.preventDefault()); // nech políčko nestratí fokus
  sPop.addEventListener('click', (e) => {
    const f = e.target.closest('[data-sp-forget]');
    if (f) { e.stopPropagation(); try { localStorage.setItem(RS_KEY, JSON.stringify(recentSearches().filter(x => x !== f.dataset.spForget))); } catch (er) {} showSuggest(); return; }
    const el = e.target.closest('[data-sp-type],[data-sp-q],[data-sp-tag],[data-sp-item],[data-sp-adv]');
    if (!el) return;
    if (el.dataset.spType) { goSearch(sInput.value, { type: el.dataset.spType }); return; }
    if (el.dataset.spQ !== undefined) { sInput.value = el.dataset.spQ; goSearch(el.dataset.spQ, state.view === 'search' ? state.sf : {}); return; }
    if (el.dataset.spTag) { closeSearchPop(); sInput.blur(); go('#tag/' + el.dataset.spTag); return; }
    if (el.dataset.spAdv !== undefined) { closeSearchPop(); openAdv(); return; }
    const it = state.sugItems[+el.dataset.spItem];
    if (!it) return;
    if (sInput.value.trim()) rememberSearch(sInput.value.trim());
    closeSearchPop(); sInput.blur();
    if (it.type === 'd') go('#folder/' + it.id); else preview(it);
  });
  document.addEventListener('mousedown', (e) => { if (!e.target.closest('#searchForm')) { closeSearchPop(); if (!e.target.closest('.select2-container, .datepicker')) closeAdv(); } });

  // rozšírené vyhľadávanie (panel)
  const opts = (map, val) => Object.entries(map).map(([k, v]) => '<option value="' + esc(k) + '"' + (k === (val || '') ? ' selected' : '') + '>' + esc(v) + '</option>').join('');
  async function openAdv() {
    const meta = await searchMeta();
    const sf = state.view === 'search' ? (state.sf || {}) : {};
    const here = state.view === 'folder' && state.folder ? { id: state.folder, name: (state.crumbs.at(-1) || {}).name } : (sf.where === 'here' ? { id: sf.wid, name: sf.wn } : null);
    const whereMap = Object.assign({}, SF.where); if (here) whereMap.here = 'V zložke „' + (here.name || '…') + '“';
    const typeMap = { '': 'Akýkoľvek' }; meta.types.forEach(t => typeMap[t.id] = t.label);
    const tagMap = { '': 'Akýkoľvek' }; meta.tags.forEach(t => tagMap[t.id] = t.name);
    const row = (label, ctl) => '<div class="adv-row"><label>' + label + '</label><div class="flex-grow-1 min-w-0">' + ctl + '</div></div>';
    sAdv.innerHTML = '<div class="d-flex align-items-center mb-2"><span class="fw-semibold me-auto">Rozšírené vyhľadávanie</span><button type="button" class="btn btn-icon btn-sm" data-adv-close aria-label="Zavrieť"><i class="bi bi-x-lg"></i></button></div>'
      + row('Typ', '<select class="form-select form-select-sm" name="type">' + opts(typeMap, sf.type) + '</select>')
      + row('Vlastník', '<select class="form-select form-select-sm" name="owner">' + opts(SF.owner, sf.owner) + '</select>')
      + row('Obsahuje slová', '<input class="form-control form-control-sm" name="q" placeholder="Zadaj výraz, ktorý sa nachádza v názve' + (meta.content ? ' alebo v obsahu' : '') + '" value="' + esc(sInput.value) + '">')
      + row('Umiestnenie', '<select class="form-select form-select-sm" name="where">' + opts(whereMap, sf.where) + '</select>')
      + row('Dátum zmeny', '<select class="form-select form-select-sm" name="modified">' + opts(SF.modified, sf.modified) + '</select>'
          + '<div class="d-flex gap-2 mt-2 adv-dates' + (sf.modified === 'custom' ? '' : ' d-none') + '"><input type="date" class="form-control form-control-sm" name="after" value="' + esc(sf.after || '') + '" aria-label="Od"><input type="date" class="form-control form-control-sm" name="before" value="' + esc(sf.before || '') + '" aria-label="Do"></div>')
      + row('Veľkosť', '<select class="form-select form-select-sm" name="size">' + opts(SF.size, sf.size) + '</select>')
      + (meta.tags.length ? row('Štítok', '<select class="form-select form-select-sm" name="tag">' + opts(tagMap, sf.tag) + '</select>') : '')
      + (meta.content ? '<div class="form-check form-switch my-2"><input class="form-check-input" type="checkbox" id="advContent" name="content"' + (sf.content === '0' ? '' : ' checked') + '><label class="form-check-label small" for="advContent">Hľadať aj v obsahu dokumentov</label></div>' : '')
      + '<div class="d-flex gap-2 justify-content-end mt-3"><button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-adv-reset>Obnoviť</button><button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-adv-go>Hľadať</button></div>';
    sAdv.dataset.wid = here ? here.id : ''; sAdv.dataset.wn = here ? (here.name || '') : '';
    sAdv.classList.remove('d-none');
    closeSearchPop();
  }
  sAdv.addEventListener('change', (e) => { if (e.target.name === 'modified') sAdv.querySelector('.adv-dates').classList.toggle('d-none', e.target.value !== 'custom'); });
  sAdv.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); sAdv.querySelector('[data-adv-go]').click(); } if (e.key === 'Escape') closeAdv(); });
  sAdv.addEventListener('click', (e) => {
    if (e.target.closest('[data-adv-close]')) { closeAdv(); return; }
    if (e.target.closest('[data-adv-reset]')) { sAdv.querySelectorAll('select').forEach(s => s.value = ''); sAdv.querySelectorAll('input:not([type=checkbox])').forEach(i => i.value = ''); sAdv.querySelector('.adv-dates').classList.add('d-none'); const c = sAdv.querySelector('[name=content]'); if (c) c.checked = true; return; }
    if (!e.target.closest('[data-adv-go]')) return;
    const v = (n) => (sAdv.querySelector('[name="' + n + '"]') || {}).value || '';
    const sf = { type: v('type'), owner: v('owner'), where: v('where'), modified: v('modified'), after: v('after'), before: v('before'), size: v('size'), tag: v('tag') };
    if (sf.where === 'here') { sf.wid = sAdv.dataset.wid; sf.wn = sAdv.dataset.wn; }
    const c = sAdv.querySelector('[name=content]'); if (c && !c.checked) sf.content = '0';
    sInput.value = v('q');
    goSearch(v('q'), sf);
  });
  $('#searchFilterBtn').addEventListener('click', () => { sAdv.classList.contains('d-none') ? openAdv() : closeAdv(); });

  // čipy filtrov nad výsledkami
  async function renderSearchChips() {
    const box = $('#searchChips');
    if (!box) return;
    if (state.view !== 'search') { box.classList.add('d-none'); box.innerHTML = ''; return; }
    const meta = await searchMeta();
    const sf = state.sf || {};
    const typeMap = { '': 'Typ' }; meta.types.forEach(t => typeMap[t.id] = t.label);
    const whereMap = Object.assign({}, SF.where, { '': 'Umiestnenie' }); if (sf.where === 'here') whereMap.here = sf.wn || 'Zložka';
    const tagMap = { '': 'Štítok' }; meta.tags.forEach(t => tagMap[t.id] = t.name);
    const chip = (key, map, label, icons) => {
      const val = sf[key] || '', on = !!val;
      const cap = on ? (key === 'modified' && val === 'custom' ? [sf.after, sf.before].filter(Boolean).map(d => fmtDate(d)).join(' – ') || 'Vlastný rozsah' : map[val]) : label;
      return '<div class="dropdown d-inline-block"><button type="button" class="s-chip' + (on ? ' on' : '') + '" data-bs-toggle="dropdown">'
        + (on && icons ? '<i class="bi ' + typeIcon(val) + '"></i>' : '') + '<span>' + esc(cap) + '</span>'
        + (on ? '<i class="bi bi-x-lg s-chip-x" data-chip-clear="' + key + '" title="Zrušiť filter"></i>' : '<i class="bi bi-caret-down-fill small"></i>') + '</button>'
        + '<ul class="dropdown-menu shadow">' + Object.entries(map).filter(([k]) => k !== '' && k !== 'custom').map(([k, v]) => '<li><a class="dropdown-item' + (k === val ? ' active' : '') + '" href="#" data-chip="' + key + '" data-val="' + esc(k) + '">'
          + (icons ? '<i class="bi ' + typeIcon(k) + ' me-2"></i>' : '') + esc(v) + '</a></li>').join('')
        + (key === 'modified' ? '<li><a class="dropdown-item" href="#" data-chip-custom>Vlastný rozsah…</a></li>' : '') + '</ul></div>';
    };
    const any = ['type', 'owner', 'modified', 'where', 'size', 'tag'].some(k => sf[k]) || sf.content === '0';
    box.innerHTML = chip('type', typeMap, 'Typ', true) + chip('owner', Object.assign({}, SF.owner, { '': 'Vlastník' }), 'Vlastník')
      + chip('modified', Object.assign({}, SF.modified, { '': 'Dátum zmeny' }), 'Dátum zmeny') + chip('where', whereMap, 'Umiestnenie')
      + chip('size', Object.assign({}, SF.size, { '': 'Veľkosť' }), 'Veľkosť') + (meta.tags.length ? chip('tag', tagMap, 'Štítok') : '')
      + (meta.content && state.q ? '<button type="button" class="s-chip' + (sf.content === '0' ? '' : ' on') + '" data-chip-content title="Hľadať aj v obsahu dokumentov"><i class="bi bi-text-paragraph"></i><span>V obsahu</span></button>' : '')
      + (any ? '<button type="button" class="btn btn-link btn-sm text-decoration-none" data-chip-reset>Vymazať filtre</button>' : '')
      + '<span class="s-count small text-body-secondary ms-auto">' + (state.items.length >= 300 ? '300+' : state.items.length) + ' ' + (state.items.length === 1 ? 'výsledok' : state.items.length > 1 && state.items.length < 5 ? 'výsledky' : 'výsledkov') + '</span>';
    box.classList.remove('d-none');
  }
  $('#searchChips') && $('#searchChips').addEventListener('click', (e) => {
    const sf = Object.assign({}, state.sf || {});
    const x = e.target.closest('[data-chip-clear]');
    if (x) { e.preventDefault(); e.stopPropagation(); const k = x.dataset.chipClear; delete sf[k]; if (k === 'modified') { delete sf.after; delete sf.before; } goSearch(state.q, sf); return; }
    const a = e.target.closest('[data-chip]');
    if (a) { e.preventDefault(); sf[a.dataset.chip] = a.dataset.val; if (a.dataset.chip === 'where' && a.dataset.val !== 'here') { delete sf.wid; delete sf.wn; } goSearch(state.q, sf); return; }
    if (e.target.closest('[data-chip-custom]')) { e.preventDefault(); openAdv().then(() => { const m = sAdv.querySelector('[name=modified]'); m.value = 'custom'; sAdv.querySelector('.adv-dates').classList.remove('d-none'); }); return; }
    if (e.target.closest('[data-chip-content]')) { if (sf.content === '0') delete sf.content; else sf.content = '0'; goSearch(state.q, sf); return; }
    if (e.target.closest('[data-chip-reset]')) goSearch(state.q, {});
  });
  // klávesová skratka „/“ ako v Google Disku
  document.addEventListener('keydown', (e) => {
    if (e.key === '/' && !e.ctrlKey && !e.metaKey && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName) && !document.querySelector('.modal.show')) { e.preventDefault(); sInput.focus(); }
  });

  /* ---------------- nahrávanie po častiach (s obnovením po výpadku) ---------------- */
  const queue = []; let active = 0, refreshTimer = null, done = 0, total = 0;
  function enqueue(files) {
    const explicit = files.every(f => f.folder !== undefined);
    if (!canEditHere() && !explicit) { toast('Sem nemôžeš nahrávať (iba na čítanie).', 'warning'); return; }
    const folder = curFolder();
    if (['trash', 'shared', 'byme', 'dupes', 'search', 'starred'].includes(state.view) && !explicit) go('#');
    files.forEach((job) => {
      job.folder = job.folder !== undefined ? job.folder : folder;
      job.name = job.name || job.file.name;
      const el = document.createElement('div');
      el.className = 'up-item';
      const [ic, cls] = fileIcon({ type: 'f', name: job.name, mime: job.file.type });
      el.innerHTML = '<i class="bi ' + ic + ' ' + cls + '"></i><div class="flex-grow-1 min-w-0"><div class="text-truncate small fw-semibold"></div><div class="progress mt-1" style="height:3px"><div class="progress-bar" style="width:0"></div></div><div class="up-err small"></div></div>'
        + '<span class="up-status"><div class="spinner-border spinner-border-sm text-secondary"></div></span><button class="btn btn-icon btn-sm up-cancel" title="Zrušiť"><i class="bi bi-x"></i></button>';
      el.querySelector('.fw-semibold').textContent = job.rel || job.name;
      el.querySelector('.up-cancel').onclick = () => cancelJob(job);
      $('#uploadList').prepend(el);
      job.el = el;
      queue.push(job);
      total++;
    });
    $('#uploadPanel').classList.remove('d-none', 'collapsed');
    updateUpTitle(); pump();
  }
  function updateUpTitle() { $('#uploadTitle').textContent = done < total ? 'Nahrávam ' + (done + 1) + ' z ' + total + '…' : 'Hotovo: ' + total + (total === 1 ? ' položka' : ' položiek'); }
  function pump() {
    const limit = queue.some(q => q.rel && q.rel.includes('/')) ? 1 : 3;
    while (active < limit && queue.length) runJob(queue.shift());
  }
  function cancelJob(job) {
    job.cancelled = true;
    if (job.xhr) job.xhr.abort();
    const qi = queue.indexOf(job); if (qi >= 0) { queue.splice(qi, 1); finish(job, false, 'Zrušené'); }
    if (job.id) { api('api.php?action=upload_cancel', { id: job.id }); localStorage.removeItem(job.lsKey); }
  }
  function finish(job, ok, msg) {
    const st = job.el.querySelector('.up-status'), bar = job.el.querySelector('.progress-bar');
    job.el.querySelector('.up-cancel')?.remove();
    if (ok) { st.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i>'; bar.parentElement.remove(); if (msg) job.el.querySelector('.up-err').innerHTML = '<span class="text-body-secondary">' + esc(msg) + '</span>'; }
    else { st.innerHTML = '<i class="bi bi-exclamation-circle-fill text-danger"></i>'; bar.classList.add('bg-danger'); job.el.querySelector('.up-err').innerHTML = '<span class="text-danger">' + esc(msg) + '</span>'; }
    done++; updateUpTitle();
  }
  const sleep = (ms) => new Promise(r => setTimeout(r, ms));
  const waitOnline = () => navigator.onLine ? Promise.resolve() : new Promise(r => addEventListener('online', r, { once: true }));
  function sendChunk(job, offset, blob) {
    return new Promise((resolve) => {
      const fd = new FormData();
      fd.append('id', job.id); fd.append('offset', offset); fd.append('chunk', blob, 'chunk');
      const xhr = job.xhr = new XMLHttpRequest();
      xhr.open('POST', base + 'api.php?action=upload_chunk');
      xhr.setRequestHeader('X-CSRF-Token', Cloud.csrf); xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.onprogress = (e) => { if (e.lengthComputable && job.file.size) job.el.querySelector('.progress-bar').style.width = ((offset + e.loaded) / job.file.size * 100) + '%'; };
      xhr.onload = () => { try { resolve(JSON.parse(xhr.responseText)); } catch (e) { resolve({ ok: false, net: xhr.status >= 500 || xhr.status === 0, error: xhr.status === 413 ? 'Server odmietol časť súboru (príliš veľká).' : 'Chyba servera (' + xhr.status + ')' }); } };
      xhr.onerror = () => resolve({ ok: false, net: true, error: 'Výpadok spojenia' });
      xhr.onabort = () => resolve({ ok: false, aborted: true, error: 'Zrušené' });
      xhr.send(fd);
    });
  }
  async function runJob(job) {
    active++;
    const f = job.file;
    job.lsKey = 'cloud-up:' + [job.folder, job.rel || job.name, f.size, f.lastModified].join('|');
    try {
      const init = await api('api.php?action=upload_init', { folder: job.folder, name: job.name, size: f.size, relpath: job.rel || '', resume: localStorage.getItem(job.lsKey) || '' });
      if (!init.ok) throw new Error(init.error);
      job.id = init.id; localStorage.setItem(job.lsKey, init.id);
      const chunk = init.chunk || 2097152;
      let off = init.received, tries = 0, res = null;
      if (off > 0) job.el.querySelector('.up-err').innerHTML = '<span class="text-body-secondary">Pokračujem od ' + formatBytes(off) + '</span>';
      while (true) {
        if (job.cancelled) throw new Error('Zrušené');
        res = await sendChunk(job, off, f.slice(off, off + chunk));
        if (res.aborted || job.cancelled) throw new Error('Zrušené');
        if (!res.ok) {
          if (res.net && tries < 8) { // výpadok – počkaj a skús znova
            tries++;
            job.el.querySelector('.up-err').innerHTML = '<span class="text-warning">Výpadok spojenia, skúšam znova (' + tries + ')…</span>';
            await waitOnline(); await sleep(Math.min(30000, 1000 * 2 ** (tries - 1)));
            continue;
          }
          throw new Error(res.error);
        }
        tries = 0; job.el.querySelector('.up-err').textContent = '';
        off = res.received;
        if (res.done) break;
      }
      localStorage.removeItem(job.lsKey);
      updateQuota(res.quota);
      finish(job, true, res.file && res.file.versioned ? 'Uložené ako nová verzia' : '');
    } catch (e) {
      if (e.message !== 'Zrušené') finish(job, false, e.message);
      else if (job.el.querySelector('.up-cancel')) finish(job, false, 'Zrušené');
    }
    active--; pump();
    clearTimeout(refreshTimer); refreshTimer = setTimeout(() => { if (['folder', 'photos', 'recent'].includes(state.view)) load(true); }, active ? 1500 : 200);
  }
  $('#uploadToggle').onclick = () => $('#uploadPanel').classList.toggle('collapsed');
  $('#uploadClose').onclick = () => { if (active || queue.length) return toast('Nahrávanie ešte prebieha'); $('#uploadPanel').classList.add('d-none'); $('#uploadList').innerHTML = ''; done = total = 0; };
  addEventListener('beforeunload', (e) => { if (active || queue.length) { e.preventDefault(); e.returnValue = ''; } });

  const fromInput = (e, rel) => { enqueue([...e.target.files].map(f => ({ file: f, rel: rel ? f.webkitRelativePath : '' }))); e.target.value = ''; };
  $('#fileInput').onchange = (e) => fromInput(e, false);
  $('#cameraInput').onchange = (e) => fromInput(e, false);
  $('#folderInput').onchange = (e) => fromInput(e, true);

  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]'); if (!a) return;
    e.preventDefault();
    if (!canEditHere()) return toast('Táto zložka je iba na čítanie.', 'warning');
    if (IN_APP && a.dataset.action === 'upload-folder') { location.href = 'cloudapp://upload-folder?folder=' + curFolder(); return; }
    ({ 'new-folder': newFolder, 'new-text': newText, 'upload-files': () => $('#fileInput').click(), 'upload-folder': () => $('#folderInput').click(), 'upload-camera': () => $('#cameraInput').click() })[a.dataset.action]?.();
  });

  // drag & drop zo systému (aj celé zložky)
  let dragDepth = 0;
  const isFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files');
  addEventListener('dragenter', (e) => { if (isFiles(e) && canEditHere()) { dragDepth++; $('#dropOverlay').classList.add('show'); } });
  addEventListener('dragleave', (e) => { if (isFiles(e) && --dragDepth <= 0) { dragDepth = 0; $('#dropOverlay').classList.remove('show'); } });
  addEventListener('dragover', (e) => { if (isFiles(e)) e.preventDefault(); });
  addEventListener('drop', async (e) => {
    if (!isFiles(e)) return;
    e.preventDefault(); dragDepth = 0; $('#dropOverlay').classList.remove('show');
    if (!canEditHere()) return toast('Sem nemôžeš nahrávať.', 'warning');
    const entries = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
    if (!entries.length) return enqueue([...e.dataTransfer.files].map(f => ({ file: f, rel: '' })));
    const out = [];
    const walk = async (entry, path) => {
      if (entry.isFile) { const f = await new Promise((res, rej) => entry.file(res, rej)); out.push({ file: f, rel: path ? path + '/' + f.name : '' }); }
      else if (entry.isDirectory) {
        const reader = entry.createReader(); let batch;
        do { batch = await new Promise((res) => reader.readEntries(res, () => res([]))); for (const ch of batch) await walk(ch, (path ? path + '/' : '') + entry.name); } while (batch.length);
      }
    };
    for (const en of entries) await walk(en, '');
    enqueue(out);
  });

  /* ---------------- súbory zdieľané z iných aplikácií (PWA share target) ---------------- */
  async function importShared() {
    if (!new URLSearchParams(location.search).has('shared-files') || !('caches' in window)) return;
    history.replaceState(null, '', location.pathname + location.hash);
    const c = await caches.open('cloud-share-inbox');
    const reqs = await c.keys();
    const files = [];
    for (const req of reqs) {
      const res = await c.match(req);
      const name = decodeURIComponent(res.headers.get('X-File-Name') || 'subor');
      files.push({ file: new File([await res.blob()], name, { type: res.headers.get('Content-Type') || '', lastModified: Date.now() }), rel: '' });
      await c.delete(req);
    }
    if (files.length) { toast('Nahrávam ' + files.length + ' zdieľaných súborov do Môj disk'); enqueue(files.map(f => ({ ...f, folder: 0 }))); }
  }

  window.cloudReload = () => load(true); // volá Android aplikácia po nahratí zložky
  route();
  loadTags();
  importShared();
})();
