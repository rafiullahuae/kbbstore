/*
 * Lane AT — "Time on site & engagement" on the Analytics board, in the
 * console at 1280, 1600 and 390, in the owner's arrangement from the brief
 * (revenue by source left, Sources and Top pages to its right) and in the
 * default one; plus the numbers: each card's height and bottom edge in that
 * row, page scrollWidth, console errors, and every request the screen makes
 * in 20 s with the board open (the block must add none).
 *
 *   BASE=http://127.0.0.1:10760 node tools/at-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10760';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-at-shots';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const say = (s) => process.stdout.write(s + '\n');

async function open(ctx, range) {
  const page = await ctx.newPage();
  const errors = [];
  const reqs = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + (m.location() || {}).url); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  page.on('requestfailed', (r) => errors.push('failed ' + r.url()));
  page.on('dialog', (d) => d.accept());
  page.on('request', (r) => reqs.push([Date.now(), r.method() + ' ' + r.url().replace(BASE, '')]));
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin#site-analytics', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  await page.evaluate(() => window.go('site-analytics'));
  await page.waitForSelector('.an-eng', { timeout: 15000 });
  if (range && range !== 'today') {
    await page.click(`[data-an-range="${range}"]`);
    await page.waitForTimeout(1200);
  }
  await page.waitForTimeout(600);
  return { page, errors, reqs };
}

async function measure(page) {
  return page.evaluate(() => {
    const r = (sel) => { const el = document.querySelector(sel); if (!el) return null; const b = el.getBoundingClientRect(); return { top: Math.round(b.top), h: Math.round(b.height), bottom: Math.round(b.bottom), left: Math.round(b.left), w: Math.round(b.width) }; };
    const fs = (sel) => { const el = document.querySelector(sel); return el ? getComputedStyle(el).fontSize : null; };
    return {
      scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
      revsrcCell: r('[data-blk="revsrc"]'), revsrcCard: r('[data-an="b-revsrc"] .card'), engage: r('.an-eng'),
      sources: r('[data-an="b-sources"] .card'), pages: r('[data-an="b-pages"] .card'),
      tileFont: fs('.an-et b'), fineFont: fs('.an-fine'), lists: document.querySelectorAll('.an-eng .bl li').length,
      more: r('.an-more'), srcRows: document.querySelectorAll('.an-eng > ul.bl li').length,
      tiles: Array.from(document.querySelectorAll('.an-et b')).map((b) => b.textContent),
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const layout of ['owner', 'default']) {
    for (const width of [1280, 1600, 390]) {
      const ctx = await browser.newContext({ viewport: { width, height: 1000 }, userAgent: width < 700 ? IPHONE : undefined, deviceScaleFactor: 1 });
      const { page, errors, reqs } = await open(ctx, 'today');
      if (layout === 'owner' && width === 1280) {
        // The owner's arrangement from the brief, saved through the board's own PUT.
        await page.evaluate(async () => {
          const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]*)/) || [])[1] || '');
          await fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/site-analytics/layout', { method: 'PUT', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': x },
            body: JSON.stringify({ order: ['live', 'feed', 'strip', 'revsrc', 'sources', 'pages', 'ccnow', 'pagesnow', 'srcnow', 'daily', 'campaigns', 'utm', 'funnel', 'entry', 'search', 'google', 'referrers', 'devices', 'langs'], hidden: [] }) });
        });
        await page.reload({ waitUntil: 'networkidle' });
        await page.evaluate(() => window.go('site-analytics'));
        await page.waitForSelector('.an-eng', { timeout: 15000 });
        await page.waitForTimeout(900);
      }
      if (layout === 'default' && width === 1280) {
        // Back to the default order for this and the following shots.
        await page.evaluate(async () => {
          const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]*)/) || [])[1] || '');
          await fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/site-analytics/layout', { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': x } });
        });
        await page.reload({ waitUntil: 'networkidle' });
        await page.evaluate(() => window.go('site-analytics'));
        await page.waitForSelector('.an-eng', { timeout: 15000 });
        await page.waitForTimeout(900);
      }
      const m = await measure(page);
      say(`${layout} ${width}: ${JSON.stringify(m)}`);
      if (m.engage && width >= 1280) {
        const col = [m.revsrcCell && m.engage.bottom, m.sources && m.sources.bottom, m.pages && m.pages.bottom];
        say(`  row bottoms (revsrc cell / sources / pages): ${col.join(' / ')}`);
      }
      // The row with the block, then the whole board.
      const top = Math.max(0, (m.revsrcCell ? m.revsrcCell.top : 0) - 70);
      const h = Math.max(m.revsrcCell ? m.revsrcCell.h : 0, m.sources ? m.sources.h : 0, m.pages ? m.pages.h : 0) + 140;
      const tall = await page.evaluate(() => Math.ceil(document.querySelector('[data-an]').getBoundingClientRect().bottom + 40));
      await page.setViewportSize({ width, height: Math.max(1000, tall) });
      await page.waitForTimeout(300);
      if (width < 700) {
        const e = await page.evaluate(() => { const b = document.querySelector('[data-blk="revsrc"]').getBoundingClientRect(); return { top: Math.round(b.top + window.scrollY), h: Math.round(b.height) }; });
        await page.screenshot({ path: `${OUT}/${layout}-block-${width}.png`, clip: { x: 0, y: Math.max(0, e.top - 20), width, height: e.h + 40 } });
      } else {
        await page.screenshot({ path: `${OUT}/${layout}-row-${width}.png`, clip: { x: 0, y: top, width, height: h } });
      }
      if (layout === 'owner') await page.screenshot({ path: `${OUT}/${layout}-board-${width}.png` });
      if (layout === 'owner' && width === 1280) {
        // Twenty seconds open: what the screen asks for. The live poll (15 s)
        // is the board's own; nothing else may appear.
        const t0 = Date.now();
        await page.waitForTimeout(20000);
        const later = reqs.filter(([t]) => t >= t0).map(([, u]) => u);
        say(`  requests in 20 s with the board open: ${later.length ? later.join(' | ') : 'none'}`);
        // "More" opened: the rest of the figures, and the small print.
        await page.click('.an-more > summary');
        await page.waitForTimeout(300);
        const cell = await page.evaluate(() => { const b = document.querySelector('[data-blk="revsrc"]').getBoundingClientRect(); return { top: Math.round(b.top + window.scrollY), h: Math.round(b.height) }; });
        say(`  "more" open: revsrc cell ${cell.h}px`);
        await page.screenshot({ path: `${OUT}/owner-more-open-${width}.png`, clip: { x: 0, y: Math.max(0, cell.top - 70), width, height: cell.h + 110 } });
        await page.click('.an-more > summary');
        // Range 7 days: the block in its "recorded from" state.
        await page.setViewportSize({ width, height: 1000 });
        await page.click('[data-an-range="7d"]');
        await page.waitForTimeout(1500);
        const m7 = await measure(page);
        say(`  7d: ${JSON.stringify({ engage: m7.engage, tiles: m7.tiles, revsrcCard: m7.revsrcCard, sources: m7.sources, pages: m7.pages })}`);
        const t7 = Math.max(0, m7.revsrcCell.top - 70);
        await page.setViewportSize({ width, height: Math.max(1000, t7 + 1400) });
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/owner-row-7d-${width}.png`, clip: { x: 0, y: t7, width, height: Math.max(m7.revsrcCell.h, m7.pages ? m7.pages.h : 0) + 140 } });
      }
      if (layout === 'owner' && width === 1280) {
        // A shop with orders from only two sources: the card takes the rest of
        // the row (flex:1) and shows source rows that fit (row counts only).
        // The summary's orders_by_channel is cut to two rows in the test
        // harness; nothing else changes.
        await page.route(/\/admin-api\/site-analytics\?/, async (route) => {
          const res = await route.fetch();
          const j = await res.json();
          j.orders_by_channel = (j.orders_by_channel || []).slice(0, 2);
          await route.fulfill({ response: res, json: j });
        });
        await page.setViewportSize({ width, height: 1000 });
        await page.click('[data-an-range="yesterday"]');
        await page.waitForTimeout(400);
        await page.click('[data-an-range="today"]');
        await page.waitForTimeout(1500);
        const m2 = await measure(page);
        say(`  two order sources: ${JSON.stringify({ revsrcCard: m2.revsrcCard, engage: m2.engage, srcRows: m2.srcRows, sources: m2.sources, pages: m2.pages })}`);
        await page.setViewportSize({ width, height: Math.max(1000, m2.revsrcCell.bottom + 200) });
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/owner-row-two-sources-${width}.png`, clip: { x: 0, y: Math.max(0, m2.revsrcCell.top - 70), width, height: Math.max(m2.revsrcCell.h, m2.pages.h) + 140 } });
        await page.unroute(/\/admin-api\/site-analytics\?/);
      }
      say(`  console errors: ${errors.length ? errors.join(' | ') : 'none'}`);
      await ctx.close();
    }
  }
  await browser.close();
})();
