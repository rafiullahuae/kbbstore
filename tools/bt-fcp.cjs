/*
 * Lane BT: does the guard cost a NORMAL visitor anything at paint time?
 * Plain Chromium, 390 mobile, CPU throttled 4x: first-contentful-paint and
 * DOMContentLoaded, median of 9 loads, for home / product / category / brand,
 * BEFORE (the guard cut out of the HTML) and AFTER (as served), interleaved.
 *   BASE=http://127.0.0.1:<port> node tools/bt-fcp.cjs
 */
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const OPEN = '<script style="font-size:16px">';
const PAGES = [['home', '/'], ['product', '/product/azelaic-10-hyaluron-redness-soothing-pad/'], ['category', '/collections/toners/'], ['brand', '/brands/anua/']];

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const [name, url] of PAGES) {
    const res = { before: [], after: [] };
    for (let i = 0; i < 18; i++) {
      const side = i % 2 ? 'after' : 'before';
      const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, serviceWorkers: 'block',
        userAgent: 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/141.0 Mobile Safari/537.36 Instagram 350.0' });
      const p = await ctx.newPage();
      if (side === 'before') {
        await p.route((u) => u.href === BASE + url, async (route) => {
          const r = await route.fetch(); let h = await r.text();
          const at = h.indexOf(OPEN); if (at > -1) h = h.slice(0, at) + h.slice(h.indexOf('</script>', at) + 10);
          return route.fulfill({ response: r, body: h });
        });
      } else {
        await p.route((u) => u.href === BASE + url, async (route) => { const r = await route.fetch(); return route.fulfill({ response: r, body: await r.text() }); });
      }
      const cdp = await ctx.newCDPSession(p);
      await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
      await p.goto(BASE + url, { waitUntil: 'load' });
      res[side].push(await p.evaluate(() => {
        const f = performance.getEntriesByName('first-contentful-paint')[0];
        const n = performance.getEntriesByType('navigation')[0];
        return { fcp: f ? Math.round(f.startTime) : null, dcl: Math.round(n.domContentLoadedEventEnd), guard: document.querySelectorAll('script[style]').length };
      }));
      await ctx.close();
    }
    const med = (a, k) => a.map((x) => x[k]).sort((x, y) => x - y)[Math.floor(a.length / 2)];
    out[name] = { before: { fcp: med(res.before, 'fcp'), dcl: med(res.before, 'dcl'), guard: res.before[0].guard }, after: { fcp: med(res.after, 'fcp'), dcl: med(res.after, 'dcl'), guard: res.after[0].guard } };
    console.log(name, JSON.stringify(out[name]));
  }
  await b.close();
  require('fs').writeFileSync(require('path').join(__dirname, '..', 'storage', 'bt-logs', 'fcp.json'), JSON.stringify(out, null, 1));
})();
