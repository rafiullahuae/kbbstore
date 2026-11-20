// Short motion ads (6-15 s, 9:16 or 4:5) rendered in the browser: the design
// template animates in (product, headline, chips), then timed captions are
// burned in inside the safe zone (Arabic RTL captions for Arabic ads), then an
// end card with the CTA. Recorded from the canvas with MediaRecorder: MP4
// (H.264) where the browser can record it, WebM otherwise. Audio: none, or the
// owner's own audio file (mixed in through Web Audio). No external services.
import { FORMATS, assets, draw } from './render.js';

export function pickMime() {
  const c = ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
  return c.find((m) => window.MediaRecorder && MediaRecorder.isTypeSupported(m)) || '';
}

// Captions: headline, sub, each bullet, then the CTA -- one at a time.
function captionsFor(spec) {
  const c = [spec.sub, ...(spec.bullets || [])].filter(Boolean);
  return c.length ? c : [spec.headline];
}

function caption(x, spec, W, H, text, a) {
  if (!text || a <= 0) return;
  const tall = H / W > 1.6;
  const y = tall ? H * 0.585 : H * 0.8; // 9:16: above the bottom 35% Reels UI
  const ar = spec.lang === 'ar';
  x.save();
  x.globalAlpha = a;
  x.font = `${ar ? '700' : '600'} ${Math.round(W * 0.042)}px ${ar ? 'Cairo' : 'Outfit'}`;
  x.direction = ar ? 'rtl' : 'ltr';
  x.textAlign = 'center';
  const w = Math.min(W * 0.86, x.measureText(text).width + W * 0.08), h = W * 0.08;
  x.fillStyle = 'rgba(42,34,40,.78)';
  x.beginPath(); x.roundRect ? x.roundRect(W / 2 - w / 2, y - h / 2, w, h, h / 2) : x.rect(W / 2 - w / 2, y - h / 2, w, h); x.fill();
  x.fillStyle = '#fff';
  x.textBaseline = 'middle';
  x.fillText(text, W / 2, y + W * 0.004, W * 0.8);
  x.restore();
}

function endCard(x, spec, W, H, a) {
  if (a <= 0) return;
  const ar = spec.lang === 'ar';
  x.save();
  x.globalAlpha = a * 0.92;
  const g = x.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#E0567B'); g.addColorStop(1, '#5A1F35');
  x.fillStyle = g; x.fillRect(0, 0, W, H);
  x.globalAlpha = a;
  x.fillStyle = '#fff'; x.textAlign = 'center'; x.direction = ar ? 'rtl' : 'ltr';
  const tall = H / W > 1.6, cy = tall ? H * 0.4 : H * 0.45;
  x.font = `${ar ? '800' : '600'} ${Math.round(W * 0.07)}px ${ar ? 'Cairo' : '"Playfair Display"'}`;
  x.fillText(spec.badge || 'K-Beauty Bliss', W / 2, cy, W * 0.86);
  x.font = `600 ${Math.round(W * 0.04)}px ${ar ? 'Tajawal' : 'Outfit'}`;
  x.fillText(ar ? 'تسوّقي الآن على kbeautybliss.com' : 'Shop now at kbeautybliss.com', W / 2, cy + W * 0.09, W * 0.86);
  if (spec.price) { x.font = `700 ${Math.round(W * 0.05)}px ${ar ? 'Cairo' : 'Outfit'}`; x.fillText(spec.price, W / 2, cy + W * 0.19); }
  x.restore();
}

// Draw frame at time s (seconds) of a `dur`-second ad.
export function frame(x, spec, W, H, A, s, dur) {
  const intro = Math.min(2.2, dur * 0.25), end = Math.min(2.5, dur * 0.2);
  const t = Math.min(1, s / intro);
  // gentle Ken Burns on the whole design
  const z = 1 + 0.04 * (s / dur);
  x.save();
  x.translate(W / 2, H / 2); x.scale(z, z); x.translate(-W / 2, -H / 2);
  draw(x, spec, W, H, A, t);
  x.restore();
  const caps = captionsFor(spec);
  const mid0 = intro, mid1 = dur - end;
  if (s >= mid0 && s < mid1) {
    const per = (mid1 - mid0) / caps.length, k = Math.min(caps.length - 1, Math.floor((s - mid0) / per));
    const local = (s - mid0 - k * per) / per;
    caption(x, spec, W, H, caps[k], Math.min(1, local * 5, (1 - local) * 5));
  }
  endCard(x, spec, W, H, Math.min(1, (s - mid1) / 0.5));
}

// Render and record. Returns {blob, mime, ms}. onProgress(0..1).
export async function recordMotion(spec, format = '9:16', dur = 8, audioFile = null, onProgress) {
  const [W0, H0] = FORMATS[format] || FORMATS['9:16'];
  const scale = 720 / W0; // record at 720 wide: smaller files, same look
  const W = Math.round(W0 * scale), H = Math.round(H0 * scale);
  const cv = document.createElement('canvas');
  cv.width = W; cv.height = H;
  const x = cv.getContext('2d');
  const A = await assets(spec);
  const mime = pickMime();
  if (!mime) throw new Error('This browser cannot record video');
  const stream = cv.captureStream(30);
  let actx = null;
  if (audioFile) {
    actx = new AudioContext();
    const buf = await actx.decodeAudioData(await audioFile.arrayBuffer());
    const src = actx.createBufferSource(); src.buffer = buf;
    const gain = actx.createGain(); gain.gain.value = 0.8;
    const dest = actx.createMediaStreamDestination();
    src.connect(gain).connect(dest);
    src.start();
    for (const tr of dest.stream.getAudioTracks()) stream.addTrack(tr);
  }
  const rec = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 3_000_000 });
  const chunks = [];
  rec.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
  const done = new Promise((ok) => { rec.onstop = ok; });
  const t0 = performance.now();
  rec.start(250);
  await new Promise((ok) => {
    const tick = () => {
      const s = (performance.now() - t0) / 1000;
      x.setTransform(scale, 0, 0, scale, 0, 0);
      frame(x, spec, W0, H0, A, Math.min(s, dur), dur);
      if (onProgress) onProgress(Math.min(1, s / dur));
      if (s >= dur) ok(); else requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
  rec.stop();
  await done;
  if (actx) actx.close();
  for (const tr of stream.getTracks()) tr.stop();
  return { blob: new Blob(chunks, { type: mime.split(';')[0] }), mime: mime.split(';')[0], ms: Math.round(performance.now() - t0), width: W, height: H };
}
