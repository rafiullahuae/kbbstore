/* Median of N runs, per field. Median rather than mean because one run in five
 * on this container is an outlier and a mean carries it into the report. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const [label, profile, n] = process.argv.slice(2);
const rows = [];
for (let i = 1; i <= Number(n); i++)
  rows.push(JSON.parse(fs.readFileSync(path.join(APP, `storage/perf-logs/lh/${label}-${i}-${profile}.json`), 'utf8')));
const med = (xs) => { const s = [...xs].sort((a, b) => a - b); return s[(s.length - 1) >> 1]; };
const val = (lhr, id, d = 1000) => (lhr.audits[id].numericValue ?? 0) / d;
const catScore = (lhr, c) => Math.round(lhr.categories[c].score * 100);
const atLiveTbt = (lhr) => {
  const refs = lhr.categories.performance.auditRefs.filter((r) => r.weight > 0);
  const t = refs.reduce((s, r) => s + r.weight, 0);
  return Math.round(100 * refs.reduce((s, r) => s + r.weight * (r.id === 'total-blocking-time' ? 1 : (lhr.audits[r.id].score ?? 0)), 0) / t);
};
const out = {
  label, profile, runs: rows.length,
  performance: med(rows.map((r) => catScore(r, 'performance'))),
  performanceAtLiveTbt: med(rows.map(atLiveTbt)),
  accessibility: med(rows.map((r) => catScore(r, 'accessibility'))),
  bestPractices: med(rows.map((r) => catScore(r, 'best-practices'))),
  seo: med(rows.map((r) => catScore(r, 'seo'))),
  fcp: +med(rows.map((r) => val(r, 'first-contentful-paint'))).toFixed(2),
  lcp: +med(rows.map((r) => val(r, 'largest-contentful-paint'))).toFixed(2),
  tbt: Math.round(med(rows.map((r) => val(r, 'total-blocking-time', 1)))),
  cls: +med(rows.map((r) => val(r, 'cumulative-layout-shift', 1))).toFixed(3),
  si: +med(rows.map((r) => val(r, 'speed-index'))).toFixed(2),
  bytes: Math.round(med(rows.map((r) => val(r, 'total-byte-weight', 1024)))),
};
console.log('MEDIAN ' + JSON.stringify(out));
fs.appendFileSync(path.join(APP, 'storage/perf-logs/lh/medians.jsonl'), JSON.stringify(out) + '\n');
