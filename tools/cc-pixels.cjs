/* Lane CC: first painted frame and loaded page, pixel by pixel, head (:8840) vs change (:8860).
 *   CC_PAGES=/ node tools/cc-pixels.cjs   (PNG files in storage/cc-logs/px) */
// Lane CC: is the opening homepage (inline grid sheet) pixel- and style-identical to today's?
// For each width: (a) computed style of every element + ::before/::after, head vs new;
// (b) the FIRST painted frame (CDP screencast, JS off, pictures held back so timing cannot differ)
// and (c) the loaded page, full height, compared pixel by pixel in a canvas.
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const fs = require('fs'); const OUT = require('path').join(__dirname, '..', 'storage/cc-logs/px'); fs.mkdirSync(OUT, { recursive: true });
const PAGES = (process.env.CC_PAGES || '/').split(',');
const A = 'http://127.0.0.1:' + (process.env.CC_A || 8840), B = 'http://127.0.0.1:' + (process.env.CC_B || 8860);
async function grab(browser, base, path, w, js) {
  // "js=false" = the page's own scripts removed from the document (both sides alike), so nothing
  // timed (the slider, lazy loaders) can make two renders differ; Playwright's evaluate needs JS on.
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 800 ? 844 : 800 }, deviceScaleFactor: 1 });
  if (!js) await ctx.route((u) => u.pathname === path, async (r) => { const res = await r.fetch({ headers: { ...r.request().headers(), 'sec-fetch-site': 'none' } }); const body = (await res.text()).replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ''); r.fulfill({ response: res, body }); });
  await ctx.route(/\.(webp|jpe?g|png|gif|avif|mp4)(\?|$)/, (r) => r.abort());
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
  const page = await ctx.newPage(); const cdp = await ctx.newCDPSession(page); const t0 = Date.now(); const log = (m) => console.error(`[${base.slice(-4)} ${path} ${w} js=${js}] ${m} +${Date.now() - t0}ms`);
  const frames = []; cdp.on('Page.screencastFrame', async (f) => { frames.push(f.data); try { await cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }); } catch (e) {} });
  await cdp.send('Page.startScreencast', { format: 'png', everyNthFrame: 1 });
  log('goto'); await page.goto(base + path, { waitUntil: 'load', timeout: 60000 }); await page.waitForTimeout(1200);
  log('loaded'); await cdp.send('Page.stopScreencast'); log('stopped');
  const html = await page.content();
  const inline = html.includes('kbb-css-inline'), link = /kbb-grid-skins-[\w-]+\.css/.test(html);
  await page.addStyleTag({ content: '*,*::before,*::after{animation-play-state:paused!important;animation-delay:0s!important;animation-duration:0s!important;transition:none!important;caret-color:transparent!important}' }).catch(() => {}); await page.waitForTimeout(300);
  const styles = [];
  log('styles ' + styles.length); const shot = (await page.screenshot({ fullPage: true, timeout: 60000 })).toString('base64'); log('shot');
  await ctx.close();
  return { frames, nFrames: frames.length, shot, styles, inline, link };
}
async function firstPainted(page, frames) { // index of the first screencast frame that is not a blank page
  for (let i = 0; i < frames.length; i++) { const n = await page.evaluate(async (d) => { const i = await new Promise((r) => { const m = new Image(); m.onload = () => r(m); m.src = 'data:image/png;base64,' + d; });
      const cv = document.createElement('canvas'); cv.width = i.width; cv.height = i.height; const g = cv.getContext('2d'); g.drawImage(i, 0, 0); const p = g.getImageData(0, 0, i.width, i.height).data; let n = 0; for (let k = 0; k < p.length; k += 4) if (p[k] < 245 || p[k + 1] < 245 || p[k + 2] < 245) n++; return n; }, frames[i]);
    if (n > 500) return frames[i]; }
  return frames[frames.length - 1];
}
async function diff(page, a, b) { // pixel compare two PNG base64 in a canvas
  return page.evaluate(async ([a, b]) => { const load = (d) => new Promise((r) => { const i = new Image(); i.onload = () => r(i); i.src = 'data:image/png;base64,' + d; });
    const [x, y] = await Promise.all([load(a), load(b)]); if (x.width !== y.width || x.height !== y.height) return { size: [x.width, x.height, y.width, y.height], diff: -1 };
    const c = (i) => { const cv = document.createElement('canvas'); cv.width = i.width; cv.height = i.height; const g = cv.getContext('2d'); g.drawImage(i, 0, 0); return g.getImageData(0, 0, i.width, i.height).data; };
    const p = c(x), q = c(y); let n = 0, x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1; for (let k = 0; k < p.length; k += 4) if (p[k] !== q[k] || p[k + 1] !== q[k + 1] || p[k + 2] !== q[k + 2]) { n++; const i = k / 4, X = i % x.width, Y = (i / x.width) | 0; x0 = Math.min(x0, X); y0 = Math.min(y0, Y); x1 = Math.max(x1, X); y1 = Math.max(y1, Y); } return { size: [x.width, x.height], diff: n, box: n ? [x0, y0, x1, y1] : null }; }, [a, b]);
}
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const tool = await (await browser.newContext()).newPage();
  const res = [];
  for (const path of PAGES) for (const w of [390, 1280]) {
    const h = await grab(browser, A, path, w, false), n = await grab(browser, B, path, w, false);
    const hj = await grab(browser, A, path, w, true), nj = await grab(browser, B, path, w, true);
    const tag = (path === '/' ? 'home' : path.replace(/\W+/g, '-')) + '-' + w;
    h.first = await firstPainted(tool, h.frames); n.first = await firstPainted(tool, n.frames);
    fs.writeFileSync(`${OUT}/${tag}-first-head.png`, Buffer.from(h.first, 'base64')); fs.writeFileSync(`${OUT}/${tag}-first-new.png`, Buffer.from(n.first, 'base64'));
    fs.writeFileSync(`${OUT}/${tag}-load-head.png`, Buffer.from(hj.shot, 'base64')); fs.writeFileSync(`${OUT}/${tag}-load-new.png`, Buffer.from(nj.shot, 'base64'));
    res.push({ path, w, headLinked: h.link, newInline: n.inline && !n.link,
      firstPaint: await diff(tool, h.first, n.first), firstPaintJs: await (async () => { const a = await firstPainted(tool, hj.frames), c = await firstPainted(tool, nj.frames); fs.writeFileSync(`${OUT}/${tag}-firstjs-head.png`, Buffer.from(a, 'base64')); fs.writeFileSync(`${OUT}/${tag}-firstjs-new.png`, Buffer.from(c, 'base64')); return diff(tool, a, c); })(), loadNoJs: await diff(tool, h.shot, n.shot), loadJs: await diff(tool, hj.shot, nj.shot),
      framesHead: h.nFrames, framesNew: n.nFrames });
    console.log(JSON.stringify(res[res.length - 1]));
  }
  fs.writeFileSync(`${OUT}/pixels.json`, JSON.stringify(res, null, 1));
  await browser.close();
})();
