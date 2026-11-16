// KBB Studio · hover previews, for every gallery: themes, templates (recipes), Create → formats, colour looks,
// transitions, music and sound effects. Hover a tile (or focus it with the keyboard, or long-press it on a touch screen)
// and a 2–4 s loop plays; leave and it stops.
//
//   hoverPreview(el, spec)   spec = { id, dur, aspect, canvas?, draw(c2d, w, h, t), prepare?(), onStop?() }   (visual)
//                                 | { id, kind:'audio', src, offset, dur }                                       (audio)
//
// Cheap by construction: ONE preview plays at a time (starting one stops the last), nothing renders until a hover
// lasts 60 ms (sweeping the mouse across a grid starts nothing), every visual is drawn on the fly into one small
// canvas — the tile's own, or a shared pop-over — from a built-in sample clip (or, with the toggle, frames of the
// user's own clip), it stops when the tile scrolls out of view or the tab hides, and audio uses one shared element
// whose file is only requested when a hover actually starts. window.__kbbHover reports latency and frame time.
import { renderFrame } from '../core/render.js';
import { createItem } from '../core/project.js';
import { themeById } from './themes.js';
import { themeStyle, TAG } from './apply.js';
import { loadAssets } from './draw.js';

const store = { get(k, d) { try { const v = localStorage.getItem(k); return v == null ? d : v === '1'; } catch { return d; } }, set(k, v) { try { localStorage.setItem(k, v ? '1' : '0'); } catch {} } };
export const prefs = { get sound() { return store.get('kbbstudio.hover.sound', false); }, set sound(v) { store.set('kbbstudio.hover.sound', v); }, get mine() { return store.get('kbbstudio.hover.mine', false); }, set mine(v) { store.set('kbbstudio.hover.mine', v); } };

const STATS = { active: null, starts: [], frames: 0, frameMs: 0, maxFrameMs: 0, stops: 0 };
if (typeof window !== 'undefined') window.__kbbHover = STATS;
let cur = null;
let io = null;
const now = () => performance.now();

function observer() {
  if (io || typeof IntersectionObserver !== 'function') return io;
  io = new IntersectionObserver((ents) => { for (const e of ents) if (!e.isIntersecting && cur && cur.el === e.target) stop(); });
  return io;
}
if (typeof document !== 'undefined') document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });

/** wire an element: hover (60 ms), keyboard focus, long-press on touch */
export function hoverPreview(el, spec) {
  if (!el || el.__hp) return el;
  el.__hp = spec; el.dataset.hp = spec.id || '1';
  let timer = 0, enterAt = 0;
  const go = () => { clearTimeout(timer); start(el, el.__hp, enterAt || now()); };
  el.addEventListener('pointerenter', (e) => { if (e.pointerType === 'touch') return; enterAt = now(); clearTimeout(timer); timer = setTimeout(go, 60); });
  el.addEventListener('pointerleave', () => { clearTimeout(timer); if (cur?.el === el) stop(); });
  el.addEventListener('focusin', () => { enterAt = now(); go(); });
  el.addEventListener('focusout', () => { if (cur?.el === el) stop(); });
  el.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'touch') return; enterAt = now(); clearTimeout(timer); timer = setTimeout(go, 380); });
  for (const ev of ['pointerup', 'pointercancel']) el.addEventListener(ev, (e) => { if (e.pointerType !== 'touch') return; clearTimeout(timer); if (cur?.el === el) stop(); });
  return el;
}
/** replace what an element previews (e.g. a select changed) */
export function setPreview(el, spec) { el.__hp = spec; el.dataset.hp = spec.id || '1'; if (cur?.el === el) start(el, spec, now()); }

export function stop() {
  const c = cur; if (!c) return;
  cur = null; STATS.active = null; STATS.stops++;
  cancelAnimationFrame(c.raf); clearTimeout(c.end);
  io?.unobserve(c.el);
  c.el.classList.remove('hp-on');
  if (c.audio) { c.audio.pause(); }
  if (c.pop) c.pop.hidden = true;
  try { c.spec.onStop?.(); } catch {}
}

function start(el, spec, enterAt) {
  if (cur?.el === el && cur.spec === spec) return;
  stop();
  if (!el.isConnected) return;
  const c = cur = { el, spec, t0: now(), enterAt, raf: 0, frames: 0, ms: 0 };
  STATS.active = spec.id || 'preview';
  el.classList.add('hp-on');
  observer()?.observe(el);
  const rec = { id: spec.id, latency: 0, kind: spec.kind || 'visual' };
  STATS.starts.push(rec); if (STATS.starts.length > 50) STATS.starts.shift();
  if (spec.kind === 'audio') return startAudio(c, rec);
  let canvas = spec.canvas;
  if (!canvas) { c.pop = popover(el, spec.aspect || '9:16', spec.label); canvas = c.pop.canvas; }
  const g = canvas.getContext('2d');
  try { spec.prepare?.(); } catch {}
  const dur = spec.dur || 3;
  const frame = () => {
    if (cur !== c) return;
    if (!el.isConnected) { stop(); return; }
    const a = now(), t = ((a - c.t0) / 1000) % dur;
    try { spec.draw(g, canvas.width, canvas.height, t); } catch (e) { console.warn('[hover]', spec.id, e); stop(); return; }
    const ms = now() - a; c.frames++; STATS.frames++;
    STATS.frameMs = STATS.frameMs ? STATS.frameMs * 0.9 + ms * 0.1 : ms; STATS.maxFrameMs = Math.max(STATS.maxFrameMs, ms);
    if (c.frames === 1) rec.latency = +(now() - c.enterAt).toFixed(1);
    c.raf = requestAnimationFrame(frame);
  };
  frame();   // the first frame now, not on the next vsync
}

let AUDIO = null;
function startAudio(c, rec) {
  const a = AUDIO ||= Object.assign(new Audio(), { preload: 'none' });
  const url = '/' + String(c.spec.src).split('/').map(encodeURIComponent).join('/');
  a.pause(); if (a.dataset.src !== url) { a.src = url; a.dataset.src = url; a.load(); }   // the file is fetched only now, on a real hover
  a.muted = !prefs.sound; a.volume = 0;
  rec.latency = null;
  a.addEventListener('playing', () => { if (cur === c && rec.latency == null) rec.latency = +(now() - c.enterAt).toFixed(1); }, { once: true });
  const go = () => { try { a.currentTime = c.spec.offset || 0; } catch {} a.play().catch(() => {}); };
  if (a.readyState >= 1) go(); else a.addEventListener('loadedmetadata', go, { once: true });
  c.audio = a;
  const dur = c.spec.dur || 4;
  const fade = () => { if (cur !== c) return; const e = (now() - c.t0) / 1000; a.volume = Math.max(0, Math.min(1, e / 0.25, (dur - e) / 0.5)) * 0.8; c.raf = requestAnimationFrame(fade); };
  fade(); c.end = setTimeout(() => { if (cur === c) stop(); }, dur * 1000);
}

// ── the shared pop-over for list rows ──
let POP = null;
function popover(el, aspect, label) {
  if (!POP) {
    POP = document.createElement('div'); POP.className = 'hp-pop'; POP.hidden = true;
    POP.canvas = document.createElement('canvas'); POP.label = document.createElement('div'); POP.label.className = 'hp-pop-l';
    POP.append(POP.canvas, POP.label); document.body.appendChild(POP); css();
  }
  const [cw, ch] = aspect === '16:9' ? [256, 144] : aspect === '1:1' ? [200, 200] : aspect === '4:5' ? [192, 240] : [144, 256];
  if (POP.canvas.width !== cw || POP.canvas.height !== ch) { POP.canvas.width = cw; POP.canvas.height = ch; }
  POP.canvas.style.width = cw + 'px'; POP.canvas.style.height = ch + 'px';
  POP.label.textContent = label || '';
  const r = el.getBoundingClientRect(), vw = innerWidth, vh = innerHeight, gap = 10, w = cw + 12, h = ch + (label ? 30 : 12);
  const left = r.right + gap + w < vw ? r.right + gap : Math.max(4, r.left - gap - w);
  POP.style.left = left + 'px'; POP.style.top = Math.max(4, Math.min(vh - h - 4, r.top + r.height / 2 - h / 2)) + 'px';
  POP.hidden = false; return POP;
}
let CSS = false;
export function css() {
  if (CSS || typeof document === 'undefined') return; CSS = true;
  const s = document.createElement('style'); s.textContent = `
.hp-pop { position: fixed; z-index: 9000; background: #0d0d12; border: 1px solid rgba(255,255,255,.16); border-radius: 12px; padding: 6px; box-shadow: 0 14px 40px rgba(0,0,0,.55); pointer-events: none; }
.hp-pop canvas { display: block; border-radius: 8px; background: #000; }
.hp-pop-l { font-size: 11px; color: #ddd; text-align: center; padding: 5px 2px 0; max-width: 256px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hp-on { outline: 2px solid var(--ws, #e8508b); outline-offset: -2px; }
.lib-row.hp-on .lname::after { content: ' ▮▮▮'; font-size: 9px; letter-spacing: 1px; color: var(--ws, #e8508b); animation: hp-eq .6s steps(3) infinite; }
@keyframes hp-eq { 0% { opacity: .3 } 50% { opacity: 1 } 100% { opacity: .5 } }
.hp-toggles { display: flex; gap: 6px; flex-wrap: wrap; margin: 2px 0 6px; }
.hp-toggle { font-size: 11px; border: 1px solid rgba(255,255,255,.18); background: transparent; color: inherit; border-radius: 999px; padding: 3px 9px; cursor: pointer; }
.hp-toggle.on { background: var(--ws, #e8508b); border-color: transparent; color: #fff; }`;
  document.head.appendChild(s);
}

/** the two switches every gallery shows: hover sound (off by default) and "preview with my clip" */
export function hoverToggles({ sound = false, mine = true } = {}) {
  css();
  const box = document.createElement('div'); box.className = 'hp-toggles';
  const mk = (key, on, off, title) => {
    const b = document.createElement('button'); b.type = 'button'; b.className = 'hp-toggle'; b.title = title; b.dataset.hpToggle = key;
    const paint = () => { b.classList.toggle('on', prefs[key]); b.textContent = prefs[key] ? on : off; };
    b.addEventListener('click', () => { prefs[key] = !prefs[key]; paint(); if (key === 'sound' && cur?.audio) cur.audio.muted = !prefs.sound; });
    paint(); return b;
  };
  if (sound) box.appendChild(mk('sound', '🔊 Hover sound on', '🔇 Hover sound off', 'Hovering a row plays a few seconds of it. Off by default.'));
  if (mine) box.appendChild(mk('mine', '🎞 Preview on my clip', '🎞 Preview on the sample clip', 'Hover previews use a built-in sample clip, or frames of the video in your reel.'));
  return box;
}

// ── the sample clip (drawn, no file) and the user's own frames ──
const SAMPLES = new Map();
/** sample "clip" frame at t: a person talking in a warm room (variant 0) or a cool street (variant 1) */
export function sampleFrame(t, variant = 0, w = 216, h = 384) {
  const key = variant + ':' + w + 'x' + h;
  let c = SAMPLES.get(key); if (!c) { c = document.createElement('canvas'); c.width = w; c.height = h; SAMPLES.set(key, c); }
  const user = prefs.mine ? userFrame(t, variant) : null;
  const x = c.getContext('2d');
  if (user) { const k = Math.max(w / user.width, h / user.height); x.drawImage(user, (w - user.width * k) / 2, (h - user.height * k) / 2, user.width * k, user.height * k); return c; }
  const k = w / 216, sway = Math.sin(t * 1.6) * 5 * k, bob = Math.sin(t * 3) * 1.5 * k;
  const g = x.createLinearGradient(0, 0, w, h);
  if (variant) { g.addColorStop(0, '#5a7fa8'); g.addColorStop(0.6, '#33465f'); g.addColorStop(1, '#1d2433'); } else { g.addColorStop(0, '#e8cfb2'); g.addColorStop(0.55, '#c99a78'); g.addColorStop(1, '#6b7d80'); }
  x.fillStyle = g; x.fillRect(0, 0, w, h);
  for (let i = 0; i < 9; i++) { x.fillStyle = variant ? `hsla(${200 + i * 12},60%,70%,0.25)` : `hsla(${30 + i * 19},70%,75%,0.3)`; x.beginPath(); x.arc(((i * 53 + t * 6 * (i % 3 + 1)) % 236) * k - 10 * k, ((i * 97) % 220) * k, (10 + (i % 4) * 7) * k, 0, 7); x.fill(); }
  const cx = 108 * k + sway;
  x.fillStyle = variant ? '#2e2a3b' : '#7a3b52'; x.beginPath(); x.moveTo(cx - 80 * k, h); x.bezierCurveTo(cx - 80 * k, 270 * k, cx - 60 * k, 250 * k, cx - 28 * k, 248 * k); x.lineTo(cx + 28 * k, 248 * k); x.bezierCurveTo(cx + 60 * k, 250 * k, cx + 80 * k, 270 * k, cx + 80 * k, h); x.fill();
  x.fillStyle = '#d9a07e'; x.fillRect(cx - 13 * k, 205 * k + bob, 26 * k, 48 * k);
  x.fillStyle = '#2b1a12'; x.beginPath(); x.ellipse(cx, 160 * k + bob, 42 * k, 52 * k, 0, 0, 7); x.fill();
  x.fillStyle = '#e2ad8c'; x.beginPath(); x.ellipse(cx, 172 * k + bob, 35 * k, 44 * k, 0, 0, 7); x.fill();
  x.fillStyle = '#2b1a12'; for (const s of [-1, 1]) { x.beginPath(); x.arc(cx + s * 13 * k, 168 * k + bob, 3 * k, 0, 7); x.fill(); }
  x.fillStyle = '#b2484f'; x.beginPath(); x.ellipse(cx, 196 * k + bob, 9 * k, (2 + 3 * Math.abs(Math.sin(t * 9))) * k, 0, 0, 7); x.fill();
  if (!variant) { x.fillStyle = '#f6e1ea'; x.fillRect(150 * k, 300 * k, 26 * k, 62 * k); x.fillStyle = '#C6395F'; x.fillRect(150 * k, 290 * k, 26 * k, 12 * k); }
  return c;
}
/** where the sample person's face is (canvas-normalised, for the theme previews' face masks) */
export const SAMPLE_FACE = { x: 0.5, y: 0.44, w: 0.36, h: 0.27 };

const USER = { key: null, frames: [], busy: false };
function userFrame(t, variant) {
  const ed = typeof window !== 'undefined' ? window.kbbEditor : null;
  const clip = ed?.project.tracks.filter((tr) => tr.kind === 'video').flatMap((tr) => tr.items).find((i) => i.type === 'clip' && i.media && ed.project.media.find((m) => m.id === i.media && m.kind === 'video'));
  if (!clip) return null;
  const key = clip.media + ':' + clip.in + ':' + clip.out;
  if (USER.key !== key && !USER.busy) {
    USER.busy = true; USER.key = key; USER.frames = [];
    (async () => { try { const n = 10, d = clip.out - clip.in; for (let i = 0; i < n; i++) { const bm = await ed.media.frameAt(clip.media, clip.in + (d * (i + 0.5)) / n, 256); if (bm && USER.key === key) USER.frames.push(bm); } } catch {} finally { USER.busy = false; } })();
  }
  if (!USER.frames.length) return null;
  const i = Math.floor(t * 3 + variant * 5) % USER.frames.length;
  return USER.frames[i];
}

// ── mini timelines through the real renderer (preview = export) ──
const ASPECTS = { '9:16': [1080, 1920], '1:1': [1080, 1080], '4:5': [1080, 1350], '16:9': [1920, 1080] };
export function miniProject(aspect = '9:16') {
  const [w, h] = ASPECTS[aspect] || ASPECTS['9:16'];
  return { canvas: { w, h, fps: 30, bg: '#000000', aspect }, media: [], tracks: ['video', 'overlay', 'text', 'caption'].map((k) => ({ id: 'hp-' + k, kind: k, items: [] })), captions: { style: null, lang: 'en', words: [], lines: [] }, markers: [], meta: {} };
}
const clipItem = (p, t0, out, variant = 0) => createItem('clip', { id: 'hp-clip-' + variant + '-' + t0, t0, in: 0, out, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, variant });
export function drawMini(g, w, h, p, t) {
  const src = (item) => (item.type === 'clip' ? sampleFrame(t, item.variant || 0) : null);
  g.save(); renderFrame(g, p, t, { scale: w / p.canvas.w, preview: false, source: src }); g.restore();
}

/** hover spec for a theme tile: the sample clip, the theme's sting, then the overlay with a 120 bpm beat */
export function themeSpec(id, { canvas, aspect = '9:16', opts = {}, ws = null, onStop } = {}) {
  const th = themeById(id); if (!th) return null;
  const p = miniProject(aspect), dur = 3.6, W = p.canvas.w, H = p.canvas.h;
  const style = themeStyle(th, { intensity: 'normal', ...opts, beats: Array.from({ length: 16 }, (_, i) => +(0.25 + i * 0.5).toFixed(2)), faces: [SAMPLE_FACE] }, ws);
  p.tracks[0].items.push(clipItem(p, 0, dur + 1));
  p.tracks[1].items.push(createItem('theme.overlay', { t0: 0, in: 0, out: dur + 1, x: 0, y: 0, w: W, h: H, auto: TAG, style }));
  if (opts.sting !== false) p.tracks[1].items.push(createItem('theme.sting', { t0: 0, in: 0, out: 0.9, x: 0, y: 0, w: W, h: H, style: { colors: style.colors, hero: th.sting?.hero, icons: (th.layers.find((l) => l.kind === 'icons')?.icons || []).slice(0, 1) } }));
  let ready = null;   // flags / maps / icons are fetched on the first hover or thumbnail, never for the whole gallery at once
  const prepare = () => (ready ||= Promise.all([loadAssets(style), loadAssets({ icons: (th.layers.find((l) => l.kind === 'icons')?.icons || []).slice(0, 1) })]));
  return { id: 'theme:' + id, label: th.name, aspect, canvas, dur, onStop, prepare, draw: (g, w, h, t) => drawMini(g, w, h, p, t), project: p, style };
}

/** hover spec for a recipe row: sample clips cut on a transition, the recipe's caption style, its theme, price tag */
export async function recipeSpec(recipe, ws) {
  const [{ suggestThemes }, CS, M] = await Promise.all([import('./suggest.js'), import('../auto/recipes/caption-styles.js'), import('../captions/model.js').catch(() => null)]);
  const p = miniProject('9:16'), W = p.canvas.w, H = p.canvas.h, dur = 3.4;
  p.tracks[0].items.push(clipItem(p, 0, 1.7, 0), clipItem(p, 1.7, 2, 1));
  const tid = suggestThemes({ recipe, workspace: ws, limit: 1 })[0]?.id;
  const th = tid && themeById(tid);
  if (th) {
    const style = themeStyle(th, { intensity: 'subtle', beats: [0.4, 0.9, 1.4, 1.9, 2.4, 2.9], faces: [SAMPLE_FACE] }, ws);
    p.tracks[1].items.push(createItem('theme.overlay', { t0: 0, in: 0, out: dur + 1, x: 0, y: 0, w: W, h: H, style })); loadAssets(style);
  }
  const kind = recipe.effects?.transitions?.[0] || 'flash';
  p.tracks[1].items.push(createItem('auto.transition', { t0: 1.5, in: 0, out: 0.4, x: 0, y: 0, w: W, h: H, style: { kind, color: kind === 'slide' || kind === 'wipe' ? ws?.colors?.primary || '#0b2545' : '#ffffff', accent: ws?.colors?.accent } }));
  if (recipe.effects?.priceTag) p.tracks[1].items.push(createItem('auto.pricetag', { t0: 2, in: 0, out: 1.4, x: W * 0.52, y: H * 0.12, w: W * 0.42, h: W * 0.2, style: { price: 'AED 99', label: 'Offer', colors: ws?.colors } }));
  const imm = (recipe.industries || []).some((i) => /immigra|education|consult/.test(i)), beauty = (recipe.industries || []).includes('beauty');
  const text = imm ? 'Study in Europe with a free consultation' : beauty ? 'This serum gives you that glow' : 'Here is how we do it, step by step';
  let t = 0.25; p.captions.words = text.split(' ').map((w) => { const o = { t0: +t.toFixed(2), t1: +(t + 0.34).toFixed(2), w, conf: 1 }; t += 0.38; return o; });
  const sid = CS.captionStyleFor(recipe, 'en'); p.captions.style = sid;
  try { p.captions.lines = M ? M.buildLines(p.captions.words, sid, p.canvas) : []; } catch { p.captions.lines = []; }
  if (p.captions.lines.length) p.tracks[3].items.push(createItem('caption', { t0: 0, in: 0, out: dur + 1, x: 0, y: 0, w: W, h: H }));
  return { id: 'recipe:' + recipe.id, label: recipe.name, aspect: '9:16', dur, draw: (g, w, h, tt) => drawMini(g, w, h, p, tt), project: p };
}

/** hover spec for a Create → format: its whole timeline sped up into a 4 s loop */
export async function formatSpec(formatId, ws, aspect = '9:16') {
  const F = await import('../content/formats.js'); const f = F.formatById(formatId); if (!f) return null;
  const p = miniProject(aspect), L = F.layoutFormat(f, p.canvas, ws || {});
  const map = { video: 0, overlay: 1, text: 2 };
  for (const { kind, item } of L.items) p.tracks[map[kind] ?? 1].items.push(item);
  const dur = 4, span = Math.min(L.length || 4, 14);
  return { id: 'format:' + formatId, label: f.name, aspect, dur, draw: (g, w, h, t) => drawMini(g, w, h, p, (t / dur) * span), project: p };
}

/** hover spec for a colour look tile: the sample clip through the look's GPU grade */
export function lookSpec(lookId, canvas, onStop) {
  let L = null; import('../look/index.js').then((m) => { L = m; }).catch(() => {});
  const fake = { id: 'hp-look', type: 'clip', t0: 0, in: 0, out: 4, speed: 1, fx: [{ type: 'look.grade', look: lookId, amount: 1, wb: [1, 1, 1], ev: 0, mg: [1, 1, 1], mo: [0, 0, 0] }] };
  return { id: 'look:' + lookId, canvas, dur: 3, onStop, draw: (g, w, h, t) => {
    const src = sampleFrame(t, 0); let out = null;
    if (L) out = L.processLook(src, fake, t, { canvas: { w: 1080, h: 1920 } }, { box: { w, h }, scale: 1, preview: true });
    const s = out || src, sw = s.width, sh = s.height, k = Math.max(w / sw, h / sh);
    g.fillStyle = '#111'; g.fillRect(0, 0, w, h); g.drawImage(s, (w - sw * k) / 2, (h - sh * k) / 2, sw * k, sh * k);
  } };
}

/** hover spec for a transition: sample clip A → B through the look module's own GPU transition shader */
export function transitionSpec(kind, label) {
  let G = null; import('../look/gl.js').then((m) => { G = m; }).catch(() => {});
  const off = document.createElement('canvas'), off2 = document.createElement('canvas');
  return { id: 'transition:' + kind, label, aspect: '9:16', dur: 2.4, draw: (g, w, h, t) => {
    const p = Math.max(0, Math.min(1, (t - 0.8) / 0.7)), A = sampleFrame(t, 0), B = sampleFrame(t, 1);
    const gl = G?.gl?.(), k = G ? Math.max(0, G.TRANSITION_KINDS.indexOf(kind)) : -1;
    if (gl && k >= 0 && p > 0 && p < 1) {
      if (off.width !== w || off.height !== h) { off.width = w; off.height = h; }
      const o = off.getContext('2d'); o.drawImage(A, 0, 0, w, h); const ta = gl.upload('hpA', off, w, h);
      if (off2.width !== w || off2.height !== h) { off2.width = w; off2.height = h; }
      off2.getContext('2d').drawImage(B, 0, 0, w, h); const tb = gl.upload('hpB', off2, w, h);
      gl.size(w, h); gl.run('trans', null, { p, kind: k, res: [w, h], seed: 3, col: [1, 1, 1], hasM: 0, mc: [0.5, 0.5] }, { A: ta, B: tb });
      g.drawImage(gl.canvas, 0, 0, w, h);
    } else { g.drawImage(p < 0.5 ? A : B, 0, 0, w, h); if (!gl && p > 0 && p < 1) { g.globalAlpha = 1 - Math.abs(2 * p - 1); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); g.globalAlpha = 1; } }
  } };
}

/** hover spec for a music / SFX row: a few seconds from the most typical part (music: a third in; sfx: from the top) */
export function audioSpec(src, { duration = 0, kind = 'music' } = {}) {
  return { id: 'audio:' + src, kind: 'audio', src, offset: kind === 'music' ? Math.max(0, Math.min(duration - 4, duration * 0.33)) : 0, dur: kind === 'music' ? 4 : Math.min(3, Math.max(1, duration || 2)) };
}

/**
 * a spec built on first use (recipes, formats: their mini timeline needs a few imports). Until it is ready the sample
 * clip plays, so the preview still starts at once.
 */
export function lazySpec(id, label, make, { aspect = '9:16', dur = 3.4 } = {}) {
  let inner = null, p = null;
  const spec = { id, label, aspect, dur,
    prepare: () => (p ||= Promise.resolve().then(make).then((s) => { inner = s; if (s?.dur) spec.dur = s.dur; inner?.prepare?.(); }).catch((e) => console.warn('[hover]', id, e))),
    draw: (g, w, h, t) => { if (inner) return inner.draw(g, w, h, t); const s = sampleFrame(t, 0); g.drawImage(s, 0, 0, w, h); } };
  return spec;
}
