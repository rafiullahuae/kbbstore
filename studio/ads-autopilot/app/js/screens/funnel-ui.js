// The funnel (engine /api/funnel) as Campaigns and Audiences show it, and the readiness card (state.readiness) that
// Overview and Approvals show when nothing can run yet — each reason with its one-click fix.
import { tt } from '../i18n.js';
import { esc, aed, num, toast } from '../ui.js';
import { api, getAccount } from '../api.js';
import { refreshState, refreshAccounts } from '../main.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id)));
export async function loadFunnel(force) {
  const k = getAccount();
  if (!force && cache.has(k)) return false;
  cache.set(k, await api('/api/funnel'));
  return true;
}
export const funnelOf = () => cache.get(getAccount());

const ST = { live: ['live', 'يعمل', 'acc'], paused: ['paused', 'متوقف', ''], missing: ['to build', 'للبناء', 'warn'], waiting: ['waiting', 'ينتظر', 'warn'], off: ['off at this budget', 'متوقف بهذه الميزانية', ''] };

export function funnelCards() {
  const f = funnelOf();
  if (!f) return '<div class="card"><div class="sk" style="height:200px"></div></div>';
  return `<div class="card" data-funnel><header><h2>${esc(tt('The funnel and why each setting', 'مسار المبيعات وسبب كل إعداد'))}</h2><span class="sub">${esc(f.tier === 'small' ? tt('small-budget tier', 'شريحة الميزانية الصغيرة') : tt('growth tier', 'شريحة النمو'))} · ${esc(tt('target CPA', 'تكلفة الطلب المستهدفة'))} ${aed(f.target_cpa)} · ${esc(tt('chat ads not used', 'بدون إعلانات محادثة'))}</span></header>
  <div class="grid g2">${(f.pieces || []).map((p) => { const s = ST[p.status] || [p.status, p.status, '']; return `<div class="card" style="background:var(--surface)"><header><h3>${esc(tt(p.name, p.name_ar))}</h3><span class="chip ${s[2]}">${esc(tt(s[0], s[1]))}</span></header>
    <p class="small ink2">${esc(p.purpose)} ${esc(p.why)}</p>
    ${(p.objects || []).length ? `<p class="small">${p.objects.map((o) => `<span class="chip">${esc(o.level)}</span> ${esc(o.name)} · ${esc(o.status)}${o.daily_budget ? ` · ${aed(o.daily_budget)}/${esc(tt('day', 'يوم'))}` : ''}${o.learning_stage ? ` · ${esc(tt('learning', 'التعلّم'))}: ${esc(o.learning_stage)}` : ''}`).join('<br>')}</p>` : ''}
    <table class="small"><tbody>${(p.settings || []).map((x) => `<tr><td><b>${esc(x.key)}</b></td><td>${esc(x.value)}<br><span class="ink2">${esc(x.why)}</span>${x.meta ? `<br><code class="muted">${esc(x.meta)}</code>` : ''}</td></tr>`).join('')}</tbody></table>
    ${(p.suggestions || []).map((s2) => `<p class="small" style="color:var(--warn)">💡 ${esc(s2)}</p>`).join('')}</div>`; }).join('')}</div></div>`;
}

export function audiencesCard() {
  const f = funnelOf();
  if (!f) return '<div class="card"><div class="sk" style="height:160px"></div></div>';
  const rows = f.audiences || [];
  return `<div class="card" data-auds><header><h2>${esc(tt('Custom audiences in this account', 'الجماهير المخصصة في هذا الحساب'))}</h2><span class="sub">${esc(tt('sizes as Meta estimates them; the app\'s own are named KAA:', 'الأحجام كما تقدّرها ميتا'))}</span></header>
    ${rows.length ? `<table><thead><tr><th>${esc(tt('Audience', 'الجمهور'))}</th><th class="n">${esc(tt('Size', 'الحجم'))}</th><th>${esc(tt('Purpose', 'الغرض'))}</th><th>${esc(tt('Used by', 'تستخدمه'))}</th></tr></thead><tbody>
    ${rows.map((a) => `<tr><td><b>${esc(a.name)}</b>${a.subtype ? ` <span class="chip">${esc(a.subtype)}</span>` : ''}${a.ready ? '' : ` <span class="chip warn">${esc(a.delivery_text || tt('not ready', 'غير جاهز'))}</span>`}</td><td class="n">${a.approximate_count_lower_bound > 0 ? `${num(a.approximate_count_lower_bound)}–${num(a.approximate_count_upper_bound)}` : '—'}</td><td class="small">${esc(a.purpose)}</td><td class="small">${(a.used_by || []).map(esc).join('<br>') || '<span class="muted">—</span>'}</td></tr>`).join('')}</tbody></table>`
    : `<p class="muted">${esc(tt('No custom audiences yet: the first launch makes the buyers audience (excluded from prospecting) and the funnel adds the rest.', 'لا جماهير بعد: الإطلاق الأول ينشئ جمهور المشترين.'))}</p>`}</div>`;
}

export function readinessCard(st, where) {
  const rd = (st && st.readiness) || [];
  if (!rd.length) return '';
  return `<div class="card" data-ready style="border-color:var(--warn)"><header><h2>${esc(where === 'approvals' ? tt('Nothing to approve because…', 'لا شيء للموافقة لأن…') : tt('Before it can launch', 'قبل أن يتمكن من الإطلاق'))}</h2></header>
    <div class="list">${rd.map((b) => `<div><span class="chip warn">${esc(tt('Step', 'خطوة'))} ${b.step}</span><span style="flex:1">${esc(tt(b.what, b.what_ar))}</span>${b.fix === 'autolink' ? `<button type="button" class="small" data-autolink>${esc(tt(b.label, 'ربط تلقائي'))}</button>` : `<a class="small" href="${esc(b.fix)}">${esc(b.label)} →</a>`}</div>`).join('')}</div></div>`;
}

export function wireReadiness() {} // kept for callers; one delegated listener below serves every Auto-link button

document.addEventListener('click', async (ev) => {
  const b = ev.target.closest && ev.target.closest('[data-autolink]');
  if (!b || b.disabled) return;
  b.disabled = true;
  const id = b.dataset.autolink || getAccount();
  try {
    const r = await api(`/api/accounts/${encodeURIComponent(id)}/autolink`, { method: 'POST', body: {}, noAccount: true });
    toast(tt('Linked: ', 'تم الربط: ') + Object.entries(r.picked || {}).map(([k, v]) => `${k} ${v}`).join(' · '));
    await refreshAccounts(); await refreshState();
  } catch (e) { toast(e.message); b.disabled = false; }
});
