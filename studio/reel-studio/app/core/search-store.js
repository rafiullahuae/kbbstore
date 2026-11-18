// KBB Studio — core/search-store.js: the one index the search box AND the hover help read (so the two never disagree).
//   entries = the curated catalogue (core/search-index.js)
//           + the saved crawl of every screen and tab (core/search-crawl-data.js, written by tools/search-check.cjs --write)
//           + a live crawl of whatever is on screen now (new companies, a panel added since the last --write).
// A crawled control the catalogue already describes (same place and words, or its `sel`) is not listed twice; its
// hover card uses the catalogue's words.

import { CATALOGUE } from './search-index.js';
import { createIndex, normalize } from './search.js';
import { crawl, keyOf, recordOf, splitIcon } from './search-crawl.js';

const KIND = { button: 'Actions', tab: 'Panels', section: 'Panels', slider: 'Settings', switch: 'Settings', choice: 'Settings', field: 'Settings' };
const PHRASE = { button: 'Button', tab: 'Tab', section: 'Section', slider: 'Slider', switch: 'Switch', choice: 'Choice', field: 'Field' };
const NEED_OF_INSP = (h, path) => (!h ? undefined : h === 'Project' ? 'none' : /clip|Picture/.test(h) ? 'clip' : h === 'Text' ? 'text' : /items selected/.test(h) ? 'multi' : /Theme element/.test(h) && path.some((p) => /Capsules/.test(p)) ? 'capsules' : undefined);
const MODAL_ID = (name) => (/^Export/.test(name || '') ? 'export' : /Keyboard shortcuts/.test(name || '') ? 'shortcuts' : /company/i.test(name || '') ? 'company' : null);
export const NEEDS = { clip: 'Needs a selected clip (the one under the playhead is picked for you).', text: 'Needs a selected text item.', capsules: 'Needs a capsules carousel on the reel.', multi: 'Needs two or more selected items.', none: 'Works on the whole reel (nothing selected).' };

let snapshot = null, snapTried = false, store = null;

async function loadSnapshot() {
  if (snapTried) return snapshot; snapTried = true;
  try { snapshot = (await import('./search-crawl-data.js')).default; } catch { snapshot = null; }
  return snapshot;
}

/** a catalogue entry for a place (side|bin|cfg + first section), for crawled controls that sit in it */
function sectionInfo() {
  const m = new Map();
  for (const c of CATALOGUE) {
    const g = c.go || {}; const place = g.side || g.bin || g.cfg || g.region || g.screen || '';
    const k = place + '|' + (g.sec?.[0] ? normalize(g.sec[0]) : '');
    if (!m.has(k)) m.set(k, c);
  }
  return m;
}
const SECS = sectionInfo();
/** catalogue entries that name one control by its words, by place */
const CLAIM = new Map();
for (const c of CATALOGUE) {
  const g = c.go || {}, scr = g.screen || 'editor', place = g.side || g.bin || g.cfg || '';
  const claim = (pl, label) => { const k = [scr, pl, normalize(splitIcon(label).text)].join('|'); if (!CLAIM.has(k)) CLAIM.set(k, c); };
  if (g.label) claim(place, g.label);
  for (const a of c.also || []) claim(place, a);
  if (g.sec?.length) claim(place, g.sec[g.sec.length - 1]);          // the section's own header
  if (g.bin && !g.label && !g.sel) claim('', g.bin);                   // a left tab itself
}
const claimOf = (r) => CLAIM.get([r.ctx.screen, r.ctx.side || r.ctx.bin || r.ctx.cfg || '', normalize(r.label)].join('|')) || null;

/** a crawl record → a search entry */
export function entryOf(r) {
  const place = r.ctx.side || r.ctx.bin || r.ctx.cfg || (r.path[0] === 'Timeline' ? 'section.timeline' : r.path[0] === 'Preview' ? '.monitor' : r.ctx.screen);
  const sec = SECS.get(place + '|' + (r.ctx.sec?.[0] ? normalize(r.ctx.sec[0]) : '')) || SECS.get(place + '|');
  const where = r.path.join(' → ');
  const title = r.label + (r.sub ? ' — ' + r.sub : '');
  const tip = r.title && normalize(r.title) !== normalize(r.label) ? r.title : '';
  const go = { screen: r.ctx.screen, label: r.label, subLabel: r.sub };
  if (r.ctx.side) go.side = r.ctx.side; if (r.ctx.bin) go.bin = r.ctx.bin; if (r.ctx.cfg) go.cfg = r.ctx.cfg; if (r.ctx.dz) go.dz = r.ctx.dz;
  if (r.ctx.sec) go.sec = r.ctx.sec; if (r.ctx.sub) go.sub = r.ctx.sub;
  if (r.ctx.modal) go.modal = MODAL_ID(r.ctx.modal) || undefined;
  if (r.ctx.side === 'inspector') go.need = NEED_OF_INSP(r.ctx.insp, r.path);
  if (!r.ctx.side && !r.ctx.bin && !r.ctx.cfg && !r.ctx.modal) { if (r.path[0] === 'Timeline') go.region = 'section.timeline'; else if (r.path[0] === 'Preview') go.region = '.monitor'; else if (r.path[0] === 'Top bar') go.region = '.topbar'; else if (r.path[0] === 'Do it for me') go.region = '.doit-row'; }
  const desc = tip || (sec ? `${PHRASE[r.kind] || 'Control'} in ${sec.title}: ${sec.desc}` : `${PHRASE[r.kind] || 'Control'} “${r.label}” in ${where || 'this screen'}.`);
  return { id: 'k:' + r.key, title, icon: r.icon, where, desc, more: tip && sec ? `Part of ${sec.title}: ${sec.desc}` : '', group: KIND[r.kind] || 'Actions', go, kind: r.kind, key: undefined, crawl: true, ws: r.ws };
}

function rowsOf(snap) {
  if (!snap) return [];
  return snap.rows.map(([label, icon, title, kind, pi, ci, sub, cat, ws]) => {
    const ctx = snap.ctxs[ci], path = snap.paths[pi];
    const r = { label, icon, title, kind, path, ctx, sub: sub || undefined, cat, ws };
    r.key = keyOf(r);
    return r;
  });
}

function currentWs() { try { return localStorage.getItem('kbbstudio.workspace') || ''; } catch { return ''; } }

/** build (or rebuild with what is on screen now) and return { index, byKey, live } */
export async function getStore({ live = true } = {}) {
  const snap = await loadSnapshot();
  const recs = new Map();
  if (!store) {
    for (const r of rowsOf(snap)) recs.set(r.key, r);
  } else for (const [k, r] of store.recs) recs.set(k, r);
  let added = 0;
  const liveEls = new Map();
  if (live) {
    for (const r of crawl()) {
      liveEls.set(r.el, r);
      if (!recs.has(r.key)) { const { el, ...rest } = r; const c = catalogueFor(el, r); if (c) rest.cat = CATALOGUE.indexOf(c); recs.set(r.key, rest); added++; }
    }
  }
  if (store && !added) { store.liveEls = liveEls; return store; }
  const ws = currentWs();
  const entries = CATALOGUE.slice();
  const byKey = new Map();
  for (const [k, r] of recs) {
    const c = r.cat != null ? CATALOGUE[r.cat] : claimOf(r);
    if (c) { byKey.set(k, c); continue; }
    if (r.ws && ws && r.ws !== ws) continue;   // a control only one company shows
    const e = entryOf(r); byKey.set(k, e); entries.push(e);
  }
  store = { index: createIndex(entries), entries, byKey, recs, liveEls };
  return store;
}
export const peekStore = () => store;

/** the entry for one control on screen (hover help): its catalogue entry by `sel`, else by place and words */
export function entryForElement(el) {
  if (!store) return null;
  for (const c of CATALOGUE) { const s = c.go?.sel; if (s && el.matches(s)) return c; }
  const r = recordOf(el);
  if (!r) return null;
  return store.byKey.get(r.key) || claimOf(r) || entryOf(r);
}

/** for tools/search-check.cjs: the keys the saved crawl knows, and whether the catalogue describes a control */
export async function snapshotKeys() { return new Set(rowsOf(await loadSnapshot()).map((r) => r.key)); }
export function catalogueFor(el, rec = recordOf(el)) {
  for (const c of CATALOGUE) { const s = c.go?.sel; if (s && el.matches(s)) return c; }
  return rec ? claimOf(rec) : null;
}
