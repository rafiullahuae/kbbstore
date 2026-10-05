/*
 * Lane AP -- a sidebar row clicked while the console is still arriving.
 *   node tools/ap-early-click.cjs <port>
 * Throttled (4x CPU, 2 Mbit/s). Two clicks, each on a fresh cold load:
 *   early  at the first frame the row exists -- before go() exists at all;
 *   mid    once window.go exists but before DOMContentLoaded, so the screen's
 *          partial (its renderer) has not been parsed yet.
 * Pass: after load the console shows the clicked screen (#ptitle), not the
 * dashboard, and the row is the one lit.
 */
const { chromium } = require('playwright');
const PORT = process.argv[2];
const ROW = process.argv[3] || 'cartpanel';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  const page = await ctx.newPage();
  await page.goto(`http://127.0.0.1:${PORT}/admin/login`);
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 40, downloadThroughput: 250000, uploadThroughput: 250000 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  const out = {};
  for (const mode of ['early', 'mid']) {
    await cdp.send('Network.clearBrowserCache');
    const t0 = Date.now();
    page.goto(`http://127.0.0.1:${PORT}/admin`, { waitUntil: 'load', timeout: 180000 }).catch(() => {});
    if (mode === 'early') {
      await page.waitForSelector(`#nav [data-go="${ROW}"]`, { state: 'attached', timeout: 60000 });
    } else {
      await page.waitForFunction(() => typeof window.go === 'function', null, { timeout: 120000, polling: 50 });
    }
    const at = await page.evaluate(() => ({ go: typeof window.go, ready: document.readyState, t: Math.round(performance.now()) }));
    await page.evaluate((row) => { const b = document.querySelector('#nav [data-go="' + row + '"]'); b.closest('.nav-group') && b.closest('.nav-group').classList.add('open'); b.click(); }, ROW);
    await page.waitForLoadState('load', { timeout: 180000 });
    await page.waitForTimeout(1500);
    const res = await page.evaluate(() => ({ title: document.getElementById('ptitle').textContent, dash: !!document.getElementById('kbbDashWrap'), lit: [...document.querySelectorAll('#nav .nav-item.on')].map((b) => b.dataset.go), url: location.pathname + location.search + location.hash }));
    out[mode] = { clickedAt: at, wallMs: Date.now() - t0, ...res };
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
