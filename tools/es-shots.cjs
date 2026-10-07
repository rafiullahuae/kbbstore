/*
 * Lane AD screenshots and measurements: the checkout's Shipping address with
 * the Emirate as a list (and the typed box, for "before"), the Oman switch,
 * and My account -> Addresses.
 *
 *   node tools/es-shots.cjs <out-dir> <tag> [port] [ar]
 *
 * A native <select>'s open list is drawn by the browser outside the page and
 * no screenshot can catch it, so "list open" is the same element given
 * size=N for the shot -- the same options, text and order, laid out in place.
 * Every number is read in the browser after layout (a camera may measure; the
 * shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [out, tag, port = '8761', ar] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = ar ? '/ar' : '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const MOBILE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const CALM = `*,*::before,*::after{animation-play-state:paused!important;transition-duration:0s!important}`;
const VIEWPORTS = ar ? [{ w: 390, h: 844 }, { w: 1280, h: 900 }] : [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

const measure = () => {
  const f = (id) => { const e = document.getElementById(id); if (!e) return null; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e);
    const c = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return { tag: e.tagName, value: e.value, top: Math.round(r.top), left: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height), font: cs.fontSize,
      clickable: c === e || e.contains(c) || (c && c.closest('label') && c.closest('label').htmlFor === id) || false,
      label: (document.querySelector('label[for="' + id + '"]') || {}).textContent }; };
  const sel = document.getElementById('billing_state');
  return {
    address: f('billing_address_1'), city: f('billing_city'), emirate: f('billing_state'), country: f('billing_country'),
    options: sel && sel.tagName === 'SELECT' ? Array.from(sel.options).map((o) => o.text) : null,
    delivery: (document.getElementById('kbbDeliverySlot') || {}).innerText,
    scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
    touchMedia: matchMedia('(hover:none),(pointer:coarse)').matches,
    htmlBytes: document.documentElement.outerHTML.length,
  };
};

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const ids = JSON.parse(await (await fetch(BASE + '/es-ids.json')).text());
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const vp of VIEWPORTS) {
    for (const who of ['guest', 'aisha']) {
      const touch = vp.w < 500;
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2,
        userAgent: touch ? MOBILE_UA : UA, hasTouch: touch, isMobile: touch });
      const page = await ctx.newPage();
      const errors = [];
      const requests = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('request', (r) => requests.push(r.method() + ' ' + r.url().replace(BASE, '')));

      await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      if (who !== 'guest') {
        report['login'] = await page.evaluate(async (email) => {
          const r = await fetch('/my-account/login', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'text/html' },
            body: 'email=' + encodeURIComponent(email) + '&password=preview-secret-1' });
          return r.status;
        }, who + '@preview.test');
        await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      }
      for (const id of ids) {
        await page.evaluate(async (pid) => {
          await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
            body: JSON.stringify({ product_id: pid, quantity: 1 }) });
        }, id);
      }

      const key = `${tag}-${ar ? 'ar-' : ''}${who}-${vp.w}`;
      requests.length = 0;
      await page.goto(BASE + P + '/checkout/', { waitUntil: 'networkidle' });
      report[key + '-requests-on-load'] = requests.filter((r) => !r.includes('/build/') || true).length;
      report[key + '-request-list'] = requests.slice();
      await page.addStyleTag({ content: CALM });
      await page.waitForTimeout(300);
      if ((await page.locator('.sec').count()) < 2) { throw new Error('no checkout at ' + page.url()); }
      const sec = page.locator('.sec').nth(1);
      await sec.evaluate((e) => e.scrollIntoView({ block: 'center' }));
      await page.screenshot({ path: `${out}/${key}-checkout.png` });
      await sec.screenshot({ path: `${out}/${key}-address.png` });
      report[key + '-closed'] = await page.evaluate(measure);

      const isList = await page.evaluate(() => document.getElementById('billing_state').tagName === 'SELECT');
      if (isList) {
        // "List open": the same select laid out in place.
        await page.evaluate(() => { const s = document.getElementById('billing_state'); s.setAttribute('size', String(s.options.length)); s.style.height = 'auto'; s.style.backgroundImage = 'none'; });
        await sec.screenshot({ path: `${out}/${key}-address-list-open.png` });
        await page.evaluate(() => { const s = document.getElementById('billing_state'); s.removeAttribute('size'); s.style.height = ''; s.style.backgroundImage = ''; });

        // A real choice, by a real click on the select, re-prices once.
        requests.length = 0;
        await page.locator('#billing_state').click({ trial: true });
        await page.selectOption('#billing_state', 'Sharjah');
        await page.waitForTimeout(1200);
        report[key + '-after-choosing-sharjah'] = { requests: requests.slice(), value: await page.inputValue('#billing_state') };

        if (who === 'guest') {
          // Oman: the list and the label follow the country, with no request
          // but the one re-pricing the delivery.
          requests.length = 0;
          await page.selectOption('#billing_country', 'OM');
          await page.waitForTimeout(1500);
          report[key + '-oman'] = await page.evaluate(measure);
          report[key + '-oman-requests'] = requests.slice();
          await sec.evaluate((e) => e.scrollIntoView({ block: 'center' }));
          await sec.screenshot({ path: `${out}/${key}-address-oman.png` });
          await page.evaluate(() => { const s = document.getElementById('billing_state'); s.setAttribute('size', String(s.options.length)); s.style.height = 'auto'; s.style.backgroundImage = 'none'; });
          await sec.screenshot({ path: `${out}/${key}-address-oman-list-open.png` });
          await page.evaluate(() => { const s = document.getElementById('billing_state'); s.removeAttribute('size'); s.style.height = ''; s.style.backgroundImage = ''; });
          await page.selectOption('#billing_state', 'Muscat');
          await page.waitForTimeout(1200);
          await sec.screenshot({ path: `${out}/${key}-address-oman-muscat.png` });
          report[key + '-oman-muscat'] = await page.evaluate(measure);
          // And back to the UAE: the seven emirates again.
          await page.selectOption('#billing_country', 'AE');
          await page.waitForTimeout(1200);
          report[key + '-back-to-uae'] = await page.evaluate(measure);
        }
      }
      report[key + '-errors'] = errors.slice();

      if (who === 'aisha') {
        await page.goto(BASE + P + '/my-account/edit-address', { waitUntil: 'networkidle' });
        await page.addStyleTag({ content: CALM });
        const edit = page.locator('.ab-acts a.ab-link').first();
        if (await edit.count()) { await edit.click(); await page.waitForLoadState('networkidle'); await page.addStyleTag({ content: CALM }); }
        const form = page.locator('.ab-form');
        await form.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await form.screenshot({ path: `${out}/${key}-account-form.png` });
        report[key + '-account'] = await page.evaluate(() => {
          const st = document.querySelector('[name="state"]'); const co = document.querySelector('[name="country"]');
          const r = st.getBoundingClientRect(); const c = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
          return { tag: st.tagName, value: st.value, h: Math.round(r.height), font: getComputedStyle(st).fontSize,
            stateTop: Math.round(r.top), countryTop: Math.round(co.getBoundingClientRect().top),
            label: (document.getElementById('ab-state-label') || st.parentElement.querySelector('span')).textContent,
            clickable: c === st || st.contains(c), touchMedia: matchMedia('(hover:none),(pointer:coarse)').matches, scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth };
        });
        if (await page.locator('#ab-country').count()) {
          await page.selectOption('#ab-country', 'SA');
          await page.waitForTimeout(200);
          report[key + '-account-saudi'] = await page.evaluate(() => ({ label: document.getElementById('ab-state-label').textContent,
            options: document.getElementById('ab-state').options.length }));
          await form.screenshot({ path: `${out}/${key}-account-form-saudi.png` });
        }
        report[key + '-account-errors'] = errors.slice();
      }
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(`${out}/${tag}${ar ? '-ar' : ''}-report.json`, JSON.stringify(report, null, 2));
  console.log('done', Object.keys(report).length);
})();
