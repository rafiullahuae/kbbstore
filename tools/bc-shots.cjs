// Lane BC: the product page's foot as the owner's third plan ships it —
// brand | category TABS, then Continue shopping — measured and photographed
// in Chromium at 390 and 1280, each tab opened.
//
//   node tools/bc-shots.cjs <tag> <port> [product-slug]   (BC_LANG=/ar for Arabic)
//     -> docs/lane-bc-shots/<tag>-*.png and <tag>-report.json
//
// getBoundingClientRect / elementFromPoint / checkVisibility are the HARNESS
// measuring, not shop code (the shop reads no geometry).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 8961}`;
const SLUG = process.argv[4] || 'anua-heartleaf-77-clear-pad';
const PRODUCT = `/product/${SLUG}/`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-bc-shots');
const BLOCKS = ['ymal-h', 'rp1-h', 'rp2-h', 'rpf-h', 'rp3-h'];
const lang = process.env.BC_LANG || '';
fs.mkdirSync(OUT, { recursive: true });

const VITALS = () => {
  window.__cls = 0;
  new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (!e.hadRecentInput) window.__cls += e.value; }))
    .observe({ type: 'layout-shift', buffered: true });
};

async function open(browser, w, log) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } });
  await ctx.addInitScript(VITALS);
  const page = await ctx.newPage();
  log.errors = []; log.requests = [];
  page.on('console', (m) => { if (m.type() === 'error') log.errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => log.errors.push('pageerror: ' + String(e).slice(0, 200)));
  page.on('request', (r) => log.requests.push({ url: r.url(), type: r.resourceType(), purpose: r.headers()['sec-purpose'] || '' }));
  return { ctx, page };
}

const hideFixed = (page) => page.evaluate(() => document.querySelectorAll('body *').forEach((el) => {
  if (/fixed|sticky/.test(getComputedStyle(el).position)) el.style.visibility = 'hidden';
}));

async function shot(page, id, file) {
  const el = await page.$(`section[aria-labelledby="${id}"]`);
  if (!el) return false;
  await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(350);
  const b = await el.boundingBox();
  const sy = await page.evaluate(() => scrollY);
  await page.screenshot({ path: path.join(OUT, file), fullPage: true, clip: { x: 0, y: b.y + sy, width: page.viewportSize().width, height: Math.min(b.height, 1100) } });
  return true;
}

// Every track: mode on this device, cards rendered / visible, and whether a
// card that is not visible (closed tab, or hidden by the per-device count)
// had its picture requested.
const state = (page, requested) => page.evaluate(({ ids, requested }) => {
  const got = new Set(requested);
  const pics = (c) => [...c.querySelectorAll('img')].flatMap((i) => [i.getAttribute('src'), ...(i.getAttribute('srcset') || '').split(',').map((x) => x.trim().split(' ')[0])])
    .filter(Boolean).map((u) => new URL(u, location.href).href);
  const out = {};
  for (const id of ids) {
    const s = document.querySelector(`section[aria-labelledby="${id}"]`);
    if (!s) continue;
    out[id] = { title: s.querySelector('h2').textContent, tabs: [...s.querySelectorAll('[data-rp-tab]')].map((t) => t.textContent + (t.getAttribute('aria-selected') === 'true' ? ' (open)' : '')), tracks: {} };
    s.querySelectorAll('.kbb-pgrid').forEach((track) => {
      const cards = [...track.children];
      const vis = cards.filter((c) => c.checkVisibility());
      out[id].tracks[track.id] = {
        mode: getComputedStyle(track).gridAutoFlow.includes('column') ? 'slider' : 'grid',
        rendered: cards.length, visible: vis.length,
        invisiblePicsFetched: cards.filter((c) => !c.checkVisibility() && pics(c).some((u) => got.has(u))).length,
      };
    });
  }
  return out;
}, { ids: BLOCKS, requested });

async function firstClick(page) {
  await page.addStyleTag({ content: '*{scroll-behavior:auto!important}' });
  return page.evaluate((ids) => {
    let ok = 0; const bad = [];
    const check = (a) => {
      a.scrollIntoView({ block: 'center', inline: 'center' });
      const r = a.getBoundingClientRect();
      if (!r.width || !r.height) return;
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      const t = hit && (hit.closest('a') || hit.closest('button'));
      if (t === a || (t && a.getAttribute('href') && t.getAttribute('href') === a.getAttribute('href'))) ok++; else bad.push(a.getAttribute('href') || a.id);
    };
    for (const id of ids) {
      const s = document.querySelector(`section[aria-labelledby="${id}"]`);
      if (!s) continue;
      s.querySelectorAll('[data-rp-tab]').forEach(check);
      s.querySelectorAll('.kbb-card a[href*="/product/"]').forEach((a) => { if (a.checkVisibility()) check(a); });
    }
    return { ok, badCount: bad.length, bad: bad.slice(0, 5) };
  }, BLOCKS);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const w of [390, 1280]) {
    const r = report[w] = {};
    const log = {};
    const { ctx, page } = await open(browser, w, log);
    await page.goto(BASE + lang + PRODUCT, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    r.onLoad = {
      requests: log.requests.length,
      scripts: log.requests.filter((q) => q.type === 'script').map((q) => q.url.replace(BASE, '')),
      cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      htmlBytes: (await (await page.request.get(BASE + lang + PRODUCT)).body()).length,
    };
    await hideFixed(page);
    for (const id of BLOCKS) await shot(page, id, `${TAG}-${w}-${id.replace('-h', '')}${id === 'ymal-h' ? '-tab1' : ''}.png`);
    await page.waitForTimeout(900);
    r.beforeTabClick = await state(page, log.requests.map((q) => q.url));
    r.clsAfterScroll = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    r.firstClick = await firstClick(page);

    // The other tab, opened by a real click.
    const other = await page.$('section[aria-labelledby="ymal-h"] [data-rp-tab][aria-selected="false"]');
    if (other) {
      await other.scrollIntoViewIfNeeded();
      await other.click();
      await page.waitForTimeout(900);
      await shot(page, 'ymal-h', `${TAG}-${w}-ymal-tab2.png`);
      r.afterTabClick = await state(page, log.requests.map((q) => q.url));
      r.clsAfterTab = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    }

    const link = await page.$('section[aria-labelledby="rp3-h"] .kbb-card a[href*="/product/"]');
    if (link) {
      const href = await link.getAttribute('href');
      await link.scrollIntoViewIfNeeded();
      await link.hover();
      await page.waitForTimeout(700);
      const pre = log.requests.filter((q) => q.url.endsWith(href) && /prefetch/.test(q.purpose)).length;
      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), link.click()]);
      r.prefetch = { href, prefetchRequests: pre, deliveryType: await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType) };
    }
    r.consoleErrors = log.errors;
    await ctx.close();
  }

  // Where the shopper came from: open a category listing that names this
  // product's shelf, click into the product from its grid, and see which tab
  // is open. Then the same from the brand's page.
  if (!process.env.BC_NO_HINT) {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + lang + PRODUCT, { waitUntil: 'networkidle' });
    const paths = await page.evaluate(() => ({
      cat: (document.querySelector('#rp-p-category') || { dataset: {} }).dataset.rpPaths || '',
      brand: (document.querySelector('#rp-p-brand') || { dataset: {} }).dataset.rpPaths || '',
    }));
    report.hint = {};
    for (const [k, list] of Object.entries(paths)) {
      const listing = list.split(' ')[0];
      if (!listing) continue;
      await page.goto(BASE + listing, { waitUntil: 'networkidle' });
      let how = 'click from the listing grid';
      const a = await page.$(`#grid a[href$="${PRODUCT}"], #brandGrid a[href$="${PRODUCT}"]`);
      if (a) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), a.click()]);
      } else {
        // Not on the listing's first page: click any card there (writes the
        // same hint), then open the product.
        how = 'click any card on the listing, then open the product';
        const any = await page.$('#grid a[href*="/product/"], #brandGrid a[href*="/product/"], #grid a[href*="/product/"]');
        if (any) await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), any.click()]);
        await page.goto(BASE + lang + PRODUCT, { waitUntil: 'networkidle' });
      }
      await page.waitForTimeout(300);
      report.hint[k] = { listing, how, stored: await page.evaluate(() => sessionStorage.getItem('kbb_rp_from')),
        open: await page.evaluate(() => (document.querySelector('[data-rp-tab][aria-selected="true"]') || {}).textContent) };
    }
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, `${TAG}-report.json`), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
