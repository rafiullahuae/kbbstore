/*
 * Lane CT v3: the short contact page. Full-page shots at 320/360/390/430 and
 * 1280 (EN and AR), document height, sideways overflow, card button heights,
 * a real tap at the centre of each card button and Send at three scroll
 * positions (middle of the screen, and level with where the floating
 * WhatsApp chip and bubble used to sit), and a close-up of the form beside the
 * checkout's own fields at 390.
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/cnt-v3-shots.cjs http://127.0.0.1:10791 docs/lane-ct-shots
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2];
const OUT = path.resolve(process.argv[3] || 'docs/lane-ct-shots');
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const ctx = (b, w) => b.newContext(w < 600 ? { viewport: { width: w, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA } : { viewport: { width: w, height: 900 } });

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [320, 360, 390, 430, 1280]) {
    for (const lang of ['en', 'ar']) {
      const c = await ctx(b, w);
      await c.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
      const p = await c.newPage();
      const errs = [];
      p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 100)); });
      await p.goto(BASE + (lang === 'ar' ? '/ar' : '') + '/contact-us/', { waitUntil: 'load' });
      await p.waitForTimeout(3300);
      const m = await p.evaluate(() => {
        const over = [...document.querySelectorAll('.ctc *')].filter((e) => e.getBoundingClientRect().right > innerWidth + 0.5 || e.getBoundingClientRect().left < -0.5).map((e) => e.className || e.tagName).slice(0, 3);
        const cards = [...document.querySelectorAll('.ctc-card')].map((c) => { const r = c.getBoundingClientRect(); const g = c.querySelector('.ctc-go').getBoundingClientRect(); return `${c.dataset.ct} ${Math.round(r.left)},${Math.round(r.top + scrollY)} ${Math.round(r.width)}x${Math.round(r.height)} btn${Math.round(g.height)}`; });
        const f = document.getElementById('kbbWa');
        return { h: document.documentElement.scrollHeight, sw: document.documentElement.scrollWidth, over, cards, field: Math.round(document.querySelector('#ctc-name').getBoundingClientRect().height), float: f ? getComputedStyle(f).display : 'absent' };
      });
      const shoot = [390, 1280, 320].includes(w);
      if (shoot) await p.screenshot({ path: `${OUT}/after-v3-${lang}-${w}.png`, fullPage: true });
      let taps = '';
      if (w === 390) {
        await p.evaluate(() => document.addEventListener('click', (ev) => { ev.preventDefault(); const a = ev.target.closest('a,button'); window.__t = a ? (a.dataset.ct || a.className.split(' ')[0]) : ev.target.tagName; }, true));
        const out = [];
        for (const sel of ['[data-ct=wa]', '[data-ct=ig]', '[data-ct=email]', '.ctc-send']) {
          for (const y of [422, 642, 765]) {
            const pt = await p.evaluate(([sel, y]) => {
              const el = document.querySelector(sel); const t = el.querySelector('.ctc-go') || el;
              let r = t.getBoundingClientRect(); window.scrollTo({ top: scrollY + (r.top + r.height / 2) - y, behavior: 'instant' }); r = t.getBoundingClientRect();
              window.__t = null; return [r.left + r.width / 2, r.top + r.height / 2];
            }, [sel, y]);
            await p.touchscreen.tap(pt[0], pt[1]);
            await p.waitForTimeout(60);
            const t = await p.evaluate(() => window.__t);
            const want = sel.startsWith('[') ? sel.slice(9, -1) : 'ctc-send';
            out.push(`${want}@${Math.round(pt[1])}:${t === want ? 'ok' : 'MISS(' + t + ')'}`);
          }
        }
        taps = out.join(' ');
        if (lang === 'en') {
          await p.focus('#ctc-email');
          const box = await p.evaluate(() => { const f = document.querySelector('.ctc-formwrap'); window.scrollTo({ top: scrollY + f.getBoundingClientRect().top - 130, behavior: 'instant' }); const r = f.getBoundingClientRect(); return { x: r.left, y: r.top, width: r.width, height: Math.min(r.height, innerHeight - r.top) }; });
          await p.waitForTimeout(250);
          await p.screenshot({ path: `${OUT}/after-v3-form-390.png`, clip: box });
        }
      }
      console.log(lang, w, JSON.stringify(m), errs.length ? 'errors ' + errs.join(';') : 'errors 0', taps);
      await c.close();
    }
  }
  // The checkout's own fields at 390, for comparison: put a product in the bag first.
  const c = await ctx(b, 390);
  await c.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
  const p = await c.newPage();
  await p.goto(BASE + '/shop/', { waitUntil: 'load' });
  await p.evaluate((pid) => { window.__pid = pid; }, Number(process.env.CNT_PID || 0));
  const ok = await p.evaluate(async () => {
    const id = (document.querySelector('[data-product-id]') || {}).dataset?.productId || (document.querySelector('[data-id]') || {}).dataset?.id;
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' }, body: JSON.stringify({ product_id: Number(id || window.__pid), quantity: 1 }) });
    return id + ' ' + r.status;
  });
  await p.goto(BASE + '/checkout/', { waitUntil: 'load' });
  await p.waitForTimeout(500);
  const sec = p.locator('#billing_first_name_field').locator('xpath=ancestor::*[contains(@class,"sec")][1]');
  await p.focus('#billing_email');
  await p.waitForTimeout(250);
  await (await sec.count() ? sec.first() : p.locator('#billing_first_name_field')).screenshot({ path: `${OUT}/after-v3-checkout-fields-390.png` });
  console.log('checkout crop', ok, await p.evaluate(() => Math.round(document.querySelector('#billing_first_name').getBoundingClientRect().height)));
  await b.close();
})();
