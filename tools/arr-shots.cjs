/*
 * Lane AR round 2 -- every customer-facing page, Arabic (RTL) beside English,
 * at 390 and 1280, from ONE preview (tools/arr-preview.sh): /ar/... and its
 * unprefixed English twin are the same tree, the same data, the same basket.
 *
 *   node tools/arr-shots.cjs <port> [outDir] [only-keys,comma]
 *
 * Writes <outDir>/<lang>-<key>-<w>.png (full page; converted to JPEG after),
 * <outDir>/measurements.json (scrollWidth, overflowing elements, console
 * errors, failed requests per page), and the SERVER-RENDERED HTML of every
 * page to storage/ar-logs/html/<lang>-<key>.html for tools/arr-standalone.cjs.
 *
 * One signed-in customer (tools/arr-seed.php) with a seven-line basket and a
 * wishlist, so cart, checkout, order received and the account pages all have
 * something in them -- the same basket in both languages.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '9463';
const OUT = process.argv[3] || 'docs/lane-ar-shots/round2';
const ONLY = (process.argv[4] || '').split(',').filter(Boolean);
const BASE = `http://127.0.0.1:${PORT}`;
const IDS = JSON.parse(fs.readFileSync('storage/ar-logs/preview-ids.json', 'utf8'));
const HTML_DIR = 'storage/ar-logs/html';
const ORDER = 10512;

/* page key, path, what to do before the shot, whether it needs the sign-in */
const PAGES = [
  ['home', '/'],
  ['shop', '/shop/'],
  ['category', '/product-category/toners/'],
  ['brands', '/brands/'],
  ['brand', '/brands/anua/'],
  ['product', `/product/${IDS.hero}/`],
  ['product-cream', `/product/${IDS.cream}/`],
  ['product-sun', `/product/${IDS.sun}/`],
  ['set', `/product/${IDS.set}/`],
  ['search', '/shop/?s='],
  ['sale', '/super-sale/'],
  ['cart', '/cart/'],
  ['cart-panel', '/', 'drawer'],
  ['checkout', '/checkout/'],
  ['checkout-signin', '/checkout/', 'signin', 'guest'],
  ['order-received', `/checkout/success/?order=${ORDER}`],
  ['login', '/my-account/', null, 'guest'],
  ['register', '/my-account/?tab=register', null, 'guest'],
  ['forgot', '/my-account/forgot/', null, 'guest'],
  ['account', '/my-account/'],
  ['orders', '/my-account/orders/'],
  ['order-detail', `/my-account/orders/${IDS.orders[0]}/`],
  ['addresses', '/my-account/edit-address/'],
  ['address-edit', `/my-account/edit-address/${IDS.address}/`],
  ['wishlist', '/my-wishlist/'],
  ['track', '/track-my-order/'],
  ['about', '/about/'],
  ['faqs', '/faqs/'],
  ['delivery', '/delivery/'],
  ['returns', '/refund_returns/'],
  ['privacy', '/privacy-policy/'],
  ['terms', '/terms-and-conditions/'],
  ['contact', '/contact-us/'],
  ['blog', '/blog/'],
  ['post', `/blog/${IDS.post}/`],
  ['not-found', '/zz-no-such-page/'],
  ['mobile-menu', '/', 'menu'],
  ['mega-menu', '/', 'mega'],
];

const VIEWPORTS = [{ w: 390, h: 844 }, { w: 1280, h: 900 }];
const FREEZE = `*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important;transition-duration:0s!important;transition-delay:0s!important;caret-color:transparent!important}`;

async function measure(page) {
  return page.evaluate(() => {
    const de = document.documentElement;
    const vw = de.clientWidth;
    const off = [];
    if (de.scrollWidth > vw) {
      for (const el of document.body.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        if (!r.width || !r.height) continue;
        if (r.right > vw + 1 || r.left < -1) {
          let clipped = false;
          for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            const cs = getComputedStyle(p);
            if (cs.overflowX !== 'visible' || cs.position === 'fixed') { clipped = true; break; }
          }
          if (!clipped && getComputedStyle(el).position !== 'fixed') {
            off.push((el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')) + ` [${Math.round(r.left)},${Math.round(r.right)}]`);
          }
        }
      }
    }
    const h1 = document.querySelectorAll('h1').length;
    return { dir: de.dir, lang: de.lang, scrollWidth: de.scrollWidth, clientWidth: vw, overflow: de.scrollWidth - vw, offenders: off.slice(0, 12), h1 };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  fs.mkdirSync(HTML_DIR, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];

  /* ONE sign-in for the whole run, carried into every member context as
     storage state: four sign-ins a minute trip the shop's own login throttle,
     and the English half of the first run photographed a signed-out shop. */
  const MEMBER = 'storage/ar-logs/member-state.json';
  {
    const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
    const page = await ctx.newPage();
    await page.goto(BASE + '/my-account/', { waitUntil: 'domcontentloaded' });
    await page.fill('form[action$="/my-account/login"] input[name=email]', 'mariam@preview.test');
    await page.fill('form[action$="/my-account/login"] input[name=password]', 'preview-pass-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('form[action$="/my-account/login"] [type=submit]')]);
    const html = await page.content();
    if (!/mariam@preview\.test|Mariam/.test(html)) throw new Error('sign-in failed: ' + page.url());
    await ctx.storageState({ path: MEMBER });
    await ctx.close();
  }

  for (const lang of ['ar', 'en']) {
    const pre = lang === 'ar' ? '/ar' : '';
    for (const vp of VIEWPORTS) {
      const contexts = {};
      const ctxFor = async (who) => {
        if (contexts[who]) return contexts[who];
        const ctx = await browser.newContext({ ...(who === 'member' ? { storageState: MEMBER } : {}), viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 1, reducedMotion: 'reduce', userAgent: vp.w < 900 ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', hasTouch: vp.w < 900, isMobile: vp.w < 900 });
        await ctx.route('**/*', (route) => {
          const u = route.request().url();
          return u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:') ? route.continue() : route.abort();
        });
        await ctx.addInitScript((css) => {
          document.addEventListener('DOMContentLoaded', () => { const s = document.createElement('style'); s.textContent = css; document.head.appendChild(s); });
        }, FREEZE);
        const page = await ctx.newPage();
        if (who === 'member') execFileSync('python3', ['tools/arr-empty-bags.py']);
        /* The basket: seven lines, three brands' worth of long and short names,
           a sale line, quantities above one. Through the shop's own endpoint. */
        // 'load' and a beat: on a member's first visit the page's own cart call
        // races the first add, and that line was lost (8 items on /ar, 10 in English).
        await page.goto(BASE + pre + '/shop/', { waitUntil: 'load' });
        await page.waitForTimeout(1500);
        await page.evaluate(async ({ pre, lines }) => {
          const K = window.KBB || {};
          const h = { 'X-CSRF-TOKEN': K.csrf || '', 'Content-Type': 'application/json', Accept: 'application/json', 'X-KBB-Hm': '9000' };
          for (const [id, q] of lines) {
            await fetch((K.routes && K.routes.cartApi || pre + '/api/cart') + '/add', { method: 'POST', headers: h, body: JSON.stringify({ product_id: id, quantity: q }) });
          }
          for (const id of [lines[1][0], lines[3][0], lines[5][0]]) {
            await fetch(pre + '/wishlist/toggle', { method: 'POST', headers: h, body: JSON.stringify({ product_id: id }) });
          }
        }, { pre, lines: [[IDS.ids[0], 2], [IDS.ids[21], 1], [IDS.ids[17], 1], [IDS.ids[12], 1], [IDS.ids[1], 1], [IDS.ids[20], 3], [IDS.ids[14], 1]] });
        contexts[who] = { ctx, page };
        return contexts[who];
      };

      for (const [key, p, action, who = 'member'] of PAGES) {
        if (ONLY.length && !ONLY.includes(key)) continue;
        const { page } = await ctxFor(who);
        const errors = [];
        const failed = [];
        const onConsole = (m) => { const at = (m.location() || {}).url || ''; if (m.type() === 'error' && !/\/api\/online/.test(at)) errors.push(m.text().slice(0, 160) + (at ? ' @ ' + at.replace(BASE, '') : '')); };
        const onPageErr = (e) => errors.push('pageerror: ' + String(e).slice(0, 200));
        const onFail = (r) => { const u = r.url(); if (u.startsWith(BASE) && !/\/api\/online/.test(u)) failed.push(u.replace(BASE, '') + ' ' + (r.failure()?.errorText || '')); };
        page.on('console', onConsole); page.on('pageerror', onPageErr); page.on('requestfailed', onFail);

        // Search asks in the page's own language: the Arabic shop is searched in Arabic.
        const pth = key === 'search' ? (lang === 'ar' ? '/shop/?s=' + encodeURIComponent('أنوا') : '/shop/?s=anua') : p;
        const resp = await page.goto(BASE + pre + pth, { waitUntil: 'load' }).catch(() => null);
        const status = resp ? resp.status() : 0;
        if (resp && vp.w === 390) {
          try { fs.writeFileSync(path.join(HTML_DIR, `${lang}-${key}.html`), await resp.text()); } catch (e) { /* redirect bodies */ }
        }
        await page.waitForTimeout(700);
        try {
          if (action === 'drawer') {
            await page.evaluate(() => { const b = document.querySelector('[data-kbb-open="cart"]'); if (b) b.click(); });
            await page.waitForTimeout(700);
          } else if (action === 'menu') {
            if (vp.w < 900) { await page.click('#burger').catch(() => {}); await page.waitForTimeout(400); }
          } else if (action === 'mega') {
            if (vp.w >= 900) { const a = await page.$('.navitem.mg-p > a'); if (a) { await a.hover(); await page.waitForTimeout(500); } }
          } else if (action === 'signin') {
            await page.evaluate(() => { const b = document.querySelector('[data-signin], [data-open="account"], .co-signin, a[href*="my-account"][data-panel]'); if (b) b.click(); });
            await page.waitForTimeout(600);
          }
        } catch (e) { errors.push('action: ' + String(e).slice(0, 120)); }
        const full = !['cart-panel', 'mobile-menu', 'mega-menu', 'checkout-signin'].includes(key);
        if (full) {
          /* Walk the page once so lazy pictures load before the full-page shot. */
          await page.evaluate(async () => {
            for (let y = 0; y < document.documentElement.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); }
            window.scrollTo(0, 0);
          });
          await page.waitForTimeout(900);
        }
        const m = status ? await measure(page) : {};
        const file = `${lang}-${key}-${vp.w}.png`;
        await page.screenshot({ path: path.join(OUT, file), fullPage: full }).catch((e) => errors.push('shot: ' + e));
        page.off('console', onConsole); page.off('pageerror', onPageErr); page.off('requestfailed', onFail);
        rows.push({ lang, key, width: vp.w, url: pre + pth, status, file: file.replace('.png', '.jpg'), ...m, consoleErrors: errors, failedRequests: failed });
        console.log(`${lang} ${key.padEnd(15)} ${vp.w} ${status} sw=${m.scrollWidth}/${m.clientWidth} h1=${m.h1} err=${errors.length} ${m.offenders && m.offenders.length ? 'OFF:' + m.offenders.slice(0, 3).join(' ') : ''}`);
      }
      for (const c of Object.values(contexts)) await c.ctx.close();
    }
  }
  await browser.close();
  const mf = path.join(OUT, ONLY.length ? 'measurements-partial.json' : 'measurements.json');
  fs.writeFileSync(mf, JSON.stringify(rows, null, 1));
  console.log('wrote', rows.length, 'rows to', mf);
})();
