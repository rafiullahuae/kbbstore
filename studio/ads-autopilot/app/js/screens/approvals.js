// Approvals: in Practice mode every proposal of the last plan waits here (the engine's /api/proposals). Approve runs
// THAT proposal for real (plus the campaign / ad set / creative it needs, if not made yet) through every check of
// the executor; Not now sets it aside. Nothing re-plans.
import { tt } from '../i18n.js';
import { esc, aed, pct, toast, empty } from '../ui.js';
import { api } from '../api.js';
import { refreshState } from '../main.js';
import { readinessCard } from './funnel-ui.js';
import { occasionsCard, loadOccasions, wireOccasions } from './occasions-ui.js';

export const load = () => loadOccasions(false);

let busy = '';
export function render(S, el) {
  const st = S.state;
  const auto = st.autopilot.mode === 'auto';
  const props = st.pending || [];
  const starter = st.starter && st.starter.state === 'practice' && props.some((p) => p.starter);
  const row = (p) => `<div><span class="chip">${esc(p.kind)}</span><span style="flex:1"><b>${esc((p.params && p.params.name) || p.target)}</b>${p.params && p.params.daily_budget_aed ? ` → ${aed(p.params.daily_budget_aed)}/${esc(tt('day', 'يوم'))}` : ''}${p.params && p.params.format ? ` <span class="chip">${esc(p.params.format)}</span>` : ''}<br><span class="ink2">${esc(p.why)}</span> <span class="small muted">· ${esc(tt('confidence', 'الثقة'))} ${pct(p.confidence, 0)}</span>${p.status !== 'shadow' && p.reason ? `<br><span class="small muted">${esc(tt('Last try: ', 'آخر محاولة: '))}${esc(p.reason)}</span>` : ''}</span>
    <span class="row"><button type="button" class="small" data-ok="${esc(p.id)}" ${busy ? 'disabled' : ''}>${esc(tt('Approve', 'موافقة'))}</button><button type="button" class="ghost small" data-no="${esc(p.id)}" ${busy ? 'disabled' : ''}>${esc(tt('Not now', 'ليس الآن'))}</button></span></div>`;
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Approvals', 'الموافقات'))}</h1><p>${esc(auto ? tt('Auto mode: changes inside your limits run by themselves; nothing waits.', 'الوضع التلقائي: التغييرات ضمن حدودك تُنفَّذ وحدها.') : tt('Practice mode: what the autopilot would do. Approve one to run it for real — your limits are still checked.', 'وضع التدريب: ما سيفعله الطيار الآلي. وافقي على أي منها لتنفيذه فعلاً — حدودك تُفحص دائماً.'))}</p></div>
  ${!auto && props.length ? `<button type="button" id="all" ${busy ? 'disabled' : ''}>${esc(tt('Approve all', 'الموافقة على الكل'))}</button>` : ''}</div>
  ${occasionsCard()}
  ${props.length && !auto ? '' : readinessCard(st, 'approvals')}
  ${starter ? `<div class="card"><header><h2>${esc(tt('First launch', 'الإطلاق الأول'))}</h2><span class="sub">${esc(st.starter.why || '')}</span></header><p class="small ink2">${esc(tt('Nothing of the app\'s is running in this account, so in Auto it starts this set at once instead of waiting for the weekly batch day.', 'لا يعمل أي إعلان للتطبيق في هذا الحساب، لذا في الوضع التلقائي يبدأ هذه المجموعة فوراً بدل انتظار يوم الدفعة الأسبوعي.'))}</p></div>` : ''}
  <div class="card">${props.length && !auto ? `<div class="list">${props.map(row).join('')}</div>`
    : empty(tt('Nothing needs you', 'لا شيء يحتاجك'), auto ? tt('The autopilot acts inside your limits.', 'يعمل الطيار الآلي ضمن حدودك.') : tt('No proposals right now.', 'لا مقترحات الآن.'))}</div>
  ${st.dropped && st.dropped.length ? `<div class="card"><header><h2>${esc(tt('Ideas it refused itself', 'أفكار رفضها بنفسه'))}</h2></header><div class="list">${st.dropped.map((d) => `<div class="small ink2">${esc(d)}</div>`).join('')}</div></div>` : ''}</div>`;
  const decide = async (id, verb) => {
    const r = await api(`/api/proposals/${encodeURIComponent(id)}/${verb}`, { method: 'POST', body: {} });
    return r;
  };
  const after = async () => { busy = ''; await refreshState(); };
  wireOccasions(el, () => render(S, el));
  el.querySelectorAll('[data-ok]').forEach((b) => { b.onclick = async () => {
    busy = b.dataset.ok; render(S, el);
    try { const r = await decide(b.dataset.ok, 'approve'); toast(tt('Approved: ', 'تمت الموافقة: ') + Object.entries(r.counts || {}).map(([k, v]) => `${k} ${v}`).join(', ')); } catch (e) { toast(e.message); }
    await after();
  }; });
  el.querySelectorAll('[data-no]').forEach((b) => { b.onclick = async () => {
    busy = b.dataset.no; render(S, el);
    try { await decide(b.dataset.no, 'reject'); toast(tt('Set aside. The next plan may suggest it again.', 'وُضع جانباً. قد تقترحه الخطة التالية مجدداً.')); } catch (e) { toast(e.message); }
    await after();
  }; });
  el.querySelector('#all')?.addEventListener('click', async () => {
    busy = 'all'; render(S, el);
    const counts = {};
    for (const p of props) { // in plan order; one already made as another's dependency answers 409 and is skipped
      try { const r = await decide(p.id, 'approve'); for (const [k, v] of Object.entries(r.counts || {})) counts[k] = (counts[k] || 0) + v; } catch (e) { if (e.status !== 409) { toast(e.message); break; } }
    }
    toast(tt('Approved: ', 'تمت الموافقة: ') + (Object.entries(counts).map(([k, v]) => `${k} ${v}`).join(', ') || '0'));
    await after();
  });
}
