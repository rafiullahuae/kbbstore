/*
 * LANE OA4 — Users & Roles → Owner app → Customise app, photographed and measured.
 *
 *   OA4_MODE=app   KBB_BASE=http://127.0.0.1:P KBB_APP=/<secret> OA4_OUT=<dir> node tools/oa4-shots.cjs
 *       every screen of the app at its defaults, 390 (iPhone UA) and 820 (Android tablet UA), PNG
 *   OA4_MODE=diff  OA4_A=<dir> OA4_B=<dir> node tools/oa4-shots.cjs
 *       pixel-compares two such runs (the build before this lane and this one)
 *   OA4_MODE=custom KBB_BASE=... KBB_APP=... node tools/oa4-shots.cjs
 *       three customised states saved through the admin endpoint, at 390 and 820
 *   OA4_MODE=admin KBB_BASE=... node tools/oa4-shots.cjs
 *       the Customise app card at 1280 and 390, its live preview, a refused colour
 *
 * Against tools/mac-preview.sh (seeded by tools/mac-seed.php; PIN 482615,
 * admin owner@example.com / preview-password). Output: docs/oa4-shots/ and
 * docs/oa4-shots/numbers.txt (appended per mode). The MEASURING is the
 * harness measuring the page — the app itself measures nothing.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const MODE = process.env.OA4_MODE || 'app';
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10481';
const APP = process.env.KBB_APP;
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const DOCS = path.join(__dirname, '..', 'docs', 'oa4-shots');
fs.mkdirSync(DOCS, { recursive: true });

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const ANDROID_TAB = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const DEVICES = {
  'phone-390': { w: 390, h: 844, ua: IPHONE, dpr: 2, ios: true },
  'tablet-820': { w: 820, h: 1180, ua: ANDROID_TAB, dpr: 1 },
};

const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));
const flush = () => fs.appendFileSync(path.join(DOCS, 'numbers.txt'), lines.splice(0).join('\n') + '\n');

async function phone(browser, d) {
  const ctx = await browser.newContext({ viewport: { width: d.w, height: d.h }, deviceScaleFactor: d.dpr, isMobile: d.w < 700, hasTouch: true, userAgent: d.ua });
  // The add-to-home sheet is offered once per phone; already seen here, so it never covers a shot.
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  if (d.ios) await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const page = await ctx.newPage();
  const net = { open: 0, all: [] };
  page.on('request', (q) => { net.all.push(q.url()); if (q.url().includes('/api/')) net.open++; });
  const done = (q) => { if (q.url().includes('/api/')) net.open--; };
  page.on('requestfinished', done);
  page.on('requestfailed', done);
  page.on('pageerror', (e) => say('  PAGE ERROR: ' + e.message));
  page.oaNet = net;
  return { ctx, page, net };
}

const idle = async (page) => {
  await page.waitForFunction(() => !document.querySelector('[data-sk]'), null, { timeout: 15000 });
  for (let i = 0; i < 100 && page.oaNet.open > 0; i++) await sleep(100);
  await page.waitForTimeout(700);
};
const go = async (page, hash) => { await page.evaluate((h) => { location.hash = h; }, hash); await idle(page); };

async function signIn(page, dev) {
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'oa4-' + dev);
  const enrol = page.waitForResponse((r) => r.url().endsWith('/api/enrol'));
  await page.click('[data-enrol] button[type=submit]');
  const body = await (await enrol).text();
  await idle(page);
  return body;
}

/* Times move between two seeded previews; their boxes are masked the same in both runs. */
const MASK = ['.upd', '.top p', 'time', '.lt-dash .kick', '.row.ord small', '.od-sum .ln > span', '.tl small', '.ver', '.prof small', '.nt small', '.cu-h p', '.re small', '.plain-list small'];

async function screens(page) {
  const firstHref = (sel) => page.evaluate((s) => { const a = document.querySelector(s); return a ? a.getAttribute('href') || '' : ''; }, sel);
  await go(page, '#/orders');
  const ord = await page.evaluate(() => { const r = document.querySelector('.row.ord'); return r ? r.getAttribute('data-o') : ''; });
  await go(page, '#/products');
  const prod = await firstHref('a.row[href^="#/products/"]');
  await go(page, '#/customers');
  const cust = await firstHref('a.row[href^="#/customers/"]');
  return [['01-my-store', '#/'], ['02-orders', '#/orders'], ['03-order', '#/orders/' + ord], ['04-products', '#/products'], ['05-product', prod],
    ['06-customers', '#/customers'], ['07-customer', cust], ['08-more', '#/more'], ['09-notifications', '#/notifications']];
}

async function shootApp(browser, out, tag) {
  fs.mkdirSync(out, { recursive: true });
  for (const [dev, d] of Object.entries(DEVICES)) {
    const { ctx, page, net } = await phone(browser, d);
    const enrolBody = await signIn(page, dev);
    const list = await screens(page);
    for (const [name, hash] of list) {
      await go(page, '#/more'); await go(page, hash);
      await page.screenshot({ path: path.join(out, dev + '--' + name + '.png'), mask: MASK.map((s) => page.locator(s)), maskColor: '#888' });
    }
    const m = await page.evaluate(() => ({ cls: document.documentElement.className, style: document.documentElement.getAttribute('style'), sw: document.documentElement.scrollWidth, vw: innerWidth }));
    const fonts = net.all.filter((u) => /\.woff2/.test(u)).length;
    let ui = null; try { ui = JSON.parse(enrolBody).ui; } catch (e) { ui = null; }
    say('  ' + tag + ' ' + dev + ': <html class="' + m.cls + '"> style=' + m.style + '; font requests ' + fonts + '; scrollWidth ' + m.sw + '/' + m.vw
      + (ui ? '; ui payload ' + JSON.stringify(ui).length + ' bytes' : '; (no ui in payload)'));
    await ctx.close();
  }
}

/* Pixel compare in the browser: two PNGs onto canvases, count the pixels that differ. */
async function diff(browser, a, b) {
  const page = await (await browser.newContext()).newPage();
  let all = 0;
  for (const f of fs.readdirSync(a).filter((x) => x.endsWith('.png')).sort()) {
    if (!fs.existsSync(path.join(b, f))) { say('  ' + f + ': MISSING in B'); all++; continue; }
    const r = await page.evaluate(async ([x, y]) => {
      const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = src; });
      const [i, j] = [await load(x), await load(y)];
      if (i.width !== j.width || i.height !== j.height) return { size: [i.width, i.height, j.width, j.height] };
      const c = (img) => { const k = document.createElement('canvas'); k.width = img.width; k.height = img.height; const g = k.getContext('2d'); g.drawImage(img, 0, 0); return g.getImageData(0, 0, k.width, k.height).data; };
      const p = c(i), q = c(j);
      let n = 0;
      for (let t = 0; t < p.length; t += 4) if (p[t] !== q[t] || p[t + 1] !== q[t + 1] || p[t + 2] !== q[t + 2]) n++;
      return { n, px: i.width * i.height, w: i.width, h: i.height };
    }, ['data:image/png;base64,' + fs.readFileSync(path.join(a, f)).toString('base64'), 'data:image/png;base64,' + fs.readFileSync(path.join(b, f)).toString('base64')]);
    if (r.size) { say('  ' + f + ': SIZE DIFFERS ' + r.size.join('x')); all++; continue; }
    say('  ' + f + ' (' + r.w + 'x' + r.h + '): ' + r.n + ' pixels differ' + (r.n ? '  <-- DIFFERENT' : ''));
    if (r.n) all++;
  }
  say('  => ' + (all ? all + ' screens differ' : 'every screen pixel-identical'));
}

async function admin(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  return { ctx, page };
}
async function putUi(page, body) {
  return page.evaluate(async (b) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const g = await fetch('/admin-api/owner-app/ui', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then((r) => r.json());
    const ui = Object.assign({}, g.defaults, b, { screens: Object.assign({}, g.defaults.screens, b.screens || {}), functions: Object.assign({}, g.defaults.functions, b.functions || {}) });
    const r = await fetch('/admin-api/owner-app/ui', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(ui) });
    return r.status;
  }, body);
}

const STATES = {
  'A-teal-compact-system': { accent: '#0F766E', density: 'compact', font: 'system', initials: 'EB', store_name: 'Extra Beauty' },
  'B-customers-products-off': { screens: { customers: false, products: false } },
  'C-sections-reordered': { sections: ['top', 'avg', 'returning', 'hero', 'needs'], sections_off: ['needs'], header: 'standard', figure: 'l', corners: 'medium' },
};

async function custom(browser) {
  const { ctx: actx, page: ap } = await admin(browser);
  for (const [name, ui] of Object.entries(STATES)) {
    say('\n== state ' + name + ' ' + JSON.stringify(ui) + ' — saved: ' + await putUi(ap, ui));
    for (const [dev, d] of Object.entries(DEVICES)) {
      const { ctx, page, net } = await phone(browser, d);
      await signIn(page, dev + '-' + name);
      await page.screenshot({ path: path.join(DOCS, name + '--' + dev + '--my-store.jpg'), type: 'jpeg', quality: 84 });
      const nums = await page.evaluate(() => {
        const q = (s) => document.querySelector(s), r = (s) => { const e = q(s); return e ? Math.round(e.getBoundingClientRect().height * 10) / 10 : null; };
        return { cls: document.documentElement.className, acc: getComputedStyle(document.documentElement).getPropertyValue('--acc').trim(),
          font: getComputedStyle(document.body).fontFamily.slice(0, 40), tabs: Array.from(document.querySelectorAll('.nav a span')).map((s) => s.textContent),
          sections: Array.from(document.querySelectorAll('#oa-view .body > *')).map((e) => e.className.replace('card ', '')).join(' | '),
          heroH: r('.hero'), figPx: q('.fig b') ? getComputedStyle(q('.fig b')).fontSize : null, headerH: r('.lt-dash'), sw: document.documentElement.scrollWidth, vw: innerWidth };
      });
      say('  ' + dev + ': classes "' + nums.cls + '"; --acc ' + nums.acc + '; body font ' + nums.font + '; tabs ' + nums.tabs.join(', ') + '; sections ' + nums.sections
        + '; header ' + nums.headerH + 'px; figure ' + nums.figPx + '; scrollWidth ' + nums.sw + '/' + nums.vw + '; font files requested ' + net.all.filter((u) => /\.woff2/.test(u)).length);
      if (name === 'B-customers-products-off') {
        await go(page, '#/more');
        await page.screenshot({ path: path.join(DOCS, name + '--' + dev + '--more.jpg'), type: 'jpeg', quality: 84 });
        const rows = await page.evaluate(() => Array.from(document.querySelectorAll('.plain-list .rm b')).map((b) => b.textContent));
        const appAsked = net.all.filter((u) => /\/api\/(customers|products)/.test(u)).length;
        await go(page, '#/customers');
        const landed = await page.evaluate(() => location.hash);
        const refused = await page.evaluate(async () => { const r = await fetch(document.body.getAttribute('data-base') + '/api/customers', { headers: { Accept: 'application/json', 'X-OA': '1', 'X-OA-CSRF': sessionStorage.getItem('oa.k') } }); return r.status + ' ' + (await r.json()).code; });
        say('  ' + dev + ': More rows ' + JSON.stringify(rows) + '; #/customers lands on ' + landed
          + '; GET /api/customers by hand → ' + refused + '; requests the app itself made to /api/customers|products: ' + appAsked);
      } else {
        await go(page, '#/orders');
        await page.screenshot({ path: path.join(DOCS, name + '--' + dev + '--orders.jpg'), type: 'jpeg', quality: 84 });
        const row = await page.evaluate(() => { const r = document.querySelector('.list .row.ord'); return r ? Math.round(r.getBoundingClientRect().height * 10) / 10 : null; });
        say('  ' + dev + ': order row ' + row + 'px tall');
      }
      await ctx.close();
    }
  }
  say('  reset to defaults: ' + await putUi(ap, {}));
  await actx.close();
}

async function adminShots(browser) {
  say('\n== admin: Platform → Users & Roles → Owner app → Customise app');
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => say('PAGE ERROR ' + e.message));
    const reqs = [];
    page.on('request', (q) => reqs.push(q.method() + ' ' + q.url().replace(BASE, '')));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin?go=users', { waitUntil: 'networkidle' });
    await page.waitForSelector('#rlt_ownerapp', { timeout: 15000 });
    await page.click('#rlt_ownerapp');
    await page.waitForSelector('[data-oac-tab="ui"]', { timeout: 10000 });
    await page.screenshot({ path: path.join(DOCS, 'admin-' + w + '--0-subtabs.jpg'), type: 'jpeg', quality: 82 });
    await page.click('[data-oac-tab="ui"]');
    await page.waitForSelector('.oac-ctl');
    await page.waitForFunction(() => { const f = document.querySelector('[data-oac-frame]'); return f && f.contentDocument && f.contentDocument.querySelector('#pv .lt-dash'); });
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(DOCS, 'admin-' + w + '--1-card.jpg'), type: 'jpeg', quality: 82, fullPage: true });
    const geo = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: innerWidth, cards: document.querySelectorAll('.oac-ctl .rl-card').length,
      pv: (() => { const r = document.querySelector('.oac-phone').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; })() }));
    const n0 = reqs.length;
    // Live preview: a preset, compact, a section moved up, Customers off — no request.
    await page.click('[data-acc="#0F766E"]');
    await page.selectOption('[data-k="density"]', 'compact');
    await page.selectOption('[data-k="font"]', 'system');
    await page.click('[data-sec-mv="-1"][data-sk="top"]');
    await page.click('[data-sec-mv="-1"][data-sk="top"]');
    await page.uncheck('[data-g="screens"][data-gk="products"]');
    await page.waitForTimeout(400);
    const pv = await page.evaluate(() => { const d = document.querySelector('[data-oac-frame]').contentDocument; return { cls: d.documentElement.className, acc: d.documentElement.style.getPropertyValue('--acc'), tabs: Array.from(d.querySelectorAll('.nav a span')).map((s) => s.textContent).join(', '), order: Array.from(d.querySelectorAll('#pv .body > *')).map((e) => e.className).join(' | ') }; });
    const during = reqs.slice(n0).filter((r) => !/\.(css|woff2|png|svg)/.test(r));
    if (w < 700) await page.locator('.oac-pv').screenshot({ path: path.join(DOCS, 'admin-' + w + '--2-live-preview.jpg'), type: 'jpeg', quality: 82 });
    else { await page.locator('[data-sec-mv]').first().scrollIntoViewIfNeeded(); await page.waitForTimeout(200); await page.screenshot({ path: path.join(DOCS, 'admin-' + w + '--2-live-preview.jpg'), type: 'jpeg', quality: 82 }); }
    // A colour white text cannot be read on: warned live, refused on Save.
    await page.fill('[data-acc-hex]', '#F4A6B8');
    await page.waitForTimeout(200);
    const warn = await page.textContent('[data-ct]');
    await page.locator('[data-ct]').scrollIntoViewIfNeeded();
    await page.locator('.oac-ctl .rl-card').first().screenshot({ path: path.join(DOCS, 'admin-' + w + '--3-refused-colour.jpg'), type: 'jpeg', quality: 82 });
    const put = await page.evaluate(async () => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); const r = await fetch('/admin-api/owner-app/ui', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ accent: '#F4A6B8' }) }); return r.status + ' ' + (await r.json()).message; });
    say('  ' + w + 'px: ' + geo.cards + ' cards, phone preview ' + geo.pv.join('x') + 'px, scrollWidth ' + geo.sw + '/' + geo.vw
      + '; after 6 changes the preview has classes "' + pv.cls + '", --acc ' + pv.acc + ', tabs ' + pv.tabs + ', sections ' + pv.order
      + '; requests during those changes: ' + (during.length ? during.join(', ') : 'none') + '; #F4A6B8 warns "' + warn.trim() + '"; server: ' + put);
    await ctx.close();
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  if (MODE === 'app') { say('\n== app at defaults: ' + process.env.OA4_OUT); await shootApp(browser, process.env.OA4_OUT, process.env.OA4_TAG || ''); }
  else if (MODE === 'diff') { say('\n== pixel compare ' + process.env.OA4_A + ' vs ' + process.env.OA4_B); await diff(browser, process.env.OA4_A, process.env.OA4_B); }
  else if (MODE === 'custom') await custom(browser);
  else if (MODE === 'admin') await adminShots(browser);
  await browser.close();
  flush();
})().catch((e) => { console.error(e); flush(); process.exit(1); });
