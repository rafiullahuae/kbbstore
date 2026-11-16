// Translation lane (T) — model evaluation in headless Chromium with the studio's own runtime (transformers.js 3.8.1,
// WASM), on the 60-sentence ad test set (app/captions/translate/testset.js), EN<->AR, EN<->UR, EN<->HI.
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/t-eval.cjs [--cand=opus,m2m100,qwen,madlad] [--n=60] [--mask]
// Outputs are cached per candidate in tools/out/t/eval/<cand>[-mask].json (resumable); the score table goes to
// tools/out/t/eval-table.md and eval.json. Models must be in models/ (bash tools/t-fetch-models.sh all; madlad).
'use strict';
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'tools/out/t'), EV = path.join(OUT, 'eval');
fs.mkdirSync(EV, { recursive: true });
const args = process.argv.slice(2);
const arg = (k, d) => (args.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=')[1];
const CANDS = arg('cand', 'opus,m2m100,qwen').split(',').filter(Boolean);
const N = +arg('n', 60);
const MASK = args.includes('--mask');
const DIRS = [['en', 'ar'], ['ar', 'en'], ['en', 'ur'], ['ur', 'en'], ['en', 'hi'], ['hi', 'en']];

// candidate -> engine per direction (null = this candidate does not cover it)
const PLAN = {
  opus: { 'en-ar': 'mt-en-ar', 'ar-en': 'mt-ar-en', 'en-hi': 'mt-en-hi', 'hi-en': 'mt-hi-en', 'en-ur': 'mt-en-mul', 'ur-en': 'mt-mul-en' },
  'opus-mul': { 'en-ar': 'mt-en-mul', 'ar-en': 'mt-mul-en', 'en-hi': 'mt-en-mul', 'hi-en': 'mt-mul-en' },
  m2m100: Object.fromEntries(DIRS.map(([a, b]) => [a + '-' + b, 'mt-m2m100'])),
  qwen: Object.fromEntries(DIRS.map(([a, b]) => [a + '-' + b, 'mt-qwen'])),
  qwen05: Object.fromEntries(DIRS.map(([a, b]) => [a + '-' + b, 'mt-qwen05'])),
  madlad: Object.fromEntries(DIRS.map(([a, b]) => [a + '-' + b, 'mt-madlad'])),
};

(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined,
    args: ['--js-flags=--max-old-space-size=8192'] });
  const page = await browser.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.setDefaultTimeout(0);
  await page.goto(base + '/app/captions/translate/dev/index.html');
  await page.waitForFunction(() => window.TR?.ready);
  const test = await page.evaluate(() => TR.TESTSET);

  for (const cand of CANDS) {
    const file = path.join(EV, `${cand}${MASK ? '-mask' : ''}.json`);
    const res = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file)) : { cand, mask: MASK, dirs: {} };
    for (const [s, t] of DIRS) {
      const dk = s + '-' + t, engId = PLAN[cand]?.[dk];
      if (!engId) continue;
      const D = res.dirs[dk] ||= { engine: engId, out: [], ms: 0 };
      if (D.out.length >= N) continue;
      console.log(`${cand} ${dk}: loading ${engId}`);
      const loadMs = await page.evaluate(async ({ engId }) => {
        window.__tr ||= new TR.Translator();
        try { const r = await window.__tr.load(TR.ENGINES[engId]); return r.ms; } catch (e) { return 'ERR ' + e.message; }
      }, { engId });
      if (typeof loadMs === 'string') { console.log('  ' + loadMs); D.error = loadMs; break; }
      if (loadMs) D.loadMs = loadMs;
      for (let i = D.out.length; i < N; i += 5) {
        const chunk = test.slice(i, Math.min(N, i + 5)).map((x) => x[s]);
        const r = await page.evaluate(async ({ engId, chunk, s, t, mask }) => {
          const eng = TR.ENGINES[engId];
          try {
            if (mask && TR.pipeline) return await TR.pipeline.translateSentences(window.__tr, eng, chunk, s, t, { glossary: TR.TEST_GLOSSARY });
            if (eng.kind === 'llm') {
              const r = await window.__tr.chat(chunk.map((x) => TR.llmMessages(x, s, t, TR.TEST_GLOSSARY.filter((g) => typeof g === 'string'))), { max: 160 });
              return { texts: r.texts.map(TR.cleanLlm), ms: r.ms };
            }
            return await window.__tr.tr(chunk, { ...TR.requestFor(eng, s, t), max: 200 });
          } catch (e) { return { error: e.message }; }
        }, { engId, chunk, s, t, mask: MASK });
        if (r.error) { console.log('  ERR ' + r.error); D.error = r.error; break; }
        D.out.push(...r.texts); D.ms += r.ms;
        fs.writeFileSync(file, JSON.stringify(res, null, 1));
        console.log(`  ${dk} ${D.out.length}/${N}  ${(D.ms / D.out.length / 1000).toFixed(2)} s/sentence  e.g. ${r.texts[0]}`);
      }
    }
    await page.evaluate(() => window.__tr?.dispose());
  }

  // ---- scores
  const M = await import(path.join(ROOT, 'app/captions/translate/metrics.js'));
  const table = {};
  for (const f of fs.readdirSync(EV).filter((x) => x.endsWith('.json'))) {
    const r = JSON.parse(fs.readFileSync(path.join(EV, f)));
    const name = f.replace(/\.json$/, '');
    for (const [dk, D] of Object.entries(r.dirs)) {
      const [s, t] = dk.split('-'), n = D.out.length;
      if (!n) continue;
      const refs = test.slice(0, n).map((x) => x[t]);
      (table[dk] ||= {})[name] = { engine: D.engine, n, chrf: +M.corpusChrf(D.out, refs).toFixed(1), bleu: +M.corpusBleu(D.out, refs).toFixed(1),
        sPerSent: +(D.ms / n / 1000).toFixed(2), error: D.error || undefined };
    }
  }
  fs.writeFileSync(path.join(OUT, 'eval.json'), JSON.stringify(table, null, 1));
  const names = [...new Set(Object.values(table).flatMap((x) => Object.keys(x)))].sort();
  let md = `| pair | ${names.join(' | ')} |\n|---|${names.map(() => '---').join('|')}|\n`;
  for (const [s, t] of DIRS) {
    const dk = s + '-' + t, row = table[dk] || {};
    md += `| ${dk} | ${names.map((n) => row[n] ? `chrF ${row[n].chrf} · BLEU ${row[n].bleu} · ${row[n].sPerSent}s${row[n].n < 60 ? ` (n=${row[n].n})` : ''}` : '—').join(' | ')} |\n`;
  }
  fs.writeFileSync(path.join(OUT, 'eval-table.md'), md);
  console.log(md);
  if (errors.length) console.log('console errors:', errors.slice(0, 5).join(' | '));
  await browser.close(); server.close();
})().catch((e) => { console.error(e); process.exit(2); });
