/*
 * Lane IM2 evidence: what the product page's gallery strip and its REVIEW
 * photographs actually pull down the wire, at 390 and at 1280, before and
 * after — and the rendered geometry proving the strip is a row of equal
 * squares.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below; `npx playwright install` is forbidden.
 *
 * ── WHAT IS MEASURED, AND WHY IT IS MEASURED THIS WAY ──────────────────────
 *
 * Round one's tools/im-shots.cjs set the method and this keeps it: every
 * response is attributed to the element that asked for it by resolving each
 * <img>'s currentSrc — the URL the browser CHOSE out of the srcset, which is
 * the only honest answer once there is more than one candidate. `src` is what
 * the server offered; currentSrc is what the shopper paid for. Bytes are the
 * response body's own length.
 *
 * TWO NEW COLUMNS, BOTH OF THEM THE POINT OF THIS LANE:
 *
 *   PAINTED. naturalWidth/naturalHeight are the pixels that arrived and
 *   getBoundingClientRect is the box. Under object-fit:contain those two
 *   disagree in a way that matters: the BOX is square and the PICTURE inside it
 *   is not, so "the tile is 66x66" says nothing about whether the strip looks
 *   square. The painted rectangle is therefore computed from the intrinsic
 *   ratio and the box — contain fits, cover fills — and that is the number that
 *   answers the owner's screenshot.
 *
 *   FILE SHAPE. The intrinsic size of what arrived, so a portrait and a
 *   landscape shot in the same strip can be told apart in the table.
 *
 * Measuring a drawn box in JavaScript is fine here: CLAUDE.md forbids
 * JavaScript that SIZES the page, and this is an instrument reading it.
 *
 *   node tools/im2-shots.cjs        (IM2_BASE, IM2_OUT, IM2_TAG override)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IM2_BASE || 'http://127.0.0.1:8979';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.IM2_OUT || `${APP}/docs/lane-im2-shots`;
const TAG = process.env.IM2_TAG || 'after';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const PRODUCTS = [
  ['sized', 'im2-gallery', 'gallery and reviews that HAVE been through the batch'],
  ['cold', 'im2-no-variants', 'gallery and a review with NO copies at all'],
];

/* 390 at both device-pixel ratios, and the 2x row is the one that matters:
   every phone this shop sells to is 2x or 3x and the candidate a browser picks
   depends on it. A measurement taken only at dpr 1 understates a handset. */
const VIEWPORTS = [[390, 844, 1], [390, 844, 2], [1280, 900, 1]];

const kb = (n) => (n / 1024).toFixed(1) + ' KB';

async function measure(browser, slug, width, height, dpr) {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: dpr });
  const page = await context.newPage();

  const bytes = new Map();
  page.on('response', async (res) => {
    if (!(res.headers()['content-type'] || '').startsWith('image/')) return;
    try {
      const body = await res.body();
      bytes.set(new URL(res.url()).pathname, body.length);
    } catch (e) { /* a response with no body is not a download */ }
  });

  await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'networkidle' });

  // Both strips are lazy, so both have to be in view before anything is asked
  // for. The review wall is far below the fold on a phone.
  await page.evaluate(() => document.getElementById('gthumbs')?.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(900);
  await page.evaluate(() => document.getElementById('sr')?.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(1400);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(900);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);

  const dom = await page.evaluate(() => {
    const read = (img) => {
      const box = img.getBoundingClientRect();
      const fit = getComputedStyle(img).objectFit;
      const iw = img.naturalWidth;
      const ih = img.naturalHeight;

      /* WHAT IS ACTUALLY PAINTED inside the box. The box can be square while
         the picture in it is not — which is the whole of the owner's
         complaint — so this is the rectangle of real picture, computed from
         the fit mode rather than read off a property that does not exist. */
      let painted = [Math.round(box.width), Math.round(box.height)];

      if (iw > 0 && ih > 0 && box.width > 0 && box.height > 0) {
        const scale = fit === 'cover'
          ? Math.max(box.width / iw, box.height / ih)
          : Math.min(box.width / iw, box.height / ih);

        painted = fit === 'cover'
          ? [Math.round(box.width), Math.round(box.height)]
          : [Math.round(iw * scale), Math.round(ih * scale)];
      }

      return {
        currentSrc: img.currentSrc ? new URL(img.currentSrc).pathname : '',
        src: img.getAttribute('src') || '',
        srcset: img.getAttribute('srcset') || '',
        sizes: img.getAttribute('sizes') || '',
        fit,
        natural: [iw, ih],
        box: [Math.round(box.width * 10) / 10, Math.round(box.height * 10) / 10],
        painted,
      };
    };

    return {
      dpr: window.devicePixelRatio,
      scrollWidth: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
      main: [...document.querySelectorAll('.gmain-img')].map(read),
      thumbs: [...document.querySelectorAll('.gthumb-img')].map(read),
      // The review wall's own photographs, which are the larger half of this
      // lane and had no copies at all before it.
      reviews: [...document.querySelectorAll('.sr-pp img')].map(read),
      // The main frame's own box, which the coordinator asked to be measured
      // and not redesigned.
      mainBox: (() => {
        const el = document.getElementById('gmain');
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return [Math.round(r.width), Math.round(r.height)];
      })(),
    };
  });

  const attribute = (rows) => rows.map((r) => ({ ...r, bytes: bytes.get(r.currentSrc) ?? null }));

  const out = {
    slug, width, dprAsked: dpr, ...dom,
    main: attribute(dom.main),
    thumbs: attribute(dom.thumbs),
    reviews: attribute(dom.reviews),
  };

  const total = (rows) => rows.reduce((a, r) => a + (r.bytes || 0), 0);
  out.stripRequests = out.thumbs.filter((t) => t.currentSrc).length;
  out.stripBytes = total(out.thumbs);
  out.reviewRequests = out.reviews.filter((t) => t.currentSrc).length;
  out.reviewBytes = total(out.reviews);
  out.mainBytes = total(out.main);

  const stem = `${TAG}-${slug}-${width}${dpr > 1 ? 'x' + dpr : ''}`;
  await page.screenshot({ path: `${OUT}/${stem}.png`, fullPage: false });

  const strip = await page.$('#gthumbs');
  if (strip) await strip.screenshot({ path: `${OUT}/${stem}-strip.png` });

  const wall = await page.$('.sr-grid');
  if (wall) {
    await page.evaluate(() => document.querySelector('.sr-grid')?.scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(500);
    await wall.screenshot({ path: `${OUT}/${stem}-reviews.png` }).catch(() => {});
  }

  await context.close();
  return out;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const all = [];

  for (const [, slug] of PRODUCTS) {
    for (const [w, h, dpr] of VIEWPORTS) {
      all.push(await measure(browser, slug, w, h, dpr));
    }
  }

  await browser.close();

  const lines = [`# Lane IM2 — product page network + geometry (${TAG})`, ''];

  for (const r of all) {
    const label = PRODUCTS.find((p) => p[1] === r.slug)[2];
    lines.push(`## ${r.slug} @ ${r.width}px dpr ${r.dprAsked}   (${label})`);
    lines.push(`viewport ${r.viewport}  scrollWidth ${r.scrollWidth}  dpr ${r.dpr}  .gmain box ${(r.mainBox || []).join('×')}`);
    lines.push('');
    lines.push(`GALLERY STRIP : ${r.stripRequests} requests, ${kb(r.stripBytes)}`);
    lines.push(`REVIEW PHOTOS : ${r.reviewRequests} requests, ${kb(r.reviewBytes)}`);
    lines.push(`MAIN FRAME    : ${kb(r.mainBytes)}`);
    lines.push('');
    lines.push('### gallery strip');
    lines.push('');
    lines.push('| # | fetched | bytes | file shape | box (CSS) | PAINTED | fit | sizes |');
    lines.push('|---|---------|-------|------------|-----------|---------|-----|-------|');
    r.thumbs.forEach((t, i) => {
      lines.push(`| ${i + 1} | ${t.currentSrc || '(none)'} | ${t.bytes === null ? '?' : kb(t.bytes)} | ${t.natural.join('×')} | ${t.box.join('×')} | ${t.painted.join('×')} | ${t.fit} | ${t.sizes || '(none)'} |`);
    });
    lines.push('');
    lines.push('### review photographs');
    lines.push('');
    lines.push('| # | fetched | bytes | file shape | box (CSS) | fit | srcset? |');
    lines.push('|---|---------|-------|------------|-----------|-----|---------|');
    r.reviews.forEach((t, i) => {
      lines.push(`| ${i + 1} | ${t.currentSrc || '(none)'} | ${t.bytes === null ? '?' : kb(t.bytes)} | ${t.natural.join('×')} | ${t.box.join('×')} | ${t.fit} | ${t.srcset ? 'yes' : 'NO'} |`);
    });
    lines.push('');
    r.main.forEach((t) => {
      lines.push(`MAIN  ${t.currentSrc}  ${t.bytes === null ? '?' : kb(t.bytes)}  file ${t.natural.join('×')}  box ${t.box.join('×')}  painted ${t.painted.join('×')}  fit=${t.fit}`);
    });
    lines.push('');
  }

  lines.push('### reading the PAINTED column');
  lines.push('');
  lines.push('The `.gthumb` BOX has always been a 66px square. Under object-fit:contain the');
  lines.push('picture inside it is scaled until it FITS, so a 9:16 photograph paints a narrow');
  lines.push('stripe and a square one fills the tile — which is what the owner photographed.');
  lines.push('Under cover it is scaled until it FILLS and the box crops it, so PAINTED equals');
  lines.push('the box for every shape. That column, not the box column, is the answer to');
  lines.push('"make them square".');
  lines.push('');
  lines.push('`naturalWidth` is DENSITY-CORRECTED once a srcset has resolved, so the file');
  lines.push('shape column reads the CSS pixels the chosen candidate is worth, not its stored');
  lines.push('pixels. The filename in `fetched` is the honest record of which file arrived.');
  lines.push('');

  fs.writeFileSync(`${OUT}/${TAG}-product.md`, lines.join('\n'));
  fs.writeFileSync(`${OUT}/${TAG}-product.json`, JSON.stringify(all, null, 2));
  console.log(lines.join('\n'));
})();
