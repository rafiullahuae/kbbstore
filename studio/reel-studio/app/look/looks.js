// KBB Studio · look · colour looks: each one is a set of shader parameters (a "LUT as numbers": readable, editable,
// identical in the preview and the export). gl.js's `grade()` applies them after the clip's own correction
// (shot match → white balance → exposure).

export const BASE = {
  exp: 0, con: 1, sat: 1, vib: 0, temp: 0, tint: 0, fade: 0, bw: 0, glow: 0, vig: 0, grain: 0, curve: 0, clar: 0, split: 0,
  lift: [0, 0, 0], gamma: [1, 1, 1], gain: [1, 1, 1], sh: [0, 0, 0], hi: [0, 0, 0], bwmix: [0.2126, 0.7152, 0.0722],
};

const L = (id, label, group, p, hint) => ({ id, label, group, hint, p: Object.assign({}, BASE, p) });

export const LOOKS = [
  L('original', 'Original', 'basic', {}, 'Correction only'),
  L('beauty-glow', 'Beauty glow', 'beauty', { exp: 0.1, con: 0.95, sat: 1.04, vib: 0.15, temp: 0.08, tint: 0.06, glow: 0.38, fade: 0.02, curve: 0.15 }, 'Soft, luminous skin'),
  L('clean-clinic', 'Clean clinic', 'beauty', { exp: 0.08, con: 1.06, sat: 0.9, temp: -0.12, tint: -0.02, gain: [1, 1.01, 1.03], glow: 0.08, clar: 0.15 }, 'Bright, neutral, trustworthy'),
  L('soft-skin', 'Soft skin warm', 'beauty', { exp: 0.06, con: 0.94, temp: 0.12, tint: 0.03, glow: 0.22, vib: 0.08 }, 'Gentle warm beauty'),
  L('pastel-dream', 'Pastel dream', 'beauty', { exp: 0.1, con: 0.84, sat: 0.84, tint: 0.07, temp: 0.04, glow: 0.3, fade: 0.11 }, 'Airy pastel'),
  L('luxury-moody', 'Luxury moody', 'mood', { exp: -0.15, con: 1.2, sat: 0.84, temp: 0.04, lift: [-0.02, -0.02, 0], sh: [0, 0.05, 0.08], hi: [0.08, 0.04, 0], split: 1, vig: 0.45, curve: 0.4 }, 'Deep, rich, premium'),
  L('teal-orange', 'Teal & orange', 'mood', { con: 1.1, sat: 1.08, sh: [-0.03, 0.04, 0.08], hi: [0.09, 0.03, -0.05], split: 1, curve: 0.2 }, 'Cinematic blockbuster'),
  L('night-neon', 'Night neon', 'mood', { con: 1.2, sat: 1.35, sh: [0.06, -0.02, 0.09], hi: [-0.03, 0.06, 0.08], split: 1, glow: 0.3, exp: -0.05 }, 'Club / city night'),
  L('travel-vivid', 'Travel vivid', 'travel', { con: 1.12, sat: 1.28, vib: 0.35, temp: 0.05, curve: 0.3, glow: 0.1, clar: 0.2 }, 'Punchy skies and colour'),
  L('desert-gold', 'Desert gold', 'travel', { temp: 0.35, sat: 1.12, con: 1.1, glow: 0.15, gain: [1.08, 1, 0.86] }, 'UAE golden hour'),
  L('cool-fresh', 'Cool fresh', 'travel', { temp: -0.26, tint: -0.05, sat: 1.04, exp: 0.05, gain: [0.97, 1.02, 1.05] }, 'Crisp, minty, clean'),
  L('corporate-trust', 'Corporate trust', 'business', { con: 1.05, sat: 0.94, temp: -0.08, hi: [-0.02, 0, 0.04], split: 1, clar: 0.15 }, 'Calm blue, professional'),
  L('crisp-hd', 'Crisp HD', 'business', { con: 1.14, sat: 1.08, curve: 0.25, clar: 0.45 }, 'Sharper, more contrast'),
  L('warm-golden', 'Warm golden', 'warm', { temp: 0.3, tint: 0.04, sat: 1.1, gain: [1.05, 1, 0.9], glow: 0.2, fade: 0.03 }, 'Sunny, inviting'),
  L('food-pop', 'Food pop', 'warm', { sat: 1.25, vib: 0.3, temp: 0.1, con: 1.1, exp: 0.05, clar: 0.2 }, 'Appetising colour'),
  L('film', 'Film', 'film', { curve: 0.5, fade: 0.06, sat: 0.9, sh: [-0.02, 0.03, 0.05], hi: [0.05, 0.02, -0.03], split: 1, grain: 0.28, gamma: [1, 0.98, 0.95] }, 'Analog film stock'),
  L('vintage-70s', 'Vintage 70s', 'film', { fade: 0.1, temp: 0.2, gain: [1.05, 0.98, 0.85], sat: 0.8, grain: 0.32, vig: 0.25 }, 'Faded retro'),
  L('bw', 'Black & white', 'mono', { bw: 1, con: 1.15, curve: 0.3, grain: 0.1 }, 'Classic monochrome'),
  L('bw-noir', 'Noir', 'mono', { bw: 1, con: 1.45, exp: -0.1, vig: 0.5, curve: 0.6, bwmix: [0.3, 0.6, 0.1] }, 'High-contrast drama'),
];
export const lookById = (id) => LOOKS.find((l) => l.id === id) || LOOKS[0];

/** the default fx entry for a clip's look */
export const gradeFx = (o = {}) => Object.assign({ type: 'look.grade', look: 'original', amount: 1, wb: [1, 1, 1], ev: 0, mg: [1, 1, 1], mo: [0, 0, 0] }, o);
