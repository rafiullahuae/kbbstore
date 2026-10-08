/* Lane PO: the Place-order timeline, split into segments, median of the runs.
     node tools/po-walk/segments.mjs file1.json file2.json ...            */
import fs from 'fs';
const runs = process.argv.slice(2).map((f) => JSON.parse(fs.readFileSync(f, 'utf8')));
const med = (a) => { const s = a.filter((x) => x != null && !Number.isNaN(x)).sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : null; };
const rows = runs.map((r) => {
  const m = (n) => (r.marks.find((x) => x[1] === n) || [])[0];
  const req = (u) => r.requests.find((x) => x.url.endsWith(u));
  const place = req('/checkout/place'); const paid = req('/checkout/card/paid'); const ty = req('/checkout/success');
  const done = (r.marks.find((x) => x[1] === 'box-class' && /is-done/.test(x[2])) || [])[0];
  return {
    'client validation (press -> POST sent)': place?.start,
    'place(): server ms, ours': place ? Math.round(place.serverMs - place.stripeMs - place.mailMs) : null,
    'place(): Stripe API inside it (not ours)': place?.stripeMs,
    'place(): mail inside it': place?.mailMs,
    'place(): round trip in browser': place ? place.end - place.start : null,
    'Stripe.js confirm (not ours)': m('stripe-confirm-start') != null ? m('stripe-confirm-end') - m('stripe-confirm-start') : null,
    'card/paid round trip in browser': paid ? paid.end - paid.start : null,
    'card/paid: server ms, ours': paid ? Math.round(paid.serverMs - paid.stripeMs - paid.mailMs) : null,
    'card/paid: Stripe API (not ours)': paid?.stripeMs ?? null,
    'card/paid: mail inside it': paid?.mailMs ?? null,
    'tick drawn -> thank-you requested': done != null && r.thankYou ? r.thankYou.navStart - done : null,
    'thank-you: server ms, ours': ty ? Math.round(ty.serverMs - ty.stripeMs - ty.mailMs) : null,
    'thank-you: Stripe API (not ours)': ty?.stripeMs ?? null,
    'thank-you: request -> first paint': r.thankYou ? r.thankYou.fcp - r.thankYou.navStart : null,
    'tick on screen (drawn -> thank-you paint)': done != null && r.thankYou ? r.thankYou.fcp - done : null,
    'TOTAL press -> thank-you painted': r.thankYou?.fcp ?? null,
    'box opened / removed': `${r.boxOpenedTimes}/${r.boxRemovedTimes}`,
  };
});
const keys = Object.keys(rows[0]);
for (const k of keys) {
  const vals = rows.map((r) => r[k]);
  const out = typeof vals[0] === 'string' ? [...new Set(vals)].join(',') : med(vals);
  console.log(`${k.padEnd(44)} ${out ?? '-'}`);
}
