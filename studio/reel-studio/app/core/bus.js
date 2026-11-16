// KBB Studio — core/bus.js (CONTRACT, owned by R1)
// A tiny synchronous event bus shared by the editor and every module.
//
//   import { bus } from '../core/bus.js';
//   const off = bus.on('project:change', e => ...);   off() to stop listening
//   bus.emit('project:change', { cmd, kind });
//
// Events the editor emits (modules may listen; payloads are plain objects):
//   'project:load'    { project }                 a project was opened or created
//   'project:change'  { cmd, kind }               after apply/undo/redo (kind: 'apply'|'undo'|'redo')
//   'selection'       { ids }                     selected item ids changed
//   'playhead'        { t }                       playhead moved (throttled to animation frames)
//   'play' / 'pause'  { t }
//   'workspace'       { workspace }               the active company changed
//   'media:added'     { media }                   a media entry was imported
//   'engine:progress' { id, done, total, state }  a download from the engine progressed
//   'modules:changed' { }                         a module registered (core/modules.js)
//   'toast'           { text, kind }              ask the editor to show a short message

export function createBus() {
  const map = new Map();
  return {
    on(name, fn) {
      if (!map.has(name)) map.set(name, new Set());
      map.get(name).add(fn);
      return () => map.get(name)?.delete(fn);
    },
    once(name, fn) {
      const off = this.on(name, (e) => { off(); fn(e); });
      return off;
    },
    emit(name, payload) {
      const set = map.get(name);
      if (!set) return;
      for (const fn of [...set]) {
        try { fn(payload); } catch (err) { console.error('[bus] listener for', name, 'threw', err); }
      }
    },
  };
}

export const bus = createBus();
