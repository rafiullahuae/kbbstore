// KBB Studio · cutaways — the 'cutaway' layer (core/render.js): ONE draw path for the preview and the export.
//   fill   the picture covers the frame, slow Ken Burns push-in
//   blur   the whole picture fitted, over a blurred, darkened copy of itself
//   pip    the picture full frame, the speaker (the clip under it, with its own look) in a round corner bubble
//   split  picture and speaker half and half (top/bottom on tall frames, left/right on wide ones)
// In/out transitions (dissolve, slide, zoom, whip, wipe, flash) are drawn here too, over style.tdur seconds.
import { registerLayer, registerPrepare, drawItem } from '../core/render.js';
import { srcTime, itemDur, isActive } from '../core/project.js';
import { TYPE } from './model.js';
import { drawSlide, loadSlideImages } from './slide.js';

const ease = (x) => (x <= 0 ? 0 : x >= 1 ? 1 : x < 0.5 ? 2 * x * x : 1 - Math.pow(-2 * x + 2, 2) / 2);

/** the talking clip under the cutaway at t (video track, top-most first) */
function speakerAt(project, t) {
  const tracks = project.tracks.filter((tr) => tr.kind === 'video' && !tr.hidden);
  for (let i = tracks.length - 1; i >= 0; i--) for (const it of tracks[i].items) if (it.type === 'clip' && it.media && isActive(it, t)) return { track: tracks[i], item: it };
  return null;
}

/** draw the picture (media or slide) into a w×h box: cover, or contain over a blurred cover */
function drawPicture(ctx, item, t, project, env, w, h, mode) {
  const st = item.style || {}, u = t - item.t0;
  if (st.slide) { drawSlide(ctx, st.slide, w, h, u, env); return true; }
  const src = env.source(item, srcTime(item, t));
  const sw = src && (src.videoWidth || src.displayWidth || src.naturalWidth || src.width), sh = src && (src.videoHeight || src.displayHeight || src.naturalHeight || src.height);
  if (!sw || !sh) { if (env.preview) { ctx.fillStyle = '#1b1b22'; ctx.fillRect(0, 0, w, h); } return false; }
  const cover = Math.max(w / sw, h / sh), contain = Math.min(w / sw, h / sh);
  if (mode === 'blur') {
    ctx.save();
    ctx.filter = `blur(${Math.round(36 * (env.scale || 1))}px) brightness(0.6)`;
    const k = cover * 1.12; ctx.drawImage(src, (w - sw * k) / 2, (h - sh * k) / 2, sw * k, sh * k);
    ctx.restore();
    ctx.drawImage(src, (w - sw * contain) / 2, (h - sh * contain) / 2, sw * contain, sh * contain);
    return true;
  }
  const f = Math.min(1, Math.max(0, u / Math.max(0.001, itemDur(item))));
  const z = 1 + (st.kb ?? 0.08) * ease(f), k = cover * z;
  ctx.drawImage(src, (w - sw * k) / 2, (h - sh * k) / 2, sw * k, sh * k);
  return true;
}

/** the speaker clip drawn into the rect (x,y,w,h) as a cover crop centred on the upper third (where a face is) */
function drawSpeaker(ctx, project, t, env, x, y, w, h) {
  const sp = speakerAt(project, t); if (!sp) return;
  const it = sp.item, bw = it.w, bh = it.h;
  const k = Math.max(w / bw, h / bh) * 1.05;
  const cx = it.x + bw / 2, cy = it.y + bh * 0.4;
  ctx.save();
  ctx.translate(x + w / 2, y + h / 2); ctx.scale(k, k); ctx.translate(-cx, -cy);
  drawItem(ctx, it, t, project, Object.assign({}, env, { track: sp.track }));
  ctx.restore();
}

function drawBody(ctx, item, t, project, env) {
  const { w, h } = env.box, st = item.style || {};
  const fit = st.fit || 'fill';
  if (fit === 'split') {
    const tall = h >= w, hw = tall ? w : w / 2, hh = tall ? h / 2 : h;
    ctx.save(); ctx.beginPath(); ctx.rect(0, 0, hw, hh); ctx.clip(); drawPicture(ctx, item, t, project, env, hw, hh, 'fill'); ctx.restore();
    ctx.save(); ctx.beginPath(); ctx.rect(tall ? 0 : hw, tall ? hh : 0, hw, hh); ctx.clip(); ctx.fillStyle = '#000'; ctx.fillRect(tall ? 0 : hw, tall ? hh : 0, hw, hh);
    drawSpeaker(ctx, project, t, env, tall ? 0 : hw, tall ? hh : 0, hw, hh); ctx.restore();
    ctx.fillStyle = 'rgba(255,255,255,0.9)'; if (tall) ctx.fillRect(0, hh - 3, w, 6); else ctx.fillRect(hw - 3, 0, 6, h);
    return;
  }
  drawPicture(ctx, item, t, project, env, w, h, fit === 'blur' ? 'blur' : 'fill');
  if (fit === 'pip') {
    const r = Math.min(w, h) * 0.17, m = Math.min(w, h) * 0.06, c = st.corner || 'tr';
    const bx = c.includes('l') ? m + r : w - m - r, by = c.includes('t') ? h * 0.1 + r : h * 0.62 - r;
    ctx.save(); ctx.beginPath(); ctx.arc(bx, by, r, 0, Math.PI * 2); ctx.clip(); ctx.fillStyle = '#000'; ctx.fillRect(bx - r, by - r, 2 * r, 2 * r);
    drawSpeaker(ctx, project, t, env, bx - r, by - r, 2 * r, 2 * r); ctx.restore();
    ctx.save(); ctx.strokeStyle = '#ffffff'; ctx.lineWidth = Math.max(4, r * 0.06); ctx.beginPath(); ctx.arc(bx, by, r, 0, Math.PI * 2); ctx.stroke(); ctx.restore();
  }
}

/** progress 0..1 of the in/out transition at t (1 = fully on) and which */
function phase(item, t) {
  const st = item.style || {}, d = Math.max(0, st.tdur ?? 0.25), u = t - item.t0, left = itemDur(item) - u;
  if (d > 0 && st.tin && st.tin !== 'none' && u < d) return { kind: st.tin, p: ease(u / d), dir: 1 };
  if (d > 0 && st.tout && st.tout !== 'none' && left < d) return { kind: st.tout, p: ease(left / d), dir: -1 };
  return null;
}

registerLayer(TYPE, (ctx, item, t, project, env) => {
  const { w, h } = env.box, ph = phase(item, t);
  if (!ph) return drawBody(ctx, item, t, project, env);
  const p = ph.p;
  ctx.save();
  switch (ph.kind) {
    case 'slide': ctx.translate((1 - p) * w * ph.dir, 0); break;
    case 'whip': ctx.translate((1 - p) * w * 1.2 * ph.dir, 0); ctx.filter = `blur(${Math.round((1 - p) * 24 * (env.scale || 1))}px)`; break;
    case 'zoom': { const s = 0.7 + 0.3 * p; ctx.globalAlpha *= p; ctx.translate(w / 2, h / 2); ctx.scale(s, s); ctx.translate(-w / 2, -h / 2); break; }
    case 'wipe': ctx.beginPath(); ph.dir > 0 ? ctx.rect(0, 0, w * p, h) : ctx.rect(w * (1 - p), 0, w * p, h); ctx.clip(); break;
    case 'flash': break;
    default: ctx.globalAlpha *= p;   // dissolve
  }
  drawBody(ctx, item, t, project, env);
  ctx.restore();
  if (ph.kind === 'flash') { ctx.save(); ctx.globalAlpha *= Math.max(0, 1 - Math.abs(p - 0.5) * 2) * 0.9; ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h); ctx.restore(); }
});

// slides' logos and background photos are decoded before the export's first frame (and when a project opens)
registerPrepare('cutaways', async (project) => {
  const list = [];
  for (const tr of project.tracks) for (const it of tr.items) if (it.type === TYPE && it.style?.slide) list.push(loadSlideImages(it.style.slide));
  await Promise.all(list);
});
