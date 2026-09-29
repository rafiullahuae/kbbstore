/*
 * Lane UG3 — what a tile costs, what it shows, and whether the one-second loop
 * is actually one second.
 *
 *   sh tools/ug3-preview.sh 8994
 *   BASE=http://127.0.0.1:8994 node tools/ug3-shots.cjs
 *
 * ── WHAT IT REPORTS, AND WHY EACH ONE IS HERE ────────────────────────────────
 *
 *   bytes       every response the page fetched, attributed to the tile that
 *               asked for it. This is the owner's complaint measured: *"2-3
 *               seconds taking more time to load on front-end"*. A tile with a
 *               teaser file and a tile without it point at the same content in
 *               this fixture, so the difference between them is the whole of the
 *               answer.
 *   states      per tile: the loader, the play disc and `is-playing`, read as
 *               COMPUTED OPACITY rather than as a class, because opacity is what
 *               the shopper sees and a class is what we hope they see. The three
 *               states have to be distinguishable from these three numbers
 *               alone.
 *   loop        currentTime sampled every 100ms. At one second the wrap happens
 *               sixty times a minute, so a loop that overshoots by a quarter of
 *               a second is a limp anybody can see. `maxSeen` is the longest lap
 *               observed and is the number that says whether the wrap is on time.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about shipped JavaScript
 * laying the page out; this is the instrument checking that it does not.
 *
 * AND IT POINTS AT `/`, NOT AT A CMS PAGE. A page body's content column is 695px
 * wide, so tiles 4-6 are clipped by the rail's own overflow and the observer
 * refuses them — which reads exactly like the defect still being there. It cost
 * UG2 a confused run with the fix already in the tree.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8994';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug3-shots';
const TAG = process.env.TAG || 'after';
const PATHNAME = process.env.PATHNAME || '/';

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 800 },
];

/* Per tile, and every visual fact as a NUMBER a screenshot can be checked
   against. `disc` and `load` are computed opacity: the classes are the
   mechanism, these two are the outcome. */
const TILES = () => Array.prototype.map.call(
  document.querySelectorAll('.ugcr-t[data-ugcr-tile]'),
  function (t, i) {
    const v = t.querySelector('video');
    const disc = t.querySelector('.ugcr-play span');
    const load = t.querySelector('.ugcr-load');
    const b = t.getBoundingClientRect();
    return {
      n: i + 1,
      slug: t.getAttribute('data-ugcr-slug'),
      teaserSrc: (t.getAttribute('data-ugcr-teaser-src') || '').split('/').pop(),
      src: (t.getAttribute('data-ugcr-src') || '').split('/').pop(),
      vis: t.getAttribute('data-ugcr-vis'),
      why: t.getAttribute('data-ugcr-why') || '',
      fault: t.getAttribute('data-ugcr-fault') || '',
      loading: t.classList.contains('is-loading'),
      playing: t.classList.contains('is-playing'),
      disc: disc ? Number(getComputedStyle(disc).opacity) : null,
      load: load ? Number(getComputedStyle(load).opacity) : null,
      loadVis: load ? getComputedStyle(load).visibility : null,
      /* The loader must not change the tile's box. The box comes from
         aspect-ratio and this is the check that it still does. */
      tileW: Math.round(b.width),
      tileH: Math.round(b.height),
      t: v ? Number(v.currentTime.toFixed(3)) : null,
      err: v && v.error ? v.error.code : null,
    };
  });

/* The longest lap actually observed, from a 100ms walk of currentTime. A wrap
   is a sample that is LOWER than the one before it; the lap is the value it
   wrapped FROM. */
function laps(series) {
  const out = [];
  for (let i = 1; i < series.length; i++) {
    if (series[i] !== null && series[i - 1] !== null && series[i] < series[i - 1]) out.push(series[i - 1]);
  }
  return out;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });

    /* EVERY RESPONSE, BY URL. `encodedBodySize` is what actually crossed the
       wire — a Range response for a video is counted as the bytes it carried,
       which is the point: a tile looping the first second of a full clip does
       not fetch all of it at once, and pretending it does would flatter the
       teaser. */
    const bytes = {};
    page.on('response', async (r) => {
      const name = r.url().split('/').pop().split('?')[0];
      let n = 0;
      try { n = Number((await r.headerValue('content-length')) || 0); } catch (e) { n = 0; }
      bytes[name] = (bytes[name] || 0) + n;
    });

    await page.goto(BASE + PATHNAME, { waitUntil: 'networkidle' });
    await page.locator('.ugcr-sec').scrollIntoViewIfNeeded();
    await page.waitForTimeout(3000);

    /* The loop walk: 100ms apart, five seconds of it. At one second that is
       about five laps per tile, which is enough to see a wrap that drifts. */
    const walk = [];
    for (let i = 0; i < 50; i++) {
      walk.push(await page.evaluate(TILES));
      await page.waitForTimeout(100);
    }

    const rows = walk[walk.length - 1].map((t, i) => {
      const series = walk.map((s) => s[i].t);
      const l = laps(series);
      return Object.assign({}, t, {
        series: series.filter((x, k) => k % 2 === 0),
        laps: l,
        maxLap: l.length ? Math.max.apply(null, l) : null,
        minLap: l.length ? Math.min.apply(null, l) : null,
      });
    });

    report[vp.name] = { tiles: rows, bytes: bytes };

    console.log('\n== ' + TAG + ' @ ' + vp.name + ' ==');
    rows.forEach((r) => console.log(
      '  tile ' + r.n + ' ' + String(r.slug).padEnd(12)
      + ' teaser=' + (r.teaserSrc ? 'Y' : 'n')
      + ' vis=' + r.vis
      + ' why=' + String(r.why).padEnd(12)
      + ' box=' + r.tileW + 'x' + r.tileH
      + ' loading=' + (r.loading ? 'Y' : 'n')
      + ' playing=' + (r.playing ? 'Y' : 'n')
      + ' disc=' + r.disc
      + ' loader=' + r.load + '/' + r.loadVis
      + ' fault=' + (r.fault || '-')
      + ' lap=' + (r.minLap === null ? '-' : r.minLap + '..' + r.maxLap)
    ));
    console.log('  bytes: ' + JSON.stringify(bytes));

    await page.screenshot({ path: OUT + '/' + TAG + '-rail-' + vp.name + '.png', fullPage: false });

    /* RTL, on the real mirrored storefront at /ar/ rather than a dir attribute
       flipped by the instrument. */
    const ar = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
    await ar.goto(BASE + '/ar' + (PATHNAME === '/' ? '' : PATHNAME), { waitUntil: 'networkidle' });
    await ar.locator('.ugcr-sec').scrollIntoViewIfNeeded();
    await ar.waitForTimeout(2500);
    await ar.screenshot({ path: OUT + '/' + TAG + '-rail-rtl-' + vp.name + '.png' });
    await ar.close();

    await page.close();
  }

  fs.writeFileSync(OUT + '/measurements-' + TAG + '.json', JSON.stringify(report, null, 2));
  await browser.close();
  console.log('\nwrote ' + OUT + '/measurements-' + TAG + '.json');
})();
