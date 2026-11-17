/* Administrácia → Údržba: dávkové šifrovanie a kontrola integrity */
(function () {
  const $ = (s) => document.querySelector(s);
  const call = (action, data = {}) => Cloud.api('admin.php?ajax=' + action, data);
  const esc = Cloud.esc;

  // ---- šifrovanie existujúcich súborov ----
  const encBtn = $('#encryptBtn');
  if (encBtn) encBtn.addEventListener('click', async () => {
    if (!await Cloud.confirm('Zašifrovať existujúce súbory?', 'Prebehne to po dávkach – nechaj stránku otvorenú. Pred začatím si stiahni záložný kľúč.', 'Zašifrovať', false)) return;
    encBtn.disabled = true;
    const bar = $('#encProgress'), st = $('#encStatus');
    bar.classList.remove('d-none');
    const total = parseInt($('#plainCount').textContent) || 1;
    let done = 0, errs = [];
    while (true) {
      const r = await call('encrypt');
      if (!r.ok) { st.textContent = 'Chyba: ' + r.error; break; }
      done += r.done; errs = errs.concat(r.errors || []);
      $('#plainCount').textContent = r.left;
      $('#encCount').textContent = (parseInt($('#encCount').textContent) || 0) + r.done;
      bar.firstElementChild.style.width = Math.min(100, done / total * 100) + '%';
      st.textContent = 'Zašifrované: ' + done + ' · zostáva: ' + r.left;
      if (r.finished || r.left === 0) break;
    }
    bar.classList.add('d-none');
    st.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Hotovo – zašifrovaných ' + done + ' súborov.</span>'
      + (errs.length ? '<div class="text-danger mt-1">Problémy (' + errs.length + '):<br>' + errs.slice(0, 20).map(esc).join('<br>') + '</div>' : '');
  });

  // ---- kontrola integrity ----
  const res = $('#chkResults'), st = $('#chkStatus'), prog = $('#chkProgress');
  document.querySelectorAll('[data-check]').forEach(btn => btn.addEventListener('click', async () => {
    const mode = btn.dataset.check;
    document.querySelectorAll('[data-check],#orphanBtn').forEach(b => b.disabled = true);
    prog.classList.remove('d-none'); res.innerHTML = '';
    let after = 0, checked = 0, problems = [];
    while (true) {
      const r = await call('check', { mode, after });
      if (!r.ok) { st.textContent = 'Chyba: ' + r.error; break; }
      after = r.next; checked += r.checked; problems = problems.concat(r.problems);
      st.textContent = 'Skontrolovaných ' + checked + ' súborov · problémy: ' + problems.length;
      if (r.finished) break;
    }
    prog.classList.add('d-none');
    document.querySelectorAll('[data-check],#orphanBtn').forEach(b => b.disabled = false);
    call('check_done', { mode, checked, problems: problems.length });
    if (!problems.length) { st.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Všetkých ' + checked + ' súborov je v poriadku.</span>'; return; }
    st.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Skontrolovaných ' + checked + ', problémov: ' + problems.length + '</span>';
    const missing = problems.filter(p => p.missing);
    res.innerHTML = '<div class="table-responsive" style="max-height:320px"><table class="table table-sm small mb-2"><thead><tr><th>Súbor</th><th>Vlastník</th><th>Problém</th></tr></thead><tbody>'
      + problems.map(p => '<tr><td class="text-break">' + esc(p.file) + '</td><td>' + esc(p.owner) + '</td><td class="text-danger">' + esc(p.problem) + '</td></tr>').join('')
      + '</tbody></table></div>'
      + (missing.length ? '<button class="btn btn-sm btn-outline-danger rounded-pill" id="rmMissing">Odstrániť ' + missing.length + ' záznamov bez dát</button>' : '');
    const rm = $('#rmMissing');
    if (rm) rm.onclick = async () => {
      if (!await Cloud.confirm('Odstrániť záznamy?', 'Súbory, ktorých dáta na disku chýbajú, sa odstránia z databázy (používatelia ich prestanú vidieť).', 'Odstrániť')) return;
      const r = await call('remove_missing', { items: missing.map(p => p.kind + ':' + p.id) });
      r.ok ? (Cloud.toast('Odstránených ' + r.removed + ' záznamov', 'success'), rm.remove()) : Cloud.toast(r.error, 'danger');
    };
  }));

  // ---- nepoužívané (osirelé) súbory na disku ----
  const ob = $('#orphanBtn');
  if (ob) ob.addEventListener('click', async () => {
    st.textContent = 'Hľadám nepoužívané súbory…'; res.innerHTML = '';
    const r = await call('orphans');
    if (!r.ok) { st.textContent = 'Chyba: ' + r.error; return; }
    if (!r.count) { st.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Na disku nie sú žiadne nepoužívané súbory.</span>'; return; }
    st.innerHTML = 'Nájdených <b>' + r.count + '</b> súborov bez záznamu v databáze (' + esc(r.bytes) + ').';
    res.innerHTML = '<div class="small text-body-secondary mb-2" style="max-height:160px;overflow:auto">' + r.items.map(i => esc(i.path) + ' · ' + Cloud.formatBytes(i.size)).join('<br>') + (r.count > r.items.length ? '<br>…' : '') + '</div>'
      + '<button class="btn btn-sm btn-outline-danger rounded-pill" id="rmOrphans">Zmazať nepoužívané súbory (' + esc(r.bytes) + ')</button>';
    $('#rmOrphans').onclick = async () => {
      if (!await Cloud.confirm('Zmazať nepoužívané súbory?', 'Tieto súbory nepatria žiadnemu záznamu a nikto ich nevidí. Akcia je nevratná.', 'Zmazať')) return;
      const x = await call('orphans_delete');
      x.ok ? (Cloud.toast('Zmazaných ' + x.deleted + ' súborov', 'success'), res.innerHTML = '', st.textContent = '') : Cloud.toast(x.error, 'danger');
    };
  });
})();
