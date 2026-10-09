/*
 * Lane QK2: Appearance -> Footer -> Social profiles, against tools/qk2-preview.sh.
 * Empty (the live shop), a refused javascript: address, a saved Instagram, the
 * Contact page's Instagram card afterwards, and an editor's read-only view.
 *   QK2_BASE=http://127.0.0.1:10620 QK2_OUT=docs/lane-qk2-shots node tools/qk2-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.QK2_BASE, OUT = process.env.QK2_OUT;

async function login(page, email) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('slimfooter'));
  await page.waitForSelector('#sfs-sec-soc', { timeout: 15000 });
  await page.waitForTimeout(600);
}

async function shoot(page, name) {
  await page.waitForTimeout(4200); // let the toast leave; the state line says the same
  const sec = page.locator('#sfs-sec-soc');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await sec.screenshot({ path: path.join(OUT, name) });
}

async function clickable(page, sel) {
  return page.evaluate((s) => {
    const el = document.querySelector(s);
    if (!el) return false;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!hit && (hit === el || el.contains(hit));
  }, sel);
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const page = await b.newPage({ viewport: { width, height: 1000 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    await login(page, 'owner@preview.test');

    const ig = '[data-sfs-soc="social_instagram"]';
    // The live shop: empty (put back if the previous width saved them).
    await page.fill(ig, '');
    await page.fill('[data-sfs-soc="social_tiktok"]', '');
    await page.click('[data-sfs-socsave]');
    await page.waitForTimeout(900);
    errors.length = 0;
    await shoot(page, `footer-social-empty-${width}.png`);
    const m = await page.evaluate(() => {
      const s = document.querySelector('#sfs-sec-soc').getBoundingClientRect();
      const i = document.querySelector('[data-sfs-soc="social_instagram"]');
      const cs = getComputedStyle(i);
      return {
        sectionW: Math.round(s.width), sectionH: Math.round(s.height),
        inputH: Math.round(i.getBoundingClientRect().height), inputFont: cs.fontSize,
        boxes: document.querySelectorAll('[data-sfs-soc]').length,
        scrollWidth: document.documentElement.scrollWidth,
        jump: !!document.querySelector('[data-sfs-jump="soc"]'),
      };
    });
    const saveClickable = await clickable(page, '[data-sfs-socsave]');

    // Refused.
    await page.fill(ig, 'javascript:alert(1)');
    await page.click('[data-sfs-socsave]');
    await page.waitForSelector('[data-sfs-socstate].is-bad', { timeout: 10000 });
    const refused = await page.textContent('[data-sfs-socstate]');
    await shoot(page, `footer-social-refused-${width}.png`);

    // Saved.
    await page.fill(ig, 'https://www.instagram.com/kbeauty.bliss/');
    await page.fill('[data-sfs-soc="social_tiktok"]', 'https://www.tiktok.com/@kbeauty.bliss');
    await page.click('[data-sfs-socsave]');
    await page.waitForSelector('[data-sfs-socstate].is-ok', { timeout: 10000 });
    const saved = await page.textContent('[data-sfs-socstate]');
    await shoot(page, `footer-social-saved-${width}.png`);
    await page.screenshot({ path: path.join(OUT, `footer-screen-full-${width}.png`), fullPage: true });

    // Survives a reload, so it was really stored.
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('slimfooter'));
    await page.waitForSelector(ig);
    const reloaded = await page.inputValue(ig);

    // The Contact page, as a shopper.
    const shop = await b.newPage({ viewport: { width, height: 900 } });
    await shop.goto(BASE + '/contact-us/', { waitUntil: 'networkidle' });
    const card = shop.locator('[data-ct="ig"]');
    const hasCard = await card.count();
    if (hasCard) { await card.scrollIntoViewIfNeeded(); await card.screenshot({ path: path.join(OUT, `contact-ig-card-${width}.png`) }); }
    const footerIcons = await shop.evaluate(() => [...document.querySelectorAll('footer a[href*="instagram.com"], footer a[href*="tiktok.com"]')].length);
    await shop.close();

    console.log(JSON.stringify({ width, ...m, saveClickable, refused, saved, reloaded, contactIgCard: hasCard, footerIcons, errors }));
    await page.close();
  }

  // An editor: footer access, no Store settings.
  const page = await b.newPage({ viewport: { width: 1280, height: 1000 } });
  await login(page, 'editor@preview.test');
  await shoot(page, 'footer-social-editor-readonly-1280.png');
  console.log(JSON.stringify({ editor: true, readonly: await page.evaluate(() => [...document.querySelectorAll('[data-sfs-soc]')].every((i) => i.readOnly)), saveButton: await page.locator('[data-sfs-socsave]').count() }));
  await b.close();
})();
