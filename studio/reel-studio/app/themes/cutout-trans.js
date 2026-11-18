// KBB Studio · themes · CUT-OUT TRANSITIONS: switches on a cut that use the PERSON in the clips (the owner, 18 Nov:
// "also we need more cut outs transitions").
//
//   cut-bgswap      the person stays while the background swaps from clip A to clip B behind them
//   cut-popout      the person pops out: scales up out of the frame while the background changes
//   cut-silin       silhouette wipe: the next clip shows INSIDE the person's shape, which grows to fill the frame
//   cut-silout      silhouette iris: the next clip closes in from the edges OUTSIDE a person-shaped hole
//   cut-flash       colour-flash outline: a glowing outline strobes round the person, a white flash on the cut
//   cut-freeze      cut-out freeze: the background drains to grey round the person, who then slides off as a sticker
//   cut-textbehind  title text behind the person, in front of the background, carried across the cut
//
// The person comes from the look module's own analysis (look/vision.js: the person segmenter at 15 fps, or the v2 mask
// of a clip whose background was removed), which runs offline for both clips of every cut-out transition — the export
// waits for it (registerPrepare 'look'), the editor runs it after the edit with progress. A mask is placed on the frame
// exactly like its clip (core/render.js drawItem with the mask as the source: crop, cover fit, Ken Burns, box), so the
// cut-out sits on the picture. A clip with no person (or before the analysis has answered) falls back to a plain soft
// wipe, and the inspector says so. Pure function of the project and t: preview = export.
import { drawItem } from '../core/render.js';
import { findItem, itemEnd, srcTime } from '../core/project.js';
import { segAt, maskAt, hasSeg, hasMask } from '../look/vision.js';
import { tx } from './text.js';
import * as A from './art.js';

const { clamp, easeIO, easeOut, easeIn, back, TAU, rgba } = A;

export const CUT_TRANSITIONS = [
  { id: 'cut-bgswap', label: 'Person stays, background swaps', icon: '🧍', dur: 1.0, note: 'You stay; the next clip’s background wipes in behind you', cut: true },
  { id: 'cut-popout', label: 'Person pop-out', icon: '🫧', dur: 1.0, note: 'You scale up out of the frame as the background changes', cut: true },
  { id: 'cut-silin', label: 'Silhouette wipe (inside)', icon: '👤', dur: 1.0, note: 'The next clip shows inside your shape, then fills the frame', cut: true },
  { id: 'cut-silout', label: 'Silhouette iris (outside)', icon: '⭕', dur: 1.0, note: 'The next clip closes in round your silhouette', cut: true },
  { id: 'cut-flash', label: 'Colour-flash outline', icon: '✨', dur: 0.8, note: 'A glowing outline strobes round you, a flash on the cut', cut: true },
  { id: 'cut-freeze', label: 'Cut-out freeze + slide', icon: '🧊', dur: 1.2, note: 'The world freezes grey, you slide off as a sticker', cut: true },
  { id: 'cut-textbehind', label: 'Text behind person', icon: '🔤', dur: 1.4, note: 'Your title sits behind you, in front of the background', cut: true },
];
export const isCutKind = (k) => /^cut-/.test(k || '');
/** the words a cut-out transition draws (the text-behind title), by key with defaults */
export const CUT_WORDS = { 'cut-textbehind': (K) => ({ title: (K.name && K.name.length < 16 ? K.name : 'NEXT UP').toUpperCase(), kicker: 'COMING UP' }) };

// ── offscreen canvases (device px), reused ──
const POOL = new Map();
function buf(name, w, h) {
  let c = POOL.get(name); if (!c) { c = document.createElement('canvas'); POOL.set(name, c); }
  if (c.width !== w || c.height !== h) { c.width = w; c.height = h; }
  const g = c.getContext('2d'); g.setTransform(1, 0, 0, 1, 0, 0); g.globalAlpha = 1; g.globalCompositeOperation = 'source-over'; g.filter = 'none'; g.shadowBlur = 0; g.shadowColor = 'rgba(0,0,0,0)'; g.clearRect(0, 0, w, h);
  return [c, g];
}

// ── the person in a clip at a time ──
const MCV = new Map();    // mask key -> canvas (white, alpha = person)
const STATS = new Map();  // mask key -> { cover, cx, cy } (source-normalised)
function maskCanvas(m) {
  let cv = MCV.get(m.key); if (cv) return cv;
  cv = document.createElement('canvas'); cv.width = m.w; cv.height = m.h;
  const g = cv.getContext('2d'), id = g.createImageData(m.w, m.h);
  let s = 0, sx = 0, sy = 0;
  for (let i = 0, n = m.w * m.h; i < n; i++) { const v = m.data[i]; id.data[i * 4] = id.data[i * 4 + 1] = id.data[i * 4 + 2] = 255; id.data[i * 4 + 3] = v; if (v > 127) { s++; sx += i % m.w; sy += (i / m.w) | 0; } }
  g.putImageData(id, 0, 0);
  STATS.set(m.key, { cover: s / (m.w * m.h), cx: s ? sx / s / m.w : 0.5, cy: s ? sy / s / m.h : 0.6 });
  MCV.set(m.key, cv); if (MCV.size > 16) { const k = MCV.keys().next().value; MCV.delete(k); STATS.delete(k); }
  return cv;
}
// the hover previews' drawn presenter (themes/hover.js sampleFrame): the same body, neck and head as a mask
const SAMPLE = new Map();
function sampleMask(t, variant, w, h) {
  const key = variant + ':' + w + 'x' + h + ':' + t.toFixed(3);
  let cv = SAMPLE.get(key);
  if (!cv) {
    cv = document.createElement('canvas'); cv.width = w; cv.height = h; const x = cv.getContext('2d');
    const k = Math.min(w / 216, h / 384), sway = Math.sin(t * 1.6) * 5 * k, bob = Math.sin(t * 3) * 1.5 * k;
    x.translate((w - 216 * k) / 2, h - 384 * k); const hh = 384 * k, cx = 108 * k + sway; x.fillStyle = '#ffffff';
    x.beginPath(); x.moveTo(cx - 80 * k, hh); x.bezierCurveTo(cx - 80 * k, 270 * k, cx - 60 * k, 250 * k, cx - 28 * k, 248 * k); x.lineTo(cx + 28 * k, 248 * k); x.bezierCurveTo(cx + 60 * k, 250 * k, cx + 80 * k, 270 * k, cx + 80 * k, hh); x.fill();
    x.fillRect(cx - 13 * k, 205 * k + bob, 26 * k, 48 * k);
    x.beginPath(); x.ellipse(cx, 160 * k + bob, 42 * k, 52 * k, 0, 0, 7); x.fill();
    SAMPLE.set(key, cv); if (SAMPLE.size > 24) SAMPLE.delete(SAMPLE.keys().next().value);
    STATS.set('s:' + key, { cover: 0.22, cx: 0.5 + sway / w, cy: 0.72 });
  }
  return { cv, st: STATS.get('s:' + key) };
}
const sampleDims = (W, H) => (W >= H ? [Math.round(216 * W / H), 216] : [216, Math.round(216 * H / W)]);

/** the person mask of `clip` at timeline time tc: { cv (source-shaped), st:{cover,cx,cy} } | null; state: 'ok'|'none'|'pending' */
export function personOf(project, clip, tc, tSample) {
  if (!clip) return { m: null, state: 'none' };
  if (!clip.media) {
    if (!/^hp-clip-/.test(clip.id || '')) return { m: null, state: 'none' };
    const [sw, sh] = sampleDims(project.canvas.w, project.canvas.h);
    return { m: sampleMask(tSample ?? tc, clip.variant || 0, sw, sh), state: 'ok' };
  }
  const s = srcTime(clip, tc), v2 = (clip.fx || []).some((f) => f.type === 'look.bg' && (f.q || 1) >= 2 && f.key !== 'chroma' && f.key !== 'subject');
  const m = (v2 && maskAt(clip.media, s, 'person', 0.5)) || segAt(clip.media, s) || maskAt(clip.media, s, 'person', 0.5) || maskAt(clip.media, s, 'subject', 0.5);
  if (!m) return { m: null, state: hasSeg(clip.media) || hasMask(clip.media, 'person') ? 'none' : 'pending' };
  const cv = maskCanvas(m), st = STATS.get(m.key);
  return { m: { cv, st }, state: st.cover > 0.004 ? 'ok' : 'none' };
}
/** the mask placed on the frame like its clip, into a device-px buffer */
function placed(name, project, clip, tc, P, env, CW, CH) {
  const [cv, g] = buf(name, CW, CH);
  g.setTransform(env.scale, 0, 0, env.scale, 0, 0);
  const track = project.tracks.find((tr) => tr.items.includes(clip));
  drawItem(g, { ...clip, fx: [], look: null }, tc, project, Object.assign({}, env, { track, preview: false, source: () => P.cv }));
  return cv;
}
/** the centroid of a clip's person in canvas units (cover fit of the clip's box, crop and zoom ignored) */
function centroid(project, clip, st) {
  const W = project.canvas.w, H = project.canvas.h, bx = clip.x ?? 0, by = clip.y ?? 0, bw = clip.w || W, bh = clip.h || H;
  return [bx + st.cx * bw, by + st.cy * bh];
}

// ── compositing helpers (device px, identity transform on ctx) ──
/** src (device) with only the inside (keep 'in') or the outside ('out') of mask m, into a named buffer */
function masked(name, src, m, keep, CW, CH, mt = null) {
  const [cv, g] = buf(name, CW, CH);
  g.drawImage(src, 0, 0, CW, CH);
  g.globalCompositeOperation = keep === 'in' ? 'destination-in' : 'destination-out';
  if (mt) g.setTransform(...mt);
  g.drawImage(m, 0, 0, CW, CH);
  return cv;
}
/** a scale of s about (x, y) (device px) as a setTransform list */
const about = (x, y, s) => [s, 0, 0, s, x - x * s, y - y * s];
/** a glowing ring round the mask: the mask grown by r px in colour col, minus the mask itself */
function outline(name, m, col, r, CW, CH) {
  const [cv, g] = buf(name, CW, CH);
  const n = 12; for (let i = 0; i < n; i++) { const a = (i / n) * TAU; g.drawImage(m, Math.cos(a) * r, Math.sin(a) * r, CW, CH); }
  g.globalCompositeOperation = 'source-in'; g.fillStyle = col; g.fillRect(0, 0, CW, CH);
  g.globalCompositeOperation = 'destination-out'; g.drawImage(m, 0, 0, CW, CH);
  return cv;
}

/**
 * Draw a cut-out transition. sides = look/transitions.js transitionSides (p, before, other); K = the theme kit; S = the
 * text slots ({txt}). Returns 'fallback' when it drew the plain wipe (no person found), else true.
 */
export function drawCutTrans(ctx, item, t, project, env, sides, K, S) {
  const kind = item.style?.kind, W = project.canvas.w, H = project.canvas.h, cv = ctx.canvas, CW = cv.width, CH = cv.height, k = env.scale || CW / W;
  const Ai = findItem(project, item.style?.a)?.item, Bi = findItem(project, item.style?.b)?.item; if (!Ai || !Bi) return false;
  const { p, before, other } = sides, tA = itemEnd(Ai) - 1e-4, tB = Bi.t0 + 1e-4;
  const live = before ? Ai : Bi, oth = before ? Bi : Ai, tl = t, to = before ? tB : tA;
  // the live frame as it is now (everything under this layer)
  const [snap, sg] = buf('snap', CW, CH); sg.drawImage(cv, 0, 0);
  const pl = personOf(project, live, tl, t), po = personOf(project, oth, to, t);
  const pa = before ? pl : po, pb = before ? po : pl;   // A's person, B's person (on their own side of the cut)
  const acc = K?.colors?.accent && K.colors.accent !== '#ffffff' ? K.colors.accent : '#ffd166';
  ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalCompositeOperation = 'source-over';
  const put = (src, a = 1, mt = null) => { if (a <= 0.002) return; ctx.save(); ctx.globalAlpha *= clamp(a); if (mt) ctx.setTransform(...mt); ctx.drawImage(src, 0, 0, CW, CH); ctx.restore(); };
  const finish = (r) => { ctx.restore(); return r; };
  const needA = kind !== 'cut-flash' && kind !== 'cut-textbehind';
  if ((needA && pa.state !== 'ok') || (!needA && pa.state !== 'ok' && pb.state !== 'ok')) {
    // no person: a plain soft wipe left to right, the cut in the middle
    const X = CW * easeIO(p), [wc, wg] = buf('wipe', CW, CH);
    wg.drawImage(other, 0, 0);
    wg.globalCompositeOperation = 'destination-in';
    const gr = wg.createLinearGradient(X - CW * 0.06, 0, X + CW * 0.06, 0);
    if (before) { gr.addColorStop(0, '#000'); gr.addColorStop(1, 'rgba(0,0,0,0)'); } else { gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(1, '#000'); }
    wg.fillStyle = gr; wg.fillRect(0, 0, CW, CH);
    put(wc);
    if (kind === 'cut-textbehind') { ctx.restore(); ctx.save(); textBehind(ctx, S, K, p, W, H); }
    return finish('fallback');
  }
  // A's person on the frame (device), and where it is
  const mA = pa.state === 'ok' ? placed('mA', project, Ai, before ? tl : tA, pa.m, env, CW, CH) : null;
  const srcA = before ? snap : other;
  const [ax, ay] = pa.state === 'ok' ? centroid(project, Ai, pa.m.st).map((v) => v * k) : [CW / 2, CH * 0.6];
  const personA = () => masked('pA', srcA, mA, 'in', CW, CH);

  if (kind === 'cut-bgswap') {
    if (before) {
      // B's picture, minus where the person stands, wiped in from the left; the person (the live frame) stays on top
      const X = CW * easeIO(p / 0.5) * 1.12, bg = masked('bg', other, mA, 'out', CW, CH), [wc, wg] = buf('wipe', CW, CH);
      wg.drawImage(bg, 0, 0); wg.globalCompositeOperation = 'destination-in';
      const gr = wg.createLinearGradient(X - CW * 0.1, 0, X, 0); gr.addColorStop(0, '#000'); gr.addColorStop(1, 'rgba(0,0,0,0)'); wg.fillStyle = gr; wg.fillRect(0, 0, CW, CH);
      put(wc);
    } else put(personA(), p < 0.72 ? 1 : 1 - easeIn((p - 0.72) / 0.28));
    return finish(true);
  }
  if (kind === 'cut-popout') {
    const s = 1 + 1.3 * easeIn(p) ** 1.2, mt = about(ax, ay, s);
    if (before) put(other, easeIO(p / 0.5));
    ctx.save(); ctx.shadowColor = 'rgba(0,0,0,0.45)'; ctx.shadowBlur = CW * 0.03; ctx.shadowOffsetY = CW * 0.01;
    put(personA(), before ? 1 : 1 - easeIn((p - 0.5) / 0.5), mt); ctx.restore();
    return finish(true);
  }
  if (kind === 'cut-silin') {
    if (before) {
      // the next clip inside the person's shape (fading in), then the shape grows until it holds the frame
      const g = clamp((p - 0.18) / 0.32), s = 1 + 9 * easeIn(g) ** 1.5;
      const win = masked('win', other, mA, 'in', CW, CH, about(ax, ay, s));
      put(win, clamp(p / 0.15));
      const ring = outline('ring', mA, '#ffffff', CW * 0.006, CW, CH);
      put(ring, clamp(p / 0.1) * (1 - g), about(ax, ay, s));
      if (p > 0.42) put(other, (p - 0.42) / 0.08);
    }
    return finish(true);
  }
  if (kind === 'cut-silout') {
    if (before) {
      // a person-shaped hole closes from huge to the person: B outside it, A inside it
      const s = 1 + 8 * (1 - easeOut(p / 0.5)), outside = masked('out', other, mA, 'out', CW, CH, about(ax, ay, s));
      put(outside);
      put(outline('ring', mA, acc, CW * 0.008, CW, CH), clamp((p - 0.3) / 0.2), about(ax, ay, s));
    } else {
      const f = (p - 0.5) / 0.5;
      put(outline('ring', mA, acc, CW * 0.008, CW, CH), 1 - easeOut(f * 1.6));
      put(personA(), 1 - easeIn(clamp((f - 0.25) / 0.75)));
    }
    return finish(true);
  }
  if (kind === 'cut-flash') {
    const mine = before ? pa : pb, own = mine.state === 'ok' ? placed('mF', project, before ? Ai : Bi, tl, mine.m, env, CW, CH) : null;
    if (own) {
      const strobe = before ? 0.35 + 0.65 * Math.abs(Math.sin(p * Math.PI * 6)) : 1 - easeOut((p - 0.5) / 0.5);
      const ring = outline('ring', own, acc, CW * 0.012, CW, CH);
      ctx.save(); ctx.shadowColor = acc; ctx.shadowBlur = CW * 0.03; put(ring, strobe); ctx.restore();
      put(outline('ring2', own, '#ffffff', CW * 0.004, CW, CH), strobe);
    }
    const fl = clamp(1 - Math.abs(p - 0.5) / 0.1);
    if (fl > 0) { ctx.save(); ctx.globalAlpha *= fl * 0.92; ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, CW, CH); ctx.restore(); }
    return finish(true);
  }
  if (kind === 'cut-freeze') {
    if (before) {
      // the background drains to grey and dims round the person; a white sticker edge pops round them
      const g = easeIO(p / 0.5), [gc, gg] = buf('grey', CW, CH);
      gg.filter = `grayscale(1) brightness(${1 - 0.35 * g})`; gg.drawImage(snap, 0, 0); gg.filter = 'none';
      gg.globalCompositeOperation = 'destination-out'; gg.drawImage(mA, 0, 0, CW, CH);
      put(gc, g);
      put(outline('ring', mA, '#ffffff', CW * 0.01, CW, CH), clamp((p - 0.15) / 0.2));
    } else {
      // the sticker (A's frozen person) slides off to the right with a turn, over the next clip
      const f = clamp((p - 0.56) / 0.44), x = CW * 1.1 * easeIn(f), rot = 0.25 * easeIn(f), s = 1 - 0.12 * f;
      const c = Math.cos(rot) * s, sn = Math.sin(rot) * s, mt = [c, sn, -sn, c, ax - c * ax + sn * ay + x, ay - sn * ax - c * ay];
      ctx.save(); ctx.shadowColor = 'rgba(0,0,0,0.45)'; ctx.shadowBlur = CW * 0.025; ctx.shadowOffsetY = CW * 0.01;
      put(outline('ring', mA, '#ffffff', CW * 0.01, CW, CH), 1, mt); ctx.restore();
      put(personA(), 1, mt);
    }
    return finish(true);
  }
  if (kind === 'cut-textbehind') {
    // the title between the background and the person: drawn over the frame, then the person laid back on top
    if (before && p > 0.4) put(other, (p - 0.4) / 0.1);
    ctx.restore(); ctx.save();
    textBehind(ctx, S, K, p, W, H);
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    const mine = before ? pa : pb, who = before ? Ai : Bi;
    if (mine.state === 'ok') {
      const m = placed('mT', project, who, tl, mine.m, env, CW, CH);
      // before the cut fades into B: the person comes from the same mix (A's live, or B's first frame once it shows)
      put(masked('pT', snap, m, 'in', CW, CH), before && p > 0.4 ? 1 - (p - 0.4) / 0.1 : 1);
    }
    return finish(true);
  }
  return finish(false);
}

/** the title text of the text-behind transition, in canvas units (the layer's own transform) */
function textBehind(ctx, S, K, p, W, H) {
  const u = Math.min(W, H), D = CUT_WORDS['cut-textbehind'](K || { name: '' });
  const inn = easeOut(p / 0.3), out = 1 - easeIn((p - 0.78) / 0.22), a = Math.min(inn, out);
  if (a <= 0.002) return;
  ctx.save(); ctx.globalAlpha *= a; ctx.translate(0, (1 - inn) * u * 0.08 - (1 - out) * u * 0.1);
  const title = tx(S, 'title', D.title), size = u * (title.length > 9 ? 0.2 : 0.27);
  A.kineticText(ctx, title, W / 2, H * 0.36, size, clamp(p / 0.35), { col: '#ffffff', align: 'center', maxW: W * 0.94, shadow: true });
  A.uiText(ctx, tx(S, 'kicker', D.kicker), W / 2, H * 0.36 - size * 1.05, u * 0.04, { col: rgba('#ffffff', 0.9), align: 'center', weight: 800, track: 0.3, maxW: W * 0.9 });
  ctx.restore();
}

/** what a cut-out transition will do on its cut: { a, b, fallback, pending, msg } (the inspector's line) */
export function cutStatus(project, item) {
  const A2 = findItem(project, item.style?.a)?.item, B2 = findItem(project, item.style?.b)?.item;
  if (!A2 || !B2) return { fallback: true, msg: 'Its clips are gone: put it back on a cut.' };
  const a = personOf(project, A2, itemEnd(A2) - 0.05), b = personOf(project, B2, B2.t0 + 0.05), kind = item.style?.kind;
  const pending = a.state === 'pending' || b.state === 'pending';
  const either = kind === 'cut-flash' || kind === 'cut-textbehind', need = either ? a.state === 'ok' || b.state === 'ok' : a.state === 'ok';
  const msg = pending ? 'Finding the person in both clips… (it runs once, offline)' : !need ? `No person found in ${either ? 'either clip' : 'the clip before the cut'}: this plays as a plain wipe instead${kind === 'cut-textbehind' ? ', with the title on top' : ''}.` : `Person found${a.state === 'ok' ? ' before' : ''}${a.state === 'ok' && b.state === 'ok' ? ' and' : ''}${b.state === 'ok' ? ' after' : ''} the cut.`;
  return { a: a.state, b: b.state, pending, fallback: !pending && !need, msg };
}
export { back };
