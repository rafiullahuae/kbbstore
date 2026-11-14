/* KBB Ad Studio -- Video mode. A compact video editor that runs from the one offline HTML file:
 * no server, no network, no ffmpeg. Clips play in <video> elements for the preview; every frame (preview,
 * still PNG and export) is drawn by ONE function, drawFrame(), so what you see is what you export.
 *
 * Export: WebCodecs (VideoEncoder H.264 + AudioEncoder AAC, or Opus where the browser has no AAC encoder)
 * muxed to MP4 by the vendored mp4-muxer, frame by frame, faster than real time. Where WebCodecs is
 * missing (Safari, Firefox) it falls back to MediaRecorder WebM, recorded in real time, and says so.
 *
 * Storage: IndexedDB 'kbb-ad-studio-video' (projects + media blobs), memory if the browser refuses.
 * Everything read back or imported goes through cleanVP() / cleanMeta(): ids match a pattern, colours a
 * regex, fonts / icons / products a fixed list, numbers are clamped, text is a bounded string drawn with
 * fillText (never parsed as HTML), and every media file must decode in a <video>/<audio>/<img> first.
 */
(function () {
  'use strict';
  var S = window.KBB_STUDIO && window.KBB_STUDIO.shared; if (!S) return;
  var FONTS = S.FONTS, ICONS = S.ICONS, D = S.D;
  function $(id) { return document.getElementById(id); }
  function mk(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function has(o, k) { return typeof k === 'string' && Object.prototype.hasOwnProperty.call(o, k); }
  function num(v, lo, hi, d) { v = +v; return isFinite(v) ? Math.max(lo, Math.min(hi, v)) : d; }
  function str(v, n) { return typeof v === 'string' ? v.slice(0, n) : ''; }
  function r3(n) { return Math.round(n * 1000) / 1000; }
  function hex(v, d) { return /^#[0-9a-fA-F]{6}$/.test(v) ? v.toUpperCase() : d; }
  function even(n) { n = Math.round(n); return n - n % 2; }
  function uid(p) { var a = new Uint8Array(6); crypto.getRandomValues(a); return p + Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  var VID = /^v[0-9a-f]{12}$/, MID = /^m[0-9a-f]{12}$/, EID = /^[co][0-9a-f]{12}$/, SIMG = S.IMGID;
  var ARABIC = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/;
  var FPS_LIST = [24, 25, 30, 60], MIN = 0.1, IMG_DEFAULT = 3;
  var PRESETS = { story: [1080, 1920, '9:16', 'Reel / Story'], post: [1080, 1350, '4:5', 'Post'], square: [1080, 1080, '1:1', 'Square'], wide: [1920, 1080, '16:9', 'Wide'] };
  var ANIMS = { none: 'None', fade: 'Fade', slide: 'Slide', pop: 'Pop' };
  var BADGES = { burst: 'Burst', circle: 'Circle', pill: 'Pill', star: 'Star', heart: 'Heart' };
  // accepted files: type -> kind. Extension is the fallback when the browser gives no type (common for .mov / .m4a).
  var TYPES = { 'video/mp4': 'video', 'video/quicktime': 'video', 'video/webm': 'video', 'video/x-m4v': 'video',
    'image/png': 'image', 'image/jpeg': 'image', 'image/webp': 'image',
    'audio/mpeg': 'audio', 'audio/mp3': 'audio', 'audio/mp4': 'audio', 'audio/x-m4a': 'audio', 'audio/m4a': 'audio', 'audio/aac': 'audio', 'audio/wav': 'audio', 'audio/x-wav': 'audio', 'audio/wave': 'audio', 'audio/webm': 'audio', 'audio/ogg': 'audio' };
  var EXT = { mp4: 'video/mp4', m4v: 'video/x-m4v', mov: 'video/quicktime', webm: 'video/webm', png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp', mp3: 'audio/mpeg', m4a: 'audio/mp4', aac: 'audio/aac', wav: 'audio/wav', ogg: 'audio/ogg' };
  var MAXB = { video: 1024 * 1048576, image: 30 * 1048576, audio: 200 * 1048576 }, MAXDUR = 1800;
  function extOf(type) { for (var k in EXT) if (EXT[k] === type) return k; return 'bin'; }

  // ── state ──
  var media = Object.create(null);     // id -> {id,name,k,type,size,dur,w,h,blob,url,el,thumbs,peaks}
  var vp = null, sel = null, ph = 0, playing = false, tab = 'media', pps = 60, hist = [], fut = [], lastJSON = '', coalesceKey = '', coalesceT = 0;
  var inited = false, saveT = null, statusEl = null;
  var studioImgs = S.images;

  // ── storage (IndexedDB; memory when the browser refuses) ──
  var VS = (function () {
    var db = null, mem = { p: {}, m: {} };
    function open() {
      return new Promise(function (res) {
        try {
          if (!window.indexedDB) return res(null);
          var rq = indexedDB.open('kbb-ad-studio-video', 1);
          rq.onupgradeneeded = function () { var d = rq.result; ['vprojects', 'media'].forEach(function (s) { if (!d.objectStoreNames.contains(s)) d.createObjectStore(s, { keyPath: 'id' }); }); };
          rq.onsuccess = function () { res(rq.result); }; rq.onerror = function () { res(null); }; rq.onblocked = function () { res(null); };
        } catch (e) { res(null); }
      });
    }
    function tx(store, mode, fn) {
      return new Promise(function (res, rej) {
        var t = db.transaction(store, mode), r = fn(t.objectStore(store)), out;
        if (r) r.onsuccess = function () { out = r.result; };
        t.oncomplete = function () { res(out); }; t.onerror = function () { rej(t.error); }; t.onabort = function () { rej(t.error || new Error('This browser refused to save (out of space?)')); };
      });
    }
    return {
      init: function () { return open().then(function (d) { db = d; return d ? 'browser' : 'memory'; }); },
      kind: function () { return db ? 'browser' : 'memory'; },
      allMedia: function () { return db ? tx('media', 'readonly', function (s) { return s.getAll(); }).then(function (a) { return a || []; }) : Promise.resolve(Object.keys(mem.m).map(function (k) { return mem.m[k]; })); },
      putMedia: function (r) { if (db) return tx('media', 'readwrite', function (s) { return s.put(r); }); mem.m[r.id] = r; return Promise.resolve(); },
      delMedia: function (id) { if (db) return tx('media', 'readwrite', function (s) { return s.delete(id); }); delete mem.m[id]; return Promise.resolve(); },
      list: function () { var f = function (a) { return (a || []).map(function (p) { return { id: p.id, name: str(p.name, 60), updated: +p.updated || 0 }; }); }; return db ? tx('vprojects', 'readonly', function (s) { return s.getAll(); }).then(f) : Promise.resolve(f(Object.keys(mem.p).map(function (k) { return mem.p[k]; }))); },
      get: function (id) { return db ? tx('vprojects', 'readonly', function (s) { return s.get(id); }).then(function (r) { return r ? r.doc : null; }) : Promise.resolve(mem.p[id] ? mem.p[id].doc : null); },
      put: function (d) { var r = { id: d.id, name: d.name, updated: Date.now(), doc: clone(d) }; if (db) return tx('vprojects', 'readwrite', function (s) { return s.put(r); }); mem.p[d.id] = r; return Promise.resolve(); },
      del: function (id) { if (db) return tx('vprojects', 'readwrite', function (s) { return s.delete(id); }); delete mem.p[id]; return Promise.resolve(); }
    };
  })();
  function lsGet(k) { try { return localStorage.getItem('kbbas:' + k); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem('kbbas:' + k, v); } catch (e) {} }

  // ── validation ──
  function cleanAnim(v, d) { return has(ANIMS, v) ? v : d; }
  function cleanOv(o) {
    if (!o || typeof o !== 'object' || !has({ text: 1, icon: 1, prod: 1, logo: 1, pay: 1, img: 1, badge: 1 }, o.t)) return null;
    var s = num(o.s, 0, 3600, 0), e = num(o.e, 0, 3600, s + 3); if (e - s < MIN) e = s + MIN;
    var c = { id: EID.test(o.id) && o.id[0] === 'o' ? o.id : uid('o'), t: o.t, s: r3(s), e: r3(e), x: num(o.x, -50, 150, 50), y: num(o.y, -50, 150, 50), r: num(o.r, -180, 180, 0), op: num(o.op, 0, 100, 100), ain: cleanAnim(o.ain, 'fade'), aout: cleanAnim(o.aout, 'fade') };
    if (o.t === 'text' || o.t === 'badge') {
      c.txt = str(o.txt, 300); c.fn = has(FONTS, o.fn) ? o.fn : 'poppins'; c.fw = [400, 500, 600, 700, 800, 900].indexOf(+o.fw) >= 0 ? +o.fw : 800;
      c.fs = num(o.fs, 1, 40, 7); c.tc = hex(o.tc, '#FFFFFF'); c.fi = o.fi === true; c.up = o.up === true;
    }
    if (o.t === 'text') { c.al = has({ l: 1, c: 1, r: 1 }, o.al) ? o.al : 'c'; c.pill = o.pill === true; c.pc = hex(o.pc, '#E8306F'); c.ol = num(o.ol, 0, 20, 0); c.oc = hex(o.oc, '#000000'); c.sh = num(o.sh, 0, 100, 40); }
    else c.w = num(o.w, 2, 200, 30);
    if (o.t === 'badge') { c.shape = has(BADGES, o.shape) ? o.shape : 'burst'; c.col = hex(o.col, '#E8306F'); }
    if (o.t === 'icon') { c.ic = has(ICONS, o.ic) ? o.ic : 'sparkle'; c.col = hex(o.col, '#FFFFFF'); }
    if (o.t === 'logo') { c.col = hex(o.col, '#FFFFFF'); }
    if (o.t === 'prod') { if (!has(D.prods, o.p)) return null; c.p = o.p; c.sh = num(o.sh, 0, 100, 50); }
    if (o.t === 'pay') c.pay = has({ both: 1, tabby: 1, tamara: 1 }, o.pay) ? o.pay : 'both';
    if (o.t === 'img') { if (typeof o.im !== 'string') return null; var mm = /^m:(.*)$/.exec(o.im), ss = /^s:(.*)$/.exec(o.im); if (!(mm && MID.test(mm[1])) && !(ss && SIMG.test(ss[1]))) return null; c.im = o.im; }
    return c;
  }
  function cleanVP(r) {
    if (!r || typeof r !== 'object') return null;
    var ab = r.ab || {}, o = { app: 'kbb-video', v: 1, id: VID.test(r.id) ? r.id : uid('v'), name: str(r.name, 60) || 'Untitled video',
      ab: { w: even(num(ab.w, 240, 3840, 1080)), h: even(num(ab.h, 240, 3840, 1920)) }, bg: { k: r.bg && r.bg.k === 'color' ? 'color' : 'blur', c: hex(r.bg && r.bg.c, '#111111') },
      fps: FPS_LIST.indexOf(+r.fps) >= 0 ? +r.fps : 30, clips: [], ovs: [], au: null };
    (Array.isArray(r.clips) ? r.clips.slice(0, 400) : []).forEach(function (c) {
      if (!c || !MID.test(c.m) || !media[c.m] || media[c.m].k === 'audio') return;
      var m = media[c.m], hi = m.k === 'video' ? m.dur : 600, a = num(c.a, 0, hi, 0), b = num(c.b, 0, hi, m.k === 'video' ? m.dur : IMG_DEFAULT);
      if (b - a < MIN) return;
      o.clips.push({ id: EID.test(c.id) && c.id[0] === 'c' ? c.id : uid('c'), m: c.m, a: r3(a), b: r3(b), fit: c.fit === 'fit' ? 'fit' : 'fill', z: num(c.z, .2, 5, 1), px: num(c.px, -100, 100, 0), py: num(c.py, -100, 100, 0), r: num(c.r, -180, 180, 0), mute: c.mute === true, vol: num(c.vol, 0, 200, 100) });
    });
    (Array.isArray(r.ovs) ? r.ovs.slice(0, 200) : []).forEach(function (x) { var c = cleanOv(x); if (c) o.ovs.push(c); });
    var u = r.au;
    if (u && MID.test(u.m) && media[u.m] && media[u.m].k === 'audio') {
      var md = media[u.m].dur, a2 = num(u.a, 0, md, 0), b2 = num(u.b, 0, md, md);
      if (b2 - a2 >= MIN) o.au = { m: u.m, a: r3(a2), b: r3(b2), o: r3(num(u.o, -3600, 3600, 0)), vol: num(u.vol, 0, 200, 100), fi: num(u.fi, 0, 10, 0), fo: num(u.fo, 0, 10, 0) };
    }
    return o;
  }
  function cleanMeta(r) {
    if (!r || !MID.test(r.id) || !has(TYPES, r.type)) return null;
    return { id: r.id, name: str(r.name, 80) || 'Clip', k: TYPES[r.type], type: r.type, size: num(r.size, 0, 2e9, 0) };
  }

  // ── media: import, validate (must decode), thumbnails, waveform ──
  function probe(m) {
    return new Promise(function (res, rej) {
      var el, done = false, to = setTimeout(function () { fin(new Error('timed out')); }, 20000);
      function fin(err) { if (done) return; done = true; clearTimeout(to); if (err) rej(err); else res(el); }
      if (m.k === 'image') { el = new Image(); el.onload = function () { m.w = el.naturalWidth; m.h = el.naturalHeight; m.dur = 0; fin(m.w ? null : new Error('empty picture')); }; el.onerror = function () { fin(new Error('not a picture this browser can open')); }; el.src = m.url; return; }
      el = document.createElement(m.k === 'video' ? 'video' : 'audio'); el.preload = 'auto'; el.playsInline = true; el.setAttribute('playsinline', ''); el.crossOrigin = 'anonymous';
      el.onloadedmetadata = function () {
        m.dur = el.duration; if (!isFinite(m.dur) || m.dur <= 0 || m.dur > MAXDUR) return fin(new Error(isFinite(m.dur) && m.dur > MAXDUR ? 'longer than 30 minutes' : 'no length'));
        if (m.k === 'video') { m.w = el.videoWidth; m.h = el.videoHeight; if (!m.w || !m.h) return fin(new Error('no picture in it')); }
        if (m.k === 'video') el.onloadeddata = function () { fin(null); }; else fin(null);
        if (m.k === 'video' && el.readyState >= 2) fin(null);
      };
      el.onerror = function () { fin(new Error(m.k === 'video' ? 'this browser cannot play that video (an iPhone .mov in HEVC? Settings → Camera → Formats → Most Compatible)' : 'this browser cannot play that sound file')); };
      el.src = m.url; el.load();
    });
  }
  function seekTo(v, t) {
    return new Promise(function (res) {
      t = Math.max(0, Math.min((v.duration || 0) - 0.001, t));
      if (Math.abs(v.currentTime - t) < 0.0005 && v.readyState >= 2) return res();
      var to = setTimeout(done, 4000);
      function done() { clearTimeout(to); v.removeEventListener('seeked', done); res(); }
      v.addEventListener('seeked', done); v.currentTime = t;
    });
  }
  function makeThumbs(m) {
    if (m.k === 'image') { var c = mk('canvas'); c.height = 64; c.width = Math.max(8, Math.round(64 * m.w / m.h)); c.getContext('2d').drawImage(m.el, 0, 0, c.width, c.height); m.thumbs = [{ t: 0, c: c }]; return Promise.resolve(); }
    if (m.k !== 'video') return Promise.resolve();
    var v = document.createElement('video'); v.muted = true; v.preload = 'auto'; v.src = m.url; var n = Math.max(2, Math.min(40, Math.ceil(m.dur))), out = [];
    return new Promise(function (r) { if (v.readyState >= 1) r(); else v.onloadedmetadata = r; }).then(function () {
      return Array.from({ length: n }).reduce(function (p, _, i) {
        return p.then(function () { var t = (i + .5) * m.dur / n; return seekTo(v, t).then(function () { var c = mk('canvas'); c.height = 64; c.width = Math.max(8, Math.round(64 * m.w / m.h)); try { c.getContext('2d').drawImage(v, 0, 0, c.width, c.height); } catch (e) {} out.push({ t: t, c: c }); }); });
      }, Promise.resolve());
    }).then(function () { m.thumbs = out; v.removeAttribute('src'); v.load(); });
  }
  function decodeAudio(m) {
    return m.blob.arrayBuffer().then(function (ab) {
      var C = window.OfflineAudioContext || window.webkitOfflineAudioContext; if (!C) return null;
      var ctx = new C(2, 48000, 48000);
      return new Promise(function (res) { try { var p = ctx.decodeAudioData(ab, res, function () { res(null); }); if (p && p.catch) p.catch(function () { res(null); }); } catch (e) { res(null); } });
    }).catch(function () { return null; });
  }
  function makePeaks(m) {
    if (m.k === 'image') { m.peaks = null; return Promise.resolve(); }
    return decodeAudio(m).then(function (buf) {
      if (!buf) { m.peaks = null; m.silent = true; return; }
      var per = 50, n = Math.ceil(buf.duration * per), d0 = buf.getChannelData(0), d1 = buf.numberOfChannels > 1 ? buf.getChannelData(1) : d0, step = buf.sampleRate / per, pk = new Float32Array(n), mx = 0;
      for (var i = 0; i < n; i++) { var a = Math.floor(i * step), b = Math.min(d0.length, Math.floor((i + 1) * step)), p = 0; for (var j = a; j < b; j += 4) { var s = Math.max(Math.abs(d0[j]), Math.abs(d1[j])); if (s > p) p = s; } pk[i] = p; if (p > mx) mx = p; }
      m.peaks = pk; m.per = per; m.silent = mx < 0.002;
    });
  }
  function register(rec, blob) {
    var m = { id: rec.id, name: rec.name, k: rec.k, type: rec.type, size: blob.size, blob: blob, url: URL.createObjectURL(blob), thumbs: [], peaks: null };
    return probe(m).then(function (el) { m.el = el; if (el.tagName === 'VIDEO' || el.tagName === 'AUDIO') { el.preload = 'auto'; el.addEventListener('seeked', function () { if (!playing) drawPreview(); }); } media[m.id] = m; return m; },
      function (e) { URL.revokeObjectURL(m.url); throw e; });
  }
  function importFiles(list) {
    var files = Array.prototype.slice.call(list || [], 0, 30), added = [], bad = [];
    if (!files.length) return Promise.resolve([]);
    status('Importing ' + files.length + ' file(s)…');
    return files.reduce(function (p, f) {
      return p.then(function () {
        var type = f.type || EXT[(f.name.split('.').pop() || '').toLowerCase()] || '';
        if (type === 'audio/x-m4a' || type === 'audio/m4a') type = 'audio/mp4';
        if (!has(TYPES, type)) { bad.push(f.name + ': not a video, picture or sound file'); return; }
        var k = TYPES[type]; if (f.size > MAXB[k]) { bad.push(f.name + ': too large'); return; }
        var rec = { id: uid('m'), name: str(f.name, 80), k: k, type: type };
        return register(rec, f.slice(0, f.size, type)).then(function (m) {
          return VS.putMedia({ id: m.id, name: m.name, k: m.k, type: m.type, size: m.size, blob: m.blob }).catch(function (e) { bad.push(m.name + ': not saved (' + ((e && e.message) || 'storage') + ')'); })
            .then(function () { added.push(m); renderBin(); return Promise.all([makeThumbs(m), makePeaks(m)]).catch(function () {}); });
        }, function (e) { bad.push(f.name + ': ' + e.message); });
      });
    }, Promise.resolve()).then(function () {
      added.forEach(function (m) { if (m.k === 'audio') { if (!vp.au) setMusic(m.id, true); } else addClip(m.id, true); });
      if (added.length) changed('import');
      renderBin(); renderTimeline();
      status(bad.length ? 'Imported ' + added.length + '. Skipped: ' + bad.join(' · ') : 'Imported ' + added.length + ' file(s)', bad.length ? 'warn' : 'ok');
      return added;
    });
  }

  // ── project model helpers ──
  function newVP() { return cleanVP({ id: uid('v'), name: 'Untitled video', ab: { w: 1080, h: 1920 } }); }
  function starts() { var t = 0; return vp.clips.map(function (c) { var s = t; t += c.b - c.a; return s; }); }
  function total() { var t = 0; vp.clips.forEach(function (c) { t += c.b - c.a; }); if (!vp.clips.length) vp.ovs.forEach(function (o) { t = Math.max(t, o.e); }); return t; }
  function clipAt(t) { var s = 0; for (var i = 0; i < vp.clips.length; i++) { var c = vp.clips[i], d = c.b - c.a; if (t < s + d || i === vp.clips.length - 1) return { c: c, i: i, s: s, local: Math.min(c.b, c.a + Math.max(0, t - s)) }; s += d; } return null; }
  function selClip() { return sel && sel.k === 'clip' ? vp.clips.filter(function (c) { return c.id === sel.id; })[0] : null; }
  function selOv() { return sel && sel.k === 'ov' ? vp.ovs.filter(function (o) { return o.id === sel.id; })[0] : null; }
  function addClip(mid, quiet) {
    var m = media[mid]; if (!m || m.k === 'audio') return;
    var c = cleanVP({ clips: [{ id: uid('c'), m: mid, a: 0, b: m.k === 'video' ? m.dur : IMG_DEFAULT }] }).clips[0]; if (!c) return;
    vp.clips.push(c); sel = { k: 'clip', id: c.id }; if (!quiet) changed('add');
  }
  function setMusic(mid, quiet) { var m = media[mid]; if (!m) return; vp.au = { m: mid, a: 0, b: r3(m.dur), o: 0, vol: 100, fi: 0, fo: 0 }; sel = { k: 'au' }; if (!quiet) changed('music'); }
  function split() {
    var h = clipAt(ph); if (!h) return status('Add a clip first', 'warn');
    var c = h.c, cut = r3(h.local);
    if (cut - c.a < MIN || c.b - cut < MIN) return status('Move the playhead inside a clip to split it', 'warn');
    var d = clone(c); d.id = uid('c'); d.a = cut; c.b = cut; vp.clips.splice(h.i + 1, 0, d); sel = { k: 'clip', id: d.id }; changed('split'); status('Split at ' + tc(ph), 'ok');
  }
  function removeSel() {
    if (!sel) return;
    if (sel.k === 'clip') vp.clips = vp.clips.filter(function (c) { return c.id !== sel.id; });
    else if (sel.k === 'ov') vp.ovs = vp.ovs.filter(function (o) { return o.id !== sel.id; });
    else if (sel.k === 'au') vp.au = null;
    sel = null; ph = Math.min(ph, total()); changed('del');
  }
  function duplicate() {
    var c = selClip(), o = selOv();
    if (c) { var d = clone(c); d.id = uid('c'); vp.clips.splice(vp.clips.indexOf(c) + 1, 0, d); sel = { k: 'clip', id: d.id }; changed('dup'); }
    else if (o) { var e = clone(o); e.id = uid('o'); e.x = Math.min(100, e.x + 3); e.y = Math.min(100, e.y + 3); vp.ovs.push(e); sel = { k: 'ov', id: e.id }; changed('dup'); }
  }

  // ── undo / redo + autosave ──
  function changed(key) {
    var j = JSON.stringify(vp), now = Date.now();
    if (j !== lastJSON) {
      if (!(key && key === coalesceKey && now - coalesceT < 800)) { hist.push(lastJSON); if (hist.length > 100) hist.shift(); }
      fut = []; lastJSON = j; coalesceKey = key || ''; coalesceT = now;
      clearTimeout(saveT); saveT = setTimeout(saveNow, 900); status('Unsaved changes…');
    }
    renderAll();
  }
  function undo() { if (!hist.length) return; fut.push(lastJSON); lastJSON = hist.pop(); vp = cleanVP(JSON.parse(lastJSON)); coalesceKey = ''; sel = null; clearTimeout(saveT); saveT = setTimeout(saveNow, 900); renderAll(); }
  function redo() { if (!fut.length) return; hist.push(lastJSON); lastJSON = fut.pop(); vp = cleanVP(JSON.parse(lastJSON)); coalesceKey = ''; sel = null; clearTimeout(saveT); saveT = setTimeout(saveNow, 900); renderAll(); }
  function saveNow() {
    clearTimeout(saveT); vp.name = str($('v-name').value, 60) || 'Untitled video';
    return VS.put(vp).then(function () { lsSet('vlast', vp.id); status(VS.kind() === 'browser' ? 'Saved' : 'Kept until you close this tab — this browser does not allow saving', VS.kind() === 'browser' ? 'ok' : 'warn'); listProjects(); }, function (e) { status('Not saved: ' + ((e && e.message) || 'storage refused'), 'warn'); });
  }
  function openVP(d) { stop(); vp = d; lastJSON = JSON.stringify(vp); hist = []; fut = []; sel = null; ph = 0; $('v-name').value = vp.name; lsSet('vlast', vp.id); renderAll(); seekPreview(); }

  // ── drawing: one function for preview, still frame and export ──
  var imgCache = Object.create(null);
  function imgFor(key, src) { var c = imgCache[key]; if (c) return c.ok ? c.im : null; c = imgCache[key] = { im: new Image(), ok: false }; c.im.onload = function () { c.ok = true; if (!playing) drawPreview(); }; c.im.src = src; return null; }
  function svgImg(ic, col, vb) {
    var e = ICONS[ic]; if (!e) return null;
    var s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' + (vb || e[2] || '0 0 24 24') + '" width="512" height="512" style="color:' + col + '">' + e[1] + '</svg>';
    return imgFor('ic:' + ic + col + (vb || ''), 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(s));
  }
  function ovImage(o) {
    if (o.t === 'prod') return imgFor('p:' + o.p, D.prods[o.p].src);
    if (o.t === 'icon') return svgImg(o.ic, o.col);
    if (o.t === 'img') { var mm = /^m:(.*)$/.exec(o.im); if (mm) { var m = media[mm[1]]; return m && m.k === 'image' ? m.el : null; } var s = studioImgs[o.im.slice(2)]; return s ? imgFor('s:' + o.im, s.src) : null; }
    return null;
  }
  function ease(p) { return 1 - Math.pow(1 - p, 3); }
  function animAt(o, t) {
    var st = { a: 1, dy: 0, sc: 1 }, L = Math.min(.45, (o.e - o.s) / 2);
    [[o.ain, (t - o.s) / L], [o.aout, (o.e - t) / L]].forEach(function (q) {
      var p = Math.max(0, Math.min(1, q[1])); if (p >= 1 || q[0] === 'none') return; var e = ease(p);
      if (q[0] === 'fade') st.a *= e; else if (q[0] === 'slide') { st.a *= e; st.dy += (1 - e) * .06; } else if (q[0] === 'pop') { st.a *= Math.min(1, p / .4); st.sc *= p < .7 ? .55 + .51 * p / .7 : 1.06 - .06 * (p - .7) / .3; }
    });
    return st;
  }
  function rrect(x, cx, cy, w, h, r) { r = Math.min(r, w / 2, h / 2); var l = cx - w / 2, t = cy - h / 2; x.beginPath(); x.moveTo(l + r, t); x.arcTo(l + w, t, l + w, t + h, r); x.arcTo(l + w, t + h, l, t + h, r); x.arcTo(l, t + h, l, t, r); x.arcTo(l, t, l + w, t, r); x.closePath(); }
  function shapePath(x, shape, w) {
    var h = w, i, a; x.beginPath();
    if (shape === 'circle') { x.arc(0, 0, w / 2, 0, Math.PI * 2); return h; }
    if (shape === 'pill') { h = w * .42; rrect(x, 0, 0, w, h, h / 2); return h; }
    if (shape === 'heart') { for (i = 0; i < 48; i++) { var t = i / 48 * Math.PI * 2, px = 16 * Math.pow(Math.sin(t), 3), py = 13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t); x[i ? 'lineTo' : 'moveTo'](px * w / 34, -py * w / 34 - w * .03); } x.closePath(); return h; }
    var n = shape === 'star' ? 5 : 14, r1 = w / 2, r2 = shape === 'star' ? w * .21 : w * .41;
    for (i = 0; i < n * 2; i++) { a = i / (n * 2) * Math.PI * 2; var rr = i % 2 ? r2 : r1; x[i ? 'lineTo' : 'moveTo'](Math.sin(a) * rr, -Math.cos(a) * rr); }
    x.closePath(); return h;
  }
  function fontOf(o, px) { return (o.fi ? 'italic ' : '') + o.fw + ' ' + px.toFixed(2) + 'px "' + FONTS[o.fn] + '", Cairo, sans-serif'; }
  function txtOf(o) { return o.up ? o.txt.toUpperCase() : o.txt; }
  // draws one overlay centred at 0,0 (already translated / rotated / scaled); returns its box size for hit tests
  function drawOv(x, o, W, H) {
    if (o.t === 'text') {
      var fpx = o.fs / 100 * W, lines = txtOf(o).split('\n'), rtl = ARABIC.test(o.txt), lh = fpx * 1.22;
      x.font = fontOf(o, fpx); x.direction = rtl ? 'rtl' : 'ltr'; x.textBaseline = 'middle';
      var ws = lines.map(function (l) { return x.measureText(l).width; }), bw = Math.max.apply(null, ws.concat([fpx * .3])), fit = (o.pill ? W * .86 - fpx : W * .92) / bw;
      if (fit < 1) { fpx *= fit; lh *= fit; bw *= fit; x.font = fontOf(o, fpx); }      // a line never runs off the frame: it shrinks to fit
      var bh = lines.length * lh;
      var padx = o.pill ? fpx * .5 : fpx * .1, pady = o.pill ? fpx * .22 : fpx * .05, sh = o.sh / 100;
      if (sh) { x.shadowColor = 'rgba(0,0,0,' + (.6 * sh).toFixed(3) + ')'; x.shadowBlur = fpx * .3 * sh; x.shadowOffsetY = fpx * .06 * sh; }
      if (o.pill) { x.fillStyle = o.pc; rrect(x, 0, 0, bw + padx * 2, bh + pady * 2, Math.min(fpx * .7, (bh + pady * 2) / 2)); x.fill(); x.shadowColor = 'transparent'; }
      var tx = o.al === 'l' ? -bw / 2 : o.al === 'r' ? bw / 2 : 0; x.textAlign = o.al === 'l' ? 'left' : o.al === 'r' ? 'right' : 'center';
      lines.forEach(function (l, i) {
        var y = -bh / 2 + lh * (i + .5);
        if (o.ol > 0) { x.lineJoin = 'round'; x.miterLimit = 2; x.lineWidth = fpx * o.ol / 50; x.strokeStyle = o.oc; x.strokeText(l, tx, y); x.shadowColor = 'transparent'; }
        x.fillStyle = o.tc; x.fillText(l, tx, y);
      });
      x.shadowColor = 'transparent';
      return [bw + padx * 2, bh + pady * 2];
    }
    var w = o.w / 100 * W;
    if (o.t === 'badge') {
      x.shadowColor = 'rgba(0,0,0,.3)'; x.shadowBlur = w * .05; x.shadowOffsetY = w * .02;
      x.fillStyle = o.col; var h = shapePath(x, o.shape, w); x.fill(); x.shadowColor = 'transparent';
      var t = txtOf(o); if (t) { var lines2 = t.split('\n'), fp = o.fs / 100 * W; x.textAlign = 'center'; x.textBaseline = 'middle'; x.direction = ARABIC.test(t) ? 'rtl' : 'ltr';
        for (var k = 0; k < 30; k++) { x.font = fontOf(o, fp); var mw = Math.max.apply(null, lines2.map(function (l) { return x.measureText(l).width; })); if (mw <= w * (o.shape === 'pill' ? .85 : .66) && fp * lines2.length * 1.1 <= h * .7) break; fp *= .92; }
        x.fillStyle = o.tc; lines2.forEach(function (l, i) { x.fillText(l, 0, (i - (lines2.length - 1) / 2) * fp * 1.1); }); }
      return [w, h];
    }
    if (o.t === 'pay') {
      var ims = [], hh = w * .22, gap = hh * .3, tw = 0;
      if (o.pay !== 'tamara') ims.push(imgFor('pay:tabby', D.pay.tabby)); if (o.pay !== 'tabby') ims.push(imgFor('pay:tamara', D.pay.tamara));
      ims.forEach(function (im) { tw += im ? hh * im.naturalWidth / im.naturalHeight : hh * 2.5; }); tw += gap * (ims.length - 1);
      var sc = Math.min(1, w / tw), cx = -tw * sc / 2;
      ims.forEach(function (im) { var iw = (im ? hh * im.naturalWidth / im.naturalHeight : hh * 2.5) * sc; if (im) x.drawImage(im, cx, -hh * sc / 2, iw, hh * sc); cx += iw + gap * sc; });
      return [w, hh * sc];
    }
    if (o.t === 'logo') {
      var bl = S.brand(); if (bl.logo && studioImgs[bl.logo]) { var li = imgFor('s:' + bl.logo, studioImgs[bl.logo].src); var lh2 = li ? w * li.naturalHeight / li.naturalWidth : w * .3; if (li) x.drawImage(li, -w / 2, -lh2 / 2, w, lh2); return [w, lh2]; }
      var fpx2 = w * .11, lot = svgImg('lotus', o.col, '0 0 24 18'), lw = fpx2 * 1.6, lh3 = fpx2 * 1.2;
      x.font = '700 ' + fpx2.toFixed(2) + 'px Poppins, sans-serif'; var tw2 = x.measureText('K-Beauty Bliss').width, all = lw + fpx2 * .4 + tw2, sc2 = Math.min(1, w / all);
      x.save(); x.scale(sc2, sc2); var l0 = -all / 2; if (lot) x.drawImage(lot, l0, -lh3 / 2, lw, lh3);
      x.fillStyle = o.col; x.textBaseline = 'middle'; x.textAlign = 'left'; x.direction = 'ltr'; x.fillText('K-Beauty Bliss', l0 + lw + fpx2 * .4, fpx2 * .05); x.restore();
      return [w, lh3 * sc2];
    }
    var im2 = ovImage(o), ar = im2 && im2.naturalWidth ? im2.naturalHeight / im2.naturalWidth : (o.t === 'prod' ? 1 / D.prods[o.p].a : 1), h2 = w * ar;
    if (o.t === 'prod' && o.sh) { x.shadowColor = 'rgba(0,0,0,' + (.45 * o.sh / 100).toFixed(3) + ')'; x.shadowBlur = w * .08 * o.sh / 100; x.shadowOffsetY = w * .03 * o.sh / 100; }
    if (im2) x.drawImage(im2, -w / 2, -h2 / 2, w, h2);
    x.shadowColor = 'transparent';
    return [w, h2];
  }
  var tiny = null;
  function drawFrame(x, W, H, t, srcOf, boxes) {
    x.save(); x.globalAlpha = 1;                     // drawn in artboard pixels: the caller's transform scales it (preview) or not (export) x.shadowColor = 'transparent'; x.imageSmoothingEnabled = true; x.imageSmoothingQuality = 'low';
    var h = vp.clips.length ? clipAt(Math.min(t, total() - 1e-4)) : null, c = h && h.c, m = c && media[c.m], src = m && srcOf(c, h.local), sw = m && m.w, sh = m && m.h;
    if (src && sw) {
      var s = (c.fit === 'fit' ? Math.min(W / sw, H / sh) : Math.max(W / sw, H / sh)) * c.z, dw = sw * s, dh = sh * s, cx0 = W / 2 + c.px / 100 * W, cy0 = H / 2 + c.py / 100 * H;
      var covers = c.r % 360 === 0 && cx0 - dw / 2 <= .5 && cy0 - dh / 2 <= .5 && cx0 + dw / 2 >= W - .5 && cy0 + dh / 2 >= H - .5;   // nothing shows behind: skip the background
      if (!covers && vp.bg.k === 'blur') {          // blur fill: the frame squeezed to a few pixels (darkened there) and stretched back -- the same in every browser
        tiny = tiny || mk('canvas'); tiny.width = 24; tiny.height = Math.max(8, Math.round(24 * H / W)); var tx = tiny.getContext('2d');
        var cs = Math.max(tiny.width / sw, tiny.height / sh); tx.drawImage(src, (tiny.width - sw * cs) / 2, (tiny.height - sh * cs) / 2, sw * cs, sh * cs); tx.fillStyle = 'rgba(0,0,0,.28)'; tx.fillRect(0, 0, tiny.width, tiny.height);
        x.drawImage(tiny, 1, 1, tiny.width - 2, tiny.height - 2, -W * .02, -H * .02, W * 1.04, H * 1.04);
      } else if (!covers) { x.fillStyle = vp.bg.c; x.fillRect(0, 0, W, H); }
      if (c.r % 360 === 0) x.drawImage(src, cx0 - dw / 2, cy0 - dh / 2, dw, dh);
      else { x.save(); x.translate(cx0, cy0); x.rotate(c.r * Math.PI / 180); x.drawImage(src, -dw / 2, -dh / 2, dw, dh); x.restore(); }
    } else { x.fillStyle = vp.bg.k === 'color' ? vp.bg.c : '#000000'; x.fillRect(0, 0, W, H); }
    vp.ovs.forEach(function (o) {
      if (t < o.s || t >= o.e) return; var a = animAt(o, t);
      x.save(); x.globalAlpha = Math.max(0, Math.min(1, a.a * o.op / 100)); x.translate(o.x / 100 * W, (o.y / 100 + a.dy) * H); x.rotate(o.r * Math.PI / 180); x.scale(a.sc, a.sc);
      var bx = drawOv(x, o, W, H); x.restore();
      if (boxes) boxes.push({ id: o.id, x: o.x / 100 * W, y: o.y / 100 * H, w: bx[0], h: bx[1], r: o.r });
    });
    x.restore();
  }

  // ── preview + playback ──
  var cv, cx, boxes = [], raf = 0, playWall = 0, playT0 = 0, activeClip = null, pvScale = 1;
  function previewSize() { var W = vp.ab.w, H = vp.ab.h, s = Math.min(1, 960 / Math.max(W, H)); pvScale = s; if (cv.width !== Math.round(W * s) || cv.height !== Math.round(H * s)) { cv.width = Math.round(W * s); cv.height = Math.round(H * s); } }
  function previewSrc(c) { var m = media[c.m]; return m ? m.el : null; }
  function drawPreview() {
    if (!vp || !cv) return; previewSize(); boxes = [];
    cx.save(); cx.scale(pvScale, pvScale); drawFrame(cx, vp.ab.w, vp.ab.h, ph, previewSrc, boxes); cx.restore();
    var o = selOv(), b = o && boxes.filter(function (q) { return q.id === o.id; })[0];
    if (b) { cx.save(); cx.scale(pvScale, pvScale); cx.translate(b.x, b.y); cx.rotate(b.r * Math.PI / 180); cx.strokeStyle = '#2F7BFF'; cx.setLineDash([12, 8]); cx.lineWidth = 3 / pvScale; cx.strokeRect(-b.w / 2 - 6, -b.h / 2 - 6, b.w + 12, b.h + 12); cx.restore(); }
    $('v-tc').textContent = tc(ph) + ' / ' + tc(total());
    movePlayhead();
  }
  function syncMedia(t, play) {
    var h = vp.clips.length ? clipAt(Math.min(t, total() - 1e-4)) : null, c = h && h.c, m = c && media[c.m], want = m && m.k === 'video' ? m.el : null;
    Object.keys(media).forEach(function (k) { var e = media[k].el; if (e && e !== want && (e.tagName === 'VIDEO' || (e.tagName === 'AUDIO' && (!vp.au || vp.au.m !== k))) && !e.paused) e.pause(); });
    if (want) {
      var clipChanged = activeClip !== c.id; activeClip = c.id;
      if (play) { want.muted = c.mute; want.volume = Math.min(1, c.vol / 100); if (clipChanged || Math.abs(want.currentTime - h.local) > .25) want.currentTime = h.local; if (want.paused) want.play().catch(function () {}); }
      else if (Math.abs(want.currentTime - h.local) > .001) want.currentTime = h.local;
    }
    var u = vp.au, ae = u && media[u.m] && media[u.m].el;
    if (ae) {
      var at = t - u.o + u.a, len = u.b - u.a, rel = t - u.o;
      if (play && at >= u.a && at < u.b) {
        var g = u.vol / 100; if (u.fi && rel < u.fi) g *= rel / u.fi; if (u.fo && rel > len - u.fo) g *= (len - rel) / u.fo;
        ae.volume = Math.max(0, Math.min(1, g)); if (Math.abs(ae.currentTime - at) > .25) ae.currentTime = at; if (ae.paused) ae.play().catch(function () {});
      } else if (!ae.paused) ae.pause();
    }
  }
  function tick() {
    if (!playing) return;
    var t = playT0 + (performance.now() - playWall) / 1000;
    if (t >= total()) { ph = total(); stop(); drawPreview(); return; }
    ph = t; syncMedia(t, true); drawPreview(); raf = requestAnimationFrame(tick);
  }
  function play() { if (playing || !vp || total() <= 0) return; if (ph >= total() - .05) ph = 0; playing = true; activeClip = null; playT0 = ph; playWall = performance.now(); $('v-play').textContent = '❚❚'; $('v-play').setAttribute('aria-label', 'Pause'); raf = requestAnimationFrame(tick); }
  function stop() { playing = false; cancelAnimationFrame(raf); Object.keys(media).forEach(function (k) { var e = media[k].el; if (e && e.pause && !e.paused) e.pause(); }); var b = $('v-play'); if (b) { b.textContent = '▶'; b.setAttribute('aria-label', 'Play'); } }
  function seekPreview(t) { if (t != null) ph = Math.max(0, Math.min(total(), t)); if (playing) { playT0 = ph; playWall = performance.now(); activeClip = null; } else syncMedia(ph, false); drawPreview(); }
  function tc(t) { t = Math.max(0, t || 0); var m = Math.floor(t / 60), s = t - m * 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s.toFixed(2); }
  function step(n) { stop(); var f = 1 / vp.fps; seekPreview(Math.round(ph / f) * f + n * f); }

  // ── preview interaction: drag an overlay; drag the picture to pan the clip; wheel zooms the clip ──
  var pdrag = null;
  function cvPoint(ev) { var r = cv.getBoundingClientRect(); return [(ev.clientX - r.left) / r.width * vp.ab.w, (ev.clientY - r.top) / r.height * vp.ab.h, r.width / vp.ab.w]; }
  function hitOv(p) { for (var i = boxes.length - 1; i >= 0; i--) { var b = boxes[i], a = -b.r * Math.PI / 180, dx = p[0] - b.x, dy = p[1] - b.y, lx = dx * Math.cos(a) - dy * Math.sin(a), ly = dx * Math.sin(a) + dy * Math.cos(a); if (Math.abs(lx) <= b.w / 2 + 10 && Math.abs(ly) <= b.h / 2 + 10) return b.id; } return null; }
  function bindPreview() {
    cv.addEventListener('pointerdown', function (ev) {
      if (!vp) return; var p = cvPoint(ev), id = hitOv(p);
      if (id) { sel = { k: 'ov', id: id }; var o = selOv(); pdrag = { k: 'ov', p: p, x: o.x, y: o.y }; showTab(o.t === 'text' ? 'text' : 'stick'); }
      else { var h = vp.clips.length ? clipAt(Math.min(ph, total() - 1e-4)) : null; if (!h) return; sel = { k: 'clip', id: h.c.id }; pdrag = { k: 'clip', p: p, x: h.c.px, y: h.c.py }; if (tab !== 'clip') showTab('clip'); }
      cv.setPointerCapture(ev.pointerId); renderPanels(); renderTimeline(); drawPreview();
    });
    cv.addEventListener('pointermove', function (ev) {
      if (!pdrag) return; var p = cvPoint(ev), dx = (p[0] - pdrag.p[0]) / vp.ab.w * 100, dy = (p[1] - pdrag.p[1]) / vp.ab.h * 100;
      if (pdrag.k === 'ov') { var o = selOv(); if (!o) return; o.x = r3(num(pdrag.x + dx, -50, 150, 50)); o.y = r3(num(pdrag.y + dy, -50, 150, 50)); if (!ev.altKey) { if (Math.abs(o.x - 50) < 1) o.x = 50; if (Math.abs(o.y - 50) < 1) o.y = 50; } }
      else { var c = selClip(); if (!c) return; c.px = r3(num(pdrag.x + dx, -100, 100, 0)); c.py = r3(num(pdrag.y + dy, -100, 100, 0)); }
      pdrag.moved = true; drawPreview();
    });
    function up() { if (!pdrag) return; var m = pdrag.moved; pdrag = null; if (m) changed('pdrag' + Date.now()); }
    cv.addEventListener('pointerup', up); cv.addEventListener('pointercancel', up);
    cv.addEventListener('wheel', function (ev) { var c = selClip(); if (!c) return; ev.preventDefault(); c.z = r3(num(c.z * (ev.deltaY < 0 ? 1.05 : 1 / 1.05), .2, 5, 1)); changed('zoom'); }, { passive: false });
  }

  // ── timeline ──
  var tlIn, tlScroll, tdrag = null, OFF = 34;   // OFF: room for the sticky track labels
  function tlW() { return Math.max(total() + 4, 10) * pps; }
  function renderTimeline() {
    if (!tlIn) return;
    tlIn.textContent = ''; tlIn.style.width = (tlW() + OFF + 40) + 'px';
    var T = total(), ru = mk('div', 'v-ruler'); ru.dataset.drag = 'ruler'; tlIn.appendChild(ru);
    var stepS = [.5, 1, 2, 5, 10, 15, 30, 60].filter(function (s) { return s * pps >= 56; })[0] || 60;
    for (var s = 0; s <= tlW() / pps; s += stepS) { var tk = mk('span', 'v-tk', tc(s).replace(/\.\d+$/, '')); tk.style.left = (OFF + s * pps) + 'px'; ru.appendChild(tk); }
    // video track
    var vt = mk('div', 'v-trk v-vtrk'), lab = mk('span', 'v-lab', '🎬'); lab.title = 'Clips'; vt.appendChild(lab); var st = starts();
    if (!vp.clips.length) vt.appendChild(mk('span', 'v-emp', 'Import videos or pictures — they line up here'));
    vp.clips.forEach(function (c, i) {
      var m = media[c.m], el = mk('div', 'v-clip' + (sel && sel.k === 'clip' && sel.id === c.id ? ' on' : '') + (m.k === 'image' ? ' img' : ''));
      el.dataset.id = c.id; el.dataset.drag = 'clip'; el.style.left = (OFF + st[i] * pps) + 'px'; el.style.width = Math.max(4, (c.b - c.a) * pps) + 'px';
      el.title = m.name + ' · ' + tc(c.b - c.a);
      var w = Math.min(4000, Math.max(4, Math.round((c.b - c.a) * pps))), can = mk('canvas', 'v-th'); can.width = w; can.height = 46; paintClip(can, c, m); el.appendChild(can);
      var nm = mk('span', 'v-cn', (c.mute && m.k === 'video' ? '🔇 ' : '') + m.name); el.appendChild(nm);
      var l = mk('i', 'v-ed l'); l.dataset.drag = 'trimL'; var r = mk('i', 'v-ed r'); r.dataset.drag = 'trimR'; el.appendChild(l); el.appendChild(r);
      vt.appendChild(el);
    });
    tlIn.appendChild(vt);
    // overlay rows
    vp.ovs.forEach(function (o) {
      var row = mk('div', 'v-trk v-otrk'), lb = mk('span', 'v-lab', o.t === 'text' ? 'T' : '★'); row.appendChild(lb);
      var el = mk('div', 'v-ov' + (o.t === 'text' ? ' tx' : '') + (sel && sel.k === 'ov' && sel.id === o.id ? ' on' : '')); el.dataset.id = o.id; el.dataset.drag = 'ov';
      el.style.left = (OFF + o.s * pps) + 'px'; el.style.width = Math.max(4, (o.e - o.s) * pps) + 'px';
      el.appendChild(mk('span', 'v-cn', ovLabel(o)));
      var l = mk('i', 'v-ed l'); l.dataset.drag = 'ovL'; var r = mk('i', 'v-ed r'); r.dataset.drag = 'ovR'; el.appendChild(l); el.appendChild(r);
      row.appendChild(el); tlIn.appendChild(row);
    });
    // audio track
    var at = mk('div', 'v-trk v-atrk'), la = mk('span', 'v-lab', '♪'); la.title = 'Music'; at.appendChild(la);
    if (vp.au) {
      var u = vp.au, m = media[u.m], ae = mk('div', 'v-au' + (sel && sel.k === 'au' ? ' on' : '')); ae.dataset.drag = 'au';
      ae.style.left = (OFF + u.o * pps) + 'px'; ae.style.width = Math.max(4, (u.b - u.a) * pps) + 'px'; ae.title = m.name;
      var wc = mk('canvas', 'v-th'); wc.width = Math.min(4000, Math.max(4, Math.round((u.b - u.a) * pps))); wc.height = 34; paintWave(wc, m, u.a, u.b, '#7C5CFF'); ae.appendChild(wc);
      ae.appendChild(mk('span', 'v-cn', '♪ ' + m.name));
      var l2 = mk('i', 'v-ed l'); l2.dataset.drag = 'auL'; var r2 = mk('i', 'v-ed r'); r2.dataset.drag = 'auR'; ae.appendChild(l2); ae.appendChild(r2);
      at.appendChild(ae);
    } else at.appendChild(mk('span', 'v-emp', 'Music: Audio tab → Import music'));
    tlIn.appendChild(at);
    var p = mk('div', 'v-ph'); p.id = 'v-ph'; tlIn.appendChild(p); movePlayhead();
    $('v-zoomv').textContent = Math.round(pps) + ' px/s';
  }
  function ovLabel(o) { return o.t === 'text' || o.t === 'badge' ? (o.txt || '…').replace(/\n/g, ' ') : o.t === 'prod' ? D.prods[o.p].n : o.t === 'icon' ? ICONS[o.ic][0] : o.t === 'pay' ? 'tabby / tamara' : o.t === 'logo' ? 'Logo' : 'Picture'; }
  function paintClip(can, c, m) {
    var x = can.getContext('2d'), H = can.height, th = m.thumbs || [];
    x.fillStyle = m.k === 'image' ? '#3A3340' : '#2B2630'; x.fillRect(0, 0, can.width, H);
    if (th.length) { var tw = Math.max(16, H * m.w / m.h); for (var px = 0; px < can.width; px += tw) { var t = c.a + (px + tw / 2) / pps, best = th[0]; th.forEach(function (q) { if (Math.abs(q.t - t) < Math.abs(best.t - t)) best = q; }); x.drawImage(best.c, px, 0, tw, H); } }
    if (m.k === 'video' && m.peaks && !m.silent) { x.fillStyle = 'rgba(0,0,0,.45)'; x.fillRect(0, H - 14, can.width, 14); paintWave(can, m, c.a, c.b, c.mute ? 'rgba(255,255,255,.25)' : '#7EE0B5', H - 14, 14); }
  }
  function paintWave(can, m, a, b, col, y0, hh) {
    var x = can.getContext('2d'), H = hh || can.height, Y = y0 || 0; if (!m.peaks) { x.fillStyle = col; x.globalAlpha = .35; x.fillRect(0, Y + H / 2 - 1, can.width, 2); x.globalAlpha = 1; return; }
    x.fillStyle = col; var mx = 0; for (var i = 0; i < m.peaks.length; i++) if (m.peaks[i] > mx) mx = m.peaks[i]; mx = mx || 1;
    for (var px = 0; px < can.width; px += 2) { var t = a + px / can.width * (b - a), k = Math.min(m.peaks.length - 1, Math.floor(t * m.per)), v = m.peaks[k] / mx * (H - 2) / 2; x.fillRect(px, Y + H / 2 - v, 1.5, Math.max(1, v * 2)); }
  }
  function movePlayhead() { var p = $('v-ph'); if (!p) return; p.style.left = (OFF + ph * pps) + 'px'; if (playing) { var L = OFF + ph * pps, sl = tlScroll.scrollLeft, w = tlScroll.clientWidth; if (L > sl + w - 40 || L < sl) tlScroll.scrollLeft = Math.max(0, L - 60); } }
  function bindTimeline() {
    tlIn.addEventListener('pointerdown', function (ev) {
      var t = ev.target, dn = t.closest('[data-drag]'), kind = dn ? dn.dataset.drag : 'ruler';
      var r = tlIn.getBoundingClientRect(), x0 = ev.clientX, sec = (ev.clientX - r.left - OFF) / pps;
      ev.preventDefault(); tlIn.setPointerCapture(ev.pointerId);
      if (kind === 'ruler') { stop(); tdrag = { k: 'ruler', r: r }; seekPreview(sec); return; }
      var host = t.closest('.v-clip,.v-ov,.v-au'), id = host && host.dataset.id;
      if (kind === 'clip' || kind === 'trimL' || kind === 'trimR') { var c = vp.clips.filter(function (q) { return q.id === id; })[0]; sel = { k: 'clip', id: id }; tdrag = { k: kind, id: id, x0: x0, a: c.a, b: c.b, el: host, idx: vp.clips.indexOf(c) }; }
      else if (kind === 'ov' || kind === 'ovL' || kind === 'ovR') { var o = vp.ovs.filter(function (q) { return q.id === id; })[0]; sel = { k: 'ov', id: id }; tdrag = { k: kind, id: id, x0: x0, s: o.s, e: o.e }; }
      else if (kind === 'au' || kind === 'auL' || kind === 'auR') { sel = { k: 'au' }; tdrag = { k: kind, x0: x0, a: vp.au.a, b: vp.au.b, o: vp.au.o }; }
      document.querySelectorAll('.v-clip.on,.v-ov.on,.v-au.on').forEach(function (n) { n.classList.remove('on'); }); if (host) host.classList.add('on');
      renderPanels(); drawPreview();
      if (tdrag.k === 'clip' || tdrag.k === 'trimL' || tdrag.k === 'trimR') { if (tab !== 'clip') showTab('clip'); }
      else if (tdrag.k.indexOf('ov') === 0) { var oo = selOv(); if (oo) showTab(oo.t === 'text' ? 'text' : 'stick'); }
      else if (tab !== 'audio') showTab('audio');
    });
    tlIn.addEventListener('pointermove', function (ev) {
      if (!tdrag) return; var dt = (ev.clientX - (tdrag.x0 || 0)) / pps, k = tdrag.k;
      if (k === 'ruler') { seekPreview((ev.clientX - tdrag.r.left - OFF) / pps); return; }
      if (Math.abs(ev.clientX - tdrag.x0) > 3) tdrag.moved = true; if (!tdrag.moved) return;
      if (k === 'clip') { tdrag.el.style.transform = 'translateX(' + (ev.clientX - tdrag.x0) + 'px)'; tdrag.el.classList.add('drag'); tdrag.dx = ev.clientX - tdrag.x0; return; }
      if (k === 'trimL' || k === 'trimR') {
        var c = vp.clips.filter(function (q) { return q.id === tdrag.id; })[0], m = media[c.m], hi = m.k === 'video' ? m.dur : 600;
        if (k === 'trimL') c.a = r3(num(tdrag.a + dt, 0, c.b - MIN, 0)); else c.b = r3(num(tdrag.b + dt, c.a + MIN, hi, c.b));
        var st = starts(), i = vp.clips.indexOf(c); ph = k === 'trimL' ? st[i] : st[i] + c.b - c.a - 1e-3; renderTimeline(); seekPreview(); return;
      }
      if (k === 'ov' || k === 'ovL' || k === 'ovR') {
        var o = selOv(); if (!o) return; var len = tdrag.e - tdrag.s;
        if (k === 'ov') { o.s = r3(Math.max(0, tdrag.s + dt)); o.e = r3(o.s + len); } else if (k === 'ovL') o.s = r3(num(tdrag.s + dt, 0, o.e - MIN, 0)); else o.e = r3(Math.max(o.s + MIN, tdrag.e + dt));
        ph = k === 'ovR' ? o.e - 1e-3 : o.s; renderTimeline(); seekPreview(); return;
      }
      var u = vp.au, md = media[u.m].dur;
      if (k === 'au') u.o = r3(tdrag.o + dt);
      else if (k === 'auL') { var na = num(tdrag.a + dt, 0, u.b - MIN, 0); u.o = r3(tdrag.o + (na - tdrag.a)); u.a = r3(na); }
      else u.b = r3(num(tdrag.b + dt, u.a + MIN, md, md));
      renderTimeline();
    });
    function up(ev) {
      if (!tdrag) return; var d = tdrag; tdrag = null;
      if (d.k === 'ruler') return;
      if (!d.moved) { if (d.k === 'clip' || d.k === 'ov' || d.k === 'au') { renderTimeline(); renderPanels(); } return; }
      if (d.k === 'clip') {                                // reorder: drop where the clip's centre lands
        var c = vp.clips[d.idx], st = starts(), mid = st[d.idx] + (c.b - c.a) / 2 + (d.dx || 0) / pps, to = 0;
        vp.clips.forEach(function (q, i) { if (i !== d.idx && st[i] + (q.b - q.a) / 2 < mid) to++; });
        vp.clips.splice(d.idx, 1); vp.clips.splice(to, 0, c); status(to !== d.idx ? 'Moved to position ' + (to + 1) : 'Clip selected', 'ok');
      }
      changed('tl' + Date.now());
    }
    tlIn.addEventListener('pointerup', up); tlIn.addEventListener('pointercancel', up);
    tlScroll.addEventListener('wheel', function (ev) { if (!(ev.ctrlKey || ev.metaKey)) return; ev.preventDefault(); setZoom(pps * (ev.deltaY < 0 ? 1.15 : 1 / 1.15)); }, { passive: false });
  }
  function setZoom(v) { pps = num(v, 8, 400, 60); $('v-zoom').value = String(Math.round(Math.log(pps / 8) / Math.log(50) * 100)); renderTimeline(); }

  // ── panels ──
  function showTab(k) {
    tab = k; document.querySelectorAll('#vmain .v-tab').forEach(function (b) { b.setAttribute('aria-selected', String(b.dataset.vt === k)); });
    document.querySelectorAll('#vmain .v-pane').forEach(function (p) { p.hidden = p.dataset.vp !== k; }); renderPanels();
  }
  function renderAll() { if (!vp) return; renderArtboard(); renderTimeline(); renderPanels(); renderBin(); drawPreview(); $('v-undo').disabled = !hist.length; $('v-redo').disabled = !fut.length; }
  function renderArtboard() {
    var k = presetOf(); document.querySelectorAll('#v-ab .chip').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.ab === k)); });
    $('v-cust').hidden = k !== 'custom'; $('v-cw').value = vp.ab.w; $('v-ch').value = vp.ab.h;
    document.querySelectorAll('#v-bgk button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === vp.bg.k)); }); $('v-bgc').value = vp.bg.c;
    $('v-abl').textContent = vp.ab.w + ' × ' + vp.ab.h; cv.style.aspectRatio = vp.ab.w + ' / ' + vp.ab.h;
  }
  function presetOf() { for (var k in PRESETS) if (PRESETS[k][0] === vp.ab.w && PRESETS[k][1] === vp.ab.h) return k; return 'custom'; }
  function setv(id, v) { var e = $(id); if (!e) return; if (e.type === 'checkbox') e.checked = !!v; else if (document.activeElement !== e) e.value = v; }
  function renderPanels() {
    if (!vp) return;
    var c = selClip(), o = selOv(), u = vp.au;
    $('vc-none').hidden = !!c; $('vc-props').hidden = !c;
    if (c) { var m = media[c.m]; $('vc-name').textContent = m.name + ' · ' + tc(c.b - c.a); setv('vc-zoom', Math.round(c.z * 100)); setv('vc-px', c.px); setv('vc-py', c.py); setv('vc-rot', c.r); setv('vc-mute', c.mute); setv('vc-vol', c.vol); setv('vc-a', c.a); setv('vc-b', c.b);
      document.querySelectorAll('#vc-fit button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === c.fit)); }); $('vc-aud').hidden = m.k !== 'video'; $('vc-volv').textContent = c.vol + '%'; $('vc-zoomv').textContent = Math.round(c.z * 100) + '%'; $('vc-rotv').textContent = c.r + '°'; }
    var isT = o && o.t === 'text';
    $('vt-props').hidden = !isT; $('vt-none').hidden = isT;
    if (isT) { setv('vt-txt', o.txt); setv('vt-fn', o.fn); setv('vt-fw', o.fw); setv('vt-fs', o.fs); setv('vt-tc', o.tc); setv('vt-pill', o.pill); setv('vt-pc', o.pc); setv('vt-ol', o.ol); setv('vt-oc', o.oc); setv('vt-sh', o.sh); setv('vt-fi', o.fi); setv('vt-up', o.up);
      document.querySelectorAll('#vt-al button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.v === o.al)); }); $('vt-txt').dir = ARABIC.test(o.txt) ? 'rtl' : 'ltr'; }
    var isS = o && o.t !== 'text';
    $('vs-props').hidden = !isS; if (isS) {
      $('vs-name').textContent = ovLabel(o); setv('vs-w', o.w); setv('vs-col', o.col || '#FFFFFF'); $('vs-colr').hidden = !(o.t === 'icon' || o.t === 'badge' || o.t === 'logo');
      $('vs-badge').hidden = o.t !== 'badge'; if (o.t === 'badge') { setv('vs-btxt', o.txt); setv('vs-shape', o.shape); setv('vs-btc', o.tc); }
      $('vs-payr').hidden = o.t !== 'pay'; if (o.t === 'pay') setv('vs-pay', o.pay);
    }
    ['vt', 'vs'].forEach(function (p) { if (!o) return; setv(p + '-s', o.s); setv(p + '-e', o.e); setv(p + '-ain', o.ain); setv(p + '-aout', o.aout); setv(p + '-op', o.op); setv(p + '-r', o.r); });
    $('va-none').hidden = !!u; $('va-props').hidden = !u;
    if (u) { $('va-name').textContent = media[u.m].name; setv('va-vol', u.vol); setv('va-fi', u.fi); setv('va-fo', u.fo); setv('va-o', u.o); setv('va-a', u.a); setv('va-b', u.b); $('va-volv').textContent = u.vol + '%';
      var wc = $('va-wave'); wc.width = 600; wc.height = 60; var x = wc.getContext('2d'); x.clearRect(0, 0, 600, 60); paintWave(wc, media[u.m], 0, media[u.m].dur, '#7C5CFF'); x.fillStyle = 'rgba(124,92,255,.15)'; x.fillRect(u.a / media[u.m].dur * 600, 0, (u.b - u.a) / media[u.m].dur * 600, 60); }
    var anyMute = vp.clips.some(function (q) { return media[q.m].k === 'video'; });
    $('va-allmute').disabled = !anyMute; $('va-allmute').textContent = vp.clips.length && vp.clips.every(function (q) { return q.mute || media[q.m].k !== 'video'; }) ? 'Turn clip sound back on' : 'Remove sound from all clips';
    $('vx-what').textContent = describeExport();
    $('vx-fps').value = String(vp.fps);
    $('v-split').disabled = !vp.clips.length; $('v-del').disabled = !sel; $('v-dup').disabled = !(selClip() || selOv());
  }
  function renderBin() {
    var bin = $('v-bin'); if (!bin) return; bin.textContent = '';
    var ids = Object.keys(media);
    if (!ids.length) { bin.appendChild(mk('p', 'note', 'Nothing imported yet. Videos (MP4 · MOV · WebM), pictures and music (MP3 · M4A · WAV).')); return; }
    ids.forEach(function (id) {
      var m = media[id], it = mk('div', 'v-bi'), th = mk('div', 'v-bt');
      if (m.thumbs && m.thumbs[0]) { var c = mk('canvas'); c.width = m.thumbs[0].c.width; c.height = 64; c.getContext('2d').drawImage(m.thumbs[0].c, 0, 0); th.appendChild(c); } else th.textContent = m.k === 'audio' ? '♪' : m.k === 'image' ? '🖼' : '🎬';
      it.appendChild(th); var nm = mk('span', 'v-bn', m.name); nm.title = m.name; it.appendChild(nm);
      it.appendChild(mk('span', 'v-bd', m.k === 'image' ? m.w + '×' + m.h : tc(m.dur) + (m.k === 'video' ? ' · ' + m.w + '×' + m.h : '')));
      var bs = mk('div', 'v-bb');
      if (m.k === 'audio') { var u = mk('button', 'btn sm', 'Use as music'); u.type = 'button'; u.onclick = function () { setMusic(id); showTab('audio'); }; bs.appendChild(u); }
      else { var a = mk('button', 'btn sm', '+ Timeline'); a.type = 'button'; a.onclick = function () { addClip(id); }; bs.appendChild(a); }
      if (m.k === 'image') { var ov = mk('button', 'btn sm', 'Overlay'); ov.type = 'button'; ov.onclick = function () { addOv({ t: 'img', im: 'm:' + id, w: 30 }); }; bs.appendChild(ov); }
      var x = mk('button', 'btn sm', '✕'); x.type = 'button'; x.title = 'Remove from the media bin'; x.setAttribute('aria-label', 'Remove ' + m.name); x.onclick = function () { removeMedia(id); }; bs.appendChild(x);
      it.appendChild(bs); bin.appendChild(it);
    });
  }
  function removeMedia(id) {
    var used = vp.clips.some(function (c) { return c.m === id; }) || (vp.au && vp.au.m === id) || vp.ovs.some(function (o) { return o.im === 'm:' + id; });
    if (used && !confirm('This file is used in the video. Remove it and its clips?')) return;
    stop(); vp.clips = vp.clips.filter(function (c) { return c.m !== id; }); if (vp.au && vp.au.m === id) vp.au = null; vp.ovs = vp.ovs.filter(function (o) { return o.im !== 'm:' + id; });
    var m = media[id]; delete media[id]; if (m) { if (m.el && m.el.pause) m.el.pause(); URL.revokeObjectURL(m.url); }
    VS.delMedia(id).catch(function () {}); sel = null; ph = Math.min(ph, total()); changed('rm');
  }
  function addOv(o) {
    var t0 = ph, T = total(), base = { id: uid('o'), s: r3(t0), e: r3(t0 + (T - t0 >= 1 ? Math.min(4, T - t0) : 4)), x: 50, y: 50 };
    var c = cleanOv(Object.assign(base, o)); if (!c) return; vp.ovs.push(c); sel = { k: 'ov', id: c.id }; changed('addov');
  }
  function listProjects() {
    var box = $('v-mine'); if (!box) return;
    VS.list().then(function (l) {
      box.textContent = ''; l.sort(function (a, b) { return b.updated - a.updated; });
      if (!l.length) box.appendChild(mk('p', 'note', 'Saved video projects appear here.'));
      l.forEach(function (p) {
        if (!VID.test(p.id)) return; var r = mk('div', 'it'), n = mk('span', null, p.name + (vp && p.id === vp.id ? ' · open' : '')); r.appendChild(n);
        var o = mk('button', 'btn sm', 'Open'); o.type = 'button'; o.disabled = vp && p.id === vp.id; o.onclick = function () { VS.get(p.id).then(function (d) { var c = cleanVP(d); if (c) { openVP(c); status('Opened ' + c.name, 'ok'); } }); }; r.appendChild(o);
        var x = mk('button', 'btn sm', '✕'); x.type = 'button'; x.setAttribute('aria-label', 'Delete ' + p.name); x.onclick = function () { if (!confirm('Delete "' + p.name + '"?')) return; VS.del(p.id).then(function () { if (vp && p.id === vp.id) openVP(newVP()); listProjects(); }); }; r.appendChild(x);
        box.appendChild(r);
      });
    });
  }

  // ── export ──
  var xbusy = false, xcancel = false, forceFallback = false, forceSeek = false;
  function hasWC() { return !forceFallback && typeof VideoEncoder === 'function' && typeof VideoFrame === 'function' && typeof Mp4Muxer === 'object'; }
  function recType() { if (!window.MediaRecorder || !HTMLCanvasElement.prototype.captureStream) return null; return ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'].filter(function (t) { try { return MediaRecorder.isTypeSupported(t); } catch (e) { return false; } })[0] || null; }
  function wantAudio() { return !$('vx-noaudio').checked && (vp.au || vp.clips.some(function (c) { var m = media[c.m]; return m.k === 'video' && !c.mute && c.vol > 0 && !m.silent; })); }
  function describeExport() { return hasWC() ? 'MP4 · H.264' + (wantAudio() ? ' + AAC (Opus where this browser has no AAC encoder)' : ' · no sound') + ' · ' + vp.ab.w + '×' + vp.ab.h + ' · encoded frame by frame, as fast as this computer can' : recType() ? 'WebM · recorded in real time (this browser has no WebCodecs — Chrome or Edge export MP4)' : 'This browser cannot export video. Chrome or Edge can.'; }
  function avcLevel(W, H, fps) { var mbs = Math.ceil(W / 16) * Math.ceil(H / 16), r = mbs * fps; return mbs <= 8192 && r <= 245760 ? '28' : mbs <= 8704 && r <= 522240 ? '2a' : mbs <= 22080 && r <= 983040 ? '33' : '34'; }
  function pickCodecs(W, H, fps, br, audio) {
    var lv = avcLevel(W, H, fps), vlist = ['avc1.6400' + lv, 'avc1.4d00' + lv, 'avc1.42e0' + lv];
    return vlist.reduce(function (p, c) { return p.then(function (f) { if (f) return f; var cfg = { codec: c, width: W, height: H, bitrate: br, framerate: fps, avc: { format: 'avc' } }; return VideoEncoder.isConfigSupported(cfg).then(function (s) { return s.supported ? cfg : null; }, function () { return null; }); }); }, Promise.resolve(null))
      .then(function (vc) {
        if (!audio || typeof AudioEncoder !== 'function') return [vc, null];
        return [['mp4a.40.2', 'aac'], ['opus', 'opus']].reduce(function (p, a) { return p.then(function (f) { if (f) return f; var cfg = { codec: a[0], sampleRate: 48000, numberOfChannels: 2, bitrate: 160000 }; return AudioEncoder.isConfigSupported(cfg).then(function (s) { return s.supported ? { cfg: cfg, mux: a[1] } : null; }, function () { return null; }); }); }, Promise.resolve(null)).then(function (ac) { return [vc, ac]; });
      });
  }
  // the sound of the whole video, mixed once offline: clips (per-clip volume, muted ones left out) + music (trim, offset, fades)
  function mixAudio(T) {
    var C = window.OfflineAudioContext || window.webkitOfflineAudioContext, SR = 48000; if (!C || T <= 0) return Promise.resolve(null);
    var need = {}; vp.clips.forEach(function (c) { if (!c.mute && c.vol > 0 && media[c.m].k === 'video') need[c.m] = 1; }); if (vp.au) need[vp.au.m] = 1;
    var bufs = {};
    return Promise.all(Object.keys(need).map(function (id) { return decodeAudio(media[id]).then(function (b) { bufs[id] = b; }); })).then(function () {
      var ctx = new C(2, Math.ceil(T * SR), SR), any = false, st = starts();
      vp.clips.forEach(function (c, i) { var b = bufs[c.m]; if (c.mute || !b || c.vol <= 0) return; var s = ctx.createBufferSource(), g = ctx.createGain(); s.buffer = b; g.gain.value = c.vol / 100; s.connect(g); g.connect(ctx.destination); if (c.a < b.duration) { s.start(st[i], c.a, Math.min(c.b, b.duration) - c.a); any = true; } });
      var u = vp.au, ub = u && bufs[u.m];
      if (ub) {
        var skip = Math.max(0, -u.o), t0 = Math.max(0, u.o), len = Math.min(u.b - u.a - skip, T - t0);
        if (len > 0) {
          var s2 = ctx.createBufferSource(), g2 = ctx.createGain(), v = u.vol / 100, full = u.b - u.a, gA = function (rel) { var k = v; if (u.fi && rel < u.fi) k *= rel / u.fi; if (u.fo && rel > full - u.fo) k *= Math.max(0, (full - rel) / u.fo); return k; };
          s2.buffer = ub; s2.connect(g2); g2.connect(ctx.destination);
          // the gain is piecewise linear between these points, so ramps between them are exact
          var pts = [skip, skip + len, u.fi, full - u.fo].filter(function (q) { return q >= skip && q <= skip + len; }).sort(function (a, b) { return a - b; });
          g2.gain.setValueAtTime(gA(pts[0]), t0); pts.slice(1).forEach(function (q) { g2.gain.linearRampToValueAtTime(gA(q), t0 + q - skip); });
          s2.start(t0, u.a + skip, len); any = true;
        }
      }
      return any ? ctx.startRendering() : null;
    });
  }
  function progress(p, txt) { $('vx-bar').value = p; $('vx-st').textContent = txt; }
  function xui(on) { xbusy = on; $('vx-go').disabled = on; $('vx-png').disabled = on; $('vx-cancel').hidden = !on; $('vx-bar').hidden = !on; }
  function exportSources() {   // export-only elements: the preview's own stay where they are
    var els = {};
    vp.clips.forEach(function (c) { var m = media[c.m]; if (els[c.m]) return; if (m.k === 'image') els[c.m] = m.el; else { var v = document.createElement('video'); v.muted = true; v.preload = 'auto'; v.playsInline = true; v.src = m.url; els[c.m] = v; } });
    return Promise.all(Object.keys(els).map(function (id) { var v = els[id]; return v.tagName === 'VIDEO' ? new Promise(function (r) { if (v.readyState >= 2) r(); else { v.onloadeddata = r; v.onerror = r; } }) : 0; })).then(function () { return els; });
  }
  function freeSources(els) { Object.keys(els).forEach(function (id) { var v = els[id]; if (v.tagName === 'VIDEO') { v.removeAttribute('src'); v.load(); } }); }
  function fontsReady() { var fams = {}; vp.ovs.forEach(function (o) { if (o.fn) fams[(o.fi ? 'italic ' : '') + o.fw + ' 40px "' + FONTS[o.fn] + '"'] = 1; }); fams['700 40px Poppins'] = 1; return Promise.all(Object.keys(fams).map(function (f) { return document.fonts.load(f, 'Aa ب').catch(function () {}); })).then(function () { return document.fonts.ready; }); }
  function fileBase() { return 'KBB-video-' + (vp.name || 'video').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
  function exportVideo() {
    if (xbusy) return Promise.resolve(null); var T = total(); if (T <= 0) { status('Add a clip first', 'warn'); return Promise.resolve(null); }
    stop(); xcancel = false; xui(true); progress(0, 'Preparing…');
    var job = hasWC() ? exportWC(T) : recType() ? exportRec(T) : Promise.reject(new Error('this browser cannot export video'));
    return job.then(function (r) {
      if (!r) return null;
      return S.Store.save(fileBase() + '-' + vp.ab.w + 'x' + vp.ab.h + '.' + r.ext, r.blob).then(function () { progress(1, 'Video ready · ' + r.ext.toUpperCase() + ' · ' + (r.blob.size / 1048576).toFixed(1) + ' MB · ' + r.note); return r; });
    }).catch(function (e) { progress(0, e && e.cancelled ? 'Export cancelled' : 'Not exported: ' + ((e && e.message) || 'try again')); return null; }).then(function (r) { xui(false); return r; });
  }
  function cancelled() { var e = new Error('cancelled'); e.cancelled = true; return e; }
  function exportWC(T) {
    var W = vp.ab.w, H = vp.ab.h, fps = vp.fps, N = Math.max(1, Math.round(T * fps)), br = Math.round(Math.min(40e6, W * H * fps * ($('vx-q').value === 'high' ? .26 : .14))), t0 = performance.now(), audioOn = wantAudio();
    var muxer, venc, aenc, err = null, els, tm = {};
    return Promise.all([pickCodecs(W, H, fps, br, audioOn), fontsReady(), exportSources()]).then(function (r) {
      var vc = r[0][0], ac = r[0][1], noSound = false; els = r[2]; if (!vc) throw new Error('this browser has no H.264 encoder for ' + W + '×' + H);
      if (audioOn && !ac) { if (recType()) { freeSources(els); els = null; return exportRec(T); } noSound = true; }   // never drop the sound quietly
      progress(.01, audioOn ? 'Mixing the sound…' : 'Encoding…'); tm.prep = performance.now() - t0;
      return (audioOn && ac ? mixAudio(T) : Promise.resolve(null)).then(function (abuf) { tm.mix = performance.now() - t0;
        muxer = new Mp4Muxer.Muxer({ target: new Mp4Muxer.ArrayBufferTarget(), video: { codec: 'avc', width: W, height: H, frameRate: fps }, audio: abuf ? { codec: ac.mux, numberOfChannels: 2, sampleRate: 48000 } : undefined, fastStart: 'in-memory', firstTimestampBehavior: 'offset' });
        venc = new VideoEncoder({ output: function (ch, meta) { muxer.addVideoChunk(ch, meta); }, error: function (e) { err = e; } }); venc.configure(vc);
        if (abuf) {
          aenc = new AudioEncoder({ output: function (ch, meta) { muxer.addAudioChunk(ch, meta); }, error: function (e) { err = e; } }); aenc.configure(ac.cfg);
          var SR = 48000, L = abuf.length, c0 = abuf.getChannelData(0), c1 = abuf.getChannelData(1);
          for (var off = 0; off < L; off += 4800) { var n = Math.min(4800, L - off), d = new Float32Array(n * 2); d.set(c0.subarray(off, off + n), 0); d.set(c1.subarray(off, off + n), n); var ad = new AudioData({ format: 'f32-planar', sampleRate: SR, numberOfFrames: n, numberOfChannels: 2, timestamp: Math.round(off / SR * 1e6), data: d }); aenc.encode(ad); ad.close(); }
        }
        var can = mk('canvas'); can.width = W; can.height = H; var x = can.getContext('2d', { alpha: false }), i = 0, st = starts(), seeks = 0;
        var note = (abuf ? (ac.mux === 'aac' ? 'H.264 + AAC' : 'H.264 + Opus (no AAC encoder in this browser)') : 'H.264, no sound') + (noSound ? ' — this browser cannot encode sound' : '');
        function srcOf(c) { return els[c.m]; }
        function check() { if (err) throw err; if (xcancel) throw cancelled(); }
        tm.emit = 0; tm.draw = 0; tm.cbs = 0; tm.pauses = 0;
        function emit(t) {
          var e0 = performance.now(); drawFrame(x, W, H, t, srcOf, null); tm.draw += performance.now() - e0;
          var f = new VideoFrame(can, { timestamp: Math.round(i * 1e6 / fps), duration: Math.round(1e6 / fps) });
          venc.encode(f, { keyFrame: i % (fps * 2) === 0 }); f.close(); i++;
          if (i % 5 === 0) progress(i / N * .98, 'Encoding frame ' + i + ' of ' + N + ' · ' + note);
          tm.emit += performance.now() - e0;
        }
        function drain() { return venc.encodeQueueSize > 6 ? new Promise(function (r) { venc.addEventListener('dequeue', r, { once: true }); setTimeout(r, 200); }) : Promise.resolve(); }
        // exact but slow (a seek decodes from the last keyframe): pictures, short bits, and the fallback
        function seekFrames(upto) {
          return (function loop() {
            check(); if (i >= upto) return Promise.resolve();
            var t = i / fps, h = vp.clips.length ? clipAt(Math.min(t, T - 1e-4)) : null, m = h && media[h.c.m];
            if (m && m.k === 'video') seeks++;
            return (m && m.k === 'video' ? seekTo(els[h.c.m], h.local + .001) : Promise.resolve()).then(function () { emit(t); return drain(); }).then(loop);
          })();
        }
        // fast: the clip PLAYS (muted, 0.2x-2x, as fast as this computer keeps up without dropping a frame) and each decoded frame is caught with requestVideoFrameCallback
        function playFrames(c, start, upto) {
          var v = els[c.m];
          return seekTo(v, c.a).then(function () {
            return new Promise(function (res, rej) {
              var dog = 0, done = false, rate = 1, prev = -1, gap = 1;
              function fin(fallback) { if (done) return; done = true; clearTimeout(dog); v.removeEventListener('ended', onEnd); v.pause(); res(fallback); }
              function arm() { clearTimeout(dog); dog = setTimeout(function () { fin(true); }, 2500); }      // stalled (tab hidden?) -> finish by seeking
              function onEnd() { if (done) return; while (i < upto) emit(i / fps); fin(false); }
              function cb(now, md) {
                if (done) return;
                try { check(); } catch (e) { done = true; clearTimeout(dog); v.pause(); return rej(e); }
                tm.cbs++; var mt = md.mediaTime, last = mt >= c.b - .5 / fps;
                // keep up without dropping source frames: slower when a frame was skipped, a little faster when not
                if (prev >= 0 && mt > prev) { var dt = mt - prev; if (dt < gap) gap = Math.max(1 / 120, dt); if (dt > gap * 1.6) rate = Math.max(.2, rate * .75); else rate = Math.min(2, rate * 1.03); v.playbackRate = rate; tm.rate = Math.round(rate * 100) / 100; }
                prev = mt;
                while (i < upto && (last || c.a + (i / fps - start) <= mt + .25 / fps)) emit(i / fps);
                if (i >= upto) return fin(false);
                arm();
                if (venc.encodeQueueSize > 24) { tm.pauses++; clearTimeout(dog); v.pause(); (function w() { if (done) return; if (venc.encodeQueueSize > 4) return setTimeout(w, 15); arm(); v.play().catch(function () { fin(true); }); })(); }
                v.requestVideoFrameCallback(cb);
              }
              v.addEventListener('ended', onEnd); v.playbackRate = rate; v.requestVideoFrameCallback(cb); arm();
              v.play().catch(function () { fin(true); });
            });
          }).then(function (fallback) { v.playbackRate = 1; return fallback ? seekFrames(upto) : null; });
        }
        var canPlay = !forceSeek && typeof HTMLVideoElement.prototype.requestVideoFrameCallback === 'function';
        function clips(k) {
          check(); if (k >= vp.clips.length) return seekFrames(N);
          var c = vp.clips[k], m = media[c.m], upto = k === vp.clips.length - 1 ? N : Math.min(N, Math.ceil((st[k] + c.b - c.a) * fps - 1e-6));
          return (m.k === 'video' && canPlay && upto - i > 3 ? playFrames(c, st[k], upto) : seekFrames(upto)).then(function () { return clips(k + 1); });
        }
        return clips(0).then(function () {
          tm.frames = performance.now() - t0; check(); return venc.flush().then(function () { return aenc ? aenc.flush() : 0; }).then(function () {
            check(); muxer.finalize();
            return { blob: new Blob([muxer.target.buffer], { type: 'video/mp4' }), ext: 'mp4', frames: i, seeks: seeks, timing: tm, note: note + ' · ' + i + ' frames in ' + ((performance.now() - t0) / 1000).toFixed(1) + ' s' };
          });
        });
      });
    }).then(function (r) { if (els) freeSources(els); return r; }, function (e) { if (els) freeSources(els); try { if (venc && venc.state !== 'closed') venc.close(); if (aenc && aenc.state !== 'closed') aenc.close(); } catch (x) {} throw e; });
  }
  function exportRec(T) {     // fallback: MediaRecorder, real time
    var W = vp.ab.w, H = vp.ab.h, type = recType(), els, actx, audioOn = wantAudio();
    return Promise.all([fontsReady(), exportSources(), audioOn ? mixAudio(T) : null]).then(function (r) {
      els = r[1]; var abuf = r[2], can = mk('canvas'); can.width = W; can.height = H; var x = can.getContext('2d'), stream = can.captureStream(vp.fps);
      if (abuf) { actx = new (window.AudioContext || window.webkitAudioContext)(); var dst = actx.createMediaStreamDestination(), s = actx.createBufferSource(); s.buffer = abuf; s.connect(dst); dst.stream.getAudioTracks().forEach(function (t) { stream.addTrack(t); }); s.start(); }
      var rec = new MediaRecorder(stream, { mimeType: type, videoBitsPerSecond: Math.min(16e6, W * H * 4) }), chunks = [];
      rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      return new Promise(function (res, rej) {
        var start = performance.now(), active = null;
        rec.onstop = function () { if (xcancel) rej(cancelled()); else res({ blob: new Blob(chunks, { type: type.split(';')[0] }), ext: /mp4/.test(type) ? 'mp4' : 'webm', note: 'recorded in real time (no WebCodecs in this browser)' }); };
        function frame() {
          var t = (performance.now() - start) / 1000;
          if (xcancel || t >= T) { Object.keys(els).forEach(function (k) { if (els[k].pause) els[k].pause(); }); rec.stop(); return; }
          var h = clipAt(Math.min(t, T - 1e-4)), v = h && els[h.c.m];
          if (v && v.tagName === 'VIDEO') { if (active !== h.c.id || Math.abs(v.currentTime - h.local) > .25) { v.currentTime = h.local; active = h.c.id; } if (v.paused) v.play().catch(function () {}); }
          drawFrame(x, W, H, t, function (c) { return els[c.m]; }, null); progress(t / T, 'Recording ' + t.toFixed(1) + ' / ' + T.toFixed(1) + ' s — keep this tab open');
          requestAnimationFrame(frame);
        }
        rec.start(250); requestAnimationFrame(frame);
      });
    }).then(function (r) { if (els) freeSources(els); if (actx) actx.close(); return r; }, function (e) { if (els) freeSources(els); if (actx) actx.close(); throw e; });
  }
  function exportStill() {
    if (xbusy) return Promise.resolve(null); stop(); var W = vp.ab.w, H = vp.ab.h, t = ph, els;
    xui(true); progress(.2, 'Drawing the frame…');
    return Promise.all([fontsReady(), exportSources()]).then(function (r) {
      els = r[1]; var h = vp.clips.length ? clipAt(Math.min(t, total() - 1e-4)) : null;
      return (h && media[h.c.m].k === 'video' ? seekTo(els[h.c.m], h.local + .001) : Promise.resolve()).then(function () {
        var can = mk('canvas'); can.width = W; can.height = H; drawFrame(can.getContext('2d'), W, H, t, function (c) { return els[c.m]; }, null);
        return new Promise(function (res) { can.toBlob(res, 'image/png'); });
      });
    }).then(function (b) { freeSources(els); return S.Store.save(fileBase() + '-frame-' + tc(t).replace(/[:.]/g, '-') + '.png', b).then(function () { progress(1, 'Frame saved · PNG ' + W + '×' + H); return b; }); })
      .catch(function (e) { progress(0, 'Not saved: ' + ((e && e.message) || 'try again')); return null; }).then(function (b) { xui(false); return b; });
  }

  // ── project file: export / import hooks used by the Ads project file (zip when video is in it) ──
  function isEmpty(d) { return !d || (!d.clips.length && !d.ovs.length && !d.au); }
  function exportPart() {
    return init().then(function () { return isEmpty(vp) ? null : saveNow(); }).then(function () { return VS.list(); }).then(function (l) { return Promise.all(l.filter(function (q) { return VID.test(q.id); }).map(function (q) { return VS.get(q.id); })); }).then(function (ds) {
      ds = ds.map(cleanVP).filter(function (d) { return d && !isEmpty(d); }); var used = {};   // an untouched empty video never turns the .json into a .zip
      ds.forEach(function (d) { d.clips.forEach(function (c) { used[c.m] = 1; }); if (d.au) used[d.au.m] = 1; d.ovs.forEach(function (o) { if (o.im && o.im.indexOf('m:') === 0) used[o.im.slice(2)] = 1; }); });
      var ms = Object.keys(used).filter(function (id) { return media[id]; }).map(function (id) { var m = media[id]; return { meta: { id: id, name: m.name, type: m.type, size: m.size, file: 'media/' + id + '.' + extOf(m.type) }, blob: m.blob }; });
      return { projects: ds, media: ms };
    });
  }
  function importPart(v, zip) {
    if (!v || typeof v !== 'object' || !Array.isArray(v.projects) || !zip) return Promise.resolve(0);
    return init().then(function () { return importPart2(v, zip); });
  }
  function importPart2(v, zip) {
    var map = Object.create(null), metas = Array.isArray(v.media) ? v.media.slice(0, 200) : [];
    return metas.reduce(function (p, raw) {
      return p.then(function () {
        var m = cleanMeta(raw); if (!m || typeof raw.file !== 'string' || !/^media\/m[0-9a-f]{12}\.[a-z0-9]{2,4}$/.test(raw.file)) return; var f = zip.file(raw.file); if (!f) return;
        return f.async('blob').then(function (b) {
          if (b.size > MAXB[m.k]) return; var rec = { id: uid('m'), name: m.name, k: m.k, type: m.type };
          return register(rec, new Blob([b], { type: m.type })).then(function (mm) { map[m.id] = mm.id; return VS.putMedia({ id: mm.id, name: mm.name, k: mm.k, type: mm.type, size: mm.size, blob: mm.blob }).then(function () { return Promise.all([makeThumbs(mm), makePeaks(mm)]).catch(function () {}); }); }).catch(function () {});
        });
      });
    }, Promise.resolve()).then(function () {
      var n = 0;
      return v.projects.slice(0, 100).reduce(function (p, raw) {
        return p.then(function () {
          if (!raw || typeof raw !== 'object') return; var r = clone(raw);
          if (Array.isArray(r.clips)) r.clips.forEach(function (c) { if (c) c.m = map[c.m] || '#'; });
          if (r.au) r.au.m = map[r.au.m] || '#';
          if (Array.isArray(r.ovs)) r.ovs.forEach(function (o) { if (o && typeof o.im === 'string' && o.im.indexOf('m:') === 0) o.im = map[o.im.slice(2)] ? 'm:' + map[o.im.slice(2)] : '#'; });
          r.id = uid('v');                                // an import never overwrites a project already here
          var d = cleanVP(r); if (!d) return; n++; return VS.put(d);
        });
      }, Promise.resolve()).then(function () { renderBin(); listProjects(); return n; });
    });
  }

  // ── UI wiring ──
  function status(t, cls) { if (!statusEl) return; statusEl.textContent = t; statusEl.className = 'st ' + (cls || ''); }
  function bindProps() {
    function onClip(id, fn, ev) { $(id).addEventListener(ev || 'input', function () { var c = selClip(); if (!c) return; fn(c, this); changed(id); }); }
    onClip('vc-zoom', function (c, e) { c.z = num(e.value / 100, .2, 5, 1); });
    onClip('vc-px', function (c, e) { c.px = num(e.value, -100, 100, 0); }); onClip('vc-py', function (c, e) { c.py = num(e.value, -100, 100, 0); });
    onClip('vc-rot', function (c, e) { c.r = num(e.value, -180, 180, 0); });
    onClip('vc-mute', function (c, e) { c.mute = e.checked; }, 'change'); onClip('vc-vol', function (c, e) { c.vol = num(e.value, 0, 200, 100); });
    onClip('vc-a', function (c, e) { var m = media[c.m]; c.a = r3(num(e.value, 0, c.b - MIN, c.a)); void m; }, 'change');
    onClip('vc-b', function (c, e) { var m = media[c.m]; c.b = r3(num(e.value, c.a + MIN, m.k === 'video' ? m.dur : 600, c.b)); }, 'change');
    $('vc-fit').addEventListener('click', function (ev) { var b = ev.target.closest('button'), c = selClip(); if (!b || !c) return; c.fit = b.dataset.v; changed('fit'); });
    $('vc-r90').onclick = function () { var c = selClip(); if (!c) return; c.r = ((c.r + 90 + 180) % 360) - 180; changed('r90'); };
    $('vc-reset').onclick = function () { var c = selClip(); if (!c) return; c.z = 1; c.px = 0; c.py = 0; c.r = 0; changed('creset'); };
    function onOv(pre, id, fn, ev) { var e = $(pre + id); if (!e) return; e.addEventListener(ev || 'input', function () { var o = selOv(); if (!o) return; fn(o, this); changed(pre + id); }); }
    ['vt', 'vs'].forEach(function (p) {
      onOv(p, '-s', function (o, e) { o.s = r3(num(e.value, 0, o.e - MIN, o.s)); }, 'change'); onOv(p, '-e', function (o, e) { o.e = r3(num(e.value, o.s + MIN, 3600, o.e)); }, 'change');
      onOv(p, '-ain', function (o, e) { o.ain = cleanAnim(e.value, 'fade'); }, 'change'); onOv(p, '-aout', function (o, e) { o.aout = cleanAnim(e.value, 'fade'); }, 'change');
      onOv(p, '-op', function (o, e) { o.op = num(e.value, 0, 100, 100); }); onOv(p, '-r', function (o, e) { o.r = num(e.value, -180, 180, 0); });
    });
    onOv('vt', '-txt', function (o, e) { o.txt = str(e.value, 300); e.dir = ARABIC.test(o.txt) ? 'rtl' : 'ltr'; });
    onOv('vt', '-fn', function (o, e) { if (has(FONTS, e.value)) o.fn = e.value; document.fonts.load('700 40px "' + FONTS[o.fn] + '"').then(drawPreview, function () {}); }, 'change');
    onOv('vt', '-fw', function (o, e) { o.fw = +e.value; }, 'change'); onOv('vt', '-fs', function (o, e) { o.fs = num(e.value, 1, 40, 7); });
    onOv('vt', '-tc', function (o, e) { o.tc = hex(e.value, o.tc); }); onOv('vt', '-pill', function (o, e) { o.pill = e.checked; }, 'change'); onOv('vt', '-pc', function (o, e) { o.pc = hex(e.value, o.pc); });
    onOv('vt', '-ol', function (o, e) { o.ol = num(e.value, 0, 20, 0); }); onOv('vt', '-oc', function (o, e) { o.oc = hex(e.value, o.oc); }); onOv('vt', '-sh', function (o, e) { o.sh = num(e.value, 0, 100, 40); });
    onOv('vt', '-fi', function (o, e) { o.fi = e.checked; }, 'change'); onOv('vt', '-up', function (o, e) { o.up = e.checked; }, 'change');
    $('vt-al').addEventListener('click', function (ev) { var b = ev.target.closest('button'), o = selOv(); if (!b || !o) return; o.al = b.dataset.v; changed('al'); });
    onOv('vs', '-w', function (o, e) { o.w = num(e.value, 2, 200, 30); }); onOv('vs', '-col', function (o, e) { o.col = hex(e.value, o.col); });
    onOv('vs', '-btxt', function (o, e) { o.txt = str(e.value, 300); }); onOv('vs', '-shape', function (o, e) { if (has(BADGES, e.value)) o.shape = e.value; }, 'change'); onOv('vs', '-btc', function (o, e) { o.tc = hex(e.value, o.tc); });
    onOv('vs', '-pay', function (o, e) { o.pay = has({ both: 1, tabby: 1, tamara: 1 }, e.value) ? e.value : 'both'; }, 'change');
    $('vt-presets').addEventListener('click', function (ev) { var b = ev.target.closest('button'); if (!b) return; var o = selOv(); var P = TSTYLE[b.dataset.k]; if (o && o.t === 'text') { Object.assign(o, P); changed('preset'); } else addText(Object.assign({}, P)); });
    $('vt-add-en').onclick = function () { addText({ txt: 'Glow Week · up to 50% off' }); };
    $('vt-add-ar').onclick = function () { addText({ txt: 'تخفيضات الجمعة البيضاء', fn: 'cairo', fw: 900 }); };
    // audio
    function onAu(id, fn, ev) { $(id).addEventListener(ev || 'input', function () { if (!vp.au) return; fn(vp.au, this); changed(id); }); }
    onAu('va-vol', function (u, e) { u.vol = num(e.value, 0, 200, 100); }); onAu('va-fi', function (u, e) { u.fi = num(e.value, 0, 10, 0); }); onAu('va-fo', function (u, e) { u.fo = num(e.value, 0, 10, 0); });
    onAu('va-o', function (u, e) { u.o = r3(num(e.value, -3600, 3600, 0)); }, 'change');
    onAu('va-a', function (u, e) { u.a = r3(num(e.value, 0, u.b - MIN, u.a)); }, 'change'); onAu('va-b', function (u, e) { u.b = r3(num(e.value, u.a + MIN, media[u.m].dur, u.b)); }, 'change');
    $('va-rm').onclick = function () { vp.au = null; sel = null; changed('aurm'); };
    $('va-allmute').onclick = function () { var all = vp.clips.every(function (q) { return q.mute || media[q.m].k !== 'video'; }); vp.clips.forEach(function (q) { q.mute = !all; }); changed('allmute'); status(all ? 'Clip sound is back on' : 'Sound removed from every clip', 'ok'); };
  }
  var TSTYLE = {
    bold: { fn: 'poppins', fw: 900, tc: '#FFFFFF', pill: false, ol: 0, sh: 60, fi: false, up: true },
    pill: { fn: 'poppins', fw: 800, tc: '#FFFFFF', pill: true, pc: '#E8306F', ol: 0, sh: 25, fi: false, up: false },
    outline: { fn: 'bebas', fw: 400, tc: '#FFFFFF', pill: false, ol: 9, oc: '#111111', sh: 0, fi: false, up: true },
    serif: { fn: 'playfair', fw: 700, tc: '#FFF4E6', pill: false, ol: 0, sh: 45, fi: true, up: false },
    soft: { fn: 'montserrat', fw: 800, tc: '#1F1B1E', pill: true, pc: '#FFFFFF', ol: 0, sh: 20, fi: false, up: false },
    gold: { fn: 'cairo', fw: 900, tc: '#F7C948', pill: false, ol: 6, oc: '#3B1E08', sh: 40, fi: false, up: false }
  };
  function addText(p) { addOv(Object.assign({ t: 'text', txt: 'Your text', fn: 'poppins', fw: 800, fs: 7.5, tc: '#FFFFFF', al: 'c', sh: 40 }, p)); showTab('text'); }
  function buildStickers() {
    var g = $('vs-icons'); Object.keys(ICONS).forEach(function (k) { if (k === 'lotus') return; var b = mk('button'); b.type = 'button'; b.title = ICONS[k][0]; b.setAttribute('aria-label', 'Add ' + ICONS[k][0]); b.innerHTML = S.iconSvg(k); b.onclick = function () { addOv({ t: 'icon', ic: k, col: S.brand().c1, w: 16 }); }; g.appendChild(b); });
    var bg = $('vs-badges'); Object.keys(BADGES).forEach(function (k) { var b = mk('button', 'btn sm', BADGES[k]); b.type = 'button'; b.onclick = function () { addOv({ t: 'badge', shape: k, txt: k === 'pill' ? 'NEW' : '-50%', fn: 'poppins', fw: 900, fs: 7, tc: '#FFFFFF', col: S.brand().c1, w: k === 'pill' ? 34 : 28, y: 25 }); }; bg.appendChild(b); });
    var pk = $('vs-prods'); Object.keys(D.prods).forEach(function (p) { var b = mk('button'), i = mk('i', 'p-' + p); b.type = 'button'; b.title = D.prods[p].n; b.appendChild(i); b.appendChild(mk('span', null, D.prods[p].n)); b.onclick = function () { addOv({ t: 'prod', p: p, w: 32, y: 62, sh: 50 }); }; pk.appendChild(b); });
    $('vs-addlogo').onclick = function () { addOv({ t: 'logo', col: '#FFFFFF', w: 46, y: 9 }); };
    $('vs-addpay').onclick = function () { addOv({ t: 'pay', pay: 'both', w: 52, y: 90 }); };
    renderStudioImgs();
  }
  function renderStudioImgs() {
    var box = $('vs-mine'); box.textContent = ''; var ids = Object.keys(studioImgs).filter(function (id) { return SIMG.test(id) && studioImgs[id].kind !== 'bg'; });
    $('vs-mine-d').hidden = !ids.length;
    ids.forEach(function (id) { var b = mk('button'), i = mk('i'); b.type = 'button'; i.style.backgroundImage = 'url("' + studioImgs[id].src.replace(/["\\\n]/g, '') + '")'; b.appendChild(i); b.appendChild(mk('span', null, studioImgs[id].name || 'Picture')); b.onclick = function () { addOv({ t: 'img', im: 's:' + id, w: 30 }); }; box.appendChild(b); });
  }
  function bindUI() {
    statusEl = $('v-status'); cv = $('v-cv'); cx = cv.getContext('2d'); tlIn = $('v-tlin'); tlScroll = $('v-tl');
    document.querySelectorAll('#vmain .v-tab').forEach(function (b) { b.onclick = function () { showTab(b.dataset.vt); }; });
    var fnSel = $('vt-fn'); Object.keys(FONTS).forEach(function (k) { var o = mk('option', null, FONTS[k]); o.value = k; fnSel.appendChild(o); });
    ['vt', 'vs'].forEach(function (p) { ['-ain', '-aout'].forEach(function (s) { var e = $(p + s); Object.keys(ANIMS).forEach(function (k) { var o = mk('option', null, ANIMS[k]); o.value = k; e.appendChild(o); }); }); });
    var shp = $('vs-shape'); Object.keys(BADGES).forEach(function (k) { var o = mk('option', null, BADGES[k]); o.value = k; shp.appendChild(o); });
    $('v-file').onchange = function () { var f = this.files; importFiles(f).then(function () {}); this.value = ''; };
    $('va-file').onchange = function () { importFiles(this.files).then(function (a) { var m = a.filter(function (q) { return q.k === 'audio'; })[0]; if (m) { setMusic(m.id); showTab('audio'); } }); this.value = ''; };
    var vm = $('vmain');
    vm.addEventListener('dragover', function (ev) { if (ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types || [], 'Files') >= 0) { ev.preventDefault(); vm.classList.add('drop'); } });
    vm.addEventListener('dragleave', function (ev) { if (ev.target === vm || !vm.contains(ev.relatedTarget)) vm.classList.remove('drop'); });
    vm.addEventListener('drop', function (ev) { ev.preventDefault(); vm.classList.remove('drop'); importFiles(ev.dataTransfer.files); });
    $('v-play').onclick = function () { playing ? stop() : play(); drawPreview(); };
    $('v-prev').onclick = function () { step(-1); }; $('v-next').onclick = function () { step(1); };
    $('v-start').onclick = function () { stop(); seekPreview(0); }; $('v-end').onclick = function () { stop(); seekPreview(total()); };
    $('v-split').onclick = split; $('v-del').onclick = removeSel; $('v-dup').onclick = duplicate;
    $('v-zoom').oninput = function () { setZoom(8 * Math.pow(50, this.value / 100)); };
    $('v-undo').onclick = undo; $('v-redo').onclick = redo; $('v-save').onclick = saveNow;
    $('v-new').onclick = function () { saveNow().then(function () { openVP(newVP()); changed('new'); status('New video', 'ok'); }); };
    $('v-name').addEventListener('input', function () { vp.name = str(this.value, 60) || 'Untitled video'; changed('name'); });
    $('v-ab').addEventListener('click', function (ev) { var b = ev.target.closest('.chip'); if (!b) return; var k = b.dataset.ab; if (k === 'custom') { $('v-cust').hidden = false; return; } vp.ab = { w: PRESETS[k][0], h: PRESETS[k][1] }; changed('ab'); });
    ['v-cw', 'v-ch'].forEach(function (id) { $(id).addEventListener('change', function () { vp.ab = { w: even(num($('v-cw').value, 240, 3840, 1080)), h: even(num($('v-ch').value, 240, 3840, 1920)) }; changed('ab'); }); });
    $('v-bgk').addEventListener('click', function (ev) { var b = ev.target.closest('button'); if (!b) return; vp.bg.k = b.dataset.v === 'color' ? 'color' : 'blur'; changed('bg'); });
    $('v-bgc').addEventListener('input', function () { vp.bg.c = hex(this.value, vp.bg.c); vp.bg.k = 'color'; changed('bgc'); });
    $('vx-go').onclick = function () { exportVideo(); }; $('vx-png').onclick = function () { exportStill(); }; $('vx-cancel').onclick = function () { xcancel = true; };
    $('vx-fps').onchange = function () { vp.fps = FPS_LIST.indexOf(+this.value) >= 0 ? +this.value : 30; changed('fps'); };
    $('vx-noaudio').onchange = function () { renderPanels(); };
    bindProps(); buildStickers(); bindPreview(); bindTimeline();
    document.addEventListener('keydown', function (ev) {
      if (document.body.dataset.mode !== 'video') return;
      var inField = /input|select|textarea/i.test((document.activeElement || {}).tagName || ''), mod = ev.ctrlKey || ev.metaKey, k = ev.key.toLowerCase();
      if (mod && k === 's') { ev.preventDefault(); saveNow(); return; }
      if (inField) return;
      if (mod && k === 'z' && !ev.shiftKey) { ev.preventDefault(); undo(); return; }
      if (mod && (k === 'y' || (k === 'z' && ev.shiftKey))) { ev.preventDefault(); redo(); return; }
      if (mod && k === 'd') { ev.preventDefault(); duplicate(); return; }
      if (mod) return;
      if (ev.key === ' ' || k === 'k') { ev.preventDefault(); playing ? stop() : play(); drawPreview(); return; }
      if (k === 's') { ev.preventDefault(); split(); return; }
      if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); removeSel(); return; }
      if (ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') { ev.preventDefault(); var d = ev.key === 'ArrowLeft' ? -1 : 1; if (ev.shiftKey) { stop(); seekPreview(ph + d); } else step(d); return; }
      if (ev.key === 'Home') { stop(); seekPreview(0); return; } if (ev.key === 'End') { stop(); seekPreview(total()); return; }
      if (ev.key === 'Escape') { sel = null; renderAll(); }
    });
    window.addEventListener('beforeunload', function () { if (vp && lastJSON) VS.put(vp).catch(function () {}); });
  }
  function init() {
    if (inited) return Promise.resolve(); inited = true; bindUI(); status('Opening…');
    return VS.init().then(function () { return VS.allMedia(); }).then(function (recs) {
      return Promise.all(recs.map(function (r) { var m = cleanMeta(r); if (!m || !(r.blob instanceof Blob)) return null; return register(m, r.blob).then(function (mm) { return Promise.all([makeThumbs(mm), makePeaks(mm)]).catch(function () {}); }, function () { return null; }); }));
    }).then(function () {
      var last = lsGet('vlast'); return last && VID.test(last) ? VS.get(last) : null;
    }).then(function (d) {
      openVP(cleanVP(d) || newVP()); listProjects();
      $('v-where').textContent = VS.kind() === 'browser' ? 'Video projects and their clips save inside this browser (works offline). Export project (Ads → Export) packs them into a .zip.' : 'This browser does not allow saving from this file — export the project before you close it.';
      status(VS.kind() === 'browser' ? 'Ready · offline' : 'Not saving in this browser', VS.kind() === 'browser' ? 'ok' : 'warn');
      document.documentElement.dataset.vready = '1';
    });
  }

  // ── Ads | Video switch ──
  function setMode(m) {
    var v = m === 'video'; document.body.dataset.mode = v ? 'video' : 'ads'; lsSet('mode', v ? 'video' : 'ads');
    document.querySelectorAll('#modesw button').forEach(function (b) { b.setAttribute('aria-pressed', String(b.dataset.m === (v ? 'video' : 'ads'))); });
    $('ads-main').hidden = v; $('vmain').hidden = !v; document.querySelectorAll('.ads-only').forEach(function (e) { e.hidden = v; });
    if (v) { if (window.KBB_STUDIO.stopPlay) window.KBB_STUDIO.stopPlay(); renderStudioImgsSafe(); init().then(function () { drawPreview(); }); } else stop();
  }
  function renderStudioImgsSafe() { if (inited) renderStudioImgs(); }
  document.querySelectorAll('#modesw button').forEach(function (b) { b.onclick = function () { setMode(b.dataset.m); }; });
  window.KBB_VIDEO = {
    setMode: setMode, init: init, exportPart: exportPart, importPart: importPart,
    get vp() { return vp; }, get media() { return media; }, get ph() { return ph; }, get sel() { return sel; },
    select: function (k, id) { sel = { k: k, id: id }; renderAll(); }, seek: function (t) { stop(); seekPreview(t); }, split: split, removeSel: removeSel, duplicate: duplicate, changed: changed, undo: undo, redo: redo,
    addText: addText, addOv: addOv, exportVideo: exportVideo, exportStill: exportStill, saveNow: saveNow, total: total, setZoom: setZoom, showTab: showTab, cleanVP: cleanVP,
    set forceFallback(v) { forceFallback = !!v; renderPanels(); }, set forceSeek(v) { forceSeek = !!v; }, hasWC: hasWC, importFiles: importFiles, play: play, stop: stop
  };
  var start = lsGet('mode'); if (start === 'video' || /[?#&]video\b/.test(location.search + location.hash)) {
    var go = function () { if (document.documentElement.dataset.ready === '1') setMode('video'); else setTimeout(go, 50); }; go();
  }
})();
