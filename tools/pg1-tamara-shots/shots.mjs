/* One viewport per invocation, so the BEFORE is a freshly seeded shop.
   Usage: node shots.mjs <width> <label>   (re-seed between invocations) */
import { chromium } from 'playwright';
const BASE = 'http://127.0.0.1:8941';
const OUT = '/tmp/pg1-shots';
const [W, LABEL] = [Number(process.argv[2]), process.argv[3]];

const measure = () => {
  const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null;
  const inputs = [...document.querySelectorAll('[data-payg="tamara"]')];
  const byKey = {};
  inputs.forEach(i => {
    const r = i.getBoundingClientRect();
    byKey[i.dataset.payf] = { type: i.type || i.tagName.toLowerCase(), value: i.value,
      w: Math.round(r.width), h: Math.round(r.height), fontSize: px(i, 'fontSize') };
  });
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    count: inputs.length,
    keys: inputs.map(i => i.dataset.payf),
    fields: byKey,
  };
};

async function openTamara(page) {
  await page.goto(BASE + '/admin#payments', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1400);
  await page.evaluate(() => window.go('payments'));
  await page.waitForTimeout(2000);
  await page.evaluate(() => {
    const t = [...document.querySelectorAll('[data-paytab]')].find(x => x.dataset.paytab === 'tamara');
    if (t) t.click();
  });
  await page.waitForTimeout(1000);
  // Bring the first NEW field to the top of the shot.
  await page.evaluate(() => {
    const el = document.querySelector('[data-payg="tamara"][data-payf="excluded_products"]')
      || document.querySelector('[data-payg="tamara"][data-payf="payment_type"]');
    if (el) el.closest('.ecopt')?.scrollIntoView({ block: 'center' });
  });
  await page.waitForTimeout(400);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: W, height: 1600 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@pg1.test');
  await page.fill('input[name=password]', 'pg1-preview-secret');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }),
                     page.click('button[type=submit], input[type=submit]')]);

  await openTamara(page);
  const before = await page.evaluate(measure);
  await page.screenshot({ path: `${OUT}/before-${LABEL}.png`, fullPage: true });

  const api = await page.evaluate(async () => {
    const csrf = document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1];
    const call = async (m, u, b) => {
      const r = await fetch(u, { method: m, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json',
                   'X-XSRF-TOKEN': decodeURIComponent(csrf || '') },
        body: b ? JSON.stringify(b) : undefined });
      return { status: r.status, body: await r.json().catch(() => null) };
    };
    return {
      register: await call('POST', '/admin-api/payments/tamara/webhook'),
      limits: await call('POST', '/admin-api/payments/tamara/limits', { country: 'AE', currency: 'AED' }),
      // The sweep: one pending order the seed planted, which Tamara reports as
      // approved. Its report is what proves the button does the work.
      sweep: await call('POST', '/admin-api/payments/tamara/sweep', { minutes: 0 }),
      state: await call('GET', '/admin-api/payments/tamara'),
    };
  });

  await openTamara(page);
  const after = await page.evaluate(measure);
  await page.screenshot({ path: `${OUT}/after-${LABEL}.png`, fullPage: true });

  console.log(JSON.stringify({ label: LABEL, before, after, api }, null, 1));
  await browser.close();
})();
