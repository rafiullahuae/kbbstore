// Lane CX: Shop by skin concern (design E) on the homepage at 390 and 1280,
// /ar at 390, the admin panel at 1280 and 390, plus the measurements the
// brief asks for: section height, chip and circle size, scrollWidth, every
// chip answering elementFromPoint, a real click that navigates, console errors.
// Usage: node tools/cx-shots.cjs http://127.0.0.1:9931 docs/lane-cx-shots
const { chromium } = require('playwright');
(async () => {
  const BASE = process.argv[2] || 'http://127.0.0.1:9931';
  const out = process.argv[3] || 'docs/lane-cx-shots';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = {};

  async function shop(path, w, tag) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', r => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    await page.goto(BASE + path, { waitUntil: 'networkidle' });
    const sec = await page.$('.hs-concerns');
    if (!sec) { console.log(tag, 'NO SECTION'); await ctx.close(); return; }
    await sec.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    const m = await page.evaluate(() => {
      const s = document.querySelector('.hs-concerns');
      const prev = s.previousElementSibling;
      const list = s.querySelector('.hs-cc-list');
      const a = s.querySelector('.hs-cc-i');
      const ic = s.querySelector('.hs-cc-ic');
      const cs = getComputedStyle(a);
      const r = s.getBoundingClientRect();
      return {
        after: prev ? prev.className : null,
        sectionH: Math.round(r.height), sectionW: Math.round(r.width),
        chips: s.querySelectorAll('.hs-cc-i').length,
        chipW: Math.round(a.getBoundingClientRect().width), chipH: Math.round(a.getBoundingClientRect().height),
        circle: Math.round(ic.getBoundingClientRect().width), font: cs.fontSize, weight: cs.fontWeight,
        bg: getComputedStyle(s).backgroundImage.slice(0, 80),
        listScrollW: list.scrollWidth, listClientW: list.clientWidth, overflowX: getComputedStyle(list).overflowX,
        docScrollW: document.documentElement.scrollWidth, innerW: window.innerWidth,
        h2: (s.querySelector('h2') || {}).textContent, dir: document.documentElement.dir,
      };
    });
    // Every chip answers a click at its centre: elementFromPoint lands inside it.
    const hits = await page.evaluate(async () => {
      const res = [];
      for (const a of document.querySelectorAll('.hs-concerns .hs-cc-i')) {
        a.scrollIntoView({ block: 'center', inline: 'center' });
        await new Promise(r => setTimeout(r, 120));
        const b = a.getBoundingClientRect();
        const pts = [[b.left + b.width / 2, b.top + b.height / 2], [b.left + 12, b.top + b.height / 2], [b.right - 8, b.top + b.height / 2]];
        res.push(pts.every(([x, y]) => { const el = document.elementFromPoint(x, y); return el && el.closest('a') === a; }));
      }
      return res;
    });
    m.elementFromPoint = hits.every(Boolean) ? `all ${hits.length} chips, 3 points each` : JSON.stringify(hits);
    // Speculation rules exclude nothing the chips point at.
    m.prefetchRules = await page.evaluate(() => { const s = document.querySelector('script[type=speculationrules]'); return s ? s.textContent.slice(0, 160) : 'none'; });
    await sec.scrollIntoViewIfNeeded();
    await page.evaluate(() => { const l = document.querySelector('.hs-cc-list'); l.scrollLeft = 0; });
    await page.waitForTimeout(250);
    const box = await sec.boundingBox();
    const y0 = Math.max(0, box.y - (w > 500 ? 260 : 220));
    await page.screenshot({ path: `${out}/${tag}-crop.png`, clip: { x: 0, y: y0, width: w, height: Math.min(box.y + box.height + 30, y0 + 2000) - y0 } });
    await sec.screenshot({ path: `${out}/${tag}-section.png` });
    if (!path.startsWith('/ar')) await page.screenshot({ path: `${out}/${tag}-full.png`, fullPage: true });
    // A real click on the whole capsule — on its icon, not its words — navigates.
    const href = await page.$eval('.hs-concerns .hs-cc-i:nth-child(1), .hs-concerns li:nth-child(2) .hs-cc-i', a => a.getAttribute('href'));
    const target = await page.$('.hs-concerns li:nth-child(2) .hs-cc-ic');
    await target.scrollIntoViewIfNeeded();
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), target.click()]);
    m.clickOnIcon = { href, landed: page.url().replace(BASE, ''), status: 'navigated' };
    m.consoleErrors = errors;
    report[tag] = m;
    console.log(tag, JSON.stringify(m));
    await ctx.close();
  }

  await shop('/', 1280, 'home-1280');
  await shop('/', 390, 'home-390');
  await shop('/ar/', 390, 'ar-390');
  await shop('/ar/', 1280, 'ar-1280');

  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1200 : 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('hpcontent'));
    // The wording tabs load on first use; then Trending, Skin concerns.
    await page.waitForSelector('[data-hpc-tab="copy"]', { timeout: 20000 });
    await page.click('[data-hpc-tab="copy"]');
    await page.waitForSelector('[data-hpc-tab="concerns"]', { timeout: 20000 });
    await page.click('[data-hpc-tab="concerns"]');
    await page.waitForSelector('.kcc', { timeout: 20000 });
    await page.waitForTimeout(300);
    const am = await page.evaluate(() => ({ rows: document.querySelectorAll('.kcc-row').length, scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
      tabs: [...document.querySelectorAll('[data-hpc-tab]')].map(t => t.dataset.hpcTab).join(',') }));
    console.log('admin', w, JSON.stringify(am), errors);
    await page.screenshot({ path: `${out}/admin-tab-${w}.png`, fullPage: true });
    if (w > 500) {
      // The editor working: move Oily Skin to the top, type a label, add a chip.
      await page.click('[data-kcc-mv="6|-1"]'); await page.click('[data-kcc-mv="5|-1"]');
      await page.fill('[data-kcc-f="l"][data-kcc-i="0"]', 'Glow skin');
      await page.click('[data-kcc-add]');
      await page.waitForTimeout(200);
      const val = await page.$eval('.kcc input[type=hidden]', i => i.value);
      console.log('admin edited value', val.slice(0, 220));
      await page.screenshot({ path: `${out}/admin-tab-edited-${w}.png`, fullPage: true });
      // All sections → Skin concerns → Edit content, the popup.
      await page.click('[data-hpc-tab="all"]');
      await page.waitForSelector('[data-hph-edit="concerns"]', { timeout: 20000 });
      await page.$eval('[data-hph-key="trending"]', el => el.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(200);
      await page.screenshot({ path: `${out}/admin-all-sections-${w}.png` });
      await page.click('[data-hph-edit="concerns"]');
      await page.waitForSelector('.hph-dlg', { timeout: 20000 });
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${out}/admin-popup-${w}.png` });
    }
    await ctx.close();
  }
  require('fs').writeFileSync(`${out}/measurements.json`, JSON.stringify(report, null, 2));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
