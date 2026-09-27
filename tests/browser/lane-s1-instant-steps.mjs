/*
 * Lane S1 — Content → Shoppable video → All clips → a clip → the five steps.
 * What does ONE step change cost, in each direction?
 *
 * IT REPORTS, IT DOES NOT JUDGE, the same way tests/browser/lane-v5-editor-cols.mjs
 * and lane-p1-upload-kit.mjs do. The owner said moving between the steps was slow
 * "in any case" — forward and back — and no amount of reading the server's output
 * can settle that. What can: click a step, wait until the target panel is on
 * screen AND the frame carrying it has been composited, and count every request
 * the front door saw in the meantime.
 *
 * IT RUNS UNCHANGED AGAINST EITHER REVISION, so the before and after tables are
 * comparable.
 *
 * ── THE TWO THINGS THAT MADE THE MEASUREMENT HONEST ────────────────────────
 *
 * 1. THE FRAME FENCE, not the attribute. render() drops `hidden` synchronously,
 *    so watching for that measures the script and none of the style, layout and
 *    paint the new DOM then costs. And goStep() is async, so on a FORWARD step
 *    the click returns long before the panel changes — timing the click alone
 *    reported 11 ms for a transition that really took 1170.
 *
 * 2. A FRONT DOOR THAT SUPPORTS HTTP RANGE. `php -S` does not implement Range
 *    for static files: it answers 200 with the whole body whatever the browser
 *    asked for. Measured behind that, every media re-fetch looks 8.5 MB big and
 *    the owner's "it re-downloads the clip" theory proves itself by instrument
 *    bug. Put a Range-capable server in front of the uploads directory, the way
 *    Apache and nginx serve them on the live box, before believing any byte
 *    count from this file.
 *
 * MEASURING HERE IS NOT WHAT RULE 4 FORBIDS. Rule 4 is about SHIPPED JavaScript
 * sizing the page; this file is the instrument that checks the shipped code, and
 * it is the only place in this lane's work that may touch a rect or a rAF.
 *
 *   BASE=http://127.0.0.1:8941 CLIP=3 TAG=after THROTTLE=1 LATENCY=250 \
 *   CHROME=/path/to/chrome node tests/browser/lane-s1-instant-steps.mjs
 *
 * ── WHAT IT FOUND, on a clip carrying the owner's own file (8.5 MB, a cover,
 *    no teaser) at 300 KB/s and 250 ms round trip ────────────────────────────
 *
 *                     BEFORE                AFTER
 *   forward 1->2     1170 ms, 4 calls      57 ms, 2 calls
 *   forward 4->5     1135 ms, 4 calls      24 ms, 2 calls
 *   back    5->4       21 ms, 0 calls      35 ms, 0 calls
 *   back    2->1       19 ms, 0 calls       8 ms, 0 calls
 *
 * — so half the report was never a latency problem: going back was already
 * 14-21 ms. Forward was four SEQUENTIAL round trips. And the stamps below prove
 * the other half: the <video> survived NONE of the eight transitions before and
 * ALL eight after.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8941';
const REQLOG = process.env.REQLOG || '/home/user/kbb-lane-s1/.preview/req.log';
const TAG = process.env.TAG || 'run';
const OUT = process.env.OUT || '/home/user/kbb-lane-s1/.preview/shots';
const CLIP = process.env.CLIP || '1';          // 1 = no teaser (owner's shape), 2 = with teaser
const THROTTLE = process.env.THROTTLE === '1';

function reqCount() {
  try { return fs.readFileSync(REQLOG, 'utf8').split('\n').filter(Boolean).length; } catch (e) { return 0; }
}
function reqLines() {
  try { return fs.readFileSync(REQLOG, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)); }
  catch (e) { return []; }
}

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/chromium-browser', args: ['--no-sandbox', '--autoplay-policy=no-user-gesture-required'] });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

if (THROTTLE) {
  /* The owner's link, as this project measured it earlier: ~300 KB/s up and a
     comparable down. A step change that re-fetches media is only painful at
     this speed, so the before/after numbers are taken here as well as on the
     loopback. */
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', {
    offline: false, latency: Number(process.env.LATENCY || 40), downloadThroughput: 300 * 1024, uploadThroughput: 300 * 1024,
  });
}

await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.fill('input[type=email], input[name=email]', 'owner@example.com');
await page.fill('input[type=password], input[name=password]', 'secret-secret');
await page.press('input[type=password], input[name=password]', 'Enter');
await page.waitForLoadState('networkidle');

await page.evaluate(() => window.go('ugcvideo'));
await page.waitForTimeout(2500);

/* Open the clip under test. */
const opened = await page.evaluate((id) => {
  const b = document.querySelector('[data-ugs-open="' + id + '"]');
  if (!b) return false;
  b.click();
  return true;
}, CLIP);
if (!opened) { console.log(JSON.stringify({ error: 'no clip tile for id ' + CLIP })); await browser.close(); process.exit(1); }
await page.waitForTimeout(3500);

/* What the step machinery looks like from outside, so a repaint is detectable
   without reading the source: a marker stamped on the two media elements
   survives a repaint only if the element itself did. */
async function stamp() {
  return page.evaluate(() => {
    const main = document.querySelector('.ugs-panel video[controls]');
    const loop = document.querySelector('.ugs-loop video');
    if (main && !main.__s1) main.__s1 = 'main-' + Math.random().toString(36).slice(2);
    if (loop && !loop.__s1) loop.__s1 = 'loop-' + Math.random().toString(36).slice(2);
    return { main: main ? main.__s1 : null, loop: loop ? loop.__s1 : null };
  });
}

async function shape() {
  return page.evaluate(() => {
    const panels = [...document.querySelectorAll('.ugs-panel')];
    const main = document.querySelector('.ugs-panel video[controls]');
    const loop = document.querySelector('.ugs-loop video');
    return {
      visiblePanel: panels.findIndex((p) => !p.hasAttribute('hidden')) + 1,
      onStep: (document.querySelector('.ugs-step.is-on') || {}).textContent
        ? (document.querySelector('.ugs-step.is-on').textContent || '').replace(/\s+/g, ' ').trim().slice(0, 22) : null,
      mainId: main ? main.__s1 || null : null,
      loopId: loop ? loop.__s1 || null : null,
      mainTime: main ? Number(main.currentTime.toFixed(2)) : null,
      loopTime: loop ? Number(loop.currentTime.toFixed(2)) : null,
      mainReady: main ? main.readyState : null,
      loopReady: loop ? loop.readyState : null,
      panels: panels.length,
    };
  });
}

/* One transition, measured. Returns the wall time until the target panel is the
   visible one, and every request the front server saw in the meantime. */
async function step(to) {
  await stamp();
  const before = reqCount();
  const t0 = Date.now();

  /*
   * TIME TO THE NEXT PAINTED FRAME, not time to the attribute changing.
   * render() drops `hidden` synchronously, so watching for that measures the
   * script and none of the style, layout and paint the new DOM then costs --
   * which is the part the owner would actually see. Two rAFs is the standard
   * "the frame after this one has been composited" fence, and long tasks over
   * the same window say how much of it blocked the main thread.
   */
  const paint = await page.evaluate(async (n) => {
    const longs = [];
    let po = null;
    try {
      po = new PerformanceObserver((l) => l.getEntries().forEach((e) => longs.push(Math.round(e.duration))));
      po.observe({ entryTypes: ['longtask'] });
    } catch (e) {}
    const visible = () => {
      const panels = [...document.querySelectorAll('.ugs-panel')];
      return panels.length >= n && !panels[n - 1].hasAttribute('hidden');
    };
    const t0 = performance.now();
    const b = document.querySelector('[data-ugs-step="' + n + '"]');
    if (b) b.click();
    else { const next = document.querySelector('[data-ugs-next]'); if (next) next.click(); }
    /* The synchronous part: goStep() is async, so a FORWARD step returns here
       long before the panel changes. Reported separately from the whole thing. */
    const script = performance.now() - t0;
    /* THE NUMBER THAT MATTERS: click until the target panel is on screen AND the
       frame carrying it has been composited. Polled on rAF so the wait itself
       costs one frame, not a setTimeout's worth. */
    await new Promise((resolve) => {
      const tick = () => {
        if (visible()) { requestAnimationFrame(() => requestAnimationFrame(resolve)); return; }
        if (performance.now() - t0 > 30000) { resolve(); return; }
        requestAnimationFrame(tick);
      };
      tick();
    });
    const frame = performance.now() - t0;
    await new Promise((r) => setTimeout(r, 400));
    if (po) try { po.disconnect(); } catch (e) {}
    return { scriptMs: Math.round(script), frameMs: Math.round(frame), longTasks: longs };
  }, to);

  /* Paint time: when does the target panel actually become the visible one. */
  let painted = null;
  try {
    await page.waitForFunction((n) => {
      const panels = [...document.querySelectorAll('.ugs-panel')];
      if (panels.length < n) return false;
      return !panels[n - 1].hasAttribute('hidden');
    }, to, { timeout: 30000 });
    painted = Date.now() - t0;
  } catch (e) { painted = null; }

  /* Then let anything the repaint kicked off actually land, so the request
     count is the whole cost of the transition and not just the part before the
     panel appeared. */
  await page.waitForTimeout(2600);
  const settled = Date.now() - t0;
  const after = reqLines().slice(before);

  return {
    to, paintedMs: painted, settledMs: settled, scriptMs: paint.scriptMs, frameMs: paint.frameMs, longTasks: paint.longTasks,
    requests: after.length,
    media: after.filter((r) => /\.(mp4|webm|jpg|jpeg|png)$/i.test(r.p)).map((r) => r.p.split('/').pop() + (r.range ? ' ' + r.range : ' full')),
    php: after.filter((r) => r.php).map((r) => r.p),
    shape: await shape(),
  };
}

const rows = [];
const start = await shape();
await stamp();

/* FORWARD 1->5, then BACKWARD 5->1, which is what he described. */
for (const n of [2, 3, 4, 5]) rows.push(await step(n));
for (const n of [4, 3, 2, 1]) rows.push(await step(n));

/* Typing, and whether a step change keeps it -- the draft half of the task. */
await page.evaluate(() => {
  const t = document.querySelector('[data-ugs-field="title"]');
  if (t) { t.value = 'TYPED BY THE INSTRUMENT'; t.dispatchEvent(new Event('input', { bubbles: true })); }
  const c = document.querySelector('[data-ugs-field="caption"]');
  if (c) { c.value = 'A caption nobody saved.'; c.dispatchEvent(new Event('input', { bubbles: true })); }
});
await page.waitForTimeout(300);
const typedThenStepped = await (async () => {
  await step(3);
  await step(1);
  return page.evaluate(() => ({
    title: (document.querySelector('[data-ugs-field="title"]') || {}).value || null,
    caption: (document.querySelector('[data-ugs-field="caption"]') || {}).value || null,
  }));
})();

/* And whether it survives a RELOAD, which is the other half of "quick draft". */
await page.reload({ waitUntil: 'networkidle' });
await page.waitForTimeout(1200);
await page.evaluate(() => window.go('ugcvideo'));
await page.waitForTimeout(2200);
const afterReload = await page.evaluate((id) => {
  const b = document.querySelector('[data-ugs-open="' + id + '"]');
  if (b) b.click();
  return true;
}, CLIP);
await page.waitForTimeout(2600);
const reloaded = await page.evaluate(() => ({
  title: (document.querySelector('[data-ugs-field="title"]') || {}).value || null,
  caption: (document.querySelector('[data-ugs-field="caption"]') || {}).value || null,
  draftNotice: !!document.querySelector('[data-ugs-draft]'),
  noticeText: (document.querySelector('[data-ugs-draft]') || {}).textContent
    ? document.querySelector('[data-ugs-draft]').textContent.replace(/\s+/g, ' ').trim().slice(0, 200) : null,
}));

console.log(JSON.stringify({ tag: TAG, clip: CLIP, throttled: THROTTLE, start, rows,
  typedThenStepped, reloaded, consoleErrors: consoleErrors.slice(0, 12) }, null, 2));

await browser.close();
