/* Lane HLB -- the "every front-end change" checks CLAUDE.md owes, before
   (BASE: HEAD without this lane) and after (NEW: this worktree), both
   tools/arr-preview.sh previews (Arabic on, mirrored).

   For home, category, shop, brand, product and blog, English and Arabic, at
   390 and 1280: console errors, CLS, HTML bytes, render-blocking CSS bytes,
   and a real elementFromPoint hit on every visible header link and button.
   Then, on NEW only: the switch clicked for real (en -> ar -> en) at 390 and
   1280; prefetch on product, category and brand links (hover, then the
   navigation's deliveryType); and the pictures into docs/lane-hlb/.

   Usage: BASE=http://127.0.0.1:9580 NEW=http://127.0.0.1:9581 node tools/hlb-checks.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE || 'http://127.0.0.1:9580';
const NEW = process.env.NEW || 'http://127.0.0.1:9581';
const OUT = path.resolve(__dirname, '../docs/lane-hlb');
fs.mkdirSync(OUT, { recursive: true });
const PAGES = { home: '/', category: '/collections/acne-care/', shop: '/shop/', brand: '/brands/medicube/', product: '/product/lanear-glow-starter-set/', blog: '/blog/how-to-layer-toner-pads/' };

async function open(b, host, url, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: 844 }, deviceScaleFactor: w < 901 ? 2 : 1, isMobile: w < 901, hasTouch: w < 901 });
  await ctx.addInitScript(() => {
    window.__cls = 0;
    try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
  });
  const p = await ctx.newPage();
  const errors = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text().slice(0, 160)); });
  p.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 160)));
  const resp = await p.goto(host + url, { waitUntil: 'networkidle' });
  const html = await resp.text();
  return { ctx, p, errors, htmlBytes: Buffer.byteLength(html) };
}

async function audit(b, host, url, w) {
  const o = await open(b, host, url, w);
  await o.p.waitForTimeout(400);
  const r = await o.p.evaluate(async () => {
    const css = [...document.querySelectorAll('head link[rel=stylesheet]:not([media=print])')];
    let cssBytes = 0;
    for (const l of css) { try { cssBytes += (await (await fetch(l.href)).arrayBuffer()).byteLength; } catch (e) {} }
    const misses = [];
    let hits = 0;
    for (const el of document.querySelectorAll('header a[href], header button')) {
      const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden') continue;
      const bx = el.getBoundingClientRect(); if (!bx.width || !bx.height || bx.bottom < 0 || bx.top > innerHeight || bx.right < 0 || bx.left > innerWidth) continue;
      if (el.closest('.acct,.sugg,.mega,[hidden]')) continue;
      const h = document.elementFromPoint(bx.left + bx.width / 2, bx.top + bx.height / 2);
      if (h && (el === h || el.contains(h))) hits++; else misses.push((el.getAttribute('aria-label') || el.textContent.trim().slice(0, 20) || el.className) + ' <- ' + (h ? h.tagName + '.' + h.className : 'null'));
    }
    return { cssBytes, cls: Math.round(window.__cls * 10000) / 10000, hits, misses, sw: document.documentElement.scrollWidth, alt: document.querySelector('.kbb-lang a')?.getAttribute('href') || null };
  });
  await o.ctx.close();
  return { ...r, htmlBytes: o.htmlBytes, errors: o.errors };
}

(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' });
  const R = { pages: [], clicks: [], prefetch: [] };

  // 1. Every page type, both languages, both widths, before and after.
  for (const [name, en] of Object.entries(PAGES)) {
    const first = await audit(b, NEW, en, 1280);
    const ar = first.alt; // this page's own Arabic address (the footer switch)
    for (const [lang, url] of [['en', en], ['ar', ar]]) for (const w of [390, 1280]) for (const [side, host] of [['before', BASE], ['after', NEW]]) {
      const a = await audit(b, host, url, w);
      R.pages.push({ name, lang, url, w, side, ...a, alt: undefined });
    }
  }

  // 2. The switch, clicked for real, both ways, at a phone and a computer width.
  for (const w of [390, 1280]) for (const start of ['/', '/ar/', PAGES.product]) {
    const o = await open(b, NEW, start, w);
    const sel = w < 901 ? 'header .lsw-m' : 'header .lsw-d a';
    const before = o.p.url();
    const hit = await o.p.evaluate((s) => { const e = document.querySelector(s); const r = e.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { hit: !!(h && (e === h || e.contains(h))), box: [Math.round(r.width), Math.round(r.height)], href: e.getAttribute('href') }; }, sel);
    await Promise.all([o.p.waitForNavigation({ waitUntil: 'networkidle' }), o.p.click(sel)]);
    R.clicks.push({ w, from: before.replace(NEW, ''), to: o.p.url().replace(NEW, ''), lang: await o.p.evaluate(() => document.documentElement.lang), ...hit, errors: o.errors });
    await o.ctx.close();
  }

  // 3. Prefetch still fires, and is used, on product, category and brand links.
  for (const [side, host] of [['before', BASE], ['after', NEW]]) for (const kind of ['product', 'collections', 'brands']) {
    const o = await open(b, host, '/', 1280);
    const sel = `main a[href^="/${kind}/"]:not([href="/${kind}/"])`;
    await o.p.evaluate((s) => document.querySelector(s)?.scrollIntoView({ block: 'center' }), sel);
    let prefetched = null;
    o.p.on('request', (rq) => { const h = rq.headers(); if ((h['sec-purpose'] || '').includes('prefetch')) prefetched = rq.url().replace(host, ''); });
    await o.p.hover(sel);
    await o.p.waitForTimeout(700);
    const href = await o.p.getAttribute(sel, 'href');
    await Promise.all([o.p.waitForNavigation({ waitUntil: 'load' }), o.p.click(sel)]);
    const used = await o.p.evaluate(() => performance.getEntriesByType('navigation')[0]?.deliveryType || '');
    R.prefetch.push({ side, kind, href, prefetched, used });
    await o.ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'checks.json'), JSON.stringify(R, null, 1));

  // 4. Pictures: the header at 390, 600 and 1280, English and Arabic, after.
  for (const lang of ['en', 'ar']) for (const w of [390, 600, 1280]) {
    const o = await open(b, NEW, lang === 'ar' ? '/ar/' : '/', w);
    await o.p.waitForTimeout(300);
    await o.p.screenshot({ path: path.join(OUT, `header-${w}-${lang}.png`), clip: { x: 0, y: 0, width: w, height: w < 901 ? 260 : 160 } });
    await o.ctx.close();
    const z = await open(b, BASE, lang === 'ar' ? '/ar/' : '/', w);
    await z.p.waitForTimeout(300);
    await z.p.screenshot({ path: path.join(OUT, `header-${w}-${lang}-before.png`), clip: { x: 0, y: 0, width: w, height: w < 901 ? 260 : 160 } });
    await z.ctx.close();
  }

  // 5. The cart panel's heart tab at 390, empty and filled, English and Arabic.
  for (const lang of ['en', 'ar']) for (const state of ['empty', 'filled']) {
    const o = await open(b, NEW, lang === 'ar' ? '/ar/' : '/', 390);
    if (state === 'filled') {
      await o.p.evaluate(async () => {
        const ids = [...new Set([...document.querySelectorAll('[data-kbb-wish],[data-kbb-add],[data-add]')].map((e) => e.dataset.kbbWish || e.dataset.kbbAdd || e.dataset.add).filter(Boolean))].slice(0, 3);
        for (const id of ids) await fetch(window.KBB.routes.wishlistToggle, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: 'product_id=' + id });
      });
      await o.p.reload({ waitUntil: 'networkidle' });
    }
    await o.p.click('header [data-kbb-cart]');
    await o.p.waitForTimeout(600);
    await o.p.click('[data-kctab="wish"]');
    await o.p.waitForTimeout(300);
    const m = await o.p.evaluate(() => {
      const tabs = [...document.querySelectorAll('.kc-tabs > *')].map((e) => { const r = e.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return `${e.dataset.kctab || e.className}@${Math.round(r.left)}:${Math.round(r.width)}x${Math.round(r.height)}${h === e || e.contains(h) ? '' : ' NOT-HIT'}`; });
      return { tabs, rows: document.querySelectorAll('#kcWish [data-wrow]').length, filled: document.querySelector('.kc-wt .kc-wh')?.getAttribute('fill'), sw: document.documentElement.scrollWidth, wishVisible: getComputedStyle(document.getElementById('kcWish')).display };
    });
    (R.cart ||= []).push({ lang, state, ...m, errors: o.errors });
    await o.p.screenshot({ path: path.join(OUT, `cart-heart-${state}-390-${lang}.png`) });
    if (state === 'filled') {
      // Remove one from inside the tab, then add one to the bag from it: the pane redraws from the toggle's own reply.
      const before = m.rows;
      await o.p.click('#kcWish .kc-wrm');
      await o.p.waitForTimeout(800);
      const after = await o.p.evaluate(() => document.querySelectorAll('#kcWish [data-wrow]').length);
      await o.p.click('#kcWish .kc-badd');
      await o.p.waitForTimeout(1200);
      const stay = await o.p.evaluate(() => ({ wishShown: getComputedStyle(document.getElementById('kcWish')).display !== 'none', ticked: document.querySelectorAll('#kcWish .kc-badd.in').length, cartCount: document.getElementById('cartCt')?.textContent }));
      R.cart.push({ lang, state: 'remove-then-add', rowsBefore: before, rowsAfterRemove: after, ...stay, errors: o.errors });
    }
    await o.ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'checks.json'), JSON.stringify(R, null, 1));
  await b.close();

  // Summary.
  const by = (s) => R.pages.filter((r) => r.side === s);
  for (const r of by('after')) {
    const z = R.pages.find((x) => x.side === 'before' && x.name === r.name && x.lang === r.lang && x.w === r.w);
    console.log(`${r.name.padEnd(8)} ${r.lang} ${String(r.w).padStart(4)} | html ${z.htmlBytes}->${r.htmlBytes} | css ${z.cssBytes}->${r.cssBytes} | cls ${z.cls}->${r.cls} | header hits ${z.hits}/${z.hits + z.misses.length} -> ${r.hits}/${r.hits + r.misses.length}${r.misses.length ? ' MISSES ' + r.misses.join('; ') : ''} | errors ${z.errors.length}->${r.errors.length}${r.errors.length ? ' ' + r.errors.join(' / ') : ''} | sw ${r.sw}`);
  }
  console.log(JSON.stringify({ clicks: R.clicks, prefetch: R.prefetch, cart: R.cart }, null, 1));
})();
