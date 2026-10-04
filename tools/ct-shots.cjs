/*
 * Growth & Marketing → Cart Tracking, photographed in the real console. (Lane CT)
 *
 *   CT_BASE=http://127.0.0.1:9894 node tools/ct-shots.cjs
 *
 * Against tools/ct-preview.sh with docs/CT-ADMIN-APP-BLOCKS.md applied
 * (python3 tools/ct-wiring.py apply), so the sidebar row, breadcrumb and deep
 * link are the ones the integrator will produce. Drives the screen: every tab,
 * a filter, a cart's side panel, the block popover — at 1280 and 390 — and
 * prints the numbers that matter beside each shot.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.CT_BASE || 'http://127.0.0.1:9894';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.CT_OUT || `${APP}/docs/ct-shots`;

async function measure(page) {
  return page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    crumb: document.querySelector('#crumb')?.textContent ?? null,
    title: document.querySelector('#ptitle')?.textContent ?? null,
    sidebarRows: document.querySelectorAll('#nav [data-go="carttracking"]').length,
    tab: document.querySelector('[data-kbt="carttracking"] [aria-selected="true"]')?.textContent ?? null,
    rows: document.querySelectorAll('.ctk-t tbody tr').length,
    rankRows: document.querySelectorAll('[data-ctk-panel]:not([hidden]) .ctk-rk:not(.h)').length,
    blockRows: document.querySelectorAll('.ctk-bt:not(.h)').length,
    tiles: [...document.querySelectorAll('.ctk-tile .v')].map((e) => e.textContent),
    panelOpen: document.querySelector('#ctkPanel')?.classList.contains('on') ?? false,
    bodyFont: getComputedStyle(document.querySelector('.ctk-t td') || document.body).fontSize,
  }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') console.log(JSON.stringify({ consoleError: m.text() })); });
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

  /* The console scrolls inside its own frame, so a "full page" shot is only
     ever one viewport. A TALL shot is taken with a tall viewport instead —
     the same layout, more of it — and the viewport is put back after. */
  const shot = async (name, w, tall = true) => {
    const h = w === 390 ? 844 : 1000;
    if (tall) await page.setViewportSize({ width: w, height: w === 390 ? 2600 : 1900 });
    await page.waitForTimeout(600);
    const m = await measure(page);
    await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
    if (tall) await page.setViewportSize({ width: w, height: h });
  };

  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });
    await page.goto(`${BASE}/admin?go=carttracking`, { waitUntil: 'networkidle' });
    await page.evaluate(() => { try { localStorage.removeItem('kbbtab:carttracking'); } catch (e) {} });
    await page.goto(`${BASE}/admin?go=carttracking`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.ctk-t tbody tr', { timeout: 15000 });
    const t0 = Date.now();
    await page.click('[data-ctk-period="7d"]');
    await page.waitForSelector('.ctk-t tbody tr');
    console.log(JSON.stringify({ carts7dRoundTripMs: Date.now() - t0, w }));
    await shot('1-carts', w);

    // Bot filter + hover reasons (desktop) / the cart panel of a bot.
    await page.selectOption('[data-ctk-f="bot"]', 'yes');
    await page.waitForTimeout(800);
    if (w === 1280) {
      await page.hover('.ctk-t tbody tr:first-child .ctk-bot');
      await shot('2-bots-hover', w, false);
    } else {
      await shot('2-bots', w);
    }
    await page.click('.ctk-t tbody tr:first-child .c-id');
    await page.waitForSelector('#ctkPanel.on .ctk-tl', { timeout: 10000 });
    await shot('3-cart-bot', w, false);
    await page.click('[data-ctk-close]');
    await page.selectOption('[data-ctk-f="bot"]', '');
    await page.selectOption('[data-ctk-f="bought"]', 'yes');
    await page.waitForTimeout(800);
    await page.click('.ctk-t tbody tr:first-child .c-id');
    await page.waitForSelector('#ctkPanel.on .ctk-tl', { timeout: 10000 });
    await shot('4-cart-purchased', w, false);
    await page.click('[data-ctk-close]');
    await page.selectOption('[data-ctk-f="bought"]', '');
    await page.waitForTimeout(800);

    // The shield → block popover.
    const shield = await page.$('.ctk-t tbody tr:nth-child(2) .ctk-shield');
    if (shield) {
      await shield.click();
      await page.waitForSelector('#ctkPop.on');
      await page.check('#ctkPop input[value=range]');
      await shot('5-block-popover', w, false);
      await page.click('[data-ctk-popclose]');
    }

    for (const [tab, name] of [['added', '6-added'], ['removed', '7-removed'], ['blocked', '8-blocked'], ['settings', '9-settings']]) {
      await page.click(`[data-kbt-tab="${tab}"]`);
      await page.waitForTimeout(900);
      await shot(name, w);
    }
    await page.click('[data-kbt-tab="carts"]');
  }

  await browser.close();
})();
