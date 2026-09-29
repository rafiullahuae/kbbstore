/**
 * Lane SEC round two — the drawer notice, at 390 and 1280.
 *
 * A shopper with the set already in the bag presses Add to bag on the toner
 * while one is on the shelf. The line is taken straight back out, and the
 * drawer is what they are looking at while it happens.
 *
 *   KBB_SEC_URL=http://127.0.0.1:8641 KBB_SEC_SET_ID=26 KBB_SEC_TONER_ID=25 \
 *   KBB_SEC_OUT=docs/sec-shots node tests/browser/sec-drawer-shots.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_SEC_URL || 'http://127.0.0.1:8641';
const OUT = process.env.KBB_SEC_OUT || 'docs/sec-shots';
const SET_ID = Number(process.env.KBB_SEC_SET_ID);
const TONER_ID = Number(process.env.KBB_SEC_TONER_ID);
const CHROME = process.env.KBB_SEC_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

mkdirSync(OUT, { recursive: true });

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const measured = {};

for (const [w, h, tag] of [[390, 844, '390'], [1280, 1000, '1280']]) {
  const ctx = await b.newContext({ viewport: { width: w, height: h } });
  const p = await ctx.newPage();

  await p.goto(`${BASE}/product/sec-medicube-booster-set`, { waitUntil: 'networkidle' });

  // The set goes in first, through the endpoint the Add-to-bag button posts to.
  await p.evaluate(async (id) => {
    const token = (window.KBB && window.KBB.csrf) || document.querySelector('meta[name="csrf-token"]')?.content;
    await fetch('/api/cart/add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ product_id: id, quantity: 1 }),
    });
  }, SET_ID);

  // Then the toner, pressed the way a shopper presses it: the real button on
  // the real product page, so the drawer opens the way it opens for them.
  await p.goto(`${BASE}/product/sec-1025-dokdo-toner`, { waitUntil: 'networkidle' });
  await p.locator('#mainAdd').click();
  await p.waitForTimeout(2500);

  await p.screenshot({ path: `${OUT}/${tag}-drawer-notice.png` });

  const notice = p.locator('.kc-note').first();
  measured[`${tag}.notice_visible`] = await notice.isVisible().catch(() => false);
  measured[`${tag}.notice`] = (await notice.textContent().catch(() => '') || '').trim();
  measured[`${tag}.rows`] = await p.locator('.dbody .kc-item').count();
  measured[`${tag}.count_badge`] = (await p.locator('.kc-tab.on .n').first().textContent().catch(() => '') || '').trim();
  measured[`${tag}.geometry`] = await p.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  measured[`${tag}.notice_box`] = await notice.boundingBox().catch(() => null);

  await ctx.close();
}

console.log(JSON.stringify(measured, null, 2));
await b.close();
