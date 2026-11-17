#!/usr/bin/env node
// KBB Studio — design families in the reels: every family element (the worker portrait in all 13 trades), the four
// family transitions and the eight family theme packages, each as a 432×768 mp4 over the drawn sample clip, through the
// real renderer (preview = export) → tools/out/families/<family>-<id>.mp4 (+ a .jpg still).
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/fam-reel-render.cjs   (ONLY=fam-ladder,door  NOVIDEO=1)
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');
const OUT = path.join(H.ROOT, 'tools', 'out', 'families');
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const dir = H.makeStudio(), e = await H.startEngine(dir), b = await H.launchChrome(), p = await b.newPage();
  const errs = []; p.on('pageerror', (x) => errs.push(String(x))); p.on('console', (m) => { if (m.type() === 'error' || /\[(themes|render)\]/.test(m.text())) errs.push(m.text()); });
  await p.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await p.goto(e.url + '/app/'); await p.waitForTimeout(2000);
  const only = (process.env.ONLY || '').split(',').filter(Boolean);
  const jobs = await p.evaluate(async () => {
    const E = await import('/app/themes/elements.js'), Tr = await import('/app/themes/transitions.js'), Th = await import('/app/themes/themes.js');
    const out = [];
    for (const d of E.ELEMENTS.filter((x) => x.fam)) {
      if (d.id === 'fam-worker') for (const v of d.variants) out.push({ kind: 'el', id: d.id, variant: v, fam: d.fam, name: `${d.fam}-worker-${v}` });
      else out.push({ kind: 'el', id: d.id, fam: d.fam, name: `${d.fam}-${d.id.replace(/^fam-/, '')}` });
    }
    for (const t of Tr.TRANSITIONS.filter((x) => x.fam)) out.push({ kind: 'trans', id: t.id, fam: t.fam, name: `${t.fam}-transition-${t.id.replace(/^fam-/, '')}` });
    for (const t of Th.THEMES.filter((x) => x.fam)) out.push({ kind: 'theme', id: t.id, fam: t.fam, name: `${t.fam}-theme-${t.id.replace(/^fam-/, '')}` });
    return out;
  });
  const done = [];
  for (const j of jobs.filter((x) => !only.length || only.includes(x.id) || only.includes(x.name))) {
    const r = await p.evaluate(async (j) => {
      const Hv = await import('/app/themes/hover.js'), E = await import('/app/themes/elements.js'), { createItem } = await import('/app/core/project.js'), D = await import('/app/themes/draw.js');
      const theme = { careers: 'fam-careers-climb', travel: 'fam-travel-journey', occupations: 'fam-occ-uniforms', future: 'fam-future-sunrise' }[j.fam];
      let spec, n;
      if (j.kind === 'el') {
        const pr = Hv.miniProject('9:16'), W = pr.canvas.w, Hh = pr.canvas.h, style = { ...E.elementStyle('country-ca', j.id, { variant: j.variant }), colors: null };
        pr.tracks[0].items.push(createItem('clip', { id: 'c0', t0: 0, in: 0, out: 5, x: 0, y: 0, w: W, h: Hh, variant: 0 }));
        pr.tracks[2].items.push(createItem('theme.el', { t0: 0.2, in: 0, out: 4.2, ...E.boxFor(j.id, W, Hh), style }));
        await D.loadAssets(style); spec = { draw: (g, w, h, t) => Hv.drawMini(g, w, h, pr, t) }; n = 140;
      } else if (j.kind === 'trans') { spec = Hv.transSpec(theme, j.id, {}); await spec.prepare(); n = 78; }
      else { spec = Hv.themeSpec(j.id, { opts: { intensity: 'bold' } }); await spec.prepare(); n = 108; }
      const cv = document.createElement('canvas'); cv.width = 432; cv.height = 768; const g = cv.getContext('2d'), frames = [];
      let ms = 0; for (let f = 0; f < n; f++) { const a = performance.now(); spec.draw(g, 432, 768, f / 30); ms = Math.max(ms, performance.now() - a); frames.push(cv.toDataURL('image/jpeg', 0.9)); }
      return { frames, ms: Math.round(ms) };
    }, j);
    const d = path.join(OUT, 'f-' + process.pid); fs.rmSync(d, { recursive: true, force: true }); fs.mkdirSync(d, { recursive: true });
    r.frames.forEach((u, i) => fs.writeFileSync(path.join(d, String(i).padStart(3, '0') + '.jpg'), Buffer.from(u.split(',')[1], 'base64')));
    execFileSync('ffmpeg', ['-y', '-loglevel', 'error', '-framerate', '30', '-i', path.join(d, '%03d.jpg'), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '24', '-preset', 'medium', '-movflags', '+faststart', path.join(OUT, j.name + '.mp4')]);
    const still = j.kind === 'trans' ? 30 : j.kind === 'theme' ? 80 : 110;
    fs.copyFileSync(path.join(d, String(Math.min(still, r.frames.length - 1)).padStart(3, '0') + '.jpg'), path.join(OUT, j.name + '.jpg'));
    fs.rmSync(d, { recursive: true, force: true });
    done.push(j.name); console.log(j.name, r.frames.length + ' frames', 'worst ' + r.ms + ' ms/frame');
  }
  fs.writeFileSync(path.join(OUT, 'reel-renders.json'), JSON.stringify({ done, errors: errs }, null, 1));
  console.log('rendered', done.length, 'errors', errs.length, errs.slice(0, 6).join('\n'));
  await b.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true });
  process.exit(errs.length ? 1 : 0);
})().catch((x) => { console.error(x); process.exit(2); });
