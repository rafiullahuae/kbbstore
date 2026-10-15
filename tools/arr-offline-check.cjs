/*
 * Lane AR round 2 -- open every docs/ar-preview file from disk with the network
 * cut, at 390 and 1280, and record: requests that tried to leave (should be
 * none that matter), console errors, horizontal overflow, the Arabic logo
 * lockup's fit (the owner asked whether the Arabic name fits the logo box
 * without changing its size), the cart panel open on load, and whether a
 * carousel arrow and a product tab still answer a click offline.
 *
 *   node tools/arr-offline-check.cjs [dir=docs/ar-preview]  -> storage/ar-logs/offline-check.json
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const DIR = path.resolve(process.argv[2] || 'docs/ar-preview');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const files = fs.readdirSync(DIR).filter((f) => /^(ar|en)-.*\.html$/.test(f)).sort();
  const out = [];
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    for (const f of files) {
      const page = await ctx.newPage();
      const leaving = [];
      const errors = [];
      await page.route('**/*', (r) => {
        const u = r.request().url();
        if (u.startsWith('file:') || u.startsWith('data:') || u.startsWith('blob:')) return r.continue();
        leaving.push(u.slice(0, 80));
        return r.abort();
      });
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 120)); });
      page.on('pageerror', (e) => errors.push('pageerror: ' + String(e.message).slice(0, 120)));
      await page.goto('file://' + path.join(DIR, f), { waitUntil: 'load' });
      await page.waitForTimeout(400);
      const m = await page.evaluate(() => {
        const r = {};
        r.sw = document.documentElement.scrollWidth;
        const lock = document.querySelector('.logo.lgx');
        const word = document.querySelector('.logo.lgx .lgx-w');
        const icon = document.querySelector('.logo.lgx .lgx-i');
        if (lock && word) {
          const lb = lock.getBoundingClientRect(), wb = word.getBoundingClientRect();
          const ib = icon ? icon.getBoundingClientRect() : null;
          const lh = parseFloat(getComputedStyle(word).lineHeight) || wb.height;
          r.logo = {
            text: word.textContent.trim(), box: [Math.round(lb.width), Math.round(lb.height)],
            word: [Math.round(wb.width), Math.round(wb.height)], lines: Math.round(wb.height / lh),
            icon: ib ? [Math.round(ib.width), Math.round(ib.height)] : null,
            overflows: word.scrollWidth > word.clientWidth + 1 || wb.right > lb.right + 1 || wb.left < lb.left - 1,
          };
        }
        const dr = document.querySelector('.drawer');
        r.drawerOpen = dr ? dr.classList.contains('on') : null;
        r.drawerLines = dr ? dr.querySelectorAll('.drawer .kc-item').length : null;
        return r;
      });
      // A carousel arrow and a tab, where the page has them.
      const act = {};
      for (const [k, sel] of [['arrow', '.hs-cc-arr:not([disabled]), .ymal-arr:not([disabled]), .cpg-arr:not([disabled]), .gs-arr:not([disabled])'], ['tab', '.ptabs [role=tab]:not([aria-selected=true]), .acc-h']]) {
        const el = await page.$(sel);
        if (!el || !(await el.isVisible())) continue;
        try {
          const before = await page.evaluate(() => [...document.querySelectorAll('.hs-cc-list, .hs-cc-track, .ymal-track, .cpg-track, .gs-track, [data-rail]')].map((t) => t.scrollLeft).join(','));
          await el.evaluate((n) => n.click());
          await page.waitForTimeout(500);
          const after = await page.evaluate(() => [...document.querySelectorAll('.hs-cc-list, .hs-cc-track, .ymal-track, .cpg-track, .gs-track, [data-rail]')].map((t) => t.scrollLeft).join(','));
          act[k] = k === 'arrow' ? (before !== after ? 'scrolled ' + after : 'no scroll') : 'clicked';
        } catch (e) { act[k] = 'error ' + String(e.message).slice(0, 60); }
      }
      out.push({ file: f, w, leaving: leaving.length, leavingSample: leaving.slice(0, 3), errors: errors.slice(0, 3), ...m, act });
      console.log(f.padEnd(26), w, 'sw=' + m.sw, 'leave=' + leaving.length, 'err=' + errors.length, m.logo ? `logo ${m.logo.box} word ${m.logo.word} lines=${m.logo.lines} icon=${m.logo.icon} over=${m.logo.overflows}` : '', m.drawerOpen ? 'DRAWER OPEN ' + m.drawerLines : '', JSON.stringify(act));
      await page.close();
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync('storage/ar-logs/offline-check.json', JSON.stringify(out, null, 1));
})();
