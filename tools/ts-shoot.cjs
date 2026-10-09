// Lane TS: in-place screenshots of the trust-strip options.
//   sh tools/ts-preview.sh [port]   then   node tools/ts-shoot.cjs <port>
// Loads REAL shop pages from the preview, injects one variant's <style> and
// markup client-side (no shop file changes), photographs it in place and prints
// the measured numbers. getBoundingClientRect here is the HARNESS measuring
// the page; the variants themselves contain no script at all.
// The floating WhatsApp button is hidden in the shots only so it does not sit
// on top of the strip being judged; it is untouched on the shop.
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const { variants } = require('./ts-variants.cjs');

const BASE = `http://127.0.0.1:${process.argv[2] || 10260}`;
const OUT = path.join(__dirname, '..', 'docs', 'trust-strip-options', 'shots');
fs.mkdirSync(path.join(OUT, '320'), { recursive: true });
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

// where: 'afterHero' | 'afterHeader' | 'beforeFooter'
const PLACES = {
  home: { url: '/', where: 'afterHero', fam: 'home' },
  sale: { url: '/super-sale/', where: 'afterHeader', fam: 'home' },
  'foot-home': { url: '/', where: 'beforeFooter', fam: 'foot' },
  'foot-category': { url: '/product-category/super-sale/', where: 'beforeFooter', fam: 'foot' },
  'foot-product': { url: '/product/house-of-hur-moist-ampoule-blusher/', where: 'beforeFooter', fam: 'foot' },
};

function inject({ css, markup, where }) {
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el);
    if (cs.position === 'fixed' && el.getBoundingClientRect().top > innerHeight / 2) el.style.visibility = 'hidden';
  }
  const st = document.createElement('style');
  st.textContent = css;
  document.head.appendChild(st);
  const tpl = document.createElement('template');
  tpl.innerHTML = markup;
  const node = tpl.content.firstElementChild;
  node.setAttribute('data-ts', '1');
  if (where === 'afterHero') {
    const hero = document.querySelector('.kbb-home > section.sec');
    hero.after(node);
  } else if (where === 'afterHeader') {
    document.querySelector('.kbb-pt').after(node);
  } else {
    const f = document.querySelector('footer.kft') || document.querySelector('footer');
    f.before(node);
  }
}

function measure() {
  const s = document.querySelector('[data-ts]');
  const r = s.getBoundingClientRect();
  const clipped = [...s.querySelectorAll('li, b, span, p')].filter((e) => e.clientWidth > 0 && e.scrollWidth > e.clientWidth + 0.5).map((e) => e.tagName + ':' + e.textContent.slice(0, 16));
  const lis = [...s.querySelectorAll('li')].map((li) => li.getBoundingClientRect());
  const ul = s.querySelector('ul');
  const outside = lis.filter((b) => b.left < -0.5 || b.right > innerWidth + 0.5).length;
  const b = s.querySelector('b');
  const svg = s.querySelector('svg').getBoundingClientRect();
  return {
    vw: innerWidth,
    docScrollWidth: document.documentElement.scrollWidth,
    stripH: Math.round(r.height * 10) / 10,
    stripTop: Math.round(r.top + scrollY),
    rows: new Set(lis.map((x) => Math.round(x.top))).size,
    itemsOutsideViewport: outside,
    ulScrolls: ul.scrollWidth > ul.clientWidth + 0.5,
    clippedText: clipped,
    font: getComputedStyle(b).fontFamily.split(',')[0] + ' ' + getComputedStyle(b).fontSize + '/' + getComputedStyle(b).fontWeight,
    icon: Math.round(svg.width) + 'px stroke ' + getComputedStyle(s.querySelector('svg path')).strokeWidth,
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
    for (const [place, P] of Object.entries(PLACES)) {
      // 320 is a measurement pass on one page per family.
      if (w === 320 && !['home', 'foot-home'].includes(place)) continue;
      for (const v of variants.filter((x) => x.family === P.fam)) {
        errors.length = 0;
        const res = await page.goto(BASE + P.url, { waitUntil: 'networkidle' });
        if (res.status() !== 200) throw new Error(`${P.url} answered ${res.status()}`);
        await page.evaluate(() => document.fonts.ready);
        await page.evaluate(inject, { css: v.css, markup: v.markup, where: P.where });
        await page.waitForTimeout(150);
        const m = await page.evaluate(measure);
        const file = `${v.id}-${place}-${w}.png`;
        const above = P.fam === 'home' ? (mobile ? 300 : 360) : (mobile ? 160 : 220);
        const below = P.fam === 'home' ? (mobile ? 240 : 260) : (mobile ? 330 : 300);
        const y = Math.max(0, m.stripTop - above);
        if (w === 320) {
          await page.locator('[data-ts]').screenshot({ path: path.join(OUT, '320', file) });
        } else {
          await page.screenshot({ path: path.join(OUT, file), fullPage: true, clip: { x: 0, y, width: w, height: m.stripH + (m.stripTop - y) + below } });
        }
        results.push({ variant: v.id, place, ...m, consoleErrors: errors.slice() });
        console.log(v.id, place, w, JSON.stringify(m), errors.length ? 'ERRORS ' + errors.join(' | ') : '');
      }
    }
    await ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(results, null, 1));
  await br.close();
})();
