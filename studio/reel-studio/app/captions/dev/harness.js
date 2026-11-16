// Lane R2 dev harness (not shipped in the editor): window.R2 is what tools/r2-check.cjs drives.
import { Transcriber } from '../stt.js';
import { drawCaptions } from '../render.js';
import { STYLES } from '../styles.js';
import { buildLines, validate } from '../model.js';
import { loadCaptionFonts } from '../fonts.js';
import '../index.js';                                  // registers the caption layer + the module slot
import { mountCaptionPanel } from '../panel.js';
import * as commands from '../commands.js';
import { History, ops } from '../../core/history.js';
import { bus } from '../../core/bus.js';
import { renderFrame } from '../../core/render.js';
import { createProject, createItem, tracksOfKind } from '../../core/project.js';
import { getModule } from '../../core/modules.js';
import { styleForWorkspace, resolveStyle } from '../styles.js';
import { SEED_WORKSPACES } from '../../core/config.js';
import { canvasGrab } from '../../core/modules.js';
import { captureWords } from '../auto.js';
import * as script from '../script.js';
import * as nonspeech from '../nonspeech.js';
import * as vocab from '../vocab.js';
import { captionBlocks } from '../render.js';
import { planWindows } from '../stt.js';

const VENDOR = new URL('../../../vendor/transformers/', import.meta.url).href;
const MODELS = new URL('../../../models/', import.meta.url).href;

let ttsLib = null;
const tts = {};
async function ttsPipe(lang) {
  if (!ttsLib) {
    ttsLib = await import(VENDOR + 'transformers.min.js');
    ttsLib.env.allowRemoteModels = false; ttsLib.env.allowLocalModels = true; ttsLib.env.localModelPath = MODELS; ttsLib.env.useBrowserCache = false;
    ttsLib.env.backends.onnx.wasm.wasmPaths = VENDOR;
    ttsLib.env.backends.onnx.wasm.numThreads = self.crossOriginIsolated ? 4 : 1;
  }
  if (!tts[lang]) {
    // VITS directly (the repo has no preprocessor_config.json, which the TTS pipeline asks for)
    const id = `Xenova/mms-tts-${lang}`;
    const tok = await ttsLib.AutoTokenizer.from_pretrained(id);
    const model = await ttsLib.VitsModel.from_pretrained(id, { dtype: 'fp32' });
    tts[lang] = async (text) => {
      const { waveform } = await model(tok(text));
      return { audio: waveform.data, sampling_rate: model.config.sampling_rate };
    };
  }
  return tts[lang];
}

async function resample(data, sr, to = 16000) {
  if (sr === to) return data;
  const ctx = new OfflineAudioContext(1, Math.ceil(data.length * to / sr), to);
  const b = ctx.createBuffer(1, data.length, sr); b.copyToChannel(data, 0);
  const s = ctx.createBufferSource(); s.buffer = b; s.connect(ctx.destination); s.start();
  return (await ctx.startRendering()).getChannelData(0);
}

const transcriber = new Transcriber();

export const R2 = {
  ready: loadCaptionFonts(),
  styles: STYLES.map((s) => ({ id: s.id, name: s.name, group: s.group })),
  /** synthetic speech (MMS-TTS, offline) -> mono 16 kHz Float32Array, with `pause` s of silence after each sentence */
  async tts(sentences, lang = 'eng', pause = 0.5) {
    const p = await ttsPipe(lang);
    const parts = [];
    for (const s of sentences) {
      const o = await p(s);
      parts.push(await resample(o.audio, o.sampling_rate), new Float32Array(Math.round(16000 * pause)));
    }
    return concat(parts);
  },
  async loadAudio(url) {
    const buf = await (await fetch(url)).arrayBuffer();
    const b = await new OfflineAudioContext(1, 1, 16000).decodeAudioData(buf);
    return b.getChannelData(0).slice();
  },
  concat,
  async saveWav(name, data) {
    await fetch(MODELS + '_r2-test/' + name, { method: 'PUT', body: wav(data, 16000) });
  },
  async transcribe(audio, opts) {
    const r = await transcriber.transcribe(audio, opts);
    return r;
  },
  transcriber,
  /** the whole capture pipeline (what Auto captions runs) */
  capture: (audio, opts) => captureWords(transcriber, audio, opts),
  script, nonspeech, vocab, planWindows, captionBlocks, renderFrame,
  /** a fake engine: writeFile() lands in window.__writes (the harness server has no write API) */
  fakeEngine: { available: true, async writeFile(path, data) { (window.__writes ||= []).push({ path, data: typeof data === 'string' ? data : JSON.stringify(data) }); return path; } },
  /** decode any audio file (m4a/wav) to mono 16 kHz */
  async loadAudio16(url) {
    const buf = await (await fetch(url)).arrayBuffer();
    const b = await new OfflineAudioContext(1, 1, 44100).decodeAudioData(buf);
    const mono = b.numberOfChannels > 1 ? b.getChannelData(0).map((v, i) => (v + b.getChannelData(1)[i]) / 2) : b.getChannelData(0).slice();
    return resample(mono, b.sampleRate, 16000);
  },
  /** out = a + b*gain starting at `at` seconds (lengths: the longer one) */
  mix(a, b, at = 0, gain = 1) {
    const o = Math.floor(at * 16000), out = new Float32Array(Math.max(a.length, o + b.length));
    out.set(a); for (let i = 0; i < b.length; i++) out[o + i] += b[i] * gain;
    return out;
  },
  noise(sec, amp = 0.0005) { const n = new Float32Array(Math.round(sec * 16000)); let s = 12345; for (let i = 0; i < n.length; i++) { s = (s * 1103515245 + 12345) & 0x7fffffff; n[i] = (s / 0x7fffffff * 2 - 1) * amp; } return n; },
  __draw: (ctx, project, t) => drawCaptions(ctx, null, t, project),
  validate, buildLines, commands, resolveStyle, getModule, styleForWorkspace, SEED_WORKSPACES,
  /** A real core project + History + bus, the caption panel mounted, the preview drawn by core/render. */
  mountEditor({ words = [], style = null, workspace = null, aspect = '9:16', alt = null, extra = null, engine = null } = {}) {
    if (window.ED) { window.ED.panel.destroy(); window.ED.off(); window.ED.offDrag?.(); }
    const project = createProject({ name: 'R2 harness', aspect, workspace: workspace?.id || null });
    const cv = document.getElementById('preview');
    cv.width = project.canvas.w; cv.height = project.canvas.h;
    cv.style.aspectRatio = `${project.canvas.w} / ${project.canvas.h}`; cv.style.height = 'auto';
    const history = new History(() => project, { bus });
    let t = 0;
    const draw = () => { const c = cv.getContext('2d'); renderFrame(c, project, t, { preview: true }); };
    const ctx = {
      get project() { return project; }, history, ops, bus, workspace, engine, media: null,
      playhead: () => t, seek: (x) => { t = x; bus.emit('playhead', { t }); draw(); },
      toast: (text, kind) => { (window.__toasts ||= []).push({ text, kind }); }, progress: () => {},
    };
    const off = bus.on('project:change', draw);
    if (words.length) {
      const sp = style || styleForWorkspace(workspace);
      const cap = { style: sp, lang: 'auto', words, lines: buildLines(words, sp, project.canvas), ...(extra || {}) };
      if (alt) cap.alt = { ...alt, lines: buildLines(alt.words, sp, project.canvas) };
      const track = tracksOfKind(project, 'caption')[0];
      history.apply(ops.batch('Auto captions', [commands.setCaptions(cap),
        ops.addItems([{ trackId: track.id, item: createItem('caption', { t0: 0, in: 0, out: words[words.length - 1].t1 + 1, w: project.canvas.w, h: project.canvas.h }) }])]));
    }
    const panel = mountCaptionPanel(document.getElementById('panel'), ctx);
    // on-canvas drag, wired exactly like editor/preview.js: core canvasGrab, then pointer moves, then seal
    const toCanvas = (e) => { const r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) * project.canvas.w / r.width, y: (e.clientY - r.top) * project.canvas.h / r.height }; };
    const onDown = (e) => {
      const p0 = toCanvas(e);
      const grab = canvasGrab(project, t, p0, e, { apply: (cmd) => history.apply(cmd) });
      if (!grab) return;
      e.preventDefault();
      const mv = (ev) => { const p = toCanvas(ev); grab.move(p.x - p0.x, p.y - p0.y, ev); };
      const up = (ev) => { removeEventListener('pointermove', mv); removeEventListener('pointerup', up); grab.end?.(ev); history.seal(); };
      addEventListener('pointermove', mv); addEventListener('pointerup', up);
    };
    cv.addEventListener('pointerdown', onDown);
    draw();
    return (window.ED = { project, history, ctx, panel, draw, seek: ctx.seek, off, offDrag: () => cv.removeEventListener('pointerdown', onDown) });
  },
  /** Draw one frame of `project` at time t onto a 1080-wide canvas over a test backdrop -> PNG data URL */
  render(project, t, bg = 'photo', type = 'image/png') { return R2.renderCanvas(project, t, bg).toDataURL(type, 0.88); },
  renderCanvas(project, t, bg = 'photo') {
    const { w, h } = project.canvas;
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const ctx = c.getContext('2d');
    backdrop(ctx, w, h, bg);
    drawCaptions(ctx, { type: 'caption', style: null }, t, project, { box: { x: 0, y: 0, w, h } });
    return c;
  },
};

function concat(parts) {
  const n = parts.reduce((s, p) => s + p.length, 0), out = new Float32Array(n);
  let o = 0; for (const p of parts) { out.set(p, o); o += p.length; }
  return out;
}

function wav(f32, sr) {
  const b = new ArrayBuffer(44 + f32.length * 2), v = new DataView(b);
  const s = (o, str) => [...str].forEach((ch, i) => v.setUint8(o + i, ch.charCodeAt(0)));
  s(0, 'RIFF'); v.setUint32(4, 36 + f32.length * 2, true); s(8, 'WAVE'); s(12, 'fmt ');
  v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true); v.setUint32(24, sr, true);
  v.setUint32(28, sr * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true); s(36, 'data'); v.setUint32(40, f32.length * 2, true);
  for (let i = 0; i < f32.length; i++) v.setInt16(44 + i * 2, Math.max(-1, Math.min(1, f32[i])) * 32767, true);
  return b;
}

/** A soft "video-like" backdrop so outlines/shadows/glows read like they will over footage. */
export function backdrop(ctx, w, h, kind = 'photo') {
  const g = ctx.createLinearGradient(0, 0, w, h);
  g.addColorStop(0, '#f3c9a8'); g.addColorStop(0.45, '#b9707f'); g.addColorStop(1, '#2d2340');
  ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
  ctx.globalAlpha = 0.35;
  for (let i = 0; i < 9; i++) {
    ctx.fillStyle = i % 2 ? '#ffffff' : '#000000';
    ctx.beginPath(); ctx.arc((i * 337) % w, (i * 613) % h, w * (0.08 + (i % 3) * 0.05), 0, Math.PI * 2); ctx.fill();
  }
  ctx.globalAlpha = 1;
}

window.R2 = R2;
