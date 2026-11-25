/* Spoločné funkcie */
window.Cloud = (function () {
  const base = document.querySelector('meta[name="base-url"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  async function api(path, data = {}, asJson = false) {
    let noLock = false;
    if (data && !(data instanceof FormData) && data.__nolock) { noLock = true; data = Object.assign({}, data); delete data.__nolock; }
    const j = await apiRaw(path, data, asJson);
    // zamknutá zložka: opýtaj sa na heslo (v aplikácii aj odtlačok) a akciu zopakuj
    if (j && j.locked && !noLock && !/action=folder_(unlock|lock)/.test(path)) {
      if (await unlockFolder(j.locked)) return apiRaw(path, data, asJson);
    }
    return j;
  }
  async function apiRaw(path, data = {}, asJson = false) {
    const opts = { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, credentials: 'same-origin' };
    if (asJson) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(data); }
    else { const fd = data instanceof FormData ? data : new FormData(); if (!(data instanceof FormData)) Object.entries(data).forEach(([k, v]) => Array.isArray(v) ? v.forEach(x => fd.append(k + '[]', x)) : fd.append(k, v ?? '')); opts.body = fd; }
    try {
      const res = await fetch(base + path, opts);
      const j = await res.json().catch(() => ({ ok: false, error: 'Neočakávaná odpoveď servera (' + res.status + ')' }));
      if (res.status === 401) { location.href = base + 'login.php'; }
      return j;
    } catch (e) { return { ok: false, error: 'Chyba pripojenia. Si online?' }; }
  }

  function toast(msg, type = 'dark', delay = 3500) {
    const wrap = document.getElementById('toasts') || (() => { const d = document.createElement('div'); d.id = 'toasts'; d.className = 'toast-container position-fixed bottom-0 end-0 p-3'; document.body.appendChild(d); return d; })();
    const icons = { success: 'check-circle', danger: 'exclamation-octagon', warning: 'exclamation-triangle', info: 'info-circle', dark: 'info-circle', primary: 'bell' };
    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 text-bg-' + type;
    el.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi bi-' + (icons[type] || 'info-circle') + ' me-2"></i></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.toast-body').append(document.createTextNode(msg));
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  /** Potvrdenie: true = hlavné tlačidlo, false = zrušiť, 'alt' = druhé tlačidlo (ak je altText). */
  function confirmBox(title, text, okText = 'Potvrdiť', danger = true, altText = '') {
    return new Promise((resolve) => {
      const id = 'cf' + Date.now();
      document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered ${altText ? '' : 'modal-sm'}"><div class="modal-content"><div class="modal-header border-0 pb-0"><h5 class="modal-title"></h5></div><div class="modal-body text-body-secondary"></div><div class="modal-footer border-0"><button class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button>${altText ? '<button class="btn btn-outline-secondary rounded-pill" data-alt></button>' : ''}<button class="btn ${danger ? 'btn-danger' : 'btn-primary'} rounded-pill" data-ok></button></div></div></div></div>`);
      const el = document.getElementById(id);
      el.querySelector('.modal-title').textContent = title;
      el.querySelector('.modal-body').textContent = text;
      el.querySelector('[data-ok]').textContent = okText;
      const m = new bootstrap.Modal(el); let ok = false;
      el.querySelector('[data-ok]').onclick = () => { ok = true; m.hide(); };
      if (altText) { el.querySelector('[data-alt]').textContent = altText; el.querySelector('[data-alt]').onclick = () => { ok = 'alt'; m.hide(); }; }
      el.addEventListener('hidden.bs.modal', () => { el.remove(); resolve(ok); });
      m.show();
    });
  }

  function promptBox(title, value = '', okText = 'OK', label = '') {
    return new Promise((resolve) => {
      const id = 'pr' + Date.now();
      document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content"><div class="modal-header border-0 pb-0"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input class="form-control form-control-lg" required maxlength="255"></div><div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4"></button></div></form></div></div>`);
      const el = document.getElementById(id);
      el.querySelector('.modal-title').textContent = title;
      const inp = el.querySelector('input'); inp.value = value; inp.placeholder = label;
      if (label === 'password') { inp.type = 'password'; inp.placeholder = 'Heslo'; inp.autocomplete = 'current-password'; }
      el.querySelector('.btn-primary').textContent = okText;
      const m = new bootstrap.Modal(el); let result = null;
      el.addEventListener('shown.bs.modal', () => { inp.focus(); const dot = value.lastIndexOf('.'); inp.setSelectionRange(0, dot > 0 ? dot : value.length); });
      el.querySelector('form').onsubmit = (e) => { e.preventDefault(); result = inp.value.trim(); m.hide(); };
      el.addEventListener('hidden.bs.modal', () => { el.remove(); resolve(result || null); });
      m.show();
    });
  }

  function formatBytes(b) {
    const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; b = +b;
    while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
    return (i ? b.toFixed(1).replace('.', ',') : b) + ' ' + u[i];
  }
  const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  document.addEventListener('DOMContentLoaded', () => {
    // tmavý režim
    const tt = document.getElementById('themeToggle');
    const setIcon = () => { if (tt) tt.innerHTML = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>'; };
    setIcon();
    tt && tt.addEventListener('click', () => {
      const n = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
      CloudUI.set('theme', n); CloudUI.apply();
    });
    document.addEventListener('cloud-theme', setIcon);
    // zobraziť heslo
    document.querySelectorAll('[data-toggle-pass]').forEach(b => b.addEventListener('click', () => {
      const i = document.querySelector(b.dataset.togglePass); i.type = i.type === 'password' ? 'text' : 'password';
      b.innerHTML = i.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    }));
    // sila hesla
    document.querySelectorAll('[data-strength]').forEach(i => i.addEventListener('input', () => {
      const v = i.value; let s = 0;
      if (v.length >= 8) s++; if (v.length >= 12) s++; if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++; if (/\d/.test(v)) s++; if (/[^A-Za-z0-9]/.test(v)) s++;
      const bar = document.querySelector(i.dataset.strength);
      bar.style.width = (s * 20) + '%'; bar.className = 'progress-bar ' + (s < 2 ? 'bg-danger' : s < 4 ? 'bg-warning' : 'bg-success');
    }));
    // potvrdenie nebezpečných akcií vo formulároch
    document.querySelectorAll('form[data-confirm]').forEach(f => f.addEventListener('submit', async (e) => {
      if (f.dataset.ok) return;
      e.preventDefault();
      if (await confirmBox('Si si istý?', f.dataset.confirm, 'Potvrdiť')) { f.dataset.ok = 1; f.submit(); }
    }));
    // tlačidlo Späť (telefón, aplikácia, prehliadač) zatvorí otvorené okno – napr. náhľad fotky či videa
    let popping = false;
    const layerOpen = (e) => { try { history.pushState({ cloudLayer: e.target.id || 'layer' }, ''); } catch (x) {} };
    const layerClosed = (e) => { if (popping) return; if (history.state && history.state.cloudLayer === (e.target.id || 'layer')) history.back(); };
    document.addEventListener('shown.bs.modal', layerOpen);
    document.addEventListener('hidden.bs.modal', layerClosed);
    const closeTop = (fromPop) => {
      const m = [...document.querySelectorAll('.modal.show')].pop();
      const oc = document.querySelector('.offcanvas.show');
      const el = m || oc; if (!el) return false;
      if (fromPop) popping = true;
      el.querySelectorAll('video, audio').forEach(v => { try { v.pause(); } catch (x) {} });
      (m ? bootstrap.Modal : bootstrap.Offcanvas).getInstance(el)?.hide();
      if (fromPop) setTimeout(() => { popping = false; }, 500);
      return true;
    };
    addEventListener('popstate', () => closeTop(true));
    // volá Android aplikácia pri tlačidle Späť: true = zatvorilo sa okno, inak sa ide späť v histórii
    window.cloudBack = () => closeTop(false);

    // tlačidlá „Kopírovať“ (data-copy="#idPola")
    document.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-copy]'); if (!b) return;
      const el = document.querySelector(b.dataset.copy); if (!el) return;
      try { await navigator.clipboard.writeText(el.value || el.textContent); } catch (x) { el.select && el.select(); document.execCommand('copy'); }
      toast('Skopírované', 'success');
    });

    // zbaliteľné skupiny v menu si pamätajú stav
    document.querySelectorAll('[data-remember]').forEach(b => {
      const k = b.dataset.remember, el = document.querySelector(b.dataset.bsTarget);
      if (!el) return;
      const put = (v) => { const sd = Object.assign({}, CloudUI.get('side', {})); if (v) sd[k] = true; else delete sd[k]; CloudUI.set('side', sd); };
      el.addEventListener('shown.bs.collapse', () => put(true));
      el.addEventListener('hidden.bs.collapse', () => put(false));
    });
    // Select2
    if (window.jQuery && jQuery.fn.select2) {
      jQuery('select.select2').each(function () {
        if (jQuery(this).data('select2')) return;
        const $s = jQuery(this);
        $s.select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: $s.data('search') === false ? Infinity : 0, dropdownParent: $s.closest('.modal').length ? $s.closest('.modal') : jQuery(document.body) });
      });
    }
  });

  // ---------------- upozornenia (zvonček) ----------------
  function timeAgo(s) {
    const d = (Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 1000;
    if (d < 60) return 'práve teraz';
    if (d < 3600) return 'pred ' + Math.floor(d / 60) + ' min';
    if (d < 86400) return 'pred ' + Math.floor(d / 3600) + ' h';
    if (d < 7 * 86400) return 'pred ' + Math.floor(d / 86400) + ' d';
    return new Date(s.replace(' ', 'T')).toLocaleDateString('sk-SK');
  }
  document.addEventListener('DOMContentLoaded', () => {
    const bell = document.getElementById('notifBell');
    if (!bell) return;
    const badge = document.getElementById('notifBadge'), list = document.getElementById('notifList');
    const setBadge = (n) => { badge.textContent = n > 99 ? '99+' : n; badge.classList.toggle('d-none', !n); };
    async function loadList() {
      const r = await api('api.php?action=notifications');
      if (!r.ok) { list.innerHTML = '<div class="text-center text-body-secondary small py-4">Nepodarilo sa načítať</div>'; return; }
      setBadge(r.unread);
      if (!r.items.length) { list.innerHTML = '<div class="text-center text-body-secondary py-5"><i class="bi bi-bell-slash fs-2 d-block mb-2"></i>Žiadne upozornenia</div>'; return; }
      list.innerHTML = '';
      r.items.forEach(n => {
        const a = document.createElement('a');
        a.className = 'notif-item' + (n.read ? '' : ' unread');
        a.href = n.link ? base + n.link : '#';
        a.innerHTML = '<span class="notif-ic"><i class="bi ' + esc(n.icon) + '"></i></span><span class="flex-grow-1 min-w-0"><span class="d-block fw-semibold notif-title"></span><span class="d-block small text-body-secondary notif-body"></span><span class="d-block small text-body-secondary opacity-75">' + esc(timeAgo(n.date)) + '</span></span>';
        a.querySelector('.notif-title').textContent = n.title;
        a.querySelector('.notif-body').textContent = n.body;
        a.addEventListener('click', (e) => {
          if (!n.read) api('api.php?action=notifications_read', { id: n.id });
          if (!n.link) e.preventDefault();
          else if (location.pathname.endsWith(n.link.split('#')[0]) && n.link.includes('#')) { e.preventDefault(); location.hash = n.link.split('#')[1]; bootstrap.Dropdown.getInstance(bell)?.hide(); }
        });
        list.appendChild(a);
      });
    }
    bell.addEventListener('show.bs.dropdown', loadList);
    document.getElementById('notifReadAll').addEventListener('click', async () => {
      await api('api.php?action=notifications_read'); setBadge(0); list.querySelectorAll('.unread').forEach(x => x.classList.remove('unread'));
    });
    // kontrola nových každú minútu
    let lastSeen = parseInt(CloudUI.get('notif', 0)) || 0;
    async function poll() {
      if (document.hidden) return;
      const r = await api('api.php?action=notifications_unread');
      if (!r.ok) return;
      setBadge(r.unread);
      // server bol aktualizovaný → načítať stránku znova (aplikácia drží stránku otvorenú dlho)
      const cur = document.documentElement.dataset.ver;
      if (r.ver && cur && r.ver !== cur && !document.querySelector('.modal.show') && !document.querySelector('.upload-panel:not(.d-none)')) {
        if (sessionStorage.getItem('cloud-reloaded-for') !== r.ver) { sessionStorage.setItem('cloud-reloaded-for', r.ver); location.reload(); return; }
      }
      if (r.last > lastSeen) {
        if (lastSeen > 0 && r.unread > 0) {
          const l = await api('api.php?action=notifications');
          const fresh = (l.items || []).filter(n => n.id > lastSeen && !n.read);
          if (fresh.length) toast(fresh[0].title + (fresh.length > 1 ? ' (+' + (fresh.length - 1) + ')' : ''), 'primary', 6000);
        }
        lastSeen = r.last; CloudUI.set('notif', lastSeen);
      }
    }
    poll(); setInterval(poll, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  });

  // PWA – service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(() => {}));
  }
  // PWA – inštalácia
  let deferred = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault(); deferred = e;
    document.querySelectorAll('[data-install]').forEach(b => { b.classList.remove('d-none'); b.onclick = async () => { deferred.prompt(); await deferred.userChoice; deferred = null; b.classList.add('d-none'); }; });
  });

  /* ---------- aplikácia: fotky pri účtoch na prepnutie (aplikácia pozná iba meno a e-mail) ---------- */
  const avCache = {};
  async function appAccountAvatars() {
    const rows = document.querySelectorAll('#accountMenu a[href^="cloudapp://account?"]');
    for (const a of rows) {
      const av = a.querySelector('.avatar'); if (!av || av.classList.contains('has-img')) continue;
      const lines = a.querySelectorAll('.text-truncate');
      const email = ((lines[lines.length - 1] || {}).textContent || '').split(' · ')[0].trim().toLowerCase();
      if (!email.includes('@')) continue;
      if (!(email in avCache)) {
        avCache[email] = null;
        const r = await api('api.php?action=user_search', { q: email });
        const hit = (r.results || []).find(x => (x.email || '').toLowerCase().startsWith(email));
        avCache[email] = hit && hit.avatar ? hit.avatar : null;
      }
      if (avCache[email]) { av.textContent = ''; av.classList.add('has-img'); const im = document.createElement('img'); im.src = avCache[email]; im.alt = ''; av.appendChild(im); }
    }
  }
  document.addEventListener('show.bs.dropdown', (e) => { if (e.target.closest && e.target.closest('.avatar-btn')) appAccountAvatars(); });

  /* ---------- odomknutie zložky zamknutej heslom ---------- */
  let unlockOpen = null;
  function unlockFolder(lk) {
    if (unlockOpen) return unlockOpen;
    const A = window.CloudAndroid;
    const bio = !!(A && A.hasFolderKey && A.hasFolderKey(String(lk.id)));
    unlockOpen = new Promise((resolve) => {
      const id = 'ul' + Date.now();
      document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><form class="modal-content">
        <div class="modal-body text-center pt-4"><div class="lock-ic mb-2"><i class="bi bi-lock-fill"></i></div><h5 class="mb-1">Zamknutá zložka</h5><div class="small text-body-secondary mb-3 text-break" data-name></div>
        <input type="password" class="form-control form-control-lg text-center" placeholder="Heslo zložky" autocomplete="off" required>
        <div class="small text-danger mt-2 d-none" data-err></div>
        ${bio ? '<button type="button" class="btn btn-outline-primary rounded-pill w-100 mt-3" data-bio><i class="bi bi-fingerprint me-1"></i>Odomknúť odtlačkom</button>' : ''}</div>
        <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn btn-primary rounded-pill px-4">Odomknúť</button></div></form></div></div>`);
      const el = document.getElementById(id), form = el.querySelector('form'), inp = el.querySelector('input'), err = el.querySelector('[data-err]');
      el.querySelector('[data-name]').textContent = lk.name || '';
      const m = new bootstrap.Modal(el); let ok = false;
      const tryPw = async (pw, fromBio) => {
        const r = await apiRaw('api.php?action=folder_unlock', { id: lk.id, password: pw });
        if (r.ok) {
          ok = true; m.hide();
          if (!fromBio && A && A.offerFolderKey && !bio) { try { A.offerFolderKey(String(lk.id), lk.name || '', pw); } catch (e) {} }
          return;
        }
        if (fromBio && A && A.forgetFolderKey) A.forgetFolderKey(String(lk.id)); // heslo sa medzitým zmenilo
        err.textContent = r.error || 'Nesprávne heslo.'; err.classList.remove('d-none'); inp.select();
      };
      form.addEventListener('submit', (e) => { e.preventDefault(); tryPw(inp.value, false); });
      window.cloudFolderKey = (fid, pw) => { if (String(fid) === String(lk.id) && pw) tryPw(pw, true); };
      const askBio = () => { try { A.unlockFolder(String(lk.id), lk.name || ''); } catch (e) {} };
      el.querySelector('[data-bio]')?.addEventListener('click', askBio);
      el.addEventListener('shown.bs.modal', () => { if (bio) askBio(); else inp.focus(); });
      el.addEventListener('hidden.bs.modal', () => { el.remove(); unlockOpen = null; window.cloudFolderKey = null; resolve(ok); });
      m.show();
    });
    return unlockOpen;
  }

  /* ---------- nastavenia vzhľadu a ovládania: v účte na serveri (platia na každom zariadení), v zariadení sa neukladá nič ---------- */
  const UI = window.CLOUD_UI || {};
  const logged = !!document.getElementById('accountMenu');
  let uiPend = {}, uiT = null;
  const uiFlush = (beacon) => {
    clearTimeout(uiT); uiT = null;
    if (!Object.keys(uiPend).length || !logged) return;
    const value = JSON.stringify(uiPend); uiPend = {};
    if (beacon && navigator.sendBeacon) {
      const fd = new FormData(); fd.append('key', 'ui'); fd.append('value', value); fd.append('_csrf', csrf);
      if (navigator.sendBeacon(base + 'api.php?action=prefs_save', fd)) return;
    }
    apiRaw('api.php?action=prefs_save', { key: 'ui', value });
  };
  addEventListener('pagehide', () => uiFlush(true));
  document.addEventListener('visibilitychange', () => { if (document.hidden) uiFlush(true); });
  const CloudUI = {
    get(k, d) { return Object.prototype.hasOwnProperty.call(UI, k) ? UI[k] : d; },
    set(k, v, now) {
      if (JSON.stringify(UI[k]) === JSON.stringify(v)) return;
      if (v === null || v === undefined) delete UI[k]; else UI[k] = v;
      uiPend[k] = v === undefined ? null : v;
      clearTimeout(uiT); uiT = setTimeout(uiFlush, now ? 0 : 800);
      document.dispatchEvent(new CustomEvent('cloud-ui', { detail: { key: k, value: v } }));
    },
    apply() {
      const d = document.documentElement;
      const t = UI.theme === 'light' || UI.theme === 'dark' ? UI.theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      d.setAttribute('data-bs-theme', t);
      d.setAttribute('data-accent', UI.accent || 'blue');
      d.setAttribute('data-density', UI.density || 'normal');
      d.setAttribute('data-thumbs', UI.thumbs || 'm');
      if (UI.anim === 'off') d.setAttribute('data-anim', 'off'); else d.removeAttribute('data-anim');
      const tc = document.querySelector('meta[name="theme-color"]'); if (tc) tc.content = ACCENTS[UI.accent || 'blue'] || '#1a73e8';
      document.dispatchEvent(new Event('cloud-theme'));
    },
    flush: () => uiFlush(false),
  };
  const ACCENTS = { blue: '#1a73e8', green: '#188038', purple: '#7c4dff', red: '#d93025', orange: '#e8710a', teal: '#00897b', pink: '#d01884' };
  const ACC_NAMES = { blue: 'Modrá', green: 'Zelená', purple: 'Fialová', red: 'Červená', orange: 'Oranžová', teal: 'Tyrkysová', pink: 'Ružová' };
  matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', () => { if (!UI.theme || UI.theme === 'auto') CloudUI.apply(); });
  // jednorazový prenos starých nastavení, ktoré boli uložené v zariadení, do účtu – potom sa v zariadení vymažú
  if (logged && !UI.migrated) {
    try {
      const L = localStorage, g = (k) => L.getItem(k), j = (k) => { try { return JSON.parse(g(k) || 'null'); } catch (e) { return null; } };
      const m = { migrated: 1 };
      if (g('cloud-theme')) m.theme = g('cloud-theme');
      if (g('cloud-layout')) m.layout = g('cloud-layout');
      if (g('cloud-sort')) m.sort = g('cloud-sort');
      if (g('cloud-dir')) m.dir = g('cloud-dir');
      if (g('cloud-psort')) m.psort = g('cloud-psort');
      if (g('cloud-pdir')) m.pdir = g('cloud-pdir');
      if (g('cloud-playout')) m.playout = g('cloud-playout');
      if (j('cloud-layouts')) m.layouts = j('cloud-layouts');
      if (j('cloud-colw')) m.colw = j('cloud-colw');
      const side = {}; Object.keys(L).filter(k => /^cloud-side-\d+$/.test(k)).forEach(k => { side['g' + k.slice(11)] = L.getItem(k) === '1'; });
      if (Object.keys(side).length) m.side = side;
      if (g('cloud-notif-last')) m.notif = parseInt(g('cloud-notif-last')) || 0;
      Object.entries(m).forEach(([k, v]) => { if (!(k in UI) || k === 'migrated') { UI[k] = v; uiPend[k] = v; } });
      uiFlush();
      Object.keys(L).filter(k => k.startsWith('cloud-')).forEach(k => L.removeItem(k));
    } catch (e) { CloudUI.set('migrated', 1, true); }
  }

  /* ---------- okno „Vzhľad a ovládanie“ ---------- */
  function appearance() {
    document.getElementById('uiModal')?.remove();
    const opt = (name, val, label, icon) => '<input type="radio" class="btn-check" name="' + name + '" id="ui-' + name + '-' + val + '" value="' + val + '"' + ((CloudUI.get(name, name === 'theme' ? 'auto' : name === 'density' ? 'normal' : name === 'thumbs' ? 'm' : 'auto') === val) ? ' checked' : '') + '>'
      + '<label class="btn ui-opt" for="ui-' + name + '-' + val + '">' + (icon ? '<i class="bi ' + icon + '"></i>' : '') + label + '</label>';
    const sw = (name, label, sub, def) => '<div class="form-check form-switch ui-switch"><input class="form-check-input" type="checkbox" role="switch" id="ui-' + name + '" data-ui-bool="' + name + '"' + (CloudUI.get(name, def) ? ' checked' : '') + '>'
      + '<label class="form-check-label" for="ui-' + name + '">' + label + '<span class="d-block small text-body-secondary">' + sub + '</span></label></div>';
    const touch = 'ontouchstart' in window;
    document.body.insertAdjacentHTML('beforeend', '<div class="modal fade" id="uiModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
      + '<div class="modal-header border-0"><h5 class="modal-title"><i class="bi bi-palette me-2"></i>Vzhľad a ovládanie</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
      + '<div class="modal-body pt-0">'
      + '<div class="ui-label">Téma</div><div class="ui-opts">' + opt('theme', 'light', 'Svetlá', 'bi-sun') + opt('theme', 'dark', 'Tmavá', 'bi-moon-stars') + opt('theme', 'auto', 'Podľa systému', 'bi-circle-half') + '</div>'
      + '<div class="ui-label">Farba zvýraznenia</div><div class="ui-accents">' + Object.keys(ACCENTS).map(a => '<button type="button" class="ui-acc' + ((UI.accent || 'blue') === a ? ' on' : '') + '" data-acc="' + a + '" style="--a:' + ACCENTS[a] + '" title="' + ACC_NAMES[a] + '" aria-label="' + ACC_NAMES[a] + '"><i class="bi bi-check-lg"></i></button>').join('') + '</div>'
      + '<div class="ui-label">Hustota zobrazenia</div><div class="ui-opts">' + opt('density', 'compact', 'Kompaktná', 'bi-list') + opt('density', 'normal', 'Normálna', 'bi-list-ul') + opt('density', 'comfy', 'Vzdušná', 'bi-view-list') + '</div>'
      + '<div class="ui-label">Veľkosť náhľadov v mriežke</div><div class="ui-opts">' + opt('thumbs', 's', 'Malé', 'bi-grid-3x3-gap') + opt('thumbs', 'm', 'Stredné', 'bi-grid') + opt('thumbs', 'l', 'Veľké', 'bi-grid-1x2') + '</div>'
      + '<div class="ui-label">Animácie</div><div class="ui-opts">' + opt('anim', 'auto', 'Zapnuté', 'bi-magic') + opt('anim', 'off', 'Vypnuté', 'bi-slash-circle') + '</div>'
      + '<div class="ui-label">Mobil</div>'
      + sw('bnav', 'Spodná lišta', 'Môj disk, Nedávne, Fotky a Zdieľané vždy po ruke', true)
      + sw('swipe', 'Potiahnutie riadku v zozname', 'Doľava = do koša, doprava = hviezdička', true)
      + (touch ? '' : '<div class="small text-body-secondary mt-3"><i class="bi bi-keyboard me-1"></i>Klávesové skratky ukáže kláves <kbd>?</kbd></div>')
      + '<div class="small text-body-secondary mt-3"><i class="bi bi-cloud-check me-1"></i>Nastavenia sa ukladajú do tvojho účtu a platia na každom zariadení.</div>'
      + '</div><div class="modal-footer border-0"><button type="button" class="btn btn-light rounded-pill me-auto" data-ui-reset>Predvolené</button><button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">Hotovo</button></div></div></div></div>');
    const el = document.getElementById('uiModal');
    el.addEventListener('change', (e) => {
      const t = e.target;
      if (t.dataset.uiBool) CloudUI.set(t.dataset.uiBool, t.checked);
      else if (t.name) CloudUI.set(t.name, t.value === 'auto' && t.name === 'anim' ? null : t.value);
      CloudUI.apply();
    });
    el.addEventListener('click', (e) => {
      const a = e.target.closest('[data-acc]');
      if (a) { CloudUI.set('accent', a.dataset.acc === 'blue' ? null : a.dataset.acc); el.querySelectorAll('.ui-acc').forEach(x => x.classList.toggle('on', x === a)); CloudUI.apply(); }
      if (e.target.closest('[data-ui-reset]')) { ['theme', 'accent', 'density', 'thumbs', 'anim', 'bnav', 'swipe'].forEach(k => CloudUI.set(k, null)); CloudUI.apply(); bootstrap.Modal.getInstance(el).hide(); toast('Vzhľad je nastavený na predvolený.', 'success'); }
    });
    el.addEventListener('hidden.bs.modal', () => { el.remove(); CloudUI.flush(); });
    bootstrap.Modal.getOrCreateInstance(el).show();
  }
  document.addEventListener('cloud-ui', (e) => { if (e.detail.key === 'bnav') document.body.classList.toggle('has-bnav', e.detail.value !== false); });
  window.CloudUI = CloudUI;
  window.cloudAppearance = appearance;

  return { api, toast, confirm: confirmBox, prompt: promptBox, formatBytes, esc, base, csrf, unlockFolder, ui: CloudUI };
})();

/* Mobil a aplikácia: menu vľavo sa otvorí potiahnutím prstom doprava a zatvorí potiahnutím doľava */
(function () {
  const sb = document.getElementById('sidebar');
  if (!sb || !window.bootstrap || !('ontouchstart' in window)) return;
  const narrow = () => window.innerWidth < 992; // na širokej obrazovke je menu stále viditeľné
  const isOpen = () => sb.classList.contains('show') || sb.classList.contains('showing');
  // kde sa potiahnutie nemá brať ako gesto pre menu
  const blocked = (t, x) => !!t.closest('.modal, .dropdown-menu, input, textarea, select, [contenteditable], .mem-row, .sheet-tabs, .table-responsive, .col-resize, #previewBody, .no-swipe')
    || (x > 28 && !!t.closest('.row-item') && window.CloudUI && CloudUI.get('swipe', true) !== false) || document.body.classList.contains('drag-select')
    || (() => { for (let el = t; el && el !== document.body; el = el.parentElement) { if (el.scrollWidth > el.clientWidth + 4 && /(auto|scroll)/.test(getComputedStyle(el).overflowX)) return true; } return false; })();
  let x0 = 0, y0 = 0, t0 = 0, active = false, done = false;
  document.addEventListener('touchstart', (e) => {
    active = false; done = false;
    if (e.touches.length !== 1 || !narrow() || document.body.classList.contains('modal-open') && !isOpen()) return;
    const t = e.touches[0];
    if (!isOpen() && blocked(e.target, t.clientX)) return;
    x0 = t.clientX; y0 = t.clientY; t0 = Date.now(); active = true;
  }, { passive: true });
  document.addEventListener('touchmove', (e) => {
    if (!active || done) return;
    const t = e.touches[0], dx = t.clientX - x0, dy = t.clientY - y0;
    if (Math.abs(dy) > 30 && Math.abs(dy) > Math.abs(dx)) { active = false; return; } // posúvanie zoznamu
    if (Math.abs(dx) < 70 || Math.abs(dy) > Math.abs(dx) * 0.6 || Date.now() - t0 > 800) return;
    done = true;
    const oc = bootstrap.Offcanvas.getOrCreateInstance(sb);
    if (dx > 0 && !isOpen()) oc.show();
    else if (dx < 0 && isOpen()) oc.hide();
  }, { passive: true });
})();

/* Aplikácia Android: kým je otvorené okno (editor fotky, náhľad, editory, menu), potiahnutie nadol nesmie obnoviť stránku.
   Hlási to priamo web (nespolieha sa iba na skript, ktorý vkladá aplikácia) a pri dotyku v okne hneď, ešte pred ťahaním. */
(function () {
  const A = window.CloudAndroid; if (!A || !A.setRefreshBlocked) return;
  let last = null;
  const send = (b) => { if (b === last) return; last = b; try { A.setRefreshBlocked(b); } catch (e) {} };
  const open = () => document.body.classList.contains('modal-open') || !!document.querySelector('.modal.show, .offcanvas.show, .dropdown-menu.show, .ctx-menu.show, .gm-menu.show');
  const f = () => send(open());
  const start = () => { new MutationObserver(f).observe(document.body, { attributes: true, subtree: true, attributeFilter: ['class'] }); f(); };
  if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  document.addEventListener('touchstart', (e) => { if (e.target.closest && e.target.closest('.modal, .offcanvas, .ie-stage, [data-norefresh]')) { last = null; send(true); } }, { capture: true, passive: true });
  document.addEventListener('touchend', () => setTimeout(f, 50), { capture: true, passive: true });
})();
