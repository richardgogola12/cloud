<?php /* Nahrávanie cez verejný odkaz – použité v s.php ($base, $mode, $curId, csrf) */ ?>
<div class="card-soft p-3 p-sm-4 mb-3 share-upload" id="shUp">
  <?php if ($mode === 'upload'): ?>
  <div class="mb-3" style="max-width:360px">
    <label class="form-label small text-body-secondary" for="upGuest">Tvoje meno (voliteľné – súbory sa uložia do zložky s menom)</label>
    <input class="form-control" id="upGuest" maxlength="60" autocomplete="name" placeholder="napr. Jana">
  </div>
  <?php endif; ?>
  <label class="share-drop" id="upDrop">
    <input type="file" id="upInput" multiple hidden>
    <i class="bi bi-cloud-arrow-up display-5 text-primary"></i>
    <span class="fw-semibold mt-2">Vyber súbory alebo ich sem pretiahni</span>
    <span class="small text-body-secondary">Nahrávanie pokračuje po častiach – aj veľké súbory</span>
  </label>
  <ul class="list-unstyled mb-0 mt-3" id="upList"></ul>
</div>
<script>
(() => {
  const base = <?= json_encode(url($base)) ?>, csrf = <?= json_encode(csrf_token()) ?>, folder = <?= (int)$curId ?>;
  const list = document.getElementById('upList'), input = document.getElementById('upInput'), drop = document.getElementById('upDrop');
  const guest = () => (document.getElementById('upGuest') || {}).value || '';
  const esc = (s) => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (b) => b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(0) + ' kB' : b < 1073741824 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : (b / 1073741824).toFixed(2).replace('.', ',') + ' GB';
  async function post(step, data, file) {
    const fd = new FormData(); fd.append('_csrf', csrf);
    for (const k in data) fd.append(k, data[k]);
    if (file) fd.append('chunk', file, 'chunk');
    const r = await fetch(base + '&up=' + step, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const j = await r.json().catch(() => ({ ok: false, error: 'Chyba servera (' + r.status + ')' }));
    if (!j.ok) throw new Error(j.error || 'Chyba');
    return j;
  }
  let queue = [], busy = false;
  function add(files) {
    for (const f of files) {
      const li = document.createElement('li');
      li.className = 'share-up-item';
      li.innerHTML = '<div class="d-flex align-items-center gap-2"><i class="bi bi-file-earmark"></i><span class="text-truncate flex-grow-1">' + esc(f.name) + '</span><span class="small text-body-secondary st">' + fmt(f.size) + '</span></div><div class="progress mt-1" style="height:4px"><div class="progress-bar" style="width:0%"></div></div>';
      list.appendChild(li);
      queue.push({ f, li });
    }
    run();
  }
  async function run() {
    if (busy) return; busy = true;
    let okCount = 0;
    while (queue.length) {
      const { f, li } = queue.shift();
      const bar = li.querySelector('.progress-bar'), st = li.querySelector('.st');
      try {
        const init = await post('init', { name: f.name, size: f.size, f: folder, guest: guest() });
        let off = 0, tries = 0;
        while (true) {
          const part = f.slice(off, off + init.chunk);
          let r;
          try { r = await post('chunk', { id: init.id, offset: off }, part); tries = 0; }
          catch (e) { if (++tries > 4) throw e; await new Promise(res => setTimeout(res, 1500 * tries)); continue; }
          off = r.received;
          bar.style.width = (f.size ? Math.round(off / f.size * 100) : 100) + '%';
          if (r.done) { st.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> nahraté</span>'; okCount++; break; }
        }
        bar.classList.add('bg-success');
      } catch (e) {
        bar.classList.add('bg-danger'); bar.style.width = '100%';
        st.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>';
      }
    }
    busy = false;
    if (okCount) post('done', { guest: guest() }).catch(() => {}).finally(() => { <?= $mode === 'both' ? 'setTimeout(() => location.reload(), 800);' : '' ?> });
  }
  input.addEventListener('change', () => { add(input.files); input.value = ''; });
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
  drop.addEventListener('drop', e => add(e.dataTransfer.files));
  addEventListener('beforeunload', e => { if (busy) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
