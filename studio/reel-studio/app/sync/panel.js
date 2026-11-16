// KBB Studio · word-perfect sync — the "⏱ Sync" tab: status, one-click re-sync, timing nudge and lead, and
// per-word timing on a waveform (drag a word's edges or the whole word; one undo step per drag).
import { h, clear, slider, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { retimeWord } from '../captions/commands.js';
import { timelineSpeech } from '../captions/auto.js';
import { layoutSig } from './follow.js';
import { ALIGN_MODEL } from './align.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true;
  const s = document.createElement('style');
  s.textContent = `
.sy { padding-bottom: 20px; }
.sy-note { font-size: 12px; color: var(--muted); margin: 0 0 6px; }
.sy-stat { font-size: 12.5px; margin: 0 0 8px; }
.sy-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.sy-btns .btn { justify-content: center; padding: 7px 8px; font-size: 12.5px; }
.sy-btns .btn.wide { grid-column: span 2; }
.sy-wave { position: relative; width: 100%; height: 132px; border-radius: 8px; background: #101015; overflow: hidden; touch-action: none; cursor: default; }
.sy-wave canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
.sy-wnav { display: flex; gap: 6px; align-items: center; margin-top: 6px; font-size: 12px; color: var(--muted); }
.sy-wnav .grow { flex: 1; }
.sy-check { display: flex; gap: 8px; align-items: center; font-size: 12.5px; margin: 6px 0; }`;
  document.head.appendChild(s);
}

export function syncPanel(el, ctx, A) {
  css();
  const root = h('div.sy'); el.appendChild(root);
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const stat = h('p.sy-stat'), modelNote = h('p.sy-note');
  const meta = () => A.syncMeta(ctx.project);
  const setMeta = (patch, label) => ctx.history.apply(ctx.ops.setProject({ meta: { ...ctx.project.meta, sync: { ...meta(), ...patch } } }, label));

  const run = async (label, fn) => {
    try { const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { if (!e?.denied) toast(`${label}: ${e.message || e}`, 'error'); console.warn('[sync]', e); }
  };
  const syncBtn = h('button.btn.primary.wide', { on: { click: () => run('Sync', async () => { const s = await A.syncWords(ctx); render(); return `${s.words} words synced to the voice${s.model ? '' : ' (sound only)'}`; }) } }, '⏱ Sync words to the voice');
  const dlBtn = h('button.btn.wide', { on: { click: () => run('Download', async () => { await A.ensureSyncModel(ctx, (f) => ctx.progress?.('Downloading the sync model', f)); ctx.progress?.(null, null); render(); msCache = null; render(); return 'Sync model installed — press Sync words to the voice'; }) } }, '⬇ Download the sync model');
  const autoCb = h('input', { type: 'checkbox', on: { change: () => setMeta({ auto: autoCb.checked }, 'Auto sync') } });
  const followCb = h('input', { type: 'checkbox', on: { change: () => setMeta({ follow: followCb.checked }, 'Captions follow clips') } });
  const nudge = slider({ label: 'Caption timing', min: -300, max: 300, step: 5, value: meta().nudgeMs, fmt: (v) => (v > 0 ? '+' : '') + v + ' ms', onInput: (v) => setMeta({ nudgeMs: v }, 'Caption timing') });
  const lead = slider({ label: 'Show words early', min: 0, max: 200, step: 5, value: meta().leadMs, fmt: (v) => v + ' ms', onInput: (v) => setMeta({ leadMs: v }, 'Caption lead') });

  // ── waveform word editor ──
  const wave = h('div.sy-wave'), cv = h('canvas'); wave.appendChild(cv);
  const span = { w: 4 }, wlabel = h('span');
  const wnav = h('div.sy-wnav', h('button.chip', { on: { click: () => zoom(1.5) }, title: 'Show more time' }, '−'), h('button.chip', { on: { click: () => zoom(1 / 1.5) }, title: 'Show less time' }, '+'), wlabel, h('div.grow'),
    h('button.chip', { title: 'Previous word', on: { click: () => step(-1) } }, '◀ word'), h('button.chip', { title: 'Next word', on: { click: () => step(1) } }, 'word ▶'));
  let audio = null, audioSig = '', drag = null, peaks = null, msCache = null;   // model state: read on mount and on installer events only

  root.append(
    sec('Word-perfect sync',
      h('p.sy-note', 'Every caption word is placed where it is said: the speech model finds each word in the sound, then each start snaps to the moment the voice begins it. Captions keep that timing through trims, splits, speed changes, silence removal and re-ordering.'),
      stat, modelNote, h('div.sy-btns', syncBtn, dlBtn),
      h('label.sy-check', autoCb, 'Sync automatically after Auto captions'),
      h('label.sy-check', followCb, 'Captions follow the clips when I edit')),
    sec('Timing', h('p.sy-note', 'Move all captions earlier (−) or later (+), and optionally show each word a little before it is said. Preview and export move together.'), nudge, lead),
    sec('Word timing', h('p.sy-note', 'The sound around the playhead with each caption word on it. Drag a word to move it, drag its edges to change when it starts or ends. Click a word to jump to it.'), wave, wnav));

  async function render() {
    const p = ctx.project, m = meta(), cap = p.captions;
    autoCb.checked = m.auto !== false; followCb.checked = m.follow !== false;
    nudge.querySelector('input').value = m.nudgeMs; nudge.querySelector('.val').textContent = (m.nudgeMs > 0 ? '+' : '') + m.nudgeMs + ' ms';
    lead.querySelector('input').value = m.leadMs; lead.querySelector('.val').textContent = m.leadMs + ' ms';
    const n = cap?.words?.length || 0, L = m.last;
    stat.textContent = !n ? 'No captions yet — run Auto captions first.' : L ? `${n} words · last synced ${new Date(L.at).toLocaleTimeString()}: ${L.aligned} placed by the speech model, ${L.snapped} snapped to the voice${L.interpolated ? `, ${L.interpolated} placed between neighbours` : ''}${cap.offcut?.length ? ` · ${cap.offcut.length} words wait off the timeline (their part of the recording was cut)` : ''}.` : `${n} words · not synced yet.`;
    syncBtn.disabled = !n;
    const ms = msCache || (msCache = await A.modelState(ctx.engine));
    dlBtn.hidden = ms.installed;
    modelNote.textContent = ms.installed ? `Sync model installed (${ALIGN_MODEL.repo.split('/')[1]}, Apache-2.0).` : `The sync model is not installed: sync uses the sound only (good, not word-perfect). Download it once (${ms.entry ? Math.round(ms.entry.size / 1e6) + ' MB' : '≈250 MB'}) for word-perfect timing.`;
    drawWave();
  }

  // the timeline's speech, once per clip layout (decoded audio is cached by the media store)
  async function ensureAudio() {
    const sig = layoutSig(ctx.project);
    if (audio && sig === audioSig) return audio;
    audioSig = sig; audio = await timelineSpeech(ctx.project, ctx.media); peaks = null;
    return audio;
  }
  function view() { const t = ctx.playhead?.() || 0; return { a: Math.max(0, t - span.w / 2), b: Math.max(0, t - span.w / 2) + span.w }; }
  function zoom(f) { span.w = Math.max(1, Math.min(20, span.w * f)); drawWave(); }
  function step(d) {
    const ws = ctx.project.captions?.words || [], t = ctx.playhead?.() || 0;
    const i = d > 0 ? ws.findIndex((w) => w.t0 > t + 0.01) : ws.map((w) => w.t0 < t - 0.01).lastIndexOf(true);
    if (i >= 0) ctx.seek(ws[i].t0);
  }
  let raf = 0;
  function drawWave() { if (raf) return; raf = requestAnimationFrame(() => { raf = 0; paint(); }); }
  async function paint() {
    const W = wave.clientWidth, H = wave.clientHeight; if (!W) return;
    const dpr = window.devicePixelRatio || 1;
    if (cv.width !== Math.round(W * dpr)) { cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr); }
    const g = cv.getContext('2d'); g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, W, H);
    const { a, b } = view(), x = (t) => ((t - a) / (b - a)) * W;
    wlabel.textContent = `${a.toFixed(1)}–${b.toFixed(1)} s`;
    const au = audio && audioSig === layoutSig(ctx.project) ? audio : null;
    if (!au) { ensureAudio().then(() => drawWave()).catch(() => {}); }
    else {
      if (!peaks) { const n = Math.ceil(au.length / 80); peaks = new Float32Array(n); for (let i = 0; i < n; i++) { let m = 0; for (let k = i * 80, e = Math.min(au.length, k + 80); k < e; k++) m = Math.max(m, Math.abs(au[k])); peaks[i] = m; } }
      g.fillStyle = 'rgba(160,170,200,0.55)';
      for (let px = 0; px < W; px++) {
        const t0 = a + (px / W) * (b - a), t1 = a + ((px + 1) / W) * (b - a);
        let m = 0; for (let i = Math.floor(t0 * 200), e = Math.ceil(t1 * 200); i < e && i < peaks.length; i++) m = Math.max(m, peaks[i]);
        const hh = Math.min(1, Math.sqrt(m)) * (H - 40) / 2; g.fillRect(px, H / 2 + 10 - hh, 1, Math.max(1, hh * 2));
      }
    }
    const ws = ctx.project.captions?.words || [];
    g.font = '600 12px system-ui, sans-serif'; g.textBaseline = 'middle';
    ws.forEach((w, i) => {
      let t0 = w.t0, t1 = w.t1; if (drag && drag.i === i) { t0 = drag.t0; t1 = drag.t1; }
      if (t1 < a || t0 > b) return;
      const x0 = x(t0), x1 = Math.max(x0 + 3, x(t1));
      g.fillStyle = drag?.i === i ? 'rgba(232,80,139,0.55)' : 'rgba(232,80,139,0.28)'; g.fillRect(x0, 6, x1 - x0, 24);
      g.fillStyle = '#e8508b'; g.fillRect(x0, 6, 2, H - 12); g.fillStyle = 'rgba(232,80,139,0.5)'; g.fillRect(x1 - 1, 6, 1, 24);
      g.save(); g.beginPath(); g.rect(x0, 6, x1 - x0, 24); g.clip(); g.fillStyle = '#fff'; g.fillText(w.w, x0 + 4, 18); g.restore();
    });
    const ph = ctx.playhead?.() || 0; g.fillStyle = '#ffd166'; g.fillRect(x(ph) - 1, 0, 2, H);
  }
  wave.addEventListener('pointerdown', (e) => {
    const r = wave.getBoundingClientRect(), { a, b } = view(), tAt = (cx) => a + ((cx - r.left) / r.width) * (b - a);
    const t = tAt(e.clientX), ws = ctx.project.captions?.words || [], tol = (b - a) * 6 / r.width;
    let i = ws.findIndex((w) => t >= w.t0 - tol && t <= w.t1 + tol);
    if (i < 0) { ctx.seek(t); return; }
    const w = ws[i], mode = Math.abs(t - w.t0) <= tol ? 'l' : Math.abs(t - w.t1) <= tol ? 'r' : 'm';
    drag = { i, mode, t, t0: w.t0, t1: w.t1, moved: false };
    wave.setPointerCapture(e.pointerId);
    const move = (ev) => {
      const dt = tAt(ev.clientX) - drag.t; if (Math.abs(dt) > 0.004) drag.moved = true;
      if (mode === 'l') drag.t0 = Math.min(w.t1 - 0.02, w.t0 + dt); else if (mode === 'r') drag.t1 = Math.max(w.t0 + 0.02, w.t1 + dt); else { drag.t0 = w.t0 + dt; drag.t1 = w.t1 + dt; }
      drawWave();
    };
    const up = () => {
      wave.removeEventListener('pointermove', move); wave.removeEventListener('pointerup', up); wave.removeEventListener('pointercancel', up);
      const d = drag; drag = null;
      if (d.moved) ctx.history.apply(retimeWord(d.i, d.t0, d.t1)); else ctx.seek(w.t0);
      drawWave();
    };
    wave.addEventListener('pointermove', move); wave.addEventListener('pointerup', up); wave.addEventListener('pointercancel', up);
  });
  wave.style.cursor = 'ew-resize';

  const offs = [bus.on('project:change', () => render()), bus.on('playhead', () => drawWave()), bus.on('engine:models', () => { msCache = null; render(); })];
  const ro = new ResizeObserver(() => drawWave()); ro.observe(wave);
  render();
  return { destroy() { offs.forEach((o) => o?.()); ro.disconnect(); } };
}
