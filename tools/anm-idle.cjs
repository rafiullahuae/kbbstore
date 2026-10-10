/*
 * Lane AN (motion): what the always-running animations cost an idle page.
 *
 *   ANM_BASE=http://127.0.0.1:8971 node tools/anm-idle.cjs [label] [mode]
 *
 * mode "pages" (default): every page type at 390 (DPR 3, 4x CPU) and 1280,
 *   idle 10 s after load + 3 s settle; Performance.getMetrics deltas.
 * mode "each": product page at 390 only, once per running animation with
 *   THAT animation alone set to none, so each one's own cost is the difference.
 */
const { chromium } = require('playwright');
const BASE = process.env.ANM_BASE || 'http://127.0.0.1:8971';
const label = process.argv[2] || 'run';
const mode = process.argv[3] || 'pages';
const IDLE = +(process.env.ANM_IDLE || 10000);
const PAGES = {
  home: '/', product: '/product/co-glow-serum/', category: '/collections/serums/',
  brand: '/brands/cosrx/', 'super-sale': '/super-sale/', blog: '/blog/', cart: '/cart/', checkout: '/checkout/',
};
const VPS = { 390: { width: 390, height: 844, deviceScaleFactor: 3, cpu: 4 }, 1280: { width: 1280, height: 800, deviceScaleFactor: 1, cpu: 1 } };

async function measure(browser, url, vp, extraCss) {
  const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', viewport: { width: vp.width, height: vp.height }, deviceScaleFactor: vp.deviceScaleFactor });
  if (url.includes('/cart/') || url.includes('/checkout/')) {
    // a line in the cart, so cart and checkout render their real pages
    const p0 = await ctx.newPage();
    await p0.goto(BASE + PAGES.product, { waitUntil: 'load' });
    await p0.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
    await p0.close();
  }
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', e => errors.push(String(e)));
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  if (vp.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: vp.cpu });
  const resp = await page.goto(BASE + url, { waitUntil: 'load' });
  if (extraCss) await page.evaluate(n => document.getAnimations().filter(a => n.startsWith('only:') ? a.animationName !== n.slice(5) : a.animationName === n).forEach(a => a.cancel()), extraCss);
  await page.waitForTimeout(3000);
  const get = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map(m => [m.name, m.value]));
  const a = await get();
  await page.waitForTimeout(IDLE);
  const b = await get();
  const anims = await page.evaluate(() => document.getAnimations().filter(a => a.playState === 'running').map(a => {
    const t = a.effect && a.effect.target; const pe = a.effect && a.effect.pseudoElement;
    const kf = a.effect.getKeyframes().flatMap(k => Object.keys(k).filter(x => !['offset', 'easing', 'composite', 'computedOffset'].includes(x)));
    return `${a.animationName}@${t ? t.tagName + (t.className && typeof t.className === 'string' ? '.' + t.className.split(' ')[0] : '') : '?'}${pe || ''}[${[...new Set(kf)].join(',')}]`;
  }));
  const sw = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
  await ctx.close();
  const d = k => +(b[k] - a[k]).toFixed(3);
  return { status: resp.status(), task: d('TaskDuration'), recalc: d('RecalcStyleCount'), layout: d('LayoutCount'), script: d('ScriptDuration'), style: d('RecalcStyleDuration'), sw, errors, anims };
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.ANM_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  if (mode === 'each') {
    const url = process.env.ANM_PAGE || PAGES.product;
    const base = await measure(browser, url, VPS[390]);
    console.log(JSON.stringify({ label, off: 'NONE', ...base }));
    const names = [...new Set(base.anims.map(s => s.split('@')[0]))];
    if (process.env.ANM_ONLY) names.push('__none__');
    for (const n of names) {
      const r = await measure(browser, url, VPS[390], (process.env.ANM_ONLY ? 'only:' : '') + n);
      console.log(JSON.stringify({ label, off: n, ...r, anims: r.anims.length }));
    }
  } else {
    for (const [name, url] of Object.entries(PAGES).filter(([n]) => !process.env.ANM_ONLY_PAGE || n === process.env.ANM_ONLY_PAGE)) for (const [w, vp] of Object.entries(VPS)) {
      const r = await measure(browser, url, vp);
      console.log(JSON.stringify({ label, page: name, w: +w, ...r, anims: r.anims.length, list: w === '390' ? r.anims : undefined }));
    }
  }
  await browser.close();
})();
