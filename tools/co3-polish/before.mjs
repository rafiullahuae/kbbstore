/* Lane CO: the BEFORE pictures and numbers, against a preview of the base
   commit (tools/co3-polish/preview.sh PORT <ref>): the coupon box, where the
   wallet row was, the section gaps at "Space between blocks" 16 and 28, and
   the footer.  node before.mjs PORT SERUM_ID SHOTS_DIR */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2], PID = Number(process.argv[3] || 25), SHOTS = process.argv[4], BASE = `http://127.0.0.1:${PORT}`;
const STUB = fs.readFileSync(`${HERE}/stripe-stub.js`, 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
for (const width of [390, 1280]) {
  for (const gap of [16, 28]) {
    const ctx = await b.newContext({ viewport: { width, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
    const p = await ctx.newPage();
    await p.route('**stripe.com/**', r => r.abort());
    await p.route('**js.stripe.com/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
    await p.goto(`${BASE}/__co/block-gap/${gap}`);
    await p.goto(`${BASE}/__co/in-stock/co-glow-serum`);
    await p.goto(`${BASE}/product/co-glow-serum`);
    await p.evaluate(async (pid) => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }); }, PID);
    await p.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(500);
    const m = await p.evaluate(() => {
      const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
      const secs = [...document.querySelectorAll('#customer_details > .sec')];
      const out = {};
      secs.forEach((s, i) => { if (!secs[i + 1]) return; let low = 0; s.querySelectorAll('*').forEach(e => { if (e.closest('h2') || !vis(e)) return; low = Math.max(low, e.getBoundingClientRect().bottom); }); out[(i + 1) + '->' + (i + 2)] = Math.round(secs[i + 1].getBoundingClientRect().top - low); });
      const c = document.querySelector('.coupon').getBoundingClientRect(), f = document.querySelector('.formbox').getBoundingClientRect();
      out['coupon->form'] = Math.round(f.top - c.bottom);
      const x = document.querySelector('[data-kbb-express]');
      out.walletRowTop = x ? Math.round(x.getBoundingClientRect().top + scrollY) : null;
      out.formTop = Math.round(f.top + scrollY);
      return out;
    });
    console.log(width, 'block gap', gap, JSON.stringify(m));
    if (gap === 16) {
      await p.screenshot({ path: `${SHOTS}/co-0-before-full-${width}.png`, fullPage: true });
    } else {
      const r = await p.evaluate(() => { const e = document.querySelector('#kbb_remember_field'); const r = e.getBoundingClientRect(); return r.top + scrollY; });
      const pay = await p.evaluate(() => document.querySelector('.sec.pay').getBoundingClientRect().top + scrollY);
      await p.screenshot({ path: `${SHOTS}/co-3b-before-section-gaps-28-${width}.png`, fullPage: true, clip: { x: 0, y: r - 60, width, height: Math.min(pay - r + 140, 900) } });
    }
    await ctx.close();
  }
}
const ctx = await b.newContext(); const p = await ctx.newPage(); await p.goto(`${BASE}/__co/block-gap/16`); await ctx.close();
await b.close();
