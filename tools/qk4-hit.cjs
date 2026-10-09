/*
 * Lane QK4: first-try clickability of every visible link and button in the
 * last section above the footer and in the footer, at 390 and 1280. Each is
 * scrolled to the viewport's middle, then elementFromPoint at its centre must
 * be it or a descendant. A failure names what covers it. Measurement only.
 *   QK4_BASE=http://127.0.0.1:10840 [QK4_SHOTS=dir] node tools/qk4-hit.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.QK4_BASE, SHOTS = process.env.QK4_SHOTS;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const PAGES = (process.env.QK4_PAGES || '/,/product/1025-dokdo-toner/,/product-category/cleansing-oils/,/brands/anua/,/blog/,/about/').split(',');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const page = await (await b.newContext({ viewport: { width: w, height: 900 }, userAgent: UA })).newPage();
    for (const u of PAGES) {
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      await page.waitForTimeout(800);
      const n = await page.evaluate(() => {
        const f = document.querySelector('body > footer');
        // the last section: main's last visible child chain down to the first element with 2+ visible children
        let sec = document.querySelector('main#content');
        const vis = (e) => { const s = getComputedStyle(e); const r = e.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && r.height > 0 && r.width > 0; };
        for (;;) { const k = [...sec.children].filter(vis); if (k.length === 1) sec = k[0]; else { sec = k.length ? k[k.length - 1] : sec; break; } }
        const els = [...sec.querySelectorAll('a[href],button'), ...f.querySelectorAll('a[href],button')].filter(e => {
          if (!vis(e)) return false; for (let a = e; a; a = a.parentElement) { const s = getComputedStyle(a); if (s.opacity === '0' || s.visibility === 'hidden') return false; } return true; });
        els.forEach((e, i) => e.setAttribute('data-qk4', i));
        window.__qk4sec = (sec.tagName + '.' + [...sec.classList].join('.')).slice(0, 50);
        return els.length;
      });
      const fails = []; let artefacts = 0;
      for (let i = 0; i < n; i++) {
        const r = await page.evaluate((i) => {
          const el = document.querySelector(`[data-qk4="${i}"]`);
          const r0 = el.getBoundingClientRect();
          window.scrollTo({ top: r0.top + scrollY + r0.height / 2 - innerHeight / 2, behavior: 'instant' });
          const rc = el.getBoundingClientRect(); const x = rc.left + rc.width / 2, y = rc.top + rc.height / 2;
          const d = (e) => e ? (e.tagName.toLowerCase() + (e.classList.length ? '.' + [...e.classList].join('.') : '')).slice(0, 60) : null;
          const label = d(el) + ' "' + (el.textContent || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 30) + '"';
          // outside the viewport (e.g. a card scrolled off inside a carousel): a probe artefact, not a cover
          let clipped = x < 0 || x > innerWidth || y < 0 || y > innerHeight;
          for (let a = el.parentElement; a && !clipped; a = a.parentElement) { const s = getComputedStyle(a); if (s.overflowX !== 'visible' || s.overflowY !== 'visible') { const ar = a.getBoundingClientRect(); if (x < ar.left || x > ar.right || y < ar.top || y > ar.bottom) clipped = true; } }
          const hit = document.elementFromPoint(x, y);
          if (hit === el || el.contains(hit)) return { ok: true };
          if (clipped) return { ok: false, artefact: true, label, why: 'centre outside the viewport or its scroller' };
          // the covering layer: the nearest positioned ancestor of the hit, with its z-index
          let layer = hit; while (layer && getComputedStyle(layer).position === 'static') layer = layer.parentElement;
          const ls = layer ? getComputedStyle(layer) : null; const lr = layer ? layer.getBoundingClientRect() : null;
          return { ok: false, label, at: [Math.round(x), Math.round(y)], hit: d(hit), layer: layer ? `${d(layer)} position:${ls.position} z-index:${ls.zIndex} box:${Math.round(lr.left)},${Math.round(lr.top)} ${Math.round(lr.width)}x${Math.round(lr.height)}` : null, i };
        }, i);
        if (r.artefact) artefacts++; else if (!r.ok) {
          fails.push(r);
          if (SHOTS && fails.length <= 2) await page.screenshot({ path: path.join(SHOTS, `hit-${u.replace(/\W+/g, '_') || 'home'}-${w}-${fails.length}.png`) });
        }
      }
      const sec = await page.evaluate(() => window.__qk4sec);
      console.log(`${w} ${u} section=${sec} checked=${n} ok=${n - fails.length - artefacts} artefacts=${artefacts} covered=${fails.length}`);
      for (const f of fails) console.log('   COVERED', f.label, 'at', f.at.join(','), 'hit', f.hit, '| layer', f.layer);
    }
  }
  await b.close();
})();
