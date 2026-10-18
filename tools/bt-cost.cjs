/*
 * Lane BT: what the guard costs, measured. Against a tools/arr-preview.sh
 * server. For each page and text zoom: the time the guard's own inline script
 * takes on the main thread (performance.mark around it, added by this harness
 * to its copy of the HTML only), with Chrome's CPU throttled 4x as a mid-range
 * phone; the guard's MutationObserver work after it is inside "until load".
 *
 *   BASE=http://127.0.0.1:<port> ZOOMS=1,1.6 node tools/bt-cost.cjs
 */
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const OPEN = '<script style="font-size:16px">';
const PAGES = [['home', '/'], ['product', '/product/azelaic-10-hyaluron-redness-soothing-pad/'], ['category', '/collections/toners/'], ['brand', '/brands/anua/']];

(async () => {
  const out = {};
  for (const z of (process.env.ZOOMS || '1,1.6').split(',')) {
    const browser = await pw.chromium.launch({
      executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', timeout: 240000,
      env: { ...process.env, BT_TEXT_ZOOM: z },
      args: ['--renderer-cmd-prefix=' + path.join(__dirname, 'bt', 'renderer-gdb.sh'), '--no-zygote', '--no-sandbox'],
    });
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block',
      userAgent: 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0 Mobile Safari/537.36 Instagram 350.0' });
    const page = await ctx.newPage();
    page.setDefaultTimeout(240000);
    await page.route('**/*', async (route) => {
      if (/\/build\/assets\/big-text-[^/]+\.js$/.test(route.request().url())) {
        const r = await route.fetch();
        return route.fulfill({ response: r, body: 'performance.mark("js0");' + (await r.text()) + ';performance.mark("js1");', headers: { ...r.headers(), 'cache-control': 'max-age=31536000' } });
      }
      if (route.request().resourceType() !== 'document') return route.continue().catch(() => {});
      const r = await route.fetch();
      let html = await r.text();
      const at = html.indexOf(OPEN);
      if (at > -1) {
        const end = html.indexOf('</script>', at) + 9;
        html = html.slice(0, at) + '<script>performance.mark("bt0")</script>' + html.slice(at, end) + '<script>performance.mark("bt1")</script>' + html.slice(end);
      }
      route.fulfill({ response: r, body: html });
    });
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: +(process.env.THROTTLE || 4) });
    for (const [name, url] of PAGES) {
      const runs = [];
      for (let i = 0; i < 5; i++) {
        await page.goto(BASE + url, { waitUntil: 'load' });
        runs.push(await page.evaluate(() => {
          const a = performance.getEntriesByName('bt0')[0], b = performance.getEntriesByName('bt1')[0];
          const j0 = performance.getEntriesByName('js0')[0], j1 = performance.getEntriesByName('js1')[0];
          return { guardMs: a && b ? +(b.startTime - a.startTime).toFixed(2) : null, jsMs: j0 && j1 ? +(j1.startTime - j0.startTime).toFixed(2) : null, fetchMs: a && j0 ? +(j0.startTime - a.startTime).toFixed(2) : null, cls: document.documentElement.className, sheets: document.styleSheets.length };
        }));
      }
      runs.sort((p, q) => p.guardMs - q.guardMs);
      out[`${name}-z${z}`] = { medianGuardMs: runs[2].guardMs, jsMs: runs.map((x) => x.jsMs), fetchMs: runs.map((x) => x.fetchMs), min: runs[0].guardMs, max: runs[4].guardMs, htmlClass: runs[2].cls, cpuThrottle: +(process.env.THROTTLE || 4) };
      console.log(name, z, JSON.stringify(out[`${name}-z${z}`]));
    }
    await browser.close();
  }
  require('fs').writeFileSync(path.join(__dirname, '..', 'storage', 'bt-logs', 'cost.json'), JSON.stringify(out, null, 1));
})();
