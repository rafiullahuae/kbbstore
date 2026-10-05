/* Lane PS — every shopper interaction the brief names, exercised in Chromium on
 * one preview, written as JSON so the before tree and the after tree can be
 * compared field by field.
 *
 *   node tools/ps-interactions.cjs <base-url> <out.json>
 *
 * Pixels: the preview has no internet, so the Meta and Google loaders are
 * answered with an empty script and the calls the shop makes are read off the
 * queues their base tags create (fbq.queue, dataLayer). That is the shop's own
 * code firing, which is the thing a change here could break. */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const [BASE, OUT] = process.argv.slice(2);

const pixelCalls = () => ({
  fbq: (window.fbq && window.fbq.queue ? window.fbq.queue : []).map(a => [...a].slice(0, 2).join(':')),
  gtag: (window.dataLayer || []).map(a => (a && a[0] !== undefined ? [...a].slice(0, 2).join(':') : JSON.stringify(a).slice(0, 40))),
});

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const res = {};
  const run = async (name, width, fn) => {
    // A real browser's user agent: the cart API refuses HeadlessChrome ("The cart only answers a web browser").
    const ctx = await b.newContext({ viewport: { width, height: 860 }, isMobile: width < 500, hasTouch: width < 500,
      userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36' });
    await ctx.route(/connect\.facebook\.net|googletagmanager\.com|analytics\.tiktok\.com/, r => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
    const p = await ctx.newPage();
    const errors = [], failed = [];
    p.on('pageerror', e => errors.push(String(e.message).slice(0, 120)));
    p.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 120)); });
    p.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE)) failed.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    try { res[name] = { ...(await fn(p)), errors, failed }; } catch (e) { res[name] = { error: String(e.message).slice(0, 200), errors, failed }; }
    await ctx.close();
  };
  const go = (p, uri) => p.goto(BASE + uri, { waitUntil: 'load' });

  await run('home-load-390', 390, async p => { await go(p, '/'); await p.waitForTimeout(800); return { title: await p.title(), tiles: await p.locator('img.kbb-card-img').count(), pixels: await p.evaluate(pixelCalls) }; });

  await run('menu-390', 390, async p => {
    await go(p, '/');
    const before = await p.evaluate(() => document.body.className);
    await p.click('#burger'); await p.waitForTimeout(600);
    const after = await p.evaluate(() => document.body.className);
    const links = await p.locator('nav a:visible').count();
    return { bodyClassChanged: before !== after, after, visibleNavLinks: links };
  });

  await run('menu-1280', 1280, async p => {
    await go(p, '/');
    const item = p.locator('.navitem').first();
    await item.hover(); await p.waitForTimeout(500);
    return { navItems: await p.locator('.navitem').count(), firstHoverVisibleLinks: await item.locator('a:visible').count() };
  });

  await run('search-1280', 1280, async p => {
    await go(p, '/');
    // The suggestion panel fills from a debounced request; it is read until two
    // reads 600 ms apart agree, three times over, so a count is the settled
    // state and not wherever the request happened to be.
    const settledCounts = [];
    for (const q of ['serum', 'toner', 'serum']) {
      await p.fill('form.sbox input[name="s"]', ''); await p.waitForTimeout(300);
      await p.fill('form.sbox input[name="s"]', q);
      let last = -1, cur = -2;
      for (let k = 0; k < 20 && cur !== last; k++) { last = cur; await p.waitForTimeout(600); cur = await p.locator('form.sbox a:visible').count(); }
      settledCounts.push(q + ':' + cur);
    }
    await Promise.all([p.waitForLoadState('load'), p.press('form.sbox input[name="s"]', 'Enter')]);
    await p.waitForTimeout(500);
    return { url: p.url().replace(BASE, ''), settledSuggestions: settledCounts, results: await p.locator('#grid img.kbb-card-img').count() };
  });

  await run('filters-390', 390, async p => {
    await go(p, '/shop/');
    // The phone opener (.mobi-filter) is display:none in this fixture on BOTH
    // trees; its own onclick is what opens the drawer, so that is run instead.
    const openerVisible = await p.locator('.mobi-filter').isVisible();
    if (openerVisible) await p.click('.mobi-filter'); else await p.evaluate(() => document.body.classList.add('filters-open'));
    await p.waitForTimeout(500);
    const opened = await p.evaluate(() => document.body.classList.contains('filters-open'));
    const opt = p.locator('#filters a.fopt').first();
    const href = await opt.getAttribute('href');
    await Promise.all([p.waitForLoadState('load'), opt.click()]); await p.waitForTimeout(800);
    return { openerVisible, drawerOpened: opened, href, url: p.url().replace(BASE, ''), tilesAfter: await p.locator('#grid img.kbb-card-img').count() };
  });

  await run('slider-1280', 1280, async p => {
    await go(p, '/');
    const pp = p.locator('.kbbs-pp').first();
    const has = await pp.count();
    const label0 = has ? await pp.getAttribute('aria-label') : null;
    if (has) { await pp.click(); await p.waitForTimeout(300); }
    const label1 = has ? await pp.getAttribute('aria-label') : null;
    const next = p.locator('.kbbs [aria-label*="ext"], .kbbs-nx, .kbbs-next').first();
    let moved = null;
    if (await next.count()) {
      const s0 = await p.evaluate(() => document.querySelector('.kbbs-vp')?.scrollLeft ?? null);
      await next.click({ force: true }); await p.waitForTimeout(900);
      const s1 = await p.evaluate(() => document.querySelector('.kbbs-vp')?.scrollLeft ?? null);
      moved = s0 !== s1;
    }
    return { pauseButton: !!has, label0, label1, nextMovedSlide: moved };
  });

  await run('carousel-bundles-1280', 1280, async p => {
    await go(p, '/');
    const t = p.locator('#bndl-track');
    const s0 = await t.evaluate(e => e.scrollLeft);
    const nx = p.locator('.bndl [data-ymal-next]').first();
    if (await nx.count()) { await nx.click({ force: true }); await p.waitForTimeout(900); }
    return { scrolled: (await t.evaluate(e => e.scrollLeft)) !== s0 };
  });

  await run('add-to-cart-mini-cart-390', 390, async p => {
    await go(p, '/');
    const add = p.locator('a[data-kbb-add]').first();
    const name = await add.getAttribute('data-name');
    await add.scrollIntoViewIfNeeded(); await add.click(); await p.waitForTimeout(1500);
    const drawerOpen = await p.evaluate(() => document.getElementById('cart')?.classList.contains('on') || document.body.className.includes('cart'));
    const lines = await p.locator('#cart .kc-line, #cart [data-line], #cart li').count();
    const text = (await p.locator('#cart').innerText()).replace(/\s+/g, ' ').slice(0, 160);
    return { added: name, drawerOpen, cartHasProduct: text.includes((name || '').slice(0, 12)), lines, pixels: await p.evaluate(pixelCalls) };
  });

  await run('checkout-start-1280', 1280, async p => {
    await go(p, '/');
    await p.locator('a[data-kbb-add]').first().click(); await p.waitForTimeout(1500);
    const r = await p.goto(BASE + '/checkout/', { waitUntil: 'load' });
    await p.waitForTimeout(500);
    return { status: r.status(), url: p.url().replace(BASE, ''), hasForm: await p.locator('form').count(), pixels: await p.evaluate(pixelCalls) };
  });

  await run('product-page-1280', 1280, async p => {
    await go(p, '/product/heartleaf-77-soothing-toner/'); await p.waitForTimeout(600);
    const main = await p.evaluate(() => { const i = document.getElementById('gmainImg'); return i ? { cur: i.currentSrc.replace(location.origin, ''), w: i.naturalWidth } : null; });
    return { main, addButton: await p.locator('button[type=submit], [data-kbb-add]').count(), pixels: await p.evaluate(pixelCalls) };
  });

  await run('filters-1280', 1280, async p => {
    await go(p, '/shop/');
    const before = await p.locator('#grid img.kbb-card-img').count();
    const href = await p.locator('#filters a.fopt').first().getAttribute('href');
    const visible = await p.locator('#filters a.fopt').first().isVisible();
    // On a laptop the panel ships closed (body.filters-hidden); "Show filters" (#showFilters) opens it.
    if (!visible) { await p.click('#showFilters'); await p.waitForTimeout(400); }
    await Promise.all([p.waitForLoadState('load'), p.locator('#filters a.fopt').first().click({ force: true })]); await p.waitForTimeout(800);
    return { href, linkVisibleBeforeOpening: visible, url: p.url().replace(BASE, ''), tilesBefore: before, tilesAfter: await p.locator('#grid img.kbb-card-img').count() };
  });

  await run('quick-view-1280', 1280, async p => {
    await go(p, '/shop/');
    const btns = await p.locator('.qv-btn').count();
    if (!btns) return { quickViewButtons: 0 };
    const tile = p.locator('#grid > .kbb-tile').first();
    await tile.hover(); await tile.locator('.qv-btn').click({ force: true }); await p.waitForTimeout(1200);
    return { quickViewButtons: btns, open: await p.locator('.qv-back').first().isVisible().catch(() => false) };
  });

  await run('wishlist-1280', 1280, async p => {
    await go(p, '/product/heartleaf-77-soothing-toner/');
    const h = p.locator('[data-kbb-wish]').first();
    if (!(await h.count())) return { wishButtons: 0 };
    const c0 = await h.getAttribute('class'); const a0 = await h.getAttribute('aria-pressed');
    await h.click({ force: true }); await p.waitForTimeout(1200);
    const ids = await p.evaluate(async () => (await fetch('/wishlist/ids', { credentials: 'same-origin' })).text());
    return { wishButtons: await p.locator('[data-kbb-wish]').count(), classBefore: c0, classAfter: await h.getAttribute('class'), pressedBefore: a0, pressedAfter: await h.getAttribute('aria-pressed'), idsAfter: ids.slice(0, 60) };
  });

  await run('whatsapp-390', 390, async p => {
    await go(p, '/');
    const a = p.locator('a.kbw-a').first();
    return { visible: await a.isVisible(), href: (await a.getAttribute('href') || '').slice(0, 40), target: await a.getAttribute('target') };
  });

  await run('language-switch-1280', 1280, async p => {
    await go(p, '/');
    return { alternates: await p.locator('link[rel=alternate][hreflang]').count(), switcher: await p.locator('a[hreflang], [data-kbb-lang]').count() };
  });

  fs.writeFileSync(OUT, JSON.stringify(res, null, 1));
  console.log(JSON.stringify(res));
  await b.close();
})();
