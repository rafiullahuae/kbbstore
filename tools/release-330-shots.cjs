/*
 * The 2.60.330 release, photographed on the merged tree.        (integrator)
 *
 *   SHOT_BASE=http://127.0.0.1:8971 node int-330-shots.cjs
 *
 * Five things shipped and every one of them is something the owner asked for,
 * so every one gets a picture at both widths: the typeface, the product page,
 * the homepage banner with the flag strip under it, the product card, and the
 * background. The numbers are read out of the document rather than claimed.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.SHOT_BASE || 'http://127.0.0.1:8971';
const OUT = process.env.SHOT_OUT || '/tmp/claude-0/-home-user-kbbstore/719ff49f-5d63-5981-9f03-fffb8cde43b0/scratchpad/int-330-shots';


/* THE PREVIEW HAS NO APP_URL, so Laravel's asset() builds `http://localhost/...`
   for every upload. Nothing is wrong with the banner: the browser is asking a
   host that is not this server. Rewrite those requests onto the preview rather
   than photograph a broken-image icon and call it a banner. */
const routeUploads = async (ctx) => {
  await ctx.route('**://localhost/**', (route) => {
    const u = new URL(route.request().url());
    return route.continue({ url: BASE + u.pathname + u.search });
  });
};

const PAGES = [['home', '/'], ['shop', '/shop/'], ['product', null]];

/* Measured in the page, never asserted from the CSS source. */
const MEASURE = () => {
  const layers = (v) => {
    if (!v || v === 'none') return 0;
    let d = 0, n = 1;
    for (const c of v) { if (c === '(') d++; else if (c === ')') d--; else if (c === ',' && d === 0) n++; }
    return n;
  };

  /* THE BACKGROUND IS NOT ON body. It paints on three FIXED PSEUDO-ELEMENT
     LAYERS -- html::before and body::before/::after -- with body itself set to
     `background-image:none`. Reading getComputedStyle(document.body) reports
     zero layers and no gradient, which is what the first version of this file
     did and it made a working feature look broken. Read the layers. */
  const bg = (el, pseudo) => {
    const cs = getComputedStyle(el, pseudo);
    return { image: cs.backgroundImage, layers: layers(cs.backgroundImage),
             position: cs.position, animation: cs.animationName,
             duration: cs.animationDuration };
  };
  const bodyCs = getComputedStyle(document.body);
  const cards = [...document.querySelectorAll('.kbb-card')];
  const heights = [...new Set(cards.map((c) => Math.round(c.getBoundingClientRect().height)))].sort((a, b) => a - b);
  const h1 = document.querySelector('h1');
  const name = document.querySelector('.kbb-card-nm');

  /* Which face the text is actually PAINTED in, not which was asked for: a
     width ruler against a face the page never loaded is the standard way this
     measurement lies. document.fonts.check is the honest question. */
  const outfitLoaded = document.fonts.check('400 14px Outfit');

  return {
    fontFamily: bodyCs.fontFamily,
    outfitLoaded,
    h1Size: h1 ? getComputedStyle(h1).fontSize : null,
    h1Weight: h1 ? getComputedStyle(h1).fontWeight : null,
    nameSize: name ? getComputedStyle(name).fontSize : null,
    nameWeight: name ? getComputedStyle(name).fontWeight : null,
    bodyImage: bodyCs.backgroundImage,
    bodyColor: bodyCs.backgroundColor,
    gradient: bg(document.documentElement, '::before'),
    shiftB: bg(document.body, '::before'),
    shiftC: bg(document.body, '::after'),
    hasDrawing: [bodyCs.backgroundImage,
                 getComputedStyle(document.documentElement, '::before').backgroundImage]
                .some((v) => v.includes('data:image')),
    bgRepeat: bodyCs.backgroundRepeat,
    cardCount: cards.length,
    cardHeights: heights,
    scrollWidth: document.documentElement.scrollWidth,
    bannerImgs: document.querySelectorAll('img[src*="int330-banner"], img[srcset*="int330-banner"]').length,
    /* naturalWidth is 0 for an image that failed to load, so this counts the
       ones that actually PAINTED -- an <img> element is not a picture. */
    bannerPainted: [...document.querySelectorAll('img[src*="int330-banner"]')].filter((i) => i.complete && i.naturalWidth > 0).length,
    bannerArrows: document.querySelectorAll('[class*="slider"] button, .pb-arrow, [class*="arrow"]').length,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  /* The product page needs a real product, so ask the shop for one. */
  const probe = await browser.newPage();
  await probe.goto(BASE + '/shop/', { waitUntil: 'load' });
  const href = await probe.evaluate(() => {
    const a = document.querySelector('a[href*="/product/"]');
    return a ? new URL(a.href).pathname : null;
  });
  await probe.close();
  PAGES[2][1] = href;
  console.log('product page:', href);

  const rows = [];
  for (const [name, url] of PAGES) {
    if (!url) { console.log('SKIP', name, '- no product found'); continue; }
    for (const width of [1280, 390]) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
      await routeUploads(ctx);
      const page = await ctx.newPage();
      await page.goto(BASE + url, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const m = await page.evaluate(MEASURE);
      rows.push({ page: name, width, ...m });
      await page.screenshot({ path: `${OUT}/330-${name}-${width}.png`, fullPage: name !== 'home' });
      if (name === 'home') await page.screenshot({ path: `${OUT}/330-${name}-${width}-full.png`, fullPage: true });
      await ctx.close();
    }
  }

  /* 320 too, because the card's equal heights were worst there and the sale
     price was being clipped. */
  for (const [name, url] of [['shop', '/shop/']]) {
    const ctx = await browser.newContext({ viewport: { width: 320, height: 800 }, deviceScaleFactor: 1 });
    await routeUploads(ctx);
    const page = await ctx.newPage();
    await page.goto(BASE + url, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    rows.push({ page: name, width: 320, ...(await page.evaluate(MEASURE)) });
    await page.screenshot({ path: `${OUT}/330-${name}-320.png`, fullPage: true });
    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(rows, null, 2));
  console.log('\npage     w     font-resolved  Outfit  grad-layers pos      anim-dur   drawing cards heights                 scrollW  banner');
  for (const r of rows) {
    console.log(
      `${r.page.padEnd(8)} ${String(r.width).padEnd(5)} ${String(r.fontFamily).split(',')[0].padEnd(14)} `
      + `${String(r.outfitLoaded).padEnd(7)} ${String(r.gradient.layers).padEnd(11)} ${String(r.gradient.position).padEnd(8)} `
      + `${String(r.shiftB.duration).padEnd(10)} ${String(r.hasDrawing).padEnd(7)} `
      + `${String(r.cardCount).padStart(5)} ${JSON.stringify(r.cardHeights).padEnd(24)} ${String(r.scrollWidth).padEnd(8)} ${r.bannerPainted}/${r.bannerImgs}`);
  }
  const over = rows.filter((r) => r.scrollWidth > r.width);
  console.log(over.length ? `\nHORIZONTAL OVERFLOW: ${JSON.stringify(over.map((r) => [r.page, r.width, r.scrollWidth]))}` : '\nno horizontal overflow at any width');
  await browser.close();
})();
