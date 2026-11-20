// Motion ads (6-15 s, 9:16 / 4:5 / 1:1), rendered frame by frame on <canvas>
// and encoded OFFLINE with WebCodecs into a real MP4 (H.264 + AAC/Opus) via
// mp4-muxer (MIT, vendored) -- not screen-recorded, so every frame is exact
// and a 10 s ad renders faster than real time. MediaRecorder only as a
// last-resort fallback on a browser without WebCodecs.
//
// The timeline: product reveal (rise + light sweep + particles) -> headline
// wipes in, in reading direction (Arabic right-to-left, whole words, joins
// intact) -> benefit captions as kinetic chips -> a Ken Burns moment on the
// product's own lifestyle photo when the shop has one -> a macro push-in ->
// end card with price + CTA. With the owner's music, scene cuts snap to the
// beats detected in it. Everything written stays inside the Reels/Stories
// safe zone (not in the top 14% / bottom 20%).
import { FORMATS, assets, draw, themeOf, digits } from './render.js';
import * as K from './art.js';

const FPS = 30;
const ease = (v) => 1 - Math.pow(1 - Math.max(0, Math.min(1, v)), 3);
const inOut = (v) => { v = Math.max(0, Math.min(1, v)); return v < 0.5 ? 4 * v * v * v : 1 - Math.pow(-2 * v + 2, 3) / 2; };

// ---------- beats ----------
// Onset detection on the owner's track: log-energy flux, adaptive threshold,
// >= 0.25 s apart. Returns beat times in seconds (first 20 s).
export function detectBeats(buf) {
  const sr = buf.sampleRate, ch = buf.getChannelData(0), hop = 512, n = Math.min(ch.length, sr * 20);
  const env = [];
  for (let i = 0; i + hop <= n; i += hop) { let e = 0; for (let k = 0; k < hop; k++) e += ch[i + k] * ch[i + k]; env.push(Math.log(1e-9 + e)); }
  const flux = env.map((v, i) => (i ? Math.max(0, v - env[i - 1]) : 0));
  const beats = [], win = 22, minGap = 0.25 * sr / hop;
  let last = -1e9;
  for (let i = 1; i < flux.length - 1; i++) {
    let m = 0, s2 = 0, c = 0;
    for (let k = Math.max(0, i - win); k < Math.min(flux.length, i + win); k++) { m += flux[k]; s2 += flux[k] * flux[k]; c++; }
    m /= c; const sd = Math.sqrt(Math.max(0, s2 / c - m * m));
    if (flux[i] > m + 1.4 * sd && flux[i] >= flux[i - 1] && flux[i] >= flux[i + 1] && i - last >= minGap) { beats.push((i * hop) / sr); last = i; }
  }
  return beats;
}

// ---------- timeline ----------
export function timeline(spec, A, dur, beats) {
  const intro = Math.min(2.2, dur * 0.22), endLen = Math.min(2.6, dur * 0.24);
  const scenes = [];
  const caps = (spec.bullets || []).filter(Boolean).slice(0, 3);
  const life = A && A[0] && A[0].photo ? A[0].photo : null;
  const plan = [];
  caps.forEach((c, i) => { plan.push({ kind: 'caption', text: c }); if (i === 0 && life) plan.push({ kind: 'lifestyle', text: spec.sub || '' }); });
  if (!caps.length) plan.push({ kind: 'caption', text: spec.sub || spec.headline });
  plan.push({ kind: 'macro', text: '' });
  const mid0 = intro, mid1 = dur - endLen, per = (mid1 - mid0) / plan.length;
  let t = mid0;
  plan.forEach((p, i) => {
    let t1 = i === plan.length - 1 ? mid1 : t + per;
    if (beats && beats.length && i < plan.length - 1) { const b = beats.filter((x) => x > t + 0.9 && x < mid1 - 0.9).sort((a, c) => Math.abs(a - t1) - Math.abs(c - t1))[0]; if (b) t1 = b; }
    scenes.push(Object.assign({ t0: t, t1 }, p));
    t = t1;
  });
  return { intro, end: mid1, dur, scenes, beats: beats || [] };
}

// ---------- frame ----------
let buf = null;
function buffer(W, H) { if (!buf || buf.width !== W || buf.height !== H) { buf = document.createElement('canvas'); buf.width = W; buf.height = H; } return buf; }

function particles(x, spec, W, H, s, P) {
  const fam = spec.template;
  if (fam === 'petals') K.petals(x, W, H, 91, 7, ['#F4A7B9', '#F9C7D3', '#FFFFFF'], 1, s * 1.4, { x: 0, y: -H * 0.1, w: W, h: H * 1.1 });
  else if (fam === 'offer' || fam === 'editorial') K.sparkles(x, W, H, 17, 14, P.goldLight, 1, s);
  else {
    const r = K.rng(5);
    for (let i = 0; i < 9; i++) { const y = ((r() * H * 1.2) - s * H * (0.03 + r() * 0.04)) % (H * 1.2); K.droplet(x, r() * W + Math.sin(s + i) * W * 0.01, y < 0 ? y + H * 1.2 : y, W * (0.006 + r() * 0.012), P.acc, 0.7); }
  }
}

function captionChip(x, spec, W, H, txt, a, P) {
  if (!txt || a <= 0) return;
  const ar = spec.lang === 'ar', tall = H / W > 1.6;
  const y = tall ? H * 0.585 : H * 0.8;
  const str = ar ? digits(txt, spec) : txt;
  x.save();
  x.font = `${ar ? '700' : '600'} ${Math.round(W * 0.046)}px ${ar ? '"El Messiri", Cairo' : '"Plus Jakarta Sans"'}`;
  x.direction = ar ? 'rtl' : 'ltr';
  const w = Math.min(W * 0.86, x.measureText(str).width + W * 0.1), h = W * 0.095;
  const slide = (1 - ease(a)) * W * 0.12 * (ar ? 1 : -1);
  x.globalAlpha = Math.min(1, a * 1.4);
  x.translate(slide, 0);
  x.shadowColor = 'rgba(43,31,38,.25)'; x.shadowBlur = h * 0.4; x.shadowOffsetY = h * 0.1;
  x.fillStyle = 'rgba(255,255,255,.94)'; x.beginPath(); x.roundRect(W / 2 - w / 2, y - h / 2, w, h, h / 2); x.fill();
  x.shadowColor = 'transparent';
  x.fillStyle = P.gold; x.beginPath(); x.arc(ar ? W / 2 + w / 2 - h * 0.5 : W / 2 - w / 2 + h * 0.5, y, h * 0.13, 0, Math.PI * 2); x.fill();
  x.fillStyle = P.ink; x.textAlign = 'center'; x.textBaseline = 'middle';
  x.fillText(str, W / 2 + (ar ? -h * 0.15 : h * 0.15), y + W * 0.004, w - h * 0.9);
  x.restore();
}

function lifestyle(x, spec, W, H, photo, local, P) {
  const k = Math.max(W / photo.width, H / photo.height) * (1.08 + 0.12 * local);
  const pw = photo.width * k, ph = photo.height * k;
  x.drawImage(photo, (W - pw) / 2 + (local - 0.5) * W * 0.06, (H - ph) / 2, pw, ph);
  const g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, K.rgba(P.deep, 0.0)); g.addColorStop(0.6, K.rgba(P.deep, 0.08)); g.addColorStop(1, K.rgba(P.deep, 0.45));
  x.fillStyle = g; x.fillRect(0, 0, W, H);
}

function endCard(x, spec, W, H, A, a, local, P) {
  if (a <= 0) return;
  const ar = spec.lang === 'ar', tall = H / W > 1.6;
  x.save(); x.globalAlpha = a;
  K.wash(x, W, H, [K.mix(P.rose, '#E86A8C', 0.3), P.deep, P.plum], 115);
  K.silk(x, W, H, P.deep, K.mix(P.rose, '#FFFFFF', 0.2), local * 3, 9, 4);
  K.sparkles(x, W, H, 7, 18, P.goldLight, 1, local * 4);
  const im = A[0];
  const top = tall ? H * 0.17 : H * 0.08, bot = tall ? H * 0.78 : H * 0.94;
  if (im) {
    const mh = (bot - top) * 0.5, k = Math.min(W * 0.5 / im.width, mh / im.height) * (0.92 + 0.08 * ease(local * 2));
    const w = im.width * k, h = im.height * k, cy = top + (bot - top) * 0.36;
    const g = x.createRadialGradient(W / 2, cy, 0, W / 2, cy, W * 0.42); g.addColorStop(0, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)');
    x.fillStyle = g; x.fillRect(0, cy - W * 0.45, W, W * 0.9);
    x.shadowColor = 'rgba(0,0,0,.25)'; x.shadowBlur = W * 0.04; x.drawImage(im, W / 2 - w / 2, cy - h / 2, w, h); x.shadowColor = 'transparent';
  }
  x.textAlign = 'center'; x.direction = ar ? 'rtl' : 'ltr'; x.textBaseline = 'alphabetic';
  let y = top + (bot - top) * 0.7;
  x.fillStyle = '#FFFFFF';
  x.font = `${ar ? '700' : '600'} ${Math.round(W * 0.066)}px ${ar ? '"El Messiri", Cairo' : '"Playfair Display"'}`;
  x.fillText(ar ? digits(spec.headline, spec) : spec.headline, W / 2, y, W * 0.86);
  y += W * 0.1;
  if (spec.price) {
    const pr = ar ? digits(spec.price, spec) : spec.price;
    x.font = `800 ${Math.round(W * 0.06)}px ${ar ? 'Cairo' : '"Plus Jakarta Sans"'}`;
    const pw = x.measureText(pr).width + W * 0.1, ph = W * 0.1;
    const gg = x.createLinearGradient(W / 2 - pw / 2, 0, W / 2 + pw / 2, 0); gg.addColorStop(0, '#F3DDB0'); gg.addColorStop(0.5, P.gold); gg.addColorStop(1, '#B38D49');
    x.fillStyle = gg; x.beginPath(); x.roundRect(W / 2 - pw / 2, y - ph * 0.72, pw, ph, ph / 2); x.fill();
    x.fillStyle = P.plum; x.fillText(pr, W / 2, y);
    y += W * 0.12;
  }
  const pulse = 1 + 0.04 * Math.sin(local * Math.PI * 4);
  const cta = { SHOP_NOW: ['Shop now', 'تسوّقي الآن'], ORDER_NOW: ['Order now', 'اطلبي الآن'] }[spec.cta] || ['Shop now', 'تسوّقي الآن'];
  x.font = `700 ${Math.round(W * 0.042 * pulse)}px ${ar ? 'Tajawal, Cairo' : 'Inter'}`;
  const cw = x.measureText(cta[ar ? 1 : 0]).width + W * 0.12, ch = W * 0.095 * pulse;
  x.fillStyle = '#FFFFFF'; x.beginPath(); x.roundRect(W / 2 - cw / 2, y - ch * 0.68, cw, ch, ch / 2); x.fill();
  x.fillStyle = P.deep; x.fillText(cta[ar ? 1 : 0], W / 2, y);
  x.font = `600 ${Math.round(W * 0.026)}px Inter`; x.fillStyle = 'rgba(255,255,255,.85)'; x.direction = 'ltr';
  x.fillText('kbeautybliss.com', W / 2, y + W * 0.09);
  x.restore();
}

// Draw the frame at time s (seconds) of a `dur`-second ad.
export function frame(x, spec, W, H, A, s, dur, plan) {
  plan = plan || timeline(spec, A, dur, null);
  const P = themeOf(spec, A);
  const t = Math.min(1, s / plan.intro);
  const sc = plan.scenes.find((v) => s >= v.t0 && s < v.t1);
  const zoom = 1 + 0.035 * (s / dur) + (sc && sc.kind === 'macro' ? 0.22 * inOut((s - sc.t0) / (sc.t1 - sc.t0)) : 0);
  const focusY = sc && sc.kind === 'macro' ? H * 0.5 : H / 2;
  x.save();
  x.translate(W / 2, focusY); x.scale(zoom, zoom); x.translate(-W / 2, -focusY);
  draw(x, spec, W, H, A, t, { time: s });
  x.restore();
  particles(x, spec, W, H, s, P);
  if (sc && sc.kind === 'lifestyle' && A[0] && A[0].photo) {
    const local = (s - sc.t0) / (sc.t1 - sc.t0), a = Math.min(1, local / 0.12, (1 - local) / 0.12);
    const b = buffer(W, H), bx = b.getContext('2d');
    bx.setTransform(1, 0, 0, 1, 0, 0); bx.clearRect(0, 0, W, H);
    lifestyle(bx, spec, W, H, A[0].photo, local, P);
    x.save(); x.globalAlpha = a; x.drawImage(b, 0, 0, W, H); x.restore();
  }
  if (sc && sc.kind !== 'macro') { const local = (s - sc.t0) / (sc.t1 - sc.t0); captionChip(x, spec, W, H, sc.text, Math.min(1, local / 0.15, (1 - local) / 0.12), P); }
  if (s >= plan.end - 0.35) endCard(x, spec, W, H, A, Math.min(1, (s - plan.end + 0.35) / 0.5), (s - plan.end) / Math.max(0.1, dur - plan.end), P);
}

// ---------- encoding ----------
async function pickCodec(w, h, bitrate) {
  if (!window.VideoEncoder) return null;
  for (const [codec, mux] of [['avc1.640028', 'avc'], ['avc1.4d0028', 'avc'], ['avc1.42e028', 'avc'], ['avc1.42e01f', 'avc'], ['vp09.00.31.08', 'vp9']]) {
    const cfg = { codec, width: w, height: h, bitrate, framerate: FPS, latencyMode: 'quality' };
    if (codec.startsWith('avc')) cfg.avc = { format: 'avc' };
    try { const r = await VideoEncoder.isConfigSupported(cfg); if (r.supported) return { cfg, mux }; } catch {}
  }
  return null;
}
async function pickAudio(sr, ch) {
  if (!window.AudioEncoder) return null;
  for (const [codec, mux] of [['mp4a.40.2', 'aac'], ['opus', 'opus']]) {
    const cfg = { codec, sampleRate: sr, numberOfChannels: ch, bitrate: 128000 };
    try { const r = await AudioEncoder.isConfigSupported(cfg); if (r.supported) return { cfg, mux }; } catch {}
  }
  return null;
}

// renderVideo(spec, format, seconds, audioFile?, onProgress?) -> {blob, mime, codec, width, height, frames, encoder, ms}
export async function renderVideo(spec, format = '9:16', dur = 10, audioFile = null, onProgress = null) {
  const t0 = performance.now();
  dur = Math.max(6, Math.min(15, dur));
  const [W0, H0] = FORMATS[format] || FORMATS['9:16'];
  const scale = 720 / W0, W = 720, H = Math.round(H0 * scale / 2) * 2;
  const A = await assets(spec);
  let audio = null, beats = null;
  if (audioFile) {
    const ac = new (window.OfflineAudioContext || window.webkitOfflineAudioContext)(2, 48000 * dur, 48000);
    const src = await ac.decodeAudioData(await audioFile.arrayBuffer());
    beats = detectBeats(src);
    const node = ac.createBufferSource(); node.buffer = src;
    const g = ac.createGain(); g.gain.setValueAtTime(0.9, 0); g.gain.setValueAtTime(0.9, Math.max(0, dur - 1.2)); g.gain.linearRampToValueAtTime(0, dur);
    node.connect(g).connect(ac.destination); node.start();
    audio = await ac.startRendering();
  }
  const plan = timeline(spec, A, dur, beats);
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const x = cv.getContext('2d');
  const bitrate = Math.round((2.1e6 * 8 * 0.98) / 10 * (W * H) / (720 * 1280)); // ~1.65 Mbps at 720x1280: 10 s ~= 2.1 MB
  const vc = await pickCodec(W, H, bitrate);
  if (!vc) return recordFallback(spec, format, dur, audioFile, onProgress, A, plan);
  const { Muxer, ArrayBufferTarget } = await import('../../vendor/mp4-muxer/mp4-muxer.js');
  const ae = audio ? await pickAudio(audio.sampleRate, audio.numberOfChannels) : null;
  const muxer = new Muxer({ target: new ArrayBufferTarget(), video: { codec: vc.mux, width: W, height: H, frameRate: FPS }, audio: ae ? { codec: ae.mux, sampleRate: audio.sampleRate, numberOfChannels: audio.numberOfChannels } : undefined, fastStart: 'in-memory', firstTimestampBehavior: 'offset' });
  let err = null;
  const enc = new VideoEncoder({ output: (c, m) => muxer.addVideoChunk(c, m), error: (e) => { err = e; } });
  enc.configure(vc.cfg);
  const n = Math.round(dur * FPS), bg = A.bg && A.bg.tagName === 'VIDEO' ? A.bg : null;
  for (let i = 0; i < n; i++) {
    if (err) throw err;
    const s = i / FPS;
    if (bg) { const tt = s % Math.max(0.1, bg.duration || dur); if (Math.abs(bg.currentTime - tt) > 0.01) await new Promise((ok) => { const h = () => { bg.removeEventListener('seeked', h); ok(); }; bg.addEventListener('seeked', h); bg.currentTime = tt; setTimeout(h, 1500); }); }
    x.setTransform(scale, 0, 0, scale, 0, 0);
    frame(x, spec, W0, H0, A, s, dur, plan);
    const vf = new VideoFrame(cv, { timestamp: Math.round(s * 1e6), duration: Math.round(1e6 / FPS) });
    enc.encode(vf, { keyFrame: i % (FPS * 2) === 0 });
    vf.close();
    while (enc.encodeQueueSize > 6) await new Promise((ok) => setTimeout(ok, 2));
    if (onProgress && i % 10 === 0) { onProgress(i / n); await new Promise((ok) => setTimeout(ok, 0)); }
  }
  await enc.flush(); enc.close();
  if (ae) {
    const aenc = new AudioEncoder({ output: (c, m) => muxer.addAudioChunk(c, m), error: (e) => { err = e; } });
    aenc.configure(ae.cfg);
    const chs = audio.numberOfChannels, len = audio.length, step = 1024;
    for (let off = 0; off < len; off += step) {
      const fr = Math.min(step, len - off), data = new Float32Array(fr * chs);
      for (let c = 0; c < chs; c++) data.set(audio.getChannelData(c).subarray(off, off + fr), c * fr);
      const ad = new AudioData({ format: 'f32-planar', sampleRate: audio.sampleRate, numberOfFrames: fr, numberOfChannels: chs, timestamp: Math.round((off / audio.sampleRate) * 1e6), data });
      aenc.encode(ad); ad.close();
    }
    await aenc.flush(); aenc.close();
  }
  if (err) throw err;
  muxer.finalize();
  if (onProgress) onProgress(1);
  const blob = new Blob([muxer.target.buffer], { type: 'video/mp4' });
  return { blob, mime: 'video/mp4', codec: vc.cfg.codec + (ae ? ' + ' + ae.cfg.codec : ''), width: W, height: H, frames: n, encoder: 'WebCodecs', ms: Math.round(performance.now() - t0), beats: plan.beats.length };
}

// Fallback: real-time capture (browsers without WebCodecs).
async function recordFallback(spec, format, dur, audioFile, onProgress, A, plan) {
  const [W0, H0] = FORMATS[format] || FORMATS['9:16'];
  const scale = 720 / W0, W = 720, H = Math.round(H0 * scale);
  const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const x = cv.getContext('2d');
  const mime = ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm'].find((m) => window.MediaRecorder && MediaRecorder.isTypeSupported(m));
  if (!mime) throw new Error('This browser cannot make video');
  const stream = cv.captureStream(FPS);
  const rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 2_000_000 });
  const chunks = []; rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
  const done = new Promise((ok) => { rec.onstop = ok; });
  const t0 = performance.now(); rec.start(250);
  await new Promise((ok) => { const tick = () => { const s = (performance.now() - t0) / 1000; x.setTransform(scale, 0, 0, scale, 0, 0); frame(x, spec, W0, H0, A, Math.min(s, dur), dur, plan); if (onProgress) onProgress(Math.min(1, s / dur)); if (s >= dur) ok(); else requestAnimationFrame(tick); }; requestAnimationFrame(tick); });
  rec.stop(); await done;
  for (const tr of stream.getTracks()) tr.stop();
  void audioFile;
  return { blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], codec: mime, width: W, height: H, frames: Math.round(dur * FPS), encoder: 'MediaRecorder', ms: Math.round(performance.now() - t0) };
}

// old name, same signature (screens/creatives.js)
export const recordMotion = renderVideo;
export function pickMime() { return window.VideoEncoder ? 'video/mp4' : ''; }
