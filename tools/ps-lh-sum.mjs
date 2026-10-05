/* Lane PS — print the audits that failed in one Lighthouse JSON (ps-lh.mjs output). */
import fs from 'node:fs';
const lhr = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const want = process.argv[3] ? process.argv[3].split(',') : null;
for (const [id, a] of Object.entries(lhr.audits)) {
  if (want && !want.includes(id)) continue;
  if (!want && (a.score === null || a.score >= 0.9) && a.scoreDisplayMode !== 'informative') continue;
  if (!want && a.scoreDisplayMode === 'notApplicable') continue;
  console.log(`## ${id} score=${a.score} ${a.displayValue ?? ''}`);
  const items = a.details?.items ?? [];
  for (const it of items.slice(0, 12)) {
    const s = JSON.stringify(it, (k, v) => (k === 'boundingRect' || k === 'lhId' || k === 'path' || k === 'subItems' ? undefined : v));
    console.log('   ' + s.slice(0, 400));
  }
}
