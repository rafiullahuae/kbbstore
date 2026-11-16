// KBB Studio — core/modules.js (CONTRACT, owned by R1)
//
// The "Do it for me" bar and any other one-click feature are slots that modules fill:
//
//   import { registerModule } from '../core/modules.js';
//   registerModule({
//     id: 'auto-captions',              // one of SLOTS below, or a new id (shown under "More")
//     label: 'Auto captions',
//     icon: '💬',                        // optional
//     run: async (ctx) => { ... },       // ctx = EditorContext (below); return nothing or a short message
//     panel: (el, ctx) => { ... },      // optional: render a settings/review panel into el (right-hand drawer)
//     tab: { label: '🎨 Look', order }, // optional: the panel ALSO lives as a tab of the editor's right column
//                                        (mounted once on first open, kept alive; panel may return {destroy()})
//   });
//
// EditorContext (what a module gets):
//   ctx.project            the live project (READ ONLY — change it through ctx.history.apply(ops...))
//   ctx.history, ctx.ops   core/history.js
//   ctx.media              core/media.js MediaStore
//   ctx.engine             core/engine.js client (askPermission, fetchModel, files)
//   ctx.bus                core/bus.js
//   ctx.workspace          the active company (Workspace JSON, see core/project.js)
//   ctx.selection()        selected item ids
//   ctx.playhead()         current time (s);  ctx.seek(t)
//   ctx.toast(text, kind)  short message;     ctx.progress(label, 0..1 | null) a progress line in the bar
//   ctx.openPanel(id)      open a module's panel
//
// Optional extras a module may use (generic hooks; the editor knows nothing about the modules that use them):
//   tab: { label, icon }   with panel(): the panel gets its OWN tab in the editor's right column (mounted once, kept
//                          alive) instead of a pop-up; panel may return { destroy() }.
//   registerTimelineStrip({ id, label, draw(el, { project, pps, width }) })   a thin strip under the timeline ruler
//                          (e.g. a heat map); draw() fills `el` (width px wide, t * pps = x) on every timeline render.
//   registerExportCheck({ id, run(project, ctx) -> [{ level:'warn'|'error', text, fix?(), fixLabel? }] })   problems the
//                          Export dialog lists before exporting (with a Fix button when fix() is given).
//   tab may also be a plain string label, e.g. tab: '✨ Create'.
//   home: (el, home) => { ... }   a card on the home screen; home = { workspace, open(project, files?), rerender() }
//
// Modules are loaded by the editor from app/captions/index.js and app/auto/index.js when those files exist; each
// file imports registerModule and registers itself. Until a slot is registered the editor shows
// "coming in the next update".

import { bus } from './bus.js';

export const SLOTS = [
  { id: 'auto-captions', label: 'Auto captions', icon: '💬' },
  { id: 'auto-reel', label: 'Auto reel', icon: '✨' },
  { id: 'remove-silences', label: 'Remove silences', icon: '✂️' },
  { id: 'add-music', label: 'Add music', icon: '🎵' },
  { id: 'suggest-style', label: 'Suggest style', icon: '🎨' },
];

const registry = new Map();

export function registerModule(mod) {
  if (!mod || !mod.id || typeof mod.run !== 'function' && typeof mod.panel !== 'function') throw new Error('registerModule: needs id and run() or panel()');
  registry.set(mod.id, mod);
  bus.emit('modules:changed', { id: mod.id });
}
export const getModule = (id) => registry.get(id) || null;
export const listModules = () => [...registry.values()];

const strips = new Map();
export function registerTimelineStrip(strip) { strips.set(strip.id, strip); bus.emit('modules:changed', { id: strip.id }); }
export const listTimelineStrips = () => [...strips.values()];
const exportChecks = new Map();
export function registerExportCheck(check) { exportChecks.set(check.id, check); }
/** every registered export check's findings (a check that throws is skipped, never blocks the export) */
export async function runExportChecks(project, ctx) {
  const out = [];
  for (const c of exportChecks.values()) { try { for (const f of (await c.run(project, ctx)) || []) out.push({ ...f, check: c.id }); } catch (e) { console.warn('[export-check]', c.id, e); } }
  return out;
}

// On-canvas grabs: a module may claim a pointer-down on the preview before the editor's own select/move, e.g. to
// drag ONE caption line. grab(project, t, p {x,y canvas px}, ev, { apply(cmd) }) -> null (not mine) or
// { move(dx, dy, ev), end(ev) }. The editor seals the undo step when the pointer goes up.
const grabs = new Map();
export function registerCanvasGrab(g) { grabs.set(g.id, g); }
export function canvasGrab(project, t, p, ev, api) {
  for (const g of grabs.values()) { try { const r = g.grab(project, t, p, ev, api); if (r) return r; } catch (e) { console.warn('[canvas-grab]', g.id, e); } }
  return null;
}

// Single-key shortcuts a module adds (no modifier; keys the editor itself does not use, e.g. I / O to mark a range):
// registerKey({ key: 'i', label: 'Mark in', run(ctx) }). Case-insensitive; listed in the editor's shortcut sheet.
const keys = new Map();
export function registerKey(k) { keys.set(String(k.key).toLowerCase(), k); }
export const keyBinding = (key) => keys.get(String(key).toLowerCase()) || null;
export const listKeys = () => [...keys.values()];

/** module entry files the editor tries to import at start (missing files are fine) */
export const MODULE_ENTRIES = ['../captions/index.js', '../auto/index.js', '../look/index.js', '../audio/index.js', '../viral/index.js', '../content/index.js', '../stock/index.js', '../designs/index.js', '../sync/index.js', '../cutaways/index.js'];
