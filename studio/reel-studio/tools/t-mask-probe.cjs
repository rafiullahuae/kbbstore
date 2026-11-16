// Translation lane (T): which placeholder survives each engine? For every test sentence that holds a glossary name,
// a number or a code, mask those spans with several placeholder shapes, translate, and count how many come back
// intact. -> tools/out/t/mask-probe.json.
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/t-mask-probe.cjs [--eng=mt-en-ar,mt-m2m100] [--pairs=en-ar,en-ur]
'use strict';
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');
const ROOT = path.resolve(__dirname, '..');
const args = process.argv.slice(2);
const arg = (k, d) => (args.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=')[1];
const ENG = arg('eng', 'mt-en-ar,mt-m2m100').split(',');
const PAIRS = arg('pairs', 'en-ar,en-ur,en-hi').split(',');
const SHAPES = { none: null, ZXA: (i) => 'ZX' + 'ABCDEFGH'[i], brack: (i) => `[${i + 1}]`, XXA: (i) => 'XX' + 'ABCDEFGH'[i], curly: (i) => `{${i + 1}}` };
(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const page = await browser.newPage();
  page.setDefaultTimeout(0);
  await page.goto(`http://127.0.0.1:${server.address().port}/app/captions/translate/dev/index.html`);
  await page.waitForFunction(() => window.TR?.ready);
  const out = {};
  for (const engId of ENG) for (const pair of PAIRS) {
    const [s, t] = pair.split('-');
    const r = await page.evaluate(async ({ engId, s, t, shapes }) => {
      const eng = TR.ENGINES[engId];
      if (!TR.engineCovers(eng, s, t)) return null;
      window.__tr ||= new TR.Translator();
      await window.__tr.load(eng);
      const G = TR.glossary;
      const gl = TR.TEST_GLOSSARY.map((x) => (typeof x === 'string' ? { term: x, keep: true } : x));
      const items = TR.TESTSET.map((x) => x[s]).filter((x) => G.mask(x, gl, t).slots.length);
      const res = {};
      for (const [name, src] of Object.entries(shapes)) {
        const f = src ? new Function('i', 'return ' + src)() : null;
        let ok = 0, n = 0;
        const texts = [], slotsAll = [];
        for (const x of items) {
          const m = G.mask(x, gl, t);
          let txt = m.text;
          const slots = m.slots.map((sl, i) => ({ ...sl, ph2: f ? f(i) : sl.src }));
          if (f) slots.forEach((sl) => { txt = txt.replace(sl.ph, sl.ph2); }); else slots.forEach((sl) => { txt = txt.replace(sl.ph, sl.src); });
          texts.push(txt); slotsAll.push(slots);
        }
        const o = await window.__tr.tr(texts, { ...TR.requestFor(eng, s, t), max: 200 });
        o.texts.forEach((y, k) => { const yy = G.westernDigits(y); for (const sl of slotsAll[k]) { n++; if (yy.includes(f ? sl.ph2 : sl.src)) ok++; } });
        res[name] = { kept: ok, of: n, eg: o.texts[0] };
      }
      return res;
    }, { engId, s, t, shapes: Object.fromEntries(Object.entries(SHAPES).map(([k, v]) => [k, v ? v.toString() : null])) });
    if (r) { out[engId + ' ' + pair] = r; console.log(engId, pair, JSON.stringify(Object.fromEntries(Object.entries(r).map(([k, v]) => [k, `${v.kept}/${v.of}`])))); }
  }
  fs.writeFileSync(path.join(ROOT, 'tools/out/t/mask-probe.json'), JSON.stringify(out, null, 1));
  await browser.close(); server.close();
})();
