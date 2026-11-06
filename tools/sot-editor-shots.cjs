/* Lane SOT: the template picker in the on-page "Edit banner" and in the
   console's Pages -> Page banners -> Super Sale banner tab, photographed and
   measured. sh tools/sot-preview.sh, then
     BASE=http://127.0.0.1:<port> NODE_PATH=<repo>/node_modules node tools/sot-editor-shots.cjs
   Writes docs/lane-sot-shots/ed-*.jpg and ed-numbers.json; puts Super Sale back. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-sot-shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, name, clip) => page.screenshot({ path: `${OUT}/ed-${name}.jpg`, type: 'jpeg', quality: 80, ...(clip ? { clip } : {}) });
const banner = (page) => page.evaluate(() => {
  const b = document.querySelector('.kbb-ssb');
  const t = b.querySelector('.ssb-super'), n = b.querySelector('.ssb-num'), tag = b.querySelector('.ssb-tag');
  return { cls: b.className, title: t && t.textContent, num: n && n.textContent, tag: tag && tag.textContent, titleColor: t && getComputedStyle(t).color, bg: getComputedStyle(b.querySelector('.ssb-in')).backgroundImage.slice(0, 40), style: b.getAttribute('style') };
});

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [], requests = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('request', (r) => requests.push(`${r.method()} ${r.url().replace(BASE, '')}`));
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/super-sale/', { waitUntil: 'networkidle' });
    const pill = page.locator('.kbb-qe-pill', { hasText: 'Edit banner' });
    await pill.waitFor({ timeout: 10000 });
    await pill.click();
    await page.locator('.ssb-ed').waitFor();
    await page.waitForTimeout(300);
    const r = { before: await banner(page) };
    await shot(page, `picker-super-${w}`);

    const n0 = requests.length;
    const pick = async (label, name) => {
      await page.locator('.ssb-ed-tpl', { hasText: label }).click();
      await page.waitForTimeout(250);
      r[name] = await banner(page);
      r[name].pressed = await page.locator('.ssb-ed-tpl[aria-pressed="true"]').textContent();
      await shot(page, `picker-${name}-${w}`);
    };
    await pick('Midnight Neon', 'm1');
    await pick('Flag Sky', 'u3');
    await pick('Fireworks', 'n3');
    // Edit a word and a colour on top of the template: the preview follows.
    await page.locator('.ssb-ed-sec summary', { hasText: 'Words' }).click();
    await page.locator('.ssb-ed-f', { hasText: 'Big title' }).locator('input').fill('HAPPY 2027');
    await page.locator('.ssb-ed-el', { hasText: 'SUPER SALE heading' }).locator('.ssb-ed-hex').fill('#FFFFFF');
    await page.waitForTimeout(200);
    r.edited = await banner(page);
    await shot(page, `picker-edited-${w}`);
    r.requestsWhilePicking = requests.slice(n0).filter((x) => !/\.(woff2?|webp|png|jpe?g|svg)(\?|$)/.test(x));

    if (w === 1280) {
      await Promise.all([page.waitForResponse((x) => x.url().includes('/admin-api/page-banners') && x.request().method() === 'POST'), page.click('.ssb-ed-btn.is-primary')]);
      await page.waitForTimeout(200);
      r.saveMessage = await page.locator('.ssb-ed-msg').textContent();
      await page.click('.ssb-ed-x');
      await page.reload({ waitUntil: 'networkidle' });
      r.afterReload = await banner(page);
      await shot(page, `saved-reload-${w}`, { x: 0, y: 0, width: w, height: 560 });

      await page.goto(BASE + '/admin');
      await page.evaluate(() => window.go('pagebanners'));
      await page.locator('[data-pbs-tab="ssb"]').click();
      await page.locator('[data-pbs-ssbhost] .ssb-ed-body').waitFor({ timeout: 10000 });
      await page.locator('[data-pbs-ssbhost] .ssb-ed-tpl', { hasText: 'Golden Dusk' }).click();
      await page.waitForTimeout(250);
      r.console = await page.evaluate(() => ({ stage: document.querySelector('[data-pbs-ssbhost] .kbb-ssb')?.className, title: document.querySelector('[data-pbs-ssbhost] .ssb-super')?.textContent }));
      await shot(page, 'console-1280');
    }
    r.errors = errors;
    r.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    report[`admin-${w}`] = r;
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/ed-numbers.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
