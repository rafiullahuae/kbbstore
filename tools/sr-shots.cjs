/*
 * Lane SR -- Store -> Payments -> Stripe, and the checkout card step.
 *
 *   sh tools/sr-preview.sh                 # prints the port
 *   node tools/sr-shots.cjs <out-dir> <port> <tag> [admin|checkout]
 *
 * admin     the Stripe card at 390 and 1280: whole card, the status block, and
 *           the log opened; scrollWidth of the document and of #content.
 * checkout  the checkout's payment step at 390 and 1280 (tag = before/after),
 *           plus HTML bytes and console errors. Every request off 127.0.0.1 is
 *           aborted, so js.stripe.com never loads: the card iframes are absent
 *           in BOTH shots, which is what makes before and after comparable.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [out, port = '10880', tag = 'after', only = ''] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
// A desktop Chrome UA: BlockGate refuses a headless one at the till as a bot.
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const CALM = '*,*::before,*::after{animation:none!important;transition:none!important}.kbt-z{visibility:hidden!important}';

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: only === 'admin' ? 6000 : 900 }, deviceScaleFactor: 1, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.abort());

    if (!only || only === 'admin') {
      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
      await page.goto(BASE + '/admin#payments/stripe', { waitUntil: 'networkidle' });
      await page.waitForSelector('#srs-mode', { timeout: 15000 });
      await page.addStyleTag({ content: CALM });
      await page.waitForTimeout(500);

      const card = page.locator('[data-paycard="stripe"]');
      await card.screenshot({ path: path.join(out, `stripe-card-${w}.png`) });
      await page.locator('#srs-panel').screenshot({ path: path.join(out, `stripe-status-${w}.png`) });

      await page.click('#srs-log-btn');
      await page.waitForSelector('#srs-log', { timeout: 10000 });
      await page.locator('#srs-panel').screenshot({ path: path.join(out, `stripe-status-log-${w}.png`) });

      report['admin-' + w] = await page.evaluate(() => ({
        docScrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        contentScrollWidth: (document.querySelector('#content') || {}).scrollWidth,
        contentClientWidth: (document.querySelector('#content') || {}).clientWidth,
        badge: document.querySelector('#srs-mode b').textContent,
        fields: [...document.querySelectorAll('[data-paycard="stripe"] [data-payf]')].map((e) => e.dataset.payf),
        panelHeight: Math.round(document.querySelector('#srs-panel').getBoundingClientRect().height),
      }));
      report['admin-' + w].consoleErrors = errors.splice(0);
    }

    if (!only || only === 'checkout') {
      const ids = JSON.parse(await (await fetch(BASE + '/pay-ids.json')).text());
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
      for (const id of ids) {
        await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
          body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
      }
      errors.splice(0);
      const res = await page.goto(BASE + '/checkout', { waitUntil: 'domcontentloaded' });
      const html = await res.text();
      await page.waitForTimeout(800);
      await page.addStyleTag({ content: CALM });
      const card = page.locator('input[value="stripe"]').first();
      if (await card.count()) { await card.check({ force: true }).catch(() => {}); }
      await page.waitForTimeout(300);
      const pay = page.locator('#payment, .kbb-payment, .wc_payment_methods').first();
      if (await pay.count()) {
        await pay.scrollIntoViewIfNeeded();
        await pay.screenshot({ path: path.join(out, `checkout-card-${tag}-${w}.png`) });
      }
      await page.screenshot({ path: path.join(out, `checkout-page-${tag}-${w}.png`), fullPage: true });
      report['checkout-' + w] = await page.evaluate((bytes) => ({
        htmlBytes: bytes,
        scrollWidth: document.documentElement.scrollWidth,
        scripts: document.querySelectorAll('script').length,
        stripeBox: !!document.querySelector('.payment_method_stripe, [data-gateway="stripe"], input[value="stripe"]'),
      }), Buffer.byteLength(html));
      report['checkout-' + w].consoleErrors = errors.filter((e) => !/net::ERR_FAILED|ERR_BLOCKED|Failed to load resource/.test(e));
    }

    await ctx.close();
  }

  fs.writeFileSync(path.join(out, `report-${only || 'all'}-${tag}.json`), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})();
