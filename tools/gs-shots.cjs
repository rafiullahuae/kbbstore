// Lane GS: the free gifts strip on the product page -- screenshots and the
// numbers CLAUDE.md asks for (strip box, font sizes, gaps, scrollWidth, the
// links around it answering a click, console errors, requests).
//
//   node tools/gs-shots.cjs <base url> <out dir> <tag> [paths...]
//
// Measures with getBoundingClientRect in THIS harness only; the shop itself
// measures nothing.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const [base, out, tag, ...rest] = process.argv.slice(2);
const paths = rest.length ? rest : ['/product/pdp-heartleaf-toner/', '/ar/product/pdp-heartleaf-toner/'];
fs.mkdirSync(out, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const report = {};
  for (const width of [390, 1280]) {
    for (const p of paths) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: width === 390 ? 2 : 1 });
      const page = await ctx.newPage();
      const errors = [];
      const requests = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('request', (r) => requests.push(r.url().replace(base, '')));
      await page.goto(base + p, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      const m = await page.evaluate(() => {
        const s = document.querySelector('.pdp-gs');
        const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden'; };
        const res = { scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, dir: document.documentElement.dir || '' };
        if (!s || !vis(s)) { res.strip = null; return res; }
        const r = s.getBoundingClientRect();
        const b = s.querySelector('b'), l = s.querySelector('.pgs-l'), i = s.querySelector('.pgs-i');
        // neighbours in the visual column: nearest visible section above and below
        const secs = [...document.querySelectorAll('.pdp-page .pm-sec, .pdp-page .kbb-cart-form > *, .pdp-page .pdp > .gallery')].filter((e) => e !== s && vis(e) && !s.contains(e) && !e.contains(s));
        let above = null, below = null;
        for (const e of secs) {
          const q = e.getBoundingClientRect();
          if (Math.abs(q.left - r.left) > 40 && width_ok(q, r)) continue;
          if (q.bottom <= r.top + 1 && (!above || q.bottom > above.q.bottom)) above = { q, cls: e.className };
          if (q.top >= r.bottom - 1 && (!below || q.top < below.q.top)) below = { q, cls: e.className };
        }
        function width_ok(q, r) { return q.right < r.left || q.left > r.right; }
        Object.assign(res, {
          strip: { top: Math.round(r.top + scrollY), left: Math.round(r.left), width: Math.round(r.width), height: +r.height.toFixed(1), radius: getComputedStyle(s).borderRadius },
          fontFamily: getComputedStyle(s).fontFamily.split(',')[0],
          line1: b ? getComputedStyle(b).fontSize : null, line2: l ? getComputedStyle(l).fontSize : null,
          icon: i ? { w: Math.round(i.getBoundingClientRect().width), side: i.getBoundingClientRect().left < r.left + r.width / 2 ? 'left' : 'right' } : null,
          gapAbove: above ? +(r.top - above.q.bottom).toFixed(1) : null, aboveIs: above ? String(above.cls).slice(0, 60) : null,
          gapBelow: below ? +(below.q.top - r.bottom).toFixed(1) : null, belowIs: below ? String(below.cls).slice(0, 60) : null,
          animations: s.getAnimations({ subtree: true }).length,
        });
        // Every link / button within 200px of the strip answers at its centre.
        // The buy column's own links and buttons (the header menu and the
        // floating chat button are not the strip's neighbours).
        const near = [...document.querySelectorAll('.pdp a[href], .pdp button, .pdp label, .pdp input[type=checkbox]')].filter((a) => {
          if (!vis(a)) return false; const q = a.getBoundingClientRect();
          return q.bottom > r.top - 200 && q.top < r.bottom + 200 && q.width > 0;
        });
        let ok = 0; const bad = [];
        for (const a of near) {
          a.scrollIntoView({ block: 'center' });
          const q = a.getBoundingClientRect();
          const hit = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2);
          if (hit && (hit === a || a.contains(hit) || (a.htmlFor && hit.id === a.htmlFor))) ok++; else bad.push((a.textContent || a.getAttribute('aria-label') || a.tagName).trim().slice(0, 30) + ' <- ' + (hit ? (hit.closest('.pdp-gs') ? 'THE STRIP ' : '') + (hit.className || hit.tagName) : 'null'));
        }
        res.clickable = { checked: near.length, ok, bad };
        return res;
      });
      // A real click on the brand link (above the strip) must navigate.
      const brand = await page.$('.pdp .bb-brand a');
      if (brand && await brand.isVisible()) {
        await brand.scrollIntoViewIfNeeded();
        const before = page.url();
        await Promise.all([page.waitForURL((u) => String(u) !== before, { timeout: 8000 }).catch(() => {}), brand.click()]);
        m.brandClickNavigated = page.url() !== before;
        await page.goto(base + p, { waitUntil: 'networkidle' });
      }
      m.consoleErrors = errors.filter((e) => !/status of 404/.test(e) || true);
      m.requests = requests.length;
      m.requestList = requests;
      const key = `${width}${p.startsWith('/ar') ? '-ar' : ''}${p.includes('?') ? '' : ''}`;
      const name = `${tag}-${key}`;
      // the strip in context: from 220px above it to 260px below it, full width
      await page.evaluate(() => window.scrollTo(0, 0));
      if (m.strip) {
        const y = Math.max(0, m.strip.top - 220);
        await page.screenshot({ path: path.join(out, name + '.png'), clip: { x: 0, y, width, height: Math.min(560, m.strip.height + 480) }, fullPage: true });
      } else {
        // No strip (the "before"): the same window around Tabby & Tamara.
        const top = await page.evaluate(() => { const e = [...document.querySelectorAll('.pdp-paylater')].find((x) => x.getBoundingClientRect().height > 0); return e ? Math.round(e.getBoundingClientRect().top + scrollY) : 300; });
        await page.screenshot({ path: path.join(out, name + '.png'), clip: { x: 0, y: Math.max(0, top - 220), width, height: 540 }, fullPage: true });
      }
      report[name] = m;
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(out, tag + '-measure.json'), JSON.stringify(report, (k, v) => (k === 'requestList' ? undefined : v), 2));
  for (const [k, v] of Object.entries(report)) {
    console.log(k, JSON.stringify({ ...v, requestList: undefined }));
  }
  fs.writeFileSync(path.join(out, '..', '..', '..', '..', 'storage', 'gs-logs', tag + '-requests.json'), JSON.stringify(Object.fromEntries(Object.entries(report).map(([k, v]) => [k, v.requestList])), null, 1));
})();
