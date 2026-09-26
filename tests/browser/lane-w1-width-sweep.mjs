/**
 * LANE W1 — the width sweep. One row per (page, viewport width).
 *
 * WHAT IT MEASURES, and why each number rather than a screenshot:
 *
 *   fits       document.documentElement.scrollWidth vs clientWidth. The claim
 *              "fits on any device" is exactly this number at every width, and
 *              nothing else. `worst` names the widest element that sticks out,
 *              so a failure says WHAT overflowed rather than that something did.
 *   container  the used width and computed max-width of the page container on
 *              that page — the thing the owner means by "site width". Reported
 *              for the HEADER's container and the MAIN one separately, because
 *              they are different elements with different maxima and the header
 *              has its own known defect.
 *   cols       the rendered product column count, read off the GRID's computed
 *              `grid-template-columns` — the resolved track list. Not inferred
 *              from a breakpoint we guessed at, and not measured by the shop's
 *              own JavaScript: rule 4 forbids that in shipped code, and this is
 *              a harness outside the shop.
 *
 * ▲ THE HARNESS DEFECT THIS FILE WAS BORN WITH, recorded because the same
 * shape will recur. Pointed at public-web-root/index.php as its php -S router,
 * every /build/assets/*.css came back as text/html with nosniff, Chromium
 * refused both stylesheets, and the sweep reported "fits at every width on
 * every page" — because with no CSS there is no layout to break. A sweep that
 * cannot fail is not evidence. The preview now serves real files off disk (see
 * the router in docs/W1-SITE-WIDTH.md) and `KBB_SWEEP_SELFTEST=1` re-asserts
 * that the stylesheets arrived before any row is believed.
 *
 * Config, all via environment:
 *   KBB_BASE          base URL of a running preview (required)
 *   KBB_CHROME        chromium executable
 *   KBB_WIDTHS        comma list of viewport widths
 *   KBB_PATHS         comma list of paths
 *   KBB_SHOTS         directory for screenshots (empty = none)
 *   KBB_SHOT_WIDTHS   widths to shoot
 *   KBB_SHOT_PATHS    paths to shoot
 *   KBB_SHOT_PREFIX   filename prefix, e.g. "before-"
 */
import { chromium } from 'playwright';

const BASE   = process.env.KBB_BASE;
const EXE    = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const WIDTHS = (process.env.KBB_WIDTHS || '320,360,390,414,480,600,768,834,1024,1180,1280,1366,1440,1536,1680,1920,2560').split(',').map(Number);
const PATHS  = (process.env.KBB_PATHS  || '/,/shop/,/my-wishlist/,/korean-skincare-brands/,/cart/,/checkout/,/skincare-guide/,/reviews/,/about/,/my-account/,/skin-quiz/').split(',');
const SHOTS  = process.env.KBB_SHOTS || '';
const SHOT_WIDTHS = (process.env.KBB_SHOT_WIDTHS || '390,1280,1680').split(',').map(Number);
const SHOT_PATHS  = (process.env.KBB_SHOT_PATHS || '/,/shop/').split(',');
const PREFIX = process.env.KBB_SHOT_PREFIX || '';

/** Every grid on the shop whose column count a shopper can see. */
const GRIDS = ['.kbb-pgrid', '#grid', '.rel', '.brw-grid', '.mgrid'];

const probe = (gridSelectors) => {
  const de = document.documentElement;
  const out = { scrollWidth: de.scrollWidth, clientWidth: de.clientWidth, sheets: 0, refused: [] };
  /*
   * A PAGE MEASURED WITHOUT ITS STYLESHEET IS NOT A MEASUREMENT, and a rule
   * count is the wrong way to say so: three storefront documents -- the
   * Journal, the review wall and the quiz -- carry their own <html> and style
   * themselves entirely from an inline <style> of 50 to 127 rules, so a
   * threshold that passes /shop (1,690) calls all three broken. What actually
   * went wrong was narrower than that: every same-origin <link rel=stylesheet>
   * the document declares must have PARSED. `refused` names the ones that did
   * not, which is exactly the ERR_ABORTED case and nothing else.
   */
  const parsed = new Map();
  for (const s of document.styleSheets) { try { out.sheets += s.cssRules.length; if (s.href) parsed.set(s.href, s.cssRules.length); } catch (e) { /* cross-origin */ } }
  for (const l of document.querySelectorAll('link[rel="stylesheet"][href]')) {
    if (new URL(l.href, location.href).origin !== location.origin) continue;
    if (!parsed.get(l.href)) out.refused.push(l.href.slice(-40));
  }

  let worst = null;
  for (const el of document.querySelectorAll('body *')) {
    const cs = getComputedStyle(el);
    if (cs.position === 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') continue;
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) continue;
    const over = Math.round(r.right - de.clientWidth);
    if (over > 1 && (!worst || over > worst.over)) {
      worst = { over, tag: el.tagName.toLowerCase(), cls: String(el.className || '').slice(0, 54), w: Math.round(r.width) };
    }
  }
  out.worst = worst;

  const measure = (el) => el && ({ cls: String(el.className || '').slice(0, 34),
                                   w: Math.round(el.getBoundingClientRect().width),
                                   max: getComputedStyle(el).maxWidth,
                                   pad: getComputedStyle(el).paddingLeft });
  out.head = measure(document.querySelector('header .wrap, header .head-in, header .hd-in'));
  /*
   * THE PAGE CONTAINER, in document order rather than by size.
   *
   * The first draft took the WIDEST container-shaped element outside the
   * header, and on six pages that was an unconstrained full-bleed section
   * wrapper -- so the table read `max: none` and 2560 at 2560 for /cart,
   * /checkout and /my-account, all three of which DO cap their content. The
   * container the owner means is the first one in the document, which is the
   * one every page's content sits inside.
   */
  let main = null;
  for (const el of document.querySelectorAll('.wrap,.brw,.rtn-wrap,.page,.co-grid,.co-notices,.acw,.shell,article')) {
    if (el.closest('header') || el.closest('footer') || el.closest('.mnav') || el.closest('.drawer')) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || el.getBoundingClientRect().width === 0) continue;
    main = el;
    break;
  }
  out.main = measure(main);
  // Every distinct container cap on the page, so a table can show the spread.
  const caps = new Set();
  for (const el of document.querySelectorAll('.wrap,.brw,.rtn-wrap,.page,.co-grid,article')) {
    if (el.closest('.mnav') || el.closest('.drawer')) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none') continue;
    caps.add(cs.maxWidth);
  }
  out.caps = [...caps];

  out.grids = [];
  for (const sel of gridSelectors) {
    for (const g of document.querySelectorAll(sel)) {
      const cs = getComputedStyle(g);
      if (cs.display !== 'grid' && cs.display !== 'inline-grid') continue;
      const tracks = cs.gridTemplateColumns;
      if (!tracks || tracks === 'none') continue;
      out.grids.push({ sel, cols: tracks.trim().split(/\s+/).length, w: Math.round(g.getBoundingClientRect().width) });
    }
  }
  return out;
};

const browser = await chromium.launch({ executablePath: EXE, args: ['--no-sandbox', '--font-render-hinting=none'] });
const rows = [];
for (const w of WIDTHS) {
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  for (const p of PATHS) {
    let r;
    try {
      const resp = await page.goto(BASE + p, { waitUntil: 'load', timeout: 45000 });
      await page.waitForTimeout(150);
      r = await page.evaluate(probe, GRIDS);
      r.status = resp ? resp.status() : 0;
      if (r.refused && r.refused.length) r.error = 'stylesheet refused: ' + r.refused.join(', ');
      if (r.sheets === 0) r.error = 'no CSS parsed at all';
    } catch (e) { r = { error: String(e).slice(0, 140) }; }
    rows.push({ width: w, path: p, ...r });
    if (SHOTS && SHOT_WIDTHS.includes(w) && SHOT_PATHS.includes(p)) {
      const name = PREFIX + (p === '/' ? 'home' : p.replace(/^\/|\/$/g, '').replace(/\//g, '-')) + '-' + w + '.png';
      await page.screenshot({ path: SHOTS + '/' + name, fullPage: false });
    }
  }
  await ctx.close();
}
await browser.close();
console.log(JSON.stringify(rows));
