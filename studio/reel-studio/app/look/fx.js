// KBB Studio · look · per-clip GPU effects, registered as SOURCE fx with core/render.js so the clip's picture is
// processed before it is cropped and drawn — the same call in the preview monitor and in the export:
//
//   item.fx {type:'look.grade',   look, amount, wb:[r,g,b], ev, mg:[3], mo:[3]}     colour look + correction + shot match
//   item.fx {type:'look.touchup', strength (0..1, default .35), eyes (0..1), under (0..1)}   face-landmark skin touch-up
//   item.fx {type:'look.bg',      mode:'blur'|'color'|'gradient'|'image'|'flag'|'landmark', color, colors:[a,b], src, country}
//
// All three run in ONE pass (gl.js `look` program); the first look.* entry in item.fx does the work for the others.
import { registerSourceFx } from '../core/render.js';
import { srcTime } from '../core/project.js';
import { gl, blurs } from './gl.js';
import { lookById } from './looks.js';
import { segAt, facesAt } from './vision.js';

export const LOOK_FX = ['look.grade', 'look.touchup', 'look.bg'];
const SET = new Set(LOOK_FX);

// ── face mask from landmarks (R = skin, G = eyes, B = under-eye) ──
const OVAL = [10, 338, 297, 332, 284, 251, 389, 356, 454, 323, 361, 288, 397, 365, 379, 378, 400, 377, 152, 148, 176, 149, 150, 136, 172, 58, 132, 93, 234, 127, 162, 21, 54, 103, 67, 109];
const EYE_R = [33, 246, 161, 160, 159, 158, 157, 173, 133, 155, 154, 153, 145, 144, 163, 7];
const EYE_L = [263, 466, 388, 387, 386, 385, 384, 398, 362, 382, 381, 380, 374, 373, 390, 249];
const BROW_R = [70, 63, 105, 66, 107, 55, 65, 52, 53, 46];
const BROW_L = [300, 293, 334, 296, 336, 285, 295, 282, 283, 276];
const LIPS = [61, 185, 40, 39, 37, 0, 267, 269, 270, 409, 291, 375, 321, 405, 314, 17, 84, 181, 91, 146];
const LID_R = [33, 7, 163, 144, 145, 153, 154, 155, 133];
const LID_L = [263, 249, 390, 373, 374, 380, 381, 382, 362];

let maskCv = null;
export function drawFaceMask(faces, aspect) {
  const MW = aspect >= 1 ? 384 : Math.round(384 * aspect), MH = aspect >= 1 ? Math.round(384 / aspect) : 384;
  if (!maskCv) maskCv = document.createElement('canvas');
  if (maskCv.width !== MW || maskCv.height !== MH) { maskCv.width = MW; maskCv.height = MH; }
  const c = maskCv.getContext('2d');
  c.globalCompositeOperation = 'source-over'; c.filter = 'none'; c.fillStyle = '#000'; c.fillRect(0, 0, MW, MH);
  for (const f of faces) {
    const P = (i) => [f[i * 2] * MW, f[i * 2 + 1] * MH];
    const poly = (idx, color, grow = 1, dy = 0) => {
      const pts = idx.map(P); const cx = pts.reduce((s, p) => s + p[0], 0) / pts.length, cy = pts.reduce((s, p) => s + p[1], 0) / pts.length;
      c.fillStyle = color; c.beginPath();
      pts.forEach(([x, y], i) => { const X = cx + (x - cx) * grow, Y = cy + (y - cy) * grow + dy; i ? c.lineTo(X, Y) : c.moveTo(X, Y); });
      c.closePath(); c.fill();
    };
    const [ax, ay] = P(33), [bx, by] = P(133), ew = Math.hypot(bx - ax, by - ay);
    c.filter = `blur(${Math.max(1, ew * 0.22).toFixed(1)}px)`;
    c.globalCompositeOperation = 'source-over';
    poly(OVAL, '#f00', 0.97);
    for (const b of [BROW_R, BROW_L]) poly(b, '#000', 1.25);
    poly(LIPS, '#000', 1.15);
    for (const e of [EYE_R, EYE_L]) poly(e, '#0f0', 1.3);
    // under-eye: the band between the lower lid and ~0.45 eye-widths below it (added in blue, skin stays red)
    c.globalCompositeOperation = 'lighter';
    for (const lid of [LID_R, LID_L]) {
      const pts = lid.map(P); c.fillStyle = '#00f'; c.beginPath();
      pts.forEach(([x, y], i) => (i ? c.lineTo(x, y + ew * 0.08) : c.moveTo(x, y + ew * 0.08)));
      for (let i = pts.length - 1; i >= 0; i--) { const [x, y] = pts[i]; const k = Math.sin((Math.PI * i) / (pts.length - 1)); c.lineTo(x, y + ew * (0.1 + 0.38 * k)); }
      c.closePath(); c.fill();
    }
  }
  c.filter = 'none'; c.globalCompositeOperation = 'source-over';
  return maskCv;
}

// ── background pictures (images and generated country scenes), decoded once ──
const BG = new Map();   // key -> {el, w, h}
export const bgKey = (fx) => (fx.mode === 'image' ? 'img:' + fx.src : fx.mode === 'flag' || fx.mode === 'landmark' ? fx.mode + ':' + (fx.country || 'gb') : null);
export function bgReady(fx) { const k = bgKey(fx); return !k || BG.has(k); }
const loadImg = (src) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = /^(blob:|data:|https?:|\/)/.test(src) ? src : '/' + src.split('/').map(encodeURIComponent).join('/'); });
let COUNTRIES = null;
async function countries() {
  if (COUNTRIES) return COUNTRIES;
  try { const r = await fetch('/brands/planet-careers/countries/countries.json'); COUNTRIES = r.ok ? (await r.json()).countries || [] : []; } catch { COUNTRIES = []; }
  return COUNTRIES;
}
export async function loadBackground(fx) {
  const k = bgKey(fx); if (!k || BG.has(k)) return;
  if (fx.mode === 'image') { const i = await loadImg(fx.src); if (i) BG.set(k, { el: i, w: i.naturalWidth, h: i.naturalHeight }); return; }
  const c = (await countries()).find((x) => x.code === fx.country) || (await countries())[0];
  if (!c) return;
  const base = 'brands/planet-careers/countries/';
  const [flag, mark] = await Promise.all([loadImg(base + c.flag), fx.mode === 'landmark' && c.landmark?.file ? loadImg(base + c.landmark.file) : null]);
  const W = 1080, H = 1920, cv = document.createElement('canvas'); cv.width = W; cv.height = H;
  const x = cv.getContext('2d'), [c0, c1, c2] = c.colors || ['#0b2545', '#13a89e', '#ffffff'];
  if (fx.mode === 'flag') {
    // the flag, full frame, softly waving (stripes of the picture shifted on a sine), then a gentle vignette
    if (flag) for (let i = 0; i < 48; i++) { const sx = (flag.naturalWidth * i) / 48, sw = flag.naturalWidth / 48, dy = Math.sin(i / 5) * 40; x.drawImage(flag, sx, 0, sw, flag.naturalHeight, (W * i) / 48 - 1, -60 + dy, W / 48 + 2, H + 120); }
    const v = x.createRadialGradient(W / 2, H * 0.45, H * 0.2, W / 2, H * 0.45, H * 0.75); v.addColorStop(0, 'rgba(0,0,0,0)'); v.addColorStop(1, 'rgba(0,0,0,0.45)');
    x.fillStyle = v; x.fillRect(0, 0, W, H);
  } else {
    // a sky in the country's colours, the flag faint behind, the landmark standing at the bottom
    const g = x.createLinearGradient(0, 0, 0, H); g.addColorStop(0, c0); g.addColorStop(0.75, c1 === '#FFFFFF' || c1 === '#ffffff' ? c0 : c1); g.addColorStop(1, '#0b0b12');
    x.fillStyle = g; x.fillRect(0, 0, W, H);
    if (flag) { x.globalAlpha = 0.16; x.drawImage(flag, -W * 0.2, H * 0.08, W * 1.4, W * 1.4 * flag.naturalHeight / flag.naturalWidth); x.globalAlpha = 1; }
    if (mark) { const s = W * 1.05; x.globalAlpha = 0.9; x.drawImage(mark, (W - s) / 2, H - s * 0.98, s, s); x.globalAlpha = 1; }
    const sun = x.createRadialGradient(W * 0.72, H * 0.22, 0, W * 0.72, H * 0.22, W * 0.55); sun.addColorStop(0, 'rgba(255,240,200,0.35)'); sun.addColorStop(1, 'rgba(255,240,200,0)');
    x.fillStyle = sun; x.fillRect(0, 0, W, H);
  }
  BG.set(k, { el: cv, w: W, h: H });
}

const hex = (c, d = [0, 0, 0]) => { const m = /^#?([0-9a-f]{6})$/i.exec(c || ''); if (!m) return d; const n = parseInt(m[1], 16); return [(n >> 16 & 255) / 255, (n >> 8 & 255) / 255, (n & 255) / 255]; };
const BGMODE = { blur: 1, color: 2, gradient: 3, image: 4, flag: 4, landmark: 4 };

/** the whole look pass for one clip at time t: returns the GL canvas (drawn by the clip layer) or null */
export function processLook(src, item, t, project, env) {
  const g = gl(); if (!g) return null;
  const sw = src.displayWidth || src.videoWidth || src.naturalWidth || src.width, sh = src.displayHeight || src.videoHeight || src.naturalHeight || src.height;
  if (!sw || !sh) return null;
  let grade = null, touch = null, bg = null;
  for (const f of item.fx) { if (f.type === 'look.grade') grade = f; else if (f.type === 'look.touchup') touch = f; else if (f.type === 'look.bg') bg = f; }
  const st = srcTime(item, t);
  const look = lookById(grade?.look).p, amount = grade ? grade.amount ?? 1 : 0;
  // working size: what the clip occupies on the output (preview: the monitor; export: the frame), never above the source
  const want = Math.max(256, Math.min(2160, Math.max(env.box.w, env.box.h) * (env.scale || 1)));
  const k = Math.min(1, want / Math.max(sw, sh)), W = Math.max(2, Math.round(sw * k)), H = Math.max(2, Math.round(sh * k));
  // the source is sampled bilinearly at the working size — the same filtering drawImage gives a clip without effects,
  // so everything the effects do not touch looks exactly as it would without them
  const srcTex = g.upload('src', src, sw, sh);
  const faces = touch && (touch.strength ?? 0.35) > 0 && item.media ? facesAt(item.media, st) : null;
  const mask = bg && item.media ? segAt(item.media, st) : null;
  const needBlur = !!(faces?.length || (mask && bg.mode === 'blur') || (amount && (look.glow > 0 || look.clar > 0)));
  const bl = needBlur ? blurs(g, srcTex, W, H, { large: !!(mask && bg.mode === 'blur') }) : { small: srcTex, large: srcTex };
  const tex = { src: srcTex, blurS: bl.small, blurL: bl.large };
  const u = {
    res: [W, H], frame: Math.round(st * 30) % 9973, amount,
    mg: grade?.mg || [1, 1, 1], mo: grade?.mo || [0, 0, 0], wb: grade?.wb || [1, 1, 1], ev: grade?.ev || 0,
    Lexp: look.exp, Lcon: look.con, Lsat: look.sat, Lvib: look.vib, Ltemp: look.temp, Ltint: look.tint, Lfade: look.fade, Lbw: look.bw, Lglow: look.glow,
    Lvig: look.vig, Lgrain: look.grain, Lcurve: look.curve, Lclar: look.clar, Lsplit: look.split, Llift: look.lift, Lgamma: look.gamma, Lgain: look.gain, Lsh: look.sh, Lhi: look.hi, Lbwmix: look.bwmix,
    hasFace: 0, hasSeg: 0, bgMode: 0, Ts: 0, Teye: 0, Tunder: 0,
  };
  if (faces?.length) {
    tex.face = g.upload('face', drawFaceMask(faces, sw / sh), 0, 0);
    Object.assign(u, { hasFace: 1, Ts: touch.strength ?? 0.35, Teye: touch.eyes ?? 0.5, Tunder: touch.under ?? 0.5 });
  }
  if (mask) {
    tex.seg = g.uploadR8('seg', mask.data, mask.w, mask.h);
    Object.assign(u, { hasSeg: 1, segTexel: [1 / mask.w, 1 / mask.h], bgMode: BGMODE[bg.mode] || 1 });
    if (bg.mode === 'color') u.bgA = hex(bg.color, [0.07, 0.07, 0.1]);
    if (bg.mode === 'gradient') { u.bgA = hex(bg.colors?.[0], [0.78, 0.22, 0.37]); u.bgB = hex(bg.colors?.[1], [0.16, 0.13, 0.16]); }
    if (u.bgMode === 4) {
      const pic = BG.get(bgKey(bg));
      if (!pic) { u.bgMode = 2; u.bgA = [0.05, 0.05, 0.08]; }
      else {
        const key = 'bg:' + bgKey(bg); let bt = g.texs.get(key);
        if (!bt || bt.src !== pic.el) { bt = g.upload(key, pic.el, pic.w, pic.h); bt.src = pic.el; }
        tex.bgTex = bt;
        // cover-fit the picture over the clip
        const ra = (W / H) / (pic.w / pic.h);
        u.bgScale = ra > 1 ? [1, 1 / ra] : [ra, 1]; u.bgOff = [(1 - u.bgScale[0]) / 2, (1 - u.bgScale[1]) / 2];
      }
    }
  }
  g.size(W, H);
  g.run('look', null, u, tex);
  return g.canvas;
}

for (const type of LOOK_FX) {
  registerSourceFx(type, (src, item, t, project, fx, env) => {
    const first = item.fx.find((f) => SET.has(f.type));
    if (first !== fx) return null;   // the first look.* entry already did all of them
    return processLook(src, item, t, project, env);
  });
}
