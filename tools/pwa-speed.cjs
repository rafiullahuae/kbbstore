/*
 * Lane PW: does the Home Screen app slow the shop down? Measured, not asserted.
 *
 *   PW_BASE=http://127.0.0.1:8731 node tools/pwa-speed.cjs [runs]
 *
 * The homepage, cold (new profile, empty cache) and warm (second visit, the
 * worker installed), with App -> Site App ON and then OFF, in a phone-sized
 * Chromium throttled like a mid-range phone on 4G: 4x CPU, 150 ms RTT,
 * 1.6 Mbit/s down. Reports the median of N runs (default 7) for FCP, LCP,
 * load, requests and bytes transferred. Uses the admin endpoint to switch.
 */
const { chromium } = require('playwright');

const BASE = process.env.PW_BASE || 'http://127.0.0.1:8731';
const RUNS = +(process.argv[2] || 7);
const median = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };

async function setSiteApp(browser, on) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login');
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
  const s = await page.evaluate(async (on) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    return (await fetch('/admin-api/site-app', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ on }) })).status;
  }, on);
  await ctx.close();
  if (s !== 200) throw new Error('could not switch the app ' + (on ? 'on' : 'off') + ': ' + s);
}

async function visit(page, cdp) {
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 1.6 * 1024 * 1024 / 8, uploadThroughput: 750 * 1024 / 8 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  await page.goto(BASE + '/', { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  return page.evaluate(() => new Promise((resolve) => {
    let lcp = 0;
    new PerformanceObserver((l) => { for (const e of l.getEntries()) lcp = Math.max(lcp, e.startTime); }).observe({ type: 'largest-contentful-paint', buffered: true });
    setTimeout(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const res = performance.getEntriesByType('resource');
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      resolve({
        fcp: Math.round(fcp ? fcp.startTime : 0), lcp: Math.round(lcp), load: Math.round(nav.loadEventEnd),
        requests: res.length + 1, kb: Math.round((res.reduce((s, r) => s + (r.transferSize || 0), 0) + nav.transferSize) / 1024),
        fromSW: res.filter((r) => r.workerStart > 0).length,
      });
    }, 300);
  }));
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const on of [true, false]) {
    await setSiteApp(browser, on);
    const cold = [], warm = [];
    for (let i = 0; i < RUNS; i++) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
      const page = await ctx.newPage();
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      cold.push(await visit(page, cdp));
      if (on) await page.waitForFunction(() => navigator.serviceWorker.controller || navigator.serviceWorker.ready, null, { timeout: 10000 }).catch(() => {});
      warm.push(await visit(page, cdp));
      await ctx.close();
    }
    const sum = (rows) => Object.fromEntries(Object.keys(rows[0]).map((k) => [k, median(rows.map((r) => r[k]))]));
    out[on ? 'app_on' : 'app_off'] = { cold: sum(cold), warm: sum(warm), runs: RUNS };
  }
  await setSiteApp(browser, true);
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
