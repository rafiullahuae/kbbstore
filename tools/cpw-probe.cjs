/* Lane CPL: the cart panel's real width on a 390px phone, per page type. BASE=... PID=... node tools/cpw-probe.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, PID = process.env.PID;
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  const page = await ctx.newPage();
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  await page.evaluate(async (pid) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) }); }, PID);
  for (const url of ['/', '/shop/', '/product/advanced-snail-96-mucin-power-essence/']) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const m = await page.evaluate(() => {
      const d = document.querySelector('.drawer'); if (!d) return { drawer: false };
      const cs = getComputedStyle(d);
      // which element carries --cp-w-m, and what value
      let carrier = null; for (const el of [d, document.documentElement, document.body, ...document.querySelectorAll('[style*="--cp-w-m"]')]) { if (el && el.getAttribute && /--cp-w-m/.test(el.getAttribute('style') || '')) { carrier = el.tagName + '.' + el.className; break; } }
      const styleTags = [...document.querySelectorAll('style')].map((s) => s.textContent).filter((t) => /--cp-w-m/.test(t)).map((t) => t.match(/--cp-w-m:[^;}]*/g));
      return { drawer: true, width: Math.round(d.getBoundingClientRect().width), cssWidth: cs.width, maxWidth: cs.maxWidth, varOnDrawer: cs.getPropertyValue('--cp-w-m').trim(), carrier, styleTags };
    });
    console.log(url, JSON.stringify(m));
  }
  await browser.close();
})();
