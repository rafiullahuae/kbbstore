/*
 * Lane LG2: what the logo's animation costs, from a 5-second Chromium
 * performance trace of the homepage at 390, taken after the page has loaded
 * and settled. Reports the renderer main thread's busy time (top-level tasks)
 * as a share of the 5 s, and how many Paint events it ran and for how long --
 * a composited animation paints nothing; one that repaints shows ~300.
 *
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-trace.cjs <base> <label> [path] [width]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.argv[2];
const LABEL = process.argv[3] || 'x';
const PATH = process.argv[4] || '/';
const W = Number(process.argv[5] || 390);
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (let run = 0; run < 3; run++) {
    const ctx = await browser.newContext({ viewport: { width: W, height: 844 }, isMobile: W < 600, hasTouch: W < 600, userAgent: UA });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    /* LG2_ISOLATE: pause every OTHER animation on the page (the menu icon's
       tiles, the footer drift, the WhatsApp button ...), so what is left in
       the trace is the logo's own cost and nothing else. */
    if (process.env.LG2_ISOLATE) {
      await ctx.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
        const s = document.createElement('style');
        s.textContent = '*,*::before,*::after{animation-play-state:paused!important}'
          + '.lgx,.lgx *,.lgx *::before,.lgx *::after{animation-play-state:running!important}';
        document.head.appendChild(s);
      }));
    }
    const page = await ctx.newPage();
    await page.goto(BASE + PATH, { waitUntil: 'load' });
    await page.waitForTimeout(2500);
    await browser.startTracing(page, { categories: ['devtools.timeline', 'disabled-by-default-devtools.timeline', 'toplevel', 'cc', 'viz'] });
    await page.waitForTimeout(5000);
    const buf = await browser.stopTracing();
    const ev = JSON.parse(buf.toString()).traceEvents;
    const names = {};
    for (const e of ev) if (e.ph === 'M' && e.name === 'thread_name') names[e.pid + ':' + e.tid] = e.args.name;
    const mains = Object.keys(names).filter((k) => names[k] === 'CrRendererMain');
    // The page's own renderer: the main thread with the most events.
    const count = {};
    for (const e of ev) { const k = e.pid + ':' + e.tid; if (mains.includes(k)) count[k] = (count[k] || 0) + 1; }
    const main = Object.keys(count).sort((a, b) => count[b] - count[a])[0];
    const comp = Object.keys(names).find((k) => names[k] === 'Compositor' && k.split(':')[0] === main.split(':')[0]);
    let busy = 0; let paints = 0; let paintMs = 0; let style = 0; let layout = 0; let compBusy = 0;
    for (const e of ev) {
      const k = e.pid + ':' + e.tid;
      if (e.ph !== 'X' || !e.dur) continue;
      if (k === main && (e.name === 'RunTask' || e.name === 'ThreadControllerImpl::RunTask')) busy += e.dur;
      if (k === main && e.name === 'Paint') { paints++; paintMs += e.dur; }
      if (k === main && e.name === 'UpdateLayoutTree') style += e.dur;
      if (k === main && e.name === 'Layout') layout += e.dur;
      if (k === comp && (e.name === 'RunTask' || e.name === 'ThreadControllerImpl::RunTask')) compBusy += e.dur;
    }
    rows.push({ busy: busy / 1000, paints, paintMs: paintMs / 1000, style: style / 1000, layout: layout / 1000, comp: compBusy / 1000 });
    if (run === 0 && process.env.LG2_KEEP) fs.writeFileSync(process.env.LG2_KEEP, buf);
    await ctx.close();
  }
  const med = (k) => { const s = rows.map((r) => r[k]).sort((a, b) => a - b); return s[1]; };
  console.log(`${LABEL}${process.env.LG2_ISOLATE ? ' (logo only)' : ''} ${PATH} @${W}: main thread busy ${med('busy').toFixed(1)} ms of 5000 (${(med('busy') / 50).toFixed(2)}%) | Paint events ${med('paints')} (${med('paintMs').toFixed(1)} ms) | style ${med('style').toFixed(1)} ms | layout ${med('layout').toFixed(1)} ms | compositor thread ${med('comp').toFixed(1)} ms  [runs: ${rows.map((r) => r.busy.toFixed(0) + '/' + r.paints).join(' ')}]`);
  await browser.close();
})();
