/**
 * LANE FP — the category Filters drawer on a phone, and press feedback.
 *
 *   node tools/fp-shots.mjs before     (on main, before the patch)
 *   node tools/fp-shots.mjs after      (on lane/fp)
 *
 * Against tools/fp-preview.sh. Every number is read from the page in Chromium;
 * the measuring happens HERE, in the harness, never in the shop's own script.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright/index.mjs';

const PHASE = process.argv[2] || 'after';
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10170';
const OUT = process.env.KBB_SHOTS || 'docs/fp-shots';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const log = (k, v) => console.log(`${PHASE} ${k} ${JSON.stringify(v)}`);

const phone = () => browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });

const drawer = (page) => page.evaluate(() => {
  const col = document.getElementById('fcol');
  const head = document.querySelector('#fcol .fhead');
  const r = col.getBoundingClientRect();
  const h = head.getBoundingClientRect();
  const hy = Math.round(h.top + h.height / 2);
  const hit = document.elementFromPoint(Math.round(h.left + 40), hy);
  return {
    open: document.body.classList.contains('filters-open'),
    width: Math.round(r.width * 10) / 10,
    left: Math.round(r.left),
    scrollTop: Math.round(col.scrollTop),
    headTop: Math.round((h.top - r.top) * 10) / 10,
    headCovered: hit ? !head.contains(hit) : true,
    closeVisible: (() => { const x = document.querySelector('#fcol .fclose').getBoundingClientRect(); return x.top >= r.top && x.bottom <= r.top + col.clientHeight; })(),
    pageScrollY: Math.round(window.scrollY),
    scrollWidth: document.documentElement.scrollWidth,
    bodyOverflow: getComputedStyle(document.body).overflowY,
    scrim: (() => { const s = document.querySelector('.fscrim'); return s ? getComputedStyle(s).opacity : 'none'; })(),
  };
});

async function openDrawer(page) {
  await page.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
  await page.tap('.mobi-filter');
  await page.waitForTimeout(450);
}

/* ── the drawer at the top, scrolled, and a tap outside ─────────────────── */
async function drawerShots(tag) {
  const ctx = await phone();
  const page = await ctx.newPage();
  await openDrawer(page);
  log(`drawer-top${tag}`, await drawer(page));
  await page.screenshot({ path: `${OUT}/${PHASE}-390-drawer-top${tag}.png` });

  await page.evaluate(() => { document.getElementById('fcol').scrollTop = 520; });
  await page.waitForTimeout(150);
  log(`drawer-scrolled${tag}`, await drawer(page));
  await page.screenshot({ path: `${OUT}/${PHASE}-390-drawer-scrolled${tag}.png` });

  if (tag === '') {
    // A wheel over the dimmed page: does the page underneath move?
    await page.mouse.move(370, 600);
    await page.mouse.wheel(0, 600);
    await page.waitForTimeout(250);
    log('page-under-after-wheel', await drawer(page));

    // Esc
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    log('after-escape', { open: await page.evaluate(() => document.body.classList.contains('filters-open')) });

    // × still works (re-opened from a clean state, so a phase where Esc did nothing still runs)
    const shut = () => page.evaluate(() => { document.body.classList.remove('filters-open'); window.scrollTo(0, 0); });
    await shut();
    await page.waitForTimeout(400);
    await page.tap('.mobi-filter');
    await page.waitForTimeout(400);
    await page.tap('#fcol .fclose');
    await page.waitForTimeout(400);
    log('after-x', { open: await page.evaluate(() => document.body.classList.contains('filters-open')) });

    // A tap inside the drawer on empty space (the group heading) does not close it.
    await shut();
    await page.waitForTimeout(400);
    await page.tap('.mobi-filter');
    await page.waitForTimeout(400);
    await page.tap('#fcol .fgroup h4');
    await page.waitForTimeout(300);
    log('after-tap-inside', { open: await page.evaluate(() => document.body.classList.contains('filters-open')) });

    // A tap on the empty area to the right.
    const url = page.url();
    await page.touchscreen.tap(365, 520);
    await page.waitForTimeout(450);
    log('after-tap-outside', { open: await page.evaluate(() => document.body.classList.contains('filters-open')), sameUrl: page.url() === url, url: page.url() });
    await page.screenshot({ path: `${OUT}/${PHASE}-390-after-tap-outside.png` });
  }
  await ctx.close();
}

/* ── laptop: the rail beside the grid, which must not move ──────────────── */
async function laptop() {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
  await page.click('#showFilters').catch(() => {});
  await page.waitForTimeout(300);
  const m = await page.evaluate(() => {
    const c = document.getElementById('fcol').getBoundingClientRect();
    const h = getComputedStyle(document.querySelector('#fcol .fhead'));
    return { railWidth: Math.round(c.width), railLeft: Math.round(c.left), headPosition: h.position, scrim: (() => { const s = document.querySelector('.fscrim'); return s ? getComputedStyle(s).display : 'none'; })(), scrollWidth: document.documentElement.scrollWidth };
  });
  log('laptop-1280', m);
  await page.screenshot({ path: `${OUT}/${PHASE}-1280-shop.png` });
  await page.close();
}

/* ── press feedback: which elements get it ──────────────────────────────── */
const CENSUS = [
  ['header icon (.ib)', 'header .ib'],
  ['burger (.kbbmi)', '.kbbmi'],
  ['filter drawer × (.fclose)', '#fcol .fclose'],
  ['column buttons (.colsel button)', '.colsel button'],
  ['card heart (.kbw-*/.heart)', '.heart, .gwish, .kbb-card .kbw'],
  ['mobile menu ×  (.mm-x)', '.mm-x'],
  ['mobile menu row (.mm-it)', '.mm-it'],
  ['mobile menu search', '.mm-srch'],
  ['header search box (.search-in)', '.search-in'],
  ['phone search field', '[data-kbb-msearch]'],
  ['filter drawer row (.fopt)', '#fcol .fopt'],
  ['filter toggle row (.ftog)', '#fcol .ftog'],
  ['price chip (.pchip)', '#fcol .pchip'],
  ['Filters button (.mobi-filter)', '.mobi-filter'],
  ['sort select', '.sortsel select'],
  ['product card link', '.kbb-card a'],
  ['Add to cart (.kbb-card-cart)', '.kbb-card-cart'],
  ['pager link', '.page-numbers'],
  ['footer link', '.kft a'],
];

async function census() {
  const ctx = await phone();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
  const rows = await page.evaluate((list) => list.map(([label, sel]) => {
    const el = document.querySelector(sel);
    if (!el) return { label, found: false };
    const target = el.querySelector('svg, span, input') || el;
    target.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: 0, isPrimary: true, pointerType: 'touch' }));
    const got = [...document.querySelectorAll('.kbb-pressed')];
    const hit = got.find((g) => g === el || el.contains(g) || g.contains(el));
    const r = (hit || el).getBoundingClientRect();
    const out = { label, pressed: !!hit, on: hit ? (hit.className || hit.tagName).toString().split(' ')[0] : null, w: Math.round(r.width), h: Math.round(r.height) };
    document.dispatchEvent(new PointerEvent('pointerup', { bubbles: true, isPrimary: true }));
    document.querySelectorAll('.kbb-pressed,.kbb-rip,.kbb-rip2').forEach((g) => g.classList.remove('kbb-pressed', 'kbb-rip', 'kbb-rip2'));
    return out;
  }), CENSUS);
  for (const r of rows) log('press', r);

  // Pictures, mid-press: a header icon, the phone search field, a menu row.
  const hold = async (sel, name) => {
    const box = await page.locator(sel).first().boundingBox();
    if (!box) return;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.waitForTimeout(140);
    await page.screenshot({ path: `${OUT}/${PHASE}-390-press-${name}.png`, clip: { x: 0, y: Math.max(0, box.y - 60), width: 390, height: 200 } });
    await page.mouse.up();
    await page.waitForTimeout(700);
  };
  await hold('header .ib', 'header-icon');
  await hold('[data-kbb-msearch]', 'search-box');
  await page.tap('.kbbmi');
  await page.waitForTimeout(500);
  await hold('.mm-it', 'menu-row');
  await ctx.close();
}

/* ── the admin control, and the drawer at a second width ────────────────── */
async function admin() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 2400 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"], input[name="email"]', 'owner@preview.test');
  await page.fill('input[type="password"], input[name="password"]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"]')]);
  await page.waitForFunction(() => typeof window.go === 'function' && document.querySelector('.side .nav-item[data-go="sitelayout"]'), null, { timeout: 15000 });
  await page.evaluate(() => window.go('sitelayout'));
  await page.waitForSelector('[data-sls-tab="grid"]', { timeout: 10000 });
  await page.click('[data-sls-tab="grid"]');
  await page.waitForSelector('[data-sls-key="filter_w"]');
  const card = page.locator('[data-sls-key="filter_w"]').locator('xpath=ancestor::div[contains(@class,"sls-card")][1]');
  await card.screenshot({ path: `${OUT}/after-admin-grid-default.png` });

  const set = (k, v) => page.evaluate(([k, v]) => {
    const el = document.querySelector(`[data-sls-key="${k}"]`);
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, [k, v]);
  await set('filter_w', 85);
  await set('filter_max', 600);
  await page.waitForTimeout(200);
  await card.screenshot({ path: `${OUT}/after-admin-grid-moved.png` });
  log('admin-grid', await page.evaluate(() => ['filter_w', 'filter_max'].map((k) => { const e = document.querySelector(`[data-sls-key="${k}"]`); return [k, e.min, e.max, e.step, e.value]; })));
  const saved = page.waitForResponse((r) => r.request().method() !== 'GET' && /layout/i.test(r.url()));
  await page.click('[data-sls-save]');
  log('admin-save', { status: (await saved).status() });
  await page.waitForTimeout(400);

  // Press feedback tab: the new "Which controls" select.
  await page.click('[data-sls-tab="press"]');
  await page.waitForSelector('[data-sls-key="press_scope"]');
  await page.locator('[data-sls-key="press_scope"]').locator('xpath=ancestor::div[contains(@class,"sls-card")][1]').screenshot({ path: `${OUT}/after-admin-press.png` });
  log('admin-fields', await page.evaluate(() => ({
    filter_w: (() => { const e = document.querySelector('[data-sls-key="filter_w"]'); return e ? [e.min, e.max, e.value] : null; })(),
    press_scope: [...document.querySelectorAll('[data-sls-key="press_scope"] option')].map((o) => o.value),
    scrollWidth: document.documentElement.scrollWidth,
  })));
  await ctx.close();
}

async function resetWidth() {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[type="email"], input[name="email"]', 'owner@preview.test');
  await page.fill('input[type="password"], input[name="password"]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type="submit"]')]);
  await page.waitForFunction(() => typeof window.go === 'function' && document.querySelector('.side .nav-item[data-go="sitelayout"]'), null, { timeout: 15000 });
  await page.evaluate(() => window.go('sitelayout'));
  await page.click('[data-sls-tab="grid"]');
  await page.waitForSelector('[data-sls-key="filter_w"]');
  await page.evaluate(() => {
    for (const [k, v] of [['filter_w', 90], ['filter_max', 300]]) {
      const el = document.querySelector(`[data-sls-key="${k}"]`);
      el.value = String(v);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
  const saved = page.waitForResponse((r) => r.request().method() !== 'GET' && /layout/i.test(r.url()));
  await page.click('[data-sls-save]');
  await saved;
  await ctx.close();
}

await drawerShots('');
await laptop();
await census();
if (PHASE === 'after') {
  await admin();
  await drawerShots('-85pc');
  await resetWidth();
}
await browser.close();
