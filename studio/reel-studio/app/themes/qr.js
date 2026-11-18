// KBB Studio · themes · QR: a small QR Code encoder written for the "Scan to chat" card (no dependency, no network).
//
//   qrMatrix(text) -> { size, get(x, y) -> bool, version } | null     byte mode (UTF-8), error correction level M,
//                                                                     versions 1–10 (up to 213 bytes: a wa.me link,
//                                                                     a website, a phone number), best of the 8 masks
// Written from the public QR Code specification (ISO/IEC 18004): Reed–Solomon over GF(256) with 0x11D, the BCH format
// and version words, the zig-zag placement and the four penalty rules. Memoised per string: a card draws the same
// matrix every frame, so it is computed once.

// [total codewords, EC codewords per block, blocks] at level M, versions 1..10
const SPEC = [null, [26, 10, 1], [44, 16, 1], [70, 26, 1], [100, 18, 2], [134, 24, 2], [172, 16, 4], [196, 18, 4], [242, 22, 4], [292, 22, 5], [346, 26, 5]];
const ALIGN = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

function gmul(x, y) { let z = 0; for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11d); z ^= ((y >>> i) & 1) * x; } return z & 255; }
function rsDivisor(deg) {
  const r = new Array(deg).fill(0); r[deg - 1] = 1; let root = 1;
  for (let i = 0; i < deg; i++) { for (let j = 0; j < deg; j++) { r[j] = gmul(r[j], root); if (j + 1 < deg) r[j] ^= r[j + 1]; } root = gmul(root, 2); }
  return r;
}
function rsRemainder(data, div) {
  const r = div.map(() => 0);
  for (const b of data) { const f = b ^ r.shift(); r.push(0); for (let i = 0; i < div.length; i++) r[i] ^= gmul(div[i], f); }
  return r;
}
const dataCap = (v) => SPEC[v][0] - SPEC[v][1] * SPEC[v][2];

function encodeData(bytes, v) {
  const bits = [], put = (val, n) => { for (let i = n - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
  put(4, 4); put(bytes.length, v < 10 ? 8 : 16); for (const b of bytes) put(b, 8);
  const cap = dataCap(v) * 8;
  put(0, Math.min(4, cap - bits.length));
  while (bits.length % 8) bits.push(0);
  const out = []; for (let i = 0; i < bits.length; i += 8) out.push(bits.slice(i, i + 8).reduce((a, b) => (a << 1) | b, 0));
  for (let pad = 0xec; out.length < dataCap(v); pad ^= 0xec ^ 0x11) out.push(pad);
  // blocks: the short ones first, the long ones (one more data byte) after; then interleave
  const [total, ecLen, nb] = SPEC[v], shortLen = Math.floor(total / nb), nShort = nb - (total % nb), div = rsDivisor(ecLen), blocks = [];
  for (let i = 0, k = 0; i < nb; i++) {
    const dl = shortLen - ecLen + (i < nShort ? 0 : 1), d = out.slice(k, k + dl); k += dl;
    blocks.push({ d, e: rsRemainder(d, div) });
  }
  const res = [], maxD = Math.max(...blocks.map((b) => b.d.length));
  for (let i = 0; i < maxD; i++) for (const b of blocks) if (i < b.d.length) res.push(b.d[i]);
  for (let i = 0; i < ecLen; i++) for (const b of blocks) res.push(b.e[i]);
  return res;
}

function build(v, codewords, mask) {
  const size = v * 4 + 17, M = Array.from({ length: size }, () => new Array(size).fill(false)), F = Array.from({ length: size }, () => new Array(size).fill(false));
  const set = (x, y, d) => { if (x < 0 || y < 0 || x >= size || y >= size) return; M[y][x] = d; F[y][x] = true; };
  for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
  const finder = (cx, cy) => { for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) { const d = Math.max(Math.abs(dx), Math.abs(dy)); set(cx + dx, cy + dy, d !== 2 && d !== 4); } };
  finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
  const al = ALIGN[v], n = al.length;
  for (let i = 0; i < n; i++) for (let j = 0; j < n; j++) {
    if ((i === 0 && j === 0) || (i === 0 && j === n - 1) || (i === n - 1 && j === 0)) continue;
    for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(al[i] + dx, al[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
  }
  const format = (m) => {
    const data = (0 << 3) | m; let rem = data;   // level M = 00
    for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    const bits = ((data << 10) | rem) ^ 0x5412, b = (i) => ((bits >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) set(8, i, b(i));
    set(8, 7, b(6)); set(8, 8, b(7)); set(7, 8, b(8));
    for (let i = 9; i < 15; i++) set(14 - i, 8, b(i));
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, b(i));
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, b(i));
    set(8, size - 8, true);
  };
  format(0);
  if (v >= 7) {
    let rem = v; for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const bits = (v << 12) | rem;
    for (let i = 0; i < 18; i++) { const bit = ((bits >>> i) & 1) === 1, a = size - 11 + (i % 3), b = Math.floor(i / 3); set(a, b, bit); set(b, a, bit); }
  }
  // the data, zig-zag from the bottom-right
  let i = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
      const x = right - j, up = ((right + 1) & 2) === 0, y = up ? size - 1 - vert : vert;
      if (!F[y][x] && i < codewords.length * 8) { M[y][x] = ((codewords[i >>> 3] >>> (7 - (i & 7))) & 1) === 1; i++; }
    }
  }
  const MASKS = [(x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x) => x % 3 === 0, (x, y) => (x + y) % 3 === 0, (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
    (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0, (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0, (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0];
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!F[y][x] && MASKS[mask](x, y)) M[y][x] = !M[y][x];
  format(mask);
  return { size, M };
}

function penalty({ size, M }) {
  let p = 0;
  const runs = (get) => {
    for (let a = 0; a < size; a++) {
      let run = 1;
      for (let b = 1; b < size; b++) { if (get(a, b) === get(a, b - 1)) { run++; if (run === 5) p += 3; else if (run > 5) p++; } else run = 1; }
      // finder-like 1:1:3:1:1 with four light modules on a side
      for (let b = 0; b + 10 < size; b++) {
        const s = Array.from({ length: 11 }, (_, k) => get(a, b + k));
        const core = s[4] && !s[5] && s[6] && s[7] && s[8] && !s[9] && s[10];
        if ((!s[0] && !s[1] && !s[2] && !s[3] && core) || (s[0] && !s[1] && s[2] && s[3] && s[4] && !s[5] && s[6] && !s[7] && !s[8] && !s[9] && !s[10])) p += 40;
      }
    }
  };
  runs((a, b) => M[a][b]); runs((a, b) => M[b][a]);
  for (let y = 0; y < size - 1; y++) for (let x = 0; x < size - 1; x++) { const c = M[y][x]; if (c === M[y][x + 1] && c === M[y + 1][x] && c === M[y + 1][x + 1]) p += 3; }
  let dark = 0; for (const row of M) for (const c of row) if (c) dark++;
  p += Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
  return p;
}

const MEMO = new Map();
export function qrMatrix(text) {
  const s = String(text ?? ''); if (!s) return null;
  if (MEMO.has(s)) return MEMO.get(s);
  const bytes = [...new TextEncoder().encode(s)];
  let v = 1; while (v <= 10 && bytes.length + 2 + (v < 10 ? 0 : 1) > dataCap(v)) v++;
  let out = null;
  if (v <= 10) {
    const cw = encodeData(bytes, v);
    let best = null, bp = Infinity;
    for (let m = 0; m < 8; m++) { const q = build(v, cw, m), pen = penalty(q); if (pen < bp) { bp = pen; best = q; } }
    out = { size: best.size, version: v, get: (x, y) => best.M[y]?.[x] === true };
  }
  MEMO.set(s, out); if (MEMO.size > 64) MEMO.delete(MEMO.keys().next().value);
  return out;
}

/** draw a matrix into (x, y, side) with a quiet zone of `quiet` modules, dark modules in `ink` on a `paper` square */
export function drawQr(c, q, x, y, side, ink = '#000000', paper = '#ffffff', quiet = 2) {
  if (!q) return;
  const n = q.size + quiet * 2, m = side / n;
  c.fillStyle = paper; c.fillRect(x, y, side, side);
  c.fillStyle = ink; c.beginPath();
  for (let yy = 0; yy < q.size; yy++) for (let xx = 0; xx < q.size; xx++) if (q.get(xx, yy)) c.rect(x + (xx + quiet) * m, y + (yy + quiet) * m, m + 0.02 * m, m + 0.02 * m);
  c.fill();
}
