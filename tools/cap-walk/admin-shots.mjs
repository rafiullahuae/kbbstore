/*
 * Lane EH -- Emails -> Sending & delivery -> At checkout, before and after
 * ticking the box and pressing Save, at 1280 and 390.
 *   node tools/eh-walk/admin-shots.mjs PORT OUTDIR
 * Needs tools/eh-walk/owner.php applied to the preview.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, OUT = '.'] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
fs.mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

for (const W of [1280, 390]) {
  const ctx = await browser.newContext({ viewport: { width: W, height: W < 600 ? 844 : 860 }, deviceScaleFactor: W < 600 ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);

  const open = async () => {
    await page.evaluate(() => window.go('emails-sending'));
    await page.waitForSelector('#eml_checkout_email_fix', { state: 'attached' });
    await page.getByRole('tab', { name: 'At checkout' }).or(page.locator('button', { hasText: 'At checkout' })).first().click();
    await page.waitForTimeout(250);
  };

  const beforeScreen = errors.length;   // anything the dashboard itself raised
  await open();
  const before = await page.isChecked('#eml_checkout_email_fix');
  await page.screenshot({ path: `${OUT}/eh-admin-${W}-1-off.png` });

  // Tick, save, and read it back from the server.
  if (!before) await page.check('#eml_checkout_email_fix');
  await page.click('#emlSaveSending3');
  await page.waitForTimeout(700);
  await open();
  const after = await page.isChecked('#eml_checkout_email_fix');
  const server = await page.evaluate(async () => {
    const r = await fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api/emails/sending', { headers: { Accept: 'application/json' } });
    return (await r.json()).checkout_email_fix;
  });
  await page.screenshot({ path: `${OUT}/eh-admin-${W}-2-on-saved.png` });

  // Put it back off, through the same Save, for the next run.
  await page.uncheck('#eml_checkout_email_fix');
  await page.click('#emlSaveSending3');
  await page.waitForTimeout(700);
  const reset = await page.evaluate(async () => {
    const r = await fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api/emails/sending', { headers: { Accept: 'application/json' } });
    return (await r.json()).checkout_email_fix;
  });

  console.log(`${W}: before=${before} after-save=${after} server=${server} after-untick=${reset} sw=${await page.evaluate(() => document.documentElement.scrollWidth)} dashboard-errors=${beforeScreen} screen-errors=${errors.length - beforeScreen} ${errors.join(' | ')}`);
  await ctx.close();
}
await browser.close();
