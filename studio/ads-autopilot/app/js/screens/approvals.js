// Approvals: in Practice mode every proposal of the last plan waits here (the engine's /api/proposals). Approve runs
// THAT proposal for real (plus the campaign / ad set / creative it needs, if not made yet) through every check of
// the executor; Not now sets it aside. Nothing re-plans.
//
// Every card that names products shows them as chips (picker.js): Change products (the picker over the shop; that
// proposal is rebuilt for exactly those and checked again), Pin, and Don't promote this product again (Playbook
// Avoid: every waiting proposal and job that used it is rebuilt). Each has Undo. A card left with no allowed
// product says so and waits for the owner's pick ("choose").
import { tt } from '../i18n.js';
import { esc, aed, pct, toast, empty } from '../ui.js';
import { api } from '../api.js';
import { refreshState } from '../main.js';
import { readinessCard } from './funnel-ui.js';
import { occasionsCard, loadOccasions, wireOccasions } from './occasions-ui.js';
import { productChips, wireChips, pickProducts, undoLast, dropCatalog } from './picker.js';

export const load = () => loadOccasions(false);

let busy = '';
export function render(S, el) {
  const st = S.state;
  const auto = st.autopilot.mode === 'auto';
  const props = st.pending || [];
  const starter = st.starter && st.starter.state === 'practice' && props.some((p) => p.starter);
  const choose = (p) => p.status === 'choose';
  const prodLine = (p) => (p.products && p.products.length) || choose(p) ? `<br>${choose(p) ? `<span class="small pk-wait">${esc(tt(p.note, 'كل منتجات هذا الإعلان مستبعدة — اختاري المنتجات'))}</span>` : ''}${productChips(p.products, { more: p.products_more })}
      <span class="row" style="margin-top:6px"><button type="button" class="${choose(p) ? '' : 'ghost'} small" data-chg="${esc(p.id)}" ${busy ? 'disabled' : ''}>${esc(choose(p) ? tt('Choose products', 'اختاري المنتجات') : tt('Change products', 'تغيير المنتجات'))}</button>
      ${p.note && !choose(p) ? `<span class="small muted">✎ ${esc(p.note === 'products chosen by you' ? tt('products chosen by you', 'منتجات اخترتِها') : p.note)}</span>` : ''}
      ${p.creation ? `<span class="chip warn">${esc(tt('New copy waiting for you in Claude', 'نص جديد بانتظارك في Claude'))}</span>` : ''}</span>` : '';
  const row = (p) => `<div data-prop="${esc(p.id)}"><span class="chip">${esc(p.kind)}</span><span style="flex:1;min-width:0"><b>${esc((p.params && p.params.name) || p.target)}</b>${p.params && p.params.daily_budget_aed ? ` → ${aed(p.params.daily_budget_aed)}/${esc(tt('day', 'يوم'))}` : ''}${p.params && p.params.format ? ` <span class="chip">${esc(p.params.format)}</span>` : ''}<br><span class="ink2">${esc(p.why)}</span> <span class="small muted">· ${esc(tt('confidence', 'الثقة'))} ${pct(p.confidence, 0)}</span>${p.status !== 'shadow' && !choose(p) && p.reason ? `<br><span class="small muted">${esc(tt('Last try: ', 'آخر محاولة: '))}${esc(p.reason)}</span>` : ''}${prodLine(p)}</span>
    <span class="row"><button type="button" class="small" data-ok="${esc(p.id)}" ${busy || choose(p) ? 'disabled' : ''}>${esc(tt('Approve', 'موافقة'))}</button><button type="button" class="ghost small" data-no="${esc(p.id)}" ${busy ? 'disabled' : ''}>${esc(tt('Not now', 'ليس الآن'))}</button></span></div>`;
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Approvals', 'الموافقات'))}</h1><p>${esc(auto ? tt('Auto mode: changes inside your limits run by themselves; nothing waits.', 'الوضع التلقائي: التغييرات ضمن حدودك تُنفَّذ وحدها.') : tt('Practice mode: what the autopilot would do. Approve one to run it for real — your limits are still checked. Not the products you want? Change them, pin one, or never promote one again.', 'وضع التدريب: ما سيفعله الطيار الآلي. وافقي على أي منها لتنفيذه فعلاً — حدودك تُفحص دائماً. لا تريدين هذه المنتجات؟ غيّريها أو ثبّتي منتجاً أو استبعديه نهائياً.'))}</p></div>
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
  const refreshAll = async () => { await loadOccasions(true); await after(); };
  wireOccasions(el, () => render(S, el));
  wireChips(el, refreshAll);
  el.querySelectorAll('[data-chg]').forEach((b) => { b.onclick = async () => {
    const p = props.find((x) => x.id === b.dataset.chg); if (!p) return;
    const ps = p.kind === 'create_product_set';
    const ids = await pickProducts({ title: tt(`Products for: ${(p.params && p.params.name) || p.target}`, `منتجات: ${(p.params && p.params.name) || p.target}`), selected: (p.products || []).map((x) => x.id), max: 10,
      hint: ps ? tt('The catalog ads show only these products.', 'إعلانات الكتالوج تعرض هذه المنتجات فقط.') : tt('One ad per product (a carousel keeps one card per product). The copy is rebuilt and every check runs again.', 'إعلان لكل منتج (الدوّار بطاقة لكل منتج). يُعاد بناء النص وتُعاد كل الفحوص.') });
    if (!ids) return;
    busy = p.id; render(S, el);
    try {
      const r = await api(`/api/proposals/${encodeURIComponent(p.id)}/products`, { method: 'POST', body: { product_ids: ids } });
      dropCatalog();
      await after();
      toast(r.creation ? tt('Rebuilt with your products and checked again. Better copy is waiting for you in Claude.', 'أُعيد البناء بمنتجاتك وفُحص مجدداً. نص أفضل بانتظارك في Claude.') : tt('Rebuilt with your products and checked again.', 'أُعيد البناء بمنتجاتك وفُحص مجدداً.'), () => undoLast(after));
    } catch (e) { toast(e.message); await after(); }
  }; });
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
    for (const p of props.filter((x) => !choose(x))) { // in plan order; one already made as another's dependency answers 409 and is skipped
      try { const r = await decide(p.id, 'approve'); for (const [k, v] of Object.entries(r.counts || {})) counts[k] = (counts[k] || 0) + v; } catch (e) { if (e.status !== 409) { toast(e.message); break; } }
    }
    toast(tt('Approved: ', 'تمت الموافقة: ') + (Object.entries(counts).map(([k, v]) => `${k} ${v}`).join(', ') || '0'));
    await after();
  });
}
