/* Lane RO2: a started campaign opened in the builder shows "Locked." with the
   reason and Duplicate to edit. BASE=http://127.0.0.1:PORT ID=5 node tools/ro2-locked-shot.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, ID = process.env.ID || '5', OUT = path.resolve(__dirname, '../docs/lane-ro2-shots');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('mkt-email'));
    await page.waitForTimeout(1500);
    // The same delegated "edit" action the campaign list uses, aimed at a paused campaign.
    await page.evaluate((id) => { const b = document.createElement('button'); b.setAttribute('data-mke', 'edit'); b.setAttribute('data-id', id); document.body.appendChild(b); b.click(); b.remove(); }, ID);
    await page.waitForTimeout(1500);
    await page.getByRole('tab', { name: 'Order', exact: true }).first().click();
    await page.locator('[data-mke-pick]').last().click();
    await page.waitForTimeout(600);
    await page.locator('.mke-locked').first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/locked-${w}.png` });
    const typed = await page.locator('.mke-ro [data-mke-p="why_text"]').isDisabled();
    console.log(w, JSON.stringify({ lockedNotes: await page.locator('.mke-locked').count(), boxDisabled: typed, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
  }
  await browser.close();
})();
