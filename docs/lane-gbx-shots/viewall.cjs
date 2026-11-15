/*
 * 15 Nov follow-up: View all shows the bonus as its last row with a small clock; below the range minimum
 * the bonus is gone silently and comes back above it. Against tools/go-preview.sh (gb-seed).
 *   GO_LANE=<lane> GO_BASE=http://127.0.0.1:<port> NODE_PATH=/home/user/kbbstore/node_modules node docs/lane-gbx-shots/viewall.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const CHROME = process.env.GB_CHROME || ['/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find((f) => fs.existsSync(f));
const BASE = process.env.GO_BASE;
const APP = path.resolve(__dirname, '../..');
const OUT = __dirname;
const port = new URL(BASE).port;
const tinker = (file, env) => execSync(`sh ${APP}/tools/go-tinker.sh ${file} ${port}`, { env: { ...process.env, ...env }, encoding: 'utf8' });
const go = (env) => tinker(`${APP}/tools/go-claim.php`, env);
const gbx = (d, v) => tinker(`${OUT}/gbx-do.php`, { GBX_DO: d, GBX_V: String(v) });
const out = { console: [] };
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  gbx('design', 'A');
  for (const vw of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: vw, height: vw === 390 ? 844 : 900 }, userAgent: UA });
    const page = await ctx.newPage();
    page.on('console', (m) => { if (m.type() === 'error') out.console.push(`${vw} ${m.text()}`); });
    page.on('pageerror', (e) => out.console.push(`${vw} pageerror ${e.message}`));
    const tok = go({ GO_DO: 'token' }).match(/TOKEN=(\S+)/)[1];
    await page.goto(`${BASE}/gift-offer/c/${tok}`, { waitUntil: 'networkidle' });
    go({ GO_DO: 'fill' });
    gbx('left', 892);

    // cart page, View all opened
    await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    const box = page.locator('fieldset.fgb').first();
    await box.locator('.fgb-th .fgb-tg').click();
    await page.waitForTimeout(1300);
    const k = `cart-${vw}`;
    out[k] = await page.evaluate(() => {
      const f = document.querySelector('fieldset.fgb');
      const rows = [...f.querySelectorAll('.fgb-ls .fgb-lr')];
      const last = rows[rows.length - 1];
      const lk = last && last.querySelector('.gbm-lk');
      return {
        rows: rows.length,
        lastIsBonus: !!last && last.classList.contains('gbm-lr'),
        clock: lk ? lk.textContent : null,
        clockPx: lk ? getComputedStyle(lk).fontSize : null,
        captionShown: [...f.querySelectorAll(':scope > [data-gbm-bonus]')].some((e) => getComputedStyle(e).display !== 'none'),
        scrollWidth: document.documentElement.scrollWidth,
      };
    });
    await box.screenshot({ path: `${OUT}/viewall-cart-${vw}.png` });

    // cart panel, View all opened
    await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-open="cart"]:visible').first().click();
    await page.waitForTimeout(1100);
    const pb = page.locator('#cart fieldset.fgb').first();
    await pb.locator('.fgb-th .fgb-tg').click();
    await page.waitForTimeout(400);
    await pb.screenshot({ path: `${OUT}/viewall-panel-${vw}.png` });
    out[`panel-${vw}`] = await pb.evaluate((f) => ({ lastIsBonus: [...f.querySelectorAll('.fgb-ls .fgb-lr')].pop()?.classList.contains('gbm-lr') }));
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
