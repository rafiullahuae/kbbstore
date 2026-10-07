/* Lane LH -- the front-end checks CLAUDE.md asks of every shop-facing change,
 * against a preview (before or after), in Chromium at 390 and 1280.
 *
 *   node tools/lh-verify.cjs <base-url> <label> [shots-dir]
 *
 * Prints one JSON line per check:
 *   slider   when the first slide change happens, against DOMContentLoaded
 *            and load (a MutationObserver on the track's style attribute --
 *            the harness reads nothing from layout either);
 *   cls      every layout-shift entry on home, category, shop, brand, product
 *            and blog, with the moved nodes;
 *   console  errors and failed requests on the same six pages;
 *   click    elementFromPoint over the centre of the first banner link, the
 *            first product card link and the first header link, and a real
 *            click on a product link that must navigate;
 *   prefetch hovering a product, a category and a brand link fires a
 *            speculation-rules prefetch and the click is served from it.
 * Screenshots (390 and 1280, home and brand) go to shots-dir when given. */
const { chromium } = require('/home/user/kbbstore/node_modules/playwright');
const fs = require('fs');

const [base, label, shots] = process.argv.slice(2);
const exe = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const pages = { home: '/', category: '/collections/spd-dept-0/', shop: '/shop/', brand: '/brands/anua/',
  product: '/product/relief-sun-rice-probiotics-spf50/', blog: '/blog/' };
const out = (o) => console.log(JSON.stringify({ label, ...o }));

const observe = () => {
  window.__ls = []; window.__slide = [];
  new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__ls.push({ v: +e.value.toFixed(4), t: Math.round(e.startTime),
    nodes: (e.sources || []).map((s) => (s.node && s.node.className ? String(s.node.className).split(' ')[0] : s.node ? s.node.nodeName : '?') + ' ' + s.previousRect.y + '->' + s.currentRect.y) }); })
    .observe({ type: 'layout-shift', buffered: true });
  document.addEventListener('DOMContentLoaded', () => { window.__dcl = Math.round(performance.now());
    const tr = document.querySelector('.kbbs-tr'); if (!tr) return;
    new MutationObserver(() => { const i = tr.style.getPropertyValue('--kbbs-i'); const last = window.__slide[window.__slide.length - 1];
      if (!last || last.i !== i) window.__slide.push({ i, t: Math.round(performance.now()) }); }).observe(tr, { attributes: true, attributeFilter: ['style'] });
    window.__slide.push({ i: tr.style.getPropertyValue('--kbbs-i'), t: Math.round(performance.now()), at: 'dcl' }); });
  addEventListener('load', () => { window.__load = Math.round(performance.now()); });
};

(async () => {
  const b = await chromium.launch({ executablePath: exe, args: ['--no-sandbox'] });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1,
      isMobile: w === 390, hasTouch: w === 390 });
    await ctx.addInitScript(observe);
    for (const [name, path] of Object.entries(pages)) {
      const p = await ctx.newPage();
      const errors = [];
      p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
      p.on('pageerror', (e) => errors.push('pageerror ' + String(e).slice(0, 160)));
      p.on('requestfailed', (r) => { if (!/facebook|google|tiktok/.test(r.url())) errors.push('failed ' + r.url().slice(0, 120)); });
      p.on('response', (r) => { if (r.status() >= 400 && !/facebook|google/.test(r.url())) errors.push(r.status() + ' ' + r.url().slice(0, 120)); });
      await p.goto(base + path, { waitUntil: 'load' });
      await p.waitForTimeout(name === 'home' ? 5500 : 1200);
      const s = await p.evaluate(() => ({ ls: window.__ls, slide: window.__slide, dcl: window.__dcl, load: window.__load,
        sw: document.documentElement.scrollWidth, html: document.documentElement.outerHTML.length }));
      out({ w, page: name, cls: +s.ls.reduce((a, e) => a + e.v, 0).toFixed(4), shifts: s.ls, consoleErrors: errors, scrollWidth: s.sw });
      if (name === 'home') out({ w, check: 'slider', dcl: s.dcl, load: s.load, changes: s.slide });
      if (shots && (name === 'home' || name === 'brand')) {
        fs.mkdirSync(shots, { recursive: true });
        await p.screenshot({ path: `${shots}/${label}-${name}-${w}.png` });
      }
      if (name === 'home') {
        // Clickable on the first try: the topmost element at each link's centre is the link (or inside it).
        const hits = await p.evaluate(() => ['.kbbs-s a.kbbs-a', 'main a[href*="/product/"]', 'header a[href]', 'a[href*="/collections/"]']
          .map((sel) => { const a = [...document.querySelectorAll(sel)].find((x) => { const r = x.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.top >= 0 && r.left >= 0 && r.right <= innerWidth && r.top + r.height / 2 < innerHeight; });
            if (!a) return { sel, found: false }; const r = a.getBoundingClientRect(); const top = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + r.height / 2, innerHeight - 1));
            return { sel, href: a.getAttribute('href'), ok: !!top && (top === a || a.contains(top)), top: top ? String(top.className || top.nodeName).slice(0, 40) : null }; }));
        out({ w, check: 'click', hits });
      }
      await p.close();
    }
    // Prefetch: hover a product, a category and a brand link on the homepage / brand page, then click.
    for (const [from, sel] of [['/', 'a[href*="/product/"]'], ['/', 'a[href*="/collections/"]'], ['/brands/', 'a[href^="/brands/"][href$="/"]:not([href="/brands/"])']]) {
      const p = await ctx.newPage();
      const pf = [];
      p.on('request', (r) => { const h = r.headers(); if (h['sec-purpose'] && /prefetch/.test(h['sec-purpose'])) pf.push(r.url().replace(base, '')); });
      await p.goto(base + from, { waitUntil: 'load' });
      const a = p.locator(sel).filter({ visible: true }).first();
      const href = await a.getAttribute('href').catch(() => null);
      if (!href) { out({ w, check: 'prefetch', from, sel, found: false }); await p.close(); continue; }
      await a.scrollIntoViewIfNeeded();
      if (w === 390) await a.dispatchEvent('touchstart'); else await a.hover();
      await p.waitForTimeout(900);
      const t0 = Date.now();
      await Promise.all([p.waitForURL((u) => u.pathname === new URL(href, base).pathname, { timeout: 15000 }).catch(() => null), a.click()]);
      const navMs = Date.now() - t0;
      const fromCache = await p.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return n ? n.deliveryType || (n.transferSize === 0 ? 'cache' : 'network') : '?'; });
      out({ w, check: 'prefetch', from, href, prefetched: pf.includes(href) || pf.some((u) => u.startsWith(href)), deliveryType: fromCache, navMs, landed: new URL(p.url()).pathname });
      await p.close();
    }
    await ctx.close();
  }
  await b.close();
})();
