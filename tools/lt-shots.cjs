/*
 * Lane LT — Catalog → Translate & categorise in the console (scope list and
 * estimate, review side by side, skin-concern suggestions) at 1280 and 390,
 * and the /ar/ product page of the approved product at 390 and 1280; with
 * page width, console errors and failed requests.
 *
 *   BASE=http://127.0.0.1:11370 SLUG=<anua slug> SUGGEST=storage/framework/testing/lt-preview/suggest.json node tools/lt-shots.cjs
 *
 * The Skin concerns tab's POST is answered with SUGGEST: the server's own
 * answer, computed by tools/lt-seed.php with a faked Claude API, because the
 * preview cannot reach the real one. Nothing else is intercepted.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:11370';
const SLUG = process.env.SLUG;
const SUGGEST = process.env.SUGGEST;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'storage/lt-shots';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const say = (s) => process.stdout.write(s + '\n');

async function tallShot(page, sel, path, width) {
  const tall = await page.evaluate((s) => Math.ceil(document.querySelector(s).getBoundingClientRect().height + 260), sel);
  await page.setViewportSize({ width, height: Math.min(Math.max(tall, 900), 6000) });
  await page.waitForTimeout(300);
  await page.screenshot({ path });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : DESKTOP });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    if (SUGGEST) {
      await page.route('**/admin-api/recent-localise/concerns/suggest', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: fs.readFileSync(SUGGEST, 'utf8') }));
    }

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin#recentlocalise', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('recentlocalise'));
    await page.waitForSelector('[data-rlz] .rlz-est', { timeout: 15000 });
    const navRow = await page.evaluate(() => !!document.querySelector('.side [data-go="recentlocalise"]'));
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
      est: document.querySelector('.rlz-est').textContent.replace(/\s+/g, ' ').trim(), rows: document.querySelectorAll('.rlz-p').length }));
    say(`console ${width}: scrollWidth ${m.sw} / clientWidth ${m.cw}; sidebar row ${navRow}; products ${m.rows}; ${m.est}`);
    await tallShot(page, '[data-rlz]', `${OUT}/lt-scope-${width}.png`, width);

    await page.setViewportSize({ width, height: 900 });
    await page.click('[data-rlz-tab="review"]');
    await page.waitForSelector('.rlz-rv textarea', { timeout: 15000 });
    await page.waitForTimeout(300);
    // An inline edit: the Save button wakes on input, not before.
    const ta = await page.$('.rlz-rv textarea');
    const savedBefore = await page.evaluate(() => document.querySelector('[data-rlz-save]').disabled);
    await ta.type(' ');
    const savedAfter = await page.evaluate(() => document.querySelector('[data-rlz-save]').disabled);
    say(`review ${width}: Save disabled before edit ${savedBefore}, after ${savedAfter}; scrollWidth ${await page.evaluate(() => document.documentElement.scrollWidth)}`);
    await tallShot(page, '[data-rlz]', `${OUT}/lt-review-${width}.png`, width);

    await page.setViewportSize({ width, height: 900 });
    await page.click('[data-rlz-tab="concerns"]');
    await page.click('[data-rlz-sug]');
    await page.waitForSelector('.rlz-why, .rlz-chip.have', { timeout: 15000 });
    await page.waitForTimeout(300);
    say(`concerns ${width}: suggestions ${await page.evaluate(() => document.querySelectorAll('.rlz-why li').length)}; scrollWidth ${await page.evaluate(() => document.documentElement.scrollWidth)}`);
    await tallShot(page, '[data-rlz]', `${OUT}/lt-concerns-${width}.png`, width);

    say(`errors ${width}: ${errors.length ? errors.join(' | ') : 'none'}`);
    await ctx.close();
  }

  if (SLUG) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : DESKTOP });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      await page.goto(BASE + '/ar/product/' + SLUG + '/', { waitUntil: 'networkidle' });
      const m = await page.evaluate(() => ({ title: document.title, h1: (document.querySelector('h1') || {}).textContent, dir: document.documentElement.dir,
        sw: document.documentElement.scrollWidth, desc: (document.querySelector('meta[name=description]') || {}).content }));
      say(`/ar/ product ${width}: dir=${m.dir} scrollWidth ${m.sw}; <title> ${m.title}; h1 ${String(m.h1).trim()}; meta ${m.desc}; errors ${errors.length ? errors.join(' | ') : 'none'}`);
      await page.screenshot({ path: `${OUT}/lt-ar-product-${width}.png`, fullPage: width < 700 ? false : false });
      if (width < 700) {
        await page.evaluate(() => window.scrollTo(0, 900));
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/lt-ar-product-${width}-tabs.png` });
      }
      await ctx.close();
    }
  }

  await browser.close();
})();
