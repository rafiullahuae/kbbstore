/*
 * Lane ARF -- walk every /ar page of tools/arf-preview.sh and count what is
 * still not Arabic: every ⟪…⟫ marker (translation_highlight_missing) and every
 * Latin word a shopper can read (visible text, placeholders, labels, button
 * values, titles), at 390 and 1280.
 *
 *   node tools/arf-crawl.cjs <port> <outJson> [shotsDir] [only-keys,comma]
 *
 * With shotsDir it also writes <key>-<w>.png (full page) and the footer /
 * header / trust-strip crops the report asks for.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '9471';
const OUT = process.argv[3] || 'storage/arf-logs/crawl.json';
const SHOTS = process.argv[4] || '';
const ONLY = (process.argv[5] || '').split(',').filter(Boolean);
const BASE = `http://127.0.0.1:${PORT}`;
const IDS = JSON.parse(fs.readFileSync('storage/arf-logs/preview-ids.json', 'utf8'));
const PRE = process.env.ARF_LANG === 'en' ? '' : '/ar';

const PAGES = [
  ['home', '/'],
  ['category', '/collections/toners/'],
  ['product', `/product/${IDS.product}/`],
  ['brand', '/brands/anua/'],
  ['brands', '/brands/'],
  ['shop', '/shop/'],
  ['search', '/shop/?s=' + encodeURIComponent(PRE ? 'أنوا' : 'anua')],
  ['cart-panel', '/', 'drawer'],
  ['cart', '/cart/'],
  ['checkout', '/checkout/'],
  ['checkout-guest', '/checkout/', null, 'guest'],
  ['order-received', `/checkout/success/?order=${IDS.order_number}`],
  ['login', '/my-account/', null, 'guest'],
  ['register', '/my-account/?tab=register', null, 'guest'],
  ['account', '/my-account/'],
  ['orders', '/my-account/orders/'],
  ['addresses', '/my-account/edit-address/'],
  ['wishlist', '/my-wishlist/'],
  ['track', '/track-my-order/'],
  ['about', '/about/'],
  ['faqs', '/faqs/'],
  ['contact', '/contact-us/'],
  ['privacy', '/privacy-policy/'],
  ['delivery', '/delivery/'],
  ['returns', '/refund_returns/'],
  ['terms', '/terms-and-conditions/'],
  ['blog', '/blog/'],
  ['post', `/blog/${IDS.post}/`],
  ['not-found', '/zz-no-such-page/'],
];
const VIEWPORTS = [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

/* Latin words a shopper may legitimately see on /ar: payment brands, the
   WhatsApp number's digits are not words, AED is printed as د.إ. */
const ALLOW = new Set(['visa', 'mastercard', 'apple', 'pay', 'google', 'tabby', 'tamara', 'gpay', 'amex', 'american', 'express']);

async function collect(page) {
  return page.evaluate(() => {
    const vis = (el) => { const cs = getComputedStyle(el); return cs.display !== 'none' && cs.visibility !== 'hidden'; };
    const texts = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let n; (n = walker.nextNode());) {
      const p = n.parentElement;
      if (!p || ['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE'].includes(p.tagName) || p.closest('template,script,style,svg')) continue;
      const t = n.nodeValue.trim();
      if (!t) continue;
      let shown = true;
      for (let e = p; e && e !== document.body; e = e.parentElement) { if (!vis(e)) { shown = false; break; } }
      texts.push({ t, shown, where: (p.closest('[class]') || p).className.toString().split(' ')[0] || p.tagName });
    }
    for (const el of document.querySelectorAll('[placeholder],[aria-label],[title],input[type=submit][value],button[value],img[alt],[data-label],option')) {
      if (el.closest('svg')) continue;
      for (const a of ['placeholder', 'aria-label', 'title', 'alt']) {
        const v = el.getAttribute(a);
        if (v && v.trim()) texts.push({ t: v.trim(), shown: true, where: '@' + a + ':' + el.tagName.toLowerCase() + '.' + (el.className.toString().split(' ')[0] || '') });
      }
      if (el.tagName === 'OPTION' && el.textContent.trim()) texts.push({ t: el.textContent.trim(), shown: true, where: 'option' });
    }
    const html = document.documentElement.outerHTML;
    const markers = (html.match(/⟪[^⟫]{0,200}⟫/g) || []);
    return { texts, markers, title: document.title };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const MEMBER = 'storage/arf-logs/member-state.json';
  {
    const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
    const page = await ctx.newPage();
    await page.goto(BASE + '/my-account/', { waitUntil: 'domcontentloaded' });
    await page.fill('form[action$="/my-account/login"] input[name=email]', 'mariam@preview.test');
    await page.fill('form[action$="/my-account/login"] input[name=password]', 'preview-pass-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('form[action$="/my-account/login"] [type=submit]')]);
    await ctx.storageState({ path: MEMBER });
    await ctx.close();
  }
  const rows = [];
  for (const vp of VIEWPORTS) {
    const ctxs = {};
    const ctxFor = async (who) => {
      if (ctxs[who]) return ctxs[who];
      const UA = vp.w < 900 ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
      const ctx = await browser.newContext({ userAgent: UA, ...(who === 'member' ? { storageState: MEMBER } : {}), viewport: { width: vp.w, height: vp.h }, reducedMotion: 'reduce', isMobile: vp.w < 900, hasTouch: vp.w < 900 });
      await ctx.route('**/*', (r) => { const u = r.request().url(); return u.startsWith(BASE) || u.startsWith('data:') || u.startsWith('blob:') ? r.continue() : r.abort(); });
      const page = await ctx.newPage();
      await page.goto(BASE + PRE + '/shop/', { waitUntil: 'load' });
      await page.waitForTimeout(1200);
      await page.evaluate(async ({ pre, lines }) => {
        const K = window.KBB || {};
        const h = { 'X-CSRF-TOKEN': K.csrf || '', 'Content-Type': 'application/json', Accept: 'application/json', 'X-KBB-Hm': '9000' };
        for (const [id, q] of lines) await fetch(((K.routes && K.routes.cartApi) || pre + '/api/cart') + '/add', { method: 'POST', headers: h, body: JSON.stringify({ product_id: id, quantity: q }) });
      }, { pre: PRE, lines: [[IDS.ids[0], 2], [IDS.ids[3], 1], [IDS.ids[7], 1]] });
      ctxs[who] = { ctx, page };
      return ctxs[who];
    };
    for (const [key, p, action, who = 'member'] of PAGES) {
      if (ONLY.length && !ONLY.includes(key)) continue;
      const { page } = await ctxFor(who);
      const errors = [];
      const onC = (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); };
      page.on('console', onC);
      const resp = await page.goto(BASE + PRE + p, { waitUntil: 'load' }).catch(() => null);
      await page.waitForTimeout(key.startsWith('checkout') || key === 'cart' ? 2500 : 500);
      if (action === 'drawer') {
        await page.evaluate(() => { const b = document.querySelector('[data-kbb-open="cart"]'); if (b) b.click(); });
        await page.waitForTimeout(700);
      }
      const c = await collect(page);
      const latin = [];
      for (const { t, shown, where } of c.texts) {
        const words = (t.match(/[A-Za-z][A-Za-z'’&.-]*[A-Za-z]|[A-Za-z]/g) || []).filter((w) => !ALLOW.has(w.toLowerCase()));
        if (words.length) latin.push({ t: t.slice(0, 120), shown, where });
      }
      const fonts = await page.evaluate(() => getComputedStyle(document.querySelector('h1,h2,.kft-ch,body')).fontFamily);
      rows.push({ key, width: vp.w, status: resp ? resp.status() : 0, url: PRE + p, markers: c.markers, markerCount: c.markers.length, latin, latinShown: latin.filter((l) => l.shown).length, errors, title: c.title, fonts,
        sw: await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]) });
      if (SHOTS) {
        fs.mkdirSync(SHOTS, { recursive: true });
        const full = action !== 'drawer';
        await page.screenshot({ path: path.join(SHOTS, `${key}-${vp.w}.png`), fullPage: full });
        if (key === 'home') {
          for (const [sel, nm] of [['footer', 'footer'], ['header', 'header'], ['.ktr', 'trust'], ['.home-hb, [data-sec="hb"], section:has(> * h2)', 'bundles']]) {
            const el = await page.$(sel); if (el) await el.screenshot({ path: path.join(SHOTS, `${nm}-${vp.w}.png`) }).catch(() => {});
          }
        }
      }
      page.off('console', onC);
      console.log(`${key.padEnd(15)} ${vp.w} ${rows[rows.length - 1].status} markers=${c.markers.length} latinShown=${rows[rows.length - 1].latinShown} sw=${rows[rows.length - 1].sw}`);
    }
    for (const x of Object.values(ctxs)) await x.ctx.close();
  }
  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify(rows, null, 1));
})();
