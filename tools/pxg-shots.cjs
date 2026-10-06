/*
 * Lane PX screenshots and checks: Growth & Marketing -> Marketing Pixels, the
 * eye in front of each ID and the guide it opens. Exits non-zero if a check
 * fails, so the pictures cannot be of a broken state.
 *   sh tools/pxg-preview.sh && PX_BASE=http://127.0.0.1:<port> node tools/pxg-shots.cjs 1280
 */
const { chromium } = require('playwright');
const BASE = process.env.PX_BASE || 'http://127.0.0.1:9144';
const OUT = process.env.PX_OUT || (__dirname + '/../docs/lane-pxg-shots');
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };

(async () => {
  const width = +process.argv[2];
  const browser = await chromium.launch({ executablePath: process.env.PX_CHROME || '/usr/bin/chromium-browser' });
  const page = await (await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 } })).newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('pixels'));
  await page.waitForSelector('.pxg-eye');
  await page.waitForTimeout(400);

  const screen = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
    eyes: [...document.querySelectorAll('.pxg-eye')].map((b) => {
      const r = b.getBoundingClientRect(); const lab = b.parentElement.querySelector('label').getBoundingClientRect();
      return { key: b.dataset.pxg, name: b.getAttribute('aria-label'), w: r.width, h: r.height, beforeLabel: r.right <= lab.left };
    }),
  }));
  console.log(JSON.stringify({ width, screen }));
  if (screen.eyes.length !== 3) fail('expected 3 eyes');
  if (screen.scrollWidth > screen.clientWidth) fail('horizontal scroll on the screen');
  screen.eyes.forEach((e) => { if (!e.beforeLabel) fail(e.key + ' eye not in front of label'); });
  await page.screenshot({ path: `${OUT}/screen-${width}.png`, fullPage: true });

  for (const key of ['meta_id', 'ga4_id', 'tiktok_id']) {
    await page.click(`.pxg-eye[data-pxg="${key}"]`);
    await page.waitForTimeout(450);
    const d = await page.evaluate(() => {
      const box = document.querySelector('.pxg-bg.on .pxg'); const r = box.getBoundingClientRect();
      return { title: box.querySelector('#pxg-title').textContent, top: r.top, bottom: r.bottom, left: r.left, w: r.width, h: r.height,
        vh: innerHeight, focusInDialog: box.contains(document.activeElement), links: box.querySelectorAll('a[target=_blank]').length,
        titleFont: getComputedStyle(box.querySelector('#pxg-title')).fontSize, stepFont: getComputedStyle(box.querySelector('.pxg-steps li')).fontSize,
        scrollWidth: document.documentElement.scrollWidth };
    });
    console.log(JSON.stringify({ width, key, dialog: d }));
    if (!d.focusInDialog) fail(key + ' focus not in dialog');
    if (width < 600 && Math.abs(d.bottom - d.vh) > 1) fail(key + ' not a bottom sheet');
    if (width >= 600 && Math.abs((d.top + d.h / 2) - d.vh / 2) > 2) fail(key + ' not centred');
    await page.mouse.move(1, 1);   // no hover on whatever the eye click left the pointer over
    await page.screenshot({ path: `${OUT}/guide-${key}-${width}.png` });
    if (key === 'meta_id') {
      // Tab trap: 40 tabs never leave the dialog.
      for (let i = 0; i < 40; i++) await page.keyboard.press('Tab');
      if (!(await page.evaluate(() => document.querySelector('.pxg').contains(document.activeElement)))) fail('Tab escaped the dialog');
      await page.keyboard.press('Escape');
    } else if (key === 'ga4_id') {
      await page.mouse.click(5, 5);   // the backdrop
    } else {
      await page.click('.pxg [data-pxg-close]');
    }
    await page.waitForTimeout(250);
    const after = await page.evaluate((k) => ({ open: !!document.querySelector('.pxg-bg.on'), back: document.activeElement && document.activeElement.dataset.pxg === k }), key);
    if (after.open) fail(key + ' did not close');
    if (!after.back) fail(key + ' focus not returned to its eye');
  }

  // Paste my ID -> closes and focuses that field; repaint after the guide keeps exactly one eye per field.
  await page.click('.pxg-eye[data-pxg="tiktok_id"]');
  await page.click('.pxg [data-pxg-paste]');
  if (!(await page.evaluate(() => document.activeElement && document.activeElement.dataset.mp === 'tiktok_id'))) fail('Paste my ID did not focus the field');
  await page.evaluate(() => window.paintPixels());
  if ((await page.$$('.pxg-eye')).length !== 3) fail('repaint changed the eye count');
  if (errors.length) fail('page errors: ' + errors.join(' | '));
  await browser.close();
})();
