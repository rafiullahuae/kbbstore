/* 2.60.499 live preview (desktop summary) (preview only): the real phone checkout of the preview
   shop, with a coupon applied, captured as standalone HTML -- stylesheets,
   fonts and images inlined, scripts removed -- in two states: the top summary
   opened, and the box at the foot of the page opened. Nothing is sent to the
   live shop. BASE=http://127.0.0.1:PORT IDS=683,684,685 node tools/co497-live.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, IDS = (process.env.IDS || '683,684,685').split(',').map(Number);
const OUT = path.resolve(__dirname, '../docs/lane-co13-shots/preview-499/live');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

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
  const ctx = await b.newContext({ userAgent: UA, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  const pg = await ctx.newPage();
  await pg.route(/js\.stripe\.com/, (r) => r.abort());
  await pg.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  await pg.evaluate(async (ids) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    for (const id of ids) await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
    await fetch('/api/cart/coupon', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ code: 'GLOW' }) });
  }, IDS);
  for (const [name, open] of [['closed', false], ['open', true]]) {
    await pg.goto(BASE + '/checkout/', { waitUntil: 'domcontentloaded' }); await pg.waitForTimeout(1200);
    if (open) { await pg.click('#kbbSumRow'); await pg.waitForTimeout(500); }
    fs.writeFileSync(`${OUT}/${name}.html`, await snapshot(pg));
    console.log(name, (fs.statSync(`${OUT}/${name}.html`).size / 1024).toFixed(0) + ' KB');
  }
  await b.close();
})();
