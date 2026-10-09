// Lane TS: screenshots and measurements of the SHIPPED trust strips.
//   sh tools/ts-preview.sh [port]   then   node tools/ts-final-shots.cjs <port>
// Writes docs/trust-strip-options/final/<page>-<width>.png and
// final/measurements.json. getBoundingClientRect is the HARNESS measuring;
// the strips themselves carry no script.
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const BASE = `http://127.0.0.1:${process.argv[2] || 10260}`;
const OUT = path.join(__dirname, '..', 'docs', 'trust-strip-options', 'final');
fs.mkdirSync(OUT, { recursive: true });
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const PAGES = {
  home: '/',
  'super-sale': '/super-sale/',
  category: '/product-category/super-sale/',
  product: '/product/house-of-hur-moist-ampoule-blusher/',
  blog: '/blog/',
  cart: '/cart/',
  checkout: '/checkout/',
};

function measure() {
  const strips = [...document.querySelectorAll('section.ktr')].filter((s) => getComputedStyle(s).display !== 'none');
  const all = document.querySelectorAll('section.ktr').length;
  const kts = [...document.querySelectorAll('.kts')].filter((s) => getComputedStyle(s).display !== 'none');
  const r = (e) => { const b = e.getBoundingClientRect(); return { top: Math.round(b.top + scrollY), h: Math.round(b.height * 10) / 10 }; };
  const s = strips[0];
  let gapAbove = null;
  if (s) {
    let prev = s.previousElementSibling;
    while (prev && (getComputedStyle(prev).display === 'none' || prev.tagName === 'STYLE' || prev.getBoundingClientRect().height === 0)) prev = prev.previousElementSibling;
    if (prev) gapAbove = Math.round((s.getBoundingClientRect().top - prev.getBoundingClientRect().bottom) * 10) / 10;
  }
  const clipped = s ? [...s.querySelectorAll('b,small')].filter((e) => e.clientWidth > 0 && e.scrollWidth > e.clientWidth + 0.5).length : 0;
  const footer = document.querySelector('footer');
  return {
    vw: innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    stripsInHtml: all,
    stripVisible: s ? s.className : null,
    strip: s ? r(s) : null,
    gapAbove,
    rows: s ? new Set([...s.querySelectorAll('li')].map((li) => Math.round(li.getBoundingClientRect().top))).size : null,
    clippedText: clipped,
    thinLine: kts[0] ? r(kts[0]) : null,
    footer: footer ? r(footer) : null,
    font: s ? getComputedStyle(s.querySelector('b')).fontSize : null,
    icon: s ? Math.round(s.querySelector('svg').getBoundingClientRect().width) + 'px/' + getComputedStyle(s.querySelector('svg path')).strokeWidth : null,
  };
}

(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const results = [];
  for (const w of [320, 390, 1280]) {
    const mobile = w < 768;
    const ctx = await br.newContext({ viewport: { width: w, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, userAgent: mobile ? UA_M : UA_D });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

    // A basket, through the shop's own button, so /cart and /checkout render full.
    await page.goto(BASE + PAGES.product, { waitUntil: 'networkidle' });
    const ok = await page.evaluate(async () => {
      const id = document.querySelector('[data-product_id]').getAttribute('data-product_id');
      const tok = (window.KBB && window.KBB.csrf) || (document.querySelector('meta[name=csrf-token]') || {}).content;
      const r = await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': tok, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: +id, quantity: 1 }) });
      return r.status + ' id=' + id;
    });
    console.log('basket', w, ok);

    for (const [name, url] of Object.entries(PAGES)) {
      errors.length = 0;
      const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      // The floating WhatsApp button is hidden in the shots only, so it does not sit on the strip.
      await page.addStyleTag({ content: '.kbw,#kbbWa,.kbw-z{visibility:hidden!important}' });
      const m = await page.evaluate(measure);
      m.status = res.status();
      m.url = page.url().replace(BASE, '');
      m.consoleErrors = errors.slice();
      results.push({ page: name, ...m });
      console.log(name, w, JSON.stringify(m));
      if (w === 320) continue;
      const file = path.join(OUT, `${name}-${w}.png`);
      if (m.strip) {
        const isFoot = /ktr-foot/.test(m.stripVisible);
        const above = isFoot ? (mobile ? 200 : 240) : (mobile ? 520 : 420);
        const below = isFoot ? (mobile ? 420 : 340) : (mobile ? 260 : 260);
        const y = Math.max(0, m.strip.top - above);
        await page.screenshot({ path: file, fullPage: true, clip: { x: 0, y, width: w, height: Math.round(m.strip.h + (m.strip.top - y) + below) } });
      } else {
        // No strip: the bottom of the page, where a footer strip would be.
        const h = await page.evaluate(() => document.documentElement.scrollHeight);
        const ch = mobile ? 1100 : 900;
        await page.screenshot({ path: file, fullPage: true, clip: { x: 0, y: Math.max(0, h - ch), width: w, height: Math.min(ch, h) } });
      }
    }
    await ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(results, null, 1) + '\n');
  await br.close();
})();
