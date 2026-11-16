// KBB Studio · themes · the "✨ Themes" tab: suggested themes for this reel, the gallery (hover = a live preview on the
// sample clip), one click to apply (one undo step), then every element tunable (on/off, density, speed, colour).
import { h, clear, slider, seg, toast } from '../editor/ui.js';
import { THEMES, GROUPS, themeById } from './themes.js';
import { suggestThemes, countryOf } from './suggest.js';
import { applyTheme, tuneTheme, tuneLayer, removeTheme, resyncBeats, overlayOf, themeItems } from './apply.js';
import { hoverPreview, themeSpec, hoverToggles, css as hoverCss, drawMini } from './hover.js';
import { recipeById } from '../auto/recipes/recipes.js';

let cssDone = false;
function css() {
  if (cssDone) return; cssDone = true; hoverCss();
  const s = document.createElement('style');
  s.textContent = `
.th { padding: 0 2px 24px; display: flex; flex-direction: column; gap: 10px; }
.th h4 { margin: 4px 0 2px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.th-note { font-size: 12px; color: var(--muted); margin: 0; }
.th-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(86px, 1fr)); gap: 7px; }
.th-tile { position: relative; border: 2px solid transparent; background: var(--panel2, #1b1b22); border-radius: 10px; padding: 0 0 4px; font-size: 11px; color: var(--text, #eee); cursor: pointer; overflow: hidden; text-align: center; line-height: 1.2; }
.th-tile canvas { display: block; width: 100%; aspect-ratio: 9 / 16; height: auto; background: #111; }
.th-tile span { display: block; padding: 4px 3px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.th-tile .th-ic { position: absolute; top: 4px; left: 5px; font-size: 13px; text-shadow: 0 1px 3px rgba(0,0,0,.6); }
.th-tile.on { border-color: var(--ws, #e8508b); }
.th-tile:focus-visible { outline: 2px solid var(--ws, #e8508b); }
.th-sugg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; }
.th-why { font-size: 10.5px; color: var(--muted); padding: 2px 4px 0; white-space: normal; }
.th-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.th-chips .chip { cursor: pointer; font-size: 11.5px; }
.th-card { border: 1px solid rgba(255,255,255,.1); border-radius: 10px; padding: 8px 10px; display: flex; flex-direction: column; gap: 7px; background: rgba(255,255,255,.03); }
.th-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 10px; font-size: 12px; }
.th-opts label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
.th-el { display: grid; grid-template-columns: auto 1fr auto; gap: 2px 8px; align-items: center; font-size: 12px; padding: 5px 0; border-top: 1px solid rgba(255,255,255,.06); }
.th-el .row.slider { grid-column: 1 / -1; display: grid; grid-template-columns: 62px 1fr 34px; gap: 6px; font-size: 11px; }
.th-el input[type=color] { width: 26px; height: 22px; padding: 0; border: 0; background: none; }
.th-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.th input.th-search { width: 100%; }`;
  document.head.appendChild(s);
}

const OPT_KEY = 'kbbstudio.themes.opts';
const loadOpts = () => { try { return Object.assign({ intensity: 'normal', colorMode: 'theme', beat: true, safe: true, sting: true, outro: true, transitions: true, title: true, sfx: true }, JSON.parse(localStorage.getItem(OPT_KEY) || '{}')); } catch { return { intensity: 'normal', colorMode: 'theme', beat: true, safe: true, sting: true, outro: true, transitions: true, title: true, sfx: true }; } };
const saveOpts = (o) => { try { localStorage.setItem(OPT_KEY, JSON.stringify(o)); } catch {} };

/** text the suggestions read: the transcript */
const transcript = (p) => (p.captions?.words || []).map((w) => w.w).join(' ');

export function mountPanel(el, ctx) {
  css();
  const root = h('div.th'); el.appendChild(root);
  const opts = loadOpts();
  let group = 'All', query = '', applying = false;
  const offs = [];
  const thumbIO = new IntersectionObserver((ents) => { for (const e of ents) if (e.isIntersecting) { thumbIO.unobserve(e.target); e.target.__thumb?.(); } }, { root: null, rootMargin: '120px' });

  /** a gallery tile: static frame when idle, the live loop while hovered / focused */
  function tile(th, why) {
    const cv = h('canvas', { width: 108, height: 192 });
    const spec = themeSpec(th.id, { canvas: cv, opts: { colorMode: opts.colorMode, intensity: opts.intensity, sting: false }, ws: ctx.workspace, onStop: () => still() });
    const still = () => { const d = () => { try { spec.draw(cv.getContext('2d'), cv.width, cv.height, 1.7); } catch {} }; d(); spec.prepare().then(d); };
    const b = h('button.th-tile', { type: 'button', title: `${th.name}${why ? '\n' + why : ''}\nHover to preview · click to apply`, dataset: { theme: th.id }, on: { click: () => apply(th.id) } },
      h('span.th-ic', th.icon || '✨'), cv, h('span', th.name), why ? h('div.th-why', why) : null);
    b.__thumb = still; thumbIO.observe(b);
    hoverPreview(b, spec);
    return b;
  }

  async function apply(id) {
    if (applying) return; applying = true;
    const th = themeById(id);
    try {
      ctx.progress?.('Theme · ' + th.name, null);
      const r = await applyTheme(ctx, id, opts);
      toast(`Theme: ${th.name} — runs through the whole reel${r.sting ? ', intro' : ''}${r.outro ? ', outro' : ''}${r.transitions ? `, ${r.transitions} transition${r.transitions > 1 ? 's' : ''}` : ''}${r.sfx ? `, ${r.sfx} sound${r.sfx > 1 ? 's' : ''}` : ''}${r.faces ? ' · kept off faces' : ''}${r.beats ? ` · pulses on ${r.beats} beats` : ''}. ⌘Z takes it back.`, 'ok');
    } catch (e) { toast(`Theme: ${e.message || e}`, 'error'); console.warn('[themes]', e); }
    finally { applying = false; ctx.progress?.(null); render(); }
  }

  const optBox = h('div.th-card');
  function renderOpts() {
    const on = overlayOf(ctx.project);
    const live = (patch, label) => { Object.assign(opts, patch); saveOpts(opts); if (on) { try { tuneTheme(ctx, patch, label); } catch (e) { toast(e.message, 'error'); } } };
    const chk = (k, label, title, liveKey) => h('label', { title }, h('input', { type: 'checkbox', checked: opts[k] !== false, dataset: { opt: k }, on: { change: (e) => { opts[k] = e.target.checked; saveOpts(opts); if (liveKey && on) live({ [liveKey]: e.target.checked }, label); } } }), label);
    clear(optBox).append(
      h('div.th-btns', h('b', 'Intensity'), seg([['subtle', 'Subtle'], ['normal', 'Normal'], ['bold', 'Bold']], opts.intensity, (v) => live({ intensity: v }, 'Theme intensity'), '.th-int')),
      h('div.th-btns', h('b', 'Colours'), seg([['theme', 'Theme'], ['brand', 'Brand kit']], opts.colorMode, (v) => live({ colorMode: v }, 'Theme colours'), '.th-col')),
      h('div.th-opts',
        chk('beat', '🥁 Pulse on the beat', 'Elements pulse on the music beats (add music first)', 'beat'),
        chk('safe', '🙂 Keep off faces & captions', 'Never draws over a face or a caption', 'safe'),
        chk('sting', '🎬 Intro sting', 'A themed 1-second opener'),
        chk('outro', '🏁 Outro', 'A themed close for the last second'),
        chk('transitions', '🔀 Transitions on cuts', 'The theme’s transition style on every cut'),
        chk('title', '🔤 Themed title', 'A title in the theme’s text animation'),
        chk('sfx', '🔊 Theme sounds', 'Intro, outro, transition and accent sounds')),
      on ? null : h('p.th-note', 'Options apply when you pick a theme. Intensity, colours, beat and face-safe also change a theme already on the reel.'));
  }

  const appliedBox = h('div');
  function renderApplied() {
    clear(appliedBox);
    const it = overlayOf(ctx.project); if (!it) return;
    const th = themeById(it.style.theme), L = it.style.layers || {};
    const rows = (it.style.defs || []).map((d) => {
      const o = L[d.id] || {};
      const on = h('input', { type: 'checkbox', checked: o.on !== false, title: 'Show this element', dataset: { layer: d.id }, on: { change: (e) => tuneLayer(ctx, d.id, { on: e.target.checked }, (e.target.checked ? 'Show ' : 'Hide ') + d.label) } });
      const colVal = /^#[0-9a-f]{6}$/i.test(o.color || '') ? o.color : '#ffffff';
      const col = h('input', { type: 'color', value: colVal, title: 'Colour of this element' }); col.addEventListener('input', () => tuneLayer(ctx, d.id, { color: col.value }, 'Element colour'));
      return h('div.th-el', { dataset: { el: d.id } }, on, h('span', d.label), col,
        slider({ label: 'Density', min: 0.2, max: 2, step: 0.05, value: o.density ?? 1, fmt: (v) => v.toFixed(1) + '×', onInput: (v) => tuneLayer(ctx, d.id, { density: v }, 'Element density') }),
        slider({ label: 'Speed', min: 0.2, max: 2.5, step: 0.05, value: o.speed ?? 1, fmt: (v) => v.toFixed(1) + '×', onInput: (v) => tuneLayer(ctx, d.id, { speed: v }, 'Element speed') }));
    });
    const n = themeItems(ctx.project).length;
    appliedBox.append(h('div.th-card', { dataset: { applied: it.style.theme } },
      h('b', `${th?.icon || '✨'} ${th?.name || it.style.theme} is on this reel`),
      h('p.th-note', `${n} item${n > 1 ? 's' : ''} · ${it.style.faces?.length ? `kept off ${it.style.faces.length} face samples` : 'no faces found'} · ${it.style.beats?.length ? `${it.style.beats.length} beats` : 'no music beats yet'}`),
      h('h4', 'Elements'), ...rows,
      h('div.th-btns',
        h('button.btn.small', { on: { click: async () => { try { const n2 = await resyncBeats(ctx); toast(n2 ? `Pulses re-synced to ${n2} beats` : 'Add music first, then re-sync', n2 ? 'ok' : 'info'); } catch (e) { toast(e.message, 'error'); } } } }, '🥁 Re-sync beats'),
        h('button.btn.small', { on: { click: () => { const k = removeTheme(ctx); toast(`Theme removed (${k} items) · ⌘Z brings it back`, 'ok'); render(); } } }, '✕ Remove theme'))));
  }

  const suggBox = h('div');
  function renderSuggest() {
    const recipe = recipeById(ctx.project.recipe), text = transcript(ctx.project);
    const s = suggestThemes({ text, recipe, workspace: ctx.workspace, country: countryOf({ text, recipe }), limit: 3 });
    clear(suggBox).append(h('h4', 'Suggested for this reel'), h('div.th-sugg', s.map((x) => tile(themeById(x.id), x.why[0]))));
  }

  const grid = h('div.th-grid');
  const search = h('input.th-search', { type: 'search', placeholder: 'Search themes: Germany, sakura, sale, Ramadan…', 'aria-label': 'Search themes' });
  search.addEventListener('input', () => { query = search.value.trim().toLowerCase(); renderGrid(); });
  search.addEventListener('keydown', (e) => e.stopPropagation());
  const chips = h('div.th-chips', ['All', ...GROUPS].map((g) => h('button.chip' + (g === group ? '.on' : ''), { dataset: { group: g }, on: { click: (e) => { group = g; for (const c of chips.children) c.classList.toggle('on', c === e.currentTarget); renderGrid(); } } }, g)));
  function renderGrid() {
    const cur = overlayOf(ctx.project)?.style.theme;
    const list = THEMES.filter((t) => (group === 'All' || t.group === group) && (!query || `${t.name} ${t.nameAr || ''} ${t.group} ${(t.keywords?.en || []).join(' ')}`.toLowerCase().includes(query)));
    clear(grid).append(...list.map((t) => { const b = tile(t); b.classList.toggle('on', t.id === cur); return b; }));
    if (!list.length) grid.append(h('p.th-note', 'No theme matches.'));
  }

  function render() { renderOpts(); renderApplied(); renderSuggest(); renderGrid(); }
  root.append(
    h('div.insp-head', h('b', '✨ Themes'), h('p.th-note', `${THEMES.length} animated themes that run through the whole video — over the picture, never over faces or captions. Hover a tile to preview it, click to apply (⌘Z undoes).`)),
    hoverToggles({ mine: true }), suggBox, appliedBox, optBox, h('h4', 'All themes'), search, chips, grid);
  render();
  let pend = 0, lastSig = '';
  const sig = () => { const it = overlayOf(ctx.project); return [it?.id, JSON.stringify(it?.style.layers || {}), it?.style.intensity, it?.style.colorMode, ctx.project.recipe, ctx.project.captions?.words?.length].join('|'); };
  offs.push(ctx.bus.on('project:change', () => { if (pend) return; pend = requestAnimationFrame(() => { pend = 0; const s2 = sig(); if (s2 !== lastSig) { lastSig = s2; if (!root.contains(document.activeElement) || document.activeElement?.type !== 'range') render(); } }); }));
  lastSig = sig();
  return { destroy() { for (const off of offs) off?.(); thumbIO.disconnect(); } };
}
export { drawMini };
