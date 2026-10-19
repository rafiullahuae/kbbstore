/*
 * Lane BIM: links in a blog post's body, measured and photographed.
 *   node tools/bim-shots.cjs <port> <outdir> <label: before|after>
 * For English and Arabic at 390 and 1280: the computed underline of every kind
 * of link on the page (body text links vs header, menu, breadcrumb, product
 * card, picture link, gallery link, related card, back link, footer),
 * scrollWidth, console errors, whether a body link answers a real click on
 * the first try, CLS, and a picture of the article's top.
 */
const { chromium } = require('playwright');
(async () => {
  const [port, out, label] = process.argv.slice(2);
  const B = `http://127.0.0.1:${port}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const ctx = await b.newContext(w === 390 ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : { viewport: { width: 1280, height: 900 } });
      const p = await ctx.newPage();
      const errs = [];
      p.on('pageerror', (e) => errs.push(String(e)));
      p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
      const url = `${B}${lang === 'ar' ? '/ar' : ''}/blog/the-double-cleanse-explained/`;
      await p.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
      await p.goto(url, { waitUntil: 'networkidle' });
      await p.waitForTimeout(300);
      const r = await p.evaluate(() => {
        const cs = (el) => { if (!el) return null; const s = getComputedStyle(el); return { line: s.textDecorationLine, color: s.color, thick: s.textDecorationThickness, offset: s.textUnderlineOffset, text: (el.textContent || '').trim().slice(0, 30) }; };
        const q = (sel) => document.querySelector(sel);
        return {
          bodyText: [...document.querySelectorAll('.abody p > a, .abody li > a')].filter((a) => !a.querySelector('img')).map(cs),
          picLink: cs(q('.abody p > a:has(img)')),
          galLink: cs(q('.abody .kbb-gal a')),
          cardLink: cs(q('.abody a.kbb-card, .abody .kbb-card a')),
          cardName: cs(q('.abody a.cn')),
          header: cs(q('header a')),
          menu: cs(q('nav a, .menu a, header nav a')),
          crumb: cs(q('.kbb-journal .crumb a')),
          related: cs(q('.kbb-journal .mcard')),
          back: cs(q('.kbb-journal .backrow a')),
          footer: cs(q('footer a')),
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          cls: window.__cls,
          htmlBytes: document.documentElement.outerHTML.length,
          journalCssBytes: (q('#kbb-journal-css') || { textContent: '' }).textContent.length,
        };
      });
      // A body link answers a real click on the first try.
      console.error(lang, w, p.url(), await p.locator('.abody p > a').count(), await p.locator('.abody').count());
      const a = p.locator('.abody p > a').first();
      await a.scrollIntoViewIfNeeded();
      const box = await a.boundingBox();
      r.hit = await p.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return !!(e && e.closest('.abody a')); }, [box.x + box.width / 2, box.y + box.height / 2]);
      // Hover state
      await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await p.waitForTimeout(150);
      r.hover = await p.evaluate(() => { const s = getComputedStyle(document.querySelector('.abody p > a')); return { thick: s.textDecorationThickness, color: s.color }; });
      await p.mouse.move(0, 0);
      await p.evaluate(() => { document.querySelector('.abody').scrollIntoView(); window.scrollBy(0, -12); });
      await p.waitForTimeout(150);
      await p.screenshot({ path: `${out}/post-${lang}-${w}-${label}.png` });
      const [nav] = await Promise.all([p.waitForNavigation({ timeout: 5000 }).then(() => p.url()).catch(() => null), a.click()]);
      r.clickNavigatedTo = nav;
      r.errors = errs;
      report[`${lang}-${w}`] = r;
      await ctx.close();
    }
  }
  await b.close();
  console.log(JSON.stringify(report, null, 1));
})();
