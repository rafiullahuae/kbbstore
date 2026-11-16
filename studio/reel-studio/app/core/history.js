// KBB Studio — core/history.js (CONTRACT, owned by R1)
//
// Every change to a project is a command applied through a History:
//
//   import { ops } from '../core/history.js';
//   editor.history.apply(ops.setItem(id, { x: 40 }));            // undoable
//   editor.history.apply(ops.batch('Auto captions', [ ops.setCaptions(c), ops.addItems([...]) ]));
//   editor.history.apply(ops.mutate('Remove silences', p => { ...edit p in place... }));   // snapshot fallback
//
// A command is { label, key?, do(project), undo(project) }. do/undo mutate the project in place and must be exact
// inverses. Commands carrying the same `key` within COALESCE_MS merge into one undo step (dragging, typing, sliders):
// the merged step keeps the FIRST undo and the LAST do, so do/undo must set absolute values (the ops below do).

import { clone, findItem, findTrack } from './project.js';
import { bus as defaultBus } from './bus.js';

export const COALESCE_MS = 700;

export class History {
  /** @param {() => object} getProject  @param {{limit?:number, bus?:object}} opts */
  constructor(getProject, { limit = 400, bus = defaultBus } = {}) {
    this.get = getProject;
    this.limit = limit;
    this.bus = bus;
    this.undoStack = [];
    this.redoStack = [];
    this.lastAt = 0;
    this.version = 0;        // bumps on every change (autosave watches this)
    this.txn = null;
  }

  apply(cmd) {
    if (!cmd) return;
    const p = this.get();
    cmd.do(p);
    if (this.txn) { this.txn.push(cmd); return this._changed(cmd, 'apply', false); }
    const now = performance.now();
    const top = this.undoStack[this.undoStack.length - 1];
    if (cmd.key && top && top.key === cmd.key && now - this.lastAt < COALESCE_MS) {
      this.undoStack[this.undoStack.length - 1] = { label: cmd.label, key: cmd.key, do: cmd.do, undo: top.undo };
    } else {
      this.undoStack.push(cmd);
      if (this.undoStack.length > this.limit) this.undoStack.shift();
    }
    this.lastAt = now;
    this.redoStack.length = 0;
    this._changed(cmd, 'apply');
  }

  /** group several applies into ONE undo step: begin('Label') ... end() */
  begin(label) { if (!this.txn) { this.txn = []; this.txn.label = label; } }
  end() {
    const t = this.txn; this.txn = null;
    if (!t || !t.length) return;
    const cmd = composite(t.label || t[0].label, t);
    this.undoStack.push(cmd); this.redoStack.length = 0; this.lastAt = 0;
    this._changed(cmd, 'apply');
  }

  undo() {
    const cmd = this.undoStack.pop(); if (!cmd) return false;
    cmd.undo(this.get()); this.redoStack.push(cmd); this.lastAt = 0;
    this._changed(cmd, 'undo'); return true;
  }
  redo() {
    const cmd = this.redoStack.pop(); if (!cmd) return false;
    cmd.do(this.get()); this.undoStack.push(cmd); this.lastAt = 0;
    this._changed(cmd, 'redo'); return true;
  }
  /** stop the current step from coalescing with the next one (e.g. on pointerup) */
  seal() { this.lastAt = 0; }
  get canUndo() { return this.undoStack.length > 0; }
  get canRedo() { return this.redoStack.length > 0; }
  get undoLabel() { return this.undoStack[this.undoStack.length - 1]?.label || ''; }
  get redoLabel() { return this.redoStack[this.redoStack.length - 1]?.label || ''; }
  clear() { this.undoStack.length = 0; this.redoStack.length = 0; this._changed(null, 'clear'); }

  _changed(cmd, kind, emit = true) {
    this.version++;
    const p = this.get(); if (p) p.modified = new Date().toISOString();
    if (emit) this.bus.emit('project:change', { cmd, kind });
  }
}

function composite(label, cmds) {
  const list = cmds.slice();
  return {
    label,
    do(p) { for (const c of list) c.do(p); },
    undo(p) { for (let i = list.length - 1; i >= 0; i--) list[i].undo(p); },
  };
}

// ── command factories ──
// Each returns a command. Values are cloned on the way in, so callers may keep using their objects.

const req = (hit, what) => { if (!hit) throw new Error('not found: ' + what); return hit; };

export const ops = {
  /** set fields on one item: ops.setItem(id, {x, y}) — coalesces per item+fields */
  setItem(id, props, label = 'Edit') {
    return ops.setItems([{ id, props }], label);
  },

  /** set fields on many items at once: [{id, props}] */
  setItems(list, label = 'Edit') {
    const next = list.map((e) => ({ id: e.id, props: clone(e.props) }));
    let prev = null;
    const key = 'set:' + next.map((e) => e.id + '(' + Object.keys(e.props).sort().join(',') + ')').join('|');
    return {
      label, key,
      do(p) {
        const before = [];
        for (const e of next) {
          const it = req(findItem(p, e.id), e.id).item;
          const old = {};
          for (const k of Object.keys(e.props)) { old[k] = clone(it[k]); it[k] = clone(e.props[k]); }
          before.push({ id: e.id, props: old });
        }
        if (!prev) prev = before;   // keep the very first "before" (redo must not overwrite it)
      },
      undo(p) {
        for (const e of prev) {
          const it = findItem(p, e.id)?.item; if (!it) continue;
          for (const k of Object.keys(e.props)) { if (e.props[k] === undefined) delete it[k]; else it[k] = clone(e.props[k]); }
        }
      },
    };
  },

  /** add items: [{trackId, item, index?}] */
  addItems(list, label = 'Add') {
    const entries = list.map((e) => ({ trackId: e.trackId, item: clone(e.item), index: e.index }));
    return {
      label,
      do(p) {
        for (const e of entries) {
          const tr = req(findTrack(p, e.trackId), e.trackId);
          const i = e.index == null ? tr.items.length : Math.min(e.index, tr.items.length);
          tr.items.splice(i, 0, clone(e.item));
        }
      },
      undo(p) {
        const ids = new Set(entries.map((e) => e.item.id));
        for (const tr of p.tracks) tr.items = tr.items.filter((i) => !ids.has(i.id));
      },
    };
  },

  /** remove items by id */
  removeItems(ids, label = 'Delete') {
    let removed = null;
    return {
      label,
      do(p) {
        const set = new Set(ids); const got = [];
        for (const tr of p.tracks) {
          tr.items.forEach((it, index) => { if (set.has(it.id)) got.push({ trackId: tr.id, index, item: clone(it) }); });
          tr.items = tr.items.filter((it) => !set.has(it.id));
        }
        removed = got;
      },
      undo(p) {
        for (const e of removed) { const tr = findTrack(p, e.trackId); if (tr) tr.items.splice(Math.min(e.index, tr.items.length), 0, clone(e.item)); }
      },
    };
  },

  /** move items to (trackId, t0): [{id, trackId?, t0}] — coalesces while dragging */
  moveItems(list, label = 'Move') {
    const next = list.map((e) => ({ ...e }));
    let prev = null;
    return {
      label, key: 'move:' + next.map((e) => e.id).join(','),
      do(p) {
        const before = [];
        for (const e of next) {
          const hit = req(findItem(p, e.id), e.id);
          before.push({ id: e.id, trackId: hit.track.id, index: hit.index, t0: hit.item.t0 });
          hit.item.t0 = e.t0;
          if (e.trackId && e.trackId !== hit.track.id) {
            hit.track.items.splice(hit.index, 1);
            req(findTrack(p, e.trackId), e.trackId).items.push(hit.item);
          }
        }
        if (!prev) prev = before;
      },
      undo(p) {
        for (const b of prev.slice().reverse()) {
          const hit = findItem(p, b.id); if (!hit) continue;
          hit.item.t0 = b.t0;
          if (hit.track.id !== b.trackId) {
            hit.track.items.splice(hit.index, 1);
            const tr = findTrack(p, b.trackId); tr.items.splice(Math.min(b.index, tr.items.length), 0, hit.item);
          }
        }
      },
    };
  },

  addMedia(media, label = 'Import') {
    const m = clone(media);
    return {
      label,
      do(p) { if (!p.media.some((x) => x.id === m.id)) p.media.push(clone(m)); },
      undo(p) { p.media = p.media.filter((x) => x.id !== m.id); },
    };
  },
  setMedia(id, props, label = 'Media') {
    let prev = null; const next = clone(props);
    return {
      label,
      do(p) { const m = req(p.media.find((x) => x.id === id), id); if (!prev) prev = Object.fromEntries(Object.keys(next).map((k) => [k, clone(m[k])])); Object.assign(m, clone(next)); },
      undo(p) { const m = p.media.find((x) => x.id === id); if (m) Object.assign(m, clone(prev)); },
    };
  },

  addTrack(track, index, label = 'Add track') {
    const t = clone(track);
    return {
      label,
      do(p) { p.tracks.splice(index == null ? p.tracks.length : index, 0, clone(t)); },
      undo(p) { p.tracks = p.tracks.filter((x) => x.id !== t.id); },
    };
  },
  removeTrack(id, label = 'Remove track') {
    let saved = null, at = -1;
    return {
      label,
      do(p) { at = p.tracks.findIndex((t) => t.id === id); saved = clone(p.tracks[at]); p.tracks.splice(at, 1); },
      undo(p) { p.tracks.splice(at, 0, clone(saved)); },
    };
  },
  setTrack(id, props, label = 'Track') {
    let prev = null; const next = clone(props);
    return {
      label, key: 'track:' + id + ':' + Object.keys(next).join(','),
      do(p) { const t = req(findTrack(p, id), id); if (!prev) prev = Object.fromEntries(Object.keys(next).map((k) => [k, clone(t[k])])); Object.assign(t, clone(next)); },
      undo(p) { const t = findTrack(p, id); if (t) Object.assign(t, clone(prev)); },
    };
  },

  /** canvas fields: {w,h,aspect,fps,bg} */
  setCanvas(props, label = 'Canvas') {
    let prev = null; const next = clone(props);
    return {
      label, key: 'canvas:' + Object.keys(next).join(','),
      do(p) { if (!prev) prev = Object.fromEntries(Object.keys(next).map((k) => [k, p.canvas[k]])); Object.assign(p.canvas, clone(next)); },
      undo(p) { Object.assign(p.canvas, clone(prev)); },
    };
  },

  /** replace the whole captions block (R2) */
  setCaptions(captions, label = 'Captions') {
    let prev = null; const next = clone(captions);
    return {
      label, key: 'captions',
      do(p) { if (!prev) prev = clone(p.captions); p.captions = clone(next); },
      undo(p) { p.captions = clone(prev); },
    };
  },

  /** top-level project fields: name, brand, recipe, markers, meta.<x> via setProject('meta', {...}) */
  setProject(props, label = 'Project') {
    let prev = null; const next = clone(props);
    return {
      label, key: 'project:' + Object.keys(next).join(','),
      do(p) { if (!prev) prev = Object.fromEntries(Object.keys(next).map((k) => [k, clone(p[k])])); Object.assign(p, clone(next)); },
      undo(p) { Object.assign(p, clone(prev)); },
    };
  },

  /** several commands as one undo step */
  batch(label, cmds) { return composite(label, cmds.filter(Boolean)); },

  /**
   * The snapshot fallback for big, irregular edits (auto reel, silence removal): fn(project) edits in place.
   * Undo restores the whole project as it was. Costs a clone of the project per step — fine for occasional use,
   * don't use it for drags.
   */
  mutate(label, fn) {
    let before = null, after = null;
    const restore = (p, snap) => { for (const k of Object.keys(p)) delete p[k]; Object.assign(p, clone(snap)); };
    return {
      label,
      do(p) {
        if (after) return restore(p, after);
        before = clone(p); fn(p); after = clone(p);
      },
      undo(p) { restore(p, before); },
    };
  },
};
