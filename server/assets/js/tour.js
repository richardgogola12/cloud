/* Rýchly sprievodca pre nových používateľov – 10 krokov, ktoré sa dajú preklikať. Zobrazí sa po prvom prihlásení,
   kým používateľ nedá „Viac nezobrazovať“. Kedykoľvek sa dá otvoriť z menu: Pomoc → Rýchly sprievodca. */
(function () {
  const img = (n) => (window.CLOUD_HELP_IMG || 'assets/help/') + n + '.jpg?v=' + (window.CLOUD_VER || '');
  const STEPS = [
    { icon: 'bi-cloud-fill', title: 'Vitaj v Cloude', img: 'tour-0',
      text: 'Cloud je tvoj súkromný disk ako Google Disk – súbory, fotky a dokumenty máš na jednom mieste, dostupné z počítača aj z mobilu. Tento krátky sprievodca ti za minútu ukáže to najdôležitejšie.' },
    { icon: 'bi-cloud-arrow-up', title: '1. Nahrávanie súborov', img: 'tour-1',
      text: 'Klikni na <b>+ Nový</b> → <b>Nahrať súbory</b> alebo <b>Nahrať zložku</b>. Ešte rýchlejšie: súbory jednoducho <b>potiahni myšou</b> do okna. Veľké súbory sa nahrávajú po častiach a pri výpadku siete pokračujú tam, kde skončili.' },
    { icon: 'bi-folder-plus', title: '2. Zložky a poriadok', img: 'tour-2',
      text: 'Vytvor si zložky (<b>+ Nový → Nová zložka</b>) a súbory do nich presúvaj potiahnutím. Zložkám môžeš dať <b>farbu</b> a <b>štítky</b>. Vpravo hore prepínaš <b>zoznam</b> a <b>mriežku</b> – každé miesto si pamätá svoje. Keď sa vrátiš o úroveň vyššie, zložka, z ktorej ideš, <b>blikne</b>.' },
    { icon: 'bi-three-dots-vertical', title: '3. Ponuka akcií', img: 'tour-3',
      text: 'Pravým klikom na súbor (v mobile cez <b>⋮</b>) otvoríš všetky akcie: stiahnuť, zdieľať, štítky a farba, premenovať, presunúť, kópia, hviezdička, verzie, informácie, aktivita (história zmien) a presun do koša. Tabuľky (.xlsx) <b>upravíš</b> v editore ako Google Tabuľky a fotky orežeš priamo v Cloude.' },
    { icon: 'bi-share', title: '4. Zdieľanie', img: 'tour-4',
      text: 'Cez <b>Zdieľať</b> pridáš ľudí z Cloudu (iba čítanie alebo aj úpravy), alebo vytvoríš <b>verejný odkaz</b> – s heslom, dátumom platnosti a pri zložke aj s možnosťou, aby ti iní nahrali súbory.' },
    { icon: 'bi-search', title: '5. Vyhľadávanie', img: 'tour-5',
      text: 'Do poľa <b>Hľadať v disku</b> napíš časť názvu. Výsledky spresníš filtrami (typ, vlastník, dátum, veľkosť, štítok) alebo skratkami ako <code>type:pdf</code>, <code>po:1.1.2026</code>, <code>size&gt;10mb</code>.' },
    { icon: 'bi-images', title: '6. Fotky a albumy', img: 'tour-6',
      text: 'V sekcii <b>Fotky</b> sú všetky obrázky a videá zoradené podľa dátumu. Môžeš si robiť <b>albumy</b>, spustiť <b>prezentáciu</b> a pozrieť si fotky <b>„V tento deň“</b> z minulých rokov.' },
    { icon: 'bi-clock-history', title: '7. Verzie a kôš – nič sa nestratí', img: 'tour-7',
      text: 'Pri úprave alebo nahratí súboru s rovnakým názvom sa starý uloží ako <b>verzia</b> – kedykoľvek ho obnovíš. Ako dlho sa verzie držia, si nastavíš v Môj účet a pred zmazaním ťa Cloud upozorní. Zmazané položky sú v <b>Koši</b>. Celú zložku vieš dokonca <b>vrátiť do stavu z ľubovoľného dňa</b>.' },
    { icon: 'bi-lock-fill', title: '8. Zamknuté zložky', img: 'tour-8',
      text: 'Citlivé veci (doklady, heslá) daj do zložky zamknutej <b>heslom</b> – v aplikácii ju otvoríš aj <b>odtlačkom prsta</b>. Sám si zvolíš, kedy sa má zložka znova zamknúť.' },
    { icon: 'bi-phone', title: '9. Aplikácia v mobile', img: 'tour-9',
      text: 'V <b>Môj účet</b> si stiahneš aplikáciu pre Android: automaticky zálohuje fotky z mobilu, synchronizuje zvolené zložky a súbory doň pošleš cez <b>Zdieľať → Cloud</b>. Menu otvoríš potiahnutím prsta doprava.' },
    { icon: 'bi-person-gear', title: '10. Tvoj účet a pomoc', img: 'tour-10',
      text: 'V <b>Môj účet</b> zmeníš heslo, nastavíš <b>prihlásenie odtlačkom prsta</b>, e-mailový súhrn (kedy a ako často chodí), bliknutie pri návrate, ako dlho uchovávať verzie, stĺpce v zozname a sieťový disk. Kompletný návod nájdeš v menu <b>Pomoc → Príručka</b>. Na počítači stlač <kbd>?</kbd> pre klávesové skratky.' },
  ];
  let i = 0, modal = null, finished = false;
  function build() {
    document.body.insertAdjacentHTML('beforeend', '<div class="modal fade" id="tourModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content tour">'
      + '<button type="button" class="btn-close tour-close" data-tour="later" aria-label="Zavrieť"></button>'
      + '<div class="tour-pic"></div><div class="tour-body"><div class="tour-step small text-body-secondary"></div><h2 class="h4 fw-bold tour-title"></h2><p class="tour-text"></p></div>'
      + '<div class="tour-foot"><button class="btn btn-link text-body-secondary px-0 me-auto" data-tour="never">Viac nezobrazovať</button>'
      + '<div class="tour-dots"></div><button class="btn btn-outline-secondary rounded-pill px-3" data-tour="prev">Späť</button><button class="btn btn-primary rounded-pill px-4" data-tour="next">Ďalej</button></div>'
      + '</div></div></div>');
    const m = document.getElementById('tourModal');
    m.addEventListener('click', (e) => {
      const b = e.target.closest('[data-tour]'); if (!b) return;
      const a = b.dataset.tour;
      if (a === 'next') { if (i < STEPS.length - 1) { i++; render(); } else finish('done'); }
      else if (a === 'prev') { if (i > 0) { i--; render(); } }
      else if (a === 'go') { i = +b.dataset.i; render(); }
      else finish(a);
    });
    m.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight') { e.preventDefault(); m.querySelector('[data-tour="next"]').click(); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); m.querySelector('[data-tour="prev"]').click(); }
    });
    m.addEventListener('hidden.bs.modal', () => { if (!finished) finish('later'); });
    let x0 = null; // potiahnutie prstom medzi krokmi
    m.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
    m.addEventListener('touchend', (e) => { if (x0 === null) return; const dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 60) m.querySelector(dx < 0 ? '[data-tour="next"]' : '[data-tour="prev"]').click(); }, { passive: true });
    return m;
  }
  function render() {
    const s = STEPS[i], m = document.getElementById('tourModal');
    m.querySelector('.tour-pic').innerHTML = '<div class="tour-ic"><i class="bi ' + s.icon + '"></i></div>' + (s.img ? '<img src="' + img(s.img) + '" alt="" onerror="this.remove()">' : '');
    m.querySelector('.tour-step').textContent = i === 0 ? 'Rýchly sprievodca' : 'Krok ' + i + ' z ' + (STEPS.length - 1);
    m.querySelector('.tour-title').textContent = s.title;
    m.querySelector('.tour-text').innerHTML = s.text;
    m.querySelector('.tour-dots').innerHTML = STEPS.map((_, k) => '<button class="' + (k === i ? 'on' : '') + '" data-tour="go" data-i="' + k + '" aria-label="Krok ' + k + '"></button>').join('');
    m.querySelector('[data-tour="prev"]').classList.toggle('invisible', i === 0);
    m.querySelector('[data-tour="next"]').textContent = i === 0 ? 'Začať' : i === STEPS.length - 1 ? 'Hotovo' : 'Ďalej';
  }
  function finish(how) {
    finished = true;
    if (modal) modal.hide();
    // „Viac nezobrazovať“ aj „Hotovo“ = už nikdy; zavretie krížikom = do ďalšieho prihlásenia
    if (window.Cloud && Cloud.api) Cloud.api('api.php?action=tour', { value: how === 'never' || how === 'done' ? 'done' : 'later' }).catch(() => {});
  }
  window.cloudTour = function () {
    const m = document.getElementById('tourModal') || build();
    i = 0; finished = false; render();
    modal = bootstrap.Modal.getOrCreateInstance(m); modal.show();
  };
  document.addEventListener('click', (e) => { const a = e.target.closest('[data-action="tour"], [data-nav="tour"]'); if (a) { e.preventDefault(); window.cloudTour(); } });
  if (location.hash === '#sprievodca' && /help\.php/.test(location.pathname)) setTimeout(() => window.cloudTour(), 300);
  if (window.CLOUD_TOUR) setTimeout(() => window.cloudTour(), 600);
})();
