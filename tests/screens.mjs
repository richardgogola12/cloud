// Snímky obrazovky (mobil + počítač) na kontrolu vzhľadu – spúšťa sa v GitHub Actions
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'fs';
mkdirSync('screens', { recursive: true });
const base = 'http://127.0.0.1:8080/';
const browser = await chromium.launch();
const sizes = [['mobil', { ...devices['Pixel 7'] }], ['velky', { viewport: { width: 580, height: 1180 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }], ['pc', { viewport: { width: 1280, height: 800 } }]];
for (const [name, opts] of sizes) {
  const ctx = await browser.newContext({ ...opts, locale: 'sk-SK' });
  const page = await ctx.newPage();
  // „velky“ = širší telefón s väčším písmom (ako textZoom vo WebView)
  if (name === 'velky') await page.addInitScript(() => addEventListener('DOMContentLoaded', () => { document.documentElement.style.fontSize = '125%'; }));
  page.on('pageerror', e => console.log(`[${name}] JS chyba: ${e.message}`));
  page.on('console', m => { if (m.type() === 'error') console.log(`[${name}] konzola: ${m.text()}`); });
  await page.goto(base + 'login.php');
  await page.fill('#email', 'admin@test.sk');
  await page.fill('#password', 'Heslo1234');
  await Promise.all([page.waitForURL(/index\.php/), page.click('form button.btn-primary')]);
  await page.waitForTimeout(800);
  const ids = await page.evaluate(async (sfx) => {
    let parent = 0; const ids = [];
    for (const n of ['Barbora Zahradníková – fotky z leta ' + sfx, 'Barbora Zahradníková', 'Dovolenka v Chorvátsku a Taliansku s rodinou']) {
      const r = await Cloud.api('api.php?action=mkdir', { parent, name: n }); parent = r.id; ids.push(r.id);
    }
    const t = await Cloud.api('api.php?action=tag_save', { name: 'Rodina ' + sfx, scope: 'd', color: '#188038' });
    await Cloud.api('api.php?action=tags_apply', { items: ['d:' + ids[0]], add: [t.id] });
    await Cloud.api('api.php?action=color_set', { items: ['d:' + ids[0]], color: '#e52592' });
    for (const n of ['Screenshot_2026-11-17-10-12-45-123_com.instagram.android.jpg', 'Veľmi dlhý názov dokumentu so zmluvou o prenájme bytu.pdf']) {
      const r = await Cloud.api('api.php?action=mkfile', { parent: ids[2], name: n });
    }
    return ids;
  }, name);
  await page.goto(base + 'index.php#folder/' + ids[2]); await page.waitForTimeout(1500);
  await page.screenshot({ path: `screens/${name}-1-dlha-cesta.png` });
  const icons = await page.evaluate(() => [...document.querySelectorAll('#items i.bi')].filter(i => /bi-(file|folder|image|camera|music)/.test(i.className)).filter(i => /\bbi-[a-z]/.test(i.className) && i.getBoundingClientRect().width > 0).length + document.querySelectorAll('#items .item-icon img').length);
  if (icons < 2) { console.log(`CHYBA [${name}]: v zozname chýbajú ikony súborov (${icons})`); process.exitCode = 1; }
  await page.goto(base + 'index.php#folder/' + ids[0]); await page.waitForTimeout(1200);
  await page.screenshot({ path: `screens/${name}-2-cesta-2-urovne.png` });
  await page.goto(base + 'index.php#folder/' + ids[1]); await page.waitForTimeout(1200);
  await page.screenshot({ path: `screens/${name}-2b-cesta-3-urovne.png` });
  await page.goto(base + 'index.php#'); await page.waitForTimeout(1200);
  await page.screenshot({ path: `screens/${name}-3-moj-disk.png` });
  // do zariadenia sa nič neukladá: po obnovení stránky nie je zoznam ani miniatúry v localStorage / CacheStorage
  await page.waitForTimeout(1000);
  await page.reload(); await page.waitForTimeout(1500);
  const stored = await page.evaluate(async () => ({ ls: Object.keys(localStorage).filter(k => k.startsWith('cloud-lc-') || k === 'cloud-recent-search'), caches: 'caches' in window ? await caches.keys() : [] }));
  console.log(`[${name}] uložené v zariadení: ${JSON.stringify(stored)}`);
  if (stored.ls.length || stored.caches.includes('cloud-thumbs')) { console.log('CHYBA: v zariadení ostali dáta'); process.exitCode = 1; }
  // potiahnutie prstom otvorí a zatvorí menu (mobil / aplikácia)
  if (name !== 'pc') {
    const swipe = (x1, x2) => page.evaluate(([a, b]) => {
      const el = document.querySelector('#items') || document.body;
      const mk = (x) => new Touch({ identifier: 1, target: el, clientX: x, clientY: 400 });
      el.dispatchEvent(new TouchEvent('touchstart', { touches: [mk(a)], bubbles: true }));
      for (let i = 1; i <= 6; i++) el.dispatchEvent(new TouchEvent('touchmove', { touches: [mk(a + (b - a) * i / 6)], bubbles: true }));
      el.dispatchEvent(new TouchEvent('touchend', { touches: [], bubbles: true }));
    }, [x1, x2]);
    await swipe(40, 260); await page.waitForTimeout(600);
    const opened = await page.locator('#sidebar.show').count();
    await page.screenshot({ path: `screens/${name}-3b-menu-swipe.png` });
    await swipe(260, 30); await page.waitForTimeout(600);
    const closed = await page.locator('#sidebar.show').count();
    console.log(`[${name}] menu potiahnutím: otvorené=${opened} po zatvorení=${closed}`);
    if (opened !== 1 || closed !== 0) { console.log('CHYBA: menu sa neotvára/nezatvára potiahnutím'); process.exitCode = 1; }
  }
  // klávesové skratky (iba počítač)
  if (name === 'pc') {
    await page.keyboard.press('Escape'); await page.mouse.click(5, 300);
    await page.keyboard.press('Shift+Slash'); await page.waitForTimeout(500);
    const kb = await page.locator('#kbdModal.show .kbd-row').count();
    console.log(`[${name}] skratky: ${kb} riadkov v pomocníkovi`);
    if (kb < 10) { console.log('CHYBA: pomocník skratiek sa neotvoril'); process.exitCode = 1; }
    await page.screenshot({ path: `screens/${name}-21-skratky.png` });
    await page.keyboard.press('Escape'); await page.waitForTimeout(400);
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown'); await page.waitForTimeout(200);
    const cur = await page.locator('#items .kb-cursor').count(), sel = await page.locator('#items .item.selected').count();
    console.log(`[${name}] šípky: kurzor ${cur}, vybraté ${sel}`);
    if (cur !== 1) { console.log('CHYBA: šípky nepresúvajú výber'); process.exitCode = 1; }
    await page.keyboard.press('Escape');
    // zoradenie v mriežke aj mimo zložky (Nedávne)
    await page.evaluate(() => { location.hash = '#recent'; }); await page.waitForTimeout(1200);
    await page.click('[data-view="grid"]'); await page.waitForTimeout(300);
    const before = await page.$$eval('#items .item[data-key^="f:"] .item-name, #items .item[data-key^="f:"] .file-name', els => els.map(e => e.textContent.trim()));
    await page.locator('.drive-head [title="Zoradiť"]').click(); await page.locator('#sortMenu [data-sort="name"]').click(); await page.waitForTimeout(300);
    const after = await page.$$eval('#items .item[data-key^="f:"] .item-name, #items .item[data-key^="f:"] .file-name', els => els.map(e => e.textContent.trim()));
    const coll = new Intl.Collator('sk', { numeric: true, sensitivity: 'base' });
    const sortedOk = after.length > 1 && after.every((v, i) => !i || coll.compare(after[i - 1], v) <= 0);
    console.log(`[${name}] zoradenie v mriežke (Nedávne podľa názvu): ${sortedOk ? 'OK' : 'NIE'} (${after.length} súborov)`);
    if (after.length > 1 && !sortedOk) { console.log('CHYBA: zoradenie v mriežke nefunguje', JSON.stringify(after)); process.exitCode = 1; }
    await page.click('[data-view="list"]');
    // fotky: prepnutie na mriežku a zoznam
    await page.evaluate(() => { location.hash = '#photos'; }); await page.waitForTimeout(1200);
    await page.click('[data-view="grid"]'); await page.waitForTimeout(300);
    const pg = await page.$eval('#items', el => el.className);
    await page.click('[data-view="list"]'); await page.waitForTimeout(300);
    const pl = await page.$eval('#items', el => el.className);
    await page.click('[data-view="photos"]'); await page.waitForTimeout(300);
    console.log(`[${name}] fotky: mriežka=${pg}, zoznam=${pl}`);
    if (!pg.includes('items-grid') || !pl.includes('items-list')) { console.log('CHYBA: fotky sa neprepínajú'); process.exitCode = 1; }
    // každý viditeľný stĺpec sa dá zoradiť (aj tie, ktoré server nezoraďuje)
    await page.evaluate(() => { location.hash = '#'; }); await page.waitForTimeout(1200);
    const hdr = await page.$$eval('#listHeader .c-col', els => els.map(e => [e.dataset.col, e.classList.contains('sortable')]));
    console.log(`[${name}] stĺpce a zoradenie: ${JSON.stringify(hdr)}`);
    if (!hdr.length || hdr.some(h => !h[1])) { console.log('CHYBA: niektorý stĺpec sa nedá zoradiť'); process.exitCode = 1; }
    const menuKeys = await page.$$eval('#sortMenu [data-sort]', els => els.map(e => e.dataset.sort));
    console.log(`[${name}] menu zoradenia: ${menuKeys.join(', ')}`);
    if (menuKeys.length < 6) { console.log('CHYBA: menu zoradenia nemá všetky stĺpce'); process.exitCode = 1; }
  }
  // aktivita, albumy, mapa
  await page.evaluate(() => { location.hash = '#activity'; }); await page.waitForTimeout(1200);
  const actRows = await page.locator('#items .item').count();
  console.log(`[${name}] aktivita: ${actRows} záznamov`);
  await page.screenshot({ path: `screens/${name}-17-aktivita.png` });
  await page.evaluate(() => { location.hash = '#photos'; }); await page.waitForTimeout(1200);
  if (!(await page.locator('#photoBar .pb-tab').count())) { console.log('CHYBA: chýba lišta fotiek'); process.exitCode = 1; }
  await page.screenshot({ path: `screens/${name}-18-fotky.png` });
  await page.evaluate(() => { location.hash = '#albums'; }); await page.waitForTimeout(1000);
  await page.screenshot({ path: `screens/${name}-19-albumy.png` });
  await page.evaluate(() => { location.hash = '#map'; }); await page.waitForTimeout(2500);
  await page.screenshot({ path: `screens/${name}-20-mapa.png` });
  await page.evaluate(() => { location.hash = '#'; }); await page.waitForTimeout(1000);
  // vyhľadávanie: návrhy, výsledky s čipmi filtrov, rozšírené vyhľadávanie
  await page.click('#searchInput'); await page.waitForTimeout(500);
  await page.screenshot({ path: `screens/${name}-12-hladanie-prazdne.png` });
  await page.fill('#searchInput', 'Barbora'); await page.waitForTimeout(900);
  await page.screenshot({ path: `screens/${name}-12b-hladanie-navrhy.png` });
  await page.press('#searchInput', 'Enter'); await page.waitForTimeout(1300);
  const chips = await page.locator('#searchChips .s-chip').count(), marks = await page.locator('#items mark').count();
  console.log(`[${name}] hľadanie: čipy ${chips}, zvýraznenia ${marks}, adresa ${page.url()}`);
  if (chips < 4 || marks < 1) { console.log('CHYBA: výsledky hľadania bez čipov / zvýraznenia'); process.exitCode = 1; }
  await page.screenshot({ path: `screens/${name}-13-hladanie-vysledky.png` });
  await page.locator('#searchChips .s-chip').first().click(); await page.waitForTimeout(400);
  await page.screenshot({ path: `screens/${name}-13b-hladanie-typ.png` });
  await page.locator('#searchChips .dropdown-menu.show [data-val="folder"]').click(); await page.waitForTimeout(1200);
  if (!page.url().includes('type=folder')) { console.log('CHYBA: čip typu nezmenil filter'); process.exitCode = 1; }
  await page.click('#searchFilterBtn'); await page.waitForTimeout(500);
  await page.screenshot({ path: `screens/${name}-14-rozsirene-hladanie.png` });
  await page.selectOption('#searchAdv select[name="modified"]', '7d');
  await page.click('#searchAdv [data-adv-go]'); await page.waitForTimeout(1200);
  if (!page.url().includes('modified=7d')) { console.log('CHYBA: rozšírené vyhľadávanie nepoužilo filter'); process.exitCode = 1; }
  await page.click('.avatar-btn'); await page.waitForTimeout(400);
  await page.screenshot({ path: `screens/${name}-15-menu-uctu.png` });
  await page.keyboard.press('Escape');
  if (name === 'pc') {
    // zdieľanie: hľadanie používateľa podľa e-mailu v Select2
    const card = page.locator('[data-key="d:' + ids[0] + '"]').first();
    await card.click({ button: 'right' }); await page.waitForTimeout(400);
    await page.click('[data-ctx="share"]'); await page.waitForTimeout(800);
    await page.click('#shUserSel + .select2 .select2-selection'); await page.waitForTimeout(300);
    await page.keyboard.type('beata@test.sk', { delay: 40 });
    await page.waitForTimeout(1500);
    const txt = await page.locator('.select2-results').innerText().catch(() => '(žiadne výsledky)');
    console.log('[zdieľanie] výsledky hľadania: ' + txt.replace(/\n/g, ' | '));
    await page.screenshot({ path: `screens/${name}-5-zdielanie.png` });
    await page.keyboard.press('Escape'); await page.waitForTimeout(300);
    await page.click('#shareModal .btn-close', { force: true }); await page.waitForTimeout(600);
    await page.goto(base + 'index.php#byme'); await page.waitForTimeout(1200);
    await page.screenshot({ path: `screens/${name}-6-zdielane-mnou.png` });
    await page.click('.side-toggle'); await page.waitForTimeout(500);
    await page.screenshot({ path: `screens/${name}-7-menu-nastavenia.png` });
    // zoznam so stĺpcami a výber stĺpcov
    await page.goto(base + 'index.php#folder/' + ids[0]); await page.waitForTimeout(800);
    await page.click('[data-view="list"]'); await page.waitForTimeout(600);
    await page.click('#colsDrop > button'); await page.waitForTimeout(500);
    await page.screenshot({ path: `screens/${name}-10-stlpce.png` });
    await page.keyboard.press('Escape'); await page.waitForTimeout(300);
    // zmena šírky stĺpca ťahaním
    const cell = page.locator('#listHeader .c-col[data-col="kind"]');
    const w0 = (await cell.boundingBox()).width;
    const hb = await page.locator('.col-resize[data-resize="kind"]').boundingBox();
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2); await page.mouse.down();
    await page.mouse.move(hb.x + hb.width / 2 + 90, hb.y + hb.height / 2, { steps: 6 }); await page.mouse.up();
    await page.waitForTimeout(300);
    const w1 = (await cell.boundingBox()).width;
    const rowW = (await page.locator('.row-item .c-col.c-kind').first().boundingBox()).width;
    console.log('[šírka stĺpca] pred: ' + Math.round(w0) + ' po: ' + Math.round(w1) + ' riadok: ' + Math.round(rowW));
    if (!(w1 > w0 + 60) || Math.abs(rowW - w1) > 2) { console.log('CHYBA: zmena šírky stĺpca nefunguje'); process.exitCode = 1; }
    // šírka stĺpca Názov + stránka nesmie pretekať do strany
    const n0 = (await page.locator('#listHeader .c-name').boundingBox()).width;
    const nh = await page.locator('.col-resize[data-resize="name"]').boundingBox();
    await page.mouse.move(nh.x + nh.width / 2, nh.y + nh.height / 2); await page.mouse.down();
    await page.mouse.move(nh.x + nh.width / 2 + 300, nh.y + nh.height / 2, { steps: 8 }); await page.mouse.up();
    await page.waitForTimeout(400);
    const n1 = (await page.locator('#listHeader .c-name').boundingBox()).width;
    const over = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    console.log('[šírka názvu] pred: ' + Math.round(n0) + ' po: ' + Math.round(n1) + ' · pretečenie stránky: ' + over + ' px');
    if (!(n1 > n0 + 100) || over > 2) { console.log('CHYBA: šírka názvu / pretekanie'); process.exitCode = 1; }
    await page.screenshot({ path: `screens/${name}-11-sirka-stlpca.png` });
    await page.click('[data-view="grid"]');
    await page.goto(base + 'admin.php'); await page.waitForTimeout(800);
    await page.locator('#healthCard').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `screens/${name}-8-admin-stav.png` });
    await page.goto(base + 'index.php#dupes'); await page.waitForTimeout(1200);
    await page.screenshot({ path: `screens/${name}-9-duplicity.png` });
    // tlačidlo Späť zatvorí náhľad
    await page.goto(base + 'index.php#photos'); await page.waitForTimeout(1200);
    await page.locator('.photo-item').first().dblclick().catch(() => {});
    await page.waitForTimeout(800);
    const opened = await page.locator('#previewModal.show').count();
    await page.goBack(); await page.waitForTimeout(800);
    const still = await page.locator('#previewModal.show').count();
    console.log('[späť] náhľad otvorený: ' + opened + ', po Späť: ' + still + ' · adresa: ' + page.url());
    if (opened !== 1 || still !== 0) { console.log('CHYBA: Späť nezatvoril náhľad'); process.exitCode = 1; }
  }
  if (name === 'mobil') {
    await page.click('button[data-bs-target="#sidebar"]'); await page.waitForTimeout(800);
    await page.screenshot({ path: `screens/${name}-4-menu.png` });
  }
  await ctx.close();
}
await browser.close();
console.log('Snímky hotové');
