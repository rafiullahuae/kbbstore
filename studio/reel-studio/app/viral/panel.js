// KBB Studio · viral · the 🔥 Viral tab (right column). Every button runs ONE feature as ONE undo step.
import { h, clear } from '../editor/ui.js';
import { HOOK_KINDS } from './lib/hooklines.js';
import { PLATFORM_IDS } from './lib/safezones.js';
import { ops } from '../core/history.js';
import { addSound } from './commands.js';
import { heat } from './lib/pacing.js';

const CSS = `
.vx { display: grid; gap: 8px; padding: 10px 10px 24px; font-size: 13px; min-width: 0; overflow-x: hidden; }
.vx details, .vx .vx-body { min-width: 0; }
.vx details { border: 1px solid var(--line); border-radius: var(--r2); background: var(--panel2); transition: border-color var(--t); }
.vx details[open] { border-color: var(--line2); }
.vx summary { cursor: pointer; padding: 9px 11px; font-weight: 700; list-style: none; display: flex; gap: 8px; align-items: center; }
.vx summary::-webkit-details-marker { display: none; }
.vx summary small { margin-left: auto; color: var(--muted); font-weight: 500; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vx .vx-body { display: grid; gap: 8px; padding: 0 11px 11px; }
.vx .vx-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.vx .vx-row > select, .vx .vx-row > input { flex: 1 1 0; min-width: 64px; }
.vx button.vx-b { border: 1px solid var(--line2); background: var(--panel3); border-radius: var(--r-sm); padding: 5px 10px; min-height: 32px; font-weight: 600; font-size: 12px; white-space: normal; text-align: center; line-height: 1.25; transition: background-color var(--t), border-color var(--t); }
.vx button.vx-b.pri { background: var(--accent); border-color: transparent; color: var(--accent-fg); }
.vx button.vx-b:hover:not(:disabled) { border-color: var(--line3); }
.vx .vx-out { color: var(--muted); font-size: 12px; white-space: pre-wrap; }
.vx .vx-out b { color: var(--text); }
.vx .vx-hook { display: flex; gap: 8px; align-items: flex-start; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--line); cursor: pointer; }
.vx .vx-hook.on { border-color: var(--ws); background: color-mix(in srgb, var(--ws) 12%, transparent); }
.vx .vx-kind { font-size: 10px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); min-width: 64px; padding-top: 2px; }
.vx .vx-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
.vx .vx-grid button { text-align: left; }
.vx .vx-snd { display: flex; gap: 6px; align-items: center; font-size: 12px; }
.vx .vx-snd span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.vx .vx-tag { font-size: 10px; color: var(--muted); }
.vx canvas.vx-heat { width: 100%; height: 22px; border-radius: 6px; display: block; }
.vx .vx-zone { display: flex; gap: 4px; flex-wrap: wrap; align-items: center; font-size: 12px; }
.vx .vx-find { border-left: 3px solid var(--warn); padding: 4px 8px; font-size: 12px; display: grid; gap: 4px; }
.vx .vx-find.high { border-left-color: var(--err); }
.vx video { width: 100%; border-radius: 8px; background: #000; max-height: 200px; }
`;

export function mountPanel(root, ctx, V) {
  if (!document.getElementById('vx-css')) document.head.appendChild(h('style#vx-css', CSS));
  clear(root);
  const box = h('div.vx'); root.appendChild(box);
  const busy = async (btn, out, fn) => {
    const label = btn.textContent; btn.disabled = true; btn.textContent = '…';
    try { const r = await fn(); return r; } catch (e) { if (out) out.textContent = e.message || String(e); ctx.toast(e.message || String(e), 'error'); console.warn('[viral]', e); return null; }
    finally { btn.disabled = false; btn.textContent = label; }
  };
  const btn = (label, onClick, pri) => { const b = h('button.vx-b' + (pri ? '.pri' : ''), label); b.addEventListener('click', () => onClick(b)); return b; };
  const section = (icon, title, sub, open, ...body) => h('details', { open, dataset: { vx: title.toLowerCase().split(' ')[0] } }, h('summary', h('span', icon), h('span', title), sub ? h('small', sub) : null), h('div.vx-body', ...body));
  const sel = (opts, value) => h('select', opts.map(([v, l]) => h('option', { value: v, selected: v === value }, l)));

  // 1 ── hook builder
  const hookOut = h('div.vx-out'), hookList = h('div', { style: { display: 'grid', gap: '4px' } }), lang = sel([['auto', 'Language: auto'], ['en', 'English'], ['ar', 'العربية']], 'auto');
  let lines = [], chosen = null, seed = 0;
  const showLines = () => {
    lines = V.hookLines(ctx, { lang: lang.value === 'auto' ? null : lang.value, seed });
    chosen = chosen && lines.find((l) => l.kind === chosen.kind) ? lines.find((l) => l.kind === chosen.kind) : lines.slice().sort((a, b) => b.score - a.score)[0];
    hookList.replaceChildren(...lines.map((l) => { const r = h('div.vx-hook' + (l === chosen ? '.on' : ''), { dataset: { kind: l.kind }, dir: l.lang === 'ar' ? 'rtl' : 'ltr' }, h('span.vx-kind', l.kind), h('span', l.text)); r.addEventListener('click', () => { chosen = l; showLines(); }); return r; }));
  };
  const ffOut = h('div.vx-out');
  box.appendChild(section('🪝', 'Hook builder', '0–2 s', true,
    h('div.vx-row', lang, btn('✨ Write 10 hooks', () => { seed = 0; showLines(); }), btn('🔄 Others', () => { seed++; showLines(); })),
    hookList,
    h('div.vx-row', btn('Use this hook', (b) => busy(b, hookOut, async () => { if (!lines.length) showLines(); const r = await V.buildHook(ctx, { line: chosen }); hookOut.replaceChildren(h('b', h('bdi', `“${r.line.text}”`)), ` — 0–2 s, punch-in + sound hit${r.check?.issues.length ? `\nFirst frame: ${r.check.issues.join(', ')}${r.check.fix ? (r.check.fix.trimTo ? ` → trimmed to ${r.check.fix.trimTo.toFixed(1)} s` : r.check.fix.cover ? ` → the clear frame at ${(+r.check.fix.cover.until).toFixed(1)} s shown over it` : ' → colour lifted') : ''}` : '\nFirst frame: clear'}`); }), true),
      btn('🧪 First-frame check', (b) => busy(b, hookOut, async () => { const r = await V.firstFrameCheck(ctx); hookOut.textContent = r.issues.length ? `First frame: ${r.issues.join(', ')} (luma ${r.stats.luma}, sharpness ${r.stats.sharp}). Best opening at ${r.best.t.toFixed(1)} s.` : `First frame is clear (luma ${r.stats.luma}, saturation ${r.stats.sat}).`; }))),
    hookOut,
    h('div.vx-row', btn('⏩ Flash-forward opener', (b) => busy(b, ffOut, async () => { const r = await V.flashForward(ctx); ffOut.textContent = `The best moment (${r.from.toFixed(1)}–${r.to.toFixed(1)} s) now plays first, then “wait for it”.`; }))), ffOut));

  // 2 ── natural hooks
  const natOut = h('div.vx-out'), sndList = h('div', { style: { display: 'grid', gap: '3px' } });
  let audio = null;
  V.hookLibrary(ctx).then(({ sounds }) => sndList.replaceChildren(...sounds.map((s) => h('div.vx-snd', { dataset: { snd: s.id } },
    btn('▶', () => { audio?.pause(); audio = new Audio('/library/sfx/hooks/' + s.file); audio.play().catch(() => {}); }),
    h('span', { title: s.title }, s.title, ' ', h('span.vx-tag', [s.role, ...s.industries.filter((x) => x !== 'any')].join(' · '))),
    btn('+', () => { const t = ctx.playhead(); ctx.history.apply(ops.mutate('Add ' + s.title, (p) => addSound(p, s, t + (s.hitAt || 0), { feat: 'user-sound' }))); ctx.toast(`${s.title} at ${t.toFixed(1)} s`, 'ok'); }))))).catch(() => {});
  box.appendChild(section('🎣', 'Natural hooks', '10 video · 21 sound', false,
    h('div.vx-row', btn('🎣 Add a hook (best pair)', (b) => busy(b, natOut, async () => { const r = await V.addNaturalHook(ctx); natOut.textContent = `${V.VIDEO_HOOKS.find((x) => x.id === r.hook).name} + ${r.sound} on ${r.beat ? 'the first beat (' + r.beat.toFixed(2) + ' s)' : '0 s'} — press again for the next pair`; }), true)),
    h('div.vx-grid', V.VIDEO_HOOKS.map((x) => { const b = btn(`${x.icon} ${x.name}`, (bb) => busy(bb, natOut, async () => { const r = await V.addNaturalHook(ctx, { hookId: x.id }); natOut.textContent = `${x.name} + ${r.sound || 'no sound'}`; })); b.title = x.what; b.dataset.hook = x.id; return b; })),
    natOut, h('b', 'Sound hooks'), sndList));

  // 3 ── retention
  const retOut = h('div.vx-out'), heatC = h('canvas.vx-heat', { width: 600, height: 22 }), zones = h('div', { style: { display: 'grid', gap: '4px' } });
  const showPacing = (r) => {
    const g = heatC.getContext('2d'), n = r.perSec.length || 1; g.clearRect(0, 0, 600, 22);
    r.perSec.forEach((s, i) => { g.fillStyle = heat(s.score); g.fillRect((i / n) * 600, 0, 600 / n + 0.5, 22); });
    retOut.replaceChildren(h('b', `Average ${r.mean}/100`), ` · first 3 s ${r.hook}/100 · ${r.dead.length} dead zone${r.dead.length === 1 ? '' : 's'}`);
    zones.replaceChildren(...r.dead.map((z) => h('div.vx-zone', h('span', `${z.t0}–${z.t1.toFixed(1)} s (low ${z.min})`),
      ...[['jump', 'Jump cut'], ['punch', 'Punch-in'], ['broll', 'B-roll'], ['text', 'Text pop']].map(([k, l]) => btn(l, (b) => busy(b, retOut, async () => { const x = await V.fixDeadZones(ctx, { zones: [z], kinds: [k] }); showPacing(x.after); }))))));
  };
  box.appendChild(section('📈', 'Retention', 'pacing per second', false,
    h('div.vx-row', btn('Score my reel', (b) => busy(b, retOut, async () => showPacing(await V.pacing(ctx))), true), btn('Fix all dead zones', (b) => busy(b, retOut, async () => { const r = await V.fixDeadZones(ctx); showPacing(r.after); }))),
    heatC, retOut, zones));

  // 4 ── tighten
  const tOut = h('div.vx-out'), minGap = h('input', { type: 'number', step: 0.02, min: 0.06, max: 1, value: 0.12, title: 'Cut gaps longer than (s)' }), pad = h('input', { type: 'number', step: 0.01, min: 0.02, max: 0.15, value: 0.04, title: 'Breathing room kept each side (s)' });
  const topt = () => ({ minGap: +minGap.value, pad: +pad.value });
  box.appendChild(section('⚡', 'Jump-cut tightening', 'breaths & gaps', false,
    h('div.vx-row', h('label.vx-tag', 'gaps over'), minGap, h('label.vx-tag', 'keep'), pad),
    h('div.vx-row', btn('Preview', (b) => busy(b, tOut, async () => { const c = await V.planTightening(ctx, topt()); tOut.textContent = `${c.length} gaps (${c.filter((x) => x.kind === 'breath').length} breaths) · −${c.reduce((s, x) => s + x.b - x.a, 0).toFixed(2)} s`; })),
      btn('⚡ Tighten', (b) => busy(b, tOut, async () => { const r = await V.tighten(ctx, topt()); tOut.textContent = r.cuts.length ? `Cut ${r.cuts.length} gaps, −${r.removed.toFixed(2)} s, micro-fades at every join` : 'Already tight'; }), true)), tOut));

  // 5 ── zooms + reframe
  const zOut = h('div.vx-out');
  box.appendChild(section('🔍', 'Smart zooms', 'stressed words · face', false,
    h('div.vx-row', btn('Zoom on stressed words', (b) => busy(b, zOut, async () => { const l = await V.smartZooms(ctx); zOut.textContent = l.length ? `${l.length} zooms: ${l.map((x) => `“${x.w}” ${x.t0.toFixed(1)} s`).join(', ')}` : 'No stressed words found'; }), true),
      btn('Face in upper third', (b) => busy(b, zOut, async () => { const r = await V.upperThird(ctx); zOut.textContent = `Reframed (${r.keys} keys, ${r.zoom}×): the face stays on the upper third`; }))), zOut));

  // 6 ── safe zones
  const sOut = h('div.vx-out'), plat = sel([['all', 'Every app'], ...PLATFORM_IDS.map((k) => [k, V.PLATFORMS[k].name])], V.currentPlatform(ctx));
  plat.addEventListener('change', () => { V.setPlatform(ctx, plat.value); if (V.zoneView.platform) { V.zoneView.platform = plat.value; globalThis.kbbEditor?.preview.draw(); } });
  const show = btn('👁 Show app buttons', () => { V.zoneView.platform = V.zoneView.platform ? null : plat.value; show.classList.toggle('pri', !!V.zoneView.platform); globalThis.kbbEditor?.preview.draw(); });
  box.appendChild(section('📱', 'Safe zones', 'IG · TikTok · Shorts · Snap', false,
    h('div.vx-row', plat, show),
    h('div.vx-row', btn('Check', (b) => busy(b, sOut, async () => { const l = V.safeCheck(ctx, plat.value); sOut.textContent = l.length ? l.map((x) => `• ${x.name} — ${x.zones[0]}`).join('\n') : 'All clear: nothing under the app buttons'; })),
      btn('Move them out', (b) => busy(b, sOut, async () => { const r = V.autoNudge(ctx, plat.value); sOut.textContent = `${r.before.length} → ${r.after.length} problems${r.caption ? ` · captions moved to ${Math.round(r.caption.posY * 100)} %${r.caption.size ? ', size ' + r.caption.size : ''}` : ''}${r.moves.length ? ` · ${r.moves.length} items moved` : ''}`; }), true)), sOut));

  // 7 ── loop
  const lOut = h('div.vx-out'), ld = sel([['0.5', '0.5 s'], ['0.7', '0.7 s'], ['1', '1 s']], '0.7');
  box.appendChild(section('🔁', 'Loop ending', 'seamless replay', false,
    h('div.vx-row', ld, btn('Make it loop', (b) => busy(b, lOut, async () => { const r = await V.loopEnding(ctx, { d: +ld.value }); lOut.textContent = `The last ${r.d} s blend into the first frame (picture and sound)`; }), true)), lOut));

  // 8 ── CTA
  const cOut = h('div.vx-out'), kind = sel(Object.keys(V.CTA_KINDS).map((k) => [k, { comment: 'Comment a keyword', link: 'Link in bio', save: 'Save / share', dm: 'DM us', whatsapp: 'WhatsApp', call: 'Call' }[k]]), 'comment');
  const clang = sel([['auto', 'auto'], ['en', 'EN'], ['ar', 'AR']], 'auto'), kw = h('input', { placeholder: 'Keyword (VISA)', title: 'Comment / DM keyword' }), title = h('input', { placeholder: 'Text (optional)' });
  box.appendChild(section('📣', 'CTA builder', 'branded, EN / AR', false,
    h('div.vx-row', kind, clang), h('div.vx-row', kw, title),
    btn('Add CTA', (b) => busy(b, cOut, async () => { const r = await V.addCta(ctx, { kind: kind.value, lang: clang.value === 'auto' ? null : clang.value, keyword: kw.value.trim() || undefined, title: title.value.trim() || undefined }); cOut.textContent = `“${r.title}” ${r.t0.toFixed(1)}–${(r.t0 + r.dur).toFixed(1)} s`; }), true), cOut));

  // 9 ── chapters
  const chOut = h('div.vx-out'), mode = sel([['', 'Auto'], ['bar', 'Progress bar'], ['steps', 'Step counter'], ['both', 'Both']], '');
  box.appendChild(section('🔢', 'Chapters & steps', 'progress bar', false,
    h('div.vx-row', mode, btn('Add chapters', (b) => busy(b, chOut, async () => { const r = await V.chapters(ctx, { mode: mode.value || undefined }); chOut.textContent = `${r.steps ? 'Steps' : 'Chapters'} (${r.mode}): ${r.chapters.map((c, i) => `${i + 1}. ${c.title} @${c.t0.toFixed(1)}s`).join(' · ')}`; }), true)), chOut));

  // 10 ── variations
  const vOut = h('div.vx-out'), vRes = h('div', { style: { display: 'grid', gap: '6px' } }), vBar = h('progress', { max: 1, value: 0, hidden: true, style: { width: '100%' } });
  box.appendChild(section('🅰️', 'A/B variations', '3 openers → 3 MP4s', false,
    btn('Export 3 versions', (b) => busy(b, vOut, async () => {
      vBar.hidden = false; vRes.replaceChildren();
      const list = await V.variations(ctx, { onProgress: (f, t) => { vBar.value = f; vOut.textContent = t; } });
      vBar.hidden = true; vOut.textContent = `3 versions saved in ${list[0].path.replace(/\/[^/]+$/, '/')}`;
      vRes.replaceChildren(...list.map((x) => h('div', h('b', `${x.label} — ${x.opener}`), h('video', { src: x.url, controls: true, muted: true, playsinline: true }), h('span.vx-tag', `${x.path.split('/').pop()} · ${(x.size / 1e6).toFixed(1)} MB`))));
    }), true), vBar, vOut, vRes));

  // 11 ── claims
  const clOut = h('div.vx-out'), clList = h('div', { style: { display: 'grid', gap: '4px' } });
  const showClaims = () => {
    const f = V.claimCheck(ctx);
    clOut.textContent = f.length ? `${f.length} risky claim${f.length > 1 ? 's' : ''} — a guide, not legal advice` : 'No risky claims found';
    clList.replaceChildren(...f.map((x) => h('div.vx-find' + (x.sev === 'high' ? '.high' : ''), h('span', h('b', `“${x.match}”`), ` → “${x.suggestion}”`), h('span.vx-tag', `${x.source}${x.t != null ? ' @' + (+x.t).toFixed(1) + 's' : ''} · ${x.why}`),
      h('div.vx-row', btn('Use the safer wording', () => { V.fixClaims(ctx, [x]); showClaims(); })))));
    return f;
  };
  box.appendChild(section('🛡️', 'Claim safety', 'visa · medical · beauty', false,
    h('div.vx-row', btn('Check claims', () => showClaims(), true), btn('Fix all', () => { V.fixClaims(ctx); showClaims(); })), clOut, clList));

  return { destroy() { audio?.pause(); V.zoneView.platform = null; } };
}

export { HOOK_KINDS };
