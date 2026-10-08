/*
 * Lane HB3: the homepage banner on a phone and a computer, and the admin's
 * Size & fit controls with its live phone preview.
 *   HB_BASE=... HB_TAG=after node tools/hb3-shots.cjs        shop
 *   HB_ADMIN=1 HB_BASE=... node tools/hb3-shots.cjs          admin
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = process.env.HB_BASE, TAG = process.env.HB_TAG || 'x';
const OUT = path.join(__dirname, '..', 'docs', 'lane-hb3-shots');
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  if (!process.env.HB_ADMIN) {
    for (const width of [390, 1280]) {
      const page = await b.newPage({ viewport: { width, height: 900 } });
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      await page.addStyleTag({ content: '.kbw{display:none!important}' }); // the chat welcome bubble, so the banner is visible
      const bottom = await page.evaluate(() => Math.ceil(document.querySelector('.kbbs-vp').getBoundingClientRect().bottom + scrollY));
      await page.setViewportSize({ width, height: Math.max(900, bottom + 30) });
      await page.screenshot({ path: path.join(OUT, `${TAG}-${width}.png`), clip: { x: 0, y: 0, width, height: bottom + 20 } });
      await page.close();
    }
  } else {
    for (const width of [1280, 390]) {
      const page = await b.newPage({ viewport: { width, height: 1400 } });
      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate(() => window.go('banners'));
      await page.waitForTimeout(1400);
      await page.click('[data-bns-open]');
      await page.waitForTimeout(1800);
      await page.addStyleTag({ content: '.bns-foot{position:static !important}' });
      const c = await page.evaluate(() => {
        const secs = [...document.querySelectorAll('#bns-editor .bns-sec')];
        const i = secs.findIndex((s) => /Size/.test(s.textContent));
        const top = secs[i].getBoundingClientRect().top + scrollY - 10;
        return { top, h: secs[i + 1].getBoundingClientRect().top + scrollY - 6 - top };
      });
      await page.setViewportSize({ width, height: Math.ceil(c.top + c.h + 40) });
      await page.screenshot({ path: path.join(OUT, `admin-size-fit-${width}.png`), clip: { x: 0, y: c.top, width, height: c.h } });
      if (width === 1280) {
        await page.setViewportSize({ width, height: 1400 });
        await page.click('[data-bns-w="390"]');
        await page.waitForTimeout(2000);
        const stage = await page.$('#bns-stage');
        await stage.scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
        await stage.screenshot({ path: path.join(OUT, 'admin-preview-phone.png') });
        const f = await page.evaluate(() => { const fr = document.querySelector('#bns-stage iframe'); const d = fr && fr.contentDocument; const vp = d && d.querySelector('.kbbs-vp'); const box = d && d.querySelector('.kbbs-s .hb-box'); const r = vp && vp.getBoundingClientRect(); const bx = box && box.getBoundingClientRect(); return { iframe: fr ? fr.clientWidth + 'x' + fr.clientHeight : null, frame: r ? Math.round(r.width) + 'x' + Math.round(r.height) : null, box: bx ? { top: Math.round(bx.top - r.top), bottom: Math.round(r.bottom - bx.bottom) } : null }; });
        console.log(JSON.stringify({ preview: f }));
      }
      await page.close();
    }
  }
  await b.close();
})();
