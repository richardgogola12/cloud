document.addEventListener('DOMContentLoaded', async () => {
  const add = document.getElementById('pkAdd'), nameEl = document.getElementById('pkName');
  if (!add) return;
  nameEl.value = Passkey.deviceName();
  if (!(await Passkey.supported())) { add.disabled = nameEl.disabled = true; document.getElementById('pkUnsupported').classList.remove('d-none'); return; }
  Passkey.prefetch('register'); // biometria sa musí spustiť hneď po kliknutí (Safari/iOS)
  add.addEventListener('click', async () => {
    add.disabled = true;
    try { await Passkey.register(nameEl.value.trim() || Passkey.deviceName()); Cloud.toast('Zariadenie pridané', 'success'); setTimeout(() => location.reload(), 700); }
    catch (e) { Cloud.toast(Passkey.errorText(e), 'danger'); Passkey.prefetch('register'); add.disabled = false; }
  });
  document.querySelectorAll('[data-pk-del]').forEach(b => b.addEventListener('click', async () => {
    if (!await Cloud.confirm('Odstrániť zariadenie?', 'Na tomto zariadení sa už nebudeš môcť prihlásiť odtlačkom.', 'Odstrániť')) return;
    const r = await Cloud.api('passkey.php?action=delete', { id: +b.dataset.pkDel }, true);
    r.ok ? b.closest('li').remove() : Cloud.toast(r.error, 'danger');
  }));
});
