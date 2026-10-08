// Lane RP: the three recommendation blocks on the product page, measured and
// photographed in Chromium at 390 and 1280.
//
//   node tools/rp-shots.cjs <tag> <port> [--measure-only]
//     -> docs/lane-rp-shots/<tag>-*.png and <tag>-report.json
//
// getBoundingClientRect / elementFromPoint here are the HARNESS measuring,
// not shop code (the shop reads no geometry).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 8761}`;
const MEASURE_ONLY = process.argv.includes('--measure-only');
const OUT = path.join(__dirname, '..', 'docs', 'lane-rp-shots');
const PRODUCT = '/product/anua-heartleaf-77-clear-pad/';
fs.mkdirSync(OUT, { recursive: true });

const VITALS = () => {
  window.__cls = 0; window.__lcp = null; window.__shifts = [];
  new PerformanceObserver((l) => l.getEntries().forEach((e) => {
    if (!e.hadRecentInput) { window.__cls += e.value; window.__shifts.push(e.value); }
  })).observe({ type: 'layout-shift', buffered: true });
  new PerformanceObserver((l) => l.getEntries().forEach((e) => {
    window.__lcp = { t: Math.round(e.startTime), el: e.element ? (e.element.tagName + '.' + (e.element.className || '')).slice(0, 60) : null };
  })).observe({ type: 'largest-contentful-paint', buffered: true });
};

async function newPage(browser, w, log) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
  await ctx.addInitScript(VITALS);
  const page = await ctx.newPage();
  log.errors = [];
  log.requests = [];
  page.on('console', (m) => { if (m.type() === 'error') log.errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => log.errors.push('pageerror: ' + String(e).slice(0, 200)));
  page.on('request', (r) => log.requests.push({ url: r.url(), type: r.resourceType(), purpose: r.headers()['sec-purpose'] || '' }));
  return { ctx, page };
}

const footImages = (page) => page.evaluate(() => {
  const out = {};
  document.querySelectorAll('section.ymal').forEach((s) => {
    const key = s.getAttribute('aria-labelledby');
    s.querySelectorAll('[data-rp-tab]').length;
    const panels = s.querySelectorAll('.rp-panel');
    const groups = panels.length ? [...panels].map((p) => [key + ':' + p.id, p]) : [[key, s]];
    groups.forEach(([k, el]) => {
      out[k] = [...el.querySelectorAll('img')].map((i) => {
        const set = (i.getAttribute('srcset') || '').split(',').map((c) => c.trim().split(' ')[0]).filter(Boolean);
        return [i.getAttribute('src'), ...set].map((u) => new URL(u, location.href).href);
      });
    });
  });
  return out;
});

const fetchedOf = (log, groups) => {
  const got = new Set(log.requests.map((r) => r.url));
  const res = {};
  for (const [k, imgs] of Object.entries(groups)) {
    res[k] = imgs.filter((cands) => cands.some((u) => got.has(u))).length + '/' + imgs.length;
  }
  return res;
};

async function shot(page, selector, file, maxH) {
  const el = await page.$(selector);
  if (!el) return false;
  await el.scrollIntoViewIfNeeded();
  await page.waitForTimeout(350);
  const b = await el.boundingBox();
  const sy = await page.evaluate(() => scrollY);
  if (!MEASURE_ONLY) {
    await page.screenshot({ path: path.join(OUT, file), fullPage: true, clip: { x: 0, y: b.y + sy, width: page.viewportSize().width, height: Math.min(b.height, maxH || 900) } });
  }
  return true;
}

const tabState = (page) => page.evaluate(() => [...document.querySelectorAll('[data-rp-tab]')].map((t) => t.textContent + (t.getAttribute('aria-selected') === 'true' ? ' [open]' : '')));

async function firstClick(page) {
  // Every card link in every block answers the point at its centre. The
  // harness turns smooth scrolling off so the box it reads is where the link
  // IS, not where an animation will put it.
  await page.addStyleTag({ content: '*{scroll-behavior:auto!important}' });
  return page.evaluate(() => {
    let ok = 0; let bad = [];
    document.querySelectorAll('section.ymal').forEach((s) => {
      s.querySelectorAll('.kbb-card a[href^="/product/"]').forEach((a) => {
        if (a.closest('[hidden]')) return;
        a.scrollIntoView({ block: 'center', inline: 'center' });
        const r = a.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return;
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        const link = hit && hit.closest('a');
        if (link && link.getAttribute('href') === a.getAttribute('href')) ok++; else bad.push(a.getAttribute('href') + ' -> ' + (hit ? hit.tagName + '.' + hit.className : 'null'));
      });
    });
    return { ok, bad: bad.slice(0, 5), badCount: bad.length };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const w of [390, 1280]) {
    const r = report[w] = {};

    /* 1. DIRECT: Google, search, a link. */
    let log = {};
    let { ctx, page } = await newPage(browser, w, log);
    await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    const groups = await footImages(page);
    r.direct = {
      tabs: await tabState(page),
      imagesOnLoad: log.requests.filter((q) => q.type === 'image').length,
      footImagesFetchedOnLoad: fetchedOf(log, groups),
      cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
      lcp: await page.evaluate(() => window.__lcp),
      requestsOnLoad: log.requests.length,
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      clientWidth: await page.evaluate(() => document.documentElement.clientWidth),
    };
    await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (/fixed|sticky/.test(getComputedStyle(el).position)) el.style.visibility = 'hidden'; }));
    const has1 = await shot(page, 'section[aria-labelledby="ymal-h"]', `${TAG}-${w}-direct-block1.png`);
    const has2 = await shot(page, 'section[aria-labelledby="rp2-h"]', `${TAG}-${w}-block2.png`, w === 390 ? 1700 : 1000);
    const has3 = await shot(page, 'section[aria-labelledby="rp3-h"]', `${TAG}-${w}-block3.png`);
    await page.waitForTimeout(800);
    r.direct.footImagesFetchedAfterScroll = fetchedOf(log, groups);
    r.direct.blocks = { one: has1, two: has2, three: has3 };
    r.direct.clsAfterScroll = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);
    r.direct.firstClick = await firstClick(page);
    if (has1) {
      r.direct.layout = await page.evaluate(() => {
        const s = document.querySelector('section[aria-labelledby="ymal-h"]');
        const tab = s.querySelector('.rp-tab');
        const card = s.querySelector('.rp-panel:not([hidden]) .kbb-card, .ymal-track > *');
        const two = document.querySelector('section[aria-labelledby="rp2-h"] .kbb-card');
        const three = document.querySelector('section[aria-labelledby="rp3-h"] .kbb-card');
        const px = (el, p) => el ? getComputedStyle(el)[p] : null;
        return {
          tabHeight: tab ? Math.round(tab.getBoundingClientRect().height) : null,
          tabFont: px(tab, 'fontSize'),
          block1CardWidth: card ? Math.round(card.getBoundingClientRect().width * 10) / 10 : null,
          block2CardWidth: two ? Math.round(two.getBoundingClientRect().width * 10) / 10 : null,
          block3CardWidth: three ? Math.round(three.getBoundingClientRect().width * 10) / 10 : null,
          block1Height: Math.round(s.getBoundingClientRect().height),
          headingFont: px(s.querySelector('h2'), 'fontSize'),
        };
      });
    }

    /* The other tab: its pictures load only once it is opened. */
    if (await page.$('#rp-t-category')) {
      const before = fetchedOf(log, groups);
      await page.click('#rp-t-category');
      await page.waitForTimeout(900);
      r.direct.afterCategoryTabClick = { tabs: await tabState(page), before, after: fetchedOf(log, groups) };
      await shot(page, 'section[aria-labelledby="ymal-h"]', `${TAG}-${w}-direct-block1-category-tab.png`);
    }

    /* Prefetch: hover a block 2 card, then click it. */
    const link = await page.$('section[aria-labelledby="rp2-h"] .kbb-card a[href^="/product/"]');
    if (link) {
      const href = await link.getAttribute('href');
      await link.scrollIntoViewIfNeeded();
      await link.hover();
      await page.waitForTimeout(700);
      const pre = log.requests.filter((q) => q.url.endsWith(href) && /prefetch/.test(q.purpose)).length;
      await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), link.click()]);
      r.direct.prefetch = {
        href,
        prefetchRequests: pre,
        deliveryType: await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType),
        landed: new URL(page.url()).pathname,
      };
    }
    r.direct.consoleErrors = log.errors;
    await ctx.close();

    /* 2. FROM A CATEGORY PAGE, then 3. FROM A BRAND PAGE, in one tab. */
    log = {};
    ({ ctx, page } = await newPage(browser, w, log));
    await page.goto(BASE + '/collections/skincare/toners/', { waitUntil: 'networkidle' });
    const catLink = await page.$(`#grid a[href="${PRODUCT}"]`) || await page.$('#grid .kbb-card a[href^="/product/"]');
    const catHref = await catLink.getAttribute('href');
    await catLink.scrollIntoViewIfNeeded();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), catLink.click()]);
    await page.waitForTimeout(400);
    r.fromCategory = {
      clicked: catHref,
      hint: await page.evaluate(() => sessionStorage.getItem('kbb_rp_from')),
      tabs: await tabState(page),
      cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
      deliveryType: await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType),
    };
    await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (/fixed|sticky/.test(getComputedStyle(el).position)) el.style.visibility = 'hidden'; }));
    await shot(page, 'section[aria-labelledby="ymal-h"]', `${TAG}-${w}-from-category-block1.png`);
    r.fromCategory.clsAfterScroll = await page.evaluate(() => Math.round(window.__cls * 10000) / 10000);

    await page.goto(BASE + '/brands/anua/', { waitUntil: 'networkidle' });
    const brLink = await page.$(`#brandGrid a[href="${PRODUCT}"]`) || await page.$('#brandGrid .kbb-card a[href^="/product/"]');
    const brHref = await brLink.getAttribute('href');
    await brLink.scrollIntoViewIfNeeded();
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), brLink.click()]);
    await page.waitForTimeout(400);
    r.fromBrand = {
      clicked: brHref,
      hint: await page.evaluate(() => sessionStorage.getItem('kbb_rp_from')),
      tabs: await tabState(page),
      cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
    };
    await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (/fixed|sticky/.test(getComputedStyle(el).position)) el.style.visibility = 'hidden'; }));
    await shot(page, 'section[aria-labelledby="ymal-h"]', `${TAG}-${w}-from-brand-block1.png`);

    /* A tampered hint is ignored. */
    await page.evaluate(() => sessionStorage.setItem('kbb_rp_from', '<img src=x onerror=alert(1)>'));
    await page.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
    r.tamperedHint = { tabs: await tabState(page), imgInjected: await page.evaluate(() => !!document.querySelector('img[src="x"]')) };
    r.fromPagesConsoleErrors = log.errors;
    await ctx.close();

    /* 4. ARABIC, right to left. */
    log = {};
    ({ ctx, page } = await newPage(browser, w, log));
    const ar = await page.goto(BASE + '/ar' + PRODUCT, { waitUntil: 'networkidle' });
    if (ar && ar.status() === 200) {
      await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (/fixed|sticky/.test(getComputedStyle(el).position)) el.style.visibility = 'hidden'; }));
      await shot(page, 'section[aria-labelledby="ymal-h"]', `${TAG}-${w}-ar-block1.png`);
      await shot(page, 'section[aria-labelledby="rp3-h"]', `${TAG}-${w}-ar-block3.png`);
      r.arabic = { dir: await page.evaluate(() => document.documentElement.dir), tabs: await tabState(page), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors: log.errors };
    }
    await ctx.close();

    /* 5. EVERY PAGE TYPE: no console error, no sideways scroll. */
    r.pages = {};
    for (const p of ['/', '/collections/skincare/toners/', '/shop/', '/brands/anua/', PRODUCT, '/blog/']) {
      log = {};
      ({ ctx, page } = await newPage(browser, w, log));
      const res = await page.goto(BASE + p, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      r.pages[p] = {
        status: res.status(),
        errors: log.errors.length,
        scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
        cls: await page.evaluate(() => Math.round(window.__cls * 10000) / 10000),
        requests: log.requests.length,
        scripts: log.requests.filter((q) => q.type === 'script').length,
      };
      await ctx.close();
    }
  }

  fs.writeFileSync(path.join(OUT, `${TAG}-report.json`), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
