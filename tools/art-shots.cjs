/*
 * Lane ART: the Arabic article's toolbar on Content → Blog Posts → Edit →
 * The article → "العربية Arabic · The article", against tools/bim-preview.sh.
 *   node tools/art-shots.cjs <port> <outdir> <before|after>
 * The Media Library and the link prompt are stubbed; the rest is the real
 * console. Prints the measured numbers as JSON.
 */
const { chromium } = require('playwright');
(async () => {
  const [port, out, label] = process.argv.slice(2);
  const B = `http://127.0.0.1:${port}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = {};
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 2600 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    const errs = []; p.on('pageerror', (e) => errs.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await p.goto(B + '/admin/login', { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
    await p.goto(B + '/admin', { waitUntil: 'networkidle' });
    await p.evaluate(() => window.go('posts'));
    await p.waitForSelector('[data-pj-edit]'); await p.click('[data-pj-edit] >> nth=0');
    await p.waitForSelector('#pj-body'); await p.waitForTimeout(300);
    const box = p.locator('[data-kbbar-box="body"]');
    await box.scrollIntoViewIfNeeded();
    const r = { width: w, errors: errs };
    if (label === 'before') {
      await box.screenshot({ path: `${out}/before-${w}-arabic-box.png` });
    } else {
      await p.evaluate(() => {
        window.prompt = () => 'https://extrabeauty.ae/ar/shop/';
        window.kbbPickMedia = (o) => Promise.resolve().then(() => o.onPick(['/uploads/bim1.svg']));
      });
      if (w === 1280) await box.screenshot({ path: `${out}/after-${w}-arabic-toolbar.png` });
      r.enGalleriesBefore = await p.$$eval('#pj-body .kbb-gal', (g) => g.length);
      r.enHtmlBefore = await p.$eval('#pj-body', (a) => a.innerHTML.length);
      const AR = '[data-kbbar-box="body"] [data-kbbar-rich]';
      const sel = (text, collapse) => p.evaluate(([s, t, c]) => {
        const a = document.querySelector(s); const wk = document.createTreeWalker(a, NodeFilter.SHOW_TEXT); let n;
        while ((n = wk.nextNode())) { const i = n.data.indexOf(t); if (i < 0) continue;
          const rg = document.createRange(); rg.setStart(n, c ? i + t.length : i); rg.setEnd(n, i + t.length);
          a.focus(); const s2 = getSelection(); s2.removeAllRanges(); s2.addRange(rg); return true; }
        return false;
      }, [AR, text, collapse]);
      const bar = '[data-kbbar-box="body"] .kbbar-bar';
      await sel('التنظيف المزدوج'); await p.click(bar + ' [data-cmd="bold"]');
      await sel('الخطوة الأولى'); await p.click(bar + ' [data-cmd="createLink"]');
      await sel('لماذا يهم الترتيب', true); await p.click(bar + ' [data-cmd="formatBlock:h2"]');
      await sel('للتفاصيل.', true); await p.click(bar + ' [data-rte-media]');
      await p.waitForSelector(AR + ' .kbb-gal img[src="/uploads/bim1.svg"]');
      await p.evaluate((s) => { const a = document.querySelector(s); a.scrollTop = 0; }, AR);
      await p.mouse.click(1, 1);
      await box.scrollIntoViewIfNeeded();
      await box.screenshot({ path: `${out}/after-${w}-bold-link-h2-picture.png` });
      if (w === 1280) await p.locator('.pj-card', { has: p.locator('#pj-body') }).screenshot({ path: `${out}/after-${w}-english-and-arabic.png` });
      Object.assign(r, await p.evaluate((s) => {
        const a = document.querySelector(s), bar = document.querySelector('[data-kbbar-box="body"] .kbbar-bar');
        const bs = [...bar.querySelectorAll('button')];
        return {
          labels: bs.map((x) => x.textContent.trim()),
          enLabels: [...document.querySelectorAll('.pj-rte > .pj-rte-bar button')].map((x) => x.textContent.trim()),
          barDir: getComputedStyle(bar).direction,
          barHeight: Math.round(bar.getBoundingClientRect().height),
          buttonFont: getComputedStyle(bs[0]).fontSize,
          bold: !!a.querySelector('b'), link: a.querySelector('a[href="https://extrabeauty.ae/ar/shop/"]') ? true : false,
          h2: !!a.querySelector('h2'), gallery: a.querySelectorAll('.kbb-gal').length,
          enGalleriesAfter: document.querySelectorAll('#pj-body .kbb-gal').length,
          enHtmlAfter: document.querySelector('#pj-body').innerHTML.length,
          words: (document.querySelector('[data-pj-ar-words]') || {}).textContent,
          scrollWidth: document.documentElement.scrollWidth,
        };
      }, AR));
    }
    res[w] = r;
    await ctx.close();
  }
  console.log(JSON.stringify(res));
  await b.close();
})();
