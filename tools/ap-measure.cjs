/*
 * Lane AP -- the admin console's load, measured on a throttled profile.
 *
 *   sh tools/ap-preview.sh            (prints the port)
 *   node tools/ap-measure.cjs <port> <label>
 *
 * Profile: Chromium, CPU throttled 4x, 2 Mbit/s down / 2 Mbit/s up, 40 ms RTT.
 * Per run: one COLD load (cache cleared, no kbb_aa cookie: a first visit),
 * three WARM ones (later visits, from the cache; the median is reported) and
 * one HARD REFRESH (Shift+reload: cache bypassed, no-cache sent). Writes storage/ap-logs/measure-<label>.json and a filmstrip of the
 * cold load to docs/ap-shots/filmstrip-<label>.png.
 *
 * What "sidebar complete" means here: the moment, read on requestAnimationFrame
 * (so it is the frame the rows were painted in, not the moment a script ran),
 * at which #nav holds every row it will hold once the page has settled. The
 * settled count is read after `load`, so a run that never completes says so
 * instead of reporting the time it gave up.
 *
 * The rAF probe is the HARNESS's instrument; the console itself measures
 * nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const PORT = process.argv[2] || '10470';
const LABEL = process.argv[3] || 'run';
const BASE = `http://127.0.0.1:${PORT}`;
const ROOT = path.join(__dirname, '..');
const LOGS = path.join(ROOT, 'storage', 'ap-logs');
const SHOTS = path.join(ROOT, 'docs', 'ap-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
fs.mkdirSync(LOGS, { recursive: true });
fs.mkdirSync(SHOTS, { recursive: true });

const PROBE = () => {
  const t0 = performance.timeOrigin;
  window.__ap = { frames: [], firstRow: null, dash: null };
  let last = -1;
  const tick = (ts) => {
    const nav = document.getElementById('nav');
    // Rows that can be PAINTED: a <nav data-wait> is visibility:hidden.
    const n = nav && !nav.hasAttribute('data-wait') ? nav.querySelectorAll('.nav-item').length : 0;
    if (n !== last) { window.__ap.frames.push([Math.round(ts), n]); last = n; }
    // The first frame the dashboard (the screen the console opens on) is drawn.
    if (window.__ap.dash === null && document.getElementById('kbbDashWrap')) window.__ap.dash = Math.round(ts);
    if (document.readyState !== 'complete' || window.__ap.frames.length < 2) requestAnimationFrame(tick);
    else requestAnimationFrame(tick); // keep sampling until the harness reads it
  };
  requestAnimationFrame(tick);
};

async function login(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, userAgent: UA });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function throttled(page) {
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 40, downloadThroughput: 250000, uploadThroughput: 250000 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  return cdp;
}

async function load(page, cdp, { cold, film, measureOnly }) {
  if (measureOnly) return { m: await read(page) };
  if (cold) await cdp.send('Network.clearBrowserCache');
  // A blank page first, so no frame of the filmstrip is the previous page.
  await page.goto('about:blank');
  const frames = [];
  if (film) {
    cdp.on('Page.screencastFrame', async (f) => {
      frames.push({ ts: f.metadata.timestamp, data: f.data });
      try { await cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }); } catch (e) {}
    });
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 70, maxWidth: 640, maxHeight: 430, everyNthFrame: 1 });
  }
  const wall0 = Date.now() / 1000;
  await page.goto(`${BASE}/admin`, { waitUntil: 'load', timeout: 180000 });
  await page.waitForTimeout(600);
  if (film) await cdp.send('Page.stopScreencast');
  return { m: await read(page), frames, wall0 };
}

function read(page) {
  return page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    const fp = performance.getEntriesByName('first-paint')[0];
    const settled = document.querySelectorAll('#nav .nav-item').length;
    const frames = window.__ap ? window.__ap.frames : [];
    const done = frames.find((f) => f[1] >= settled);
    const first = frames.find((f) => f[1] > 0);
    return {
      settledRows: settled,
      firstRowFrameMs: first ? first[0] : null,
      sidebarCompleteFrameMs: done ? done[0] : null,
      dashboardFrameMs: window.__ap ? window.__ap.dash : null,
      firstPaintMs: fp ? Math.round(fp.startTime) : null,
      fcpMs: fcp ? Math.round(fcp.startTime) : null,
      responseEndMs: Math.round(nav.responseEnd),
      domContentLoadedMs: Math.round(nav.domContentLoadedEventEnd),
      loadMs: Math.round(nav.loadEventEnd),
      docTransferBytes: nav.transferSize, docEncodedBytes: nav.encodedBodySize, docDecodedBytes: nav.decodedBodySize,
      resources: performance.getEntriesByType('resource').filter((r) => /\/build\//.test(r.name)).map((r) => ({ url: r.name.replace(/^https?:\/\/[^/]+/, ''), transfer: r.transferSize, encoded: r.encodedBodySize, decoded: r.decodedBodySize, endMs: Math.round(r.responseEnd) })),
      frames,
    };
  });
}

async function filmstrip(browser, frames, file, t0) {
  if (!frames.length) return [];
  const marks = [0.5, 1, 1.5, 2, 2.5, 3, 4, 5, 6, 7];
  const picked = [];
  for (const s of marks) {
    let best = null;
    for (const f of frames) if (f.ts - t0 <= s) best = f;
    if (best) picked.push({ s, data: best.data });
  }
  const html = `<html><body style="margin:0;background:#222;font:12px sans-serif;color:#eee;display:flex;flex-wrap:wrap;gap:6px;padding:6px;width:${5 * 326}px">` +
    picked.map((p) => `<div><div style="padding:2px 0">${p.s.toFixed(1)} s</div><img src="data:image/jpeg;base64,${p.data}" style="width:320px;display:block;border:1px solid #555"></div>`).join('') + '</body></html>';
  const ctx = await browser.newContext({ viewport: { width: 5 * 326 + 12, height: 200 } });
  const p = await ctx.newPage();
  await p.setContent(html);
  await p.screenshot({ path: file, fullPage: true });
  await ctx.close();
  return picked.map((p) => p.s);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const { ctx, page } = await login(browser);
  await page.addInitScript(PROBE);

  // Raw and gzip size of the document, read without throttling: first as a
  // browser without the build's files gets it (inline), then as one with them.
  await ctx.clearCookies({ name: 'kbb_aa' });
  const get = () => page.evaluate(async () => (await fetch(location.origin + '/admin', { credentials: 'same-origin' })).text());
  const inlineHtml = await get();
  const html = await get();
  const raw = Buffer.byteLength(html);
  const gz = zlib.gzipSync(Buffer.from(html), { level: 6 }).length;
  const inlineRaw = Buffer.byteLength(inlineHtml);
  const inlineGz = zlib.gzipSync(Buffer.from(inlineHtml), { level: 6 }).length;
  const navAt = html.indexOf('id="nav"');
  const navEnd = html.indexOf('</nav>', navAt);
  const lastRowAt = html.lastIndexOf('data-go=', navEnd);

  const cdp = await throttled(page);
  // COLD: a browser that has never fetched this build -- empty cache, no
  // kbb_aa cookie. Then wait for the after-load prefetches to land.
  await ctx.clearCookies({ name: 'kbb_aa' });
  const cold = await load(page, cdp, { cold: true, film: true });
  await page.waitForTimeout(12000);
  const strip = await filmstrip(browser, cold.frames, path.join(SHOTS, `filmstrip-${LABEL}.png`), cold.wall0);
  // Warm: three more visits, each from the browser's cache, reported as the
  // median by sidebar-complete time (single throttled runs vary by ~2x).
  const warms = [];
  for (let k = 0; k < 3; k++) warms.push((await load(page, cdp, { cold: false, film: false })).m);
  warms.sort((a, b) => a.sidebarCompleteFrameMs - b.sidebarCompleteFrameMs);
  const warm = { m: warms[1] };
  const warmAll = warms.map((w) => ({ complete: w.sidebarCompleteFrameMs, dash: w.dashboardFrameMs, fcp: w.fcpMs, dcl: w.domContentLoadedMs }));

  // HARD REFRESH: Shift+reload of a console the browser already knows. The
  // browser bypasses its cache and says no-cache; the server answers inline.
  await page.goto(`${BASE}/admin`, { waitUntil: 'load' });
  const reloaded = page.waitForEvent('load', { timeout: 180000 });
  await cdp.send('Page.reload', { ignoreCache: true });
  await reloaded;
  await page.waitForTimeout(600);
  const hard = (await load(page, cdp, { measureOnly: true })).m;

  const out = {
    label: LABEL,
    profile: 'Chromium 4x CPU throttle, 2 Mbit/s, 40 ms RTT, gzip',
    documentInline: { rawBytes: inlineRaw, gzipBytes: inlineGz },
    document: { rawBytes: raw, gzipBytes: gz, navOpenAtByte: navAt, navCloseAtByte: navEnd, lastNavRowInHtmlAtByte: navEnd > navAt ? lastRowAt : null },
    cold: cold.m,
    warm: warm.m,
    warmRuns: warmAll,
    hardRefresh: hard,
    filmstripSeconds: strip,
  };
  for (const k of ['cold', 'warm', 'hardRefresh']) out[k].frames = out[k].frames.slice(0, 12);
  fs.writeFileSync(path.join(LOGS, `measure-${LABEL}.json`), JSON.stringify(out, null, 2));
  const s = (r) => `rows=${r.settledRows} firstRow=${r.firstRowFrameMs} complete=${r.sidebarCompleteFrameMs} dash=${r.dashboardFrameMs} FP=${r.firstPaintMs} FCP=${r.fcpMs} DCL=${r.domContentLoadedMs} load=${r.loadMs} docTransfer=${r.docTransferBytes}`;
  console.log(`${LABEL} doc raw=${raw} gzip=${gz} navAt=${navAt} | inline doc raw=${inlineRaw} gzip=${inlineGz}`);
  console.log(`  cold ${s(cold.m)}`);
  console.log(`  warm ${s(warm.m)}`);
  console.log('  warm runs', JSON.stringify(warmAll));
  console.log(`  hard ${s(hard)}`);
  await ctx.close();
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
