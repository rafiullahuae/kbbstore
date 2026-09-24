/**
 * Lane SEO round 2 — the URL map on Store → Import → Addresses & pictures.
 *
 * The suite proves the buckets. This proves the thing the owner actually sees:
 * how many redirects the import proposes to write, and how many questions it
 * puts in front of him, before and after.
 *
 *   KBB_SEO_URL=http://127.0.0.1:8977 KBB_SEO_OUT=docs/seo-import-shots \
 *   node tests/browser/seo-import-senses-urls.mjs
 *
 * Signs in as seo@example.test / secret-secret. Point it at a preview, never at
 * production — it READS the map but the screen it opens can write rows.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_SEO_URL || 'http://127.0.0.1:8977';
const OUT = process.env.KBB_SEO_OUT || 'docs/seo-import-shots';
const CHROME = process.env.KBB_SEO_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });

for (const [label, width, height] of [['390', 390, 844], ['1280', 1280, 900]]) {
  const ctx = await b.newContext({ viewport: { width, height } });
  const p = await ctx.newPage();

  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[type="email"]', 'seo@example.test');
  await p.fill('input[type="password"]', 'secret-secret');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);

  // Below 880px the console's sidebar is off-canvas until the hamburger is
  // pressed (app.blade.php:677). Opening it is what the owner does on a phone.
  if (width < 880) {
    await p.locator('.menubtn').click();
    await p.waitForTimeout(400);
  }

  await p.locator('#nav .nav-group[data-sec="Store"] .nav-gh').click();
  await p.waitForTimeout(500);
  await p.locator('[data-go="import"]').first().click();
  await p.waitForSelector('#impDrop', { timeout: 15000 });
  await p.waitForTimeout(800);

  // "Have a look" builds the map. It is a read; nothing is written.
  const load = p.locator('#gbUMLoad');
  if (await load.count()) {
    await load.click();
    await p.waitForTimeout(3500);
  }

  const card = await p.evaluate(() => {
    const el = [...document.querySelectorAll('b')].find(n => /Addresses & pictures/.test(n.textContent));
    const box = el?.closest('.card');
    if (!box) return { found: false };
    const tiles = [...box.querySelectorAll('.impfile')].map(t => t.textContent.replace(/\s+/g, ' ').trim());
    const r = box.getBoundingClientRect();
    box.scrollIntoView({ block: 'center' });
    return {
      found: true,
      tiles,
      width: Math.round(r.width),
      height: Math.round(r.height),
      note: (box.querySelector('p')?.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 200),
    };
  });

  await p.waitForTimeout(400);

  console.log(`[${label}px]`, JSON.stringify(card));
  console.log(`[${label}px]`, JSON.stringify(await p.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }))));

  await p.screenshot({ path: `${OUT}/${label}-01-url-map.png` });
  await ctx.close();
}

await b.close();
