// KBB Studio · themes · hero landmark ILLUSTRATIONS with depth (original drawings, made here as compact path data; no
// outside artwork). Each is { name, mat, parts:[[tone, path] | [tone, path, 'b'] ...] } in a 200×200 box standing on
// y=200. Tones: 0 lit face · 1 shaded face · 2 deep shadow / openings · 3 lit windows (glow) · 4 highlight · 5 accent
// (mat.acc: a torii's red, a flame) · 'b' marks a ball (path = "cx cy r") drawn as a shaded sphere. art.js paints the
// tones from the material (mat) and the scene's light, so the same drawing works at noon, golden hour and night.
// Landmarks not drawn here fall back to the country packs' silhouettes (app/auto/countries/landmarks.js) with an
// automatic lit/shaded split, so every country has a hero.
const f = (n) => +n.toFixed(1);
const R = (x, y, w, h) => `M${f(x)} ${f(y)}h${f(w)}v${f(h)}h${f(-w)}Z`;
const P = (...p) => 'M' + p.map(([x, y]) => `${f(x)} ${f(y)}`).join('L') + 'Z';
const C = (cx, cy, r) => `M${f(cx - r)} ${f(cy)}a${f(r)} ${f(r)} 0 1 0 ${f(2 * r)} 0a${f(r)} ${f(r)} 0 1 0 ${f(-2 * r)} 0Z`;
const E = (cx, cy, rx, ry) => `M${f(cx - rx)} ${f(cy)}a${f(rx)} ${f(ry)} 0 1 0 ${f(2 * rx)} 0a${f(rx)} ${f(ry)} 0 1 0 ${f(-2 * rx)} 0Z`;
const A = (x, y, w, h) => `M${f(x)} ${f(y + h)}V${f(y + w / 2)}A${f(w / 2)} ${f(w / 2)} 0 0 1 ${f(x + w)} ${f(y + w / 2)}V${f(y + h)}Z`;
const L = (x1, y1, x2, y2, w = 1) => { const dx = x2 - x1, dy = y2 - y1, k = Math.hypot(dx, dy) || 1, nx = (-dy / k) * w / 2, ny = (dx / k) * w / 2; return P([x1 + nx, y1 + ny], [x2 + nx, y2 + ny], [x2 - nx, y2 - ny], [x1 - nx, y1 - ny]); };
const rep = (n, fn) => Array.from({ length: n }, (_, i) => fn(i)).flat();
const mirror = (pts) => pts.map(([x, y]) => [200 - x, y]);

// materials: lit / shade / dark / glow / hi / acc
export const MATS = {
  stone: { lit: '#f2d3a4', shade: '#a77b5a', dark: '#3b2a24', glow: '#ffd27a', hi: '#fff1d6', acc: '#e0b04a' },
  sand: { lit: '#f6dcb0', shade: '#b4895f', dark: '#4a3326', glow: '#ffd27a', hi: '#fff4dc', acc: '#d4a24a' },
  iron: { lit: '#c98a58', shade: '#6e4433', dark: '#2a1b18', glow: '#ffd27a', hi: '#f2c48e', acc: '#ffcf5a' },
  glass: { lit: '#d6ecff', shade: '#5c7fae', dark: '#1d2b45', glow: '#ffe39a', hi: '#ffffff', acc: '#9fd3ff' },
  white: { lit: '#ffffff', shade: '#b7c6dc', dark: '#33415c', glow: '#ffe7a8', hi: '#ffffff', acc: '#e9eef6' },
  copper: { lit: '#8fd3c1', shade: '#3e8c7d', dark: '#1d4a43', glow: '#ffcf5a', hi: '#d5fff3', acc: '#ffb13b' },
  snow: { lit: '#ffffff', shade: '#8ea6cf', dark: '#3d4f78', glow: '#ffe7a8', hi: '#ffffff', acc: '#e5383b' },
  steel: { lit: '#e4ecf5', shade: '#7f8fa6', dark: '#2a3343', glow: '#ffe39a', hi: '#ffffff', acc: '#c9d6e8' },
  brick: { lit: '#e7a07a', shade: '#9c5340', dark: '#3a1f1a', glow: '#ffd27a', hi: '#ffd2b8', acc: '#f2c14e' },
};

const columns = (x0, n, step, y, w, h) => rep(n, (i) => [[0, R(x0 + i * step, y, w * 0.62, h)], [1, R(x0 + i * step + w * 0.62, y, w * 0.38, h)]]);

export const HERO = {
  // Elizabeth Tower with the Palace of Westminster beside it
  'big-ben': { name: 'Elizabeth Tower (Big Ben), London', mat: 'stone', parts: [
    [1, R(0, 146, 84, 54)], [0, R(0, 146, 84, 6)],
    ...rep(9, (i) => [[1, P([4 + i * 9, 146], [7.5 + i * 9, 130], [11 + i * 9, 146])], [3, R(3 + i * 9, 158, 3, 7)], [3, R(3 + i * 9, 174, 3, 7)]]),
    [1, R(30, 120, 14, 26)], [0, R(30, 120, 7, 26)], [1, P([28, 120], [37, 100], [46, 120])], [0, P([28, 120], [37, 100], [37, 120])],
    [0, R(78, 184, 22, 16)], [1, R(100, 184, 22, 16)],
    [0, R(84, 92, 16, 94)], [1, R(100, 92, 16, 94)],
    ...rep(3, (i) => [[2, R(87 + i * 4.5, 98, 1.6, 84)], [2, R(103 + i * 4.5, 98, 1.6, 84)]]),
    ...rep(5, (i) => [[3, R(87, 104 + i * 16, 10, 4)], [3, R(103, 104 + i * 16, 10, 4)]]),
    [0, R(80, 58, 20, 34)], [1, R(100, 58, 20, 34)], [4, R(79, 56, 42, 3)], [4, R(79, 91, 42, 2)],
    [3, C(90, 75, 8.2)], [3, E(110, 75, 6, 8.2)], [2, L(90, 75, 90, 69, 1.2)], [2, L(90, 75, 94, 77, 1.2)], [2, L(110, 75, 110, 69.5, 1)],
    [0, R(83, 44, 17, 12)], [1, R(100, 44, 17, 12)], ...rep(3, (i) => [[2, A(85.5 + i * 5, 46, 3.4, 9)], [2, A(102 + i * 5, 46, 3.4, 9)]]),
    [0, P([81, 44], [100, 12], [100, 44])], [1, P([100, 12], [119, 44], [100, 44])],
    [4, P([95, 36], [100, 26], [100, 36])], [2, R(99.2, 2, 1.6, 12)],
    [0, P([79, 58], [81, 48], [83, 58])], [1, P([117, 58], [119, 48], [121, 58])],
  ] },
  'eiffel': { name: 'Eiffel Tower, Paris', mat: 'iron', parts: [
    [0, 'M46 200Q76 152 88 96L93 44L98 10H100V162Q80 166 66 200Z'],
    [1, 'M154 200Q124 152 112 96L107 44L102 10H100V162Q120 166 134 200Z'],
    [2, 'M74 200Q86 176 100 172Q114 176 126 200Z'],
    ...rep(6, (i) => { const y = 196 - i * 10.5, x = 52 + i * 5.6; return [[2, L(x, y, x + 9 + i * 0.5, y - 10, 1.1)], [2, L(x + 9, y, x + 1 + i * 0.5, y - 10, 1.1)], [2, L(200 - x, y, 191 - x - i * 0.5, y - 10, 1.1)], [2, L(191 - x, y, 199 - x - i * 0.5, y - 10, 1.1)]]; }),
    ...rep(5, (i) => { const y = 92 - i * 10; return [[2, L(90 - i * 0.6, y, 99, y - 9, 0.9)], [2, L(110 + i * 0.6, y, 101, y - 9, 0.9)]]; }),
    [4, R(66, 132, 68, 5)], [2, R(68, 137, 64, 3)], [4, R(82, 94, 36, 4)], [2, R(84, 98, 32, 2)], [4, R(93, 44, 14, 3)],
    [3, R(98.4, 0, 3.2, 10)],
    ...rep(9, (i) => [[3, C(70 + i * 7.5, 134.5, 0.9)]]), ...rep(5, (i) => [[3, C(85 + i * 7.5, 96, 0.8)]]),
  ] },
  'brandenburg-gate': { name: 'Brandenburg Gate, Berlin', mat: 'sand', parts: [
    [2, R(30, 118, 140, 82)],
    ...columns(34, 6, 25.6, 118, 11, 82),
    [0, R(26, 110, 148, 8)], [4, R(26, 110, 148, 2)],
    [0, R(30, 90, 140, 20)], [1, R(30, 104, 140, 6)], ...rep(14, (i) => [[2, R(33 + i * 10, 94, 4, 6)]]),
    [0, P([60, 90], [100, 74], [100, 90])], [1, P([100, 74], [140, 90], [100, 90])],
    [1, R(84, 66, 32, 10)],
    [5, 'M86 66l2-9 4-3 2 2 2-6 3 2v14ZM98 66l1-10 4-4 2 3 1-6 3 3 1 14ZM104 66l3-8 4-2 1 3 3-4 2 3-1 8ZM114 66l-1-7 3-3 1 2 2-2v10Z'],
    [5, R(94, 52, 12, 6)], [5, L(100, 52, 100, 40, 1.4)], [5, C(100, 38, 3)],
  ] },
  'colosseum': { name: 'Colosseum, Rome', mat: 'stone', parts: [
    [0, 'M6 200V100Q50 80 112 80L118 80V200Z'], [1, 'M118 80L132 82V98L150 102V122L168 128V150L192 156V200H118Z'],
    [4, 'M6 100Q50 80 112 80V83Q50 83 6 103Z'],
    ...[[178, 194], [148, 168], [118, 140]].flatMap(([y]) => rep(10, (i) => { const x = 10 + i * 18; return x + 12 > (y < 130 ? 130 : y < 160 ? 168 : 192) ? [] : [[2, A(x, y - 22, 10, 22)], [3, A(x + 2, y - 18, 6, 6)]]; })),
    ...rep(8, (i) => [[2, R(14 + i * 15, 92, 6, 8)]]),
    [4, R(6, 148, 186, 3)], [4, R(6, 176, 186, 3)], [4, R(6, 120, 156, 3)],
  ] },
  'opera-house': { name: 'Opera House, Sydney', mat: 'white', parts: [
    [1, R(6, 176, 188, 24)], [0, R(6, 176, 188, 5)], ...rep(12, (i) => [[3, R(12 + i * 15, 186, 8, 4)]]),
    [0, 'M24 176Q34 118 78 98Q66 140 70 176Z'], [1, 'M70 176Q66 140 78 98Q74 140 86 176Z'],
    [0, 'M62 176Q76 104 128 88Q108 138 112 176Z'], [1, 'M112 176Q108 138 128 88Q120 140 132 176Z'],
    [0, 'M106 176Q122 120 168 108Q152 148 156 176Z'], [1, 'M156 176Q152 148 168 108Q162 150 172 176Z'],
    [0, 'M148 176Q158 150 186 144Q178 162 180 176Z'],
    [2, 'M26 176Q30 160 40 152L42 176Z'], [2, 'M66 176Q70 160 80 150L84 176Z'], [2, 'M110 176Q116 160 126 152L128 176Z'],
    [4, 'M30 160Q40 128 70 108Q46 132 36 166Z'], [4, 'M70 150Q84 112 116 96Q90 120 78 156Z'],
  ] },
  'harbour-bridge': { name: 'Harbour Bridge, Sydney', mat: 'steel', parts: [
    [1, 'M18 150Q100 46 182 150H170Q100 66 30 150Z'], [0, 'M30 150Q100 66 170 150H158Q100 86 42 150Z'],
    ...rep(13, (i) => { const x = 36 + i * 10, y = 150 - 86 * Math.sin(Math.PI * (x - 30) / 140) * 0.92; return [[2, L(x, y + 4, x, 150, 1)]]; }),
    ...rep(12, (i) => { const x = 36 + i * 10, x2 = x + 10, y1 = 150 - 84 * Math.sin(Math.PI * (x - 30) / 140), y2 = 150 - 84 * Math.sin(Math.PI * (x2 - 30) / 140); return [[2, L(x, y1 + 6, x2, y2 + 14, 0.8)]]; }),
    [0, R(0, 148, 200, 6)], [2, R(0, 154, 200, 3)],
    [0, R(6, 120, 12, 80)], [1, R(18, 120, 8, 80)], [0, R(174, 120, 12, 80)], [1, R(186, 120, 8, 80)],
    [4, R(6, 120, 20, 3)], [4, R(174, 120, 20, 3)], ...rep(4, (i) => [[3, R(9, 160 + i * 8, 6, 3)], [3, R(177, 160 + i * 8, 6, 3)]]),
  ] },
  'cn-tower': { name: 'CN Tower, Toronto', mat: 'steel', parts: [
    [0, P([88, 200], [96, 88], [100, 88], [100, 200])], [1, P([100, 88], [104, 88], [112, 200], [100, 200])],
    [2, L(97, 120, 97, 196, 1.2)], [2, L(103, 120, 103, 196, 1.2)],
    [0, 'M78 84Q100 64 100 64V100Q84 96 78 84Z'], [1, 'M122 84Q100 64 100 64V100Q116 96 122 84Z'],
    [3, R(80, 82, 40, 4)], [4, R(82, 76, 36, 2)],
    [0, R(96, 54, 4, 12)], [1, R(100, 54, 4, 12)], [0, E(100, 54, 6, 3)],
    [0, R(98.5, 6, 1.5, 48)], [1, R(100, 6, 1.5, 48)], [3, C(100, 6, 1.6)],
  ] },
  'burj-khalifa': { name: 'Burj Khalifa, Dubai', mat: 'glass', parts: [
    [0, P([72, 200], [76, 160], [84, 160], [86, 120], [92, 120], [93, 80], [97, 80], [98, 40], [100, 40], [100, 200])],
    [1, P([100, 40], [102, 40], [103, 80], [107, 80], [108, 120], [114, 120], [116, 160], [124, 160], [128, 200], [100, 200])],
    [0, R(99, 0, 1, 40)], [1, R(100, 0, 1, 40)],
    ...rep(18, (i) => [[3, R(i < 6 ? 78 : i < 11 ? 88 : 94, 196 - i * 8.6, i < 6 ? 44 : i < 11 ? 24 : 12, 1.2)]]),
    [4, L(99.5, 40, 99.5, 196, 0.8)],
  ] },
  'marina-bay': { name: 'Marina Bay Sands, Singapore', mat: 'glass', parts: [
    ...[30, 82, 134].flatMap((x) => [[0, P([x, 200], [x + 2, 92], [x + 18, 92], [x + 18, 200])], [1, P([x + 18, 92], [x + 34, 92], [x + 36, 200], [x + 18, 200])],
      ...rep(10, (i) => [[3, R(x + 4, 100 + i * 9.5, 28, 1.4)]])]),
    [0, 'M18 86H182L192 80H174Q100 76 26 80H14Z'], [1, 'M18 86H182V92H18Z'], [4, 'M14 80H192V82H14Z'],
    [2, 'M170 86L192 80V84L174 90Z'],
  ] },
  'petronas': { name: 'Petronas Towers, Kuala Lumpur', mat: 'steel', parts: [
    ...[[46, 1], [118, 1]].flatMap(([x]) => [
      [0, P([x, 200], [x, 80], [x + 4, 70], [x + 8, 70], [x + 10, 56], [x + 14, 56], [x + 16, 40], [x + 18, 40], [x + 18, 200])],
      [1, P([x + 18, 40], [x + 20, 40], [x + 22, 56], [x + 26, 56], [x + 28, 70], [x + 32, 70], [x + 36, 80], [x + 36, 200], [x + 18, 200])],
      [0, R(x + 17, 6, 1.2, 34)], [1, R(x + 18.2, 6, 1.2, 34)],
      ...rep(12, (i) => [[3, R(x + 3, 88 + i * 9, 30, 1.4)]]),
      [4, L(x + 17.5, 44, x + 17.5, 198, 0.7)]]),
    [1, R(82, 118, 36, 4)], [0, P([82, 122], [100, 132], [118, 122], [116, 122], [100, 129], [84, 122])],
  ] },
  'fuji-torii': { name: 'Mount Fuji with a torii gate', mat: 'snow', parts: [
    [1, 'M0 186L74 74Q84 66 100 64Q116 66 126 74L200 186Z'], [0, 'M0 186L74 74Q84 66 100 64V186Z'],
    [4, 'M74 74Q84 66 100 64Q116 66 126 74L138 92L128 88L120 98L110 88L100 100L90 88L80 98L72 88L62 92Z'],
    [2, R(0, 186, 200, 14)],
    [5, R(132, 132, 6, 68)], [5, R(176, 132, 6, 68)], [5, R(126, 140, 62, 6)], [5, 'M118 124Q157 132 196 124L194 132Q157 138 120 132Z'],
    [2, R(118, 122, 78, 3)], [5, R(154, 132, 6, 8)],
  ] },
  'liberty': { name: 'Statue of Liberty, New York', mat: 'copper', parts: [
    [0, P([62, 200], [70, 158], [100, 158], [100, 200])], [1, P([100, 158], [130, 158], [138, 200], [100, 200])],
    [4, R(66, 156, 68, 4)], [2, R(84, 172, 10, 16)], [2, R(106, 172, 10, 16)],
    [0, P([80, 158], [86, 140], [100, 140], [100, 158])], [1, P([100, 140], [114, 140], [120, 158], [100, 158])],
    [0, 'M88 140L92 92Q94 84 100 84V140Z'], [1, 'M100 84Q106 84 108 92L112 140H100Z'],
    [1, 'M92 110Q98 116 104 112L108 120Q98 124 90 118Z'],
    [0, C(100, 76, 7.5)], [1, 'M100 68.5A7.5 7.5 0 0 1 100 83.5Z'],
    ...rep(7, (i) => { const a = Math.PI * (1.08 + i * 0.14); return [[0, P([100 + Math.cos(a) * 7, 72 + Math.sin(a) * 7], [100 + Math.cos(a) * 15, 72 + Math.sin(a) * 15], [100 + Math.cos(a + 0.12) * 7, 72 + Math.sin(a + 0.12) * 7])]]; }),
    [0, 'M104 92L118 42L124 44L112 96Z'], [1, 'M118 42L124 44L122 36L116 34Z'],
    [5, 'M120 34Q114 26 120 14Q122 22 126 24Q130 30 124 36Z'], [3, 'M121 32Q118 26 121 20Q124 28 124 32Z'],
    [1, P([86, 108], [80, 122], [86, 124], [92, 112])],
  ] },
  'atomium': { name: 'Atomium, Brussels', mat: 'steel', parts: [
    ...[[100, 30, 100, 100], [100, 100, 100, 170], [52, 66, 148, 134], [148, 66, 52, 134], [52, 66, 100, 30], [148, 66, 100, 30], [52, 134, 100, 170], [148, 134, 100, 170], [52, 66, 52, 134], [148, 66, 148, 134], [100, 30, 52, 134], [100, 170, 148, 66]]
      .map(([a, b, c, d]) => [1, L(a, b, c, d, 5)]),
    [2, P([92, 170], [80, 200], [86, 200], [100, 176])], [2, P([108, 170], [120, 200], [114, 200], [100, 176])], [1, R(97, 170, 6, 30)],
    ...[[100, 30], [52, 66], [148, 66], [52, 134], [148, 134], [100, 170], [76, 100], [124, 100], [100, 100]].map(([x, y]) => [0, `${x} ${y} 14`, 'b']),
  ] },
  'windmill': { name: 'Windmill and canal, Netherlands', mat: 'brick', parts: [
    [0, P([74, 200], [84, 96], [100, 96], [100, 200])], [1, P([100, 96], [116, 96], [126, 200], [100, 200])],
    [2, R(66, 150, 68, 5)], [4, R(66, 148, 68, 2)],
    [2, A(94, 174, 12, 26)], [3, A(96, 176, 8, 10)], [3, R(88, 120, 7, 9)], [3, R(105, 120, 7, 9)],
    [0, 'M82 98Q84 78 100 74V98Z'], [1, 'M118 98Q116 78 100 74V98Z'],
    ...[[0.78, 0], [0.78 + Math.PI / 2, 1], [0.78 + Math.PI, 0], [0.78 + Math.PI * 1.5, 1]].flatMap(([a, k]) => {
      const cx = 100, cy = 86, ex = cx + Math.cos(a) * 74, ey = cy + Math.sin(a) * 74, nx = -Math.sin(a), ny = Math.cos(a), bx = cx + Math.cos(a) * 16, by = cy + Math.sin(a) * 16;
      return [[2, L(cx, cy, ex, ey, 2.6)], [k ? 1 : 0, P([bx, by], [ex, ey], [ex + nx * 13, ey + ny * 13], [bx + nx * 13, by + ny * 13])],
        ...rep(5, (i) => { const s = 0.25 + i * 0.16, x1 = cx + Math.cos(a) * 74 * s, y1 = cy + Math.sin(a) * 74 * s; return [[2, L(x1, y1, x1 + nx * 13, y1 + ny * 13, 0.8)]]; })];
    }),
    [4, C(100, 86, 4.5)],
  ] },
  'charles-bridge': { name: 'Charles Bridge and Old Town Bridge Tower, Prague', mat: 'stone', parts: [
    [1, R(0, 150, 200, 22)], [0, R(0, 146, 200, 6)], [4, R(0, 146, 200, 1.5)],
    ...rep(5, (i) => [[2, `M${10 + i * 42} 200V184Q${31 + i * 42} 160 ${52 + i * 42} 184V200Z`]]),
    ...rep(6, (i) => [[1, R(4 + i * 42, 166, 8, 34)], [0, P([2 + i * 42, 172], [8 + i * 42, 160], [14 + i * 42, 172])]]),
    ...rep(7, (i) => [[2, R(26 + i * 26, 132, 3, 14)], [2, C(27.5 + i * 26, 130, 2.6)]]),
    [0, R(150, 70, 18, 80)], [1, R(168, 70, 18, 80)], [2, A(158, 120, 20, 30)],
    [0, P([146, 70], [168, 30], [168, 70])], [1, P([168, 30], [190, 70], [168, 70])],
    [0, P([146, 72], [150, 56], [154, 72])], [1, P([182, 72], [186, 56], [190, 72])],
    [3, R(154, 86, 5, 8)], [3, R(173, 86, 5, 8)], [4, R(148, 98, 40, 2)],
  ] },
  'parthenon': { name: 'Parthenon, Athens', mat: 'stone', parts: [
    [2, R(20, 100, 160, 80)],
    ...columns(24, 8, 20.4, 100, 12, 80),
    ...rep(8, (i) => [[4, R(25 + i * 20.4, 100, 2, 80)]]),
    [0, R(16, 86, 168, 14)], [1, R(16, 96, 168, 4)], ...rep(16, (i) => [[2, R(18 + i * 10.4, 88, 4, 6)]]),
    [0, P([14, 86], [100, 62], [100, 86])], [1, P([100, 62], [186, 86], [100, 86])], [2, P([28, 84], [100, 68], [172, 84])],
    [0, R(10, 180, 180, 6)], [1, R(6, 186, 188, 6)], [0, R(2, 192, 196, 8)], [4, R(10, 180, 180, 1.5)],
  ] },
  'matterhorn': { name: 'Matterhorn, Switzerland', mat: 'snow', parts: [
    [1, 'M0 200L40 150L64 120L90 40L104 22L112 30L124 70L150 110L176 138L200 160V200Z'],
    [0, 'M0 200L40 150L64 120L90 40L104 22L100 70L92 120L80 160L70 200Z'],
    [4, 'M90 40L104 22L112 30L118 52L110 46L104 58L98 50L92 62Z'], [4, 'M64 120L80 100L84 112L72 124Z'],
    [2, 'M0 200L30 176L60 186L90 168L120 184L150 170L200 188V200Z'],
  ] },
  'sagrada': { name: 'Sagrada Família, Barcelona', mat: 'sand', parts: [
    [1, R(36, 140, 128, 60)], [0, R(36, 140, 64, 60)], [2, A(86, 156, 28, 44)], [3, A(92, 164, 16, 14)],
    ...[[58, 80, 14], [80, 112, 16], [120, 112, 16], [142, 80, 14], [100, 134, 20]].flatMap(([x, hh, w]) => [
      [0, `M${x - w / 2} 140V${140 - hh * 0.6}Q${x - w / 2} ${140 - hh} ${x} ${140 - hh - 6}V140Z`],
      [1, `M${x + w / 2} 140V${140 - hh * 0.6}Q${x + w / 2} ${140 - hh} ${x} ${140 - hh - 6}V140Z`],
      ...rep(Math.floor(hh / 16), (i) => [[2, R(x - 1.5, 134 - i * 14, 3, 6)]]),
      [5, C(x, 140 - hh - 7, 2.4)]]),
  ] },
};

// a second, smaller landmark that completes a skyline (drawn behind and to the side of the hero)
export const SECONDARY = { au: 'harbour-bridge', it: 'pisa', nl: 'nyhavn' };
// which hero each country shows (the brief's list; the rest are the country packs' own landmarks)
export const HERO_OF = { it: 'colosseum', cz: 'charles-bridge' };
// generic material for the country packs' silhouettes
export const SIL_MAT = { 'cn-tower': 'steel', 'sky-tower': 'steel', 'seoul-tower': 'steel', 'galata': 'stone', 'riesenrad': 'steel', 'nyhavn': 'brick', 'stockholm-hall': 'brick', 'palace-culture': 'sand', 'parliament-hu': 'stone', 'helsinki-cathedral': 'white', 'aphrodite-rock': 'sand', 'valletta-dome': 'sand', 'belem': 'white', 'gediminas': 'brick', 'riga-spire': 'brick', 'tallinn-towers': 'brick', 'hapenny-bridge': 'white', 'pisa': 'white', 'prague-tower': 'stone' };
