/*
 * The page background, photographed on the real shop.                (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8933 node tools/bg-shots.cjs
 *
 * ── WHAT IT SHOOTS, AND WHY EACH SET IS HERE ───────────────────────────────
 *
 *   today            the shop with no parameter at all. FIRST, and in every
 *                    contact sheet, because without it "different" and "better"
 *                    are the same word.
 *   a b c d          the four treatments, on five real pages, at both widths.
 *   the cycle        each treatment frozen at 0%, 33% and 66% of its own cycle.
 *                    A still of a moving thing proves nothing about the rest of
 *                    it, so the animation is PAUSED at a negative delay rather
 *                    than caught mid-flight: the same three frames come back on
 *                    every run, which is also what makes the byte-identical
 *                    check in tools/bg-sheet.cjs mean something.
 *   reduced motion   the same pages in a context that reports
 *                    prefers-reduced-motion: reduce. There must be no animation
 *                    on the page at all, and the measurement below reads
 *                    getAnimations() rather than trusting the stylesheet.
 *   signed out       /?kbbwash=b with no admin session, which must be
 *                    byte-identical to today. The gate, photographed.
 *   arabic           /ar/ and /ar/shop/, because the wash is drawn by two
 *                    pseudo-elements with a logical inset and somebody has to
 *                    look at the mirrored page rather than reason about it.
 *
 * ── HOW A FRAME IS FROZEN ──────────────────────────────────────────────────
 *
 * `animation-play-state:paused` with `animation-delay:-Ns` puts the animation
 * exactly N seconds into its cycle and holds it there. It is injected as a
 * stylesheet, so it overrides the emitted rule without this script knowing
 * anything about the numbers in it — and it is the ONLY thing this script
 * injects into a storefront page.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8933';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.BG_OUT || `${APP}/docs/bg-shots`;

const PAGES = [
  ['home', '/'],
  ['shop', '/shop/'],
  ['product', '/product/lanebg-1/'],
  ['cart', '/cart/'],
  ['journal', '/blog/'],
];

const TREATMENTS = ['a', 'b', 'c', 'd'];
const WIDTHS = [[390, 844], [1280, 900]];

/* The measurements that matter, taken on the page itself.
 *
 * `scrollWidth` at both widths is the one the brief asks for by name. The other
 * six are the "it must not eat the shop" checks, read off computed style rather
 * than looked at: a header, a card, the cart drawer and the tab bar must each
 * keep the background colour they have today, and the three wash layers must be
 * behind everything (a negative z-index) and untouchable (pointer-events none).
 */
const MEASURE = () => {
  const cs = (el, p) => (el ? getComputedStyle(el).getPropertyValue(p) : null);
  const pseudo = (sel, p) => {
    const el = document.querySelector(sel.split('::')[0]);
    return el ? getComputedStyle(el, '::' + sel.split('::')[1]).getPropertyValue(p) : null;
  };
  /* NAMED SURFACES, ONE SELECTOR EACH.
   *
   * This was a list of four alternatives in one querySelector -- the first
   * element in DOM order matching ANY of them -- and it reported the home page
   * card "changing" from rgba(255,255,255,.94) to transparent under all four
   * treatments AND under the signed-out control, which emits no wash at all.
   * That is the tell: the selector had matched a different element, not the
   * element had changed. A measurement that moves on a page nothing changed on
   * is worse than no measurement, because it reads as a regression. */
  const header = document.querySelector('header');
  const card = document.querySelector('.kbb-home .sec > .wrap');
  const productCard = document.querySelector('.kbb-card');
  const drawer = document.querySelector('#kbbCart, .cart-drawer, .qv-modal');
  const panel = document.querySelector('.kbb-cart .wrap, .kbb-checkout .co-grid');
  const tabbar = document.querySelector('.tabbar');
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    scrollHeight: document.documentElement.scrollHeight,
    washBlock: !!document.getElementById('kbb-page-wash'),
    washBytes: document.getElementById('kbb-page-wash')?.textContent.length ?? 0,
    bodyBg: cs(document.body, 'background-color'),
    bodyBgImage: cs(document.body, 'background-image').slice(0, 40),
    beforeZ: pseudo('body::before', 'z-index'),
    beforePos: pseudo('body::before', 'position'),
    beforeEvents: pseudo('body::before', 'pointer-events'),
    afterZ: pseudo('body::after', 'z-index'),
    htmlBeforeZ: pseudo('html::before', 'z-index'),
    headerBg: cs(header, 'background-color'),
    headerOpacity: cs(header, 'opacity'),
    cardBg: cs(card, 'background-color'),
    cardOpacity: cs(card, 'opacity'),
    productCardBg: cs(productCard, 'background-color'),
    drawerBg: cs(drawer, 'background-color'),
    panelBg: cs(panel, 'background-color'),
    tabbarBg: cs(tabbar, 'background-color'),
    bodyFontSize: cs(document.body, 'font-size'),
    /* Every running animation on the document, by name. Under reduced motion
       this has to be empty -- and it is read from the ANIMATION TIMELINE, not
       from the stylesheet, so a media query that did not apply is caught. */
    animations: document.getAnimations().map((a) => a.animationName || String(a.constructor.name)),
  };
};

/* EVERY ROW CARRIES THE PAGE IT WAS REALLY TAKEN ON.
 *
 * The reduced-motion and signed-out sets moved from the home page to /shop/
 * (the home page's sections are white cards laid edge to edge, so it shows
 * almost nothing of a background) and their `page` label did not move with
 * them. Two different pages then shared one key, the later row overwrote the
 * earlier, and the comparison that reads this file reported the home page's
 * section card "disappearing" under all four treatments AND under the
 * signed-out control — which emits no wash at all, and is the tell that the
 * measurement had moved rather than the page. */
const rows = [];

async function shoot(page, name, w, h, extra = {}) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(450);
  const m = await page.evaluate(MEASURE);
  await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
  const row = { shot: `${name}-${w}`, ...extra, ...m };
  rows.push(row);
  console.log(JSON.stringify(row));
}

async function freeze(page, seconds) {
  await page.addStyleTag({
    content:
      'body::before,body::after{animation-delay:-' + seconds + 's !important;' +
      'animation-play-state:paused !important}',
  });
  await page.waitForTimeout(250);
}

async function signIn(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.close();
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  // ── the ordinary, signed-in contexts ────────────────────────────────────
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await signIn(ctx);
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

  for (const [w, h] of WIDTHS) {
    for (const [pname, url] of PAGES) {
      // today: no parameter, nothing emitted
      await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
      await shoot(page, `now-${pname}`, w, h, { variant: 'now', page: pname });

      for (const t of TREATMENTS) {
        await page.goto(`${BASE}${url}?kbbwash=${t}`, { waitUntil: 'networkidle' });
        await freeze(page, 0);
        await shoot(page, `${t}-${pname}`, w, h, { variant: t, page: pname, cyclePct: 0 });
      }
    }

    /* ── the cycle: 0%, 33% and 66% of each treatment, ON /shop/ ──────────
     *
     * NOT on the home page, which was the first draft and showed nothing. The
     * home page's sections are white cards at rgba(255,255,255,.94) laid edge
     * to edge over the page, so the wash shows at the gutters and between
     * sections and nowhere else -- twelve frames of it came back looking like
     * one frame. /shop/ is the page where the background IS most of the page,
     * so it is the page that can show a background moving.
     */
    for (const t of TREATMENTS) {
      const cycle = { a: 420, b: 120, c: 240, d: 180 }[t];
      for (const pct of [0, 33, 66]) {
        await page.goto(`${BASE}/shop/?kbbwash=${t}`, { waitUntil: 'networkidle' });
        await freeze(page, Math.round((cycle * pct) / 100));
        await shoot(page, `cycle-${t}-${pct}`, w, h, { variant: t, page: 'shop', cyclePct: pct });
      }
    }

    // ── treatment d scrolled down, where its fade-out is the whole point ──
    await page.goto(`${BASE}/shop/?kbbwash=d`, { waitUntil: 'networkidle' });
    await page.evaluate(() => window.scrollTo(0, Math.round(window.innerHeight * 1.4)));
    await shoot(page, 'd-scrolled', w, h, { variant: 'd', page: 'home-scrolled' });

    // ── arabic, mirrored ─────────────────────────────────────────────────
    for (const [pname, url] of [['ar-shop', '/ar/shop/'], ['ar-home', '/ar/']]) {
      await page.goto(`${BASE}${url}?kbbwash=a`, { waitUntil: 'networkidle' });
      await freeze(page, 0);
      await shoot(page, pname, w, h, { variant: 'a', page: pname });
      await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle' });
      await shoot(page, `now-${pname}`, w, h, { variant: 'now', page: pname });
    }
  }
  await page.close();

  // ── prefers-reduced-motion: reduce ──────────────────────────────────────
  const rm = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  await signIn(rm);
  const rmPage = await rm.newPage();

  for (const [w, h] of WIDTHS) {
    for (const t of TREATMENTS) {
      await rmPage.goto(`${BASE}/shop/?kbbwash=${t}`, { waitUntil: 'networkidle' });
      await rmPage.waitForTimeout(600);
      await shoot(rmPage, `reduced-${t}`, w, h, { variant: t, page: 'shop', reducedMotion: true });
    }
  }
  await rm.close();

  // ── the gate: a visitor with no admin session ───────────────────────────
  const out = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const outPage = await out.newPage();

  for (const [w, h] of WIDTHS) {
    await outPage.goto(`${BASE}/shop/?kbbwash=b`, { waitUntil: 'networkidle' });
    await shoot(outPage, 'signedout-b-home', w, h, { variant: 'signed-out ?kbbwash=b', page: 'shop' });
  }
  await out.close();

  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(rows, null, 2));
  await browser.close();
  console.log(JSON.stringify({ done: rows.length, out: OUT }));
})();
