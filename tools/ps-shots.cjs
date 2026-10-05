/* Lane PS — before/after screenshots, pixel diff and picture sharpness.
 *
 *   node tools/ps-shots.cjs <before-base-url> <after-base-url> <out-dir>
 *
 * For every page × width (390, 1280) it takes a full-page Chromium screenshot of
 * each tree after scrolling to the bottom (so every lazy picture has loaded),
 * then counts differing pixels three ways:
 *   all      every pixel
 *   outside  pixels that differ OUTSIDE every <img> box (layout, text, colour,
 *            spacing -- anything that is not a photograph's own pixels)
 *   noimg    a second pair of shots with every <img> hidden: the page with its
 *            photographs taken out, which must be identical
 * and records the page geometry (document height, scrollWidth). Then, at device
 * pixel ratios 1, 1.75 and 3, the "sharpness" of every <img>: the file's natural
 * width over the device pixels it is drawn into. A value below 1 is a picture
 * stretched up -- softer than its box needs. Reported as the worst image per page
 * and the count below 1, before and after.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const { PNG } = require(path.join(__dirname, '..', 'node_modules', 'playwright-core', 'lib', 'utilsBundle'));

const [BEFORE, AFTER, OUT, ONLY, MODE] = process.argv.slice(2);
const PAGES = [['home', '/'], ['category', '/collections/sunscreens/'], ['product', '/product/heartleaf-77-soothing-toner/'],
  ['super-sale', '/super-sale/'], ['brand', '/brands/anua/']].filter(([n]) => !ONLY || ONLY.split(',').includes(n));
fs.mkdirSync(OUT, { recursive: true });

async function settle(page) {
  await page.addStyleTag({ content: '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important} #kbbWaB,.kbw-g{display:none!important}' });
  await page.evaluate(async () => {
    for (let y = 0; y < document.documentElement.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 40)); }
    window.scrollTo(0, 0);
    await Promise.race([new Promise(r => setTimeout(r, 4000)),
      Promise.all([...document.images].map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })))]);
    await document.fonts.ready;
  });
  await page.waitForTimeout(300);
}

async function shoot(browser, base, uri, width, hideImgs) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
  // The title header draws a random palette per load (an existing feature:
  // three loads of one tree gave three colours), so Math.random is made the
  // same sequence on both trees -- otherwise the diff measures the dice.
  await ctx.addInitScript(() => { let s = 42; Math.random = () => ((s = (s * 16807) % 2147483647) / 2147483647); });
  const page = await ctx.newPage();
  await page.goto(base + uri, { waitUntil: 'load' });
  await settle(page);
  if (hideImgs) await page.addStyleTag({ content: 'img{visibility:hidden!important}' });
  const geo = await page.evaluate(() => ({
    height: document.documentElement.scrollHeight, scrollWidth: document.documentElement.scrollWidth,
    imgs: [...document.images].map(i => { const r = i.getBoundingClientRect(); return [r.left + scrollX, r.top + scrollY, r.width, r.height]; }),
    // The category title header's box colour is drawn at random per REQUEST by
    // the server (three requests to one tree: lilac, mint, blush), so its box is
    // left out of the comparison and reported as such.
    random: [...document.querySelectorAll('.kbb-th')].map(i => { const r = i.getBoundingClientRect(); return [r.left + scrollX, r.top + scrollY, r.width, r.height]; }),
  }));
  const buf = await page.screenshot({ fullPage: true });
  await ctx.close();
  return { png: PNG.sync.read(buf), buf, geo };
}

function diff(a, b, rects, skip = []) {
  const w = Math.min(a.width, b.width), h = Math.min(a.height, b.height);
  const mask = new Uint8Array(w * h);
  for (const [rx, ry, rw, rh] of rects) {
    const x0 = Math.max(0, Math.floor(rx) - 1), x1 = Math.min(w - 1, Math.ceil(rx + rw) + 1);
    const y0 = Math.max(0, Math.floor(ry) - 1), y1 = Math.min(h - 1, Math.ceil(ry + rh) + 1);
    for (let y = y0; y <= y1; y++) mask.fill(1, y * w + x0, y * w + x1 + 1);
  }
  const skipMask = new Uint8Array(w * h);
  for (const [rx, ry, rw, rh] of skip) {
    const x0 = Math.max(0, Math.floor(rx) - 2), x1 = Math.min(w - 1, Math.ceil(rx + rw) + 2);
    const y0 = Math.max(0, Math.floor(ry) - 2), y1 = Math.min(h - 1, Math.ceil(ry + rh) + 2);
    for (let y = y0; y <= y1; y++) skipMask.fill(1, y * w + x0, y * w + x1 + 1);
  }
  let all = 0, outside = 0;
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    if (skipMask[y * w + x]) continue;
    const i = (y * a.width + x) * 4, j = (y * b.width + x) * 4;
    if (a.data[i] !== b.data[j] || a.data[i + 1] !== b.data[j + 1] || a.data[i + 2] !== b.data[j + 2]) {
      all++; if (!mask[y * w + x]) outside++;
    }
  }
  return { all, outside, sizeA: [a.width, a.height], sizeB: [b.width, b.height] };
}

async function sharpness(browser, base, uri, dpr, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: dpr });
  const page = await ctx.newPage();
  await page.goto(base + uri, { waitUntil: 'load' });
  await settle(page);
  // naturalWidth is DENSITY-CORRECTED for a srcset pick (a 400w copy chosen at
  // sizes=206px reports 206), so the file's real width is read from a fresh
  // Image() of currentSrc, which has no srcset and reports pixels.
  const r = await page.evaluate(async (dpr) => {
    const imgs = [...document.images].filter(i => i.getBoundingClientRect().width > 40 && i.naturalWidth > 0);
    const out = [];
    for (const i of imgs) {
      const real = await new Promise(res => { const t = new Image(); t.onload = () => res([t.naturalWidth, t.naturalHeight]); t.onerror = () => res([0, 0]); t.src = i.currentSrc || i.src; });
      if (!real[0]) continue;
      const b = i.getBoundingClientRect(); const fit = getComputedStyle(i).objectFit;
      const need = fit === 'cover' ? Math.max(b.width, b.height * real[0] / real[1]) : b.width;
      out.push({ src: (i.currentSrc || i.src).replace(location.origin, ''), ratio: +(real[0] / (need * dpr)).toFixed(3), bytes: performance.getEntriesByName(i.currentSrc)[0]?.encodedBodySize ?? 0 });
    }
    return out;
  }, dpr);
  await ctx.close();
  return r;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const rows = [];
  for (const [name, uri] of (MODE === 'sharp' ? [] : PAGES)) {
    for (const width of [390, 1280]) {
      const b = await shoot(browser, BEFORE, uri, width, false), a = await shoot(browser, AFTER, uri, width, false);
      fs.writeFileSync(path.join(OUT, `${name}-${width}-before.png`), b.buf);
      fs.writeFileSync(path.join(OUT, `${name}-${width}-after.png`), a.buf);
      const d = diff(b.png, a.png, [...b.geo.imgs, ...a.geo.imgs], [...b.geo.random, ...a.geo.random]);
      const bn = await shoot(browser, BEFORE, uri, width, true), an = await shoot(browser, AFTER, uri, width, true);
      const dn = diff(bn.png, an.png, [], [...bn.geo.random, ...an.geo.random]);
      const row = { page: name, width, heightBefore: b.geo.height, heightAfter: a.geo.height, scrollWidthBefore: b.geo.scrollWidth,
        scrollWidthAfter: a.geo.scrollWidth, diffAll: d.all, diffOutsideImages: d.outside, diffImagesHidden: dn.all, randomHeaderExcluded: b.geo.random.length > 0, sizes: [d.sizeA, d.sizeB] };
      rows.push(row); console.log(JSON.stringify(row));
    }
  }
  const sharp = [];
  for (const [name, uri] of PAGES) for (const [dpr, width] of [[1, 1280], [1.75, 412], [3, 390]]) {
    for (const [label, base] of [['before', BEFORE], ['after', AFTER]]) {
      const r = await sharpness(browser, base, uri, dpr, width);
      const worst = r.reduce((m, x) => (x.ratio < m.ratio ? x : m), { ratio: 99 });
      const row = { page: name, dpr, tree: label, images: r.length, below1: r.filter(x => x.ratio < 0.999).length, worst: worst.ratio, worstSrc: worst.src,
        imgKiB: Math.round(r.reduce((s, x) => s + x.bytes, 0) / 1024) };
      sharp.push(row); console.log(JSON.stringify(row));
    }
  }
  fs.writeFileSync(path.join(OUT, MODE === 'sharp' ? 'sharp.json' : 'shots.json'), JSON.stringify({ rows, sharp }, null, 1));
  await browser.close();
})();
