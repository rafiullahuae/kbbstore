/* KBB Ad Studio · cut-out engine (18 Nov 2026). The owner: remove backgrounds "on the video as well, in case we want to
 * remove the background from the portrait ads videos" -- and in the Ad Studio, photos and video ads alike.
 *
 * It is the video editor's code, not a second implementation. build.py inlines, from studio/reel-studio/app/look/:
 *   gl.js      the WebGL2 engine and its 'look' shader -- matte refinement (colour-guided upsampling of the low-res mask,
 *              colour-line matting for hair), Shrink/grow and Feather, chroma key with spill suppression, light wrap
 *   vision.js  the v2 mask store and its temporal filter (MASK_FPS, maskAt: Gaussian in time x per-pixel range weight)
 *   bg.js      BG_DEFAULTS, BG_KEYS, autoKeyColor (the green / blue screen colour measured from the frame's border)
 * as window.KBB_LOOK. This file only feeds them: which frames to analyse, which segmenter to ask, and the uniforms
 * fx.js's processLook() would set for a clip whose only effect is the background (grade, touch-up, adjust neutral).
 *
 * The segmenters:
 *   person   MediaPipe's selfie segmenter. Inside KBB Studio: its own vision-worker.js (off the main thread); opened as a
 *            file: ad-studio-cutout.js (the same model and runtime, packed next to this file, loaded on first use).
 *   subject  U²-Net-p, the optional 4.6 MB model (models/manifest.json 'subject-cutout', Apache-2.0): ONLY inside KBB
 *            Studio, through its subject.js -- the engine's checked installer downloads it once on one Allow, and its
 *            module worker runs it. Standalone there is no engine and no model folder, so it is not offered.
 *   chroma   no model: the shader keys the colour.
 *
 *   KBB_CUT.inside()                         running inside KBB Studio (the engine served this page)?
 *   KBB_CUT.cutoutLib()                      ad-studio-cutout.js (MediaPipe), loaded once
 *   KBB_CUT.subjectState() -> 'standalone' | 'missing' | 'ready' | 'error'
 *   KBB_CUT.installSubject(onProgress)       the one-click download through KBB Studio's model installer
 *   KBB_CUT.masksFor(kind, pictures)         [{w, h, data}] (person: long side 256; subject: 320 x 320)
 *   KBB_CUT.analyse({mid, kind, ks, grab, progress})   fills KBB_LOOK.MASKS[mid|kind] for grid frames ks
 *   KBB_CUT.pass(src, sw, sh, o)             the look pass; returns the GL canvas (RGBA) or null
 *   KBB_CUT.cutPhoto(img, mask, o)           a cut-out photo as a canvas (transparent where the background was)
 */
(function () {
  'use strict';
  var L = window.KBB_LOOK; if (!L) return;
  function inside() { return !!(window.KBB_DISK && window.KBB_DISK.inside); }
  var lookURL = function (f) { return new URL('../look/' + f, location.href).href; };

  var cutP = null;
  function cutoutLib() {
    if (window.KBB_CUTOUT) return Promise.resolve(window.KBB_CUTOUT);
    return cutP || (cutP = new Promise(function (res, rej) {
      var s = document.createElement('script'); s.src = new URL('ad-studio-cutout.js', location.href).href;
      s.onload = function () { window.KBB_CUTOUT ? res(window.KBB_CUTOUT) : rej(new Error('cut-out file is damaged')); };
      s.onerror = function () { cutP = null; rej(new Error('Cut-out needs the file ad-studio-cutout.js next to this one (it comes in the same folder)')); };
      document.head.appendChild(s);
    }));
  }

  // ── inside KBB Studio: the video editor's own modules, served by the engine next to this page (/app/look/) ──
  var subjP = null;
  function subject() { if (!inside()) return Promise.reject(new Error('The main-subject cut-out needs KBB Studio')); return subjP || (subjP = import(lookURL('subject.js')).catch(function (e) { subjP = null; throw e; })); }
  function subjectState() {
    if (!inside()) return Promise.resolve('standalone');
    return subject().then(function (S) { return S.subjectReady().then(function (r) { return r ? 'ready' : 'missing'; }); }, function () { return 'error'; });
  }
  function installSubject(onProgress) { return subject().then(function (S) { return S.installSubjectModel(onProgress || function () {}); }); }

  var VW = null, vseq = 0, vpend = Object.create(null);
  function vision() {
    if (VW) return VW;
    VW = new Worker(lookURL('vision-worker.js'));
    VW.onmessage = function (e) { var p = vpend[e.data.id]; if (!p) return; delete vpend[e.data.id]; if (e.data.error) p.rej(new Error(e.data.error)); else p.res(e.data.masks); };
    VW.onerror = function (e) { if (e.preventDefault) e.preventDefault(); Object.keys(vpend).forEach(function (k) { vpend[k].rej(new Error(e.message || 'the person finder stopped')); }); vpend = Object.create(null); VW = null; };
    return VW;
  }
  function masksFor(kind, bms) {
    if (kind === 'subject') return subject().then(function (S) { return S.subjectMasks(bms); });
    if (inside()) return new Promise(function (res, rej) { var id = ++vseq; vpend[id] = { res: res, rej: rej }; vision().postMessage({ id: id, op: 'seg', bitmaps: bms }, bms); });
    return cutoutLib().then(function (C) { return C.masks(bms); });
  }
  // the picture the segmenter sees: as vision.js sizes it (person: the shape kept, long side 256; subject: its 320 x 320)
  function dims(src) { return [src.naturalWidth || src.videoWidth || src.width, src.naturalHeight || src.videoHeight || src.height]; }
  function bitmapFor(kind, src) {
    var d = dims(src), k = 256 / Math.max(d[0], d[1]);
    var sz = kind === 'subject' ? [320, 320] : [Math.max(1, Math.round(d[0] * k)), Math.max(1, Math.round(d[1] * k))];
    return createImageBitmap(src, { resizeWidth: sz[0], resizeHeight: sz[1], resizeQuality: 'high' });
  }
  // analyse grid frames ks (at MASK_FPS[kind]) of one media: one decode per frame, batches of 8, then the cache of
  // filtered frames is dropped so nothing drawn while it ran survives
  function analyse(o) {
    var key = o.mid + '|' + o.kind, fps = L.MASK_FPS[o.kind], rec0 = L.MASKS.get(key);
    var need = o.ks.filter(function (k) { return !rec0 || !rec0.frames.has(k); }), done = 0, batch = [];
    if (!need.length) return Promise.resolve(0);
    function flush() {
      var b = batch; batch = []; if (!b.length) return Promise.resolve();
      return masksFor(o.kind, b.map(function (x) { return x.bm; })).then(function (r) {
        var rec = L.MASKS.get(key) || { w: r[0].w, h: r[0].h, fps: fps, frames: new Map() }; L.MASKS.set(key, rec);
        r.forEach(function (m, i) { rec.frames.set(b[i].k, m.data); });
        done += b.length; if (o.progress) o.progress(done / need.length);
      });
    }
    return need.reduce(function (p, k) {
      return p.then(function () { return o.grab(k / fps); }).then(function (src) { return bitmapFor(o.kind, src); })
        .then(function (bm) { batch.push({ k: k, bm: bm }); if (batch.length >= 8) return flush(); });
    }, Promise.resolve()).then(flush).then(function () { var r = L.MASKS.get(key); L.drop(key); if (r) L.MASKS.set(key, r); return need.length; });
  }

  // ── the look pass: fx.js processLook()'s uniforms for a clip whose only look.* effect is the background (v2) ──
  var BGMODE = { color: 2, gradient: 3, none: 5 };
  function rgb(c, d) { var m = /^#?([0-9a-f]{6})$/i.exec(c || ''); if (!m) return d; var n = parseInt(m[1], 16); return [(n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255]; }
  function domOf(c) { return c[1] >= c[0] && c[1] >= c[2] ? 1 : c[2] >= c[0] ? 2 : 0; }
  // o: {key, mode, mask {w,h,data}|null, color, colors[2], feather, grow, wrap, keyColor, tol, soft, spill, work (px on the long side)}
  function pass(src, sw, sh, o) {
    var g = L.gl(); if (!g || !sw || !sh) return null;
    var chroma = o.key === 'chroma'; if (!chroma && !o.mask) return null;
    var D = L.BG_DEFAULTS, B = L.BASE, mode = BGMODE[o.mode] || 5;
    var want = Math.max(256, Math.min(2160, o.work || Math.max(sw, sh))), k = Math.min(1, want / Math.max(sw, sh));
    var W = Math.max(2, Math.round(sw * k)), H = Math.max(2, Math.round(sh * k));
    var srcTex = g.upload('src', src, sw, sh, k < 0.6), kc = rgb(o.keyColor, [0.1, 0.75, 0.25]);
    var f = function (v, d) { return typeof v === 'number' && isFinite(v) ? v : d; };
    var u = {
      res: [W, H], frame: 0, amount: 0, mg: [1, 1, 1], mo: [0, 0, 0], wb: [1, 1, 1], ev: 0,
      Lexp: B.exp, Lcon: B.con, Lsat: B.sat, Lvib: B.vib, Ltemp: B.temp, Ltint: B.tint, Lfade: B.fade, Lbw: B.bw, Lglow: B.glow,
      Lvig: B.vig, Lgrain: B.grain, Lcurve: B.curve, Lclar: B.clar, Lsplit: B.split, Llift: B.lift, Lgamma: B.gamma, Lgain: B.gain, Lsh: B.sh, Lhi: B.hi, Lbwmix: B.bwmix,
      hasFace: 0, hasSeg: 0, bgMode: mode, Ts: 0, Teye: 0, Tunder: 0, hasAdj: 0, upS: 0, cmpOn: 0, cmpU: 0, srcRes: [sw, sh],
      q2: 1, keyMode: chroma ? 1 : 0, keyCol: kc, kDom: domOf(kc), kTol: f(o.tol, D.tol), kSoft: f(o.soft, D.soft), kSpill: f(o.spill, D.spill),
      eFeather: f(o.feather, D.feather), eGrow: f(o.grow, D.grow), eWrap: mode === 5 ? 0 : f(o.wrap, D.wrap),
      bgA: mode === 3 ? rgb(o.colors && o.colors[0], [0.78, 0.22, 0.37]) : rgb(o.color, [0.07, 0.07, 0.1]), bgB: rgb(o.colors && o.colors[1], [0.16, 0.13, 0.16]), bgScale: [1, 1], bgOff: [0, 0]
    };
    var tex = { src: srcTex, blurS: srcTex, blurL: srcTex };
    if (o.mask) { tex.seg = g.uploadR8('seg', o.mask.data, o.mask.w, o.mask.h); u.hasSeg = 1; u.segTexel = [1 / o.mask.w, 1 / o.mask.h]; }
    g.size(W, H); g.run('look', null, u, tex);
    return g.canvas;
  }
  function coverage(m) { var s = 0; for (var i = 0; i < m.data.length; i++) if (m.data[i] > 127) s++; return s / m.data.length; }

  // a photo: the picture (long side up to 1600) with the background made transparent. Without WebGL2: the mask alone,
  // the same threshold and feather as the shader's interior (smoothstep round .5), drawn over the photo
  function cutPhoto(img, mask, o) {
    var d = dims(img), k = Math.min(1, 1600 / Math.max(d[0], d[1])), W = Math.max(1, Math.round(d[0] * k)), H = Math.max(1, Math.round(d[1] * k));
    var out = document.createElement('canvas'); out.width = W; out.height = H; var x = out.getContext('2d');
    var g = pass(img, d[0], d[1], { key: o.key, mode: 'none', mask: mask, feather: o.feather, grow: o.grow, work: Math.max(W, H) });
    if (g) { x.drawImage(g, 0, 0, W, H); return out; }
    x.drawImage(img, 0, 0, W, H);
    var mc = document.createElement('canvas'); mc.width = mask.w; mc.height = mask.h; var mx = mc.getContext('2d'), id = mx.createImageData(mask.w, mask.h);
    var c = 0.5 - (o.grow || 0) * 0.3, wd = 0.12 + (o.feather == null ? L.BG_DEFAULTS.feather : o.feather) * 0.45;
    for (var i = 0; i < mask.data.length; i++) { var t = Math.max(0, Math.min(1, (mask.data[i] / 255 - (c - wd)) / (2 * wd))); id.data[i * 4 + 3] = Math.round(t * t * (3 - 2 * t) * 255); }
    mx.putImageData(id, 0, 0); x.save(); x.globalCompositeOperation = 'destination-in'; x.imageSmoothingQuality = 'high'; x.drawImage(mc, 0, 0, W, H); x.restore();
    return out;
  }
  // the photo's mask, once per picture and kind (Feather and a second cut reuse it)
  var pm = Object.create(null);
  function photoMask(id, img, kind) {
    var key = id + '|' + kind; if (pm[key]) return pm[key];
    return (pm[key] = bitmapFor(kind, img).then(function (bm) { return masksFor(kind, [bm]); }).then(function (r) { return r[0]; }).catch(function (e) { delete pm[key]; throw e; }));
  }

  window.KBB_CUT = { inside: inside, cutoutLib: cutoutLib, subjectState: subjectState, installSubject: installSubject, masksFor: masksFor, analyse: analyse, pass: pass, cutPhoto: cutPhoto, photoMask: photoMask, coverage: coverage, gl: function () { return !!L.gl(); } };
})();
