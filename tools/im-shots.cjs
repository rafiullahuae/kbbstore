/*
 * Lane IM evidence: what the product gallery's thumbnail strip actually pulls
 * down the wire, at 390 and at 1280, before and after.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * ── WHAT IS MEASURED, AND WHY IT IS MEASURED THIS WAY ──────────────────────
 *
 * Every response is attributed to the element that asked for it, by resolving
 * each <img>'s currentSrc — the URL the browser CHOSE out of the srcset, which
 * is the only honest answer once there is more than one candidate. `src` is
 * what the server offered; currentSrc is what the shopper paid for.
 *
 * Bytes are `encodedBodySize` off the Resource Timing entry rather than the
 * Content-Length header, because that is the figure that includes the transfer
 * and is what a browser reports for a file it actually fetched. Where an entry
 * is missing (a cache hit inside the run) the response's own body length is
 * used and the row is marked.
 *
 * naturalWidth/naturalHeight are the INTRINSIC pixels that arrived;
 * getBoundingClientRect is the CSS box they were drawn into. The ratio between
 * them is the waste this lane exists to remove. Measuring the drawn box in
 * JavaScript is fine — CLAUDE.md forbids JavaScript that SIZES the page, and
 * this is an instrument reading it, not the shop laying itself out.
 *
 *   node tools/im-shots.cjs           (IM_BASE, IM_OUT, IM_TAG override)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.IM_BASE || 'http://127.0.0.1:8977';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.IM_OUT || `${APP}/docs/lane-im-shots`;
const TAG = process.env.IM_TAG || 'after';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const PRODUCTS = [
  ['full', 'im-full-gallery', 'gallery whose photographs have been through the batch'],
  ['novariant', 'im-no-variants', 'gallery whose photographs have NO copies at all'],
];

/* 390 is photographed at BOTH device-pixel ratios, and the 2x row is the one
   that matters: every phone this shop actually sells to is 2x or 3x, and the
   candidate a browser picks depends on it. A measurement taken only at dpr 1
   would understate what a real handset asks for. */
const VIEWPORTS = [[390, 844, 1], [390, 844, 2], [1280, 900, 1]];

function kb(n) { return (n / 1024).toFixed(1) + ' KB'; }

async function measure(browser, slug, width, height, dpr) {
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: dpr,
  });
  const page = await context.newPage();

  // Every image response, keyed by URL, with the bytes that came back.
  const bytes = new Map();
  page.on('response', async (res) => {
    const type = (res.headers()['content-type'] || '');
    if (!type.startsWith('image/')) return;
    try {
      const body = await res.body();
      bytes.set(new URL(res.url()).pathname, body.length);
    } catch (e) { /* a response with no body is not a download */ }
  });

  await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'networkidle' });
  // The strip is lazy, so it has to be in view before any of it is asked for.
  await page.evaluate(() => document.getElementById('gthumbs')?.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(400);

  const dom = await page.evaluate(() => {
    const read = (img) => ({
      currentSrc: img.currentSrc ? new URL(img.currentSrc).pathname : '',
      src: img.getAttribute('src') || '',
      srcset: img.getAttribute('srcset') || '',
      sizes: img.getAttribute('sizes') || '',
      loading: img.getAttribute('loading') || '',
      fetchpriority: img.getAttribute('fetchpriority') || '',
      natural: [img.naturalWidth, img.naturalHeight],
      css: [
        Math.round(img.getBoundingClientRect().width * 10) / 10,
        Math.round(img.getBoundingClientRect().height * 10) / 10,
      ],
      complete: img.complete && img.naturalWidth > 0,
    });

    return {
      dpr: window.devicePixelRatio,
      scrollWidth: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
      main: [...document.querySelectorAll('.gmain-img')].map(read),
      thumbs: [...document.querySelectorAll('.gthumb-img')].map(read),
    };
  });

  const attribute = (rows) => rows.map((r) => ({
    ...r,
    bytes: bytes.get(r.currentSrc) ?? null,
  }));

  const out = {
    slug, width, dprAsked: dpr, ...dom,
    main: attribute(dom.main),
    thumbs: attribute(dom.thumbs),
  };

  out.stripRequests = out.thumbs.filter((t) => t.currentSrc).length;
  out.stripBytes = out.thumbs.reduce((a, t) => a + (t.bytes || 0), 0);
  out.mainBytes = out.main.reduce((a, t) => a + (t.bytes || 0), 0);

  const stem = `${TAG}-${slug}-${width}${dpr > 1 ? 'x' + dpr : ''}`;
  await page.screenshot({ path: `${OUT}/${stem}.png`, fullPage: false });

  // And a close crop of just the strip, which at 66px is unreadable in a full
  // page shot at 1280.
  const strip = await page.$('#gthumbs');
  if (strip) {
    await strip.screenshot({ path: `${OUT}/${stem}-strip.png` });
  }

  await context.close();
  return out;
}

/*
 * THE OTHER HALF OF THE SWEEP. The basket line thumbnail is a 42px square drawn
 * as a CSS `background-image`, which cannot carry a srcset, so the width is
 * chosen on the server. There is no <img> to read currentSrc off, so this
 * counts the image responses the page made and reads the computed
 * background-image off each square.
 */
async function measureCart(browser, width, height, dpr, slugs) {
  const context = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: dpr });
  const page = await context.newPage();

  /* THE BASKET IS FILLED THROUGH THE REAL ENDPOINT and not by planting a
     cookie. `kbb_cart` is in Laravel's encrypted set (bootstrap/app.php), so a
     token written straight into the browser is unreadable to the server and the
     page renders an EMPTY basket — which measures 0 requests and looks, in a
     report, exactly like a fix that worked. POST /cart/add with the page's own
     CSRF token leaves the cookie the server itself issued. */
  for (const slug of slugs) {
    await page.goto(`${BASE}/product/${slug}`, { waitUntil: 'domcontentloaded' });

    const added = await page.evaluate(async (s) => {
      // window.KBB carries the token and the route base; the page's own cart.js
      // reads exactly these, so this adds the way a shopper's tap adds.
      const id = document.querySelector('[data-kbb-add]')?.getAttribute('data-kbb-add')
        || document.querySelector('[data-kbb-wish]')?.getAttribute('data-kbb-wish');
      const res = await fetch(`${window.KBB.routes.cartApi}/add`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
        body: JSON.stringify({ product_id: Number(id), quantity: 1 }),
      });
      return res.status;
    }, slug);

    if (added >= 400) throw new Error(`/api/cart/add answered ${added} for ${slug}`);
  }

  const seen = [];
  page.on('response', async (res) => {
    if (!(res.headers()['content-type'] || '').startsWith('image/')) return;
    try {
      const body = await res.body();
      seen.push({ path: new URL(res.url()).pathname, bytes: body.length });
    } catch (e) { /* no body */ }
  });

  await page.goto(`${BASE}/cart`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  const squares = await page.evaluate(() => [...document.querySelectorAll('.cth, .kc-th')].map((el) => ({
    cls: el.className,
    css: [Math.round(el.getBoundingClientRect().width), Math.round(el.getBoundingClientRect().height)],
    background: getComputedStyle(el).backgroundImage,
  })));

  await page.screenshot({ path: `${OUT}/${TAG}-cart-${width}${dpr > 1 ? 'x' + dpr : ''}.png` });
  await context.close();

  const photos = seen.filter((r) => r.path.includes('/uploads/im/') || r.path.includes('/img-cache/'));

  return {
    slug: 'cart', width, dprAsked: dpr, squares,
    requests: photos.length,
    bytes: photos.reduce((a, r) => a + r.bytes, 0),
    responses: photos,
  };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const all = [];
  const carts = [];

  for (const [, slug] of PRODUCTS) {
    for (const [w, h, dpr] of VIEWPORTS) {
      all.push(await measure(browser, slug, w, h, dpr));
    }
  }

  for (const [w, h, dpr] of [[390, 844, 2], [1280, 900, 1]]) {
    carts.push(await measureCart(browser, w, h, dpr, PRODUCTS.map((p) => p[1])));
  }

  await browser.close();

  const lines = [];
  lines.push(`# Lane IM — gallery network measurement (${TAG})`);
  lines.push('');

  for (const r of all) {
    const label = PRODUCTS.find((p) => p[1] === r.slug)[2];
    lines.push(`## ${r.slug} @ ${r.width}px dpr ${r.dprAsked}   (${label})`);
    lines.push(`viewport ${r.viewport}  scrollWidth ${r.scrollWidth}  dpr ${r.dpr}`);
    lines.push('');
    lines.push(`STRIP: ${r.stripRequests} image requests, ${kb(r.stripBytes)} total`);
    lines.push(`MAIN : ${kb(r.mainBytes)}`);
    lines.push('');
    lines.push('| # | fetched | bytes | intrinsic | drawn (CSS) | waste | loading |');
    lines.push('|---|---------|-------|-----------|-------------|-------|---------|');
    r.thumbs.forEach((t, i) => {
      const waste = t.css[0] > 0 ? (t.natural[0] / t.css[0]).toFixed(1) + '×' : '-';
      lines.push(`| ${i + 1} | ${t.currentSrc || '(none)'} | ${t.bytes === null ? '?' : kb(t.bytes)} | ${t.natural.join('×')} | ${t.css.join('×')} | ${waste} | ${t.loading} |`);
    });
    lines.push('');
    r.main.forEach((t) => {
      lines.push(`MAIN  ${t.currentSrc}  ${t.bytes === null ? '?' : kb(t.bytes)}  intrinsic ${t.natural.join('×')}  drawn ${t.css.join('×')}  loading=${t.loading} fetchpriority=${t.fetchpriority}`);
    });
    lines.push('');
  }

  for (const c of carts) {
    lines.push(`## /cart @ ${c.width}px dpr ${c.dprAsked}   (basket line thumbnails: a CSS background, no srcset possible)`);
    lines.push('');
    lines.push(`${c.requests} product-image requests, ${kb(c.bytes)} total`);
    lines.push('');
    for (const r of c.responses) lines.push(`  ${r.path}  ${kb(r.bytes)}`);
    lines.push('');
    for (const sq of c.squares) lines.push(`  .${sq.cls.trim().split(/\s+/)[0]}  drawn ${sq.css.join('\u00d7')}  ${sq.background}`);
    lines.push('');
  }

  lines.push('### reading the intrinsic column');
  lines.push('');
  lines.push('`naturalWidth` is DENSITY-CORRECTED once a srcset has resolved: a 200px file');
  lines.push('chosen for a 66px box reports 66, not 200. So the "waste" column is the ratio');
  lines.push('the browser itself sees, and 1.0 means the file carries exactly the pixels the');
  lines.push('box can show. Before the change, the same column read 1000 against a 52px box.');
  lines.push('The filename in the `fetched` column is the honest record of which file arrived.');
  lines.push('');

  fs.writeFileSync(`${OUT}/${TAG}-measurements.md`, lines.join('\n'));
  fs.writeFileSync(`${OUT}/${TAG}-measurements.json`, JSON.stringify({ gallery: all, cart: carts }, null, 2));
  console.log(lines.join('\n'));
})();
