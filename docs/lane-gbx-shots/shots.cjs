/*
 * Lane GBX2: the bonus gift INSIDE the Free gifts box, on the real shop code.
 *
 *   GO_LANE=lane-gbx2 GO_SEED=../docs/plans/gift-box-merge/gb-seed.php sh tools/go-preview.sh 9790
 *   GO_LANE=lane-gbx2 GO_BASE=http://127.0.0.1:<port> NODE_PATH=/home/user/kbbstore/node_modules node docs/lane-gbx-shots/shots.cjs
 *
 * Nothing is injected: every picture is what the server draws. Design A on the
 * cart panel, cart page and checkout at 390 and 1280, live / under 2 minutes /
 * expired; B-E once each at 390; the switch off (today's two boxes); /ar; and a
 * box watched to zero. Writes PNGs here and result.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const CHROME = process.env.GB_CHROME || ['/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find((f) => fs.existsSync(f));
const BASE = process.env.GO_BASE || 'http://127.0.0.1:9790';
const APP = path.resolve(__dirname, '../..');
const OUT = __dirname;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const LANE = process.env.GO_LANE || 'lane-gbx2';
const port = new URL(BASE).port;
const tinker = (file, env) => execSync(`sh ${APP}/tools/go-tinker.sh ${file} ${port}`, { env: { ...process.env, GO_LANE: LANE, ...env }, encoding: 'utf8' });
const go = (env) => tinker(`${APP}/tools/go-claim.php`, env);
const gbx = (d, v) => tinker(`${OUT}/gbx-do.php`, { GBX_DO: d, GBX_V: String(v) });
const result = { shots: {}, console: [], watch: {} };

async function openPage(page, which, prefix = '') {
  if (which === 'panel') {
    await page.goto(`${BASE}${prefix}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-open="cart"]:visible').first().click();
    await page.waitForTimeout(1100);
  } else {
    await page.goto(`${BASE}${prefix}/${which}/`, { waitUntil: 'networkidle' });
    if (which === 'checkout' && page.viewportSize().width === 390) await page.click('#kbbSumRow').catch(() => {});
    await page.waitForTimeout(700);
  }
}

// Measured in the page (reading layout here is the test harness, never the shop's script).
const measure = (page) => page.evaluate(() => {
  const f = [...document.querySelectorAll('fieldset.fgb')].find((e) => e.getBoundingClientRect().width > 0 && getComputedStyle(e).visibility !== 'hidden');
  const out = { scrollWidth: document.documentElement.scrollWidth, width: innerWidth, rows: document.querySelectorAll('.kgo-r').length };
  if (!f) return out;
  const r = f.getBoundingClientRect();
  const th = f.querySelector('.fgb-th');
  const tiles = th ? [...th.querySelectorAll(':scope > .fgb-t')] : [];
  const clk = f.querySelector('[data-gbm-clk], [data-gbm-flip]');
  const legend = f.querySelector('legend');
  let between = '';
  for (let n = legend.nextElementSibling; n && n !== th; n = n.nextElementSibling) between += n.className + ' ';
  const va = f.querySelector('.fgb-th .fgb-tg');
  let click = null;
  if (va && va.getBoundingClientRect().width) { const b = va.getBoundingClientRect(); const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); click = !!hit && (hit === va || va.contains(hit)); }
  return { ...out, box: { x: r.left, y: r.top, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10, sy: scrollY },
    cls: f.className, bonusLast: tiles.length ? tiles[tiles.length - 1].hasAttribute('data-gbm-bonus') : null, tiles: tiles.length,
    bonus: f.querySelectorAll('[data-gbm-bonus]').length, clock: clk ? clk.textContent : null, clockPx: clk ? getComputedStyle(clk).fontSize : null,
    clockDir: clk ? getComputedStyle(clk).direction : null, aboveRow: between.trim(), viewAllClicks: click,
    words: /nded|time is up/i.test(f.textContent) };
});

async function shoot(page, file, which) {
  if (which !== 'panel') {
    await page.evaluate(() => { const f = [...document.querySelectorAll('fieldset.fgb')].find((e) => e.getBoundingClientRect().width > 0); if (f) f.scrollIntoView({ block: 'center' }); });
    await page.waitForTimeout(250);
  }
  const m = await measure(page);
  const vw = page.viewportSize().width, vh = page.viewportSize().height;
  if (!m.box) { await page.screenshot({ path: file }); return m; }
  const r = m.box;
  const pad = vw === 390 ? 0 : 60;
  const x = vw === 390 ? 0 : Math.max(0, r.x - pad);
  const w = vw === 390 ? 390 : Math.min(vw - x, r.w + pad * 2);
  const y = Math.max(0, r.y - 110), h = Math.min(vh - y, r.h + 110 + 70);
  await page.screenshot({ path: file, clip: { x, y, width: w, height: h } });
  return m;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  gbx('design', 'A');
  for (const vw of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: vw, height: vw === 390 ? 844 : 900 }, userAgent: UA });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') result.console.push(`${vw} ${m.text()}`); });
    page.on('pageerror', (e) => result.console.push(`${vw} pageerror ${e.message}`));
    page.on('response', (r) => { if (r.status() >= 400) result.console.push(`${vw} ${r.status()} ${r.url()}`); });
    const tok = go({ GO_DO: 'token' }).match(/TOKEN=(\S+)/)[1];
    await page.goto(`${BASE}/gift-offer/c/${tok}`, { waitUntil: 'networkidle' });
    go({ GO_DO: 'fill' });

    for (const [st, left] of [['live', 892], ['low', 98], ['expired', -5]]) {
      gbx('left', left);
      for (const which of ['panel', 'cart', 'checkout']) {
        await openPage(page, which);
        result.shots[`A-${which}-${vw}-${st}`] = await shoot(page, `${OUT}/A-${which}-${vw}-${st}.png`, which);
      }
    }

    if (vw === 390) {
      gbx('left', 892);
      for (const d of ['B', 'C', 'D', 'E']) {
        gbx('design', d);
        for (const which of ['panel', 'cart']) {
          await openPage(page, which);
          result.shots[`${d}-${which}-390-live`] = await shoot(page, `${OUT}/${d}-${which}-390-live.png`, which);
        }
      }
      gbx('design', 'off');
      await openPage(page, 'cart');
      result.shots['off-cart-390'] = await shoot(page, `${OUT}/off-cart-390-two-boxes.png`, 'cart');
      gbx('design', 'A');

      // Watched to zero: 4 seconds left, then 6 seconds later the bonus is gone with no words and no gap.
      gbx('left', 4);
      await openPage(page, 'cart');
      result.watch.before = await shoot(page, `${OUT}/A-cart-390-watch-before.png`, 'cart');
      await page.waitForTimeout(6000);
      result.watch.after = await shoot(page, `${OUT}/A-cart-390-watch-after.png`, 'cart');
      gbx('left', -5);
      await openPage(page, 'cart');
      result.watch.serverExpired = await measure(page);

      // Arabic.
      gbx('ar', 1);
      gbx('left', 892);
      await openPage(page, 'cart', '/ar');
      result.shots['A-ar-cart-390-live'] = await shoot(page, `${OUT}/A-ar-cart-390-live.png`, 'cart');
      gbx('left', 98);
      await openPage(page, 'panel', '/ar');
      result.shots['A-ar-panel-390-low'] = await shoot(page, `${OUT}/A-ar-panel-390-low.png`, 'panel');
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 1));
  console.log(JSON.stringify(result.watch), result.console.length, 'console lines');
})().catch((e) => { console.error(e); process.exit(1); });
