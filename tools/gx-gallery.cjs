/*
 * Lane GX (gallery stand-in + idle warm-up): what a shopper sees on a product
 * page and on a thumbnail tap, and whether the background warm-up slows the
 * NEXT page.
 *
 *   node tools/gx-gallery.cjs tap LABEL[,LABEL] N [SHOTS_DIR] > out.json
 *   node tools/gx-gallery.cjs nav LABEL[,LABEL] N > out.json
 *
 * LABEL is a preview booted by tools/spd-preview.sh (port read from its dir).
 * Every run is a FRESH browser context (cold cache) through
 * tools/pg2g-throttle.cjs, which shares ONE bandwidth budget between every
 * response -- so a warm-up download really does compete with a click.
 *   phone-s4g  390x844 DPR3 touch, 4x CPU, 1.6 Mbps / 150 ms   ("Slow 4G")
 *   phone-4g   390x844 DPR3 touch, 4x CPU, 9 Mbps / 60 ms
 *   laptop     1280x800 DPR1, 20 Mbps / 30 ms
 * navigator.connection is pinned to effectiveType '4g' (Chrome reports Slow 4G
 * as '4g' too: 150 ms RTT, 1.6 Mbps), GX_ECT overrides it.
 *
 * tap: load the product page; record LCP, CLS, TBT (long tasks, load+10 s),
 *      image bytes before `load` and in all; tap the 4th thumbnail (neither
 *      the shown nor the next shot) GX_AT ms after load ('early' 600, 'late'
 *      7000); record tap -> next paint (Event Timing: the INP of the tap),
 *      what the frame shows (stand-in kind, its pixel width), tap -> sharp
 *      (the full file decoded AND fully opaque), long tasks during the swap,
 *      bytes the tap cost, and frames at 50/150/300/600 ms after the tap.
 * nav: load the product page, wait D ms after load (1000, 2000, 4000), touch a
 *      link (90 ms finger, as tools/spd-navtime.cjs) to home / a category / a
 *      product; record click -> next page FCP and whether the prefetch served.
 * Harness-only JavaScript reads the page; nothing here ships.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const APP = path.dirname(__dirname);
const MODE = process.argv[2];
const labels = process.argv[3].split(',');
const N = Number(process.argv[4] || 3);
const SHOTS = process.argv[5] || '';
const PRODUCT = process.env.GX_PATH || '/product/spd-product-500/';
const ECT = process.env.GX_ECT || '4g';
const PROFS = (process.env.GX_PROF || 'phone-s4g,phone-4g,laptop').split(',');
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

const PROFILES = {
  'phone-s4g': { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: UA }, cpu: 4, proxy: [9481, 1600, 150] },
  'phone-4g': { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: UA }, cpu: 4, proxy: [9482, 9000, 60] },
  laptop: { ctx: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }, cpu: 1, proxy: [9483, 20000, 30] },
};

const INIT = (ect) => {
  try { Object.defineProperty(navigator, 'connection', { configurable: true, get: () => ({ effectiveType: ect, saveData: false, addEventListener() {} }) }); } catch (e) { /* */ }
  window.__g = { lcp: 0, cls: 0, lt: [], tap: null, fcp: null };
  const po = (type, fn, o) => { try { new PerformanceObserver((l) => l.getEntries().forEach(fn)).observe(Object.assign({ type, buffered: true }, o || {})); } catch (e) { /* */ } };
  po('largest-contentful-paint', (e) => { window.__g.lcp = e.startTime; });
  po('paint', (e) => { if (e.name === 'first-contentful-paint') window.__g.fcp = e.startTime; });
  po('layout-shift', (e) => { if (!e.hadRecentInput) window.__g.cls += e.value; });
  po('longtask', (e) => { window.__g.lt.push([Math.round(e.startTime), Math.round(e.duration)]); });
  po('event', (e) => {
    const t = window.__g.tap;
    if (t && e.startTime >= t.ts - 5 && /pointer|click|touch/.test(e.name)) t.inp = Math.max(t.inp || 0, Math.round(e.duration));
  }, { durationThreshold: 16 });
  const scan = () => {
    const t = window.__g.tap;
    if (t && t.sharp == null) {
      const now = performance.now() - t.ts;
      const m = document.getElementById('gmainImg');
      const s = document.querySelector('#gmain .gx-s');
      const bg = (el) => (el && el.style.backgroundImage) || '';
      let kind = 'old';
      if (m && m !== t.el) {
        const full = m.complete && m.naturalWidth > 0 && decodeURIComponent(m.currentSrc).includes(t.want) && m.classList.contains('ld') && getComputedStyle(m).opacity === '1';
        if (full) kind = 'full';
        else if (m.complete && m.naturalWidth > 0 && getComputedStyle(m).opacity !== '0' && m.classList.contains('ld')) kind = 'fading';
        else if (bg(s) || bg(m)) kind = 'standin:' + (bg(s) || bg(m)).replace(/^.*img-cache\/(\d+)\/.*$/, '$1w').replace(/^url\(.*$/, 'orig');
        else kind = 'grey';
      }
      if (t.kinds[t.kinds.length - 1]?.[1] !== kind) t.kinds.push([Math.round(now), kind]);
      if (kind === 'full') t.sharp = Math.round(now);
    }
    if (performance.now() < 90000) requestAnimationFrame(scan);
  };
  requestAnimationFrame(scan);
  addEventListener('pointerdown', (e) => {
    const th = e.target.closest && e.target.closest('.gthumb');
    if (th) window.__g.tap = { el: document.getElementById('gmainImg'), ts: e.timeStamp, wall: performance.timeOrigin + e.timeStamp, want: decodeURIComponent(th.dataset.image || '#none'), sharp: null, kinds: [], inp: null };
  }, true);
};

function startProxy([p, kbps, rtt]) {
  fs.mkdirSync(path.join(APP, 'storage/gx-logs'), { recursive: true });
  return spawn(process.execPath, [path.join(__dirname, 'pg2g-throttle.cjs'), String(p), String(kbps), String(rtt)], { stdio: ['ignore', 'ignore', fs.openSync(path.join(APP, 'storage/gx-logs/proxy-' + p + '.err'), 'a')] });
}
const ctl = async (page, what) => page.evaluate(async (u) => (await fetch(u)).json(), 'http://pg2g.control/' + what).catch(() => ({}));
// what reached the browser (the proxy's `sent`), so a cancelled download counts only what it used
const imgBytes = (s) => Object.entries(s.sent || {}).filter(([k]) => k.startsWith('image/')).reduce((a, [, v]) => a + v, 0);

async function newPage(browser, prof) {
  const ctx = await browser.newContext(prof.ctx);
  // NO ctx.route(): Playwright disables the HTTP cache whenever routing is on,
  // which hides exactly what this measures (a warmed file found in the cache).
  // The product page loads nothing from a third party.
  await ctx.addInitScript(INIT, ECT);
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  if (prof.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
  return { ctx, page, cdp };
}

async function tapRun(browser, profName, label, at, shotDir) {
  const prof = PROFILES[profName];
  const base = 'http://127.0.0.1:' + port(label);
  const { ctx, page, cdp } = await newPage(browser, prof);
  await page.goto(base + '/kbb-preview-id.txt');
  await ctl(page, 'reset');
  await page.goto(base + PRODUCT, { waitUntil: 'load', timeout: 180000 });
  const atLoad = imgBytes(await ctl(page, 'stats'));
  const loadT = await page.evaluate(() => performance.now());
  await page.waitForTimeout(at);
  const beforeTap = imgBytes(await ctl(page, 'stats'));
  const frames = [];
  {
    await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 85, everyNthFrame: 1, maxWidth: prof.ctx.viewport.width * (prof.ctx.deviceScaleFactor || 1), maxHeight: prof.ctx.viewport.height * (prof.ctx.deviceScaleFactor || 1) });
    cdp.on('Page.screencastFrame', (f) => { frames.push({ wall: f.metadata.timestamp * 1000, data: f.data }); cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {}); });
    await page.waitForTimeout(250);
  }
  const sel = '#gthumbs .gthumb:nth-child(' + (process.env.GX_TAP || 4) + ')';
  await page.locator(sel).scrollIntoViewIfNeeded();
  if (prof.ctx.hasTouch) await page.tap(sel); else await page.click(sel);
  await page.waitForFunction(() => window.__g.tap && window.__g.tap.sharp != null, null, { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(700);
  await cdp.send('Page.stopScreencast').catch(() => {});
  const box = await page.evaluate(() => { const r = document.getElementById('gmain').getBoundingClientRect(); return [r.x / innerWidth, r.y / innerHeight, r.width / innerWidth, r.height / innerHeight]; });
  const afterTap = imgBytes(await ctl(page, 'stats'));
  const r = await page.evaluate(([lt0, process_all]) => {
    const g = window.__g; const t = g.tap || {};
    return {
      lcp: Math.round(g.lcp), fcp: Math.round(g.fcp), cls: Math.round(g.cls * 1000) / 1000,
      tbt: g.lt.filter(([s]) => s < lt0 + 10000).reduce((a, [, d]) => a + Math.max(0, d - 50), 0),
      tapLong: g.lt.filter(([s, d]) => s >= t.ts && s < t.ts + 2000 && d > 50).length,
      inp: t.inp, sharp: t.sharp, kinds: t.kinds, wall: t.wall,
      standinPx: (() => { const s = document.querySelector('#gmain .gx-s'); return s ? s.style.backgroundImage : null; })(),
      warmed: performance.getEntriesByType('resource').filter((e) => (process_all || /\.(jpe?g|png|webp)/.test(e.name)) && e.startTime > lt0).map((e) => [e.initiatorType, e.name.replace(location.origin, '').slice(-46), Math.round(e.startTime - lt0), Math.round(e.responseEnd - lt0), e.encodedBodySize, e.transferSize]),
    };
  }, [loadT, Boolean(process.env.GX_ALLRES)]);
  if (shotDir && r.wall) {
    fs.mkdirSync(shotDir, { recursive: true });
    for (const ms of [50, 150, 300, 600, 1000]) {
      const want = r.wall + ms;
      let pick = null;
      for (const f of frames) if (f.wall <= want) pick = f;
      if (pick) fs.writeFileSync(path.join(shotDir, String(ms).padStart(4, '0') + 'ms.jpg'), Buffer.from(pick.data, 'base64'));
    }
  }
  await ctx.close();
  Object.assign(r, await pixels(browser, frames, box, r.wall));
  return Object.assign(r, { kbAtLoad: Math.round(atLoad / 1024), kbIdle: Math.round((beforeTap - atLoad) / 1024), kbTap: Math.round((afterTap - beforeTap) / 1024), kbTotal: Math.round(afterTap / 1024) });
}

/* What the frame SHOWED, read off the screencast's own pixels (not the DOM):
   firstChange -- the first frame after the tap whose gallery box differs from
   the last frame before it; settled -- the first frame from which the box
   stays equal to the final one; plus what each of 50/150/300/600 ms showed:
   'old', 'grey' (flat, no picture), 'final', or 'other' (a stand-in). */
async function pixels(browser, frames, box, wall) {
  if (!wall || frames.length < 2) return {};
  const ctx = await browser.newContext();
  const pg = await ctx.newPage();
  const stats = await pg.evaluate(async ({ datas, box }) => {
    const out = [];
    const c = document.createElement('canvas'); const g = c.getContext('2d', { willReadFrequently: true });
    let ref = null;
    for (const d of datas) {
      const img = new Image(); img.src = 'data:image/jpeg;base64,' + d; await img.decode();
      // 200x200 device pixels of the photograph's coloured band (left of the
      // seeded bottle, 40% down the box), at 1:1
      const cx = Math.round((box[0] + box[2] * 0.2) * img.width), cy = Math.round((box[1] + box[3] * 0.4) * img.height);
      c.width = 200; c.height = 200; g.drawImage(img, cx - 100, cy - 100, 200, 200, 0, 0, 200, 200);
      const px = g.getImageData(0, 0, 200, 200).data;
      const lum = new Float32Array(40000);
      for (let i = 0; i < 40000; i++) lum[i] = 0.3 * px[i * 4] + 0.59 * px[i * 4 + 1] + 0.11 * px[i * 4 + 2];
      let sharp = 0; for (let yy = 0; yy < 200; yy++) for (let xx = 1; xx < 200; xx++) sharp += Math.abs(lum[yy * 200 + xx] - lum[yy * 200 + xx - 1]);
      let mn = 255, mx = 0; for (const v of lum) { if (v < mn) mn = v; if (v > mx) mx = v; }
      let chroma = 0; for (let i = 0; i < 40000; i += 7) chroma += Math.max(px[i * 4], px[i * 4 + 1], px[i * 4 + 2]) - Math.min(px[i * 4], px[i * 4 + 1], px[i * 4 + 2]);
      out.push({ lum: Array.from(lum.filter((_, i) => i % 7 === 0)), sharp: sharp / 39800, range: mx - mn, chroma: chroma / (40000 / 7) });
    }
    return out;
  }, { datas: frames.map((f) => f.data), box });
  await ctx.close();
  const diff = (a, b) => { let t = 0; for (let i = 0; i < a.lum.length; i++) t += Math.abs(a.lum[i] - b.lum[i]); return t / a.lum.length; };
  const ts = frames.map((f) => f.wall - wall);
  let pre = 0; for (let i = 0; i < ts.length; i++) if (ts[i] <= 0) pre = i;
  const fin = stats.length - 1;
  const kind = (i) => diff(stats[i], stats[pre]) < 1.5 ? 'old' : stats[i].chroma < 8 ? (stats[i].range < 12 && stats[i].lum[0] > 252 ? 'white' : 'grey') : stats[i].sharp >= 0.8 * stats[fin].sharp ? 'sharp' : 'blur' + Math.round(100 * stats[i].sharp / stats[fin].sharp) + '%';
  let firstChange = null, settled = null;
  for (let i = pre + 1; i < stats.length; i++) if (firstChange == null && kind(i) !== 'old') firstChange = Math.round(ts[i]);
  for (let i = pre + 1; i < stats.length; i++) if (settled == null && kind(i) === 'sharp') settled = Math.round(ts[i]);
  const at = {};
  for (const ms of [50, 150, 300, 600, 1000]) {
    let k = pre; for (let i = 0; i < ts.length; i++) if (ts[i] <= ms) k = i;
    at[ms] = kind(k);
  }
  const seq = []; for (let i = pre; i < stats.length; i++) { const kk = kind(i); if (!seq.length || seq[seq.length - 1][1] !== kk) seq.push([Math.round(ts[i]), kk]); }
  return { firstChange, settled, at, seq };
}

// listing: a category in the laptop's menu row; on a phone the product page
// shows no category link without opening the menu, so its brand link (a
// listing page all the same).
const TARGETS = { home: 'a[href="/"]', listing: 'a.navlink[href^="/collections/"], .crumb a[href^="/collections/"], a[href^="/brands/spd-"]', product: 'a[href^="/product/"]:not([href="' + PRODUCT + '"])' };

async function navRun(browser, profName, label, delay, target) {
  const prof = PROFILES[profName];
  const base = 'http://127.0.0.1:' + port(label);
  const { ctx, page, cdp } = await newPage(browser, prof);
  try {
  await page.goto(base + PRODUCT, { waitUntil: 'load', timeout: 180000 });
  // the link: the first VISIBLE one of its kind on screen without scrolling the gallery away
  const href = await page.evaluate((s) => {
    for (const a of document.querySelectorAll(s)) { const r = a.getBoundingClientRect(); if (r.width > 20 && r.height > 8 && r.top >= 0 && r.bottom <= innerHeight && getComputedStyle(a).visibility !== 'hidden') return a.getAttribute('href'); }
    for (const a of document.querySelectorAll(s)) { const r = a.getBoundingClientRect(); if (r.width > 20 && r.height > 8 && getComputedStyle(a).visibility !== 'hidden') return a.getAttribute('href'); }
    return null;
  }, TARGETS[target]);
  const link = page.locator('a[href="' + href + '"]').filter({ visible: true }).first();
  await link.scrollIntoViewIfNeeded();
  await page.waitForTimeout(delay);
  const box = await link.boundingBox();
  const x = box.x + box.width / 2; const y = box.y + Math.min(box.height / 2, 20);
  let t0;
  if (prof.ctx.hasTouch) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await page.waitForTimeout(90);
    t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  } else {
    await page.mouse.move(x, y);
    await page.waitForTimeout(200);
    t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
    await page.mouse.down(); await page.waitForTimeout(60); await page.mouse.up();
  }
  await page.waitForURL((u) => u.pathname === href, { timeout: 60000 });
  await page.waitForFunction(() => window.__g && window.__g.fcp != null, null, { timeout: 60000 }).catch(() => {});
  const r = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    const slow = performance.getEntriesByType('resource').filter((e) => e.duration > 1500).map((e) => [e.name.replace(location.origin, '').slice(-50), Math.round(e.startTime), Math.round(e.duration)]);
    return { origin: performance.timeOrigin, fcp: window.__g.fcp, pre: n.deliveryType, nav: [n.fetchStart, n.responseStart, n.responseEnd, n.domInteractive].map(Math.round), slow };
  });
  const click = r.fcp == null ? null : Math.round(r.origin + r.fcp - t0);
  return Object.assign({ click, prefetched: r.pre === 'navigational-prefetch', href }, click > 1500 ? { originLag: Math.round(r.origin - t0), nav: r.nav, slow: r.slow } : {});
  } finally {
    // a context left open keeps loading -- on the SAME throttled line as the
    // next run, which is how one failure poisons every number after it
    await ctx.close().catch(() => {});
  }
}

let procs = [];
const reap = () => { for (const c of procs) try { c.kill(); } catch (e) { /* gone */ } };
process.on('exit', reap);
process.on('SIGTERM', () => { reap(); process.exit(143); });
(async () => {
  procs = PROFS.map((p) => startProxy(PROFILES[p].proxy));
  await new Promise((r) => setTimeout(r, 600));
  const browsers = {};
  for (const p of PROFS) browsers[p] = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--proxy-server=http://127.0.0.1:' + PROFILES[p].proxy[0], '--proxy-bypass-list=<-loopback>'] });
  const out = {};
  const push = (k, r) => { (out[k] = out[k] || []).push(r); process.stderr.write(k + ' ' + JSON.stringify(r) + '\n'); };
  for (let i = 0; i < N; i++) {
    for (const p of PROFS) {
      if (MODE === 'tap') {
        for (const at of (process.env.GX_AT || 'early,late').split(',')) for (const l of labels) {
          const ms = at === 'early' ? 600 : 7000;
          push([l, p, at].join(' '), await tapRun(browsers[p], p, l, ms, SHOTS && i === 0 ? path.join(SHOTS, l + '-' + p + '-' + at) : ''));
        }
      } else {
        for (const target of (process.env.GX_TARGETS || 'home,listing,product').split(',')) for (const d of (process.env.GX_DELAYS || '1000,2000,4000').split(',').map(Number)) for (const l of labels) {
          push([l, p, target, d].join(' '), await navRun(browsers[p], p, l, d, target).catch((e) => ({ click: null, error: String(e.message).slice(0, 80) })));
        }
      }
    }
  }
  const summary = {};
  for (const [k, runs] of Object.entries(out)) {
    const pick = (f) => median(runs.map(f));
    summary[k] = MODE === 'tap'
      ? { lcp: pick((r) => r.lcp), cls: Math.max(...runs.map((r) => r.cls)), tbt: pick((r) => r.tbt), kbAtLoad: pick((r) => r.kbAtLoad), kbIdle: pick((r) => r.kbIdle), kbTap: pick((r) => r.kbTap), kbTotal: pick((r) => r.kbTotal), inp: pick((r) => r.inp), sharp: pick((r) => r.sharp), firstChange: pick((r) => r.firstChange), settled: pick((r) => r.settled), at: runs.map((r) => r.at && Object.values(r.at).join('/')).join(' | '), tapLong: Math.max(...runs.map((r) => r.tapLong)), kinds: runs[0].kinds }
      : { click: pick((r) => r.click), prefetched: runs.filter((r) => r.prefetched).length + '/' + runs.length, href: runs[0].href };
  }
  console.log(JSON.stringify({ summary, runs: out }, null, 1));
  for (const b of Object.values(browsers)) await b.close();
  for (const c of procs) c.kill();
})();
