// Snímky obrazovky (mobil + počítač) na kontrolu vzhľadu – spúšťa sa v GitHub Actions
import { chromium, devices } from 'playwright';
import { mkdirSync } from 'fs';
mkdirSync('screens', { recursive: true });
const base = 'http://127.0.0.1:8080/';
const browser = await chromium.launch();
const sizes = [['mobil', { ...devices['Pixel 7'] }], ['pc', { viewport: { width: 1280, height: 800 } }]];
for (const [name, opts] of sizes) {
  const ctx = await browser.newContext({ ...opts, locale: 'sk-SK' });
  const page = await ctx.newPage();
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
  await page.goto(base + 'index.php#folder/' + ids[0]); await page.waitForTimeout(1200);
  await page.screenshot({ path: `screens/${name}-2-cesta-2-urovne.png` });
  await page.goto(base + 'index.php#'); await page.waitForTimeout(1200);
  await page.screenshot({ path: `screens/${name}-3-moj-disk.png` });
  if (name === 'mobil') {
    await page.click('button[data-bs-target="#sidebar"]'); await page.waitForTimeout(800);
    await page.screenshot({ path: `screens/${name}-4-menu.png` });
  }
  await ctx.close();
}
await browser.close();
console.log('Snímky hotové');
