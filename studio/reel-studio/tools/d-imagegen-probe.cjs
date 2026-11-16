// KBB Studio · Designs · a quick probe of the offline image model (SDXS) on WASM in headless Chrome.
// node tools/d-imagegen-probe.cjs [latent=16]     (needs models/creador/sdxs-512-dreamshaper-onnx/ in this folder)
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const { ROOT, makeStudio, startEngine, stopEngine, launchChrome } = require('./studio-harness.cjs');
const REPO = 'creador/sdxs-512-dreamshaper-onnx';
(async () => {
  const L = +(process.argv[2] || 16);
  const dir = makeStudio();
  fs.mkdirSync(path.join(dir, 'models', 'creador'), { recursive: true });
  execFileSync('cp', ['-al', path.join(ROOT, 'models', REPO), path.join(dir, 'models', REPO)]);
  const e = await startEngine(dir);
  const b = await launchChrome();
  const page = await b.newPage();
  page.on('console', (m) => console.log('[page]', m.text()));
  await page.goto(e.url + '/app/');
  const r = await page.evaluate(async (L) => {
    const w = new Worker('/app/designs/imagegen/worker.js', { type: 'module' });
    let seq = 0; const pend = new Map(); const imgs = [];
    w.onmessage = (ev) => { const d = ev.data; if (d.progress) return; if (d.image) { imgs.push(d.image); return; } const p = pend.get(d.id); pend.delete(d.id); d.error ? p.rej(new Error(d.error)) : p.res(d.result); };
    const call = (msg) => new Promise((res, rej) => { const id = ++seq; pend.set(id, { res, rej }); w.postMessage({ id, ...msg }); });
    const t0 = performance.now();
    try {
      await call({ op: 'load', device: 'wasm', base: '/models/creador/sdxs-512-dreamshaper-onnx/', repo: 'creador/sdxs-512-dreamshaper-onnx', models: '/models/', vendor: '/vendor/transformers/' });
      const tl = performance.now() - t0;
      await call({ op: 'gen', prompt: 'soft pink silk fabric with rose petals, luxury beauty background, studio light, no text', seeds: [7], latent: L });
      const im = imgs[0];
      const px = new Uint8ClampedArray(im.data); let s = 0, s2 = 0; for (let i = 0; i < px.length; i += 4) { const y = px[i] * 0.3 + px[i + 1] * 0.59 + px[i + 2] * 0.11; s += y; s2 += y * y; }
      const n = px.length / 4, mean = s / n, sd = Math.sqrt(s2 / n - mean * mean);
      const c = document.createElement('canvas'); c.width = im.w; c.height = im.h; c.getContext('2d').putImageData(new ImageData(px, im.w, im.h), 0, 0);
      return { ok: true, loadMs: Math.round(tl), genMs: im.ms, w: im.w, h: im.h, mean, sd, png: c.toDataURL('image/png') };
    } catch (err) { return { ok: false, error: String(err.message || err), ms: Math.round(performance.now() - t0) }; }
  }, L);
  if (r.png) { const out = path.join(ROOT, 'tools', 'out', 'd'); fs.mkdirSync(out, { recursive: true }); fs.writeFileSync(path.join(out, `probe-${L}.png`), Buffer.from(r.png.split(',')[1], 'base64')); delete r.png; }
  console.log(JSON.stringify(r));
  await b.close(); await stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
})().catch((e) => { console.error(e); process.exit(1); });
