// Lane SSB: /super-sale/ at 390 and 1280, English and Arabic, with the numbers.
//   node tools/ssb-shots.cjs http://127.0.0.1:11460 after   (or "before")
// Writes storage/ssb-shots/<label>-<lang>-<width>.png (the top of the page) and
// <label>-numbers.json: the banner box, scrollWidth, console errors, and an
// elementFromPoint check over every link in the first screenful.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.argv[2];
const label = process.argv[3] || 'after';
const out = path.join(__dirname, '..', 'storage', 'ssb-shots');
fs.mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const numbers = {};
  for (const lang of ['en', 'ar']) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: width === 390 ? 2 : 1 });
      const page = await ctx.newPage();
      const errors = [];
      const requests = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('request', (r) => requests.push(r.url().replace(base, '')));
      // CLS and the LCP element, collected from the first paint (harness only).
      await page.addInitScript(() => {
        window.__cls = 0; window.__lcp = '';
        new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
        new PerformanceObserver((l) => { const e = l.getEntries().pop(); if (e) window.__lcp = (e.element ? e.element.tagName + '.' + e.element.className : '') + ' ' + Math.round(e.startTime) + 'ms'; }).observe({ type: 'largest-contentful-paint', buffered: true });
      });
      const url = base + (lang === 'ar' ? '/ar/super-sale/' : '/super-sale/');
      const res = await page.goto(url, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts && document.fonts.ready);
      const m = await page.evaluate(() => {
        const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) }; };
        const ssb = document.querySelector('.kbb-ssb');
        const pic = document.querySelector('.kbb-ph-i');
        const q = (s) => r(document.querySelector(s));
        // Every link a shopper can see in the first screenful (menus, the
        // header, crumbs, the first product cards) -- not a closed drawer's.
        const links = [...document.querySelectorAll('a[href]')].filter((a) => { const b = a.getBoundingClientRect(); const cx = b.left + b.width / 2, cy = b.top + b.height / 2; return b.width > 0 && b.height > 0 && cy > 0 && cy < innerHeight && cx > 0 && cx < innerWidth && a.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }); });
        const blocked = links.filter((a) => { const b = a.getBoundingClientRect(); const el = document.elementFromPoint(b.left + b.width / 2, Math.max(1, b.top + b.height / 2)); return !(el && (el === a || a.contains(el))); }).map((a) => a.textContent.trim().slice(0, 40) + ' ' + a.getAttribute('href'));
        const fs = (s) => { const el = document.querySelector(s); return el ? getComputedStyle(el).fontSize : null; };
        const title = document.querySelector('.ssb-super');
        return {
          banner: r(ssb), headerPicture: ssb ? null : r(pic),
          hero: q('.ssb-hero'), gifts: q('.ssb-gifts'), model: q('.ssb-model'), giftIcon: q('.ssb-gic'), thumb: q('.ssb-c'),
          thumbsShown: [...document.querySelectorAll('.ssb-c')].filter((e) => e.getBoundingClientRect().width > 0).length,
          titleFont: fs('.ssb-super'), titleOneLine: title ? title.getBoundingClientRect().height < parseFloat(getComputedStyle(title).fontSize) * 1.6 : null,
          titleOverflows: title ? title.scrollWidth > title.clientWidth + 1 : null,
          trustStrip: q('.ktr'), scrollWidth: document.documentElement.scrollWidth, innerWidth,
          linksInView: links.length, linksBlocked: blocked,
          scripts: document.scripts.length, stylesheets: document.querySelectorAll('link[rel=stylesheet]').length,
          htmlBytes: document.documentElement.outerHTML.length,
          cls: Math.round(window.__cls * 10000) / 10000, lcp: window.__lcp,
          // No clipping: the hero and the copy hold all of their content.
          overflow: ['.ssb-hero', '.ssb-copy', '.ssb-in'].map((s) => { const el = document.querySelector(s); return el ? `${s} ${el.scrollHeight}/${el.clientHeight}` : null; }),
          // The room above the tabby/tamara row and below the coupon line, inside the hero.
          room: (() => { const h = document.querySelector('.ssb-hero'), p = document.querySelector('.ssb-pay'), c = document.querySelector('.ssb-coupon'); if (!h || !p || !c) return null; const hb = h.getBoundingClientRect(); return { aboveBadges: Math.round(p.getBoundingClientRect().top - hb.top), belowCoupon: Math.round(hb.bottom - c.getBoundingClientRect().bottom) }; })(),
          payShown: (() => { const el = document.querySelector('.ssb-paytxt'); return el ? getComputedStyle(el).display !== 'none' : null; })(),
          modelImg: (() => { const el = document.querySelector('.ssb-model img'); return el ? { current: el.currentSrc.replace(location.origin, ''), box: r(el) } : null; })(),
          editorTrace: /ssb-ed|Edit banner|super-sale-banner-editor/.test(document.documentElement.outerHTML),
        };
      });
      m.status = res.status();
      m.consoleErrors = errors;
      m.imageRequests = requests.filter((u) => /\.(webp|png|jpe?g|svg)(\?|$)/.test(u));
      m.modelRequests = m.imageRequests.filter((u) => /appearance\//.test(u));
      numbers[`${lang}-${width}`] = m;
      await page.screenshot({ path: path.join(out, `${label}-${lang}-${width}.png`), clip: { x: 0, y: 0, width, height: width === 390 ? 760 : 820 } });
      await ctx.close();
    }
  }
  fs.writeFileSync(path.join(out, `${label}-numbers.json`), JSON.stringify(numbers, null, 1));
  console.log(JSON.stringify(numbers, (k, v) => (k === 'imageRequests' ? v.length : v), 1));
  await browser.close();
})();
