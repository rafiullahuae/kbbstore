/*
 * Lane RK — photograph the Emails screens (package E1) at 1280 and 390, and
 * measure what CLAUDE.md asks for: document and #content scrollWidth.
 *
 *   sh tools/rk-preview.sh            -> prints the port
 *   node tools/rk-shots.cjs http://127.0.0.1:<port>
 *
 * Needs the integrator wiring applied (NAV group + include + require); the lane
 * applies it locally, uncommitted, for the photographs. Writes
 * docs/rk-emails/*.png and one JSON line per shot.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = path.resolve(__dirname, '../docs/rk-emails');
const GROW = 'html,body{height:auto!important}.app{height:auto!important;min-height:100vh}.content{overflow:visible!important}';

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function shot(page, name, w) {
  await page.setViewportSize({ width: w, height: 1000 });
  await page.addStyleTag({ content: GROW });
  await page.waitForTimeout(700);
  const m = await page.evaluate(() => ({
    docScrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: document.querySelector('#content')?.scrollWidth,
    contentClientWidth: document.querySelector('#content')?.clientWidth,
    crumb: document.querySelector('#crumb')?.textContent,
    title: document.querySelector('#ptitle')?.textContent,
  }));
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
}

async function open(page, id) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.evaluate((s) => window.go(s), id);
  await page.waitForTimeout(1500);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 });
  const page = await login(ctx);

  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: 1000 });

    await open(page, 'emails');
    await shot(page, 'e1-overview', w);

    await open(page, 'emails-sending');
    await shot(page, 'e2-sending-server', w);
    // The owner picks Google: the account and app-password boxes appear.
    await page.check('input[name=emlTransport][value=gmail]');
    await page.waitForTimeout(300);
    await shot(page, 'e2-sending-google-picked', w);

    await open(page, 'emails-branding');
    await shot(page, 'e3-branding', w);

    await open(page, 'emails-sent');
    await shot(page, 'e4-sent-mail', w);

    await open(page, 'mail');
    await shot(page, 'e5-all-mail-settings', w);
  }

  // A real test-send through the saved transport (server mail), and its answer.
  await page.setViewportSize({ width: 1280, height: 1000 });
  await open(page, 'emails-sending');
  await page.fill('#emlTo', 'owner@example.com');
  await page.click('#emlTest');
  await page.waitForTimeout(2500);
  await shot(page, 'e2-sending-test-result', 1280);

  // Design & branding: type the contact details and save; the footer preview
  // is redrawn from the server's answer.
  await open(page, 'emails-branding');
  await page.fill('#eml_mail_support_whatsapp', '+971 58 505 2611');
  await page.fill('#eml_mail_address_dubai', '[Dubai address — owner to paste]\nDubai, United Arab Emirates');
  await page.fill('#eml_mail_address_korea', '[Korea address — owner to paste]\nSeoul, South Korea');
  await page.click('#emlSaveBranding');
  await page.waitForTimeout(1500);
  await shot(page, 'e3-branding-saved', 1280);
  await shot(page, 'e3-branding-saved', 390);

  // Sidebar with the new group open.
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.evaluate(() => document.querySelector('#nav .nav-group[data-sec="Emails"]')?.classList.add('open'));
  const groups = await page.evaluate(() => [...document.querySelectorAll('#nav .nav-group[data-sec="Emails"] .nav-item span:first-of-type')].map((s) => s.textContent));
  console.log(JSON.stringify({ emailsGroup: groups }));
  await page.addStyleTag({ content: '.side{height:auto!important;max-height:none!important;position:static!important;overflow:visible!important}' });
  await (await page.$('#side')).screenshot({ path: `${OUT}/sidebar-emails.png` });

  await browser.close();
})();
