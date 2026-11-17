/* Prihlásenie odtlačkom prsta (WebAuthn / passkeys) */
(function () {
  const b64uToBuf = (s) => {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    const bin = atob(s), buf = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  };
  const bufToB64u = (buf) => {
    const bytes = new Uint8Array(buf); let s = '';
    for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  const post = (action, data) => Cloud.api('passkey.php?action=' + action, data, true);

  const Passkey = {
    async supported() {
      if (!window.PublicKeyCredential || !window.isSecureContext) return false;
      try { return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch (e) { return false; }
    },

    // výzvu načítame vopred – Safari/iOS vyžaduje, aby sa biometria spustila hneď po ťuknutí
    _pre: {},
    prefetch(kind, data = {}) {
      this._pre[kind] = post(kind === 'login' ? 'login_options' : 'register_options', data);
      this._preData = data;
      this._pre[kind].catch(() => {});
    },
    async _options(kind, data) {
      const p = this._pre[kind] || post(kind === 'login' ? 'login_options' : 'register_options', data);
      delete this._pre[kind];
      const r = await p;
      if (!r.ok) throw new Error(r.error);
      return r.options;
    },

    async login(email) {
      // ak je vyplnený e-mail, výzva obsahuje len jeho kľúče (inak "discoverable" passkey)
      const o = await this._options('login', { email: email || '' });
      o.challenge = b64uToBuf(o.challenge);
      (o.allowCredentials || []).forEach(c => c.id = b64uToBuf(c.id));
      const cred = await navigator.credentials.get({ publicKey: o });
      const res = await post('login_verify', {
        id: cred.id,
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        authenticatorData: bufToB64u(cred.response.authenticatorData),
        signature: bufToB64u(cred.response.signature),
        userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null,
      });
      if (!res.ok) throw new Error(res.error);
      return res;
    },

    async register(name) {
      const o = await this._options('register', {});
      o.challenge = b64uToBuf(o.challenge);
      o.user.id = b64uToBuf(o.user.id);
      (o.excludeCredentials || []).forEach(c => c.id = b64uToBuf(c.id));
      const cred = await navigator.credentials.create({ publicKey: o });
      const res = await post('register_verify', {
        name,
        clientDataJSON: bufToB64u(cred.response.clientDataJSON),
        attestationObject: bufToB64u(cred.response.attestationObject),
      });
      if (!res.ok) throw new Error(res.error);
      return res;
    },

    deviceName() {
      const ua = navigator.userAgent;
      if (/iPhone/.test(ua)) return 'iPhone';
      if (/iPad/.test(ua)) return 'iPad';
      if (/Android/.test(ua)) return 'Android';
      if (/Mac/.test(ua)) return 'Mac';
      if (/Windows/.test(ua)) return 'Windows';
      return 'Zariadenie';
    },
    errorText(e) {
      if (e && e.name === 'NotAllowedError') return 'Overenie bolo zrušené alebo vypršal čas.';
      if (e && e.name === 'InvalidStateError') return 'Toto zariadenie je už pridané.';
      return (e && e.message) || 'Overenie zlyhalo.';
    },
  };
  window.Passkey = Passkey;

  // --- prihlasovacia stránka ---
  document.addEventListener('DOMContentLoaded', async () => {
    const box = document.getElementById('passkeyLogin');
    if (box && await Passkey.supported()) {
      box.classList.remove('d-none');
      const emailEl = document.getElementById('email');
      Passkey.prefetch('login', { email: emailEl.value.trim() });
      emailEl.addEventListener('change', () => Passkey.prefetch('login', { email: emailEl.value.trim() }));
      document.getElementById('btnPasskeyLogin').addEventListener('click', async (ev) => {
        const btn = ev.currentTarget; btn.disabled = true;
        try {
          const email = document.getElementById('email').value.trim();
          const r = await Passkey.login(email);
          location.href = r.redirect;
        } catch (e) {
          Cloud.toast(Passkey.errorText(e), 'danger');
          Passkey.prefetch('login', { email: emailEl.value.trim() });
          btn.disabled = false;
        }
      });
    }

    // --- ponuka po prihlásení heslom ---
    const offer = document.getElementById('passkeyOffer');
    if (offer && await Passkey.supported()) {
      offer.classList.remove('d-none');
      Passkey.prefetch('register');
      offer.querySelector('[data-pk-add]').addEventListener('click', async () => {
        try { await Passkey.register(Passkey.deviceName()); offer.remove(); Cloud.toast('Odtlačok prsta bol pridaný. Nabudúce sa prihlásiš jedným dotykom.', 'success'); }
        catch (e) { Cloud.toast(Passkey.errorText(e), 'danger'); Passkey.prefetch('register'); }
      });
      offer.querySelector('[data-pk-dismiss]').addEventListener('click', () => { post('dismiss', {}); offer.remove(); });
    }
  });
})();
