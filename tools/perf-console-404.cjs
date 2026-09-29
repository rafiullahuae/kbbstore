/*
 * Lane PERF — the console 404, before and after.
 *
 * Lighthouse's `errors-in-console` audit caught this on the owner's shop and
 * does NOT catch it on this fixture, for a reason worth writing down: the
 * shoppable-video rail's covers carry `loading="lazy"`, the rail is below the
 * fold, and Lighthouse only reaches it when its own full-page screenshot pass
 * happens to scroll that far. So the evidence for fix 4 is this, which scrolls
 * the page to the bottom and records every failed request — which is what a
 * shopper's browser does.
 *
 *   node tools/perf-console-404.cjs
 */
const { chromium } = require('playwright');

(async () => {
  const b = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--ignore-certificate-errors'],
  });

  for (const [label, base] of [['before', process.env.PERF_BEFORE || 'http://127.0.0.1:8992'],
                               ['after', process.env.PERF_AFTER || 'http://127.0.0.1:8991']]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
    const page = await ctx.newPage();
    const bad = [];
    page.on('response', (r) => { if (r.status() >= 400) bad.push(r.status() + ' ' + r.url().replace(/^https?:\/\/[^/]+/, '')); });
    await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
    await page.addStyleTag({ content: '*,*::before,*::after{animation:none !important}' });
    await page.evaluate(async () => {
      for (let y = 0; y < document.body.scrollHeight; y += 600) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 60));
      }
    });
    await page.waitForTimeout(1500);
    console.log(label.padEnd(7), bad.length === 0 ? 'no failed requests' : JSON.stringify(bad));
    await ctx.close();
  }

  await b.close();
})();
