// KBB Studio · BRIEF → DESIGNS, the screen: type a brief (English or Arabic, in his own words) → the reading (editable
// chips) → 6–8 distinct designs that pass every design rule, in each size he asked for → open one in the design editor.
// Right column: the generated visuals -- AI pictures (offline model), colour schemes, icons, artwork.
import { engine } from '../core/engine.js';
import { APP_NAME } from '../core/config.js';
import { h, clear, toast } from '../editor/ui.js';
import { state as wsState, wsBadge } from '../editor/workspaces.js';
import { catalogueAll } from './catalogue.js';
import { readBrief } from './llm.js';
import { generate } from './generate.js';
import { renderCanvas, PX, prepare } from './render.js';
import { FAMILY_NAMES } from './layouts.js';
import { schemesFor, paletteCheck } from './lib/palette.js';
import { iconsFor, iconImage, searchIcons, loadIcons } from './icons.js';
import { drawArt, artFor, ART_KINDS } from './art.js';
import { promptsFor, sanitize } from './imagegen/prompts.js';
import * as IG from './imagegen/index.js';
import { openDesignEditor, saveDesign, listSaved } from './editor.js';
import { claudeStatus } from '../settings/claude.js';
const ap = (el, ...k) => { el.append(...k.flat(Infinity).filter((x) => x != null && x !== false)); return el; };

let cssOn = false;
const ls = { get: (k) => { try { return localStorage.getItem(k); } catch { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch {} } };
const EXAMPLES = {
  beauty: ['UAE National Day: 30% off Anua and BoJ, story and post, luxury', 'New arrival: Biodance collagen eye cream, soft and calm, square', 'عرض اليوم الوطني: خصم ٣٠٪ على أنوا وبيوتي أوف جوسون'],
  consultancy: ['Study in Germany, September intake open, book a free consultation', 'Work abroad: welders wanted for Poland, apply on WhatsApp', 'الدراسة في كندا - التسجيل مفتوح لدفعة يناير'],
};

export async function openDesigns(root, { home, brief: initial = '', auto = false } = {}) {
  if (!cssOn) { document.head.appendChild(h('link', { rel: 'stylesheet', href: new URL('./designs.css', import.meta.url).href })); cssOn = true; }
  const ws = wsState.current;
  const cat = await catalogueAll();
  const S = { spec: null, via: '', designs: [], size: null, image: null, workerPhoto: null, seed: 1, palettes: [] };
  const lastKey = 'kbbstudio.brief.' + ws.id;
  const briefIn = h('textarea.dz-brief#dz-brief', { dir: 'auto', placeholder: 'Describe the ad in your own words — English or Arabic.\ne.g. "National Day 30% off Anua, story and post, luxury"', 'aria-label': 'Brief' });
  briefIn.value = initial || ls.get(lastKey) || '';
  const prefs = await fetch('/api/prefs', { headers: { 'X-RS-Token': document.querySelector('meta[name="rs-token"]')?.content || '' } }).then((r) => r.json()).catch(() => ({}));
  const cl = await claudeStatus();
  const smart = h('input#dz-smart', { type: 'checkbox', checked: !!(cl.set && prefs.smartBrief), disabled: !cl.set });
  const specBox = h('div.dz-spec#dz-spec', h('span.dz-small', 'The reading of your brief shows here.'));
  const prog = h('div.dz-prog#dz-prog');
  const grid = h('div.dz-grid#dz-grid', h('div.dz-empty', 'Type a brief and press ', h('b', '✨ Make designs'), '. You get 6–8 different professional designs in your company’s kit, each checked against the design rules (contrast, safe zones, sizes, no overlaps, products never cropped).'));
  const sizeTabs = h('div.dz-tabs#dz-sizes');
  const saved = h('div.dz-saved#dz-saved');
  const right = h('aside.dz-col.dz-right#dz-visuals');
  const goBtn = h('button.btn.primary.big.dz-go#dz-go', { on: { click: () => run() } }, '✨ Make designs');

  root.replaceChildren(h('div.dz',
    h('header.topbar',
      h('button.brand', { title: 'Home', on: { click: () => home?.rerender() } }, h('span.logo-dot', '▶'), h('b', APP_NAME)),
      h('span.ws-switch', wsBadge(ws), h('span.ws-name', ws.name)),
      h('b', 'Brief → Designs'),
      h('div.grow'),
      h('button.btn.ghost#dz-config', { on: { click: () => import('../settings/configure.js').then((m) => m.openConfigure('claude')) } }, '⚙ Configure'),
      h('button.btn', { on: { click: () => home?.rerender() } }, '← Home')),
    h('div.dz-body',
      h('aside.dz-col.dz-left',
        h('h2', 'Your brief'), briefIn,
        h('div.dz-ex', (EXAMPLES[cat && /consult|immigra|planet/i.test(ws.industry + ws.id) ? 'consultancy' : 'beauty'] || []).map((t) => h('button.chip', { on: { click: () => { briefIn.value = t; } } }, t.length > 46 ? t.slice(0, 44) + '…' : t))),
        h('label.dz-row', smart, h('span', h('b', 'Smart brief (Claude)'), h('span.dz-small', cl.set ? ' — asks once per session' : ' — connect Claude in ⚙ Configure'))),
        goBtn, prog, specBox,
        h('h3', 'Saved designs'), saved),
      h('main.dz-col.dz-main', h('div.dz-row', h('h2', 'Designs'), h('div.grow'), sizeTabs), grid),
      right)));

  // ── the reading ──
  function chip(label, img) { return h('span.dz-chip', img ? h('img', { src: '/' + img, alt: '' }) : null, label); }
  function paintSpec(sp, via, note, refused = []) {
    const P = (id) => cat.products.find((p) => p.id === id), Ct = (c) => cat.countries.find((x) => x.code === c), O = (o) => cat.occupations.find((x) => x.id === o);
    ap(clear(specBox), 
      h('div.dz-row', h('b', 'What I understood'), h('div.grow'), h('span.dz-via', via === 'claude' ? '✦ Claude' : via === 'local' ? '🧠 Offline model' : '⚡ Built-in reader')),
      h('div.dz-kv',
        h('span', 'Type'), h('span', sp.kind),
        sp.occasion ? [h('span', 'Occasion'), h('span', sp.occasion.en)] : null,
        sp.offer ? [h('span', 'Offer'), h('span', sp.offer.en + (sp.offer.code ? ' · code ' + sp.offer.code : ''))] : null,
        sp.brands.length ? [h('span', 'Brands'), h('div.dz-chips', sp.brands.map((b) => chip(b + (sp.missingBrands.includes(b) ? ' (no photo yet)' : ''))))] : null,
        sp.products.length ? [h('span', 'Products'), h('div.dz-chips', sp.products.map((id) => chip(P(id)?.name || id, P(id)?.src)))] : null,
        sp.countries.length ? [h('span', 'Country'), h('div.dz-chips', sp.countries.map((c) => chip(Ct(c)?.name || c, Ct(c)?.flagSrc)))] : null,
        sp.visa ? [h('span', 'Route'), h('span', sp.visa + (sp.intake ? ' · intake ' + sp.intake : ''))] : null,
        sp.occupations.length ? [h('span', 'Jobs'), h('div.dz-chips', sp.occupations.map((o) => chip(O(o)?.name.en || o)))] : null,
        h('span', 'Audience'), h('span', sp.audience || '—'),
        h('span', 'Tone'), h('span', sp.tone),
        h('span', 'Languages'), h('span', sp.languages.join(' + ').toUpperCase()),
        h('span', 'Sizes'), h('div.dz-chips', sp.sizes.map((s) => chip(s))),
        h('span', 'Button'), h('span', `${sp.cta.en} · ${sp.cta.ar}`)),
      note ? h('div.dz-via', note) : null,
      ...sp.notes.map((n) => h('div.dz-note', '⚠ ' + n)),
      refused.length ? h('div.dz-small', 'Not used from the model: ' + refused.slice(0, 4).join('; ')) : null);
  }

  // ── the designs ──
  function paintGrid() {
    clear(grid);
    if (!S.designs.length) { grid.appendChild(h('div.dz-empty', 'No design passed the rules for this brief. Try another wording or fewer words.')); return; }
    for (const D of S.designs) {
      const size = D.sizes.includes(S.size) ? S.size : D.sizes[0];
      const cv = renderCanvas(D, size, 0.24);
      const card = h('div.dz-card', { dataset: { design: D.id, family: D.family }, on: { click: () => edit(D) } }, cv,
        h('div.dz-card-foot', h('b', FAMILY_NAMES[D.family] + ' · ' + (D.lang === 'ar' ? 'عربي' : 'EN')), h('span.dz-score', { title: 'Design rules: every hard rule passed in every size' }, '✓ ' + D.score)),
        h('div.dz-sizes', D.sizes.map((s) => h('span.dz-chip', s)), h('div.grow'),
          h('button.btn.small', { on: { click: (e) => { e.stopPropagation(); quickExport(D); } } }, '⬇ PNG')));
      grid.appendChild(card);
    }
  }
  function paintSizes() {
    clear(sizeTabs);
    for (const s of S.spec?.sizes || []) sizeTabs.appendChild(h('button.chip' + (s === S.size ? '.on' : ''), { dataset: { size: s }, on: { click: () => { S.size = s; paintSizes(); paintGrid(); } } }, `${s} ${PX[s].join('×')}`));
  }
  async function run({ keepSpec = false } = {}) {
    const text = briefIn.value.trim();
    if (!text) { briefIn.focus(); toast('Type a brief first', 'warn'); return; }
    ls.set(lastKey, text);
    goBtn.disabled = true;
    try {
      if (!keepSpec || !S.spec) {
        prog.textContent = 'Reading the brief…';
        const r = await readBrief(text, ws, cat, { smart: smart.checked, onProgress: (t) => { prog.textContent = t; } });
        S.spec = r.spec; S.via = r.via;
        paintSpec(r.spec, r.via, r.note, r.refused);
        S.size = S.spec.sizes[0];
        paintSizes();
        paintVisuals();
      }
      prog.textContent = 'Designing…';
      const t0 = performance.now();
      const res = await generate(S.spec, ws, cat, { count: 8, seed: S.seed, image: S.image, workerPhoto: S.workerPhoto, onProgress: (f, l) => { prog.textContent = l; } });
      S.designs = res.designs; S.palettes = res.palettes;
      prog.textContent = `${res.designs.length} designs · every rule passed in ${S.spec.sizes.join(' / ')} · ${Math.round(performance.now() - t0)} ms` + (res.rejected.length ? ` · ${res.rejected.length} layouts rejected` : '');
      paintGrid();
    } catch (e) { console.error(e); prog.textContent = 'Something went wrong: ' + e.message; }
    goBtn.disabled = false;
  }
  async function quickExport(D) {
    const size = D.sizes.includes(S.size) ? S.size : D.sizes[0];
    await prepare(D);
    const blob = await new Promise((r) => renderCanvas(D, size, 1).toBlob(r, 'image/png'));
    const path = `workspaces/${ws.id}/exports/designs/${D.family}-${size}-${D.id}.png`;
    try { await engine.writeFile(path, blob); toast('Saved ' + path.split('/').pop() + ' in exports/designs', 'ok'); } catch (e) { toast('Not saved: ' + e.message, 'error'); }
  }
  function edit(D) {
    openDesignEditor(D, { ws, cat, spec: S.spec, size: S.size, home, onSaved: () => paintSaved() });
  }
  async function paintSaved() {
    clear(saved);
    const list = await listSaved(ws.id);
    if (!list.length) { saved.appendChild(h('span.dz-small', 'Designs you save appear here.')); return; }
    for (const d of list.slice(0, 20)) saved.appendChild(h('img', { src: '/' + d.thumb + '?v=' + d.mtime, alt: d.name, title: d.name, on: { click: async () => { const D = await engine.readFile(d.path, 'json'); edit(D); } } }));
  }

  // ── visuals: AI pictures, colours, icons, artwork ──
  async function paintVisuals() {
    clear(right);
    const tabs = h('div.dz-tabs'), body = h('div');
    const T = [['ai', '🖼 AI pictures'], ['colours', '🎨 Colours'], ['icons', '✳ Icons'], ['art', '✦ Artwork']];
    const show = (id) => { for (const b of tabs.children) b.classList.toggle('on', b.dataset.t === id); clear(body); VIS[id](body); };
    for (const [id, l] of T) tabs.appendChild(h('button.chip', { dataset: { t: id }, on: { click: () => show(id) } }, l));
    ap(right, h('h2', 'Visuals'), tabs, body);
    show('ai');
  }
  const VIS = {
    async ai(el) {
      const st = await IG.status();
      const P = (S.palettes[0]) || schemesFor(S.spec || { kind: 'brand', languages: ['en'], industry: 'beauty' }, ws, cat)[0];
      const list = S.spec ? promptsFor(S.spec, P, cat) : [];
      const sel = h('select#dz-prompt', { 'aria-label': 'Picture idea' }, list.map((p, i) => h('option', { value: i }, p.label)), h('option', { value: 'own' }, 'My own words…'));
      const own = h('textarea#dz-own', { placeholder: 'Describe a background, scene or person — no words in it (the ad’s words stay live text).', hidden: list.length > 0, rows: 3 });
      sel.addEventListener('change', () => { own.hidden = sel.value !== 'own'; });
      const nIn = h('select#dz-n', [2, 4, 6].map((n) => h('option', { value: n, selected: n === 4 }, n + ' pictures')));
      const lock = h('input#dz-lock', { type: 'checkbox' }), seedIn = h('input.de-num#dz-seed', { type: 'number', value: 1234, min: 0 });
      const out = h('div.dz-cands#dz-cands'), msg = h('div.dz-small#dz-ai-msg', st.installed ? st.note : 'The picture model is not installed yet (≈ 980 MB, once). ⚙ Configure → Models → “Pictures (AI)”.');
      const goAI = h('button.btn.primary#dz-ai-go', { disabled: !st.installed, on: { click: async () => {
        const p = sel.value === 'own' ? own.value : list[+sel.value]?.prompt;
        const chk = sanitize(p);
        if (!chk.ok) { msg.className = 'dz-note'; msg.textContent = '✗ Not made: ' + (chk.refused || 'type what the picture shows'); return; }
        goAI.disabled = true; clear(out); msg.className = 'dz-small';
        try {
          const cands = await IG.generate(p, { n: +nIn.value, seed: lock.checked ? +seedIn.value : null, onProgress: (f, t) => { msg.textContent = `${t || ''} ${Math.round(f * 100)}%`; },
            onImage: (c) => out.appendChild(candEl(c)) });
          msg.textContent = `${cands.length} pictures · ${Math.round(cands.reduce((s, c) => s + c.ms, 0) / cands.length / 100) / 10} s each · ${st.device === 'webgpu' ? 'Mac GPU' : 'CPU'}`;
        } catch (e) { msg.className = 'dz-note'; msg.textContent = e.message; }
        goAI.disabled = false;
      } } }, '✨ Make pictures');
      function candEl(c) {
        const box = h('div.dz-cand', { title: 'Seed ' + c.seed }, c.canvas, h('span.seed', '#' + c.seed), c.flags.length ? h('span.flag', '⚠ ' + c.flags.map((f) => f.id).join(', ')) : null);
        box.addEventListener('click', async () => {
          for (const x of out.children) x.classList.remove('on'); box.classList.add('on');
          seedIn.value = c.seed;
          msg.textContent = 'Saving…';
          let cv = c.canvas, up = false;
          try { msg.textContent = 'Enlarging ×2…'; cv = st.upscaler || st.device === 'wasm' ? await IG.upscale(c.canvas) : IG.smoothUpscale(c.canvas); up = cv.dataset.method; } catch (e) { console.warn('[designs] upscale', e); }
          const path = await IG.save(ws.id, cv, { prompt: c.prompt, seed: c.seed, upscaled: up, flags: c.flags });
          const kind = list[+sel.value]?.kind;
          if (kind === 'worker') S.workerPhoto = path; else S.image = path;
          msg.textContent = `Saved (${cv.width}×${cv.height}${up === 'swin2sr' ? ', AI-upscaled' : up === 'smooth' ? ', enlarged' : ''}) · used in the designs`;
          run({ keepSpec: true });
        });
        return box;
      }
      ap(el, h('p.dz-small', `Made on this Mac by an open model (${IG.MODEL.name}, ${IG.MODEL.licence}). Text is never drawn into a picture; product photos are always the real ones.`),
        sel, own, h('div.dz-row', nIn, h('label.dz-row', lock, 'Lock seed'), seedIn), goAI, msg, out,
        S.image || S.workerPhoto ? h('button.btn.small', { on: { click: () => { S.image = S.workerPhoto = null; run({ keepSpec: true }); } } }, 'Stop using the AI picture') : null);
    },
    colours(el) {
      if (!S.spec) { ap(el, h('p.dz-small', 'Make designs first.')); return; }
      const list = S.palettes.length ? S.palettes : schemesFor(S.spec, ws, cat);
      ap(el, h('p.dz-small', `${list.length} schemes for this brief, from your kit, the occasion, the mood and the country — each contrast-checked (text ≥ 4.5:1).`));
      for (const p of list) {
        const sw = h('span.dz-sw', [p.bg, p.bg2, p.acc, p.acc2, p.ink, ...(p.deco || []).slice(0, 2)].map((c) => h('i', { style: { background: c } })));
        el.appendChild(h('div.dz-pal', { dataset: { pal: p.id } }, sw, h('span', p.name), h('div.grow'), h('span.dz-score', paletteCheck(p).length ? '✗' : '✓'),
          h('button.btn.small', { title: 'Save to this company', on: { click: async (e) => { e.stopPropagation(); const f = `workspaces/${ws.id}/designs/palettes.json`; let cur = []; try { cur = await engine.readFile(f, 'json'); } catch {} cur = cur.filter((x) => x.id !== p.id).concat([{ ...p, saved: new Date().toISOString() }]); await engine.writeFile(f, cur); toast('Saved to ' + ws.name, 'ok'); } } }, '★')));
      }
    },
    async icons(el) {
      await loadIcons();
      const P = S.palettes[0] || { ink: '#111111', acc: '#C6395F', onAcc: '#FFFFFF' };
      const chosen = S.spec ? iconsFor(S.spec, 6) : [];
      const q = h('input', { type: 'search', placeholder: 'Search 1,539 icons (e.g. visa, gift, truck)', 'aria-label': 'Search icons' });
      const box = h('div.dz-icons#dz-icons');
      const paint = async (names) => { clear(box); for (const n of names.slice(0, 36)) for (const style of ['line', 'duotone', 'badge']) { const im = (await iconImage(n, style, { fg: style === 'badge' ? P.onAcc : P.ink, bg: P.acc, accent: P.acc })).cloneNode(); im.title = `${n} · ${style}`; im.alt = n; box.appendChild(im); if (names !== chosen) break; } };
      q.addEventListener('input', () => paint(q.value ? searchIcons(q.value) : chosen));
      ap(el, h('p.dz-small', 'Chosen by meaning from your brief (Lucide, ISC licence), in line / duotone / badge styles and your colours.'), q, box);
      paint(chosen);
    },
    art(el) {
      const P = S.palettes[0] || schemesFor(S.spec || { kind: 'brand', languages: ['en'], industry: 'beauty' }, ws, cat)[0];
      const box = h('div.dz-arts#dz-arts');
      for (const k of ART_KINDS) { const c = h('canvas', { width: 160, height: 200, title: k }); drawArt(c.getContext('2d'), 160, 200, { base: 'linear', seed: 7, layers: [{ k }] }, P); box.appendChild(c); }
      ap(el, h('p.dz-small', 'Procedural artwork — instant, always in your colours, never text. Every design picks layers that suit the brief; in the editor you can change them.'), box,
        h('button.btn.small', { on: { click: () => { S.seed++; run({ keepSpec: true }); } } }, '↻ New artwork for all designs'));
    },
  };

  paintSaved();
  paintVisuals();
  if (auto && briefIn.value.trim()) run();
  return { run, state: S };
}
