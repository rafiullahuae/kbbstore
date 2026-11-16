// KBB Studio — core/engine.js (CONTRACT, owned by R1)
//
// The client for the local Go engine (engine/). Every path is RELATIVE to the studio folder and must start with one
// of the engine's roots; the engine refuses anything else (and any '..'):
//   readable: app/ vendor/ models/ library/ brands/ workspaces/
//   writable: workspaces/ library/ brands/ models/
//
//   import { engine } from '../core/engine.js';
//   await engine.connect();                       -> true when the engine answers (engine.available)
//   await engine.listDir('library/music')         -> [{name, dir, size, mtime}]
//   await engine.readFile('workspaces/kbb/workspace.json', 'json'|'text'|'blob'|'arrayBuffer')
//   await engine.writeFile(path, string|Blob|ArrayBuffer|object)   (objects are written as pretty JSON)
//   await engine.remove(path)
//   await engine.saveProject(project)  / loadProject(workspace, id) / listProjects(workspace)
//   await engine.export(path, blob)               -> path (an MP4 into workspaces/<ws>/exports/)
//   await engine.askPermission({what, size, host, url, scope}) -> bool   (the dialog is the app's: setPermissionUI)
//   await engine.fetchModel(repo, file, {size, what, onProgress}) -> 'models/<repo>/<file>' (local path)
//   engine.stop()                                 -> shuts the engine down (the "Stop" button)
//
// Network: ONLY through the engine's /net/*, which refuses unless askPermission() said yes for that exact URL
// (scope 'exact') or that Hugging Face repo (scope 'repo') this session, and only to the allowlist
// huggingface.co, cdn-lfs.huggingface.co, cas-bridge.xethub.hf.co.

import { bus } from './bus.js';
import { projectDir, projectsDir } from './config.js';

const token = () => document.querySelector('meta[name="rs-token"]')?.content || '';
const enc = (p) => encodeURIComponent(p);

let permissionUI = async ({ what, size, host }) =>
  window.confirm(`Allow a one-time download?\n\n${what}\n${size ? fmtBytes(size) + ' from ' : 'From '}${host}`);

export const fmtBytes = (n) => (n >= 1e9 ? (n / 1e9).toFixed(2) + ' GB' : n >= 1e6 ? (n / 1e6).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1e3)) + ' KB');

class EngineClient {
  constructor() { this.available = false; this.info = null; this.events = null; this.waiters = new Map(); this.grants = new Set(); }

  async _fetch(url, opts = {}) {
    const r = await fetch(url, Object.assign({ cache: 'no-store' }, opts, { headers: Object.assign({ 'X-RS-Token': token() }, opts.headers || {}) }));
    if (!r.ok) {
      let msg = r.status + ' ' + r.statusText;
      try { const j = await r.json(); if (j.error) msg = j.error; } catch {}
      const e = new Error(msg); e.status = r.status; throw e;
    }
    return r;
  }

  async connect() {
    try {
      this.info = await (await this._fetch('/api/info')).json();
      this.available = true;
      this._listen();
    } catch { this.available = false; }
    return this.available;
  }

  // the event stream is also the heartbeat: when the last tab closes, the engine stops itself
  _listen() {
    if (this.events || typeof EventSource !== 'function') return;
    this.events = new EventSource('/api/events?t=' + enc(token()));
    this.events.addEventListener('progress', (e) => {
      const d = JSON.parse(e.data);
      bus.emit('engine:progress', d);
      const w = this.waiters.get(d.id);
      if (w) { w.onProgress?.(d); if (d.state === 'done') { this.waiters.delete(d.id); w.res(d); } if (d.state === 'error') { this.waiters.delete(d.id); w.rej(new Error(d.error || 'download failed')); } }
    });
  }

  async listDir(path) {
    try { return await (await this._fetch('/api/list?path=' + enc(path))).json(); }
    catch (e) { if (e.status === 404) return []; throw e; }
  }
  async exists(path) {
    try { await this._fetch('/api/stat?path=' + enc(path)); return true; } catch { return false; }
  }
  async readFile(path, as = 'json') {
    const r = await this._fetch('/' + path.split('/').map(enc).join('/'));
    return as === 'json' ? r.json() : as === 'text' ? r.text() : as === 'blob' ? r.blob() : r.arrayBuffer();
  }
  async writeFile(path, data) {
    const body = data instanceof Blob || data instanceof ArrayBuffer || ArrayBuffer.isView(data) || typeof data === 'string' ? data : JSON.stringify(data, null, 1);
    await this._fetch('/api/write?path=' + enc(path), { method: 'PUT', body });
    return path;
  }
  async remove(path) { await this._fetch('/api/delete?path=' + enc(path), { method: 'POST' }); }

  // ── projects (each: workspaces/<ws>/projects/<id>/project.json + media/) ──
  async saveProject(project) {
    if (!project.workspace) throw new Error('project has no workspace');
    await this.writeFile(projectDir(project.workspace, project.id) + '/project.json', project);
  }
  loadProject(workspace, id) { return this.readFile(projectDir(workspace, id) + '/project.json', 'json'); }
  async listProjects(workspace) {
    const dirs = (await this.listDir(projectsDir(workspace))).filter((d) => d.dir);
    const out = [];
    for (const d of dirs) {
      try {
        const p = await this.readFile(`${projectsDir(workspace)}/${d.name}/project.json`, 'json');
        out.push({ id: p.id, name: p.name, modified: p.modified, aspect: p.canvas?.aspect, recipe: p.recipe, workspace });
      } catch { /* a half-written folder: skip */ }
    }
    return out.sort((a, b) => String(b.modified).localeCompare(String(a.modified)));
  }
  async export(path, blob) { return this.writeFile(path, blob); }

  // ── the permission gate ──
  setPermissionUI(fn) { permissionUI = fn; }
  async askPermission({ what, size = 0, host, url, scope = 'exact' }) {
    const key = scope + ' ' + url;
    if (this.grants.has(key)) return true;
    const ok = !!(await permissionUI({ what, size, host: host || new URL(url).host, url }));
    if (!ok) return false;
    await this._fetch('/api/permission', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url, scope, what, size }) });
    this.grants.add(key);
    return true;
  }

  /** small allowlisted GET (JSON/config) after permission */
  async netGet(url) { return this._fetch('/net/get?url=' + enc(url)); }

  /** download a model file ONCE into models/<repo>/<file>; asks first (one question per repo per session) */
  async fetchModel(repo, file, { size = 0, what, onProgress, revision = 'main' } = {}) {
    const dest = `models/${repo}/${file}`;
    if (await this.exists(dest)) return dest;
    const url = `https://huggingface.co/${repo}/resolve/${revision}/${file}`;
    const repoUrl = `https://huggingface.co/${repo}/`;
    const ok = await this.askPermission({ what: what || `Model ${repo}`, size, host: 'huggingface.co', url: repoUrl, scope: 'repo' });
    if (!ok) { const e = new Error('Download not allowed'); e.denied = true; throw e; }
    const r = await (await this._fetch('/net/download', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url, dest }) })).json();
    if (r.state === 'done') return dest;
    await new Promise((res, rej) => this.waiters.set(r.id, { res, rej, onProgress }));
    return dest;
  }

  async stop() { try { this.events?.close(); await this._fetch('/api/stop', { method: 'POST' }); } catch {} }
}

export const engine = new EngineClient();
