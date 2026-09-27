/*
 * Lane P2 — the three upload screens, measured in a real browser at 1280px and
 * 390px, before and after.
 *
 * IT REPORTS, IT DOES NOT JUDGE, following tests/browser/lane-u1-upload-limits.mjs
 * and lane-v4-add-clip.mjs. Everything this lane claims about a screen is a
 * claim about rendered output; no amount of server-side assertion settles what
 * the owner is shown. It is written to run UNCHANGED against either revision so
 * the two runs are comparable: the drop zones, the bars, the Stop buttons and the
 * ceiling sentences are all LOOKED FOR and reported absent rather than awaited.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about shipped JavaScript
 * sizing the page. This file is the instrument that proves the shipped CSS did.
 *
 * THE THREE SCREENS, and where each one is in the admin:
 *
 *   1. The shared Media Library popup — window.kbbPickMedia, reached from
 *      Catalog -> Products -> Edit -> Main image -> Choose, and from every other
 *      image field in the console.
 *   2. Catalog -> Products -> Edit -> Main image / Gallery / Search appearance.
 *   3. Reviews -> Review Import / Export -> Import.
 *
 * THE FIXTURES ARE REAL FILE HEADERS at sizes chosen against THIS box's ini
 * rather than picked round, so each one exercises a different failure mode:
 *
 *   under-limit.png    1,900,000 B  under upload_max_filesize (2M): it uploads
 *   over-per-file.png  3,145,728 B  over 2M, under post_max_size (8M): mode A
 *   over-post.png      9,437,184 B  over post_max_size: mode B, a 413
 *   small.csv             ~2,000 B  a CSV the importer really reads
 *   over-post.csv      9,437,184 B  a CSV that trips post_max_size
 *
 *   KBB_P2_BASE=http://127.0.0.1:8972 \
 *   KBB_P2_EMAIL=owner@example.com KBB_P2_PASSWORD=secret-secret \
 *   KBB_P2_FIXTURES=/tmp/p2-fixtures KBB_P2_OUT=/tmp/p2-shots KBB_P2_TAG=after \
 *   KBB_P2_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/lane-p2-upload-screens.mjs
 *
 * Serve the app the way docs/GJ-POSTS-AND-VERDICT.md §11 does — KBB_PUBLIC_PATH
 * at public-web-root, SESSION_DRIVER=file, `php -S 127.0.0.1:8972 -t
 * public-web-root public-web-root/index.php`. Using index.php as the router
 * means static files are NOT served, so an <img src> pointing at an upload 404s
 * in the console: that is the harness, not the screen.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.KBB_P2_BASE || 'http://127.0.0.1:8972';
const EMAIL = process.env.KBB_P2_EMAIL || 'owner@example.com';
const PASSWORD = process.env.KBB_P2_PASSWORD || 'secret-secret';
const FIXTURES = process.env.KBB_P2_FIXTURES || '/tmp/p2-fixtures';
const OUT = process.env.KBB_P2_OUT || '/tmp/p2-shots';
const TAG = process.env.KBB_P2_TAG || 'run';
const PRODUCT_ID = Number(process.env.KBB_P2_PRODUCT || 1);

fs.mkdirSync(OUT, { recursive: true });

const report = { tag: TAG, base: BASE, widths: [], console: [], error: null };

/* The bundled headless shell is not always the version this playwright expects,
   so the system Chromium is used when KBB_P2_CHROME names one. lane-u1 and
   lane-v4 take the same escape hatch for the same reason. */
const CHROME = process.env.KBB_P2_CHROME;
const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

async function login(page) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  if (await page.locator('input[type=password]').count()) {
    await page.locator('input[type=email], input[name=email]').first().fill(EMAIL);
    await page.locator('input[type=password]').first().fill(PASSWORD);
    await page.locator('button[type=submit], input[type=submit]').first().click();
    await page.waitForLoadState('networkidle');
  }
}

async function shoot(page, name) {
  const file = path.join(OUT, `${TAG}-${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  return path.basename(file);
}

/* Bring a card into frame before photographing it. These screens are taller
   than the viewport at both widths — the import card sits under a totals box
   and an export box, and the share-image field is near the foot of the editor —
   so a shot taken where the page happens to be scrolled shows the wrong thing.
   Scrolling for a PHOTOGRAPH is not the page measuring itself; nothing shipped
   does this. */
async function bring(page, sel) {
  await page.evaluate((s) => {
    document.querySelector(s)?.scrollIntoView({ block: 'center' });
  }, sel);
  await page.waitForTimeout(250);
}

/* The overflow numbers every screen in this console is judged on. Measured on
   #content as well as documentElement, because the console's shell is what
   scrolls, not the page — so the document metric is structurally blind here.
   AdminMobileOverflowTest draws the same distinction. */
async function frame(page) {
  return page.evaluate(() => {
    const c = document.querySelector('#content');
    return {
      docScrollWidth: document.documentElement.scrollWidth,
      docClientWidth: document.documentElement.clientWidth,
      contentScrollWidth: c ? c.scrollWidth : null,
      contentClientWidth: c ? c.clientWidth : null,
    };
  });
}

/* One selector, reported as "absent" rather than awaited, so the BEFORE run
   produces a row instead of a timeout. */
async function probe(page, sel) {
  return page.evaluate((s) => {
    const el = document.querySelector(s);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    const cs = window.getComputedStyle(el);
    return {
      w: Math.round(r.width),
      h: Math.round(r.height),
      fontSize: cs.fontSize,
      borderStyle: cs.borderTopStyle,
      borderColor: cs.borderTopColor,
      text: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 220),
    };
  }, sel);
}

async function probeAll(page, map) {
  const out = {};
  for (const [key, sel] of Object.entries(map)) out[key] = await probe(page, sel);
  return out;
}

/* ── the media picker ──────────────────────────────────────────────────────── */

async function openPicker(page) {
  /* Opened through the shared entry point rather than by clicking a screen's
     button, because the point of the dialog is that every screen opens the same
     one. A build where kbbPickMedia does not exist reports that and moves on. */
  const ok = await page.evaluate(() => {
    if (typeof window.kbbPickMedia !== 'function') return false;
    window.kbbPickMedia({ folder: 'products', onPick: () => {} });
    return true;
  });
  if (ok) await page.waitForTimeout(1200);
  return ok;
}

const PICKER = {
  dialog: '.mp-back',
  drop: '#mp-drop',
  uploadRow: '.mp-up',
  bar: '.mp-up-track > i',
  pct: '.mp-up-pct',
  stopOne: '.mp-up-x',
  stopRest: '#mp-upstop',
  batch: '.mp-up-head',
};

/* ── the product editor ───────────────────────────────────────────────────── */

/* window.peoEdit(id) is the editor's own external entry point — the one the
   products list's Edit button calls. It sets what the screen should show and
   then switches screens; calling go() and loadProduct() separately is the race
   the screen's own comment describes. */
async function openEditor(page, id) {
  const ok = await page.evaluate((pid) => {
    if (typeof window.peoEdit !== 'function') return null;
    window.peoEdit(pid);
    return 'peoEdit';
  }, id);
  await page.waitForTimeout(2600);
  return ok;
}

const EDITOR = {
  mainCard: '#peo-mainzone',
  mainHint: '#peo-mainzone .peo-dhint',
  mainBox: '#peo-mainzone .peo-main-img',
  galCard: '#peo-galzone',
  galDrop: '#peo-galdrop',
  galCap: '#peo-galdrop .peo-cap',
  ogZone: '#peo-ogzone',
  ogDrop: '#peo-ogdrop',
  ogCap: '#peo-ogdrop .peo-cap',
  mainUps: '[data-ups="main"]',
  galUps: '[data-ups="gallery"]',
  ogUps: '[data-ups="og"]',
  bar: '.peo-up-track > i',
  pct: '.peo-up-pct',
  stopOne: '.peo-up-x',
  banner: '.peo-banner',
};

/* ── the review importer ──────────────────────────────────────────────────── */

async function openImporter(page) {
  await page.evaluate(() => { if (typeof window.go === 'function') window.go('rev-io'); });
  await page.waitForTimeout(1800);
  return page.evaluate(() => !!document.querySelector('#rio-file'));
}

const IO = {
  card: '#rio-import-card',
  drop: '#rio-drop',
  cap: '#rio-drop span',
  legend: '#rio-import-card .rio-legend-sub',
  row: '#rio-up',
  bar: '#rio-up .rio-up-track > i',
  pct: '#rio-up .rio-up-pct',
  stop: '#rio-up-x',
  banner: '.rio-banner',
  name: '.rio-name',
};

/*
 * A DROP, not a click. setInputFiles() goes through the <input>, which is the
 * path that already worked — the thing this lane added is the DROP path, and the
 * only way to exercise it is a real DataTransfer.
 *
 * Playwright cannot construct a File in the page from a disk path directly, so
 * the bytes are handed over as an array and reassembled in the page. Kept small
 * for the files that are dropped; the multi-megabyte ones go through the input,
 * where size is the thing being tested rather than the transport.
 */
async function dropOnto(page, selector, files) {
  const payload = files.map((f) => ({
    name: path.basename(f.path),
    type: f.type,
    bytes: Array.from(fs.readFileSync(f.path)),
  }));

  return page.evaluate(({ selector, payload }) => {
    const node = document.querySelector(selector);
    if (!node) return false;

    const dt = new DataTransfer();
    for (const p of payload) {
      dt.items.add(new File([new Uint8Array(p.bytes)], p.name, { type: p.type }));
    }

    for (const type of ['dragenter', 'dragover']) {
      node.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt }));
    }
    return true;
  }, { selector, payload });
}

/** The same DataTransfer, let go. Split from the hover so the lit state can be shot. */
async function releaseOnto(page, selector, files) {
  const payload = files.map((f) => ({
    name: path.basename(f.path),
    type: f.type,
    bytes: Array.from(fs.readFileSync(f.path)),
  }));

  return page.evaluate(({ selector, payload }) => {
    const node = document.querySelector(selector);
    if (!node) return false;

    const dt = new DataTransfer();
    for (const p of payload) {
      dt.items.add(new File([new Uint8Array(p.bytes)], p.name, { type: p.type }));
    }

    node.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    return true;
  }, { selector, payload });
}


/*
 * AN UPLOAD IN FLIGHT, THROTTLED, because otherwise there is nothing to
 * photograph. A 40 KB PNG on a loopback server is finished before the first
 * frame is painted, so the first run of this file captured only the refusal row
 * and never the bar — which is the one thing rule 2 asks to be shown moving.
 * 1.9 MB at 50 KB/s is about forty seconds, which is also long enough to click
 * Stop and photograph what it leaves behind.
 */
async function throttle(context, upBytesPerSecond) {
  const cdp = await context.newCDPSession(await context.pages()[0]);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: 40,
    downloadThroughput: 4 * 1024 * 1024,
    uploadThroughput: upBytesPerSecond,
  });
  return cdp;
}

async function unthrottle(cdp) {
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
  });
}

/** Whichever of these cards is currently lit as a drop target. */
async function litZones(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('.is-drag'))
    .map((el) => el.id || el.className));
}

try {
  for (const width of [1280, 390]) {
    const context = await browser.newContext({
      viewport: { width, height: width === 390 ? 844 : 900 },
    });
    const page = await context.newPage();
    page.on('console', (m) => { if (m.type() === 'error') report.console.push(`${width}: ${m.text()}`); });
    page.on('pageerror', (e) => report.console.push(`${width}: pageerror ${e.message}`));

    const block = { width, shots: [], picker: {}, editor: {}, importer: {} };

    await login(page);

    /* ─────────────────────────── 1. the media picker ───────────────────── */

    block.picker.opened = await openPicker(page);
    block.picker.resting = await probeAll(page, PICKER);
    block.picker.frame = await frame(page);
    block.shots.push(await shoot(page, `${width}-1-picker-resting`));

    if (block.picker.opened) {
      // Hovering a file over the dialog: the whole dialog is the target.
      await dropOnto(page, '.mp-back', [{ path: path.join(FIXTURES, 'small.png'), type: 'image/png' }]);
      await page.waitForTimeout(300);
      block.picker.litWhileHovering = await litZones(page);
      block.shots.push(await shoot(page, `${width}-2-picker-drag-over`));

      // Let go. Two files, one of them over this server's ceiling, so the
      // pre-flight refusal and a real upload are on screen together.
      await releaseOnto(page, '.mp-back', [
        { path: path.join(FIXTURES, 'small.png'), type: 'image/png' },
        { path: path.join(FIXTURES, 'over-per-file.png'), type: 'image/png' },
      ]);
      await page.waitForTimeout(2500);
      block.picker.afterDrop = await probeAll(page, PICKER);
      block.picker.rows = await page.evaluate(() => Array.from(document.querySelectorAll('.mp-up'))
        .map((r) => ({
          cls: r.className,
          name: r.querySelector('.mp-up-name')?.innerText?.trim() || null,
          pct: r.querySelector('.mp-up-pct')?.innerText?.trim() || null,
          barWidth: r.querySelector('.mp-up-track > i')?.style.width || null,
          aria: r.querySelector('.mp-up-track')?.getAttribute('aria-valuenow') || null,
        })));
      block.shots.push(await shoot(page, `${width}-3-picker-after-drop`));

      /* Throttled, so the bar, the percentage and Stop can be photographed. */
      const cdp = await throttle(context, 50 * 1024);
      await releaseOnto(page, '.mp-back', [
        { path: path.join(FIXTURES, 'under-limit.png'), type: 'image/png' },
      ]);
      await page.waitForTimeout(6000);
      block.picker.inFlight = await probeAll(page, PICKER);
      block.picker.inFlightRows = await page.evaluate(() => Array.from(document.querySelectorAll('.mp-up'))
        .map((r) => ({
          cls: r.className,
          name: r.querySelector('.mp-up-name')?.innerText?.trim() || null,
          pct: r.querySelector('.mp-up-pct')?.innerText?.trim() || null,
          barWidth: r.querySelector('.mp-up-track > i')?.style.width || null,
          aria: r.querySelector('.mp-up-track')?.getAttribute('aria-valuenow') || null,
          stopVisible: !r.querySelector('.mp-up-x')?.hidden,
        })));
      block.shots.push(await shoot(page, `${width}-3b-picker-in-flight`));

      // Stop it, and photograph what Stop leaves behind.
      await page.evaluate(() => { document.querySelector('.mp-up-x')?.click(); });
      await page.waitForTimeout(900);
      block.picker.afterStop = await page.evaluate(() => Array.from(document.querySelectorAll('.mp-up'))
        .map((r) => ({ cls: r.className, pct: r.querySelector('.mp-up-pct')?.innerText?.trim() || null })));
      block.shots.push(await shoot(page, `${width}-3c-picker-stopped`));

      await unthrottle(cdp);

      await page.evaluate(() => { document.querySelector('#mp-cancel')?.click(); });
      await page.waitForTimeout(500);
    }

    /* ────────────────────────── 2. the product editor ───────────────────── */

    block.editor.opened = await openEditor(page, PRODUCT_ID);
    block.editor.resting = await probeAll(page, EDITOR);
    block.editor.frame = await frame(page);
    await bring(page, EDITOR.mainCard);
    block.shots.push(await shoot(page, `${width}-4-editor-resting`));

    // The share image zone, which had neither a drop target nor a bar.
    await page.evaluate(() => {
      document.querySelector('#peo-ogzone')?.scrollIntoView({ block: 'center' });
    });
    await page.waitForTimeout(300);
    block.shots.push(await shoot(page, `${width}-5-editor-share-image`));

    // A photograph dragged over the gallery card. On the BEFORE revision this
    // lights a THUMBNAIL and drops into a handler that does nothing.
    await dropOnto(page, '#peo-galzone', [{ path: path.join(FIXTURES, 'small.png'), type: 'image/png' }]);
    await page.waitForTimeout(300);
    block.editor.litWhileHovering = await litZones(page);
    await page.evaluate(() => {
      document.querySelector('#peo-galzone')?.scrollIntoView({ block: 'center' });
    });
    block.shots.push(await shoot(page, `${width}-6-editor-gallery-drag-over`));

    // Let go: three files, the middle one over the per-file ceiling.
    await releaseOnto(page, '#peo-galzone', [
      { path: path.join(FIXTURES, 'small.png'), type: 'image/png' },
      { path: path.join(FIXTURES, 'over-per-file.png'), type: 'image/png' },
      { path: path.join(FIXTURES, 'small2.png'), type: 'image/png' },
    ]);
    await page.waitForTimeout(400);
    block.editor.inFlight = await probeAll(page, EDITOR);
    block.editor.inFlightRows = await page.evaluate(() => Array.from(document.querySelectorAll('.peo-up'))
      .map((r) => ({
        cls: r.className,
        name: r.querySelector('.peo-up-name')?.innerText?.trim() || null,
        pct: r.querySelector('.peo-up-pct')?.innerText?.trim() || null,
        barWidth: r.querySelector('.peo-up-track > i')?.style.width || null,
        aria: r.querySelector('.peo-up-track')?.getAttribute('aria-valuenow') || null,
        stopVisible: !r.querySelector('.peo-up-x')?.hidden,
      })));
    await bring(page, EDITOR.galCard);
    block.shots.push(await shoot(page, `${width}-7-editor-gallery-in-flight`));

    await page.waitForTimeout(3500);
    block.editor.afterUpload = await probeAll(page, EDITOR);
    block.editor.afterRows = await page.evaluate(() => Array.from(document.querySelectorAll('.peo-up'))
      .map((r) => ({
        cls: r.className,
        name: r.querySelector('.peo-up-name')?.innerText?.trim() || null,
        pct: r.querySelector('.peo-up-pct')?.innerText?.trim() || null,
      })));
    await bring(page, EDITOR.galCard);
    block.shots.push(await shoot(page, `${width}-8-editor-gallery-done`));

    /* Throttled, and THREE files, so the queue is what gets photographed: the
       one sending with a live bar and a Stop, the ones waiting behind it, and
       "Stop the rest" above them. */
    const ecdp = await throttle(context, 50 * 1024);
    await releaseOnto(page, '#peo-galzone', [
      { path: path.join(FIXTURES, 'under-limit.png'), type: 'image/png' },
      { path: path.join(FIXTURES, 'under-limit2.png'), type: 'image/png' },
      { path: path.join(FIXTURES, 'small.png'), type: 'image/png' },
    ]);
    await page.waitForTimeout(7000);
    await page.evaluate(() => {
      document.querySelector('[data-ups="gallery"]')?.scrollIntoView({ block: 'center' });
    });
    block.editor.throttledRows = await page.evaluate(() => Array.from(document.querySelectorAll('.peo-up'))
      .map((r) => ({
        cls: r.className,
        name: r.querySelector('.peo-up-name')?.innerText?.trim() || null,
        pct: r.querySelector('.peo-up-pct')?.innerText?.trim() || null,
        barWidth: r.querySelector('.peo-up-track > i')?.style.width || null,
        aria: r.querySelector('.peo-up-track')?.getAttribute('aria-valuenow') || null,
        stopVisible: !r.querySelector('.peo-up-x')?.hidden,
      })));
    block.editor.stopRestVisible = await page.evaluate(() => {
      const b = document.querySelector('[data-upstop="gallery"]');
      return b ? !b.hidden : null;
    });
    await bring(page, '[data-ups="gallery"]');
    block.shots.push(await shoot(page, `${width}-8b-editor-queue-in-flight`));

    await page.evaluate(() => { document.querySelector('[data-upstop="gallery"]')?.click(); });
    await page.waitForTimeout(1500);
    block.editor.afterStopRest = await page.evaluate(() => Array.from(document.querySelectorAll('.peo-up'))
      .map((r) => ({ cls: r.className, pct: r.querySelector('.peo-up-pct')?.innerText?.trim() || null })));
    await bring(page, '[data-ups="gallery"]');
    block.shots.push(await shoot(page, `${width}-8c-editor-queue-stopped`));

    await unthrottle(ecdp);

    /* ───────────────────────── 3. the review importer ───────────────────── */

    block.importer.opened = await openImporter(page);
    block.importer.resting = await probeAll(page, IO);
    block.importer.frame = await frame(page);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-9-importer-resting`));

    await dropOnto(page, '#rio-import-card', [{ path: path.join(FIXTURES, 'small.csv'), type: 'text/csv' }]);
    await page.waitForTimeout(300);
    block.importer.litWhileHovering = await litZones(page);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-10-importer-drag-over`));

    await releaseOnto(page, '#rio-import-card', [{ path: path.join(FIXTURES, 'small.csv'), type: 'text/csv' }]);
    await page.waitForTimeout(700);
    block.importer.afterDrop = await probeAll(page, IO);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-11-importer-after-drop`));

    // Check the file, which is the first half of the documented order and the
    // pass that shows the bar and then the server phase.
    await page.evaluate(() => { document.querySelector('#rio-check')?.click(); });
    await page.waitForTimeout(250);
    block.importer.checking = await probeAll(page, IO);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-12-importer-checking`));

    await page.waitForTimeout(2500);
    block.importer.checked = await probeAll(page, IO);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-13-importer-checked`));

    /* Throttled, with a 1.5 MB CSV that is UNDER this server's ceiling, so both
       halves of the bar's story can be photographed: bytes going up, and then
       the server phase where the rows are parsed and written. */
    const icdp = await throttle(context, 50 * 1024);
    await releaseOnto(page, '#rio-import-card', [
      { path: path.join(FIXTURES, 'mid.csv'), type: 'text/csv' },
    ]);
    await page.waitForTimeout(600);
    await page.evaluate(() => { document.querySelector('#rio-check')?.click(); });
    await page.waitForTimeout(6000);
    block.importer.throttled = await probeAll(page, IO);
    block.importer.throttledRow = await page.evaluate(() => {
      const r = document.querySelector('#rio-up');
      if (!r) return null;
      return {
        cls: r.className,
        pct: r.querySelector('.rio-up-pct')?.innerText?.trim() || null,
        barWidth: r.querySelector('.rio-up-track > i')?.style.width || null,
        aria: r.querySelector('.rio-up-track')?.getAttribute('aria-valuenow') || null,
        stopVisible: !r.querySelector('#rio-up-x')?.hidden,
      };
    });
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-13b-importer-in-flight`));

    await page.evaluate(() => { document.querySelector('#rio-up-x')?.click(); });
    await page.waitForTimeout(1200);
    block.importer.afterStop = await probeAll(page, IO);
    await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-13c-importer-stopped`));

    await unthrottle(icdp);

    /* A CSV over post_max_size, through the INPUT: the pre-flight is what is
       being exercised, and the transport is not the point. On the BEFORE
       revision this reaches the server and comes back a bare 413. */
    const csvInput = page.locator('#rio-file');
    if (await csvInput.count()) {
      await csvInput.setInputFiles(path.join(FIXTURES, 'over-post.csv'));
      await page.waitForTimeout(400);
      await page.evaluate(() => { document.querySelector('#rio-check')?.click(); });
      await page.waitForTimeout(2500);
      block.importer.oversized = await probeAll(page, IO);
      await bring(page, IO.card);
    block.shots.push(await shoot(page, `${width}-14-importer-oversized`));
    }

    report.widths.push(block);
    await context.close();
  }
} catch (e) {
  report.error = `${e.message}\n${e.stack}`;
}

await browser.close();

const jsonPath = path.join(OUT, `${TAG}-report.json`);
fs.writeFileSync(jsonPath, JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, null, 2));
console.log(`\nreport: ${jsonPath}`);
