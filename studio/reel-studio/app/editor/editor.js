// KBB Studio — the editor: state, layout, top bar, "Do it for me" bar, keyboard, autosave, clipboard, split.

import { bus } from '../core/bus.js';
import { History, ops } from '../core/history.js';
import { MediaStore } from '../core/media.js';
import { engine } from '../core/engine.js';
import { APP_NAME, projectDir } from '../core/config.js';
import { ASPECTS, projectDuration, findItem, findTrack, itemDur, itemEnd, uid, clone, groupMembers, createTrack } from '../core/project.js';
import { SLOTS, getModule, listModules } from '../core/modules.js';
import { h, $, clear, toast, modal, seg, isTyping, fmtTime } from './ui.js';
import { workspaceSwitcher, state as wsState } from './workspaces.js';
import { Preview } from './preview.js';
import { Timeline } from './timeline.js';
import { Inspector } from './inspector.js';
import { Bin } from './bin.js';
import { exportDialog } from './exportdlg.js';
import { clipFromMedia, trackEnd, reflowTo } from './items.js';
import { prepareRender } from '../core/render.js';

export class Editor {
  constructor(root, project, { onHome } = {}) {
    this.root = root;
    this.project = project;
    this.onHome = onHome;
    this.bus = bus;
    this.engine = engine;
    this.ops = ops;
    this.history = new History(() => this.project);
    this.media = new MediaStore({ getProject: () => this.project, engine });
    this.sel = new Set();
    this.t = 0;
    this.playing = false;
    this.clipboard = null;
    this.savedVersion = 0;
    this.offs = [];
    this.build();
    this.offs.push(bus.on('project:change', () => this.changed()));
    this.offs.push(bus.on('modules:changed', () => { this.renderDoit(); this.renderSideTabs(); }));
    this.offs.push(bus.on('toast', (e) => toast(e.text, e.kind)));
    this.keyHandler = (e) => this.onKey(e);
    document.addEventListener('keydown', this.keyHandler);
    this.unloadHandler = () => { if (this.history.version !== this.savedVersion) this.save(); };
    window.addEventListener('pagehide', this.unloadHandler);
    bus.emit('project:load', { project });
    this.changed(true);
    // badges and brand fonts the layers need (a reopened project draws exactly like the one that was saved)
    prepareRender(project).then(() => this.preview?.draw());
    window.kbbEditor = this; // for the check scripts and the console
  }

  destroy() {
    this.pause();
    if (this.history.version !== this.savedVersion) this.save();
    for (const off of this.offs) off();
    document.removeEventListener('keydown', this.keyHandler);
    window.removeEventListener('pagehide', this.unloadHandler);
    this.capPanel?.destroy?.();
    for (const rec of this.modBodies.values()) rec.inst?.destroy?.();
    this.preview.destroy(); this.timeline.destroy();
    for (const m of this.project.media) this.media.release(m.id);
    if (window.kbbEditor === this) delete window.kbbEditor;
  }

  get workspace() { return wsState.current; }
  get dir() { return projectDir(this.project.workspace, this.project.id); }
  duration() { return projectDuration(this.project); }
  apply(cmd) { this.history.apply(cmd); }

  // ── module context (core/modules.js EditorContext) ──
  context() {
    const ed = this;
    return {
      get project() { return ed.project; },
      history: ed.history, ops, media: ed.media, engine, bus,
      get workspace() { return ed.workspace; },
      selection: () => [...ed.sel], playhead: () => ed.t, seek: (t) => ed.seek(t),
      toast, progress: (label, f) => ed.setProgress(label, f), openPanel: (id) => ed.openModulePanel(id),
    };
  }

  // ── layout ──
  build() {
    clear(this.root);
    this.status = h('span.save-state', 'Saved');
    this.undoBtn = h('button.icon-btn', { title: 'Undo (⌘Z)', on: { click: () => this.history.undo() } }, '↶');
    this.redoBtn = h('button.icon-btn', { title: 'Redo (⇧⌘Z)', on: { click: () => this.history.redo() } }, '↷');
    this.nameIn = h('input.proj-name', { value: this.project.name, title: 'Project name', 'aria-label': 'Project name' });
    this.nameIn.addEventListener('change', () => { const v = this.nameIn.value.trim() || 'Untitled reel'; this.apply(ops.setProject({ name: v }, 'Rename')); });
    this.aspectSeg = seg(Object.keys(ASPECTS).map((k) => [k, k, ASPECTS[k].label]), this.project.canvas.aspect, (v) => this.setAspect(v), '.aspect');
    const top = h('header.topbar',
      h('button.brand', { title: 'Home', on: { click: () => this.goHome() } }, h('span.logo-dot', '▶'), h('b', APP_NAME)),
      workspaceSwitcher({ onSwitch: () => this.goHome() }),
      this.nameIn, this.status,
      h('div.grow'),
      this.aspectSeg,
      h('div.sep'),
      this.undoBtn, this.redoBtn,
      h('button.icon-btn', { title: 'Keyboard shortcuts (?)', on: { click: () => this.shortcuts() } }, '⌨'),
      h('button.btn.primary.export-btn', { on: { click: () => exportDialog(this) } }, '⬇ Export'),
      h('button.btn.ghost.stop-btn', { title: `Stop ${APP_NAME}`, on: { click: () => stopStudio() } }, '⏻ Stop'),
    );
    this.doit = h('div.doit', { role: 'toolbar', 'aria-label': 'Do it for me' });
    this.progressLine = h('div.mod-progress', { hidden: true });
    this.binEl = h('aside.bin');
    this.previewEl = h('section.monitor');
    // the right column: the Inspector, and the caption editor (R2's panel, mounted once, kept alive)
    this.inspBody = h('div.side-body.insp-body');
    this.capBody = h('div.side-body.cap-body', { hidden: true });
    this.sideTabs = h('nav.side-tabs', { role: 'tablist' },
      h('button.side-tab.on', { role: 'tab', dataset: { side: 'inspector' }, on: { click: () => this.showSide('inspector') } }, '⚙ Inspector'),
      h('button.side-tab', { role: 'tab', dataset: { side: 'captions' }, on: { click: () => this.showSide('captions') } }, '💬 Captions'));
    this.modBodies = new Map();   // right-column tabs that modules add (registerModule({tab}))
    this.inspEl = h('aside.inspector.side', this.sideTabs, this.inspBody, this.capBody);
    this.renderSideTabs();
    this.tlEl = h('section.timeline');
    this.root.appendChild(h('div.editor', top, h('div.doit-row', h('span.doit-lbl', 'Do it for me'), this.doit, this.progressLine),
      h('div.work', this.binEl, this.previewEl, this.inspEl), this.tlEl));
    this.bin = new Bin(this, this.binEl);
    this.preview = new Preview(this, this.previewEl);
    this.inspector = new Inspector(this, this.inspBody);
    this.timeline = new Timeline(this, this.tlEl);
    this.renderDoit();
  }

  renderDoit() {
    clear(this.doit);
    const extra = listModules().filter((m) => !SLOTS.some((s) => s.id === m.id));
    for (const s of [...SLOTS, ...extra]) {
      const mod = getModule(s.id);
      this.doit.appendChild(h('button.doit-btn' + (mod ? '' : '.soon'), {
        title: mod ? (mod.label || s.label) : `${s.label} — coming in the next update`, dataset: { mod: s.id },
        on: { click: () => this.runModule(s.id, s.label) },
      }, h('span.ic', mod?.icon || s.icon || '★'), h('span', mod?.label || s.label)));
    }
  }

  async runModule(id, label) {
    const mod = getModule(id);
    if (!mod) { toast(`${label} is coming in the next update`, 'info'); return; }
    if (mod.panel && !mod.run) return this.openModulePanel(id);
    try {
      this.setProgress(mod.label || label, 0);
      const msg = await mod.run(this.context());
      if (msg) toast(String(msg), 'ok');
    } catch (e) {
      if (!e?.denied) { console.warn('[module]', id, e); toast(`${label}: ${e?.message || e}`, 'error'); }
    } finally { this.setProgress(null, null); }
  }

  /** one tab per module that asks for one (after Inspector and Captions), in tab.order */
  renderSideTabs() {
    if (!this.sideTabs) return;
    const mods = listModules().filter((m) => m.tab && m.panel).sort((a, b) => (a.tab.order ?? 50) - (b.tab.order ?? 50));
    for (const m of mods) {
      if (this.sideTabs.querySelector(`[data-side="mod:${m.id}"]`)) continue;
      this.sideTabs.appendChild(h('button.side-tab', { role: 'tab', dataset: { side: 'mod:' + m.id }, on: { click: () => this.showSide('mod:' + m.id) } }, m.tab.label || m.label));
      const body = h('div.side-body.mod-body', { hidden: true, dataset: { mod: m.id } });
      this.modBodies.set(m.id, { body, mounted: false, inst: null });
      this.inspEl.appendChild(body);
    }
  }

  /** right column: 'inspector' | 'captions' | 'mod:<module id>' */
  showSide(which) {
    for (const b of this.sideTabs.children) b.classList.toggle('on', b.dataset.side === which);
    this.inspBody.hidden = which !== 'inspector';
    this.capBody.hidden = which !== 'captions';
    for (const [id, rec] of this.modBodies) {
      rec.body.hidden = which !== 'mod:' + id;
      if (!rec.body.hidden && !rec.mounted) { rec.mounted = true; rec.inst = getModule(id).panel(rec.body, this.context()); }
    }
    if (which === 'captions' && !this.capMounted) {
      const mod = getModule('auto-captions');
      if (mod?.panel) { this.capMounted = true; this.capPanel = mod.panel(this.capBody, this.context()); }
      else this.capBody.replaceChildren(h('p.muted.pad', 'Captions are not installed in this copy of the studio.'));
    }
    this.side = which;
  }

  openModulePanel(id) {
    if (id === 'auto-captions') { this.showSide('captions'); return; }
    if (this.modBodies.has(id)) { this.showSide('mod:' + id); return; }
    const mod = getModule(id); if (!mod?.panel) return;
    const el = h('div.mod-panel');
    modal({ title: mod.label || id, body: el, wide: true });
    mod.panel(el, this.context());
  }

  setProgress(label, f) {
    const el = this.progressLine;
    if (label == null) { el.hidden = true; return; }
    el.hidden = false;
    el.replaceChildren(...[h('span', label), f == null ? null : h('progress', { max: 1, value: f })].filter(Boolean));
  }

  // ── state changes ──
  changed(first = false) {
    // drop selections of items that no longer exist
    for (const id of [...this.sel]) if (!findItem(this.project, id)) this.sel.delete(id);
    this.undoBtn.disabled = !this.history.canUndo; this.redoBtn.disabled = !this.history.canRedo;
    this.undoBtn.title = this.history.canUndo ? `Undo ${this.history.undoLabel} (⌘Z)` : 'Nothing to undo';
    this.redoBtn.title = this.history.canRedo ? `Redo ${this.history.redoLabel} (⇧⌘Z)` : 'Nothing to redo';
    if (document.activeElement !== this.nameIn) this.nameIn.value = this.project.name;
    for (const b of this.aspectSeg.children) b.classList.toggle('on', b.dataset.v === this.project.canvas.aspect);
    this.timeline.render(); this.inspector.render(); this.preview.layout(); this.preview.draw(); this.bin.renderMedia?.();
    if (!first) this.queueSave();
  }

  select(ids, mode = 'replace') {
    // clicking one member of a group selects the whole group
    const expand = (id) => groupMembers(this.project, id).map((i) => i.id);
    const list = ids.flatMap(expand);
    if (mode === 'replace') this.sel = new Set(list);
    else if (mode === 'add') for (const id of list) this.sel.add(id);
    else if (mode === 'toggle') {
      const on = list.every((id) => this.sel.has(id));
      for (const id of list) on ? this.sel.delete(id) : this.sel.add(id);
    }
    bus.emit('selection', { ids: [...this.sel] });
    this.timeline.renderSelection(); this.inspector.render(); this.preview.drawOverlay();
  }
  selectedItems() { return [...this.sel].map((id) => findItem(this.project, id)).filter(Boolean); }

  // ── transport ──
  seek(t) {
    this.t = Math.max(0, Math.min(t, Math.max(this.duration(), 0)));
    if (this.playing) this.preview.restartClock();
    this.preview.draw(); this.timeline.renderPlayhead();
    bus.emit('playhead', { t: this.t });
  }
  play(rate = 1) {
    if (this.duration() <= 0) return;
    if (this.t >= this.duration() - 0.01) this.t = 0;
    this.playing = true; this.rate = rate; this.preview.start(); bus.emit('play', { t: this.t }); this.timeline.renderPlayhead();
  }
  pause() { if (!this.playing) return; this.playing = false; this.preview.stop(); bus.emit('pause', { t: this.t }); this.timeline.renderPlayhead(); this.preview.draw(); }
  toggle() { this.playing ? this.pause() : this.play(); }
  frame() { return 1 / (this.project.canvas.fps || 30); }

  // ── edits ──
  setAspect(a) {
    if (a === this.project.canvas.aspect) return;
    this.apply(ops.mutate(`Aspect ${a}`, reflowTo(a)));
  }

  /** add a media entry (already imported) to the timeline; returns the item */
  addMediaToTimeline(m, { trackId, t0 } = {}) {
    const p = this.project;
    let track = trackId ? findTrack(p, trackId) : null;
    const kind = m.kind === 'audio' ? 'music' : 'video';
    if (!track) track = p.tracks.find((t) => t.kind === kind && !t.locked);
    if (!track) return null;
    const start = t0 ?? (track.kind === 'video' ? trackEnd(track) : this.t);
    const item = clipFromMedia(p, m, track.kind, start);
    const cmds = [];
    if (!p.media.some((x) => x.id === m.id)) cmds.push(ops.addMedia(m));
    cmds.push(ops.addItems([{ trackId: track.id, item }]));
    this.apply(ops.batch(`Add ${m.name}`, cmds));
    this.select([item.id]);
    return item;
  }

  addItem(item, kind) {
    const track = this.project.tracks.find((t) => t.kind === kind && !t.locked) || null;
    if (!track) { const t = createTrack(kind); this.apply(ops.addTrack(t)); return this.addItem(item, kind); }
    this.apply(ops.addItems([{ trackId: track.id, item }], 'Add ' + (item.name || item.type)));
    this.select([item.id]);
  }

  deleteSelection(ripple = false) {
    const hits = this.selectedItems().filter((x) => !x.track.locked);
    if (!hits.length) return;
    const cmds = [ops.removeItems(hits.map((x) => x.item.id))];
    if (ripple) {
      // close each gap: later items on the same track move left by the removed duration
      const shifts = new Map();
      for (const { track, item } of hits) {
        for (const other of track.items) {
          if (hits.some((x) => x.item.id === other.id)) continue;
          if (other.t0 >= itemEnd(item) - 1e-6) shifts.set(other.id, (shifts.get(other.id) ?? other.t0) - itemDur(item));
        }
      }
      if (shifts.size) cmds.push(ops.moveItems([...shifts].map(([id, t0]) => ({ id, t0: Math.max(0, t0) })), 'Ripple'));
    }
    this.apply(ops.batch(ripple ? 'Ripple delete' : 'Delete', cmds));
    this.select([]);
  }

  /** split the selected items (or everything under the playhead) at the playhead */
  split() {
    const t = this.t, p = this.project;
    let targets = this.selectedItems();
    if (!targets.length) targets = p.tracks.filter((tr) => !tr.locked).flatMap((track) => track.items.filter((it) => t > it.t0 + 0.02 && t < itemEnd(it) - 0.02).map((item) => ({ track, item })));
    targets = targets.filter(({ track, item }) => !track.locked && t > item.t0 + 0.02 && t < itemEnd(item) - 0.02);
    if (!targets.length) { toast('Move the playhead over a clip to split it'); return; }
    const cmds = [], newIds = [];
    for (const { track, item } of targets) {
      const cut = item.in + (t - item.t0) * (item.speed || 1);
      const right = clone(item); right.id = uid('i'); right.t0 = t; right.in = cut; right.fadeIn = 0; right.kf = {};
      cmds.push(ops.setItem(item.id, { out: cut, fadeOut: 0 }, 'Split'));
      cmds.push(ops.addItems([{ trackId: track.id, item: right, index: track.items.indexOf(item) + 1 }]));
      newIds.push(right.id);
    }
    this.apply(ops.batch('Split', cmds));
    this.select(newIds);
  }

  copy(quiet = false) {
    const hits = this.selectedItems(); if (!hits.length) return;
    const t0 = Math.min(...hits.map((x) => x.item.t0));
    this.clipboard = hits.map(({ track, item }) => ({ trackId: track.id, item: clone(item), rel: item.t0 - t0 }));
    if (!quiet) toast(`Copied ${hits.length} item${hits.length > 1 ? 's' : ''}`);
  }
  paste(at = this.t) {
    if (!this.clipboard?.length) return;
    const groups = new Map();
    const list = this.clipboard.map((c) => {
      const it = clone(c.item); it.id = uid('i'); it.t0 = Math.max(0, at + c.rel);
      if (it.group) { if (!groups.has(it.group)) groups.set(it.group, uid('g')); it.group = groups.get(it.group); }
      const track = findTrack(this.project, c.trackId) || this.project.tracks.find((t) => t.kind === 'overlay');
      return { trackId: track.id, item: it };
    });
    this.apply(ops.addItems(list, 'Paste'));
    this.select(list.map((x) => x.item.id));
  }
  duplicate() {
    const hits = this.selectedItems(); if (!hits.length) return;
    this.copy(true);
    const end = Math.max(...hits.map((x) => itemEnd(x.item)));
    this.paste(end);
  }
  group() {
    const hits = this.selectedItems(); if (hits.length < 2) { toast('Select two or more items to group'); return; }
    const g = uid('g');
    this.apply(ops.setItems(hits.map((x) => ({ id: x.item.id, props: { group: g } })), 'Group'));
    toast('Grouped');
  }
  ungroup() {
    const hits = this.selectedItems().filter((x) => x.item.group); if (!hits.length) return;
    this.apply(ops.setItems(hits.map((x) => ({ id: x.item.id, props: { group: undefined } })), 'Ungroup'));
    toast('Ungrouped');
  }
  addMarker() {
    const m = { id: uid('k'), t: this.t, label: 'Marker', color: '#ffd166' };
    this.apply(ops.setProject({ markers: [...this.project.markers, m].sort((a, b) => a.t - b.t) }, 'Add marker'));
  }
  nudge(dx, dy) {
    const hits = this.selectedItems().filter((x) => !x.track.locked); if (!hits.length) return;
    this.apply(ops.setItems(hits.map(({ item }) => ({ id: item.id, props: { x: item.x + dx, y: item.y + dy } })), 'Nudge'));
  }

  // ── saving ──
  queueSave() {
    this.status.textContent = 'Saving…'; this.status.classList.remove('err');
    clearTimeout(this.saveT);
    this.saveT = setTimeout(() => this.save(), 1200);
  }
  async save() {
    clearTimeout(this.saveT);
    if (!engine.available) { this.status.textContent = 'Not saved (no engine)'; return; }
    const v = this.history.version;
    try {
      await engine.saveProject(this.project);
      if (v === this.history.version) { this.savedVersion = v; this.status.textContent = 'Saved'; }
    } catch (e) { this.status.textContent = 'Not saved!'; this.status.classList.add('err'); console.warn('[save]', e); }
  }
  async goHome() { this.pause(); await this.save(); this.onHome?.(); }

  // ── keyboard ──
  onKey(e) {
    if (isTyping(e) || document.querySelector('.modal-back')) return;
    const mod = e.metaKey || e.ctrlKey, k = e.key;
    const done = () => { e.preventDefault(); e.stopPropagation(); };
    if (mod && k.toLowerCase() === 'z') { done(); e.shiftKey ? this.history.redo() : this.history.undo(); return; }
    if (mod && k.toLowerCase() === 'y') { done(); this.history.redo(); return; }
    if (mod && k.toLowerCase() === 'c') { done(); this.copy(); return; }
    if (mod && k.toLowerCase() === 'x') { done(); this.copy(); this.deleteSelection(); return; }
    if (mod && k.toLowerCase() === 'v') { done(); this.paste(); return; }
    if (mod && k.toLowerCase() === 'd') { done(); this.duplicate(); return; }
    if (mod && k.toLowerCase() === 'g') { done(); e.shiftKey ? this.ungroup() : this.group(); return; }
    if (mod && k.toLowerCase() === 'a') { done(); this.select(this.project.tracks.filter((t) => !t.locked).flatMap((t) => t.items.map((i) => i.id))); return; }
    if (mod && k.toLowerCase() === 's') { done(); this.save(); toast('Saved'); return; }
    if (mod && k.toLowerCase() === 'e') { done(); exportDialog(this); return; }
    if (mod) return;
    const step = e.shiftKey ? 10 : 1;
    switch (k) {
      case ' ': done(); this.toggle(); break;
      case 'k': case 'K': done(); this.pause(); break;
      case 'l': case 'L': done(); this.playing && this.rate >= 1 ? this.play(Math.min(4, (this.rate || 1) * 2)) : this.play(1); break;
      case 'j': case 'J': done(); this.pause(); this.seek(this.t - 1); break;
      case 's': case 'S': done(); this.split(); break;
      case 'm': case 'M': done(); this.addMarker(); break;
      case 'Delete': case 'Backspace': done(); this.deleteSelection(e.shiftKey); break;
      case 'Escape': this.select([]); break;
      case 'ArrowLeft': done(); this.sel.size && e.altKey ? this.nudge(-step, 0) : this.seek(this.t - this.frame() * step); break;
      case 'ArrowRight': done(); this.sel.size && e.altKey ? this.nudge(step, 0) : this.seek(this.t + this.frame() * step); break;
      case 'ArrowUp': if (this.sel.size) { done(); this.nudge(0, -step); } break;
      case 'ArrowDown': if (this.sel.size) { done(); this.nudge(0, step); } break;
      case 'Home': done(); this.seek(0); break;
      case 'End': done(); this.seek(this.duration()); break;
      case '+': case '=': done(); this.timeline.zoom(1.25); break;
      case '-': case '_': done(); this.timeline.zoom(0.8); break;
      case '\\': done(); this.timeline.fit(); break;
      case '?': done(); this.shortcuts(); break;
      default:
    }
  }

  shortcuts() {
    const rows = [
      ['Space', 'Play / pause'], ['J / K / L', 'Back 1 s / stop / play (press L again: faster)'], ['← →', 'One frame (Shift: 10 frames)'],
      ['Home / End', 'Start / end'], ['S', 'Split at playhead'], ['Delete', 'Delete selected'], ['Shift + Delete', 'Ripple delete (close the gap)'],
      ['⌘Z / ⇧⌘Z', 'Undo / redo'], ['⌘C / ⌘X / ⌘V', 'Copy / cut / paste at playhead'], ['⌘D', 'Duplicate'], ['⌘A', 'Select all'],
      ['⌘G / ⇧⌘G', 'Group / ungroup'], ['↑ ↓ (Alt + ← →)', 'Nudge selected 1 px (Shift: 10 px)'], ['M', 'Add marker'],
      ['+ / − / \\', 'Timeline zoom in / out / fit'], ['⌘S', 'Save now'], ['⌘E', 'Export'], ['Esc', 'Clear selection'],
      ['Drag on the video', 'Box-select (starts over the video too)'], ['Shift / ⌘ click', 'Add to / remove from selection'], ['Alt + drag', 'Always box-select'],
    ];
    modal({ title: 'Keyboard shortcuts', wide: true, body: h('div.keys', rows.map(([a, b]) => h('div.key-row', h('kbd', a), h('span', b)))) });
  }

  /** import dropped/chosen files into this project and (optionally) the timeline */
  async importFiles(files, { addToTimeline = true, trackId, t0 } = {}) {
    const out = [];
    for (const f of files) {
      try {
        this.setProgress(`Importing ${f.name}…`, null);
        const m = await this.media.importFile(f, this.dir);
        this.apply(ops.addMedia(m));
        bus.emit('media:added', { media: m });
        if (addToTimeline) this.addMediaToTimeline(m, { trackId, t0 });
        out.push(m);
      } catch (e) { toast(e.message, 'error'); }
    }
    this.setProgress(null);
    return out;
  }
}

export async function stopStudio() {
  await window.kbbEditor?.save();
  await engine.stop();
  document.body.replaceChildren(h('div.stopped', h('h1', `${APP_NAME} has stopped`), h('p', 'You can close this tab. Double-click "Start ' + APP_NAME + '" to open it again.')));
}

export { fmtTime };
