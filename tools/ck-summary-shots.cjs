/*
 * Lane CK part 2: the checkout summary as one thin row.
 *
 *   node tools/ck-summary-shots.cjs <out-dir> <tag> [port] [ar|admin]
 *
 * Shoots the row folded and opened at 390 and 1280 (390 only for Arabic), three
 * frames of the arrow's animation, and with `admin` the switches on
 * Appearance -> Checkout page -> Fields & attention. Numbers are read in the
 * browser after layout.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [out, tag, port = '8661', mode] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = mode === 'ar' ? '/ar' : '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  if (mode === 'admin') {
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, userAgent: UA })).newPage();
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('checkoutpage'));
    await page.waitForTimeout(1500);
    await page.click('[data-chp-tab="cues"]');
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${out}/${tag}-admin-checkout-fields.png`, fullPage: true });
    report.labels = await page.evaluate(() => [...document.querySelectorAll('label, .chp-field b, .chp-field span')]
      .map((e) => e.textContent.trim()).filter((t) => /Address picker|Order summary: collapsed|Recently browsed/.test(t)).slice(0, 6));
    await browser.close();
    console.log(JSON.stringify(report, null, 2));
    return;
  }

  const ids = JSON.parse(await (await fetch(BASE + '/ck-ids.json')).text());
  const vps = mode === 'ar' ? [390] : [390, 1280];

  for (const w of vps) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 844 }, deviceScaleFactor: 2, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
    for (const id of ids) {
      await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
        body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
    }
    await page.goto(BASE + P + '/checkout/', { waitUntil: 'networkidle' });
    const key = `${tag}-${mode === 'ar' ? 'ar-' : ''}${w}`;
    const row = page.locator('#kbbSumRow');
    const hasRow = (await row.count()) > 0;

    if (hasRow) {
      // Three frames of the arrow, a third of its 1.6s period apart.
      for (const [i, t] of [[1, 0], [2, 530], [3, 530]]) {
        await page.waitForTimeout(t);
        await row.screenshot({ path: `${out}/${key}-row-frame${i}.png` });
        report[`${key}-chev-transform-${i}`] = await page.evaluate(() => getComputedStyle(document.querySelector('.cosr-chev')).transform);
      }
    }

    await page.screenshot({ path: `${out}/${key}-summary-folded.png` });
    report[key + '-folded'] = await page.evaluate(() => {
      const r = (s) => { const e = document.querySelector(s); return e ? Math.round(e.getBoundingClientRect().height * 10) / 10 : null; };
      const vis = (s) => { const e = document.querySelector(s); return !!e && e.getClientRects().length > 0; };
      const row = document.getElementById('kbbSumRow');
      return {
        rowHeight: r('#kbbSumRow'), summaryHeight: r('#kbbSummary'),
        rowTotal: row ? row.querySelector('.cosr-tot').innerText : null,
        blockTotal: (document.querySelector('#kbbSummary .js-total-row .js-total') || {}).textContent,
        ariaExpanded: row ? row.getAttribute('aria-expanded') : null,
        itemsVisible: vis('#kbbSummary .co-items'), placeVisible: vis('#kbbSummary .place'),
        browsedTab: !!document.querySelector('[data-stab="browsed"]'),
        chevColor: row ? getComputedStyle(row.querySelector('.cosr-chev')).color : null,
        textSize: row ? getComputedStyle(row.querySelector('.cosr-tx')).fontSize : null,
        dir: document.documentElement.dir,
        scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
      };
    });

    if (hasRow) {
      await row.click();
      await page.waitForTimeout(350);
      await page.screenshot({ path: `${out}/${key}-summary-open.png` });
      await page.locator('#kbbSummary').screenshot({ path: `${out}/${key}-summary-open-card.png` });
      report[key + '-open'] = await page.evaluate(() => ({
        ariaExpanded: document.getElementById('kbbSumRow').getAttribute('aria-expanded'),
        summaryHeight: Math.round(document.getElementById('kbbSummary').getBoundingClientRect().height),
        itemsVisible: document.querySelector('#kbbSummary .co-items').getClientRects().length > 0,
        scrollWidth: document.documentElement.scrollWidth,
      }));
      // Choose cash on delivery: the row follows the order block's COD total.
      if (await page.locator('#payment_method_cod').count()) {
        await page.locator('#payment_method_cod').check({ force: true });
        await page.waitForTimeout(300);
        report[key + '-cod'] = await page.evaluate(() => ({
          rowTotal: document.querySelector('#kbbSumRow .cosr-tot').innerText,
          blockTotal: (document.querySelector('#kbbSummary .js-total-row-fee .js-total-fee') || {}).textContent,
        }));
      }
    }
    report[key + '-errors'] = errors;
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${out}/${tag}${mode ? '-' + mode : ''}-summary-report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
