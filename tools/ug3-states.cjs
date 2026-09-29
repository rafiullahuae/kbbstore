/*
 * Lane UG3 — the three states of a tile, photographed rather than hoped for.
 *
 *   sh tools/ug3-preview.sh 8994
 *   (set max_playing to 6 so every tile is attempted -- see the note below)
 *   BASE=http://127.0.0.1:8994 node tools/ug3-states.cjs
 *
 * ── WHY A SECOND SCRIPT ─────────────────────────────────────────────────────
 *
 * tools/ug3-shots.cjs measures a rail that is BEHAVING: it loads over loopback
 * in a few milliseconds, so the loading state exists for about one frame and a
 * screenshot of it is a matter of luck. Luck is not evidence.
 *
 * So this one HOLDS THE VIDEO RESPONSES. Every request for a clip is delayed
 * before it is fulfilled, which is not a fake state — it is the same state, on
 * a slower connection, which is the condition the owner is describing in the
 * first place (*"2-3 seconds taking more time to load on front-end"*). The
 * loader turns because the bytes really have not arrived.
 *
 * WHAT IT PROVES, and each is a separate picture:
 *
 *   loading  every on-screen tile: loader at opacity 1, play disc at 0.
 *   ready    the tiles past the cap, and every tile once the fetch is refused:
 *            disc at 1, loader at 0.
 *   playing  after the responses are released: both at 0.
 *   failed   `ug3-gone` points at a file that is not on disk. It has to end up
 *            in READY -- disc back, loader gone -- or a shopper is left looking
 *            at a spinner that will never finish. That is the whole reason this
 *            tile is in the fixture.
 *
 * max_playing IS SET TO 6 FOR THIS RUN and that is deliberate: the default cap
 * of 4 means the failing tile is never even attempted at 1280, so the one
 * question this script exists to answer would go unasked. The cap itself is
 * measured by tools/ug3-shots.cjs on the shipped default.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8994';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug3-shots';
/* Long enough that the loader is unmistakably turning and the shot is not a
   race, short enough that the run finishes. */
const HOLD = Number(process.env.HOLD || 9000);

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 800 },
];

const TILES = () => Array.prototype.map.call(
  document.querySelectorAll('.ugcr-t[data-ugcr-tile]'),
  function (t, i) {
    const disc = t.querySelector('.ugcr-play span');
    const load = t.querySelector('.ugcr-load');
    const b = t.getBoundingClientRect();
    return {
      n: i + 1,
      slug: t.getAttribute('data-ugcr-slug'),
      why: t.getAttribute('data-ugcr-why') || '',
      fault: t.getAttribute('data-ugcr-fault') || '',
      loading: t.classList.contains('is-loading'),
      playing: t.classList.contains('is-playing'),
      disc: disc ? Number(getComputedStyle(disc).opacity) : null,
      load: load ? Number(getComputedStyle(load).opacity) : null,
      box: Math.round(b.width) + 'x' + Math.round(b.height),
    };
  });

function say(label, rows) {
  console.log('  -- ' + label);
  rows.forEach((r) => console.log(
    '     tile ' + r.n + ' ' + String(r.slug).padEnd(12)
    + ' box=' + String(r.box).padEnd(9)
    + ' why=' + String(r.why).padEnd(12)
    + ' loading=' + (r.loading ? 'Y' : 'n')
    + ' playing=' + (r.playing ? 'Y' : 'n')
    + ' disc=' + r.disc + ' loader=' + r.load
    + ' fault=' + (r.fault || '-')
  ));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  /* ── AND ONE PASS WITH THE OS ASKING FOR NO MOTION ──────────────────────
   *
   * The loader must not spin for a shopper who asked for stillness, and it must
   * not be a FROZEN ARC either — a stationary three-quarter ring reads as
   * broken, which is worse than no indicator at all. Under reduced motion the
   * rim is redrawn WHOLE and still. That is a picture, not an assertion, so it
   * gets one. */
  const PASSES = [
    { locale: '', motion: null },
    { locale: '/ar', motion: null },
    { locale: '', motion: 'reduce' },
  ];

  for (const pass of PASSES) {
    const locale = pass.locale;
    for (const vp of VIEWPORTS) {
      const tag = (pass.motion ? 'reduced-' : '') + (locale ? 'rtl-' : '') + vp.name;
      const page = await browser.newPage({
        viewport: { width: vp.width, height: vp.height },
        reducedMotion: pass.motion === 'reduce' ? 'reduce' : 'no-preference',
      });

      /* HELD, NOT FAKED. The request is made, the server answers, and the
         answer is handed to the page HOLD ms later. Nothing about the page is
         altered. */
      let release = false;
      await page.route('**/*.webm', async (route) => {
        const started = Date.now();
        while (!release && Date.now() - started < HOLD) {
          await new Promise((r) => setTimeout(r, 200));
        }
        try { await route.continue(); } catch (e) { /* page went away */ }
      });

      await page.goto(BASE + locale + '/', { waitUntil: 'domcontentloaded' });
      await page.locator('.ugcr-sec').scrollIntoViewIfNeeded();
      await page.waitForTimeout(1200);

      /* THE PAGE'S OWN WIDTH, because a loading indicator that introduced a
         horizontal scrollbar would be a layout change dressed as a nicety. */
      const page_w = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));

      const loading = await page.evaluate(TILES);
      console.log('  -- ' + tag + ' page scrollWidth=' + page_w.scrollWidth
        + ' clientWidth=' + page_w.clientWidth
        + (page_w.scrollWidth > page_w.clientWidth ? '  <-- HORIZONTAL SCROLL' : ''));
      say(tag + ' LOADING (video responses held)', loading);
      await page.screenshot({ path: OUT + '/state-loading-' + tag + '.png' });

      /* Just the rail, tight, so the loader over a bright poster and over a
         dark one can be looked at rather than squinted at. */
      await page.locator('.ugcr-rail').screenshot({ path: OUT + '/state-loading-rail-' + tag + '.png' });

      /* ── AND THE SAME STATE OVER THE OTHER POSTERS ──────────────────────
       *
       * At 390 only two tiles are on screen, and they are the two whose poster
       * is the same picture. The loader has to be looked at over a BRIGHT
       * poster and over a DARK one — that is the whole contrast question, and
       * a shot that only ever shows one of them has not asked it. So the rail
       * is scrolled to the bright/dark pair and held again.
       *
       * scrollLeft on the rail, which is the SHOPPER'S OWN gesture on a rail
       * whose whole design is that it scrolls sideways. Nothing about the page
       * is altered. */
      await page.evaluate(() => {
        const rail = document.querySelector('.ugcr-rail');
        /* NEGATIVE IN RTL. Chromium reports and accepts a right-to-left
           scroller's scrollLeft as 0 at the start and NEGATIVE going left, so
           a positive value here is a no-op and the Arabic shot silently stayed
           on tile 1. Found by reading the run, not by reasoning about it. */
        if (!rail) return;
        const rtl = getComputedStyle(rail).direction === 'rtl';
        rail.scrollLeft = rail.scrollWidth * 0.34 * (rtl ? -1 : 1);
      });
      await page.waitForTimeout(1500);

      const scrolled = await page.evaluate(TILES);
      say(tag + ' LOADING, rail scrolled to the bright/dark pair', scrolled);
      await page.locator('.ugcr-rail').screenshot({ path: OUT + '/state-loading-posters-' + tag + '.png' });

      /* ── THE REDUCED-MOTION LOADER, FORCED, AND SAID SO ─────────────────
       *
       * A FINDING RATHER THAN A SHOT: under prefers-reduced-motion the rail
       * plays NOTHING. rebalance() stops every tile and marks it
       * `why="reduced-motion"`, so a tile is never mounted and the loader is
       * never reached — which is the rule "no loader on a tile that will never
       * play", satisfied by construction rather than by a media query.
       *
       * The @media rule that draws a STILL rim instead of a frozen arc is
       * therefore belt and braces, and the only way to look at it is to put the
       * class on by hand. That is an instrument forcing a state and it is
       * labelled as one in the file name: nothing on the shop puts this class
       * on a tile under reduced motion. */
      if (pass.motion === 'reduce') {
        await page.evaluate(() => {
          document.querySelectorAll('.ugcr-t[data-ugcr-tile]')
            .forEach((t) => t.classList.add('is-loading'));
        });
        await page.waitForTimeout(400);
        await page.locator('.ugcr-rail').screenshot({ path: OUT + '/state-loading-FORCED-reduced-' + vp.name + '.png' });
        await page.evaluate(() => {
          document.querySelectorAll('.ugcr-t[data-ugcr-tile]')
            .forEach((t) => t.classList.remove('is-loading'));
        });
      }

      release = true;
      await page.waitForTimeout(4000);

      const playing = await page.evaluate(TILES);
      say(tag + ' RELEASED (playing, and the one that cannot)', playing);
      await page.screenshot({ path: OUT + '/state-playing-' + tag + '.png' });
      await page.locator('.ugcr-rail').screenshot({ path: OUT + '/state-playing-rail-' + tag + '.png' });

      report[tag] = { page: page_w, loading: loading, scrolled: scrolled, playing: playing };
      await page.close();
    }
  }

  fs.writeFileSync(OUT + '/states.json', JSON.stringify(report, null, 2));
  await browser.close();
  console.log('\nwrote ' + OUT + '/states.json');
})();
