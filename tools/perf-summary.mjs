/* Lane PERF — read one Lighthouse JSON and print the audits this lane is
 * working, with the numbers that decide them. */
import fs from 'node:fs';
const f = process.argv[2];
const lhr = JSON.parse(fs.readFileSync(f, 'utf8'));
const a = lhr.audits;
const g = (id) => a[id];
console.log(`== ${f}`);
console.log('scores', Object.fromEntries(Object.entries(lhr.categories).map(([k, v]) => [k, Math.round(v.score * 100)])));
for (const id of ['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index'])
  console.log(` ${id.padEnd(28)} ${g(id)?.displayValue}`);
console.log('--- opportunities / diagnostics');
for (const id of ['render-blocking-resources', 'uses-responsive-images', 'modern-image-formats', 'unused-css-rules',
  'unused-javascript', 'unminified-css', 'unminified-javascript', 'uses-long-cache-ttl', 'uses-text-compression',
  'lcp-lazy-loaded', 'prioritize-lcp-image', 'font-display', 'critical-request-chains', 'legacy-javascript',
  'total-byte-weight', 'server-response-time', 'network-dependency-tree-insight', 'lcp-discovery-insight',
  'render-blocking-insight', 'image-delivery-insight', 'lcp-breakdown-insight', 'cache-insight', 'document-latency-insight']) {
  const x = g(id); if (!x) continue;
  const ms = x.details?.overallSavingsMs ?? x.numericValue;
  console.log(` ${id.padEnd(34)} score=${x.score} ${x.displayValue ?? ''} ${ms !== undefined ? '(' + Math.round(ms) + ')' : ''}`);
}
console.log('--- failing a11y/bp/seo');
for (const [id, x] of Object.entries(a)) {
  if (x.score !== null && x.score < 1 && x.scoreDisplayMode === 'binary' || (x.score !== null && x.score < 1 && x.scoreDisplayMode === 'numeric')) {
    const cats = Object.entries(lhr.categories).filter(([, c]) => c.auditRefs.some(r => r.id === id && r.weight > 0)).map(([k]) => k);
    if (cats.some(c => c !== 'performance')) console.log(` ${id} [${cats}] ${x.title}`);
  }
}
const items = (id) => (g(id)?.details?.items ?? []);
console.log('--- render-blocking items');
for (const i of items('render-blocking-resources')) console.log('  ', i.url, i.totalBytes, i.wastedMs);
console.log('--- LCP element');
console.log('  ', JSON.stringify(g('largest-contentful-paint-element')?.details?.items?.[0]?.items?.[0]?.node?.snippet ?? '').slice(0, 220));
console.log('--- lcp breakdown');
for (const i of items('lcp-breakdown-insight')) console.log('  ', i.subpart ?? i.label, i.duration);
console.log('--- image delivery');
for (const i of items('image-delivery-insight')) console.log('  ', (i.url||'').slice(-60), i.totalBytes, i.wastedBytes);
console.log('--- console errors');
for (const i of items('errors-in-console')) console.log('  ', i.source, (i.description||'').slice(0,120), i.sourceLocation?.url ?? '');
