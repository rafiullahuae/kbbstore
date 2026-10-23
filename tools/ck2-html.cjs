/* Lane CK2: the raw HTML of every shop page type, base vs branch, same
   database copy, same basket -- what differs, line by line, after the
   per-request noise (CSRF token, nonces, the build's file hashes) is masked.
     A=http://127.0.0.1:P B=http://127.0.0.1:Q node tools/ck2-html.cjs <out.json>
   Local previews only. */
const { chromium } = require('playwright');
const fs = require('fs');
const A = process.env.A, B = process.env.B;
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const PAGES = ['/', '/shop/', '/collections/cleansers/', '/brands/anua/', '/product/1025-dokdo-toner/', '/cart/', '/checkout/'];

const mask = (h) => h
  .replace(/(name="_token" value=")[^"]+/g, '$1T')
  .replace(/(<meta name="csrf-token" content=")[^"]+/g, '$1T')
  .replace(/("csrf"\s*:\s*")[^"]+/g, '$1T')
  .replace(/nonce="[^"]+"/g, 'nonce="N"')
  .replace(/(\/build\/assets\/[\w.-]+?)-[A-Za-z0-9_-]{8}\.(css|js)/g, '$1-H.$2');

async function grab(base) {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ userAgent: UA, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.goto(base + '/cart/', { waitUntil: 'networkidle' });
  const ids = await (await page.request.get(base + '/ck-ids.json')).json();
  for (const id of ids.slice(0, 3)) {
    await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
  }
  const out = {};
  for (const lang of ['', '/ar']) {
    for (const p of PAGES) {
      const r = await ctx.request.get(base + lang + p, { maxRedirects: 0 });
      out[lang + p] = { status: r.status(), html: mask(await r.text()) };
    }
  }
  await browser.close();
  return out;
}

(async () => {
  const a = await grab(A);
  const b = await grab(B);
  const report = [];
  for (const k of Object.keys(a)) {
    const la = a[k].html.split('\n'), lb = b[k].html.split('\n');
    const diffs = [];
    for (let i = 0; i < Math.max(la.length, lb.length); i++) {
      if (la[i] !== lb[i]) diffs.push({ line: i + 1, base: (la[i] || '').slice(0, 220), branch: (lb[i] || '').slice(0, 220) });
    }
    report.push({ page: k, status: [a[k].status, b[k].status], bytes: [a[k].html.length, b[k].html.length], lines: diffs.length, diffs: diffs.slice(0, 6) });
    console.log(k.padEnd(32), a[k].status, b[k].status, 'bytes', a[k].html.length, '->', b[k].html.length, '(' + (b[k].html.length - a[k].html.length) + ')', 'differing lines', diffs.length);
  }
  fs.writeFileSync(process.argv[2], JSON.stringify(report, null, 1));
})();
