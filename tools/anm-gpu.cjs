/*
 * Lane AN (motion): what the 300 s page wash costs OFF the main thread.
 *   node tools/anm-gpu.cjs BASE [keep-regex]
 * Product page, every animation cancelled except those whose name matches
 * keep-regex (none = everything cancelled). 6 s trace: busy time per thread
 * (top-level tasks) and frames drawn by the display compositor.
 */
const { chromium } = require('playwright');
const [BASE, KEEP] = process.argv.slice(2);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const [w, h, dpr] of [[390, 844, 3], [1280, 800, 1]]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: h }, deviceScaleFactor: dpr });
    const page = await ctx.newPage();
    await page.goto(BASE + (process.env.ANM_PATH || '/product/co-glow-serum/'), { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await page.evaluate(k => document.getAnimations().forEach(a => { if (!k || !new RegExp(k).test(a.animationName)) a.cancel(); }), KEEP || '');
    const left = await page.evaluate(() => document.getAnimations().map(a => a.animationName).join(','));
    await page.waitForTimeout(1000);
    await browser.startTracing(page, { categories: ['toplevel', 'viz', 'cc', 'gpu', '__metadata'] });
    await page.waitForTimeout(6000);
    const ev = JSON.parse((await browser.stopTracing()).toString()).traceEvents;
    const names = {}; for (const e of ev) if (e.ph === 'M' && e.name === 'thread_name') names[e.pid + ':' + e.tid] = e.args.name;
    const busy = {}; let draws = 0;
    for (const e of ev) {
      if (e.ph === 'X' && e.name === 'ThreadControllerImpl::RunTask') { const n = names[e.pid + ':' + e.tid] || '?'; busy[n] = (busy[n] || 0) + e.dur / 1000; }
      if (/DrawAndSwap|SkiaRenderer::SwapBuffers|Display::DrawAndSwap/.test(e.name) && (e.ph === 'X' || e.ph === 'B')) draws++;
    }
    const top = Object.entries(busy).filter(([n]) => /Gpu|Viz|Compositor|CrRenderer|Main/.test(n)).map(([n, v]) => `${n}=${v.toFixed(0)}ms`).join(' ');
    console.log(JSON.stringify({ w, keep: KEEP || 'none', running: left, draws, busy: top }));
    await ctx.close();
  }
  await browser.close();
})();
