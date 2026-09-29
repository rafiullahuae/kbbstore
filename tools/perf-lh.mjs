/* Lane PERF — the instrument. One Lighthouse run against the local preview,
 * on the profile PageSpeed Insights uses, written out as JSON + HTML.
 *
 *   node tools/perf-lh.mjs <label> <mobile|desktop> [url]
 *
 * Everything lands in storage/perf-logs/lh/<label>-<profile>.{json,html}, which
 * is INSIDE THIS WORKTREE — CLAUDE.md's scratchpad landmine: the session
 * scratchpad is shared between lanes and a generic filename there is how one
 * lane comes to report another's numbers.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import lighthouse from 'lighthouse';
import * as chromeLauncher from 'chrome-launcher';
import desktopConfig from 'lighthouse/core/config/desktop-config.js';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const [label, profile = 'mobile', url = 'http://127.0.0.1:8991/'] = process.argv.slice(2);
if (!label) { console.error('usage: perf-lh.mjs <label> [mobile|desktop] [url]'); process.exit(2); }

const out = path.join(APP, 'storage/perf-logs/lh');
fs.mkdirSync(out, { recursive: true });

const chrome = await chromeLauncher.launch({
  chromePath: process.env.CHROME_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    /* --ignore-certificate-errors is what lets fonts.googleapis.com resolve at
     all from this container: outbound HTTPS goes through an agent proxy whose
     CA this Chromium does not carry, and without the flag the font request
     dies with ERR_CERT_AUTHORITY_INVALID -- i.e. the 3rd-party font chain that
     is the single biggest thing in the owner's report would not be present in
     the baseline, and "removing it" would measure nothing. */
  chromeFlags: ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
    '--ignore-certificate-errors'],
});

try {
  /* CPU THROTTLING IS SET TO WHAT PSI ACTUALLY MEASURED WITH, not to
     Lighthouse's default 4x. The owner's report names its own calibration in
     the run header: "Unthrottled CPU/Memory Power: 745 · CPU throttling: 1.2x
     slowdown (Simulated)" on mobile and "1x" on desktop. Left at 4x on this
     container -- which runs six other lanes -- TBT came back 3,320 ms against
     the 0 ms PSI recorded, and a metric worth 30 points of the score would
     then be measuring how busy this machine is rather than anything in the
     shop. Network throttling is left exactly as Lighthouse ships it, because
     that is what PSI uses: 150 ms RTT / 1,638.4 kb/s on mobile. */
  const base = profile === 'desktop' ? desktopConfig : undefined;
  const config = {
    ...(base ?? { extends: 'lighthouse:default' }),
    settings: {
      ...(base?.settings ?? {}),
      throttling: {
        ...(base?.settings?.throttling ?? {
          rttMs: 150, throughputKbps: 1638.4, requestLatencyMs: 150 * 4,
          downloadThroughputKbps: 1638.4, uploadThroughputKbps: 675,
        }),
        cpuSlowdownMultiplier: profile === 'desktop' ? 1 : 1.2,
      },
    },
  };
  const options = { logLevel: 'error', output: ['json', 'html'], port: chrome.port };
  const runner = await lighthouse(url, options, config);
  const lhr = runner.lhr;

  fs.writeFileSync(path.join(out, `${label}-${profile}.json`), runner.report[0]);
  fs.writeFileSync(path.join(out, `${label}-${profile}.html`), runner.report[1]);

  const n = (id) => lhr.audits[id]?.numericValue;

  /* ── THE SCORE THIS CONTAINER CANNOT MEASURE, AND WHY IT IS RECOMPUTED ────
   *
   * PageSpeed Insights measured TBT = 0 ms on both profiles. This container
   * runs six other lanes and has no GPU, so the same page here spends 8.6 s in
   * "Other" and 2.9 s in "Rendering" where the owner's run spent 1.3 s and
   * 0.4 s, and TBT comes back between 1.5 and 2.5 s from one run to the next.
   * TBT is 30% of the performance score, so left alone it would swamp every
   * before/after in this lane with the load average of the machine.
   *
   * Nothing this lane changes runs JavaScript, so the honest thing is to say
   * both numbers: `performance` is what this instrument actually scored, and
   * `performanceAtLiveTbt` is the same category recomputed from Lighthouse's
   * OWN per-audit scores with the TBT audit held at the 1.0 the owner's shop
   * already earns. The second is the one comparable with the PSI report; the
   * first is the one that was measured. Neither is adjusted in any other way.
   */
  const refs = lhr.categories.performance.auditRefs.filter((r) => r.weight > 0);
  const total = refs.reduce((s, r) => s + r.weight, 0);
  const atLiveTbt = refs.reduce(
    (s, r) => s + r.weight * (r.id === 'total-blocking-time' ? 1 : (lhr.audits[r.id].score ?? 0)), 0) / total;
  const row = {
    label, profile,
    performance: Math.round(lhr.categories.performance.score * 100),
    performanceAtLiveTbt: Math.round(atLiveTbt * 100),
    accessibility: Math.round(lhr.categories.accessibility.score * 100),
    bestPractices: Math.round(lhr.categories['best-practices'].score * 100),
    seo: Math.round(lhr.categories.seo.score * 100),
    fcp: +(n('first-contentful-paint') / 1000).toFixed(2),
    lcp: +(n('largest-contentful-paint') / 1000).toFixed(2),
    tbt: Math.round(n('total-blocking-time')),
    cls: +(n('cumulative-layout-shift') ?? 0).toFixed(3),
    si: +(n('speed-index') / 1000).toFixed(2),
  };
  console.log(JSON.stringify(row));
  fs.appendFileSync(path.join(out, 'runs.jsonl'), JSON.stringify(row) + '\n');
} finally {
  await chrome.kill();
}
