/* Lane SPD: server ms, queries and settings-map reads per page, from the
 * preview's measuring front controller (tools/spd-index.php).
 *   node tools/spd-server.cjs <label> <baseUrl> <previewDir> [n=9]
 * Median of n warm requests; settings reads from the same request's line in
 * <previewDir>/spd-after.log (top_keys['kbb.settings.map']). */
const fs = require('fs');
const path = require('path');
const [label, base, dir, n = '9'] = process.argv.slice(2);
const urls = JSON.parse(process.env.SPD_URLS || '{}');
const pages = Object.assign({ home: '/' }, urls);
(async () => {
  const log = path.join(dir, 'spd-after.log');
  for (const [name, p] of Object.entries(pages)) {
    await fetch(base + p); // warm
    const ms = []; let q = 0; let reads = 0;
    for (let i = 0; i < +n; i++) {
      const before = fs.existsSync(log) ? fs.statSync(log).size : 0;
      const r = await fetch(base + p); await r.text();
      ms.push(+r.headers.get('x-spd-ms')); q = +r.headers.get('x-spd-q');
      const tail = fs.readFileSync(log, 'utf8').slice(before).trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)).find((j) => j.uri === p);
      if (tail) reads = tail.top_keys?.['kbb.settings.map'] ?? 0;
    }
    ms.sort((a, b) => a - b);
    console.log(JSON.stringify({ label, page: name, ms: ms[(ms.length - 1) >> 1], queries: q, settingsReads: reads }));
  }
})();
