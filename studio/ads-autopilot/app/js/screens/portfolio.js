import { t, tt } from '../i18n.js';
import { esc, aed, num, pct, tip, toast } from '../ui.js';
import { api } from '../api.js';
import { switchAccount } from '../main.js';

let data = null;
export function render(S, el) {
  const rows = (data && data.accounts) || S.accounts;
  const p = data && data.profile;
  const tot = rows.reduce((a, r) => ({ spend: a.spend + r.d7.spend, purchases: a.purchases + r.d7.purchases, rev: a.rev + r.d7.spend * r.d7.roas }), { spend: 0, purchases: 0, rev: 0 });
  el.innerHTML = `<div class="page">
  <section class="kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="kpi"><span class="l">${esc(t('Accounts'))}</span><span class="v">${rows.length}</span><span class="d">${rows.filter((r) => r.automate).length} ${esc(tt('automated', 'مؤتمتة'))}</span></div>
    <div class="kpi"><span class="l">${esc(t('Spend'))} · 7d</span><span class="v num">${aed(tot.spend)}</span><span class="d">${esc(tt('all accounts', 'كل الحسابات'))}</span></div>
    <div class="kpi"><span class="l">${esc(t('ROAS'))} · 7d</span><span class="v num">${num(tot.spend ? tot.rev / tot.spend : 0, 2)}</span><span class="d">&nbsp;</span></div>
    <div class="kpi"><span class="l">${esc(t('CPA'))} · 7d</span><span class="v num">${aed(tot.purchases ? tot.spend / tot.purchases : 0)}</span><span class="d">&nbsp;</span></div>
  </section>
  <div class="card"><header><h2>${esc(tt('Ad accounts in the business portfolio', 'الحسابات الإعلانية في محفظة الأعمال'))} ${tip(tt('Every account the Meta business portfolio gives access to. Each one has its own limits, mode, plan and log; the autopilot only acts in accounts you automate.', 'كل حساب تتيحه محفظة ميتا. لكل حساب حدوده ووضعه وخطته وسجله؛ ولا يعمل الطيار الآلي إلا في الحسابات التي تؤتمتينها.'))}</h2></header>
  <table><thead><tr><th>${esc(t('Account'))}</th><th>${esc(t('Status'))}</th><th>${esc(t('Mode'))}</th><th class="n">${esc(t('Spend'))} 7d</th><th class="n">${esc(t('ROAS'))}</th><th class="n">${esc(t('CPA'))}</th><th class="n">${esc(t('CPC'))}</th><th class="n">${esc(t('CTR'))}</th><th class="n">${esc(t('Alerts'))}</th><th class="n">${esc(t('History'))}</th><th>${esc(t('Automate'))}</th><th></th></tr></thead><tbody>
  ${rows.map((r) => `<tr><td><b>${esc(r.name)}</b><br><span class="muted small">${esc(r.id)} · ${esc(r.currency)}</span></td><td><span class="dot ${r.status === 'ACTIVE' ? 'ok' : 'bad'}" style="display:inline-block"></span> ${esc(r.status)}</td>
    <td><span class="pill ${esc(r.mode)}">${esc(t(r.mode))}</span>${r.limits_set ? '' : `<br><span class="small muted">${esc(tt('no limits', 'بلا حدود'))}</span>`}</td>
    <td class="n">${aed(r.d7.spend)}</td><td class="n">${num(r.d7.roas, 2)}</td><td class="n">${aed(r.d7.cpa)}</td><td class="n">${aed(r.d7.cpc, 2)}</td><td class="n">${pct(r.d7.ctr)}</td><td class="n">${r.alerts || '—'}</td>
    <td class="n">${r.history_days ? r.history_days + ' ' + esc(tt('days', 'يوماً')) : '—'}</td>
    <td><input type="checkbox" data-auto="${esc(r.id)}" ${r.automate ? 'checked' : ''} ${r.status !== 'ACTIVE' ? 'disabled' : ''} aria-label="${esc(t('Automate'))} ${esc(r.name)}"></td>
    <td><button class="ghost small" data-open="${esc(r.id)}">${esc(tt('Open', 'فتح'))}</button></td></tr>`).join('')}
  </tbody></table></div>
  ${p ? `<div class="card"><header><h2>${esc(tt('What the portfolio taught it', 'ما تعلّمه من المحفظة كلها'))}</h2><a href="#/learned">${esc(tt('Details', 'التفاصيل'))} →</a></header>
    <div class="list">${(p.winners || []).slice(0, 6).map((w) => `<div>✓ <span>${esc(w)}</span></div>`).join('') || `<div class="muted">${esc(tt('Not enough history yet.', 'لا يوجد سجل كافٍ بعد.'))}</div>`}</div></div>` : ''}
  </div>`;
  el.querySelectorAll('[data-open]').forEach((b) => { b.onclick = () => { switchAccount(b.dataset.open); location.hash = '#/dashboard'; }; });
  el.querySelectorAll('[data-auto]').forEach((c) => {
    c.onchange = async () => {
      const id = c.dataset.auto, on = c.checked;
      try {
        await api(`/api/accounts/${id}`, { method: 'PUT', body: { automate: on }, noAccount: true });
        data = null;
        toast(on ? tt('Account will be automated.', 'سيتم أتمتة الحساب.') : tt('Account no longer automated.', 'توقفت أتمتة الحساب.'), async () => { await api(`/api/accounts/${id}`, { method: 'PUT', body: { automate: !on }, noAccount: true }); data = null; c.checked = !on; });
      } catch (e) { c.checked = !on; toast(e.message); }
    };
  });
}
export async function load() {
  if (data) return false;
  data = await api('/api/portfolio', { noAccount: true });
  return location.hash.startsWith('#/portfolio');
}
