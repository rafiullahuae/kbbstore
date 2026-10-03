/*
 * Lane RJ — photograph the admin's mail screens AS THEY ARE TODAY, in the
 * running rj preview (tools/rj-preview.sh), logged in as the preview owner.
 *
 *   node tools/rj-admin-before.cjs http://127.0.0.1:<port>
 *
 * Writes docs/rj-email-previews/admin-before/*.png plus one JSON line per shot.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2];
const OUT = path.resolve(__dirname, '../docs/rj-email-previews/admin-before');

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

/* The console scrolls inside .content, so a "full page" screenshot would stop
   at the viewport. For the photograph only, let the document grow instead. */
const GROW = 'html,body{height:auto!important}.app{height:auto!important;min-height:100vh}.content{overflow:visible!important}';

async function shot(page, name, w) {
  await page.addStyleTag({ content: GROW });
  await page.setViewportSize({ width: w, height: 1000 });
  await page.waitForTimeout(600);
  const m = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    crumb: document.querySelector('#crumb')?.textContent,
    title: document.querySelector('#ptitle')?.textContent,
  }));
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 1 });
  const page = await login(ctx);

  for (const [screen, name, open] of [
    ['mail', 'store-mail', 'Store'],
    ['newsletter', 'growth-newsletter', 'Growth & Marketing'],
  ]) {
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.evaluate(([s, sec]) => {
      window.go(s);
      document.querySelector(`.nav-group[data-sec="${sec}"]`)?.classList.add('open');
    }, [screen, open]);
    await page.waitForTimeout(1500);
    await shot(page, name, 1280);
    await shot(page, name, 390);
  }

  /* The sidebar on its own, every group open, so the owner can see where mail
     lives today. */
  await page.setViewportSize({ width: 1280, height: 1000 });
  await page.evaluate(() => document.querySelectorAll('.nav-group').forEach((g) => g.classList.add('open')));
  const side = await page.$('#side');
  await page.addStyleTag({ content: '.side{height:auto!important;max-height:none!important;position:static!important;overflow:visible!important}' });
  await page.waitForTimeout(300);
  await side.screenshot({ path: `${OUT}/sidebar-today.png` });
  const groups = await page.evaluate(() => [...document.querySelectorAll('#nav .nav-group')].map((g) => g.dataset.sec + ': ' + [...g.querySelectorAll('.nav-item span:first-of-type')].map((s) => s.textContent).join(' | ')));
  fs.writeFileSync(`${OUT}/sidebar-today.txt`, groups.join('\n') + '\n');
  console.log(groups.join('\n'));
  await browser.close();
})();
