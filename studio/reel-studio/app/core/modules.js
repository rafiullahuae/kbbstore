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
// Optional, generic (round 2):
//     tab: '✨ Create',                  // the module's panel also lives as a TAB in the editor's right column
//                                       // (mounted once on first open, kept alive; panel may return {destroy})
//     home: (el, home) => { ... },      // a card on the home screen; home = { workspace, open(project, files?), rerender() }
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

/** module entry files the editor tries to import at start (missing files are fine) */
export const MODULE_ENTRIES = ['../captions/index.js', '../auto/index.js', '../content/index.js'];
