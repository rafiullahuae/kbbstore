/**
 * Lane NV — Appearance → Header → Navigation, where the fit controls live.
 *
 *   KBB_NV_URL=http://127.0.0.1:9892 KBB_NV_OUT=storage/nv-logs/shots \
 *   KBB_NV_EMAIL=nv@preview.test KBB_NV_PASSWORD=nv-secret-123 node tests/browser/nv-nav-admin.mjs
 *
 * Signs in to the console of a PREVIEW, never production.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_NV_URL || 'http://127.0.0.1:9892';
const OUT = process.env.KBB_NV_OUT || 'storage/nv-logs/shots';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });

for (const [width, height] of [[1280, 1000], [390, 1400]]) {
  const p = await (await b.newContext({ viewport: { width, height } })).newPage();
  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', process.env.KBB_NV_EMAIL || 'nv@preview.test');
  await p.fill('input[type="password"]', process.env.KBB_NV_PASSWORD || 'nv-secret-123');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
  if (width < 880) { await p.locator('.menubtn').click(); await p.waitForTimeout(400); }
  await p.locator('#nav .nav-group[data-sec="Appearance"] .nav-gh').click();
  await p.waitForTimeout(400);
  await p.locator('[data-go="header"]').first().click();
  await p.waitForSelector('[data-hdtab="nav"]', { timeout: 15000 });
  await p.locator('[data-hdtab="nav"]').click();
  await p.waitForTimeout(800);
  const info = await p.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    text: document.querySelector('.mmbody')?.innerText.replace(/\s+/g, ' ').slice(0, 900),
  }));
  console.log(width, JSON.stringify(info));
  await p.screenshot({ path: `${OUT}/admin-header-navigation-${width}.png`, fullPage: width < 880 });
}
await b.close();
