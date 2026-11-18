// KBB Studio · content · long video → 8–12 shorts. From a 10–30 min webinar or consultation: transcript → topics →
// the best self-contained 20–60 s moment of each (lib/shorts-plan.js) → a finished 9:16 reel per moment, saved as its
// own project in the company: reframed onto the speaker, captions in the viral look, a hook on screen for the first
// seconds, the company's CTA at the end. Batch export writes them all.
import { createProject, createItem, normalizeProject, findMedia, fitBox, clone, uid, srcTime } from '../core/project.js';
import { MediaStore } from '../core/media.js';
import { exportMp4 } from '../core/export.js';
import { exportsDir } from '../core/config.js';
import { buildLines } from '../captions/model.js';
import { safeArea } from '../captions/render.js';
import { reframe, cropToItemKf } from '../auto/lib/video.js';
import { planShorts } from './lib/shorts-plan.js';
import { lookSpec } from './looks.js';
import { brandPop, contrast } from './lib/catalogue.js';
import { brandText, mainVideo, r1 } from './lib/items.js';

/** faces in [t0,t1] of a media (1 per second), for the reframe; [] when the face finder is unavailable */
async function facesIn(ctx, mediaId, t0, t1) {
  try {
    const { trackFaces } = await import('../auto/faces.js');
    const frames = [];
    for (let t = t0; t <= t1; t += 1) frames.push({ t, bitmap: await ctx.media.frameAt(mediaId, t, 256) });
    return await trackFaces(frames);
  } catch (e) { console.warn('[content] shorts faces:', e.message); return []; }
}

/**
 * Plan + build the shorts. opts: { min=20, max=60, faces=true, look, onProgress }.
 * Returns { shorts:[{id, name, t0, t1, dur, title, firstWord, words, cta}], topics, sentences }.
 */
export async function makeShorts(ctx, opts = {}) {
  let p = ctx.project;
  const mv = mainVideo(p); if (!mv) throw new Error('Add the long video first');
  if (!p.captions?.words?.length) {
    const { autoCaptions } = await import('../captions/auto.js'); await autoCaptions(ctx); p = ctx.project;
    if (!p.captions?.words?.length) throw new Error('No speech found in the video');
  }
  const { item, media } = mv;
  // the transcript in SOURCE time of the long clip (a ramp / reverse maps through core/retime.js; words off the clip fall out)
  const toSrc = (t) => (item.curve || item.reverse ? (t < item.t0 || t > item.t0 + (item.out - item.in) / (item.speed || 1) ? -1e9 : srcTime(item, t)) : item.in + (t - item.t0) * (item.speed || 1));
  const words = p.captions.words.map((w) => ({ ...w, t0: toSrc(w.t0), t1: toSrc(w.t1) })).filter((w) => w.t0 >= item.in - 0.05 && w.t1 <= item.out + 0.05);
  let energy = null;
  try {
    const a = await ctx.media.decodeAudio(media.id);
    if (a?.mono16k) { const x = a.mono16k, sr = 16000; const rms = (t0, t1) => { let s = 0, n = 0; for (let i = Math.floor(t0 * sr); i < Math.min(x.length, t1 * sr); i += 160) { s += x[i] * x[i]; n++; } return n ? Math.sqrt(s / n) : 0; };
      const all = rms(0, media.dur || x.length / sr) || 1e-4; energy = (t0, t1) => Math.max(0, Math.min(1, rms(t0, t1) / all - 0.5)); }
  } catch {}
  const plan = planShorts(words, { min: opts.min, max: opts.max, energy });
  if (!plan.shorts.length) throw new Error('Could not find stand-alone moments of 20–60 s');
  const ws = ctx.workspace || {}, wsId = ws.id || p.workspace;
  const look = opts.look || (/[؀-ۿ]/.test(words.slice(0, 30).map((w) => w.w).join('')) ? 'v-arabic' : 'v-pop');
  const spec = lookSpec(look, ws), pop = brandPop(ws), ink = contrast(pop, '#111111') > contrast(pop, '#ffffff') ? '#111111' : '#ffffff';
  const ctas = [].concat(ws.endCard?.cta || ['Follow for more']);
  const out = [];
  for (let k = 0; k < plan.shorts.length; k++) {
    const s = plan.shorts[k];
    opts.onProgress?.(`Short ${k + 1} of ${plan.shorts.length}`, k / plan.shorts.length);
    const prevEnd = k ? 0 : 0, sIn = Math.max(item.in, s.t0 - 0.12, prevEnd), sOut = Math.min(item.out, s.t1 + 0.35), dur = sOut - sIn;
    const q = createProject({ name: `${p.name} · Short ${k + 1} · ${s.title}`.slice(0, 90), workspace: wsId, brand: p.brand, recipe: p.recipe, aspect: '9:16', fps: p.canvas.fps });
    q.media = [clone(media)];
    const W = q.canvas.w, H = q.canvas.h;
    const clip = createItem('clip', { media: media.id, t0: 0, in: r1(sIn), out: r1(sOut), ...fitBox(q.canvas, media.w || W, media.h || H, 'cover'), name: media.name });
    if (opts.faces !== false && media.w && Math.abs(media.w / media.h - W / H) > 0.05) {
      const fs = (await facesIn(ctx, media.id, sIn, sOut)).filter((f) => f.x != null);
      if (fs.length >= 2) {
        const crop = reframe(fs, { w: media.w, h: media.h }, { w: W, h: H }, { duration: sOut });
        const kf = cropToItemKf(crop.filter((c) => c.t >= sIn - 1), { w: media.w, h: media.h }, q.canvas, 0, sIn);
        if (kf.x.length) { clip.kf = kf; Object.assign(clip, { x: kf.x[0][1], y: kf.y[0][1], w: kf.w[0][1], h: kf.h[0][1] }); }
      }
    }
    // no speaker to follow in a landscape source (slides, a screen share): keep the WHOLE picture, full width in the
    // upper middle on the company's dark colour, and put the captions under it instead of cropping the slide away
    let capStyle;
    if (!clip.kf?.x && media.w > media.h * 1.2) {
      const h = Math.round(W * media.h / media.w);
      Object.assign(clip, { x: 0, y: Math.round(H * 0.27), w: W, h });
      q.canvas.bg = [ws.colors?.primary, ws.colors?.secondary, ws.colors?.ink].find((c) => c && contrast(c, '#ffffff') >= 7) || '#111111';
      const sa = safeArea(W, H), cy = (H * 0.27 + h + H * 0.1) / H;
      capStyle = { ...spec, pos: { y: +Math.min(0.95, (cy * H - sa.y) / sa.h).toFixed(3) } };
    }
    const tr = (kind) => q.tracks.find((t) => t.kind === kind);
    tr('video').items.push(clip);
    const sw = words.filter((w) => w.t0 >= sIn - 0.02 && w.t1 <= sOut + 0.02).map((w) => ({ ...w, t0: r1(w.t0 - sIn), t1: r1(w.t1 - sIn) }));
    q.captions = { style: spec, lang: p.captions.lang || 'auto', words: sw, lines: buildLines(sw, spec, q.canvas) };
    tr('caption').items.push(createItem('caption', { t0: 0, in: 0, out: r1(dur), x: 0, y: 0, w: W, h: H, name: 'Captions', style: capStyle, auto: 'content.look' }));
    // the hook for the first seconds (top of the title-safe area) and the CTA at the end
    const hk = Math.min(3, dur * 0.15);
    tr('text').items.push(createItem('text', { t0: 0, in: 0, out: r1(hk), x: W * 0.08, y: H * 0.13, w: W * 0.84, h: H * 0.14, text: s.title, style: brandText(ws, { size: 70, color: '#ffffff', stroke: '#000000', strokeW: 6 }), fx: [{ type: 'content.pop', dur: 0.3 }], fadeOut: 0.2, auto: 'content.short', name: 'Hook' }));
    const cta = ctas[k % ctas.length];
    tr('text').items.push(createItem('text', { t0: r1(Math.max(hk, dur - 3)), in: 0, out: r1(Math.min(3, dur - hk)), x: W * 0.12, y: H * 0.62, w: W * 0.76, h: H * 0.075, text: cta, style: brandText(ws, { size: 54, bg: pop, color: ink }), fx: [{ type: 'content.pop', dur: 0.3 }], auto: 'content.short', name: 'CTA' }));
    q.meta = { content: { short: { from: p.id, index: k + 1, t0: r1(sIn), t1: r1(sOut), title: s.title, score: s.score }, look } };
    if (ctx.engine?.available) await ctx.engine.saveProject(q);
    out.push({ id: q.id, name: q.name, project: q, t0: r1(sIn), t1: r1(sOut), dur: r1(dur), title: s.title, firstWord: s.firstWord, words: sw.length, cta, startsOnSentence: true, text: s.text.slice(0, 160) });
  }
  ctx.history.apply(ctx.ops.setProject({ meta: { ...p.meta, content: { ...(p.meta?.content || {}), shorts: out.map((x) => ({ id: x.id, name: x.name, t0: x.t0, t1: x.t1, title: x.title })) } } }, 'Shorts'));
  opts.onProgress?.(null);
  return { shorts: out, topics: plan.topics.length, sentences: plan.sentences };
}

/** export shorts (projects made by makeShorts) to MP4: [{id, path, bytes, frames}] */
export async function exportShorts(ctx, shorts, opts = {}) {
  const res = [];
  for (let i = 0; i < shorts.length; i++) {
    const q = normalizeProject(shorts[i].project || await ctx.engine.loadProject(ctx.project.workspace, shorts[i].id));
    const store = new MediaStore({ getProject: () => q, engine: ctx.engine });
    try {
      const r = await exportMp4(q, store, { width: opts.size === 720 ? 720 : 1080, height: opts.size === 720 ? 1280 : 1920, quality: opts.quality || 'standard', signal: opts.signal,
        onProgress: (f, txt) => opts.onProgress?.(`Short ${i + 1}/${shorts.length} · ${txt}`, (i + f) / shorts.length) });
      const base = String(ctx.project.name || 'webinar').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 30) || 'webinar';
      const path = `${exportsDir(q.workspace)}/${base}-short-${String(i + 1).padStart(2, '0')}.mp4`;
      if (ctx.engine?.available) await ctx.engine.export(path, r.blob);
      res.push({ id: q.id, path, bytes: r.blob.size, frames: r.frames, note: r.note });
    } finally { for (const m of q.media) store.release(m.id); }
  }
  return res;
}
export { uid, findMedia };
