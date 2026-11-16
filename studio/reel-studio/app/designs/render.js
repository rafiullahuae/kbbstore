// KBB Studio · Designs · THE RENDERER: one canvas code path for the grid thumbnails, the editor, the rule checker and
// the PNG/JPG export (full HD and 4K), so what is scored is exactly what is exported.
//
// A design (D):
//   { v, id, name, workspace, family, sizes:[...], palette:{bg,bg2,ink,sub,acc,acc2,onAcc,accText,card,cardInk,deco},
//     art:{base, layers, seed} (art.js), bgImage:{src, op, fit}|null, brand:{logo, logoLight, name},
//     fonts:{head, body, ar, display}, els:[El], geo:{story:{id:G}, post:{...}, square:{...}} }
//   El: {id, t:'text'|'btn'|'shape'|'icon'|'prod'|'img'|'logo', role, name, txt, font:'head'|'body'|'ar'|'display'|'serif',
//        fw, lh, ls, up, al, pc (palette key) | col (hex), plate:{pc|col, op, rad, pad}, fill(pc key|hex), sh, rad, op,
//        ic, style, src, fit, mask, tint, maxLines, deco (true = decoration: may sit under/over others)}
//   G : {x, y, w, h, fs, al, r, hd}  -- canvas px for that size (1080 wide); text boxes grow downwards from y
import { SIZES } from './lib/brief.js';
import { drawArt } from './art.js';
import { iconImage } from './icons.js';
import { rgba, contrast } from './lib/color.js';

export const PX = Object.fromEntries(Object.entries(SIZES).map(([k, v]) => [k, [v.w, v.h]]));

// ── fonts (offline: the kits' own files and the studio's OFL caption fonts) ──
const FONT_FILES = {
  'Playfair Display': [['brands/kbb/fonts/playfair-display.woff2', '400 900']],
  Poppins: [['brands/kbb/fonts/poppins-400.woff2', '400'], ['brands/kbb/fonts/poppins-600.woff2', '600'], ['brands/kbb/fonts/poppins-700.woff2', '700']],
  Cairo: [['vendor/caption-fonts/cairo-arabic-wght-normal.woff2', '200 1000'], ['vendor/caption-fonts/cairo-latin-wght-normal.woff2', '200 1000']],
  Afacad: [['brands/planet-careers/fonts/afacad-latin.woff2', '400 700'], ['brands/planet-careers/fonts/afacad-latin-ext.woff2', '400 700']],
  Montserrat: [['vendor/caption-fonts/montserrat-latin-wght-normal.woff2', '100 900']],
  'Bebas Neue': [['vendor/caption-fonts/bebas-neue-latin-400-normal.woff2', '400']],
  Anton: [['vendor/caption-fonts/anton-latin-400-normal.woff2', '400']],
  Inter: [['vendor/caption-fonts/inter-latin-wght-normal.woff2', '100 900']],
  Tajawal: [['vendor/caption-fonts/tajawal-arabic-700-normal.woff2', '700'], ['vendor/caption-fonts/tajawal-latin-700-normal.woff2', '700'], ['vendor/caption-fonts/tajawal-arabic-400-normal.woff2', '400']],
  Amiri: [['vendor/caption-fonts/amiri-arabic-700-normal.woff2', '700'], ['vendor/caption-fonts/amiri-latin-700-normal.woff2', '700']],
};
export const FONT_LIST = Object.keys(FONT_FILES);
const base = new URL('../../', import.meta.url);
const fontLoads = new Map();
export function loadFont(family) {
  if (!FONT_FILES[family]) return Promise.resolve(false);
  if (!fontLoads.has(family)) {
    fontLoads.set(family, Promise.all(FONT_FILES[family].map(async ([file, weight]) => {
      const f = new FontFace(family, `url(${new URL(file, base).href})`, { weight, display: 'block' });
      document.fonts.add(await f.load());
    })).then(() => true).catch((e) => { console.warn('[designs] font', family, e.message); return false; }));
  }
  return fontLoads.get(family);
}
const STACK = { head: 'serif', body: 'sans-serif', ar: 'sans-serif', display: 'sans-serif', serif: 'serif' };
export function fontOf(D, el) {
  const isAr = /[؀-ۿ]/.test(el.txt || '');
  const fam = isAr ? D.fonts.ar : el.font === 'display' ? D.fonts.display : el.font === 'serif' ? D.fonts.serif || D.fonts.head : el.font === 'head' ? D.fonts.head : D.fonts.body;
  return `"${fam}", ${STACK[el.font] || 'sans-serif'}`;
}

// ── images (studio paths are served by the engine; data:/blob: URLs as they are) ──
const imgs = new Map();
export const urlOf = (src) => (/^(data|blob|https?):/.test(src) ? src : '/' + String(src).split('/').map(encodeURIComponent).join('/'));
export function loadImage(src, tint) {
  const key = src + '|' + (tint ? JSON.stringify(tint) : '');
  if (!imgs.has(key)) {
    imgs.set(key, (async () => {
      let url = urlOf(src);
      if (/\.svg($|\?)/i.test(src)) {
        // SVGs: give them an explicit size from their viewBox (aspect ratio for "contain") and recolour on request
        let txt = await (await fetch(url)).text();
        const vb = /viewBox="\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/.exec(txt);
        if (vb) txt = txt.replace(/<svg\b([^>]*)>/, (m, a) => `<svg${a.replace(/\s(width|height)="[^"]*"/g, '')} width="${+vb[3] * 4}" height="${+vb[4] * 4}">`);
        if (tint) for (const [from, to] of Object.entries(tint)) txt = txt.split(from).join(to);
        url = URL.createObjectURL(new Blob([txt], { type: 'image/svg+xml' }));
      }
      const im = new Image(); im.decoding = 'async'; im.src = url;
      await im.decode();
      return im;
    })().catch((e) => { imgs.delete(key); throw e; }));
  }
  return imgs.get(key);
}
const ready = new Map(); // key -> HTMLImageElement once loaded (sync access while drawing)
async function want(src, tint) { const key = src + '|' + (tint ? JSON.stringify(tint) : ''); if (!ready.has(key)) ready.set(key, await loadImage(src, tint)); return ready.get(key); }
const got = (src, tint) => ready.get(src + '|' + (tint ? JSON.stringify(tint) : ''));
const iconKey = (el, D) => el.ic + '|' + el.style + '|' + colorOf(D, el.pc, el.col) + '|' + colorOf(D, el.bgPc || 'acc', el.bgCol);

/** load everything a design needs (fonts, pictures, icons) -- call before layout/draw */
export async function prepare(D) {
  const fams = new Set([D.fonts.head, D.fonts.body, D.fonts.ar, D.fonts.display, D.fonts.serif].filter(Boolean));
  await Promise.all([...fams].map(loadFont));
  const jobs = [];
  if (D.bgImage?.src) jobs.push(want(D.bgImage.src));
  for (const el of D.els) {
    if ((el.t === 'prod' || el.t === 'img') && el.src) jobs.push(want(el.src, el.tint).catch((e) => console.warn('[designs] image', el.src, e.message)));
    if (el.t === 'logo') { const s = logoSrc(D, el); if (s) jobs.push(want(s).catch(() => {})); }
    if (el.t === 'icon') jobs.push(iconImage(el.ic, el.style, { fg: colorOf(D, el.pc, el.col), bg: colorOf(D, el.bgPc || 'acc', el.bgCol), accent: colorOf(D, 'acc') }).then((im) => ready.set('icon:' + iconKey(el, D), im)));
  }
  await Promise.all(jobs);
}
export const colorOf = (D, pc, col) => (col && /^#[0-9a-f]{6}/i.test(col) ? col.slice(0, 7) : (pc && D.palette[pc]) || D.palette.ink);
export function logoSrc(D, el) {
  const light = el.light ?? contrast('#FFFFFF', colorOf(D, el.pc || 'ink')) < 2;
  return el.variant === 'mark' ? (light ? D.brand.markLight || D.brand.mark : D.brand.mark) : (light ? D.brand.logoLight || D.brand.logo : D.brand.logo);
}

// ── text layout ──
let mctx = null;
const measureCtx = () => (mctx ||= (typeof OffscreenCanvas === 'function' ? new OffscreenCanvas(8, 8) : document.createElement('canvas')).getContext('2d'));
const fontStr = (D, el, fs) => `${el.fi ? 'italic ' : ''}${el.fw || 700} ${fs}px ${fontOf(D, el)}`;
function wrap(ctx, text, maxW, ls) {
  const out = [];
  for (const para of String(text).split('\n')) {
    const words = para.split(/\s+/).filter(Boolean);
    let line = '';
    for (const w of words) {
      const t = line ? line + ' ' + w : w;
      if (line && width(ctx, t, ls) > maxW) { out.push(line); line = w; } else line = t;
    }
    out.push(line);
  }
  return out;
}
const width = (ctx, s, ls) => ctx.measureText(s).width + (ls ? ls * Math.max(0, [...s].length - 1) : 0);

/** a text element's lines, size and real box inside its geometry (shrinks to fit width, height and maxLines) */
export function layoutText(D, el, g) {
  const ctx = measureCtx();
  const txt = el.up ? String(el.txt || '').toUpperCase() : String(el.txt || '');
  let fs = g.fs || 40, lines = [], w = 0;
  const pad = el.plate ? (el.plate.pad ?? 0.45) : 0;
  for (let i = 0; i < 40; i++) {
    ctx.font = fontStr(D, el, fs);
    const ls = (el.ls || 0) / 100 * fs, padX = pad * fs * 1.6, padY = pad * fs;
    const inner = Math.max(10, g.w - 2 * padX);
    lines = wrap(ctx, txt, inner, ls);
    w = Math.max(0, ...lines.map((l) => width(ctx, l, ls)));
    const h = lines.length * fs * (el.lh || 1.15) + 2 * padY;
    const tooWide = w > inner + 0.5, tooTall = g.h && h > g.h + 0.5, tooMany = el.maxLines && lines.length > el.maxLines;
    if (!(tooWide || tooTall || tooMany) || fs <= (g.fs || 40) * 0.35) break;
    fs *= 0.94;
  }
  ctx.font = fontStr(D, el, fs);
  const ls = (el.ls || 0) / 100 * fs, padX = pad * fs * 1.6, padY = pad * fs;
  const lh = fs * (el.lh || 1.15);
  const bw = w + 2 * padX, bh = lines.length * lh + 2 * padY;
  const al = g.al || el.al || 'c';
  const bx = al === 'l' ? g.x : al === 'r' ? g.x + g.w - bw : g.x + (g.w - bw) / 2;
  const by = g.h && el.valign !== 'top' ? g.y + (g.h - bh) / 2 : g.y;
  return { fs, lh, ls, lines: lines.map((t) => ({ t, w: width(ctx, t, ls) })), box: { x: bx, y: by, w: bw, h: bh }, padX, padY, al };
}

/** every visible element with its real box for one size, in drawing order */
export function layout(D, size) {
  const geo = D.geo[size] || {};
  const out = [];
  for (const el of D.els) {
    const g = geo[el.id];
    if (!g || g.hd || el.hide) continue;
    let box = { x: g.x, y: g.y, w: g.w, h: g.h || 0 }, text = null;
    if (el.t === 'text' || el.t === 'btn') {
      if (el.t === 'btn') { text = layoutText(D, { ...el, plate: null }, { ...g, w: g.w - (g.fs || 40) * 1.4 }); box = { x: g.x, y: g.y, w: g.w, h: g.h }; text.box = { ...box }; }
      else { text = layoutText(D, el, g); box = text.box; }
    } else if (el.t === 'prod' || el.t === 'img' || el.t === 'logo') {
      const src = el.t === 'logo' ? logoSrc(D, el) : el.src, im = src ? got(src, el.tint) : null;
      if (im && (el.fit || 'contain') === 'contain') {
        const a = im.naturalWidth / im.naturalHeight, ga = g.w / g.h;
        const w = a > ga ? g.w : g.h * a, h = a > ga ? g.w / a : g.h;
        const ax = el.ax ?? (g.al === 'l' ? 0 : g.al === 'r' ? 1 : 0.5), ay = el.ay ?? (el.t === 'prod' ? 1 : 0.5);
        box = { x: g.x + (g.w - w) * ax, y: g.y + (g.h - h) * ay, w, h };
      }
    }
    out.push({ el, g, box, text });
  }
  return out;
}

// ── drawing ──
function roundRect(c, x, y, w, h, r) { r = Math.max(0, Math.min(r, w / 2, h / 2)); c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); }
function shapePath(c, sh, x, y, w, h, rad) {
  if (sh === 'circle' || sh === 'ellipse') { c.beginPath(); c.ellipse(x + w / 2, y + h / 2, w / 2, h / 2, 0, 0, Math.PI * 2); }
  else if (sh === 'arch') { c.beginPath(); c.moveTo(x, y + h); c.lineTo(x, y + w / 2); c.arc(x + w / 2, y + w / 2, w / 2, Math.PI, 0); c.lineTo(x + w, y + h); c.closePath(); }
  else if (sh === 'pill') roundRect(c, x, y, w, h, h / 2);
  else if (sh === 'diag') { c.beginPath(); c.moveTo(x, y + h * 0.25); c.lineTo(x + w, y); c.lineTo(x + w, y + h * 0.75); c.lineTo(x, y + h); c.closePath(); }
  else if (sh === 'blob') { c.beginPath(); const cx = x + w / 2, cy = y + h / 2; for (let k = 0; k <= 12; k++) { const a = (k / 12) * Math.PI * 2, r = 0.5 + 0.06 * Math.sin(a * 3 + 1); const px = cx + Math.cos(a) * w * r, py = cy + Math.sin(a) * h * r; k ? c.lineTo(px, py) : c.moveTo(px, py); } c.closePath(); }
  else roundRect(c, x, y, w, h, rad || 0);
}
function fillFor(c, D, el, x, y, w, h) {
  const f = colorOf(D, el.fill || 'acc', el.fillCol);
  if (el.grad) { const g = c.createLinearGradient(x, y, x + w, y + h); g.addColorStop(0, f); g.addColorStop(1, colorOf(D, el.grad, el.gradCol)); return g; }
  return f;
}

/**
 * draw one size into ctx (ctx already sized W*scale x H*scale). opts: {scale, only:(item)=>bool, under:id (draw only what
 * lies beneath that element: for contrast checks), noText}
 */
export function draw(ctx, D, size, opts = {}) {
  const [W, H] = PX[size], s = opts.scale || 1;
  ctx.save(); ctx.scale(s, s);
  drawArt(ctx, W, H, D.art, D.palette);
  if (D.bgImage?.src) {
    const im = got(D.bgImage.src);
    if (im) {
      const a = im.naturalWidth / im.naturalHeight, r = W / H;
      const sw = a > r ? im.naturalHeight * r : im.naturalWidth, sh = a > r ? im.naturalHeight : im.naturalWidth / r;
      ctx.save(); ctx.globalAlpha = D.bgImage.op ?? 1;
      ctx.drawImage(im, (im.naturalWidth - sw) * (D.bgImage.fx ?? 0.5), (im.naturalHeight - sh) * (D.bgImage.fy ?? 0.5), sw, sh, 0, 0, W, H);
      ctx.restore();
      if (D.bgImage.scrim) { const g = ctx.createLinearGradient(0, H * D.bgImage.scrim[0], 0, H); g.addColorStop(0, rgba(D.palette.bg, 0)); g.addColorStop(0.55, rgba(D.palette.bg, 0.92)); g.addColorStop(1, rgba(D.palette.bg, 1)); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H); }
    }
  }
  const items = opts.items || layout(D, size);
  for (const it of items) {
    if (opts.under && it.el.id === opts.under) break;
    if (opts.only && !opts.only(it)) continue;
    drawItem(ctx, D, it, opts);
  }
  ctx.restore();
}

export function drawItem(ctx, D, { el, g, box, text }, opts = {}) {
  ctx.save();
  ctx.globalAlpha = (el.op ?? 100) / 100;
  if (g.r) { const cx = box.x + box.w / 2, cy = box.y + box.h / 2; ctx.translate(cx, cy); ctx.rotate((g.r * Math.PI) / 180); ctx.translate(-cx, -cy); }
  if (el.t === 'shape') {
    shapePath(ctx, el.sh, g.x, g.y, g.w, g.h, el.rad);
    if (el.shadow) { ctx.shadowColor = 'rgba(0,0,0,.18)'; ctx.shadowBlur = 40; ctx.shadowOffsetY = 14; }
    if (el.fill !== 'none') { ctx.fillStyle = fillFor(ctx, D, el, g.x, g.y, g.w, g.h); ctx.fill(); }
    ctx.shadowColor = 'transparent';
    if (el.stroke) { ctx.strokeStyle = colorOf(D, el.stroke, el.strokeCol); ctx.lineWidth = el.sw || 3; ctx.stroke(); }
  } else if (el.t === 'text' || el.t === 'btn') {
    if (el.t === 'btn') {
      shapePath(ctx, el.sh || 'pill', g.x, g.y, g.w, g.h, el.rad ?? 16);
      ctx.shadowColor = 'rgba(0,0,0,.22)'; ctx.shadowBlur = 24; ctx.shadowOffsetY = 8;
      ctx.fillStyle = fillFor(ctx, D, { fill: el.fill || 'acc', fillCol: el.fillCol, grad: el.grad }, g.x, g.y, g.w, g.h); ctx.fill(); ctx.shadowColor = 'transparent';
    } else if (el.plate) {
      ctx.save(); ctx.globalAlpha *= el.plate.op ?? 1; roundRect(ctx, box.x, box.y, box.w, box.h, el.plate.rad ?? box.h / 2);
      ctx.fillStyle = colorOf(D, el.plate.pc || 'card', el.plate.col); ctx.fill(); ctx.restore();
    }
    if (!opts.noText) drawLines(ctx, D, el, text, el.t === 'btn' ? { x: g.x, y: g.y + (g.h - text.lines.length * text.lh) / 2 - text.padY, w: g.w } : box);
  } else if (el.t === 'icon') {
    const im = ready.get('icon:' + iconKey(el, D));
    if (im) ctx.drawImage(im, g.x, g.y, g.w, g.h || g.w);
  } else if (el.t === 'prod' || el.t === 'img' || el.t === 'logo') {
    const src = el.t === 'logo' ? logoSrc(D, el) : el.src, im = src ? got(src, el.tint) : null;
    if (im) {
      if (el.t === 'prod') { ctx.shadowColor = 'rgba(0,0,0,.28)'; ctx.shadowBlur = 36; ctx.shadowOffsetY = 18; }
      if ((el.fit || 'contain') === 'cover') {
        ctx.save(); shapePath(ctx, el.mask || 'rect', g.x, g.y, g.w, g.h, el.rad || 0); ctx.clip();
        const a = im.naturalWidth / im.naturalHeight, r = g.w / g.h;
        const sw = a > r ? im.naturalHeight * r : im.naturalWidth, sh = a > r ? im.naturalHeight : im.naturalWidth / r;
        ctx.drawImage(im, (im.naturalWidth - sw) * (el.fx ?? 0.5), (im.naturalHeight - sh) * (el.fy ?? 0.2), sw, sh, g.x, g.y, g.w, g.h);
        ctx.restore();
        if (el.ring) { shapePath(ctx, el.mask || 'rect', g.x, g.y, g.w, g.h, el.rad || 0); ctx.strokeStyle = colorOf(D, el.ring); ctx.lineWidth = el.ringW || 10; ctx.stroke(); }
      } else ctx.drawImage(im, box.x, box.y, box.w, box.h);
    } else if (el.t === 'logo') {
      const t = { ...el, txt: D.brand.name || '', font: 'head', fw: 700 };
      const L = layoutText(D, t, { ...g, fs: g.h * 0.6 }); drawLines(ctx, D, t, L, L.box);
    }
  }
  ctx.restore();
}

function drawLines(ctx, D, el, L, box) {
  ctx.font = fontStr(D, el, L.fs);
  ctx.fillStyle = colorOf(D, el.pc, el.col);
  ctx.textBaseline = 'alphabetic';
  const isAr = /[؀-ۿ]/.test(el.txt || '');
  ctx.direction = isAr ? 'rtl' : 'ltr';
  if ('letterSpacing' in ctx) ctx.letterSpacing = L.ls ? L.ls + 'px' : '0px';
  if (el.shadowText) { ctx.shadowColor = 'rgba(0,0,0,.35)'; ctx.shadowBlur = L.fs * 0.3; ctx.shadowOffsetY = L.fs * 0.06; }
  let y = box.y + L.padY + L.lh * 0.5 + L.fs * 0.36;
  for (const ln of L.lines) {
    const al = L.al;
    ctx.textAlign = 'left';
    const x = al === 'l' ? box.x + L.padX : al === 'r' ? box.x + box.w - L.padX - ln.w : box.x + (box.w - ln.w) / 2;
    // textAlign 'left' anchors the left edge of the run whatever the direction; rtl keeps the Arabic word order
    ctx.fillText(ln.t, x, y);
    y += L.lh;
  }
  if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
}

/** a canvas with the design at `scale` (1 = 1080 wide, full HD; 2 = 4K for a story) */
export function renderCanvas(D, size, scale = 1, opts = {}) {
  const [W, H] = PX[size];
  const c = document.createElement('canvas'); c.width = Math.round(W * scale); c.height = Math.round(H * scale);
  draw(c.getContext('2d'), D, size, { ...opts, scale });
  return c;
}
export const toBlob = (canvas, type = 'image/png', q = 0.95) => new Promise((res) => canvas.toBlob(res, type, q));
