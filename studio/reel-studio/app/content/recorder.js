// KBB Studio · content · teleprompter + recording in the studio, from the Mac's camera and microphone
// (getUserMedia + MediaRecorder; nothing leaves the Mac). Scrolling script with speed, size and mirror, a 3-2-1
// countdown, any number of takes, and a best-take picker:
//   fewest fillers  (Whisper, when the speech model is installed: "um", "uh", "like", Arabic fillers per minute)
//   best energy     (loudness and its movement — a flat, quiet read scores low — and how much of the take is speech)
//   eyes on camera  (the face found, centred and facing the lens across the take; when faces are available)
// Takes are saved into the project's media folder; "Use this take" puts it on the timeline (one undo step).
import { vad } from '../auto/lib/audio.js';
import { fillers as findFillers } from '../auto/lib/audio.js';
import { projectDir } from '../core/config.js';
import { ops } from '../core/history.js';
import { projectDuration } from '../core/project.js';
import { h, modal, toast } from '../editor/ui.js';

export function recorderMime() {
  for (const m of ['video/mp4;codecs=avc1.42E01F,mp4a.40.2', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'])
    if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) return m;
  return '';
}
export const extFor = (mime) => (/mp4/.test(mime) ? 'mp4' : 'webm');

export async function openCamera({ width = 1080, height = 1920, deviceId } = {}) {
  if (!navigator.mediaDevices?.getUserMedia) throw new Error('This browser cannot use the camera');
  return navigator.mediaDevices.getUserMedia({ video: { width: { ideal: width }, height: { ideal: height }, deviceId, facingMode: 'user', frameRate: { ideal: 30 } },
    audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: false } });
}

/** a recording session on a stream: start() → stop() resolves {blob, mime, dur} */
export class Take {
  constructor(stream, mime = recorderMime()) { this.stream = stream; this.mime = mime; this.chunks = []; }
  start() {
    this.rec = new MediaRecorder(this.stream, { mimeType: this.mime, videoBitsPerSecond: 8e6, audioBitsPerSecond: 160e3 });
    this.rec.ondataavailable = (e) => { if (e.data.size) this.chunks.push(e.data); };
    this.t0 = performance.now(); this.rec.start(500);
  }
  stop() {
    return new Promise((res) => { this.rec.onstop = () => res({ blob: new Blob(this.chunks, { type: this.mime.split(';')[0] }), mime: this.mime, dur: (performance.now() - this.t0) / 1000 }); this.rec.stop(); });
  }
}

/** score a take: { dur, energy, speech, fillers, fillersPerMin, eyes, score, why } — every part 0..1 except counts */
export async function scoreTake(blob, { transcribe = true, faces = true } = {}) {
  const buf = await blob.arrayBuffer();
  let x = null, sr = 16000;
  try { const ab = await new OfflineAudioContext(1, 1, sr).decodeAudioData(buf.slice(0)); x = ab.getChannelData(0); } catch {}
  const out = { dur: x ? x.length / sr : 0, energy: 0, speech: 0, fillers: null, fillersPerMin: null, eyes: null };
  if (x && x.length > sr * 0.5) {
    const v = vad(x, sr);
    const sp = v.speech.reduce((s, [a, b]) => s + b - a, 0);
    out.speech = +(sp / out.dur).toFixed(3);
    const voiced = v.db.filter((d, i) => v.speech.some(([a, b]) => i * v.hop >= a && i * v.hop < b));
    const mean = voiced.length ? voiced.reduce((a, b) => a + b, 0) / voiced.length : -80;
    const sd = voiced.length ? Math.sqrt(voiced.reduce((a, b) => a + (b - mean) ** 2, 0) / voiced.length) : 0;
    // loud enough (−30 dBFS and up is a confident read) and lively (pitch/loudness movement shows as level spread)
    out.energy = +Math.max(0, Math.min(1, 0.6 * Math.min(1, Math.max(0, (mean + 45) / 25)) + 0.4 * Math.min(1, sd / 9))).toFixed(3);
    out.level = +mean.toFixed(1);
    if (transcribe) {
      try {
        const { engine } = await import('../core/engine.js');
        const st = await engine.modelsStatus();
        const base = st.models.find((m) => m.id === 'whisper-base')?.installed, small = st.models.find((m) => m.id === 'whisper-small')?.installed;
        if (base || small) {
          const { transcriber } = await import('../captions/auto.js');
          const r = await transcriber().transcribe(x, { model: base ? 'base' : 'small', lang: 'auto' });
          const f = findFillers(r.words).filter((y) => y.kind !== 'soft');
          out.fillers = f.length; out.fillersPerMin = +(f.length / Math.max(0.25, out.dur / 60)).toFixed(2); out.words = r.words.length;
        }
      } catch (e) { console.warn('[content] take transcript:', e.message); }
    }
  }
  if (faces) {
    try {
      const url = URL.createObjectURL(blob), el = document.createElement('video'); el.muted = true; el.src = url;
      await new Promise((r, j) => { el.onloadedmetadata = r; el.onerror = () => j(new Error('take not playable')); });
      // MediaRecorder files often report Infinity until read through once
      if (!isFinite(el.duration)) { el.currentTime = 1e9; await new Promise((r) => { el.ontimeupdate = () => { el.ontimeupdate = null; r(); }; }); }
      const D = isFinite(el.duration) ? el.duration : out.dur, frames = [];
      for (let i = 0; i < 6; i++) { el.currentTime = D * (0.1 + 0.8 * i / 5); await new Promise((r) => { el.onseeked = r; }); frames.push({ t: el.currentTime, bitmap: await createImageBitmap(el, { resizeWidth: 256 }) }); }
      URL.revokeObjectURL(url);
      const { trackFaces } = await import('../auto/faces.js');
      const fs = await trackFaces(frames);
      const seen = fs.filter((f) => f.x != null);
      out.facesSeen = seen.length;
      // eyes on camera ≈ a face, centred left-right, upright box (turned heads give narrow boxes), every frame
      out.eyes = seen.length ? +(seen.reduce((s, f) => s + (1 - Math.min(1, Math.abs(f.x - 0.5) * 2.5)) * Math.min(1, (f.w / Math.max(0.01, f.h)) / 0.75), 0) / fs.length).toFixed(3) : 0;
      if (!out.dur) out.dur = D;
    } catch (e) { console.warn('[content] take faces:', e.message); }
  }
  const fl = out.fillersPerMin == null ? null : Math.max(0, 1 - out.fillersPerMin / 8);
  const parts = [[out.energy, 0.4], [out.speech, 0.15], [fl, 0.25], [out.eyes, 0.2]].filter(([v]) => v != null);
  const wsum = parts.reduce((s, [, w]) => s + w, 0);
  out.score = +(parts.reduce((s, [v, w]) => s + v * w, 0) / wsum).toFixed(3);
  out.why = [out.energy >= 0.6 ? 'good energy' : 'low energy', fl != null ? `${out.fillers} filler${out.fillers === 1 ? '' : 's'}` : null, out.eyes != null ? (out.eyes >= 0.6 ? 'eyes on camera' : 'look at the lens') : null].filter(Boolean).join(' · ');
  return out;
}
export const bestTake = (takes) => takes.filter((t) => t.score).reduce((a, b) => (!a || b.score.score > a.score.score ? b : a), null);

/** save a take into the project's media folder and the bin; returns the media entry */
export async function saveTake(ctx, take, n) {
  const p = ctx.project, name = `take-${String(n).padStart(2, '0')}.${extFor(take.mime)}`;
  const file = new File([take.blob], name, { type: take.blob.type });
  const m = await ctx.media.importFile(file, projectDir(p.workspace, p.id));
  ctx.history.apply(ops.addMedia(m, 'Recorded take'));
  return m;
}
/** put a take on the main track at the end of the reel (or at 0 when empty) */
export function useTake(ctx, m) {
  const p = ctx.project, vt = p.tracks.find((t) => t.kind === 'video');
  const t0 = vt.items.length ? projectDuration(p) : 0, c = p.canvas;
  const s = Math.max(c.w / (m.w || c.w), c.h / (m.h || c.h)), w = Math.round((m.w || c.w) * s), hh = Math.round((m.h || c.h) * s);
  const it = { id: 'i' + Date.now().toString(36), type: 'clip', media: m.id, t0, in: 0, out: m.dur || 1, speed: 1, x: Math.round((c.w - w) / 2), y: Math.round((c.h - hh) / 2), w, h: hh, rot: 0, opacity: 1, fx: [], kf: {}, volume: 1, muted: false, fadeIn: 0, fadeOut: 0, name: m.name };
  ctx.history.apply(ops.addItems([{ trackId: vt.id, item: it }], 'Use take'));
  return it;
}

/** the teleprompter screen (modal). Returns {close} */
export function openTeleprompter(ctx, { script = '' } = {}) {
  const st = { wpm: 140, size: 44, mirror: true, takes: [], stream: null, take: null, raf: 0, y: 0, last: 0, running: false };
  const video = h('video.tp-cam', { autoplay: true, muted: true, playsinline: true });
  const text = h('div.tp-text');
  const roll = h('div.tp-roll', text);
  const area = h('textarea.tp-script', { rows: 5, placeholder: 'Paste or type your script here…', 'aria-label': 'Script' });
  area.value = script || defaultScript(ctx);
  const setText = () => { text.textContent = area.value; text.style.fontSize = st.size + 'px'; };
  area.addEventListener('input', setText); setText();
  const count = h('div.tp-count', { hidden: true });
  const recBtn = h('button.btn.primary.tp-rec', { on: { click: () => (st.take ? stop() : start()) } }, '● Record');
  const list = h('div.tp-takes');
  const status = h('span.muted.small');
  const speed = h('input', { type: 'range', min: 80, max: 220, step: 5, value: st.wpm, 'aria-label': 'Speed', on: { input: (e) => { st.wpm = +e.target.value; sv.textContent = st.wpm + ' words/min'; } } });
  const sv = h('small.muted', st.wpm + ' words/min');
  const size = h('input', { type: 'range', min: 28, max: 80, step: 2, value: st.size, 'aria-label': 'Text size', on: { input: (e) => { st.size = +e.target.value; setText(); } } });
  const mir = h('input', { type: 'checkbox', checked: st.mirror, on: { change: (e) => { st.mirror = e.target.checked; video.classList.toggle('mirror', st.mirror); } } });
  video.classList.toggle('mirror', st.mirror);
  const body = h('div.tp',
    h('div.tp-stage', video, roll, count),
    h('div.tp-side',
      h('label.tp-row', 'Script', area),
      h('label.tp-row', h('span', 'Scroll speed '), speed, sv),
      h('label.tp-row', h('span', 'Text size '), size),
      h('label.tp-check', mir, ' Mirror the camera'),
      h('div.tp-actions', recBtn, status),
      h('h3', 'Takes'), list));
  const dlg = modal({ title: '🎬 Teleprompter', body, wide: true, onClose: () => close() });
  dlg.box?.classList?.add('tp-modal');
  (async () => { try { st.stream = await openCamera(); video.srcObject = st.stream; status.textContent = 'Camera ready'; } catch (e) { status.textContent = 'Camera unavailable: ' + e.message; recBtn.disabled = true; } })();

  function scroll(ts) {
    if (!st.running) return;
    const dt = st.last ? (ts - st.last) / 1000 : 0; st.last = ts;
    // words per minute → pixels: one line holds ~ (width / (0.55·size)) / 5.5 words
    const lineWords = Math.max(3, roll.clientWidth / (st.size * 0.55) / 5.5);
    st.y += dt * (st.wpm / 60) / lineWords * st.size * 1.35;
    text.style.transform = `translateY(${-st.y}px)`;
    st.raf = requestAnimationFrame(scroll);
  }
  async function start() {
    if (!st.stream) return;
    recBtn.disabled = true; count.hidden = false;
    for (const n of [3, 2, 1]) { count.textContent = n; await new Promise((r) => setTimeout(r, 800)); }
    count.hidden = true; recBtn.disabled = false; recBtn.textContent = '■ Stop'; recBtn.classList.add('on');
    st.y = 0; st.last = 0; text.style.transform = 'translateY(0)';
    st.take = new Take(st.stream); st.take.start();
    st.running = true; st.raf = requestAnimationFrame(scroll);
  }
  async function stop() {
    st.running = false; cancelAnimationFrame(st.raf);
    recBtn.textContent = '● Record'; recBtn.classList.remove('on');
    const r = await st.take.stop(); st.take = null;
    const n = st.takes.length + 1, row = { n, ...r, score: null, media: null };
    st.takes.push(row); render(); status.textContent = `Take ${n}: checking…`;
    try { row.media = await saveTake(ctx, r, n); } catch (e) { toast('Take not saved: ' + e.message, 'error'); }
    try { row.score = await scoreTake(r.blob); } catch (e) { console.warn('[content] score:', e.message); }
    status.textContent = `Take ${n} saved`; render();
  }
  function render() {
    const best = bestTake(st.takes);
    list.replaceChildren(...st.takes.map((t) => h('div.tp-take' + (t === best ? '.best' : ''),
      h('b', `Take ${t.n}`), h('small', `${t.dur.toFixed(1)} s${t.score ? ' · score ' + Math.round(t.score.score * 100) + ' · ' + t.score.why : ' · checking…'}`),
      t === best ? h('span.tp-badge', '★ Best') : null,
      h('button.btn.small', { disabled: !t.media, on: { click: () => { useTake(ctx, t.media); toast(`Take ${t.n} is on the timeline`, 'ok'); } } }, 'Use this take'))));
  }
  function close() { st.running = false; cancelAnimationFrame(st.raf); try { st.take?.rec?.stop(); } catch {} st.stream?.getTracks().forEach((t) => t.stop()); }
  return { close, state: st, dialog: dlg };
}

function defaultScript(ctx) {
  const p = ctx.project, f = p.meta?.content?.format;
  if (p.captions?.words?.length) return p.captions.words.map((w) => w.w).join(' ');
  if (f) return `Hook: say it in the first 2 seconds.\n\nThen the main point, one idea per sentence.\n\nEnd with the call to action.`;
  return '';
}
