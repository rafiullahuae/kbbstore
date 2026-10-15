/*
 * Lane ARC: /ar pages at 390 and 1280 in Chromium. Screenshots, the font that
 * actually paints every text node (CDP CSS.getPlatformFontsForNode), console
 * errors, failed requests, scrollWidth, and Latin words still visible.
 *
 *   NODE_PATH=/home/user/kbbstore/node_modules node shots.cjs <port> <outDir>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2];
const OUT = process.argv[3];
const BASE = `http://127.0.0.1:${PORT}`;
fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  ['home', '/ar/'],
  ['product', '/ar/product/skin-1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml/'],
  ['category', '/ar/collections/toners/'],
  ['brand', '/ar/brands/anua/'],
  ['search', '/ar/shop/?s=' + encodeURIComponent('أنوا')],
  ['cart-panel', '/ar/', 'drawer'],
  ['cart', '/ar/cart/'],
  ['checkout', '/ar/checkout/'],
  ['page', '/ar/about/'],
  ['blog', '/ar/blog/'],
  ['post', '/ar/blog/pdrn-skincare-what-is-it-does-it-actually-work/'],
];
const BASKET = ['anua-heartleaf-77-soothing-toner-250ml', 'skin-1004-madagascar-centella-hyalu-cica-water-fit-sun-serum-50ml',
  'medicube-pdrn-pink-peptide-serum', 'beauty-of-joseon-relief-sun-rice-probiotics-spf50-pa-50ml'];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1, locale: 'ar-AE',
      userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
    const page = await ctx.newPage();
    // A basket through the shop's own endpoint, resolved by slug.
    await page.goto(BASE + '/ar/collections/toners/', { waitUntil: 'load' });
    await page.waitForTimeout(1500);
    const ids = await page.evaluate(async (slugs) => {
      const out = [];
      for (const s of slugs) {
        const r = await fetch('/api/products/' + s); if (r.ok) { const j = await r.json(); out.push(j.id || null); }
      }
      return out;
    }, BASKET);
    await page.evaluate(async (slugs) => {
      const K = window.KBB || {};
      const h = { 'X-CSRF-TOKEN': K.csrf || '', 'Content-Type': 'application/json', Accept: 'application/json' };
      h['X-KBB-Hm'] = String(Math.round(performance.now()));
      const cards = [...new Set([...document.querySelectorAll('[data-kbb-add]')].map((c) => c.getAttribute('data-kbb-add')))].slice(0, 3);
      for (const id of cards) {
        await fetch(((K.routes && K.routes.cartApi) || '/ar/api/cart') + '/add', { method: 'POST', headers: h, body: JSON.stringify({ product_id: Number(id), quantity: 1 }) });
      }
    }, BASKET);
    const client = await ctx.newCDPSession(page);
    for (const [key, p, action] of PAGES) {
      const errors = []; const failed = [];
      const onC = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); };
      const onF = (r) => { if (r.url().startsWith(BASE)) failed.push(r.url().replace(BASE, '')); };
      page.on('console', onC); page.on('requestfailed', onF);
      const resp = await page.goto(BASE + p, { waitUntil: 'load' }).catch(() => null);
      await page.evaluate(() => document.fonts && document.fonts.ready);
      await page.waitForTimeout(400);
      if (action === 'drawer') {
        const opener = await page.$('[data-kbb-cart-open], .hact a[href*="cart"], a[aria-label*="حقيبت"], button[aria-label*="حقيبت"]');
        if (opener) { await opener.click().catch(() => {}); await page.waitForTimeout(900); }
      }
      // The font each text node is painted in.
      await client.send('DOM.enable'); await client.send('CSS.enable');
      const doc = await client.send('DOM.getDocument', { depth: -1 });
      const nodes = await page.evaluate(() => {
        const out = []; let n = 0;
        const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        while (walk.nextNode()) {
          const t = walk.currentNode; const el = t.parentElement;
          if (!el || !t.textContent.trim()) continue;
          const cs = getComputedStyle(el);
          if (cs.display === 'none' || cs.visibility === 'hidden') continue;
          if (['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
          el.setAttribute('data-arc-f', String(n++));
          out.push({ i: n - 1, text: t.textContent.trim().slice(0, 40), family: cs.fontFamily });
        }
        return out;
      });
      const fallback = {}; const used = {};
      for (const nd of nodes.slice(0, 1500)) {
        const q = await client.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: `[data-arc-f="${nd.i}"]` }).catch(() => null);
        if (!q || !q.nodeId) continue;
        const f = await client.send('CSS.getPlatformFontsForNode', { nodeId: q.nodeId }).catch(() => null);
        for (const pf of (f && f.fonts) || []) {
          used[pf.familyName] = (used[pf.familyName] || 0) + pf.glyphCount;
          if (!/^(Cairo|Outfit)$/.test(pf.familyName) && !/^\s*$/.test(nd.text)) {
            (fallback[pf.familyName] = fallback[pf.familyName] || []).push(nd.text);
          }
        }
      }
      const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, lang: document.documentElement.lang, dir: document.documentElement.dir }));
      const file = path.join(OUT, `ar-${key}-${w}.png`);
      await page.screenshot({ path: file, fullPage: !['home', 'cart-panel'].includes(key) && w === 390 ? false : false });
      if (['home', 'product', 'category', 'blog', 'post'].includes(key)) {
        await page.screenshot({ path: path.join(OUT, `ar-${key}-${w}-full.png`), fullPage: true });
      }
      report[`${key}@${w}`] = { status: resp ? resp.status() : 0, ...m, fontsUsed: used,
        fallback: Object.fromEntries(Object.entries(fallback).map(([k, v]) => [k, [...new Set(v)].slice(0, 12)])),
        errors, failed };
      page.off('console', onC); page.off('requestfailed', onF);
      console.log(key, w, resp && resp.status(), 'sw', m.sw, 'fonts', JSON.stringify(used), 'fallback', Object.keys(fallback).join(','), 'err', errors.length, 'failed', failed.length);
    }
    await ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  await browser.close();
})();
