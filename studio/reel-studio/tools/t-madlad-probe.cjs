// Translation lane (T): can MADLAD-400 3B (int8 ONNX, Kutalia/madlad400-3b-mt-onnx) run in the studio's browser
// runtime (transformers.js 3.8.1, WASM)? Loads it in headless Chromium and translates 3 test sentences, or reports
// the error. Result -> tools/out/t/madlad-probe.json.   NODE_PATH=/home/user/kbbstore/node_modules node tools/t-madlad-probe.cjs
'use strict';
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');
const ROOT = path.resolve(__dirname, '..');
(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const page = await browser.newPage();
  const logs = [];
  page.on('console', (m) => logs.push(m.type() + ': ' + m.text()));
  page.on('pageerror', (e) => logs.push('pageerror: ' + e.message));
  page.on('crash', () => logs.push('PAGE CRASHED'));
  page.setDefaultTimeout(0);
  await page.goto(base + '/app/captions/translate/dev/index.html');
  await page.waitForFunction(() => window.TR?.ready);
  const t0 = Date.now();
  const r = await page.evaluate(async () => {
    const tr = new TR.Translator();
    try {
      const l = await tr.load(TR.ENGINES['mt-madlad']);
      const s = TR.TESTSET.slice(0, 3).map((x) => x.en);
      const o = await tr.tr(s, { ...TR.requestFor(TR.ENGINES['mt-madlad'], 'en', 'ar'), max: 120 });
      return { ok: true, loadMs: l.ms, out: o.texts, ms: o.ms };
    } catch (e) { return { ok: false, error: String(e.message || e) }; }
  }).catch((e) => ({ ok: false, error: 'page: ' + e.message }));
  r.wallMs = Date.now() - t0; r.logs = logs.filter((x) => !/^(log|info|debug)/.test(x)).slice(0, 20);
  fs.writeFileSync(path.join(ROOT, 'tools/out/t/madlad-probe.json'), JSON.stringify(r, null, 1));
  console.log(JSON.stringify(r, null, 1));
  await browser.close(); server.close();
})();
