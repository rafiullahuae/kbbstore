/*
 * Lane CK screenshots and measurements: the cart's docked bar and the
 * checkout's Shipping address section, with the address picker row on
 * ("before" -- today's shop) and off ("after" -- as shipped).
 *
 *   node tools/ck-shots.cjs <out-dir> <tag> [port] [ar]
 *
 * Every number is read in the browser after layout (a camera may measure; the
 * shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [out, tag, port = '8661', ar] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = ar ? '/ar' : '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const CALM = `*,*::before,*::after{animation-play-state:paused!important;transition-duration:0s!important}`;
const VIEWPORTS = ar ? [{ w: 390, h: 844 }] : [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const ids = JSON.parse(await (await fetch(BASE + '/ck-ids.json')).text());
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const vp of VIEWPORTS) {
    for (const who of ar ? ['guest'] : ['guest', 'member']) {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2, userAgent: UA });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

      await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      if (who === 'member') {
        const st = await page.evaluate(async () => {
          const r = await fetch('/my-account/login', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'text/html' },
            body: 'email=aisha%40preview.test&password=preview-secret-1' });
          return r.status;
        });
        report['login'] = st;
        // Signing in regenerates the session, so the token the page carried is stale.
        await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      }
      for (const id of ids) {
        report['add-' + id] = await page.evaluate(async (pid) => {
          const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
            body: JSON.stringify({ product_id: pid, quantity: 1 }) });
          const j = await r.json().catch(() => ({}));
          return r.status + ' count=' + j.count + ' ' + (j.error || '');
        }, id); console.log('add', id, report['add-' + id]);
      }

      const key = `${tag}-${ar ? 'ar-' : ''}${who}-${vp.w}`;

      if (who === 'guest') {
        await page.goto(BASE + P + '/cart/', { waitUntil: 'networkidle' });
        await page.addStyleTag({ content: CALM });
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${out}/${key}-cart.png` });
        report[key + '-cart'] = await page.evaluate(() => {
          const h = (s) => { const e = document.querySelector(s); return e ? Math.round(e.getBoundingClientRect().height * 10) / 10 : null; };
          const wrap = document.querySelector('.kbb-cartpage .wrap');
          return {
            docked: h('.cpg-docked'), addrRow: h('.cpg-addrbar'), checkoutRow: h('.cpg-cobar'),
            wrapPaddingBottom: wrap ? getComputedStyle(wrap).paddingBottom : null,
            scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
          };
        });
      }

      await page.goto(BASE + P + '/checkout/', { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: CALM });
      await page.waitForTimeout(400);
      if ((await page.locator('.sec').count()) < 2) { throw new Error('no checkout at ' + page.url() + ' ' + (await page.title())); }
      const sec = page.locator('.sec').nth(1);
      await sec.evaluate((e) => e.scrollIntoView({ block: 'center' }));
      await page.screenshot({ path: `${out}/${key}-checkout.png` });
      await sec.screenshot({ path: `${out}/${key}-checkout-address.png` });
      report[key + '-checkout'] = await page.evaluate(() => {
        const sec = document.querySelectorAll('.sec')[1];
        const f = (id) => { const e = document.getElementById(id); if (!e) return null; const cs = getComputedStyle(e);
          return { type: e.type, value: e.value, h: Math.round(e.getBoundingClientRect().height), font: cs.fontSize }; };
        return {
          sectionHeight: Math.round(sec.getBoundingClientRect().height),
          address: f('billing_address_1'), emirate: f('billing_state'), city: f('billing_city'), country: f('billing_country'),
          pickerRow: !!document.getElementById('cka'), sheet: !!document.getElementById('cpgSheet'),
          delivery: (document.getElementById('kbbDeliverySlot') || {}).innerText,
          scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
        };
      });

      // The emirate re-prices the page (typed fields only).
      const typed = await page.evaluate(() => { const e = document.getElementById('billing_state'); return e && e.type === 'text'; });
      if (typed && who === 'guest' && !ar) {
        await page.fill('#billing_state', 'Dubai');
        await page.locator('#billing_city').focus();
        await page.waitForTimeout(1500);
        report[key + '-emirate-dubai'] = await page.evaluate(() => document.getElementById('kbbDeliverySlot').innerText);
        await page.locator('.sec').nth(1).screenshot({ path: `${out}/${key}-checkout-after-emirate.png` });
        await page.fill('#billing_state', 'Sharjah');
        await page.locator('#billing_city').focus();
        await page.waitForTimeout(1500);
        report[key + '-emirate-sharjah'] = await page.evaluate(() => document.getElementById('kbbDeliverySlot').innerText);
      }
      report[key + '-errors'] = errors;
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(`${out}/${tag}${ar ? '-ar' : ''}-report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
