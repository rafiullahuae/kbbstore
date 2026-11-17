// KBB Studio · themes · DESIGN FAMILIES, the pieces (see families.js): every piece is {fam, name, nameAr, style, words,
// draw(c, w, h, t, o)} drawn at the origin inside w×h. o = {P (family colours), K (kit: landmark, flag, city), flag
// (picture or null), words, role, rtl}. Pure functions of (w, h, t, o): the preview, the still and the MP4 agree.
import * as A from './art.js';
import { kitFor } from './kit.js';
import { TRADES, person, T, paper, wave, rrect, bestOn, SKIN, shadowed } from './families.js';

const { TAU, clamp, easeOut, easeIO, back, rnd, fr, mix, rgba, lum } = A;
export const ez = (t, t0, d) => easeOut((t - t0) / d);
export const pop = (t, t0, d = 0.5) => back(clamp((t - t0) / d));
export const lerp = (a, b, k) => a + (b - a) * k;
export const flagOf = (o) => o.flag || (o.K?.flagKind === 'stars' ? A.bandFlag(o.K.flag, 'stars') : A.bandFlag(o.K?.flag || ['#3A4CCB', '#ffffff', '#FF6B4A']));
export const lmOf = (K) => K?.landmark || (K?.skyline || [])[0] || 'big-ben';
export const CITIES = ['LONDON', 'TORONTO', 'BERLIN', 'WARSAW', 'SYDNEY', 'DUBLIN', 'PARIS', 'ROMA'];
export function sketchLine(c, x0, y0, x1, y1, p, seed, col, lw) {
  if (p <= 0) return;
  const x2 = lerp(x0, x1, clamp(p)), y2 = lerp(y0, y1, clamp(p));
  c.save(); c.strokeStyle = col; c.lineCap = 'round';
  for (let k = 0; k < 2; k++) { const j = lw * 0.6; c.lineWidth = lw * (k ? 0.55 : 1); c.globalAlpha *= k ? 0.6 : 1; c.beginPath(); c.moveTo(x0 + (rnd(seed, k) - 0.5) * j, y0 + (rnd(seed, k + 2) - 0.5) * j); c.quadraticCurveTo((x0 + x2) / 2 + (rnd(seed, k + 4) - 0.5) * j * 3, (y0 + y2) / 2 + (rnd(seed, k + 6) - 0.5) * j * 3, x2, y2); c.stroke(); }
  c.restore();
}
/** isometric helpers: a prism with three shaded faces */
export function iso(cx, cy, k) { const C = Math.cos(Math.PI / 6); return (x, y, z = 0) => [cx + (x - y) * k * C, cy + (x + y) * k * 0.5 - z * k]; }
export function prism(c, P3, x, y, z, sx, sy, sz, col, win) {
  const p = (a, b, cc) => P3(x + a, y + b, z + cc);
  const face = (pts, f) => { c.fillStyle = f; c.beginPath(); pts.forEach(([X, Y], i) => (i ? c.lineTo(X, Y) : c.moveTo(X, Y))); c.closePath(); c.fill(); };
  face([p(0, sy, 0), p(sx, sy, 0), p(sx, sy, sz), p(0, sy, sz)], mix(col, '#000000', 0.12));
  face([p(sx, 0, 0), p(sx, sy, 0), p(sx, sy, sz), p(sx, 0, sz)], mix(col, '#000000', 0.28));
  face([p(0, 0, sz), p(sx, 0, sz), p(sx, sy, sz), p(0, sy, sz)], mix(col, '#ffffff', 0.25));
  if (win && sz > 0.6) {
    c.fillStyle = rgba(win, 0.85);
    for (let zz = 0.35; zz < sz - 0.2; zz += 0.5) for (let k = 0.2; k < sx - 0.1; k += 0.4) { const [X, Y] = p(k, sy, zz), [X2, Y2] = p(k + 0.22, sy, zz + 0.25); c.beginPath(); c.moveTo(X, Y); c.lineTo(X2, Y2 + (Y - Y2) * 0); const [X3, Y3] = p(k + 0.22, sy, zz); const [X4, Y4] = p(k, sy, zz + 0.25); c.lineTo(X3, Y3); c.lineTo(X, Y); c.closePath(); c.moveTo(X, Y); c.lineTo(X4, Y4); c.lineTo(X2, Y2); c.lineTo(X3, Y3); c.closePath(); c.fill(); }
  }
}
export function mortar(c, x, y, s, rot, P) {
  c.save(); c.translate(x, y); c.rotate(rot);
  c.fillStyle = mix(P.ink, '#000000', 0.2); c.beginPath(); c.ellipse(0, s * 0.18, s * 0.42, s * 0.2, 0, 0, Math.PI); c.lineTo(-s * 0.42, s * 0.02); c.lineTo(s * 0.42, s * 0.02); c.fill();
  c.fillStyle = P.ink; c.beginPath(); c.moveTo(-s * 0.75, 0); c.lineTo(0, -s * 0.3); c.lineTo(s * 0.75, 0); c.lineTo(0, s * 0.3); c.closePath(); c.fill();
  c.fillStyle = rgba('#ffffff', 0.12); c.beginPath(); c.moveTo(-s * 0.75, 0); c.lineTo(0, -s * 0.3); c.lineTo(0, 0); c.closePath(); c.fill();
  c.strokeStyle = P.b; c.lineWidth = s * 0.05; c.lineCap = 'round'; c.beginPath(); c.moveTo(0, 0); c.quadraticCurveTo(s * 0.45, s * 0.05, s * 0.5, s * 0.45); c.stroke();
  c.fillStyle = P.b; c.beginPath(); c.ellipse(s * 0.5, s * 0.5, s * 0.06, s * 0.12, 0, 0, TAU); c.fill();
  c.fillStyle = mix(P.b, '#ffffff', 0.3); c.beginPath(); c.arc(0, 0, s * 0.06, 0, TAU); c.fill();
  c.restore();
}
export const sky3 = (c, w, h, a, b, d) => A.gradRect(c, 0, 0, w, h, [[0, a], [0.6, b], [1, d]]);
export function burst(c, cx, cy, R, t, col, n = 16, a = 0.35) { c.save(); c.translate(cx, cy); c.rotate(t * 0.08); c.fillStyle = rgba(col, a); for (let i = 0; i < n; i++) { c.beginPath(); c.moveTo(0, 0); c.arc(0, 0, R, (i / n) * TAU, ((i + 0.5) / n) * TAU); c.closePath(); c.fill(); } c.restore(); }
export function sparkle(c, w, h, t, n, col, u, seed) { c.save(); A.sparkles(c, w, h, t, n, col, u, seed); c.restore(); }

export const PIECES = {};
export const def = (id, o) => { PIECES[id] = o; };

// ════════════ CAREERS ════════════
def('ladder', { fam: 'careers', dir: true, name: 'Career staircase', nameAr: 'سلّم المسيرة', style: 'Paper-cut layers', words: { s1: ['START', 'البداية'], s2: ['LEARN', 'تعلّم'], s3: ['GROW', 'تطوّر'], s4: ['LEAD', 'تقدّم'], s5: ['YOUR GOAL', 'هدفك'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.b, '#ffffff', 0.55)], [1, mix(P.a, '#ffffff', 0.55)]]);
    c.fillStyle = mix(P.b, '#ffffff', 0.15); c.beginPath(); c.arc(w * 0.78, h * 0.2, u * 0.13, 0, TAU); c.fill();
    [[0.5, P.c], [0.64, P.d], [0.8, mix(P.d, P.ink, 0.35)]].forEach(([yy, col], i) => paper(c, wave(-10, h * yy, w + 20, h, i * 1.7 + t * 0.12 * (i % 2 ? 1 : -1), h * 0.03), mix(col, '#ffffff', 0.35 - i * 0.12), u, 1 + i * 0.3));
    const n = 5, x0 = w * 0.06, y0 = h * 0.96, sw = (w * 0.88) / n, sh = (h * 0.58) / n, cols = [P.a, P.b, P.c, P.d, P.a];
    for (let i = 0; i < n; i++) {
      const k = pop(t, 0.25 + i * 0.2, 0.55); if (k <= 0.01) continue;
      const bh = (i + 1) * sh * k, p2 = new Path2D(); p2.rect(x0 + i * sw, y0 - bh, sw + 1, bh);
      paper(c, p2, cols[i], u, 1.2);
      c.fillStyle = rgba('#ffffff', 0.25); c.fillRect(x0 + i * sw, y0 - bh, sw + 1, u * 0.012);
      const word = o.words['s' + (i + 1)];
      T(c, word, x0 + i * sw + sw / 2, y0 - bh + Math.min(sh * 0.62, u * 0.08), Math.min(sw * 0.15, sh * 0.32, u * 0.05), { col: bestOn(cols[i]), weight: 900, maxW: sw * 0.9 });
    }
    // the climber hops up one step after another, then raises both arms under the flag
    const p = clamp((t - 0.9) / 2.5) * (n - 1), i = Math.min(n - 2, Math.floor(p)), f = p >= n - 1 ? 1 : p - i, top = (k) => y0 - (k + 1) * sh;
    const s = Math.min(sh * 2.6, sw * 1.7), done = p >= n - 1;
    const feet = lerp(top(i), top(i + 1), easeIO(f)) - Math.sin(Math.PI * f) * sh * 0.55, x = x0 + (i + 0.5 + f) * sw;
    if (t > 0.6) {
      c.save(); c.globalAlpha *= clamp((t - 0.6) * 3);
      if (done) { const fx = x0 + (n - 0.25) * sw, fy = top(n - 1); c.fillStyle = '#5c4033'; c.fillRect(fx, fy - s * 1.25, u * 0.008, s * 1.25); A.flagCloth(c, flagOf(o), o.K?.flag || [P.a], fx + u * 0.008, fy - s * 1.25, s * 0.5, s * 0.33, t, { pole: false, strips: 12, amp: 0.05 }); c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha *= 0.7; c.drawImage(A.blob('#fff3b0'), x - s * 0.7, feet - s * 1.4, s * 1.4, s * 1.4); c.restore(); }
      person(c, x, feet, s, { top: P.d, bottom: P.ink, step: done ? 0 : f, hair: 'short', skin: SKIN[1], arms: done ? [[-Math.PI / 2 - 0.5, -Math.PI / 2 - 0.3], [-Math.PI / 2 + 0.5, -Math.PI / 2 + 0.3]] : undefined, hold: (cc, [[lx, ly]], ss) => { cc.fillStyle = '#5C4033'; rrect(cc, lx - ss * 0.06, ly - ss * 0.02, ss * 0.12, ss * 0.09, ss * 0.015); cc.fill(); } });
      c.restore();
    }
    if (t > 3.3) A.confettiBurst(c, x0 + (n - 0.5) * sw, top(n - 1) - s, t - 3.3, u, [P.a, P.b, P.c, P.d, '#ffffff'], { n: 26, life: 2.6 });
  } });

def('cv', { fam: 'careers', name: 'CV that builds itself', nameAr: 'سيرة ذاتية تُبنى', style: 'Gradient mesh + glass', words: { name: ['Your Name', 'اسمك هنا'], role: ['‹Your next role›', '‹وظيفتك القادمة›'], skills: ['SKILLS', 'المهارات'], badge: ['CV READY', 'السيرة جاهزة'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    c.fillStyle = mix(P.paper, P.d, 0.08); c.fillRect(0, 0, w, h);
    [[0.2, 0.25, P.a], [0.85, 0.2, P.b], [0.15, 0.85, P.c], [0.8, 0.8, P.d]].forEach(([x, y, col], i) => { const r = Math.max(w, h) * 0.55; c.save(); c.globalAlpha = 0.75; c.drawImage(A.blob(col), x * w + Math.sin(t * 0.4 + i) * u * 0.04 - r, y * h + Math.cos(t * 0.35 + i) * u * 0.04 - r, r * 2, r * 2); c.restore(); });
    const cw = Math.min(w * 0.8, h * 0.84 * 0.78), ch = cw / 0.78, cx = (w - cw) / 2, cy = (h - ch) / 2 + (1 - ez(t, 0.1, 0.8)) * h * 0.25;
    c.save(); c.globalAlpha *= clamp(t * 3);
    // two floating glass chips behind the card
    for (const [k, dx, dy, col] of [[0, -0.1, 0.12, P.a], [1, 0.82, 0.62, P.c]]) { const gx = cx + cw * dx, gy = cy + ch * dy + Math.sin(t * 1.2 + k) * u * 0.015, gw = cw * 0.3, gh = cw * 0.16; shadowed(c, u * 0.04, u * 0.012, 'rgba(40,30,90,0.25)', () => { c.fillStyle = rgba('#ffffff', 0.55); rrect(c, gx, gy, gw, gh, gh * 0.3); c.fill(); }); c.fillStyle = col; c.beginPath(); c.arc(gx + gh * 0.5, gy + gh * 0.5, gh * 0.26, 0, TAU); c.fill(); c.fillStyle = rgba(P.ink, 0.25); rrect(c, gx + gh * 0.95, gy + gh * 0.36, gw * 0.5, gh * 0.12, gh * 0.06); c.fill(); rrect(c, gx + gh * 0.95, gy + gh * 0.56, gw * 0.32, gh * 0.1, gh * 0.05); c.fill(); }
    shadowed(c, u * 0.06, u * 0.02, 'rgba(40,30,90,0.3)', () => { c.fillStyle = rgba('#ffffff', 0.82); rrect(c, cx, cy, cw, ch, cw * 0.06); c.fill(); });
    c.strokeStyle = rgba('#ffffff', 0.95); c.lineWidth = u * 0.006; rrect(c, cx, cy, cw, ch, cw * 0.06); c.stroke();
    const X = (k) => cx + cw * k, Y = (k) => cy + ch * k, ink = P.ink;
    // photo
    const pk = pop(t, 0.5, 0.5);
    if (pk > 0.01) { c.save(); c.translate(X(0.2), Y(0.15)); c.scale(pk, pk); const r = cw * 0.12; const g = c.createLinearGradient(-r, -r, r, r); g.addColorStop(0, P.a); g.addColorStop(1, P.d); c.fillStyle = g; c.beginPath(); c.arc(0, 0, r, 0, TAU); c.fill(); c.save(); c.beginPath(); c.arc(0, 0, r * 0.86, 0, TAU); c.clip(); c.fillStyle = mix(P.b, '#ffffff', 0.4); c.fillRect(-r, -r, r * 2, r * 2); person(c, 0, r * 2.6, r * 2.9, { top: P.d, skin: SKIN[2], hair: 'short' }); c.restore(); c.restore(); }
    const name = o.words.name || '', nm = name.slice(0, Math.ceil(name.length * ez(t, 0.8, 0.7)));
    T(c, nm, X(0.37), Y(0.135), cw * 0.075, { col: ink, align: 'left', weight: 900, maxW: cw * 0.58 });
    if (t > 1.1) T(c, o.words.role, X(0.37), Y(0.2), cw * 0.045, { col: mix(ink, '#ffffff', 0.3), align: 'left', weight: 700, maxW: cw * 0.58 });
    for (let i = 0; i < 3; i++) { c.fillStyle = rgba(ink, 0.14); rrect(c, X(0.08), Y(0.32 + i * 0.055), cw * (0.84 - i * 0.14) * ez(t, 1.0 + i * 0.15, 0.5), ch * 0.022, ch * 0.011); c.fill(); }
    T(c, o.words.skills, X(0.08), Y(0.55), cw * 0.05, { col: P.d, align: 'left', weight: 900, track: 0.12 });
    for (let i = 0; i < 3; i++) {
      const yy = Y(0.6 + i * 0.085), full = [0.86, 0.7, 0.78][i] * ez(t, 1.5 + i * 0.2, 0.9);
      c.fillStyle = rgba(ink, 0.1); rrect(c, X(0.08), yy, cw * 0.6, ch * 0.03, ch * 0.015); c.fill();
      const g = c.createLinearGradient(X(0.08), 0, X(0.68), 0); g.addColorStop(0, [P.a, P.c, P.d][i]); g.addColorStop(1, mix([P.a, P.c, P.d][i], P.b, 0.5)); c.fillStyle = g; rrect(c, X(0.08), yy, cw * 0.6 * full, ch * 0.03, ch * 0.015); c.fill();
      const k = pop(t, 2.2 + i * 0.2, 0.4); if (k > 0.01) { c.save(); c.translate(X(0.8), yy + ch * 0.015); c.scale(k, k); c.fillStyle = P.c; c.beginPath(); c.arc(0, 0, cw * 0.04, 0, TAU); c.fill(); c.strokeStyle = '#ffffff'; c.lineWidth = cw * 0.012; c.lineCap = 'round'; c.beginPath(); c.moveTo(-cw * 0.018, 0); c.lineTo(-cw * 0.004, cw * 0.016); c.lineTo(cw * 0.02, -cw * 0.014); c.stroke(); c.restore(); }
    }
    const bk = pop(t, 2.9, 0.5);
    if (bk > 0.01) { c.save(); c.translate(X(0.7), Y(0.93)); c.rotate(-0.08); c.scale(bk, bk); const bw = cw * 0.5, bh = cw * 0.13; shadowed(c, u * 0.03, u * 0.01, 'rgba(0,0,0,0.25)', () => { c.fillStyle = P.a; rrect(c, -bw / 2, -bh / 2, bw, bh, bh / 2); c.fill(); }); T(c, o.words.badge, 0, bh * 0.18, bh * 0.46, { col: bestOn(P.a), weight: 900, maxW: bw * 0.86 }); c.restore(); }
    c.restore();
    if (t > 3) A.sheen(c, cx, cy, cw, ch, fr((t - 3) / 3), 0.28);
  } });

def('offer', { fam: 'careers', name: 'Job-offer letter', nameAr: 'خطاب عرض العمل', style: 'Warm flat', words: { title: ['JOB OFFER', 'عرض عمل'], seal: ['WELCOME', 'مرحباً'], sign: ['Signature', 'التوقيع'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    c.fillStyle = mix(P.b, '#ffffff', 0.6); c.fillRect(0, 0, w, h);
    burst(c, w / 2, h * 0.42, Math.hypot(w, h) * 0.7, t, '#ffffff', 18, 0.45);
    const ew = Math.min(w * 0.82, h * 0.66), eh = ew * 0.6, ex = (w - ew) / 2, envTop = h * 0.97 - eh, lw = ew * 0.84, lh = lw * 1.2, lx = (w - lw) / 2;
    const k = ez(t, 0.7, 1.1), LT = lerp(envTop + eh * 0.1, envTop + eh * 0.6 - lh, k);
    // envelope back + open flap
    c.fillStyle = mix(P.a, '#000000', 0.15); rrect(c, ex, envTop, ew, eh, u * 0.02); c.fill();
    const fk = ez(t, 0.25, 0.45); c.fillStyle = mix(P.a, '#000000', 0.05); c.beginPath(); c.moveTo(ex, envTop); c.lineTo(ex + ew, envTop); c.lineTo(ex + ew / 2, envTop + eh * 0.55 * (1 - 2 * fk)); c.closePath(); c.fill();
    // the letter
    c.save(); c.beginPath(); c.rect(0, 0, w, envTop + eh * 0.36); c.clip();
    shadowed(c, u * 0.03, u * 0.01, 'rgba(60,30,0,0.25)', () => { c.fillStyle = '#ffffff'; rrect(c, lx, LT, lw, lh, u * 0.015); c.fill(); });
    c.fillStyle = P.d; rrect(c, lx, LT, lw, lh * 0.16, u * 0.015); c.fill(); c.fillRect(lx, LT + lh * 0.08, lw, lh * 0.08);
    T(c, o.words.title, lx + lw / 2, LT + lh * 0.115, lh * 0.075, { col: '#ffffff', weight: 900, maxW: lw * 0.9, track: 0.08 });
    c.fillStyle = rgba(P.ink, 0.16); for (let i = 0; i < 5; i++) { rrect(c, lx + lw * 0.1, LT + lh * (0.24 + i * 0.065), lw * [0.8, 0.72, 0.78, 0.6, 0.7][i], lh * 0.022, lh * 0.011); c.fill(); }
    // the signature writes itself
    const sp = ez(t, 1.8, 1.0), sy = LT + lh * 0.66;
    c.strokeStyle = rgba(P.ink, 0.4); c.lineWidth = u * 0.003; c.beginPath(); c.moveTo(lx + lw * 0.1, sy + lh * 0.03); c.lineTo(lx + lw * 0.55, sy + lh * 0.03); c.stroke();
    T(c, o.words.sign, lx + lw * 0.1, sy + lh * 0.075, lh * 0.03, { col: rgba(P.ink, 0.55), align: 'left', weight: 600 });
    if (sp > 0) { c.strokeStyle = P.d; c.lineWidth = u * 0.008; c.lineCap = 'round'; c.lineJoin = 'round'; c.beginPath(); const N = 40; for (let i = 0; i <= N * sp; i++) { const q = i / N, X = lx + lw * (0.12 + q * 0.4), Y = sy + Math.sin(q * TAU * 3.2) * lh * 0.025 - Math.sin(q * Math.PI) * lh * 0.02; i ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); }
    c.restore();
    // envelope front pocket
    c.fillStyle = P.a; c.beginPath(); c.moveTo(ex, envTop + eh * 0.25); c.lineTo(ex + ew / 2, envTop + eh * 0.62); c.lineTo(ex + ew, envTop + eh * 0.25); c.lineTo(ex + ew, envTop + eh); c.lineTo(ex, envTop + eh); c.closePath(); c.fill();
    c.fillStyle = rgba('#ffffff', 0.18); c.beginPath(); c.moveTo(ex, envTop + eh * 0.25); c.lineTo(ex + ew / 2, envTop + eh * 0.62); c.lineTo(ex + ew / 2, envTop + eh * 0.68); c.lineTo(ex, envTop + eh * 0.32); c.fill();
    // the seal
    const sk = pop(t, 2.9, 0.5);
    if (sk > 0.01) { const rx = lx + lw * 0.8, ry = LT + lh * 0.62, r = lw * 0.12; c.save(); c.translate(rx, ry); c.scale(sk, sk); c.fillStyle = P.c; for (const a of [-0.35, 0.35]) { c.save(); c.rotate(a); c.fillRect(-r * 0.22, r * 0.5, r * 0.44, r * 1.1); c.restore(); } c.fillStyle = P.b; A.star(c, 0, 0, r, 16, 0.84, 0); c.fill(); c.fillStyle = mix(P.b, P.a, 0.35); c.beginPath(); c.arc(0, 0, r * 0.74, 0, TAU); c.fill(); T(c, o.words.seal, 0, r * 0.12, r * 0.3, { col: '#ffffff', weight: 900, maxW: r * 1.3 }); c.restore(); }
    if (t > 3.1) sparkle(c, w, h * 0.7, t, 10, '#ffffff', u, 3);
  } });

def('path', { fam: 'careers', dir: true, name: 'Your next role, step by step', nameAr: 'وظيفتك القادمة خطوة بخطوة', style: 'Bold Swiss type', words: { s1: ['APPLY', 'قدّم'], s2: ['INTERVIEW', 'المقابلة'], s3: ['VISA', 'التأشيرة'], s4: ['FLY', 'سافر'], s5: ['WORK', 'اعمل'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), n = 5, wide = w > h * 0.9, prog = ez(t, 0.3, 2.8) * (n - 1);
    // Swiss: one big colour block and a hairline grid
    c.fillStyle = P.a; if (wide) c.fillRect(w * 0.74, 0, w * 0.26, h * 0.36); else c.fillRect(w * 0.72, h * 0.86, w * 0.28, h * 0.14);
    c.strokeStyle = rgba(P.ink, 0.12); c.lineWidth = Math.max(1, u * 0.002); for (let i = 1; i < 6; i++) { c.beginPath(); c.moveTo((w * i) / 6, 0); c.lineTo((w * i) / 6, h); c.stroke(); }
    const pts = [...Array(n)].map((_, i) => (wide ? [w * (0.1 + i * 0.2), h * 0.56] : [w * 0.17, h * (0.1 + i * 0.2)]));
    const r = wide ? Math.min(w * 0.07, h * 0.16) : Math.min(h * 0.075, w * 0.13);
    c.strokeStyle = P.ink; c.lineWidth = r * 0.22; c.lineCap = 'butt';
    c.beginPath(); c.moveTo(...pts[0]); const i0 = Math.floor(prog), f = prog - i0; for (let i = 1; i <= Math.min(n - 1, i0); i++) c.lineTo(...pts[i]); if (i0 < n - 1) c.lineTo(lerp(pts[i0][0], pts[i0 + 1][0], f), lerp(pts[i0][1], pts[i0 + 1][1], f)); c.stroke();
    c.strokeStyle = rgba(P.ink, 0.15); c.beginPath(); c.moveTo(...pts[0]); pts.forEach((p) => c.lineTo(...p)); c.stroke();
    pts.forEach(([x, y], i) => {
      const k = pop(t, 0.2 + i * 0.55, 0.45); if (k <= 0.01) return;
      const on = prog >= i - 0.02, last = i === n - 1;
      c.save(); c.translate(x, y); c.scale(k, k);
      c.fillStyle = last ? P.b : on ? P.d : '#ffffff'; c.fillRect(-r, -r, r * 2, r * 2);
      c.strokeStyle = P.ink; c.lineWidth = r * 0.1; c.strokeRect(-r, -r, r * 2, r * 2);
      T(c, String(i + 1), 0, r * 0.55, r * 1.55, { col: last ? P.ink : on ? '#ffffff' : P.ink, font: 'display', weight: 400 });
      c.restore();
      const lab = o.words['s' + (i + 1)];
      if (wide) T(c, lab, x, y + r * 1.9, Math.min(r * 0.5, w * 0.03), { col: P.ink, weight: 900, maxW: w * 0.19 });
      else T(c, lab, x + r * 1.5, y + r * 0.3, Math.min(r * 0.8, w * 0.085), { col: P.ink, align: 'left', weight: 900, maxW: w * 0.62 });
    });
    // the marker at the head of the line
    const hi = Math.min(n - 1, prog), j = Math.floor(hi), fj = hi - j, hx = j < n - 1 ? lerp(pts[j][0], pts[j + 1][0], fj) : pts[n - 1][0], hy = j < n - 1 ? lerp(pts[j][1], pts[j + 1][1], fj) : pts[n - 1][1];
    if (t > 0.3) { c.save(); c.translate(hx + (wide ? 0 : -r * 1.6), hy + (wide ? -r * 1.6 : 0)); c.rotate(wide ? Math.PI / 2 : 0); c.fillStyle = P.a; c.beginPath(); c.moveTo(-r * 0.45, -r * 0.4); c.lineTo(r * 0.45, 0); c.lineTo(-r * 0.45, r * 0.4); c.closePath(); c.fill(); c.restore(); }
  } });

def('handshake', { fam: 'careers', name: 'Handshake + signed contract', nameAr: 'مصافحة وعقد موقّع', style: 'Warm flat', words: { doc: ['CONTRACT', 'عقد العمل'], sign: ['Signed', 'تم التوقيع'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), cx = w / 2, cy = h * 0.36;
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.c, '#ffffff', 0.55)], [1, mix(P.b, '#ffffff', 0.5)]]);
    for (let i = 4; i >= 1; i--) { c.fillStyle = rgba(i % 2 ? '#ffffff' : P.b, 0.35); c.beginPath(); c.arc(cx, cy, u * 0.13 * i * (0.9 + 0.1 * ez(t, 0.8, 0.8)), 0, TAU); c.fill(); }
    // the contract
    const dw = Math.min(w * 0.72, h * 0.5 * 1.25), dh = dw * 0.62, dx = (w - dw) / 2, dy = h * 0.95 - dh, dk = ez(t, 0.1, 0.7);
    c.save(); c.translate(0, (1 - dk) * h * 0.3); c.translate(w / 2, dy + dh / 2); c.rotate(-0.04); c.translate(-w / 2, -(dy + dh / 2));
    shadowed(c, u * 0.04, u * 0.012, 'rgba(0,0,0,0.25)', () => { c.fillStyle = '#ffffff'; rrect(c, dx, dy, dw, dh, u * 0.012); c.fill(); });
    T(c, o.words.doc, dx + dw / 2, dy + dh * 0.17, dh * 0.1, { col: P.d, weight: 900, track: 0.1, maxW: dw * 0.9 });
    c.fillStyle = rgba(P.ink, 0.15); for (let i = 0; i < 3; i++) { rrect(c, dx + dw * 0.08, dy + dh * (0.3 + i * 0.1), dw * [0.84, 0.76, 0.8][i], dh * 0.035, dh * 0.017); c.fill(); }
    for (const k of [0, 1]) { const sx = dx + dw * (0.1 + k * 0.46), sy = dy + dh * 0.78, sp = ez(t, 1.6 + k * 0.6, 0.6); c.strokeStyle = rgba(P.ink, 0.35); c.lineWidth = u * 0.003; c.beginPath(); c.moveTo(sx, sy); c.lineTo(sx + dw * 0.36, sy); c.stroke(); if (sp > 0) { c.strokeStyle = k ? P.a : P.d; c.lineWidth = u * 0.006; c.lineCap = 'round'; c.beginPath(); for (let i = 0; i <= 30 * sp; i++) { const q = i / 30, X = sx + dw * 0.03 + q * dw * 0.3, Y = sy - dh * 0.06 + Math.sin(q * TAU * 2.5 + k) * dh * 0.05; i ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); } }
    const ck = pop(t, 2.9, 0.45); if (ck > 0.01) { c.save(); c.translate(dx + dw * 0.86, dy + dh * 0.2); c.rotate(-0.12); c.scale(ck, ck); c.strokeStyle = P.c; c.lineWidth = dh * 0.025; c.beginPath(); A.rr(c, -dw * 0.12, -dh * 0.08, dw * 0.24, dh * 0.16, dh * 0.03); c.stroke(); T(c, o.words.sign, 0, dh * 0.035, dh * 0.08, { col: P.c, weight: 900, maxW: dw * 0.22 }); c.restore(); }
    c.restore();
    // two arms meet in the middle and shake
    const k = ez(t, 0.2, 0.9), shake = t > 1.1 && t < 2.3 ? Math.sin((t - 1.1) * 14) * u * 0.012 * (1 - (t - 1.1) / 1.2) : 0, y = cy + shake;
    const arm = (side) => {
      const dir = side < 0 ? -1 : 1, off = (1 - k) * w * 0.6 * dir, wx = cx + dir * u * 0.17 + off, sleeve = side < 0 ? P.d : P.a;
      c.save(); c.strokeStyle = sleeve; c.lineWidth = u * 0.13; c.lineCap = 'butt'; c.beginPath(); c.moveTo(dir < 0 ? -u * 0.1 : w + u * 0.1, y + u * 0.2); c.lineTo(wx + dir * u * 0.02, y + u * 0.01); c.stroke();
      c.strokeStyle = '#ffffff'; c.lineWidth = u * 0.135; c.beginPath(); c.moveTo(wx + dir * u * 0.05, y + u * 0.005); c.lineTo(wx, y); c.stroke(); c.restore();
      return wx;
    };
    const lx = arm(-1), rx = arm(1), skA = SKIN[1], skB = SKIN[3];
    // the left hand: palm with the thumb on top; the right hand wraps it with four fingers
    c.save(); c.translate(cx, y); c.scale(1.45, 1.45); c.translate(-cx, -y);
    c.fillStyle = skA; rrect(c, lx - u * 0.005, y - u * 0.055, (rx - lx) * 0.62, u * 0.11, u * 0.05); c.fill();
    c.fillStyle = mix(skA, '#ffffff', 0.12); c.beginPath(); c.ellipse(lx + (rx - lx) * 0.42, y - u * 0.06, u * 0.06, u * 0.024, -0.15, 0, TAU); c.fill();
    c.fillStyle = skB; rrect(c, lx + (rx - lx) * 0.3, y - u * 0.06, (rx - lx) * 0.72, u * 0.125, u * 0.05); c.fill();
    c.strokeStyle = mix(skB, '#000000', 0.25); c.lineWidth = u * 0.006; c.lineCap = 'round';
    for (let i = 0; i < 4; i++) { const yy = y - u * 0.035 + i * u * 0.026; c.beginPath(); c.moveTo(lx + (rx - lx) * 0.33, yy); c.lineTo(lx + (rx - lx) * 0.52, yy + u * 0.004); c.stroke(); }
    c.fillStyle = mix(skB, '#ffffff', 0.1); c.beginPath(); c.ellipse(lx + (rx - lx) * 0.78, y - u * 0.07, u * 0.055, u * 0.022, 0.2, 0, TAU); c.fill();
    c.restore();
    if (t > 1.0 && t < 2.6) { c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = Math.sin(Math.PI * (t - 1) / 1.6) * 0.6; c.drawImage(A.blob('#fff3b0'), cx - u * 0.3, cy - u * 0.3, u * 0.6, u * 0.6); c.restore(); }
    if (t > 1.2) sparkle(c, w, h * 0.6, t, 8, '#ffffff', u, 11);
  } });
