import { t, tt } from '../i18n.js';
import { esc, aed, tip, empty } from '../ui.js';
import { jobsCard, wireJobs, loadJobs } from './jobs-ui.js';
import { dailyPlanCard, wireToday, loadToday } from './today-ui.js';

export const load = async (S) => { const a = await loadJobs(false); const b = await loadToday(S); return a || b; };

const FORMAT = { single_image: 'Single image', single_video: 'Single video', reels_stories: 'Reels & Stories', carousel: 'Carousel', collection: 'Collection', catalog: 'Advantage+ catalog', flexible: 'Flexible format', click_to_whatsapp: 'Click to WhatsApp', click_to_messenger: 'Click to Messenger', whatsapp_status: 'WhatsApp Status', partnership: 'Partnership' };
const AUD = { women_gcc_ar: 'Arabic-speaking women 18-45, GCC', women_uae_en: 'English-speaking women 18-45, UAE', broad: 'Broad (Advantage+)', retarget_viewers: 'Product viewers 14d', retarget_cart: 'Cart abandoners 7d', retarget_engagers: 'Video viewers & engagers', lookalike_buyers: 'Lookalike of buyers', existing_customers: 'Existing customers' };

// Plan, in three tabs (the owner, 23 Nov: "The plan page is super confusing. There should be two tabs, Upcoming /
// Already Launched (which actually run in meta), and proper date wise"):
//   Upcoming          every job still to run, by day, with its time (jobs-ui.js), and today's plan
//   Launched on Meta  everything the app really made on Meta (engine live.go Launched), newest day first, each with its
//                     time, what it is, the run it came from, its Meta id and its status on Meta now
//   Weekly plan       the strategy pages that were here before
let tab = 'upcoming';
export function render(S, el) {
  try { const t0 = sessionStorage.getItem('kaa.plantab'); if (t0) { tab = t0; sessionStorage.removeItem('kaa.plantab'); } } catch {}
  const launched = (((S.state || {}).raw || {}).launched || []);
  const tabs = `<div class="tabs" data-plantabs style="margin-bottom:12px">${[['upcoming', tt('Upcoming', 'القادم')], ['launched', tt(`Launched on Meta (${launched.length})`, `أُطلق على Meta (${launched.length})`)], ['weekly', tt('Weekly plan', 'الخطة الأسبوعية')]].map(([k, l]) => `<button type="button" data-ptab="${k}" class="${tab === k ? 'on' : ''}">${esc(l)}</button>`).join('')}</div>`;
  if (tab === 'weekly') inner(S, el);
  else el.innerHTML = '<div class="page"></div>';
  const pg = el.querySelector('.page');
  if (!pg) return;
  if (tab === 'upcoming') {
    pg.insertAdjacentHTML('afterbegin', jobsCard(S)); wireJobs(S, el, () => render(S, el));
    pg.insertAdjacentHTML('afterbegin', dailyPlanCard(S)); wireToday(S, el, () => render(S, el));
  } else if (tab === 'launched') pg.insertAdjacentHTML('afterbegin', launchedCard(S, launched));
  pg.insertAdjacentHTML('afterbegin', `<div class="page-h"><div><h1>${esc(tt('Plan', 'الخطة'))}</h1></div></div>${tabs}`);
  pg.querySelectorAll('[data-ptab]').forEach((b) => { b.onclick = () => { tab = b.dataset.ptab; render(S, el); }; });
}

const KINDL = { create_campaign: ['Campaign', 'حملة'], create_adset: ['Ad set', 'مجموعة'], create_ad: ['Ad', 'إعلان'] };
function statusOn(S, id) {
  for (const c of (((S.state || {}).raw || {}).campaigns || [])) {
    if (c.id === id) return c.status;
    for (const s of c.adsets || []) {
      if (s.id === id) return s.status;
      for (const a of s.ads || []) if (a.id === id) return a.effective_status || a.status;
    }
  }
  return '';
}
function launchedCard(S, items) {
  if (!items.length) return `<div class="card">${empty(tt('Nothing launched yet', 'لم يُطلق شيء بعد'), tt('What the autopilot makes on Meta (campaigns, ad sets, ads) shows here by day, with its time and its status on Meta.', 'ما يصنعه الطيار الآلي على Meta يظهر هنا حسب اليوم.'))}</div>`;
  const xs = [...items].sort((a, b) => new Date(b.at) - new Date(a.at));
  const key = (t) => new Date(t).toDateString();
  const day = (t) => { const d = new Date(t), now = new Date(), y = new Date(now); y.setDate(now.getDate() - 1); const s = d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); return key(d) === key(now) ? tt(`Today · ${s}`, `اليوم · ${s}`) : key(d) === key(y) ? tt(`Yesterday · ${s}`, `أمس · ${s}`) : s; };
  const days = [...new Set(xs.map((x) => key(x.at)))];
  const ads = xs.filter((x) => x.kind === 'create_ad').length, camps = xs.filter((x) => x.kind === 'create_campaign').length;
  return `<div class="card" data-launched><header><h2>${esc(tt('Launched on Meta', 'ما أُطلق على Meta'))}</h2><span class="sub">${esc(tt(`${camps} campaign(s), ${ads} ad(s) made by the autopilot · status = on Meta now`, `${camps} حملة، ${ads} إعلان · الحالة الآن على Meta`))}</span></header>
    ${days.map((k) => { const ds = xs.filter((x) => key(x.at) === k);
      return `<h3 data-lday style="margin:14px 0 4px;font-size:14px">${esc(day(ds[0].at))} <span class="small muted">· ${esc(tt(`${ds.filter((x) => x.kind === 'create_ad').length} ad(s)`, `${ds.filter((x) => x.kind === 'create_ad').length} إعلان`))}</span></h3>
        <div class="list">${ds.map((x) => { const st = statusOn(S, x.meta_id);
          return `<div data-litem><span class="chip">${esc(new Date(x.at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }))}</span><span style="flex:1;min-width:0"><span class="chip">${esc(tt(...(KINDL[x.kind] || [x.kind, x.kind])))}</span> <b dir="auto">${esc(x.title)}</b><br><span class="small muted">${esc(tt('from', 'من'))}: ${esc(x.run || '—')} · Meta id ${esc(x.meta_id)}</span></span><span class="chip ${/ACTIVE/.test(st) ? 'acc' : /PAUSED|ARCHIVED|DELETED|DISAPPROVED|WITH_ISSUES/.test(st) ? 'warn' : ''}" title="${esc(tt('Status on Meta now', 'الحالة على Meta الآن'))}">${esc(st ? st.replace(/_/g, ' ').toLowerCase() : tt('in review / not read yet', 'قيد المراجعة'))}</span></div>`; }).join('')}</div>`; }).join('')}</div>`;
}

function inner(S, el) {
  const st = S.state;
  const p = st.plan;
  if (!p && st.engine_plan && (st.engine_plan.steps || []).length) {
    el.innerHTML = `<div class="page"><div class="card"><header><h2>${esc(tt('Plan', 'الخطة'))}</h2></header><p>${esc(st.engine_plan.summary || '')}</p><div class="list">${st.engine_plan.steps.map((s) => `<div><span class="chip">${esc(s.when)}</span><span><b>${esc(s.title)}</b><br><span class="small ink2">${esc(s.detail || '')}</span></span></div>`).join('')}</div></div></div>`;
    return;
  }
  if (!p) { el.innerHTML = `<div class="page"><div class="card">${empty(tt('No plan yet', 'لا توجد خطة بعد'), tt('Run a cycle to build this week\'s plan.', 'شغّلي دورة لبناء خطة هذا الأسبوع.'))}</div></div>`; return; }
  const rep = (st.report && st.report.text) || '';
  const [en, ar] = rep.split(/\n\s*\nAR\n/);
  el.innerHTML = `<div class="page">
  <div class="card"><header><h2>${esc(tt('Plan for', 'خطة'))} ${esc(p.week)}</h2><span class="muted small">${esc(new Date(p.generated_at).toLocaleString())}</span></header>
    <div class="bi"><p>${esc(p.summary)}</p><p class="rtl" dir="rtl" lang="ar">${esc(p.summary_ar)}</p></div></div>
  <div class="grid g21">
    <div class="card"><header><h2>${esc(t("This week's tests"))} ${tip(tt('Each test is a genuinely different idea (persona, angle, format). New ads go out together on the weekly batch day so Meta re-learns once, not every day.', 'كل اختبار فكرة مختلفة فعلاً. تُطلق الإعلانات الجديدة معاً في يوم الدفعة الأسبوعي.'))}</h2></header>
      <table><thead><tr><th>${esc(tt('Test', 'الاختبار'))}</th><th>${esc(tt('Lang', 'اللغة'))}</th><th>${esc(tt('Format', 'الشكل'))}</th><th>${esc(tt('Audience', 'الجمهور'))}</th><th class="n">${esc(tt('AED/day', 'درهم/يوم'))}</th><th>${esc(t('Status'))}</th></tr></thead><tbody>
      ${(p.tests || []).map((x) => `<tr><td><b>${esc(x.name)}</b><br><span class="small ink2">${esc(x.hypothesis)}</span><br><span class="chip">${esc(x.angle)}</span> <span class="chip">${esc(x.template)}</span></td><td>${esc((x.lang || '').toUpperCase())}</td><td>${esc(FORMAT[x.format] || x.format || 'Single image')}</td><td class="small">${esc(AUD[x.audience] || x.audience || AUD.women_gcc_ar)}</td><td class="n">${x.budget_aed ? aed(x.budget_aed) : '—'}</td><td><span class="chip">${esc(x.status)}</span></td></tr>`).join('')}
      </tbody></table></div>
    <div class="card"><header><h2>${esc(t('Budget split'))}</h2></header>
      ${(p.allocation || []).map((a) => `<div style="margin-bottom:10px"><div class="spread small"><b>${esc(a.bucket)}</b><span class="num">${Math.round(a.share_pct)}% · ${aed(a.daily_aed)}/${esc(tt('day', 'يوم'))}</span></div><div class="bar-s"><i style="width:${Math.max(0, Math.min(100, a.share_pct))}%"></i></div><span class="small muted">${esc(a.why)}</span></div>`).join('')}
      <h3 style="margin-top:12px">${esc(tt('Audience & cost playbook', 'خطة الجمهور والتكلفة'))}</h3>
      <ul class="small ink2" style="padding-inline-start:18px;margin:6px 0">
        <li>${esc(tt('Advantage+ audience with your age and gender as suggestions; recent buyers excluded (Strategy → Your playbook).', 'جمهور Advantage+ مع العمر والجنس كاقتراحات؛ استبعاد المشترين الجدد.'))}</li>
        <li>${esc(tt('Reels and Stories first (9:16), Advantage+ placements on, 4:5 for feed.', 'الريلز والستوريز أولاً (٩:١٦) مع تفعيل أماكن الظهور التلقائية و٤:٥ للخلاصة.'))}</li>
        <li>${esc(tt('Creative-first targeting: many distinct creatives instead of narrow interests.', 'الاستهداف بالإبداع: إعلانات متنوعة بدل اهتمامات ضيقة.'))}</li>
        <li>${esc(tt('Frequency kept under ~2.5/week for prospecting; fatigued ads replaced.', 'تكرار أقل من ٢٫٥ أسبوعياً؛ استبدال الإعلانات المرهقة.'))}</li></ul></div>
  </div>
  <div class="grid g2">
    <div class="card"><header><h2>${esc(t('Seasonal pushes'))}</h2><span class="muted small">${esc(tt('next 60 days', 'خلال ٦٠ يوماً'))}</span></header>
      <div class="list">${(p.seasonal || []).map((s) => `<div><span class="chip">${esc(s.start.slice(5))} → ${esc(s.end.slice(5))}</span><span><b>${esc(s.name)}</b> <span class="rtl" dir="rtl" lang="ar">${esc(s.name_ar)}</span>${s.approx ? ` <span class="muted small">(${esc(tt('approx.', 'تقريبي'))})</span>` : ''}<br><span class="small ink2">${esc(tt('Creative ready by', 'الإعلانات جاهزة قبل'))} ${esc(s.prep_from)} · ${esc(s.action)}</span></span></div>`).join('') || `<div class="muted">${esc(tt('No seasonal moment in the next 60 days.', 'لا مواسم خلال ٦٠ يوماً.'))}</div>`}</div></div>
    <div class="card"><header><h2>${esc(tt('Watch list', 'قائمة المتابعة'))}</h2></header><div class="list">${(p.watch || []).map((w) => `<div>• <span>${esc(w)}</span></div>`).join('') || `<div class="muted">—</div>`}</div></div>
  </div>
  <div class="card"><header><h2>${esc(t('Daily report'))}</h2><span class="muted small">${st.report ? esc(new Date(st.report.at).toLocaleString()) : ''}</span></header>
    <div class="bi"><pre class="report">${esc((en || '').replace(/^EN\n/, ''))}</pre><pre class="report rtl" dir="rtl" lang="ar">${esc(ar || '')}</pre></div></div>
  </div>`;
}
