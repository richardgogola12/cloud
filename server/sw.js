/* Service worker – Cloud PWA */
const VERSION = 'cloud-v2';
const SHELL = [
  'offline.html',
  'assets/css/app.css?v=2',
  'assets/js/common.js?v=2',
  'assets/js/drive.js?v=2',
  'assets/js/passkey.js?v=2',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(SHELL.map(u => new URL(u, self.registration.scope)))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION && k !== INBOX).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

const INBOX = 'cloud-share-inbox';

/* "Zdieľať → Cloud" z iných aplikácií: súbory odložíme do cache a otvoríme disk, ktorý ich nahrá */
async function handleShare(req) {
  const fd = await req.formData();
  const c = await caches.open(INBOX);
  let i = 0;
  for (const f of fd.getAll('files')) {
    if (!(f instanceof File)) continue;
    await c.put(new Request('/__share/' + Date.now() + '-' + (i++)), new Response(f, { headers: { 'Content-Type': f.type || 'application/octet-stream', 'X-File-Name': encodeURIComponent(f.name || 'subor') } }));
  }
  // zdieľaný text/odkaz uložíme ako .txt
  const txt = [fd.get('title'), fd.get('text'), fd.get('url')].filter(Boolean).join('\n');
  if (!i && txt) await c.put(new Request('/__share/' + Date.now() + '-t'), new Response(txt, { headers: { 'Content-Type': 'text/plain', 'X-File-Name': encodeURIComponent('Zdieľané ' + new Date().toISOString().slice(0, 16).replace('T', ' ').replace(':', '-') + '.txt') } }));
  return Response.redirect(new URL('index.php?shared-files=1', self.registration.scope).href, 303);
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method === 'POST' && url.pathname.endsWith('/share-target.php')) { e.respondWith(handleShare(req)); return; }
  if (req.method !== 'GET') return;

  // dynamické dáta a súbory nikdy necacheujeme
  if (url.origin === location.origin && /\/(api|passkey|logout|admin)\.php/.test(url.pathname)) return;

  // navigácia: sieť, pri výpadku offline stránka
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(new URL('offline.html', self.registration.scope))));
    return;
  }

  // statické súbory (vlastné + CDN): stale-while-revalidate
  if (/\.(css|js|png|svg|woff2?|ttf)(\?|$)/.test(url.pathname + url.search) || url.hostname === 'cdn.jsdelivr.net') {
    e.respondWith(caches.open(VERSION).then(async (c) => {
      const hit = await c.match(req);
      const net = fetch(req).then(res => { if (res.ok || res.type === 'opaque') c.put(req, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    }));
  }
});
