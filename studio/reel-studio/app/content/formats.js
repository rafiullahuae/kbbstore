// KBB Studio · content · proven formats as READY TIMELINES. One click lays the whole reel out — slots for his footage
// (with what to film and how long), the on-screen text, the caption look, the CTA — and every slot fills with one drop.
// A slot is an item of type 'content.slot' (layers.js): a brand-tinted card saying what goes there; dropping a clip
// turns it into a normal clip with the same place, time and effects (e.g. the before/after wipe). ONE undo step each.
import { createItem, findMedia, tracksOfKind, projectDuration } from '../core/project.js';
import { ops, r1, uid, ensureTrack, brandText, placedBy } from './lib/items.js';
import { brandPop, contrast } from './lib/catalogue.js';

export const FORMAT_TAG = 'content.format';

// ── the formats: a list of beats; each beat = [t0, dur, ...things]. Regions: full | top | bottom | left | right ──
//   S(key, label, guide, region='full', extra)   a footage slot (same key = same clip, filled together)
//   T(text, role, y=0.2)                          on-screen text ('hook' | 'label' | 'big' | 'cta' | 'myth' | 'fact' | 'tick')
//   P(n)                                          a big step number badge
const S = (key, label, guide, region = 'full', extra = {}) => ({ k: 'slot', key, label, guide, region, ...extra });
const T = (text, role = 'label', y, extra = {}) => ({ k: 'text', text, role, y, ...extra });
const P = (n) => ({ k: 'num', n });
const CTA = (txt) => T(txt, 'cta', 0.66);

export const FORMATS = [
  // ── KBB ──
  { id: 'kbb-before-after', ws: 'kbb', name: 'Before / after (split + wipe)', look: 'v-box', recipe: 'kbb-before-after', vars: { days: '7', product: 'this serum' },
    desc: 'Split screen hook, then the after wipes over the before. Film both from the SAME angle and light.',
    beats: (v) => [
      [0, 3, S('before', 'Before', 'Bare skin, same angle, front light', 'top'), S('after', 'After', `After ${v.days} days, same angle`, 'bottom'), T(`${v.days} days of ${v.product}`, 'hook', 0.47)],
      [3, 3.6, S('before', 'Before', 'Same clip as the top half', 'full'), T('BEFORE', 'label', 0.16)],
      [4.2, 2.4, S('after', 'After', 'Same clip as the bottom half', 'full', { wipe: { t0: 0, dur: 1.1 } })],
      [6.6, 3.4, S('after', 'After', 'Hold on the result', 'full', { offset: 2.4 }), T('AFTER ✨', 'label', 0.16)],
      [10, 2.5, S('product', 'The product', 'Hold the product next to your face', 'full'), CTA('Shop at kbeautybliss.com')],
    ] },
  { id: 'kbb-3-products', ws: 'kbb', name: '"3 products for…"', look: 'v-pop', recipe: 'kbb-routine-steps', vars: { goal: 'glowing skin' },
    desc: 'Hook with all three, then one product per beat with a big number. Keep each shot moving (pick up, turn, apply).',
    beats: (v) => [
      [0, 2.5, S('all', 'All three', 'All 3 products in frame, quick push-in'), T(`3 products for ${v.goal}`, 'hook', 0.2)],
      [2.5, 4, S('p1', 'Product 1', 'Pick it up, show the label, one swatch'), P(1), T('Product one', 'label', 0.74)],
      [6.5, 4, S('p2', 'Product 2', 'Pick it up, show the label, apply'), P(2), T('Product two', 'label', 0.74)],
      [10.5, 4, S('p3', 'Product 3', 'Pick it up, show the label, apply'), P(3), T('Product three', 'label', 0.74)],
      [14.5, 2.5, S('face', 'The result', 'Your face, glowing, smile'), CTA('Which one first? 👇')],
    ] },
  { id: 'kbb-grwm', ws: 'kbb', name: 'GRWM (get ready with me)', look: 'v-clean', recipe: 'kbb-grwm', vars: { occasion: 'a night out' },
    desc: 'Six fast steps from bare face to done. Talk while you apply; each step is 3 seconds.',
    beats: (v) => [
      [0, 3, S('intro', 'Intro', 'Bare face to camera, say the occasion'), T(`GRWM for ${v.occasion}`, 'hook', 0.2)],
      [3, 3, S('s1', 'Cleanse', 'Cleanser in hands, foam, rinse'), T('Step 1 · Cleanse', 'label', 0.18)],
      [6, 3, S('s2', 'Serum', 'Drops on the face, pat in'), T('Step 2 · Serum', 'label', 0.18)],
      [9, 3, S('s3', 'Moisturise', 'Cream on the cheeks, smooth out'), T('Step 3 · Moisturise', 'label', 0.18)],
      [12, 3, S('s4', 'Sunscreen / make-up', 'SPF or base, blend'), T('Step 4 · Protect', 'label', 0.18)],
      [15, 3, S('final', 'Final look', 'Turn to the light, smile'), T('Ready ✨', 'big', 0.5), CTA('Shop the routine · link in bio')],
    ] },
  { id: 'kbb-texture', ws: 'kbb', name: 'Texture close-up', look: 'v-luxe', recipe: 'kbb-texture-demo', vars: { product: 'this cream' },
    desc: 'Macro shots, no talking needed. Slow and close; the sound of the pump and the swatch sells it.',
    beats: (v) => [
      [0, 2, S('reveal', 'Product reveal', 'Product turning slowly, soft light'), T('The texture 🤍', 'hook', 0.2)],
      [2, 4, S('macro', 'Texture macro', 'Pump / scoop very close, slow'), T(v.product, 'label', 0.78)],
      [6, 4, S('swatch', 'Swatch & spread', 'Swatch on the hand, spread with a finger')],
      [10, 3, S('glow', 'Absorbed', 'Hand or cheek after, catching the light'), T('Absorbs in seconds', 'label', 0.78)],
      [13, 2.5, S('reveal', 'Product again', 'Same as the reveal', 'full', { offset: 1 }), CTA('Shop now · kbeautybliss.com')],
    ] },
  { id: 'kbb-dupe', ws: 'kbb', name: 'Dupe or worth it?', look: 'v-stack', recipe: 'kbb-ugc-review', vars: { price: 'AED 79' },
    desc: 'The famous one against ours: same tests, side by side, a clear verdict.',
    beats: (v) => [
      [0, 2.5, S('famous', 'The famous one', 'The expensive product, label to camera', 'top'), S('ours', 'Ours', 'The KBB product, same pose', 'bottom'), T('Dupe or worth it?', 'hook', 0.47)],
      [2.5, 4, S('famous', 'The famous one', 'Swatch it, say the price', 'full', { offset: 1 }), T('$$$ · the famous one', 'label', 0.16)],
      [6.5, 4, S('ours', 'Ours', 'Swatch it the same way', 'full', { offset: 1 }), T(`${v.price} ✨`, 'label', 0.16)],
      [10.5, 4, S('famous', 'Texture A', 'Left hand: the famous one', 'left', { offset: 3 }), S('ours', 'Texture B', 'Right hand: ours', 'right', { offset: 3 }), T('Same texture?', 'label', 0.16)],
      [14.5, 3, S('verdict', 'Verdict', 'Your face, the honest verdict'), T('WORTH IT ✅', 'big', 0.42), CTA('Link in bio')],
    ] },
  // ── Planet Careers ──
  { id: 'pc-visa-approved', ws: 'planet-careers', name: 'Visa approved reaction', look: 'v-box', recipe: 'pc-success-story', vars: { country: 'Germany', weeks: '6' },
    desc: 'The moment of the email or the stamp, then the proof. Real reactions only (with the client\'s OK).',
    beats: (v) => [
      [0, 4, S('reaction', 'The reaction', 'Opening the email / the passport, real reaction'), T('Wait for it…', 'hook', 0.2)],
      [2.6, 1.4, T('VISA APPROVED ✅', 'big', 0.45)],
      [4, 4, S('proof', 'The proof', 'Passport with the visa page (cover personal data)'), T(`${v.country} study visa · ${v.weeks} weeks`, 'label', 0.18)],
      [8, 3.5, S('client', 'Client message', 'The client saying thank you / next step'), CTA('Book your free consultation')],
    ] },
  { id: 'pc-documents-30', ws: 'planet-careers', name: 'Documents in 30 s', look: 'v-clean', recipe: 'pc-documents-checklist', vars: { country: 'Germany' },
    desc: 'One document per beat with a tick. Lay each one on the desk and point at it.',
    beats: (v) => [
      [0, 3, S('intro', 'Intro', 'You at the desk, all documents in a pile'), T(`Documents for a ${v.country} student visa`, 'hook', 0.2)],
      ...['Passport', 'Admission letter', 'Bank statement', 'Health insurance', 'Language certificate', 'Photos & forms'].map((d, i) => [3 + i * 3.5, 3.5, S('d' + (i + 1), d, `Put the ${d.toLowerCase()} on the desk, point at it`), T('✅ ' + d, 'tick', 0.74)]),
      [24, 4, S('outro', 'Outro', 'You to camera: "save this"'), CTA('Save this · book a free check')],
    ] },
  { id: 'pc-myth-fact', ws: 'planet-careers', name: 'Myth vs fact', look: 'v-stack', recipe: 'pc-faq-myth', vars: { m1: 'You need IELTS for every country', f1: 'Many universities accept other proofs', m2: 'Studying abroad is only for the rich', f2: 'Germany has free tuition at public universities', m3: 'Visas take a year', f3: 'Most take 4 to 12 weeks' },
    desc: 'Three myths, each busted by a fact. Say the myth with a doubtful face, the fact with a smile.',
    beats: (v) => [
      [0, 2.5, S('talk', 'You to camera', 'Talking head, chest up'), T('MYTH vs FACT', 'hook', 0.2)],
      ...[1, 2, 3].flatMap((n, i) => [[2.5 + i * 7, 3.5, S('talk', 'You to camera', 'Say the myth', 'full', { offset: 2.5 + i * 7 }), T('❌ MYTH: ' + v['m' + n], 'myth', 0.2)], [6 + i * 7, 3.5, S('talk', 'You to camera', 'Say the fact', 'full', { offset: 6 + i * 7 }), T('✅ FACT: ' + v['f' + n], 'fact', 0.2)]]),
      [23.5, 3, S('talk', 'You to camera', 'The call to action', 'full', { offset: 23.5 }), CTA('Book your free consultation')],
    ] },
  { id: 'pc-country-ab', ws: 'planet-careers', name: 'Country A vs B', look: 'v-box', recipe: 'pc-country-spotlight', vars: { a: 'Germany', b: 'Canada' },
    desc: 'Split screen: one country on top, the other below. Three rounds, then your verdict.',
    beats: (v) => [
      [0, 3, S('a', v.a, `${v.a}: city / campus / flag shot`, 'top'), S('b', v.b, `${v.b}: city / campus / flag shot`, 'bottom'), T(`${v.a} vs ${v.b}`, 'hook', 0.47)],
      [3, 4, S('a', v.a, 'Tuition', 'top', { offset: 3 }), S('b', v.b, 'Tuition', 'bottom', { offset: 3 }), T('Round 1 · Tuition', 'label', 0.47)],
      [7, 4, S('a', v.a, 'Work while studying', 'top', { offset: 7 }), S('b', v.b, 'Work while studying', 'bottom', { offset: 7 }), T('Round 2 · Work hours', 'label', 0.47)],
      [11, 4, S('a', v.a, 'Stay after studies', 'top', { offset: 11 }), S('b', v.b, 'Stay after studies', 'bottom', { offset: 11 }), T('Round 3 · Stay after', 'label', 0.47)],
      [15, 3.5, S('verdict', 'Your verdict', 'You to camera: who wins for whom'), CTA('Not sure? Book a free consultation')],
    ] },
  { id: 'pc-cost', ws: 'planet-careers', name: 'Cost breakdown', look: 'v-pop', recipe: 'pc-country-spotlight', vars: { country: 'Germany', tuition: '€0', rent: '€450', food: '€250', travel: '€60', visa: '€75', total: '€835 / month' },
    desc: 'What it really costs, line by line, with the number big on screen.',
    beats: (v) => [
      [0, 3, S('intro', 'Intro', `You or a ${v.country} city shot`), T(`What it REALLY costs to study in ${v.country}`, 'hook', 0.2)],
      ...[['Tuition', v.tuition], ['Rent', v.rent], ['Food', v.food], ['Transport', v.travel], ['Visa fee', v.visa]].map(([l, x], i) => [3 + i * 3, 3, S('c' + (i + 1), l, `B-roll for ${l.toLowerCase()}`), T(l, 'label', 0.3), T(x, 'big', 0.45)]),
      [18, 3, S('total', 'Total', 'You to camera'), T('Total ≈ ' + v.total, 'big', 0.4)],
      [21, 3, S('total', 'Total', 'Same shot', 'full', { offset: 3 }), CTA('Get your exact budget · free consultation')],
    ] },
  { id: 'pc-testimonial', ws: 'planet-careers', name: 'Student testimonial', look: 'v-clean', recipe: 'pc-success-story', vars: { name: 'Student name', course: 'MSc · Germany' },
    desc: 'The student tells it: the result first, then the story, then life there.',
    beats: (v) => [
      [0, 3, S('hook', 'The result', 'Student: "I got my visa in 6 weeks"'), T('From application to visa 🎓', 'hook', 0.2)],
      [3, 14, S('story', 'The story', 'Student talking: worries, steps, how we helped'), T(`${v.name} · ${v.course}`, 'lower', 0.66, { dur: 4 })],
      [17, 4, S('life', 'Life there', 'Campus / city b-roll of the student')],
      [21, 3, S('hook', 'Closing line', 'Student smiling', 'full', { offset: 3 }), CTA('Your turn · book a free consultation')],
    ] },
  { id: 'pc-day-in-life', ws: 'planet-careers', name: 'Day in the life (student)', look: 'v-bounce', recipe: 'pc-country-spotlight', vars: { country: 'Germany' },
    desc: 'A student\'s day in seven quick shots with the time on screen.',
    beats: (v) => [
      ...[['7:30 AM ☕', 'Breakfast / coffee'], ['8:30 AM 🚲', 'Commute (bike, tram)'], ['9:00 AM 🎓', 'Lecture'], ['1:00 PM 🥗', 'Lunch with friends'], ['4:00 PM 💼', 'Part-time job'], ['7:00 PM 📚', 'Study session'], ['9:00 PM 🌙', 'City at night']].map(([tm, g], i) => [i * 2.6, 2.6, S('m' + (i + 1), g, g), T(tm, 'label', 0.18), ...(i === 0 ? [T(`Day in the life · student in ${v.country}`, 'hook', 0.3, { dur: 2.6 })] : [])]),
      [18.2, 3, S('m7', 'Outro', 'Same as the last shot', 'full', { offset: 2.6 }), CTA('Study in ' + v.country + ' · free consultation')],
    ] },
];

export const formatsFor = (ws) => {
  const s = `${ws?.id || ''} ${ws?.brandRef || ''} ${ws?.industry || ''}`;
  const pc = /planet|consult|immigra|visa|study/i.test(s), kbb = /kbb|beauty|skin/i.test(s);
  return FORMATS.filter((f) => (pc && f.ws === 'planet-careers') || (kbb && f.ws === 'kbb') || (!pc && !kbb));
};
export const formatById = (id) => FORMATS.find((f) => f.id === id) || null;

const REGION = (W, H, r) => ({ full: { x: 0, y: 0, w: W, h: H }, top: { x: 0, y: 0, w: W, h: H / 2 }, bottom: { x: 0, y: H / 2, w: W, h: H / 2 },
  left: { x: 0, y: 0, w: W / 2, h: H }, right: { x: W / 2, y: 0, w: W / 2, h: H } }[r]);

/** the timeline of a format for a canvas and company: { items:[{kind, item}], slots:[{key,label,guide,n,dur}], length } */
export function layoutFormat(f, canvas, ws = {}, vars = {}) {
  const W = canvas.w, H = canvas.h, k = Math.min(W, H) / 1080, v = { ...f.vars, ...vars };
  const pop = brandPop(ws), ink = contrast(pop, '#111111') > contrast(pop, '#ffffff') ? '#111111' : '#ffffff';
  const col = ws.colors || {}, slotStyle = { color: col.secondary || '#2a2228', color2: '#0d0b0d', accent: pop };
  const items = [], slots = new Map();
  let length = 0, n = 0;
  for (const [t0, dur, ...things] of f.beats(v)) {
    length = Math.max(length, t0 + dur);
    for (const th of things) {
      if (th.k === 'slot') {
        if (!slots.has(th.key)) slots.set(th.key, { key: th.key, label: th.label, guide: th.guide, n: ++n, dur: 0 });
        const sl = slots.get(th.key); sl.dur = Math.max(sl.dur, (th.offset || 0) + dur);
        const box = REGION(W, H, th.region);
        items.push({ kind: th.region === 'full' && !th.wipe ? 'video' : 'overlay', item: createItem('content.slot', { t0: r1(t0), in: r1(th.offset || 0), out: r1((th.offset || 0) + dur), ...box,
          slot: { key: th.key, label: th.label, guide: th.guide, n: sl.n, region: th.region }, style: slotStyle, fx: th.wipe ? [{ type: 'content.wipe', t0: th.wipe.t0, dur: th.wipe.dur }] : [], auto: FORMAT_TAG, name: `Slot ${sl.n} · ${th.label}` }) });
        if (th.wipe) items.push({ kind: 'overlay', item: createItem('shape', { t0: r1(t0), in: 0, out: r1(th.wipe.dur + 0.15), x: -6 * k, y: 0, w: 12 * k, h: H, style: { shape: 'rect', fill: '#ffffff' },
          kf: { x: [[th.wipe.t0, -6 * k, 'inout'], [th.wipe.t0 + th.wipe.dur, W - 6 * k, 'linear']] }, fadeOut: 0.15, auto: FORMAT_TAG, name: 'Wipe line' }) });
      } else if (th.k === 'text') {
        const d = th.dur || dur, role = th.role;
        const size = { hook: 76, label: 60, big: 120, cta: 58, myth: 52, fact: 52, tick: 70, lower: 44 }[role] * k * (W > H ? 0.8 : 1);
        const style = role === 'hook' ? brandText(ws, { size, color: '#ffffff', stroke: '#000000', strokeW: 6 * k, shadow: true })
          : role === 'big' ? brandText(ws, { size, font: 'Anton', weight: 400, color: pop, stroke: '#000000', strokeW: 8 * k })
          : role === 'cta' ? brandText(ws, { size, bg: pop, color: ink })
          : role === 'myth' ? brandText(ws, { size, bg: '#d7263d', color: '#ffffff', upper: false, radius: 24 * k })
          : role === 'fact' ? brandText(ws, { size, bg: '#1f9d55', color: '#ffffff', upper: false, radius: 24 * k })
          : role === 'lower' ? { ...brandText(ws, { size, bg: 'rgba(0,0,0,.62)', color: '#ffffff', upper: false, radius: 18 * k }), align: 'left' }
          : brandText(ws, { size, color: '#ffffff', bg: 'rgba(0,0,0,.55)', radius: 22 * k });
        const lines = Math.ceil(String(th.text).length * size * 0.62 / (W * 0.84));
        const h = Math.round(size * 1.25 * Math.min(3, Math.max(1, lines)) + size * 0.7);
        const w = role === 'cta' ? W * 0.76 : W * 0.86, y = Math.min(H * 0.8 - h, Math.max(H * 0.08, (th.y ?? 0.2) * H - h / 2));
        items.push({ kind: 'text', item: createItem('text', { t0: r1(t0), in: 0, out: r1(d), x: r1((W - w) / 2), y: r1(y), w: r1(w), h, text: th.text, style,
          fx: role === 'label' || role === 'lower' ? [] : [{ type: 'content.pop', dur: 0.3 }], fadeOut: 0.12, auto: FORMAT_TAG, name: role[0].toUpperCase() + role.slice(1) + ' · ' + th.text.slice(0, 24) }) });
      } else if (th.k === 'num') {
        const s = 230 * k;
        items.push({ kind: 'text', item: createItem('text', { t0: r1(t0), in: 0, out: r1(dur), x: r1(W * 0.06), y: r1(H * 0.1), w: r1(s), h: r1(s), text: String(th.n),
          style: { ...brandText(ws, { size: s * 0.62, font: 'Anton', weight: 400, color: ink, bg: pop }), radius: s / 2, pad: 0 }, fx: [{ type: 'content.pop', dur: 0.3 }], auto: FORMAT_TAG, name: 'Number ' + th.n }) });
      }
    }
  }
  return { items, slots: [...slots.values()], length: r1(length) };
}

/** build a format into the open project (its timeline is replaced only where the format's items go; his clips stay) */
export function buildFormat(ctx, id, vars = {}) {
  const f = formatById(id); if (!f) throw new Error('No such format');
  const p = ctx.project, ws = ctx.workspace || {};
  const L = layoutFormat(f, p.canvas, ws, vars);
  const cmds = [];
  const old = placedBy(p, FORMAT_TAG); if (old.length) cmds.push(ops.removeItems(old, 'Format'));
  // a format starts the reel: anything of his already on the timeline moves after it
  const shift = projectDuration(p) > 0 && !old.length ? L.length : 0;
  if (shift) for (const tr of p.tracks) for (const it of tr.items) cmds.push(ops.setItem(it.id, { t0: r1(it.t0 + shift) }, 'Make room'));
  const tracks = { video: ensureTrack(p, 'video', cmds), overlay: ensureTrack(p, 'overlay', cmds), text: ensureTrack(p, 'text', cmds) };
  cmds.push(ops.addItems(L.items.map(({ kind, item }) => ({ trackId: tracks[kind].id, item })), 'Format'));
  cmds.push(ops.setProject({ recipe: f.recipe || p.recipe, meta: { ...p.meta, content: { ...(p.meta?.content || {}), format: { id: f.id, vars: { ...f.vars, ...vars }, look: f.look } } } }, 'Format'));
  ctx.history.apply(ops.batch('Format · ' + f.name, cmds));
  return { id: f.id, length: L.length, slots: L.slots, items: L.items.length };
}

/** the slots of the format on the timeline: [{key, label, guide, n, items:[ids], filled, dur}] */
export function slotsOf(p) {
  const by = new Map();
  for (const tr of p.tracks) for (const it of tr.items) if (it.slot) {
    const s = by.get(it.slot.key) || { key: it.slot.key, label: it.slot.label, guide: it.slot.guide, n: it.slot.n, items: [], filled: null, dur: 0 };
    s.items.push(it.id); if (it.type === 'clip') s.filled = it.media; s.dur = Math.max(s.dur, it.out); by.set(it.slot.key, s);
  }
  return [...by.values()].sort((a, b) => a.n - b.n);
}

/** drop a clip/photo into a slot (every place that slot appears). Video is used from its start; slowed if too short. */
export function fillSlot(ctx, key, mediaId) {
  const p = ctx.project, m = findMedia(p, mediaId); if (!m) throw new Error('Pick a clip from the bin');
  if (m.kind === 'audio') throw new Error('A slot takes a video or a photo');
  const list = [];
  for (const tr of p.tracks) for (const it of tr.items) if (it.slot?.key === key) list.push(it);
  if (!list.length) throw new Error('No such slot');
  const need = Math.max(...list.map((it) => it.out));
  const speed = m.kind === 'video' && m.dur && m.dur < need ? Math.max(0.25, m.dur / need) : 1;
  const cmds = list.map((it) => ops.setItem(it.id, { type: 'clip', media: m.id, in: r1(it.in * speed), out: r1(it.out * speed), speed: r1(speed) || 1, zoom: m.kind === 'image' ? { from: 1, to: 1.06, cx: 0.5, cy: 0.5 } : null, volume: tr0(p, it) ? 1 : 0, name: `${it.slot.label} · ${m.name}` }, 'Fill slot'));
  ctx.history.apply(ops.batch('Fill slot · ' + list[0].slot.label, cmds));
  return { key, items: list.length, speed };
}
const tr0 = (p, it) => tracksOfKind(p, 'video').some((t) => t.items.includes(it));

/** fill every empty slot from the bin in order (unused media first) */
export function autoFill(ctx) {
  const p = ctx.project, used = new Set(slotsOf(p).map((s) => s.filled).filter(Boolean));
  const free = p.media.filter((m) => (m.kind === 'video' || m.kind === 'image') && !used.has(m.id) && !/^brands\//.test(m.src));
  let n = 0;
  ctx.history.begin('Fill slots from the bin');
  try { for (const s of slotsOf(p)) { if (s.filled || !free.length) continue; fillSlot(ctx, s.key, free.shift().id); n++; } } finally { ctx.history.end(); }
  return n;
}
export { uid };
