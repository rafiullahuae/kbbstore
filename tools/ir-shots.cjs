/*
 * Lane IR: the Image SEO browser proof, against tools/ir-preview.sh.
 *
 *   node tools/ir-shots.cjs http://127.0.0.1:10860
 *
 * 1. Every shop page (home, category x2, brand x2, product x4, search, cart,
 *    Spotted, a Journal post) at 1280 and 390 BEFORE: every <img> loaded
 *    (naturalWidth > 0), every srcset candidate answers 200, no console error.
 * 2. Drives Catalog -> Image SEO as the owner: Find (sorted "needs attention
 *    first"), select, Rename preview, Start, waits for the run, the live check;
 *    ALT preview, Apply; History; the Media Library ticks. Screenshots of each
 *    at 1280 and 390.
 * 3. The same shop pages AFTER, plus the admin product editor and media
 *    library: zero broken images, the same number of srcset candidates per
 *    page, each one a 200, and every old URL answering 301 to a 200 in ONE hop.
 *
 * Writes docs/lane-ir-shots/*.png and docs/lane-ir-shots/report.json; exits 1
 * on any failure.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const ORIGIN = process.argv[2] || 'http://127.0.0.1:10860';
// A real browser's name: the shop refuses checkout pages to declared headless bots (BlockGate).
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const OUT = path.join(__dirname, '..', 'docs', 'lane-ir-shots');
fs.mkdirSync(OUT, { recursive: true });
const IDS = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'storage', 'framework', 'testing', 'ir-preview', 'ids.json'), 'utf8'));

const SHOP = [
  ['home', '/'],
  ['category-eye', '/collections/ir-eye-care/'],
  ['category-toners', '/collections/ir-toners/'],
  ['brand-medicube', '/brands/ir-medicube/'],
  ['brand-anua', '/brands/ir-anua/'],
  ['product-medicube', '/product/medicube-pdrn-eye-patches/'],
  ['product-anua', '/product/anua-heartleaf-77-toner/'],
  ['product-cosrx', '/product/cosrx-snail-96-essence/'],
  ['product-boj', '/product/boj-relief-sun/'],
  ['search', '/shop/?s=medicube'],
  ['cart', '/cart'],
  ['spotted', '/kbeautybliss-spotted'],
  ['journal-post', '/blog/eye-patch-routine/'],
];

const OLD = [
  'uploads/products/20261005-101010-a1b2c3.jpg',
  'uploads/products/20261005-101011-d4e5f6.jpg',
  'uploads/products/IMG_1234.jpg',
  'wp-content/uploads/2023/05/DSC00042.jpg',
  'uploads/products/20261005-101015-zz9900.jpg',
  'uploads/products/20261006-090000-aa11bb.jpg',
  'uploads/products/20261001-000000-snail1.webp',
  'uploads/products/20261001-000000-snail1.jpg',
  'img-cache/400/uploads/products/20261005-101010-a1b2c3.jpg',
  'img-cache/800/wp-content/uploads/2023/05/DSC00042.jpg',
];

const report = { origin: ORIGIN, before: {}, after: {}, redirects: [], admin: {}, failures: [] };
const fail = (m) => { report.failures.push(m); console.log('FAIL ' + m); };

async function audit(page, label) {
  // Scroll through so every lazy picture is asked for, then wait for the network.
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => requestAnimationFrame(() => r())); }
    window.scrollTo(0, 0);
    document.querySelectorAll('img[loading="lazy"]').forEach((i) => { i.loading = 'eager'; });
  });
  await page.waitForLoadState('networkidle');
  return page.evaluate(async () => {
    const imgs = [...document.querySelectorAll('img')].filter((i) => i.currentSrc || i.getAttribute('src'));
    await Promise.all(imgs.map((i) => (i.complete ? null : new Promise((r) => { i.onload = i.onerror = r; }))));
    const broken = imgs.filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.currentSrc || i.src);
    const candidates = new Set();
    imgs.forEach((i) => (i.getAttribute('srcset') || '').split(',').forEach((c) => { const u = c.trim().split(/\s+/)[0]; if (u) candidates.add(new URL(u, location.href).href); }));
    const bad = [];
    for (const u of candidates) {
      const r = await fetch(u, { cache: 'no-store' });
      if (r.status !== 200) bad.push(r.status + ' ' + u);
    }
    // The basket and a few cards paint pictures as an inline background-image.
    const bg = new Set();
    document.querySelectorAll('[style*="url("]').forEach((el) => { const m = el.getAttribute('style').match(/url\(['"]?([^'")]+)['"]?\)/); if (m) bg.add(new URL(m[1], location.href).href); });
    for (const u of bg) {
      if (!/uploads|img-cache/.test(u)) continue;
      const r = await fetch(u, { cache: 'no-store' });
      if (r.status !== 200) bad.push('background ' + r.status + ' ' + u);
    }
    const srcs = imgs.map((i) => i.getAttribute('src'));
    return { images: imgs.length, backgrounds: [...bg].filter((u) => /uploads|img-cache/.test(u)), broken, srcset: candidates.size, srcsetBad: bad, srcs, scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth };
  });
}

async function shop(browser, phase) {
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    // Something in the cart, so the cart page draws product pictures.
    await page.goto(ORIGIN + '/', { waitUntil: 'networkidle' });
    await page.evaluate(async (ids) => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      for (const id of ids) {
        await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, qty: 1 }) });
      }
    }, [IDS.toner, IDS.snail]);
    for (const [name, url] of SHOP) {
      errors.length = 0;
      const res = await page.goto(ORIGIN + url, { waitUntil: 'networkidle' });
      const a = await audit(page, name);
      a.status = res ? res.status() : 0;
      a.consoleErrors = errors.filter((e) => !/favicon/i.test(e));
      report[phase][name + '@' + width] = a;
      if (a.status !== 200) fail(`${phase} ${name}@${width}: HTTP ${a.status}`);
      if (a.broken.length) fail(`${phase} ${name}@${width}: broken ${a.broken.join(', ')}`);
      if (a.srcsetBad.length) fail(`${phase} ${name}@${width}: srcset ${a.srcsetBad.join(', ')}`);
      if (a.consoleErrors.length) fail(`${phase} ${name}@${width}: console ${a.consoleErrors.join(' | ')}`);
      if (a.scrollWidth > a.clientWidth) fail(`${phase} ${name}@${width}: horizontal scroll ${a.scrollWidth}>${a.clientWidth}`);
      if (phase === 'after' && ['product-medicube', 'search', 'cart', 'spotted', 'journal-post'].includes(name)) {
        await page.screenshot({ path: path.join(OUT, `${width}-shop-${name}-after.png`), fullPage: false });
      }
    }
    await ctx.close();
  }
}

async function login(page, email) {
  await page.goto(ORIGIN + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function openScreen(page) {
  await page.goto(ORIGIN + '/admin?go=imageseo', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go && window.go('imageseo'));
  await page.waitForSelector('[data-isx-screen] .isx-tabs');
}

async function shot(page, name, width) {
  await page.waitForLoadState('networkidle');
  const m = await page.evaluate(() => {
    const c = document.getElementById('content');
    return { scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth, contentOverflow: c ? c.scrollWidth - c.clientWidth : 0 };
  });
  report.admin[name + '@' + width] = m;
  if (m.contentOverflow > 1) fail(`admin ${name}@${width}: content overflows by ${m.contentOverflow}px`);
  // The console scrolls inside #content, so a full-page shot is one screen:
  // grow the window to the content for the picture, then put it back.
  const tall = await page.evaluate(() => { const c = document.getElementById('content'); return c ? c.scrollHeight + (c.getBoundingClientRect().top || 0) + 40 : 0; });
  const vp = page.viewportSize();
  if (tall > vp.height) await page.setViewportSize({ width: vp.width, height: Math.ceil(Math.min(tall, 7000)) });
  await page.screenshot({ path: path.join(OUT, `${width}-${name}.png`), fullPage: true });
  if (tall > vp.height) await page.setViewportSize(vp);
}

async function tail(page, width, errors) {
    await page.click('[data-isx="tab-history"]');
    await shot(page, '07-history', width);

    // The Media Library, with its ticks.
    await page.evaluate(() => window.go('media'));
    await page.waitForSelector('.mlib-tile');
    await page.waitForLoadState('networkidle');
    // Lazy tiles below the fold have not been asked for yet: ask, then judge.
    await page.evaluate(async () => {
      const imgs = [...document.querySelectorAll('.mlib-tile img')];
      imgs.forEach((i) => { i.loading = 'eager'; });
      await Promise.all(imgs.map((i) => (i.complete && i.naturalWidth ? null : new Promise((r) => { i.onload = i.onerror = r; setTimeout(r, 5000); }))));
    });
    const tiles = await page.evaluate(() => ({ ticks: document.querySelectorAll('.mlib-pill.is-seo.is-good').length, scored: document.querySelectorAll('.mlib-pill.is-seo').length,
      broken: [...document.querySelectorAll('.mlib-tile img')].filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.src) }));
    report.admin['media@' + width] = tiles;
    if (!tiles.ticks) fail('media library shows no green tick at ' + width);
    if (tiles.broken.length) fail('media library broken at ' + width + ': ' + tiles.broken.join(', '));
    await shot(page, '08-media-library', width);

    // The product editor on the renamed product.
    await page.evaluate((id) => window.peoEdit(id), IDS.medi);
    await page.waitForResponse((r) => r.url().includes('/product-editor-load/'));
    await page.waitForLoadState('networkidle');
    await shot(page, '09-product-editor', width);
    const pe = await page.evaluate(() => [...document.querySelectorAll('#content img')].filter((i) => i.getAttribute('src') && !(i.complete && i.naturalWidth > 0)).map((i) => i.src));
    report.admin['product-editor@' + width] = { broken: pe };
    if (pe.length) fail('product editor broken at ' + width + ': ' + pe.join(', '));

    report.admin['consoleErrors@' + width] = errors;
    if (errors.length) fail('admin console errors at ' + width + ': ' + errors.join(' | '));
}

async function admin(browser) {
  // The phone first, so its Find and previews show the shop BEFORE the run
  // (which the 1280 pass then makes).
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (d) => d.accept());
    await login(page, 'owner@example.com');
    await openScreen(page);
    // Wait out the score backfill the screen starts by itself.
    await page.waitForFunction(() => !document.querySelector('[data-isx-screen]').textContent.includes('Scoring the library'), null, { timeout: 30000 });

    // Find: everything, needs attention first.
    await page.selectOption('#isx-sort', 'attention');
    await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/find')), page.click('[data-isx-form="find"] button[type=submit]')]);
    await page.waitForSelector('.isx-prod');
    await page.evaluate(() => { const d = document.querySelector('.isx details'); if (d) d.open = true; });
    await shot(page, '01-find', width);

    if (width === 1280) {
      // Select every product, preview, start.
      await page.click('[data-isx="page-all"]');
      await page.click('[data-isx="tab-rename"]');
      await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/preview')), page.click('[data-isx="preview"]')]);
      await page.waitForSelector('.isx-log li');
      await shot(page, '02-rename-preview', width);
      await page.click('[data-isx="start"]');
      await page.waitForFunction(() => /finished/.test((document.querySelector('[data-isx-screen]') || {}).textContent || ''), null, { timeout: 120000 });
      await page.waitForFunction(() => /Checked live|Live check/.test(document.querySelector('[data-isx-screen]').textContent), null, { timeout: 20000 });
      await shot(page, '03-rename-done', width);
      const live = await page.evaluate(() => (document.querySelector('.isx-msg.is-ok, .isx-msg.is-bad') || {}).textContent || '');
      report.admin.liveCheck = live;
      if (!/Checked live/.test(live)) fail('live redirect check: ' + live);

      // ALT text.
      await page.click('[data-isx="tab-alt"]');
      await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/alt-preview')), page.click('[data-isx="alt-preview"]')]);
      await page.waitForSelector('[data-isx-alt]');
      await shot(page, '04-alt-preview', width);
      await page.click('[data-isx="alt-apply"]');
      await page.waitForFunction(() => /Alt text · run #\d+\s*finished/.test(document.querySelector('[data-isx-screen]').textContent), null, { timeout: 60000 });
      await shot(page, '05-alt-done', width);

      // Find again: the scores after.
      await page.click('[data-isx="tab-find"]');
      await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/find')), page.click('[data-isx-form="find"] button[type=submit]')]);
      await page.waitForSelector('.isx-prod');
      await shot(page, '06-find-after', width);
    } else {
      // The phone run of the same tabs BEFORE anything is renamed.
      await page.click('[data-isx="page-all"]');
      await page.click('[data-isx="tab-rename"]');
      await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/preview')), page.click('[data-isx="preview"]')]);
      await page.waitForSelector('.isx-log li');
      await shot(page, '02-rename-preview', width);
      await page.click('[data-isx="tab-alt"]');
      await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/alt-preview')), page.click('[data-isx="alt-preview"]')]);
      await page.waitForSelector('[data-isx-alt]');
      await shot(page, '04-alt-preview', width);
      report.admin['consoleErrors-before@' + width] = errors.slice();
      if (errors.length) fail('admin console errors at ' + width + ': ' + errors.join(' | '));
      await ctx.close();
      continue;
    }

    await tail(page, width, errors);
    await ctx.close();
  }

  // The phone, after the run: Find, History, the Media Library, the editor.
  {
    const width = 390;
    const ctx = await browser.newContext({ viewport: { width, height: 844 }, deviceScaleFactor: 1, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    await login(page, 'owner@example.com');
    await openScreen(page);
    await page.selectOption('#isx-sort', 'attention');
    await Promise.all([page.waitForResponse((r) => r.url().includes('/image-seo/find')), page.click('[data-isx-form="find"] button[type=submit]')]);
    await page.waitForSelector('.isx-prod');
    await shot(page, '06-find-after', width);
    await tail(page, width, errors);
    await ctx.close();
  }

  // The Content Editor role may not open it.
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
  const page = await ctx.newPage();
  await login(page, 'editor@example.com');
  const st = await page.evaluate(async () => (await fetch('/admin-api/image-seo', { headers: { Accept: 'application/json' } })).status);
  report.admin.editorStatus = st;
  if (st !== 403) fail('editor got ' + st + ' from /admin-api/image-seo');
  await ctx.close();
}

async function redirects(browser) {
  const ctx = await browser.newContext({ userAgent: UA });
  const page = await ctx.newPage();
  await page.goto(ORIGIN + '/kbb-preview-id.txt');
  for (const rel of OLD) {
    const r = await page.evaluate(async (u) => {
      const a = await fetch('/' + u, { redirect: 'manual', cache: 'no-store' });
      const f = await fetch('/' + u, { cache: 'no-store' });
      return { type: a.type, finalUrl: f.url, finalStatus: f.status, redirected: f.redirected, ctype: f.headers.get('content-type') };
    }, rel);
    // One hop: the manual fetch saw a redirect, and the target itself is a 200 picture.
    const hop = await page.evaluate(async (u) => (await fetch(u, { redirect: 'manual', cache: 'no-store' })).status, r.finalUrl);
    r.rel = rel; r.targetStatusNoFollow = hop;
    report.redirects.push(r);
    if (r.type !== 'opaqueredirect') fail('old ' + rel + ' did not redirect');
    if (r.finalStatus !== 200 || !/^image\//.test(r.ctype || '')) fail('old ' + rel + ' ends at ' + r.finalStatus + ' ' + r.ctype);
    if (hop !== 200) fail('old ' + rel + ' needs more than one hop (' + hop + ')');
  }
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  await shop(browser, 'before');
  await admin(browser);
  await shop(browser, 'after');
  await redirects(browser);
  await browser.close();

  for (const key of Object.keys(report.after)) {
    const b = report.before[key], a = report.after[key];
    if (b && a.srcset !== b.srcset) fail(`${key}: srcset candidates ${b.srcset} before, ${a.srcset} after`);
    if (b && a.images !== b.images) fail(`${key}: ${b.images} images before, ${a.images} after`);
    if (b && a.backgrounds.length !== b.backgrounds.length) fail(`${key}: ${b.backgrounds.length} background pictures before, ${a.backgrounds.length} after`);
  }
  const strip = (o) => Object.fromEntries(Object.entries(o).map(([k, v]) => [k, { status: v.status, images: v.images, backgrounds: v.backgrounds.length, sampleBackground: v.backgrounds[0] || null, broken: v.broken.length, srcset: v.srcset, srcsetBad: v.srcsetBad.length, consoleErrors: v.consoleErrors.length, scrollWidth: v.scrollWidth, clientWidth: v.clientWidth, sampleSrc: (v.srcs || []).filter((s) => s && /uploads|img-cache/.test(s)).slice(0, 3) }]));
  report.before = strip(report.before);
  report.after = strip(report.after);
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
  console.log(report.failures.length ? report.failures.length + ' FAILURE(S)' : 'ALL GREEN');
  process.exit(report.failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
