// Lane GS2: the one-line free gifts strip -- every style, measured and
// photographed against tools/pdp-preview.sh (CLAUDE.md "every patch arrives
// with a picture").
//
//   node tools/gs2-shots.cjs <base url> <out dir> [style ...]
//
// For every style (tools/gs2-set.sh writes it), EN and /ar, at 320 … 1280:
// one line box, no overflow, the font size, the page's scrollWidth; a shot of
// the strip at 390 for the contact sheet; and, for the default style, the
// page around it at 390 and 1280 with console errors and requests. Measures
// with getBoundingClientRect in THIS harness only; the shop measures nothing.
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const [base, out, ...only] = process.argv.slice(2);
const STYLES = only.length ? only : ['underline', 'sway', 'pill', 'rose', 'twinkles', 'band', 'plain', 'quiet', 'blush', 'blushglint', 'outline',
  'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'ka', 'kb', 'kl', 'l', 'm'];
const WIDTHS = [320, 360, 375, 390, 414, 430, 768, 1280];
const LANGS = { en: '/product/pdp-heartleaf-toner/', ar: '/ar/product/pdp-heartleaf-toner/' };
fs.mkdirSync(path.join(out, 'sheet'), { recursive: true });

function set(...pairs) {
  execFileSync('sh', [path.join(__dirname, 'gs2-set.sh'), ...pairs], { stdio: 'ignore' });
}

const measure = () => {
  const s = document.querySelector('.pdp-gs');
  if (!s || s.getBoundingClientRect().height === 0) return { strip: null, scrollWidth: document.documentElement.scrollWidth };
  const t = s.querySelector('.pgs-t');
  const cs = getComputedStyle(t);
  const fsz = parseFloat(cs.fontSize);
  const r = s.getBoundingClientRect();
  // One line box: the words' box is one line tall, and every text rect sits
  // on that line (tops within half a font size of each other).
  const tops = [];
  const range = document.createRange(); range.selectNodeContents(t);
  for (const q of range.getClientRects()) if (q.width > 0) tops.push(q.top);
  const oneLine = t.getBoundingClientRect().height - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom) < fsz * 2 && (Math.max(...tops) - Math.min(...tops)) < fsz / 2;
  // What the words need (their own rects) against the box they were given.
  const need = range.getBoundingClientRect().width;
  const have = t.getBoundingClientRect().width;
  return {
    stripW: Math.round(r.width), stripH: +r.height.toFixed(1), font: +fsz.toFixed(2),
    lines: oneLine ? 1 : 2, need: +need.toFixed(1), have: +have.toFixed(1),
    overflow: need > have + 0.5 || r.right > window.innerWidth + 0.5,
    scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
  };
};

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const fit = {};
  for (const style of STYLES) {
    set('style=' + style);
    fit[style] = {};
    for (const [lang, p] of Object.entries(LANGS)) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto(base + p, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      fit[style][lang] = { errors };
      for (const w of WIDTHS) {
        await page.setViewportSize({ width: w, height: 844 });
        await page.waitForTimeout(120);
        fit[style][lang][w] = await page.evaluate(measure);
        if (w === 390) {
          const box = await page.evaluate(() => { const r = document.querySelector('.pdp-gs').getBoundingClientRect(); return { x: r.left, y: r.top + scrollY, w: r.width, h: r.height }; });
          await page.evaluate((y) => scrollTo(0, y - 120), box.y);
          await page.waitForTimeout(700);
          const b2 = await page.evaluate(() => { const r = document.querySelector('.pdp-gs').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
          await page.screenshot({ path: path.join(out, 'sheet', `${style}-${lang}.png`), clip: { x: 0, y: Math.max(0, b2.y - 14), width: 390, height: b2.h + 28 } });
        }
      }
      await ctx.close();
    }
    process.stdout.write(style + ' ');
  }
  fs.writeFileSync(path.join(out, 'fit.json'), JSON.stringify(fit, null, 1));

  // The default, in place: 390 and 1280, EN and /ar.
  set('style=');
  const page390 = {};
  for (const width of [390, 1280]) {
    for (const [lang, p] of Object.entries(LANGS)) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: width === 390 ? 2 : 1 });
      const page = await ctx.newPage();
      const errors = []; const requests = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('request', (r) => requests.push(r.url().replace(base, '')));
      await page.goto(base + p, { waitUntil: 'networkidle' });
      const y = await page.evaluate(() => document.querySelector('.pdp-gs').getBoundingClientRect().top + scrollY);
      await page.evaluate((yy) => scrollTo(0, yy - (innerHeight / 2)), y);
      await page.waitForTimeout(900);
      await page.screenshot({ path: path.join(out, `default-${lang}-${width}.png`) });
      const m = await page.evaluate(measure);
      // Every link and button in the buy column answers at its centre.
      m.blocked = await page.evaluate(() => [...document.querySelectorAll('.pdp a[href], .pdp button')].filter((a) => {
        const r = a.getBoundingClientRect(); if (!r.width || !r.height) return false;
        const cs = getComputedStyle(a); if (cs.visibility === 'hidden' || cs.display === 'none') return false;
        const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
        if (cy < 0 || cy > innerHeight) return false;
        const hit = document.elementFromPoint(cx, cy);
        return hit && !a.contains(hit) && !hit.contains(a);
      }).map((a) => a.outerHTML.slice(0, 80)));
      m.animations = await page.evaluate(() => [...document.querySelector('.pdp-gs').getAnimations({ subtree: true })].map((a) => a.animationName));
      page390[`${lang}-${width}`] = { ...m, errors, requests: requests.length };
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(out, 'default.json'), JSON.stringify(page390, null, 1));
  await browser.close();
  console.log('\ndone');
})();
