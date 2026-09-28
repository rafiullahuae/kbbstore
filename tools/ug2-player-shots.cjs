/*
 * Lane UG2 — the opened player: the prev/next arrows and the product card.
 *
 *   BASE=http://127.0.0.1:8989 TAG=before node tools/ug2-player-shots.cjs
 *
 * Reports, at each width and in each direction:
 *   - the product thumbnail's real box (the owner drew an arrow at it and
 *     called it a rectangle, so its width and height are the claim),
 *   - HOW MANY LINES the product name takes, which is the whole point of
 *     squaring the thumbnail and therefore the number that proves the change,
 *   - the frame's edges against the picture's (band 0 on every edge),
 *   - where the arrows are, and whether the first/last clip disables one.
 *
 * Measuring here is the instrument, not the shipped page — rule 4 is about
 * JavaScript laying the page out, and this script is what checks that none does.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8989';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug2-shots';
const TAG = process.env.TAG || 'after';

const PROBE = () => {
  const r = (el) => {
    if (!el) return null;
    const b = el.getBoundingClientRect();
    return { x: Math.round(b.left), y: Math.round(b.top), w: Math.round(b.width), h: Math.round(b.height) };
  };
  const box = document.querySelector('.ugcp-box');
  const th = document.querySelector('.ugcp-th');
  const nm = document.querySelector('.ugcp-card .ugcr-nm');
  const rail = document.querySelector('.ugcp-rail');
  const prev = document.querySelector('[data-ugcp-nav="-1"]');
  const next = document.querySelector('[data-ugcp-nav="1"]');

  /* Line count without laying anything out: the rendered height over the
     computed line-height. Both are numbers the browser already has. */
  let lines = null;
  if (nm) {
    const cs = getComputedStyle(nm);
    const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2;
    lines = Math.round(nm.getBoundingClientRect().height / lh);
  }

  return {
    dir: document.documentElement.getAttribute('dir') || 'ltr',
    box: r(box),
    thumb: r(th),
    thumbSquare: th ? Math.abs(r(th).w - r(th).h) <= 1 : null,
    name: nm ? nm.textContent.trim() : null,
    nameBox: r(nm),
    nameLines: lines,
    railBox: r(rail),
    prev: prev ? Object.assign(r(prev), { disabled: prev.disabled, label: prev.getAttribute('aria-label') }) : null,
    next: next ? Object.assign(r(next), { disabled: next.disabled, label: next.getAttribute('aria-label') }) : null,
    openSlug: document.querySelector('.ugcp').getAttribute('data-ugcp-slug'),
    scrollWidth: document.documentElement.scrollWidth,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const locale of ['', '/ar']) {
    for (const width of [390, 1280]) {
      const key = (locale ? 'ar' : 'en') + '-' + width;
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.goto(BASE + (locale || '/'), { waitUntil: 'networkidle' });
      await page.locator('.ugcr-sec').scrollIntoViewIfNeeded();
      await page.waitForTimeout(600);

      /* Open the FIRST tile, which is the "no previous clip" end. */
      await page.evaluate(() => document.querySelectorAll('.ugcr-t[data-ugcr-tile]')[0].click());
      await page.waitForTimeout(1200);
      report[key + '-first'] = await page.evaluate(PROBE);
      await page.screenshot({ path: `${OUT}/player-${TAG}-${key}-first.png` });

      /* Walk to the last clip with the control itself, which also proves the
         control works from the keyboard path it shares. */
      for (let i = 0; i < 5; i++) {
        const moved = await page.evaluate(() => {
          const b = document.querySelector('[data-ugcp-nav="1"]');
          if (!b || b.disabled) return false;
          b.click();
          return true;
        });
        if (!moved) break;
        await page.waitForTimeout(500);
      }
      await page.waitForTimeout(700);
      report[key + '-last'] = await page.evaluate(PROBE);
      await page.screenshot({ path: `${OUT}/player-${TAG}-${key}-last.png` });

      /*
       * THE MUTATION, MEASURED ON THE SAME CARD. Putting `flex:1` back on
       * .ugcp-th is exactly the defect the `:not(.ugcp-th)` removed, so this is
       * the before-and-after of one product name rather than of two different
       * ones — which is the only comparison that proves anything about a line
       * count.
       */
      report[key + '-last-mutated'] = await page.evaluate(() => {
        const st = document.createElement('style');
        st.id = 'ug2-mutate';
        st.textContent = '.ugcp-card > .ugcp-th{flex:1 1 0%}';
        document.head.appendChild(st);
        return null;
      }) || await page.evaluate(PROBE);
      await page.evaluate(() => document.getElementById('ug2-mutate').remove());

      /*
       * ── THE ONE CLAIM IN THIS LANE THAT WAS PINNED IN SOURCE AND NEVER
       *    MEASURED ────────────────────────────────────────────────────────
       *
       * "On close, focus must return to the tile the shopper ENDED on, not the
       * one they opened" is the thing prev/next quietly breaks, and the test
       * for it reads the JavaScript — it can only say that `returnFocusTo =
       * tile;` sits outside the wasOpen guard. That is a statement about the
       * source, not about a browser.
       *
       * So: press Escape and ask the document who has focus. The shopper opened
       * tile 1 and walked to the last one, so a correct answer is the LAST
       * tile's slug and the bug's answer is the first's.
       */
      const ended = report[key + '-last'].openSlug;

      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      report[key + '-closed'] = await page.evaluate(() => {
        const el = document.activeElement;
        const tile = el && el.closest ? el.closest('.ugcr-t[data-ugcr-tile]') : null;
        return {
          focusedSlug: tile ? tile.getAttribute('data-ugcr-slug') : null,
          focusedTag: el ? el.tagName.toLowerCase() : null,
          playerOpen: !!document.querySelector('.ugcp.is-on'),
        };
      });
      report[key + '-closed'].endedOn = ended;
      report[key + '-closed'].returnedToEnded =
        report[key + '-closed'].focusedSlug === ended;

      console.log('\n== ' + TAG + ' ' + key + ' ==');
      const brief = (r) => 'thumb ' + r.thumb.w + 'x' + r.thumb.h
        + ' square=' + r.thumbSquare
        + ' | name "' + r.name + '" ' + r.nameBox.w + 'px / ' + r.nameLines + ' line(s)'
        + ' | prev ' + r.prev.x + (r.prev.disabled ? ' (disabled)' : '')
        + ' next ' + r.next.x + (r.next.disabled ? ' (disabled)' : '')
        + ' | scrollWidth ' + r.scrollWidth;
      console.log('  first        ' + brief(report[key + '-first']));
      console.log('  last         ' + brief(report[key + '-last']));
      console.log('  last MUTATED ' + brief(report[key + '-last-mutated']));
      const c = report[key + '-closed'];
      console.log('  on close     focus -> ' + c.focusedSlug
        + ' (ended on ' + c.endedOn + ') returnedToEnded=' + c.returnedToEnded
        + ' playerOpen=' + c.playerOpen);

      await page.close();
    }
  }

  fs.writeFileSync(`${OUT}/player-${TAG}.json`, JSON.stringify(report, null, 2));
  await browser.close();
})();
