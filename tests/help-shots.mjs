// Snímky obrazovky do príručky (ukážková používateľka Jana Nováková + administrátor) – spúšťa sa v GitHub Actions
import { chromium, devices } from 'playwright';
import { mkdirSync, readFileSync } from 'fs';
const out = 'screens/help';
mkdirSync(out, { recursive: true });
const base = 'http://127.0.0.1:8080/';
const ids = JSON.parse(readFileSync('help-demo.json', 'utf8'));
const browser = await chromium.launch();
const wait = (p, ms = 700) => p.waitForTimeout(ms);
let failed = 0;

async function login(page, email, pass) {
  await page.goto(base + 'login.php');
  await page.fill('#email', email); await page.fill('#password', pass);
  await Promise.all([page.waitForURL(/index\.php/), page.click('form button.btn-primary')]);
  await wait(page, 1200);
}
async function shot(page, name, fn) {
  try {
    if (fn) await fn();
    await page.screenshot({ path: `${out}/${name}.png` });
    console.log('  ✔ ' + name);
  } catch (e) { failed++; console.log('  ✘ ' + name + ': ' + e.message.split('\n')[0]); }
  await page.keyboard.press('Escape').catch(() => {}); await wait(page, 300);
  await page.evaluate(() => document.querySelectorAll('.modal.show').forEach(m => bootstrap.Modal.getInstance(m)?.hide())).catch(() => {});
  await wait(page, 300);
}
const go = async (page, hash, ms = 1300) => { await page.evaluate((h) => { location.hash = h; }, hash); await wait(page, ms); };
const item = (page, key) => page.locator(`#items [data-key="${key}"]`).first();
const setLayout = async (page, v) => { const b = page.locator(`[data-view="${v}"]`); if (await b.count() && await b.isVisible()) { await b.click(); await wait(page, 600); } };

/* ---------------- počítač: používateľka ---------------- */
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'sk-SK' });
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('JS chyba: ' + e.message));
  await login(page, 'jana@demo.sk', 'Demo12345');
  await go(page, '#'); await setLayout(page, 'grid');
  await shot(page, 'u-disk');
  await setLayout(page, 'list');
  await shot(page, 'u-disk-zoznam');
  await shot(page, 'u-novy', async () => { await page.click('.btn-new'); await wait(page, 400); });
  await go(page, '#folder/' + ids.zml);
  await shot(page, 'u-kontext', async () => { await item(page, 'f:' + ids.najom).click({ button: 'right' }); await wait(page, 500); });
  await shot(page, 'u-info', async () => { await item(page, 'f:' + ids.najom).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="info"]'); await wait(page, 900); });
  await shot(page, 'u-verzie', async () => { await item(page, 'f:' + ids.najom).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="versions"]'); await wait(page, 900); });
  await shot(page, 'u-historia', async () => { await item(page, 'f:' + ids.najom).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="activity"]'); await wait(page, 1000); });
  await go(page, '#');
  await shot(page, 'u-zdielanie', async () => { await item(page, 'd:' + ids.dov).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="share"]'); await wait(page, 1000); });
  await go(page, '#folder/' + ids.dok);
  await shot(page, 'u-odkaz', async () => { await item(page, 'd:' + ids.fak).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="share"]'); await wait(page, 800); await page.click('[data-bs-target="#shLink"]'); await wait(page, 600); });
  await go(page, '#folder/' + ids.dov); await setLayout(page, 'grid'); await wait(page, 1200);
  await shot(page, 'u-zlozka-fotky');
  await shot(page, 'u-nahlad', async () => { await item(page, 'f:' + ids.photo).dblclick(); await wait(page, 1500); });
  await shot(page, 'u-obrazok', async () => { await page.evaluate(() => bootstrap.Modal.getInstance(document.getElementById('previewModal'))?.hide()); await wait(page, 400);
    await item(page, 'f:' + ids.photo).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="edit"]'); await page.waitForSelector('#imgEditModal.show .ie-canvas', { timeout: 15000 }); await wait(page, 1500);
    await page.click('#imgEditModal [data-ratio="1.7777777777777777"]'); await wait(page, 600); });
  const openEd = async () => { await item(page, 'f:' + ids.photo).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="edit"]'); await page.waitForSelector('#imgEditModal.show .ie-canvas', { timeout: 15000 }); await wait(page, 1500); };
  await shot(page, 'u-obrazok-tvary', async () => { await openEd();
    await page.click('#imgEditModal [data-tab="shapes"]'); await wait(page, 400);
    await page.click('#imgEditModal [data-ie="lib"]'); await wait(page, 300); await page.click('#imgEditModal [data-shape="callRound"]'); await wait(page, 300);
    await page.click('#imgEditModal [data-gp="fill"] [data-sw="fill"][data-c="#ffffff"]');
    await page.click('#imgEditModal [data-g="text"]'); await page.fill('#imgEditModal [data-gp="text"] textarea', 'Krásny výhľad!'); await page.click('#imgEditModal [data-gp="text"] [data-sw="tc"][data-c="#000000"]');
    await page.click('#imgEditModal [data-g="shadow"]'); await page.click('#imgEditModal [data-gp="shadow"] [data-k="shadow"]'); await wait(page, 600); });
  await shot(page, 'u-obrazok-kniznica', async () => { await openEd(); await page.click('#imgEditModal [data-tab="shapes"]'); await page.click('#imgEditModal [data-ie="lib"]'); await wait(page, 500); });
  await shot(page, 'u-obrazok-maska', async () => { await openEd(); await page.click('#imgEditModal [data-tab="mask"]'); await page.click('#imgEditModal [data-mpre="vignette"]'); await wait(page, 800); });
  await page.evaluate(() => { const m = document.getElementById('imgEditModal'); m && bootstrap.Modal.getInstance(m)?.hide(); }); await wait(page, 500);
  await go(page, '#shared');
  await shot(page, 'u-zdielane-so-mnou');
  await go(page, '#byme');
  await shot(page, 'u-zdielane-mnou');
  await go(page, '#');
  await shot(page, 'u-hladanie-navrhy', async () => { await page.click('#searchInput'); await page.fill('#searchInput', 'zml'); await wait(page, 1000); });
  await shot(page, 'u-hladanie-vysledky', async () => { await page.fill('#searchInput', 'faktúra'); await page.press('#searchInput', 'Enter'); await wait(page, 1400); });
  await shot(page, 'u-hladanie-rozsirene', async () => { await page.click('#searchFilterBtn'); await wait(page, 600); });
  await page.goto(base + 'index.php'); await wait(page, 1200);
  await go(page, '#photos', 1800);
  await shot(page, 'u-fotky');
  await go(page, '#albums');
  await shot(page, 'u-albumy');
  await go(page, '#album/' + ids.album, 1600);
  await shot(page, 'u-album');
  await go(page, '#recent');
  await shot(page, 'u-nedavne');
  await go(page, '#starred');
  await shot(page, 'u-hviezdicky');
  await go(page, '#activity', 1600);
  await shot(page, 'u-aktivita');
  await go(page, '#trash');
  await shot(page, 'u-kos');
  await go(page, '#folder/' + ids.sukr);
  await shot(page, 'u-zamknuta');
  await go(page, '#');
  await shot(page, 'u-zamknut', async () => { await item(page, 'd:' + ids.dok).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="lock"]'); await wait(page, 800); });
  await shot(page, 'u-rewind', async () => { await item(page, 'd:' + ids.dok).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="rewind"]'); await wait(page, 1000); });
  await shot(page, 'u-ucty', async () => { await page.click('.avatar-btn'); await wait(page, 500); });
  await shot(page, 'u-skratky', async () => { await page.keyboard.press('?'); await wait(page, 600); });
  await shot(page, 'u-vzhlad', async () => { await page.evaluate(() => window.cloudAppearance && cloudAppearance()); await wait(page, 700); });
  await page.keyboard.press('Escape'); await wait(page, 400);
  await shot(page, 'u-sprievodca', async () => { await page.evaluate(() => window.cloudTour && window.cloudTour()); await wait(page, 900); });
  await shot(page, 'u-tmavy', async () => { await page.click('#themeToggle'); await wait(page, 800); });
  await page.click('#themeToggle').catch(() => {}); await wait(page, 500);
  await go(page, '#');
  await shot(page, 'u-tabulka', async () => { await item(page, 'f:' + ids.rozpocet).click({ button: 'right' }); await wait(page, 300); await page.click('[data-ctx="edit"]'); await page.waitForSelector('#sheetModal.show iframe', { timeout: 20000 });
    let fr = null; for (let i = 0; i < 80 && !fr; i++) { fr = page.frames().find(f => f.url().includes('sheet.php')); if (!fr) await wait(page, 250); }
    await fr.waitForFunction(() => window.CloudSheetEditor && CloudSheetEditor.ready, null, { timeout: 30000 }); await wait(page, 1000);
    await fr.evaluate(() => {
      const fmt = (r, a, v) => { luckysheet.setRangeShow(r); luckysheet.setRangeFormat(a, v); };
      fmt('A1:D1', 'bl', 1); fmt('A1:D1', 'bg', '#e8f0fe'); fmt('A6:D6', 'bl', 1); fmt('B2:D6', 'ct', { fa: '#,##0.00 €', t: 'n' });
      luckysheet.setHorizontalFrozen(false); luckysheet.setRangeShow('B2:D5'); }); await wait(page, 500);
    const F = page.frameLocator('#sheetModal iframe');
    await F.locator('#gsMenu .gs-mb', { hasText: 'Formát' }).click(); await wait(page, 600);
    await page.screenshot({ path: `${out}/u-tabulka-menu.png` }); console.log('  ✔ u-tabulka-menu');
    await F.locator('#gsMenu .gs-mb', { hasText: 'Formát' }).click(); await wait(page, 300);
    await fr.evaluate(() => luckysheet.setRangeShow('C7')); await fr.waitForFunction(() => /Všetky zmeny/.test(document.getElementById('gsStatus').textContent), null, { timeout: 15000 }).catch(() => {}); await wait(page, 500); });
  await page.frameLocator('#sheetModal iframe').locator('body').press('Escape').catch(() => {});
  await page.evaluate(() => { const f = document.querySelector('#sheetModal iframe'); f && f.contentWindow.postMessage({ cloudSheetCmd: 'close' }, location.origin); }); await wait(page, 2500);
  await shot(page, 'u-dokument', async () => {
    await page.evaluate(() => { location.hash = '#'; }); await wait(page, 800);
    await page.click('.btn-new'); await wait(page, 300); await page.click('.dropdown-menu.show [data-action="new-doc"]'); await wait(page, 500);
    await page.fill('.modal.show input', 'Zápisnica – rodinná porada'); await page.click('.modal.show .btn-primary');
    await page.waitForSelector('#sheetModal.show iframe', { timeout: 20000 });
    let df = null; for (let i = 0; i < 80 && !df; i++) { df = page.frames().find(f => f.url().includes('doc.php')); if (!df) await wait(page, 250); }
    await df.waitForFunction(() => window.CloudDocEditor && CloudDocEditor.ready, null, { timeout: 30000 });
    await df.evaluate(() => {
      const p = document.getElementById('gdPage');
      p.innerHTML = '<h1>Rodinná porada</h1><p><span style="color:#5f6368">Nedeľa 23. novembra 2026 · obývačka</span></p><h2>Program</h2><ol><li>Dovolenka v lete – <b>Tatry alebo more?</b></li><li>Rozpočet na Vianoce</li><li>Kto sa postará o záhradu</li></ol>'
        + '<h2>Rozhodli sme</h2><table><tbody><tr><td><b>Úloha</b></td><td><b>Kto</b></td><td><b>Do kedy</b></td></tr><tr><td>Zistiť ceny chát</td><td>Jana</td><td>30. 11.</td></tr><tr><td>Zoznam darčekov</td><td>Peter</td><td>5. 12.</td></tr></tbody></table>'
        + '<p>Ďalšie stretnutie: <span style="background-color:#fff475">nedeľa 7. decembra</span>.</p>';
      p.dispatchEvent(new Event('input'));
    });
    await wait(page, 3500); });
  await page.evaluate(() => { const f = document.querySelector('#sheetModal iframe'); f && f.contentWindow.postMessage({ cloudSheetCmd: 'close' }, location.origin); }); await wait(page, 2500);
  await page.goto(base + 'profile.php'); await wait(page, 1200);
  await shot(page, 'u-profil');
  await shot(page, 'u-2fa', async () => { await page.locator('#tfaStart').click(); await wait(page, 800); await page.locator('#tfa').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); await wait(page, 300); });
  await shot(page, 'u-kos-verzie', async () => { await page.locator('#versions').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); await wait(page, 300); });
  await shot(page, 'u-pripnute', async () => {
    await page.goto(base + 'index.php'); await wait(page, 1200);
    await page.evaluate(async (ids) => { for (const id of [ids.dok, ids.dov].filter(Boolean)) await Cloud.api('api.php?action=pin', { id, on: '1' }); }, ids);
    await page.reload(); await wait(page, 1500); });
  await page.goto(base + 'profile.php'); await wait(page, 1200);
  await shot(page, 'u-suhrn', async () => { await page.locator('#digest').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); await wait(page, 300); });
  await shot(page, 'u-webdav', async () => { await page.locator('#dav').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); await wait(page, 300); });
  await shot(page, 'u-stlpce', async () => { await page.locator('#cols').scrollIntoViewIfNeeded().catch(() => {}); await page.evaluate(() => { const c = document.getElementById('cols') || [...document.querySelectorAll('h2')].find(h => h.textContent.includes('Stĺpce')); c && c.scrollIntoView(); scrollBy(0, -80); }); await wait(page, 300); });
  await page.goto(base + 'help.php'); await wait(page, 1000);
  await shot(page, 'u-prirucka');
  await ctx.close();
}

/* ---------------- mobil ---------------- */
{
  const ctx = await browser.newContext({ ...devices['Pixel 7'], locale: 'sk-SK' });
  const page = await ctx.newPage();
  await login(page, 'jana@demo.sk', 'Demo12345');
  await go(page, '#'); await setLayout(page, 'list');
  await shot(page, 'm-disk');
  await shot(page, 'm-menu', async () => { await page.click('button[data-bs-target="#sidebar"]'); await wait(page, 800); });
  await go(page, '#photos', 1800);
  await shot(page, 'm-fotky');
  await go(page, '#folder/' + ids.zml);
  await shot(page, 'm-akcie', async () => { await item(page, 'f:' + ids.najom).locator('.item-menu, [data-menu], .btn-icon').first().click(); await wait(page, 600); });
  await ctx.close();
}

/* ---------------- počítač: administrátor ---------------- */
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'sk-SK' });
  const page = await ctx.newPage();
  await login(page, 'admin@test.sk', 'Heslo1234');
  for (const [tab, name] of [['dashboard', 'a-prehlad'], ['users', 'a-pouzivatelia'], ['settings', 'a-nastavenia'], ['smtp', 'a-smtp'], ['log', 'a-zaznamy'], ['maintenance', 'a-udrzba'], ['backup', 'a-zalohy'], ['update', 'a-aktualizacia']]) {
    await page.goto(base + 'admin.php?tab=' + tab); await wait(page, 1200);
    await shot(page, name);
  }
  await page.goto(base + 'admin.php?tab=dashboard'); await wait(page, 1200);
  await shot(page, 'a-platforma', async () => { await page.locator('#platformCard').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); });
  await shot(page, 'a-stav', async () => { await page.locator('#healthCard').scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -80)); });
  await page.goto(base + 'help.php?doc=dev'); await wait(page, 1000);
  await shot(page, 'a-dev-prirucka');
  await ctx.close();
}
await browser.close();
console.log(failed ? `Snímky príručky: ${failed} sa nepodarilo` : 'Snímky príručky: všetky hotové');
