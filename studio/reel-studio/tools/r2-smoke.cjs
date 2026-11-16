// Lane R2: quick one-off smoke (JFK sample, one model). node tools/r2-smoke.cjs [base|small]
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');
(async () => {
  await new Promise((r) => server.listening ? r() : server.once('listening', r));
  const url = `http://127.0.0.1:${server.address().port}/app/captions/dev/index.html`;
  const b = await chromium.launch();
  const p = await b.newPage();
  p.on('console', (m) => console.log('[console]', m.type(), m.text().slice(0, 300)));
  p.on('pageerror', (e) => console.log('[pageerror]', e.message));
  p.on('requestfailed', (r) => console.log('[reqfail]', r.url()));
  p.on('response', (r) => { if (r.status() >= 400) console.log('[http]', r.status(), r.url()); });
  await p.goto(url);
  await p.waitForFunction(() => window.R2);
  const r = await p.evaluate(async ([model, file, from, to]) => {
    let a = await R2.loadAudio('/models/_r2-test/' + file);
    a = a.slice(from * 16000, to * 16000);
    const res = await R2.transcribe(a, { model, lang: 'auto' });
    return { ...res, text: res.words.map((w) => w.t0.toFixed(1) + ':' + w.w).join(' '), words: res.words.slice(0, 3), coi: crossOriginIsolated };
  }, [process.argv[2] || 'base', process.argv[3] || 'jfk.wav', +(process.argv[4] || 0), +(process.argv[5] || 9999)]);
  console.log(JSON.stringify(r, null, 1));
  await b.close(); server.close();
})().catch((e) => { console.error(e); process.exit(1); });
