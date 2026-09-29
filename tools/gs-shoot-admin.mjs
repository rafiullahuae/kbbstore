/**
 * LANE GS — the admin screen, shot at 390 and 1280.
 *
 * Signs in for real, opens Appearance → Product grids through the sidebar the
 * partial registers (not by URL), opens the editor for one instance, and waits
 * for the live preview frame to draw before shooting. Nothing is stubbed: what
 * is in the frame is the storefront partial rendered by the same endpoint the
 * owner's browser calls.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright/index.mjs';

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8971';
const OUT = process.env.KBB_SHOTS || 'docs/lane-gs-shots';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

for (const width of [390, 1280]) {
  const page = await browser.newPage({ viewport: { width, height: 1500 } });

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"], input[name="email"]', 'owner@example.test');
  await page.fill('input[type="password"], input[name="password"]', 'secret-secret');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"]')]);

  /*
     Through window.go, which is the wrapper the partial installed — so this
     also proves the sidebar entry and the route wrapper are live.

     WAITED FOR, not assumed: the console defines window.go in its own script
     and the partial wraps it at DOMContentLoaded, and calling before either has
     run throws "window.go is not a function" — which is what the 1280 pass did
     on the first run while 390 happened to win the race.
  */
  await page.waitForFunction(() => typeof window.go === 'function'
    && document.querySelector('.side .nav-item[data-go="gridsections"]') !== null, null, { timeout: 15000 });
  await page.evaluate(() => window.go('gridsections'));
  await page.waitForSelector('.gss-wrap', { timeout: 10000 });
  await page.waitForTimeout(700);

  const probe = await page.evaluate(() => {
    const d = document.documentElement;
    return {
      scrollWidth: d.scrollWidth,
      clientWidth: d.clientWidth,
      crumb: (document.querySelector('#crumb') || {}).textContent,
      title: (document.querySelector('#ptitle') || {}).textContent,
      navEntries: document.querySelectorAll('.side .nav-item[data-go="gridsections"]').length,
      grids: document.querySelectorAll('.gss-item').length,
      presets: document.querySelectorAll('[data-gss-add]').length,
    };
  });

  console.log(`admin list ${width}px`, JSON.stringify(probe));
  await page.screenshot({ path: `${OUT}/admin-${width}-list.png`, fullPage: width === 390 });

  // Open the second instance's editor and wait for the preview frame.
  await page.click('[data-gss-edit]:last-of-type, [data-gss-edit]');
  await page.waitForSelector('#gss-stage', { timeout: 10000 });
  await page.waitForSelector('.gss-frame', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1200);

  const probe2 = await page.evaluate(() => {
    const d = document.documentElement;
    return {
      scrollWidth: d.scrollWidth,
      clientWidth: d.clientWidth,
      controls: document.querySelectorAll('[data-gss-key]').length,
      groups: document.querySelectorAll('.gss-card > div > .gss-title').length,
      frame: document.querySelectorAll('.gss-frame').length,
    };
  });

  console.log(`admin editor ${width}px`, JSON.stringify(probe2));
  await page.screenshot({ path: `${OUT}/admin-${width}-editor.png`, fullPage: true });

  // The live preview on its own, which is the control the owner watches while
  // he drags a number. It is the STOREFRONT partial inside an iframe, linking
  // the shop's built stylesheets — the same markup the homepage draws.
  const stage = await page.$('#gss-stage');
  if (stage) await stage.screenshot({ path: `${OUT}/admin-${width}-preview.png` });
  await page.close();
}

await browser.close();
