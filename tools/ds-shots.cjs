/*
 * Lane DS: screenshots of Platform → Domain switch → 1b "Payments ready?"
 * (mixed results) and 6b "Old links in the shop's text" (the preview), at
 * 390 and 1280, in Chromium, on the DW preview (real shop code, providers
 * faked at the boundary by tools/dw-router.php's `ds` world).
 *
 *   sh tools/dw-preview.sh 10790     # then:
 *   node tools/ds-shots.cjs 10790
 *
 * Records console errors, 4xx/5xx, horizontal overflow and every outbound
 * request the shop made, to docs/lane-ds-shots/shots.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const APP = path.join(__dirname, '..');
const PORT = process.argv[2] || '10790';
const DIR = path.join(APP, 'storage/framework/testing/dw-preview');
const OUT = path.join(APP, 'docs', 'lane-ds-shots');
const ORIGIN = `http://127.0.0.1:${PORT}`;
fs.mkdirSync(OUT, { recursive: true });

// The shop after step 6: APP_URL on the new domain, the ds seed on top of dw's.
const env = fs.readFileSync(path.join(DIR, '.env'), 'utf8').replace(/^APP_URL=.*$/m, 'APP_URL=https://kbeautybliss.com');
fs.writeFileSync(path.join(DIR, '.env'), env);
execSync(`. ${DIR}/env.sh && APP_URL=https://kbeautybliss.com php ${APP}/artisan tinker --execute="require '${APP}/tools/ds-seed.php';"`, { stdio: 'inherit', shell: '/bin/sh' });
fs.writeFileSync(path.join(DIR, 'scenario.json'), JSON.stringify({ dns: {}, tls: 'ok', stripe: 'ok', tabby: 'ok', tamara: 'ok', ds: { hook_host: 'extrabeauty.ae', wallets: [] } }));
fs.writeFileSync(path.join(DIR, 'outbound.log'), '');

/* The console scrolls inside its own pane, so an element taller than the
   viewport would be cut: grow the viewport to the element first. */
async function shoot(page, sel, file, width) {
  const h = await page.$eval(sel, (el) => Math.ceil(el.getBoundingClientRect().height));
  await page.setViewportSize({ width, height: Math.max(width === 390 ? 844 : 900, h + 400) });
  await page.$eval(sel, (el) => el.scrollIntoView({ block: 'start' }));
  await page.waitForTimeout(200);
  await page.locator(sel).screenshot({ path: file });
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: width === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.request().method() + ' ' + r.url()); });
    page.on('dialog', (d) => d.accept());

    await page.goto(ORIGIN + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(ORIGIN + '/admin', { waitUntil: 'networkidle' });
    const outboundBefore = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8').split('\n').filter(Boolean).length;
    await page.evaluate(() => window.go('domainswitch'));
    await page.waitForSelector('#dw-step-1b');
    await page.waitForTimeout(800);
    const outboundOnOpen = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8').split('\n').filter(Boolean).length - outboundBefore;

    // 1b: press Check everything.
    const t0 = Date.now();
    await Promise.all([page.waitForResponse((r) => r.url().includes('/domain-switch/payments-check')), page.click('[data-dw="pay_check"]')]);
    const payMs = Date.now() - t0;
    await page.waitForSelector('#dw-step-1b .dw-pay');
    const pay = await page.$eval('#dw-step-1b', (el) => ({
      dots: [...el.querySelectorAll('li .dw-dot')].map((d) => d.className.replace('dw-dot is-', '')),
      text: el.innerText.slice(0, 4000),
    }));
    await shoot(page, '#dw-step-1b', path.join(OUT, `${width}-payments-ready.png`), width);

    // 6b: Show what would change.
    await Promise.all([page.waitForResponse((r) => r.url().includes('/domain-switch/rewrite')), page.click('[data-dw="rw_preview"]')]);
    await page.waitForSelector('#dw-step-6b .dw-rw, #dw-step-6b .dw-msg');
    const rw = await page.$eval('#dw-step-6b', (el) => ({ text: el.innerText.slice(0, 3000), apply: !!el.querySelector('[data-dw="rewrite_content"]:not([disabled])') }));
    await shoot(page, '#dw-step-6b', path.join(OUT, `${width}-old-links-preview.png`), width);

    const metrics = await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));
    const outbound = fs.readFileSync(path.join(DIR, 'outbound.log'), 'utf8').split('\n').filter(Boolean);
    report[width] = { outboundOnOpen, payCheckMs: payMs, pay, rw, metrics, errors, outbound };
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'shots.json'), JSON.stringify(report, null, 2));
  for (const w of Object.keys(report)) {
    const r = report[w];
    console.log(w, 'outbound on open:', r.outboundOnOpen, '| check ms:', r.payCheckMs, '| dots:', r.pay.dots.join(','), '| apply enabled:', r.rw.apply, '| scrollWidth', r.metrics.scrollWidth, '/', r.metrics.clientWidth, '| errors:', r.errors.length);
  }
})();
