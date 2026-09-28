/*
 * Lane UG2 — what each tile in the owner's rail actually does.
 *
 *   sh tools/ug2-preview.sh 8989
 *   BASE=http://127.0.0.1:8989 node tools/ug2-shots.cjs
 *
 * ── WHY THIS EXISTS WHEN tools/ug-shots.cjs ALREADY DID ──────────────────────
 *
 * ug-shots measured a rail of six identical, healthy clips and reported it
 * looping. The owner's rail is four DEMO tiles followed by two real ones, and
 * the whole failure lives in that mix — so this script reports PER TILE:
 *
 *   mounted     is there a <video> element inside the tile at all
 *   playing     is `is-playing` on the tile (the ONLY thing that hides the disc)
 *   disc        the computed opacity of .ugcr-play span — what the owner SEES
 *   t0..t3      currentTime sampled a second apart: advance, and the wrap
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about shipped JavaScript
 * laying the page out; this is the instrument checking that it does not.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8989';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug2-shots';
const TAG = process.env.TAG || 'after';
/*
 * THE HOMEPAGE, AND THE DEFAULT IS PART OF THE INSTRUMENT.
 *
 * This read '/about' and that is the WRONG RAIL to measure. A CMS page's
 * content column is 695px wide, so tiles 4-6 are clipped by the rail's own
 * overflow and the IntersectionObserver refuses them before the cap is ever
 * reached — the gate that binds there is visibility, and the gate that binds
 * on the owner's screenshot is the CAP. Run against /about at 1600 this script
 * reports tiles 5 and 6 as vis=0 and not playing, which looks exactly like the
 * defect still being there and is not: it is a different rail.
 *
 * It cost one confused run in this lane, with the fix already in the tree. His
 * rail is the homepage "Shop the look" block (tools/ug2-seed.php sets
 * `home_section`), so that is what this measures unless told otherwise.
 */
const PATHNAME = process.env.PATHNAME || '/';

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 800 },
  /* HIS width. The owner's screenshot is a desktop wide enough to show all six
     tiles at once with room to spare, which is the ONLY condition under which
     the cap — not the visibility threshold — is what refuses tiles 5 and 6.
     At 1280 the rail is already scrolled and the threshold binds first, so a
     lane that measured only 1280 would report the wrong gate. */
  { name: '1600', width: 1600, height: 1000 },
];

const TILES = () => Array.prototype.map.call(
  document.querySelectorAll('.ugcr-t[data-ugcr-tile]'),
  function (t, i) {
    const v = t.querySelector('video');
    const disc = t.querySelector('.ugcr-play span');
    const b = t.getBoundingClientRect();
    return {
      n: i + 1,
      slug: t.getAttribute('data-ugcr-slug'),
      src: (t.getAttribute('data-ugcr-src') || '').split('/').pop(),
      teaserSrc: (t.getAttribute('data-ugcr-teaser-src') || '').split('/').pop(),
      demo: t.getAttribute('data-ugcr-demo') || '0',
      vis: t.getAttribute('data-ugcr-vis'),
      ratio: t.getAttribute('data-ugcr-vis-ratio'),
      fault: t.getAttribute('data-ugcr-fault') || '',
      mounted: !!v,
      playing: t.classList.contains('is-playing'),
      disc: disc ? getComputedStyle(disc).opacity : 'n/a',
      t: v ? Number(v.currentTime.toFixed(2)) : null,
      paused: v ? v.paused : null,
      ready: v ? v.readyState : null,
      err: v && v.error ? v.error.code : null,
      /* How much of the tile the VIEWPORT can see, as the fraction an
         IntersectionObserver would report. The gate the rail ships is 0.6. */
      onScreen: Number(Math.max(0, Math.min(
        (Math.min(b.right, innerWidth) - Math.max(b.left, 0)) / b.width, 1)).toFixed(2)),
    };
  });

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const vp of VIEWPORTS) {
    const page = await browser.newPage({ viewport: { width: vp.width, height: vp.height } });
    await page.goto(BASE + PATHNAME, { waitUntil: 'networkidle' });
    await page.locator('.ugcr-sec').scrollIntoViewIfNeeded();
    await page.waitForTimeout(2500);

    const series = [];
    for (let i = 0; i < 5; i++) {
      series.push(await page.evaluate(TILES));
      await page.waitForTimeout(1000);
    }

    /* One row per tile: the flags, then the currentTime walk. */
    const rows = series[series.length - 1].map((t, i) => Object.assign({}, t, {
      series: series.map((s) => s[i].t),
    }));
    report[vp.name] = rows;

    console.log('\n== ' + TAG + ' @ ' + vp.name + ' ==');
    rows.forEach((r) => console.log(
      '  tile ' + r.n + ' ' + String(r.slug).padEnd(30)
      + ' demo=' + r.demo
      + ' onScreen=' + r.onScreen
      + ' vis=' + r.vis + (r.ratio ? '(' + r.ratio + ')' : '')
      + ' mounted=' + (r.mounted ? 'Y' : 'n')
      + ' is-playing=' + (r.playing ? 'Y' : 'n')
      + ' disc=' + r.disc
      + ' err=' + r.err
      + ' fault=' + (r.fault || '-')
      + ' t=' + JSON.stringify(r.series)
    ));

    await page.locator('.ugcr-sec').screenshot({ path: `${OUT}/rail-${TAG}-${vp.name}.png` });
    await page.close();
  }

  fs.writeFileSync(`${OUT}/measurements-${TAG}.json`, JSON.stringify(report, null, 2));
  await browser.close();
})();
