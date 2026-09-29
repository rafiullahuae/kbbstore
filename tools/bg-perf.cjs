/*
 * What the wash costs, measured rather than asserted.                (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8933 node tools/bg-perf.cjs
 *
 * ── WHAT IS MEASURED, AND WHY THESE NUMBERS ────────────────────────────────
 *
 * The failure this is written to catch is the classic one: an animated gradient
 * that repaints the whole viewport on the main thread every frame, which pins a
 * phone's CPU and flattens its battery. The tell is not "does it look smooth"
 * — a phone will happily burn itself smooth — it is whether the MAIN THREAD is
 * doing anything at all while the animation runs.
 *
 * So each page is left completely idle for a fixed window at a phone-sized
 * viewport, and Chromium's own counters are read before and after:
 *
 *   TaskDuration        main-thread CPU seconds spent in tasks
 *   RecalcStyleCount    style recalculations
 *   LayoutCount         layouts
 *   ScriptDuration      seconds in script
 *
 * A composited opacity animation moves none of those: the compositor
 * interpolates the value on its own thread and reuses the rasterised layer. A
 * main-thread one moves all four, and RecalcStyleCount climbs by roughly one
 * per frame — 60 a second, 1,200 over the window below.
 *
 * TODAY IS MEASURED TOO, on the same page in the same run, because the number
 * that matters is the DIFFERENCE. The shop already animates things (the hero
 * slider, the marquee), so an absolute figure says nothing.
 *
 * The frame is also shown with the animation running at its real speed and
 * again with the cycle compressed to 6 seconds (a negative delay plus a fast
 * override), which is the only way to see the steady-state cost of a wash whose
 * real cycle is minutes: at 420s the compositor genuinely has little to do
 * between frames, and a measurement at that rate would flatter it.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8933';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-shots');
const WINDOW_MS = Number(process.env.BG_WINDOW || 20000);

function pick(metrics) {
  const m = {};
  for (const e of metrics) m[e.name] = e.value;
  return {
    TaskDuration: m.TaskDuration,
    ScriptDuration: m.ScriptDuration,
    LayoutDuration: m.LayoutDuration,
    RecalcStyleDuration: m.RecalcStyleDuration,
    RecalcStyleCount: m.RecalcStyleCount,
    LayoutCount: m.LayoutCount,
    JSHeapUsedSize: m.JSHeapUsedSize,
    Nodes: m.Nodes,
  };
}

async function measure(ctx, label, url, opts = {}) {
  const page = await ctx.newPage();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(url, { waitUntil: 'networkidle' });

  if (opts.fastCycle) {
    // The same animation, its cycle compressed, so the window below contains
    // several full crossfades instead of a sliver of one.
    await page.addStyleTag({
      content: 'body::before,body::after{animation-duration:6s !important}',
    });
  }

  await page.waitForTimeout(1500);

  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');

  const before = pick((await cdp.send('Performance.getMetrics')).metrics);
  const t0 = Date.now();
  await page.waitForTimeout(WINDOW_MS);
  const after = pick((await cdp.send('Performance.getMetrics')).metrics);
  const elapsed = (Date.now() - t0) / 1000;

  const row = {
    label,
    url: url.replace(BASE, ''),
    seconds: Math.round(elapsed * 10) / 10,
    cpuMsPerSecond: Math.round(((after.TaskDuration - before.TaskDuration) / elapsed) * 1000 * 100) / 100,
    scriptMsPerSecond: Math.round(((after.ScriptDuration - before.ScriptDuration) / elapsed) * 1000 * 100) / 100,
    styleRecalcs: after.RecalcStyleCount - before.RecalcStyleCount,
    layouts: after.LayoutCount - before.LayoutCount,
    styleMs: Math.round((after.RecalcStyleDuration - before.RecalcStyleDuration) * 1000 * 100) / 100,
    layoutMs: Math.round((after.LayoutDuration - before.LayoutDuration) * 1000 * 100) / 100,
    heapMB: Math.round((after.JSHeapUsedSize / 1048576) * 10) / 10,
    nodes: after.Nodes,
  };

  await page.close();
  console.log(JSON.stringify(row));
  return row;
}

async function signIn(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await signIn(ctx);

  /*
   * INTERLEAVED AND REPEATED, WITH MEDIANS, and that is not fastidiousness.
   * Three lanes share this machine. A first pass measured 121 ms/s for the
   * shop as it is today and 313 for the same page with the wash on -- and a
   * second pass, minutes later, measured 119 and 148 for the same two. The
   * difference between the passes is other people's test suites, and a single
   * before/after pair cannot tell that apart from the thing being measured.
   *
   * So each condition is measured REPEATS times, the conditions alternate, and
   * the median of each is reported. Anything that loads the machine for a
   * minute now lands on every condition rather than on whichever one ran while
   * it happened.
   */
  const REPEATS = Number(process.env.BG_REPEATS || 5);

  const CONDITIONS = [
    ['today — no wash', `${BASE}/shop/`, {}],
    ['treatment A (420s cycle)', `${BASE}/shop/?kbbwash=a`, {}],
    ['treatment B (120s cycle)', `${BASE}/shop/?kbbwash=b`, {}],
    ['treatment D (top, 180s)', `${BASE}/shop/?kbbwash=d`, {}],
    ['treatment B, cycle forced to 6s', `${BASE}/shop/?kbbwash=b`, { fastCycle: true }],
  ];

  const samples = new Map(CONDITIONS.map(([label]) => [label, []]));

  for (let pass = 0; pass < REPEATS; pass++) {
    for (const [label, url, opts] of CONDITIONS) {
      samples.get(label).push(await measure(ctx, label + ' #' + (pass + 1), url, opts));
    }
  }

  const median = (xs) => {
    const s = [...xs].sort((a, b) => a - b);
    return s.length % 2 ? s[(s.length - 1) / 2] : Math.round(((s[s.length / 2 - 1] + s[s.length / 2]) / 2) * 100) / 100;
  };

  const rows = CONDITIONS.map(([label]) => {
    const xs = samples.get(label);

    return {
      label,
      url: xs[0].url,
      passes: xs.length,
      seconds: xs[0].seconds,
      cpuMsPerSecond: median(xs.map((r) => r.cpuMsPerSecond)),
      cpuSpread: [Math.min(...xs.map((r) => r.cpuMsPerSecond)), Math.max(...xs.map((r) => r.cpuMsPerSecond))],
      scriptMsPerSecond: median(xs.map((r) => r.scriptMsPerSecond)),
      styleRecalcs: median(xs.map((r) => r.styleRecalcs)),
      layouts: median(xs.map((r) => r.layouts)),
      heapMB: median(xs.map((r) => r.heapMB)),
      nodes: xs[0].nodes,
    };
  });

  // And the same page in a context that asks for less motion: no animation at
  // all should exist, so the numbers must fall back onto the baseline's.
  const rm = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
  await signIn(rm);
  const rmSamples = [];
  for (let pass = 0; pass < REPEATS; pass++) {
    rmSamples.push(await measure(rm, 'treatment B, prefers-reduced-motion #' + (pass + 1), `${BASE}/shop/?kbbwash=b`));
  }
  await rm.close();

  rows.push({
    label: 'treatment B, prefers-reduced-motion',
    url: rmSamples[0].url,
    passes: rmSamples.length,
    seconds: rmSamples[0].seconds,
    cpuMsPerSecond: median(rmSamples.map((r) => r.cpuMsPerSecond)),
    cpuSpread: [Math.min(...rmSamples.map((r) => r.cpuMsPerSecond)), Math.max(...rmSamples.map((r) => r.cpuMsPerSecond))],
    scriptMsPerSecond: median(rmSamples.map((r) => r.scriptMsPerSecond)),
    styleRecalcs: median(rmSamples.map((r) => r.styleRecalcs)),
    layouts: median(rmSamples.map((r) => r.layouts)),
    heapMB: median(rmSamples.map((r) => r.heapMB)),
    nodes: rmSamples[0].nodes,
  });

  fs.mkdirSync(OUT, { recursive: true });
  fs.writeFileSync(`${OUT}/performance.json`, JSON.stringify(rows, null, 2));

  const w = (s, n) => String(s).padEnd(n);
  console.log('\n' + w('page (median of ' + REPEATS + ')', 38) + w('cpu ms/s', 10) + w('spread', 18) + w('style recalcs', 15) + w('layouts', 9) + 'heap MB');
  for (const r of rows) {
    console.log(w(r.label, 38) + w(r.cpuMsPerSecond, 10) + w(r.cpuSpread.join(' — '), 18)
      + w(r.styleRecalcs, 15) + w(r.layouts, 9) + r.heapMB);
  }

  await browser.close();
})();
