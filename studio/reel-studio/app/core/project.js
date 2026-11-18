// KBB Studio — core/project.js (CONTRACT, owned by R1)
//
// The project is plain JSON. Modules READ it freely but never mutate it: every change goes through
// history.apply(cmd) (core/history.js) so it can be undone.
//
// Project
//   { v, id, workspace, name, brand, recipe, created, modified,
//     canvas:  { w, h, fps, bg, aspect },                 aspect: '9:16' | '1:1' | '4:5' | '16:9'
//     media:   [ Media ],
//     tracks:  [ Track ],                                 ARRAY ORDER = DRAW ORDER (index 0 is drawn first, at the bottom)
//     captions:{ style, lang, words:[{t0,t1,w,conf}], lines:[{id,t0,t1,wordIdx:[a,b]}] },
//     markers: [ { id, t, label, color } ],
//     meta:    { }                                        free space for modules: meta[moduleId] = {...}
//   }
//   workspace: the slug of the company (workspace) the project belongs to — see Workspace below.
// Workspace (workspaces/<slug>/workspace.json, one per company; its projects/ and exports/ live beside it)
//   { v, id:slug, name, industry, brandRef?, colors:{primary,secondary,accent,text,bg}, fonts:{heading,body},
//     logo?, intro?, outro?, endCard?:{title,sub,cta}, contact, favRecipes:[recipeId], created, modified }
//     logo/intro/outro: paths inside the studio folder (usually workspaces/<slug>/brand/...);
//     brandRef: a brand kit folder under brands/ (R3) the workspace was seeded from.
// Media   { id, kind:'video'|'audio'|'image', name, src, dur, w, h, fps, hasAudio, size }
//           src is a path inside the Reel Studio folder (e.g. 'projects/<id>/media/clip.mp4'), served by the engine.
// Track   { id, kind:'video'|'overlay'|'text'|'caption'|'music'|'sfx', name, items:[Item], locked, muted, hidden, volume }
// Item    { id, type, media?, t0, in, out, speed,
//           x, y, w, h, rot, opacity, fx:[{type, v?, ...}], kf:{ prop:[[t, v, ease]] },
//           text?, style?, volume, muted, fadeIn, fadeOut, crop?:{l,t,r,b}, zoom?:{from,to,cx,cy}, group?, name? }
//   type: 'clip' (video/image media on a visual track) | 'audio' (music/sfx) | 'text' | 'shape' | 'sticker' | 'caption'
//   t0      timeline start (s).  in/out: source in/out (s).  Items without a source (text, shapes) use in=0, out=duration.
//   speed   playback rate; on-timeline duration = (out - in) / speed (for a speed ramp: its average rate).
//   curve?, reverse?, freeze?, keepPitch?, rampMute?, smooth?   speed ramps, reverse, freeze frames and how slow
//           motion and its sound behave — see core/retime.js. Map times through srcTime / tlTime, never by hand.
//   x,y,w,h canvas px of the item's box (top-left origin), rot in degrees about the box centre, opacity 0..1.
//   kf      keyframes, t relative to the item start (s), ease: 'linear' | 'in' | 'out' | 'inout' | 'hold'.
//   crop    fractions 0..1 cut from each edge of the source.  zoom: Ken Burns scale from→to across the item, centred cx,cy (0..1).
// All times are seconds (float); all geometry is canvas px.

import { srcTime as retimeSrc, tlTime as retimeTl } from './retime.js';

export const SCHEMA_VERSION = 1;

export const TRACK_KINDS = ['video', 'overlay', 'text', 'caption', 'music', 'sfx'];
export const VISUAL_KINDS = new Set(['video', 'overlay', 'text', 'caption']);
export const AUDIO_KINDS = new Set(['music', 'sfx']);

export const ASPECTS = {
  '9:16': { w: 1080, h: 1920, label: '9:16 Reel / TikTok' },
  '1:1': { w: 1080, h: 1080, label: '1:1 Square' },
  '4:5': { w: 1080, h: 1350, label: '4:5 Feed' },
  '16:9': { w: 1920, h: 1080, label: '16:9 YouTube' },
};

// ids: short, unique enough for one machine, sortable by creation
let seq = 0;
export function uid(prefix = 'i') {
  seq = (seq + 1) % 1296;
  return prefix + Date.now().toString(36) + seq.toString(36).padStart(2, '0') + Math.random().toString(36).slice(2, 6);
}

export const clone = (o) => (o === undefined ? undefined : structuredClone(o));

export function createTrack(kind, name) {
  return { id: uid('t'), kind, name: name || defaultTrackName(kind), items: [], locked: false, muted: false, hidden: false, volume: 1 };
}

function defaultTrackName(kind) {
  return { video: 'Video', overlay: 'Overlay', text: 'Text', caption: 'Captions', music: 'Music', sfx: 'Sound FX' }[kind] || kind;
}

export function createProject({ name = 'Untitled reel', workspace = null, brand = null, recipe = null, aspect = '9:16', fps = 30 } = {}) {
  const a = ASPECTS[aspect] || ASPECTS['9:16'];
  const now = new Date().toISOString();
  return {
    v: SCHEMA_VERSION,
    id: uid('p'),
    workspace,
    name,
    brand,
    recipe,
    created: now,
    modified: now,
    canvas: { w: a.w, h: a.h, fps, bg: '#000000', aspect },
    media: [],
    // draw order: video at the bottom, then overlays, text, captions on top
    tracks: ['video', 'overlay', 'text', 'caption', 'music', 'sfx'].map((k) => createTrack(k)),
    captions: { style: null, lang: 'auto', words: [], lines: [] },
    markers: [],
    meta: {},
  };
}

/** slug for a workspace id from a company name: 'Planet Careers' -> 'planet-careers' */
export const slugify = (s) => String(s || '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'company';

export function createWorkspace({ name = 'New company', industry = '', colors = {}, fonts = {}, contact = '', brandRef = null } = {}) {
  const now = new Date().toISOString();
  return {
    v: SCHEMA_VERSION, id: slugify(name), name, industry, brandRef,
    colors: Object.assign({ primary: '#e8508b', secondary: '#1d1d24', accent: '#ffd166', text: '#ffffff', bg: '#000000' }, colors),
    fonts: Object.assign({ heading: 'system-ui', body: 'system-ui' }, fonts),
    logo: null, intro: null, outro: null, endCard: { title: name, sub: '', cta: '' }, contact,
    favRecipes: [], created: now, modified: now,
  };
}

/** A fully-populated item with every field the renderer reads. */
export function createItem(type, props = {}) {
  const base = {
    id: uid('i'), type, t0: 0, in: 0, out: 3, speed: 1,
    x: 0, y: 0, w: 100, h: 100, rot: 0, opacity: 1,
    fx: [], kf: {}, volume: 1, muted: false, fadeIn: 0, fadeOut: 0,
  };
  return Object.assign(base, props);
}

/** Bring an older or partial project up to the current schema (never throws on missing fields). */
export function normalizeProject(p) {
  const out = Object.assign(createProject({ name: p?.name }), clone(p) || {});
  out.v = SCHEMA_VERSION;
  out.canvas = Object.assign({ w: 1080, h: 1920, fps: 30, bg: '#000000', aspect: '9:16' }, out.canvas);
  out.media = Array.isArray(out.media) ? out.media : [];
  out.tracks = Array.isArray(out.tracks) ? out.tracks : [];
  for (const t of out.tracks) {
    t.items = Array.isArray(t.items) ? t.items : [];
    t.locked = !!t.locked; t.muted = !!t.muted; t.hidden = !!t.hidden;
    if (typeof t.volume !== 'number') t.volume = 1;
    t.items = t.items.map((it) => Object.assign(createItem(it.type || 'clip'), it));
  }
  out.captions = Object.assign({ style: null, lang: 'auto', words: [], lines: [] }, out.captions);
  out.markers = Array.isArray(out.markers) ? out.markers : [];
  out.meta = out.meta || {};
  return out;
}

// ── read helpers (pure) ──

export const itemDur = (it) => Math.max(0, (it.out - it.in) / (it.speed || 1));
export const itemEnd = (it) => it.t0 + itemDur(it);
/** source time for timeline time t (clamped to the item's source range; speed ramps, reverse and freeze included) */
export const srcTime = retimeSrc;
/** timeline time at which the item shows source time s (the inverse of srcTime) */
export const tlTime = retimeTl;
export { speedAt, isRetimed } from './retime.js';
export const isActive = (it, t) => t >= it.t0 && t < itemEnd(it);

export function projectDuration(p) {
  let end = 0;
  for (const tr of p.tracks) for (const it of tr.items) end = Math.max(end, itemEnd(it));
  return end;
}

export function findItem(p, id) {
  for (const track of p.tracks) {
    const index = track.items.findIndex((i) => i.id === id);
    if (index >= 0) return { track, item: track.items[index], index };
  }
  return null;
}

export const findTrack = (p, id) => p.tracks.find((t) => t.id === id) || null;
export const findMedia = (p, id) => p.media.find((m) => m.id === id) || null;
export const tracksOfKind = (p, kind) => p.tracks.filter((t) => t.kind === kind);

/** all items in a group (or just the item when it has none) */
export function groupMembers(p, id) {
  const hit = findItem(p, id);
  if (!hit || !hit.item.group) return hit ? [hit.item] : [];
  const out = [];
  for (const tr of p.tracks) for (const it of tr.items) if (it.group === hit.item.group) out.push(it);
  return out;
}

/** a box that fits media of size mw×mh inside (contain) or over (cover) the canvas */
export function fitBox(canvas, mw, mh, mode = 'cover') {
  const s = mode === 'cover' ? Math.max(canvas.w / mw, canvas.h / mh) : Math.min(canvas.w / mw, canvas.h / mh);
  const w = Math.round(mw * s), h = Math.round(mh * s);
  return { x: Math.round((canvas.w - w) / 2), y: Math.round((canvas.h - h) / 2), w, h };
}

/** Title-safe and action-safe areas for the reels apps (bottom UI and right-side buttons). */
export function safeAreas(canvas) {
  const { w, h } = canvas;
  if (canvas.aspect === '9:16') {
    return {
      action: { x: w * 0.06, y: h * 0.08, w: w * 0.88, h: h * 0.72 },
      title: { x: w * 0.08, y: h * 0.12, w: w * 0.76, h: h * 0.6 },   // clear of the right-side buttons and the caption/CTA strip
    };
  }
  return {
    action: { x: w * 0.05, y: h * 0.05, w: w * 0.9, h: h * 0.9 },
    title: { x: w * 0.1, y: h * 0.1, w: w * 0.8, h: h * 0.8 },
  };
}
