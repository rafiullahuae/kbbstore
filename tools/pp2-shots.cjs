/*
 * Lane PP2 — the square frame, the thumbnail strip on a SET, and the proof that
 * deleting the `.pp-lay*` block moved nothing.
 *
 * Shot on a set that carries a main image, THREE gallery images and THREE
 * pictured members (tools/pp2-seed.php), because Lane PP's fixture gave every
 * set one image and so could not show a thumbnail strip at all.
 *
 * English and Arabic, 390 and 1280. The measurement is deliberately the same
 * shape Lane PP used, so the two rounds are comparable line for line.
 *
 * NOTHING HERE SHIPS, and the PAGE measures nothing — every rectangle below is
 * read by the test harness in a browser, never by a script the shop serves.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.PP2_BASE || 'http://127.0.0.1:8995';
const OUT = process.env.PP2_OUT || '/home/user/lane-pp2/docs/lane-pp2-shots';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const M = () => {
  const gal = document.querySelector('.gmain');
  const img = document.querySelector('.gmain-img');
  const f = gal ? gal.getBoundingClientRect() : null;

  let fill = null;
  let painted = null;

  if (img && f && img.naturalWidth) {
    const r = img.getBoundingClientRect();
    const s = Math.min(r.width / img.naturalWidth, r.height / img.naturalHeight);
    painted = [Math.round(img.naturalWidth * s), Math.round(img.naturalHeight * s)];
    fill = Math.round(((painted[0] * painted[1]) / (f.width * f.height)) * 100);
  }

  const btn = document.querySelector('.addcart');
  const b = btn ? btn.getBoundingClientRect() : null;
  const strip = document.querySelector('#gthumbs');
  const s = strip ? strip.getBoundingClientRect() : null;

  const pdp = document.querySelector('.pdp');
  const title = document.querySelector('.bb-title');

  return {
    dir: document.documentElement.getAttribute('dir') || 'ltr',
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    pageHeight: document.documentElement.scrollHeight,
    pdpHeight: pdp ? Math.round(pdp.getBoundingClientRect().height) : null,
    pdpClass: pdp ? pdp.getAttribute('class') : null,
    addToCartY: b ? Math.round(b.top + window.scrollY) : null,
    galleryFrame: f ? [Math.round(f.width), Math.round(f.height)] : null,
    galleryAspect: gal ? getComputedStyle(gal).aspectRatio : null,
    galleryPainted: painted,
    galleryFillPct: fill,
    // ▲ THE ANSWER TO ITEM 3. A set draws this strip or it does not.
    thumbStrip: strip ? { tiles: strip.querySelectorAll('.gthumb').length,
                          box: [Math.round(s.width), Math.round(s.height)] } : null,
    setRows: document.querySelectorAll('.ksl-r').length,
    titleFontSize: title ? getComputedStyle(title).fontSize : null,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: EXE });
  const out = {};

  const pages = [
    ['set', '/product/pp2-glow-ritual-set/'],
    ['product', '/product/pp2-rice-toner/'],
  ];

  for (const [lang, prefix] of [['en', ''], ['ar', '/ar']]) {
    for (const [name, path] of pages) {
      for (const width of [390, 1280]) {
        const ctx = await browser.newContext({
          viewport: { width, height: width === 390 ? 844 : 900 },
          deviceScaleFactor: 1,
        });
        const page = await ctx.newPage();

        const key = `${lang}-${name}-${width}`;
        const url = BASE + prefix + path;
        const res = await page.goto(url, { waitUntil: 'networkidle' });

        if (!res || res.status() !== 200) {
          throw new Error(`${key}: ${url} answered ${res ? res.status() : 'nothing'}`);
        }

        out[key] = await page.evaluate(M);

        await page.screenshot({ path: `${OUT}/${key}.png`, fullPage: true });

        await ctx.close();
      }
    }
  }

  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));

  await browser.close();
})();
