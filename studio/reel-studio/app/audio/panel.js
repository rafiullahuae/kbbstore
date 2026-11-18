// KBB Studio · audio · the "🔊 Audio" tab.
import { h, clear, slider, select, seg, toast } from '../editor/ui.js';
import { bus } from '../core/bus.js';
import { EQ_PRESETS } from './dsp.js';
import { STAGES, DEFAULT_VOICE, strengthLabel, strengthDb } from './voice.js';
import { css } from '../look/panel.js';

let advCss = false;
function css2() {
  if (advCss) return; advCss = true;
  const s = document.createElement('style');
  s.textContent = `.lk details.adv { border-top: 1px solid var(--line2); padding-top: 6px; }
.lk details.adv > summary { cursor: pointer; font-size: 12.5px; font-weight: 600; color: var(--muted); padding: 4px 0; }
.lk details.adv[open] > summary { color: var(--text); }
.lk details.adv .checks { margin: 4px 0 8px; }`;
  document.head.appendChild(s);
}

export function audioPanel(el, ctx, A) {
  css(); css2();
  const root = h('div.lk'); el.appendChild(root);
  const st = JSON.parse(JSON.stringify(DEFAULT_VOICE));
  const run = async (label, fn) => {
    try { ctx.progress?.(label, null); const r = await fn(); if (typeof r === 'string') toast(r, 'ok'); return r; }
    catch (e) { toast(`${label}: ${e.message || e}`, 'error'); console.warn('[audio]', e); }
    finally { ctx.progress?.(null, null); }
  };
  const sec = (title, ...kids) => h('details.sec', { open: true }, h('summary', title), h('div.sec-body', kids));
  const voiceNote = h('p.lk-note'), loudNote = h('p.lk-note'), plats = h('div.lk-chips');

  const abBox = h('div.ab-box');
  function renderVoice() {
    const ms = ctx.project.media.filter((m) => m.audio?.stats);
    voiceNote.textContent = ms.length ? ms.map((m) => { const s = m.audio.stats; return `${m.name}: noise floor ${s.floorBefore} → ${s.floorAfter} dB` + (s.hum ? `, ${s.hum} Hz hum removed` : '') + (s.tp != null ? `, ${s.lufs} LUFS, peak ${s.tp} dBTP` : ''); }).join(' · ') : `${A.voiceMedia(ctx).length} clip(s) with sound. Enhancing keeps the original — undo or "Original sound" brings it back.`;
    // A/B: only when there is an enhanced sound to compare with
    clear(abBox);
    if (ms.length) abBox.appendChild(h('label.row', h('span.lbl', 'Listen'), seg([['before', 'A · Before'], ['after', 'B · After']], A.hearingOriginal() ? 'before' : 'after', (v) => A.hearOriginal(v === 'before'))));
    else if (A.hearingOriginal()) A.hearOriginal(false);
  }
  const strengthOut = (v) => strengthLabel(v);
  const strengthNote = h('p.lk-note');
  const noteFor = (v) => { strengthNote.textContent = v <= 0 ? 'Noise removal off (the other stages still run).' : Number.isFinite(strengthDb(v)) ? `Background noise about ${Math.round(strengthDb(v))} dB quieter${v > 0.67 ? ', plus a gate between words' : ''}.` : 'All the noise it finds, plus a gate between words.'; };
  noteFor(st.strength);
  const stageBoxes = STAGES.map(([k, label, tip]) => h('label.check', { title: tip }, h('input', { type: 'checkbox', checked: st.stages[k], dataset: { stage: k }, on: { change: (e) => { st.stages[k] = e.target.checked; } } }), label));
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
    sec('✨ Enhance voice',
      h('p.lk-note', 'One click: background noise, hum, room echo, voice EQ, de-ess, compression and −14 LUFS loudness. Runs offline; the preview plays the result and the export uses the same sound.'),
      slider({ label: 'Strength', min: 0, max: 1, step: 0.05, value: st.strength, fmt: strengthOut, onInput: (v) => { st.strength = v; noteFor(v); } }),
      strengthNote,
      h('div.lk-btns',
        h('button.btn.primary', { dataset: { act: 'enhance-voice' }, on: { click: () => run('Enhance voice', async () => { const r = await A.enhanceVoice(ctx, st); renderVoice(); return r.map((x) => `Noise ${x.floorBefore} → ${x.floorAfter} dB · ${x.lufs} LUFS`).join(' · ') + (r.ducked ? ' · music ducked' : ''); }) } }, '✨ Enhance voice'),
        h('button.btn', { on: { click: () => run('Original sound', () => { const n = A.undoVoice(ctx); renderVoice(); return n ? 'Original sound back' : 'Nothing to undo'; }) } }, 'Original sound')),
      abBox,
      voiceNote,
      h('details.adv', h('summary', 'Advanced — each stage'),
        h('div.checks', stageBoxes),
        h('label.row', h('span.lbl', 'EQ'), select(Object.entries(EQ_PRESETS).map(([k, v]) => [k, v.label]), st.eq, (v) => { st.eq = v; })),
        slider({ label: 'De-ess', min: 0, max: 1, step: 0.05, value: st.deess, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.deess = v; } }),
        slider({ label: 'Room echo', min: 0, max: 1, step: 0.05, value: st.reverb, fmt: (v) => Math.round(v * 100) + '%', onInput: (v) => { st.reverb = v; } }),
        h('p.lk-note', 'Change a stage, then press ✨ Enhance voice again: it always starts from the original sound.')),
      h('div.lk-btns', h('button.btn.wide', { title: 'Music dips while someone speaks (volume keyframes on the music)', on: { click: () => run('Duck music', async () => { const n = await A.duckMusic(ctx); return n ? `Music ducked under speech (${n} item${n === 1 ? '' : 's'})` : 'No music or no speech found'; }) } }, '🎵 Duck music under speech'))),
    sec('Loudness for the platform', plats, loudNote,
      h('div.lk-btns', h('button.btn.wide', { on: { click: () => run('Measure', async () => { const r = await A.measureMix(ctx); return r ? `The export would be ${r.lufs.toFixed(1)} LUFS, true peak ${r.tp.toFixed(1)} dBTP` : 'Nothing audible yet'; }) } }, '📏 Measure the mix now'))),
    sec('Sound design',
      h('div.lk-btns',
        h('button.btn', { title: 'A whoosh on every cut, its peak on the cut', on: { click: () => run('SFX on cuts', async () => `${await A.sfxOnCuts(ctx)} whooshes on the cuts`) } }, '💨 SFX on every cut'),
        h('button.btn', { title: 'Moves each cut to the nearest beat of the music', on: { click: () => run('Beat sync', async () => { const r = await A.beatSync(ctx); return `${r.moved} cut${r.moved === 1 ? '' : 's'} moved onto the beat`; }) } }, '🥁 Cuts on the beat'),
        h('button.btn.wide', { title: 'Riser + hit landing on the selected item, the end card or the playhead', on: { click: () => run('Riser', async () => `Riser lands at ${(await A.riserBeforeReveal(ctx)).toFixed(2)} s`) } }, '🚀 Riser before the reveal'))),
  );
  renderVoice(); renderLoud();
  const offs = [bus.on('project:change', () => { renderVoice(); renderLoud(); }), bus.on('audio:loudness', renderLoud), bus.on('audio:ab', renderVoice)];
  return { destroy() { for (const o of offs) o(); root.remove(); } };
}
