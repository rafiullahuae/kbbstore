/* Lane CK (22 October): checkout pictures, before and after, EN and AR, 390 and 1280.
     APP_KEY=... FG_SEED=tsz-seed.php sh tools/fg-preview.sh 11640
     BASE=http://127.0.0.1:PORT TAG=before node tools/ck22-shots.cjs
   Writes docs/lane-ck/<TAG>-checkout-<lang>-<w>.png (whole page) and
   <TAG>-fields-<lang>-<w>.png (the Contact + Shipping block, the owner's view),
   and prints scrollWidth, console errors and the label colours. Local preview
   only: BASE must be 127.0.0.1. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, TAG = process.env.TAG || 'after';
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck');
fs.mkdirSync(OUT, { recursive: true });
const BASKET = [[26, 1], [5, 1], [11, 1]];
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

async function basket(page, pre) {
  await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
  for (const [id, q] of BASKET) {
    await page.evaluate(async ([pid, qty]) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: qty }) })).status, [id, q]);
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const m = w === 390;
      const ctx = await browser.newContext({ viewport: { width: w, height: m ? 844 : 900 }, deviceScaleFactor: 1, isMobile: m, hasTouch: m, userAgent: m ? UA_M : UA_D });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      const pre = lang === 'ar' ? '/ar' : '';
      await basket(page, pre);
      await page.goto(BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
      // The owner's view: Email and Full name left empty, the rest filled.
      const fill = { billing_phone: '501234567', billing_address_1: 'Marina Heights, Apt 1203', billing_address_2: 'Al Barsha 1, Street 12' };
      for (const [k, v] of Object.entries(fill)) {
        const f = page.locator(`#kbbCheckoutForm [name="${k}"]`).first();
        if (await f.count()) await f.fill(v);
      }
      const st = page.locator('#kbbCheckoutForm select[name="billing_state"]').first();
      if (await st.count()) await st.selectOption({ index: 2 }).catch(() => {});
      await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
      await page.waitForTimeout(600);
      const facts = await page.evaluate(() => {
        const lab = (id) => { const l = document.querySelector(`label[for="${id}"]`); if (!l) return null; const s = getComputedStyle(l); const star = l.querySelector('.required'); return { text: l.textContent.trim(), color: s.color, size: s.fontSize, star: star ? getComputedStyle(star).display : 'none' }; };
        return { sw: document.documentElement.scrollWidth, iw: innerWidth,
          email: lab('billing_email'), name: lab('billing_first_name'), phone: lab('billing_phone'), building: lab('billing_address_1'),
          area: lab('billing_address_2'), state: lab('billing_state'), country: lab('billing_country'),
          areaRequired: !!document.querySelector('#billing_address_2[required]'),
          optionalWords: (document.querySelector('#kbbCheckoutForm') || document.body).innerText.match(/optional|اختياري/gi) || [] };
      });
      await page.screenshot({ path: path.join(OUT, `${TAG}-checkout-${lang}-${w}.png`), fullPage: true });
      const sec = page.locator('#kbbCheckoutForm .sec').first();
      const box = await page.locator('#billing_country_field').first().boundingBox();
      const top = await sec.boundingBox();
      if (top && box) {
        await page.screenshot({ path: path.join(OUT, `${TAG}-fields-${lang}-${w}.png`), fullPage: true,
          clip: { x: 0, y: Math.max(0, top.y - 8), width: w, height: Math.min(1400, box.y + box.height - top.y + 24) } });
      }
      report.push({ lang, w, ...facts, errors });
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, `${TAG}-facts.json`), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
