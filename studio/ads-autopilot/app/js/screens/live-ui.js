// Running now (the owner, 22 Nov: "where i can see what's running currently, i need the live real time progress
// with proper list"): the engine's live list (engine/live.go), pushed over the event stream the moment each step
// changes. Used by the Overview card, the "● Running" pill and the preview window while it waits.
import { tt } from '../i18n.js';
import { esc } from '../ui.js';

const ICON = { ok: ['✓', 'acc'], warn: ['!', 'warn'], fail: ['✕', 'bad'], info: ['•', ''], run: ['⟳', 'warn'] };
const hm = (t) => { const d = new Date(t); return isNaN(d) || d.getFullYear() < 2000 ? '' : d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };

export function liveSteps(l, max = 60) {
  const steps = (l && l.steps) || [];
  const shown = steps.slice(-max);
  return `<ol class="small" style="list-style:none;margin:6px 0 0;padding:0">${shown.map((s) => { const [ic, cls] = ICON[s.status] || ICON.info;
    return `<li style="display:flex;gap:8px;align-items:flex-start;padding:5px 0;border-top:1px solid var(--line)"><span class="chip ${cls}" style="min-width:22px;justify-content:center${s.status === 'run' ? ';animation:ccspin 1.2s linear infinite' : ''}">${ic}</span><span style="flex:1;min-width:0;overflow-wrap:anywhere"><b>${esc(s.title)}</b>${s.detail ? `<br><span class="muted">${esc(s.detail)}</span>` : ''}</span></li>`; }).join('') || `<li class="muted">${esc(tt('Starting…', 'يبدأ…'))}</li>`}</ol>
  ${steps.length > max ? `<p class="small muted">${esc(tt(`… and ${steps.length - max} earlier step(s)`, `… و${steps.length - max} خطوة سابقة`))}</p>` : ''}`;
}

// the Overview card: while something runs, and for 10 minutes after it ends
export function liveCard(l) {
  if (!l || !l.kind) return '<div id="live-card"></div>';
  const recent = l.running || (l.ended && Date.now() - new Date(l.ended).getTime() < 10 * 60e3);
  if (!recent) return '<div id="live-card"></div>';
  const done = (l.steps || []).filter((s) => s.status === 'ok').length, bad = (l.steps || []).filter((s) => s.status === 'fail' || s.status === 'warn').length;
  return `<div id="live-card" class="card" data-live style="border-color:${l.running ? 'var(--accent)' : 'var(--line)'}"><header><h2>${l.running ? '● ' : ''}${esc(l.running ? tt('Running now', 'يعمل الآن') : tt('Just finished', 'انتهى للتو'))} · ${esc(l.kind)}</h2>
    <span class="small muted">${esc(tt(`started ${hm(l.started)}`, `بدأ ${hm(l.started)}`))}${l.ended && !l.running ? ' · ' + esc(tt(`ended ${hm(l.ended)}`, `انتهى ${hm(l.ended)}`)) : ''} · ${esc(tt(`${done} done${bad ? `, ${bad} need a look` : ''}`, `${done} تم${bad ? `، ${bad} تحتاج نظرة` : ''}`))}</span></header>
    ${l.running && l.now ? `<p class="small" style="margin:4px 0 0"><b>${esc(tt('Now', 'الآن'))}:</b> ${esc(l.now)}</p>` : ''}${liveSteps(l)}</div>`;
}

// the pill: visible on every page while something runs
export function paintPill(l) {
  let p = document.getElementById('livepill');
  if (!p) {
    p = document.createElement('button');
    p.id = 'livepill'; p.type = 'button';
    p.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:60;max-width:min(520px,calc(100vw - 32px));border-radius:999px;padding:8px 14px;box-shadow:0 6px 20px rgba(0,0,0,.25);text-align:start;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
    p.onclick = () => { location.hash = '#/overview'; setTimeout(() => { const c = document.getElementById('live-card'); if (c) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300); };
    document.body.appendChild(p);
  }
  p.hidden = !(l && l.running);
  if (l && l.running) p.textContent = `● ${tt('Running', 'يعمل')}: ${l.kind}${l.now ? ' — ' + l.now : ''}`;
}
