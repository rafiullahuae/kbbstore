/* Lane LH -- one Lighthouse run against a local preview, PSI's mobile profile
 * (Lighthouse's own default: Moto G Power 412x823, simulated Slow 4G, 4x CPU)
 * or its desktop preset. Lighthouse is NOT a dependency of this repository;
 * point LH_DIR at a folder with `lighthouse` and `chrome-launcher` installed.
 *
 *   LH_DIR=/path node tools/lh-run.mjs <label> <mobile|desktop> <url>
 *
 * Writes storage/lh-logs/lh/<label>-<profile>.json (the full LHR, filmstrip
 * included) and appends one summary row to storage/lh-logs/lh/runs.jsonl --
 * inside the worktree, never the shared scratchpad (CLAUDE.md). The row
 * carries `siObserved`: Lighthouse's simulated Speed Index is
 * -250 + 1.4 x (the OBSERVED, unthrottled filmstrip's SI) + 0.4 x (a
 * layout-based estimate), so the observed filmstrip is the lever. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LH = process.env.LH_DIR;
if (!LH) { console.error('set LH_DIR'); process.exit(2); }
const req = createRequire(path.join(LH, 'package.json'));
const lighthouse = (await import(pathToFileURL(req.resolve('lighthouse')).href)).default;
const chromeLauncher = await import(pathToFileURL(req.resolve('chrome-launcher')).href);
const desktopConfig = (await import(pathToFileURL(path.join(path.dirname(req.resolve('lighthouse/package.json')), 'core/config/desktop-config.js')).href)).default;

const [label, profile = 'mobile', url] = process.argv.slice(2);
const out = path.join(APP, 'storage/lh-logs/lh');
fs.mkdirSync(out, { recursive: true });

const chrome = await chromeLauncher.launch({
  chromePath: process.env.CHROME_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  chromeFlags: ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
});
try {
  /* CPU MATCHED TO PSI'S MACHINE, not Lighthouse's 4x. The owner's report
     reads "Unthrottled CPU/Memory Power: 782 -- CPU throttling 1.2x" on mobile
     and "936 -- 1x" on desktop; this container benchmarks ~1,700, so the same
     effective CPU is 1.2 x 1700/782 = 2.6x on mobile and 1700/936 = 1.8x on
     desktop.

     `mobile-dt` is the SAME phone with DevTools throttling (the network really
     slowed during the load, Slow 4G as Lighthouse defines it). PSI's report
     shows its observed load is slow -- "Initial Navigation 2,613 ms", LCP
     "resource load duration 1,550 ms" for a 30 KiB picture -- and Lantern's
     Speed Index is 1.4 x the OBSERVED filmstrip's SI (+0.4 x a layout
     estimate), so the observed filmstrip under a slow network is the thing to
     move; a local, instant observed load cannot show it. */
  const base = profile === 'desktop' ? desktopConfig : { extends: 'lighthouse:default' };
  const config = { ...base, settings: { ...(base.settings ?? {}),
    /* ...and with the SAME end-of-trace rule PSI's run uses. Lighthouse lengthens
       its waits to 5,250 ms under DevTools throttling; under 'simulate' (PSI) they
       are 1,000 ms, so PSI's filmed run stops ~1 s after the network and the CPU
       go quiet. Left at 5,250 the film runs on for seconds PSI never sees, and a
       slide change at 7 s counts here and not there. */
    ...(profile === 'mobile-dt' ? { throttlingMethod: 'devtools', pauseAfterFcpMs: 1000, pauseAfterLoadMs: 1000,
      networkQuietThresholdMs: 1000, cpuQuietThresholdMs: 1000 } : {}),
    throttling: { ...(base.settings?.throttling ?? { rttMs: 150, throughputKbps: 1638.4, requestLatencyMs: 562.5,
      downloadThroughputKbps: 1474.56, uploadThroughputKbps: 675 }), cpuSlowdownMultiplier: profile === 'desktop' ? 1.8 : 2.6 } } };
  /* LH_COOKIE (Lane CC): a Cookie header for every request, so /cart/ and
     /checkout/ can be measured with a basket instead of redirecting. */
  const extraHeaders = process.env.LH_COOKIE ? { Cookie: process.env.LH_COOKIE } : undefined;
  const runner = await lighthouse(url, { logLevel: 'error', output: 'json', port: chrome.port, onlyCategories: ['performance'], extraHeaders }, config);
  const lhr = runner.lhr;
  fs.writeFileSync(path.join(out, `${label}-${profile}.json`), runner.report);
  /* The filmed frames, kept so tools/lh-si-cut.mjs can re-score the run as
     if the film had stopped where PSI's would (see that file). */
  const ev = runner.artifacts?.Trace?.traceEvents ?? [];
  fs.writeFileSync(path.join(out, `${label}-${profile}.frames.json`), JSON.stringify(ev.filter((e) =>
    e.name === 'Screenshot' || e.name === 'navigationStart' || e.name === 'TracingStartedInBrowser')));
  const a = (id) => lhr.audits[id]?.numericValue;
  const blocking = (lhr.audits['render-blocking-insight']?.details?.items ?? lhr.audits['render-blocking-resources']?.details?.items ?? []);
  const row = { label, profile, url, perf: Math.round((lhr.categories.performance?.score ?? 0) * 100),
    fcp: Math.round(a('first-contentful-paint')), lcp: Math.round(a('largest-contentful-paint')), si: Math.round(a('speed-index')),
    tbt: Math.round(a('total-blocking-time')), cls: +(a('cumulative-layout-shift') ?? 0).toFixed(3),
    kib: Math.round((a('total-byte-weight') ?? 0) / 1024),
    blockKib: Math.round(blocking.reduce((s, i) => s + (i.totalBytes ?? i.transferSize ?? 0), 0) / 1024),
    blockN: blocking.length,
  };
  // The observed (unthrottled) metrics Lantern starts from.
  const m = lhr.audits.metrics?.details?.items?.[0] ?? {};
  row.siObserved = m.observedSpeedIndex; row.fcpObserved = m.observedFirstContentfulPaint;
  row.lcpObserved = m.observedLargestContentfulPaint; row.lastFrame = m.observedLastVisualChange;
  fs.appendFileSync(path.join(out, 'runs.jsonl'), JSON.stringify(row) + '\n');
  console.log(JSON.stringify(row));
} finally {
  await chrome.kill();
}
