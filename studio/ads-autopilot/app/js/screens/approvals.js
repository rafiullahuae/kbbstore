// Approvals: in Practice mode every proposal waits here; Approve runs this
// cycle for real (the executor still checks every limit); Not now hides it.
import { tt } from '../i18n.js';
import { esc, aed, pct, toast, empty } from '../ui.js';
import { api, getAccount } from '../api.js';
import { refreshState } from '../main.js';

const hidden = new Set();
export function render(S, el) {
  const st = S.state;
  const auto = st.autopilot.mode === 'auto';
  const props = (st.proposals || []).filter((p) => !hidden.has(getAccount() + p.id));
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Approvals', 'الموافقات'))}</h1><p>${esc(auto ? tt('Auto mode: changes inside your limits run by themselves; nothing waits.', 'الوضع التلقائي: التغييرات ضمن حدودك تُنفَّذ وحدها.') : tt('Practice mode: what the autopilot would do. Approve to run it for real — your limits are still checked.', 'وضع التدريب: ما سيفعله الطيار الآلي. وافقي لتنفيذه فعلاً — حدودك تُفحص دائماً.'))}</p></div>
  ${!auto && props.length ? `<button type="button" id="all">${esc(tt('Approve all', 'الموافقة على الكل'))}</button>` : ''}</div>
  <div class="card">${props.length && !auto ? `<div class="list">${props.map((p) => `<div><span class="chip">${esc(p.kind)}</span><span style="flex:1"><b>${esc(p.target)}</b>${p.params && p.params.daily_budget_aed ? ` → ${aed(p.params.daily_budget_aed)}/${esc(tt('day', 'يوم'))}` : ''}${p.params && p.params.format ? ` <span class="chip">${esc(p.params.format)}</span>` : ''}<br><span class="ink2">${esc(p.why)}</span> <span class="small muted">· ${esc(tt('confidence', 'الثقة'))} ${pct(p.confidence, 0)}</span></span>
    <span class="row"><button type="button" class="small" data-ok="${esc(p.id)}">${esc(tt('Approve', 'موافقة'))}</button><button type="button" class="ghost small" data-no="${esc(p.id)}">${esc(tt('Not now', 'ليس الآن'))}</button></span></div>`).join('')}</div>`
    : empty(tt('Nothing needs you', 'لا شيء يحتاجك'), auto ? tt('The autopilot acts inside your limits.', 'يعمل الطيار الآلي ضمن حدودك.') : tt('No proposals right now.', 'لا مقترحات الآن.'))}</div>
  ${st.dropped && st.dropped.length ? `<div class="card"><header><h2>${esc(tt('Ideas it refused itself', 'أفكار رفضها بنفسه'))}</h2></header><div class="list">${st.dropped.map((d) => `<div class="small ink2">${esc(d)}</div>`).join('')}</div></div>` : ''}</div>`;
  // The engine runs a whole cycle at a time (no per-proposal endpoint yet): Approve = run this cycle in auto.
  const approve = async () => {
    try { const r = await api('/api/cycle', { method: 'POST', body: { mode: 'auto' } }); toast(tt('Approved: ', 'تمت الموافقة: ') + Object.entries(r.counts || {}).map(([k, v]) => `${k} ${v}`).join(', ')); await refreshState(); } catch (e) { toast(e.message); }
  };
  el.querySelector('#all')?.addEventListener('click', approve);
  el.querySelectorAll('[data-ok]').forEach((b) => { b.onclick = approve; });
  el.querySelectorAll('[data-no]').forEach((b) => { b.onclick = () => { hidden.add(getAccount() + b.dataset.no); render(S, el); toast(tt('Hidden until the next plan.', 'أُخفي حتى الخطة التالية.')); }; });
}
