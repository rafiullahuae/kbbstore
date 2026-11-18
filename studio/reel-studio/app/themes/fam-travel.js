// KBB Studio · themes · DESIGN FAMILIES · TRAVEL pieces (see families.js / fampieces.js)
import * as A from './art.js';
import { kitFor } from './kit.js';
import { T, paper, wave, rrect, shadowed } from './families.js';
import { def, ez, pop, flagOf, lmOf, CITIES, sketchLine } from './fampieces.js';

const { TAU, clamp, rnd, fr, mix, rgba } = A;

def('route', { fam: 'travel', dir: true, name: 'Road-trip route map', nameAr: 'خريطة الرحلة', style: 'Warm flat', words: { from: ['HOME', 'الوطن'], to: ['', ''] }, defaults: (K) => ({ to: (K?.city || '').toUpperCase() }),
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K, wide = w > h * 1.1;
    c.fillStyle = '#FFF6E0'; c.fillRect(0, 0, w, h);
    c.fillStyle = '#9ED8FF'; c.beginPath(); c.moveTo(0, 0); c.lineTo(w * 0.45, 0); c.quadraticCurveTo(w * 0.3, h * 0.2, w * 0.05, h * 0.34); c.lineTo(0, h * 0.36); c.fill();
    c.strokeStyle = rgba('#ffffff', 0.7); c.lineWidth = u * 0.005; for (let i = 0; i < 4; i++) { const yy = h * (0.05 + i * 0.06), xx = w * (0.02 + fr(t * 0.05 + i * 0.3) * 0.2); c.beginPath(); c.moveTo(xx, yy); c.quadraticCurveTo(xx + u * 0.03, yy - u * 0.015, xx + u * 0.06, yy); c.stroke(); }
    [[0.75, 0.3, 0.28, '#BDE8A7'], [0.3, 0.7, 0.32, '#C9EEB2'], [0.85, 0.85, 0.22, '#A8DF8E']].forEach(([x, y, r, col]) => { c.fillStyle = col; c.beginPath(); c.ellipse(w * x, h * y, u * r * 1.3, u * r * 0.8, 0.3, 0, TAU); c.fill(); });
    for (let i = 0; i < 7; i++) { const x = w * rnd(i, 3), y = h * (0.15 + 0.8 * rnd(i, 4)); c.fillStyle = mix(P.d, '#ffffff', 0.3); c.beginPath(); c.moveTo(x - u * 0.05, y); c.lineTo(x, y - u * 0.07); c.lineTo(x + u * 0.05, y); c.fill(); c.fillStyle = '#ffffff'; c.beginPath(); c.moveTo(x - u * 0.015, y - u * 0.05); c.lineTo(x, y - u * 0.07); c.lineTo(x + u * 0.015, y - u * 0.05); c.fill(); }
    for (let i = 0; i < 10; i++) { const x = w * rnd(i, 7), y = h * (0.1 + 0.85 * rnd(i, 8)); c.fillStyle = '#2B9348'; c.beginPath(); c.arc(x, y, u * 0.018, 0, TAU); c.fill(); c.fillStyle = '#8D5524'; c.fillRect(x - u * 0.003, y + u * 0.012, u * 0.006, u * 0.015); }
    // the road from home to the destination
    const C = wide ? [[0.08, 0.78], [0.3, 0.3], [0.55, 0.95], [0.86, 0.36]] : [[0.16, 0.9], [0.9, 0.7], [0.05, 0.42], [0.7, 0.28]];
    const pt = (s) => { const [a, b, cc, d] = C, m = 1 - s; return [w * (m * m * m * a[0] + 3 * m * m * s * b[0] + 3 * m * s * s * cc[0] + s * s * s * d[0]), h * (m * m * m * a[1] + 3 * m * m * s * b[1] + 3 * m * s * s * cc[1] + s * s * s * d[1])]; };
    const N = 90, q = ez(t, 0.4, 2.6);
    c.lineCap = 'round'; c.lineJoin = 'round';
    const road = () => { c.beginPath(); for (let i = 0; i <= N; i++) { const [X, Y] = pt(i / N); i ? c.lineTo(X, Y) : c.moveTo(X, Y); } };
    c.strokeStyle = '#ffffff'; c.lineWidth = u * 0.05; road(); c.stroke();
    c.strokeStyle = rgba(P.ink, 0.15); c.lineWidth = u * 0.032; road(); c.stroke();
    c.strokeStyle = P.c; c.lineWidth = u * 0.016; c.setLineDash([u * 0.03, u * 0.025]); c.beginPath(); for (let i = 0; i <= N * q; i++) { const [X, Y] = pt(i / N); i ? c.lineTo(X, Y) : c.moveTo(X, Y); } c.stroke(); c.setLineDash([]);
    const [hx, hy] = pt(0); c.fillStyle = P.d; c.beginPath(); c.arc(hx, hy, u * 0.035, 0, TAU); c.fill(); c.strokeStyle = '#ffffff'; c.lineWidth = u * 0.01; c.stroke();
    T(c, o.words.from, hx, hy + u * 0.09, u * 0.045, { col: P.ink, weight: 900 });
    const [dx, dy] = pt(1), lk = pop(t, 2.9, 0.6);
    if (lk > 0.01) { c.save(); c.translate(dx, dy); c.scale(lk, lk); c.save(); c.globalAlpha = 0.75; c.drawImage(A.blob('#ffffff'), -u * 0.22, -u * 0.34, u * 0.44, u * 0.44); c.restore(); A.landmark(c, lmOf(K), 0, 0, u * 0.32, 'gold', 1); c.restore(); }
    T(c, o.words.to || (K?.city || '').toUpperCase(), dx, dy + u * 0.08, u * 0.055, { col: P.ink, weight: 900 });
    const s = Math.min(1, q), [px, py] = pt(s), hop = Math.abs(Math.sin(t * 6)) * u * 0.02 * (s < 1 ? 1 : 0.3), pr = u * 0.05;
    c.save(); c.translate(px, py - hop); c.fillStyle = rgba('#000000', 0.2); c.beginPath(); c.ellipse(0, hop + u * 0.005, pr * 0.6, pr * 0.2, 0, 0, TAU); c.fill();
    c.fillStyle = P.a; c.beginPath(); c.moveTo(0, 0); c.bezierCurveTo(-pr * 0.2, -pr * 0.6, -pr, -pr * 1.1, -pr, -pr * 1.7); c.arc(0, -pr * 1.7, pr, Math.PI, 0); c.bezierCurveTo(pr, -pr * 1.1, pr * 0.2, -pr * 0.6, 0, 0); c.fill();
    c.fillStyle = '#ffffff'; c.beginPath(); c.arc(0, -pr * 1.7, pr * 0.42, 0, TAU); c.fill(); c.restore();
  } });

def('suitcase', { fam: 'travel', name: 'Suitcase opens onto the destination', nameAr: 'حقيبة تنفتح على وجهتك', style: 'Paper-cut', words: { tag: ['', ''] }, defaults: (K) => ({ tag: (K?.city || '').toUpperCase() }),
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K;
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.a, '#ffffff', 0.45)], [1, mix(P.b, '#ffffff', 0.55)]]);
    for (let i = 0; i < 3; i++) { const cx = w * fr(rnd(i, 9) + t * 0.02 * (i + 1)), cy = h * (0.08 + i * 0.1), r = u * (0.07 + 0.02 * i); const p2 = new Path2D(); p2.ellipse(cx, cy, r * 2, r * 0.6, 0, 0, TAU); p2.ellipse(cx + r * 0.6, cy - r * 0.35, r, r * 0.6, 0, 0, TAU); paper(c, p2, '#ffffff', u, 0.6); }
    paper(c, wave(-10, h * 0.86, w + 20, h, 0.4, h * 0.012), mix(P.d, '#ffffff', 0.25), u, 1);
    const sw = Math.min(w * 0.8, h * 0.6 * 1.3), bh = sw * 0.44, L = sw * 0.5, sx = (w - sw) / 2, base = h * 0.86, hinge = base - bh;
    const shk = t < 0.9 ? Math.sin(t * 22) * 0.025 * (1 - t / 0.9) : 0;
    c.save(); c.translate(w / 2, base); c.rotate(shk); c.translate(-w / 2, -base);
    const a = Math.PI * 0.86 * ez(t, 0.9, 1.0), cosA = Math.cos(a), lidH = L * cosA;
    // the base: what is packed inside, then its front
    c.fillStyle = mix(P.c, '#000000', 0.35); rrect(c, sx, hinge, sw, bh, u * 0.03); c.fill();
    ['#FF5FA2', '#4FD8B5', '#FFC94A', '#6C5CE7'].forEach((col, i) => { c.fillStyle = col; rrect(c, sx + sw * (0.06 + i * 0.225), hinge + bh * 0.08, sw * 0.2, bh * 0.4, u * 0.015); c.fill(); c.fillStyle = rgba('#ffffff', 0.3); c.fillRect(sx + sw * (0.06 + i * 0.225), hinge + bh * 0.2, sw * 0.2, bh * 0.03); });
    c.fillStyle = P.c; rrect(c, sx, hinge + bh * 0.5, sw, bh * 0.5, u * 0.03); c.fill();
    c.fillStyle = rgba('#ffffff', 0.22); c.fillRect(sx + u * 0.02, hinge + bh * 0.5, sw - u * 0.04, u * 0.008);
    c.fillStyle = mix(P.c, '#000000', 0.25); for (const k of [0.25, 0.75]) c.fillRect(sx + sw * k - u * 0.013, hinge + bh * 0.5, u * 0.026, bh * 0.5);
    // stickers on the front
    c.save(); c.translate(sx + sw * 0.5, hinge + bh * 0.76); c.rotate(-0.08); A.flagCloth(c, flagOf(o), K.flag, -bh * 0.22, -bh * 0.13, bh * 0.44, bh * 0.28, t, { pole: false, strips: 8, amp: 0.02 }); c.restore();
    c.fillStyle = P.b; A.star(c, sx + sw * 0.12, hinge + bh * 0.78, bh * 0.1, 5, 0.45); c.fill();
    c.fillStyle = '#ffffff'; c.beginPath(); c.arc(sx + sw * 0.88, hinge + bh * 0.76, bh * 0.1, 0, TAU); c.fill(); A.plane(c, sx + sw * 0.88, hinge + bh * 0.76, bh * 0.12, -0.6, P.d, false);
    // the lid: closed it covers the base; open it shows the destination inside
    if (lidH > 0) { c.fillStyle = P.c; rrect(c, sx, hinge - bh * 0.02, sw, lidH / L * bh * 0.52 + 1, u * 0.03); c.fill(); c.fillStyle = mix(P.c, '#000000', 0.25); for (const k of [0.25, 0.75]) c.fillRect(sx + sw * k - u * 0.013, hinge, u * 0.026, lidH / L * bh * 0.5); }
    else {
      const ih = -lidH, ly = hinge - ih;
      c.fillStyle = mix(P.c, '#000000', 0.15); rrect(c, sx, ly, sw, ih, u * 0.03); c.fill();
      c.save(); c.beginPath(); A.rr(c, sx + sw * 0.04, ly + ih * 0.07, sw * 0.92, ih * 0.86, u * 0.02); c.clip();
      A.scene(c, K, sx + sw * 0.04, ly + ih * 0.07, sw * 0.92, Math.max(1, ih * 0.86), t, K.light);
      c.restore();
      if (t > 1.4 && t < 3.4) { c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = Math.sin(Math.PI * (t - 1.4) / 2) * 0.7; c.drawImage(A.blob('#fff6c8'), w / 2 - sw * 0.6, hinge - sw * 0.7, sw * 1.2, sw * 1.2); c.restore(); }
    }
    // luggage tag with the destination
    const tg = o.words.tag || (K?.city || '').toUpperCase(), tx = sx + sw * 0.94, ty = hinge + bh * 0.55;
    c.save(); c.translate(tx, ty); c.rotate(0.12 + Math.sin(t * 2) * 0.05); c.strokeStyle = '#5C4033'; c.lineWidth = u * 0.006; c.beginPath(); c.moveTo(0, 0); c.lineTo(0, bh * 0.2); c.stroke(); c.fillStyle = P.b; rrect(c, -sw * 0.13, bh * 0.2, sw * 0.26, bh * 0.24, u * 0.012); c.fill(); c.fillStyle = '#ffffff'; c.beginPath(); c.arc(0, bh * 0.25, u * 0.008, 0, TAU); c.fill(); T(c, tg, 0, bh * 0.38, bh * 0.1, { col: P.ink, weight: 900, maxW: sw * 0.22 }); c.restore();
    c.restore();
    if (t > 1.6) A.confettiBurst(c, w / 2, hinge - L * 0.4, t - 1.6, u, [P.a, P.b, P.c, P.d, '#ffffff'], { n: 24, life: 2.4, spread: 1.1 });
  } });

def('window', { fam: 'travel', name: 'Plane window view', nameAr: 'من نافذة الطائرة', style: 'Soft gradient', words: {},
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K, wide = w > h * 1.1;
    A.gradRect(c, 0, 0, w, h, [[0, mix('#F3F6FB', P.a, 0.08)], [1, mix('#C9D6E8', P.d, 0.12)]]);
    c.strokeStyle = rgba('#8FA3BF', 0.35); c.lineWidth = u * 0.004; for (let i = 1; i < 4; i++) { c.beginPath(); c.moveTo(0, (h * i) / 4); c.lineTo(w, (h * i) / 4 + h * 0.02); c.stroke(); }
    const n = wide ? 2 : 1;
    for (let k = 0; k < n; k++) {
      const ww = wide ? Math.min(w * 0.3, h * 0.6) : Math.min(w * 0.66, h * 0.52), wh = ww * 1.36, wx = wide ? w * (0.27 + k * 0.46) - ww / 2 : (w - ww) / 2, wy = (h - wh) / 2, r = ww * 0.46;
      shadowed(c, u * 0.05, u * 0.015, 'rgba(30,40,70,0.35)', () => { c.fillStyle = '#E8EDF5'; rrect(c, wx - ww * 0.12, wy - ww * 0.12, ww * 1.24, wh + ww * 0.24, r * 1.25); c.fill(); });
      const bev = c.createLinearGradient(wx, wy, wx + ww, wy + wh); bev.addColorStop(0, '#ffffff'); bev.addColorStop(1, '#AEBBD0');
      c.fillStyle = bev; rrect(c, wx - ww * 0.05, wy - ww * 0.05, ww * 1.1, wh + ww * 0.1, r * 1.1); c.fill();
      c.save(); c.beginPath(); A.rr(c, wx, wy, ww, wh, r); c.clip();
      A.scene(c, K, wx - ww * 0.3, wy, ww * 1.6, wh, t + k * 3, K.light);
      c.fillStyle = rgba('#ffffff', 0.85); for (let i = 0; i < 3; i++) { const cx = wx + ww * (1.4 - fr(t * 0.18 + i * 0.37 + k * 0.2) * 1.9), cy = wy + wh * (0.62 + i * 0.12), cr = ww * (0.18 + i * 0.05); c.beginPath(); c.ellipse(cx, cy, cr * 1.6, cr * 0.42, 0, 0, TAU); c.ellipse(cx + cr * 0.5, cy - cr * 0.2, cr * 0.8, cr * 0.4, 0, 0, TAU); c.fill(); }
      c.fillStyle = '#D5DCE8'; c.beginPath(); c.moveTo(wx - ww * 0.1, wy + wh * 0.98); c.lineTo(wx + ww * 0.75, wy + wh * 0.8); c.lineTo(wx + ww * 0.82, wy + wh * 0.84); c.lineTo(wx - ww * 0.1, wy + wh * 1.1); c.fill();
      c.fillStyle = P.a; c.beginPath(); c.moveTo(wx + ww * 0.72, wy + wh * 0.81); c.lineTo(wx + ww * 0.8, wy + wh * 0.7); c.lineTo(wx + ww * 0.84, wy + wh * 0.72); c.lineTo(wx + ww * 0.82, wy + wh * 0.84); c.fill();
      const bl = wh * (1 - ez(t, 0.35 + k * 0.25, 1.0));
      if (bl > 0.5) { c.fillStyle = '#F4F6FA'; c.fillRect(wx, wy, ww, bl); c.fillStyle = '#C7D0DE'; c.fillRect(wx, wy + bl - ww * 0.05, ww, ww * 0.05); c.fillStyle = '#9AA7BA'; rrect(c, wx + ww * 0.42, wy + bl - ww * 0.04, ww * 0.16, ww * 0.03, ww * 0.015); c.fill(); }
      const gl = c.createLinearGradient(wx, wy, wx + ww, wy + wh); gl.addColorStop(0.2, rgba('#ffffff', 0)); gl.addColorStop(0.35, rgba('#ffffff', 0.28)); gl.addColorStop(0.5, rgba('#ffffff', 0)); c.fillStyle = gl; c.fillRect(wx, wy, ww, wh);
      c.restore();
    }
  } });

def('postcards', { fam: 'travel', name: 'Postcard stack', nameAr: 'مجموعة بطاقات بريدية', style: 'Duotone photo slots', words: { note: ['See you there!', 'نراك هناك!'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, P.d); g.addColorStop(1, P.a); c.fillStyle = g; c.fillRect(0, 0, w, h);
    c.fillStyle = rgba('#ffffff', 0.12); const gs = u * 0.045; for (let y = gs / 2; y < h; y += gs) for (let x = gs / 2; x < w; x += gs) { const r = gs * 0.32 * (0.3 + 0.7 * (x / w)); c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); }
    const pw = Math.min(w * 0.7, h * 0.44 * 1.45), ph = pw / 1.45, kits = [kitFor('europe'), kitFor('travel'), o.K];
    const spots = [[-0.2, -0.26, -0.14], [0.18, -0.05, 0.1], [0, 0.2, -0.03]];
    spots.forEach(([dx, dy, rot], i) => {
      const k = ez(t, 0.25 + i * 0.4, 0.7); if (k <= 0) return;
      const x = w / 2 + dx * pw, y = h / 2 + dy * ph * 1.6 - (1 - k) * h * 0.7;
      c.save(); c.translate(x, y); c.rotate(rot + (1 - k) * 0.6);
      shadowed(c, u * 0.04, u * 0.015, 'rgba(0,0,0,0.35)', () => { c.fillStyle = '#FFFDF6'; c.fillRect(-pw / 2, -ph / 2, pw, ph); });
      const m = pw * 0.04, sh = ph - m * 2 - (i === 2 ? ph * 0.16 : 0);
      A.scene(c, kits[i], -pw / 2 + m, -ph / 2 + m, pw - 2 * m, sh, t + i, kits[i].light);
      if (i < 2) { c.save(); c.globalCompositeOperation = 'color'; c.fillStyle = i ? P.c : P.b; c.fillRect(-pw / 2 + m, -ph / 2 + m, pw - 2 * m, sh); c.restore(); }
      if (i === 2) {
        T(c, o.words.note, -pw / 2 + m * 1.5, ph / 2 - m * 1.3, ph * 0.09, { col: P.ink, align: 'left', weight: 800, maxW: pw * 0.62 });
        const ps = pw * 0.17; c.fillStyle = '#ffffff'; c.fillRect(pw / 2 - m * 1.6 - ps, -ph / 2 + m * 1.6, ps, ps * 0.75); A.flagCloth(c, flagOf(o), o.K.flag, pw / 2 - m * 1.6 - ps * 0.92, -ph / 2 + m * 1.6 + ps * 0.08, ps * 0.84, ps * 0.6, t, { pole: false, strips: 8, amp: 0.04 });
        c.strokeStyle = rgba(P.ink, 0.55); c.lineWidth = ps * 0.03; c.beginPath(); c.arc(pw / 2 - m * 1.6 - ps * 1.05, -ph / 2 + m * 1.6 + ps * 0.6, ps * 0.32, 0, TAU); c.stroke();
      }
      c.restore();
    });
  } });

def('stamps', { fam: 'travel', name: 'Passport stamp collage', nameAr: 'جواز سفر مليء بالأختام', style: 'Sketch that colours in', words: { title: ['PASSPORT', 'جواز السفر'] }, defaults: (K) => { const c0 = (K?.city || 'TORONTO').toUpperCase(); return Object.fromEntries([c0, ...CITIES.filter((x) => x !== c0)].slice(0, 6).map((x, i) => ['city' + (i + 1), x])); },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K;
    c.fillStyle = '#FFFCF2'; c.fillRect(0, 0, w, h);
    c.strokeStyle = rgba(P.d, 0.08); c.lineWidth = Math.max(1, u * 0.002); for (let y = u * 0.04; y < h; y += u * 0.04) { c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke(); }
    const pw = Math.min(w * 0.92, h * 0.72 * 1.42), ph = pw * 0.7, px = (w - pw) / 2, py = (h - ph) / 2;
    const line = ez(t, 0.05, 1.2), fill = ez(t, 0.9, 0.9), ink = P.ink;
    if (fill > 0) { c.save(); c.globalAlpha *= fill; c.fillStyle = P.d; rrect(c, px - pw * 0.02, py - ph * 0.03, pw * 1.04, ph * 1.06, u * 0.02); c.fill(); c.fillStyle = '#FBF3DF'; rrect(c, px, py, pw / 2 - pw * 0.005, ph, u * 0.012); c.fill(); rrect(c, px + pw / 2 + pw * 0.005, py, pw / 2, ph, u * 0.012); c.fill(); c.fillStyle = mix(P.c, '#ffffff', 0.5); c.fillRect(px + pw * 0.06, py + ph * 0.24, pw * 0.14, ph * 0.38); c.restore(); }
    const L = (x0, y0, x1, y1, d, s) => sketchLine(c, x0, y0, x1, y1, clamp((line - d) * 3), s, ink, u * 0.006);
    L(px, py, px + pw, py, 0, 1); L(px + pw, py, px + pw, py + ph, 0.1, 2); L(px + pw, py + ph, px, py + ph, 0.2, 3); L(px, py + ph, px, py, 0.3, 4); L(px + pw / 2, py, px + pw / 2, py + ph, 0.35, 5);
    L(px + pw * 0.06, py + ph * 0.24, px + pw * 0.2, py + ph * 0.24, 0.45, 6); L(px + pw * 0.2, py + ph * 0.24, px + pw * 0.2, py + ph * 0.62, 0.5, 7); L(px + pw * 0.2, py + ph * 0.62, px + pw * 0.06, py + ph * 0.62, 0.55, 8); L(px + pw * 0.06, py + ph * 0.62, px + pw * 0.06, py + ph * 0.24, 0.6, 9);
    for (let i = 0; i < 4; i++) L(px + pw * 0.24, py + ph * (0.32 + i * 0.08), px + pw * (0.38 + 0.06 * rnd(i, 2)), py + ph * (0.32 + i * 0.08), 0.6 + i * 0.05, 20 + i);
    if (line > 0.4) { c.save(); c.globalAlpha *= clamp((line - 0.4) * 3); c.strokeStyle = ink; c.lineWidth = u * 0.005; c.beginPath(); c.arc(px + pw * 0.13, py + ph * 0.36, pw * 0.03, 0, TAU); c.stroke(); c.beginPath(); c.ellipse(px + pw * 0.13, py + ph * 0.56, pw * 0.05, ph * 0.07, 0, Math.PI, 0); c.stroke(); c.restore(); }
    T(c, o.words.title, px + pw * 0.25, py + ph * 0.15, ph * 0.075, { col: fill > 0.5 ? P.d : ink, weight: 900, maxW: pw * 0.4, track: 0.1 });
    const city = (K?.city || 'TORONTO').toUpperCase(), cities = [city, ...CITIES.filter((x) => x !== city)].slice(0, 6);
    const at = [[0.7, 0.3, 0.2, -0.25, 'round'], [0.82, 0.68, 0.15, 0.2, 'rect'], [0.6, 0.76, 0.14, -0.1, 'round'], [0.87, 0.22, 0.12, 0.3, 'rect'], [0.33, 0.8, 0.11, 0.15, 'round'], [0.42, 0.34, 0.1, -0.3, 'rect']];
    const cols = [P.a, P.d, P.c, '#C1121F', mix(P.d, P.a, 0.5), '#2B9348'];
    at.forEach(([x, y, r, rot, kind], i) => {
      const d = t - (1.7 + i * 0.27); if (d < -0.12) return;
      const sc = d < 0 ? 2.2 - (1 + d / 0.12) * 1.2 : 1, a = d < 0 ? clamp(1 + d / 0.12) * 0.4 : 1;
      c.save(); c.translate(px + pw * x, py + ph * y); c.scale(sc, sc); A.stampMark(c, 0, 0, ph * r, o.words['city' + (i + 1)] || cities[i], ['✈', '★', '✓'][i % 3], cols[i], rot, a * 0.9, kind); c.restore();
    });
  } });

def('departures', { fam: 'travel', name: 'Departures board', nameAr: 'لوحة المغادرة', style: 'Kinetic split-flap', words: { title: ['DEPARTURES', 'المغادرة'], status: ['BOARDING', 'صعود'] }, defaults: (K) => { const c0 = (K?.city || 'TORONTO').toUpperCase(); return Object.fromEntries([c0, ...CITIES.filter((x) => x !== c0)].slice(0, 6).map((x, i) => ['city' + (i + 1), x])); },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K, wide = w > h * 1.1;
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.a, '#ffffff', 0.25)], [1, mix(P.d, '#ffffff', 0.15)]]);
    const bw = w * 0.94, rows = wide ? 5 : 6, bx = (w - bw) / 2, hh = Math.min(h * 0.14, bw * 0.12), rh = Math.min((h * 0.9 - hh) / (rows + 0.3), bw * (wide ? 0.1 : 0.15)), bh = hh + rh * (rows + 0.3), by = (h - bh) / 2;
    shadowed(c, u * 0.05, u * 0.02, 'rgba(0,0,0,0.35)', () => { c.fillStyle = '#15171E'; rrect(c, bx, by, bw, bh, u * 0.03); c.fill(); });
    c.fillStyle = P.b; rrect(c, bx, by, bw, hh, u * 0.03); c.fill(); c.fillRect(bx, by + hh * 0.5, bw, hh * 0.5);
    A.plane(c, bx + hh * 0.7, by + hh / 2, hh * 0.5, -0.5, P.ink, false);
    T(c, o.words.title, bx + hh * 1.3, by + hh * 0.66, hh * 0.46, { col: P.ink, align: 'left', weight: 900, track: 0.08, maxW: bw * 0.6 });
    const dest = (K?.city || 'TORONTO').toUpperCase(), list = [dest, ...CITIES.filter((x) => x !== dest)].slice(0, rows).map((x, i) => String(o.words['city' + (i + 1)] || x).toUpperCase()), gates = ['A1', 'B4', 'C2', 'D7', 'A3', 'B9'];
    const nCh = 10, cw = Math.min((bw * 0.66) / (nCh + 3), rh * 0.62), gap = cw * 0.12, R = 'ABCDEFGHIJKLMNOPRSTUVWXYZ';
    list.forEach((city, r) => {
      const y = by + hh + rh * 0.25 + r * rh, chs = city.padEnd(nCh, ' ').slice(0, nCh) + ' ' + gates[r];
      [...chs].forEach((ch, i) => {
        const settle = 0.35 + r * 0.22 + i * 0.045, x = bx + bw * 0.035 + i * (cw + gap), done = t >= settle;
        const show = done ? ch : R[Math.floor(rnd(i * 7 + r, Math.floor(t * 16)) * R.length)];
        c.fillStyle = '#262A35'; rrect(c, x, y, cw, rh * 0.8, cw * 0.12); c.fill();
        c.fillStyle = '#0E1015'; c.fillRect(x, y + rh * 0.4 - 0.5, cw, Math.max(1, rh * 0.02));
        const flip = done ? 0 : fr(t * 16);
        c.save(); if (flip > 0.5) { c.translate(0, y + rh * 0.4); c.scale(1, 1 - (flip - 0.5) * 1.6); c.translate(0, -(y + rh * 0.4)); }
        if (show.trim()) T(c, show, x + cw / 2, y + rh * 0.58, rh * 0.46, { col: r === 0 && done ? P.b : '#F4F4F4', weight: 800 });
        c.restore();
      });
      if (r === 0) { const on = t > 3.0 && Math.sin(t * 5) > -0.4, sx = bx + bw * 0.035 + (nCh + 3.4) * (cw + gap), sw = bx + bw * 0.965 - sx; if (sw > cw) { c.fillStyle = on ? '#22C55E' : '#166534'; rrect(c, sx, y + rh * 0.06, sw, rh * 0.68, rh * 0.12); c.fill(); if (t > 2.9) T(c, o.words.status, sx + sw / 2, y + rh * 0.52, rh * 0.36, { col: '#ffffff', weight: 900, maxW: sw * 0.9 }); } }
      else { const sx = bx + bw * 0.035 + (nCh + 3.4) * (cw + gap), sw = bx + bw * 0.965 - sx; if (sw > cw && t > settleEnd(r)) { c.fillStyle = rgba('#ffffff', 0.12); rrect(c, sx, y + rh * 0.06, sw, rh * 0.68, rh * 0.12); c.fill(); T(c, '--:--', sx + sw / 2, y + rh * 0.52, rh * 0.36, { col: '#cfd3dc', weight: 800, maxW: sw * 0.9 }); } }
    });
    function settleEnd(r) { return 0.35 + r * 0.22 + 13 * 0.045; }
  } });
