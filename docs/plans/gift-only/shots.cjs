// "Gift product" previews (the owner, 6 Nov 2026, URGENT): "another product type
// 'Gift product' ... on product page instead of add to cart, it will be Gift only,
// and the button will act nothing. give me a nice button or box type ... along with
// gift icon. so people will understand that this product added only as gifts."
// Draws each design onto the LIVE product page (in place of the buy row) and
// photographs it at 1280 and 390. Nothing is changed on the shop.
const { chromium, devices } = require('playwright');
const OUT = process.argv[2];
const URL = 'https://kbeautybliss.com/product/axis-y-dark-spot-correcting-glow-serum/';
const GIFT = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>';
const V = {
  A: { name: 'A · Gift pill', css: `.gx{display:flex;flex-direction:column;gap:8px;margin:14px 0}.gx-pill{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:16px 20px;border-radius:999px;background:#eaf7f0;border:2px solid #2e8f62;color:#1f6b48;font-weight:800;font-size:17px;line-height:1.2;letter-spacing:.02em;cursor:default}.gx-pill svg{flex:none}.gx-note{text-align:center;font-size:13.5px;color:#5e545a}.gx-note a{color:#2e8f62;font-weight:700}`,
       html: `<div class="gx" role="note"><div class="gx-pill">${GIFT}<span>FREE GIFT ONLY · not sold separately</span></div><div class="gx-note">Comes free with qualifying orders. <a href="#">See how to get it →</a></div></div>` },
  B: { name: 'B · Gift ticket', css: `.gx{position:relative;display:flex;align-items:center;gap:14px;margin:14px 0;padding:16px 18px;border:2px dashed #e4457c;border-radius:16px;background:linear-gradient(90deg,#fff0f5,#fff8fb)}.gx-ic{flex:none;width:52px;height:52px;border-radius:14px;display:grid;place-items:center;background:#e4457c;color:#fff}.gx-ic svg{width:28px;height:28px}.gx b{display:block;font-size:17px;color:#c3306a}.gx span{font-size:13.5px;color:#5e545a}.gx a{color:#c3306a;font-weight:700}`,
       html: `<div class="gx" role="note"><div class="gx-ic">${GIFT}</div><div><b>This is a free gift</b><span>Not for sale on its own: it is added free to qualifying orders. <a href="#">See the gifts →</a></span></div></div>` },
  C: { name: 'C · Gold ribbon card', css: `.gx{position:relative;overflow:hidden;margin:14px 0;padding:18px 18px 16px 70px;border-radius:16px;background:linear-gradient(120deg,#fff6e3,#fffbf2);border:1.5px solid #e6c98a}.gx::before{content:"";position:absolute;left:0;top:0;bottom:0;width:52px;background:linear-gradient(180deg,#d9a441,#b8862a)}.gx-ic{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#fff}.gx-ic svg{width:28px;height:28px}.gx b{display:block;font-size:16px;color:#7a5512;letter-spacing:.04em}.gx span{font-size:13.5px;color:#5e545a}.gx a{color:#9a6b12;font-weight:700}`,
       html: `<div class="gx" role="note"><div class="gx-ic">${GIFT}</div><b>GIFT ONLY</b><span>Free with qualifying orders, never sold separately. <a href="#">See the free gifts →</a></span></div>` },
  D: { name: 'D · Glow button (does nothing)', css: `.gx{margin:14px 0;display:flex;flex-direction:column;gap:8px}.gx-btn{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:17px 20px;border-radius:14px;background:linear-gradient(100deg,#ec3a80,#ff8a5c);color:#fff;font-weight:800;font-size:17px;line-height:1.2;letter-spacing:.04em;cursor:default;box-shadow:0 14px 26px -14px #ec3a80}.gx-btn::after{content:"";position:absolute;inset:0;background:linear-gradient(100deg,transparent 30%,rgba(255,255,255,.35) 45%,transparent 60%);transform:translateX(-100%);animation:gxS 4s ease-in-out infinite}@keyframes gxS{60%,100%{transform:translateX(100%)}}@media (prefers-reduced-motion:reduce){.gx-btn::after{animation:none}}.gx-note{text-align:center;font-size:13.5px;color:#5e545a}`,
       html: `<div class="gx" role="note"><div class="gx-btn">${GIFT}<span>GIFT ONLY</span></div><div class="gx-note">Added free to qualifying orders · not sold separately</div></div>` },
};
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [k, v] of Object.entries(V)) for (const dev of ['desk', 'phone']) {
    const ctx = await b.newContext(dev === 'desk' ? { viewport: { width: 1280, height: 900 } } : { ...devices['iPhone 13'] });
    const p = await ctx.newPage();
    await p.goto(URL, { waitUntil: 'networkidle', timeout: 60000 });
    const box = await p.evaluate(([css, html]) => {
      const st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);
      const form = document.querySelector('.buybox form.kbb-cart-form');
      [...form.children].forEach(c => c.style.display = 'none');
      form.insertAdjacentHTML('afterbegin', html);
      document.querySelectorAll('.sticky-atc, .kbb-sticky, [class*=sticky]').forEach(e => { if (/cart|add/i.test(e.textContent)) e.style.display = 'none'; });
      const bb = document.querySelector('.buybox'); bb.scrollIntoView({ block: 'center' }); const r = bb.getBoundingClientRect();
      return { x: r.left, y: r.top, w: r.width, h: r.height };
    }, [v.css, v.html]);
    await p.waitForTimeout(600);
    const pad = 24;
    if (dev === 'desk') await p.screenshot({ path: `${OUT}/${k}-desk.png`, clip: { x: Math.max(0, box.x - 640), y: Math.max(0, box.y - 260), width: 1280, height: Math.min(900, box.h + 380) } });
    else {
      const r = await p.evaluate(() => { const e = document.querySelector('.gx'); const b = e.getBoundingClientRect(); return { y: b.top + scrollY, h: b.height }; });
      const top = Math.max(0, r.y - 330);
      await p.screenshot({ path: `${OUT}/${k}-phone.png`, fullPage: true, clip: { x: 0, y: top, width: 390, height: 600 } });
    }
    await ctx.close();
  }
  await b.close();
})();
