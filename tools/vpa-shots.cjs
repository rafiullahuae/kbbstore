/* Lane VPA: the variant picker in a real Chromium.
   node tools/vpa-shots.cjs <base-url> <out-dir> [before]
   Taps a swatch on a card and on the product page and checks that the price,
   the picture and the stock line move; adds a shade to the basket and checks
   the basket holds THAT shade; taps a sold-out shade and checks it cannot be
   added. Writes pictures and a JSON of every measurement. Exit 1 on a failed
   check. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10570';
const OUT = path.resolve(process.argv[3] || 'docs/lane-vpa-shots');
const BEFORE = process.argv[4] === 'before';
fs.mkdirSync(OUT, { recursive: true });
const results = { checks: [], measures: {} };
let failed = 0;
const check = (name, ok, detail) => { results.checks.push({ name, ok: !!ok, detail }); if (!ok) { failed++; console.log('FAIL', name, detail || ''); } else console.log('ok  ', name); };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const shoot = async (ctxOpts, url, file, fn) => {
    const ctx = await browser.newContext(ctxOpts);
    // CLS, the browser's own count: every layout shift not caused by input.
    await ctx.addInitScript(() => {
      window.__cls = 0;
      try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
    });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error' && !/status of (404|422)/.test(m.text())) errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/api/cart/add')) errors.push(r.status() + ' ' + r.url()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    results.measures['cls ' + url + ' ' + ctxOpts.viewport.width] = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    const out = fn ? await fn(page) : null;
    if (file) await page.screenshot({ path: path.join(OUT, file), fullPage: false });
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    await ctx.close();
    return { out, errors, sw };
  };
  // A real browser's user agent: the shop's bot gate refuses "HeadlessChrome"
  // on the basket endpoints, exactly as it should.
  const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' };
  const laptop = { viewport: { width: 1280, height: 860 },
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' };
  const tag = BEFORE ? 'before' : 'after';

  // ── Grids and pages, EN and AR, 390 and 1280 ───────────────────────────
  for (const [lang, pre] of [['en', ''], ['ar', '/ar']]) {
    for (const [w, opts] of [['390', phone], ['1280', laptop]]) {
      const g = await shoot(opts, pre + '/collections/vpa-makeup/', `grid-${lang}-${w}-${tag}.png`, async (page) => {
        const card = page.locator('.kbb-tile', { hasText: 'TIRTIR' }).first();
        await card.scrollIntoViewIfNeeded();
        await page.evaluate(() => window.scrollBy(0, -60));
        return null;
      });
      check(`grid ${lang} ${w}: no console errors`, g.errors.length === 0, g.errors.join(' | '));
      check(`grid ${lang} ${w}: no sideways scroll`, g.sw[0] <= g.sw[1], g.sw);
      const p = await shoot(opts, pre + '/product/vpa-tirtir/', `pdp-${lang}-${w}-${tag}.png`, async (page) => {
        // Before, the options are display:none -- the defect -- so the shot is
        // framed on the stock line where they should have been.
        const box = page.locator(BEFORE ? '.stockline' : '#vpa');
        if (await box.count()) await box.first().scrollIntoViewIfNeeded();
        await page.evaluate(() => window.scrollBy(0, -140));
        return page.evaluate(() => { const b = document.querySelector('.pm-bundles'); return b ? getComputedStyle(b).display : 'none'; });
      });
      results.measures[`pdp-${lang}-${w}-${tag}-options-display`] = p.out;
      check(`pdp ${lang} ${w}: no console errors`, p.errors.length === 0, p.errors.join(' | '));
      check(`pdp ${lang} ${w}: no sideways scroll`, p.sw[0] <= p.sw[1], p.sw);
    }
  }
  if (BEFORE) { fs.writeFileSync(path.join(OUT, 'measure-before.json'), JSON.stringify(results, null, 2)); await browser.close(); process.exit(0); }

  // ── Card: tap a dot, the price and the picture move ─────────────────────
  for (const [w, opts] of [['390', phone], ['1280', laptop]]) {
    await shoot(opts, '/collections/vpa-makeup/', w === '390' ? 'card-tap-390.png' : null, async (page) => {
      const card = page.locator('.kbb-tile', { hasText: 'MISSHA' }).first();
      // Card at the top of the screen, clear of the chat bubble in the corner.
      await card.evaluate((c) => { c.scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); });
      const before = await card.evaluate((c) => [c.querySelector('.cp').textContent.trim(), c.querySelector('.kbb-card-img').getAttribute('src')]);
      const dot = card.locator('.vpc-sw').nth(2);
      // Clickable on the first try: the dot itself is what is under its centre.
      const hit = await dot.evaluate((b) => { const r = b.getBoundingClientRect(); return document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) === b; });
      check(`card ${w}: the dot is what is under the finger`, hit);
      await dot.click();
      const after = await card.evaluate((c) => [c.querySelector('.cp').textContent.trim(), c.querySelector('.kbb-card-img').getAttribute('src'), c.querySelector('.vpc-sw[aria-pressed="true"]').getAttribute('aria-label'), location.pathname]);
      check(`card ${w}: price moved to the shade's own`, before[0] !== after[0] && /128/.test(after[0]), [before[0], after[0]]);
      check(`card ${w}: picture moved to the shade's own`, /missha-27/.test(after[1]), after[1]);
      check(`card ${w}: tapping a dot does not navigate`, after[3].includes('/collections/'), after[3]);
      check(`card ${w}: the tapped dot is pressed`, /#27 Honey Beige/.test(after[2]), after[2]);
      // The name is still a link that answers on the first click. The chat
      // bubble is the shop's own corner overlay, not part of the card; it is
      // set aside so the measurement is of the tile.
      await page.evaluate(() => document.querySelectorAll('[id^="kbbWa"],.kbw,.kbw-b').forEach((n) => { n.style.display = 'none'; }));
      const name = card.locator('a.cn');
      const nameHit = await name.evaluate((a) => { const r = a.getBoundingClientRect(); const el = document.elementFromPoint(r.left + 8, r.top + r.height / 2); return a.contains(el); });
      check(`card ${w}: the name link is still clickable`, nameHit);
      results.measures[`card-${w}`] = { before, after };
    });
  }

  // ── Prefetch still fires and is used from a card with dots ──────────────
  {
    const ctx = await browser.newContext(laptop);
    const page = await ctx.newPage();
    await page.goto(BASE + '/collections/vpa-makeup/', { waitUntil: 'networkidle' });
    const link = page.locator('.kbb-tile', { hasText: 'TIRTIR' }).locator('a.cn');
    await link.hover();
    await page.waitForTimeout(700);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), link.click()]);
    const delivery = await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType || '');
    results.measures['prefetch delivery (card name -> product)'] = delivery;
    check('prefetch: the product page came from the prefetch', delivery === 'navigational-prefetch', delivery);
    await ctx.close();
  }

  // ── Page: tap a shade on sale, a sold-out shade; add one to the basket ──
  for (const [w, opts] of [['390', phone], ['1280', laptop]]) {
    const ctx = await browser.newContext(opts);
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error' && !/status of (404|422)/.test(m.text())) errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/api/cart/add')) errors.push(r.status() + ' ' + r.url()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/product/vpa-tirtir/', { waitUntil: 'networkidle' });
    const read = () => page.evaluate(() => ({
      label: document.querySelector('.vpa-lbl b')?.textContent,
      price: document.querySelector('#bbPrice .now')?.textContent.trim(),
      was: document.querySelector('#bbPrice s')?.textContent.trim() || '',
      wasShown: (() => { const s = document.querySelector('#bbPrice s'); return !!s && s.style.display !== 'none'; })(),
      stock: document.querySelector('.stockline')?.textContent.trim(),
      out: document.querySelector('.stockline')?.classList.contains('out'),
      add: document.querySelector('#mainAdd')?.disabled,
      addText: document.querySelector('#mainAdd')?.textContent.trim(),
      img: document.querySelector('#gmainImg')?.getAttribute('src'),
      sku: document.querySelector('#vpaSku')?.textContent.trim(),
      vid: document.querySelector('#kbbVarId')?.value,
    }));
    const start = await read();
    await page.locator('#vpa').scrollIntoViewIfNeeded();
    await page.locator('.vpa-sw[data-n="23N Sand"]').click();
    const sand = await read();
    check(`page ${w}: label names the shade`, sand.label === '23N Sand', sand.label);
    check(`page ${w}: price moved to the shade's sale price`, /129/.test(sand.price) && sand.wasShown && /145/.test(sand.was), sand);
    check(`page ${w}: picture moved to the shade's own`, /tirtir-23n/.test(sand.img || ''), sand.img);
    check(`page ${w}: SKU moved`, /TT-MF-23NSand/.test(sand.sku || ''), sand.sku);
    check(`page ${w}: still in stock and addable`, !sand.out && !sand.add, sand);
    if (w === '390') await page.screenshot({ path: path.join(OUT, 'pdp-tap-390.png') });
    await page.locator('.vpa-sw[data-n="17C Porcelain"]').click();
    const porcelain = await read();
    check(`page ${w}: a sold-out shade says so`, porcelain.out && porcelain.add && porcelain.label === '17C Porcelain', porcelain);
    if (w === '390') await page.screenshot({ path: path.join(OUT, 'pdp-soldout-390.png') });
    // The server refuses it too, whatever the page says.
    const refused = await page.evaluate(async (vid) => {
      const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const r = await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        body: JSON.stringify({ product_id: Number(document.querySelector('.kbb-cart-form').dataset.product_id), variant_id: Number(vid), quantity: 1 }) });
      return [r.status, (await r.json()).ok];
    }, porcelain.vid);
    check(`page ${w}: the server refuses the sold-out shade`, refused[0] === 422 && refused[1] === false, refused);
    // Add 21N Ivory and read the basket.
    await page.locator('.vpa-sw[data-n="21N Ivory"]').click();
    const ivory = await read();
    await page.locator('#mainAdd').click();
    await page.waitForTimeout(900);
    await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
    const cart = await page.evaluate(() => document.querySelector('.kbb-cartpage .items')?.innerText || '');
    check(`page ${w}: the basket holds 21N Ivory`, /Shade: 21N Ivory/.test(cart), cart.slice(0, 200));
    if (w === '390') await page.screenshot({ path: path.join(OUT, 'cart-variant-390.png') });
    check(`page ${w}: no console errors`, errors.length === 0, errors.join(' | '));
    results.measures[`page-${w}`] = { start, sand, porcelain, ivory, refused };
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'measure-after.json'), JSON.stringify(results, null, 2));
  await browser.close();
  console.log(failed ? `${failed} FAILED` : 'all checks passed');
  process.exit(failed ? 1 : 0);
})();
