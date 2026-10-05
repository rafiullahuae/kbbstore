/*
 * Lane CT (contrast) — screenshots, axe colour-contrast / target-size, and the
 * footer-link geometry, for one preview at a time.
 *
 *   CTC_BASE=http://127.0.0.1:9471 CTC_TAG=before node tools/ctc-audit.cjs
 *
 * Nothing here ships. axe-core is read from the npx cache Lighthouse already
 * put on this machine (CTC_AXE), not added to package.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.CTC_BASE;
const TAG = process.env.CTC_TAG || 'x';
const OUT = process.env.CTC_OUT || __dirname + '/../storage/ct-logs/' + TAG;
const AXE = process.env.CTC_AXE || '/root/.npm/_npx/8003d8991b0d346b/node_modules/axe-core/axe.min.js';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140 Safari/537.36';

const PAGES = [
  ['home', '/'],
  ['shop', '/shop/'],
  ['category', '/collections/serums/'],
  ['product', '/product/relief-sun-rice-probiotics-spf50/'],
  ['cart', '/cart/'],
  ['super-sale', '/super-sale/'],
];

const FREEZE = `*,*::before,*::after{animation-play-state:paused!important;animation-delay:-0.0001s!important;
  animation-duration:0s!important;transition:none!important;caret-color:transparent!important}`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  // Greyscale anti-aliasing: LCD text puts coloured fringes on every glyph,
  // and a fringe is not a blend of one colour, so the pixel diff could not
  // attribute it. The page renders the same; only the glyph edges differ.
  const browser = await chromium.launch({ executablePath: EXE, args: ['--disable-lcd-text'] });
  const axeSrc = fs.readFileSync(AXE, 'utf8');
  const report = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
      deviceScaleFactor: 1, reducedMotion: 'reduce', userAgent: UA,
    });
    const page = await ctx.newPage();
    // One thing in the cart, so /cart/ draws its full page.
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await page.locator('a.kbb-card-cart.ajax_add_to_cart').first().click();
    await page.waitForTimeout(1500);

    for (const [name, path] of PAGES) {
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: FREEZE });
      await page.evaluate(() => document.fonts.ready);
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/${name}-${width}.png`, fullPage: true });

      await page.addScriptTag({ content: axeSrc });
      const axe = await page.evaluate(async () => {
        const r = await window.axe.run(document, { runOnly: ['color-contrast', 'target-size'], resultTypes: ['violations'] });
        return r.violations.map((v) => ({
          id: v.id,
          nodes: v.nodes.map((n) => ({
            target: n.target.join(' '),
            data: (n.any[0] || {}).data || null,
          })),
        }));
      });
      const geo = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        footerLinks: [...document.querySelectorAll('nav.kft-col > ul > li > a')].slice(0, 4).map((a) => {
          const r = a.getBoundingClientRect();
          return { text: a.textContent.trim(), top: +(r.top + scrollY).toFixed(1), h: +r.height.toFixed(1) };
        }),
      }));
      report[`${name}-${width}`] = { axe, geo };
      const cc = axe.find((v) => v.id === 'color-contrast');
      const ts = axe.find((v) => v.id === 'target-size');
      console.log(`${name}-${width}: contrast ${cc ? cc.nodes.length : 0}, target-size ${ts ? ts.nodes.length : 0}, scrollWidth ${geo.scrollWidth}`);
    }
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/axe.json`, JSON.stringify(report, null, 1));
  await browser.close();
})();
