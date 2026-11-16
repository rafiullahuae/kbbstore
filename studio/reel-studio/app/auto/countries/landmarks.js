// KBB Studio · original landmark silhouettes for the country packs (drawn here, no outside artwork).
// Each is { name, d:[shape paths], h:[detail paths] } in a 200×200 box standing on y=200. Every shape is filled on its own
// (so overlaps never cancel); h = windows / clock faces / arches, drawn on top in the detail colour.
const f = n => +n.toFixed(1);
const R = (x, y, w, h) => `M${f(x)} ${f(y)}h${f(w)}v${f(h)}h${f(-w)}Z`;
const Poly = (...p) => 'M' + p.map(([x, y]) => `${f(x)} ${f(y)}`).join('L') + 'Z';
const Circ = (cx, cy, r) => `M${f(cx - r)} ${f(cy)}a${f(r)} ${f(r)} 0 1 0 ${f(2 * r)} 0a${f(r)} ${f(r)} 0 1 0 ${f(-2 * r)} 0Z`;
const Ring = (cx, cy, r, r2) => Circ(cx, cy, r) + `M${f(cx - r2)} ${f(cy)}a${f(r2)} ${f(r2)} 0 1 1 ${f(2 * r2)} 0a${f(r2)} ${f(r2)} 0 1 1 ${f(-2 * r2)} 0Z`;
const Dome = (cx, y, r) => `M${f(cx - r)} ${f(y)}A${f(r)} ${f(r)} 0 0 1 ${f(cx + r)} ${f(y)}Z`;
const Arch = (x, y, w, h) => `M${f(x)} ${f(y + h)}V${f(y + w / 2)}A${f(w / 2)} ${f(w / 2)} 0 0 1 ${f(x + w)} ${f(y + w / 2)}V${f(y + h)}Z`;
const Spire = (cx, y, w, h) => Poly([cx - w / 2, y], [cx, y - h], [cx + w / 2, y]);
const rep = (n, fn) => Array.from({ length: n }, (_, i) => fn(i)).flat();

export const LANDMARKS = {
  'big-ben': { name: 'Elizabeth Tower, London', d: [R(80, 60, 40, 140), R(76, 52, 48, 10), Circ(100, 78, 9), Poly([78, 52], [100, 18], [122, 52]), R(98, 4, 4, 16)], h: [Circ(100, 78, 13), ...rep(4, i => R(86 + i * 8, 110, 4, 70))] },
  'cn-tower': { name: 'CN Tower, Toronto', d: [Poly([92, 200], [97, 70], [103, 70], [108, 200]), `M80 74Q100 56 120 74Q100 92 80 74Z`, R(98.5, 6, 3, 50), Poly([95, 58], [100, 40], [105, 58])], h: [R(94, 96, 12, 6)] },
  'opera-house': { name: 'Opera House, Sydney', d: [R(10, 180, 180, 20), `M30 180Q40 120 80 100Q72 140 74 180Z`, `M66 180Q80 110 130 92Q112 140 116 180Z`, `M108 180Q124 124 170 112Q154 150 158 180Z`, `M150 180Q160 150 188 146Q178 165 182 180Z`] },
  'sky-tower': { name: 'Sky Tower, Auckland', d: [Poly([94, 200], [96, 80], [104, 80], [106, 200]), `M84 84Q100 66 116 84L112 96H88Z`, R(98.8, 10, 2.4, 60), R(30, 160, 30, 40), R(140, 150, 26, 50), R(64, 176, 20, 24)] },
  'liberty': { name: 'Statue of Liberty, New York', d: [Poly([70, 200], [76, 160], [124, 160], [130, 200]), R(82, 140, 36, 20), Poly([90, 140], [94, 80], [108, 80], [112, 140]), Circ(100, 72, 8), Poly([92, 66], [86, 56], [96, 62], [100, 50], [104, 62], [114, 56], [108, 66]), Poly([106, 84], [118, 40], [122, 40], [112, 88]), Poly([114, 40], [118, 26], [126, 40])] },
  'hapenny-bridge': { name: "Ha'penny Bridge, Dublin", d: [`M10 150Q100 80 190 150V160Q100 98 10 160Z`, R(10, 150, 6, 50), R(184, 150, 6, 50), ...rep(9, i => R(28 + i * 18, 128 - 18 * Math.sin(Math.PI * (i + 1) / 10), 2, 24)), R(0, 186, 200, 14)] },
  'brandenburg-gate': { name: 'Brandenburg Gate, Berlin', d: [R(30, 92, 140, 18), Poly([30, 92], [100, 76], [170, 92]), R(26, 110, 148, 8), ...rep(6, i => R(34 + i * 25.6, 118, 9, 82)), R(86, 52, 28, 24), Poly([90, 52], [100, 40], [110, 52])] },
  'eiffel': { name: 'Eiffel Tower, Paris', d: [`M60 200Q84 140 94 70H106Q116 140 140 200H122Q112 170 100 168Q88 170 78 200Z`, R(72, 128, 56, 8), R(84, 92, 32, 6), Poly([95, 70], [98.5, 12], [101.5, 12], [105, 70])] },
  'windmill': { name: 'Windmill, Netherlands', d: [Poly([80, 200], [86, 100], [114, 100], [120, 200]), Dome(100, 100, 16), Poly([99, 88], [40, 40], [46, 34]), Poly([101, 88], [160, 40], [154, 34]), Poly([99, 92], [40, 140], [46, 146]), Poly([101, 92], [160, 140], [154, 146]), Circ(100, 90, 5)], h: [Arch(94, 170, 12, 30)] },
  'pisa': { name: 'Leaning Tower, Pisa', d: [`M76 200L96 52L124 56L108 200Z`, ...rep(6, i => R(80 + i * 3.1 - 2, 190 - i * 24, 34, 3)), `M95 52L98 40L120 43L123 56Z`] },
  'sagrada': { name: 'Sagrada Família, Barcelona', d: [R(40, 140, 120, 60), Spire(60, 140, 14, 80), Spire(80, 140, 16, 110), Spire(120, 140, 16, 110), Spire(140, 140, 14, 80), Spire(100, 140, 22, 130)], h: [Arch(86, 160, 28, 40)] },
  'belem': { name: 'Belém Tower, Lisbon', d: [R(60, 120, 80, 80), R(78, 60, 44, 60), ...rep(5, i => R(60 + i * 18, 112, 8, 8)), ...rep(3, i => R(78 + i * 18, 52, 8, 8)), Dome(62, 112, 6), Dome(138, 112, 6), R(0, 190, 200, 10)], h: [Arch(92, 76, 16, 24)] },
  'palace-culture': { name: 'Palace of Culture and Science, Warsaw', d: [R(40, 150, 120, 50), R(64, 110, 72, 40), R(78, 74, 44, 36), R(88, 44, 24, 30), Poly([90, 44], [100, 6], [110, 44])], h: [...rep(5, i => R(46 + i * 24, 160, 6, 30))] },
  'valletta-dome': { name: 'Valletta skyline, Malta', d: [R(20, 140, 160, 60), R(70, 110, 60, 30), Dome(100, 110, 26), R(97, 70, 6, 14), Spire(100, 70, 6, 10), R(50, 96, 14, 44), R(136, 96, 14, 44), Spire(57, 96, 14, 12), Spire(143, 96, 14, 12), R(0, 188, 200, 12)] },
  'parliament-hu': { name: 'Parliament, Budapest', d: [R(10, 140, 180, 60), R(80, 110, 40, 30), Dome(100, 110, 22), Spire(100, 88, 10, 50), ...rep(8, i => Spire(18 + i * 22 + (i > 3 ? 10 : 0), 140, 6, 22)), R(0, 188, 200, 12)] },
  'prague-tower': { name: 'Old Town Bridge Tower, Prague', d: [R(70, 70, 60, 130), Poly([66, 70], [100, 20], [134, 70]), Spire(70, 70, 8, 30), Spire(130, 70, 8, 30), `M0 170Q50 160 70 170V200H0Z`, `M130 170Q150 160 200 170V200H130Z`], h: [Arch(86, 150, 28, 50)] },
  'gediminas': { name: 'Gediminas Tower, Vilnius', d: [`M0 200Q100 120 200 200Z`, R(80, 100, 40, 60), ...rep(4, i => R(80 + i * 11, 92, 7, 8)), R(96, 70, 2, 22), Poly([98, 70], [114, 76], [98, 82])], h: [Arch(94, 116, 12, 18)] },
  'riga-spire': { name: "St Peter's spire, Riga", d: [R(70, 120, 60, 80), R(84, 80, 32, 40), R(90, 56, 20, 24), Dome(100, 56, 10), Poly([96, 50], [100, 4], [104, 50]), Poly([40, 200], [40, 150], [55, 132], [70, 150], [70, 200]), Poly([130, 200], [130, 156], [145, 140], [160, 156], [160, 200])] },
  'stockholm-hall': { name: 'City Hall, Stockholm', d: [R(20, 140, 160, 60), R(118, 40, 34, 100), R(114, 32, 42, 10), Spire(135, 32, 14, 16), Circ(129, 12, 3), Circ(135, 8, 3), Circ(141, 12, 3), R(0, 190, 200, 10)], h: [...rep(6, i => R(28 + i * 14, 150, 6, 20))] },
  'helsinki-cathedral': { name: 'Helsinki Cathedral', d: [R(40, 120, 120, 80), R(80, 86, 40, 34), Dome(100, 86, 24), R(98, 52, 4, 12), R(48, 100, 16, 20), Dome(56, 100, 8), R(136, 100, 16, 20), Dome(144, 100, 8), Poly([60, 120], [100, 100], [140, 120]), ...rep(6, i => R(64 + i * 13, 130, 5, 50)), R(30, 180, 140, 20)] },
  'nyhavn': { name: 'Nyhavn, Copenhagen', d: [...rep(6, i => { const x = 6 + i * 32, h = 70 + (i * 37 % 40); return R(x, 200 - h, 30, h) + Poly([x, 200 - h], [x + 15, 200 - h - 20], [x + 30, 200 - h]); }), R(0, 186, 200, 14)] },
  'riesenrad': { name: 'Riesenrad, Vienna', d: [Ring(100, 90, 70, 64), Circ(100, 90, 8), ...rep(8, i => { const a = i * Math.PI / 4; return Poly([100 + Math.cos(a) * 6, 90 + Math.sin(a) * 6], [100 + Math.cos(a) * 66, 90 + Math.sin(a) * 66], [100 + Math.cos(a + .05) * 66, 90 + Math.sin(a + .05) * 66]); }), ...rep(15, i => { const a = i * Math.PI * 2 / 15; return R(100 + Math.cos(a) * 70 - 5, 90 + Math.sin(a) * 70, 10, 8); }), Poly([96, 90], [70, 200], [80, 200], [100, 100], [120, 200], [130, 200], [104, 90])] },
  'atomium': { name: 'Atomium, Brussels', d: [...[[100, 30], [60, 70], [140, 70], [100, 100], [60, 130], [140, 130], [100, 160]].map(([x, y]) => Circ(x, y, 14)).join(''), Poly([98, 30], [58, 128], [62, 132], [102, 34]), Poly([102, 30], [142, 128], [138, 132], [98, 34]), R(98, 30, 4, 130), Poly([60, 70], [140, 130], [138, 134], [58, 74]), Poly([140, 70], [60, 130], [62, 134], [142, 74]), Poly([96, 170], [80, 200], [90, 200], [100, 176], [110, 200], [120, 200], [104, 170])] },
  'aphrodite-rock': { name: "Aphrodite's Rock, Cyprus", d: [`M30 190Q40 120 70 110Q100 104 112 140Q120 170 124 190Z`, `M120 190Q130 160 150 156Q166 160 170 190Z`, `M0 186Q25 180 50 186T100 186T150 186T200 186V200H0Z`, Circ(160, 50, 16)] },
  'parthenon': { name: 'Parthenon, Athens', d: [Poly([30, 88], [100, 60], [170, 88]), R(28, 88, 144, 12), ...rep(8, i => R(34 + i * 18, 100, 9, 76)), R(22, 176, 156, 8), R(16, 184, 168, 16)] },
  'matterhorn': { name: 'Matterhorn, Switzerland', d: [Poly([0, 200], [40, 150], [70, 120], [100, 30], [112, 40], [130, 100], [160, 130], [200, 170], [200, 200]), Poly([100, 30], [106, 70], [96, 54])] },
  'burj-khalifa': { name: 'Burj Khalifa, Dubai', d: [Poly([80, 200], [84, 150], [88, 150], [90, 110], [94, 110], [95, 70], [98, 70], [99, 4], [101, 4], [102, 70], [105, 70], [106, 110], [110, 110], [112, 150], [116, 150], [120, 200]), R(30, 160, 24, 40), R(146, 150, 22, 50), R(56, 176, 18, 24)] },
  'fuji-torii': { name: 'Mount Fuji with torii, Japan', d: [Poly([0, 170], [70, 70], [90, 62], [110, 62], [130, 70], [200, 170], [200, 200], [0, 200])], h: [Poly([76, 64], [90, 56], [110, 56], [124, 64], [118, 84], [104, 74], [96, 80], [84, 76]), `M20 128Q100 116 180 128V136H20Z`, R(28, 140, 144, 7), R(44, 136, 9, 64), R(147, 136, 9, 64)] },
  'seoul-tower': { name: 'N Seoul Tower', d: [`M0 200Q100 140 200 200Z`, Poly([94, 170], [96, 70], [104, 70], [106, 170]), `M82 76Q100 60 118 76L114 90H86Z`, R(98.5, 10, 3, 54), `M20 168Q40 150 60 168Z`, R(30, 168, 20, 20)] },
  'marina-bay': { name: 'Marina Bay Sands, Singapore', d: [Poly([40, 200], [46, 70], [62, 70], [62, 200]), Poly([86, 200], [90, 70], [106, 70], [108, 200]), Poly([132, 200], [136, 70], [152, 70], [154, 200]), `M24 58H176Q178 66 170 70H30Q22 66 24 58Z`, R(0, 186, 200, 14)] },
  'petronas': { name: 'Petronas Towers, Kuala Lumpur', d: [Poly([46, 200], [48, 60], [56, 40], [62, 60], [64, 200]), Poly([136, 200], [138, 60], [144, 40], [152, 60], [154, 200]), R(54.5, 6, 2, 34), R(143.5, 6, 2, 34), R(64, 110, 72, 8), ...rep(5, i => R(44 + 0 * i, 80 + i * 24, 22, 3) + R(134, 80 + i * 24, 22, 3))] },
  'galata': { name: 'Galata Tower, Istanbul', d: [R(80, 70, 40, 130), R(76, 62, 48, 10), Poly([78, 62], [100, 14], [122, 62]), R(0, 186, 200, 14), Dome(40, 186, 24), R(16, 120, 4, 66)], h: [...rep(4, i => Arch(82 + i * 9, 72, 6, 14))] },
  'tallinn-towers': { name: 'Old Town towers, Tallinn', d: [R(20, 140, 160, 60), R(30, 100, 26, 40), Spire(43, 100, 30, 46), R(140, 92, 28, 48), Spire(154, 92, 32, 50), R(86, 70, 28, 70), Spire(100, 70, 32, 64)], h: [...rep(5, i => R(60 + i * 16, 132, 8, 8))] },
};
export const landmark = id => LANDMARKS[id] || LANDMARKS['big-ben'];
/** svg markup (inner) at the 200×200 box; detail colour defaults to a translucent white */
export const landmarkInner = (id, fill = '#0b2545', detail = 'rgba(255,255,255,.55)') => { const L = landmark(id); return L.d.map(p => `<path fill="${fill}" d="${p}"/>`).join('') + (L.h || []).map(p => `<path fill="${detail}" d="${p}"/>`).join(''); };
export const landmarkSvg = (id, fill, detail) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200">${landmarkInner(id, fill, detail)}</svg>`;
/** draw on a canvas 2D context into box (x, y, size) */
export function drawLandmark(ctx, id, x, y, size, fill, detail) {
  const L = landmark(id), k = size / 200;
  ctx.save(); ctx.translate(x, y); ctx.scale(k, k);
  ctx.fillStyle = fill; for (const p of L.d) ctx.fill(new Path2D(p));
  if (L.h && detail) { ctx.fillStyle = detail; for (const p of L.h) ctx.fill(new Path2D(p)); }
  ctx.restore();
}
