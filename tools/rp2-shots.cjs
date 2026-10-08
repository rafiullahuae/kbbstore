// Lane RP2: the product page's three blocks (brand / category / best sellers),
// measured and photographed in Chromium at 390 and 1280.
//
//   node tools/rp2-shots.cjs <tag> <port> [product-slug]
//     -> docs/lane-rp2-shots/<tag>-*.png and <tag>-report.json
//
// getBoundingClientRect / elementFromPoint are the HARNESS measuring, not shop
// code (the shop reads no geometry).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 8861}`;
const PRODUCT = `/product/${process.argv[4] || 'anua-heartleaf-77-clear-pad'}/`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-rp2-shots');
const BLOCKS = ['rp1-h', 'rp2-h', 'ymal-h'];
fs.mkdirSync(OUT, { recursive: true });

const VITALS = () => {
  window.__cls = 0; window.__lcp = null;
  new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (!e.hadRecentInput) window.__cls += e.value; }))
    .observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => {
    window.__lcp = { t: Math.round(e.startTime), el: e.element ? (e.element.tagName + '.' + (e.element.className || '')).slice(0, 60) : null };
  })).observe({ type: 'largest-contentful-paint', buffered: true });
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

async function shot(page, id, file, maxH) {
  const el = await page.$(`section[aria-labelledby="${id}"]`);
  if (!el) return false;
  await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(350);
  const b = await el.boundingBox();
  const sy = await page.evaluate(() => scrollY);
  await page.screenshot({ path: path.join(OUT, file), fullPage: true, clip: { x: 0, y: b.y + sy, width: page.viewportSize().width, height: Math.min(b.height, maxH || 1100) } });
  return true;
}

// Each block: its mode on this device, cards rendered / shown, and whether a
// hidden card's picture was ever requested.
const blockState = (page, requested) => page.evaluate(({ ids, requested }) => {
  const got = new Set(requested);
  const out = {};
  for (const id of ids) {
    const s = document.querySelector(`section[aria-labelledby="${id}"]`);
    if (!s) { out[id] = null; continue; }
    const track = s.querySelector('.kbb-pgrid');
    const cards = [...track.children];
    const shown = cards.filter((c) => getComputedStyle(c).display !== 'none');
    const hidden = cards.filter((c) => getComputedStyle(c).display === 'none');
    const pics = (c) => [...c.querySelectorAll('img')].flatMap((i) => [i.getAttribute('src'), ...(i.getAttribute('srcset') || '').split(',').map((x) => x.trim().split(' ')[0])])
      .filter(Boolean).map((u) => new URL(u, location.href).href);
    out[id] = {
      mode: getComputedStyle(track).gridAutoFlow.includes('column') ? 'slider' : 'grid',
      rendered: cards.length,
      shown: shown.length,
      hiddenPicsFetched: hidden.filter((c) => pics(c).some((u) => got.has(u))).length,
      title: s.querySelector('h2').textContent,
    };
  }
  return out;
}, { ids: BLOCKS, requested });

async function firstClick(page) {
  await page.addStyleTag({ content: '*{scroll-behavior:auto!important}' });
  return page.evaluate((ids) => {
    let ok = 0; const bad = [];
    for (const id of ids) {
      const s = document.querySelector(`section[aria-labelledby="${id}"]`);
      if (!s) continue;
      s.querySelectorAll('.kbb-card a[href^="/product/"]').forEach((a) => {
        if (getComputedStyle(a.closest('.kbb-card')).display === 'none') return;
        a.scrollIntoView({ block: 'center', inline: 'center' });
        const r = a.getBoundingClientRect();
        if (!r.width || !r.height) return;
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        const link = hit && hit.closest('a');
        if (link && link.getAttribute('href') === a.getAttribute('href')) ok++; else bad.push(a.getAttribute('href'));
      });
    }
    return { ok, bad: bad.slice(0, 5), badCount: bad.length };
  }, BLOCKS);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};
  const lang = process.env.RP2_LANG || '';

  for (const w of [390, 1280]) {
    const r = report[w] = {};
    const log = {};
    const { ctx, page } = await open(browser, w, log);
    await page.goto(BASE + lang + PRODUCT, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    r.onLoad = {
      requests: log.requests.length,
      images: log.requests.filter((q) => q.type === 'image').length,
      scripts: log.requests.filter((q) => q.type === 'script').length,
      cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
      lcp: await page.evaluate(() => window.__lcp),
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      htmlBytes: await page.evaluate(() => document.documentElement.outerHTML.length),
    };
    await hideFixed(page);
    for (const id of BLOCKS) await shot(page, id, `${TAG}-${w}-${id.replace('-h', '')}.png`);
    await page.waitForTimeout(900);
    r.blocks = await blockState(page, log.requests.map((q) => q.url));
    r.clsAfterScroll = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    r.firstClick = await firstClick(page);

    const link = await page.$('section[aria-labelledby="rp2-h"] .kbb-card a[href^="/product/"]') || await page.$('section[aria-labelledby="ymal-h"] .kbb-card a[href^="/product/"]');
    if (link && !process.env.RP2_NO_PREFETCH) {
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

  fs.writeFileSync(path.join(OUT, `${TAG}-report.json`), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
