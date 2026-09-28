/*
 * Lane SX screenshots: a product image address that becomes CSS.
 *
 * WHAT MAKES THIS MORE THAN A PICTURE OF A PAGE. The preview seeds a variable
 * product (`/product/sx-quote-toner/`) whose option image address is
 *
 *     /uploads/sx/toner.png');background:url(/uploads/sx/injected.png) center/cover
 *
 * so the finding is visible rather than argued: on the BEFORE server the quote
 * closes the url(), the semicolon ends the declaration, and the swatch paints
 * the RED "INJECTED CSS" tile that the shop never asked for. On the AFTER
 * server the whole address stays inside one url() and the swatch paints the
 * blue toner.
 *
 * The decisive measurement is not the colour, it is `injectedRequests`: every
 * network request the page made for /uploads/sx/injected.png. That number is
 * what "a request fired from your shopper's browser" means, counted. BEFORE it
 * is 1 or more; AFTER it is 0.
 *
 * Usage: node tools/sx-shots.cjs <width> <base-url> <out-dir> <label>
 */
const { chromium } = require('playwright');
const fs = require('fs');

const WIDTH = +process.argv[2];
const BASE = process.argv[3];
const OUT = process.argv[4];
const LABEL = process.argv[5];
const CHROME = process.env.SX_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const wait = (p, ms) => p.waitForTimeout(ms);

async function measure(page, name) {
  return await page.evaluate((n) => {
    const swatches = [...document.querySelectorAll('.vsw')];
    const thumbs = [...document.querySelectorAll('.cth, .kc-th, .kthumb, .sth, .im')];
    const bg = (el) => getComputedStyle(el).backgroundImage;

    return {
      page: n,
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      // Horizontal overflow is the thing a restyle causes first, so it is
      // measured on every page rather than eyeballed.
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      swatches: swatches.length,
      // The computed value is what the CSS parser MADE of the declaration,
      // which is the only honest read of whether the url() was closed early.
      swatchBackgrounds: swatches.map(bg),
      swatchBox: swatches.map((el) => {
        const r = el.getBoundingClientRect();
        return Math.round(r.width) + 'x' + Math.round(r.height);
      }),
      thumbs: thumbs.length,
      thumbBackgrounds: thumbs.slice(0, 6).map(bg),
      fontSizes: [...new Set([...document.querySelectorAll('.vn, .cn, .kc-nm')]
        .map((el) => getComputedStyle(el).fontSize))],
    };
  }, name);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: WIDTH, height: 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  /*
   * EXACT PATH, not includes(). After the fix the browser still fetches ONE
   * address — the whole escaped string as a single (broken) URL on this shop's
   * own host — and that string CONTAINS the characters "injected.png". A
   * substring test counts that as a hit and reports the fix as no fix at all.
   * What the finding is about is a request whose path IS the injected file.
   */
  let injected = [];
  page.on('request', (r) => {
    let path = null;
    try { path = new URL(r.url()).pathname; } catch (e) { path = null; }
    if (path === '/uploads/sx/injected.png') { injected.push(r.url()); }
  });

  const out = [];
  const shot = async (name) => {
    await page.screenshot({ path: `${OUT}/${LABEL}-${name}-${WIDTH}.png`, fullPage: false });
  };

  // 1. The product whose option image carries a quote.
  injected = [];
  await page.goto(`${BASE}/product/sx-quote-toner/`, { waitUntil: 'networkidle' });
  await wait(page, 400);
  out.push({ ...(await measure(page, 'product-quote')), injectedRequests: injected.length });
  await shot('product-quote');

  // The swatch on its own, large, so the two servers can be compared by eye.
  const sw = await page.$('.vsw');
  if (sw) { await sw.screenshot({ path: `${OUT}/${LABEL}-swatch-${WIDTH}.png` }); }

  // 2. An ordinary product, to prove the pictures still draw.
  injected = [];
  await page.goto(`${BASE}/product/sx-plain-serum/`, { waitUntil: 'networkidle' });
  await wait(page, 300);
  out.push({ ...(await measure(page, 'product-plain')), injectedRequests: injected.length });
  await shot('product-plain');

  // 3. Add it to the basket, which is what fills the cart, drawer and checkout.
  await page.click('#mainAdd');
  await wait(page, 1200);
  await shot('drawer');
  out.push({ ...(await measure(page, 'cart-drawer')), injectedRequests: 0 });

  // 4. And the hostile one, so the basket thumbnail carries the same address.
  injected = [];
  await page.goto(`${BASE}/product/sx-quote-toner/`, { waitUntil: 'networkidle' });
  await page.click('#mainAdd');
  await wait(page, 1200);

  injected = [];
  await page.goto(`${BASE}/cart`, { waitUntil: 'networkidle' });
  await wait(page, 500);
  out.push({ ...(await measure(page, 'cart')), injectedRequests: injected.length });
  await shot('cart');

  injected = [];
  await page.goto(`${BASE}/checkout`, { waitUntil: 'networkidle' });
  await wait(page, 700);
  out.push({ ...(await measure(page, 'checkout')), injectedRequests: injected.length });
  await shot('checkout');

  fs.writeFileSync(`${OUT}/${LABEL}-measurements-${WIDTH}.json`, JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));

  await browser.close();
})();
