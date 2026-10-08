/*
 * Lane PG2 + Lane GX: the product gallery's loading behaviour, RUN in Chromium.
 *
 *   node tools/pg2g-gallery-unit.cjs [/path/to/chromium]
 *
 * tests/Feature/GalleryLoadingPlaceholderTest.php runs this. It serves the
 * real resources/js/kbb/pdp.js (initGallery) and the real kbb.css and
 * kbb-product.css to a page carrying the gallery's markup, with photographs
 * whose arrival this server controls, and checks what the shopper SEES.
 * navigator.connection is set per page: none (Safari/Firefox: no API), '4g',
 * or Save-Data.
 *
 *   1. While the main photo has not arrived, its box is the frame's WHITE, as
 *      before PG2 -- the grey box is for the 2nd photo onwards only (the owner,
 *      8 October; Lane LZ) -- and no alt text is painted.
 *   2. Once it is decoded the grey is handed back to the frame (.ld).
 *   3. (GX) A tap on a shot whose large file is slow shows the GREY loading
 *      box -- never the old photo and never the thumbnail's own small file
 *      stretched -- then the large file fades in and the stand-in goes.
 *   4. A tap on a thumbnail whose own picture has not arrived shows the grey
 *      box, not the old photo.
 *   5. No connection API: after the page loads, the NEXT shot's large file is
 *      fetched once (warm), nothing beyond it; hovering another thumbnail
 *      fetches its file.
 *   6. A main photo that FAILS paints no alt text.
 *   7. (GX) The mid copy: a finger on a thumbnail asks for its 400w copy; if
 *      it is in by the tap it is the stand-in at once, if it lands after the
 *      tap it takes over the grey box; nothing asks for it before a finger.
 *   8. (GX) '4g': once the page has loaded AND the main photo is decoded, the
 *      whole gallery is fetched ONE file at a time, by fetch(), the frame's
 *      own srcset candidate; a tap afterwards paints the photograph in the
 *      first frame with no stand-in at all, and downloads nothing.
 *   9. (GX) '4g': a finger on a LINK cancels the file in flight (the server
 *      sees the connection close) and nothing more is fetched. A pointer
 *      RESTING on a link (when instant navigation fetches the next page)
 *      cancels it too and holds the warm-up; moving off lets it carry on.
 *  10. (GX) Save-Data: nothing is fetched after load and no mid copy on a
 *      finger -- only what is tapped.
 *  11. (GX) Reduced motion: no fade; the stand-in goes the moment the
 *      photograph is in.
 *
 * Exit 0 and "ok N" when every check holds; otherwise exit 1, one line each.
 */
const http = require('http');
const path = require('path');
const fs = require('fs');
const zlib = require('zlib');

const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const CHROME = process.argv[2] || process.env.KBB_BROWSER_CHROME || '/opt/pw-browsers/chromium';

const fails = [];
let checks = 0;
const ok = (cond, msg) => { checks++; if (!cond) fails.push(msg); };

/* A solid-colour PNG, so no image library is needed. */
function png(size, [r, g, b]) {
  const crc = (buf) => { let c = ~0; for (const x of buf) { c ^= x; for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xEDB88320 & -(c & 1)); } return ~c >>> 0; };
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.alloc(1 + size * 3); for (let x = 0; x < size; x++) { row[1 + x * 3] = r; row[2 + x * 3] = g; row[3 + x * 3] = b; }
  const raw = Buffer.concat(Array.from({ length: size }, () => row));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const COLOURS = [[220, 40, 40], [40, 160, 60], [40, 70, 210], [230, 180, 20]];
/* The 400w copy of each shot is a DIFFERENT colour, so the frame says which
   file is standing in: the thumbnail's own (COLOURS), the mid (MIDS) or grey. */
const MIDS = [[250, 120, 200], [120, 240, 230], [150, 60, 120], [90, 60, 20]];
const BIG = COLOURS.map((c) => png(600, c));
const SMALL = COLOURS.map((c) => png(66, c));
const MID = MIDS.map((c) => png(200, c));

/* How long each file is held, ms, per page (?p=). */
const HOLDS = {
  a: { 'big-0': 1200, 'big-1': 0, 'big-2': 2500, 'big-3': 2500, 'mid-2': 0, 'mid-3': 4000 },
  hang: { 'big-3': 2500, 'mid-3': 30000 },
  midlate: { 'big-2': 2500, 'mid-2': 700 },
  g4: { 'big-1': 300, 'big-2': 300, 'big-3': 300 },
  leave: { 'big-1': 4000, 'big-2': 4000, 'big-3': 4000 },
  rest: { 'big-1': 4000, 'big-2': 300, 'big-3': 300 },
  sd: { 'big-2': 1500 },
  rm: { 'big-2': 900 },
};
const asked = [];

const PAGE = (p) => `<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/css/kbb.css"><link rel="stylesheet" href="/css/kbb-product.css">
<style>body{margin:0}.gallery{position:static;width:400px;padding:10px}</style></head><body>
<div class="gallery">
  <div class="gmain" id="gmain" style="background:#fff">
    <img class="gmain-img" id="gmainImg" src="/img/big-0.png?p=${p}" alt="AXIS - Y - Dark Spot Correcting Glow Serum" width="1000" height="1000" loading="eager" decoding="async" fetchpriority="high">
    <span class="cap" id="gcap" data-brand="B" hidden>B<br>Front</span>
  </div>
  <div class="gthumbs" id="gthumbs">
    ${[0, 1, 2, 3].map((i) => {
      // Shot 1 carries a real srcset for the FRAME, so the warm-up has to ask
      // the browser which candidate it will take (600w for this 400px frame).
      const frame = i === 1 ? `/img/big-1.png?p=${p}&w=300 300w, /img/big-1.png?p=${p} 600w` : '';
      return `<div class="gthumb${i === 0 ? ' on' : ''}" data-i="${i}" data-image="/img/big-${i}.png?p=${p}" data-srcset="${frame}" data-sizes="${frame ? '400px' : ''}" data-label="" data-alt="alt ${i}" style="background:#fff"><img class="gthumb-img" src="/img/t-${i}.png?p=${p}${p === 'hang' && i === 3 ? '&hang=1' : ''}" srcset="/img/t-${i}.png?p=${p}${p === 'hang' && i === 3 ? '&hang=1' : ''} 200w, /img/mid-${i}.png?p=${p} 400w" sizes="66px" alt="alt ${i}" width="66" height="66" loading="eager" fetchpriority="low" decoding="async"></div>`;
    }).join('')}
  </div>
  <a id="away" href="/elsewhere" style="display:block;width:100px;height:30px">away</a>
</div>
<div id="broken" class="gallery"><div class="gmain" style="background:#fff"><img class="gmain-img" src="/img/missing.png" alt="A FAILED PHOTO TITLE" width="1000" height="1000"></div></div>
<script>
  window.__seen = [];
  new MutationObserver((l) => { for (const m of l) for (const n of m.addedNodes) if (n.nodeType === 1) window.__seen.push(String(n.className)); })
    .observe(document.getElementById('gmain'), { childList: true });
</script>
<script type="module">import { initGallery } from '/js/pdp.js'; initGallery(); window.__ready = true;</script>
</body></html>`;

const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html' }); return res.end(PAGE(u.searchParams.get('p') || 'a')); }
  if (u.pathname.startsWith('/css/')) { res.writeHead(200, { 'Content-Type': 'text/css' }); return res.end(fs.readFileSync(path.join(APP, 'resources/css/kbb', path.basename(u.pathname)))); }
  if (u.pathname.startsWith('/js/')) {
    const f = path.join(APP, 'resources/js/kbb', path.basename(u.pathname));
    if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': 'text/javascript' }); return res.end(fs.readFileSync(f));
  }
  const m = u.pathname.match(/^\/img\/(big|t|mid)-(\d)\.png$/);
  if (!m) { res.writeHead(404); return res.end(); }
  const key = m[1] + '-' + m[2];
  const p = u.searchParams.get('p') || 'a';
  const rec = { key, p, w: u.searchParams.get('w'), dest: req.headers['sec-fetch-dest'] || '', t: Date.now(), done: null, closed: false };
  asked.push(rec);
  res.on('close', () => { if (rec.done == null) rec.closed = true; });
  setTimeout(() => {
    if (rec.closed) return;
    res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'max-age=3600' });
    rec.done = Date.now();
    res.end((m[1] === 'big' ? BIG : m[1] === 'mid' ? MID : SMALL)[Number(m[2])]);
  }, u.searchParams.has('hang') ? 30000 : ((HOLDS[p] || {})[key] || 0));
});

/* The colour painted at a point of the page, read from a real screenshot. */
async function colourAt(page, cdp, x, y) {
  const shot = await cdp.send('Page.captureScreenshot', { format: 'png', clip: { x, y, width: 1, height: 1, scale: 1 } });
  return page.evaluate(async (b64) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
    const c = document.createElement('canvas'); c.width = 1; c.height = 1; const g = c.getContext('2d'); g.drawImage(img, 0, 0);
    return Array.from(g.getImageData(0, 0, 1, 1).data.slice(0, 3));
  }, shot.data);
}
const near = (a, b, tol = 30) => a && b && a.every((v, i) => Math.abs(v - b[i]) <= tol);
const greyish = (c) => c && Math.max(...c) - Math.min(...c) <= 12 && c[0] >= 215 && c[0] <= 245;
const of = (p, key) => asked.filter((a) => a.p === p && a.key === key);

server.listen(0, '127.0.0.1', async () => {
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ executablePath: CHROME });
  // conn: null = no API at all, as in Safari and Firefox.
  const open = async (p, conn, opts = {}) => {
    const ctx = await browser.newContext(Object.assign({ viewport: { width: 420, height: 900 } }, opts));
    await ctx.addInitScript((c) => {
      Object.defineProperty(navigator, 'connection', { configurable: true, get: () => (c ? Object.assign({ addEventListener() {} }, c) : undefined) });
    }, conn || null);
    const page = await ctx.newPage();
    const cdp = await ctx.newCDPSession(page);
    page.goto(base + '/?p=' + p).catch(() => {});
    await page.waitForFunction(() => window.__ready === true, null, { timeout: 10000 });
    return { page, cdp };
  };
  const settled = (page) => page.waitForFunction(() => document.getElementById('gmainImg').classList.contains('ld'), null, { timeout: 10000 });
  try {
    const { page, cdp } = await open('a');

    // 1. big-0 is held for 1.2 s: the main frame is the frame's white (Lane LZ:
    //    the grey box is for the 2nd photo onwards only), and no text is painted.
    const box = await page.evaluate(() => { const r = document.getElementById('gmain').getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
    const mid = [box.x + box.w / 2, box.y + box.h / 2];
    const first = await colourAt(page, cdp, mid[0], mid[1]);
    ok(first && Math.min(...first) >= 250, '1. the FIRST photo shows a loading box (the owner: "only and only for images 2nd and onwards"): ' + JSON.stringify(first));
    ok(await page.evaluate(() => getComputedStyle(document.getElementById('gmainImg')).color === 'rgba(0, 0, 0, 0)'), '1. the main photo would paint its alt text (color is not transparent)');

    // 2. Once decoded: the red photo, and .ld.
    await settled(page).catch(() => {});
    ok(await page.evaluate(() => document.getElementById('gmainImg').classList.contains('ld')), '2. the main photo never settled (.ld) after it decoded');
    ok(near(await colourAt(page, cdp, mid[0], mid[1]), COLOURS[0]), '2. the main photo is not on screen once it arrived');
    ok(await page.evaluate(() => [...document.querySelectorAll('.gthumb-img')].slice(0, 3).every((i) => i.classList.contains('ld'))), '2. the loaded thumbnails never settled (.ld)');

    // 5. No connection API: the next shot (big-1) once, after load; nothing more.
    await page.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
    await page.waitForTimeout(300);
    ok(of('a', 'big-1').length === 1, '5. the next shot was not warmed after the page loaded (asked ' + of('a', 'big-1').length + 'x)');
    ok(of('a', 'big-2').length === 0 && of('a', 'big-3').length === 0, '5. with no connection API a shot beyond the next one was fetched without being asked for');
    ok(of('a', 'mid-1').length + of('a', 'mid-2').length + of('a', 'mid-3').length === 0, '7. a mid copy was fetched before any finger asked for it');

    // 3 + 7. Shot 3 (index 2): its large file is held 2.5 s, its mid copy is
    // instant. A finger lands (pointerdown asks for the mid), then the tap.
    await page.hover('.gthumb[data-i="2"]');
    // The request is asserted once it has ARRIVED, not the instant after the
    // hover: under load it reached the test server a few ms later and this read
    // 0 (one run in three in the 2.60.434 suite). Still exactly one request.
    for (let t = 0; t < 40 && of('a', 'big-2').length === 0; t++) { await page.waitForTimeout(50); }
    ok(of('a', 'big-2').length === 1, '5. hovering a thumbnail did not start its large file');
    await page.dispatchEvent('.gthumb[data-i="2"] .gthumb-img', 'pointerdown', { bubbles: true });
    await page.waitForTimeout(250);
    ok(of('a', 'mid-2').length === 1, '7. a finger on a thumbnail did not ask for its 400w copy');
    await page.click('.gthumb[data-i="2"]');
    await page.waitForTimeout(120);
    const withMid = await colourAt(page, cdp, mid[0], mid[1]);
    ok(near(withMid, MIDS[2]), '7. a tap whose mid copy was in did not show it at once (old photo, grey or the stretched thumbnail instead): ' + JSON.stringify(withMid));
    ok(!near(withMid, COLOURS[0]), '3. a tap left the OLD photo in the frame: ' + JSON.stringify(withMid));
    await page.waitForFunction(() => { const i = document.getElementById('gmainImg'); return i.complete && i.naturalWidth === 600 && i.classList.contains('ld') && !document.querySelector('#gmain .gx-s'); }, null, { timeout: 8000 }).catch(() => {});
    ok(await page.evaluate(() => { const i = document.getElementById('gmainImg'); return i.complete && i.naturalWidth === 600 && i.classList.contains('ld') && !i.getAttribute('style'); }), '3. the large file never replaced the stand-in (or its background was left behind)');
    ok(await page.evaluate(() => document.querySelectorAll('#gmain .gmain-img').length === 1 && !document.querySelector('#gmain .gx-s')), '3. the stand-in was left in the frame after the photograph faded in');
    ok(await page.evaluate(() => getComputedStyle(document.getElementById('gmainImg')).transitionDuration === '0.14s'), '3. the photograph does not fade in (0.14s) over its stand-in');
    ok(near(await colourAt(page, cdp, mid[0], mid[1]), COLOURS[2]), '3. the large file is not on screen once it arrived');
    ok(await page.evaluate(() => document.getElementById('gmainImg').alt === 'alt 2'), '3. the swapped photo lost its alt text');

    // 3. NOT READY -> GREY, NEVER THE STRETCHED THUMBNAIL. Shot 4 (index 3):
    //    large file held 2.5 s and its mid copy 4 s (the click's own finger
    //    asks for it). Its 66px file IS loaded -- and must not be what the
    //    frame shows.
    await page.click('.gthumb[data-i="3"]');
    await page.waitForTimeout(150);
    const notReady = await colourAt(page, cdp, mid[0], mid[1]);
    ok(greyish(notReady), '3. a tap whose photograph is not in showed something other than the grey box (the stretched thumbnail is ' + JSON.stringify(COLOURS[3]) + '): ' + JSON.stringify(notReady));
    ok(await page.evaluate(() => { const s = document.querySelector('#gmain .gx-s'); return Boolean(s) && !s.style.backgroundImage; }), '3. the stand-in carries a picture when nothing sharper than the thumbnail was in');

    // 7. The mid copy lands AFTER the tap (700 ms) and before the photograph
    //    (2.5 s): it takes over the grey box.
    {
      const { page: pm, cdp: cm } = await open('midlate');
      await settled(pm);
      await pm.dispatchEvent('.gthumb[data-i="2"] .gthumb-img', 'pointerdown', { bubbles: true });
      await pm.click('.gthumb[data-i="2"]');
      await pm.waitForTimeout(150);
      ok(greyish(await colourAt(pm, cm, mid[0], mid[1])), '7. before the mid copy landed the frame was not the grey box');
      await pm.waitForTimeout(1100);
      const late = await colourAt(pm, cm, mid[0], mid[1]);
      ok(near(late, MIDS[2]), '7. a mid copy that landed after the tap did not take over the grey box: ' + JSON.stringify(late));
    }

    // 4. A thumbnail whose own picture never arrives: grey, not the old photo.
    {
      const { page: pb, cdp: cdpb } = await open('hang');
      await settled(pb);
      await pb.click('.gthumb[data-i="3"]');
      await pb.waitForTimeout(150);
      const four = await colourAt(pb, cdpb, mid[0], mid[1]);
      ok(greyish(four), '4. a tap on a thumbnail with no picture yet left the old photo up instead of the grey box: ' + JSON.stringify(four));
    }

    // 8. '4g': the whole gallery, one at a time, after load AND the main photo.
    {
      const { page: pg, cdp: cg } = await open('g4', { effectiveType: '4g', saveData: false });
      await pg.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
      // The page's OWN load moment, not the moment this poll noticed it: the
      // poll can trail the load event by tens of ms, and the warm-up starts AT
      // load, so a poll-time stamp read a correct warm-up as "before load"
      // (1 run in 3 in the 2.60.445 suite). Same machine, same epoch clock.
      const loadAt = await pg.evaluate(() => Math.floor(performance.timeOrigin + performance.getEntriesByType('navigation')[0].loadEventStart));
      await pg.waitForTimeout(2500);
      const warm = ['big-1', 'big-2', 'big-3'].map((k) => of('g4', k).filter((a) => a.dest === 'empty'));
      ok(warm.every((w) => w.length === 1), '8. the 4G warm-up did not fetch every shot exactly once by fetch(): ' + JSON.stringify(warm.map((w) => w.length)));
      const order = warm.map((w) => w[0]).filter(Boolean).sort((a, b) => a.t - b.t);
      ok(order.length === 3 && order.every((a, i) => i === 0 || a.t >= order[i - 1].done), '8. the 4G warm-up fetched two shots at once (it must be one at a time)');
      ok(order.length && order[0].t >= loadAt - 50 && order[0].key === 'big-1', '8. the 4G warm-up started before the page had loaded, or not with the next shot');
      ok(of('g4', 'big-1').every((a) => a.w === null), '8. the warm-up fetched a srcset candidate the frame would not take (' + JSON.stringify(of('g4', 'big-1').map((a) => a.w)) + ')');
      const before = asked.length;
      await pg.click('.gthumb[data-i="1"]');
      await pg.waitForTimeout(40);
      const first = await colourAt(pg, cg, mid[0], mid[1]);
      ok(near(first, COLOURS[1]), '8. after the warm-up, a tap did not paint the photograph straight away: ' + JSON.stringify(first));
      ok(!(await pg.evaluate(() => window.__seen.some((c) => /gx-s/.test(c)))), '8. after the warm-up, a tap still put a stand-in in the frame');
      ok(asked.slice(before).filter((a) => a.key === 'big-1').length === 0, '8. a warmed shot was downloaded again on the tap');
    }

    // 9. '4g': a finger on a link cancels the file in flight; nothing more.
    {
      const { page: pl } = await open('leave', { effectiveType: '4g', saveData: false });
      await pl.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
      await pl.waitForTimeout(800);
      ok(of('leave', 'big-1').filter((a) => a.dest === 'empty').length === 1, '9. the 4G warm-up never started (nothing to cancel)');
      await pl.dispatchEvent('#away', 'pointerdown', { bubbles: true });
      await pl.waitForTimeout(500);
      ok(of('leave', 'big-1').length > 0 && of('leave', 'big-1').every((a) => a.closed), '9. a finger on a link did not cancel the warm-up file in flight');
      await pl.waitForTimeout(800);
      ok(of('leave', 'big-2').length === 0 && of('leave', 'big-3').length === 0, '9. the warm-up went on after a finger landed on a link');
    }

    // 9. A pointer resting on a link: cancel and hold; moving off resumes.
    {
      const { page: pr } = await open('rest', { effectiveType: '4g', saveData: false });
      await pr.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
      await pr.waitForTimeout(800);
      await pr.hover('#away');
      await pr.waitForTimeout(500);
      ok(of('rest', 'big-1').length === 1 && of('rest', 'big-1')[0].closed, '9. a pointer resting on a link did not cancel the warm-up file in flight');
      await pr.waitForTimeout(700);
      ok(of('rest', 'big-2').length === 0, '9. the warm-up went on while a pointer rested on a link');
      await pr.mouse.move(5, 880);
      await pr.waitForTimeout(1500);
      ok(of('rest', 'big-1').length === 2 && of('rest', 'big-2').length === 0, '9. the warm-up did not carry on, with the dropped shot first and one at a time, once the pointer left the link (' + of('rest', 'big-1').length + 'x big-1, ' + of('rest', 'big-2').length + 'x big-2)');
    }

    // 10. Save-Data: nothing after load, no mid on a finger; a tap still works.
    {
      const { page: ps, cdp: cs } = await open('sd', { effectiveType: '4g', saveData: true });
      await ps.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
      await ps.waitForTimeout(800);
      ok(['big-1', 'big-2', 'big-3'].every((k) => of('sd', k).length === 0), '10. Save-Data: a shot was fetched without being tapped');
      await ps.dispatchEvent('.gthumb[data-i="2"] .gthumb-img', 'pointerdown', { bubbles: true });
      await ps.click('.gthumb[data-i="2"]');
      await ps.waitForTimeout(150);
      ok(of('sd', 'mid-2').length === 0, '10. Save-Data: a finger fetched a mid copy');
      ok(greyish(await colourAt(ps, cs, mid[0], mid[1])), '10. Save-Data: the tap did not show the grey box while the photograph loaded');
    }

    // 11. Reduced motion: no transition, the stand-in goes at once.
    {
      const { page: pr } = await open('rm', null, { reducedMotion: 'reduce' });
      await settled(pr);
      await pr.click('.gthumb[data-i="2"]');
      await pr.waitForFunction(() => { const i = document.getElementById('gmainImg'); return i.complete && i.naturalWidth === 600 && i.classList.contains('ld'); }, null, { timeout: 5000 }).catch(() => {});
      await pr.waitForTimeout(30);
      ok(await pr.evaluate(() => getComputedStyle(document.getElementById('gmainImg')).transitionDuration === '0s' && !document.querySelector('#gmain .gx-s')), '11. reduced motion: the photograph still faded, or the stand-in stayed');
    }

    // 6. A failed photo paints no text.
    const bx = await page.evaluate(() => { const r = document.querySelector('#broken .gmain').getBoundingClientRect(); return { x: r.x, y: r.y }; });
    await page.waitForTimeout(200);
    let dark = 0;
    for (let dx = 24; dx < 220; dx += 6) {   // past the 16px broken-image icon
      const c = await colourAt(page, cdp, bx.x + dx, bx.y + 10);
      if (c[0] < 120) dark++;
    }
    ok(dark === 0, '6. a failed photo painted its alt text (' + dark + ' dark pixels along its first line)');
  } catch (e) {
    fails.push('harness: ' + e.message);
  } finally {
    await browser.close();
    server.close();
  }
  if (fails.length) { console.log(fails.map((f) => 'FAIL ' + f).join('\n')); process.exit(1); }
  console.log('ok ' + checks);
  process.exit(0);
});
