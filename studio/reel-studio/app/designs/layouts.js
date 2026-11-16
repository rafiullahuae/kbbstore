// KBB Studio · Designs · LAYOUT FAMILIES (the Ad Studio's idea -- a family is a function of the format that returns the
// same element ids for every size, so one design has one set of texts/colours and a position per size -- ported to
// canvas px so the renderer, the rule checker and the export share it). Ten families, each a different composition:
//   spotlight · split · bignum · editorial · band · card · tiles · poster · arch · badge
// Every family places text only inside the platform-safe area, never over the hero picture (heroes get their own box),
// with one dominant headline, a button, and the small print last.
//
//   compose(family, size, ctx) -> [[El, G], ...]      ctx = {P, C, C2, spec, hero, icons, benefits, lang, measure, brand}
import { layoutText } from './render.js';

export const FAMILIES = ['spotlight', 'split', 'bignum', 'editorial', 'band', 'card', 'tiles', 'poster', 'arch', 'badge'];
export const FAMILY_NAMES = { spotlight: 'Spotlight', split: 'Split', bignum: 'Big number', editorial: 'Editorial frame', band: 'Diagonal band', card: 'Floating card', tiles: 'Tiles', poster: 'Poster', arch: 'Arch window', badge: 'Offer badge' };
/** the platform-safe area per size (IG/TikTok story chrome: top 250, bottom 340) */
export const SAFE = { story: { x0: 72, y0: 250, x1: 1008, y1: 1580 }, post: { x0: 64, y0: 64, x1: 1016, y1: 1286 }, square: { x0: 60, y0: 60, x1: 1020, y1: 1020 } };
export const MIN_TEXT = 24; // px at 1080 wide: nothing smaller (small print included)

const G = (x, y, w, h, fs, al) => ({ x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h || 0), fs: fs ? Math.round(fs * 10) / 10 : undefined, al });

// ── the text pieces, styled once (fonts/colours by role; sizes per family) ──
function pieces(ctx) {
  const { C, C2, spec } = ctx;
  const ar = ctx.lang === 'ar';
  const isAr = (s) => /[؀-ۿ]/.test(s || '');
  return {
    ar,
    kicker: C.kicker && { id: 'kicker', t: 'text', role: 'kicker', name: 'Kicker', txt: C.kicker, font: 'body', fw: 700, ls: isAr(C.kicker) ? 0 : 16, up: !isAr(C.kicker), pc: 'accText', lh: 1.2, maxLines: 2 },
    headline: { id: 'headline', t: 'text', role: 'headline', name: 'Headline', txt: C.headline, font: 'head', fw: 800, pc: 'ink', lh: 1.04, maxLines: 3 },
    offer: C.offer && { id: 'offer', t: 'text', role: 'offer', name: 'Offer', txt: C.offer, font: 'body', fw: 800, pc: 'onAcc', lh: 1.15, plate: { pc: 'acc', pad: 0.42 }, maxLines: 2 },
    sub: C.sub && { id: 'sub', t: 'text', role: 'sub', name: 'Supporting line', txt: C.sub, font: 'body', fw: 500, pc: 'sub', lh: 1.3, maxLines: 2 },
    second: C2?.headline && { id: 'second', t: 'text', role: 'second', name: ar ? 'English line' : 'Arabic line', txt: C2.offer && spec.kind === 'offer' ? C2.offer : C2.headline, font: 'body', fw: 700, pc: 'ink', lh: 1.3, maxLines: 2 },
    cta: { id: 'cta', t: 'btn', role: 'cta', name: 'Button', txt: C.cta, font: 'body', fw: 700, pc: 'onAcc', fill: 'acc', sh: 'pill', lh: 1.1, maxLines: 1 },
    trust: C.trust && { id: 'trust', t: 'text', role: 'trust', name: 'Small print', txt: C.trust, font: 'body', fw: 500, pc: 'sub', lh: 1.25, maxLines: 2 },
    code: C.code && { id: 'code', t: 'text', role: 'code', name: 'Code', txt: C.code, font: 'body', fw: 700, pc: 'ink', lh: 1.2, plate: { pc: 'card', pad: 0.4 }, maxLines: 1 },
    logo: { id: 'logo', t: 'logo', role: 'logo', name: 'Logo', pc: 'ink' },
  };
}

/** place text blocks top->bottom inside [y0,y1]; when they do not fit, gaps close, then every font shrinks together */
function stack(ctx, blocks, x, w, y0, y1, { gap = 26, align = 'center', al } = {}) {
  blocks = blocks.filter((b) => b && b[0]).map(([el, fs, extra]) => [el, fs * (ctx.textK || 1), extra]);
  const hOf = (el, fs) => el.t === 'btn' ? fs * 2.5 : layoutText(ctx.measureD, el, { x: 0, y: 0, w, fs }).box.h;
  const floor = (el) => (el.role === 'headline' ? 58 : el.role === 'big' ? 90 : MIN_TEXT + 1);
  // optional lines go first when space runs out (never the headline, the offer or the button)
  const DROP = ['sub', 'second', 'kicker', 'trust', 'code'];
  for (let round = 0; round < 6; round++) {
    let k = 1;
    for (let i = 0; i < 24; i++) {
      const fss = blocks.map(([el, fs]) => Math.max(floor(el), fs * k));
      const hs = blocks.map(([el], j) => hOf(el, fss[j])), tot = hs.reduce((a, b) => a + b, 0) + gap * k * (blocks.length - 1);
      if (tot <= y1 - y0) {
        let y = align === 'top' ? y0 : align === 'bottom' ? y1 - tot : y0 + (y1 - y0 - tot) / 2;
        return blocks.map(([el, , extra], j) => {
          const bw = Math.min(w, extra?.bw || 520);
          const g = el.t === 'btn' ? G(x + (al === 'l' ? 0 : al === 'r' ? w - bw : (w - bw) / 2), y, bw, hs[j], fss[j], 'c') : G(x, y, w, 0, fss[j], al);
          y += hs[j] + gap * k;
          return [el, g];
        });
      }
      if (fss.every((f, j) => f <= floor(blocks[j][0]))) break;
      k *= 0.95;
    }
    const drop = DROP.find((r) => blocks.some(([el]) => el.role === r));
    if (!drop) break;
    blocks = blocks.filter(([el]) => el.role !== drop);
  }
  return blocks.map(([el, fs]) => [el, G(x, y0, w, 0, fs, al)]); // does not fit: the rules will say so
}

// ── heroes: what the picture is (products, a worker, a destination scene, an AI image) ──
function heroEls(ctx, x, y, w, h, opts = {}) {
  const H = ctx.hero, out = [];
  if (!H) return out;
  if (ctx.heroK && ctx.heroK !== 1) { const k = ctx.heroK; x += (w * (1 - k)) / 2; y += h * (1 - k); w *= k; h *= k; }
  if (H.kind === 'products') {
    const items = H.items.slice(0, opts.max || (w > 600 ? 4 : 3));
    const n = items.length, gap = Math.min(40, w * 0.04);
    const weights = items.map((_, i) => (n >= 3 && i === Math.floor(n / 2) ? 1 : n >= 3 ? 0.84 : 1));
    // every slot at the height that fits the width, so products never touch or crop
    const sumA = items.reduce((s, it, i) => s + (it.w / it.h) * weights[i], 0);
    const hh = Math.min(h, (w - gap * (n - 1)) / sumA);
    const total = items.reduce((s, it, i) => s + (it.w / it.h) * hh * weights[i], 0) + gap * (n - 1);
    let px = x + (w - total) / 2;
    items.forEach((it, i) => {
      const ph = hh * weights[i], pw = (it.w / it.h) * ph;
      out.push([{ id: 'p' + i, t: 'prod', role: 'product', name: it.name || 'Product', src: it.src, fit: 'contain' }, G(px, y + h - ph, pw, ph)]);
      px += pw + gap;
    });
  } else if (H.kind === 'image') {
    out.push([{ id: 'hero', t: 'img', role: 'hero', name: 'Picture', src: H.src, fit: 'cover', mask: opts.mask || 'rect', rad: opts.rad ?? 36, fy: 0.3 }, G(x, y, w, h)]);
  } else if (H.kind === 'worker') {
    if (opts.disc !== false) out.push([{ id: 'heroBack', t: 'shape', role: 'deco', deco: true, sh: opts.mask === 'arch' ? 'arch' : 'circle', fill: 'card', op: 100 }, opts.mask === 'arch' ? G(x, y, w, h) : G(x + (w - Math.min(w, h)) / 2, y + h - Math.min(w, h), Math.min(w, h), Math.min(w, h))]);
    out.push([{ id: 'hero', t: 'img', role: 'hero', name: H.name || 'Worker', src: H.src, fit: 'contain', ay: 1 }, G(x + w * 0.04, y + h * 0.04, w * 0.92, h * 0.96)]);
  } else if (H.kind === 'scene') {
    const d = Math.min(w, h);
    out.push([{ id: 'heroBack', t: 'shape', role: 'deco', deco: true, sh: opts.mask || 'circle', fill: 'card' }, G(x + (w - d) / 2, y + (h - d) / 2, d, d)]);
    out.push([{ id: 'hero', t: 'img', role: 'hero', name: H.name || 'Landmark', src: H.src, tint: H.tint, fit: 'contain' }, G(x + (w - d * 0.72) / 2, y + (h - d * 0.72) / 2 - d * 0.02, d * 0.72, d * 0.72)]);
    if (H.flag) out.push([{ id: 'flag', t: 'img', role: 'flag', name: 'Flag', src: H.flag, fit: 'cover', mask: 'circle', ring: 'card', ringW: 10 }, G(x + (w + d) / 2 - d * 0.3, y + (h - d) / 2 + d * 0.02, d * 0.26, d * 0.26)]);
  }
  return out;
}
const iconRow = (ctx, x, y, w, s, style = 'badge') => {
  const ics = ctx.icons.slice(0, 3), gap = (w - ics.length * s) / Math.max(1, ics.length - 1);
  return ics.map((ic, i) => [{ id: 'ic' + i, t: 'icon', role: 'icon', name: 'Icon', ic, style, pc: style === 'badge' ? 'onAcc' : 'accText', bgPc: 'acc' }, G(x + i * (s + Math.min(gap, s * 0.6)) + (w - (ics.length * s + (ics.length - 1) * Math.min(gap, s * 0.6))) / 2, y, s, s)]);
};

// ── the families ──
const F = {};

F.spotlight = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, T = pieces(ctx), story = size === 'story', sq = size === 'square';
  const out = [[T.logo, G(A.x0 + W / 2 - 170, A.y0, 340, story ? 96 : 76)]];
  const textTop = A.y0 + (story ? 110 : 84), heroH = (A.y1 - A.y0) * (story ? 0.36 : sq ? 0.34 : 0.36);
  const textBot = story ? A.y0 + (A.y1 - A.y0) * 0.5 : A.y0 + (A.y1 - A.y0) * (sq ? 0.5 : 0.48);
  out.push(...stack(ctx, [[T.kicker, 30], [T.headline, story ? 118 : sq ? 92 : 104], [T.second, 40], [T.offer, story ? 52 : 44]], A.x0 + 20, W - 40, textTop, textBot, { gap: 22 }));
  const hy = textBot + 24;
  out.push([{ id: 'podium', t: 'shape', role: 'deco', deco: true, sh: 'ellipse', fill: 'card', op: 92 }, G(A.x0 + W * 0.12, hy + heroH - 40, W * 0.76, 80)]);
  out.push(...heroEls(ctx, A.x0 + W * 0.08, hy, W * 0.84, heroH - 10, { mask: 'arch' }));
  out.push(...stack(ctx, [[T.cta, story ? 40 : 34, { bw: 560 }], [T.trust, 25]], A.x0, W, hy + heroH + 30, A.y1, { gap: 18, align: 'top' }));
  return out;
};

F.split = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), al = T.ar ? 'r' : 'l';
  const out = [];
  if (size === 'story') {
    out.push([{ id: 'panel', t: 'shape', role: 'deco', deco: true, sh: 'rect', fill: 'card', rad: 48, op: 96, shadow: true }, G(A.x0, A.y0 + Hh * 0.47, W, Hh * 0.53)]);
    out.push(...heroEls(ctx, A.x0 + 40, A.y0 + 10, W - 80, Hh * 0.42, { mask: 'arch' }));
    out.push([{ ...T.logo, pc: 'cardInk' }, G(T.ar ? A.x1 - 240 : A.x0 + 40, A.y0 + Hh * 0.47 + 40, 200, 56, null, al)]);
    out.push(...stack(ctx, [[T.kicker, 28], [T.headline, 100], [T.offer, 44], [T.second, 36], [T.cta, 38, { bw: 520 }], [T.trust, 25]], A.x0 + 48, W - 96, A.y0 + Hh * 0.47 + 120, A.y1 - 40, { gap: 22, al }).map(darkOnCard));
  } else {
    const tx = T.ar ? A.x0 + W * 0.5 : A.x0, hx = T.ar ? A.x0 : A.x0 + W * 0.52;
    out.push([{ id: 'panel', t: 'shape', role: 'deco', deco: true, sh: 'rect', fill: 'card', rad: 44, op: 70 }, G(hx - 10, A.y0, W * 0.48 + 10, Hh)]);
    out.push(...heroEls(ctx, hx + 20, A.y0 + Hh * 0.12, W * 0.48 - 30, Hh * 0.8, { disc: false }));
    out.push([T.logo, G(T.ar ? tx + W * 0.5 - 200 : tx, A.y0, 200, 54, null, al)]);
    out.push(...stack(ctx, [[T.kicker, 26], [T.headline, size === 'square' ? 78 : 88], [T.offer, 38], [T.second, 32], [T.cta, 34, { bw: 420 }], [T.trust, 24]], tx, W * 0.48, A.y0 + 90, A.y1, { gap: 20, al }));
  }
  return out;
};
const darkOnCard = ([el, g]) => [el.t === 'text' && !el.plate ? { ...el, pc: el.role === 'kicker' ? 'acc' : el.role === 'sub' || el.role === 'trust' ? 'cardInk' : 'cardInk' } : el, g];

F.bignum = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), al = T.ar ? 'r' : 'l';
  const big = ctx.big || { txt: '★', small: '' };
  const out = [[T.logo, G(T.ar ? A.x1 - 220 : A.x0, A.y0, 220, 58, null, al)]];
  const bigFs = size === 'story' ? 330 : size === 'square' ? 250 : 290;
  const bigEl = { id: 'big', t: 'text', role: 'big', name: 'Big number', txt: big.txt, font: 'display', fw: 400, pc: 'accText', lh: 0.9, maxLines: 1 };
  const bigLbl = big.small && { id: 'bigLabel', t: 'text', role: 'bigLabel', name: 'Big number label', txt: big.small, font: 'display', fw: 400, pc: 'ink', lh: 1, maxLines: 1, ls: 6 };
  if (size === 'story') {
    out.push(...stack(ctx, [[bigEl, bigFs], [bigLbl, 96], [T.headline, 92], [T.second, 36], [T.kicker, 28]], A.x0, W, A.y0 + 90, A.y0 + Hh * 0.56, { gap: 14, al }));
    out.push(...heroEls(ctx, A.x0 + W * 0.06, A.y0 + Hh * 0.58, W * 0.88, Hh * 0.27));
    out.push(...stack(ctx, [[T.cta, 38, { bw: 540 }], [T.trust, 25]], A.x0, W, A.y0 + Hh * 0.87, A.y1, { gap: 16, align: 'top' }));
  } else {
    const tx = T.ar ? A.x0 + W * 0.46 : A.x0, hx = T.ar ? A.x0 : A.x0 + W * 0.56;
    out.push(...stack(ctx, [[bigEl, bigFs], [bigLbl, 80], [T.headline, size === 'square' ? 64 : 72], [T.second, 30], [T.cta, 32, { bw: 400 }], [T.trust, 24]], tx, W * 0.54, A.y0 + 80, A.y1, { gap: 14, al }));
    out.push(...heroEls(ctx, hx, A.y0 + Hh * 0.16, W * 0.44, Hh * 0.72));
  }
  return out;
};

F.editorial = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story';
  const head = { ...T.headline, font: 'serif', fw: 700, fi: false };
  const out = [[T.logo, G(A.x0 + W / 2 - 160, A.y0 + 4, 320, 70)]];
  out.push(...stack(ctx, [[T.kicker, 26], [head, story ? 104 : 84]], A.x0 + 40, W - 80, A.y0 + 80, A.y0 + Hh * (story ? 0.3 : 0.32), { gap: 16 }));
  const ay = A.y0 + Hh * (story ? 0.32 : 0.34), ah = Hh * (story ? 0.38 : 0.4), aw = Math.min(W * 0.6, ah * 0.86);
  out.push([{ id: 'archBack', t: 'shape', role: 'deco', deco: true, sh: 'arch', fill: 'card', op: 100, shadow: true }, G(A.x0 + (W - aw) / 2, ay, aw, ah)]);
  out.push(...heroEls(ctx, A.x0 + (W - aw) / 2 + aw * 0.08, ay + ah * 0.16, aw * 0.84, ah * 0.8, { disc: false, mask: 'arch', rad: 0 }));
  out.push(...stack(ctx, [[T.offer, story ? 44 : 36], [T.second, 32], [T.cta, story ? 36 : 30, { bw: 480 }], [T.trust, 24]], A.x0 + 20, W - 40, ay + ah + 26, A.y1, { gap: 16, align: 'top' }));
  return out;
};

F.band = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story', full = 1080;
  const out = [[T.logo, G(A.x0 + W / 2 - 160, A.y0, 320, 72)]];
  out.push(...stack(ctx, [[T.kicker, 28], [T.headline, story ? 104 : 80], [T.second, 34]], A.x0 + 20, W - 40, A.y0 + 76, A.y0 + Hh * (story ? 0.3 : 0.32), { gap: 16 }));
  const by = A.y0 + Hh * (story ? 0.33 : 0.35), bh = story ? 210 : 170;
  out.push([{ id: 'band', t: 'shape', role: 'deco', deco: true, sh: 'diag', fill: 'acc', grad: 'acc2' }, G(0, by, full, bh)]);
  if (T.offer) out.push([{ ...T.offer, plate: null, pc: 'onAcc', maxLines: 1 }, G(A.x0 + 30, by + bh * 0.24, W - 60, bh * 0.52, story ? 66 : 54)]);
  out.push(...heroEls(ctx, A.x0 + W * 0.08, by + bh + 30, W * 0.84, Hh * (story ? 0.3 : 0.27)));
  out.push(...stack(ctx, [[T.cta, story ? 38 : 32, { bw: 520 }], [T.trust, 24]], A.x0, W, by + bh + 50 + Hh * (story ? 0.3 : 0.27), A.y1, { gap: 14, align: 'top' }));
  return out;
};

F.card = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story', al = T.ar ? 'r' : 'l';
  const out = [[T.logo, G(A.x0 + W / 2 - 160, A.y0, 320, 72)]];
  const heroH = Hh * (story ? 0.4 : size === 'square' ? 0.36 : 0.4);
  out.push(...heroEls(ctx, A.x0 + W * 0.06, A.y0 + 76, W * 0.88, heroH, { mask: 'rect', rad: 40 }));
  const cy = A.y0 + 96 + heroH, ch = A.y1 - cy;
  out.push([{ id: 'cardBox', t: 'shape', role: 'deco', deco: true, sh: 'rect', fill: 'card', rad: 42, shadow: true }, G(A.x0, cy, W, ch)]);
  out.push(...stack(ctx, [[T.kicker, 26], [T.headline, story ? 92 : 70], [T.offer, story ? 42 : 34], [T.second, 30], [T.cta, story ? 36 : 30, { bw: 460 }], [T.trust, 24]], A.x0 + 50, W - 100, cy + 36, A.y1 - 30, { gap: story ? 18 : 12, al }).map(darkOnCard));
  return out;
};

F.tiles = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story';
  const out = [[T.logo, G(A.x0 + W / 2 - 160, A.y0, 320, 72)]];
  out.push(...stack(ctx, [[T.kicker, 28], [T.headline, story ? 100 : 76], [T.offer, story ? 42 : 34]], A.x0 + 20, W - 40, A.y0 + 76, A.y0 + Hh * (story ? 0.33 : 0.36), { gap: 16 }));
  const ty = A.y0 + Hh * (story ? 0.36 : 0.39), th = Hh * (story ? 0.4 : 0.38), gap = 22;
  const prods = ctx.hero?.kind === 'products' ? ctx.hero.items.slice(0, 4) : [];
  const ben = ctx.benefits.slice(0, 4);
  const n = prods.length >= 2 ? Math.min(prods.length, 4) : ben.length, cols = n === 3 && prods.length >= 2 ? 3 : 2, rows = Math.ceil(n / cols);
  const tw = (W - gap) / cols, tH = (th - gap * (rows - 1)) / rows;
  for (let i = 0; i < n; i++) {
    const cx = A.x0 + (i % cols) * (tw + gap), cy = ty + Math.floor(i / cols) * (tH + gap);
    out.push([{ id: 'tile' + i, t: 'shape', role: 'deco', deco: true, sh: 'rect', fill: 'card', rad: 30, op: 100, shadow: true }, G(cx, cy, tw, tH)]);
    if (prods.length >= 2) out.push([{ id: 'p' + i, t: 'prod', role: 'product', name: prods[i].name || 'Product', src: prods[i].src, fit: 'contain' }, G(cx + tw * 0.12, cy + tH * 0.08, tw * 0.76, tH * 0.84)]);
    else {
      const [ic, en, arL] = ben[i], s = Math.min(tH * 0.42, 110);
      out.push([{ id: 'tic' + i, t: 'icon', role: 'icon', name: 'Icon', ic, style: 'badge', pc: 'onAcc', bgPc: 'acc' }, G(cx + (tw - s) / 2, cy + tH * 0.12, s, s)]);
      out.push([{ id: 'tl' + i, t: 'text', role: 'label', name: 'Tile label', txt: T.ar ? arL : en, font: 'body', fw: 700, pc: 'cardInk', lh: 1.15, maxLines: 2 }, G(cx + 16, cy + tH * 0.12 + s + 14, tw - 32, tH * 0.88 - s - 30, story ? 36 : 30)]);
    }
  }
  out.push(...stack(ctx, [[T.second, 32], [T.cta, story ? 38 : 30, { bw: 520 }], [T.trust, 24]], A.x0, W, ty + th + 28, A.y1, { gap: 14, align: 'top' }));
  return out;
};

F.poster = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story', al = T.ar ? 'r' : 'l';
  const out = [];
  const hh = Hh * (story ? 0.52 : size === 'square' ? 0.46 : 0.5);
  out.push(...heroEls(ctx, A.x0, A.y0, W, hh, { mask: 'rect', rad: 44, disc: true }));
  out.push([T.logo, G(T.ar ? A.x0 : A.x1 - 210, A.y0 + hh + 34, 210, 52, null, T.ar ? 'l' : 'r')]);
  out.push(...stack(ctx, [[T.kicker, 26], [T.headline, story ? 96 : 72], [T.offer, story ? 40 : 32], [T.second, 30], [T.cta, story ? 36 : 30, { bw: 460 }], [T.trust, 24]], A.x0 + 8, W - 16, A.y0 + hh + 110, A.y1, { gap: story ? 18 : 12, al, align: 'top' }));
  return out;
};

F.arch = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story', al = T.ar ? 'r' : 'l';
  const out = [];
  if (story) {
    const aw = W * 0.74, ah = Hh * 0.5;
    out.push([{ id: 'archBack', t: 'shape', role: 'deco', deco: true, sh: 'arch', fill: 'acc', op: 22 }, G(A.x0 + (W - aw) / 2, A.y0 + 20, aw, ah)]);
    out.push(...heroEls(ctx, A.x0 + (W - aw) / 2 + aw * 0.08, A.y0 + 20 + ah * 0.2, aw * 0.84, ah * 0.76, { disc: false, mask: 'arch', rad: 0 }));
    out.push([T.logo, G(A.x0 + W / 2 - 120, A.y0 + ah + 46, 240, 54)]);
    out.push(...stack(ctx, [[T.kicker, 28], [T.headline, 96], [T.offer, 42], [T.second, 34], [T.cta, 38, { bw: 520 }], [T.trust, 24]], A.x0 + 20, W - 40, A.y0 + ah + 120, A.y1, { gap: 16, align: 'top' }));
  } else {
    const ax = T.ar ? A.x0 : A.x0 + W * 0.5, tx = T.ar ? A.x0 + W * 0.52 : A.x0, aw = W * 0.48, ah = Hh;
    out.push([{ id: 'archBack', t: 'shape', role: 'deco', deco: true, sh: 'arch', fill: 'acc', op: 22 }, G(ax, A.y0, aw, ah)]);
    out.push(...heroEls(ctx, ax + aw * 0.06, A.y0 + ah * 0.22, aw * 0.88, ah * 0.74, { disc: false, mask: 'arch', rad: 0 }));
    out.push([T.logo, G(T.ar ? tx + W * 0.48 - 200 : tx, A.y0, 200, 54, null, al)]);
    out.push(...stack(ctx, [[T.kicker, 26], [T.headline, size === 'square' ? 72 : 82], [T.offer, 34], [T.second, 30], [T.cta, 32, { bw: 400 }], [T.trust, 24]], tx, W * 0.48, A.y0 + 90, A.y1, { gap: 16, al }));
  }
  return out;
};

F.badge = (size, ctx) => {
  const A = SAFE[size], W = A.x1 - A.x0, Hh = A.y1 - A.y0, T = pieces(ctx), story = size === 'story';
  const big = ctx.big || { txt: '★', small: '' };
  const out = [[T.logo, G(A.x0 + W / 2 - 160, A.y0, 320, 72)]];
  out.push(...stack(ctx, [[T.kicker, 28], [T.headline, story ? 104 : 80], [T.second, 34]], A.x0 + 20, W - 40, A.y0 + 76, A.y0 + Hh * (story ? 0.3 : 0.33), { gap: 16 }));
  const d = story ? 300 : size === 'square' ? 230 : 260, by = A.y0 + Hh * (story ? 0.33 : 0.36);
  const heroW = W - d - 30, heroH = story ? Hh * 0.34 : Hh * 0.42;
  const bx = T.ar ? A.x0 : A.x1 - d, hx = T.ar ? A.x0 + d + 30 : A.x0;
  out.push(...heroEls(ctx, hx, by, heroW, heroH));
  out.push([{ id: 'badgeDisc', t: 'shape', role: 'deco', deco: true, sh: 'circle', fill: 'acc', grad: 'acc2', shadow: true }, G(bx, by + heroH * 0.1, d, d)]);
  out.push(...stack(ctx, [[{ id: 'big', t: 'text', role: 'big', name: 'Badge number', txt: big.txt, font: 'display', fw: 400, pc: 'onAcc', lh: 0.95, maxLines: 1 }, d * 0.42], [big.small && { id: 'bigLabel', t: 'text', role: 'bigLabel', name: 'Badge label', txt: big.small, font: 'display', fw: 400, pc: 'onAcc', lh: 1, maxLines: 1, ls: 4 }, d * 0.17]], bx + d * 0.14, d * 0.72, by + heroH * 0.1 + d * 0.16, by + heroH * 0.1 + d * 0.84, { gap: 4 }));
  out.push(...stack(ctx, [[T.offer && { ...T.offer }, story ? 40 : 32], [T.cta, story ? 38 : 30, { bw: 520 }], [T.trust, 24]], A.x0, W, by + heroH + 36, A.y1, { gap: 14, align: 'top' }));
  return out;
};

export function compose(family, size, ctx) { return (F[family] || F.spotlight)(size, ctx); }

/** the families that suit a brief, best first (distinct compositions; tiles need ≥2 products or benefits) */
export function familiesFor(spec, hero) {
  const k = spec.kind, hasNum = spec.offer?.type === 'percent' || spec.offer?.type === 'amount';
  if (k === 'hiring') return ['poster', 'split', 'card', 'arch', 'tiles', 'spotlight', 'editorial', 'band', 'bignum', 'badge'];
  if (k === 'study' || k === 'visa') return ['split', 'tiles', 'arch', 'card', 'poster', 'editorial', 'spotlight', 'band', 'bignum', 'badge'];
  const base = ['spotlight', 'editorial', 'split', 'card', 'arch', 'tiles', 'poster', 'band'];
  return hasNum ? ['spotlight', 'bignum', 'badge', 'editorial', 'band', 'split', 'card', 'arch', 'tiles', 'poster'] : base;
}
