// KBB Studio — the Export dialog: size, frame rate, quality, extra aspects, progress, cancel.

import { exportMp4, QUALITY } from '../core/export.js';
import { exportsDir } from '../core/config.js';
import { ASPECTS, clone, projectDuration } from '../core/project.js';
import { engine, fmtBytes } from '../core/engine.js';
import { h, modal, seg, field, toast, fmtDur } from './ui.js';
import { reflowTo } from './items.js';

let busy = false;

function dims(aspect, short) {
  const a = ASPECTS[aspect];
  const s = short / Math.min(a.w, a.h);
  return { w: Math.round((a.w * s) / 2) * 2, h: Math.round((a.h * s) / 2) * 2 };
}
const safe = (s) => String(s || 'reel').replace(/[^\w\- ]+/g, '').trim().replace(/\s+/g, '-').slice(0, 50) || 'reel';

export function exportDialog(ed) {
  if (busy) return;
  ed.pause();
  const p = ed.project, T = projectDuration(p);
  if (T <= 0) { toast('Add a clip to the timeline first', 'warn'); return; }
  const opt = { size: 1080, fps: p.canvas.fps || 30, quality: 'standard', extra: new Set() };
  const est = h('small.muted');
  const upd = () => {
    const d = dims(p.canvas.aspect, opt.size);
    const bps = Math.min(50e6, d.w * d.h * opt.fps * QUALITY[opt.quality].bpp) + 160000;
    est.textContent = `${d.w}×${d.h} · ${fmtDur(T)} · about ${fmtBytes((bps / 8) * T)} per file`;
  };
  const name = h('input', { value: safe(p.name), 'aria-label': 'File name' });
  const extras = h('div.checks', Object.keys(ASPECTS).filter((a) => a !== p.canvas.aspect).map((a) => h('label.check', h('input', { type: 'checkbox', on: { change: (e) => { e.target.checked ? opt.extra.add(a) : opt.extra.delete(a); } } }), `Also ${a}`)));
  const bar = h('progress.xbar', { max: 1, value: 0, hidden: true });
  const stat = h('div.xstat');
  const results = h('div.xresults');
  const body = h('div.export-form',
    field('File name', name),
    field('Size', seg([['720', '720p'], ['1080', '1080p (best for reels)'], ['1440', '1440p']], '1080', (v) => { opt.size = +v; upd(); })),
    field('Frame rate', seg([['30', '30 fps'], ['60', '60 fps']], String(opt.fps), (v) => { opt.fps = +v; upd(); })),
    field('Quality', seg(Object.entries(QUALITY).map(([k, q]) => [k, q.label]), 'standard', (v) => { opt.quality = v; upd(); })),
    field('Also export', extras),
    h('div.row', h('span.lbl', ''), est),
    h('p.muted.small', `MP4 (H.264 + AAC) into workspaces/${p.workspace}/exports/`),
    bar, stat, results);
  upd();
  let ctrl = null;
  const dlg = modal({
    title: 'Export video', body, wide: true,
    actions: [
      { label: 'Close', id: 'x-close', onClick: () => { if (busy) { ctrl?.abort(); return false; } } },
      { label: '⬇ Export MP4', primary: true, id: 'x-go', onClick: () => { run(); return false; } },
    ],
    onClose: () => ctrl?.abort(),
  });
  const go = dlg.box.querySelector('#x-go'), closeBtn = dlg.box.querySelector('#x-close');

  async function run() {
    if (busy) return;
    busy = true; go.disabled = true; closeBtn.textContent = 'Cancel'; bar.hidden = false; results.replaceChildren();
    ctrl = new AbortController();
    const jobs = [p.canvas.aspect, ...opt.extra];
    try {
      for (let j = 0; j < jobs.length; j++) {
        const aspect = jobs[j];
        let proj = p;
        if (aspect !== p.canvas.aspect) { proj = clone(p); reflowTo(aspect)(proj); }
        const d = dims(aspect, opt.size);
        const tag = jobs.length > 1 ? `(${j + 1}/${jobs.length}, ${aspect}) ` : '';
        const r = await exportMp4(proj, ed.media, {
          width: d.w, height: d.h, fps: opt.fps, quality: opt.quality, signal: ctrl.signal,
          onProgress: (f, txt) => { bar.value = (j + f) / jobs.length; stat.textContent = tag + txt; },
        });
        const file = `${safe(name.value)}-${aspect.replace(':', 'x')}-${d.w}x${d.h}.mp4`;
        const path = `${exportsDir(p.workspace)}/${file}`;
        if (engine.available) await engine.export(path, r.blob);
        const url = URL.createObjectURL(r.blob);
        results.appendChild(h('div.xres', h('video', { src: url, controls: true, muted: true, playsinline: true }),
          h('div', h('b', file), h('small', `${fmtBytes(r.blob.size)} · ${r.note}`), h('small.muted', engine.available ? `Saved in ${exportsDir(p.workspace)}/` : 'Not saved: no engine'),
            h('a.btn.small', { href: url, download: file }, 'Save a copy…'))));
        ed.lastExport = { path, size: r.blob.size, note: r.note, frames: r.frames, audio: r.audio };
      }
      stat.textContent = jobs.length > 1 ? `All ${jobs.length} videos ready` : 'Video ready';
      bar.value = 1;
      toast('Export finished', 'ok');
    } catch (e) {
      stat.textContent = e.cancelled ? 'Export cancelled' : 'Not exported: ' + (e.message || e);
      if (!e.cancelled) console.warn('[export]', e);
      ed.lastExport = { error: e.message, cancelled: !!e.cancelled };
    } finally {
      busy = false; go.disabled = false; closeBtn.textContent = 'Close'; ctrl = null;
    }
  }
  return dlg;
}
