import { t, tt } from '../i18n.js';
import { esc, aed, tip, empty } from '../ui.js';

const FORMAT = { single_image: 'Single image', single_video: 'Single video', reels_stories: 'Reels & Stories', carousel: 'Carousel', collection: 'Collection', catalog: 'Advantage+ catalog', flexible: 'Flexible format', click_to_whatsapp: 'Click to WhatsApp', click_to_messenger: 'Click to Messenger', whatsapp_status: 'WhatsApp Status', partnership: 'Partnership' };
const AUD = { women_gcc_ar: 'Arabic-speaking women 18-45, GCC', women_uae_en: 'English-speaking women 18-45, UAE', broad: 'Broad (Advantage+)', retarget_viewers: 'Product viewers 14d', retarget_cart: 'Cart abandoners 7d', retarget_engagers: 'Video viewers & engagers', lookalike_buyers: 'Lookalike of buyers', existing_customers: 'Existing customers' };

export function render(S, el) {
  const st = S.state;
  const p = st.plan;
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
        <li>${esc(tt('Advantage+ audience with suggestions: women 18-45, Arabic, GCC, skincare & K-beauty interests.', 'جمهور Advantage+ مع اقتراحات: نساء ١٨-٤٥، العربية، الخليج، اهتمامات العناية والجمال الكوري.'))}</li>
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
