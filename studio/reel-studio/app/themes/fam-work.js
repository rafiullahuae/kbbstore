// KBB Studio · themes · DESIGN FAMILIES · OCCUPATIONS pieces (see families.js / fampieces.js)
import * as A from './art.js';
import { T, rrect, shadowed, paper, wave, person, workerPortrait, TRADES, TRADE_IDS, SKIN, bestOn } from './families.js';
import { def, ez, pop, lerp, iso, prism } from './fampieces.js';

const { TAU, clamp, easeOut, rnd, fr, mix, rgba } = A;
const tradeName = (r, o) => (o.ar ? TRADES[r]?.ar : TRADES[r]?.en) || r;

def('worker', { fam: 'occupations', name: 'Worker portrait in uniform', nameAr: 'صورة عامل بزيّه', style: 'Warm flat', words: { role: ['', ''] }, roles: true, defaults: (K, role) => ({ role: TRADES[role || 'nurse']?.en || '' }),
  draw(c, w, h, t, o) {
    const role = o.role || 'nurse', k = ez(t, 0.15, 0.9), rise = (1 - k) * h * 0.25;
    c.save(); c.beginPath(); c.rect(0, 0, w, h); c.clip();
    c.save(); c.translate(w / 2, h / 2); const z = 1.08 - 0.08 * ez(t, 0, 1.4); c.scale(z, z); c.translate(-w / 2, -h / 2);
    workerPortrait(c, role, w, h, t, o.P, { scale: w > h * 1.1 ? 1.35 : 1.1, cx: w > h * 1.1 ? 0.5 : 0.42, noPlace: false, pose: 0 });
    c.restore();
    if (rise > 0.5) { c.globalAlpha = clamp(1 - k); c.fillStyle = o.P.paper; c.fillRect(0, 0, w, h); }
    c.restore();
    // the trade's name on a tag
    const tk = pop(t, 1.1, 0.5), u = Math.min(w, h), lab = o.words.role || tradeName(role, o);
    if (tk > 0.01 && lab) { c.save(); c.translate(w * 0.06, h * (w > h * 1.1 ? 0.9 : 0.74)); c.scale(tk, tk); c.font = `900 ${Math.round(u * 0.06)}px ${A.FONT_UI}`; const tw = Math.min(w * 0.8, c.measureText(lab).width + u * 0.08); shadowed(c, u * 0.03, u * 0.01, 'rgba(0,0,0,0.3)', () => { c.fillStyle = o.P.a; rrect(c, 0, -u * 0.075, tw, u * 0.1, u * 0.05); c.fill(); }); T(c, lab, tw / 2, -u * 0.006, u * 0.055, { col: bestOn(o.P.a), weight: 900, maxW: tw * 0.9 }); c.restore(); }
  } });

def('trades', { fam: 'occupations', dir: true, name: 'Pick your trade', nameAr: 'اختر مهنتك', style: 'Swiss grid', words: {}, defaults: () => Object.fromEntries(TRADE_IDS.map((r) => ['trade_' + r, TRADES[r].en])),
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), ratio = w / h, cols = ratio > 1.4 ? 5 : ratio > 0.9 ? 4 : 3, rows = ratio > 1.4 ? 3 : ratio > 0.9 ? 3 : ratio > 0.7 ? 4 : 4;
    c.fillStyle = P.paper; c.fillRect(0, 0, w, h);
    const g = u * 0.02, tw = (w - g * (cols + 1)) / cols, th = (h - g * (rows + 1)) / rows, n = cols * rows;
    const list = [...Array(n)].map((_, i) => TRADE_IDS[i % TRADE_IDS.length]);
    const want = Math.max(0, list.indexOf(o.role || 'welder')), sel = want;
    const hopT = clamp((t - 1.5) / 1.7), path = [0, Math.min(n - 1, cols + 1), Math.min(n - 1, 2), sel], seg = hopT * (path.length - 1), si = Math.min(path.length - 2, Math.floor(seg)), sf = seg - si;
    const cen = (i) => [g + (i % cols) * (tw + g) + tw / 2, g + Math.floor(i / cols) * (th + g) + th / 2];
    const cols4 = [P.a, P.b, P.c, P.d];
    list.forEach((role, i) => {
      const k = pop(t, 0.15 + i * 0.05, 0.5); if (k <= 0.01) return;
      const [cx, cy] = cen(i), picked = t > 3.2 && i === sel, sc = k * (picked ? 1 + 0.06 * ez(t, 3.2, 0.4) : 1);
      c.save(); c.translate(cx, cy); c.scale(sc, sc); c.translate(-tw / 2, -th / 2);
      shadowed(c, u * 0.02, u * 0.006, 'rgba(20,20,60,0.22)', () => { c.fillStyle = cols4[i % 4]; rrect(c, 0, 0, tw, th, u * 0.02); c.fill(); });
      c.save(); c.beginPath(); A.rr(c, 0, 0, tw, th, u * 0.02); c.clip();
      workerPortrait(c, role, tw, th * 0.86, t + i, P, { scale: 1.25, noPlace: false, pose: i });
      c.fillStyle = picked ? P.ink : '#ffffff'; c.fillRect(0, th * 0.8, tw, th * 0.2);
      T(c, o.words['trade_' + role] || tradeName(role, o), tw / 2, th * 0.95, Math.min(th * 0.1, tw * 0.13), { col: picked ? '#ffffff' : P.ink, weight: 900, maxW: tw * 0.92 });
      c.restore();
      if (picked) { c.strokeStyle = P.ink; c.lineWidth = u * 0.012; rrect(c, 0, 0, tw, th, u * 0.02); c.stroke(); }
      c.restore();
    });
    // the pointer hops tile to tile and picks one
    if (t > 1.3) {
      const [x0, y0] = cen(path[si]), [x1, y1] = cen(path[si + 1]), e = easeOut(sf), px = lerp(x0, x1, e) + tw * 0.2, py = lerp(y0, y1, e) + th * 0.12 - Math.sin(Math.PI * sf) * th * 0.25, s = u * 0.09;
      c.save(); c.translate(px, py); c.rotate(-0.35);
      c.fillStyle = '#ffffff'; c.strokeStyle = P.ink; c.lineWidth = s * 0.06; c.lineJoin = 'round';
      c.beginPath(); c.moveTo(0, 0); c.lineTo(0, s * 0.9); c.lineTo(s * 0.22, s * 0.7); c.lineTo(s * 0.38, s * 1.05); c.lineTo(s * 0.5, s * 1.0); c.lineTo(s * 0.34, s * 0.66); c.lineTo(s * 0.62, s * 0.64); c.closePath(); c.fill(); c.stroke();
      c.restore();
      if (t > 3.2 && t < 3.9) { const [qx, qy] = cen(sel), r = ez(t, 3.2, 0.7) * u * 0.25; c.strokeStyle = rgba(P.a, 1 - ez(t, 3.2, 0.7)); c.lineWidth = u * 0.01; c.beginPath(); c.arc(qx, qy, r, 0, TAU); c.stroke(); }
    }
  } });

def('day', { fam: 'occupations', dir: true, name: 'A day in the life', nameAr: 'يوم في حياة', style: 'Paper-cut panels', words: { p1: ['MORNING', 'صباحاً'], p2: ['AT WORK', 'في العمل'], p3: ['BREAK', 'استراحة'], p4: ['EVENING', 'مساءً'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), wide = w > h * 1.5, cols = wide ? 4 : 2, rows = wide ? 1 : 2, g = u * 0.025;
    c.fillStyle = mix(P.paper, P.d, 0.06); c.fillRect(0, 0, w, h);
    const pw = (w - g * (cols + 1)) / cols, ph = (h - g * (rows + 1)) / rows, role = o.role || 'chef';
    const skies = [['#FFB86B', '#FFE3B3'], ['#7CC6FF', '#DDF2FF'], ['#9BE7C4', '#E9FFF5'], ['#2A2A72', '#7A4BB0']];
    for (let i = 0; i < 4; i++) {
      const k = ez(t, 0.2 + i * 0.55, 0.6); if (k <= 0) continue;
      const x = g + (i % cols) * (pw + g), y = g + Math.floor(i / cols) * (ph + g) + (1 - k) * ph * 0.3;
      c.save(); c.globalAlpha *= clamp(k * 1.5);
      const clip = new Path2D(); clip.rect(x, y, pw, ph); paper(c, clip, skies[i][1], u, 1.2);
      c.save(); c.clip(clip); c.translate(x, y);
      A.gradRect(c, 0, 0, pw, ph, [[0, skies[i][0]], [1, skies[i][1]]]);
      const pu = Math.min(pw, ph);
      if (i === 0) { c.fillStyle = '#FFE066'; c.beginPath(); c.arc(pw * 0.75, ph * 0.5, pu * 0.14, 0, TAU); c.fill(); paper(c, wave(-5, ph * 0.62, pw + 10, ph, 1, ph * 0.03), '#F4A261', u, 0.6); c.fillStyle = P.a; rrect(c, pw * (0.5 - fr(t * 0.08) * 0.1), ph * 0.48, pw * 0.42, ph * 0.18, pu * 0.03); c.fill(); c.fillStyle = '#BDE0FE'; for (let j = 0; j < 4; j++) c.fillRect(pw * (0.53 + j * 0.09 - fr(t * 0.08) * 0.1), ph * 0.51, pw * 0.06, ph * 0.06); person(c, pw * 0.25, ph * 0.98, ph * 0.62, { top: P.d, step: t * 0.8, hair: 'short', skin: SKIN[2] }); }
      if (i === 1) workerPortrait(c, role, pw, ph, t, P, { scale: 1.1, pose: 1 });
      if (i === 2) { paper(c, wave(-5, ph * 0.75, pw + 10, ph, 2, ph * 0.02), '#55A630', u, 0.6); c.fillStyle = '#8D6E63'; c.fillRect(pw * 0.1, ph * 0.66, pw * 0.8, ph * 0.04); for (const [k2, top] of [[0.32, TRADES[role]?.top || P.c], [0.68, P.a]]) person(c, pw * k2, ph * 1.02, ph * 0.6, { top, hair: k2 > 0.5 ? 'hijab' : 'short', hijab: P.b, skin: SKIN[k2 > 0.5 ? 0 : 3], arms: [[Math.PI / 2 + 0.3, -0.6], [Math.PI / 2 - 0.2, Math.PI / 2 - 0.1]], hold: (cc, [[hx, hy]], s) => { cc.fillStyle = '#ffffff'; rrect(cc, hx - s * 0.04, hy - s * 0.07, s * 0.08, s * 0.08, s * 0.01); cc.fill(); cc.strokeStyle = rgba('#ffffff', 0.7); cc.lineWidth = s * 0.008; const st = fr(t * 0.7); cc.globalAlpha = 1 - st; cc.beginPath(); cc.moveTo(hx, hy - s * 0.08); cc.quadraticCurveTo(hx - s * 0.02, hy - s * 0.12 - st * s * 0.05, hx + s * 0.01, hy - s * 0.16 - st * s * 0.05); cc.stroke(); cc.globalAlpha = 1; } }); }
      if (i === 3) { c.fillStyle = '#ffffff'; for (let j = 0; j < 20; j++) { c.globalAlpha = 0.4 + 0.6 * Math.abs(Math.sin(t * 2 + j)); c.fillRect(rnd(j, 1) * pw, rnd(j, 2) * ph * 0.5, 2, 2); } c.globalAlpha = 1; c.fillStyle = '#FFF3C4'; c.beginPath(); c.arc(pw * 0.8, ph * 0.2, pu * 0.08, 0, TAU); c.fill(); c.fillStyle = '#5A4FCF'; c.fillRect(pw * 0.05, ph * 0.35, pw * 0.4, ph * 0.65); c.fillStyle = '#FFD166'; for (let j = 0; j < 4; j++) c.fillRect(pw * (0.09 + (j % 2) * 0.18), ph * (0.45 + Math.floor(j / 2) * 0.2), pw * 0.12, ph * 0.12); const pp = person(c, pw * 0.68, ph * 1.02, ph * 0.66, { top: TRADES[role]?.top || P.c, hair: 'short', skin: SKIN[1], arms: [[Math.PI / 2 + 0.15, Math.PI / 2 + 0.05], [Math.PI / 2 - 0.6, -1.6]], hold: (cc, [, [hx, hy]], s) => { cc.fillStyle = '#22223B'; rrect(cc, hx - s * 0.03, hy - s * 0.1, s * 0.06, s * 0.11, s * 0.012); cc.fill(); cc.fillStyle = '#9BE7FF'; cc.fillRect(hx - s * 0.022, hy - s * 0.09, s * 0.044, s * 0.08); } }); const hk = 0.8 + 0.2 * Math.sin(t * 4); c.fillStyle = P.a; const hx = pp.head[0] + ph * 0.12, hy = pp.head[1] - ph * 0.08, r = pu * 0.035 * hk; c.beginPath(); c.moveTo(hx, hy + r); c.bezierCurveTo(hx - r * 2, hy - r * 0.5, hx - r, hy - r * 2, hx, hy - r * 0.6); c.bezierCurveTo(hx + r, hy - r * 2, hx + r * 2, hy - r * 0.5, hx, hy + r); c.fill(); }
      // the panel's label
      const lab = o.words['p' + (i + 1)], ls = Math.min(ph * 0.08, pw * 0.09);
      c.font = `900 ${Math.round(ls)}px ${A.FONT_UI}`; const lw = Math.min(pw * 0.86, c.measureText(lab || '').width + ls * 1.4);
      c.fillStyle = '#ffffff'; rrect(c, pw * 0.05, ph * 0.05, lw, ls * 1.6, ls * 0.4); c.fill();
      T(c, lab, pw * 0.05 + lw / 2, ph * 0.05 + ls * 1.15, ls, { col: P.ink, weight: 900, maxW: lw * 0.9 });
      c.restore(); c.restore();
    }
    // the clock of the day
    const ck = pop(t, 0.1, 0.5), cr = u * 0.09, cx = w / 2, cy = wide ? h - cr * 1.15 : h / 2;
    if (ck > 0.01) { c.save(); c.translate(cx, cy); c.scale(ck, ck); shadowed(c, u * 0.03, u * 0.01, 'rgba(0,0,0,0.25)', () => { c.fillStyle = '#ffffff'; c.beginPath(); c.arc(0, 0, cr, 0, TAU); c.fill(); }); c.strokeStyle = P.d; c.lineWidth = cr * 0.12; c.beginPath(); c.arc(0, 0, cr * 0.9, 0, TAU); c.stroke(); for (let j = 0; j < 12; j++) { const a = (j / 12) * TAU; c.fillStyle = P.ink; c.beginPath(); c.arc(Math.cos(a) * cr * 0.7, Math.sin(a) * cr * 0.7, cr * 0.04, 0, TAU); c.fill(); } const a = -Math.PI / 2 + clamp(t / 3.6) * TAU * 0.9 + (t > 3.6 ? (t - 3.6) * 0.3 : 0); c.strokeStyle = P.a; c.lineWidth = cr * 0.1; c.lineCap = 'round'; c.beginPath(); c.moveTo(0, 0); c.lineTo(Math.cos(a) * cr * 0.62, Math.sin(a) * cr * 0.62); c.stroke(); c.strokeStyle = P.ink; c.beginPath(); c.moveTo(0, 0); c.lineTo(Math.cos(a / 12 - 1) * cr * 0.42, Math.sin(a / 12 - 1) * cr * 0.42); c.stroke(); c.restore(); }
  } });

def('morph', { fam: 'occupations', name: 'Uniforms that morph', nameAr: 'زيّ يتحوّل من مهنة لأخرى', style: 'Duotone', words: {}, defaults: () => Object.fromEntries(TRADE_IDS.map((r) => ['trade_' + r, TRADES[r].en])), roles: true,
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), first = o.role || 'nurse', base = TRADE_IDS.filter((r) => r !== first);
    const seq = [base[2], base[5], base[8], first, base[0], base[3], base[6], base[9], base[1], base[4], base[7], base[10]].filter(Boolean);
    const per = 1.2, ph = (t + 0.6) / per, i = Math.floor(ph) % seq.length, f = fr(ph), cur = seq[i], prev = seq[(i + seq.length - 1) % seq.length];
    // duotone ground: two colours and a halftone
    const g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, P.d); g.addColorStop(1, mix(P.d, P.a, 0.65)); c.fillStyle = g; c.fillRect(0, 0, w, h);
    c.fillStyle = rgba(P.b, 0.22); const gs = u * 0.04; for (let y = gs / 2; y < h; y += gs) for (let x = gs / 2; x < w; x += gs) { const r = gs * 0.45 * clamp(1 - Math.hypot(x - w / 2, y - h * 0.45) / (u * 0.75)); if (r > 0.5) { c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); } }
    const R = Math.min(w * 0.46, h * 0.4), cx = w / 2, cy = h * 0.46;
    c.fillStyle = mix(P.b, '#ffffff', 0.3); c.beginPath(); c.arc(cx, cy, R, 0, TAU); c.fill();
    const fig = (role) => { c.save(); c.beginPath(); c.arc(cx, cy, R * 0.96, 0, TAU); c.rect(cx - R, cy, R * 2, h - cy); c.clip(); c.translate(cx - R, cy - R); workerPortrait(c, role, R * 2, R * 2, t, P, { noPlace: true, scale: 1.05, pose: 2 }); c.restore(); };
    const wipe = clamp(f / 0.3);
    if (wipe < 1) { fig(prev); c.save(); c.beginPath(); c.arc(cx, cy + R * 0.3, Math.max(0.1, wipe * R * 2.2), 0, TAU); c.clip(); fig(cur); c.restore(); c.strokeStyle = rgba('#ffffff', 1 - wipe); c.lineWidth = u * 0.012; c.beginPath(); c.arc(cx, cy + R * 0.3, wipe * R * 2.2, 0, TAU); c.stroke(); }
    else fig(cur);
    // the trade's name, big
    const lab = String(o.words['trade_' + cur] || tradeName(cur, o)).toUpperCase(), fs = Math.min(u * 0.12, w * 0.14), k = clamp(f / 0.25);
    c.save(); c.globalAlpha *= k; c.fillStyle = P.b; const by = Math.min(h - fs * 0.6, cy + R + fs * 0.9); rrect(c, w * 0.08, by - fs * 1.05, w * 0.84, fs * 1.4, fs * 0.25); c.fill(); T(c, lab, w / 2, by, fs, { col: P.ink, font: o.ar ? 'ui' : 'display', weight: o.ar ? 900 : 400, maxW: w * 0.78 }); c.restore();
  } });

def('isosite', { fam: 'occupations', name: 'Isometric work site', nameAr: 'موقع عمل ثلاثي الأبعاد', style: 'Isometric 3D', words: {},
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.c, '#ffffff', 0.6)], [1, mix(P.b, '#ffffff', 0.55)]]);
    const k = Math.min(w / 15.5, h / 11.5), P3 = iso(w / 2, h * 0.56, k);
    // the ground slab and its grid
    prism(c, P3, -4, -4, -0.4, 8, 8, 0.4, '#D9E6C3');
    c.strokeStyle = rgba('#ffffff', 0.6); c.lineWidth = Math.max(1, u * 0.002); for (let i = -4; i <= 4; i++) { let [a, b] = P3(i, -4), [x2, y2] = P3(i, 4); c.beginPath(); c.moveTo(a, b); c.lineTo(x2, y2); c.stroke(); [a, b] = P3(-4, i); [x2, y2] = P3(4, i); c.beginPath(); c.moveTo(a, b); c.lineTo(x2, y2); c.stroke(); }
    // a road across it
    c.fillStyle = '#5C677D'; const rd = [P3(-4, 1), P3(4, 1), P3(4, 2), P3(-4, 2)]; c.beginPath(); rd.forEach(([X, Y], i) => (i ? c.lineTo(X, Y) : c.moveTo(X, Y))); c.fill();
    // buildings rise one by one: a warehouse, racks, a building in scaffolding
    const blocks = [[-3.5, -3.5, 3, 2.4, 1.8, P.d, '#BDE0FE'], [0.5, -3.5, 1.4, 1.4, 3.2, P.a, '#FFE7A8'], [2.4, -3.5, 1.4, 2.4, 2.2, P.c, '#ffffff'], [-3.5, 2.6, 1.6, 1.2, 1.2, P.b, null], [1.5, 2.6, 2.4, 1.2, 0.8, '#C8A26B', null]];
    blocks.forEach(([x, y, sx, sy, sz, col, win], i) => { const g2 = ez(t, 0.2 + i * 0.3, 0.8); if (g2 > 0.01) prism(c, P3, x, y, 0, sx, sy, sz * g2, col, win); });
    // scaffolding lines on the tall block, a crane above
    if (t > 1.3) { const [cx0, cy0] = P3(0.5, -3.5, 3.2), [cx1, cy1] = P3(0.5, -3.5, 5.2); c.strokeStyle = P.b; c.lineWidth = u * 0.012; c.beginPath(); c.moveTo(cx0, cy0); c.lineTo(cx1, cy1); const [jx, jy] = P3(-2.5 + Math.sin(t * 0.6) * 0.8, -1, 5.1); c.lineTo(jx, jy); c.stroke(); c.strokeStyle = '#495057'; c.lineWidth = u * 0.003; c.beginPath(); c.moveTo(jx, jy); c.lineTo(jx, jy + k * 1.2); c.stroke(); c.fillStyle = '#E76F51'; c.fillRect(jx - k * 0.3, jy + k * 1.2, k * 0.6, k * 0.25); }
    // little workers and a forklift on the move
    for (let i = 0; i < 5; i++) { const s = fr(t * 0.08 + i * 0.21), x = -3.8 + s * 7.6, y = 1.2 + (i % 2) * 0.55, [X, Y] = P3(x, y); c.fillStyle = ['#FF8C42', '#3A86FF', '#2EC4B6', '#FF5FA2', '#FFD23F'][i]; rrect(c, X - k * 0.08, Y - k * 0.42, k * 0.16, k * 0.3, k * 0.06); c.fill(); c.fillStyle = SKIN[i % 4]; c.beginPath(); c.arc(X, Y - k * 0.5, k * 0.08, 0, TAU); c.fill(); c.fillStyle = '#FFD60A'; c.beginPath(); c.ellipse(X, Y - k * 0.56, k * 0.09, k * 0.05, 0, Math.PI, 0); c.fill(); }
    const fs = fr(t * 0.1), fx = 3.6 - fs * 7, [FX, FY] = P3(fx, 1.5); prism(c, (x, y, z) => P3(x + fx - 0.3, y + 1.3, z), 0, 0, 0, 0.7, 0.45, 0.4, '#FFB703'); c.fillStyle = '#22223B'; c.fillRect(FX - k * 0.5, FY - k * 0.8, k * 0.06, k * 0.6);
  } });

def('duotone', { fam: 'occupations', name: 'Duotone photo frame', nameAr: 'إطار صورة بلونين', style: 'Duotone photo slots', words: {}, over: true,
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    // over a photo: map it to two colours, then a bold offset frame and halftone corners
    c.save(); c.globalCompositeOperation = 'color'; const g = c.createLinearGradient(0, 0, 0, h); g.addColorStop(0, P.d); g.addColorStop(1, P.a); c.fillStyle = g; c.fillRect(0, 0, w, h); c.restore();
    c.save(); c.globalCompositeOperation = 'soft-light'; c.fillStyle = rgba(P.b, 0.5); c.fillRect(0, 0, w, h); c.restore();
    const k = ez(t, 0.3, 0.9);
    c.strokeStyle = P.b; c.lineWidth = u * 0.03; c.strokeRect(u * 0.05 + (1 - k) * u * 0.1, u * 0.05 + (1 - k) * u * 0.1, w - u * 0.1, h - u * 0.1);
    c.fillStyle = rgba('#ffffff', 0.35); const gs = u * 0.03;
    for (let y = gs; y < u * 0.35; y += gs) for (let x = gs; x < u * 0.35; x += gs) { const r = gs * 0.4 * clamp(1 - Math.hypot(x, y) / (u * 0.35)) * k; if (r > 0.4) { c.beginPath(); c.arc(w - x, y, r, 0, TAU); c.fill(); c.beginPath(); c.arc(x, h - y, r, 0, TAU); c.fill(); } }
  } });
