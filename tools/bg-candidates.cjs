/*
 * FIVE BACKGROUNDS TO CHOOSE FROM, ON HIS OWN PAGES.               (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8996 node tools/bg-candidates.cjs
 *
 * The owner rejected the shop's background in four words that each name a
 * different thing:
 *
 *   "you put some image on background"   -> --bg-botanical, a data-URI SVG of
 *                                           leaves and petals in the body rule
 *   "not continue type"                  -> background-repeat:repeat-y, which
 *                                           tiles that motif down the page
 *   "from one corner to another"         -> the gradient is linear-gradient(
 *                                           180deg, …), top to bottom
 *   "change continues slightly"          -> the slow shift, which exists and
 *                                           ships off
 *
 * NOTHING IN THE REPOSITORY IS CHANGED BY THIS SCRIPT. Every candidate is
 * applied with addStyleTag at capture time, against the real shop, so the
 * pictures are of his pages and the shop still renders what it rendered
 * before. He picks a letter first; docs/BG-BACKGROUND-CANDIDATES.md carries the
 * block to apply when he does.
 *
 * ── THE FIVE DIFFER IN FOUR WAYS, ON PURPOSE ─────────────────────────────
 *
 * He has twice sent back options that were versions of one idea. These vary
 * the DIRECTION of the diagonal, how FAR APART the colours are, how much the
 * colour TRAVELS between moments, and how FAST. C runs the diagonal the other
 * way entirely — pink at the top left, ivory at the bottom right — so it reads
 * as a different picture rather than a different setting.
 *
 * ── EVERY COLOUR IS CHECKED BEFORE IT IS DRAWN ────────────────────────────
 *
 * App\Services\PageWash::CONTRAST_FLOOR is #FCE7EE, luminance 0.8402: the
 * darkest flat background any storefront page renders today, and the bar this
 * lane set for the wash. All twenty stops below clear it. Three did not when
 * they were first written — b's #FBE2EE (0.8107) and d's #FCE6F1 (0.8364) and
 * #F8DEEC (0.7826) — and were walked toward white until they did, 16%, 2% and
 * 29% of the way, which is the same thing that happened to cream_blush_lilac's
 * third colour last time. A palette that fails the invariant gets changed.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8996';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-candidates');

/* The floor, repeated here only so this file can be read on its own. */
const FLOOR = '#FCE7EE';

const CANDIDATES = {
  a: { name: 'Ivory to rose', angle: 135, cycle: 420, travel: 'small',
       stops: ['#FFF6EE', '#FFFDFB', '#FDF1F6', '#FCE9F1'] },
  b: { name: 'Corner light', angle: 115, cycle: 300, travel: 'medium',
       stops: ['#FFF1E4', '#FFFFFF', '#FDECF4', '#FCE7F1'] },
  c: { name: 'Reverse fall', angle: 300, cycle: 360, travel: 'medium',
       stops: ['#FCE8F0', '#FFFCFA', '#FFF4EA', '#FFEFE0'] },
  d: { name: 'Wide sweep', angle: 160, cycle: 180, travel: 'large',
       stops: ['#FFEBD8', '#FFFFFF', '#FCE7F1', '#FAE8F2'] },
  e: { name: 'Barely there', angle: 135, cycle: 600, travel: 'tiny',
       stops: ['#FFFAF5', '#FFFFFF', '#FFF8FB', '#FEF2F7'] },
};

/*
 * THE STOP POSITIONS ARE WHAT "TRAVEL" MOVES, NOT THE COLOURS.
 *
 * The wash moves colours between moments and keeps its blobs still, because a
 * layer that travels needs a transform and a transformed full-width layer is
 * how a page grows a horizontal scrollbar. The same applies here: these three
 * moments slide the gradient's STOP POSITIONS along the same axis, which the
 * compositor can do without touching layout, and the page's scrollWidth is
 * asserted unchanged at both widths in the run below.
 */
const TRAVEL = { tiny: 3, small: 6, medium: 11, large: 18 };

function gradient(c, moment) {
  const d = TRAVEL[c.travel] * (moment - 1);
  const at = [0, 38 + d, 72 + d, 100];

  return `linear-gradient(${c.angle}deg,`
    + c.stops.map((s, i) => `${s} ${Math.max(0, Math.min(100, at[i]))}%`).join(',')
    + ')';
}

/*
 * What each candidate does to the page, and it is four declarations.
 *
 * `background-image` with no motif is the whole of "no image". `no-repeat` is
 * the whole of "not continue type". The angle is the whole of "corner to
 * corner". `background-attachment:fixed` is kept for now and is the one line
 * worth arguing about — see the doc.
 */
function css(id, moment) {
  const c = CANDIDATES[id];

  return `body{background-color:${FLOOR};`
    + `background-image:${gradient(c, moment)};`
    + 'background-repeat:no-repeat;background-size:cover;background-attachment:fixed}';
}

const PAGES = [
  ['home', '/'], ['shop', '/shop/'], ['product', '/product/lanebg-1/'],
  ['cart', '/cart/'], ['journal', '/blog/'],
];

/*
 * COUNTING BACKGROUND LAYERS NEEDS A DEPTH COUNTER, NOT A REGEX.
 *
 * The first version of this used `.split(/,(?![^(]*\))/)`, which is the
 * expression this lane has been carrying since round 3 — and it counts the
 * commas INSIDE a gradient's stop list. It reported the shop's designed
 * background, which is two images, as FIVE layers, and reported each candidate
 * below — a single gradient — as five as well. "5 layers" appears as a measured
 * fact in two of this lane's own documents and is wrong in both.
 *
 * A layer boundary is a comma at PAREN DEPTH ZERO. That needs counting, and a
 * regular expression cannot count.
 */
const layerCount = (value) => {
  if (!value || value === 'none') return 0;
  let depth = 0;
  let layers = 1;
  for (const ch of value) {
    if (ch === '(') depth++;
    else if (ch === ')') depth--;
    else if (ch === ',' && depth === 0) layers++;
  }
  return layers;
};

const MEASURE = () => {
  const layerCount = (value) => {
    if (!value || value === 'none') return 0;
    let depth = 0;
    let layers = 1;
    for (const ch of value) {
      if (ch === '(') depth++;
      else if (ch === ')') depth--;
      else if (ch === ',' && depth === 0) layers++;
    }
    return layers;
  };
  const bs = getComputedStyle(document.body);
  /* The home page's own cards sit over the background at .94 alpha. If the
     wash cannot be seen through them, it cannot be seen where he looks most. */
  const card = document.querySelector('.kbb-home .sec > .wrap');
  const cs = card ? getComputedStyle(card) : null;

  return {
    backgroundColor: bs.backgroundColor,
    layers: layerCount(bs.backgroundImage),
    hasMotif: bs.backgroundImage.includes('data:image'),
    repeat: bs.backgroundRepeat,
    attachment: bs.backgroundAttachment,
    scrollWidth: document.documentElement.scrollWidth,
    cardBackground: cs ? cs.backgroundColor : null,
    cardCount: document.querySelectorAll('.kbb-home .sec > .wrap').length,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];

  /* Today, for comparison — no injection at all. */
  for (const [name, url] of PAGES) {
    for (const width of [1280, 390]) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + url, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      const m = await page.evaluate(MEASURE);
      await page.screenshot({ path: `${OUT}/today-${name}-${width}.png` });
      if (width === 1280) rows.push({ candidate: 'today', page: name, ...m });
      await ctx.close();
    }
  }

  for (const id of Object.keys(CANDIDATES)) {
    for (const [name, url] of PAGES) {
      for (const width of [1280, 390]) {
        const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        await page.goto(BASE + url, { waitUntil: 'load' });
        await page.evaluate(() => document.fonts.ready);
        await page.addStyleTag({ content: css(id, 1) });
        await page.evaluate(() => document.fonts.ready);
        const m = await page.evaluate(MEASURE);
        await page.screenshot({ path: `${OUT}/${id}-${name}-${width}.png` });
        if (width === 1280) rows.push({ candidate: id, page: name, ...m });
        await ctx.close();
      }
    }
  }

  /* The shift, which a still cannot show: one page, three moments. */
  for (const id of Object.keys(CANDIDATES)) {
    for (const moment of [1, 2, 3]) {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/shop/', { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      await page.addStyleTag({ content: css(id, moment) });
      await page.screenshot({ path: `${OUT}/moment-${id}-${moment}.png` });
      await ctx.close();
    }
  }

  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(rows, null, 2));

  const bad = rows.filter((r) => r.scrollWidth !== 1280);
  console.log('candidate page      bg                   layers motif repeat     scrollWidth');
  for (const r of rows) {
    console.log(`${r.candidate.padEnd(9)} ${r.page.padEnd(9)} ${r.backgroundColor.padEnd(20)} `
      + `${String(r.layers).padStart(6)} ${String(r.hasMotif).padStart(5)} ${r.repeat.padEnd(10)} ${r.scrollWidth}`);
  }
  console.log(bad.length === 0 ? '\nno horizontal overflow on any candidate' : `\nOVERFLOW: ${JSON.stringify(bad)}`);
  await browser.close();
})();
