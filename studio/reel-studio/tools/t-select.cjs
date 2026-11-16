// Translation lane (T) — does "two engines + pick by back-translation" beat the best single engine? For a pair where two
// candidates exist (cached outputs in tools/out/t/eval/), back-translate both with the reverse engine and keep, per
// sentence, the one whose back-translation is closer (chrF) to the source. No reference is used to choose, so this
// is what the studio could do at run time. -> tools/out/t/select.json
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/t-select.cjs [--pairs=en-ar,en-hi]
'use strict';
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');
const ROOT = path.resolve(__dirname, '..'), EV = path.join(ROOT, 'tools/out/t/eval');
const args = process.argv.slice(2);
const PAIRS = ((args.find((a) => a.startsWith('--pairs=')) || '--pairs=en-ar,en-hi,ar-en,hi-en').slice(8)).split(',');
const BACK = { 'en-ar': 'mt-ar-en', 'en-hi': 'mt-hi-en', 'ar-en': 'mt-en-ar', 'hi-en': 'mt-en-hi', 'en-ur': 'mt-mul-en' };
(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const page = await browser.newPage(); page.setDefaultTimeout(0);
  await page.goto(`http://127.0.0.1:${server.address().port}/app/captions/translate/dev/index.html`);
  await page.waitForFunction(() => window.TR?.ready);
  const cands = fs.readdirSync(EV).filter((f) => /^(opus|m2m100)-mask\.json$/.test(f)).map((f) => JSON.parse(fs.readFileSync(path.join(EV, f))));
  const out = {};
  for (const pair of PAIRS) {
    const [s, t] = pair.split('-');
    const have = cands.filter((c) => c.dirs[pair]?.out?.length === 60);
    if (have.length < 2) continue;
    const r = await page.evaluate(async ({ s, t, back, outs }) => {
      const tr = new TR.Translator(); await tr.load(TR.ENGINES[back]);
      const src = TR.TESTSET.map((x) => x[s]), ref = TR.TESTSET.map((x) => x[t]);
      const bts = [];
      for (const o of outs) { const b = await tr.tr(o.out.map((x) => TR.glossary.westernDigits(x)), { ...TR.requestFor(TR.ENGINES[back], t, s), max: 200 }); bts.push(b.texts); }
      tr.dispose();
      const pick = src.map((x, i) => { let best = 0, bs = -1; outs.forEach((o, k) => { const sc = TR.chrf(bts[k][i], x); if (sc > bs) { bs = sc; best = k; } }); return best; });
      const sel = pick.map((k, i) => outs[k].out[i]);
      const oracle = src.map((x, i) => { let best = 0, bs = -1; outs.forEach((o, k) => { const sc = TR.chrf(o.out[i], ref[i]); if (sc > bs) { bs = sc; best = k; } }); return outs[best].out[i]; });
      // how well back-translation chrF predicts the reference chrF (per sentence, all candidates)
      const xs = [], ys = [];
      outs.forEach((o, k) => o.out.forEach((y, i) => { xs.push(TR.chrf(bts[k][i], src[i])); ys.push(TR.chrf(y, ref[i])); }));
      const mean = (a) => a.reduce((p, q) => p + q, 0) / a.length, mx = mean(xs), my = mean(ys);
      const corr = xs.reduce((p, x, i) => p + (x - mx) * (ys[i] - my), 0) / Math.sqrt(xs.reduce((p, x) => p + (x - mx) ** 2, 0) * ys.reduce((p, y) => p + (y - my) ** 2, 0));
      const bad = ys.map((y, i) => [xs[i], y]).filter(([, y]) => y < 40), good = ys.map((y, i) => [xs[i], y]).filter(([, y]) => y >= 60);
      return { single: outs.map((o) => [o.name, TR.corpusChrf(o.out, ref)]), selected: TR.corpusChrf(sel, ref), oracle: TR.corpusChrf(oracle, ref), picks: outs.map((o, k) => pick.filter((p) => p === k).length), corr,
        btBad: bad.length ? mean(bad.map((x) => x[0])) : null, btGood: good.length ? mean(good.map((x) => x[0])) : null };
    }, { s, t, back: BACK[pair], outs: have.map((c) => ({ name: c.cand, out: c.dirs[pair].out })) });
    out[pair] = r;
    console.log(pair, JSON.stringify(r));
  }
  fs.writeFileSync(path.join(ROOT, 'tools/out/t/select.json'), JSON.stringify(out, null, 1));
  await browser.close(); server.close();
})();
