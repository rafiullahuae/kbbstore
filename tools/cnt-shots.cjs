/*
 * Lane CT shots: /contact-us/ (English and Arabic) at 390 and 1280, full page,
 * with the numbers that matter: scrollWidth, the h1 count, each card's box,
 * the form's controls and their font sizes, console errors and scripts.
 *
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/cnt-shots.cjs http://127.0.0.1:10790 docs/lane-ct-shots before
 *   ... after        -- the page, plus the error and success states of the form
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.argv[2] || 'http://127.0.0.1:10790';
const OUT = path.resolve(process.argv[3] || 'docs/lane-ct-shots');
const TAG = process.argv[4] || 'after';
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
fs.mkdirSync(OUT, { recursive: true });

async function ctx(browser, w) {
  const c = await browser.newContext(w < 600
    ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA }
    : { viewport: { width: 1280, height: 900 } });
  await c.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
  return c;
}

const measure = () => {
  const box = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; };
  const fz = (sel) => { const e = document.querySelector(sel); return e ? getComputedStyle(e).fontSize : null; };
  return {
    sw: document.documentElement.scrollWidth,
    iw: innerWidth,
    h1: document.querySelectorAll('h1').length,
    cards: [...document.querySelectorAll('.ctc-card')].map((c) => c.dataset.ct + ' ' + box(c).join('x')),
    socials: document.querySelectorAll('.ctc-soc a').length,
    form: document.querySelector('.ctc-form') ? box(document.querySelector('.ctc-form')).join('x') : null,
    inputFont: fz('#ctc-name'),
    scripts: [...document.scripts].filter((s) => s.src).map((s) => s.src.split('/').pop()),
    inlineCt: [...document.scripts].filter((s) => !s.src && s.textContent.includes('ctc')).length,
  };
};

async function fill(page, ok) {
  await page.waitForTimeout(3200); // past the minimum fill time
  if (ok) {
    await page.fill('#ctc-name', 'Aisha Rahman');
    await page.fill('#ctc-email', 'aisha@example.com');
    await page.fill('#ctc-phone', '+971 50 123 4567');
    await page.selectOption('#ctc-topic', { index: 1 });
    await page.fill('#ctc-message', 'Hello! Is the Beauty of Joseon sunscreen suitable for oily skin in summer?');
  } else {
    await page.fill('#ctc-name', '');
    await page.fill('#ctc-email', 'not-an-email');
    await page.fill('#ctc-message', 'Hi');
  }
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('.ctc-form button[type=submit]')]);
  await page.waitForTimeout(500);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    for (const p of ['/contact-us/', '/ar/contact-us/']) {
      const c = await ctx(browser, w);
      const page = await c.newPage();
      const errs = [];
      page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 120)));
      page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 120)); });
      await page.goto(BASE + p, { waitUntil: 'load' });
      await page.waitForTimeout(600);
      const lang = p.startsWith('/ar') ? 'ar' : 'en';
      await page.screenshot({ path: `${OUT}/${TAG}-${lang}-${w}.png`, fullPage: true });
      console.log(TAG, lang, w, JSON.stringify(await page.evaluate(measure)), 'errors', errs.length ? errs.join(' ; ') : 0);

      if (TAG === 'after' && lang === 'en') {
        await fill(page, false);
        const focused = await page.evaluate(() => document.activeElement && document.activeElement.id);
        const live = await page.evaluate(() => { const s = document.querySelector('.ctc-status'); return s ? s.getAttribute('role') + ' ' + s.textContent.trim().slice(0, 60) : null; });
        await page.locator('.ctc-formwrap').screenshot({ path: `${OUT}/after-error-${w}.png` });
        console.log('  error state: focus', focused, '| status', live);
        await page.goto(BASE + p, { waitUntil: 'load' });
        await fill(page, true);
        const msg = await page.evaluate(() => { const s = document.querySelector('.ctc-status'); return s ? s.textContent.trim().slice(0, 80) : null; });
        await page.locator('.ctc-formwrap').screenshot({ path: `${OUT}/after-success-${w}.png` });
        console.log('  success state:', msg, '| focus', await page.evaluate(() => document.activeElement && (document.activeElement.id || document.activeElement.className)));
      }
      await c.close();
    }
  }
  await browser.close();
})();
