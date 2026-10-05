/*
 * LANE OA4 — My store's sales hero (the bug fix) and top sellers, photographed.
 *
 *   KBB_BASE=http://127.0.0.1:P KBB_APP=/<secret> node tools/oa4-dash-shots.cjs
 *
 * Against tools/mac-preview.sh. Every range at 390 (iPhone UA) and 820 (Android
 * tablet UA); Total-only (the default); Gross/Net switched on; Top performers on
 * 7 days and This month; and, beside each, the same period's figures from
 * Store → Analytics (/admin-api/analytics) to show they agree.
 * Output: docs/oa4-shots/dash-*.jpg and docs/oa4-shots/dash-numbers.txt.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE;
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'oa4-shots');
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
const DEV = {
  'phone-390': { w: 390, h: 844, dpr: 2, ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1' },
  'tablet-820': { w: 820, h: 1180, dpr: 1, ua: 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' },
};
const RANGES = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7d', 'Last 7 days'], ['month', 'This month'], ['last_month', 'Last month']];

async function adminPage(browser) {
  const page = await (await browser.newContext()).newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  return page;
}
const call = (page, method, url, body) => page.evaluate(async ([m, u, b]) => {
  const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
  const r = await fetch(u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: b ? JSON.stringify(b) : undefined });
  return r.json();
}, [method, url, body]);

async function grossNet(admin, on) {
  const g = await call(admin, 'GET', '/admin-api/owner-app/ui');
  const ui = g.ui;
  ui.functions.gross_net = on;
  await call(admin, 'PUT', '/admin-api/owner-app/ui', ui);
}

async function phone(browser, d, dev) {
  const ctx = await browser.newContext({ viewport: { width: d.w, height: d.h }, deviceScaleFactor: d.dpr, isMobile: d.w < 700, hasTouch: true, userAgent: d.ua });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  const page = await ctx.newPage();
  const reqs = [];
  page.on('request', (q) => { if (q.url().includes('/api/dashboard')) reqs.push(q.url().split('/api/')[1]); });
  page.on('pageerror', (e) => say('  PAGE ERROR ' + e.message));
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'oa4-dash-' + dev);
  await page.click('[data-enrol] button[type=submit]');
  await page.waitForSelector('.hero [data-fig]', { timeout: 15000 });
  await sleep(600);
  return { ctx, page, reqs };
}
const heroNums = (page) => page.evaluate(() => {
  const h = document.querySelector('.hero');
  return { range: (h.querySelector('.rng') || h.querySelector('.kick')).textContent.trim(), chips: Array.from(h.querySelectorAll('[data-dview]')).map((b) => b.textContent),
    fig: h.querySelector('[data-fig]').textContent, delta: (h.querySelector('.delta') || {}).textContent || '', bars: h.querySelectorAll('svg rect').length,
    paid: h.querySelector('.mets b').textContent, span: h.querySelector('.upd span:last-child').textContent, heroH: Math.round(h.getBoundingClientRect().height), sw: document.documentElement.scrollWidth };
});

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  const admin = await adminPage(browser);
  await grossNet(admin, false);
  const an = {};
  const fmt = (k) => ({ today: 'period=today', month: 'period=month' }[k]);
  for (const [k] of RANGES) {
    const today = new Date();
    const dstr = (dt) => new Date(dt.getTime() + 4 * 3600e3).toISOString().slice(0, 10);   // Dubai
    const t = new Date(Date.now());
    let q = fmt(k);
    if (!q) {
      const day = (n) => dstr(new Date(t.getTime() - n * 864e5));
      const local = new Date(t.getTime() + 4 * 3600e3);
      const lmEnd = new Date(Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), 0)), lmStart = new Date(Date.UTC(local.getUTCFullYear(), local.getUTCMonth() - 1, 1));
      q = k === 'yesterday' ? 'period=custom&from=' + day(1) + '&to=' + day(1) : k === '7d' ? 'period=custom&from=' + day(6) + '&to=' + day(0)
        : 'period=custom&from=' + lmStart.toISOString().slice(0, 10) + '&to=' + lmEnd.toISOString().slice(0, 10);
    }
    const a = await call(admin, 'GET', '/admin-api/analytics?' + q);
    an[k] = a;
    void today;
  }

  for (const [dev, d] of Object.entries(DEV)) {
    say('\n== ' + dev + ' — default (Total only)');
    let { ctx, page, reqs } = await phone(browser, d, dev);
    await page.screenshot({ path: path.join(OUT, 'dash-' + dev + '--my-store-default.jpg'), type: 'jpeg', quality: 84 });
    for (const [k, label] of RANGES) {
      if (k !== 'today') {
        reqs.length = 0;
        await page.click('[data-range]');
        await page.waitForSelector('.sheet.open [data-rk="' + k + '"]');
        await sleep(350);
        if (k === '7d') await page.screenshot({ path: path.join(OUT, 'dash-' + dev + '--range-sheet.jpg'), type: 'jpeg', quality: 84 });
        await page.click('.sheet.open [data-rk="' + k + '"]');
        await page.waitForFunction((l) => { const b = document.querySelector('.hero .rng'); return b && b.textContent.trim() === l && !document.querySelector('.hero.busy'); }, label);
        await sleep(450);
      }
      const n = await heroNums(page);
      await page.locator('.hero').screenshot({ path: path.join(OUT, 'dash-' + dev + '--total-' + k + '.jpg'), type: 'jpeg', quality: 84 });
      const a = an[k];
      say('  ' + label + ': chips ' + JSON.stringify(n.chips) + '; AED ' + n.fig + ' (Analytics: net revenue ' + a.revenue_total_aed + ', gross ' + a.gross_revenue_aed + ', VAT ' + a.tax_collected_aed + ', paid orders ' + a.paid_orders + '); paid ' + n.paid
        + '; ' + n.bars + ' bars; "' + n.delta.trim() + '"; "' + n.span + '"; hero ' + n.heroH + 'px; scrollWidth ' + n.sw + '/' + d.w + (k === 'today' ? '' : '; requests: ' + reqs.join(', ')));
    }
    await page.evaluate(() => { location.hash = '#/orders'; });
    await sleep(500);
    await page.evaluate(() => { location.hash = '#/'; });
    await page.waitForSelector('.tp');
    await sleep(500);
    for (const tp of ['7d', 'month']) {
      reqs.length = 0;
      await page.locator('.tp').scrollIntoViewIfNeeded();
      await page.click('[data-tp="' + tp + '"]');
      await page.waitForFunction((t) => { const b = document.querySelector('[data-tp="' + t + '"]'); return b && b.classList.contains('on') && !document.querySelector('.tp.busy'); }, tp);
      await sleep(400);
      await page.locator('.tp').screenshot({ path: path.join(OUT, 'dash-' + dev + '--top-' + tp + '.jpg'), type: 'jpeg', quality: 84 });
      const rows = await page.evaluate(() => Array.from(document.querySelectorAll('.tp .row')).map((r) => r.querySelector('.rm b').textContent + ' ×' + r.querySelector('.re b').textContent + ' ' + ((r.querySelector('.rm small') || {}).textContent || '')));
      say('  Top performers · ' + tp + ': ' + JSON.stringify(rows) + '; requests: ' + reqs.join(', '));
    }
    await ctx.close();

    say('== ' + dev + ' — Gross and Net switched on');
    await grossNet(admin, true);
    ({ ctx, page, reqs } = await phone(browser, d, dev + '-gn'));
    for (const v of ['total', 'gross', 'net']) {
      await page.click('[data-dview="' + v + '"]');
      await sleep(250);
      const n = await heroNums(page);
      if (v !== 'total') await page.locator('.hero').screenshot({ path: path.join(OUT, 'dash-' + dev + '--grossnet-' + v + '.jpg'), type: 'jpeg', quality: 84 });
      else await page.locator('.hero').screenshot({ path: path.join(OUT, 'dash-' + dev + '--grossnet-total.jpg'), type: 'jpeg', quality: 84 });
      say('  ' + v + ': chips ' + JSON.stringify(n.chips) + '; AED ' + n.fig + '; "' + n.delta.trim() + '"; bars ' + n.bars);
    }
    say('  (Analytics today: gross ' + an.today.gross_revenue_aed + ', net revenue ' + an.today.revenue_total_aed + ', VAT ' + an.today.tax_collected_aed + ' -> app Net = ' + (an.today.revenue_total_aed - an.today.tax_collected_aed) + ')');
    await ctx.close();
    await grossNet(admin, false);
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'dash-numbers.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
