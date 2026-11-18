// ✂ Cut-outs in the Ad Studio (18 Nov 2026). The owner: remove backgrounds "on the video as well, in case we want to remove
// the background from the portrait ads videos" -- and the Ad Studio's own photos, products included.
//
// Everything is drawn here, each with its exact ground-truth silhouette (nothing downloaded but the optional model):
//   person photo / product photo           a presenter on a busy background; a serum bottle on a table
//   person.mp4 / green.mp4 / product.mp4   2 s, 540×960, the presenter moving, the presenter on a green screen, the product
//                                          (slow camera push)
//   alpha.webm / alpha.zip                 a see-through disc moving (WebM VP9 + alpha as libvpx writes it, the format
//                                          KBB Studio's export-alpha.js writes; PNG-sequence .zip with stored entries)
// Checked, standalone (file://, dist/ad-studio.html) and inside KBB Studio (its engine, COOP/COEP, a throwaway folder):
//   photos        ✂ Person: IoU against the truth; Edge feather re-cuts; ↺ Restore background; undo / redo; save + reload
//                 📦 Main subject (KBB Studio): the model's one-click download through the engine's installer, product IoU
//   video         🧍 Remove background with Person, Main subject (KBB Studio) and Green screen: IoU per sampled frame,
//                 flicker; behind it Transparent (a "Behind the video" layer shows through), Colour, Brand gradient
//   playback      preview frame cost and frames drawn per second while playing
//   transparent   WebM with alpha / PNG-sequence zip / transparent PNG imported: alpha sampled in the preview AND in the
//                 exported MP4 (ffmpeg), and on a still ad (first frame as a transparent picture)
//   export=preview  the MP4's frames against the editor's own drawing of the same instants
//   history       one undo per action, redo; save → reload: the same pixels; an old design (no new fields) draws exactly as
//                 the build before this change drew it (Ads PNG + a video frame, from git HEAD's dist/ad-studio.html)
//   standalone    no 📦 Main subject, the note says products need KBB Studio, asking for it changes nothing; no network
// Pictures -> shots/cutout/*.png. Scratch -> tools/out-cut/ (git-ignored). The downloaded model lives in a temp folder that
// is deleted at the end.
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/check-cutout.cjs [--no-studio] [--base <git ref>]
'use strict';
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os'), cp = require('child_process');
const ROOT = path.join(__dirname, '..'), DIST = path.join(ROOT, 'dist', 'ad-studio.html'), FILE = 'file://' + DIST;
const REEL = path.resolve(ROOT, '..', '..', '..', 'studio', 'reel-studio');
const OUT = path.join(__dirname, 'out-cut'), SHOTS = path.join(ROOT, 'shots', 'cutout');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const STUDIO = !process.argv.includes('--no-studio'), ONLY_STUDIO = process.argv.includes('--only-studio'), BASE = arg('base', '16600674b');   // the tree before the cut-out lane
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
let fails = 0; const nums = {};
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const pct = (x) => (x * 100).toFixed(1) + '%';
const ff = (...a) => cp.execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const W = 540, H = 960, FPS = 30, N = 60;

// ── the drawn world (runs in the page): o.mask draws the silhouette only, white on black ──
const WORLD = `window.CW = (() => {
  function busy(x, w, h, t) {
    const g = x.createLinearGradient(0, 0, w, h); g.addColorStop(0, '#d9c3a5'); g.addColorStop(.55, '#b98e6a'); g.addColorStop(1, '#5b6f73'); x.fillStyle = g; x.fillRect(0, 0, w, h);
    for (let i = 0; i < 18; i++) { x.fillStyle = 'hsla(' + (30 + i * 23) % 360 + ',55%,' + (55 + (i % 3) * 10) + '%,.35)'; x.beginPath(); x.arc(((i * 97) + t * 40) % w, (i * 151) % h, (30 + (i % 4) * 18) * w / 800, 0, 7); x.fill(); }
  }
  function person(x, w, h, cx, o) {
    const P = o.mask ? () => '#fff' : (c) => c, k = w / 800;
    x.save(); x.translate(cx, 0); x.scale(k, h / 1000);
    x.fillStyle = P(o.shirt || '#1f3b70'); x.beginPath(); x.moveTo(-280, 1000); x.bezierCurveTo(-280, 700, -220, 640, -100, 630); x.lineTo(100, 630); x.bezierCurveTo(220, 640, 280, 700, 280, 1000); x.closePath(); x.fill();
    x.fillStyle = P('#d9a07e'); x.fillRect(-50, 500, 100, 150);
    x.fillStyle = P('#2b1a12'); x.beginPath(); x.ellipse(0, 380, 140, 170, 0, 0, 7); x.fill();
    x.fillStyle = P('#e2ad8c'); x.beginPath(); x.ellipse(0, 415, 118, 150, 0, 0, 7); x.fill();
    if (!o.mask) {
      for (const s of [-1, 1]) { x.fillStyle = '#fff'; x.beginPath(); x.ellipse(s * 46, 400, 22, 12, 0, 0, 7); x.fill(); x.fillStyle = '#3a2416'; x.beginPath(); x.arc(s * 46, 400, 9, 0, 7); x.fill(); }
      x.fillStyle = '#b2484f'; x.beginPath(); x.ellipse(0, 500, 34, 9, 0, 0, 7); x.fill();
    }
    x.restore();
  }
  function product(x, w, h, t, o) {
    const P = o.mask ? () => '#fff' : (c) => c, k = w / 540, z = 1 + t * .04;
    if (!o.mask) {
      const g = x.createLinearGradient(0, 0, 0, h); g.addColorStop(0, '#e9e4dc'); g.addColorStop(.62, '#d8cfc2'); g.addColorStop(.62, '#a98f74'); g.addColorStop(1, '#8a7258'); x.fillStyle = g; x.fillRect(0, 0, w, h);
      x.fillStyle = 'rgba(0,0,0,.12)'; x.beginPath(); x.ellipse(w * .5, h * .64, w * .36, h * .025, 0, 0, 7); x.fill();
    }
    // one product, as a product shot has it (U²-Net-p keeps THE main subject: with two side by side it may take one)
    x.save(); x.translate(w / 2, h * .62); x.scale(k * z, k * z);
    x.fillStyle = P('#c9782c'); x.beginPath(); x.roundRect(-75, -360, 150, 360, 26); x.fill();                  // amber serum bottle
    x.fillStyle = P('#26201c'); x.fillRect(-55, -432, 110, 76);                                              // cap
    x.fillStyle = P('#fbf7f0'); x.fillRect(-75, -230, 150, 84);                                              // label
    if (!o.mask) { x.fillStyle = '#c6395f'; x.fillRect(-55, -208, 110, 12); x.fillRect(-55, -184, 70, 9); }
    x.restore();
  }
  // kind: person | green | product · t seconds · mask: the truth
  function frame(kind, w, h, t, mask) {
    const c = document.createElement('canvas'); c.width = w; c.height = h; const x = c.getContext('2d');
    if (mask) { x.fillStyle = '#000'; x.fillRect(0, 0, w, h); }
    else if (kind === 'green') { const g = x.createLinearGradient(0, 0, w, 0); g.addColorStop(0, '#1fae46'); g.addColorStop(1, '#27c050'); x.fillStyle = g; x.fillRect(0, 0, w, h); }
    else if (kind === 'person') busy(x, w, h, t);
    const cx = w * (.5 + .1 * Math.sin(t * 2));
    if (kind === 'product') product(x, w, h, t, { mask }); else person(x, w, h, cx, { mask, shirt: kind === 'green' ? '#7a2340' : '#1f3b70' });
    return c;
  }
  // IoU of a picture's opaque part (alpha >= 128, or "not the key colour" when key is given) against the truth
  function iou(src, kind, t, key) {
    const w = src.width, h = src.height, c = document.createElement('canvas'); c.width = w; c.height = h; const x = c.getContext('2d', { willReadFrequently: true });
    x.drawImage(src, 0, 0, w, h); const a = x.getImageData(0, 0, w, h).data;
    const g = frame(kind, w, h, t, true).getContext('2d').getImageData(0, 0, w, h).data;
    let I = 0, U = 0;
    for (let i = 0; i < a.length; i += 4) {
      const on = key ? Math.abs(a[i] - key[0]) + Math.abs(a[i + 1] - key[1]) + Math.abs(a[i + 2] - key[2]) > 90 : a[i + 3] >= 128, tr = g[i] > 127;
      if (on && tr) I++; if (on || tr) U++;
    }
    return U ? I / U : 0;
  }
  return { frame, iou };
})();`;

// a moving see-through disc: RGBA PNG frames -> WebM VP9 with alpha (libvpx) and a PNG-sequence .zip (stored entries)
function alphaMedia() {
  const dir = path.join(OUT, 'alpha'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
  // a disc of #E8306F with a white ring, centre moving right; outside fully transparent
  ff('-f', 'lavfi', '-i', `color=c=black@0:s=320x320:r=${FPS}:d=2,format=rgba,geq=r='if(lt(hypot(X-(110+50*T),Y-160),70),232,255)':g='if(lt(hypot(X-(110+50*T),Y-160),70),48,255)':b='if(lt(hypot(X-(110+50*T),Y-160),70),111,255)':a='if(lt(hypot(X-(110+50*T),Y-160),90),255,0)'`,
    path.join(dir, 'f-%05d.png'));
  ff('-framerate', String(FPS), '-i', path.join(dir, 'f-%05d.png'), '-c:v', 'libvpx-vp9', '-pix_fmt', 'yuva420p', '-auto-alt-ref', '0', '-b:v', '2M', path.join(OUT, 'alpha.webm'));
  // stored zip, as export-alpha.js's ZipWriter writes it (PNGs are already compressed)
  const zf = path.join(OUT, 'alpha.zip'); fs.rmSync(zf, { force: true });
  cp.execFileSync('zip', ['-0', '-q', '-j', zf, ...fs.readdirSync(dir).filter((f) => f.endsWith('.png')).sort().map((f) => path.join(dir, f))]);
  fs.copyFileSync(path.join(dir, 'f-00001.png'), path.join(OUT, 'alpha.png'));
}

function watch(p, errs, net) {
  p.on('console', (m) => { if (m.type() === 'error' && !/^INFO: Created TensorFlow Lite XNNPACK delegate/.test(m.text())) errs.push(m.text()); });
  p.on('pageerror', (e) => errs.push(e.message));
  p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url()) && !/^http:\/\/(127\.0\.0\.1|localhost):\d+\//.test(r.url())) net.push(r.url()); });
  p.on('dialog', (d) => d.accept());
}
async function open(ctx, url, mode) {
  const p = await ctx.newPage(), errs = [], net = []; watch(p, errs, net);
  await p.goto(url); await p.waitForFunction(() => document.documentElement.dataset.ready === '1', null, { timeout: 60000 });
  await p.addScriptTag({ content: WORLD });
  if (mode === 'video') {
    if (await p.evaluate(() => document.body.dataset.mode) !== 'video') await p.click('#modesw button[data-m="video"]');
    await p.waitForFunction(() => document.documentElement.dataset.vready === '1');
  } else if (await p.evaluate(() => document.body.dataset.mode) === 'video') await p.click('#modesw button[data-m="ads"]');
  return { p, errs, net };
}
const shot = (p, name) => p.screenshot({ path: path.join(SHOTS, name + '.png') });
async function writeFrom(p, fn, arg, file) { const b64 = await p.evaluate(fn, arg); fs.writeFileSync(file, Buffer.from(b64.split(',')[1], 'base64')); return file; }

// the test clips, drawn in a page and encoded by ffmpeg (H.264, as a phone would hand them over)
async function makeClips(p) {
  for (const kind of ['person', 'green', 'product']) {
    const dir = path.join(OUT, 'f-' + kind); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir);
    for (let i = 0; i < N; i += 10) {
      const urls = await p.evaluate(([k, a, w, h, fps]) => Array.from({ length: 10 }, (_, j) => window.CW.frame(k, w, h, (a + j) / fps, false).toDataURL('image/jpeg', .93)), [kind, i, W, H, FPS]);
      urls.forEach((u, j) => fs.writeFileSync(path.join(dir, 'f' + String(i + j).padStart(3, '0') + '.jpg'), Buffer.from(u.split(',')[1], 'base64')));
    }
    ff('-framerate', String(FPS), '-i', path.join(dir, 'f%03d.jpg'), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '16', '-g', '30', path.join(OUT, kind + '.mp4'));
  }
}

// ── Video-mode helpers ──
const V = (p, fn, a) => p.evaluate(fn, a);
async function freshVideo(p) { await p.click('#v-new'); await p.waitForFunction(() => window.KBB_VIDEO.vp && !window.KBB_VIDEO.vp.clips.length); }
async function importV(p, files) {
  const n0 = await V(p, () => Object.keys(window.KBB_VIDEO.media).length);
  await p.setInputFiles('#v-file', files);
  await p.waitForFunction((n) => /Imported|Skipped/.test(document.getElementById('v-status').textContent) && Object.keys(window.KBB_VIDEO.media).length >= n, n0 + files.length, { timeout: 60000 });
}
// the editor's own drawing of instant t at artboard size (what the export draws), as a canvas id'd on window
async function drawAt(p, t) {
  return V(p, async (t) => {
    const K = window.KBB_VIDEO; K.seek(t); await K.ensureMasks();
    const vids = Object.values(K.media).map((m) => m.el).filter((e) => e && e.tagName === 'VIDEO');
    await new Promise((r) => setTimeout(r, 120)); for (const v of vids) if (v.seeking) await new Promise((r) => v.addEventListener('seeked', r, { once: true }));
    const c = document.createElement('canvas'); c.width = K.vp.ab.w; c.height = K.vp.ab.h; K.drawFrame(c.getContext('2d'), c.width, c.height, t); window.__F = c; return true;
  }, t);
}
// IoU of the cut-out (on a flat magenta "Colour" background, light wrap off) against the truth at sample times
async function videoIoU(p, kind, ts) {
  const out = [];
  for (const t of ts) { await drawAt(p, t); out.push(await V(p, ([k, t]) => window.CW.iou(window.__F, k, t, [255, 0, 255]), [kind, t])); }
  return out;
}
async function seqDiff(p, ts) {     // false frame-to-frame change: the cut's flicker where the truth did not move (static product)
  const ms = [];
  for (const t of ts) { await drawAt(p, t); ms.push(await V(p, () => { const c = window.__F, x = c.getContext('2d'), d = x.getImageData(0, 0, c.width, c.height).data, m = new Uint8Array(c.width * c.height); for (let i = 0, j = 0; i < d.length; i += 4, j++) m[j] = Math.abs(d[i] - 255) + d[i + 1] + Math.abs(d[i + 2] - 255) > 90 ? 1 : 0; return Array.from(m); })); }
  let ch = 0, n = 0; for (let i = 1; i < ms.length; i++) for (let j = 0; j < ms[i].length; j++) { n++; if (ms[i][j] !== ms[i - 1][j]) ch++; }
  return n ? ch / n : 0;
}
const mean = (a) => a.reduce((s, x) => s + x, 0) / Math.max(1, a.length);

// ── photos in Ads mode ──
async function photoCut(p, file, kind, label) {
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('flash-sale'));
  await p.setInputFiles('#add-img', file);
  await p.waitForFunction(() => { const S = window.KBB_STUDIO; return S.doc.els.some((e) => e.t === 'img'); }, null, { timeout: 30000 });
  const id = await p.evaluate(() => { const e = window.KBB_STUDIO.doc.els.filter((x) => x.t === 'img').pop(); window.KBB_STUDIO.setSel([e.id]); return e.id; });
  await p.click('.tab[data-tab="edit"]');
  const vis = await p.evaluate(() => ({ row: !document.getElementById('cut-row').hidden, person: !document.getElementById('ph-cut').hidden, subj: !document.getElementById('ph-cut-subj').hidden, note: document.getElementById('ph-cut-note').textContent }));
  const t0 = Date.now();
  await p.click(kind === 'subject' ? '#ph-cut-subj' : '#ph-cut');
  await p.waitForFunction(() => /^(Cut out —|Not cut out)/.test(document.querySelector('#status').textContent), null, { timeout: 240000 });
  const ms = Date.now() - t0;
  const r = await p.evaluate(async ([id, kind]) => {
    const S = window.KBB_STUDIO, e = S.doc.els.find((x) => x.id === id); if (!e.cut) return { err: document.querySelector('#status').textContent };
    const im = new Image(); im.src = S.images[e.img].src; await im.decode();
    return { iou: window.CW.iou(im, kind === 'subject' ? 'product' : 'person', 0), cut: e.cut, name: e.name, w: im.naturalWidth, h: im.naturalHeight };
  }, [id, kind]);
  return Object.assign({ id, vis, ms }, r);
}
async function alphaAt(p, src, pts) {
  return p.evaluate(async ([src, pts]) => { const im = new Image(); im.src = src; await im.decode(); const c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight; const x = c.getContext('2d'); x.drawImage(im, 0, 0); return pts.map((q) => x.getImageData(Math.round(q[0] * c.width), Math.round(q[1] * c.height), 1, 1).data[3]); }, [src, pts]);
}

async function photos(p, where, studio) {
  const ph = path.join(OUT, 'person.jpg'), pr = path.join(OUT, 'product.jpg');
  await writeFrom(p, () => window.CW.frame('person', 800, 1000, 0, false).toDataURL('image/jpeg', .92), null, ph);
  await writeFrom(p, () => window.CW.frame('product', 800, 1000, 0, false).toDataURL('image/jpeg', .92), null, pr);
  const a = await photoCut(p, ph, 'person');
  ok(a.vis.row && a.vis.person && (studio ? a.vis.subj : !a.vis.subj && /need KBB Studio/.test(a.vis.note)), `${where}: a photo layer shows ✂ Cut out → 🧍 Person${studio ? ' and 📦 Main subject' : ' only, and says products need KBB Studio ("' + a.vis.note.slice(0, 60) + '…")'}`);
  ok(a.iou > 0.85 && a.cut && a.cut.k === 'person', `${where}: ✂ Person on the person photo: IoU ${pct(a.iou || 0)} against the truth, ${(a.ms / 1000).toFixed(1)} s (${a.w}×${a.h})${a.err ? ' — ' + a.err : ''}`);
  nums[where + ' photo person IoU'] = a.iou; nums[where + ' photo person s'] = a.ms / 1000;
  if (!studio) { await p.evaluate((id) => window.KBB_STUDIO.setSel([id]), a.id); await p.waitForTimeout(200); await shot(p, 'photo-person-cut-1280'); }
  // Edge feather: re-cut from the original, softer edge (more pixels between 10 and 245 alpha)
  const soft = async () => p.evaluate(async () => { const S = window.KBB_STUDIO, e = S.doc.els.find((x) => x.cut); const im = new Image(); im.src = S.images[e.img].src; await im.decode(); const c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight; const x = c.getContext('2d'); x.drawImage(im, 0, 0); const d = x.getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 3; i < d.length; i += 4) if (d[i] > 10 && d[i] < 245) n++; return n; });
  const s0 = await soft(), h0 = await p.evaluate(() => window.KBB_STUDIO.doc.els.find((x) => x.cut).img);
  await p.$eval('#ph-fea', (e) => { e.value = '80'; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.waitForFunction((h0) => { const e = window.KBB_STUDIO.doc.els.find((x) => x.cut); return e && e.img !== h0 && e.cut.f === 80; }, h0, { timeout: 60000 });
  const s1 = await soft();
  ok(s1 > s0 * 1.3, `${where}: Edge feather 10 → 80 re-cuts from the original photo: soft edge pixels ${s0} → ${s1}`);
  // Restore background, undo, redo
  const st = () => p.evaluate(() => { const e = window.KBB_STUDIO.doc.els.filter((x) => x.t === 'img').pop(); return { img: e.img, cut: e.cut ? e.cut.f : null, name: e.name }; });
  const cutState = await st();
  await p.click('#ph-restore'); const rest = await st();
  ok(!rest.cut && rest.img !== cutState.img && !/cut out/.test(rest.name), `${where}: ↺ Restore background puts the original photo back (${rest.name})`);
  await p.locator('#stage').click({ position: { x: 3, y: 3 } }).catch(() => {});
  await p.keyboard.press('Control+z'); const und = await st();
  await p.keyboard.press('Control+y'); const red = await st();
  await p.keyboard.press('Control+z'); await p.keyboard.press('Control+z'); const und2 = await st();
  ok(und.img === cutState.img && und.cut === 80 && red.img === rest.img && !red.cut && und2.cut === 10, `${where}: undo / redo step through cut → feather → restore (one step each)`);
  let b = null;
  if (studio) {
    b = await photoCut(p, pr, 'subject');
    ok(b.iou > 0.85 && b.cut && b.cut.k === 'subject', `${where}: 📦 Main subject on the product photo (U²-Net-p): IoU ${pct(b.iou || 0)}, ${(b.ms / 1000).toFixed(1)} s incl. the first load${b.err ? ' — ' + b.err : ''}`);
    nums['product photo IoU'] = b.iou;
    await p.evaluate((id) => window.KBB_STUDIO.setSel([id]), b.id); await p.waitForTimeout(200); await shot(p, 'photo-product-cut-1280');
    await writeFrom(p, async (id) => { const S = window.KBB_STUDIO, bl = await (await fetch(S.images[S.doc.els.find((e) => e.id === id).img].src)).blob(); return new Promise((r) => { const f = new FileReader(); f.onload = () => r(f.result); f.readAsDataURL(bl); }); }, b.id, path.join(OUT, 'product-cut.png'));
    const pp = await photoCut(p, pr, 'person');
    nums['product photo, person segmenter IoU'] = pp.iou || 0;
    ok(!pp.cut || (pp.iou || 0) < b.iou - 0.2, `${where}: the person segmenter cannot do the product (${pp.cut ? 'IoU ' + pct(pp.iou) : pp.err}) — that is why Main subject exists`);
  }
  return { a, b };
}

// ── video mode ──
async function videoRemoval(p, where, studio) {
  const res = {};
  await freshVideo(p);
  await importV(p, [path.join(OUT, 'person.mp4')]);
  await V(p, () => { const K = window.KBB_VIDEO; K.vp.ab = { w: 540, h: 960 }; K.changed('ab'); K.select('clip', K.vp.clips[0].id); K.showTab('clip'); });
  ok(await p.isVisible('#v-bgr') && await p.isVisible('#vb-key button[data-v="person"]') && await p.isVisible('#vb-key button[data-v="chroma"]') && (await p.isVisible('#vb-key button[data-v="subject"]')) === studio,
    `${where}: the Clip panel of a selected video clip has 🧍 Remove background: Person, ${studio ? 'Main subject, ' : ''}Green screen`);
  const before = await V(p, () => JSON.stringify(window.KBB_VIDEO.vp));
  let t0 = Date.now();
  await p.click('#vb-key button[data-v="person"]');
  await p.waitForFunction(() => /Background removed|stopped/.test(document.getElementById('v-status').textContent), null, { timeout: 240000 });
  const an = await V(p, () => window.KBB_VIDEO.maskStats); res.analysis = an;
  ok(an && an.frames >= 60, `${where}: Person: the clip analysed once, ${an && an.frames} grid frames (30 a second, as the video editor) in ${an && (an.ms / 1000).toFixed(1)} s`);
  nums[where + ' person analysis s per clip-second'] = an && an.ms / 1000 / 2;
  await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'color', color: '#FF00FF', wrap: 0 }, 'test'));
  const ts = [0.2, 0.6, 1.0, 1.4, 1.8], io = await videoIoU(p, 'person', ts);
  res.person = mean(io); nums[where + ' video person IoU'] = res.person;
  ok(mean(io) > 0.85 && Math.min(...io) > 0.8, `${where}: Person on the moving clip: IoU ${io.map(pct).join(' · ')} (mean ${pct(mean(io))})`);
  // undo / redo: one step each
  const hist = await V(p, () => { const K = window.KBB_VIDEO; K.undo(); const a = !!K.vp.clips[0].bgr && K.vp.clips[0].bgr.mode === 'none'; K.undo(); const b = !K.vp.clips[0].bgr; K.redo(); K.redo(); const c = K.vp.clips[0].bgr && K.vp.clips[0].bgr.mode === 'color'; return [a, b, c]; });
  ok(hist.every(Boolean), `${where}: undo: colour → transparent → no removal; redo brings both back ` + JSON.stringify(hist));
  // the modes: transparent (a "Behind the video" picture layer shows through), colour, brand gradient
  const modes = {};
  await V(p, () => { const K = window.KBB_VIDEO; K.vp.bg = { k: 'color', c: '#00FF00' }; K.changed('bg'); K.select('clip', K.vp.clips[0].id); });
  for (const m of ['none', 'color', 'gradient']) {
    await V(p, (m) => window.KBB_VIDEO.setBgr({ mode: m, color: '#FF00FF', colors: ['#E8306F', '#1F1B1E'], wrap: .3 }, 'mode-' + m), m);
    await drawAt(p, 1.0);
    modes[m] = await V(p, () => { const x = window.__F.getContext('2d'); const px = (a, b) => Array.from(x.getImageData(a, b, 1, 1).data).slice(0, 3); return { corner: px(20, 20), low: px(20, 900), face: px(270, 400) }; });
  }
  const near = (a, b, d) => a.every((v, i) => Math.abs(v - b[i]) <= (d || 12));
  ok(near(modes.none.corner, [0, 255, 0]) && near(modes.color.corner, [255, 0, 255]) && !near(modes.gradient.corner, modes.gradient.low, 30) && !near(modes.none.face, [0, 255, 0], 60),
    `${where}: behind the person — Transparent shows the Empty-space fill ${JSON.stringify(modes.none.corner)}, Colour ${JSON.stringify(modes.color.corner)}, Brand gradient top ${JSON.stringify(modes.gradient.corner)} → bottom ${JSON.stringify(modes.gradient.low)}; the face stays ${JSON.stringify(modes.none.face)}`);
  res.modes = modes;
  // transparent + a picture layer set to "Behind the video": it shows round the person, not over the face
  await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'none' }, 'mode-none2'));
  const pic = path.join(OUT, 'behind.png'); await writeFrom(p, () => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; const x = c.getContext('2d'); x.fillStyle = '#2040C0'; x.fillRect(0, 0, 540, 960); return c.toDataURL('image/png'); }, null, pic);
  await importV(p, [pic]);
  const bh = await V(p, async () => {
    const K = window.KBB_VIDEO, mid = Object.keys(K.media).find((k) => K.media[k].k === 'image');
    K.vp.clips = K.vp.clips.filter((c) => K.media[c.m].k !== 'image'); K.seek(0); K.addOv({ t: 'img', im: 'm:' + mid, w: 100, s: 0, e: 2, ain: 'none', aout: 'none' }); const o = K.vp.ovs[K.vp.ovs.length - 1]; o.bh = true; K.changed('bh'); return o.id;
  });
  await drawAt(p, 1.0);
  const bhp = await V(p, () => { const x = window.__F.getContext('2d'); const px = (a, b) => Array.from(x.getImageData(a, b, 1, 1).data).slice(0, 3); return { corner: px(20, 20), face: px(270, 400) }; });
  ok(near(bhp.corner, [32, 64, 192], 14) && !near(bhp.face, [32, 64, 192], 60), `${where}: Transparent + a picture layer "Behind the video": the layer shows round the person ${JSON.stringify(bhp.corner)}, the person stays in front ${JSON.stringify(bhp.face)}`);
  await V(p, (id) => { const K = window.KBB_VIDEO; K.select('ov', id); K.showTab('stick'); }, bh);
  if (!studio) await shot(p, 'video-layer-behind-1280');
  await V(p, () => { const K = window.KBB_VIDEO; K.select('clip', K.vp.clips[0].id); K.showTab('clip'); K.seek(1.0); });
  await p.waitForTimeout(300);
  if (!studio) await shot(p, 'video-remove-background-panel-1280');

  // playback: the preview's cost per frame and what it keeps up with while playing
  const perf = await V(p, async () => {
    const K = window.KBB_VIDEO, one = (on) => { const c = K.vp.clips[0], keep = c.bgr; if (!on) delete c.bgr; const t0 = performance.now(); for (let i = 0; i < 10; i++) { K.redraw(); K.preview().getContext('2d').getImageData(0, 0, 1, 1); } if (!on) c.bgr = keep; return (performance.now() - t0) / 10; };
    K.seek(1.0); await new Promise((r) => setTimeout(r, 200)); const plain = one(false), cut = one(true);
    K.seek(0); let frames = 0, run = true; const tick = () => { frames++; if (run) requestAnimationFrame(tick); }; const w0 = performance.now(); K.play(); requestAnimationFrame(tick);
    await new Promise((r) => setTimeout(r, 1500)); run = false; const at = K.ph, wall = (performance.now() - w0) / 1000; K.stop();
    const r = K.preview().getBoundingClientRect();
    return { plain: +plain.toFixed(1), cut: +cut.toFixed(1), fps: +(frames / 1.5).toFixed(1), at: +at.toFixed(2), wall: +wall.toFixed(2), shown: Math.round(r.width) + '×' + Math.round(r.height), gl: window.KBB_LOOK.gl().gl.getParameter(window.KBB_LOOK.gl().gl.RENDERER) };
  });
  nums[where + ' preview ms/frame plain → with cut-out'] = [perf.plain, perf.cut]; nums[where + ' rAF fps while playing'] = perf.fps;
  ok(Math.abs(perf.at - perf.wall) < 0.25, `${where}: playback stays in real time with the cut-out on (playhead ${perf.at} s after ${perf.wall} s); preview frame ${perf.plain} ms plain → ${perf.cut} ms cut out, drawn at the monitor's ${perf.shown} on ${perf.gl} (CPU-emulated GL here: ${perf.fps} frames a second; a Mac GPU runs this shader in about a millisecond)`);

  // export = preview: the MP4's frames against the editor's own drawing at the same instants (gradient behind)
  await V(p, () => { const K = window.KBB_VIDEO; K.select('clip', K.vp.clips[0].id); K.setBgr({ mode: 'gradient' }, 'mode-g2'); });
  const ets = [0.5, 1.0, 1.5], ref = [];
  for (const t of ets) { await drawAt(p, t); ref.push(await V(p, () => { const c = document.createElement('canvas'); c.width = 135; c.height = 240; c.getContext('2d').drawImage(window.__F, 0, 0, 135, 240); return Array.from(c.getContext('2d').getImageData(0, 0, 135, 240).data); })); }
  const prevPx = await V(p, async () => { const K = window.KBB_VIDEO; K.seek(1.0); await new Promise((r) => setTimeout(r, 300)); const c = document.createElement('canvas'); c.width = 135; c.height = 240; c.getContext('2d').drawImage(K.preview(), 0, 0, 135, 240); return Array.from(c.getContext('2d').getImageData(0, 0, 135, 240).data); });
  t0 = Date.now();
  await V(p, () => window.KBB_VIDEO.showTab('export'));
  const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 300000 }), p.click('#vx-go')]);
  const mp4 = path.join(OUT, where + '-cut.mp4'); await dl.saveAs(mp4); const ems = Date.now() - t0;
  const diffs = ets.map((t, i) => {
    const raw = cp.execFileSync('ffmpeg', ['-v', 'error', '-ss', String(t + 0.5 / FPS), '-i', mp4, '-frames:v', '1', '-vf', 'scale=135:240:flags=area', '-f', 'rawvideo', '-pix_fmt', 'rgba', '-']);
    let s = 0, n = 0; for (let j = 0; j < raw.length; j += 4) for (let k = 0; k < 3; k++) { s += Math.abs(raw[j + k] - ref[i][j + k]); n++; } return s / n;
  });
  let pd = 0, pn = 0; for (let j = 0; j < prevPx.length; j += 4) for (let k = 0; k < 3; k++) { pd += Math.abs(prevPx[j + k] - ref[1][j + k]); pn++; }
  nums[where + ' export vs editor (mean abs diff, 0-255)'] = diffs.map((d) => +d.toFixed(2)); nums[where + ' preview monitor vs export drawing'] = +(pd / pn).toFixed(2);
  ok(diffs.every((d) => d < 4) && pd / pn < 6, `${where}: export = preview: MP4 frames vs the editor's drawing at ${ets.join(', ')} s: mean |Δ| ${diffs.map((d) => d.toFixed(2)).join(' / ')} (H.264); the preview monitor vs it ${(pd / pn).toFixed(2)}; exported in ${(ems / 1000).toFixed(1)} s`);

  // green screen: the key colour measured from the clip
  await freshVideo(p); await importV(p, [path.join(OUT, 'green.mp4')]);
  await V(p, () => { const K = window.KBB_VIDEO; K.vp.ab = { w: 540, h: 960 }; K.changed('ab'); K.select('clip', K.vp.clips[0].id); K.showTab('clip'); });
  await p.click('#vb-key button[data-v="chroma"]');
  await p.waitForFunction(() => window.KBB_VIDEO.vp.clips[0].bgr && window.KBB_VIDEO.vp.clips[0].bgr.key === 'chroma', null, { timeout: 30000 });
  const kc = await V(p, () => window.KBB_VIDEO.vp.clips[0].bgr.keyColor);
  await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'color', color: '#FF00FF', wrap: 0 }, 'gt'));
  const gi = await videoIoU(p, 'green', ts); res.green = mean(gi); nums[where + ' video green screen IoU'] = res.green;
  const spill = await V(p, () => { const x = window.__F.getContext('2d'), d = x.getImageData(0, 0, 540, 960).data; let s = 0, n = 0; for (let i = 0; i < d.length; i += 4) { const mg = Math.abs(d[i] - 255) + d[i + 1] + Math.abs(d[i + 2] - 255) > 90; if (mg && d[i + 1] > Math.max(d[i], d[i + 2]) + 40) { s++; } if (mg) n++; } return s / Math.max(1, n); });
  ok(mean(gi) > 0.93 && spill < 0.02, `${where}: Green screen (key ${kc} measured from the clip): IoU ${gi.map(pct).join(' · ')}; green-cast pixels left on the person ${pct(spill)}`);
  if (!studio) { await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'gradient', wrap: .3 }, 'gs')); await V(p, () => window.KBB_VIDEO.seek(1.0)); await p.waitForTimeout(300); await shot(p, 'video-green-screen-1280'); }

  if (studio) {
    await freshVideo(p); await importV(p, [path.join(OUT, 'product.mp4')]);
    await V(p, () => { const K = window.KBB_VIDEO; K.vp.ab = { w: 540, h: 960 }; K.changed('ab'); K.select('clip', K.vp.clips[0].id); K.showTab('clip'); });
    t0 = Date.now();
    await p.click('#vb-key button[data-v="subject"]');
    await p.waitForFunction(() => /Background removed|stopped|not removed/i.test(document.getElementById('v-status').textContent), null, { timeout: 240000 });
    await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'color', color: '#FF00FF', wrap: 0 }, 'gt'));
    const si = await videoIoU(p, 'product', ts); res.subject = mean(si); nums['video main subject IoU'] = res.subject;
    const fl = await seqDiff(p, [0.9, 0.933, 0.967, 1.0, 1.033]); nums['video main subject flicker'] = fl;
    ok(mean(si) > 0.85, `${where}: 📦 Main subject on the product clip (U²-Net-p, 10 grid frames a second, temporal filter): IoU ${si.map(pct).join(' · ')} in ${((Date.now() - t0) / 1000).toFixed(1)} s; frame-to-frame change ${pct(fl)}`);
    await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'gradient', wrap: .3 }, 'gs')); await V(p, () => window.KBB_VIDEO.seek(1.0)); await p.waitForTimeout(400);
    await shot(p, 'video-product-main-subject-1280');
  } else {
    // standalone: asking for the main subject changes nothing and says why
    const vb = await V(p, () => JSON.stringify(window.KBB_VIDEO.vp.clips[0].bgr));
    await V(p, () => window.KBB_VIDEO.removeBg('subject'));
    const msg = await p.textContent('#v-status'), va = await V(p, () => JSON.stringify(window.KBB_VIDEO.vp.clips[0].bgr));
    ok(vb === va && /needs KBB Studio/.test(msg), `${where}: 📦 Main subject asked for anyway: nothing changes — "${msg.slice(0, 90)}"`);
  }
  void before;
  return res;
}

// transparent media: WebM with alpha, PNG sequence, transparent PNG -- as layers over a solid clip, preview and export
async function transparent(p, where) {
  await freshVideo(p);
  const base = path.join(OUT, 'solid.png'); await writeFrom(p, () => { const c = document.createElement('canvas'); c.width = 320; c.height = 320; const x = c.getContext('2d'); x.fillStyle = '#20A040'; x.fillRect(0, 0, 320, 320); return c.toDataURL('image/png'); }, null, base);
  await importV(p, [base, path.join(OUT, 'alpha.webm'), path.join(OUT, 'alpha.zip')]);
  const info = await V(p, () => { const K = window.KBB_VIDEO; return Object.values(K.media).map((m) => ({ id: m.id, k: m.k, alpha: !!m.alpha, w: m.w, h: m.h, dur: m.dur, name: m.name })); });
  const wm = info.find((m) => m.name === 'alpha.webm'), zm = info.find((m) => m.name === 'alpha.zip'), im = info.find((m) => m.name === 'solid.png');
  ok(wm && wm.alpha && zm && zm.alpha && zm.dur === 2 && wm.w === 320, `${where}: imported a WebM with alpha (${wm && wm.w}×${wm && wm.h}, see-through: ${wm && wm.alpha}) and a PNG sequence (${zm && Math.round(zm.dur * FPS)} frames, ${zm && zm.dur} s)`);
  await V(p, ([b, w, z]) => {
    const K = window.KBB_VIDEO; K.vp.ab = { w: 320, h: 640 }; K.vp.clips = K.vp.clips.filter((c) => c.m === b); K.vp.clips[0].b = 2; K.vp.fps = 30; K.changed('ab');
    K.seek(0); K.addOv({ t: 'img', im: 'm:' + w, w: 100, s: 0, e: 2, y: 25, ain: 'none', aout: 'none' }); K.addOv({ t: 'img', im: 'm:' + z, w: 100, s: 0, e: 2, y: 75, ain: 'none', aout: 'none' });
  }, [im.id, wm.id, zm.id]);
  // the disc: centre x = 110 + 50 t (of 320), y = 160 of the 320 square; each layer is 320 wide, centred at y 160 / 480
  const sample = async (t) => { await drawAt(p, t); await p.waitForTimeout(150); await drawAt(p, t);
    return V(p, (t) => { const x = window.__F.getContext('2d'), px = (a, b) => Array.from(x.getImageData(Math.round(a), Math.round(b), 1, 1).data).slice(0, 3), cx = 110 + 50 * t; return { wIn: px(cx, 160), wOut: px(cx + 130, 160 + 120), zIn: px(cx, 480), zOut: px(cx + 130, 480 - 120) }; }, t); };
  const s1 = await sample(1.0), near = (a, b, d) => a.every((v, i) => Math.abs(v - b[i]) <= (d || 14));
  ok(near(s1.wIn, [232, 48, 111]) && near(s1.wOut, [32, 160, 64]) && near(s1.zIn, [232, 48, 111]) && near(s1.zOut, [32, 160, 64]),
    `${where}: preview at 1.0 s: the disc ${JSON.stringify(s1.wIn)} / ${JSON.stringify(s1.zIn)} over the green clip, the clip shows round it ${JSON.stringify(s1.wOut)} / ${JSON.stringify(s1.zOut)} (WebM / PNG sequence)`);
  await V(p, () => { const K = window.KBB_VIDEO; K.seek(1.0); K.select('ov', K.vp.ovs[0].id); K.showTab('stick'); }); await p.waitForTimeout(500);
  if (where === 'standalone') await shot(p, 'video-transparent-layers-1280');
  await V(p, () => window.KBB_VIDEO.showTab('export'));
  const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 300000 }), p.click('#vx-go')]);
  const mp4 = path.join(OUT, where + '-layers.mp4'); await dl.saveAs(mp4);
  const fr = (t) => { const raw = cp.execFileSync('ffmpeg', ['-v', 'error', '-ss', String(t + 0.5 / FPS), '-i', mp4, '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-']); return (a, b) => { const i = (Math.round(b) * 320 + Math.round(a)) * 3; return [raw[i], raw[i + 1], raw[i + 2]]; }; };
  const e1 = fr(1.0), cx = 160;
  const ex = { wIn: e1(cx, 160), wOut: e1(cx + 130, 280), zIn: e1(cx, 480), zOut: e1(cx + 130, 360) };
  ok(near(ex.wIn, [232, 48, 111], 20) && near(ex.wOut, [32, 160, 64], 20) && near(ex.zIn, [232, 48, 111], 20) && near(ex.zOut, [32, 160, 64], 20),
    `${where}: the exported MP4 at 1.0 s keeps the alpha too: disc ${JSON.stringify(ex.wIn)} / ${JSON.stringify(ex.zIn)}, round it ${JSON.stringify(ex.wOut)} / ${JSON.stringify(ex.zOut)}`);
  // and on a still ad: the first frame as a transparent picture
  await p.click('#modesw button[data-m="ads"]');
  const still = [];
  for (const f of ['alpha.webm', 'alpha.zip', 'alpha.png']) {
    await p.evaluate(() => window.KBB_STUDIO.useTemplate('flash-sale'));
    await p.setInputFiles('#add-img', path.join(OUT, f));
    await p.waitForFunction(() => window.KBB_STUDIO.doc.els.some((e) => e.t === 'img'), null, { timeout: 30000 });
    const src = await p.evaluate(() => { const S = window.KBB_STUDIO, e = S.doc.els.filter((x) => x.t === 'img').pop(); return S.images[e.img].src; });
    still.push(await alphaAt(p, src, [[0.03, 0.03], [110 / 320, 0.5], [0.97, 0.97]]));
  }
  ok(still.every((a) => a[0] < 8 && a[1] > 245 && a[2] < 8), `${where}: on a still ad (Ads → Image…) WebM / zip / PNG come in see-through: alpha corner·disc·corner ${still.map((a) => a.join('·')).join(' | ')}`);
  await p.click('#modesw button[data-m="video"]');
}

// an old design (no cut-out fields) draws exactly as the build before this change: an Ads PNG and a video frame
async function oldDesign(b) {
  const old = path.join(OUT, 'old-ad-studio.html');
  try { fs.writeFileSync(old, cp.execFileSync('git', ['show', BASE + ':docs/plans/ad-studio/dist/ad-studio.html'], { cwd: ROOT, maxBuffer: 1 << 28 })); } catch (e) { ok(false, 'old build from git ' + BASE + ': ' + e.message); return; }
  if (fs.readFileSync(old).equals(fs.readFileSync(DIST))) { console.log('note: ' + BASE + "'s build is this build -- run with --base <the commit before the cut-out lane>"); }
  const clip = path.join(OUT, 'person.mp4'), res = [];
  for (const f of [old, DIST]) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    const { p } = await open(ctx, 'file://' + f, 'ads');
    const ad = await p.evaluate(async () => {
      const S = window.KBB_STUDIO; S.useTemplate('flash-sale'); await new Promise((r) => setTimeout(r, 300));
      const bm = await createImageBitmap(await S.renderFile('post', 0, 'png')), c = document.createElement('canvas'); c.width = bm.width; c.height = bm.height; c.getContext('2d').drawImage(bm, 0, 0);
      const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let h = 0; for (let i = 0; i < d.length; i++) h = (h * 31 + d[i]) >>> 0; return { size: bm.width + '×' + bm.height, h };
    }).catch((e) => ({ err: e.message }));
    await p.click('#modesw button[data-m="video"]'); await p.waitForFunction(() => document.documentElement.dataset.vready === '1');
    await p.click('#v-new'); await p.setInputFiles('#v-file', [clip]); await p.waitForFunction(() => /Imported/.test(document.getElementById('v-status').textContent), null, { timeout: 60000 });
    const vf = await p.evaluate(async () => {
      const K = window.KBB_VIDEO; K.vp.bg = { k: 'blur', c: '#111111' }; K.vp.clips[0].z = .8; K.addText({ txt: 'Old design', y: 20 }); K.addOv({ t: 'prod', p: 'anua', w: 30, y: 70, s: 0, e: 2 }); K.seek(1.0);
      await new Promise((r) => setTimeout(r, 600)); const c = K.preview ? K.preview() : document.getElementById('v-cv'); const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let h = 0; for (let i = 0; i < d.length; i += 7) h = (h * 31 + d[i]) >>> 0; return h;
    });
    res.push({ ad, vf }); await ctx.close();
  }
  ok(res[0].ad.h === res[1].ad.h && !res[0].ad.err, `an old design: the Ads PNG (flash-sale post, ${res[1].ad.size}) has the same pixels as ${BASE}'s build`);
  ok(res[0].vf === res[1].vf, `an old video project (no cut-out fields): the preview frame is pixel-identical to ${BASE}'s build`);
}

// save → reload: the cut-out comes back and draws the same pixels (the masks recomputed from the clip)
async function reload(ctx, p, where, url) {
  await freshVideo(p); await importV(p, [path.join(OUT, 'person.mp4')]);
  await V(p, () => { const K = window.KBB_VIDEO; K.vp.ab = { w: 540, h: 960 }; K.changed('ab'); K.select('clip', K.vp.clips[0].id); });
  await V(p, () => window.KBB_VIDEO.removeBg('person')); await V(p, () => window.KBB_VIDEO.setBgr({ mode: 'gradient', feather: .25 }, 'r'));
  await drawAt(p, 1.0); const h1 = await V(p, () => { const d = window.__F.getContext('2d').getImageData(0, 0, 540, 960).data; let s = 0; for (let i = 0; i < d.length; i++) s += d[i]; return s; });
  const vid = await V(p, () => window.KBB_VIDEO.vp.id); await V(p, () => window.KBB_VIDEO.saveNow());
  await p.close();
  const q = await open(ctx, url, 'video');
  const back = await V(q.p, () => { const c = window.KBB_VIDEO.vp.clips[0]; return { id: window.KBB_VIDEO.vp.id, bgr: c && c.bgr }; });
  await drawAt(q.p, 1.0); const h2 = await V(q.p, () => { const d = window.__F.getContext('2d').getImageData(0, 0, 540, 960).data; let s = 0; for (let i = 0; i < d.length; i++) s += d[i]; return s; });
  ok(back.id === vid && back.bgr && back.bgr.key === 'person' && back.bgr.mode === 'gradient' && back.bgr.feather === .25 && Math.abs(h1 - h2) / h1 < 0.002, `${where}: save → reload: the cut-out and its settings come back ${JSON.stringify(back.bgr)} and draw the same frame (Σ ${h1} vs ${h2})`);
  return q;
}

(async () => {
  alphaMedia();
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  // ── standalone (file://) ──
  if (!ONLY_STUDIO) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    let { p, errs, net } = await open(ctx, FILE, 'ads');
    await makeClips(p);
    ok(await p.evaluate(() => window.KBB_CUT.inside() === false && !!window.KBB_LOOK.gl()), 'standalone: KBB_CUT runs as a file, WebGL2 look engine up');
    ok(await p.evaluate(() => window.KBB_CUT.subjectState()) === 'standalone', 'standalone: the main-subject model is not offered (no engine, no models folder)');
    await photos(p, 'standalone', false);
    await p.click('#modesw button[data-m="video"]'); await p.waitForFunction(() => document.documentElement.dataset.vready === '1');
    nums.standalone = await videoRemoval(p, 'standalone', false);
    await transparent(p, 'standalone');
    const q = await reload(ctx, p, 'standalone', FILE);
    // 390 px: the panel fits the phone (no sideways scroll)
    await q.p.setViewportSize({ width: 390, height: 844 });
    await V(q.p, () => { const K = window.KBB_VIDEO; K.select('clip', K.vp.clips[0].id); K.showTab('clip'); });
    await q.p.waitForTimeout(300); await q.p.evaluate(() => document.getElementById('v-bgr').scrollIntoView());
    const sw = await q.p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
    ok(sw[0] <= sw[1], `390 px: the Remove background panel fits (scrollWidth ${sw[0]} / ${sw[1]})`);
    await shot(q.p, 'video-remove-background-390');
    errs = errs.concat(q.errs); net = net.concat(q.net);
    ok(!errs.length, 'standalone: no console errors' + (errs.length ? ': ' + errs.slice(0, 4).join(' | ') : ''));
    ok(!net.length, 'standalone: no network requests' + (net.length ? ': ' + net.slice(0, 3).join(' ') : ''));
    await ctx.close();
  }
  if (!ONLY_STUDIO) await oldDesign(b);
  else { const ctx = await b.newContext(); const { p } = await open(ctx, FILE, 'ads'); await makeClips(p); await ctx.close(); }
  await b.close();

  // ── inside KBB Studio: its engine, the optional model downloaded through its installer, then deleted ──
  if (STUDIO) {
    const Hn = require(path.join(REEL, 'tools', 'studio-harness.cjs'));
    if (!fs.existsSync(Hn.BIN)) ok(false, 'the Linux engine is not built (studio/reel-studio/engine/build.sh)');
    else {
      const APP = Hn.makeStudio(), eng = await Hn.startEngine(APP);
      const bb = await Hn.launchChrome();
      try {
        const ctx = await bb.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
        const url = eng.url + '/app/adstudio/ad-studio.html';
        let { p, errs, net } = await open(ctx, url, 'ads');
        ok(await p.evaluate(() => window.KBB_CUT.inside()) && await p.evaluate(() => window.KBB_CUT.subjectState()) === 'missing', 'KBB Studio: the cut-out runs inside, the main-subject model is not installed yet');
        await shot(p, 'studio-before-model-1280');
        await photos(p, 'KBB Studio', true);
        const mfile = path.join(APP, 'models', 'BritishWerewolf', 'U-2-Netp', 'onnx', 'model.onnx');
        ok(fs.existsSync(mfile) && fs.statSync(mfile).size === 4574861, `KBB Studio: the model came through the engine's checked installer into its models folder (${fs.existsSync(mfile) ? fs.statSync(mfile).size : 0} bytes)`);
        await p.click('#modesw button[data-m="video"]'); await p.waitForFunction(() => document.documentElement.dataset.vready === '1');
        nums.studio = await videoRemoval(p, 'KBB Studio', true);
        await transparent(p, 'KBB Studio');
        const q = await reload(ctx, p, 'KBB Studio', url);
        errs = errs.concat(q.errs); net = net.concat(q.net);
        ok(!errs.length, 'KBB Studio: no console errors' + (errs.length ? ': ' + errs.slice(0, 4).join(' | ') : ''));
        ok(!net.length, 'KBB Studio: no request leaves 127.0.0.1 (the model came through the engine)' + (net.length ? ': ' + net.slice(0, 3).join(' ') : ''));
        await ctx.close();
      } finally {
        await bb.close(); await Hn.stopEngine(eng);
        fs.rmSync(APP, { recursive: true, force: true });
        ok(!fs.existsSync(APP), 'the throwaway KBB Studio folder and its downloaded model are deleted');
      }
    }
  }
  console.log('\nnumbers ' + JSON.stringify(nums, null, 1));
  console.log(fails ? `\n${fails} FAILED` : '\nALL PASS');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
