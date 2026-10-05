/*
 * Lane WS — the WhatsApp side tab on the phone cart and checkout.
 *
 *   sh storage/ws-logs/preview.sh        (a copy of the int-364 harness, APP=this
 *                                         worktree, dir ws-preview, port 10160)
 *   node tools/ws-shots.cjs [port]
 *
 * Writes docs/ws-shots/*.png and docs/ws-shots/measurements.json.
 *
 * "Before" is the shop with Cart & checkout · phone switched OFF, which is
 * byte for byte what the cart and checkout printed before this lane (the
 * round button; WhatsAppButtonTabTest pins that the switch restores it).
 * Every switch is moved through the admin endpoint the screen itself uses,
 * and put back to the shipped defaults at the end.
 *
 * Measured, not eyeballed: the tab's box, the gap to the nearest content
 * beside it, its clearance above the docked checkout bar and below the
 * header, every visible in-flow element the tab's box intersects (must be
 * none), and document.documentElement.scrollWidth. Measuring is the
 * harness's job; the shop itself measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '10160';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'ws-shots');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
/* The cart refuses a headless user agent (it answers only a browser). */
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_DESK = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

const DEFAULTS = { tab_on: true, tab_size: 26, tab_y: 50, tab_palette: 'blush', tab_anim: true };

async function admin(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, userAgent: UA_DESK });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('pageError', String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function save(page, settings) {
  const r = await page.evaluate(async (s) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
    const res = await fetch('/admin-api/whatsapp-button', {
      method: 'POST', credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m.pop()) : '' },
      body: JSON.stringify({ settings: s }),
    });
    return res.status;
  }, settings);
  if (r !== 200) throw new Error('save ' + JSON.stringify(settings) + ' -> ' + r);
}

async function shopper(browser, w, h) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, userAgent: w < 901 ? UA_PHONE : UA_DESK, reducedMotion: 'reduce' });
  await ctx.route('**/*', (route) => (route.request().url().startsWith('http://127.0.0.1') ? route.continue() : route.abort()));
  const page = await ctx.newPage();
  await page.goto(`${BASE}/shop/`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async () => {
    for (const id of [1, 2, 3, 4, 5, 6, 7, 8]) {
      await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: 'product_id=' + id + '&quantity=1' });
    }
  });
  return { ctx, page };
}

async function measure(page) {
  return page.evaluate(() => {
    const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return r.width || r.height ? { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height), right: Math.round(r.right), bottom: Math.round(r.bottom) } : null; };
    const tab = document.querySelector('.kbt');
    const t = box(tab);
    const kbw = box(document.querySelector('.kbw .kbw-a'));
    const out = { scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth, tab: t, roundButton: kbw };
    if (!t) return out;
    out.label = { fontSize: getComputedStyle(document.querySelector('.kbt-l') || tab).fontSize, icon: box(document.querySelector('.kbt-i')) };

    /* What sits beside the tab and what it could cover. In-flow, visible,
       not the page's own full-width wrappers, not fixed furniture. */
    const fixedUp = (e) => { for (let a = e; a && a !== document.body; a = a.parentElement) { if (getComputedStyle(a).position === 'fixed') return true; } return false; };
    const wrappers = new Set(['MAIN', 'SECTION', 'FORM']);
    let gap = Infinity, nearest = null; const covered = [];
    document.querySelectorAll('#content *').forEach((e) => {
      if (fixedUp(e) || !e.checkVisibility({ visibilityProperty: true, opacityProperty: true })) return;
      const r = e.getBoundingClientRect(); if (!r.width || !r.height) return;
      if (r.bottom < t.y || r.top > t.bottom) return; /* only what is level with the tab now */
      if (wrappers.has(e.tagName) || r.width >= innerWidth - t.w - 1) return; /* page-wide backgrounds */
      const g = r.left - t.right; if (g < gap) { gap = g; nearest = e.tagName.toLowerCase() + '.' + String(e.className.baseVal ?? e.className).split(' ')[0]; }
      if (r.left < t.right && r.right > t.x) covered.push(e.tagName.toLowerCase() + '.' + String(e.className.baseVal ?? e.className).split(' ')[0]);
    });
    out.gapToContent = Math.round(gap); out.nearest = nearest; out.covered = covered.slice(0, 10);
    const dock = document.querySelector('.cpg-docked') || document.querySelector('.mpbar.is-on');
    out.bottomBar = box(dock);
    if (out.bottomBar) out.clearanceAboveBar = out.bottomBar.y - t.bottom;
    const head = document.querySelector('header.hd-sticky, .kbb-checkout .co-head');
    out.header = box(head);
    if (out.header) out.clearanceBelowHeader = t.y - out.header.bottom;
    out.firstLineItem = box(document.querySelector('.kbb-cartpage .ci, .kbb-checkout .ci'));
    out.stepper = box(document.querySelector('.kbb-cartpage .ci .qty, .kbb-cartpage .ci [class*="qty"]'));
    return out;
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const { ctx: actx, page: apage } = await admin(browser);
  const rows = {};
  const sigs = {};

  const shoot = async (key, w, h, settings, extra) => {
    await save(apage, { ...DEFAULTS, ...settings });
    const { ctx, page } = await shopper(browser, w, h);
    for (const p of ['cart', 'checkout']) {
      await page.goto(`${BASE}/${p}/`, { waitUntil: 'networkidle' });
      if (extra) await extra(page);
      await page.waitForTimeout(300);
      const file = `ws-${p}-${w}-${key}.png`;
      await page.screenshot({ path: path.join(OUT, file) });
      rows[file] = await measure(page);
      if (w > 900) {
        /* Every element's box on the laptop page, the tab's own column left
           out: the instrument for "unchanged" at 1280. Pixels are not one —
           the checkout's own animations make two shots of the SAME page
           differ. */
        sigs[file] = await page.evaluate(() => JSON.stringify([...document.querySelectorAll('body *')]
          .filter((e) => !e.closest('.kbt-z') && !['STYLE', 'SCRIPT', 'TEMPLATE'].includes(e.tagName))
          .map((e) => { const r = e.getBoundingClientRect(); return [e.tagName, Math.round(r.left), Math.round(r.top), Math.round(r.width), Math.round(r.height)]; })));
      }
      console.log(file, JSON.stringify(rows[file]));
    }
    await ctx.close();
  };

  await shoot('before', 390, 844, { tab_on: false });
  await shoot('after', 390, 844, {});
  await shoot('after-scrolled', 390, 844, {}, (page) => page.evaluate(() => window.scrollTo(0, 420)));
  await shoot('min22', 390, 844, { tab_size: 22 });
  await shoot('max44', 390, 844, { tab_size: 44 });
  await shoot('max44-low', 390, 844, { tab_size: 44, tab_y: 100 });
  await shoot('lilac', 390, 844, { tab_palette: 'lilac', tab_y: 30 });
  await shoot('before', 1280, 900, { tab_on: false });
  await shoot('after', 1280, 900, {});

  /* 1280 unchanged: every element in the same box with the tab off and on. */
  for (const p of ['cart', 'checkout']) {
    const a = sigs[`ws-${p}-1280-before.png`], b = sigs[`ws-${p}-1280-after.png`];
    const same = a === b;
    rows[`ws-${p}-1280 layout identical`] = { identical: same, elements: JSON.parse(a).length };
    console.log(`${p} at 1280: ${JSON.parse(a).length} element boxes, before and after ${same ? 'IDENTICAL' : 'DIFFER'}`);
  }

  /* Close-ups of the tab at its two ends, at 3x. */
  for (const [key, size] of [['min22', 22], ['default26', 26], ['max44', 44]]) {
    await save(apage, { ...DEFAULTS, tab_size: size });
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, userAgent: UA_PHONE, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/shop/`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'product_id=1&quantity=1' }); });
    await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    const t = await page.evaluate(() => { const r = document.querySelector('.kbt').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
    await page.screenshot({ path: path.join(OUT, `ws-tab-closeup-${key}.png`), clip: { x: 0, y: Math.max(0, t.y - 12), width: 120, height: t.h + 24 } });
    rows[`ws-tab-closeup-${key}.png`] = { w: Math.round(t.w), h: Math.round(t.h) };
    console.log(`closeup ${key}`, JSON.stringify(rows[`ws-tab-closeup-${key}.png`]));
    await ctx.close();
  }

  /* The admin section, with the live preview: shipped, dragged to 22 and to 44,
     and "My own two colours" with its pickers. A fresh console per width, so
     the Unfinished-changes tray of one run is not in the next one's picture. */
  await save(apage, DEFAULTS);
  for (const w of [1280, 390]) {
    const { ctx, page } = await admin(browser);
    await page.setViewportSize({ width: w, height: w > 900 ? 1000 : 844 });
    await page.goto(`${BASE}/admin?go=wabutton`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    await page.click('[data-wab-tab="tab"]');
    await page.waitForTimeout(600);
    const prevCard = async (name) => {
      if (w > 900) await page.screenshot({ path: path.join(OUT, name) });
      else await page.locator('.wab-prev').screenshot({ path: path.join(OUT, name) });
    };
    await prevCard(`ws-admin-${w}-default.png`);
    for (const size of [22, 44]) {
      await page.evaluate((v) => { const r = document.querySelector('#wab-tab_size'); r.value = v; r.dispatchEvent(new Event('input', { bubbles: true })); }, size);
      await page.waitForTimeout(400);
      const prev = await page.evaluate(() => { const r = document.querySelector('[data-wab-stage] .kbt').getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), sizeLabel: document.querySelector('[data-wab-val="tab_size"]').textContent, scrollWidth: document.documentElement.scrollWidth }; });
      rows[`ws-admin-${w}-dragged${size}.png`] = prev;
      await prevCard(`ws-admin-${w}-dragged${size}.png`);
      console.log(`admin ${w} dragged ${size}`, JSON.stringify(prev));
    }
    if (w > 900) {
      await page.selectOption('#wab-tab_palette', 'custom');
      await page.waitForTimeout(400);
      await page.evaluate(() => document.querySelector('#wab-tab_c1').scrollIntoView({ block: 'center' }));
      await page.fill('#wab-tab_c1', '#333333');
      await page.locator('#wab-tab_c1').blur();
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, `ws-admin-${w}-custom-colours.png`) });
    }
    await ctx.close();
  }

  await save(apage, DEFAULTS);
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(rows, null, 2) + '\n');
  await actx.close();
  await browser.close();
})();
