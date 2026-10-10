/*
 * Lane EB (bounces) -- Growth & Marketing → Bounces & unsubscribes, photographed
 * and measured at 1280 and 390.
 *
 *   sh tools/ebh-preview.sh            (prints the port)
 *   node tools/ebh-shots.cjs <port>
 *
 * Writes docs/lane-eb-shots/bounces/*.png and storage/eb-logs/shots.json.
 * Measured by the harness (the screen measures nothing): scrollWidth vs
 * clientWidth (no sideways page scroll), the tab font size, the sidebar row,
 * console errors, and the Restore action before and after.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '10930';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-eb-shots', 'bounces');
const LOGS = path.join(__dirname, '..', 'storage', 'eb-logs');
fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(LOGS, { recursive: true });

const measure = (page) => page.evaluate(() => {
  const tab = document.querySelector('.ebh-tab');
  const row = document.querySelector('#nav [data-go="mkt-health"]');
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    tabFont: tab ? getComputedStyle(tab).fontSize : null,
    navRow: row ? row.textContent.trim() : null,
    items: document.querySelectorAll('.ebh-item').length,
    title: (document.querySelector('#ptitle') || {}).textContent || null,
  };
});

async function run(browser, w, h) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  await page.waitForTimeout(600);
  const out = { width: w };

  await page.evaluate(() => window.go('mkt-health'));
  await page.waitForSelector('.ebh-item');
  await page.waitForTimeout(250);
  out.bounced = await measure(page);
  await page.screenshot({ path: path.join(OUT, `${w}-1-bounced.png`), fullPage: true });

  for (const [tab, name] of [['watching', '2-watching'], ['unsubscribed', '3-unsubscribed'], ['complaints', '4-complaints'], ['setup', '5-setup'], ['dns', '6-deliverability']]) {
    await page.click(`.ebh-tab[data-tab="${tab}"]`);
    await page.waitForTimeout(450);
    out[tab] = await measure(page);
    // The admin scrolls inside its own panel, so a tall window shows the whole tab.
    if (tab === 'setup') await page.setViewportSize({ width: w, height: w < 600 ? 2250 : 2050 });
    await page.screenshot({ path: path.join(OUT, `${w}-${name}.png`), fullPage: true });
    if (tab === 'setup') await page.setViewportSize({ width: w, height: h });
  }

  if (w === 1280) {
    // Restore, before and after (the confirm is accepted).
    await page.click('.ebh-tab[data-tab="bounced"]');
    await page.waitForSelector('.ebh-item button:has-text("Restore")');
    out.restoreBefore = await page.evaluate(() => ({ items: document.querySelectorAll('.ebh-item').length, count: document.querySelector('.ebh-tab[data-tab="bounced"] .ct').textContent }));
    // The admin's house "Are you sure?" box asks first (reset-guard).
    await page.click('.ebh-item button:has-text("Restore")');
    await page.waitForSelector('#kbbSureBg.on');
    out.confirmText = await page.textContent('#kbbSureText');
    await page.screenshot({ path: path.join(OUT, `${w}-7-restore-are-you-sure.png`) });
    await page.click('#kbbSureYes');
    await page.waitForTimeout(900);
    out.restoreAfter = await page.evaluate(() => ({ items: document.querySelectorAll('.ebh-item').length, count: document.querySelector('.ebh-tab[data-tab="bounced"] .ct').textContent, message: (document.querySelector('.ebh-good[role=status]') || {}).textContent || null, banner: (document.querySelector('.ebh-note') || {}).textContent || null }));
    await page.screenshot({ path: path.join(OUT, `${w}-8-after-restore.png`), fullPage: true });
  }

  out.errors = errors;
  await ctx.close();
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [await run(browser, 1280, 900), await run(browser, 390, 844)];
  await browser.close();
  fs.writeFileSync(path.join(LOGS, 'shots.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
})();
