#!/usr/bin/env node
// KBB Studio — phone clips with a rotation tag come out the right way up (headless Chromium + the Linux engine).
//
// The owner, 19 Nov: exported the final reel as a 9:16 story and "all clips rotated" (the presenter on her side).
// An iPhone stores a portrait video as landscape pixels plus a display matrix ("show this turned 90°"). The editor's
// <video> element applies it, so the preview looked right; the export decodes the pixels itself (WebCodecs) and drew
// them as stored. Now every decoded picture is turned by the track's matrix (core/media.js trackRotation).
//
// Drives: a test picture (red left, blue right, white band on top: no symmetry) tagged 90°, 180° and 270°, read three
// ways, the browser's <video> (the reference: what the owner sees), the export reader (openReader) and the frame
// stream (ramps, slow motion, reverse) — they must agree; then a REAL 9:16 export of the 90° clip, read back with
// ffmpeg, must match the clip as ffmpeg itself shows it (ffmpeg applies the tag too).
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/rotate-check.cjs
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'rotate-check'), SHOTS = path.join(ROOT, 'shots', 'rotate');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, msg) => { console.log((c ? 'PASS ' : 'FAIL ') + msg); if (!c) failures++; };
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a]);
const PIC = 'color=c=red:s=640x360:r=30:d=2,drawbox=x=320:y=0:w=320:h=360:color=blue:t=fill,drawbox=x=0:y=0:w=640:h=72:color=white:t=fill';
const ROTS = [90, 180, 270];
for (const r of ROTS) {
  // VP9 in MP4: this test Chromium has no H.264 decoder in WebCodecs (it would fall back to the <video> element and
  // never take the decoder path the owner's Mac takes); VP9 is decoded by WebCodecs here, like H.264 / HEVC on a Mac
  const f = path.join(OUT, `rot${r}.mp4`);
  // landscape pixels + the tag, like an iPhone: encode upright, then stream-copy with the display matrix set (an input
  // -display_rotation on the lavfi source would turn the PIXELS and store no tag at all)
  if (!fs.existsSync(f)) {
    const flat = path.join(OUT, `flat${r}.mp4`);
    ff('-f', 'lavfi', '-i', PIC, '-c:v', 'libvpx-vp9', '-b:v', '1M', '-pix_fmt', 'yuv420p', '-g', '15', '-f', 'mp4', flat);
    ff('-display_rotation', String(r), '-i', flat, '-c', 'copy', '-f', 'mp4', f);
  }
}
// 3×3 grid of colours, each 'R' 'B' 'W' or '?'
const cls = (r, g, b) => (r > 180 && g > 180 && b > 180 ? 'W' : r > 150 && g < 90 && b < 90 ? 'R' : b > 150 && r < 90 && g < 90 ? 'B' : '?');
function gridOfRaw(buf, w, h) {
  const out = [];
  for (const fy of [0.08, 0.5, 0.92]) for (const fx of [0.15, 0.5, 0.85]) { const i = (Math.floor(fy * h) * w + Math.floor(fx * w)) * 3; out.push(cls(buf[i], buf[i + 1], buf[i + 2])); }
  return out.join('');
}

(async () => {
  const STUDIO = H.makeStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(0);
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/\[BoxParser\]/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, a) => page.evaluate(fn, a);
  try {
    await page.goto(e.url + '/app/');
    await page.waitForSelector('.home', { timeout: 30000 });
    await page.setInputFiles('.home-drop input[type=file]', path.join(OUT, 'rot90.mp4'));
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    await ev(async (files) => {
      for (const [name, b64] of files) { const b = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)); await window.kbbEditor.importFiles([new File([b], name, { type: 'video/mp4' })], { addToTimeline: false }); }
    }, ['rot180', 'rot270'].map((n) => [n + '.mp4', fs.readFileSync(path.join(OUT, n + '.mp4')).toString('base64')]));
    await page.waitForFunction(() => window.kbbEditor.project.media.length === 3);

    // the three readers, compared on the same source moment
    const reads = await ev(async () => {
      const ed = window.kbbEditor, cls = (r, g, b) => (r > 180 && g > 180 && b > 180 ? 'W' : r > 150 && g < 90 && b < 90 ? 'R' : b > 150 && r < 90 && g < 90 ? 'B' : '?');
      const grid = (src, w, h) => { const c = new OffscreenCanvas(w, h), x = c.getContext('2d'); x.drawImage(src, 0, 0, w, h); const d = x.getImageData(0, 0, w, h).data, out = [];
        for (const fy of [0.08, 0.5, 0.92]) for (const fx of [0.15, 0.5, 0.85]) { const i = (Math.floor(fy * h) * w + Math.floor(fx * w)) * 4; out.push(cls(d[i], d[i + 1], d[i + 2])); } return out.join(''); };
      const res = {};
      for (const m of ed.project.media) {
        const el = document.createElement('video'); el.muted = true; el.src = ed.media.url(m);
        await new Promise((r) => { el.onloadeddata = r; }); el.currentTime = 0.5; await new Promise((r) => { el.onseeked = r; });
        const ref = grid(el, el.videoWidth, el.videoHeight);
        const rd = await ed.media.openReader(m.id), f = await rd.frame(0.5), via = rd.el ? 'element' : 'decoder';
        const fw = f.displayWidth || f.width, fh = f.displayHeight || f.height, reader = grid(f, fw, fh); rd.close();
        const st = await ed.media.openStream(m.id, { budgetMB: 64 }); st.target(0.5, 0, 1); const fr = await st.frameAt(0.5);
        const stream = grid(fr.a, fr.a.width, fr.a.height), sdim = [fr.a.width, fr.a.height]; st.close();
        res[m.name] = { ref, dim: [el.videoWidth, el.videoHeight], reader, rdim: [fw, fh], stream, sdim, via };
      }
      return res;
    });
    for (const r of [90, 180, 270]) {
      const x = reads[`rot${r}.mp4`];
      ok(!!x && x.via === 'decoder' && x.ref === x.reader && x.reader === x.stream && !x.ref.includes('?') && x.dim.join() === x.rdim.join() && x.dim[0] / x.dim[1] === x.sdim[0] / x.sdim[1],
        `${r}° clip (export reader: ${x?.via}): the picture as shown ${x?.ref} (${x?.dim.join('×')}) = export reader ${x?.reader} (${x?.rdim.join('×')}) = frame stream ${x?.stream} (${x?.sdim.join('×')})`);
    }

    // a real 9:16 export of the 90° clip
    const file = await ev(async () => {
      const ed = window.kbbEditor, E = await import('/app/core/export.js'), p = ed.project;
      const r = await E.exportMp4(p, ed.media, { quality: 'high', width: 360, height: 640, duration: 1.5 });
      const f = `workspaces/${p.workspace}/exports/rotate-check.mp4`; await ed.engine.writeFile(f, r.blob); return f;
    });
    const exp = path.join(STUDIO, file);
    const raw = (src, w, h) => execFileSync('ffmpeg', ['-v', 'error', '-ss', '0.5', '-i', src, '-frames:v', '1', '-vf', `scale=${w}:${h}`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-']);
    const want = gridOfRaw(raw(path.join(OUT, 'rot90.mp4'), 360, 640), 360, 640), got = gridOfRaw(raw(exp, 360, 640), 360, 640);
    ok(want === got && !want.includes('?'), `9:16 export of the 90° phone clip: ${got}, the clip as ffmpeg shows it: ${want}`);
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-ss', '0.5', '-i', exp, '-frames:v', '1', path.join(SHOTS, 'rotate-export-90.png')]);
    ok(!errors.length, `no console errors${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}`);
  } catch (err) { console.error(err); failures++; }
  finally { await browser.close(); await H.stopEngine(e); }
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})();
