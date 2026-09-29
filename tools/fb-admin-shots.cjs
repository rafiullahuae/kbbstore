/*
 * Lane FB screenshot: Appearance → Header → Flag bar.
 *
 * Driven the way an owner drives it — sign in, open Appearance → Header, press
 * the Flag bar tab — so the picture is the path a person takes and not a state
 * assembled by script. Nothing is saved: the shop is left exactly as the
 * package ships it.
 */
const { chromium } = require('playwright');

const BASE = process.env.FB_BASE || 'http://127.0.0.1:8978';
const OUT = process.env.FB_OUT || (__dirname + '/../docs/lane-fb-shots');

(async () => {
  const width = +process.argv[2] || 1280;
  const browser = await chromium.launch({ executablePath: process.env.FB_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 1100 }, deviceScaleFactor: 1 });
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
  await page.evaluate(() => window.go('header'));
  await page.waitForTimeout(1500);

  const tabs = await page.evaluate(() =>
    [...document.querySelectorAll('[data-hdtab]')].map((b) => b.dataset.hdtab));

  await page.click('[data-hdtab="flagbar"]');
  await page.waitForTimeout(900);

  const m = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    crumb: (document.querySelector('#crumb') || {}).textContent,
    title: (document.querySelector('#ptitle') || {}).textContent,
    tabLabel: (document.querySelector('[data-hdtab="flagbar"]') || {}).textContent,
    cardHeading: (document.querySelector('.mmhd b') || {}).textContent,
    cardNote: (document.querySelector('.mmhd span') || {}).textContent,
    controls: [...document.querySelectorAll('.mmbody .mmrow .mmlbl b')].map((b) => b.textContent.trim()),
    switches: [...document.querySelectorAll('.mmbody .ectog')].map((s) => s.className.includes('on')),
    keys: [...document.querySelectorAll('.mmbody [data-hd]')].map((e) => e.dataset.hd),
  }));

  console.log(JSON.stringify({ width, tabs, ...m }, null, 2));

  await page.screenshot({ path: `${OUT}/admin-flagbar-${width}.png`, fullPage: true });

  await browser.close();
})();
