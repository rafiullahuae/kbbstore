/*
 * LANE TM — Payment journey (admin order screen + owner app) and the owner-app
 * phone line, against tools/tm-preview.sh (orders 56187 / 56188 / 56189).
 *
 *   KBB_BASE=http://127.0.0.1:P KBB_APP=/<owner-app-path> node tools/tm-shots.cjs
 *
 * Output: docs/lane-tm-shots/*.png and numbers.json.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE;
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'lane-tm-shots');
fs.mkdirSync(OUT, { recursive: true });
const numbers = {};

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    // ── admin: Store → Orders → #56187 / #56188 / #56189 ──
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => errs.push('PAGE ERROR ' + e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    const IDS = JSON.parse(process.env.KBB_IDS); // {"56187": 37, ...}
    for (const num of Object.keys(IDS)) {
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.evaluate(() => window.go('orders'));
      await page.waitForTimeout(1500);
      const id = await page.evaluate((i) => {
        const b = document.querySelector('[data-olview="' + i + '"]');
        if (b) { b.click(); return i; }
        return null;
      }, String(IDS[num]));
      await page.waitForSelector('.odpj', { timeout: 10000 });
      await page.evaluate(() => document.querySelector('.odpj').scrollIntoView({ block: 'start' }));
      await page.waitForTimeout(400);
      numbers['admin-' + w + '-' + num] = await page.evaluate(() => {
        const v = document.querySelector('.odpj-v'), li = document.querySelectorAll('.odpj-l li');
        const r = document.querySelector('.odpj').getBoundingClientRect();
        return { verdict: v.innerText, steps: li.length, panelWidth: Math.round(r.width), verdictFont: getComputedStyle(v).fontSize,
          scrollWidth: document.documentElement.scrollWidth, innerWidth: innerWidth };
      });
      await page.screenshot({ path: path.join(OUT, 'admin-' + w + '-' + num + '.png') });
      numbers['admin-' + w + '-' + num].id = id;
      if (num === '56187') {
        await page.evaluate(() => document.querySelector('#odJourney').scrollIntoView({ block: 'start' }));
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(OUT, 'admin-' + w + '-56187-journey-emails.png') });
        numbers['admin-' + w + '-56187-cards'] = await page.evaluate(() => ({
          journeySteps: document.querySelectorAll('#odJourney .odpj-l li').length,
          emails: document.querySelectorAll('#odEmails .odem-r').length,
          emailText: (document.querySelector('#odEmails .odem-r') || {}).innerText || '',
        }));
      }
    }
    numbers['admin-' + w + '-console-errors'] = errs;
    await ctx.close();

    // ── owner app: Orders → #56187 ──
    const octx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1, isMobile: w < 700, hasTouch: true });
    await octx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
    const op = await octx.newPage();
    const oerrs = [];
    op.on('pageerror', (e) => oerrs.push('PAGE ERROR ' + e.message));
    op.on('console', (m) => { if (m.type() === 'error') oerrs.push(m.text().slice(0, 160)); });
    await op.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await op.waitForSelector('[data-enrol]');
    await op.fill('input[name=email]', 'owner@example.com');
    await op.fill('input[name=pin]', '482615');
    await op.fill('input[name=device_name]', 'tm-' + w);
    await op.click('[data-enrol] button[type=submit]');
    await op.waitForTimeout(2500);
    for (const num of ['56187', '56189']) {
      const oid = JSON.parse(process.env.KBB_IDS)[num];
      await op.evaluate((h) => { location.hash = h; }, '#/orders/' + oid);
      await op.waitForSelector('.pj', { timeout: 10000 });
      await op.waitForTimeout(700);
      await op.evaluate(() => document.querySelector('.pj').scrollIntoView({ block: 'start' }));
      await op.waitForTimeout(300);
      await op.screenshot({ path: path.join(OUT, 'owner-' + w + '-' + num + '-journey.png') });
      await op.evaluate(() => document.querySelector('.cj').scrollIntoView({ block: 'start' }));
      await op.waitForTimeout(300);
      await op.screenshot({ path: path.join(OUT, 'owner-' + w + '-' + num + '-customer-journey.png') });
      await op.evaluate(() => document.querySelector('.em').scrollIntoView({ block: 'start' }));
      await op.waitForTimeout(300);
      await op.screenshot({ path: path.join(OUT, 'owner-' + w + '-' + num + '-emails.png') });
      await op.evaluate(() => document.querySelector('.cu-ph').scrollIntoView({ block: 'center' }));
      await op.waitForTimeout(300);
      await op.screenshot({ path: path.join(OUT, 'owner-' + w + '-' + num + '-phone.png') });
      numbers['owner-' + w + '-' + num] = await op.evaluate(() => {
        const a = document.querySelector('.cu-ph a');
        const r = a.getBoundingClientRect();
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return { phoneText: a.textContent, href: a.getAttribute('href'), hitIsLink: hit === a || a.contains(hit), linkH: Math.round(r.height), font: getComputedStyle(a).fontSize,
          verdict: document.querySelector('.pj-v').innerText, steps: document.querySelectorAll('.pj .tl li').length,
          journeySteps: document.querySelectorAll('.cj .tl li').length, emails: document.querySelectorAll('.em .em-r').length,
          scrollWidth: document.documentElement.scrollWidth, innerWidth: innerWidth };
      });
    }
    numbers['owner-' + w + '-console-errors'] = oerrs;
    await octx.close();
  }
  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(numbers, null, 1));
  console.log(JSON.stringify(numbers, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
