/*
 * LANE OA2 — Platform → Users & Roles → Owner app → "Show loading bars after
 * (minutes)", at 390 and 1280: as found, a refused value, a saved value, and
 * the value arriving in the app (More → Sync on open).
 *
 *   KBB_BASE=http://127.0.0.1:10260 KBB_APP=/<secret> node tools/oa2-admin-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10260';
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'oa2-shots');
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  say('== admin: Platform → Users & Roles → Owner app → Settings');
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => say('PAGE ERROR ' + e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin?go=users', { waitUntil: 'networkidle' });
    await page.waitForSelector('#rlt_ownerapp', { timeout: 15000 });
    await page.click('#rlt_ownerapp');
    await page.waitForSelector('[data-set="stale_minutes"]', { timeout: 10000 });
    const card = page.locator('.rl-card', { has: page.locator('[data-set="stale_minutes"]') });
    await card.scrollIntoViewIfNeeded();
    const input = page.locator('[data-set="stale_minutes"]');
    const before = await input.inputValue();
    const geo = await page.evaluate(() => {
      const i = document.querySelector('[data-set="stale_minutes"]'), l = i.closest('label'), lock = document.querySelector('[data-set="idle_hours"]').closest('label');
      const r = l.getBoundingClientRect(), q = lock.getBoundingClientRect();
      return { label: l.firstChild.textContent.trim(), w: Math.round(r.width), sameRowAsLock: Math.abs(r.top - q.top) < 2, sw: document.documentElement.scrollWidth, vw: innerWidth };
    });
    await card.screenshot({ path: path.join(OUT, 'admin-' + w + '--a-setting.jpg'), type: 'jpeg', quality: 85 });

    await input.fill('4');
    await page.click('[data-oa="settings"]');
    await page.waitForTimeout(700);
    const refused = await page.evaluate(() => { const t = Array.from(document.querySelectorAll('[role=status], .rl-toast, .toast, .rl-err')).map((x) => x.textContent.trim()).filter(Boolean); return t.join(' | '); });
    await page.screenshot({ path: path.join(OUT, 'admin-' + w + '--b-refused-4.jpg'), type: 'jpeg', quality: 80 });

    await input.fill(w < 700 ? '45' : '30');
    await page.click('[data-oa="settings"]');
    await page.waitForSelector('.rl-ok', { timeout: 5000 });
    await page.locator('.rl-card', { has: page.locator('[data-set="stale_minutes"]') }).scrollIntoViewIfNeeded();
    const saved = await page.locator('[data-set="stale_minutes"]').inputValue();
    await page.screenshot({ path: path.join(OUT, 'admin-' + w + '--c-saved.jpg'), type: 'jpeg', quality: 80 });
    say('  ' + w + 'px: label "' + geo.label + '" (' + geo.w + 'px wide, same row as "Lock after": ' + geo.sameRowAsLock + '); as found ' + before
      + '; 4 refused with: ' + refused + '; saved ' + saved + '; scrollWidth ' + geo.sw + '/' + geo.vw);
    await ctx.close();
  }

  // The 45 saved at 390 above, before the 1280 pass put it back to 30, reached
  // the app with its state: shown under More → Sync on open.
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1' });
  const page = await ctx.newPage();
  const admin = await browser.newContext();
  const ap = await admin.newPage();
  await ap.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await ap.fill('input[name=email]', 'owner@example.com');
  await ap.fill('input[name=password]', 'preview-password');
  await Promise.all([ap.waitForNavigation({ waitUntil: 'networkidle' }), ap.click('button[type=submit]')]);
  const put = await ap.evaluate(async () => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/admin-api/owner-app/settings', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ stale_minutes: 45 }) });
    return r.status;
  });
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  const enrol = page.waitForResponse((r) => r.url().endsWith('/api/enrol'));
  await page.click('[data-enrol] button[type=submit]');
  const body = await (await enrol).json();
  await page.waitForTimeout(1500);
  await page.keyboard.press('Escape');
  await page.evaluate(() => { location.hash = '#/more'; });
  await page.waitForTimeout(800);
  const row = await page.evaluate(() => Array.from(document.querySelectorAll('.row')).map((r) => r.textContent).find((t) => /Sync on open/.test(t)));
  await page.screenshot({ path: path.join(OUT, 'phone-390--16-more-sync-on-open-45.jpg'), type: 'jpeg', quality: 82 });
  say('  delivered: PUT stale_minutes 45 -> ' + put + '; enrol response stale_minutes ' + body.stale_minutes + '; More shows "' + (row || '').trim() + '"');
  await ap.evaluate(async () => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    await fetch('/admin-api/owner-app/settings', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ stale_minutes: 30 }) });
  });
  await browser.close();
  fs.appendFileSync(path.join(OUT, 'numbers.txt'), '\n' + lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
