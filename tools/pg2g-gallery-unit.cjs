/*
 * Lane PG2: the product gallery's loading behaviour, RUN in Chromium.
 *
 *   node tools/pg2g-gallery-unit.cjs [/path/to/chromium]
 *
 * tests/Feature/GalleryLoadingPlaceholderTest.php runs this. It serves the
 * real resources/js/kbb/pdp.js (initGallery) and the real kbb.css and
 * kbb-product.css to a page carrying the gallery's markup, with photographs
 * whose arrival this server controls, and checks what the shopper SEES:
 *
 *   1. While the main photo has not arrived, its box is the grey loading box
 *      (a background behind the <img>) and no alt text is painted.
 *   2. Once it is decoded the grey is handed back to the frame (.ld).
 *   3. A tap on a thumbnail whose large file is slow shows THAT thumbnail's
 *      picture in the main frame at once -- not the old photo -- and then the
 *      large file.
 *   4. A tap on a thumbnail whose own picture has not arrived shows the grey
 *      box, not the old photo.
 *   5. After the page loads, the NEXT shot's large file is fetched once
 *      (warm), and hovering another thumbnail fetches its file.
 *   6. A main photo that FAILS paints no alt text.
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
const BIG = COLOURS.map((c) => png(600, c));
const SMALL = COLOURS.map((c) => png(66, c));

/* How long each file is held, ms. -1 = never answered (a failed photo is a 404). */
const HOLD = { 'big-0': 1200, 'big-1': 0, 'big-2': 2500, 'big-3': 2500, 't-0': 0, 't-1': 0, 't-2': 0, 't-3': 0 };
const asked = [];

const PAGE = (hang) => `<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/css/kbb.css"><link rel="stylesheet" href="/css/kbb-product.css">
<style>body{margin:0}.gallery{position:static;width:400px;padding:10px}</style></head><body>
<div class="gallery">
  <div class="gmain" id="gmain" style="background:#fff">
    <img class="gmain-img" id="gmainImg" src="/img/big-0.png" alt="AXIS - Y - Dark Spot Correcting Glow Serum" width="1000" height="1000" loading="eager" decoding="async" fetchpriority="high">
    <span class="cap" id="gcap" data-brand="B" hidden>B<br>Front</span>
  </div>
  <div class="gthumbs" id="gthumbs">
    ${[0, 1, 2, 3].map((i) => `<div class="gthumb${i === 0 ? ' on' : ''}" data-i="${i}" data-image="/img/big-${i}.png" data-srcset="" data-sizes="" data-label="" data-alt="alt ${i}" style="background:#fff"><img class="gthumb-img" src="/img/t-${i}.png${hang && i === 3 ? '?hang=1' : ''}" alt="alt ${i}" width="66" height="66" loading="eager" fetchpriority="low" decoding="async"></div>`).join('')}
  </div>
</div>
<div id="broken" class="gallery"><div class="gmain" style="background:#fff"><img class="gmain-img" src="/img/missing.png" alt="A FAILED PHOTO TITLE" width="1000" height="1000"></div></div>
<script type="module">import { initGallery } from '/js/pdp.js'; initGallery(); window.__ready = true;</script>
</body></html>`;

const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://x');
  if (u.pathname === '/') { res.writeHead(200, { 'Content-Type': 'text/html' }); return res.end(PAGE(u.searchParams.has('hang'))); }
  if (u.pathname.startsWith('/css/')) { res.writeHead(200, { 'Content-Type': 'text/css' }); return res.end(fs.readFileSync(path.join(APP, 'resources/css/kbb', path.basename(u.pathname)))); }
  if (u.pathname.startsWith('/js/')) {
    const f = path.join(APP, 'resources/js/kbb', path.basename(u.pathname));
    if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': 'text/javascript' }); return res.end(fs.readFileSync(f));
  }
  const m = u.pathname.match(/^\/img\/(big|t)-(\d)\.png$/);
  if (!m) { res.writeHead(404); return res.end(); }
  const key = m[1] + '-' + m[2];
  asked.push({ key, t: Date.now() });
  setTimeout(() => {
    res.writeHead(200, { 'Content-Type': 'image/png', 'Cache-Control': 'max-age=3600' });
    res.end((m[1] === 'big' ? BIG : SMALL)[Number(m[2])]);
  }, u.searchParams.has('hang') ? 30000 : (HOLD[key] || 0));
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

server.listen(0, '127.0.0.1', async () => {
  const base = 'http://127.0.0.1:' + server.address().port;
  const browser = await chromium.launch({ executablePath: CHROME });
  try {
    const page = await browser.newPage({ viewport: { width: 420, height: 900 } });
    const cdp = await page.context().newCDPSession(page);
    page.goto(base + '/').catch(() => {});
    await page.waitForFunction(() => window.__ready === true, null, { timeout: 10000 });

    // 1. big-0 is held for 1.2 s: the main frame is grey, and no text is painted.
    const box = await page.evaluate(() => { const r = document.getElementById('gmain').getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
    const mid = [box.x + box.w / 2, box.y + box.h / 2];
    ok(greyish(await colourAt(page, cdp, mid[0], mid[1])), '1. the main frame is not the grey loading box while its photo is on the way');
    ok(await page.evaluate(() => getComputedStyle(document.getElementById('gmainImg')).color === 'rgba(0, 0, 0, 0)'), '1. the main photo would paint its alt text (color is not transparent)');

    // 2. Once decoded: the red photo, and .ld.
    await page.waitForFunction(() => document.getElementById('gmainImg').classList.contains('ld'), null, { timeout: 8000 }).catch(() => {});
    ok(await page.evaluate(() => document.getElementById('gmainImg').classList.contains('ld')), '2. the main photo never settled (.ld) after it decoded');
    ok(near(await colourAt(page, cdp, mid[0], mid[1]), COLOURS[0]), '2. the main photo is not on screen once it arrived');
    ok(await page.evaluate(() => [...document.querySelectorAll('.gthumb-img')].slice(0, 3).every((i) => i.classList.contains('ld'))), '2. the loaded thumbnails never settled (.ld)');

    // 5. The next shot (big-1) was fetched once, after load, before any tap.
    await page.waitForFunction(() => document.readyState === 'complete', null, { timeout: 8000 });
    await page.waitForTimeout(200);
    ok(asked.filter((a) => a.key === 'big-1').length === 1, '5. the next shot was not warmed after the page loaded (asked ' + asked.filter((a) => a.key === 'big-1').length + 'x)');
    ok(asked.filter((a) => a.key === 'big-2').length === 0, '5. a shot beyond the next one was fetched without being asked for');

    // 3. Tap shot 3 (index 2): big-2 is held 2.5 s; its 66px file is loaded.
    //    Hovering it first is what a finger or a pointer does -- and warms it.
    await page.hover('.gthumb[data-i="2"]');
    ok(asked.filter((a) => a.key === 'big-2').length === 1, '5. hovering a thumbnail did not start its large file');
    await page.click('.gthumb[data-i="2"]');
    await page.waitForTimeout(150);
    const afterTap = await colourAt(page, cdp, mid[0], mid[1]);
    ok(near(afterTap, COLOURS[2]), '3. a tap left the OLD photo (or nothing) in the frame instead of the tapped thumbnail: ' + JSON.stringify(afterTap));
    ok(await page.evaluate(() => document.querySelectorAll('#gmain .gmain-img').length === 1), '3. the swap left two main photos in the frame');
    await page.waitForFunction(() => { const i = document.getElementById('gmainImg'); return i.complete && i.naturalWidth === 600 && i.classList.contains('ld'); }, null, { timeout: 8000 }).catch(() => {});
    ok(await page.evaluate(() => { const i = document.getElementById('gmainImg'); return i.complete && i.naturalWidth === 600 && i.classList.contains('ld') && !i.getAttribute('style'); }), '3. the large file never replaced the thumbnail stand-in (or its background was left behind)');
    ok(await page.evaluate(() => document.getElementById('gmainImg').alt === 'alt 2'), '3. the swapped photo lost its alt text');

    // 4. A second page whose 4th thumbnail never arrives: nothing can stand
    //    in, so a tap must show the grey box, not the photo that was up.
    const pb = await browser.newPage({ viewport: { width: 420, height: 900 } });
    const cdpb = await pb.context().newCDPSession(pb);
    pb.goto(base + '/?hang=1').catch(() => {});
    await pb.waitForFunction(() => window.__ready === true && document.getElementById('gmainImg').classList.contains('ld'), null, { timeout: 10000 });
    await pb.click('.gthumb[data-i="3"]');
    await pb.waitForTimeout(150);
    const four = await colourAt(pb, cdpb, mid[0], mid[1]);
    ok(greyish(four), '4. a tap on a thumbnail with no picture yet left the old photo up instead of the grey box: ' + JSON.stringify(four));

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
