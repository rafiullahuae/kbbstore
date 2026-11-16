// KBB Studio · audio · the "🔊 Audio" tab.
import { h, clear, slider, select, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { EQ_PRESETS } from './dsp.js';
import { css } from '../look/panel.js';

export function audioPanel(el, ctx, A) {
  css();
  const root = h('div.lk'); el.appendChild(root);
  const st = { denoise: 1, eq: 'clear', deess: 0.5, level: true, compress: true };
  const run = async (label, fn) => {
    try { ctx.progress?.(label, null); const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[audio]', e); }
    finally { ctx.progress?.(null, null); }
  };
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const voiceNote = h('p.lk-note'), loudNote = h('p.lk-note'), plats = h('div.lk-chips');

  function renderVoice() {
    const ms = ctx.project.media.filter((m) => m.audio?.stats);
    voiceNote.textContent = ms.length ? ms.map((m) => `${m.name}: noise floor ${m.audio.stats.floorBefore} → ${m.audio.stats.floorAfter} dB`).join(' · ') : `${A.voiceMedia(ctx).length} clip(s) with sound. Cleaning keeps the original — undo or "Original sound" brings it back.`;
  }
  function renderLoud() {
    const cur = ctx.project.meta?.audio?.target?.platform || 'off';
    clear(plats);
    for (const [k, v] of [...Object.entries(A.PLATFORMS).map(([k, v]) => [k, v.label + ' −14']), ['off', 'Off']]) {
      plats.appendChild(h('button.chip' + (k === cur ? '.on' : ''), { dataset: { loud: k }, on: { click: () => { const msg = A.setLoudness(ctx, k === 'off' ? null : k); toast(msg, 'ok'); renderLoud(); } } }, v));
    }
    const r = A.loudnessReport();
    loudNote.textContent = cur === 'off' ? 'The export keeps the mix as it is.' : `On export: ${A.PLATFORMS[cur].lufs} LUFS integrated, true peak ≤ ${A.PLATFORMS[cur].tp} dBTP.` + (r ? ` Last export: ${r.after} LUFS, ${r.tpAfter} dBTP.` : '');
  }

  root.append(
    h('div.insp-head', h('b', '🔊 Audio'), h('small', 'Voice, loudness and sound design')),
    sec('Voice cleanup',
      h('p.lk-note', 'Removes background noise and hum (offline), then EQ, de-ess, levelling and compression — one click.'),
      slider({ label: 'Noise removal', min: 0, max: 1, step: 0.05, value: st.denoise, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.denoise = v; } }),
      h('label.row', h('span.lbl', 'EQ'), select(Object.entries(EQ_PRESETS).map(([k, v]) => [k, v.label]), st.eq, (v) => { st.eq = v; })),
      slider({ label: 'De-ess', min: 0, max: 1, step: 0.05, value: st.deess, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.deess = v; } }),
      h('label.check', h('input', { type: 'checkbox', checked: st.level, on: { change: (e) => { st.level = e.target.checked; } } }), 'Even out the level'),
      h('label.check', h('input', { type: 'checkbox', checked: st.compress, on: { change: (e) => { st.compress = e.target.checked; } } }), 'Compress (radio voice)'),
      h('div.lk-btns',
        h('button.btn.primary', { on: { click: () => run('Clean up voice', async () => { const r = await A.cleanVoice(ctx, st); renderVoice(); return r.map((x) => `Noise ${x.floorBefore} → ${x.floorAfter} dB`).join(' · '); }) } }, '🎙 Clean up voice'),
        h('button.btn', { on: { click: () => run('Original sound', () => { const n = A.undoVoice(ctx); renderVoice(); return n ? 'Original sound back' : 'Nothing to undo'; }) } }, 'Original sound')),
      voiceNote),
    sec('Loudness for the platform', plats, loudNote,
      h('div.lk-btns', h('button.btn.wide', { on: { click: () => run('Measure', async () => { const r = await A.measureMix(ctx); return r ? `The export would be ${r.lufs.toFixed(1)} LUFS, true peak ${r.tp.toFixed(1)} dBTP` : 'Nothing audible yet'; }) } }, '📏 Measure the mix now'))),
    sec('Sound design',
      h('div.lk-btns',
        h('button.btn', { title: 'A whoosh on every cut, its peak on the cut', on: { click: () => run('SFX on cuts', async () => `${await A.sfxOnCuts(ctx)} whooshes on the cuts`) } }, '💨 SFX on every cut'),
        h('button.btn', { title: 'Moves each cut to the nearest beat of the music', on: { click: () => run('Beat sync', async () => { const r = await A.beatSync(ctx); return `${r.moved} cut${r.moved === 1 ? '' : 's'} moved onto the beat`; }) } }, '🥁 Cuts on the beat'),
        h('button.btn.wide', { title: 'Riser + hit landing on the selected item, the end card or the playhead', on: { click: () => run('Riser', async () => `Riser lands at ${(await A.riserBeforeReveal(ctx)).toFixed(2)} s`) } }, '🚀 Riser before the reveal'))),
  );
  renderVoice(); renderLoud();
  const offs = [bus.on('project:change', () => { renderVoice(); renderLoud(); }), bus.on('audio:loudness', renderLoud)];
  return { destroy() { for (const o of offs) o(); root.remove(); } };
}
