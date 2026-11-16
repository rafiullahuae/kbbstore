// KBB Studio · Designs · prompts for the offline image model, built automatically from the brief (pure, no DOM):
// industry + occasion + palette words + the occupation library's uniform template (Work Abroad) + the country pack.
//
// Rules the builder enforces (and tools/d-check.cjs tests):
//   - TEXT IS NEVER BAKED INTO A PICTURE: a prompt that asks for words, letters, a logo, a price, a sign or a slogan is
//     refused (`textRequest` names what was refused); every prompt ends with "no text, no letters, no logo, no watermark".
//     The words of the ad are always live text on the design, editable and translatable.
//   - PRODUCT PHOTOS ARE ALWAYS REAL: the model is never asked to draw a product, a bottle or a brand (refused); products
//     come from the catalogue cut-outs. The model makes backgrounds, scenes, textures, artwork and people.
//   - one-step model, no guidance: the "avoid" list cannot be a negative prompt; it is folded into the prompt as plain
//     "no ..." words and re-checked on the picture (check.js) before it is offered.
export const AVOID = ['text', 'letters', 'words', 'logo', 'watermark', 'signature', 'caption', 'label', 'brand name', 'blurry', 'deformed hands', 'extra fingers', 'distorted face'];
const TAIL = 'no text, no letters, no logo, no watermark, high quality, sharp focus';

/** -> null, or what in the prompt asks for text / a product (the builder refuses it) */
export function textRequest(p) {
  p = String(p || '').replace(/\bno (text|letters|logos?|watermarks?|words|captions?)\b/gi, '');
  const s = p.toLowerCase();
  const m = /["“”'‘’«»].{1,40}["“”'‘’«»]/.exec(p || '') ||
    /\b(saying|says|that says|with the (words?|text|title|slogan|caption)|text|lettering|letters|typography|headline|slogan|caption|logo|watermark|signage|sign that|price tag|label(led)?|written|write|font|percent|%\s*off|sale\b|discount)\b/.exec(s) ||
    /(نص|كتابة|شعار|عبارة|مكتوب)/.exec(p || '');
  return m ? m[0].trim() : null;
}
export function productRequest(p) {
  const m = /\b(bottle|serum|cream jar|tube|packaging|product shot|cosmetic product|skincare product|anua|medicube|joseon|biodance|numbuzin|celimax)\b/i.exec(String(p || ''));
  return m ? m[0] : null;
}
/** check a typed prompt: {ok, prompt, refused} -- refused prompts are not sent to the model */
export function sanitize(p) {
  const t = textRequest(p); if (t) return { ok: false, refused: `asks for text in the picture (“${t}”). Words go on the design as live text instead.` };
  const r = productRequest(p); if (r) return { ok: false, refused: `asks the model to draw a product (“${r}”). Product photos are always the real cut-outs from the catalogue.` };
  const clean = String(p || '').replace(/\s+/g, ' ').trim().slice(0, 300);
  return { ok: !!clean, prompt: withTail(clean) };
}
const withTail = (s) => (/no text/.test(s) ? s : `${s}, ${TAIL}`);

const COLOR_WORDS = [['#EF3340', 'red'], ['#00843D', 'green'], ['#C6395F', 'rose pink'], ['#E8789A', 'soft pink'], ['#FFF0F4', 'blush'], ['#BE8E2E', 'gold'], ['#C9A04E', 'champagne gold'], ['#002768', 'navy blue'], ['#0B2545', 'navy'], ['#BE0B32', 'crimson'], ['#FFFFFF', 'white'], ['#111111', 'black'], ['#1B2A4A', 'midnight blue'], ['#D4A64A', 'warm gold'], ['#13A89E', 'teal']];
function colourWords(hexes) {
  const out = [];
  const dist = (a, b) => { const p = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16)); const x = p(a), y = p(b); return Math.hypot(x[0] - y[0], x[1] - y[1], x[2] - y[2]); };
  for (const h of hexes.filter((x) => /^#[0-9a-f]{6}$/i.test(x || ''))) { const best = COLOR_WORDS.slice().sort((a, b) => dist(h, a[0]) - dist(h, b[0]))[0]; if (best && !out.includes(best[1])) out.push(best[1]); }
  return out.slice(0, 3);
}

/**
 * prompts for a brief: [{id, kind:'background'|'scene'|'worker'|'texture', label, prompt}] (3–5, best first)
 * opts.person ('a young South Asian man' ...), opts.region (occupations promptTokens {region}), opts.framing
 */
export function promptsFor(spec, palette, cat = {}, opts = {}) {
  const cols = colourWords([palette.acc, palette.bg2, ...(spec.occasion?.colors || [])]).join(' and ');
  const out = [];
  const add = (id, kind, label, prompt) => out.push({ id, kind, label, prompt: withTail(prompt) });
  const occ = (cat.occupations || []).find((o) => spec.occupations?.includes(o.id));
  const ctry = (cat.countries || []).find((c) => c.code === spec.countries?.[0]);
  if (occ) {
    const t = occ.prompt || `Professional photograph of {person} working as ${occ.inSentence || 'a worker'}, wearing ${occ.uniform}`;
    const person = opts.person || 'a confident skilled worker';
    const p = t.replace('{person}', person).replace('{region}', opts.region ? `, ${opts.region}` : '').replace('{framing}', opts.framing || 'half-body portrait').replace(/,\s*,/g, ',').replace(/no text, no logos, no watermark/i, '').trim().replace(/,$/, '');
    add('worker', 'worker', `${occ.name.en} in uniform`, p);
    add('worker-close', 'worker', `${occ.name.en} · uniform close-up`, p.replace(/half-body portrait|full-body/i, 'close-up of the uniform and tools'));
    if (ctry) add('worker-place', 'worker', `${occ.name.en} · in ${ctry.name}`, `${p}, ${occ.setting || 'at work'} in ${ctry.name}`);
  }
  if (ctry) {
    const lm = ctry.landmark?.name || ctry.name;
    add('scene', 'scene', `${lm}, golden hour`, `${lm} at golden hour, clean modern travel photography, wide shot, soft light, calm sky, ${spec.kind === 'study' ? 'university city atmosphere' : 'inviting'}`);
    add('scene-campus', 'scene', `${ctry.name} campus`, `bright modern university campus in ${ctry.name}, students walking in the distance, spring, soft daylight, architectural photography`);
  }
  if (spec.industry === 'beauty' || !out.length) {
    const occW = spec.occasion?.id === 'uae-national-day' ? `, subtle ${cols} silk ribbons, celebration` : spec.occasion ? `, ${spec.occasion.en} mood` : '';
    const mood = spec.tone === 'luxury' ? 'luxury' : spec.tone === 'playful' ? 'playful pastel' : 'soft elegant';
    add('bg-silk', 'background', 'Silk & petals', `${mood} beauty background, flowing ${cols || 'soft pink'} silk fabric with rose petals, studio light, empty space in the middle${occW}`);
    add('bg-water', 'background', 'Water & light', `${mood} skincare backdrop, clear water ripples and caustic light, ${cols || 'pastel'} tones, minimal, copy space`);
    add('bg-podium', 'background', 'Stone podium set', `empty ${cols || 'blush'} stone podium with soft shadows and dried flowers, minimal studio set, product photography background, nothing on the podium`);
    add('texture', 'texture', 'Cream texture', `macro texture of whipped cream swirl, ${cols || 'pink'} tint, soft light, abstract`);
  }
  if (spec.kind === 'event' || spec.kind === 'generic') add('abstract', 'background', 'Abstract light', `abstract ${cols} gradient light shapes, soft bokeh, modern, minimal`);
  return out;
}
