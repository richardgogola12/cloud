/* Prehrávač videa Cloudu: vlastné ovládanie (rýchlosť, titulky, obraz v obraze, celá obrazovka),
   pokračovanie od miesta, kde si skončil (pamätá si server, nie zariadenie), gestá v mobile a klávesové skratky.
   Použitie: const p = CloudVideo.mount(element, { src, poster, name, id, subs: [{ url, label, srt }] }); … p.destroy(); */
(function () {
  const fmt = (s) => {
    if (!isFinite(s) || s < 0) s = 0;
    s = Math.floor(s);
    const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = String(s % 60).padStart(2, '0');
    return h ? h + ':' + String(m).padStart(2, '0') + ':' + x : m + ':' + x;
  };
  const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
  const ICON = (n) => '<i class="bi bi-' + n + '"></i>';
  let vol = 1, muted = false, speed = 1; // platí kým je stránka otvorená
  const touch = () => matchMedia('(hover: none)').matches;

  function srtToVtt(t) {
    return 'WEBVTT\n\n' + t.replace(/\r/g, '').replace(/^\d+\n(?=\d\d:)/gm, '').replace(/(\d\d:\d\d:\d\d),(\d\d\d)/g, '$1.$2');
  }

  function mount(host, o) {
    host.innerHTML = '<div class="cv" tabindex="0" aria-label="Prehrávač videa">'
      + '<video playsinline preload="metadata"' + (o.poster ? ' poster="' + o.poster + '"' : '') + '></video>'
      + '<div class="cv-tap left"></div><div class="cv-tap right"></div>'
      + '<div class="cv-ripple left">' + ICON('rewind-fill') + '<span>10 s</span></div><div class="cv-ripple right">' + ICON('fast-forward-fill') + '<span>10 s</span></div>'
      + '<div class="cv-spin spinner-border text-light"></div>'
      + '<button class="cv-big" aria-label="Prehrať">' + ICON('play-fill') + '</button>'
      + '<div class="cv-msg d-none"></div>'
      + '<div class="cv-resume d-none"></div>'
      + '<div class="cv-osd"></div>'
      + '<div class="cv-ctrl">'
      + '  <div class="cv-seek"><div class="cv-rail"><div class="cv-buf"></div><div class="cv-played"></div><div class="cv-knob"></div></div><div class="cv-tip">0:00</div></div>'
      + '  <div class="cv-row">'
      + '    <button class="cv-b" data-cv="play" title="Prehrať / pozastaviť (K, medzerník)">' + ICON('play-fill') + '</button>'
      + '    <button class="cv-b" data-cv="back" title="Späť o 10 s (J)">' + ICON('arrow-counterclockwise') + '</button>'
      + '    <button class="cv-b" data-cv="fwd" title="Dopredu o 10 s (L)">' + ICON('arrow-clockwise') + '</button>'
      + '    <div class="cv-vol"><button class="cv-b" data-cv="mute" title="Stlmiť (M)">' + ICON('volume-up-fill') + '</button><input type="range" min="0" max="1" step="0.05" aria-label="Hlasitosť"></div>'
      + '    <span class="cv-time"><span class="cv-cur">0:00</span> / <span class="cv-dur">0:00</span></span>'
      + '    <span class="ms-auto"></span>'
      + '    <button class="cv-b cv-cc d-none" data-cv="cc" title="Titulky (C)">' + ICON('badge-cc') + '</button>'
      + '    <div class="dropup"><button class="cv-b cv-speed" data-bs-toggle="dropdown" title="Rýchlosť (&lt; &gt;)">1×</button><ul class="dropdown-menu dropdown-menu-end cv-menu">'
      + SPEEDS.map(s => '<li><button class="dropdown-item" data-speed="' + s + '">' + (s === 1 ? 'Normálna' : String(s).replace('.', ',') + '×') + '</button></li>').join('')
      + '      <li><hr class="dropdown-divider"></li><li><button class="dropdown-item" data-cv="loop"><i class="bi bi-repeat me-2"></i>Opakovať</button></li></ul></div>'
      + '    <button class="cv-b cv-pip" data-cv="pip" title="Obraz v obraze (I)">' + ICON('pip') + '</button>'
      + '    <button class="cv-b" data-cv="fs" title="Celá obrazovka (F)">' + ICON('fullscreen') + '</button>'
      + '  </div>'
      + '</div></div>';
    const root = host.firstChild, v = root.querySelector('video'), q = (s) => root.querySelector(s);
    const ctrl = q('.cv-ctrl'), seek = q('.cv-seek'), rail = q('.cv-rail'), tip = q('.cv-tip'), volIn = q('.cv-vol input');
    let hideT = null, saveT = null, dead = false, dragging = false, lastSaved = -1, ccOn = false, resumeAt = 0;

    v.volume = vol; v.muted = muted; v.playbackRate = speed;
    if (!document.pictureInPictureEnabled || v.disablePictureInPicture) q('.cv-pip').classList.add('d-none');
    if (touch()) q('.cv-vol input').classList.add('d-none');

    /* ---------- titulky (súbor .vtt / .srt s rovnakým názvom v tej istej zložke) ---------- */
    (o.subs || []).forEach(async (s, i) => {
      try {
        let url = s.url;
        if (s.srt) { const t = await (await fetch(s.url, { credentials: 'same-origin' })).text(); url = URL.createObjectURL(new Blob([srtToVtt(t)], { type: 'text/vtt' })); }
        const tr = document.createElement('track');
        tr.kind = 'subtitles'; tr.label = s.label || 'Titulky'; tr.srclang = 'sk'; tr.src = url;
        v.appendChild(tr);
        q('.cv-cc').classList.remove('d-none');
        if (i === 0) { ccOn = true; setTimeout(() => setCC(true), 50); }
      } catch (e) {}
    });
    function setCC(on) {
      ccOn = on;
      [...v.textTracks].forEach((t, i) => { t.mode = on && i === 0 ? 'showing' : 'disabled'; });
      q('.cv-cc').classList.toggle('on', on);
    }

    /* ---------- zobrazenie ovládania ---------- */
    const show = () => {
      root.classList.add('ui');
      clearTimeout(hideT);
      if (!v.paused && !dragging) hideT = setTimeout(() => { if (!root.querySelector('.cv-menu.show')) root.classList.remove('ui'); }, touch() ? 3000 : 2200);
    };
    const osd = (html) => { const e = q('.cv-osd'); e.innerHTML = html; e.classList.remove('on'); void e.offsetWidth; e.classList.add('on'); };
    function paint() {
      const d = v.duration || 0, t = dragging ? +rail.dataset.t : v.currentTime;
      q('.cv-played').style.width = d ? (t / d * 100) + '%' : 0;
      q('.cv-knob').style.left = d ? (t / d * 100) + '%' : 0;
      q('.cv-cur').textContent = fmt(t);
      let b = 0;
      for (let i = 0; i < v.buffered.length; i++) if (v.buffered.start(i) <= v.currentTime + 1) b = Math.max(b, v.buffered.end(i));
      q('.cv-buf').style.width = d ? Math.min(100, b / d * 100) + '%' : 0;
    }
    function state() {
      const p = v.paused;
      root.classList.toggle('paused', p);
      q('[data-cv="play"]').innerHTML = ICON(v.ended ? 'arrow-repeat' : p ? 'play-fill' : 'pause-fill');
      q('.cv-big').innerHTML = ICON(v.ended ? 'arrow-repeat' : 'play-fill');
      q('[data-cv="mute"]').innerHTML = ICON(v.muted || v.volume === 0 ? 'volume-mute-fill' : v.volume < 0.5 ? 'volume-down-fill' : 'volume-up-fill');
      volIn.value = v.muted ? 0 : v.volume;
      volIn.style.setProperty('--p', (v.muted ? 0 : v.volume) * 100 + '%');
      q('.cv-speed').textContent = String(v.playbackRate).replace('.', ',') + '×';
      root.querySelectorAll('[data-speed]').forEach(b => b.classList.toggle('active', +b.dataset.speed === v.playbackRate));
      q('[data-cv="loop"]').classList.toggle('active', v.loop);
      const fs = document.fullscreenElement === root || document.webkitFullscreenElement === root;
      q('[data-cv="fs"]').innerHTML = ICON(fs ? 'fullscreen-exit' : 'fullscreen');
      show();
    }

    /* ---------- akcie ---------- */
    const toggle = () => { if (v.paused || v.ended) { q('.cv-resume').classList.add('d-none'); v.play().catch(() => {}); } else v.pause(); };
    const skip = (s) => { v.currentTime = Math.max(0, Math.min((v.duration || 0) - 0.1, v.currentTime + s)); paint(); osd(ICON(s < 0 ? 'rewind-fill' : 'fast-forward-fill') + ' ' + Math.abs(s) + ' s'); show(); };
    const setVol = (x) => { v.volume = vol = Math.max(0, Math.min(1, x)); v.muted = muted = vol === 0; osd(ICON(muted ? 'volume-mute-fill' : 'volume-up-fill') + ' ' + Math.round(vol * 100) + ' %'); };
    const setSpeed = (s) => { v.playbackRate = speed = s; osd(String(s).replace('.', ',') + '×'); };
    async function fullscreen() {
      try {
        if (document.fullscreenElement || document.webkitFullscreenElement) { await (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
        if (root.requestFullscreen) await root.requestFullscreen({ navigationUI: 'hide' });
        else if (root.webkitRequestFullscreen) root.webkitRequestFullscreen();
        else if (v.webkitEnterFullscreen) v.webkitEnterFullscreen(); // iPhone
        // v mobile otočiť na šírku, ak je video na šírku
        if (touch() && v.videoWidth > v.videoHeight && screen.orientation?.lock) screen.orientation.lock('landscape').catch(() => {});
      } catch (e) {}
    }
    async function pip() {
      try { if (document.pictureInPictureElement) await document.exitPictureInPicture(); else await v.requestPictureInPicture(); } catch (e) {}
    }

    root.addEventListener('click', (e) => {
      const b = e.target.closest('[data-cv], [data-speed], .cv-big, .cv-resume button');
      if (!b) return;
      e.stopPropagation();
      if (b.dataset.speed) return setSpeed(+b.dataset.speed);
      if (b.classList.contains('cv-big')) return toggle();
      if (b.dataset.resume !== undefined) { v.currentTime = +b.dataset.resume; q('.cv-resume').classList.add('d-none'); v.play().catch(() => {}); return; }
      if (b.dataset.restart !== undefined) { v.currentTime = 0; q('.cv-resume').classList.add('d-none'); forget(); v.play().catch(() => {}); return; }
      ({ play: toggle, back: () => skip(-10), fwd: () => skip(10), mute: () => { v.muted = muted = !v.muted; if (!v.muted && v.volume === 0) v.volume = vol = 0.5; },
         fs: fullscreen, pip, cc: () => setCC(!ccOn), loop: () => { v.loop = !v.loop; osd(ICON('repeat') + (v.loop ? ' Opakovať' : ' Neopakovať')); state(); } })[b.dataset.cv]?.();
    });
    volIn.addEventListener('input', () => { v.volume = vol = +volIn.value; v.muted = muted = vol === 0; });

    // kliknutie na obraz = prehrať/pozastaviť (počítač), ťuknutie = ukázať ovládanie (mobil), dvojklik = celá obrazovka / dvojťuk po stranách = ±10 s
    let tapT = null, lastTap = 0;
    root.querySelectorAll('.cv-tap').forEach(z => z.addEventListener('click', (e) => {
      e.stopPropagation();
      const now = Date.now(), dbl = now - lastTap < 300; lastTap = now;
      if (touch()) {
        if (dbl) {
          clearTimeout(tapT);
          const left = z.classList.contains('left');
          skip(left ? -10 : 10);
          const r = q('.cv-ripple.' + (left ? 'left' : 'right')); r.classList.remove('on'); void r.offsetWidth; r.classList.add('on');
          return;
        }
        tapT = setTimeout(() => { if (root.classList.contains('ui') && !v.paused) root.classList.remove('ui'); else show(); }, 280);
      } else {
        if (dbl) { clearTimeout(tapT); fullscreen(); return; }
        tapT = setTimeout(toggle, 220);
      }
    }));
    root.addEventListener('mousemove', show);
    root.addEventListener('mouseleave', () => { if (!v.paused) root.classList.remove('ui'); });

    /* ---------- posuvník ---------- */
    const posAt = (x) => { const r = rail.getBoundingClientRect(); return Math.max(0, Math.min(1, (x - r.left) / r.width)) * (v.duration || 0); };
    const tipAt = (x) => { const r = seek.getBoundingClientRect(), t = posAt(x); tip.textContent = fmt(t); tip.style.left = Math.max(24, Math.min(r.width - 24, x - r.left)) + 'px'; };
    seek.addEventListener('pointermove', (e) => { tipAt(e.clientX); if (dragging) { rail.dataset.t = posAt(e.clientX); paint(); } });
    seek.addEventListener('pointerdown', (e) => {
      e.preventDefault(); e.stopPropagation();
      dragging = true; seek.setPointerCapture(e.pointerId); seek.classList.add('drag');
      rail.dataset.t = posAt(e.clientX); tipAt(e.clientX); paint(); show();
    });
    const endDrag = (e) => {
      if (!dragging) return;
      dragging = false; seek.classList.remove('drag');
      v.currentTime = posAt(e.clientX); q('.cv-resume').classList.add('d-none'); paint(); show();
    };
    seek.addEventListener('pointerup', endDrag);
    seek.addEventListener('pointercancel', () => { dragging = false; seek.classList.remove('drag'); });
    seek.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true }); // posúvanie neprepne na ďalší súbor

    /* ---------- udalosti videa ---------- */
    ['play', 'pause', 'ended', 'volumechange', 'ratechange'].forEach(ev => v.addEventListener(ev, state));
    v.addEventListener('timeupdate', () => { if (!dragging) paint(); });
    v.addEventListener('progress', paint);
    v.addEventListener('loadedmetadata', () => { q('.cv-dur').textContent = fmt(v.duration); paint(); root.classList.toggle('portrait', v.videoHeight > v.videoWidth); });
    v.addEventListener('waiting', () => root.classList.add('busy'));
    ['playing', 'canplay', 'seeked', 'pause'].forEach(ev => v.addEventListener(ev, () => root.classList.remove('busy')));
    v.addEventListener('error', () => {
      root.classList.remove('busy'); root.classList.add('failed');
      const m = q('.cv-msg'); m.classList.remove('d-none');
      m.innerHTML = '<i class="bi bi-film d-block mb-2" style="font-size:2.5rem"></i><b>Toto video prehliadač nevie prehrať.</b>'
        + '<div class="small text-white-50 mt-1 mb-3">Formát alebo kodek (napr. HEVC/H.265, MKV, AVI) tento prehliadač nepodporuje.</div>'
        + (o.openApp ? '<a class="btn btn-primary btn-sm rounded-pill px-3 me-2" href="' + o.openApp + '"><i class="bi bi-box-arrow-up-right me-1"></i>Otvoriť v aplikácii</a>' : '')
        + (o.download ? '<a class="btn btn-light btn-sm rounded-pill px-3" href="' + o.download + '"><i class="bi bi-download me-1"></i>Stiahnuť</a>' : '');
    });
    document.addEventListener('fullscreenchange', state);
    v.addEventListener('ended', () => { forget(); if (o.onEnded) o.onEnded(); });

    /* ---------- pokračovanie od posledného miesta (na serveri, nie v zariadení) ---------- */
    const api = (data) => window.Cloud && Cloud.api ? Cloud.api('api.php?action=video_pos', data) : Promise.resolve({});
    function save(force) {
      if (!o.id || !v.duration || v.duration < 60) return;
      const t = Math.floor(v.currentTime);
      if (!force && Math.abs(t - lastSaved) < 10) return;
      lastSaved = t;
      const near = t < 15 || t > v.duration - 20;
      api({ id: o.id, pos: near ? 0 : t, dur: Math.floor(v.duration) }).catch(() => {});
    }
    const forget = () => { if (o.id && lastSaved !== 0) { lastSaved = 0; api({ id: o.id, pos: 0 }).catch(() => {}); } };
    if (o.id) {
      api({ id: o.id }).then(r => {
        if (dead || !r || !r.pos || r.pos < 15) return;
        resumeAt = r.pos;
        const box = q('.cv-resume');
        box.innerHTML = '<span>Naposledy si skončil na <b>' + fmt(r.pos) + '</b></span>'
          + '<button class="btn btn-primary btn-sm rounded-pill px-3" data-resume="' + r.pos + '">' + ICON('play-fill') + ' Pokračovať</button>'
          + '<button class="btn btn-outline-light btn-sm rounded-pill px-3" data-restart>Od začiatku</button>';
        box.classList.remove('d-none');
        if (v.currentTime < 3) { v.pause(); v.currentTime = r.pos; }
      }).catch(() => {});
      saveT = setInterval(() => { if (!v.paused) save(); }, 5000);
      v.addEventListener('pause', () => save(true));
    }

    /* ---------- systémové ovládanie (zamknutá obrazovka, slúchadlá) ---------- */
    if ('mediaSession' in navigator) {
      try {
        navigator.mediaSession.metadata = new MediaMetadata({ title: o.name || 'Video', artist: 'Cloud', artwork: o.poster ? [{ src: o.poster, sizes: '400x400' }] : [] });
        navigator.mediaSession.setActionHandler('seekbackward', () => skip(-10));
        navigator.mediaSession.setActionHandler('seekforward', () => skip(10));
        navigator.mediaSession.setActionHandler('play', () => v.play());
        navigator.mediaSession.setActionHandler('pause', () => v.pause());
      } catch (e) {}
    }

    /* ---------- klávesnica (má prednosť pred listovaním v náhľade) ---------- */
    function key(e) {
      if (dead || !root.isConnected || e.target.matches('input:not([type=range]), textarea, [contenteditable]')) return;
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const k = e.key, low = k.length === 1 ? k.toLowerCase() : k;
      const map = {
        ' ': toggle, k: toggle, j: () => skip(-10), l: () => skip(10), ArrowLeft: () => skip(-5), ArrowRight: () => skip(5),
        ArrowUp: () => setVol(v.volume + 0.1), ArrowDown: () => setVol(v.volume - 0.1), m: () => { v.muted = muted = !v.muted; },
        f: fullscreen, i: pip, c: () => setCC(!ccOn), Home: () => { v.currentTime = 0; }, End: () => { v.currentTime = v.duration - 1; },
        '<': () => setSpeed(SPEEDS[Math.max(0, SPEEDS.indexOf(v.playbackRate) - 1)] || 1), '>': () => setSpeed(SPEEDS[Math.min(SPEEDS.length - 1, SPEEDS.indexOf(v.playbackRate) + 1)] || 1),
        ',': () => { if (v.paused) v.currentTime -= 1 / 30; }, '.': () => { if (v.paused) v.currentTime += 1 / 30; },
      };
      if (e.shiftKey && (k === 'ArrowLeft' || k === 'ArrowRight')) return; // Shift+šípka = ďalší / predchádzajúci súbor
      let fn = map[k] || map[low];
      if (!fn && /^[0-9]$/.test(k)) fn = () => { v.currentTime = v.duration * (+k / 10); };
      if (!fn) return;
      e.preventDefault(); e.stopImmediatePropagation();
      fn(); show();
    }
    document.addEventListener('keydown', key, true);

    v.src = o.src;
    if (o.autoplay !== false) v.play().catch(() => state());
    state();
    setTimeout(() => root.focus({ preventScroll: true }), 50);

    return {
      video: v,
      destroy() {
        if (dead) return;
        save(true);
        dead = true;
        clearInterval(saveT); clearTimeout(hideT);
        document.removeEventListener('keydown', key, true);
        document.removeEventListener('fullscreenchange', state);
        if (document.pictureInPictureElement === v) document.exitPictureInPicture().catch(() => {});
        try { v.pause(); v.removeAttribute('src'); v.load(); } catch (e) {}
      },
    };
  }
  window.CloudVideo = { mount, fmt };
})();
