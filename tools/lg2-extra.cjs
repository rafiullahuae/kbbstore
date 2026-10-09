/*
 * Lane LG2: the lockup's other three homes -- the mobile drawer's head, the
 * checkout's own header and the order-received header share it -- measured
 * the way tools/lg2-header.cjs measures the site header (every glyph inside
 * the logo box and inside every clipping ancestor, no overlap with the next
 * thing in the row) and photographed.
 *
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-extra.cjs <base> <label>
 */
const { chromium } = require('playwright');
const BASE = process.argv[2];
const LABEL = process.argv[3] || 'x';
const OUT = __dirname + '/../docs/lane-lg2-shots/';
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

const measure = (sel) => {
  const logo = document.querySelector(sel);
  if (!logo) return { missing: sel };
  const L = logo.getBoundingClientRect();
  let worst = 0; let n = 0; let last = 0;
  const w = document.createTreeWalker(logo, NodeFilter.SHOW_TEXT);
  let t;
  while ((t = w.nextNode())) {
    for (let i = 0; i < t.data.length; i++) {
      if (!t.data[i].trim()) continue;
      const rg = document.createRange(); rg.setStart(t, i); rg.setEnd(t, i + 1);
      const b = rg.getBoundingClientRect(); if (!b.width) continue; n++; last = Math.max(last, b.right);
      for (let a = t.parentElement; a; a = a.parentElement) {
        if (getComputedStyle(a).overflowX !== 'visible' || a === logo) {
          const ab = a.getBoundingClientRect(); worst = Math.max(worst, b.right - ab.right, ab.left - b.left);
        }
      }
    }
  }
  const next = logo.nextElementSibling ? logo.nextElementSibling.getBoundingClientRect().left : innerWidth;
  return { box: [Math.round(L.left), Math.round(L.right), Math.round(L.width)], glyphs: n, last: +last.toFixed(1), worst: +worst.toFixed(2), next: +next.toFixed(1), name: getComputedStyle(logo.querySelector('.lgx-w') || logo).fontSize, sw: document.documentElement.scrollWidth };
};

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [320, 390, 1280]) {
    const phone = w < 600;
    const ctx = await browser.newContext(phone ? { viewport: { width: w, height: 760 }, isMobile: true, hasTouch: true, userAgent: UA, deviceScaleFactor: 2 } : { viewport: { width: w, height: 800 }, deviceScaleFactor: 2, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    const page = await ctx.newPage();
    if (phone) {
      await page.goto(BASE + '/', { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);
      await page.evaluate(() => document.getElementById('mnav').classList.add('on'));
      await page.waitForTimeout(500);
      console.log(w, 'drawer', JSON.stringify(await page.evaluate(measure, '.mnav-h .logo')));
      await page.locator('.mnav-h').screenshot({ path: `${OUT}${LABEL}-drawer-${w}.png`, animations: 'disabled' });
    }
    await page.goto(BASE + '/product/relief-sun-rice-probiotics-spf50/', { waitUntil: 'load' });
    await page.evaluate(async (id) => {
      const tok = document.querySelector('meta[name="csrf-token"]');
      const body = new URLSearchParams(); body.set('product_id', id); body.set('quantity', '1');
      return (await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': (window.KBB && window.KBB.csrf) || (tok ? tok.content : ''), 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })).text();
    }, process.env.LG2_PID || '1').then((t) => process.env.LG2_DEBUG && console.log('add:', (t.match(/<title>[^<]*/) || [t.slice(0,200)])[0]));
    await page.goto(BASE + '/checkout/', { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    console.log(w, 'checkout', page.url().replace(BASE, ''), JSON.stringify(await page.evaluate(measure, '.co-head .logo')));
    await page.locator('.co-head').first().screenshot({ path: `${OUT}${LABEL}-checkout-${w}.png`, animations: 'disabled' });
    await ctx.close();
  }
  await browser.close();
})();
