// Lane SEO: Growth & Marketing -> Google Shopping feed at 1280 and 390, the
// switch pressed off and back on, and a storefront pass (console errors, CLS,
// horizontal scroll) over every page type. Preview: storage/seo-logs/env-after/serve.sh.
//   node tools/seo-shots.cjs <port>
const { chromium } = require('playwright');
const fs = require('fs');
const PORT = process.argv[2];
const ORIGIN = `http://kbeautybliss.test:${PORT}`;
const OUT = __dirname + '/../docs/lane-seo-shots';
const report = { admin: {}, shop: {} };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: [`--host-resolver-rules=MAP kbeautybliss.test 127.0.0.1`] });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(ORIGIN + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.click('#nav [data-go="merchantfeed"]').catch(async () => { await page.goto(ORIGIN + '/admin?go=merchantfeed', { waitUntil: 'networkidle' }); });
    await page.waitForSelector('[data-mf-screen] .mf-item', { timeout: 15000 });
    await page.screenshot({ path: `${OUT}/${width}-01-feed-on.png`, fullPage: true });
    const m = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
      title: document.querySelector('#ptitle')?.textContent, crumb: document.querySelector('#crumb')?.textContent,
      url: document.querySelector('.mf-copy code')?.textContent, items: document.querySelectorAll('.mf-item').length,
      navRow: !!document.querySelector('#nav [data-go="merchantfeed"]') }));
    // Off, then on again: each is one POST, and the public URL follows.
    await Promise.all([page.waitForResponse((r) => r.url().includes('/admin-api/merchant-feed') && r.request().method() === 'POST'), page.click('[data-mf-set="0"]')]);
    await page.waitForSelector('.mf-msg');
    const offStatus = await page.evaluate(async () => (await fetch('/feeds/google-merchant.xml')).status);
    await page.screenshot({ path: `${OUT}/${width}-02-feed-off.png`, fullPage: true });
    await Promise.all([page.waitForResponse((r) => r.url().includes('/admin-api/merchant-feed') && r.request().method() === 'POST'), page.click('[data-mf-set="1"]')]);
    const onStatus = await page.evaluate(async () => (await fetch('/feeds/google-merchant.xml')).status);
    report.admin[width] = { ...m, offStatus, onStatus, errors };
    await ctx.close();
  }

  const pages = { home: '/', shop: '/shop/', category: '/collections/seo-skincare/seo-toners/', brand: '/brands/seo-lab/', product: '/product/seo-product-5/', blog: '/blog/' };
  for (const width of [1280, 390]) {
    for (const [name, path] of Object.entries(pages)) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      await page.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
      const resp = await page.goto(ORIGIN + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(500);
      const r = await page.evaluate(() => ({ cls: Math.round(window.__cls * 1000) / 1000, scrollWidth: document.documentElement.scrollWidth,
        speculation: document.querySelectorAll('script[type="speculationrules"]').length, ldjson: document.querySelectorAll('script[type="application/ld+json"]').length }));
      report.shop[`${name}-${width}`] = { status: resp.status(), ...r, errors: errors.filter((e) => !/Failed to load resource/.test(e)), loadErrors: errors.filter((e) => /Failed to load resource/.test(e)).length };
      await ctx.close();
    }
  }
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
