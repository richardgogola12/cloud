/* Webový SSH terminál (Administrácia → Terminál) */
(() => {
  const form = document.getElementById('termForm');
  if (!form) return;
  const $ = (s) => document.querySelector(s);
  const wrap = $('#termWrap'), box = $('#term'), dot = $('#termDot'), title = $('#termTitle');
  const enc = new TextEncoder();
  const b64 = (bytes) => { let s = ''; for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000)); return btoa(s); };
  const unb64 = (s) => Uint8Array.from(atob(s), c => c.charCodeAt(0));
  let term = null, fit = null, sid = null, off = 0, alive = false, sending = false, queue = [], lastSize = '';

  function status(state, text) { dot.className = 'term-dot ' + state; title.textContent = text; }
  function api(op, data) { return Cloud.api('term.php?op=' + op, data); }

  function ensureTerm() {
    if (term) return;
    if (!window.Terminal) { Cloud.toast('Knižnicu terminálu sa nepodarilo načítať.', 'danger'); throw new Error('xterm'); }
    term = new Terminal({ cursorBlink: true, fontSize: innerWidth < 576 ? 12 : 14, scrollback: 5000, convertEol: false,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "DejaVu Sans Mono", monospace',
      theme: { background: '#1e1f24', foreground: '#e6e6e6', cursor: '#8ab4f8', selectionBackground: '#3b4b6b' } });
    fit = new FitAddon.FitAddon();
    term.loadAddon(fit);
    term.open(box);
    term.onData((d) => send(enc.encode(d)));
    term.onBinary((d) => send(Uint8Array.from(d, c => c.charCodeAt(0) & 255)));
    new ResizeObserver(() => refit()).observe(box);
  }
  let rzT;
  function refit() {
    if (!term || wrap.classList.contains('d-none')) return;
    try { fit.fit(); } catch (e) { return; }
    const sz = term.rows + 'x' + term.cols;
    if (sz === lastSize || !sid) return;
    lastSize = sz;
    clearTimeout(rzT); rzT = setTimeout(() => sid && api('resize', { id: sid, rows: term.rows, cols: term.cols }), 150);
  }
  async function send(bytes) {
    if (!sid || !alive) return;
    queue.push(bytes);
    if (sending) return;
    sending = true;
    while (queue.length && sid) {
      const all = new Uint8Array(queue.reduce((n, b) => n + b.length, 0));
      let o = 0; queue.forEach(b => { all.set(b, o); o += b.length; }); queue = [];
      const r = await api('write', { id: sid, data: b64(all) });
      if (!r.ok) { status('err', r.error || 'Chyba'); break; }
    }
    sending = false;
  }
  async function readLoop(id) {
    let fails = 0;
    while (sid === id) {
      const r = await api('read', { id, off });
      if (sid !== id) return;
      if (!r.ok) {
        if (++fails > 5 || /neexistuje/.test(r.error || '')) { ended(r.error); return; }
        await new Promise(res => setTimeout(res, 1000)); continue;
      }
      fails = 0;
      if (r.data) term.write(unb64(r.data));
      off = r.off;
      if (r.ended) { ended(); return; }
    }
  }
  function ended(err) {
    alive = false; sid = null;
    status('off', err ? 'Odpojené – ' + err : 'Spojenie ukončené');
    term && term.write('\r\n\x1b[90m[Spojenie ukončené – stlač Enter pre nové pripojenie]\x1b[0m\r\n');
    $('#termConnect').classList.remove('d-none');
    const once = term.onData((d) => { if (d === '\r' && !sid) { once.dispose(); connect(); } });
  }
  async function connect() {
    const user = $('#termUser').value.trim(), host = $('#termHost').value.trim() || '127.0.0.1', port = $('#termPort').value || 22;
    if (!user) { $('#termUser').focus(); return; }
    const btn = $('#termGo'); btn.disabled = true;
    try {
      const pw = $('#termPass');
      if (!$('#termPassWrap').classList.contains('d-none')) {
        if (!pw.value) { pw.focus(); return; }
        const u = await api('unlock', { password: pw.value });
        pw.value = '';
        if (!u.ok) { Cloud.toast(u.error, 'danger'); pw.focus(); return; }
        $('#termPassWrap').classList.add('d-none');
      }
      wrap.classList.remove('d-none');
      ensureTerm();
      refit();
      const r = await api('start', { user, host, port, rows: term.rows, cols: term.cols });
      if (!r.ok) {
        if (r.locked) { $('#termPassWrap').classList.remove('d-none'); pw.focus(); }
        Cloud.toast(r.error, 'danger'); return;
      }
      term.reset();
      sid = r.id; off = 0; alive = true; lastSize = term.rows + 'x' + term.cols;
      status('on', user + '@' + host + (String(port) !== '22' ? ':' + port : ''));
      $('#termConnect').classList.add('d-none');
      term.focus();
      readLoop(sid);
    } finally { btn.disabled = false; }
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); connect(); });
  $('#termClose').addEventListener('click', async () => {
    if (sid) { const id = sid; await api('close', { id }); }
  });
  $('#termFull').addEventListener('click', () => {
    wrap.classList.toggle('term-full');
    document.body.classList.toggle('overflow-hidden', wrap.classList.contains('term-full'));
    setTimeout(refit, 50); term && term.focus();
  });
  const KEYS = { esc: '\x1b', tab: '\t', ctrlc: '\x03', up: '\x1b[A' };
  wrap.querySelectorAll('[data-key]').forEach(b => b.addEventListener('click', () => { send(enc.encode(KEYS[b.dataset.key])); term && term.focus(); }));
  addEventListener('beforeunload', () => { if (sid) navigator.sendBeacon && navigator.sendBeacon(Cloud.base + 'term.php?op=close', (() => { const f = new FormData(); f.append('id', sid); f.append('_csrf', Cloud.csrf); return f; })()); });
})();
