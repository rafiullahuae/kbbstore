/*
 * Lane PY -- the SHIPPED payment boxes, through the real checkout (no
 * injection: the logos and classes are the server's), plus the admin tab.
 *
 *   sh tools/pay-preview.sh                         # English shop
 *   node tools/pay-final-shots.cjs docs/lane-py-shots <port>
 *   PAY_AR=1 sh tools/pay-preview.sh && node tools/pay-final-shots.cjs docs/lane-py-shots <port> ar
 *
 * Writes final-{tabby,tamara}-{390,1280}.png (or -ar-390), final-today-*,
 * final-admin-{1280,390}.png and final-measurements*.json. Every number is
 * read in the browser after layout (a camera may measure; the shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [out, port = '10880', ar] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = ar ? '/ar' : '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
// .kbt-z is the shop's fixed side tab; it floats over the left edge of every element shot.
const CALM = '*,*::before,*::after{animation:none!important;transition:none!important}.kbt-z{visibility:hidden!important}';
const VIEWPORTS = ar ? [{ w: 390, h: 844 }] : [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

async function admin(browser, width, settings) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1, userAgent: UA });
  const page = await ctx.newPage();
  await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.abort());
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  if (settings) {
    const st = await page.evaluate(async (s) => {
      const r = await fetch('/admin-api/checkout-page', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) || [])[1] || '') },
        body: JSON.stringify({ settings: s }) });
      return r.status;
    }, settings);
    await ctx.close();
    return st;
  }
  await page.evaluate(() => window.go('checkoutpage'));
  await page.waitForSelector('[data-chp-tab="payments"]', { timeout: 15000 });
  await page.click('[data-chp-tab="payments"]');
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(out, `final-admin-${width}.png`), fullPage: true });
  // The console scrolls inside its own column, so the lower controls get a second frame.
  await page.evaluate(() => { const all = [...document.querySelectorAll('#content select, #content input')].filter((e) => e.offsetParent !== null); all[all.length - 1].scrollIntoView({ block: 'end' }); });
  await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(out, `final-admin-${width}-lower.png`) });
  const fields = await page.evaluate(() => [...document.querySelectorAll('#content input, #content select')]
    .filter((e) => e.offsetParent !== null).map((e) => e.name || e.getAttribute('data-k') || e.type).slice(0, 40));
  await ctx.close();
  return fields;
}

async function checkout(browser, vp, tag, report) {
  const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2, userAgent: UA });
  const page = await ctx.newPage();
  await page.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.abort());
  await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
  const ids = JSON.parse(await (await fetch(BASE + '/pay-ids.json')).text());
  for (const id of ids) {
    const st = await page.evaluate(async (pid) => (await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
      body: JSON.stringify({ product_id: pid, quantity: 1 }) })).status, id);
    if (st !== 200) throw new Error('cart add ' + id + ' answered ' + st);
  }
  await page.goto(BASE + P + '/checkout', { waitUntil: 'domcontentloaded' });
  if (!/\/checkout/.test(page.url())) throw new Error('checkout redirected to ' + page.url());
  await page.waitForTimeout(600);
  await page.addStyleTag({ content: CALM });
  for (const pick of ['tabby', 'tamara']) {
    await page.check('#payment_method_' + pick, { force: true });
    await page.waitForTimeout(120);
    const key = `final-${tag}${pick}-${ar ? 'ar-' : ''}${vp.w}`;
    await (await page.$('.sec.pay')).screenshot({ path: path.join(out, key + '.png') });
    report[key] = await page.evaluate(() => {
      const r1 = (n) => Math.round(n * 10) / 10;
      const sec = document.querySelector('.kbb-checkout');
      return {
        sectionClass: sec.className,
        scrollWidth: document.documentElement.scrollWidth,
        items: [...document.querySelectorAll('li.wc_payment_method')].map((li) => {
          const lab = li.querySelector('label'); const logo = li.querySelector('.pay-logo');
          const lr = logo && logo.getBoundingClientRect(); const cs = getComputedStyle(lab);
          return { id: li.querySelector('input').value, cls: li.className, li: r1(li.getBoundingClientRect().height),
            label: r1(lab.getBoundingClientRect().height), font: cs.fontSize + '/' + cs.fontWeight,
            logo: lr ? `${r1(lr.width)}x${r1(lr.height)} @x${r1(lr.left)}` : null,
            bg: getComputedStyle(li).backgroundImage.slice(0, 48) };
        }),
      };
    });
  }
  if (vp.w === 390 && !tag) {
    const a = await (await page.$('li.payment_method_tabby')).boundingBox();
    const b = await (await page.$('li.payment_method_tamara > label')).boundingBox();
    await page.screenshot({ path: path.join(out, `final-zoom-logos-${ar ? 'ar-' : ''}390.png`),
      clip: { x: a.x - 4, y: a.y - 4, width: a.width + 8, height: b.y + b.height - a.y + 8 } });
  }
  await ctx.close();
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const vp of VIEWPORTS) await checkout(browser, vp, '', report);
  if (!ar) {
    report.adminFields1280 = await admin(browser, 1280);
    await admin(browser, 390);
    // "Today": the way back, through the same Save the screen uses.
    report.saveToday = await admin(browser, 1280, { pay_style: 'plain' });
    await checkout(browser, { w: 390, h: 844 }, 'today-', report);
    report.saveSoft = await admin(browser, 1280, { pay_style: 'soft' });
  }
  await browser.close();
  fs.writeFileSync(path.join(out, `final-measurements${ar ? '-ar' : ''}.json`), JSON.stringify(report, null, 1));
  console.log('final shots written to', out);
})();
