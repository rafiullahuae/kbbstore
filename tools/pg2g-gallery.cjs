/*
 * Lane PG2 (gallery): the product page's pictures, measured the way the owner
 * meets them -- a cold browser, a phone on a slow network, then a thumbnail
 * tap.
 *
 *   node tools/pg2g-gallery.cjs LABEL[,LABEL] N [FILMSTRIP_DIR] > out.json
 *
 * LABEL is a preview built by tools/spd-preview.sh (port read from its dir).
 * Every run is a FRESH browser context (empty HTTP cache, no service worker),
 * through tools/pg2g-throttle.cjs so the page AND the shop's service worker are
 * slowed alike:
 *   phone   390x844 DPR3 touch, 4x CPU, proxy 1.6 Mbps / 150 ms
 *   laptop  1280x800, no CPU throttle, proxy unthrottled (bytes counted only)
 * Two scenarios:
 *   cold    the product page is the first page of the visit (no worker yet)
 *   sw      the home page first, the worker takes control, THEN the product
 *           page -- the second page of an incognito visit
 * Recorded: TTFB, the main photograph's first painted pixel (its LCP entry),
 * LCP, CLS, image bytes on the wire, whether any gallery or card <img> was
 * ever seen BROKEN (complete with no pixels -- the only state in which Chrome
 * paints alt text, measured), and a thumbnail tap: tap -> first new picture in
 * the main frame, and tap -> the full-size picture.
 * Harness-only JavaScript reads the page; nothing here ships.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const APP = path.dirname(__dirname);
const labels = process.argv[2].split(',');
const N = Number(process.argv[3] || 3);
const FILM = process.argv[4] || '';
const PRODUCT = process.env.PG2G_PATH || '/product/spd-product-500/';
const SCEN = (process.env.PG2G_SCEN || 'cold,sw').split(',');
const PROFS = (process.env.PG2G_PROF || 'phone,laptop').split(',');
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };

const PROFILES = {
  phone: { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true }, cpu: 4, proxy: [9461, 1600, 150] },
  laptop: { ctx: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false }, cpu: 1, proxy: [9462, 1e7, 0] },
};

function startProxy([p, kbps, rtt]) {
  const child = spawn(process.execPath, [path.join(__dirname, 'pg2g-throttle.cjs'), String(p), String(kbps), String(rtt)], { stdio: ['ignore', 'ignore', fs.openSync(path.join(APP, 'storage/pg2-logs/proxy-' + p + '.err'), 'a')] });
  return child;
}
const ctl = async (page, what) => page.evaluate(async (u) => (await fetch(u)).json(), 'http://pg2g.control/' + what).catch(() => ({}));

const INIT = () => {
  window.__g = { lcp: 0, main: null, cls: 0, broken: [], tap: null, thumbs: null };
  new PerformanceObserver((l) => {
    for (const e of l.getEntries()) {
      window.__g.lcp = e.startTime;
      if (e.element && e.element.classList && e.element.classList.contains('gmain-img') && window.__g.main == null) window.__g.main = e.startTime;
    }
  }).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__g.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  const seen = new Set();
  const scan = () => {
    for (const i of document.querySelectorAll('.gmain-img,.gthumb-img,.kbb-card-img')) {
      if (i.complete && i.naturalWidth === 0 && (i.currentSrc || i.getAttribute('src'))) {
        const k = i.className + ' ' + (i.currentSrc || i.src);
        if (!seen.has(k)) { seen.add(k); window.__g.broken.push({ cls: i.className, src: (i.currentSrc || i.src).slice(-60), t: Math.round(performance.now()) }); }
      }
    }
    if (window.__g.thumbs == null) {
      const th = document.querySelectorAll('.gthumb-img');
      if (th.length && [...th].every((i) => i.complete && i.naturalWidth > 0)) window.__g.thumbs = performance.now();
    }
    const t = window.__g.tap;
    if (t && t.sharp == null) {
      const m = document.getElementById('gmainImg');
      const src = m ? decodeURIComponent(m.currentSrc || '') : '';
      // first: ANY new picture in the frame -- a new element with pixels (the
      // thumbnail shown while the big file loads), or the old element showing
      // the new file. sharp: the frame-sized file itself, decoded.
      const full = m && m.complete && m.naturalWidth > 0 && src.includes(t.want);
      if (t.first == null && m && ((m !== t.el && (m.naturalWidth > 0 || m.style.backgroundImage)) || full)) t.first = performance.now() - t.t0;
      if (full && m.naturalWidth > 200) t.sharp = performance.now() - t.t0;
    }
    if (performance.now() < 60000) requestAnimationFrame(scan);
  };
  requestAnimationFrame(scan);
  addEventListener('pointerdown', (e) => {
    const th = e.target.closest && e.target.closest('.gthumb');
    if (th) window.__g.tap = { el: document.getElementById('gmainImg'), t0: e.timeStamp, want: decodeURIComponent(th.dataset.image || '#none'), first: null, sharp: null };
  }, true);
};

async function frames(cdp, out, t0) {
  await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 70, everyNthFrame: 1 });
  cdp.on('Page.screencastFrame', async (f) => {
    out.push({ t: Math.round(f.metadata.timestamp * 1000 - t0()), data: f.data });
    cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
  });
}

async function one(browser, profName, label, scen, film) {
  const prof = PROFILES[profName];
  const base = 'http://127.0.0.1:' + port(label);
  const ctx = await browser.newContext(prof.ctx);
  await ctx.addInitScript(INIT);
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  if (prof.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
  // PG2G_QUOTA=bytes: a browser that cannot store much (incognito, a full
  // phone). Cache Storage writes beyond it are refused, as they are there.
  if (process.env.PG2G_QUOTA) await cdp.send('Storage.overrideQuotaForOrigin', { origin: base, quotaSize: Number(process.env.PG2G_QUOTA) });
  if (scen === 'sw') {
    await page.goto(base + '/', { waitUntil: 'load', timeout: 120000 });
    await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);
  }
  await ctl(page, 'reset');
  const shots = [];
  let navStart = 0;
  if (film) await frames(cdp, shots, () => navStart);
  navStart = Date.now();
  await page.goto(base + PRODUCT, { waitUntil: 'load', timeout: 180000 });
  await page.waitForFunction(() => window.__g.main != null, null, { timeout: 60000 }).catch(() => {});
  await page.waitForTimeout(prof.cpu > 1 ? 1500 : 400);
  const sw = await page.evaluate(() => Boolean(navigator.serviceWorker && navigator.serviceWorker.controller));
  const bytes = await ctl(page, 'stats');
  const load = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const m = document.getElementById('gmainImg');
    return { ttfb: nav.responseStart, thumbs: window.__g.thumbs, main: window.__g.main, lcp: window.__g.lcp, cls: window.__g.cls, broken: window.__g.broken.slice(), mainSrc: m ? m.currentSrc.replace(location.origin, '') : null };
  });
  // the tap: the second thumbnail
  const tapAt = Date.now() - navStart;
  const sel = '#gthumbs .gthumb:nth-child(' + (process.env.PG2G_TAP || 2) + ')';
  if (prof.ctx.hasTouch) await page.tap(sel); else await page.click(sel);
  await page.waitForFunction(() => window.__g.tap && window.__g.tap.sharp != null, null, { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(300);
  const tap = await page.evaluate(() => { const t = window.__g.tap; return t && { first: t.first, sharp: t.sharp }; });
  if (film) await cdp.send('Page.stopScreencast').catch(() => {});
  await ctx.close();
  const img = Object.entries(bytes).filter(([k]) => k.startsWith('image/')).reduce((s, [, v]) => s + v, 0);
  return { ttfb: load.ttfb, thumbs: load.thumbs, main: load.main, lcp: load.lcp, cls: Math.round(load.cls * 1000) / 1000, imgKB: Math.round(img / 1024), broken: load.broken, mainSrc: load.mainSrc, sw, tapFirst: tap && tap.first, tapSharp: tap && tap.sharp, tapAt, shots };
}

(async () => {
  const procs = [];
  const browsers = {};
  for (const p of PROFS) {
    procs.push(startProxy(PROFILES[p].proxy));
  }
  await new Promise((r) => setTimeout(r, 600));
  for (const p of PROFS) {
    browsers[p] = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--proxy-server=http://127.0.0.1:' + PROFILES[p].proxy[0], '--proxy-bypass-list=<-loopback>'] });
  }
  const out = {};
  for (let i = 0; i < N; i++) {
    for (const p of PROFS) for (const s of SCEN) for (const l of labels) {
      const film = FILM && i === 0;
      const r = await one(browsers[p], p, l, s, film);
      const key = [l, p, s].join(' ');
      (out[key] = out[key] || []).push(r);
      if (film) {
        const dir = path.join(FILM, l + '-' + p + '-' + s);
        fs.mkdirSync(dir, { recursive: true });
        for (const f of r.shots) fs.writeFileSync(path.join(dir, String(f.t).padStart(6, '0') + '.jpg'), Buffer.from(f.data, 'base64'));
        fs.writeFileSync(path.join(dir, 'meta.json'), JSON.stringify({ tapAt: r.tapAt, main: r.main, tapFirst: r.tapFirst, tapSharp: r.tapSharp }));
      }
      delete r.shots;
      process.stderr.write(key + ' ' + JSON.stringify(r) + '\n');
    }
  }
  const summary = {};
  for (const [k, runs] of Object.entries(out)) {
    summary[k] = {
      ttfb: median(runs.map((r) => r.ttfb)), thumbs: median(runs.map((r) => r.thumbs)), mainPixel: median(runs.map((r) => r.main)), lcp: median(runs.map((r) => r.lcp)),
      cls: Math.max(...runs.map((r) => r.cls)), imgKB: median(runs.map((r) => r.imgKB)), brokenRuns: runs.filter((r) => r.broken.length).length,
      tapFirst: median(runs.map((r) => r.tapFirst)), tapSharp: median(runs.map((r) => r.tapSharp)), sw: runs.map((r) => r.sw).join(','), mainSrc: runs[0].mainSrc,
    };
  }
  console.log(JSON.stringify({ summary, runs: out }, null, 1));
  for (const b of Object.values(browsers)) await b.close();
  for (const c of procs) c.kill();
})();
