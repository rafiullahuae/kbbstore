/*
 * Lane LZ: WHEN does each picture in the first viewport become visible?
 *
 *   node tools/lz-film.cjs LABEL[,LABEL] PATH[,PATH] N [FILM_DIR] > out.json
 *
 * LABEL is a tools/spd-preview.sh preview. Every run is a FRESH browser context
 * (cold HTTP cache, no service worker), through tools/pg2g-throttle.cjs:
 *   phone   390x844 DPR3 touch, 4x CPU, 9 Mbps / 150 ms RTT (Fast 4G, 150 ms)
 *   laptop  1280x860, no CPU throttle, the same 9 Mbps / 150 ms network
 * Recorded per page: TTFB, LCP, CLS, the time EVERY picture in the first
 * viewport is visible (complete, has pixels, and nothing above it at opacity
 * 0 / visibility hidden), the latest of those and each one's own time; bytes of
 * those pictures; image and total request counts when that happened; console
 * errors. FILM_DIR keeps a screencast for the run with i == 0.
 * Harness-only JavaScript reads the page; nothing here ships.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const APP = path.dirname(__dirname);
const labels = process.argv[2].split(',');
const PATHS = process.argv[3].split(',');
const N = Number(process.argv[4] || 3);
const FILM = process.argv[5] || '';
const PROFS = (process.env.LZ_PROF || 'phone,laptop').split(',');
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };
const KBPS = Number(process.env.LZ_KBPS || 9000), RTT = Number(process.env.LZ_RTT || 150);
const PROFILES = {
  phone: { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, serviceWorkers: 'block' }, cpu: 4, proxy: 9481 },
  laptop: { ctx: { viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false, serviceWorkers: 'block' }, cpu: 1, proxy: 9482 },
};

const INIT = () => {
  window.__z = { lcp: 0, cls: 0, ready: new Map() };
  new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__z.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__z.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  const shown = (img) => {
    if (!img.complete || img.naturalWidth === 0) return false;
    for (let el = img; el && el.nodeType === 1; el = el.parentElement) {
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) < 0.99) return false;
    }
    return true;
  };
  const scan = () => {
    for (const img of document.images) if (!window.__z.ready.has(img) && shown(img)) window.__z.ready.set(img, performance.now());
    if (performance.now() < 30000) requestAnimationFrame(scan);
  };
  requestAnimationFrame(scan);
};

async function one(browser, profName, label, p, film) {
  const prof = PROFILES[profName];
  const base = 'http://127.0.0.1:' + port(label);
  const ctx = await browser.newContext(prof.ctx);
  await ctx.addInitScript(INIT);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
  page.on('pageerror', (e) => errors.push('pageerror ' + String(e).slice(0, 160)));
  const cdp = await ctx.newCDPSession(page);
  if (prof.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
  const shots = [];
  let navStart = 0;
  if (film) {
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 70, everyNthFrame: 1 });
    cdp.on('Page.screencastFrame', (f) => { shots.push({ t: Math.round(f.metadata.timestamp * 1000 - navStart), data: f.data }); cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {}); });
  }
  navStart = Date.now();
  await page.goto(base + p, { waitUntil: 'load', timeout: 180000 });
  await page.waitForTimeout(film ? 2500 : 1500);
  const r = await page.evaluate(() => {
    const vh = innerHeight, vw = innerWidth;
    // the picture's box as the shopper can see it: cut by every ancestor that
    // clips (a carousel track, the slider's viewport)
    const clip = (img) => {
      const r = img.getBoundingClientRect();
      let t = r.top, l = r.left, bo = r.bottom, ri = r.right;
      for (let el = img.parentElement; el && el !== document.body; el = el.parentElement) {
        const cs = getComputedStyle(el);
        if (cs.display !== 'contents' && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) { const c = el.getBoundingClientRect(); t = Math.max(t, c.top); l = Math.max(l, c.left); bo = Math.min(bo, c.bottom); ri = Math.min(ri, c.right); }
      }
      return { top: t, left: l, bottom: bo, right: ri, width: Math.max(0, ri - l), height: Math.max(0, bo - t) };
    };
    const nav = performance.getEntriesByType('navigation')[0];
    const res = performance.getEntriesByType('resource');
    const byUrl = new Map(res.map((e) => [e.name, e]));
    const atf = [];
    for (const img of document.images) {
      const b = clip(img);
      if (b.width < 8 || b.height < 8 || b.top >= vh || b.bottom <= 0 || b.left >= vw || b.right <= 0) continue;
      let vis = true;
      for (let el = img; el && el.nodeType === 1; el = el.parentElement) { const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) < 0.99) { vis = false; break; } }
      if (!vis) continue;
      const e = byUrl.get(img.currentSrc);
      atf.push({ t: window.__z.ready.has(img) ? Math.round(window.__z.ready.get(img)) : null, start: e ? Math.round(e.startTime) : null, end: e ? Math.round(e.responseEnd) : null, bytes: e ? e.encodedBodySize : 0, loading: img.getAttribute('loading') || '-', fp: img.getAttribute('fetchpriority') || '-', src: img.currentSrc.replace(location.origin, '').slice(-48) });
    }
    const allT = atf.map((a) => a.t).filter((x) => x != null);
    const done = atf.length && allT.length === atf.length ? Math.max(...allT) : null;
    const before = (t) => res.filter((e) => e.startTime <= t);
    const isImg = (e) => e.initiatorType === 'img' || /\.(jpe?g|png|webp|avif|gif)(\?|$)/i.test(e.name);
    return {
      ttfb: Math.round(nav.responseStart), lcp: Math.round(window.__z.lcp), cls: Math.round(window.__z.cls * 10000) / 10000,
      atfN: atf.length, atfLazy: atf.filter((a) => a.loading === 'lazy').length, atfDone: done,
      atfKB: Math.round(atf.reduce((s, a) => s + a.bytes, 0) / 1024),
      reqAtDone: done != null ? before(done).length + 1 : null, imgReqAtDone: done != null ? before(done).filter(isImg).length : null,
      reqTotal: res.length + 1, imgReqTotal: res.filter(isImg).length, imgKBTotal: Math.round(res.filter(isImg).reduce((s, e) => s + e.encodedBodySize, 0) / 1024),
      atf,
    };
  });
  if (film) await cdp.send('Page.stopScreencast').catch(() => {});
  await ctx.close();
  return { ...r, errors, shots };
}

(async () => {
  const procs = [];
  for (const pr of PROFS) procs.push(spawn(process.execPath, [path.join(__dirname, 'pg2g-throttle.cjs'), String(PROFILES[pr].proxy), String(KBPS), String(RTT)], { stdio: 'ignore' }));
  await new Promise((r) => setTimeout(r, 600));
  const browsers = {};
  for (const pr of PROFS) browsers[pr] = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--proxy-server=http://127.0.0.1:' + PROFILES[pr].proxy, '--proxy-bypass-list=<-loopback>'] });
  const out = {};
  try {
    for (let i = 0; i < N; i++) for (const pr of PROFS) for (const p of PATHS) for (const l of labels) {
      const film = FILM && i === 0;
      const r = await one(browsers[pr], pr, l, p, film);
      const key = [l, pr, p].join(' ');
      (out[key] = out[key] || []).push(r);
      if (film) {
        const dir = path.join(FILM, l + '-' + pr + '-' + p.replace(/[^a-z0-9]+/gi, '_'));
        fs.rmSync(dir, { recursive: true, force: true });
        fs.mkdirSync(dir, { recursive: true });
        for (const f of r.shots) fs.writeFileSync(path.join(dir, String(Math.max(0, f.t)).padStart(6, '0') + '.jpg'), Buffer.from(f.data, 'base64'));
      }
      delete r.shots;
      process.stderr.write(key + ' done=' + r.atfDone + ' lcp=' + r.lcp + ' atf=' + r.atfN + '/' + r.atfLazy + 'lazy err=' + r.errors.length + '\n');
    }
  } finally {
    for (const b of Object.values(browsers)) await b.close();
    for (const c of procs) c.kill();
  }
  const summary = {};
  for (const [k, runs] of Object.entries(out)) {
    summary[k] = {
      ttfb: median(runs.map((r) => r.ttfb)), atfDone: median(runs.map((r) => r.atfDone)), lcp: median(runs.map((r) => r.lcp)),
      cls: Math.max(...runs.map((r) => r.cls)), atfN: runs[0].atfN, atfLazy: runs[0].atfLazy, atfKB: median(runs.map((r) => r.atfKB)),
      reqAtDone: median(runs.map((r) => r.reqAtDone)), imgReqAtDone: median(runs.map((r) => r.imgReqAtDone)), reqTotal: median(runs.map((r) => r.reqTotal)),
      imgReqTotal: median(runs.map((r) => r.imgReqTotal)), imgKBTotal: median(runs.map((r) => r.imgKBTotal)), errors: [...new Set(runs.flatMap((r) => r.errors))],
      runs: runs.map((r) => r.atfDone), sample: runs[0].atf,
    };
  }
  process.stdout.write(JSON.stringify(summary, null, 1));
})();
