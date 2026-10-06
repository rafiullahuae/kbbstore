/*
 * Lane TP screenshots and measurements: the four policy pages, the site
 * footer's Help column, and the policy links under the cart and checkout
 * totals, at 390 and 1280.
 *
 *   node tools/tp-shots.cjs <port> <out-dir> <tag>
 *
 * Every number is read in the browser after layout (a camera may measure; the
 * shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [port = '10760', out = 'docs/lane-tp-shots', tag = 'after'] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const CALM = '*,*::before,*::after{animation-play-state:paused!important;transition-duration:0s!important}';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const PAGES = ['delivery', 'refund_returns', 'faqs', 'privacy-policy'];

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const ids = JSON.parse(await (await fetch(BASE + '/tp-ids.json')).text());
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));

    for (const slug of PAGES) {
      await page.goto(`${BASE}/${slug}/`, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: CALM });
      await page.screenshot({ path: `${out}/${tag}-${slug}-${w}.png`, fullPage: true });
      report[`${slug}-${w}`] = await page.evaluate(() => {
        const body = document.querySelector('.policy-body');
        const cs = (s) => { const e = body.querySelector(s); if (!e) return null; const c = getComputedStyle(e); return { size: c.fontSize, mt: c.marginTop, mb: c.marginBottom, list: c.listStyleType }; };
        return {
          scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
          articleWidth: Math.round(document.querySelector('article.policy').getBoundingClientRect().width),
          h1: getComputedStyle(document.querySelector('article.policy h1')).fontSize,
          h2: cs('h2'), h3: cs('h3'), p: cs('p'), ul: cs('ul'), firstChildMarginTop: getComputedStyle(body.firstElementChild).marginTop,
          counts: { h2: body.querySelectorAll('h2').length, h3: body.querySelectorAll('h3').length, p: body.querySelectorAll('p').length, li: body.querySelectorAll('li').length, a: body.querySelectorAll('a').length },
        };
      });
    }

    // The footer's Help column.
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: CALM });
    const col = page.locator('nav.kft-col2');
    await col.scrollIntoViewIfNeeded();
    await page.locator('footer.kft').screenshot({ path: `${out}/${tag}-footer-${w}.png` });
    report[`footer-${w}`] = await col.evaluate((n) => [...n.querySelectorAll('a')].map((a) => a.textContent.trim() + ' ' + a.getAttribute('href')));

    // Basket, then the cart and checkout.
    for (const id of ids) {
      await page.evaluate(async (pid) => {
        await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
          body: JSON.stringify({ product_id: pid, quantity: 1 }) });
      }, id);
    }
    for (const where of ['cart', 'checkout']) {
      await page.goto(`${BASE}/${where}/`, { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: CALM });
      const pol = page.locator('.kbb-pol:visible').first();
      if (await pol.count()) {
        await pol.scrollIntoViewIfNeeded();
        await page.waitForTimeout(200);
      }
      await page.screenshot({ path: `${out}/${tag}-${where}-${w}.png` });
      report[`${where}-${w}`] = await page.evaluate(() => {
        const all = [...document.querySelectorAll('.kbb-pol')];
        const vis = all.filter((e) => e.offsetParent !== null);
        const e = vis[0];
        const links = e ? [...e.querySelectorAll('a')].map((a) => { const r = a.getBoundingClientRect(); return { text: a.textContent, href: a.getAttribute('href'), h: Math.round(r.height), w: Math.round(r.width), size: getComputedStyle(a).fontSize, color: getComputedStyle(a).color }; }) : null;
        return { inDom: all.length, visible: vis.length, links, scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth };
      });
    }
    report[`errors-${w}`] = errors;
    await ctx.close();
  }
  fs.writeFileSync(`${out}/${tag}-measurements.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
