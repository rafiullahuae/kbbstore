// KBB Studio · Designs · THE DESIGN RULES, enforced and scored on the real rendering (the same pixels that export):
//   hard (a design that fails one is fixed or rejected):
//     safe     -- every text, button, logo, product and picture inside the platform-safe area (story: top 250 / bottom 340)
//     size     -- no text under 24 px at 1080 wide; the headline at least 56 px
//     fit      -- every text fits its box and its line limit (nothing cut, nothing spilling)
//     overlap  -- no two content boxes touch (text, button, logo, product, picture, icon)
//     contrast -- every text ≥ 4.5:1 against what is actually behind it (5th-percentile pixel, measured)
//     crop     -- products shown whole (contain-fit, inside the canvas, never covered)
//     hierarchy-- one headline, clearly the biggest text (≥ 1.3× any other line), one button
//   soft (score only): grid alignment (shared left edges / centre axis), breathing room, text amount.
// check(D, size) -> {score 0..100, pass, checks:[{id, ok, msg, ids}]}
import { layout, draw, colorOf, PX } from './render.js';
import { SAFE, MIN_TEXT } from './layouts.js';
import { lumRGB, ratio, lum } from './lib/color.js';

const CONTENT = new Set(['text', 'btn', 'logo', 'prod', 'img', 'icon']);
const inter = (a, b, pad = 0) => a.x < b.x + b.w + pad && b.x < a.x + a.w + pad && a.y < b.y + b.h + pad && b.y < a.y + a.h + pad;
const textBox = (it) => (it.text ? it.text.box : it.box);
// pairs that are allowed to touch by design: a flag badge on its landmark disc; an icon on its tile
const allowed = (a, b) => [a.el.role, b.el.role].sort().join('+') === 'flag+hero';

export function check(D, size, { pixels = true } = {}) {
  const items = layout(D, size), A = SAFE[size], [W, H] = PX[size];
  const checks = [];
  const add = (id, ok, msg, ids = []) => checks.push({ id, ok, msg, ids });
  const content = items.filter((it) => CONTENT.has(it.el.t) && !it.el.deco);

  // safe area
  const out = content.filter((it) => { const b = textBox(it); return b.x < A.x0 - 2 || b.y < A.y0 - 2 || b.x + b.w > A.x1 + 2 || b.y + b.h > A.y1 + 2; });
  add('safe', !out.length, out.length ? `${out.map((x) => x.el.name).join(', ')} outside the safe area` : 'Everything inside the safe area', out.map((x) => x.el.id));

  // text size + fit
  const texts = items.filter((it) => it.text);
  const small = texts.filter((it) => it.text.fs < (it.el.role === 'headline' ? 56 : MIN_TEXT) - 0.01);
  add('size', !small.length, small.length ? small.map((x) => `${x.el.name} ${Math.round(x.text.fs)} px`).join(', ') + ' too small' : `Smallest text ${Math.round(Math.min(...texts.map((x) => x.text.fs)))} px`, small.map((x) => x.el.id));
  const spill = texts.filter((it) => (it.el.maxLines && it.text.lines.length > it.el.maxLines) || it.text.lines.some((l) => l.w > it.text.box.w - 2 * it.text.padX + 1) || (it.el.t === 'btn' && it.text.lines.length * it.text.lh > it.g.h));
  add('fit', !spill.length, spill.length ? spill.map((x) => x.el.name).join(', ') + ' does not fit' : 'Every text fits', spill.map((x) => x.el.id));

  // overlaps
  const clashes = [];
  for (let i = 0; i < content.length; i++) for (let j = i + 1; j < content.length; j++) {
    const a = content[i], b = content[j];
    if (allowed(a, b)) continue;
    if (inter(textBox(a), textBox(b), -1)) clashes.push([a, b]);
  }
  add('overlap', !clashes.length, clashes.length ? clashes.map(([a, b]) => `${a.el.name} × ${b.el.name}`).join(', ') : 'No overlaps', clashes.flat().map((x) => x.el.id));

  // products whole
  const prods = items.filter((it) => it.el.t === 'prod');
  const cut = prods.filter((it) => it.box.x < 0 || it.box.y < 0 || it.box.x + it.box.w > W || it.box.y + it.box.h > H || it.box.w > it.g.w + 1 || it.box.h > it.g.h + 1);
  add('crop', !cut.length, cut.length ? cut.map((x) => x.el.name).join(', ') + ' cropped' : `${prods.length} product${prods.length === 1 ? '' : 's'} shown whole`, cut.map((x) => x.el.id));

  // hierarchy
  const head = texts.find((x) => x.el.role === 'headline'), ctas = items.filter((x) => x.el.role === 'cta');
  const others = texts.filter((x) => x !== head && !['big', 'bigLabel', 'cta'].includes(x.el.role));
  const hr = head ? head.text.fs / Math.max(1, ...others.map((x) => x.text.fs)) : 0;
  add('hierarchy', !!head && hr >= 1.3 && ctas.length === 1, head ? `Headline ${hr.toFixed(2)}× the next text · ${ctas.length} button` : 'No headline', head ? [head.el.id] : []);

  // contrast, on pixels
  let worst = 99;
  if (pixels) {
    const bad = [];
    const s = 0.5, cv = document.createElement('canvas'); cv.width = Math.round(W * s); cv.height = Math.round(H * s);
    const cx = cv.getContext('2d', { willReadFrequently: true });
    for (const it of texts) {
      const col = colorOf(D, it.el.pc, it.el.col), tl = lum(col);
      let r;
      if (it.el.t === 'btn') r = ratio(tl, lum(colorOf(D, it.el.fill || 'acc', it.el.fillCol)));
      else if (it.el.plate && (it.el.plate.op ?? 1) >= 0.98) r = ratio(tl, lum(colorOf(D, it.el.plate.pc || 'card', it.el.plate.col)));
      else {
        cx.clearRect(0, 0, cv.width, cv.height);
        draw(cx, D, size, { scale: s, items, under: it.el.id });
        if (it.el.plate) { const op = it.el.plate.op ?? 1; cx.save(); cx.scale(s, s); cx.globalAlpha = op; cx.fillStyle = colorOf(D, it.el.plate.pc || 'card', it.el.plate.col); const b = it.text.box; cx.fillRect(b.x, b.y, b.w, b.h); cx.restore(); }
        const lums = [];
        let y = it.text.box.y + it.text.padY;
        for (const ln of it.text.lines) {
          const x0 = it.text.al === 'l' ? it.text.box.x + it.text.padX : it.text.al === 'r' ? it.text.box.x + it.text.box.w - it.text.padX - ln.w : it.text.box.x + (it.text.box.w - ln.w) / 2;
          const sx = Math.max(0, Math.floor(x0 * s)), sy = Math.max(0, Math.floor(y * s)), sw = Math.max(1, Math.min(cv.width - sx, Math.ceil(ln.w * s))), sh = Math.max(1, Math.min(cv.height - sy, Math.ceil(it.text.lh * s)));
          const px = cx.getImageData(sx, sy, sw, sh).data;
          const step = Math.max(1, Math.floor(px.length / 4 / 1500));
          for (let i = 0; i < px.length; i += 4 * step) lums.push(lumRGB(px[i], px[i + 1], px[i + 2]));
          y += it.text.lh;
        }
        const rs = lums.map((l) => ratio(tl, l)).sort((a, b) => a - b);
        r = rs.length ? rs[Math.floor(rs.length * 0.05)] : 21;
      }
      it.contrast = r;
      worst = Math.min(worst, r);
      if (r < 4.5) bad.push(it);
    }
    add('contrast', !bad.length, bad.length ? bad.map((x) => `${x.el.name} ${x.contrast.toFixed(1)}:1`).join(', ') : `Lowest text contrast ${worst.toFixed(1)}:1`, bad.map((x) => x.el.id));
  }

  // soft: grid alignment + breathing room + amount of text
  const lefts = texts.filter((x) => x.text.al === 'l').map((x) => Math.round(x.text.box.x)), cents = texts.filter((x) => x.text.al === 'c').map((x) => Math.round(x.text.box.x + x.text.box.w / 2));
  const aligned = (arr) => { if (arr.length < 2) return 1; const m = new Map(); for (const v of arr) { const k = Math.round(v / 4); m.set(k, (m.get(k) || 0) + 1); } return Math.max(...m.values()) / arr.length; };
  const grid = (aligned(lefts) * lefts.length + aligned(cents) * cents.length) / Math.max(1, lefts.length + cents.length);
  add('grid', grid >= 0.6, `${Math.round(grid * 100)}% of text on a shared edge or axis`);
  const words = texts.reduce((n, x) => n + String(x.el.txt || '').split(/\s+/).length, 0);
  add('words', words <= 45, `${words} words`);

  const hard = ['safe', 'size', 'fit', 'overlap', 'crop', 'hierarchy', 'contrast'];
  const failed = checks.filter((c) => hard.includes(c.id) && !c.ok);
  let score = 100 - failed.length * 18;
  score -= (1 - grid) * 10;
  if (words > 45) score -= 6;
  if (worst < 99) score -= Math.max(0, 7 - worst) * 1.5; // more contrast scores higher
  if (hr) score -= Math.max(0, 1.8 - hr) * 6;
  return { score: Math.max(0, Math.round(score)), pass: !failed.length, checks, items };
}
