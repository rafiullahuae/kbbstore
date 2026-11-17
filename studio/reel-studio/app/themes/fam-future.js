// KBB Studio · themes · DESIGN FAMILIES · BRIGHT FUTURE pieces (see families.js / fampieces.js)
import * as A from './art.js';
import { T, rrect, shadowed, paper, wave, person, SKIN, bestOn } from './families.js';
import { def, ez, pop, lerp, lmOf, sketchLine, iso, prism, mortar, burst, sparkle } from './fampieces.js';

const { TAU, clamp, easeOut, easeIO, rnd, fr, mix, rgba } = A;
const heart = (c, x, y, r) => { c.beginPath(); c.moveTo(x, y + r); c.bezierCurveTo(x - r * 2, y - r * 0.5, x - r, y - r * 2, x, y - r * 0.6); c.bezierCurveTo(x + r, y - r * 2, x + r * 2, y - r * 0.5, x, y + r); c.fill(); };

def('sunrise', { fam: 'future', name: 'Sunrise over your new skyline', nameAr: 'شروق فوق مدينتك الجديدة', style: 'Soft gradient', words: {},
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K, k = ez(t, 0, 3.2), hz = h * 0.7;
    const top = mix(mix(P.sky, '#0B0F3A', 0.5), mix(P.d, '#7FB8FF', 0.5), k), mid = mix('#4B2D7F', mix(P.a, '#FF9E7A', 0.4), k), low = mix('#7A3B69', mix(P.b, '#FFE7A0', 0.3), k);
    A.gradRect(c, 0, 0, w, hz, [[0, top], [0.6, mid], [1, low]]);
    // the sun rises, its rays turn slowly
    const sy = lerp(hz + u * 0.1, hz - h * 0.3, easeOut(clamp(t / 3.2))), sr = u * 0.13;
    c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = 0.35 * k + 0.1; burst(c, w / 2, sy, Math.hypot(w, h), t, '#FFF1C1', 20, 0.25); c.globalAlpha = 0.9; c.drawImage(A.blob('#FFD580'), w / 2 - sr * 4, sy - sr * 4, sr * 8, sr * 8); c.restore();
    c.save(); c.beginPath(); c.rect(0, 0, w, hz); c.clip(); c.fillStyle = '#FFF4CF'; c.beginPath(); c.arc(w / 2, sy, sr, 0, TAU); c.fill(); c.restore();
    // the city: a far skyline, the landmarks
    c.fillStyle = mix(mid, '#2A1B4A', 0.55); c.beginPath(); c.moveTo(0, hz); let x = 0, i = 0; while (x < w) { const bw = u * (0.04 + 0.05 * rnd(i, 4)), bh = u * (0.05 + 0.16 * rnd(i, 5)); c.lineTo(x, hz - bh); c.lineTo(x + bw, hz - bh); x += bw; i++; } c.lineTo(w, hz); c.fill();
    const sk = K?.skyline?.length ? K.skyline.slice(0, 3) : [K?.secondary, lmOf(K)].filter(Boolean);
    sk.forEach((id, j) => A.landmark(c, id, w * (sk.length === 1 ? 0.5 : 0.18 + (0.64 * j) / Math.max(1, sk.length - 1)), hz, u * (j === Math.floor(sk.length / 2) ? 0.5 : 0.36), 'gold', 0.95 * ez(t, 0.4 + j * 0.2, 0.8)));
    if (K?.landmark && !K?.skyline) A.landmark(c, K.landmark, w * 0.5, hz, u * 0.55, 'gold', ez(t, 0.4, 0.8));
    // water with the sun's path on it
    A.gradRect(c, 0, hz, w, h - hz, [[0, mix(low, P.d, 0.4)], [1, mix(P.d, '#1B1446', 0.5)]]);
    c.fillStyle = rgba('#FFF1C1', 0.6); for (let j = 0; j < 14; j++) { const yy = hz + (h - hz) * (0.06 + j * 0.065), ww = u * (0.2 - j * 0.008) * (0.7 + 0.3 * Math.sin(t * 2 + j)); c.fillRect(w / 2 - ww / 2 + Math.sin(t + j) * u * 0.02, yy, ww, Math.max(1, u * 0.006)); }
    c.strokeStyle = rgba('#2A1B4A', 0.7); c.lineWidth = u * 0.005; c.lineCap = 'round'; for (let j = 0; j < 4; j++) { const bx = w * fr(0.2 + j * 0.17 + t * 0.04), by = h * (0.18 + 0.05 * Math.sin(j * 2 + t)), s = u * 0.018, fl = Math.sin(t * 8 + j) * s * 0.6; c.beginPath(); c.moveTo(bx - s, by - fl); c.quadraticCurveTo(bx - s * 0.4, by - s * 0.2, bx, by); c.quadraticCurveTo(bx + s * 0.4, by - s * 0.2, bx + s, by - fl); c.stroke(); }
  } });

def('door', { fam: 'future', name: 'A door opens onto a new country', nameAr: 'باب ينفتح على بلد جديد', style: 'Paper-cut', words: { mat: ['WELCOME', 'أهلاً'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K;
    c.fillStyle = mix(P.c, '#ffffff', 0.35); c.fillRect(0, 0, w, h);
    c.fillStyle = rgba('#ffffff', 0.18); for (let i = 0; i < 9; i++) c.fillRect(0, (h * i) / 9, w, u * 0.004);
    const fl = h * 0.86; c.fillStyle = mix(P.b, '#C68863', 0.4); c.fillRect(0, fl, w, h - fl);
    const dw = Math.min(w * 0.5, h * 0.36), dh = Math.min(dw * 2, fl * 0.86), dx = (w - dw) / 2, dy = fl - dh;
    // beyond the door: the new country
    c.save(); c.beginPath(); c.rect(dx, dy, dw, dh); c.clip(); A.scene(c, K, dx - dw * 0.4, dy, dw * 1.8, dh, t, K.light); c.restore();
    const a = 1.3 * easeIO(clamp((t - 0.5) / 1.4)), open = Math.sin(a);
    // light spills onto the floor
    if (open > 0.02) { c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = 0.55 * open; const g = c.createLinearGradient(0, fl, 0, h); g.addColorStop(0, '#FFE8A3'); g.addColorStop(1, 'rgba(255,232,163,0)'); c.fillStyle = g; c.beginPath(); c.moveTo(dx, fl); c.lineTo(dx + dw, fl); c.lineTo(dx + dw * 1.6, h); c.lineTo(dx - dw * 0.6, h); c.closePath(); c.fill(); c.drawImage(A.blob('#FFF3C4'), dx - dw * 0.4, dy - dw * 0.2, dw * 1.8, dh * 1.1); c.restore(); }
    // the frame (paper layers)
    const fr2 = new Path2D(); fr2.rect(dx - dw * 0.1, dy - dw * 0.1, dw * 1.2, dh + dw * 0.1); fr2.rect(dx, dy, dw, dh); c.save(); c.shadowColor = 'rgba(30,20,60,0.35)'; c.shadowBlur = u * 0.03; c.shadowOffsetY = u * 0.01; c.fillStyle = P.paper; c.fill(fr2, 'evenodd'); c.restore();
    // the door swings toward us on its left hinge (its free edge grows as it comes closer)
    const cw = dw * Math.cos(a), grow = 1 + 0.22 * open, top = dy - (dh * (grow - 1)) / 2;
    if (cw > 1) {
      const doorCol = mix(P.a, '#000000', 0.08 * open);
      c.fillStyle = doorCol; c.beginPath(); c.moveTo(dx, dy); c.lineTo(dx + cw, top); c.lineTo(dx + cw, top + dh * grow); c.lineTo(dx, dy + dh); c.closePath(); c.fill();
      c.strokeStyle = rgba('#ffffff', 0.3); c.lineWidth = u * 0.006; for (const [y0, y1] of [[0.08, 0.45], [0.55, 0.92]]) { c.beginPath(); c.moveTo(dx + cw * 0.15, lerp(dy, top, 0.15) + dh * lerp(1, grow, 0.15) * y0); c.lineTo(dx + cw * 0.85, lerp(dy, top, 0.85) + dh * lerp(1, grow, 0.85) * y0); c.lineTo(dx + cw * 0.85, lerp(dy, top, 0.85) + dh * lerp(1, grow, 0.85) * y1); c.lineTo(dx + cw * 0.15, lerp(dy, top, 0.15) + dh * lerp(1, grow, 0.15) * y1); c.closePath(); c.stroke(); }
      c.fillStyle = P.b; c.beginPath(); c.arc(dx + cw * 0.86, lerp(dy, top, 0.86) + dh * lerp(1, grow, 0.86) * 0.52, u * 0.016, 0, TAU); c.fill();
    }
    // a plant each side and the mat
    for (const s of [-1, 1]) { const px = w / 2 + s * dw * 0.95; c.fillStyle = '#E76F51'; c.beginPath(); c.moveTo(px - u * 0.05, fl); c.lineTo(px - u * 0.06, fl - u * 0.09); c.lineTo(px + u * 0.06, fl - u * 0.09); c.lineTo(px + u * 0.05, fl); c.fill(); for (let j = 0; j < 5; j++) { const p2 = new Path2D(); const ang = -Math.PI / 2 + (j - 2) * 0.35 + Math.sin(t * 1.5 + j) * 0.04; p2.ellipse(px + Math.cos(ang) * u * 0.07, fl - u * 0.09 + Math.sin(ang) * u * 0.07, u * 0.025, u * 0.07, ang + Math.PI / 2, 0, TAU); paper(c, p2, j % 2 ? '#2B9348' : '#55A630', u, 0.4); } }
    const mk = pop(t, 1.6, 0.5); if (mk > 0.01) { c.save(); c.translate(w / 2, fl + (h - fl) * 0.45); c.scale(mk, mk); c.fillStyle = P.d; rrect(c, -dw * 0.55, -(h - fl) * 0.28, dw * 1.1, (h - fl) * 0.56, u * 0.015); c.fill(); T(c, o.words.mat, 0, (h - fl) * 0.12, Math.min((h - fl) * 0.32, dw * 0.16), { col: '#ffffff', weight: 900, maxW: dw, track: 0.1 }); c.restore(); }
    if (t > 1.8) sparkle(c, w, fl, t, 10, '#ffffff', u, 21);
  } });

def('tree', { fam: 'future', name: 'Growing tree of goals', nameAr: 'شجرة الأهداف', style: 'Sketch that colours in', words: { g1: ['Skills', 'مهارات'], g2: ['Career', 'مسيرة'], g3: ['Home', 'بيت'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), ink = P.ink, gy = h * 0.88, cx = w / 2, s = Math.min(h * 0.78, w * 1.05);
    c.fillStyle = '#FFFCF4'; c.fillRect(0, 0, w, h);
    c.fillStyle = mix(P.b, '#ffffff', 0.4); c.beginPath(); c.arc(w * 0.82, h * 0.16, u * 0.08, 0, TAU); c.fill();
    const col = ez(t, 1.4, 1.2);
    c.save(); c.globalAlpha *= col; c.fillStyle = mix(P.c, '#ffffff', 0.25); c.beginPath(); c.ellipse(cx, gy + h * 0.06, w * 0.7, h * 0.09, 0, Math.PI, 0); c.lineTo(w, h); c.lineTo(0, h); c.fill(); c.restore();
    sketchLine(c, 0, gy, w, gy - h * 0.01, ez(t, 0, 0.5), 3, ink, u * 0.005);
    // trunk and branches draw as pencil lines, then fill with colour
    const g = clamp(t / 2.2), trunkTop = gy - s * 0.45 * easeOut(g * 1.6);
    if (col > 0) { c.save(); c.globalAlpha *= col; c.fillStyle = '#8D5524'; c.beginPath(); c.moveTo(cx - s * 0.035, gy); c.quadraticCurveTo(cx - s * 0.02, (gy + trunkTop) / 2, cx - s * 0.015, trunkTop); c.lineTo(cx + s * 0.015, trunkTop); c.quadraticCurveTo(cx + s * 0.02, (gy + trunkTop) / 2, cx + s * 0.035, gy); c.fill(); c.restore(); }
    sketchLine(c, cx - s * 0.03, gy, cx - s * 0.015, trunkTop, 1, 4, ink, u * 0.006); sketchLine(c, cx + s * 0.03, gy, cx + s * 0.015, trunkTop, 1, 5, ink, u * 0.006);
    const br = [[-0.18, -0.62, 0.3], [0.2, -0.66, 0.4], [-0.08, -0.78, 0.55], [0.1, -0.82, 0.6]];
    br.forEach(([bx, by, d], i) => sketchLine(c, cx, gy - s * (0.3 + i * 0.05), cx + s * bx, gy + s * by, clamp((t - 0.6 - d) / 0.6), 10 + i, ink, u * 0.005));
    // leaves pop
    const leaves = [[0, -0.86, 0.2], [-0.18, -0.68, 0.16], [0.2, -0.7, 0.17], [-0.08, -0.8, 0.15], [0.1, -0.84, 0.15], [-0.25, -0.58, 0.12], [0.27, -0.6, 0.12]];
    leaves.forEach(([lx, ly, lr], i) => { const k = pop(t, 1.2 + i * 0.12, 0.5); if (k <= 0.01) return; const x = cx + s * lx, y = gy + s * ly, r = s * lr * k; c.save(); c.globalAlpha *= 0.4 + 0.6 * col; c.fillStyle = ['#55A630', '#80B918', '#2B9348'][i % 3]; c.beginPath(); c.arc(x, y + Math.sin(t * 1.4 + i) * u * 0.003, r, 0, TAU); c.fill(); c.restore(); c.strokeStyle = rgba(ink, 0.7); c.lineWidth = u * 0.004; c.beginPath(); c.arc(x + u * 0.003, y, r * 0.98, 0.3, TAU - 0.2); c.stroke(); });
    // goal fruits with their words
    const fruits = [[-0.17, -0.66, P.a, 'g1'], [0.19, -0.7, P.d, 'g2'], [0.0, -0.9, P.b, 'g3']];
    fruits.forEach(([fx, fy, fc, key], i) => {
      const k = pop(t, 2.4 + i * 0.25, 0.5); if (k <= 0.01) return;
      const x = cx + s * fx, y = gy + s * fy, r = s * 0.045 * k;
      c.fillStyle = fc; c.beginPath(); c.arc(x, y, r, 0, TAU); c.fill(); c.fillStyle = rgba('#ffffff', 0.5); c.beginPath(); c.arc(x - r * 0.35, y - r * 0.35, r * 0.25, 0, TAU); c.fill();
      const word = o.words[key]; if (!word) return; const fs = s * 0.042; c.font = `900 ${Math.round(fs)}px ${A.FONT_UI}`; const tw = Math.min(w * 0.4, c.measureText(word).width + fs * 1.2), lx = x + (fx < 0 ? -r - tw - u * 0.01 : fx > 0 ? r + u * 0.01 : -tw / 2), ly = fx === 0 ? y - r - fs * 1.7 : y - fs * 0.75;
      c.save(); c.globalAlpha *= k; c.fillStyle = '#ffffff'; rrect(c, lx, ly, tw, fs * 1.5, fs * 0.4); c.fill(); c.strokeStyle = fc; c.lineWidth = u * 0.005; c.stroke(); T(c, word, lx + tw / 2, ly + fs * 1.08, fs, { col: ink, weight: 900, maxW: tw * 0.9 }); c.restore();
    });
  } });

def('home', { fam: 'future', name: 'Home key + family', nameAr: 'مفتاح البيت ولمّ الشمل', style: 'Warm flat', words: { sign: ['HOME', 'بيتنا'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), gy = h * 0.84;
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.d, '#ffffff', 0.25)], [0.6, mix(P.a, '#ffffff', 0.45)], [1, mix(P.b, '#ffffff', 0.4)]]);
    c.fillStyle = '#9BD770'; c.fillRect(0, gy, w, h - gy);
    const hw = Math.min(w * 0.62, h * 0.5), hh = hw * 0.62, hx = (w - hw) / 2, hy = gy - hh;
    // the house
    c.fillStyle = P.paper; c.fillRect(hx, hy, hw, hh);
    c.fillStyle = P.a; c.beginPath(); c.moveTo(hx - hw * 0.08, hy); c.lineTo(w / 2, hy - hw * 0.38); c.lineTo(hx + hw * 1.08, hy); c.closePath(); c.fill();
    c.fillStyle = mix(P.a, '#000000', 0.2); c.fillRect(hx + hw * 0.72, hy - hw * 0.3, hw * 0.09, hw * 0.18);
    for (let j = 0; j < 3; j++) { const st = fr(t * 0.4 + j / 3); c.fillStyle = rgba('#ffffff', 0.6 * (1 - st)); c.beginPath(); c.arc(hx + hw * 0.765 + Math.sin(st * 6 + j) * u * 0.01, hy - hw * 0.32 - st * hw * 0.3, u * (0.02 + st * 0.03), 0, TAU); c.fill(); }
    const lit = ez(t, 1.4, 0.6);
    for (const k of [0.12, 0.66]) { c.fillStyle = mix('#5A6B8C', '#FFD166', lit); c.fillRect(hx + hw * k, hy + hh * 0.18, hw * 0.22, hh * 0.3); c.strokeStyle = '#ffffff'; c.lineWidth = u * 0.006; c.strokeRect(hx + hw * k, hy + hh * 0.18, hw * 0.22, hh * 0.3); c.beginPath(); c.moveTo(hx + hw * (k + 0.11), hy + hh * 0.18); c.lineTo(hx + hw * (k + 0.11), hy + hh * 0.48); c.stroke(); }
    if (lit > 0) { c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = 0.45 * lit; for (const k of [0.23, 0.77]) c.drawImage(A.blob('#FFD166'), hx + hw * k - hw * 0.2, hy + hh * 0.1, hw * 0.4, hw * 0.4); c.restore(); }
    c.fillStyle = P.d; rrect(c, hx + hw * 0.41, hy + hh * 0.4, hw * 0.18, hh * 0.6, u * 0.01); c.fill(); c.fillStyle = P.b; c.beginPath(); c.arc(hx + hw * 0.55, hy + hh * 0.72, u * 0.008, 0, TAU); c.fill();
    c.fillStyle = P.b; rrect(c, w / 2 - hw * 0.14, hy - hw * 0.1, hw * 0.28, hw * 0.1, u * 0.01); c.fill(); T(c, o.words.sign, w / 2, hy - hw * 0.03, hw * 0.06, { col: P.ink, weight: 900, maxW: hw * 0.26 });
    // the family walks up together
    const fam = [[-0.3, 1, P.c, 'short', SKIN[2]], [-0.12, 0.62, P.b, 'short', SKIN[1]], [0.06, 0.55, P.a, 'bun', SKIN[1]], [0.26, 0.96, P.d, 'hijab', SKIN[0]]], wk = ez(t, 0.1, 1.6), fs = Math.min(hh * 1.25, u * 0.4);
    fam.forEach(([fx, sc, top, hair, skin], i) => { const x = w / 2 + fx * hw * 1.25 + (1 - wk) * (i < 2 ? -1 : 1) * w * 0.4; person(c, x, h * 0.97, fs * sc, { top, hair, hijab: P.a, skin, step: wk < 1 ? t * 1.2 : 0, arms: i === 1 || i === 2 ? [[Math.PI / 2 + 0.6, Math.PI / 2 + 0.2], [Math.PI / 2 - 0.6, Math.PI / 2 - 0.2]] : undefined }); });
    const hk = pop(t, 2.3, 0.6); if (hk > 0.01) { c.save(); c.globalAlpha *= hk; c.fillStyle = '#FF4D6D'; const s = 1 + 0.08 * Math.sin(t * 5); heart(c, w / 2, h * 0.97 - fs * 1.15, u * 0.04 * hk * s); c.restore(); }
    // the key turns
    const kr = -0.9 + 0.9 * easeOut(clamp((t - 0.3) / 1.0)), ks = u * 0.2, kx = w * 0.16, ky = h * 0.14;
    c.save(); c.translate(kx, ky); c.rotate(kr + Math.sin(t * 1.2) * 0.03); const kg = c.createLinearGradient(-ks, -ks, ks, ks); kg.addColorStop(0, '#FFE8A3'); kg.addColorStop(0.5, '#F6C445'); kg.addColorStop(1, '#C8922A');
    c.fillStyle = kg; c.beginPath(); c.arc(0, 0, ks * 0.32, 0, TAU); c.fill(); c.fillRect(ks * 0.25, -ks * 0.07, ks * 0.85, ks * 0.14); c.fillRect(ks * 0.82, ks * 0.05, ks * 0.1, ks * 0.16); c.fillRect(ks * 0.98, ks * 0.05, ks * 0.1, ks * 0.11);
    c.fillStyle = mix(P.d, '#ffffff', 0.3); heart(c, 0, -ks * 0.02, ks * 0.11); c.restore();
    if (t > 1.2 && t < 2.2) A.glint(c, kx, ky, u * 0.06, Math.sin(Math.PI * (t - 1.2)), '#ffffff');
  } });

def('grad', { fam: 'future', name: 'Graduation caps in the air', nameAr: 'قبعات التخرّج في الهواء', style: 'Kinetic flat', words: { scroll: ['GRADUATE', 'خرّيج'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.d, '#ffffff', 0.15)], [1, mix(P.a, '#ffffff', 0.35)]]);
    c.save(); c.globalCompositeOperation = 'lighter'; burst(c, w / 2, h * 1.05, Math.hypot(w, h), t, '#ffffff', 22, 0.12); c.restore();
    const n = 7;
    for (let i = 0; i < n; i++) {
      const t0 = 0.2 + i * 0.09, k = easeOut(clamp((t - t0) / 1.3)); if (t < t0) continue;
      const x0 = w * (0.5 + (rnd(i, 1) - 0.5) * 0.3), x1 = w * (0.1 + 0.8 * ((i + 0.5) / n)), y1 = h * (0.1 + 0.35 * rnd(i, 2));
      const x = lerp(x0, x1, k) + Math.sin(t * 1.3 + i) * u * 0.012, y = lerp(h * 0.95, y1, k) + Math.sin(t * 1.7 + i * 2) * u * 0.015;
      mortar(c, x, y, u * (0.1 + 0.04 * rnd(i, 3)), (1 - k) * (rnd(i, 4) - 0.5) * 9 + Math.sin(t + i) * 0.15, P);
    }
    if (t > 0.9) A.confettiBurst(c, w / 2, h * 0.7, t - 0.9, u, [P.a, P.b, P.c, '#ffffff'], { n: 50, life: 3.2, spread: 1.2, speed: 1.6 });
    // the diploma
    const dk = pop(t, 1.3, 0.6); if (dk > 0.01) {
      const sw = Math.min(w * 0.7, u * 0.8), sh = sw * 0.22; c.save(); c.translate(w / 2, h * 0.82); c.rotate(-0.06); c.scale(dk, dk);
      shadowed(c, u * 0.03, u * 0.01, 'rgba(0,0,0,0.25)', () => { c.fillStyle = '#FFF8E7'; rrect(c, -sw / 2, -sh / 2, sw, sh, sh / 2); c.fill(); });
      c.fillStyle = '#F1E3C4'; for (const s of [-1, 1]) { c.beginPath(); c.ellipse(s * sw / 2, 0, sh * 0.22, sh / 2, 0, 0, TAU); c.fill(); }
      c.fillStyle = P.a; c.fillRect(-sw * 0.06, -sh / 2, sw * 0.12, sh); c.beginPath(); c.moveTo(0, sh * 0.2); c.lineTo(-sw * 0.08, sh * 0.85); c.lineTo(-sw * 0.03, sh * 0.75); c.lineTo(0, sh * 0.95); c.lineTo(sw * 0.03, sh * 0.75); c.lineTo(sw * 0.08, sh * 0.85); c.closePath(); c.fill();
      T(c, o.words.scroll, -sw * 0.27, sh * 0.15, sh * 0.36, { col: P.ink, weight: 900, maxW: sw * 0.34 }); T(c, o.words.scroll, sw * 0.27, sh * 0.15, sh * 0.36, { col: P.ink, weight: 900, maxW: sw * 0.34 });
      c.restore();
    }
  } });

def('stars', { fam: 'future', dir: true, name: 'Constellation of goals', nameAr: 'كوكبة الأهداف', style: 'Gradient mesh', words: { g1: ['DREAM', 'احلم'], g2: ['PLAN', 'خطّط'], g3: ['GO', 'انطلق'], g4: ['GROW', 'تطوّر'], g5: ['SHINE', 'تألّق'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    c.fillStyle = '#1A1442'; c.fillRect(0, 0, w, h);
    [[0.15, 0.2, P.d], [0.85, 0.3, P.a], [0.3, 0.85, P.c], [0.8, 0.85, P.d]].forEach(([x, y, col], i) => { const r = Math.max(w, h) * 0.5; c.save(); c.globalAlpha = 0.45; c.drawImage(A.blob(col), x * w + Math.sin(t * 0.3 + i) * u * 0.05 - r, y * h - r, r * 2, r * 2); c.restore(); });
    c.fillStyle = '#ffffff'; for (let i = 0; i < 90; i++) { c.globalAlpha = 0.25 + 0.75 * Math.abs(Math.sin(t * (0.8 + rnd(i, 3)) + i)); const s = u * 0.004 * (0.5 + rnd(i, 4)); c.fillRect(rnd(i, 1) * w, rnd(i, 2) * h, s, s); } c.globalAlpha = 1;
    const wide = w > h * 1.1, pts = (wide ? [[0.1, 0.8], [0.3, 0.55], [0.48, 0.68], [0.68, 0.36], [0.88, 0.14]] : [[0.18, 0.88], [0.62, 0.7], [0.28, 0.5], [0.72, 0.32], [0.45, 0.12]]).map(([x, y]) => [x * w, y * h]);
    const prog = ez(t, 0.4, 2.6) * (pts.length - 1);
    c.strokeStyle = rgba('#ffffff', 0.85); c.lineWidth = u * 0.006; c.setLineDash([u * 0.02, u * 0.012]); c.beginPath(); c.moveTo(...pts[0]);
    for (let i = 1; i < pts.length; i++) { const f = clamp(prog - (i - 1)); if (f <= 0) break; c.lineTo(lerp(pts[i - 1][0], pts[i][0], f), lerp(pts[i - 1][1], pts[i][1], f)); } c.stroke(); c.setLineDash([]);
    pts.forEach(([x, y], i) => {
      const k = pop(t, 0.3 + i * 0.6, 0.5); if (k <= 0.01) return;
      const r = u * (0.03 + i * 0.008) * k * (1 + 0.08 * Math.sin(t * 3 + i)), col = [P.c, P.b, P.a, P.b, '#FFF3B0'][i];
      c.save(); c.globalCompositeOperation = 'lighter'; c.globalAlpha = 0.8; c.drawImage(A.blob(col), x - r * 3, y - r * 3, r * 6, r * 6); c.restore();
      c.fillStyle = col; A.star(c, x, y, r * 1.3, 5, 0.45); c.fill();
      const word = o.words['g' + (i + 1)], right = x < w * 0.55;
      T(c, word, x + (right ? r * 1.8 : -r * 1.8), y + u * 0.018, u * (0.045 + i * 0.006), { col: '#ffffff', align: right ? 'left' : 'right', weight: 900, maxW: w * 0.42, track: 0.08 });
    });
    if (t > 3.0) { const p = fr((t - 3.0) / 2.5); if (p < 0.5) { const q = p / 0.5, sx = w * (0.95 - q * 0.6), sy = h * (0.05 + q * 0.25); c.strokeStyle = rgba('#ffffff', 1 - q); c.lineWidth = u * 0.006; c.beginPath(); c.moveTo(sx, sy); c.lineTo(sx + u * 0.15, sy - u * 0.06); c.stroke(); } }
  } });

def('growth', { fam: 'future', dir: true, name: 'Growth chart', nameAr: 'مخطط النمو', style: 'Isometric 3D', words: { v: ['XX', 'XX'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h);
    c.fillStyle = P.paper; c.fillRect(0, 0, w, h);
    const k = Math.min(w / 9.5, h / 10.5), ground = iso(w * 0.5, h * 0.74, k);
    prism(c, ground, -3.4, -3.4, -0.3, 6.8, 6.8, 0.3, mix(P.d, '#ffffff', 0.75));
    const cols = [P.c, P.b, P.a, P.d, mix(P.a, P.b, 0.5)], n = 5;
    for (let i = 0; i < n; i++) {
      const hgt = (1.0 + i * 0.95) * ez(t, 0.3 + i * 0.25, 0.9), bx = -3.4 + i * 1.7, gx = bx / 2 - 0.45, gy = -bx / 2 - 0.45;
      if (hgt > 0.02) prism(c, ground, gx, gy, 0, 0.9, 0.9, hgt, cols[i]);
      const lk = pop(t, 1.1 + i * 0.25, 0.4); if (lk > 0.01) { const [X, Y] = ground(gx + 0.45, gy + 0.45, hgt + 0.75); c.save(); c.translate(X, Y); c.scale(lk, lk); c.fillStyle = '#ffffff'; rrect(c, -k * 0.6, -k * 0.38, k * 1.2, k * 0.62, k * 0.18); c.fill(); c.strokeStyle = cols[i]; c.lineWidth = k * 0.06; c.stroke(); T(c, o.words.v || 'XX', 0, k * 0.12, k * 0.38, { col: P.ink, weight: 900, maxW: k }); c.restore(); }
    }
    // the arrow sweeps up over the bars
    const ak = ez(t, 1.4, 1.6), pts = [...Array(31)].map((_, j) => { const s2 = j / 30, bx = -3.6 + s2 * 7.2; return ground(bx / 2 - 0.9, -bx / 2 - 0.9, 1.6 + s2 * 4.6 + Math.sin(s2 * Math.PI) * 0.8); });
    const m = Math.max(1, Math.floor(30 * ak));
    if (ak > 0) {
      c.strokeStyle = rgba(P.a, 0.95); c.lineWidth = u * 0.03; c.lineCap = 'round'; c.lineJoin = 'round'; c.beginPath(); pts.slice(0, m + 1).forEach(([X, Y], j) => (j ? c.lineTo(X, Y) : c.moveTo(X, Y))); c.stroke();
      const [x1, y1] = pts[m], [x0, y0] = pts[m - 1], ang = Math.atan2(y1 - y0, x1 - x0), s = u * 0.07;
      c.fillStyle = P.a; c.beginPath(); c.moveTo(x1 + Math.cos(ang) * s, y1 + Math.sin(ang) * s); c.lineTo(x1 + Math.cos(ang + 2.4) * s, y1 + Math.sin(ang + 2.4) * s); c.lineTo(x1 + Math.cos(ang - 2.4) * s, y1 + Math.sin(ang - 2.4) * s); c.closePath(); c.fill();
    }
    if (t > 3.0) sparkle(c, w, h * 0.5, t, 8, P.b, u, 31);
  } });

def('kinetic', { fam: 'future', name: 'Kinetic words', nameAr: 'كلمات متحرّكة', style: 'Kinetic typography', words: { w1: ['WORK', 'اعمل'], w2: ['TRAVEL', 'سافر'], w3: ['GROW', 'تطوّر'] },
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), cols = [P.a, P.c, P.d], n = 3, bh = h / (n + 0.6);
    c.fillStyle = P.b; c.fillRect(0, 0, w, h);
    c.fillStyle = rgba('#ffffff', 0.25); for (let i = 0; i < 6; i++) { const x = ((i * 0.22 + t * 0.05) % 1.3 - 0.15) * w; c.beginPath(); c.moveTo(x, 0); c.lineTo(x + w * 0.06, 0); c.lineTo(x - w * 0.14, h); c.lineTo(x - w * 0.2, h); c.fill(); }
    for (let i = 0; i < n; i++) {
      const k = ez(t, 0.25 + i * 0.45, 0.6), dir = i % 2 ? 1 : -1, y = bh * 0.3 + i * bh * 1.05, off = (1 - k) * w * 1.1 * dir;
      c.save(); c.translate(off, 0);
      c.fillStyle = cols[i]; c.beginPath(); c.moveTo(w * 0.04, y + bh * 0.08); c.lineTo(w * 0.98, y); c.lineTo(w * 0.96, y + bh * 0.9); c.lineTo(w * 0.02, y + bh * 0.98); c.closePath(); c.fill();
      const word = o.words['w' + (i + 1)] || '', sc = 0.85 + 0.15 * pop(t, 0.45 + i * 0.45, 0.5), bounce = Math.sin(t * 2 + i) * u * 0.004;
      c.save(); c.translate(w / 2, y + bh * 0.5 + bounce); c.rotate(-0.03); c.scale(sc, sc); T(c, word, 0, bh * 0.3, bh * 0.82, { col: '#ffffff', font: /[؀-ۿ]/.test(word) ? 'ui' : 'display', weight: 400, maxW: w * 0.86 }); c.restore();
      c.restore();
    }
    // a big arrow ends the line-up
    const ak = pop(t, 1.7, 0.6); if (ak > 0.01) { c.save(); c.translate(w * 0.88, bh * 0.25); c.rotate(-0.6); c.scale(ak, ak); c.fillStyle = P.ink; const s = u * 0.08; c.beginPath(); c.moveTo(-s, s * 0.3); c.lineTo(s * 0.2, s * 0.3); c.lineTo(s * 0.2, s * 0.8); c.lineTo(s * 1.2, 0); c.lineTo(s * 0.2, -s * 0.8); c.lineTo(s * 0.2, -s * 0.3); c.lineTo(-s, -s * 0.3); c.closePath(); c.fill(); c.restore(); }
  } });

def('isocity', { fam: 'future', name: 'Isometric city of tomorrow', nameAr: 'مدينة الغد', style: 'Isometric 3D', words: {},
  draw(c, w, h, t, o) {
    const P = o.P, u = Math.min(w, h), K = o.K;
    A.gradRect(c, 0, 0, w, h, [[0, mix(P.a, '#ffffff', 0.55)], [1, mix(P.c, '#ffffff', 0.5)]]);
    for (let i = 0; i < 3; i++) { const cx = w * fr(rnd(i, 7) + t * 0.015 * (i + 1)), cy = h * (0.08 + i * 0.07), r = u * 0.06; c.fillStyle = rgba('#ffffff', 0.85); c.beginPath(); c.ellipse(cx, cy, r * 2, r * 0.6, 0, 0, TAU); c.ellipse(cx + r * 0.6, cy - r * 0.35, r, r * 0.6, 0, 0, TAU); c.fill(); }
    const k = Math.min(w / 16.5, h / 13), P3 = iso(w / 2, h * 0.54, k);
    prism(c, P3, -4.5, -4.5, -0.5, 9, 9, 0.5, '#B7E4A0');
    c.fillStyle = '#5C677D'; for (const [a2, b2] of [[[-4.5, -0.5], [4.5, 0.5]], [[-0.5, -4.5], [0.5, 4.5]]]) { const q = [P3(a2[0], a2[1]), P3(b2[0], a2[1]), P3(b2[0], b2[1]), P3(a2[0], b2[1])]; c.beginPath(); q.forEach(([X, Y], i) => (i ? c.lineTo(X, Y) : c.moveTo(X, Y))); c.fill(); }
    const cols = [P.a, P.b, P.d, P.c, '#FF8FB1', '#7AD3FF'], items = [];
    const blocks = [[-4, -4, 1.4, 1.4, 3.6], [-2.2, -4, 1.4, 1.2, 2.2], [1.2, -4, 1.4, 1.4, 4.2], [3, -4, 1.2, 1.4, 2.6], [-4, 1.2, 1.4, 1.4, 2.4], [-4, 3, 1.4, 1.2, 1.6], [3.2, 1.2, 1.2, 1.2, 3.0], [1.2, 3.2, 1.2, 1.2, 1.8], [-2.2, 3, 1.4, 1.2, 1.4]];
    blocks.forEach(([x, y, sx, sy, sz], i) => items.push([x + y + sx + sy, () => { const g = ez(t, 0.2 + i * 0.15, 0.8); if (g > 0.01) prism(c, P3, x, y, 0, sx, sy, sz * g, cols[i % cols.length], '#ffffff'); }]));
    // the destination's landmark on the plaza
    items.push([5.2, () => { const lk = pop(t, 1.9, 0.7); if (lk > 0.01) { const [X, Y] = P3(2.2, 2.2); c.save(); c.translate(X, Y); c.scale(lk, lk); c.save(); c.globalAlpha = 0.7; c.drawImage(A.blob('#ffffff'), -k * 2.2, -k * 3.8, k * 4.4, k * 4.4); c.restore(); A.landmark(c, lmOf(K), 0, 0, k * 5.5, "day", 1); c.restore(); } }]);
    for (let i = 0; i < 4; i++) { const s = fr(t * 0.12 + i * 0.25), along = i % 2 === 0, x = along ? -4.4 + s * 8.8 : (i === 1 ? -0.25 : 0.1), y = along ? (i === 0 ? -0.25 : 0.15) : -4.4 + s * 8.8; items.push([x + y + 0.8, () => prism(c, P3, x, y, 0, along ? 0.5 : 0.3, along ? 0.3 : 0.5, 0.25, ['#E63946', '#FFFFFF', '#3A86FF', '#FFB703'][i])]); }
    items.sort((p, q) => p[0] - q[0]).forEach(([, f]) => f());
  } });
