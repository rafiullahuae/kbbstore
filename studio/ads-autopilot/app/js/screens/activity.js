import { t, tt } from '../i18n.js';
import { esc, ago, txt, empty } from '../ui.js';
import { api, getAccount } from '../api.js';

let kind = 'all', q = '';
export function render(S, el) {
  const rows = S.cache.get(`${getAccount()}|log`);
  if (!rows) { el.innerHTML = `<div class="page"><div class="sk" style="height:42px"></div><div class="sk" style="height:420px"></div></div>`; return; }
  const kinds = ['all', ...new Set(rows.map((r) => r.kind))];
  const f = rows.filter((r) => (kind === 'all' || r.kind === kind) && (!q || (r.summary + ' ' + r.object).toLowerCase().includes(q.toLowerCase())));
  el.innerHTML = `<div class="page"><div class="spread"><div class="seg">${kinds.map((k) => `<button type="button" data-k="${esc(k)}" class="${kind === k ? 'on' : ''}">${esc(k === 'all' ? t('All') : k)}</button>`).join('')}</div>
  <input id="q" type="search" placeholder="${esc(t('Search'))}…" value="${esc(q)}" style="width:260px"></div>
  <div class="card" style="padding:0">${f.length ? `<table><thead><tr><th>${esc(tt('When', 'الوقت'))}</th><th>${esc(tt('Kind', 'النوع'))}</th><th>${esc(tt('Object', 'العنصر'))}</th><th>${esc(tt('What happened', 'ما حدث'))}</th><th>${esc(tt('Outcome', 'النتيجة'))}</th></tr></thead><tbody>
  ${f.slice(0, 300).map((r) => `<tr><td class="small" title="${esc(r.time)}">${esc(ago(r.time))}</td><td><span class="chip">${esc(r.kind)}</span></td><td class="small muted">${esc(r.object || '')}</td><td>${txt(r.summary)}</td><td>${esc(r.outcome || '')}</td></tr>`).join('')}</tbody></table>`
    : empty(tt('Nothing logged yet', 'لا يوجد سجل بعد'), tt('Every proposal, check, change and Meta answer will appear here.', 'سيظهر هنا كل مقترح وفحص وتغيير ورد من ميتا.'))}</div></div>`;
  el.querySelectorAll('[data-k]').forEach((b) => { b.onclick = () => { kind = b.dataset.k; render(S, el); }; });
  const qi = el.querySelector('#q');
  qi.oninput = () => { q = qi.value; const pos = qi.selectionStart; render(S, el); const n = el.querySelector('#q'); n.focus(); n.setSelectionRange(pos, pos); };
}
export async function load(S) {
  if (S.cache.has(`${getAccount()}|log`)) return false;
  S.cache.set(`${getAccount()}|log`, ((await api('/api/log?limit=300')).entries || []).map((e) => ({ id: e.seq, time: e.at, kind: e.kind, object: (e.data && (e.data.target || e.data.object)) || '', summary: e.summary, outcome: (e.data && (e.data.outcome || e.data.status)) || '' })).reverse());
  return location.hash.startsWith('#/activity');
}
