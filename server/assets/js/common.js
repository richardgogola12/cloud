/* Spoločné funkcie */
window.Cloud = (function () {
  const base = document.querySelector('meta[name="base-url"]').content;
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  async function api(path, data = {}, asJson = false) {
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
    const icons = { success: 'check-circle', danger: 'exclamation-octagon', warning: 'exclamation-triangle', info: 'info-circle', dark: 'info-circle' };
    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 text-bg-' + type;
    el.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi bi-' + (icons[type] || 'info-circle') + ' me-2"></i></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    el.querySelector('.toast-body').append(document.createTextNode(msg));
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  function confirmBox(title, text, okText = 'Potvrdiť', danger = true) {
    return new Promise((resolve) => {
      const id = 'cf' + Date.now();
      document.body.insertAdjacentHTML('beforeend', `<div class="modal fade" id="${id}" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content"><div class="modal-header border-0 pb-0"><h5 class="modal-title"></h5></div><div class="modal-body text-body-secondary"></div><div class="modal-footer border-0"><button class="btn btn-light rounded-pill" data-bs-dismiss="modal">Zrušiť</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'} rounded-pill" data-ok></button></div></div></div></div>`);
      const el = document.getElementById(id);
      el.querySelector('.modal-title').textContent = title;
      el.querySelector('.modal-body').textContent = text;
      el.querySelector('[data-ok]').textContent = okText;
      const m = new bootstrap.Modal(el); let ok = false;
      el.querySelector('[data-ok]').onclick = () => { ok = true; m.hide(); };
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
      document.documentElement.setAttribute('data-bs-theme', n); localStorage.setItem('cloud-theme', n); setIcon();
    });
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
    // Select2
    if (window.jQuery && jQuery.fn.select2) {
      jQuery('.select2').each(function () {
        const $s = jQuery(this);
        $s.select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: $s.data('search') === false ? Infinity : 0, dropdownParent: $s.closest('.modal').length ? $s.closest('.modal') : jQuery(document.body) });
      });
    }
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

  return { api, toast, confirm: confirmBox, prompt: promptBox, formatBytes, esc, base, csrf };
})();
