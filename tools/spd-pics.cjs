/* Lane SPD: pictures and the review-photo numbers.
 *
 *   node tools/spd-pics.cjs <label> <baseUrl> <outDir>
 *
 * For 390 (ratio 3) and 1280 (ratio 1):
 *   - the banner of the brand, category, content page and a Journal article,
 *     shot at the top of the page, with the file the browser chose
 *     (currentSrc) and its natural size;
 *   - the product page's review cards, scrolled into view: every review
 *     photograph's bytes, the file chosen and its natural size;
 *   - one card clicked (a real click, after elementFromPoint says the card is
 *     what is under the pointer): the popup open, and the bytes of the
 *     ORIGINAL photographs it loads only then.
 * Writes <outDir>/<label>-*.png and <outDir>/<label>-shots.json. Scrolling
 * here is the tool's, not the shop's: the shop measures nothing. */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const [label, base, outDir] = process.argv.slice(2);
const urls = JSON.parse(process.env.SPD_URLS || '{}');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
fs.mkdirSync(outDir, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
  const report = {};
  for (const [width, dpr] of [[390, 3], [1280, 1]]) {
    const mk = async () => {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 800 }, deviceScaleFactor: dpr,
        isMobile: width === 390, hasTouch: width === 390 });
      const page = await ctx.newPage();
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      const bytes = new Map();
      const urlOf = new Map();
      cdp.on('Network.requestWillBeSent', (e) => urlOf.set(e.requestId, e.request.url));
      cdp.on('Network.dataReceived', (e) => { const u = urlOf.get(e.requestId); bytes.set(u, (bytes.get(u) || 0) + e.encodedDataLength); });
      cdp.on('Network.loadingFinished', (e) => { const u = urlOf.get(e.requestId); bytes.set(u, Math.max(bytes.get(u) || 0, e.encodedDataLength)); });
      return { ctx, page, bytes };
    };

    for (const name of ['brand', 'category', 'page', 'post']) {
      if (!urls[name]) continue;
      const { ctx, page, bytes } = await mk();
      await page.goto(base + urls[name], { waitUntil: 'load' });
      await page.waitForTimeout(800);
      const sel = name === 'post' ? '.kbb-journal .cover img' : '.brw-ph__img, .kbb-pb-img img';
      const info = await page.evaluate((s) => {
        const i = document.querySelector(s);
        return i ? { chosen: i.currentSrc.replace(location.origin, ''), natural: i.naturalWidth + 'x' + i.naturalHeight,
          box: Math.round(i.getBoundingClientRect().width) + 'x' + Math.round(i.getBoundingClientRect().height),
          lazy: i.loading, priority: i.getAttribute('fetchpriority') } : null;
      }, sel);
      if (info) info.kb = +((bytes.get(base + info.chosen) || 0) / 1024).toFixed(1);
      info && (info.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth));
      report[`${name}-${width}`] = info;
      await page.screenshot({ path: path.join(outDir, `${label}-${name}-${width}.png`) });
      await ctx.close();
    }

    if (urls.product) {
      const { ctx, page, bytes } = await mk();
      await page.goto(base + urls.product, { waitUntil: 'load' });
      await page.evaluate(() => document.querySelector('.sr-grid, .sr-card').scrollIntoView({ block: 'start' }));
      await page.waitForTimeout(1500);
      await page.evaluate(() => Promise.all([...document.querySelectorAll('.sr-ph img')].map((i) => i.decode().catch(() => {}))));
      const thumbs = await page.evaluate(() => [...document.querySelectorAll('.sr-ph img')].filter((i) => i.complete && i.currentSrc)
        .map((i) => ({ chosen: i.currentSrc.replace(location.origin, ''), natural: i.naturalWidth + 'x' + i.naturalHeight,
          box: Math.round(i.getBoundingClientRect().width) + 'x' + Math.round(i.getBoundingClientRect().height) })));
      // Resource Timing, same origin: what crossed the wire for each review file.
      const rt = () => page.evaluate(() => performance.getEntriesByType('resource').filter((e) => /reviews\//.test(e.name))
        .map((e) => [e.name.replace(location.origin, ''), e.transferSize || e.encodedBodySize]));
      const seen = await rt();
      const kb = seen.reduce((s, [, b]) => s + b, 0) / 1024;
      for (const t of thumbs) { const m = seen.find(([u]) => u === t.chosen); t.kb = m ? +(m[1] / 1024).toFixed(1) : 0; }
      await page.screenshot({ path: path.join(outDir, `${label}-reviews-${width}.png`) });
      // A real click on the first photographed card, after proving it is what the pointer hits.
      const card = page.locator('.sr-card:has(.sr-ph)').first();
      const box = await card.locator('.sr-ph').first().boundingBox();
      const hit = await page.evaluate(([x, y]) => { const el = document.elementFromPoint(x, y); return el ? !!el.closest('.sr-card') : false; },
        [box.x + box.width / 2, box.y + box.height / 2]);
      const before = new Set(seen.map(([u]) => u));
      await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
      await page.waitForTimeout(1500);
      const open = await page.evaluate(() => { const m = document.querySelector('[data-sr-modal]'); return !!m && !m.hidden; });
      const full = (await rt()).filter(([u]) => !before.has(u)).map(([u, b]) => `${(b / 1024).toFixed(1)}K ${u}`);
      await page.screenshot({ path: path.join(outDir, `${label}-review-viewer-${width}.png`) });
      report[`reviews-${width}`] = { thumbs: thumbs.length, thumbKB: +kb.toFixed(1), sample: thumbs.slice(0, 3), cardHit: hit, popupOpen: open,
        fullOnClick: full, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(outDir, `${label}-shots.json`), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
