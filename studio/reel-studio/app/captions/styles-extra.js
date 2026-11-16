// Viral caption looks (round 2, Lane C). Data only, in the same delta-from-default shape as styles.js RAW, which
// appends them to its list — so every look is a normal caption style: editable in the Style tab, drawn by the same
// renderer in the preview and the export.
//
// What makes them "viral":
//   * 1–3 words at a time: rowChars ≈ 12 and one row, short cues (maxDur 1.3 s), so the eye never reads a sentence.
//   * the spoken word in the BRAND colour: `brand` names the field app/content fills with the company's colour
//     ('active' = the word's text colour, 'activeBg' = the box behind it, 'glow', 'bg' = marker/pill colour).
//   * in the middle third: pos.y 0.56 of the platform safe box puts the cue's centre at ~50 % of a 9:16 frame
//     (safe box 12 %–80 %), ~55 % on 4:5 / 1:1 / 16:9. app/content moves it off faces and products per moment.
//   * keyword pops: app/content adds the product / price / country words as their own pop-up items (editable).
// The ids start with 'v-' so a recipe or the content module can ask for "any viral look".

const MID = { y: 0.56 };
const SHORT = { rowChars: 12, maxRows: 1, minDur: 0.35, maxDur: 1.3 };

export const EXTRA_STYLES = [
  { id: 'v-pop', name: 'Viral pop', group: 'Viral', brand: 'active', ...SHORT, font: 'Montserrat', weight: 900, size: 92, case: 'upper',
    outline: { w: 12, color: '#000000' }, shadow: { blur: 0, x: 0, y: 7, color: '#000000' }, active: '#ff2f7a', anim: 'pop', pos: MID },
  { id: 'v-box', name: 'Brand box', group: 'Viral', brand: 'activeBg', ...SHORT, font: 'Montserrat', weight: 900, size: 88, case: 'upper',
    outline: { w: 9, color: '#000000' }, activeBg: '#ff2f7a', anim: 'highlight-box', pos: MID },
  { id: 'v-stack', name: 'Big stack', group: 'Viral', brand: 'active', ...SHORT, rowChars: 10, font: 'Anton', weight: 400, size: 128, case: 'upper',
    tracking: 1, outline: { w: 12, color: '#000000' }, active: '#ffd400', anim: 'highlight-scale', pos: MID },
  { id: 'v-clean', name: 'Clean colour', group: 'Viral', brand: 'active', ...SHORT, font: 'Inter', weight: 800, size: 84,
    shadow: { blur: 22, x: 0, y: 5, color: 'rgba(0,0,0,0.8)' }, active: '#3dd9ff', anim: 'highlight-color', pos: MID },
  { id: 'v-glow', name: 'Brand glow', group: 'Viral', brand: 'glow', ...SHORT, font: 'Montserrat', weight: 800, size: 86, case: 'upper',
    color: '#ffffff', active: '#ffffff', glow: { blur: 30, color: '#ff2bd6' }, anim: 'highlight-glow', pos: MID },
  { id: 'v-luxe', name: 'Luxe words', group: 'Viral', brand: 'active', ...SHORT, rowChars: 12, font: 'Playfair Display', fontAr: 'Amiri', weight: 800, size: 88,
    color: '#fff8ec', active: '#d4af37', shadow: { blur: 16, x: 0, y: 4, color: 'rgba(0,0,0,0.75)' }, anim: 'highlight-scale', pos: MID },
  { id: 'v-bounce', name: 'Bounce words', group: 'Viral', brand: 'active', ...SHORT, font: 'Bangers', weight: 400, size: 112, tracking: 2,
    outline: { w: 11, color: '#111111' }, active: '#ffde00', anim: 'bounce', pos: MID },
  { id: 'v-marker', name: 'Marker swipe', group: 'Viral', brand: 'bg', ...SHORT, font: 'Montserrat', weight: 900, size: 80, case: 'upper',
    color: '#111111', bg: { type: 'marker', color: '#ffe14d', opacity: 1, radius: 6, pad: 10 }, anim: 'marker', pos: MID },
  { id: 'v-one', name: 'One word punch', group: 'Viral', brand: 'active', font: 'Anton', weight: 400, size: 170, case: 'upper',
    outline: { w: 14, color: '#000000' }, active: '#ff2f7a', color: '#ff2f7a', anim: 'one-word', pos: MID, rowChars: 10, maxRows: 1, minDur: 0.3, maxDur: 1 },
  { id: 'v-karaoke', name: 'Fill sweep', group: 'Viral', brand: 'active', ...SHORT, font: 'Montserrat', weight: 900, size: 86, case: 'upper',
    dim: 'rgba(255,255,255,0.5)', active: '#ff2f7a', outline: { w: 9, color: '#120910' }, anim: 'karaoke', pos: MID },
  { id: 'v-arabic', name: 'Arabic punch', group: 'Viral', brand: 'active', ...SHORT, font: 'Cairo', fontAr: 'Cairo', weight: 900, size: 92,
    outline: { w: 10, color: '#000000' }, active: '#ffd400', anim: 'pop', pos: MID },
  { id: 'v-pill', name: 'Soft pill', group: 'Viral', brand: 'active', ...SHORT, font: 'Montserrat', weight: 800, size: 70,
    color: '#2a2228', active: '#c6395f', bg: { type: 'pill', color: '#ffffff', opacity: 0.95, radius: 48, pad: 20 }, anim: 'highlight-scale', pos: MID },
];

export const VIRAL_IDS = EXTRA_STYLES.map((s) => s.id);
