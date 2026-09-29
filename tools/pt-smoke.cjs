/*
 * A round trip through the real screen, in a real browser. (Lane PT)
 *
 * The suite drives the endpoints and the screen test reads the source. Neither
 * proves the two are joined up: a payload key spelt one way in the browser and
 * another on the server passes both and saves nothing. So this adds a tab,
 * overrides one, and reads the PRODUCT PAGE back to see it.
 */
const { chromium } = require('playwright');

const BASE = process.env.PT_BASE || 'http://127.0.0.1:8977';
const SLUG = 'lanept-ceramide-moisturiser';

async function tabs(page) {
  await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
  return page.evaluate(() => [...document.querySelectorAll('.dtab')].map((n) => n.textContent.trim()));
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 } });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageerror: String(e) })));

  console.log(JSON.stringify({ step: 'before', tabs: await tabs(page) }));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('product-tabs'));
  await page.waitForTimeout(1500);

  await page.fill('[data-kpt-q]', 'Ceramide Barrier');
  await page.click('[data-kpt-find]');
  await page.waitForTimeout(1000);
  await page.click('[data-kpt-pick]');
  await page.waitForTimeout(1200);

  /* 1. A tab of this product's own, typed into the real form. */
  await page.click('[data-kpt-open="new-own"]');
  await page.waitForTimeout(600);
  await page.fill('.kpt-edit [data-kpt-title]', 'Smoke tab');
  await page.click('.kpt-edit [data-kpt-body]');
  await page.type('.kpt-edit [data-kpt-body]', 'Written through the screen.', { delay: 8 });
  await page.click('[data-kpt-save="new-own"]');
  await page.waitForTimeout(1600);

  console.log(JSON.stringify({ step: 'after own tab', tabs: await tabs(page) }));

  /* 2. Override an inherited global tab's title on this product only. */
  await page.goBack({ waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.go('product-tabs'));
  await page.waitForTimeout(1500);
  await page.fill('[data-kpt-q]', 'Ceramide Barrier');
  await page.click('[data-kpt-find]');
  await page.waitForTimeout(1000);
  await page.click('[data-kpt-pick]');
  await page.waitForTimeout(1200);

  const key = await page.evaluate(() => {
    const b = [...document.querySelectorAll('[data-kpt-over]')]
      .find((n) => n.getAttribute('data-kpt-over').startsWith('global:'));
    return b ? b.getAttribute('data-kpt-over') : null;
  });

  await page.click(`[data-kpt-over="${key}"]`);
  await page.waitForTimeout(700);
  await page.fill('.kpt-edit [data-kpt-title]', 'Shipping (this one is heavy)');
  await page.click(`[data-kpt-saveover="${key}"]`);
  await page.waitForTimeout(1600);

  console.log(JSON.stringify({ step: 'after override', key, tabs: await tabs(page) }));

  /* 3. And the OTHER product must be untouched by that override. */
  await page.goto(`${BASE}/product/lanept-led-mask/`, { waitUntil: 'networkidle' });
  console.log(JSON.stringify({
    step: 'other product unaffected',
    tabs: await page.evaluate(() => [...document.querySelectorAll('.dtab')].map((n) => n.textContent.trim())),
  }));

  await browser.close();
})();
