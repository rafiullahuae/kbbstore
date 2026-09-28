/*
 * Lane UG — the evidence for the shoppable-video rail and its popup.
 *
 *   nohup sh tools/ug-preview.sh 8967 &                      # ordinary server
 *   UG_SUFFIX=-nospawn UG_NO_PROC_OPEN=1 \
 *     nohup sh tools/ug-preview.sh 8968 &                    # the owner's server
 *   node tools/ug-shots.cjs
 *
 * ── TWO INSTRUMENT FACTS THIS SCRIPT IS BUILT AROUND ───────────────────────
 *
 * 1. Playwright's bundled Chromium is the open-source build and has NO H.264.
 *    tools/ug-seed.php therefore seeds VP9 in WebM. An mp4 that plays perfectly
 *    for the owner reports error 4 / MEDIA_ERR_SRC_NOT_SUPPORTED here, and a
 *    lane that seeds one "finds" a bug that does not exist.
 * 2. `php -S` does not implement HTTP Range — it answers 200 with the whole
 *    body — and Chromium asks for media by range and gives up when the answer
 *    is not a 206, which looks identical to a broken file. tools/ug-preview.sh
 *    runs tools/m1-router.php, which serves real 206 and 416.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about shipped JavaScript
 * laying the page out. This is the instrument that checks that it does not.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8967';
const NOSPAWN = process.env.NOSPAWN || 'http://127.0.0.1:8968';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug-shots';

const VIEWPORTS = [
  { name: '390', width: 390, height: 844 },
  { name: '1280', width: 1280, height: 800 },
];

const measured = {};

function note(key, value) {
  measured[key] = value;
  console.log(key + ' ' + JSON.stringify(value));
}

/* Where an element really is, and — for the player — where the PICTURE inside it
   really is. The second is the number the owner's complaint was about: a video
   element and the picture painted in it are the same rectangle only when the
   element's ratio equals the clip's, which is exactly what this round changed. */
const GEOMETRY = () => {
  const r = (el) => {
    if (!el) return null;
    const b = el.getBoundingClientRect();
    return { top: Math.round(b.top), left: Math.round(b.left),
      width: Math.round(b.width), height: Math.round(b.height) };
  };
  const box = document.querySelector('.ugcp-box');
  const v = document.querySelector('.ugcp-v');
  const rail = document.querySelector('.ugcp-rail');
  const credit = document.querySelector('.ugcp-credit');
  const vr = r(v);

  let picture = null;
  if (v && v.videoWidth && vr) {
    const clip = v.videoWidth / v.videoHeight;
    const frame = vr.width / vr.height;
    const fit = getComputedStyle(v).objectFit;
    // What `contain` would paint. With `cover` and a frame of the clip's own
    // ratio the two are the same rectangle, which is the point.
    const w = clip > frame ? vr.width : vr.height * clip;
    const h = clip > frame ? vr.width / clip : vr.height;
    picture = {
      objectFit: fit,
      width: Math.round(w), height: Math.round(h),
      top: Math.round(vr.top + (vr.height - h) / 2),
      left: Math.round(vr.left + (vr.width - w) / 2),
      blackBandTopPx: Math.round((vr.height - h) / 2),
      blackBandSidePx: Math.round((vr.width - w) / 2),
    };
  }

  return {
    dir: document.documentElement.getAttribute('dir') || 'ltr',
    scrollWidth: document.documentElement.scrollWidth,
    frame: r(box),
    videoElement: vr,
    picture,
    productRail: r(rail),
    credit: r(credit),
    productBoxes: document.querySelectorAll('.ugcp-card').length,
    clipPixels: v ? [v.videoWidth, v.videoHeight] : null,
    frameRatioVar: box ? getComputedStyle(box).getPropertyValue('--ugcp-ar').trim() : null,
    playing: v ? !v.paused : null,
    mediaError: v && v.error ? v.error.code : null,
    /* THE THREE CLAIMS, as booleans, so a future reader does not have to do the
       arithmetic again. */
    pictureFillsFrame: picture ? (picture.blackBandTopPx === 0 && picture.blackBandSidePx === 0) : null,
    productsOverVideo: (picture && rail)
      ? (r(rail).top >= picture.top && r(rail).left >= picture.left
         && r(rail).left + r(rail).width <= picture.left + picture.width)
      : null,
    creditOverVideo: (picture && credit)
      ? (r(credit).top >= picture.top && r(credit).left >= picture.left)
      : null,
  };
};

async function shoot(page, name) {
  fs.mkdirSync(OUT, { recursive: true });
  await page.screenshot({ path: `${OUT}/${name}.png` });
}

async function openPlayer(page, slug) {
  await page.locator(`.ugcr-t[data-ugcr-slug="${slug}"]`).scrollIntoViewIfNeeded();
  await page.locator(`.ugcr-t[data-ugcr-slug="${slug}"]`).click({ force: true });
  await page.waitForTimeout(2200);
}

async function closePlayer(page) {
  await page.keyboard.press('Escape');
  await page.waitForTimeout(700);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const problems = [];

  /* ───────────────────────── the storefront ───────────────────────────── */
  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => problems.push(vp.name + ' pageerror: ' + e.message));

    await page.goto(BASE + '/about', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);

    /* ── THE LOOP, SAMPLED ────────────────────────────────────────────────
       currentTime every 150ms for six seconds. A still cannot tell a playing
       clip from a paused one, and a single pair of readings cannot tell a clip
       that plays once from one that loops — this shows it advance, wrap, and
       advance again, per tile. */
    const series = [];
    for (let i = 0; i < 40; i++) {
      series.push(await page.evaluate(() => {
        const out = {};
        document.querySelectorAll('.ugcr-t[data-ugcr-tile]').forEach((t) => {
          const v = t.querySelector('video');
          out[t.getAttribute('data-ugcr-slug')] = v
            ? { t: +v.currentTime.toFixed(2), paused: v.paused, loop: v.loop,
                err: v.error ? v.error.code : null }
            : null;
        });
        return out;
      }));
      await page.waitForTimeout(150);
    }

    const slugs = Object.keys(series[0]);
    const summary = {};
    for (const s of slugs) {
      const t = series.map((f) => (f[s] ? f[s].t : null)).filter((x) => x !== null);
      if (!t.length) { summary[s] = { mounted: false }; continue; }
      let wraps = 0, advances = 0;
      for (let i = 1; i < t.length; i++) {
        if (t[i] < t[i - 1]) wraps++;
        else if (t[i] > t[i - 1]) advances++;
      }
      const frame = series.find((f) => f[s]);
      summary[s] = {
        mounted: true,
        hasOwnTeaserFile: frame[s].loop === true,
        samples: t.length, min: Math.min(...t), max: Math.max(...t),
        advances, wraps, paused: frame[s].paused, mediaError: frame[s].err,
        series: t,
      };
    }

    note('rail-' + vp.name, {
      viewport: vp.width + 'x' + vp.height,
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      tilesOnPage: slugs.length,
      tilesPlaying: Object.values(summary).filter((s) => s.mounted && s.paused === false).length,
      perClip: summary,
    });
    await shoot(page, 'rail-' + vp.name);

    /* ── THE POPUP ───────────────────────────────────────────────────────── */
    for (const [slug, label] of [['ug-c3', 'player-several-products'],
      ['ug-dark', 'player-one-product-dark-clip'],
      ['ug-bright', 'player-bright-clip']]) {
      await openPlayer(page, slug);
      note(label + '-' + vp.name, await page.evaluate(GEOMETRY));
      await shoot(page, label + '-' + vp.name);
      await closePlayer(page);
    }

    /* ── THE KEYBOARD ────────────────────────────────────────────────────── */
    await openPlayer(page, 'ug-c3');
    const keyboard = { focusOnOpen: await page.evaluate(() => document.activeElement.className) };
    const walk = [];
    for (let i = 0; i < 14; i++) {
      await page.keyboard.press('Tab');
      walk.push(await page.evaluate(() => {
        const a = document.activeElement;
        return { cls: a.className || a.tagName, insideDialog: !!(a.closest && a.closest('.ugcp')) };
      }));
    }
    keyboard.tabStops = walk.map((w) => w.cls);
    keyboard.everLeftTheDialog = walk.some((w) => !w.insideDialog);
    await shoot(page, 'player-keyboard-' + vp.name);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(600);
    keyboard.closedByEscape = await page.evaluate(() => !document.querySelector('.ugcp.is-on'));
    keyboard.focusAfterClose = await page.evaluate(() => {
      const a = document.activeElement;
      return { cls: a.className, slug: a.getAttribute ? a.getAttribute('data-ugcr-slug') : null };
    });
    note('keyboard-' + vp.name, keyboard);

    /* ── RTL, on the REAL mirrored storefront rather than a flipped attribute ── */
    await page.goto(BASE + '/ar/about', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await openPlayer(page, 'ug-c3');
    note('player-rtl-' + vp.name, await page.evaluate(GEOMETRY));
    await shoot(page, 'player-rtl-' + vp.name);

    await ctx.close();
  }

  /* ───────────────────────────── the admin ─────────────────────────────── */
  async function admin(base, tag, vp, after) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => problems.push(tag + ' pageerror: ' + e.message));

    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await page.press('input[name=password]', 'Enter');
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => window.go && window.go('ugcvideo'));
    await page.waitForTimeout(3000);

    if (after) await after(page);

    const read = await page.evaluate(() => ({
      chips: Array.from(document.querySelectorAll('.ugs-chip')).map((e) => e.textContent.trim()),
      remedyShown: !!document.querySelector('[data-ugs-cutremedy]'),
      remedyNamesProcOpen: (document.querySelector('[data-ugs-cutremedy]') || { textContent: '' })
        .textContent.indexOf('proc_open') > -1,
      remedyNamesCron: (document.querySelector('[data-ugs-cutremedy]') || { textContent: '' })
        .textContent.indexOf('php artisan schedule:run') > -1,
      teaserPills: Array.from(document.querySelectorAll('.ugs-tile .ugs-pills'))
        .map((e) => e.textContent.trim()),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    note(tag + '-' + vp.name, read);
    await shoot(page, tag + '-' + vp.name);
    await ctx.close();
  }

  /* A REAL upload through the real screen, so the row's teaser state is the
     state a real upload leaves behind and not one a seed wrote. */
  async function uploadOne(page) {
    await page.click('[data-ugs-new]');
    await page.waitForTimeout(1200);
    await page.fill('[data-ugs-field="title"]', 'Anua mist spray 2');
    await page.waitForTimeout(400);

    /* [data-ugs-next], NOT `.ugs-foot button` — the first button in that row is
       `data-ugs-back` ("All clips"), and clicking it navigates away and leaves a
       screenshot that looks like the upload silently did nothing. */
    await page.locator('[data-ugs-next]').first().click({ force: true });

    /* WAITED FOR, NOT SLEPT THROUGH. Saving step 1 fires four requests and the
       panel is rebuilt when the last lands; setInputFiles on the input that was
       there a moment earlier attaches a file to an element that is about to be
       replaced, and the change event goes nowhere. That is what left "No video
       yet" on the row in an earlier run of this script — an upload that never
       happened, photographed as if it had. */
    await page.waitForSelector('[data-ugs-upload="clip"]', { state: 'attached', timeout: 20000 });

    /* `state: 'attached'` above, because the input is VISUALLY HIDDEN behind the
       drop zone that labels it — the default `visible` waits forever on a
       control the screen is drawing perfectly well. */

    /* The clip's own input. The teaser and the poster have their own.
     *
     * RETRIED, AND FAILING LOUDLY IF IT NEVER LANDS. The panel is rebuilt
     * whenever a read of the clip comes back, so setInputFiles can attach a file
     * to an element that is replaced a moment later and the change event goes
     * nowhere — silently. That is what left "No video yet" on the row in two
     * earlier runs of this script: an upload that never happened, photographed
     * as though it had, which is worse than no evidence at all. The confirmation
     * this waits for is the screen's own, printed when the upload request has
     * answered — and the upload request cuts the cover and the teaser before it
     * answers, so it also means the derivatives are done. */
    let landed = false;

    for (let attempt = 0; attempt < 4 && !landed; attempt++) {
      await page.waitForFunction(
        () => { const el = document.querySelector('[data-ugs-upload="clip"]'); return el && !el.disabled; },
        null, { timeout: 20000 },
      );
      await page.locator('[data-ugs-upload="clip"]')
        .setInputFiles('storage/framework/testing/ug-media/clip5.webm');

      landed = await page.waitForFunction(
        () => Array.from(document.querySelectorAll('.ugs-note'))
          .some((n) => n.textContent.indexOf('Your video is uploaded') > -1),
        null, { timeout: 25000 },
      ).then(() => true, () => false);
    }

    if (!landed) throw new Error('the upload never confirmed — do not photograph this');

    note('the-upload-the-row-is-of', await page.evaluate(() => ({
      confirmation: Array.from(document.querySelectorAll('.ugs-note'))
        .map((n) => n.textContent.trim())
        .filter((t) => t.indexOf('Your video is uploaded') > -1 || t.indexOf('own teaser file') > -1),
    })));

    await page.waitForTimeout(1500);
    /* Back to the list, which is where the row and its teaser pill live. */
    await page.evaluate(() => window.go && window.go('ugcvideo'));
    await page.waitForTimeout(3500);
  }

  for (const vp of VIEWPORTS) {
    await admin(BASE, 'admin-clips-ffmpeg-present', vp);
    await admin(NOSPAWN, 'admin-clips-no-proc-open', vp);
  }
  await admin(BASE, 'admin-row-after-a-real-upload', VIEWPORTS[1], uploadOne);
  await admin(BASE, 'admin-row-after-a-real-upload', VIEWPORTS[0]);

  measured.problems = problems;
  fs.writeFileSync(OUT + '/measurements.json', JSON.stringify(measured, null, 2));
  console.log('problems', JSON.stringify(problems));
  await browser.close();
})();
