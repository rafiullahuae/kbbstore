// KBB Studio · themes · CONTACT ICONS: original line-and-fill glyphs for the call-to-action cards (phone, chat, email,
// globe, camera, music note, ghost, play button, tapping hand, calendar, pin, QR mark). Drawn here from our own paths, in
// a 100 × 100 box centred on (x, y): generic, recognisable pictures of the thing — never a company's trademark. The
// social glyphs are deliberately plain (a speech bubble with a handset, a camera outline, a note, a ghost, a play
// button) so they read as "WhatsApp / Instagram / TikTok / Snapchat / YouTube" next to the handle without copying a logo.
//
// Pure: drawIcon(c, id, x, y, size, col, opts) paints with the context's current transform; preview = export.
import { path2d, rr, TAU } from './art.js';

// a classic handset, drawn for this file (100 box)
const HANDSET = 'M27 9C21 9 12 15 12 25C12 52 48 88 75 88C85 88 91 79 91 73L91 64C91 60 88 57 85 56L71 52C68 51 64 52 62 55L57 62C46 57 43 54 38 43L45 38C48 36 49 32 48 29L44 15C43 11 40 9 36 9Z';
// a pointing hand: the index finger up, three folded fingers and the thumb (100 box)
const HAND = 'M40 14C40 8 50 8 50 14L50 46C52 43 60 43 61 48C63 45 71 45 72 51C74 48 82 49 82 55L82 70C82 82 74 92 62 92L52 92C44 92 38 88 33 81L20 62C17 57 22 52 27 55L40 66Z';
// a friendly ghost (round head, three soft scallops at the hem)
const GHOST = 'M50 10C30 10 22 26 22 42L22 74C22 78 18 80 15 82C20 86 27 84 31 81C35 86 42 88 50 84C58 88 65 86 69 81C73 84 80 86 85 82C82 80 78 78 78 74L78 42C78 26 70 10 50 10Z';

/** every glyph, by id: [label, draw(c)] with c already scaled to the 100 box centred on 0,0 */
const G = {
  phone: (c, col) => { c.translate(-51, -48); c.fillStyle = col; c.fill(path2d(HANDSET)); },
  chat: (c, col, o) => {
    // a round speech bubble with a small tail, a handset inside (cut out of the bubble)
    c.fillStyle = col; c.beginPath(); c.arc(0, -2, 40, 0, TAU); c.moveTo(-30, 24); c.lineTo(-42, 44); c.lineTo(-12, 36); c.closePath(); c.fill();
    c.save(); c.globalCompositeOperation = o.cut ? 'destination-out' : 'source-over'; c.fillStyle = o.cut ? '#000' : o.inner || '#ffffff';
    c.translate(-21, -23); c.scale(0.42, 0.42); c.fill(path2d(HANDSET)); c.restore();
  },
  email: (c, col) => {
    c.strokeStyle = col; c.lineWidth = 8; c.lineJoin = 'round'; c.lineCap = 'round';
    c.beginPath(); rr(c, -40, -28, 80, 56, 8); c.stroke();
    c.beginPath(); c.moveTo(-36, -22); c.lineTo(0, 6); c.lineTo(36, -22); c.stroke();
  },
  globe: (c, col) => {
    c.strokeStyle = col; c.lineWidth = 7;
    c.beginPath(); c.arc(0, 0, 40, 0, TAU); c.stroke();
    c.beginPath(); c.ellipse(0, 0, 17, 40, 0, 0, TAU); c.stroke();
    c.beginPath(); c.moveTo(-40, 0); c.lineTo(40, 0); c.stroke();
    c.lineWidth = 5; c.beginPath(); c.moveTo(-34, -20); c.quadraticCurveTo(0, -14, 34, -20); c.moveTo(-34, 20); c.quadraticCurveTo(0, 14, 34, 20); c.stroke();
  },
  camera: (c, col) => {
    c.strokeStyle = col; c.lineWidth = 8;
    c.beginPath(); rr(c, -38, -38, 76, 76, 22); c.stroke();
    c.beginPath(); c.arc(0, 0, 17, 0, TAU); c.stroke();
    c.fillStyle = col; c.beginPath(); c.arc(23, -23, 5.5, 0, TAU); c.fill();
  },
  note: (c, col) => {
    // a beamed pair of notes with a little offset echo (motion)
    const one = (dx, dy, a) => {
      c.save(); c.translate(dx, dy); c.globalAlpha *= a; c.fillStyle = col; c.strokeStyle = col; c.lineWidth = 8; c.lineCap = 'round';
      c.beginPath(); c.ellipse(-20, 26, 12, 9, -0.4, 0, TAU); c.fill(); c.beginPath(); c.ellipse(22, 18, 12, 9, -0.4, 0, TAU); c.fill();
      c.beginPath(); c.moveTo(-9, 24); c.lineTo(-9, -30); c.lineTo(33, -38); c.lineTo(33, 16); c.stroke();
      c.lineWidth = 10; c.beginPath(); c.moveTo(-9, -24); c.lineTo(33, -32); c.stroke(); c.restore();
    };
    one(-4, 3, 0.35); one(0, 0, 1);
  },
  ghost: (c, col, o) => {
    c.translate(-50, -48); c.fillStyle = col; c.fill(path2d(GHOST));
    c.fillStyle = o.inner || '#ffffff'; for (const x of [40, 60]) { c.beginPath(); c.ellipse(x, 40, 4.5, 6.5, 0, 0, TAU); c.fill(); }
  },
  play: (c, col, o) => {
    c.fillStyle = col; c.beginPath(); rr(c, -44, -31, 88, 62, 18); c.fill();
    c.fillStyle = o.inner || '#ffffff'; c.beginPath(); c.moveTo(-11, -16); c.lineTo(18, 0); c.lineTo(-11, 16); c.closePath(); c.fill();
  },
  hand: (c, col, o) => {
    c.translate(-50, -52); c.fillStyle = col; c.fill(path2d(HAND));
    c.strokeStyle = o.inner || 'rgba(0,0,0,0.25)'; c.lineWidth = 3; c.lineCap = 'round';
    c.beginPath(); c.moveTo(61, 50); c.lineTo(61, 62); c.moveTo(72, 55); c.lineTo(72, 64); c.stroke();
  },
  calendar: (c, col) => {
    c.strokeStyle = col; c.fillStyle = col; c.lineWidth = 7; c.lineJoin = 'round';
    c.beginPath(); rr(c, -38, -30, 76, 68, 9); c.stroke();
    c.fillRect(-38, -30, 76, 18);
    c.lineCap = 'round'; c.beginPath(); c.moveTo(-20, -42); c.lineTo(-20, -24); c.moveTo(20, -42); c.lineTo(20, -24); c.stroke();
    for (let r = 0; r < 2; r++) for (let k = 0; k < 3; k++) c.fillRect(-24 + k * 18, 2 + r * 16, 10, 9);
  },
  pin: (c, col, o) => {
    c.fillStyle = col; c.beginPath(); c.moveTo(0, 44); c.bezierCurveTo(-14, 22, -32, 6, -32, -12); c.arc(0, -12, 32, Math.PI, 0); c.bezierCurveTo(32, 6, 14, 22, 0, 44); c.fill();
    c.fillStyle = o.inner || '#ffffff'; c.beginPath(); c.arc(0, -12, 12, 0, TAU); c.fill();
  },
  qr: (c, col, o) => {
    // finder squares as rings (stroked), never clearRect: that would punch a hole through the video under the card
    c.fillStyle = col; c.strokeStyle = col; c.lineWidth = 6;
    for (const [x, y] of [[-40, -40], [12, -40], [-40, 12]]) { c.strokeRect(x + 3, y + 3, 22, 22); c.fillRect(x + 10, y + 10, 8, 8); }
    for (const [x, y] of [[16, 16], [30, 16], [16, 30], [30, 30], [2, -8], [-8, 2], [22, 2], [2, 22]]) c.fillRect(x, y, 9, 9);
  },
  star: (c, col) => { c.fillStyle = col; c.beginPath(); for (let i = 0; i < 10; i++) { const a = -Math.PI / 2 + (i * Math.PI) / 5, r = i % 2 ? 17 : 42; i ? c.lineTo(Math.cos(a) * r, Math.sin(a) * r) : c.moveTo(Math.cos(a) * r, Math.sin(a) * r); } c.closePath(); c.fill(); },
};
export const ICON_IDS = Object.keys(G);

/**
 * draw glyph `id` centred on (x, y), `size` across. opts: { inner: colour of the cut-outs drawn on top (eyes, play
 * triangle, the handset in the chat bubble), cut: punch the handset out of the bubble instead }
 */
export function drawIcon(c, id, x, y, size, col, opts = {}) {
  const fn = G[id]; if (!fn || !(size > 0.5)) return;
  c.save(); c.translate(x, y); c.scale(size / 100, size / 100);
  try { fn(c, col, opts); } finally { c.restore(); }
}
