/* Lane SPD: does the deferred pixel loader (Analytics::LATE) fire at load on a
 * page nobody touched?  node tools/spd-late-probe.cjs <Analytics.php> [label]
 *
 * Builds a page with the loader exactly as the PHP constant holds it, plus a
 * horizontal strip that scrolls ITSELF before load (what the product page's
 * #gthumbs does on the live shop), and records when the loader's callback runs:
 *   at load          -> the strip's scroll was taken for a shopper (the defect)
 *   ~5 s after load  -> waited for a person, as designed
 * Then a second page where a real touch arrives, to show a person still wakes
 * it at once. Nothing leaves the machine: the callback only stamps the time. */
const fs = require('fs');
const http = require('http');
const { chromium } = require('playwright');

const [php, label = ''] = process.argv.slice(2);
const src = fs.readFileSync(php, 'utf8');
const m = src.match(/public const LATE = "((?:[^"\\]|\\.)*)";/);
if (!m) { console.error('no LATE constant in ' + php); process.exit(2); }
const LATE = m[1].replace(/\\"/g, '"');

const html = `<!doctype html><meta name=viewport content="width=device-width">
<script>window.t0=performance.now();(${LATE})(function(){window.fired=Math.round(performance.now()-window.t0)});
addEventListener('load',function(){window.loaded=Math.round(performance.now()-window.t0)});</script>
<div id=strip style="display:flex;overflow-x:auto;width:200px;scroll-snap-type:x mandatory">${'<div style="flex:0 0 120px;height:60px;scroll-snap-align:start;background:#eee;margin:2px"></div>'.repeat(8)}</div>
<script>document.getElementById('strip').scrollLeft=130</script>
<img src="/slow.png" width=10 height=10>`;

const server = http.createServer((req, res) => {
  if (req.url === '/slow.png') { setTimeout(() => { res.writeHead(200, { 'Content-Type': 'image/png' }); res.end(); }, 600); return; }
  res.writeHead(200, { 'Content-Type': 'text/html' }); res.end(html);
}).listen(0, '127.0.0.1', async () => {
  const url = `http://127.0.0.1:${server.address().port}/`;
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
  const out = {};
  for (const touch of [false, true]) {
    const page = await browser.newPage({ viewport: { width: 390, height: 700 }, hasTouch: true, isMobile: true });
    await page.goto(url, { waitUntil: 'load' });
    if (touch) await page.touchscreen.tap(50, 300);
    await page.waitForTimeout(touch ? 500 : 6000);
    out[touch ? 'withTap' : 'untouched'] = await page.evaluate(() => ({ load: window.loaded, fired: window.fired ?? null }));
    await page.close();
  }
  await browser.close(); server.close();
  console.log(JSON.stringify({ label, ...out }));
});
