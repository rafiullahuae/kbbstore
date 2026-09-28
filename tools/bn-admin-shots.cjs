/*
 * Lane BN screenshots: Appearance -> Banners -> Cards banner.
 *
 * Driven the way an owner drives it — sign in, open the screen, press Edit on
 * the set — so what the pictures show is the path a person takes and not a
 * state assembled by script.
 */
const { chromium } = require('playwright');

const BASE = process.env.BN_BASE || 'http://127.0.0.1:8973';
const OUT = process.env.BN_OUT || (__dirname + '/../docs/lane-bn-shots');

(async () => {
  const width = +process.argv[2];
  const browser = await chromium.launch({ executablePath: process.env.BN_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 1200 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('banners'));
  await page.waitForTimeout(1400);

  const list = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    sets: [...document.querySelectorAll('.bns-set .bns-nm')].map(n => n.textContent.trim()),
    navRow: !!document.querySelector('.side .nav-item[data-go="banners"]'),
    crumb: (document.querySelector('#crumb') || {}).textContent,
    title: (document.querySelector('#ptitle') || {}).textContent,
  }));

  console.log(JSON.stringify({ step: 'list', width, ...list }, null, 2));
  await page.screenshot({ path: `${OUT}/admin-list-${width}.png`, fullPage: true });

  // Open the set — the Edit button on the first row.
  await page.click('[data-bns-open]');
  await page.waitForTimeout(1800);

  const editor = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    controls: [...document.querySelectorAll('.bns-lab')].map(l => l.textContent.trim()),
    switches: [...document.querySelectorAll('.bns-sw span')].map(l => l.textContent.trim()),
    cards: document.querySelectorAll('.bns-cd').length,
    thumbs: document.querySelectorAll('.bns-th img').length,
    frame: !!document.querySelector('.bns-frame'),
    framedCards: (() => {
      const f = document.querySelector('.bns-frame');
      if (!f || !f.contentDocument) return null;
      return f.contentDocument.querySelectorAll('.kbbn-c').length;
    })(),
  }));

  console.log(JSON.stringify({ step: 'editor', width, ...editor }, null, 2));
  await page.screenshot({ path: `${OUT}/admin-editor-${width}.png`, fullPage: true });

  // The preview frame on its own, at each of the three widths the screen offers.
  for (const w of [390, 768, 1280]) {
    await page.click(`[data-bns-w="${w}"]`);
    await page.waitForTimeout(1400);
    const stage = await page.$('#bns-stage');
    if (stage) {
      await stage.scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      await stage.screenshot({ path: `${OUT}/admin-preview-${w}.png` });
    }
  }

  await browser.close();
})();
