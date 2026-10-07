/* Lane LH -- re-score a filmed run as if the film had stopped where PSI's does.
 *
 *   LH_DIR=/path node tools/lh-si-cut.mjs <label-prefix> <profile> [n=5]
 *
 * Lighthouse ends the filmed run once the page has been quiet -- load fired,
 * no request in flight and no long task -- for 1 s (PSI's 'simulate' settings).
 * On this container six lanes share four cores, long tasks never stop, and the
 * film runs on to 10-19 s; PSI's stops about a second after the network does.
 * A slide change at 7 s is in one film and not the other, and that is exactly
 * the question this lane has to answer, so each run is scored twice with the
 * same speedline call Lighthouse makes: on the whole film (must match the LHR's
 * observedSpeedIndex -- printed as a check) and cut at
 * max(load, last response) + 1,000 ms. */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath, pathToFileURL } from 'node:url';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const req = createRequire(path.join(process.env.LH_DIR, 'package.json'));
const speedline = (await import(pathToFileURL(req.resolve('speedline-core')).href)).default;
const [prefix, profile, n = 5] = process.argv.slice(2);
const dir = path.join(APP, 'storage/lh-logs/lh');
const med = (xs) => { const s = [...xs].sort((a, b) => a - b); return s[(s.length - 1) >> 1]; };

const rows = [];
for (let i = 1; i <= Number(n); i++) {
  const base = path.join(dir, `${prefix}-${i}-${profile}`);
  if (!fs.existsSync(base + '.frames.json')) continue;
  const lhr = JSON.parse(fs.readFileSync(base + '.json', 'utf8'));
  const ev = JSON.parse(fs.readFileSync(base + '.frames.json', 'utf8'));
  const nav = ev.filter((e) => e.name === 'navigationStart' && e.args?.data?.isLoadingMainFrame && /^http/.test(e.args.data.documentLoaderURL ?? ''));
  const t0 = nav[nav.length - 1].ts;
  const m = lhr.audits.metrics.details.items[0];
  const lastNet = Math.max(...lhr.audits['network-requests'].details.items.filter((r) => !/^data:/.test(r.url)).map((r) => r.networkEndTime));
  const cut = Math.max(m.observedLoad, lastNet) + 1000;
  const score = async (events) => (await speedline(events, { timeOrigin: t0, fastMode: true, include: 'speedIndex' })).speedIndex;
  const full = await score(ev);
  const cutSi = await score(ev.filter((e) => e.name !== 'Screenshot' || e.ts <= t0 + cut * 1000));
  rows.push({ i, lhrObserved: Math.round(m.observedSpeedIndex), full: Math.round(full), cutAt: Math.round(cut), cut: Math.round(cutSi),
    fcp: Math.round(m.observedFirstContentfulPaint), lcp: Math.round(m.observedLargestContentfulPaint), load: Math.round(m.observedLoad) });
}
for (const r of rows) console.log(JSON.stringify(r));
const o = { prefix, profile, runs: rows.length, cutSiMedian: med(rows.map((r) => r.cut)), fullSiMedian: med(rows.map((r) => r.full)),
  cutAtMedian: med(rows.map((r) => r.cutAt)), lcpMedian: med(rows.map((r) => r.lcp)) };
console.log('MEDIAN ' + JSON.stringify(o));
fs.appendFileSync(path.join(dir, 'si-cut.jsonl'), JSON.stringify(o) + '\n');
