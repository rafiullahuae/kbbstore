/*
 * Lane V4 — Content → Shoppable video → All clips, measured in a real browser.
 *
 * IT REPORTS, IT DOES NOT JUDGE, following tests/browser/lane-m-module-screens.mjs.
 * The claims this lane makes are claims about rendered layout — how many clips fit
 * above the fold, whether the add-a-clip flow overflows at 390px, whether the
 * preview really loops inside the first three seconds — and the server's output
 * cannot settle any of them.
 *
 * It is written to run UNCHANGED against either revision, so the BEFORE and the
 * AFTER tables are comparable: every selector it reaches for is tried under both
 * the old names and the new ones, and a miss is reported rather than thrown.
 *
 * MEASURING HERE IS NOT THE THING RULE 4 FORBIDS. Rule 4 is about shipped
 * JavaScript sizing the page; this file is the instrument that proves the shipped
 * CSS did it right. UgcRailR3Test's own comment draws the same line.
 *
 *   KBB_V4_BASE=http://127.0.0.1:8934 \
 *   KBB_V4_EMAIL=owner@example.com KBB_V4_PASSWORD=secret-secret \
 *   KBB_V4_OUT=/tmp/shots KBB_V4_TAG=after \
 *   node tests/browser/lane-v4-add-clip.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_V4_BASE || 'http://127.0.0.1:8934';
const EMAIL = process.env.KBB_V4_EMAIL || 'owner@example.com';
const PASSWORD = process.env.KBB_V4_PASSWORD || 'secret-secret';
const OUT = process.env.KBB_V4_OUT || '/tmp';
const TAG = process.env.KBB_V4_TAG || 'run';
const CHROME = process.env.KBB_V4_CHROME;

const report = { tag: TAG, base: BASE, widths: [], console: [], error: null };

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

/* Click the first button whose text matches, under either revision's wording. */
async function clickAny(page, rx) {
  const buttons = page.locator('button, a[role=button], label');
  const n = await buttons.count();
  for (let i = 0; i < n; i += 1) {
    const b = buttons.nth(i);
    if (!(await b.isVisible())) continue;
    const text = ((await b.innerText()) || '').replace(/\s+/g, ' ').trim();
    if (rx.test(text)) {
      await b.click();
      return text;
    }
  }
  return null;
}

/*
 * ?go=ugcvideo DOES NOT WORK AND IS NOT THIS LANE'S FILE TO FIX.
 *
 * app.blade.php's deep-link boot routes only ids that appear in its own TITLES
 * map, and none of the three shoppable-video ids are in it -- so ?go=ugcvideo
 * and #ugcvideo both land on the Dashboard. Every other admin harness in this
 * directory uses that address, which is why this one does not: it calls the
 * screen's own entry point instead, which is exactly what the sidebar row and
 * the tab strip call.
 */
async function openLibrary(page) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('ugcvideo'));
  await page.waitForTimeout(2200);
}

/* The page geometry every block reports, plus what the screen drew. */
async function measure(page) {
  return page.evaluate(() => {
    /* THE SCROLLER IS #content, NOT THE DOCUMENT. app.blade.php puts
       overflow:hidden on body and overflow-y:auto on .content, so
       documentElement.scrollHeight is always the viewport height and reads as
       "nothing overflows" whatever the screen does. */
    const scroller = document.querySelector('#content');
    const px = (el, prop) => (el ? window.getComputedStyle(el)[prop] : null);
    const first = (sel) => document.querySelector(sel);

    /* A clip tile under either revision: the old .ugs-row, the new .ugs-tile. */
    const tiles = document.querySelectorAll('.ugs-row, .ugs-tile');

    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      contentHeight: scroller ? scroller.scrollHeight : null,
      viewportHeight: scroller ? scroller.clientHeight : null,
      /* Clips whose tile is ENTIRELY inside the first screenful — the density
         number the owner actually feels. */
      tilesAboveFold: scroller
        ? Array.prototype.filter.call(
            document.querySelectorAll('.ugs-row, .ugs-tile'),
            (t) => t.getBoundingClientRect().bottom <= scroller.getBoundingClientRect().bottom,
          ).length
        : null,
      tiles: tiles.length,
      /* Rendered tile box, read off the first one. The numbers this lane quotes
         for density come from here. */
      tileWidth: tiles.length ? Math.round(tiles[0].getBoundingClientRect().width) : null,
      tileHeight: tiles.length ? Math.round(tiles[0].getBoundingClientRect().height) : null,
      steps: document.querySelectorAll('[data-ugs-step]').length,
      dropZones: document.querySelectorAll('.ugs-drop').length,
      previews: document.querySelectorAll('.ugs-pv, video').length,
      fileInputs: document.querySelectorAll('input[type=file]').length,
      visibleFileInputs: Array.prototype.filter.call(
        document.querySelectorAll('input[type=file]'),
        (i) => {
          const s = window.getComputedStyle(i);
          return s.display !== 'none' && s.visibility !== 'hidden' && Number(s.opacity) > 0.01;
        },
      ).length,
      brokenImages: Array.prototype.filter.call(
        document.images,
        (i) => i.complete && i.naturalWidth === 0,
      ).length,
      titleSize: px(first('.ugs-title, .ugs-h'), 'fontSize'),
      helpSize: px(first('.ugs-help'), 'fontSize'),
      cardPad: px(first('.ugs-card'), 'padding'),
    };
  });
}

/* A full-flow picture: the console scrolls INSIDE #content, so a fullPage
   screenshot of it shows one viewport and nothing else. Released afterwards. */
async function expand(page, on) {
  await page.evaluate((flag) => {
    const c = document.querySelector('#content');
    if (!c) return;
    if (flag) {
      document.body.style.overflow = 'visible';
      document.documentElement.style.height = 'auto';
      document.body.style.height = 'auto';
      c.style.overflow = 'visible';
      c.style.height = 'auto';
      const app = document.querySelector('.app');
      if (app) { app.style.height = 'auto'; app.style.minHeight = '100%'; }
      /* The editor's footer is position:sticky, which pins it to the middle of
         a full-page capture once the scroller is released. Released too. */
      document.querySelectorAll('.ugs-foot').forEach((f) => { f.style.position = 'static'; });
    } else {
      document.body.style.overflow = '';
      document.documentElement.style.height = '';
      document.body.style.height = '';
      c.style.overflow = '';
      c.style.height = '';
      const app = document.querySelector('.app');
      if (app) { app.style.height = ''; app.style.minHeight = ''; }
      document.querySelectorAll('.ugs-foot').forEach((f) => { f.style.position = ''; });
    }
  }, on);
  await page.waitForTimeout(250);
}

/* What the 2-3 second loop actually does, sampled twice from the real element. */
async function sampleLoop(page) {
  const found = await page.evaluate(() => {
    /* THE LOOP TILE'S video, not the full-size preview's. Both are <video> and
       only one of them is the thing being measured. */
    const v = document.querySelector('.ugs-loop video');
    if (!v) return null;
    return { src: v.currentSrc || v.src, nativeLoop: v.loop, muted: v.muted, paused: v.paused };
  });
  if (!found) return null;

  /* Sampled four times across six seconds. A loop that is really rewinding
     inside the first 2.5s never shows a reading past it; a tile playing the
     whole clip would march past 3, 4, 5. */
  const samples = [];
  for (let i = 0; i < 4; i += 1) {
    await page.waitForTimeout(1500);
    samples.push(
      await page.evaluate(() => {
        const v = document.querySelector('.ugs-loop video');
        return v ? Math.round(v.currentTime * 100) / 100 : null;
      }),
    );
  }

  return { ...found, samplesSeconds: samples, highest: Math.max(...samples.map((n) => n || 0)) };
}

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
    });
    const page = await ctx.newPage();
    page.on('console', (m) => {
      if (m.type() === 'error') report.console.push(`${width}: ${m.text()}`);
    });
    /* Deleting a clip goes through window.confirm, which Playwright dismisses
       by default — so the probe clip would survive and the next width would
       measure a library this script had polluted. */
    page.on('dialog', (d) => d.accept());

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    if (await page.locator('input[type=email]').count()) {
      await page.fill('input[type=email]', EMAIL);
      await page.fill('input[type=password]', PASSWORD);
      await page.click('button[type=submit]');
      await page.waitForLoadState('networkidle');
    }

    const block = { width, list: null, add: null, edit: null, files: null, loop: null,
                    refusal: null, opened: null };

    /* ── the library list ─────────────────────────────────────────────── */
    await openLibrary(page);
    block.list = await measure(page);
    await page.screenshot({ path: `${OUT}/${TAG}-list-fold-${width}.png` });
    await expand(page, true);
    await page.screenshot({ path: `${OUT}/${TAG}-list-${width}.png`, fullPage: true });
    await expand(page, false);

    /* ── the add-a-clip flow, step one ───────────────────────────────── */
    block.opened = await clickAny(page, /^(New video|New clip|Add a clip|Add clip)$/i);
    await page.waitForTimeout(1200);
    block.add = await measure(page);
    await page.screenshot({ path: `${OUT}/${TAG}-add-fold-${width}.png` });
    await expand(page, true);
    await page.screenshot({ path: `${OUT}/${TAG}-add-${width}.png`, fullPage: true });
    await expand(page, false);

    /* ── an existing clip, which has media and therefore a preview ───── */
    await openLibrary(page);
    /* Under the old revision a clip is opened by its Edit button; under the new
       one the tile itself is the button. Try both, in that order. */
    if (!(await clickAny(page, /^(Edit|Open)$/i))) {
      const tile = page.locator('.ugs-tilemain').first();
      if (await tile.count()) await tile.click();
    }
    await page.waitForTimeout(1800);
    block.edit = await measure(page);
    await expand(page, true);
    await page.screenshot({ path: `${OUT}/${TAG}-edit-${width}.png`, fullPage: true });
    await expand(page, false);

    /* The preview lives on whichever step holds the files. Under the new
       revision that is step 2; under the old one there are no steps at all and
       this is a no-op, which is what makes the two runs comparable. */
    const step2 = page.locator('[data-ugs-step="2"]').first();
    if (await step2.count()) await step2.click();
    await page.waitForTimeout(2000);
    await expand(page, true);
    await page.screenshot({ path: `${OUT}/${TAG}-files-${width}.png`, fullPage: true });
    await expand(page, false);
    block.files = await measure(page);
    block.loop = await sampleLoop(page);
    await expand(page, true);
    await page.screenshot({ path: `${OUT}/${TAG}-loop-${width}.png`, fullPage: true });
    await expand(page, false);

    /* ── a refused publish must not eat what was typed ────────────────
       The old screen repainted every field from the last GET after a 422, so
       everything typed since the last successful save was reverted. This walks
       the real flow: make a clip, jump to Publish with no video and no cover,
       change the title, press Save, and read the box back. */
    block.refusal = await (async () => {
      await openLibrary(page);
      if (!(await clickAny(page, /^(New video|New clip|Add a clip|Add clip)$/i))) return null;

      const probe = `Lane V4 probe ${width}`;
      const box = page.locator('#ugs-title');
      if (!(await box.count())) return null;
      await box.fill(probe);

      /* Step 1 saves and moves on under the new revision; under the old one
         there is only Save, and the rest of this still works. */
      await clickAny(page, /^(Save and continue|Save)$/i);
      await page.waitForTimeout(2500);

      const step5 = page.locator('[data-ugs-step="5"]').first();
      if (await step5.count()) { await step5.click(); await page.waitForTimeout(1500); }

      const edited = `${probe} EDITED`;
      await page.evaluate((t) => {
        const title = document.querySelector('[data-ugs-field="title"]');
        if (title) title.value = t;
        const status = document.querySelector('[data-ugs-field="status"]');
        if (status) status.value = 'publish';
      }, edited);

      await clickAny(page, /^Save$/i);
      await page.waitForTimeout(2500);

      await expand(page, true);
      await page.screenshot({ path: `${OUT}/${TAG}-refusal-${width}.png`, fullPage: true });
      await expand(page, false);

      const answer = await page.evaluate((t) => {
        const title = document.querySelector('[data-ugs-field="title"]');
        return {
          titleInBox: title ? title.value : null,
          keptWhatWasTyped: !!title && title.value === t,
          blockersListed: document.querySelectorAll('.ugs-note.is-bad li, .ugs-bad li').length,
          checklistFailing: document.querySelectorAll('.ugs-ci.is-no').length,
        };
      }, edited);

      // ...and take the probe clip back out, so the second width measures the
      // same library the first one did.
      await clickAny(page, /^Delete$/i);
      await page.waitForTimeout(2000);

      return answer;
    })();

    report.widths.push(block);
    await ctx.close();
  }
} catch (e) {
  report.error = String(e && e.stack ? e.stack : e);
} finally {
  await browser.close();
}

console.log(JSON.stringify(report, null, 1));
