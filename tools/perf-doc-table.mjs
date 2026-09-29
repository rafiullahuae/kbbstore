/* Lane PERF — write the measured before/after table into docs/PERF-PAGESPEED.md.
 *
 * The numbers in a report have to come from the run rather than from a typist,
 * which is the whole reason this exists: a table transcribed by hand is a table
 * nobody can re-derive. It replaces the <!-- PERF-AB-TABLE --> marker in place.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LOG = path.join(APP, 'storage/perf-logs/lh/medians.jsonl');
const DOC = path.join(APP, 'docs/PERF-PAGESPEED.md');

const rows = fs.readFileSync(LOG, 'utf8').trim().split('\n').map((l) => JSON.parse(l));
const last = (label, profile) => [...rows].reverse().find((r) => r.label === label && r.profile === profile);

const cell = (b, a, key, unit = '', better = 'down') => {
  const bv = b[key];
  const av = a[key];
  const same = bv === av;
  const good = better === 'down' ? av < bv : av > bv;
  const mark = same ? '' : good ? ' ✅' : ' ⚠️';
  return `| ${bv}${unit} | ${av}${unit}${mark} |`;
};

const table = (profile) => {
  const b = last('AB-before', profile);
  const a = last('AB-after', profile);
  if (!b || !a) return `_(no ${profile} run recorded)_`;

  return `**${profile === 'mobile' ? 'Mobile' : 'Desktop'}** — ${b.runs} interleaved pairs, median

| | before | after |
|---|---|---|
| **Performance** (measured) ${cell(b, a, 'performance', '', 'up')}
| **Performance** (with TBT at your shop's 0 ms) ${cell(b, a, 'performanceAtLiveTbt', '', 'up')}
| Accessibility ${cell(b, a, 'accessibility', '', 'up')}
| Best Practices ${cell(b, a, 'bestPractices', '', 'up')}
| SEO ${cell(b, a, 'seo', '', 'up')}
| First Contentful Paint ${cell(b, a, 'fcp', ' s')}
| Largest Contentful Paint ${cell(b, a, 'lcp', ' s')}
| Speed Index ${cell(b, a, 'si', ' s')}
| Cumulative Layout Shift ${cell(b, a, 'cls')}
| Total Blocking Time (this machine, see below) ${cell(b, a, 'tbt', ' ms')}
| Page weight ${cell(b, a, 'bytes', ' KiB')}`;
};

let doc = fs.readFileSync(DOC, 'utf8');
doc = doc.replace('<!-- PERF-AB-TABLE -->', `${table('mobile')}\n\n${table('desktop')}`);
fs.writeFileSync(DOC, doc);
console.log('written');
console.log(table('mobile'));
console.log();
console.log(table('desktop'));
