/* Lane SPD: the first requests of saved Lighthouse runs, side by side.
 *   node tools/spd-lh-net.cjs <label-profile> [...]   (storage/spd-logs/lh/<x>.json) */
const path = require('path');
for (const name of process.argv.slice(2)) {
  const l = require(path.resolve(__dirname, '../storage/spd-logs/lh', name + '.json'));
  console.log('== ' + name, 'FCPobs', l.audits.metrics.details.items[0].observedFirstContentfulPaint);
  for (const r of l.audits['network-requests'].details.items.slice(0, +(process.env.N || 14))) {
    console.log(Math.round(r.networkRequestTime), Math.round(r.networkEndTime), (r.transferSize / 1024).toFixed(1) + 'K',
      r.resourceType, r.priority, r.url.replace(/^https?:\/\/[^/]+/, '').slice(0, 90));
  }
}
