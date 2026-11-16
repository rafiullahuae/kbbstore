// KBB Studio · Designs · colour schemes generated per brief (pure): from the company's kit, the mood/tone, the occasion's
// colours and the destination country's flag -- every scheme contrast-checked (ink on bg ≥ 4.5:1, button text on button
// ≥ 4.5:1, ink on card ≥ 4.5:1) before it is offered. Saved favourites live per company (designs/palettes.json).
import { norm, contrast, reach, ink, mix, hsl, fromHsl, withL, lum, shift } from './color.js';

/** a scheme: {id, name, bg, bg2, ink, sub, acc, acc2, onAcc, card, cardInk, deco:[...], dark} -- fixed so it passes */
export function scheme(id, name, { bg, bg2, ink: k, acc, acc2, card, deco = [] }) {
  bg = norm(bg); bg2 = norm(bg2 || bg); acc = norm(acc); acc2 = norm(acc2 || acc); card = norm(card || mix(bg, '#FFFFFF', lum(bg) > 0.4 ? 0.7 : 0.08));
  const mid = mix(bg, bg2, 0.5);
  // ink must read on both ends of the background gradient
  let ik = reach(k || ink(mid), bg, 4.5); if (contrast(ik, bg2) < 4.5) ik = reach(ik, bg2, 4.5);
  if (contrast(ik, bg) < 4.5 || contrast(ik, bg2) < 4.5) ik = contrast('#FFFFFF', mid) > contrast('#111111', mid) ? '#FFFFFF' : '#111111';
  let sub = mix(ik, mid, 0.22); if (contrast(sub, bg) < 4.5 || contrast(sub, bg2) < 4.5) sub = ik;
  const onAcc = ink(acc);
  const accText = reach(acc, mid, 4.5); // the accent when used AS text on the background
  const cardInk = reach(ink(card), card, 4.5);
  return { id, name, bg, bg2, ink: ik, sub, acc, acc2, onAcc, accText, card, cardInk, deco: deco.map((d) => norm(d)), dark: lum(mid) < 0.25 };
}

export function paletteCheck(p) {
  const fails = [];
  if (contrast(p.ink, p.bg) < 4.5) fails.push('ink/bg');
  if (contrast(p.ink, p.bg2) < 4.5) fails.push('ink/bg2');
  if (contrast(p.onAcc, p.acc) < 4.5) fails.push('button text/button');
  if (contrast(p.cardInk, p.card) < 4.5) fails.push('card text/card');
  if (contrast(p.accText, mix(p.bg, p.bg2, 0.5)) < 4.5) fails.push('accent text/bg');
  return fails;
}

/** 5–6 schemes for a brief: brand light, brand dark, occasion, mood, country/flag, and a soft neutral */
export function schemesFor(spec, ws = {}, cat = {}) {
  const c = ws.colors || {};
  const P = norm(c.primary, '#C6395F'), S = norm(c.secondary, '#2A2228'), A = norm(c.accent, '#BE8E2E');
  const soft = norm(c.soft, mix(P, '#FFFFFF', 0.9)), inkC = norm(c.ink, S);
  const out = [];
  const tone = spec.tone || 'calm';
  out.push(scheme('brand-light', 'Brand · light', { bg: soft, bg2: mix(soft, P, 0.12), ink: inkC, acc: P, acc2: shift(P, 0, 0, -0.08), card: '#FFFFFF', deco: [P, A, mix(P, '#FFFFFF', 0.5)] }));
  out.push(scheme('brand-deep', 'Brand · deep', { bg: lum(S) < 0.08 ? S : withL(S, 0.12), bg2: withL(P, 0.16), ink: '#FFFFFF', acc: lum(A) > 0.15 ? A : P, acc2: A, card: mix(S, '#FFFFFF', 0.08), deco: [P, A] }));
  const occ = spec.occasion;
  if (occ?.colors?.length) {
    const [o1, o2] = occ.colors;
    if (occ.id === 'uae-national-day') {
      out.push(scheme('occasion-ivory', occ.en + ' · ivory', { bg: '#FFF7F3', bg2: '#F6E3DD', ink: '#2A1A1C', acc: '#00843D', acc2: '#EF3340', card: '#FFFFFF', deco: ['#EF3340', '#00843D', '#000000', '#FFFFFF'] }));
      out.push(scheme('occasion-night', occ.en + ' · emerald night', { bg: '#0B2A1F', bg2: '#04140E', ink: '#FFFFFF', acc: '#EF3340', acc2: '#C9A04E', card: '#123B2C', deco: ['#EF3340', '#00843D', '#FFFFFF', '#C9A04E'] }));
    } else out.push(scheme('occasion', occ.en, { bg: lum(o1) < 0.2 ? o1 : mix(o1, '#FFFFFF', 0.85), bg2: lum(o1) < 0.2 ? withL(o1, 0.06) : mix(o1, '#FFFFFF', 0.7), acc: o2 || o1, acc2: o1, deco: occ.colors }));
  }
  const ctry = (cat.countries || []).find((x) => x.code === spec.countries?.[0]);
  if (ctry?.colors?.length) {
    const flag = ctry.colors.map((x) => norm(x));
    const darkF = flag.slice().sort((a, b) => lum(a) - lum(b))[0];
    out.push(scheme('flag', ctry.name + ' colours', { bg: '#FFFFFF', bg2: mix(flag[0], '#FFFFFF', 0.9), ink: P, acc: flag.find((x) => lum(x) > 0.05 && lum(x) < 0.6) || P, card: '#FFFFFF', deco: flag }));
    out.push(scheme('flag-deep', ctry.name + ' · deep', { bg: withL(P, Math.min(0.2, hsl(P)[2])), bg2: withL(P, 0.08), ink: '#FFFFFF', acc: flag.find((x) => lum(x) > 0.12 && lum(x) < 0.7) || A, acc2: darkF, card: withL(P, 0.24), deco: flag }));
  }
  // the mood
  const MOOD = {
    luxury: () => scheme('mood-luxury', 'Luxury · champagne', { bg: '#1A1416', bg2: '#2C2023', ink: '#FFFFFF', acc: '#C9A04E', acc2: '#E6C98A', card: '#2A2124', deco: ['#C9A04E', P] }),
    playful: () => scheme('mood-playful', 'Playful · candy', { bg: mix(P, '#FFFFFF', 0.82), bg2: mix(A, '#FFFFFF', 0.78), ink: withL(P, 0.2), acc: P, acc2: A, card: '#FFFFFF', deco: [P, A, '#FFD166'] }),
    bold: () => scheme('mood-bold', 'Bold · contrast', { bg: '#111111', bg2: '#1E1E1E', ink: '#FFFFFF', acc: lum(A) > 0.2 ? A : '#FFC233', acc2: P, card: '#222222', deco: [P, '#FFC233'] }),
    urgent: () => scheme('mood-urgent', 'Urgent · hot', { bg: withL(P, 0.42), bg2: withL(P, 0.3), ink: '#FFFFFF', acc: '#FFE066', acc2: '#FFFFFF', card: withL(P, 0.22), deco: ['#FFE066', '#FFFFFF'] }),
    professional: () => scheme('mood-pro', 'Professional · navy', { bg: '#F4F7FB', bg2: '#E3EAF4', ink: lum(P) < 0.1 ? P : '#0B2545', acc: P, acc2: S, card: '#FFFFFF', deco: [P, S, A] }),
    calm: () => scheme('mood-calm', 'Calm · pastel', { bg: '#F7F3EF', bg2: mix(P, '#FFFFFF', 0.86), ink: '#2E2A2B', acc: shift(P, 0, -0.1, -0.05), card: '#FFFFFF', deco: [mix(P, '#FFFFFF', 0.5), '#D8CFC4'] }),
    warm: () => scheme('mood-warm', 'Warm · sunset', { bg: '#FFF4E8', bg2: '#FFE1CC', ink: '#3B2418', acc: '#C8553D', card: '#FFFFFF', deco: ['#F28F3B', '#C8553D'] }),
  };
  out.push((MOOD[tone] || MOOD.calm)());
  if (tone !== 'luxury' && spec.industry === 'beauty') out.push(MOOD.luxury());
  if (spec.industry === 'consultancy' && tone !== 'professional') out.push(MOOD.professional());
  // every scheme passes, or it is dropped
  return out.filter((p) => !paletteCheck(p).length);
}

/** a scheme the owner edited in the editor: re-derived through the same checks */
export const fromColors = (p) => scheme(p.id || 'custom', p.name || 'Custom', p);
export { fromHsl };
