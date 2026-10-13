/* Lane CPV (preview only): the real cart and checkout pages of the preview shop,
   holding the owner's real bag (six products, AED 691), captured as standalone
   HTML -- stylesheets, fonts and images inlined, scripts removed -- for the
   checkout-changes live preview (docs/checkout-preview/). Nothing is sent to the
   live shop. BASE=http://127.0.0.1:PORT IDS=683,684,... node tools/cpv-capture.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, IDS = (process.env.IDS || '').split(',').map(Number);
// TAG=<name>: save as cart-<name>.html (e.g. a small bag). COUPON=<code>: the same bag with that code applied, saved as cart-coupon.html / checkout-coupon.html.
const COUPON = process.env.COUPON || '', SFX = process.env.TAG ? '-' + process.env.TAG : (COUPON ? '-coupon' : '');
const OUT = path.resolve(__dirname, '../docs/checkout-preview/captured');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

async function snapshot(pg) {
  return pg.evaluate(async () => {
    const toData = async (url) => {
      try {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) return null;
        const b = await r.blob();
        return await new Promise((res) => { const f = new FileReader(); f.onload = () => res(f.result); f.readAsDataURL(b); });
      } catch (e) { return null; }
    };
    const inlineUrls = async (css, base) => {
      const urls = [...new Set([...css.matchAll(/url\((['"]?)([^'")]+)\1\)/g)].map((m) => m[2]).filter((u) => !u.startsWith('data:') && !u.startsWith('#')))];
      for (const u of urls) {
        const abs = new URL(u, base).href;
        const d = await toData(abs);
        if (d) css = css.split(u).join(d);
      }
      return css;
    };
    const doc = document.documentElement.cloneNode(true);
    // Stylesheets -> inline <style>, with their fonts and images as data URIs.
    for (const l of [...doc.querySelectorAll('link[rel="stylesheet"]')]) {
      const href = new URL(l.getAttribute('href'), location.href).href;
      try {
        let css = await (await fetch(href)).text();
        css = await inlineUrls(css, href);
        const s = document.createElement('style'); s.textContent = css; l.replaceWith(s);
      } catch (e) { l.remove(); }
    }
    for (const s of [...doc.querySelectorAll('style')]) s.textContent = await inlineUrls(s.textContent, location.href);
    // Images -> data URIs.
    for (const im of [...doc.querySelectorAll('img[src]')]) {
      const src = im.getAttribute('src');
      if (src && !src.startsWith('data:')) { const d = await toData(new URL(src, location.href).href); if (d) im.setAttribute('src', d); }
      im.removeAttribute('srcset'); im.removeAttribute('loading');
    }
    for (const el of [...doc.querySelectorAll('[style*="url("]')]) el.setAttribute('style', await inlineUrls(el.getAttribute('style'), location.href));
    // No scripts, no preloads, no links out.
    doc.querySelectorAll('script, link[rel="preload"], link[rel="modulepreload"], link[rel="manifest"], link[rel="icon"], link[rel="apple-touch-icon"], noscript, iframe').forEach((n) => n.remove());
    doc.querySelectorAll('a[href]').forEach((a) => a.setAttribute('href', '#'));
    doc.querySelectorAll('form').forEach((f) => { f.removeAttribute('action'); f.setAttribute('onsubmit', 'return false'); });
    return '<!doctype html>\n' + doc.outerHTML;
  });
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ userAgent: UA, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const pg = await ctx.newPage();
  await pg.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  await pg.evaluate(async ([ids, code]) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    for (const [i, id] of ids.entries()) {
      await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: i === 0 && ids.length > 1 ? 2 : 1 }) });
    }
    if (code) {
      const r = await fetch('/api/cart/coupon', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ code }) });
      if (!r.ok) throw new Error('coupon refused: ' + r.status + ' ' + (await r.text()).slice(0, 200));
    }
  }, [IDS, COUPON]);
  for (const [name, url] of [['cart', '/cart/'], ['checkout', '/checkout/']]) {
    await pg.goto(BASE + url, { waitUntil: 'networkidle' }); await pg.waitForTimeout(1200);
    await pg.screenshot({ path: `${OUT}/${name}${SFX}-before-390.png`, fullPage: true });
    fs.writeFileSync(`${OUT}/${name}${SFX}.html`, await snapshot(pg));
    console.log(name, (fs.statSync(`${OUT}/${name}${SFX}.html`).size / 1024).toFixed(0) + ' KB');
  }
  await b.close();
})();
