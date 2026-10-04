// Lane KW screenshots: every SEO Keywords tab at 1280 and 390, plus the
// product page's head excerpt. Usage: node shoot.cjs <port>
const { chromium } = require('/home/user/lane-kw/node_modules/playwright');
const fs = require('fs');
const BASE = `http://127.0.0.1:${process.argv[2] || 9980}`;
const OUT = '/home/user/lane-kw/docs/lane-kw-shots';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = [];
  for (const w of (process.env.ONLY === 'excerpt' ? [] : [1280, 390])) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', String(e)));
    page.on('dialog', d => d.accept());
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('seokeywords'));
    await page.waitForSelector('[data-skw-tab="overview"]', { timeout: 20000 });
    for (const tab of ['overview', 'sources', 'bank', 'pages', 'sync']) {
      await page.click(`[data-skw-tab="${tab}"]`);
      await page.waitForTimeout(900);
      if (tab === 'sync' && w === 1280) {
        await page.click('[data-skw-act="dry"]');
        await page.waitForFunction(() => /Dry run #\d+ · done/.test(document.querySelector('#content').textContent), null, { timeout: 60000 });
        await page.waitForTimeout(500);
      }
      const m = await page.evaluate(() => ({
        scrollW: document.documentElement.scrollWidth,
        innerW: window.innerWidth,
        contentH: document.querySelector('#content').scrollHeight,
        cards: document.querySelectorAll('#content .skw-card').length,
        tabFont: getComputedStyle(document.querySelector('[data-skw-tab]')).fontSize,
      }));
      report.push(`${w} ${tab} ${JSON.stringify(m)}`);
      await page.screenshot({ path: `${OUT}/admin-${tab}-${w}.png`, fullPage: true });
    }
    if (w === 1280) {
      // Popular searches: switched on for the shot, then off again.
      const put = (body) => page.evaluate(async (b) => {
        const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
        const r = await fetch(base + '/admin-api/seo-keywords/settings', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(b) });
        return r.status;
      }, body);
      report.push('popular on: ' + await put({ popular: true }));
      for (const bw of [1280, 390]) {
        const c2 = await b.newContext({ viewport: { width: bw, height: 844 }, deviceScaleFactor: bw > 500 ? 1 : 2 });
        const p2 = await c2.newPage();
        await p2.goto(`${BASE}/brands/anua/`, { waitUntil: 'networkidle' });
        const el = await p2.$('.kbb-popsearch');
        if (el) {
          await el.scrollIntoViewIfNeeded();
          const box = await el.boundingBox();
          const mm = await p2.evaluate(() => { const n = document.querySelector('.kbb-popsearch'); const a = n.querySelector('a'); return { scrollW: document.documentElement.scrollWidth, links: n.querySelectorAll('a').length, height: Math.round(n.getBoundingClientRect().height), linkFont: getComputedStyle(a).fontSize }; });
          report.push(`${bw} brand popular-searches ${JSON.stringify(mm)}`);
          await p2.screenshot({ path: `${OUT}/storefront-brand-popular-${bw}.png`, clip: { x: 0, y: Math.max(0, box.y - 260), width: bw, height: Math.min(844, box.height + 360) } });
        } else report.push(`${bw} brand popular-searches MISSING`);
        await c2.close();
      }
      report.push('popular off: ' + await put({ popular: false }));
    }
    // the sidebar row
    const nav = await page.evaluate(() => [...document.querySelectorAll('#nav .nav-group[data-sec="Store"] .nav-item')].map(x => x.dataset.go));
    report.push(`${w} Store menu: ${nav.join(' ')}`);
    await ctx.close();
  }
  // storefront: product page head + JSON-LD, and a brand page with Popular searches off
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const res = await page.goto(`${BASE}/product/pdp-heartleaf-toner/`, { waitUntil: 'networkidle' });
  const html = await res.text();
  const head = html.split('</head>')[0];
  const lines = head.split('\n').filter(l => /<title>|name="description"|name="keywords"|"@type":"Product"|"@type":"WebPage"|"@type":"CollectionPage"/.test(l));
  const ld = [...head.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/gs)].map(m => JSON.parse(m[1])).filter(n => n.keywords);
  const excerpt = ['GET /product/pdp-heartleaf-toner/  (head excerpt)', '', ...lines.map(l => l.length > 400 && l.includes('ld+json') ? '<script type="application/ld+json"> … Product node … </script>' : l), '',
    'JSON-LD nodes carrying keywords:', ...ld.map(n => `  @type=${n['@type']}  keywords: ${n.keywords}`), '',
    `body contains the keywords tag? ${html.split('<body')[1].includes('name="keywords"')}`];
  fs.writeFileSync(`${OUT}/product-head-excerpt.txt`, excerpt.join('\n') + '\n');
  await page.setContent(`<pre style="font:13px/1.5 ui-monospace,monospace;white-space:pre-wrap;padding:20px;margin:0">${excerpt.join('\n').replace(/&/g,'&amp;').replace(/</g,'&lt;')}</pre>`);
  await page.screenshot({ path: `${OUT}/product-head-excerpt.png`, fullPage: true });
  await ctx.close();
  await b.close();
  if (process.env.ONLY !== 'excerpt') fs.writeFileSync(`${OUT}/measurements.txt`, report.join('\n') + '\n');
  console.log(report.join('\n'));
})();
