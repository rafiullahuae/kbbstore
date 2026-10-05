/*
 * LANE OA3 — Platform → Users & Roles → Owner app → (address card) → Custom
 * address, at 390 and 1280: empty, a refused value with its message, saved.
 *
 *   sh tools/mac-preview.sh 10340
 *   KBB_BASE=http://127.0.0.1:10340 node tools/oa3-admin-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10340';
const OUT = path.join(__dirname, '..', 'docs', 'oa3-shots');
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const UA = {
  390: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
  1280: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
};
const PLAN = {
  390: { refused: 'rafistore-2027', saved: 'rafi_store-2027' },
  1280: { refused: 'refund_returns', saved: 'q7hz_rafi-m2xw9k4p' },
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  say('== Platform → Users & Roles → Owner app → address card → Custom address');
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1, userAgent: UA[w], isMobile: w < 700, hasTouch: w < 700 });
    const page = await ctx.newPage();
    const dialogs = [];
    page.on('dialog', (d) => { dialogs.push(d.message()); d.accept(); });
    page.on('pageerror', (e) => say('PAGE ERROR ' + e.message));
    const keystrokeRequests = [];
    page.on('request', (r) => { if (r.url().includes('/admin-api/owner-app')) keystrokeRequests.push(r.method() + ' ' + r.url().replace(BASE, '')); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin?go=users', { waitUntil: 'networkidle' });
    await page.waitForSelector('#rlt_ownerapp', { timeout: 15000 });
    await page.click('#rlt_ownerapp');
    await page.waitForSelector('[data-cust]', { timeout: 10000 });
    const card = () => page.locator('.rl-card', { has: page.locator('[data-cust]') });
    await card().scrollIntoViewIfNeeded();

    const geo = await page.evaluate(() => {
      const i = document.querySelector('[data-cust]'), b = document.querySelector('[data-oa="custom"]'), cs = getComputedStyle(i);
      const copy = document.querySelector('[data-oa="copy"]').getBoundingClientRect(), r = i.getBoundingClientRect(), br = b.getBoundingClientRect();
      return { inW: Math.round(r.width), inH: Math.round(r.height), font: cs.fontSize, btnW: Math.round(br.width), btnH: Math.round(br.height),
        belowCopy: Math.round(r.top - copy.bottom), url: document.querySelector('[data-url]').textContent, sw: document.documentElement.scrollWidth, vw: innerWidth };
    });
    await card().screenshot({ path: path.join(OUT, w + '-a-empty.png') });
    say(`  ${w}px empty: input ${geo.inW}×${geo.inH}px, font ${geo.font}; button ${geo.btnW}×${geo.btnH}px; ${geo.belowCopy}px below the Copy link row; scrollWidth ${geo.sw}/${geo.vw}; address ${geo.url}`);

    const before = keystrokeRequests.length;
    await page.fill('[data-cust]', '');
    await page.type('[data-cust]', PLAN[w].refused, { delay: 15 });
    const typedReq = keystrokeRequests.length - before;
    await page.click('[data-oa="custom"]');
    await page.waitForSelector('.oaa-err', { timeout: 5000 });
    const refused = await page.locator('.oaa-err').textContent();
    await card().scrollIntoViewIfNeeded();
    await card().screenshot({ path: path.join(OUT, w + '-b-refused.png') });
    say(`  ${w}px refused "${PLAN[w].refused}": ${refused}  (requests while typing ${PLAN[w].refused.length} keys: ${typedReq})`);

    await page.fill('[data-cust]', '');
    await page.type('[data-cust]', PLAN[w].saved, { delay: 15 });
    const hint = (await page.locator('[data-cust-hint]').textContent()) || '(none)';
    await page.click('[data-oa="custom"]');
    await page.waitForSelector('.rl-ok', { timeout: 5000 });
    const flash = await page.locator('.rl-ok').textContent();
    const url = await page.locator('[data-url]').textContent();
    await page.locator('.rl-ok').scrollIntoViewIfNeeded();
    const sw2 = await page.evaluate(() => document.documentElement.scrollWidth);
    await page.screenshot({ path: path.join(OUT, w + '-c-saved.png') });
    say(`  ${w}px saved "${PLAN[w].saved}": live hint while typing: ${hint}`);
    say(`      confirm: ${JSON.stringify(dialogs[dialogs.length - 1] || '')}`);
    say(`      flash: ${flash}`);
    say(`      address now ${url}; scrollWidth ${sw2}/${w}`);
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'shots-log.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
