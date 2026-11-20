import { tt } from '../i18n.js';
import { esc, aed, num, pct, tip, empty, toast } from '../ui.js';
import { api, getAccount } from '../api.js';

let scope = 'account';
const learn = new Map(); // account → /api/brain/x/learning
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => learn.delete(id)));
const data = new Map();
export function render(S, el) {
  const key = scope === 'account' ? getAccount() : 'portfolio';
  const p = data.get(key);
  if (!p) { el.innerHTML = '<div class="page"><div class="sk" style="height:42px"></div><div class="grid g3"><div class="sk" style="height:220px"></div><div class="sk" style="height:220px"></div><div class="sk" style="height:220px"></div></div></div>'; return; }
  const b = p.baselines || {};
  const sl = (title, rows, help) => `<div class="card"><header><h2>${esc(title)} ${help ? tip(help) : ''}</h2></header>${rows && rows.length ? `<table><thead><tr><th></th><th class="n">${esc(tt('Spend', 'الإنفاق'))}</th><th class="n">${esc(tt('CPA', 'تكلفة الطلب'))}</th><th class="n">${esc(tt('ROAS idx', 'مؤشر العائد'))}</th><th class="n" data-tip="${esc(tt('Probability this cut beats the account\'s own CPA (Bayesian).', 'احتمال أن يتفوق هذا الجزء على متوسط تكلفة الطلب.'))}">P(win)</th></tr></thead><tbody>
    ${rows.slice(0, 8).map((r) => `<tr><td>${esc(r.key)}</td><td class="n">${aed(r.spend)}</td><td class="n">${aed(r.cpa)}</td><td class="n" style="color:${r.roas_index >= 1.1 ? 'var(--good)' : r.roas_index && r.roas_index < 0.9 ? 'var(--bad)' : 'inherit'}">${num(r.roas_index, 2)}</td><td class="n">${pct(r.p_beats_baseline, 0)}</td></tr>`).join('')}</tbody></table>` : `<p class="muted">${esc(tt('No data for this cut yet.', 'لا بيانات بعد.'))}</p>`}</div>`;
  el.innerHTML = `<div class="page">
  <div class="spread"><div class="seg"><button type="button" data-s="account" class="${scope === 'account' ? 'on' : ''}">${esc(tt('This account', 'هذا الحساب'))}</button><button type="button" data-s="portfolio" class="${scope === 'portfolio' ? 'on' : ''}">${esc(tt('Whole portfolio', 'المحفظة كاملة'))}</button></div>
  <span class="muted small">${esc(tt('Trained on', 'تعلّم من'))} ${p.days || 0} ${esc(tt('days of history', 'يوماً من السجل'))}${p.first_day ? ` (${esc(p.first_day)} → ${esc(p.last_day)})` : ''} · ${esc(tt('rebuilt after every sync', 'يُعاد بناؤه بعد كل مزامنة'))}</span></div>
  ${(p.days || 0) === 0 && !(p.winners || []).length ? `<div class="card">${empty(tt('Nothing learned yet', 'لم يتعلّم شيئاً بعد'), tt('After the first sync it reads the account\'s full history (up to 37 months) and shows what wins here, with evidence.', 'بعد أول مزامنة يقرأ سجل الحساب كاملاً (حتى ٣٧ شهراً) ويعرض ما ينجح هنا مع الأدلة.'))}</div>` : ''}
  <section class="kpis" style="grid-template-columns:repeat(5,minmax(0,1fr))">
    ${['30d', '90d', '365d', 'all'].map((w) => `<div class="kpi"><span class="l">${esc(tt('Baseline', 'المعدل'))} ${esc(w)}</span><span class="v num">${aed((b[w] || {}).cpa)}</span><span class="d">${esc(tt('CPA', 'تكلفة الطلب'))} · ROAS ${num((b[w] || {}).roas, 2)} · CTR ${pct((b[w] || {}).ctr)}</span></div>`).join('')}
    <div class="kpi"><span class="l">${esc(tt('Creative half-life', 'عمر الإعلان'))} ${tip(tt('Days until an ad\'s CTR typically falls 30% below its first week. New creative is planned before this.', 'الأيام حتى تنخفض نسبة النقر ٣٠٪ عن أسبوعها الأول.'))}</span><span class="v num">${p.creative_half_life_days ? num(p.creative_half_life_days) + ' ' + esc(tt('days', 'يوماً')) : '—'}</span><span class="d">${(p.fatigue_curve || []).map((x) => num(x, 2)).join(' → ')}</span></div>
  </section>
  ${scope === 'account' ? learnSection(learn.get(getAccount())) : ''}
  <div class="grid g2">
    <div class="card"><header><h2>${esc(tt('What wins here', 'ما ينجح هنا'))}</h2></header><div class="list">${(p.winners || []).map((w) => `<div>✓ <span>${esc(w)}</span></div>`).join('') || `<div class="muted">${esc(tt('No credible winner yet.', 'لا رابح مؤكد بعد.'))}</div>`}</div></div>
    <div class="card"><header><h2>${esc(tt('Evidence & warnings', 'أدلة وتحذيرات'))}</h2></header><div class="list">${(p.evidence || []).map((w) => `<div>• <span>${esc(w)}</span></div>`).join('') || `<div class="muted">—</div>`}</div></div>
  </div>
  <div class="grid g3">
    ${sl(tt('Language', 'اللغة'), p.by_lang)}${sl(tt('Angle', 'الزاوية'), p.by_angle)}${sl(tt('Design', 'التصميم'), p.by_template)}
    ${sl(tt('Format', 'الشكل'), p.by_format)}${sl(tt('Placement', 'مكان الظهور'), p.by_placement, tt('From Meta\'s placement breakdown.', 'من تقسيم ميتا لأماكن الظهور.'))}${sl(tt('Season', 'الموسم'), p.by_season)}
    ${sl(tt('Weekday', 'يوم الأسبوع'), p.by_weekday)}${p.by_country ? sl(tt('Country', 'الدولة'), p.by_country) : ''}${p.by_objective ? sl(tt('Objective', 'الهدف'), p.by_objective) : ''}${p.by_audience ? sl(tt('Audience', 'الجمهور'), p.by_audience) : ''}
  </div></div>`;
  el.querySelectorAll('[data-s]').forEach((x) => { x.onclick = () => { scope = x.dataset.s; render(S, el); load(S).then((c) => c && render(S, el)); }; });
}
export async function load() {
  const key = scope === 'account' ? getAccount() : 'portfolio';
  if (scope === 'account' && !learn.has(key)) { learn.set(key, await api('/api/brain/x/learning').catch(() => null)); if (data.has(key)) return location.hash.startsWith('#/learned'); }
  if (data.has(key)) return false;
  if (scope === 'account') data.set(key, (await api('/api/brain/x/profile')) || {});
  else data.set(key, fromLearnings(await api('/api/learnings', { noAccount: true }).catch(() => [])));
  return location.hash.startsWith('#/learned');
}

// the engine's portfolio learnings (all accounts, long history) as profile cuts
function fromLearnings(ls) {
  const by = {};
  let spend = 0, rev = 0, purch = 0, from = '', to = '';
  for (const l of ls || []) {
    by[l.dimension] = by[l.dimension] || {};
    const x = (by[l.dimension][l.key] = by[l.dimension][l.key] || { key: l.key, spend: 0, purchases: 0, revenue: 0, ads: 0 });
    x.spend += l.spend; x.purchases += l.purchases; x.revenue += l.revenue; x.ads += l.ads;
    if (l.dimension === 'objective') { spend += l.spend; rev += l.revenue; purch += l.purchases; }
    if (!from || l.from < from) from = l.from;
    if (l.to > to) to = l.to;
  }
  const roas = spend ? rev / spend : 0;
  const cut = (d) => Object.values(by[d] || {}).map((x) => Object.assign(x, { cpa: x.purchases ? x.spend / x.purchases : 0, roas_index: roas && x.spend ? x.revenue / x.spend / roas : 0 })).sort((a, b) => b.spend - a.spend);
  const days = from && to ? Math.round((Date.parse(to) - Date.parse(from)) / 864e5) : 0;
  return { days, first_day: from, last_day: to, baselines: { all: { cpa: purch ? spend / purch : 0, roas, ctr: 0 } },
    by_format: cut('format'), by_angle: cut('angle'), by_country: cut('country'), by_objective: cut('objective'), by_audience: cut('audience'), winners: [], evidence: [] };
}

// The self-learning loop (engine brain/learn.go): what it learned and changed this week, whether its predictions are
// getting better, the lessons it plans with, and the thresholds it tuned (with undo).
function learnSection(l) {
  if (!l) return '';
  const w = (l.weekly || []).slice(-1)[0];
  const h = l.health || [];
  const maxE = Math.max(0.01, ...h.map((x) => x.mean_abs_log_error));
  return `<div class="grid g21" data-learning><div class="card"><header><h2>${esc(tt('What I learned and changed', 'ما تعلّمته وما غيّرته'))}</h2><span class="sub">${esc(w ? w.week : '')}</span></header>
    ${w ? `<div class="bi"><pre class="report">${esc(w.en)}</pre><pre class="report rtl" dir="rtl" lang="ar">${esc(w.ar)}</pre></div>` : `<p class="muted">${esc(tt('The first weekly report comes after the first week of finished tests (each test is judged 9 days after it starts).', 'أول تقرير أسبوعي بعد أسبوع من الاختبارات المكتملة.'))}</p>`}
    ${(l.lessons || []).length ? `<h3>${esc(tt('Lessons it plans with', 'الدروس التي يخطط بها'))}</h3><div class="list">${l.lessons.slice(0, 8).map((x) => `<div><span class="chip ${x.kind === 'win' ? 'acc' : 'warn'}">${esc(x.kind)}</span><span class="small">${esc(tt(x.text, x.text_ar))}</span></div>`).join('')}</div>` : ''}</div>
  <div class="card"><header><h2>${esc(tt('Is it getting better?', 'هل يتحسّن؟'))} ${tip(tt('Each launch is recorded with the cost per order it expected; 9 days later it is scored against the real one. Lower error = better predictions.', 'كل إطلاق يُسجّل بتوقعه ثم يُقارن بالنتيجة بعد ٩ أيام.'))}</h2></header>
    ${h.length ? `<div class="list">${h.slice(-8).map((x) => `<div class="small"><span style="width:70px">${esc(x.week)}</span><span class="bar-s" style="flex:1"><i style="width:${Math.round((x.mean_abs_log_error / maxE) * 100)}%"></i></span><span>${num((Math.exp(x.mean_abs_log_error) - 1) * 100)}% · ${num(x.scored)} ${esc(tt('tests', 'اختبارات'))}</span></div>`).join('')}</div>` : `<p class="muted small">${esc(tt('No finished tests yet.', 'لا اختبارات مكتملة بعد.'))}</p>`}
    <p class="small">${esc(tt('Open predictions', 'توقعات مفتوحة'))}: <b>${num(l.open)}</b> · ${esc(tt('scored', 'مقيّمة'))}: <b>${num(l.scored)}</b> · ${esc(tt('cells learned', 'خلايا متعلّمة'))}: <b>${num(l.cells)}</b></p>
    <h3>${esc(tt('Its own thresholds', 'حدوده الذاتية'))}</h3>
    <p class="small">${esc(tt('Mode', 'الوضع'))}: <b>${esc(l.self_tune)}</b>${l.tuned && l.tuned.hard_stop_mult ? ` · ${esc(tt('stop after', 'الإيقاف بعد'))} <b>${num(l.tuned.hard_stop_mult, 1)}×</b> ${esc(l.tuned.applied ? tt('(applied)', '(مطبّق)') : tt('(proposed)', '(مقترح)'))}` : ''}</p>
    <div class="list">${(l.changes || []).slice(-5).reverse().map((c) => `<div class="small ink2">${esc(c)}</div>`).join('') || `<div class="small muted">${esc(tt('No changes yet.', 'لا تغييرات بعد.'))}</div>`}</div>
    <div class="row">${l.tuned && l.tuned.hard_stop_mult && !l.tuned.applied ? `<button type="button" class="small" data-lapply>${esc(tt('Apply the proposal', 'تطبيق المقترح'))}</button>` : ''}${l.tuned && l.tuned.hard_stop_mult ? `<button type="button" class="ghost small" data-lreset>${esc(tt('Undo its tuning', 'التراجع عن ضبطه'))}</button>` : ''}</div></div></div>`;
}

document.addEventListener('click', async (ev) => {
  const a = ev.target.closest && ev.target.closest('[data-lapply],[data-lreset]');
  if (!a) return;
  const path = a.hasAttribute('data-lapply') ? 'learning/apply-proposal' : 'learning/reset-tuning';
  try { learn.set(getAccount(), await api('/api/brain/x/' + path, { method: 'POST', body: {} })); location.hash = '#/learned'; window.dispatchEvent(new HashChangeEvent('hashchange')); } catch (e) { toast(e.message); }
});
