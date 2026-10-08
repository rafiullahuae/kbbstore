/* Lane CC: what a click downloads, head (:8840) vs change (:8860), phone; prints the page's variant and every response after the click.
 *   node tools/cc-navbytes.cjs */
// Lane CC: what a click downloads, head vs new: every response after the click, with cache state and bytes.
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const PAIRS = [['home→product', '/', null], ['product→home', '/product/spd-product-500/', '/'], ['category→home', '/collections/spd-dept-1/', '/'], ['home→category', '/', '/collections/spd-dept-1/']];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [name, start, target] of PAIRS) for (const [lab, port] of [['head', 8840], ['new', 8860]]) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
    const page = await ctx.newPage(); const cdp = await ctx.newCDPSession(page); await cdp.send('Network.enable');
    const base = 'http://127.0.0.1:' + port; await page.goto(base + start, { waitUntil: 'load' }); await page.waitForTimeout(1500);
    const href = target || await page.evaluate(() => [...document.querySelectorAll('a[href^="/product/"]')].find((a) => a.getBoundingClientRect().width > 20).getAttribute('href'));
    const got = []; const sizes = {};
    cdp.on('Network.responseReceived', (e) => { got.push({ id: e.requestId, url: e.response.url.replace(base, ''), cache: e.response.fromDiskCache || e.response.fromServiceWorker || e.response.fromPrefetchCache ? 'cache' : 'net', sf: (e.response.requestHeaders || {})['Sec-Fetch-Site'] }); });
    cdp.on('Network.loadingFinished', (e) => { sizes[e.requestId] = e.encodedDataLength; });
    const link = page.locator(`a[href="${href}"]`).filter({ visible: true }).first(); await link.scrollIntoViewIfNeeded(); await link.hover().catch(() => {}); await page.waitForTimeout(600);
    await Promise.all([page.waitForURL((u) => u.pathname === href), link.click()]); await page.waitForLoadState('load'); await page.waitForTimeout(800);
    const html = await page.content();
    const css = got.filter((g) => /\.css|\/$|\/\?|product\//.test(g.url) && !/\.(webp|jpg|png)/.test(g.url)).map((g) => `${g.url.slice(0, 48)} ${g.cache} ${sizes[g.id] ?? '?'}B`);
    console.log(name, lab, 'inline-on-landed-page:', html.includes('kbb-css-inline'), '| net bytes after click:', got.filter((g) => g.cache === 'net').reduce((s, g) => s + (sizes[g.id] || 0), 0), '|', css.join(' ; '));
    await ctx.close();
  }
  await b.close();
})();
