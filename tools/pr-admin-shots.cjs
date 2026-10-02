/*
 * Lane PR — the admin screens every control in this lane lives on.
 *
 *   PR_BASE=http://127.0.0.1:8961 node tools/pr-admin-shots.cjs
 *
 *   Appearance → Product styles → Layout          "Card hover effects on phones"
 *   Appearance → Product styles → Card content    "Discount badge", "New badge"
 *   Appearance → Product styles → Spacing & type  the 21 new controls
 *   Appearance → Site layout → Loading more products
 *
 * Driven, not posed: the Spacing & type shot is taken twice, before and after
 * a slider and two selects are moved, so a control that is drawn and changes
 * nothing shows up as two identical previews. NOTHING IS SAVED.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PR_BASE || 'http://127.0.0.1:8961';
const OUT = path.resolve(__dirname, '..', process.env.PR_OUT || 'docs/lane-pr-shots/admin');

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

  const info = async (name) => {
    const m = await page.evaluate(() => ({
      crumb: document.querySelector('#crumb')?.textContent?.trim() ?? null,
      tab: document.querySelector('.ectab.on, .sls-tab[aria-selected="true"]')?.textContent?.trim() ?? null,
      controls: document.querySelectorAll('[data-ps]').length,
      scrollWidth: document.documentElement.scrollWidth,
      viewport: document.documentElement.clientWidth,
      previewCss: document.querySelector('#psPrev style')?.textContent?.length ?? 0,
      previewPad: (() => { const cb = document.querySelector('#psPrev .cb'); return cb ? getComputedStyle(cb).padding : null; })(),
      previewName: (() => { const n = document.querySelector('#psPrev .kbb-card-nm'); return n ? getComputedStyle(n).fontSize + '/' + getComputedStyle(n).fontWeight : null; })(),
    }));
    console.log(JSON.stringify({ shot: name, ...m }));
  };

  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });

    await page.goto(`${BASE}/admin?go=prodstyles`, { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-pstab="layout"]');
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/prodstyles-layout-${w}.png`, fullPage: true });
    await info(`prodstyles-layout-${w}`);

    await page.click('[data-pstab="content"]');
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/prodstyles-content-${w}.png`, fullPage: true });
    await info(`prodstyles-content-${w}`);

    await page.click('[data-pstab="spacing"]');
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/prodstyles-spacing-${w}.png`, fullPage: true });
    await info(`prodstyles-spacing-${w}`);

    if (w === 1280) {
      // Move three controls (NOT saved) and photograph the preview again.
      await page.$eval('input[data-ps="card_pad_d"]', (el) => { el.value = '6'; el.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.selectOption('select[data-ps="card_fs_title_d"]', '17px');
      await page.selectOption('select[data-ps="card_fw_title"]', '400');
      await page.$eval('select[data-ps="card_fw_title"]', (el) => el.dispatchEvent(new Event('input', { bubbles: true })));
      await page.$eval('select[data-ps="card_fs_title_d"]', (el) => el.dispatchEvent(new Event('input', { bubbles: true })));
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/prodstyles-spacing-moved-${w}.png`, fullPage: true });
      await info(`prodstyles-spacing-moved-${w}`);
    }

    await page.goto(`${BASE}/admin?go=sitelayout`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const tab = page.locator('.sls-tab', { hasText: 'Loading more products' });
    if (await tab.count()) await tab.first().click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/sitelayout-loading-${w}.png`, fullPage: true });
    await info(`sitelayout-loading-${w}`);
  }

  console.log(JSON.stringify({ errors }));
  await browser.close();
})();
