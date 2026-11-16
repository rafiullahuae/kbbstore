// KBB Studio · cutaways — slides: a full-frame card made in the slide designer, drawn from item data only (so the
// preview and the export are the same pixels).
//
// slide = { theme, title, bullets:[..], price, offer, icon (emoji), image (studio path: a product shot) , logo (studio path | null), bg: { kind:'brand'|'solid'|
//           'image', c1, c2, src? }, colors: { primary, secondary, accent, text, bg }, font, anim: 'fade'|'rise'|'pop'|'type' }
import { roundRect, wrapText, fontString } from '../core/render.js';

export const THEMES = {
  brand: { label: 'Brand', bg: 'gradient', card: false },
  bold: { label: 'Bold', bg: 'solid-primary', card: false },
  minimal: { label: 'Minimal', bg: 'light', card: true },
  dark: { label: 'Dark', bg: 'dark', card: true },
  offer: { label: 'Offer', bg: 'gradient', card: true },
};
export const ANIMS = { fade: 'Fade in', rise: 'Rise up', pop: 'Pop', type: 'Typewriter' };

const IMAGES = new Map();   // studio path -> HTMLImageElement | ImageBitmap (decoded once)
export const slideImageSrcs = (s) => [s?.logo, s?.image, s?.bg?.kind === 'image' ? s.bg.src : null].filter(Boolean);
/** decode a slide's pictures (the export awaits this through registerPrepare; the editor before drawing) */
export async function loadSlideImages(slide) {
  await Promise.all(slideImageSrcs(slide).map(async (src) => {
    if (IMAGES.has(src)) return IMAGES.get(src);
    const p = (async () => { const r = await fetch('/' + src.split('/').map(encodeURIComponent).join('/')); if (!r.ok) throw new Error(src); return createImageBitmap(await r.blob()); })();
    IMAGES.set(src, p);
    try { IMAGES.set(src, await p); } catch { IMAGES.delete(src); }
  }));
}
const img = (src) => { const v = src && IMAGES.get(src); return v && !(v instanceof Promise) ? v : null; };

export function defaultSlide(ws, extra = {}) {
  const c = ws?.colors || {};
  return {
    theme: 'brand', title: 'Your headline', bullets: [], price: '', offer: '', icon: '', logo: ws?.logo || null,
    bg: { kind: 'brand' }, colors: { primary: c.primary || '#e8508b', secondary: c.secondary || '#1d1d24', accent: c.accent || '#ffd166', text: c.text || '#ffffff', bg: c.bg || '#000000' },
    font: ws?.fonts?.heading || 'system-ui', anim: 'rise', ...extra,
  };
}

const ease = (x) => 1 - Math.pow(1 - Math.max(0, Math.min(1, x)), 3);
/** opacity/offset/scale of element k (0 = title) at local time u (s) */
function enter(anim, k, u) {
  const f = ease((u - 0.12 * k) / 0.45);
  if (anim === 'rise') return { a: f, dy: (1 - f) * 60, s: 1 };
  if (anim === 'pop') { const b = Math.max(0, Math.min(1, (u - 0.1 * k) / 0.35)); return { a: Math.min(1, b * 2), dy: 0, s: b < 1 ? 0.6 + 0.4 * ease(b) + Math.sin(b * Math.PI) * 0.08 : 1 }; }
  if (anim === 'type') return { a: 1, dy: 0, s: 1, chars: Math.max(0, (u - 0.25 * k) * 28) };
  return { a: f, dy: 0, s: 1 };
}

/** draw a slide filling w×h at local time u (s into the cutaway); env.scale for blur radii */
export function drawSlide(ctx, s, w, h, u, env = {}) {
  const C = s.colors || {}, th = THEMES[s.theme] || THEMES.brand, k = Math.min(w, h) / 1080;
  // background
  const bgImg = s.bg?.kind === 'image' ? img(s.bg.src) : null;
  if (bgImg) {
    const sc = Math.max(w / bgImg.width, h / bgImg.height), z = 1 + 0.06 * Math.min(1, u / 4);
    const dw = bgImg.width * sc * z, dh = bgImg.height * sc * z;
    ctx.drawImage(bgImg, (w - dw) / 2, (h - dh) / 2, dw, dh);
    ctx.fillStyle = 'rgba(0,0,0,0.38)'; ctx.fillRect(0, 0, w, h);
  } else if (s.bg?.kind === 'solid') { ctx.fillStyle = s.bg.c1 || C.primary; ctx.fillRect(0, 0, w, h); }
  else if (th.bg === 'light') { ctx.fillStyle = '#f7f5f2'; ctx.fillRect(0, 0, w, h); }
  else if (th.bg === 'dark') { ctx.fillStyle = '#121216'; ctx.fillRect(0, 0, w, h); }
  else if (th.bg === 'solid-primary') { ctx.fillStyle = C.primary; ctx.fillRect(0, 0, w, h); }
  else {
    const g = ctx.createLinearGradient(0, 0, w * 0.4, h);
    g.addColorStop(0, s.bg?.c1 || C.primary); g.addColorStop(1, s.bg?.c2 || C.secondary);
    ctx.fillStyle = g; ctx.fillRect(0, 0, w, h);
    // slow light sweep (animated, deterministic in u)
    const x = ((u * 0.25) % 1.6 - 0.3) * w, sw = ctx.createLinearGradient(x - w * 0.3, 0, x + w * 0.3, h * 0.4);
    sw.addColorStop(0, 'rgba(255,255,255,0)'); sw.addColorStop(0.5, 'rgba(255,255,255,0.10)'); sw.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = sw; ctx.fillRect(0, 0, w, h);
  }
  const light = th.bg === 'light' && !bgImg;
  const ink = light ? (C.secondary || '#1d1d24') : (C.text || '#ffffff');
  const pad = Math.round(w * 0.09), boxW = w - pad * 2;
  // card
  if (th.card) {
    ctx.save(); ctx.fillStyle = light ? '#ffffff' : 'rgba(255,255,255,0.08)';
    ctx.shadowColor = 'rgba(0,0,0,0.25)'; ctx.shadowBlur = 40 * k * (env.scale || 1);
    ctx.beginPath(); roundRect(ctx, pad * 0.6, h * 0.2, w - pad * 1.2, h * 0.6, 36 * k); ctx.fill(); ctx.restore();
  }
  const font = s.font || 'system-ui';
  const blocks = [];
  const hero = img(s.image);
  if (hero) { const hh = h * (w > h ? 0.42 : 0.3), hw = Math.min(boxW, hero.width * hh / hero.height); blocks.push({ kind: 'image', pic: hero, iw: hw, ih: hw * hero.height / hero.width }); }
  if (s.icon) blocks.push({ kind: 'icon', text: s.icon, size: 150 * k });
  if (s.title) blocks.push({ kind: 'title', text: s.title, size: (w > h ? 92 : 104) * k, weight: 900 });
  for (const b of (s.bullets || []).filter(Boolean).slice(0, 5)) blocks.push({ kind: 'bullet', text: b, size: 54 * k, weight: 600 });
  if (s.price) blocks.push({ kind: 'price', text: s.price, size: 96 * k, weight: 900 });
  if (s.offer) blocks.push({ kind: 'offer', text: s.offer, size: 50 * k, weight: 800 });
  // measure
  let total = 0;
  for (const b of blocks) {
    if (b.kind === 'image') { b.lines = []; b.lh = 0; b.hgt = b.ih; total += b.hgt + 28 * k; continue; }
    if (b.kind === 'icon') { b.lines = [b.text]; b.lh = b.size * 1.1; }
    else {
      ctx.font = fontString({ font, size: b.size, weight: b.weight });
      b.lines = wrapText(ctx, b.kind === 'bullet' ? '•  ' + b.text : b.text, b.kind === 'price' ? boxW * 0.8 : boxW);
      b.lh = b.size * (b.kind === 'title' ? 1.08 : 1.25);
    }
    b.hgt = b.lines.length * b.lh + (b.kind === 'price' ? b.size * 0.5 : 0);
    total += b.hgt + 28 * k;
  }
  let y = (h - total) / 2 + (s.logo ? 40 * k : 0);
  blocks.forEach((b, i) => {
    const e = enter(s.anim, i, u);
    ctx.save(); ctx.globalAlpha *= e.a;
    const cx = w / 2, cy = y + b.hgt / 2 + e.dy;
    ctx.translate(cx, cy); ctx.scale(e.s, e.s); ctx.translate(-cx, -cy);
    ctx.textAlign = b.kind === 'bullet' ? 'left' : 'center'; ctx.textBaseline = 'middle';
    if (b.kind === 'image') ctx.drawImage(b.pic, cx - b.iw / 2, y + e.dy, b.iw, b.ih);
    else if (b.kind === 'icon') { ctx.font = `${Math.round(b.size)}px "Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif`; ctx.fillText(b.text, cx, y + b.lh / 2 + e.dy); }
    else {
      ctx.font = fontString({ font, size: b.size, weight: b.weight });
      if (b.kind === 'price') {
        const tw = Math.min(boxW, Math.max(...b.lines.map((l) => ctx.measureText(l).width)) + b.size * 1.2), bh = b.hgt;
        ctx.fillStyle = C.accent || '#ffd166'; ctx.beginPath(); roundRect(ctx, cx - tw / 2, y + e.dy, tw, bh, bh / 2); ctx.fill();
      }
      ctx.fillStyle = b.kind === 'price' ? (C.secondary || '#111') : b.kind === 'offer' ? (light ? C.primary : C.accent || '#ffd166') : ink;
      let cy0 = y + (b.kind === 'price' ? b.size * 0.25 : 0) + b.lh / 2 + e.dy, left = 0;
      for (const ln of b.lines) {
        let t = ln;
        if (e.chars != null) { t = ln.slice(0, Math.max(0, Math.floor(e.chars - left))); left += ln.length; }
        ctx.fillText(t, b.kind === 'bullet' ? pad + (th.card ? 10 * k : 0) : cx, cy0);
        cy0 += b.lh;
      }
    }
    ctx.restore();
    y += b.hgt + 28 * k;
  });
  // logo (top centre)
  const L = img(s.logo);
  if (L) {
    const lh = 110 * k, lw = Math.min(w * 0.5, L.width * lh / L.height);
    ctx.save(); ctx.globalAlpha *= ease(u / 0.4); ctx.drawImage(L, (w - lw) / 2, h * 0.08, lw, lw * L.height / L.width); ctx.restore();
  }
}
