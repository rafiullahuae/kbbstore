#!/usr/bin/env node
// KBB Studio — design families: a contact sheet of every piece (4:5, 9:16, 16:9 at t = 4, plus 9:16 at t = 0.8 / 2)
// → tools/out/families/sheet-*.png, for a quick look while drawing. ONLY=ladder,cv  T=4  RTL=1
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/fam-sheet.cjs
'use strict';
const fs = require('fs'), path = require('path');
const H = require('./studio-harness.cjs');
const OUT = path.join(H.ROOT, 'tools', 'out', 'families');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const dir = H.makeStudio(), e = await H.startEngine(dir), b = await H.launchChrome(), p = await b.newPage();
  const errs = []; p.on('pageerror', (x) => errs.push(String(x))); p.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errs.push(m.text()); });
  await p.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await p.goto(e.url + '/app/'); await p.waitForTimeout(1500);
  const only = (process.env.ONLY || '').split(',').filter(Boolean), T0 = +(process.env.T || 4), rtl = !!process.env.RTL, cc = process.env.CC || 'ca';
  const res = await p.evaluate(async ({ only, T0, rtl, cc }) => {
    const F = await import('/app/themes/fam.js'), K = await import('/app/themes/kit.js'), D = await import('/app/themes/draw.js');
    await D.loadAssets({ theme: 'country-' + cc }); await D.loadAssets({ theme: 'europe' }); await D.loadAssets({ theme: 'travel' });
    await document.fonts.ready;
    const kit = K.kitFor('country-' + cc), ids = Object.keys(F.FAM_PIECES).filter((k) => !only.length || only.includes(k)), out = [];
    const cells = [[432, 540, T0], [304, 540, T0], [720, 405, T0], [304, 540, 0.8], [304, 540, 2]];
    for (const id of ids) {
      const W = cells.reduce((s, x) => s + x[0] + 12, 12), cv = document.createElement('canvas'); cv.width = W; cv.height = 564; const g = cv.getContext('2d');
      g.fillStyle = '#222'; g.fillRect(0, 0, W, 564); let x = 12; const t0 = performance.now();
      for (const [w, h, t] of cells) { g.fillStyle = '#fff'; g.fillRect(x, 12, w, h); F.drawFam(g, id, { x, y: 12, w, h }, t, { K: kit, flag: D.flagImage(cc), rtl, ar: rtl, role: 'welder' }); x += w + 12; }
      out.push({ id, url: cv.toDataURL('image/png'), ms: Math.round(performance.now() - t0) });
    }
    return out;
  }, { only, T0, rtl, cc });
  for (const r of res) { fs.writeFileSync(path.join(OUT, `sheet-${r.id}${rtl ? '-rtl' : ''}.png`), Buffer.from(r.url.split(',')[1], 'base64')); console.log(r.id, r.ms + ' ms'); }
  console.log('errors', errs.length, errs.slice(0, 8).join('\n'));
  await b.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
})().catch((x) => { console.error(x); process.exit(2); });
