/* Disk – správa súborov a zložiek */
(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const { api, toast, esc, formatBytes, base } = Cloud;
  const PERM = { view: 1, edit: 2, owner: 3 };

  const state = {
    view: 'folder', folder: null, q: '', items: [], crumbs: [], perm: 'owner', root: 'drive', trashDays: 30,
    sel: new Set(),
    layout: localStorage.getItem('cloud-layout') || (innerWidth < 576 ? 'list' : 'grid'),
    sort: localStorage.getItem('cloud-sort') || 'name',
    dir: localStorage.getItem('cloud-dir') || 'asc',
  };
  const key = (it) => it.type + ':' + it.id;
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
  async function load(keepSel) {
    $('#loading').classList.remove('d-none');
    const r = await api('api.php?action=list', { view: state.view, folder: state.folder || 0, q: state.q, sort: state.sort, dir: state.dir });
    $('#loading').classList.add('d-none');
    if (!r.ok) { toast(r.error, 'danger'); if (state.view === 'folder' && state.folder) go('#'); return; }
    Object.assign(state, { items: r.items, crumbs: r.crumbs || [], perm: r.perm || 'owner', root: r.root || 'drive', trashDays: r.trash_days });
    if (!keepSel) state.sel.clear(); else state.sel = new Set([...state.sel].filter(byKey));
    render(); updateQuota(r.quota);
  }

  function renderCrumbs() {
    const c = $('#crumbs');
    const titles = { recent: 'Nedávne', starred: 'S hviezdičkou', trash: 'Kôš', shared: 'Zdieľané so mnou', photos: 'Fotky', search: 'Výsledky pre „' + state.q + '“' };
    if (state.view !== 'folder') { c.innerHTML = '<li class="breadcrumb-item active">' + esc(titles[state.view]) + '</li>'; return; }
    const rootLi = state.root === 'shared'
      ? '<li class="breadcrumb-item"><a href="#shared">Zdieľané so mnou</a></li>'
      : '<li class="breadcrumb-item' + (state.crumbs.length ? '' : ' active') + '"><a href="#" data-drop-folder="0">Môj disk</a></li>';
    let h = rootLi;
    state.crumbs.forEach((cr, i) => {
      const last = i === state.crumbs.length - 1;
      h += '<li class="breadcrumb-item' + (last ? ' active' : '') + '">' + (last ? esc(cr.name) : '<a href="#folder/' + cr.id + '" data-drop-folder="' + cr.id + '">' + esc(cr.name) + '</a>') + '</li>';
    });
    c.innerHTML = h;
    if (state.crumbs.length > 2 && innerWidth < 576) {
      const lis = $$('li', c); lis.slice(1, -2).forEach(li => li.remove());
      lis[0].insertAdjacentHTML('afterend', '<li class="breadcrumb-item">…</li>');
    }
  }

  function render() {
    renderCrumbs();
    document.title = (state.view === 'folder' ? (state.crumbs.at(-1)?.name || (state.root === 'shared' ? 'Zdieľané' : 'Môj disk')) : $('#crumbs').textContent) + ' · Cloud';
    const navKey = state.view === 'folder' ? (state.root === 'shared' ? 'shared' : 'drive') : state.view === 'search' ? 'drive' : state.view;
    $$('[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === state.layout));
    $$('#sortMenu [data-sort]').forEach(a => a.classList.toggle('active', a.dataset.sort === state.sort));
    $$('#sortMenu [data-dir]').forEach(a => a.classList.toggle('active', a.dataset.dir === state.dir));
    $$('.side-nav [data-nav]').forEach(a => a.classList.toggle('active', a.dataset.nav === navKey));
    document.body.classList.toggle('readonly', !canEditHere());

    // pruhy nad zoznamom
    $('#trashBar').classList.toggle('d-none', state.view !== 'trash' || !state.items.length);
    $('#trashInfo').textContent = state.trashDays > 0 ? 'Položky v koši sa natrvalo zmažú po ' + state.trashDays + ' dňoch. Stále sa započítavajú do úložiska.' : 'Položky v koši sa započítavajú do úložiska.';
    const sb = $('#sharedBar');
    if (state.view === 'shared') { sb.classList.remove('d-none'); sb.querySelector('span').textContent = 'Súbory a zložky, ktoré s tebou zdieľajú iní používatelia.'; }
    else if (state.view === 'folder' && state.root === 'shared') { sb.classList.remove('d-none'); sb.querySelector('span').textContent = state.perm === 'edit' ? 'Zdieľaná zložka – môžeš pridávať a upravovať.' : 'Zdieľaná zložka – iba na čítanie.'; }
    else sb.classList.add('d-none');

    const box = $('#items');
    const layout = state.view === 'photos' ? 'photos' : state.layout;
    box.className = 'items items-' + layout;
    $('#listHeader').classList.toggle('d-none', layout !== 'list' || !state.items.length);
    $('#listHeader .c-date').textContent = state.view === 'trash' ? 'Zmazané' : 'Zmenené';
    $('#empty').classList.toggle('d-none', state.items.length > 0);
    const emptyTxt = { search: ['Nič sa nenašlo', 'Skús iný výraz.'], starred: ['Žiadne položky s hviezdičkou', 'Označ dôležité súbory hviezdičkou.'],
      trash: ['Kôš je prázdny', 'Zmazané položky sa sem presunú a dajú sa obnoviť.'], shared: ['Nikto s tebou zatiaľ nič nezdieľa', ''],
      photos: ['Zatiaľ žiadne fotky', 'Nahraj obrázky – zoradia sa podľa dátumu.'], recent: ['Žiadne nedávne súbory', ''] }[state.view] || ['Zatiaľ tu nič nie je', 'Pretiahni sem súbory alebo použi tlačidlo Nový.'];
    $('#empty h5').textContent = emptyTxt[0];
    $('#empty p').textContent = emptyTxt[1];
    $('#empty .btn').classList.toggle('d-none', !canEditHere() || !['folder', 'photos'].includes(state.view));

    const folders = state.items.filter(i => i.type === 'd'), files = state.items.filter(i => i.type === 'f');
    let h = '';
    if (layout === 'photos') {
      let month = '';
      files.forEach(it => {
        const d = new Date((it.taken || it.updated).replace(' ', 'T'));
        const m = d.toLocaleDateString('sk-SK', { month: 'long', year: 'numeric' });
        if (m !== month) { if (month) h += '</div>'; month = m; h += '<div class="section-title photo-month">' + esc(m) + '</div><div class="photo-grid">'; }
        h += '<div class="item photo-item" data-key="' + key(it) + '"><input type="checkbox" class="form-check-input item-check" tabindex="-1"><img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + it.id + '" alt=""></div>';
      });
      if (month) h += '</div>';
    } else if (layout === 'grid') {
      if (folders.length) h += '<div class="section-title">Zložky</div><div class="grid grid-folders">' + folders.map(gridItem).join('') + '</div>';
      if (files.length) h += '<div class="section-title">Súbory</div><div class="grid grid-files">' + files.map(gridItem).join('') + '</div>';
    } else {
      h = state.items.map(listItem).join('');
    }
    box.innerHTML = h;
    updateSel();
  }

  const badges = (it) => (it.starred ? '<i class="bi bi-star-fill text-warning small ms-1" title="S hviezdičkou"></i>' : '')
    + (it.shared ? '<i class="bi bi-people-fill text-body-secondary small ms-1" title="Zdieľané"></i>' : '')
    + (it.versions ? '<span class="badge text-bg-light border ms-1" title="Počet starších verzií">v' + (it.versions + 1) + '</span>' : '');
  function thumbHtml(it, big) {
    const [ic, cls] = fileIcon(it);
    if (it.type === 'f' && it.thumb && !it.deleted) return '<img loading="lazy" src="' + base + 'api.php?action=thumb&id=' + it.id + '" alt="" onerror="this.replaceWith(Object.assign(document.createElement(\'i\'),{className:\'bi ' + ic + ' ' + cls + '\'}))">';
    return '<i class="bi ' + ic + ' ' + cls + (big ? ' big' : '') + '"></i>';
  }
  function subText(it) {
    if (state.view === 'trash') return 'zmazané ' + fmtDate(it.deleted);
    if (it.owner) return it.owner;
    return fmtDate(it.updated);
  }
  function listItem(it) {
    const k = key(it);
    return '<div class="item row-item" data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '"' + (it.type === 'd' && !it.deleted ? ' data-drop-folder="' + it.id + '"' : '') + '>'
      + '<div class="c-check"><input type="checkbox" class="form-check-input item-check" tabindex="-1"></div>'
      + '<div class="c-name"><span class="item-icon">' + thumbHtml(it) + '</span><span class="item-name text-truncate">' + esc(it.name) + '</span>' + badges(it) + '</div>'
      + '<div class="c-date d-none d-md-block text-truncate">' + esc(subText(it)) + '</div>'
      + '<div class="c-size d-none d-sm-block">' + (it.type === 'f' ? formatBytes(it.size) : (it.count != null ? it.count + ' pol.' : '—')) + '</div>'
      + '<div class="c-act"><button class="btn btn-icon btn-sm item-menu" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div></div>';
  }
  function gridItem(it) {
    const k = key(it);
    if (it.type === 'd') {
      return '<div class="item folder-card" data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '"' + (it.deleted ? '' : ' data-drop-folder="' + it.id + '"') + '>'
        + '<input type="checkbox" class="form-check-input item-check" tabindex="-1"><i class="bi ' + (it.shared || it.owner ? 'bi-folder-symlink-fill' : 'bi-folder-fill') + ' ic-folder"></i>'
        + '<span class="item-name text-truncate">' + esc(it.name) + '</span>' + badges(it)
        + '<button class="btn btn-icon btn-sm item-menu ms-auto" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div>';
    }
    const [ic, cls] = fileIcon(it);
    return '<div class="item file-card" data-key="' + k + '" draggable="' + (it.deleted ? 'false' : 'true') + '">'
      + '<input type="checkbox" class="form-check-input item-check" tabindex="-1">'
      + '<div class="file-thumb">' + thumbHtml(it, true) + '</div>'
      + '<div class="file-meta"><i class="bi ' + ic + ' ' + cls + '"></i><span class="item-name text-truncate">' + esc(it.name) + '</span>'
      + '<button class="btn btn-icon btn-sm item-menu ms-auto" aria-label="Akcie"><i class="bi bi-three-dots-vertical"></i></button></div>'
      + '<div class="file-sub text-truncate">' + formatBytes(it.size) + ' · ' + esc(subText(it)) + ' ' + badges(it) + '</div></div>';
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
    else if (['recent', 'starred', 'trash', 'shared', 'photos'].includes(h)) { state.view = h; state.folder = null; }
    else if (h.startsWith('search/')) { state.view = 'search'; state.q = h.slice(7); $('#searchInput').value = state.q; }
    else { state.view = 'folder'; state.folder = null; }
    if (state.view !== 'search') $('#searchInput').value = '';
    const oc = bootstrap.Offcanvas.getInstance($('#sidebar')); oc && oc.hide();
    load();
  }
  window.addEventListener('hashchange', route);

  function open(it) {
    if (state.view === 'trash') { toast('Položku v koši najprv obnov.'); return; }
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
    $('#infoBody').innerHTML = Object.entries(r.info).map(([k, v]) => '<dt class="col-5 text-body-secondary fw-normal">' + esc(k) + '</dt><dd class="col-7 text-break">' + esc(v) + '</dd>').join('');
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
      li.querySelector('.avatar').textContent = u.name.charAt(0).toUpperCase();
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
    $('#shPassword').value = '';
    $('#shPassword').placeholder = l && l.has_password ? '•••• (nastavené – nechaj prázdne)' : 'bez hesla';
    $('#shPwClearWrap').classList.toggle('d-none', !(l && l.has_password));
    $('#shPwClear').checked = false;
    $('#shStats').textContent = l ? (l.expired ? '⚠ Platnosť vypršala · ' : '') + 'Stiahnutia: ' + l.downloads + (l.has_password ? ' · chránené heslom' : '') : '';
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
    const r = await api('api.php?action=share_link_save', { type: shareIt.type, id: shareIt.id, expires: $('#shExpires').value, password: pw, password_mode: mode });
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
  const pvList = () => state.items.filter(i => i.type === 'f');
  async function preview(it) {
    const files = pvList();
    pvIdx = files.findIndex(f => f.id === it.id);
    const body = $('#previewBody');
    $('#previewName').textContent = it.name;
    $('#previewDownload').href = fileUrl(it.id);
    $('#previewPrev').style.visibility = pvIdx > 0 ? 'visible' : 'hidden';
    $('#previewNext').style.visibility = pvIdx < files.length - 1 ? 'visible' : 'hidden';
    $('#previewEdit')?.remove();
    const m = it.mime, u = fileUrl(it.id, true);
    bootstrap.Modal.getOrCreateInstance($('#previewModal')).show();
    if (/^image\/(jpeg|png|gif|webp|bmp)/.test(m)) body.innerHTML = '<img src="' + u + '" alt="">';
    else if (m.startsWith('video/')) body.innerHTML = '<video src="' + u + '" controls autoplay playsinline></video>';
    else if (m.startsWith('audio/')) body.innerHTML = '<div class="text-center"><i class="bi bi-music-note-beamed display-1 d-block mb-4 text-white-50"></i><audio src="' + u + '" controls autoplay></audio></div>';
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
      body.innerHTML = '<div class="text-center text-white"><i class="bi ' + ic + ' ' + cls + '" style="font-size:6rem"></i><p class="mt-3 mb-1 fw-semibold">' + esc(it.name) + '</p><p class="text-white-50">' + formatBytes(it.size) + ' · náhľad nie je dostupný</p><a class="btn btn-light rounded-pill px-4" href="' + fileUrl(it.id) + '"><i class="bi bi-download me-2"></i>Stiahnuť</a></div>';
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
  function showCtx(items, x, y) {
    ctxItems = items;
    const single = items.length === 1 ? items[0] : null;
    const trash = state.view === 'trash';
    const minPerm = items.reduce((m, i) => Math.min(m, PERM[i.perm] || 1), 3);
    const vis = {
      open: single && !trash, edit: single && !trash && isText(single) && minPerm >= 2, download: !trash,
      share: single && !trash && minPerm === 3, rename: single && !trash && minPerm >= 2, move: !trash && minPerm >= 2,
      copy: !trash && !items.some(i => i.type === 'd'), star: !trash && minPerm === 3, versions: single && !trash && single.type === 'f',
      info: single && !trash, restore: trash, leave: state.view === 'shared', delete: !trash && minPerm >= 2, purge: trash,
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
    ({ open: () => open(it), edit: () => editText(it), download: () => download(items), share: () => share(it), rename: () => rename(it),
       move: () => moveDialog(items, 'move'), copy: () => moveDialog(items, 'copy'), star: () => star(items), versions: () => versions(it),
       info: () => info(it), restore: () => restore(items), leave: () => leave(items), delete: () => del(items), purge: () => purge(items) })[a.dataset.ctx]();
  });

  /* ---------------- udalosti v zozname ---------------- */
  const itemsBox = $('#items');
  let lpTimer = null, lpFired = false;
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

  $('#checkAll').addEventListener('change', (e) => { state.sel = new Set(e.target.checked ? state.items.map(key) : []); updateSel(); });
  $('#selBar').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sel]'); if (!b) return;
    const items = selItems();
    ({ clear: () => { state.sel.clear(); updateSel(); }, download: () => download(items), move: () => moveDialog(items, 'move'),
       copy: () => moveDialog(items, 'copy'), star: () => star(items), delete: () => del(items), restore: () => restore(items),
       purge: () => purge(items), leave: () => leave(items) })[b.dataset.sel]();
  });
  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea, select') || document.querySelector('.modal.show')) return;
    if (e.key === 'Delete' && state.sel.size) state.view === 'trash' ? purge(selItems()) : del(selItems().filter(i => PERM[i.perm] >= 2));
    if (e.key === 'Escape') { state.sel.clear(); updateSel(); hideCtx(); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); state.sel = new Set(state.items.map(key)); updateSel(); }
    if (e.key === 'F2' && state.sel.size === 1) rename(selItems()[0]);
  });

  // prepínače
  $$('[data-view]').forEach(b => b.onclick = () => { state.layout = b.dataset.view; localStorage.setItem('cloud-layout', state.layout); if (state.view === 'photos') go('#'); else render(); });
  $$('#sortMenu [data-sort]').forEach(a => a.onclick = (e) => { e.preventDefault(); state.sort = a.dataset.sort; localStorage.setItem('cloud-sort', state.sort); load(); });
  $$('#sortMenu [data-dir]').forEach(a => a.onclick = (e) => { e.preventDefault(); state.dir = a.dataset.dir; localStorage.setItem('cloud-dir', state.dir); load(); });
  $('#crumbs').addEventListener('click', (e) => { const a = e.target.closest('a'); if (a && a.dataset.dropFolder === '0') { e.preventDefault(); go('#'); } });
  $$('.side-nav [data-nav]').forEach(a => {
    const map = { drive: '#', recent: '#recent', starred: '#starred', trash: '#trash', shared: '#shared', photos: '#photos' };
    if (map[a.dataset.nav] !== undefined) a.addEventListener('click', (e) => { e.preventDefault(); go(map[a.dataset.nav]); });
  });

  // vyhľadávanie
  let sTimer;
  $('#searchInput').addEventListener('input', (e) => {
    clearTimeout(sTimer);
    sTimer = setTimeout(() => { const v = e.target.value.trim(); go(v ? '#search/' + encodeURIComponent(v) : '#'); }, 350);
  });

  /* ---------------- nahrávanie po častiach (s obnovením po výpadku) ---------------- */
  const queue = []; let active = 0, refreshTimer = null, done = 0, total = 0;
  function enqueue(files) {
    const explicit = files.every(f => f.folder !== undefined);
    if (!canEditHere() && !explicit) { toast('Sem nemôžeš nahrávať (iba na čítanie).', 'warning'); return; }
    const folder = curFolder();
    if (['trash', 'shared', 'search', 'starred'].includes(state.view) && !explicit) go('#');
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

  route();
  importShared();
})();
