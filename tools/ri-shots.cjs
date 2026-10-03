/*
 * Lane RI — "Buy these together" in the right column, photographed and
 * measured in Chromium.
 *
 *   sh tools/ri-preview.sh 9890
 *   RI_BASE=http://127.0.0.1:9890 node tools/ri-shots.cjs [steps]
 *
 * Every placement is saved THROUGH THE REAL ADMIN ENDPOINT (POST
 * /admin-api/product-page) or the real screen, as the owner would; every
 * rectangle is read by this harness, never by a script the shop serves.
 * Writes docs/ri-shots/*.png and docs/ri-shots/MEASUREMENTS.json.
 *
 * "BEFORE" IS THE INTEGRATOR'S STYLESHEET. The default page is measured twice:
 * once as this branch serves it and once with the product stylesheet swapped
 * (page.route) for the integrator's build, saved by the caller at
 * storage/ri-logs/old-kbb-product.css (`git show origin/claude/kind-mayer-rpqesv:
 * public/build/assets/kbb-product-DY41sjNZ.css`). Equal rects at every width are the
 * proof that the default page did not move.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RI_BASE || 'http://127.0.0.1:9890';
const OUT = path.join(__dirname, '..', 'docs', 'ri-shots');
const OLD_CSS = path.join(__dirname, '..', 'storage', 'ri-logs', 'old-kbb-product.css');
const CHROME = process.env.RI_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SLUG = process.env.RI_SLUG || 'pdp-heartleaf-toner';
const STEPS = (process.argv[2] || 'all').split(',');
const want = (s) => STEPS.includes('all') || STEPS.includes(s);
const M = fs.existsSync(path.join(OUT, 'MEASUREMENTS.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'MEASUREMENTS.json'), 'utf8')) : {};
fs.mkdirSync(OUT, { recursive: true });

const BUY_DEFAULT = ['title', 'price', 'short', 'paylater', 'bundles', 'ready', 'delivery', 'cart', 'auth', 'trust', 'paychips'];
const UNDER_DEFAULT = ['buytogether', 'details', 'reviews', 'related'];
const SEL = {
  crumb: '.pdp-page > .crumb', pdp: '.pdp', gallery: '.pdp > .gallery', buybox: '.pdp > .buybox',
  title: '.pm-title', price: '.pm-price', short: '.pm-short:not(.pm-short-set)', paylater: '.pm-paylater', bundles: '.pm-bundles',
  ready: '.pm-ready', delivery: '.kbb-cart-form > .pts-del', cart: '.pm-cart', auth: '.kbb-cart-form > .pts-stack',
  trust: '.pm-trust', paychips: '.pm-paychips', fbt: '.kbb-fbt', details: '.pm-details', reviews: '.pdp-page > .sr', related: '.ymal',
};

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function api(page, body, method = 'POST') {
  return page.evaluate(async ([body, method]) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' };
    const r = await fetch('/admin-api/product-page', { method, credentials: 'same-origin', headers: h, body: method === 'POST' ? JSON.stringify(body) : undefined });
    return { status: r.status, body: await r.json() };
  }, [body, method]);
}

async function place(admin, buy, under) {
  const r = await api(admin, { dsections: { order: under, buy } });
  if (r.status !== 200) throw new Error('save failed: ' + JSON.stringify(r.body));
  return r.status;
}

/** Rects of every block, and what is inside Buy these together. */
async function measure(p) {
  return p.evaluate((SEL) => {
    const R = (el) => {
      if (!el) return null;
      const b = el.getBoundingClientRect();
      if (getComputedStyle(el).display === 'none' || (b.width === 0 && b.height === 0)) return 'hidden';
      const f = (n) => Math.round(n * 100) / 100;
      return { top: f(b.top + scrollY), left: f(b.left), width: f(b.width), height: f(b.height) };
    };
    const out = { scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth, blocks: {} };
    for (const [k, s] of Object.entries(SEL)) out.blocks[k] = R(document.querySelector(s));
    const fbt = document.querySelector('.kbb-fbt');
    out.fbtParent = fbt ? (fbt.parentElement.classList.contains('buybox') ? 'buybox' : fbt.parentElement.classList.contains('pdp-page') ? 'pdp-page' : fbt.parentElement.className) : null;
    out.fbtCount = document.querySelectorAll('.kbb-fbt').length;
    out.wrapper = document.querySelector('.pdp-page').className;
    if (fbt && R(fbt) !== 'hidden') {
      const box = fbt.getBoundingClientRect();
      const cards = [...fbt.querySelectorAll('.bt-card')];
      const rail = fbt.querySelector('.bt-rail').getBoundingClientRect();
      const cs = getComputedStyle(fbt);
      out.inside = {
        h2: { fontSize: getComputedStyle(fbt.querySelector('h2')).fontSize, height: Math.round(fbt.querySelector('h2').getBoundingClientRect().height) },
        cards: cards.length,
        cardWidths: cards.map((c) => Math.round(c.getBoundingClientRect().width * 100) / 100),
        cardsFullyInRail: cards.filter((c) => { const b = c.getBoundingClientRect(); return b.left >= rail.left - 0.5 && b.right <= rail.right + 0.5; }).length,
        nameFont: cards[0] ? getComputedStyle(cards[0].querySelector('.nm')).fontSize : null,
        priceFont: cards[0] ? getComputedStyle(cards[0].querySelector('.pr')).fontSize : null,
        // Text inside its box: each name and price inside its card, the foot inside the panel.
        textOutside: [...fbt.querySelectorAll('.nm, .pr, .pr > *')].filter((t) => {
          const c = t.closest('.bt-card').getBoundingClientRect(); const b = t.getBoundingClientRect();
          return b.width > 0 && (b.left < c.left - 0.5 || b.right > c.right + 0.5);
        }).length,
        footOverflow: [...fbt.querySelectorAll('.bt-sumrow, .bt-total, .bt-save, .bt-buy')].filter((t) => t.offsetParent && (t.scrollWidth > t.clientWidth + 1 || t.getBoundingClientRect().right > box.right + 0.5)).length,
        sumrow: R(fbt.querySelector('.bt-sumrow')),
        button: R(fbt.querySelector('.bt-buy')),
        rail: { left: Math.round(rail.left), width: Math.round(rail.width) },
        radius: cs.borderRadius, padding: cs.padding, marginTop: cs.marginTop,
      };
      const prev = fbt.parentElement.classList.contains('buybox')
        ? [...fbt.parentElement.querySelectorAll('.pm-sec, .pts-del, .pts-stack')].filter((e) => getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0 && e.getBoundingClientRect().bottom <= box.top + 0.5)
          .sort((a, b) => b.getBoundingClientRect().bottom - a.getBoundingClientRect().bottom)[0]
        : null;
      out.gapAboveFbt = prev ? Math.round((box.top - prev.getBoundingClientRect().bottom) * 100) / 100 : null;
      out.blockAboveFbt = prev ? prev.className : null;
      const gal = document.querySelector('.pdp > .gallery').getBoundingClientRect();
      out.buyColumnBottomVsPhotoBottom = Math.round(document.querySelector('.pdp > .buybox').getBoundingClientRect().bottom - gal.bottom);
    }
    return out;
  }, SEL);
}

async function product(browser, w, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 1000 }, deviceScaleFactor: 1, reducedMotion: opts.motion ? 'no-preference' : 'reduce' });
  const p = await ctx.newPage();
  await p.clock.install({ time: new Date('2026-10-03T08:00:00Z') });
  await p.clock.pauseAt(new Date('2026-10-03T08:00:00Z'));
  if (opts.oldCss) {
    const body = fs.readFileSync(OLD_CSS, 'utf8');
    await p.route(/\/build\/assets\/kbb-product-[^/]+\.css$/, (route) => route.fulfill({ status: 200, contentType: 'text/css', body }));
  }
  await p.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
  // Every picture loaded and decoded before anything is read: a thumbnail
  // still decoding is a few pixels that differ between two identical pages.
  await p.evaluate(() => Promise.all([...document.images].map((i) => { i.loading = 'eager'; return i.decode().catch(() => null); })));
  await p.waitForTimeout(250);
  const m = await measure(p);
  if (opts.shot) {
    const file = path.join(OUT, `${opts.shot}-${w}.png`);
    if (w < 600) {
      await p.screenshot({ path: file, fullPage: true, animations: 'disabled' });
    } else {
      // The two columns and whatever is under them down to the details block.
      const top = Math.max(0, m.blocks.pdp.top - 10);
      const det = m.blocks.details && m.blocks.details !== 'hidden' ? m.blocks.details.top : m.blocks.pdp.top + m.blocks.pdp.height;
      const fb = m.blocks.fbt && m.blocks.fbt !== 'hidden' ? m.blocks.fbt.top + m.blocks.fbt.height : 0;
      const bottom = Math.max(m.blocks.pdp.top + m.blocks.pdp.height, fb, det) + 20;
      await p.screenshot({ path: file, fullPage: true, clip: { x: 0, y: top, width: w, height: bottom - top }, animations: 'disabled' });
    }
    m.shot = path.relative(path.join(__dirname, '..'), file);
  }
  if (opts.full390) {
    // The gallery's thumbnail strip is masked: its top rows differ by a few
    // pixels between two loads of the SAME page (measured: default against
    // default, 10px in one run of three), so it cannot tell placements apart.
    m.png = await p.screenshot({ fullPage: true, animations: 'disabled', mask: [p.locator('.gthumbs')], maskColor: '#ff00ff' });
  }
  await ctx.close();
  return m;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** Pixel diff of two PNGs, decoded in a blank page's canvas: count and bounding box. */
async function pixelDiff(browser, a, b) {
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  const r = await p.evaluate(async ([a, b]) => {
    const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = src; });
    const [ia, ib] = await Promise.all([load(a), load(b)]);
    if (ia.width !== ib.width || ia.height !== ib.height) return { sizeA: [ia.width, ia.height], sizeB: [ib.width, ib.height], differing: -1 };
    const px = (img) => { const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const g = c.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, img.width, img.height).data; };
    const da = px(ia), db = px(ib);
    let n = 0, x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
    for (let i = 0; i < da.length; i += 4) {
      if (da[i] !== db[i] || da[i + 1] !== db[i + 1] || da[i + 2] !== db[i + 2] || da[i + 3] !== db[i + 3]) {
        n++; const q = i / 4, x = q % ia.width, y = Math.floor(q / ia.width);
        x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y);
      }
    }
    return { size: [ia.width, ia.height], differing: n, box: n ? [x0, y0, x1, y1] : null };
  }, ['data:image/png;base64,' + a.toString('base64'), 'data:image/png;base64,' + b.toString('base64')]);
  await ctx.close();
  return r;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const actx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const admin = await actx.newPage();
  await login(admin);
  const W = [390, 1000, 1280, 1440, 1920];

  if (want('default')) {
    await place(admin, BUY_DEFAULT, UNDER_DEFAULT);
    M.default = {};
    for (const w of W) {
      const now = await product(browser, w, { shot: [1280, 1440, 1920, 390].includes(w) ? 'before-default' : null });
      const old = await product(browser, w, { oldCss: true });
      delete now.shot; // rects only in the comparison
      M.default[w] = { rects: now, sameAsIntegratorStylesheet: same(now.blocks, old.blocks) && now.scrollWidth === old.scrollWidth, wrapper: now.wrapper, scrollWidth: now.scrollWidth };
    }
  }

  const placements = {
    'right-under-auth': [...BUY_DEFAULT.slice(0, 9), 'buytogether', ...BUY_DEFAULT.slice(9)],
    'right-under-price': ['title', 'price', 'buytogether', ...BUY_DEFAULT.slice(2)],
  };

  for (const [name, buy] of Object.entries(placements)) {
    if (!want(name)) continue;
    M[name] = { save: await place(admin, buy, ['details', 'reviews', 'related']) };
    for (const w of W) {
      M[name][w] = await product(browser, w, { shot: [1280, 1440, 1920].includes(w) ? `after-${name}` : null });
    }
  }

  // The phone, pixel for pixel, in both placements.
  if (want('phone')) {
    await place(admin, BUY_DEFAULT, UNDER_DEFAULT);
    const a = await product(browser, 390, { full390: true });
    fs.writeFileSync(path.join(OUT, 'phone-default-390.png'), a.png);
    await place(admin, placements['right-under-auth'], ['details', 'reviews', 'related']);
    const b = await product(browser, 390, { full390: true });
    fs.writeFileSync(path.join(OUT, 'phone-placed-right-390.png'), b.png);
    await place(admin, placements['right-under-price'], ['details', 'reviews', 'related']);
    const c = await product(browser, 390, { full390: true });
    await place(admin, BUY_DEFAULT, UNDER_DEFAULT);
    const a2 = await product(browser, 390, { full390: true });
    M.phone = {
      pixelDiffDefaultVsAuth: await pixelDiff(browser, a.png, b.png),
      pixelDiffDefaultVsPrice: await pixelDiff(browser, a.png, c.png),
      pixelDiffDefaultVsDefault: await pixelDiff(browser, a.png, a2.png),
      pngBytesIdenticalAuth: Buffer.compare(a.png, b.png) === 0,
      pngBytesIdenticalPrice: Buffer.compare(a.png, c.png) === 0,
      rectsIdenticalAuth: same(a.blocks, b.blocks), rectsIdenticalPrice: same(a.blocks, c.blocks),
      fbtParentDefault: a.fbtParent, fbtParentPlaced: b.fbtParent, fbtCount: [a.fbtCount, b.fbtCount, c.fbtCount],
      scrollWidth: [a.scrollWidth, b.scrollWidth, c.scrollWidth], fbt: [a.blocks.fbt, b.blocks.fbt, c.blocks.fbt],
    };
  }

  // The section's own laptop switch, and the total row (RH's master switch), in the right column.
  if (want('switches')) {
    await place(admin, placements['right-under-auth'], ['details', 'reviews', 'related']);
    M.switches = {};
    M.switches.offSave = (await api(admin, { dsections: { laptop: { buytogether: false } } })).status;
    M.switches.laptopOff1280 = (await product(browser, 1280)).blocks.fbt;
    M.switches.laptopOff390 = (await product(browser, 390)).blocks.fbt;
    M.switches.onSave = (await api(admin, { dsections: { laptop: { buytogether: true } } })).status;
    const cur = (await api(admin, null, 'GET')).body;
    M.switches.togetherKeys = cur.together ? Object.keys(cur.together.options || cur.together) : null;
  }

  if (want('discount')) {
    await place(admin, placements['right-under-auth'], ['details', 'reviews', 'related']);
    const r = await api(admin, { together: { options: { discount_on: true, show_total: true, row_phone: true, row_laptop: true } } });
    M.discountSave = { status: r.status, error: r.body.error || null };
    M.discountOn = {};
    for (const w of [1000, 1280, 1440, 1920]) M.discountOn[w] = await product(browser, w, { shot: `after-right-under-auth-discount-on`, motion: false });
    const r2 = await api(admin, { together: { options: { discount_on: false } } });
    M.discountOffSave = r2.status;
  }

  // Six cards in the column: four across, the rest past the edge, the one-off peek.
  if (want('six')) {
    await place(admin, placements['right-under-auth'], ['details', 'reviews', 'related']);
    const before = (await api(admin, null, 'GET')).body.together;
    M.sixSave = (await api(admin, { together: { options: { count: 6 } } })).status;
    M.six = {};
    for (const w of [1000, 1280, 1920]) {
      M.six[w] = await product(browser, w, { shot: 'after-right-six-cards' });
    }
    // The peek, mid-animation, with motion allowed.
    {
      const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, reducedMotion: 'no-preference' });
      const p = await ctx.newPage();
      await p.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
      await p.evaluate(() => document.querySelector('.kbb-fbt').scrollIntoView({ block: 'center' }));
      await p.waitForTimeout(1100);
      M.six.peek = await p.evaluate(() => {
        const f = document.querySelector('.kbb-fbt');
        const c = f.querySelector('.bt-card');
        return { cls: f.className, firstCardMarginStart: getComputedStyle(c).marginInlineStart, railScrollWidth: f.querySelector('.bt-rail').scrollWidth, railClientWidth: f.querySelector('.bt-rail').clientWidth, pageScrollWidth: document.documentElement.scrollWidth };
      });
      const box = await (await p.$('.kbb-fbt')).boundingBox();
      await p.screenshot({ path: path.join(OUT, 'after-right-six-cards-peek-1280.png'), clip: { x: box.x - 10, y: box.y - 10, width: box.width + 20, height: box.height + 20 } });
      await ctx.close();
    }
    M.sixRestore = (await api(admin, { together: { options: { count: (before && before.options && before.options.count) || 4 } } })).status;
  }

  if (want('admin')) {
    const log = (m) => process.env.RI_DEBUG && console.error('[admin] ' + m);
    await place(admin, BUY_DEFAULT, UNDER_DEFAULT);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    log('login done');
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    log('admin loaded');
    await page.waitForTimeout(600);
    await page.evaluate(() => window.go('productpage'));
    await page.waitForSelector('[data-pdstab]', { timeout: 15000 });
    await page.click('[data-pdstab]');
    await page.waitForSelector('#pdsList');
    log('tab open');
    await page.waitForTimeout(2500);
    const lists = () => page.evaluate(() => ({
      buy: [...document.querySelectorAll('#pdsBuy [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')),
      under: [...document.querySelectorAll('#pdsList [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')),
    }));
    M.admin = { start: await lists() };
    log('lists ' + JSON.stringify(M.admin.start));
    await page.screenshot({ path: path.join(OUT, 'admin-before-1440.png'), fullPage: true });
    log('before shot');
    const h = await page.$('[data-pds-handle="buytogether"]');
    await h.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    // Both ends on screen BEFORE the press: nothing is scrolled mid-drag.
    const hb = await h.boundingBox();
    const tb = await (await page.$('[data-pds-row="trust"]')).boundingBox();
    log('boxes ' + JSON.stringify([hb, tb]));
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + 20, hb.y - 20, { steps: 4 });
    await page.mouse.move(tb.x + 240, tb.y + 8, { steps: 10 });
    await page.waitForTimeout(150);
    await page.mouse.move(tb.x + 250, tb.y + 10, { steps: 2 });
    await page.waitForTimeout(200);
    M.admin.inProgress = await page.evaluate(() => ({
      dragging: [...document.querySelectorAll('.pds-row.dragging')].map((r) => r.getAttribute('data-pds-row')),
      over: [...document.querySelectorAll('.pds-row.over')].map((r) => r.getAttribute('data-pds-row')),
      after: [...document.querySelectorAll('.pds-row.over.after')].map((r) => r.getAttribute('data-pds-row')),
    }));
    log('in progress');
    await page.screenshot({ path: path.join(OUT, 'admin-drag-in-progress-1440.png') });
    await page.mouse.up();
    await page.waitForTimeout(500);
    log('dropped');
    M.admin.afterDrop = await lists();
    M.admin.previewLive = await page.evaluate(() => {
      const fr = document.querySelector('[data-ppframe="desktop"]');
      const doc = fr && fr.contentDocument;
      const pg = doc && doc.querySelector('.pdp-page');
      const bt = doc && doc.querySelector('.kbb-fbt');
      return pg ? {
        className: pg.className, buyVars: (pg.getAttribute('style') || '').split(';').map((s) => s.trim()).filter((s) => s.startsWith('--pdsb') || s.startsWith('--pds-')),
        fbtParent: bt ? bt.parentElement.className : null, fbtCount: doc.querySelectorAll('.kbb-fbt').length,
      } : null;
    });
    await page.screenshot({ path: path.join(OUT, 'admin-after-drop-1440.png'), fullPage: true });
    const pane = await page.$('[data-ppv="desktop"]');
    if (pane) {
      await page.evaluate(() => { const fr = document.querySelector('[data-ppframe="desktop"]'); const b = fr.contentDocument.querySelector('.kbb-fbt'); if (b) b.scrollIntoView({ block: 'center' }); });
      await page.waitForTimeout(300);
      await pane.screenshot({ path: path.join(OUT, 'admin-preview-live-1440.png') });
    }
    // Keyboard: ↓ twice on the handle walks it out of the bottom of the Buy column.
    await page.focus('[data-pds-handle="buytogether"]');
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(150);
    M.admin.afterArrowDown1 = await lists();
    await page.focus('[data-pds-handle="buytogether"]');
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(150);
    M.admin.afterArrowDown2 = await lists();
    await page.focus('[data-pds-handle="buytogether"]');
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(150);
    M.admin.afterArrowDown3 = await lists();
    await page.click('[data-pds-move="buytogether"]');
    await page.waitForTimeout(200);
    M.admin.afterMoveButton = await lists();
    await page.click('#ppSave');
    await page.waitForTimeout(2500);
    M.admin.afterSave = { lists: await lists(), bar: await page.evaluate(() => document.getElementById('ppDirty').textContent) };
    const saved = (await api(page, null, 'GET')).body.dsections;
    M.admin.savedPayload = { list: saved.list.map((r) => r.key), buy: saved.buy.map((r) => r.key) };
    await page.screenshot({ path: path.join(OUT, 'admin-after-save-1440.png'), fullPage: true });
    await ctx.close();
    await place(admin, BUY_DEFAULT, UNDER_DEFAULT);
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, (k, v) => (k === 'png' ? undefined : v), 2));
  await browser.close();
  console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
