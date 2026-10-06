/*
 * Lane DM — the domain move. Shoots the admin screens whose text this lane
 * changed, opened on kbeautybliss.com (mapped to the local preview server with
 * --host-resolver-rules, so location.hostname is the real new name).
 *
 *   DM_EMAIL=dm@example.com DM_PASSWORD=secret-secret-dm DM_TAG=after \
 *     node tests/browser/lane-dm-domain-move.mjs
 *
 * Reports, does not judge.
 */
import { chromium } from 'playwright';
import fs from 'fs';

const PORT = process.env.DM_PORT || '8961';
const BASE = `http://kbeautybliss.com:${PORT}`;
const OUT = process.env.DM_OUT || 'docs/lane-dm-shots';
const TAG = process.env.DM_TAG || 'after';
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({
  executablePath: process.env.DM_CHROME || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell',
  args: ['--no-sandbox', `--host-resolver-rules=MAP kbeautybliss.com 127.0.0.1`],
});

const report = [];

for (const width of [390, 1280]) {
  const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } });
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', process.env.DM_EMAIL);
    await page.fill('input[type=password]', process.env.DM_PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForTimeout(3000);
  }

  for (const [id, name, probe] of [
    ['siteaddr', 'site-address', () => {
      const ta = document.querySelector('#saAliases');
      const p = ta ? ta.parentElement.querySelector('p') : null;
      return { forwardingNow: p ? p.innerText : null };
    }],
    ['seokeywords', 'seo-keywords', () => {
      const i = document.querySelector('#skwProp');
      return { propertyPlaceholder: i ? i.placeholder : null };
    }],
  ]) {
    await page.evaluate((go) => window.go && window.go(go), id);
    await page.waitForTimeout(2500);
    if (name === 'seo-keywords') {
      const tab = page.locator('[data-skw-tab="sources"]').first();
      if (await tab.count()) { await tab.click(); await page.waitForTimeout(1500); }
    }
    const m = await page.evaluate(probe);
    m.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    m.host = await page.evaluate(() => location.hostname);
    report.push({ width, screen: name, ...m });
    await page.setViewportSize({ width, height: 2200 });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/${TAG}-${name}-${width}.png`, fullPage: true });
    await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
  }
  await page.close();
}

await browser.close();
console.log(JSON.stringify(report, null, 2));
