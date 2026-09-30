/*
 * THE PRODUCT-PAGE REVIEW BLOCK, IN EACH TYPE IT COULD HAVE.        (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8990 node tools/bg-sorina-options.cjs
 *
 * resources/css/kbb/sorina-reviews.css asks for two families this shop has
 * never loaded:
 *
 *     .sr        font-family:"Hanken Grotesk", -apple-system, …, Arial, sans-serif
 *     .sr-title  font-family:Fraunces, Georgia, serif
 *     .sr-avg    font-family:Fraunces, Georgia, serif
 *     .sr-stitle font-family:Fraunces, Georgia, serif
 *
 * Neither is declared anywhere in the repository, so every one of those rules
 * has always fallen through to whatever the DEVICE supplies. Measured with
 * tools/font-probe.cjs on a real product page: `Fraunces` and `Hanken Grotesk`
 * both come back renderedAny=false, ruler identical to the control at every
 * weight, while `Poppins` renders.
 *
 * The families came in with the third-party "Sorina" review template rather
 * than from a decision about this shop — resources/views/store/review-wall
 * .blade.php says so out loud in its own stylesheet: "KBB real tokens (never
 * Sorina/Fraunces)". So the block is not styled the way anybody chose.
 *
 * ── THIS SCRIPT CHANGES NOTHING; IT PHOTOGRAPHS THE CHOICES ───────────────
 *
 * Every option is applied with addStyleTag at capture time. The repository's
 * CSS is untouched and the shop renders exactly what it rendered before, which
 * is the point: the owner is being given a decision, not the result of one.
 *
 *   A  as it ships today          no injection at all
 *   B  the shop's own type        the four rules pointed at var(--sans)
 *   A' the same block in four     what "Georgia, serif" actually resolves to
 *      different serifs           on four different devices
 *
 * Option C — Fraunces and Hanken Grotesk properly self-hosted — is NOT
 * photographed here, and deliberately: building it means downloading two font
 * families into this repository, which is not a lane's decision to take. Its
 * wire cost is measured in docs/BG-SORINA-REVIEW-TYPE.md from Google's own API
 * without fetching a single font file.
 */
const { chromium } = require('playwright');
const { probeFamily } = require('./font-probe.cjs');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8990';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-sorina');
const URL = process.env.BG_PRODUCT || '/product/lanebg-1/';

/*
 * OPTION B, and it is four declarations because that is all the difference is.
 * `--sans` is the shop's own token, declared on :root in kbb.css as
 * "Poppins", system-ui, … — the same stack the product title, the price, the
 * tabs and every other word on this page already use.
 */
const OPTION_B = `
  .sr{font-family:var(--sans)}
  .sr-title,.sr-avg,.sr-stitle{font-family:var(--sans)}
  .sr-verified{padding:1px 4px}
`;

/*
 * THE FIFTH DECLARATION IS NOT DECORATION, AND THE PICTURE IS WHY IT IS HERE.
 *
 * With the four family rules alone, the middle review card broke: Poppins is
 * wider than the Arial the block falls back to today, so "Fatima R." plus the
 * ✓ Verified badge no longer fitted on one line inside a 207px card. The badge
 * wrapped, that card's name row went from 16px to 36px, its height went 207px
 * to 228px, and the three cards' stars stopped lining up. Nothing measured it;
 * the screenshot showed it.
 *
 * Six candidate fixes were measured (storage/bg-logs is not committed, the
 * numbers are in docs/BG-SORINA-REVIEW-TYPE.md). Stacking the row, letting
 * .sr-cmeta wrap and shrinking the name all FAILED to realign it. Two worked,
 * and this is the smaller: the badge keeps its 10px type and gives up 2px of
 * horizontal padding either side. Name rows go back to 16px on all three cards,
 * card heights back to 207px — the same 207px option A has — and the stars
 * line up again.
 *
 * So option B costs FIVE declarations, not four, and a Verified badge 4px
 * narrower. That is the honest price and it is on the picture.
 */

/* The serifs a device might hand `Fraunces, Georgia, serif` when it has none
   of the first two. All four are present in this container, which is what
   makes the swing measurable here rather than argued. */
const SERIFS = ['Georgia', 'DejaVu Serif', 'Nimbus Roman', 'FreeSerif'];


/*
 * CAPTURE THE BLOCK FROM THE FULL PAGE AND CROP, never elementHandle.screenshot().
 *
 * An element screenshot scrolls the element into view and photographs its box
 * OUT OF THE VIEWPORT, so anything overlaying it is baked in. At 390 this shop
 * has a sticky header, and the first run of this tool produced an option-B
 * picture with the average rating hidden behind it -- a difference between the
 * two options that was the camera, not the CSS.
 *
 * A full-page render clipped to the element's DOCUMENT coordinates has no
 * viewport and therefore no overlay.
 */
async function shotBlock(page, selector, file) {
  const box = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return {
      x: Math.max(0, r.left + window.scrollX),
      y: Math.max(0, r.top + window.scrollY),
      width: Math.max(1, r.width),
      height: Math.max(1, r.height),
    };
  }, selector);

  if (!box) return false;

  await page.screenshot({ path: file, fullPage: true, clip: box });
  return true;
}

const MEASURE = () => {
  const px = (n) => Math.round(n * 100) / 100;
  /*
   * TEXT WIDTH IS MEASURED WITH A RANGE, NOT WITH scrollWidth.
   *
   * The first version of this used `e.scrollWidth`, and `.sr-title` is a BLOCK
   * element: it fills its container whatever font it is set in, so every option
   * measured 868px and the swing came out as 0.0% — a number that looked like a
   * finding ("the serif makes no difference") and was the container's width
   * standing in for the text's. Exactly the class of error this round is about,
   * caught because a 0.0% swing between four different typefaces is not a
   * result anybody should believe.
   *
   * A Range around the element's contents measures the TEXT, which is the thing
   * the font actually changes.
   */
  const textWidth = (e) => {
    const r = document.createRange();
    r.selectNodeContents(e);
    const rects = [...r.getClientRects()];
    r.detach && r.detach();
    if (rects.length === 0) return null;
    return px(Math.max(...rects.map((x) => x.width)));
  };

  const pick = (sel) => {
    const e = document.querySelector(sel);
    if (!e) return null;
    const c = getComputedStyle(e);
    const r = e.getBoundingClientRect();
    return {
      family: c.fontFamily.slice(0, 40),
      weight: c.fontWeight,
      size: c.fontSize,
      textWidth: textWidth(e),
      blockWidth: px(r.width),
      height: px(r.height),
    };
  };
  const block = document.querySelector('.sr');
  return {
    blockHeight: block ? px(block.getBoundingClientRect().height) : null,
    scrollWidth: document.documentElement.scrollWidth,
    title: pick('.sr-title'),
    avg: pick('.sr-avg'),
    body: pick('.sr'),
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];

  for (const [label, css] of [['A-today', null], ['B-shop-type', OPTION_B]]) {
    for (const width of [1280, 390]) {
      const ctx = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 900 },
        deviceScaleFactor: 1,
      });
      const page = await ctx.newPage();
      await page.goto(BASE + URL, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      if (css) await page.addStyleTag({ content: css });
      await page.evaluate(() => document.fonts.ready);

      const m = await page.evaluate(MEASURE);
      const poppins = await probeFamily(page, 'Poppins', [500, 600]);
      const fraunces = await probeFamily(page, 'Fraunces', [500, 600]);

      /* The block itself, cropped, which is what the owner is choosing between. */
      await shotBlock(page, '.sr', `${OUT}/${label}-${width}.png`);

      if (width === 1280) {
        rows.push({
          option: label,
          blockHeight: m.blockHeight,
          titleWidth: m.title ? m.title.textWidth : null,
          titleFamily: m.title ? m.title.family : null,
          avgWidth: m.avg ? m.avg.textWidth : null,
          scrollWidth: m.scrollWidth,
          poppinsRendered: poppins.renderedAny,
          frauncesRendered: fraunces.renderedAny,
        });
        console.log(`${label.padEnd(12)} blockH=${String(m.blockHeight).padStart(7)}`
          + `  title "${m.title ? m.title.textWidth : '?'}px" in ${m.title ? m.title.family : '?'}`
          + `  poppins=${poppins.renderedAny} fraunces=${fraunces.renderedAny}`);
      }

      await ctx.close();
    }
  }

  /* A': the same heading in each serif a device might actually supply. */
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + URL, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);

  const swing = {};
  for (const serif of SERIFS) {
    await page.addStyleTag({ content: `.sr-title,.sr-avg,.sr-stitle{font-family:"${serif}" !important}` });
    const m = await page.evaluate(MEASURE);
    swing[serif] = m.title ? m.title.textWidth : null;
    await shotBlock(page, '.sr', `${OUT}/A-serif-${serif.replace(/\s+/g, '-')}-1280.png`);
  }
  await ctx.close();

  console.log('\n"Customer Reviews" at 28px/500, per serif the device supplies:');
  for (const [k, v] of Object.entries(swing)) console.log(`   ${k.padEnd(14)} ${v}px`);
  const vals = Object.values(swing).filter((v) => v !== null);
  console.log(`   swing: ${Math.min(...vals)}px .. ${Math.max(...vals)}px`
    + `  (${(100 * (Math.max(...vals) - Math.min(...vals)) / Math.min(...vals)).toFixed(1)}%)`);

  fs.writeFileSync(`${OUT}/options.json`, JSON.stringify({ rows, swing }, null, 2));
  await browser.close();
})();
