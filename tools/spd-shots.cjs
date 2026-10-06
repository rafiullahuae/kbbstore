/*
 * Lane SP: "nothing visible moved" -- each page type at 390 and 1280, the HEAD
 * tree beside this lane's tree (feature ON), full page, pixel-diffed.
 *
 *   node tools/spd-shots.cjs OUTDIR
 *
 * Writes OUTDIR/<page>-<w>.jpg (this lane's tree) and prints, per shot, the share of
 * pixels that differ, plus scrollWidth and the page height. A CONTROL row
 * shoots HEAD twice, so whatever moves on its own (a rotating banner, the
 * category header's random tone) is visible as such and not blamed on a fix.
 */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const { PNG } = require(path.join(__dirname, '..', 'node_modules', 'playwright-core', 'lib', 'utilsBundle'));
const APP = path.dirname(__dirname);
const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const PAGES = [['home', '/'], ['product', '/product/spd-product-500/'], ['category', '/collections/spd-dept-1/'], ['brand', '/brands/anua/'],
  ['super-sale', '/super-sale/'], ['shop', '/shop/'], ['search', '/shop/?s=glow'], ['blog-post', '/blog/spd-post-12/']];
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

function diff(a, b) {
  const A = PNG.sync.read(a); const B = PNG.sync.read(b);
  if (A.width !== B.width || A.height !== B.height) return `size ${A.width}x${A.height} vs ${B.width}x${B.height}`;
  let n = 0;
  for (let i = 0; i < A.data.length; i += 4) if (A.data[i] !== B.data[i] || A.data[i + 1] !== B.data[i + 1] || A.data[i + 2] !== B.data[i + 2]) n++;
  return (100 * n / (A.width * A.height)).toFixed(3) + '%';
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const shoot = async (label, p, w) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 800 }, deviceScaleFactor: 1, isMobile: w < 600, hasTouch: w < 600, userAgent: UA, reducedMotion: 'reduce' });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
    const page = await ctx.newPage();
    await page.goto(base(label) + p, { waitUntil: 'load' });
    await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 60)); } window.scrollTo(0, 0); });
    await page.waitForTimeout(1200);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight }));
    const png = await page.screenshot({ fullPage: true, animations: 'disabled' });
    const jpg = await page.screenshot({ fullPage: true, animations: 'disabled', type: 'jpeg', quality: 60 });
    await ctx.close();
    return { png, jpg, m };
  };
  for (const [name, p] of PAGES) {
    for (const w of [390, 1280]) {
      const h = await shoot('head', p, w); const s = await shoot('nav', p, w);
      fs.writeFileSync(path.join(out, `${name}-${w}.jpg`), s.jpg);
      const c = name === 'home' || name === 'category' ? ' control(head vs head) ' + diff(h.png, (await shoot('head', p, w)).png) : '';
      console.log(`${name} ${w}: diff ${diff(h.png, s.png)}  head sw ${h.m.sw} h ${h.m.h} | sp sw ${s.m.sw} h ${s.m.h}${c}`);
    }
  }
  await browser.close();
})();
