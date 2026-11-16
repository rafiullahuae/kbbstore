// KBB Studio · automation · effect layers. Registered with core/render.js so the preview monitor and the MP4 export
// draw them through the same code path. Item data is plain JSON (kit colours/paths copied into item.style).
//
//   item.type 'auto.transition'  style {kind: flash|whip|zoom|glitch|leak|dissolve|slide|wipe, color}
//   item.type 'auto.pricetag'    style {price, label, colors}
//   item.type 'auto.logo'        style {mode: intro|outro, kit:{name, colors, fonts, markPaths, sting}}
//   item.type 'auto.endcard'     style {kit, title, sub, cta, contact, payments:[src]}
//   item.fx  {type:'auto.punch', t0 (timeline s), dur, amount}   punch-in zoom on a clip
import { registerLayer, registerFx, roundRect } from '../core/render.js';

const images = new Map();
/** decode images once (end-card payment badges, logos) so drawing stays synchronous */
export function preloadImages(srcs = []) {
  return Promise.all(srcs.filter(Boolean).map(src => {
    if (images.has(src)) return images.get(src).ready;
    const img = new Image(); const rec = { img, ok: false };
    rec.ready = new Promise(res => { img.onload = () => { rec.ok = true; res(); }; img.onerror = () => res(); });
    img.src = /^(blob:|data:|https?:|\/)/.test(src) ? src : '/' + src; images.set(src, rec); return rec.ready;
  }));
}
const imageOf = src => { const r = images.get(src); return r && r.ok ? r.img : null; };
/** FontFace-load a kit's fonts (kit folder base e.g. 'brands/kbb/') so canvas text uses them */
export async function loadKitFonts(kit, base) {
  if (typeof FontFace === 'undefined' || !kit?.fonts) return;
  const jobs = [];
  for (const f of Object.values(kit.fonts)) for (const file of f.files || []) {
    const ff = new FontFace(f.family, `url(/${base}${file.file})`, { weight: String(file.weight || 'normal'), style: file.style || 'normal', ...(file.unicodeRange ? { unicodeRange: file.unicodeRange } : {}) });
    jobs.push(ff.load().then(x => document.fonts.add(x)).catch(() => {}));
  }
  await Promise.all(jobs);
}

const clamp = (x, a = 0, b = 1) => Math.max(a, Math.min(b, x));
const easeOut = x => 1 - Math.pow(1 - clamp(x), 3);
const back = x => { x = clamp(x); const c = 1.7; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const isAr = s => /[؀-ۿ]/.test(s || '');
const fontOf = (kit, role, w, px, text) => `${w} ${Math.round(px)}px ${isAr(text) ? `"${kit?.fonts?.arabic?.family || 'Cairo'}", ` : ''}"${kit?.fonts?.[role]?.family || 'Inter'}", ${kit?.fonts?.[role]?.fallback || 'sans-serif'}`;

// ── punch-in zoom (item fx) ──
registerFx('auto.punch', (ctx, item, t, project, fx, env) => {
  const lt = t - fx.t0; if (lt < 0 || lt > fx.dur) return;
  const up = 0.12, down = 0.2, a = fx.amount || 1.12;
  const k = lt < up ? 1 + (a - 1) * easeOut(lt / up) : lt > fx.dur - down ? 1 + (a - 1) * (1 - easeOut((lt - fx.dur + down) / down)) : a;
  const { w, h } = env.box;
  ctx.translate(w / 2, h / 2); ctx.scale(k, k); ctx.translate(-w / 2, -h / 2);
});

// ── transitions over a cut (reads back what is already drawn on this frame) ──
registerLayer('auto.transition', (ctx, item, t, project, env) => {
  const p = clamp(env.local / Math.max(0.01, item.out - item.in)), s = item.style || {}, W = env.w, H = env.h;
  const peak = 1 - Math.abs(2 * p - 1);
  const cv = ctx.canvas, dpr = env.scale || 1;
  const self = (dx, dy, k, alpha) => { // redraw the current frame shifted / scaled, in device pixels
    ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalAlpha = alpha;
    const cw = cv.width, ch = cv.height; ctx.drawImage(cv, 0, 0, cw, ch, (cw - cw * k) / 2 + dx * dpr, (ch - ch * k) / 2 + dy * dpr, cw * k, ch * k); ctx.restore();
  };
  switch (s.kind) {
    case 'flash': ctx.fillStyle = s.color || '#ffffff'; ctx.globalAlpha *= 0.85 * Math.pow(peak, 1.6); ctx.fillRect(0, 0, W, H); break;
    case 'whip': for (let i = 1; i <= 6; i++) self((p < .5 ? 1 : -1) * peak * W * 0.06 * i, 0, 1, 0.22 * peak); break;
    case 'zoom': for (let i = 1; i <= 5; i++) self(0, 0, 1 + peak * 0.07 * i, 0.25 * peak); break;
    case 'glitch': {
      const n = 9, seed = Math.floor(env.local * 30);
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0);
      for (let i = 0; i < n; i++) { const r = Math.abs(Math.sin((seed + 1) * (i + 3) * 12.9898)) % 1, y = Math.floor(r * cv.height), hh = Math.max(2, Math.floor(cv.height * (0.02 + 0.06 * ((r * 7) % 1)))); const dx = (r - 0.5) * cv.width * 0.12 * peak; ctx.drawImage(cv, 0, y, cv.width, hh, dx, y, cv.width, hh); }
      ctx.globalCompositeOperation = 'screen'; ctx.globalAlpha = 0.35 * peak; ctx.fillStyle = '#ff2a6d'; ctx.fillRect(0, 0, cv.width * 0.5 * peak, cv.height); ctx.fillStyle = '#05d9e8'; ctx.fillRect(cv.width * (1 - 0.5 * peak), 0, cv.width, cv.height);
      ctx.restore(); break;
    }
    case 'leak': {
      const g = ctx.createRadialGradient(W * (0.1 + 0.8 * p), H * 0.3, 0, W * (0.1 + 0.8 * p), H * 0.3, W * 0.9);
      g.addColorStop(0, 'rgba(255,190,120,0.9)'); g.addColorStop(0.45, 'rgba(255,90,140,0.45)'); g.addColorStop(1, 'rgba(255,90,140,0)');
      ctx.globalCompositeOperation = 'screen'; ctx.globalAlpha *= peak; ctx.fillStyle = g; ctx.fillRect(0, 0, W, H); break;
    }
    case 'dissolve': ctx.fillStyle = s.color || '#ffffff'; ctx.globalAlpha *= 0.45 * Math.pow(peak, 1.2); ctx.fillRect(0, 0, W, H); break;
    case 'slide': case 'wipe': {
      const x = -W * 0.3 + p * W * 1.6, bw = W * 0.35;
      ctx.fillStyle = s.color || '#0b2545'; ctx.globalAlpha *= 0.95;
      ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x + bw, 0); ctx.lineTo(x + bw - W * 0.15, H); ctx.lineTo(x - W * 0.15, H); ctx.closePath(); ctx.fill();
      if (s.accent) { ctx.fillStyle = s.accent; ctx.fillRect(x + bw - 6, 0, 10, H); }
      break;
    }
    default: break;
  }
});

// ── price / offer tag ──
registerLayer('auto.pricetag', (ctx, item, t, project, env) => {
  const s = item.style || {}, c = s.colors || {}, { w, h } = env.box, d = item.out - item.in, lt = env.local;
  const k = lt < 0.35 ? back(lt / 0.35) : lt > d - 0.25 ? 1 - easeOut((lt - d + 0.25) / 0.25) : 1;
  if (k <= 0.01) return;
  const wob = Math.sin(lt * 5) * 0.03 * (lt > 0.35 ? 1 : 0);
  ctx.translate(w / 2, h / 2); ctx.rotate(-0.12 + wob); ctx.scale(k, k); ctx.translate(-w / 2, -h / 2);
  ctx.shadowColor = 'rgba(0,0,0,.28)'; ctx.shadowBlur = h * 0.08; ctx.shadowOffsetY = h * 0.04;
  ctx.fillStyle = c.primary || '#C6395F';
  ctx.beginPath(); ctx.moveTo(h * 0.32, 0); ctx.lineTo(w - h * 0.12, 0); ctx.quadraticCurveTo(w, 0, w, h * 0.12); ctx.lineTo(w, h * 0.88); ctx.quadraticCurveTo(w, h, w - h * 0.12, h); ctx.lineTo(h * 0.32, h); ctx.lineTo(0, h / 2); ctx.closePath(); ctx.fill();
  ctx.shadowColor = 'transparent';
  ctx.fillStyle = c.soft || '#ffffff'; ctx.beginPath(); ctx.arc(h * 0.3, h / 2, h * 0.08, 0, Math.PI * 2); ctx.fill();
  ctx.fillStyle = c.accent || '#ffd166'; ctx.font = fontOf(s.kit, 'body', 800, h * 0.2, s.label); ctx.textBaseline = 'alphabetic'; ctx.textAlign = 'center';
  ctx.fillText(String(s.label || 'OFFER').toUpperCase(), (w + h * 0.4) / 2, h * 0.36);
  ctx.fillStyle = c.text || '#ffffff'; ctx.font = fontOf(s.kit, 'heading', 900, h * 0.42, s.price); ctx.fillText(s.price || 'SALE', (w + h * 0.4) / 2, h * 0.8, w - h * 0.5);
});

// ── logo sting (intro / outro) ──
function drawMark(ctx, kit, cx, cy, size, prog, onColor) {
  const mp = kit?.markPaths; if (!mp) return;
  const [vw, vh] = mp.viewBox, k = size / Math.max(vw, vh);
  ctx.save(); ctx.translate(cx - vw * k / 2, cy - vh * k / 2); ctx.scale(k, k);
  if (mp.orbit) { // Planet Careers: the orbit draws itself around the planet
    const o = mp.orbit; ctx.save(); ctx.translate(o.cx, o.cy); ctx.rotate(o.rot * Math.PI / 180);
    ctx.strokeStyle = o.stroke; ctx.lineWidth = 7; ctx.lineCap = 'round'; ctx.beginPath(); ctx.ellipse(0, 0, o.rx, o.ry, 0, Math.PI, Math.PI + Math.PI * 2 * clamp(prog * 1.4)); ctx.stroke(); ctx.restore();
  }
  // on a brand-coloured card the mark goes light (keeps its accent-coloured parts) so it never sinks into the background
  const prim = (kit.colors?.primary || '').toLowerCase();
  for (const p of mp.paths) { ctx.fillStyle = onColor && String(p.fill).toLowerCase() === prim ? onColor : p.fill; ctx.fill(new Path2D(p.d)); }
  ctx.restore();
}
registerLayer('auto.logo', (ctx, item, t, project, env) => {
  const s = item.style || {}, kit = s.kit || {}, c = kit.colors || {}, W = env.w, H = env.h, d = item.out - item.in, lt = env.local, p = clamp(lt / d);
  const fadeOut = s.mode === 'intro' ? 1 - easeOut((lt - (d - 0.35)) / 0.35) : 1;
  if (s.mode === 'intro') { // full-frame brand card that lifts away
    const g = ctx.createLinearGradient(0, 0, W, H); (kit.gradient || [c.primary, c.secondary]).forEach((col, i, a) => g.addColorStop(i / Math.max(1, a.length - 1), col));
    ctx.globalAlpha *= fadeOut; ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
  } else { ctx.fillStyle = 'rgba(0,0,0,0.35)'; ctx.globalAlpha *= easeOut(p * 3); ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1; }
  const size = Math.min(W, H) * 0.34 * back(lt / 0.55);
  drawMark(ctx, kit, W / 2, H * 0.44, size, lt / 0.9, c.text || '#ffffff');
  const tp = easeOut((lt - 0.35) / 0.45);
  ctx.globalAlpha = Math.min(fadeOut, tp); ctx.fillStyle = c.text || '#fff'; ctx.textAlign = 'center';
  ctx.font = fontOf(kit, 'heading', 800, W * 0.075, kit.name); ctx.fillText(kit.fullName || kit.name || '', W / 2, H * 0.44 + Math.min(W, H) * 0.24 + (1 - tp) * 30);
});

// ── end card ──
registerLayer('auto.endcard', (ctx, item, t, project, env) => {
  const s = item.style || {}, kit = s.kit || {}, c = kit.colors || {}, W = env.w, H = env.h, lt = env.local;
  const g = ctx.createLinearGradient(0, 0, 0, H); (kit.gradient || [c.primary, c.secondary]).forEach((col, i, a) => g.addColorStop(i / Math.max(1, a.length - 1), col));
  ctx.globalAlpha *= easeOut(lt / 0.3); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
  ctx.fillStyle = 'rgba(0,0,0,0.18)'; ctx.fillRect(0, 0, W, H); ctx.globalAlpha = 1;
  const st = (i) => easeOut((lt - 0.15 - i * 0.12) / 0.4), u = Math.min(W, H);
  const lift = i => (1 - st(i)) * u * 0.05;
  ctx.textAlign = 'center';
  ctx.globalAlpha = st(0); drawMark(ctx, kit, W / 2, H * 0.3 + lift(0), u * 0.3, lt / 0.9, c.text || '#ffffff');
  ctx.globalAlpha = st(1); ctx.fillStyle = c.text || '#fff'; ctx.font = fontOf(kit, 'heading', 800, u * 0.085, s.title); ctx.fillText(s.title || kit.fullName || '', W / 2, H * 0.47 + lift(1), W * 0.86);
  if (s.sub) { ctx.globalAlpha = st(2) * 0.9; ctx.font = fontOf(kit, 'body', 500, u * 0.04, s.sub); ctx.fillText(s.sub, W / 2, H * 0.47 + u * 0.075 + lift(2), W * 0.86); }
  // CTA pill
  const cta = s.cta || 'Shop now'; ctx.globalAlpha = st(3);
  ctx.font = fontOf(kit, 'body', 800, u * 0.05, cta); const tw = Math.min(W * 0.84, ctx.measureText(cta).width + u * 0.12), ph = u * 0.11, px = (W - tw) / 2, py = H * 0.6 + lift(3);
  const pulse = 1 + 0.03 * Math.sin(lt * 6) * (lt > 0.9 ? 1 : 0);
  ctx.save(); ctx.translate(W / 2, py + ph / 2); ctx.scale(pulse, pulse); ctx.translate(-W / 2, -(py + ph / 2));
  ctx.fillStyle = c.accent || '#ffd166'; ctx.beginPath(); roundRect(ctx, px, py, tw, ph, ph / 2); ctx.fill();
  ctx.fillStyle = c.ink || c.secondary || '#111'; ctx.textBaseline = 'middle'; ctx.fillText(cta, W / 2, py + ph / 2 + 2, tw - u * 0.06); ctx.restore();
  ctx.textBaseline = 'alphabetic';
  if (s.contact) { ctx.globalAlpha = st(4); ctx.fillStyle = c.text || '#fff'; ctx.font = fontOf(kit, 'body', 600, u * 0.038, s.contact); ctx.fillText(s.contact, W / 2, H * 0.6 + ph + u * 0.09 + lift(4), W * 0.86); }
  const pays = (s.payments || []).map(imageOf).filter(Boolean);
  if (pays.length) {
    ctx.globalAlpha = st(5); const bh = u * 0.07; let total = 0; const ws = pays.map(i => bh * (i.naturalWidth / i.naturalHeight || 3)); total = ws.reduce((a, b) => a + b, 0) + (pays.length - 1) * bh * 0.4;
    let x = (W - total) / 2; const y = H * 0.82 + lift(5);
    ctx.fillStyle = 'rgba(255,255,255,0.92)'; ctx.beginPath(); roundRect(ctx, x - bh * 0.4, y - bh * 0.3, total + bh * 0.8, bh * 1.6, bh * 0.4); ctx.fill();
    pays.forEach((img, i) => { ctx.drawImage(img, x, y, ws[i], bh); x += ws[i] + bh * 0.4; });
  }
});

export const LAYER_TYPES = ['auto.transition', 'auto.pricetag', 'auto.logo', 'auto.endcard'];
export const FX_TYPES = ['auto.punch'];
