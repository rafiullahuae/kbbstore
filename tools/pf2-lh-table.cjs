// Lane PF2: medians of the Lighthouse runs tools/pf2-lh.sh wrote.
const fs = require('fs');
const dir = process.argv[2] || 'storage/pf2-logs/lh';
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const rows = {};
for (const f of fs.readdirSync(dir).filter(f => f.endsWith('.json'))) {
  const [side, page, profile] = f.replace(/-\d+\.json$/, '').split('-');
  const j = JSON.parse(fs.readFileSync(dir + '/' + f, 'utf8'));
  const a = j.audits;
  const img = (a['resource-summary']?.details?.items || []).find(i => i.resourceType === 'image');
  const k = `${page} ${profile}`;
  rows[k] ??= {}; rows[k][side] ??= [];
  rows[k][side].push({
    perf: Math.round(j.categories.performance.score * 100),
    lcp: a['largest-contentful-paint'].numericValue,
    cls: a['cumulative-layout-shift'].numericValue,
    tbt: a['total-blocking-time'].numericValue,
    bytes: a['total-byte-weight'].numericValue,
    img: img ? img.transferSize : 0,
    resp: a['uses-responsive-images']?.details?.overallSavingsBytes ?? 0,
    unsized: a['unsized-images']?.details?.items?.length ?? 0,
    lcpEl: a['largest-contentful-paint-element']?.details?.items?.[0]?.items?.[0]?.node?.snippet?.slice(0, 70) ?? '',
  });
}
console.log('| page / profile | side | runs | Perf | LCP ms | CLS | TBT ms | total KiB | image KiB | responsive-images savings KiB | unsized images |');
console.log('|---|---|---|---|---|---|---|---|---|---|---|');
for (const k of Object.keys(rows).sort()) {
  for (const side of ['before', 'after']) {
    const r = rows[k][side]; if (!r) continue;
    const m = (f) => med(r.map(x => x[f]));
    console.log(`| ${k} | ${side} | ${r.length} | ${m('perf')} | ${Math.round(m('lcp'))} | ${m('cls').toFixed(3)} | ${Math.round(m('tbt'))} | ${(m('bytes') / 1024).toFixed(0)} | ${(m('img') / 1024).toFixed(0)} | ${(m('resp') / 1024).toFixed(0)} | ${m('unsized')} |`);
  }
}
for (const k of Object.keys(rows).sort()) for (const side of ['before', 'after']) if (rows[k][side]) console.log(`LCP element ${k} ${side}: ${[...new Set(rows[k][side].map(x => x.lcpEl))].join(' || ')}`);
