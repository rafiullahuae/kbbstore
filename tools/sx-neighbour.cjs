/*
 * Lane SX: rest on a mega item, slide sideways along the bar to its neighbour,
 * rest, click. Does the neighbour get the pointer (prefetch on rest, click
 * navigates), or does the first item's hover bridge swallow it?
 *   node sx-neighbour.cjs label,label
 */
const path = require('path'); const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const LABELS = (process.argv[2] || 'sxgood,sxhead').split(',');
const W = Number(process.env.SX_W || 1280);
const dir = (l) => path.join(APP, 'storage/framework/testing/lane-spd-' + l);
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(dir(l) + '/port', 'utf8').trim();

async function one(browser, l, fromIdx, toIdx, yFrac) {
  const ctx = await browser.newContext({ viewport: { width: W, height: 800 }, serviceWorkers: 'block' });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  const page = await ctx.newPage();
  const prefetched = [];
  page.on('request', (r) => { if (r.headers()['sec-purpose']) prefetched.push(new URL(r.url()).pathname); });
  await page.goto(base(l) + (process.env.SX_PAGE || '/collections/spd-dept-1/'), { waitUntil: 'load' });
  await page.waitForTimeout(500);
  const links = page.locator('.mbar .wrap > .navitem > .navlink');
  const a = await links.nth(fromIdx).boundingBox();
  const b = await links.nth(toIdx).boundingBox();
  const href = await links.nth(toIdx).getAttribute('href');
  const label = (await links.nth(toIdx).innerText()).trim().split(/\s/)[0];
  await page.mouse.move(a.x + a.width / 2, a.y + a.height * yFrac, { steps: 3 });
  await page.waitForTimeout(300);
  const x = b.x + b.width / 2; const y = b.y + b.height * yFrac;
  await page.mouse.move(x, y, { steps: 10 });
  await page.waitForTimeout(400);
  const hit = await page.evaluate(([px, py]) => { const e = document.elementFromPoint(px, py); const a2 = e && e.closest('a'); const ni = e && e.closest('.navitem'); return { el: e ? e.tagName + '.' + String(e.className).split(' ')[0] : null, a: a2 ? a2.getAttribute('href') : null, item: ni ? ni.querySelector('.navlink').textContent.trim() : null, open: [...document.querySelectorAll('.mbar .navitem')].filter((n) => n.matches(':hover')).map((n) => n.querySelector('.navlink').textContent.trim()) }; }, [x, y]);
  const pre = prefetched.includes(new URL(href, base(l)).pathname);
  await page.mouse.down(); await page.waitForTimeout(60); await page.mouse.up();
  await page.waitForTimeout(1500);
  const arrived = new URL(page.url()).pathname === new URL(href, base(l)).pathname;
  await ctx.close();
  return { to: label, yFrac, hitLink: hit.a === href, hitEl: hit.el, hovered: hit.open.join('|'), prefetchedOnRest: pre, clickNavigated: arrived };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const l of LABELS) {
    const ctx = await browser.newContext({ viewport: { width: W, height: 800 } });
    const p = await ctx.newPage();
    await p.goto(base(l) + (process.env.SX_PAGE || '/collections/spd-dept-1/'), { waitUntil: 'load' });
    const items = await p.evaluate(() => [...document.querySelectorAll('.mbar .wrap > .navitem')].map((n, i) => ({ i, label: n.querySelector('.navlink').textContent.trim(), cls: n.className })));
    await ctx.close();
    const megas = items.filter((it) => /mg-|drop/.test(it.cls) || true).filter((it) => it.label.startsWith('Brands') || it.label.startsWith('Skincare'));
    const rows = [];
    for (const m of megas) {
      for (const n of [m.i - 1, m.i + 1].filter((k) => k >= 0 && k < items.length)) {
        for (const yf of [0.5, 0.65, 0.8]) rows.push(Object.assign({ from: m.label.split(/\s/)[0] }, await one(browser, l, m.i, n, yf)));
      }
    }
    console.log(l, W);
    for (const r of rows) console.log('  ' + JSON.stringify(r));
  }
  await browser.close();
})();
