/*
 * Lane LZ: the owner's real path -- a page of the shop is open, the shopper
 * points at a link (InstantNav prefetches the HTML) and taps it. Measured on
 * the NEW page, from the tap: first paint, the moment every picture in its
 * first viewport is visible, and the gap between the two -- the window in
 * which the page is on screen with empty picture frames ("the images keep
 * showing time to time"). Same network as lz-film.cjs; pictures cold.
 *   node tools/lz-nav.cjs LABEL[,LABEL] FROM>LINKSELECTOR[,...] N > out.json
 * Harness-only JavaScript reads the page; nothing here ships.
 */
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const APP = path.dirname(__dirname);
const labels = process.argv[2].split(',');
const ROUTES = process.argv[3].split(',').map((r) => r.split('>'));
const N = Number(process.argv[4] || 3);
const PROFS = (process.env.LZ_PROF || 'phone,laptop').split(',');
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };
const PROFILES = {
  phone: { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, serviceWorkers: 'block' }, cpu: 4, proxy: 9491 },
  laptop: { ctx: { viewport: { width: 1280, height: 860 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false, serviceWorkers: 'block' }, cpu: 1, proxy: 9492 },
};
const INIT = () => {
  window.__z = { ready: new Map() };
  const shown = (img) => { if (!img.complete || img.naturalWidth === 0) return false; for (let el = img; el && el.nodeType === 1; el = el.parentElement) { const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) < 0.99) return false; } return true; };
  const scan = () => { for (const img of document.images) if (!window.__z.ready.has(img) && shown(img)) window.__z.ready.set(img, performance.now()); if (performance.now() < 30000) requestAnimationFrame(scan); };
  requestAnimationFrame(scan);
};
(async () => {
  const procs = PROFS.map((pr) => spawn(process.execPath, [path.join(__dirname, 'pg2g-throttle.cjs'), String(PROFILES[pr].proxy), '9000', '150'], { stdio: 'ignore' }));
  await new Promise((r) => setTimeout(r, 600));
  const browsers = {};
  for (const pr of PROFS) browsers[pr] = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--proxy-server=http://127.0.0.1:' + PROFILES[pr].proxy, '--proxy-bypass-list=<-loopback>'] });
  const out = {};
  try {
    for (let i = 0; i < N; i++) for (const pr of PROFS) for (const [from, sel] of ROUTES) for (const l of labels) {
      const prof = PROFILES[pr];
      const ctx = await browsers[pr].newContext(prof.ctx);
      await ctx.addInitScript(INIT);
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 120)); });
      const cdp = await ctx.newCDPSession(page);
      if (prof.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
      await page.goto('http://127.0.0.1:' + port(l) + from, { waitUntil: 'load', timeout: 120000 });
      await page.waitForTimeout(2500);
      const link = page.locator(sel).first();
      await link.scrollIntoViewIfNeeded();
      const href = await link.getAttribute('href');
      await link.hover().catch(() => {});
      await page.waitForTimeout(400);             // pointer rests: InstantNav's prefetch
      const prefetched = await page.evaluate((h) => performance.getEntriesByType('resource').some((e) => e.name.endsWith(h) || e.name.includes(h)), href);
      await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 120000 }), prof.ctx.hasTouch ? link.tap() : link.click()]);
      await page.waitForTimeout(1500);
      const r = await page.evaluate(() => {
        const vh = innerHeight, vw = innerWidth;
        const clip = (img) => { const r = img.getBoundingClientRect(); let t = r.top, le = r.left, bo = r.bottom, ri = r.right; for (let el = img.parentElement; el && el !== document.body; el = el.parentElement) { const cs = getComputedStyle(el); if (cs.display !== 'contents' && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) { const c = el.getBoundingClientRect(); t = Math.max(t, c.top); le = Math.max(le, c.left); bo = Math.min(bo, c.bottom); ri = Math.min(ri, c.right); } } return { top: t, left: le, bottom: bo, right: ri, width: Math.max(0, ri - le), height: Math.max(0, bo - t) }; };
        const ts = [];
        for (const img of document.images) {
          const b = clip(img);
          if (b.width < 8 || b.height < 8 || b.top >= vh || b.bottom <= 0 || b.left >= vw || b.right <= 0) continue;
          let vis = true; for (let el = img; el && el.nodeType === 1; el = el.parentElement) { const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) < 0.99) { vis = false; break; } }
          if (vis) ts.push(window.__z.ready.has(img) ? window.__z.ready.get(img) : null);
        }
        const fcp = (performance.getEntriesByName('first-contentful-paint')[0] || {}).startTime;
        const nav = performance.getEntriesByType('navigation')[0];
        const done = ts.length && ts.every((t) => t != null) ? Math.max(...ts) : null;
        return { fcp: Math.round(fcp), ttfb: Math.round(nav.responseStart), done: done && Math.round(done), gap: done && fcp ? Math.round(done - fcp) : null, n: ts.length, url: location.pathname };
      });
      await ctx.close();
      const key = [l, pr, from + '>' + sel].join(' ');
      (out[key] = out[key] || []).push({ ...r, prefetched, errors });
      process.stderr.write(key + ' ' + JSON.stringify(r) + ' pf=' + prefetched + '\n');
    }
  } finally {
    for (const b of Object.values(browsers)) await b.close();
    for (const c of procs) c.kill();
  }
  const s = {};
  for (const [k, runs] of Object.entries(out)) s[k] = { url: runs[0].url, n: runs[0].n, ttfb: median(runs.map((r) => r.ttfb)), fcp: median(runs.map((r) => r.fcp)), done: median(runs.map((r) => r.done)), gap: median(runs.map((r) => r.gap)), prefetched: runs.every((r) => r.prefetched), errors: [...new Set(runs.flatMap((r) => r.errors))] };
  process.stdout.write(JSON.stringify(s, null, 1));
})();
