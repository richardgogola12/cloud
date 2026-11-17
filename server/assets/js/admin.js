document.addEventListener('DOMContentLoaded', () => {
  // kvóta – predvoľby a "bez obmedzenia"
  document.querySelectorAll('.quota-input').forEach(box => {
    const val = box.querySelector('[name="quota_val"]'), unl = box.querySelector('.quota-unl'), unit = jQuery(box.querySelector('.quota-unit'));
    unl.addEventListener('change', () => { val.disabled = unl.checked; });
    box.querySelectorAll('.quota-preset').forEach(b => b.addEventListener('click', () => {
      unl.checked = false; val.disabled = false; val.value = b.dataset.v; unit.val(b.dataset.u).trigger('change');
    }));
  });

  // modal používateľa – nový / úprava
  const modal = document.getElementById('userModal');
  modal.addEventListener('show.bs.modal', (e) => {
    const raw = e.relatedTarget && e.relatedTarget.dataset.user;
    const form = modal.querySelector('form'), f = form.elements;
    const isNew = !raw || raw === 'new';
    const u = isNew ? { id: 0, name: '', email: '', role: 'user', is_active: 1, unlimited: false, qv: DEFAULT_QUOTA.qv, qu: DEFAULT_QUOTA.qu } : JSON.parse(raw);
    f['act'].value = isNew ? 'user_create' : 'user_save';
    f['id'].value = u.id; f['name'].value = u.name; f.email.value = u.email; f.password.value = '';
    f.is_active.checked = !!u.is_active;
    jQuery(f.role).val(u.role).trigger('change');
    f.quota_val.value = u.qv; jQuery(f.quota_unit).val(u.qu).trigger('change');
    f.unlimited.checked = !!u.unlimited; f.quota_val.disabled = !!u.unlimited;
    modal.querySelector('.modal-title').textContent = isNew ? 'Nový používateľ' : 'Upraviť používateľa';
    modal.querySelector('[data-pass-hint]').textContent = isNew ? '(prázdne = vygeneruje sa)' : '(nechaj prázdne pre nezmenené)';
    modal.querySelector('[data-new-only]').classList.toggle('d-none', !isNew);
  });

});

// hromadné akcie s používateľmi
document.addEventListener('DOMContentLoaded', () => {
  const bar = document.getElementById('bulkForm');
  if (!bar) return;
  const checks = () => [...document.querySelectorAll('.bulk-check')];
  const upd = () => {
    const n = checks().filter(c => c.checked).length;
    bar.classList.toggle('d-none', n === 0);
    document.getElementById('bulkCount').textContent = n;
    const all = document.getElementById('bulkAll');
    all.checked = n > 0 && n === checks().length; all.indeterminate = n > 0 && n < checks().length;
  };
  checks().forEach(c => c.addEventListener('change', upd));
  document.getElementById('bulkAll').addEventListener('change', (e) => { checks().forEach(c => c.checked = e.target.checked); upd(); });
  bar.addEventListener('submit', async (e) => {
    const op = e.submitter && e.submitter.value;
    if (bar.dataset.ok || !['deactivate', 'logout'].includes(op)) return;
    e.preventDefault();
    if (await Cloud.confirm('Si si istý?', op === 'logout' ? 'Vybraní používatelia budú odhlásení zo všetkých zariadení.' : 'Vybraní používatelia sa nebudú môcť prihlásiť.', 'Potvrdiť')) {
      bar.dataset.ok = 1;
      const h = document.createElement('input'); h.type = 'hidden'; h.name = 'bulk_op'; h.value = op; bar.appendChild(h);
      bar.submit();
    }
  });
});
