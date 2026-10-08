/*
 * Lane MN (2.60.441): the desktop menu's "current page" marking, measured in
 * Chromium against a running tools/nc-preview.sh.
 *
 *   node tools/nc-browse.cjs http://127.0.0.1:PORT before|after > out.json
 *
 * Per page type at 1280 (and 390 for the phone header): which menu item is
 * marked and how it is painted, every top-level menu link answering
 * elementFromPoint at rest AND while hovered, every panel link answering it with
 * its panel open, hover-to-prefetch on a category, a brand and a product link,
 * a real click that navigates, console errors, CLS. Screenshots of the header
 * go to docs/lane-mn-shots/<label>-<page>-<width>.png.
 */
const path = require('path');
const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const BASE = process.argv[2];
const LABEL = process.argv[3] || 'after';
const SHOTS = path.join(APP, 'docs/lane-mn-shots');
fs.mkdirSync(SHOTS, { recursive: true });

const PAGES = {
  home: '/',
  shop: '/shop/',
  newin: '/shop/?orderby=date',
  category: '/collections/skincare/serums/',
  product: '/product/vitamin-c-brightening-serum/',
  brand: '/brands/cosrx/',
  blog: '/blog/',
  post: '/blog/nc-morning-routine/',
  page: '/delivery/',
  category_ar: '/ar/collections/skincare/serums/',
  product_ar: '/ar/product/vitamin-c-brightening-serum/',
};

async function ctxFor(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 800 }, serviceWorkers: 'block' });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  await ctx.addInitScript(() => {
    window.__cls = 0;
    try {
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; })
        .observe({ type: 'layout-shift', buffered: true });
    } catch (e) { /* */ }
  });
  return ctx;
}

async function open(browser, width, url) {
  const ctx = await ctxFor(browser, width);
  const page = await ctx.newPage();
  const errors = [];
  const prefetched = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('request', (r) => { const h = r.headers(); if (h['sec-purpose']) prefetched.push(new URL(r.url()).pathname + new URL(r.url()).search); });
  await page.goto(BASE + url, { waitUntil: 'load' });
  await page.mouse.move(2, 790);
  await page.waitForTimeout(700);
  return { ctx, page, errors, prefetched };
}

const marked = (page) => page.evaluate(() => [...document.querySelectorAll('.mbar [aria-current]')].map((a) => {
  const cs = getComputedStyle(a);
  const af = getComputedStyle(a, '::after');
  return { text: a.textContent.trim().replace(/\s+/g, ' ').slice(0, 30), cur: a.getAttribute('aria-current'), top: a.classList.contains('navlink'),
    color: cs.color, afterTransform: af.transform, afterBg: af.backgroundColor, afterH: af.height, bg: cs.backgroundColor };
}));

/* Every top-level link: elementFromPoint over its own box, pointer away, then hovered. */
async function clickable(page) {
  const boxes = await page.$$eval('.mbar .navlink', (ls) => ls.map((l) => { const r = l.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, href: l.getAttribute('href'), x1: r.x + 4, y1: r.y + r.height - 4 }; }));
  let rest = 0; let hovered = 0; let corner = 0;
  for (const b of boxes) {
    const at = (x, y) => page.evaluate(([px, py, href]) => { const e = document.elementFromPoint(px, py); const a = e && e.closest('a'); return !!a && a.getAttribute('href') === href; }, [x, y, b.href]);
    if (await at(b.x, b.y)) rest++;
    await page.mouse.move(b.x, b.y, { steps: 3 });
    await page.waitForTimeout(220);
    if (await at(b.x, b.y)) hovered++;
    if (await at(b.x1, b.y1)) corner++;
  }
  await page.mouse.move(2, 790);
  await page.waitForTimeout(250);
  return { links: boxes.length, rest, hovered, lowerCorner: corner };
}

async function panelClickable(page, label) {
  const item = page.locator('.mbar .navitem', { has: page.locator(`.navlink:text-is("${label}")`) }).first();
  const nb = await item.locator('.navlink').first().boundingBox();
  await page.mouse.move(nb.x + nb.width / 2, nb.y + nb.height / 2, { steps: 4 });
  await page.waitForTimeout(400);
  const res = await item.locator('.drop a').evaluateAll((ls) => ls.map((l) => {
    const r = l.getBoundingClientRect(); const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    return !!e && e.closest('a') === l;
  }));
  return { links: res.length, ok: res.filter(Boolean).length };
}

async function shot(page, name, width, fullHeader = true) {
  const h = await page.evaluate(() => { const m = document.querySelector('.mbar'); const hd = document.querySelector('header'); const r = (m && m.offsetParent ? m : hd).getBoundingClientRect(); return Math.ceil(r.bottom); });
  await page.screenshot({ path: path.join(SHOTS, `${LABEL}-${name}-${width}.png`), clip: { x: 0, y: 0, width, height: Math.min(800, Math.max(120, h + (fullHeader ? 16 : 0))) } });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};

  for (const [name, url] of Object.entries(PAGES)) {
    const { ctx, page, errors } = await open(browser, 1280, url);
    const row = { url, marked: await marked(page) };
    await shot(page, name, 1280);
    row.clickable = await clickable(page);
    row.cls = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    row.mbarH = await page.evaluate(() => document.querySelector('.mbar').getBoundingClientRect().height);
    row.navlinkH = await page.evaluate(() => [...new Set([...document.querySelectorAll('.mbar .navlink')].map((l) => l.getBoundingClientRect().height))]);
    row.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    row.consoleErrors = errors;
    out[name] = row;
    await ctx.close();

    const m = await open(browser, 390, url);
    out[name].phone = { mbarDisplay: await m.page.evaluate(() => getComputedStyle(document.querySelector('.mbar')).display), scrollWidth: await m.page.evaluate(() => document.documentElement.scrollWidth), cls: await m.page.evaluate(() => Math.round(window.__cls * 10000) / 10000), consoleErrors: m.errors };
    await m.page.screenshot({ path: path.join(SHOTS, `${LABEL}-${name}-390.png`), clip: { x: 0, y: 0, width: 390, height: 200 } });
    await m.ctx.close();
    process.stderr.write(name + ' ' + JSON.stringify(out[name].marked.map((x) => x.text + ':' + x.cur)) + '\n');
  }

  /* Panels: every link answers with its panel open (on the brand page, where Brands is current). */
  {
    const { ctx, page } = await open(browser, 1280, PAGES.brand);
    out.panels = { brands: await panelClickable(page, 'Brands') };
    await shot(page, 'brand-hover-own-panel', 1280, false);
    await page.screenshot({ path: path.join(SHOTS, `${LABEL}-brand-hover-own-panel-1280.png`), clip: { x: 0, y: 0, width: 1280, height: 420 } });
    out.panels.skincare = await panelClickable(page, 'Skincare');
    await page.screenshot({ path: path.join(SHOTS, `${LABEL}-brand-hover-other-panel-1280.png`), clip: { x: 0, y: 0, width: 1280, height: 420 } });
    out.panels.indicatorsWhileOtherOpen = await page.evaluate(() => [...document.querySelectorAll('.mbar .navlink')].filter((a) => {
      const t = getComputedStyle(a, '::after').transform; return t !== 'none' && !/^matrix\(0,/.test(t);
    }).map((a) => a.textContent.trim().split(/\s+/)[0]));
    await ctx.close();
  }
  {
    const { ctx, page } = await open(browser, 1280, PAGES.category);
    out.panels.categoryOwn = await panelClickable(page, 'Skincare');
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(SHOTS, `${LABEL}-category-hover-own-panel-1280.png`), clip: { x: 0, y: 0, width: 1280, height: 420 } });
    out.panels.indicatorsOwnOpen = await page.evaluate(() => [...document.querySelectorAll('.mbar .navlink')].map((a) => ({ t: a.textContent.trim().split(/\s+/)[0], after: getComputedStyle(a, '::after').transform, before: getComputedStyle(a, '::before').opacity })).filter((x) => x.t === 'Skincare'));
    await ctx.close();
  }

  /* Prefetch, fired AND used: from the brand page, rest on a category link, a
     brand link in an open panel and a product card, then a real click, and
     read the arrival's deliveryType ("navigational-prefetch" = served from the
     prefetch). The speculation-rules fetch is browser-initiated, so Playwright's
     request events do not see it; the arrival does. */
  out.prefetch = {};
  const targets = {
    category: { open: null, sel: '.mbar .navlink[href="/collections/masks/"]' },
    categoryInPanel: { open: 'Skincare', sel: '.mbar .drop a[href="/collections/toners/"]' },
    brandInPanel: { open: 'Brands', sel: '.mbar .drop a[href="/brands/anua/"]' },
    product: { open: null, sel: 'main a[href*="/product/"]' },
  };
  for (const [k, t] of Object.entries(targets)) {
    const { ctx, page } = await open(browser, 1280, PAGES.brand);
    if (t.open) {
      const nb = await page.locator('.mbar .navitem', { has: page.locator(`.navlink:text-is("${t.open}")`) }).first().locator('.navlink').first().boundingBox();
      await page.mouse.move(nb.x + nb.width / 2, nb.y + nb.height / 2, { steps: 4 });
      await page.waitForTimeout(400);
    }
    const loc = page.locator(t.sel).first();
    const href = await loc.getAttribute('href');
    const b = await loc.boundingBox();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 8 });
    await page.waitForTimeout(700);
    const hit = await page.evaluate(([x, y, h]) => { const e = document.elementFromPoint(x, y); const a = e && e.closest('a'); return !!a && a.getAttribute('href') === h; }, [b.x + b.width / 2, b.y + b.height / 2, href]);
    await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2);
    const want = new URL(href, BASE).pathname;
    await page.waitForURL((u) => u.pathname === want, { timeout: 15000 }).catch(() => {});
    await page.waitForLoadState('load');
    out.prefetch[k] = { href, hitIsLink: hit, ...(await page.evaluate(() => ({ arrived: location.pathname, deliveryType: performance.getEntriesByType('navigation')[0].deliveryType, marked: [...document.querySelectorAll('.mbar [aria-current]')].map((a) => a.textContent.trim().replace(/\s+/g, ' ') + ':' + a.getAttribute('aria-current')) }))) };
    await ctx.close();
  }

  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
