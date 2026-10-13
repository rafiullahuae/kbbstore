/* 2.60.497 live preview (preview only): the real phone checkout of the preview
   shop, with a coupon applied, captured as standalone HTML -- stylesheets,
   fonts and images inlined, scripts removed -- in two states: the top summary
   opened, and the box at the foot of the page opened. Nothing is sent to the
   live shop. BASE=http://127.0.0.1:PORT IDS=683,684,685 node tools/co497-live.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, IDS = (process.env.IDS || '683,684,685').split(',').map(Number);
const OUT = path.resolve(__dirname, '../docs/lane-co13-shots/preview-497/live');
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
  await pg.evaluate(async (ids) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    for (const id of ids) await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
    const r = await fetch('/api/cart/coupon', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ code: 'GLOW' }) });
    if (!r.ok) throw new Error('coupon refused: ' + r.status);
  }, IDS);
  const prep = async () => {
    await pg.goto(BASE + '/checkout/', { waitUntil: 'networkidle' }); await pg.waitForTimeout(900);
    // The Apple Pay row is shown by Stripe on an iPhone; shown here by hand.
    await pg.evaluate(() => { const li = document.querySelector('[data-kbb-ap-li]'); if (li) li.hidden = false; });
  };
  await prep();
  await pg.click('#kbbSumRow'); await pg.waitForTimeout(500);
  fs.writeFileSync(`${OUT}/top.html`, await snapshot(pg));
  await prep();
  await pg.locator('.kbb-mobile-order').scrollIntoViewIfNeeded();
  await pg.locator('.kbb-mobile-order .co-ta:visible').first().click(); await pg.waitForTimeout(500);
  const y = await pg.evaluate(() => { const r = document.querySelector('#payment').getBoundingClientRect(); return Math.round(r.top + scrollY + r.height - 260); });
  fs.writeFileSync(`${OUT}/bottom.html`, (await snapshot(pg)).replace('<html', `<html data-scroll="${y}"`));
  for (const n of ['top', 'bottom']) console.log(n, (fs.statSync(`${OUT}/${n}.html`).size / 1024).toFixed(0) + ' KB');
  await b.close();
})();
