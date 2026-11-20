import { t, tt, getLang } from '../i18n.js';
import { esc, aed, num, pct, kpi, ago, lineChart, spark, txt, tip } from '../ui.js';

let win = 'd7';
const HELP = {
  Spend: ['Money Meta charged for ads in this period.', 'المبلغ الذي دفعته لميتا مقابل الإعلانات في هذه الفترة.'],
  Orders: ['Purchases Meta attributes to the ads (7-day click / 1-day view). The last 2 days still grow.', 'المشتريات التي تنسبها ميتا للإعلانات. آخر يومين ما زالا يزيدان.'],
  Revenue: ['Value of those purchases as the shop\'s pixel reported it (incl. VAT).', 'قيمة هذه المشتريات كما أرسلها بكسل المتجر (شاملة الضريبة).'],
  ROAS: ['Revenue ÷ spend. Above the break-even ROAS the ads make money.', 'الإيرادات ÷ الإنفاق. فوق نقطة التعادل تربح الإعلانات.'],
  CPA: ['Spend ÷ orders: what one order costs in ads. Lower is better.', 'الإنفاق ÷ الطلبات: تكلفة الطلب الواحد. الأقل أفضل.'],
  CPC: ['Spend ÷ link clicks: the price of one visit to the shop.', 'الإنفاق ÷ النقرات: سعر الزيارة الواحدة للمتجر.'],
  CTR: ['Link clicks ÷ impressions: how attractive the ad is.', 'النقرات ÷ مرات الظهور: مدى جاذبية الإعلان.'],
  CPM: ['Cost of 1,000 impressions: the price of attention.', 'تكلفة ١٠٠٠ ظهور: سعر الانتباه.'],
};
const h = (k) => tt(HELP[k][0], HELP[k][1]);

export function render(S, el) {
  const st = S.state;
  const k = st.kpis[win];
  const tg = st.targets || {};
  const vs = (v, target, lowerBetter) => {
    if (!target || !v) return '';
    const good = lowerBetter ? v <= target : v >= target;
    return `<span class="${good ? 'good' : 'bad'}">${good ? '✓' : '!'} ${esc(t('target'))} ${lowerBetter ? aed(target) : num(target, 2)}</span>`;
  };
  const series = st.series || [];
  const mode = st.autopilot.mode;
  const doing = tt(st.autopilot.doing, st.autopilot.doing_ar) ||
    (mode === 'off' ? tt('The autopilot is off for this account. Set limits and turn it on in Settings → Limits.', 'الطيار الآلي متوقف لهذا الحساب. حدّدي الحدود وشغّليه من الإعدادات ← الحدود.') : '');
  const pc = (st.placements || []).filter((p) => p.spend > 0);
  el.innerHTML = `<div class="page">
  <section class="card hero" aria-label="${esc(t('What it is doing now'))}">
    <div class="ring ${mode === 'auto' || mode === 'shadow' ? 'live' : ''}"><span>${mode === 'auto' ? '▶' : mode === 'shadow' ? '◐' : '■'}</span></div>
    <div><h2>${esc(mode === 'auto' ? tt('Autopilot is running your ads', 'الطيار الآلي يدير إعلاناتك') : mode === 'shadow' ? tt('Autopilot is watching (shadow mode)', 'الطيار الآلي يراقب (وضع المراقبة)') : tt('Autopilot is off', 'الطيار الآلي متوقف'))}</h2>
      <p>${txt(doing)}</p>
      <p class="small muted">${esc(t('Next cycle'))}: ${esc(new Date(st.autopilot.next_cycle_at).toLocaleTimeString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { hour: '2-digit', minute: '2-digit' }))} · ${esc(tt('planned by', 'يخطط بواسطة'))} ${esc(st.autopilot.model || '—')}</p></div>
    <div class="seg" role="tablist">${[['today', 'Today'], ['d7', '7 days'], ['d30', '30 days']].map(([id, l]) => `<button type="button" data-win="${id}" class="${win === id ? 'on' : ''}">${esc(t(l))}</button>`).join('')}</div>
  </section>
  <section class="kpis">
    ${kpi('Spend', aed(k.spend), st.limits_set ? `${esc(tt('limit', 'الحد'))} ${aed(st.limits.daily_aed)}/${esc(tt('day', 'يوم'))}` : esc(tt('no limit set', 'لا يوجد حد')), h('Spend'))}
    ${kpi('Orders', num(k.purchases), '', h('Orders'))}
    ${kpi('Revenue', aed(k.revenue), '', h('Revenue'))}
    ${kpi('ROAS', num(k.roas, 2), vs(k.roas, tg.roas), h('ROAS'))}
    ${kpi('CPA', aed(k.cpa), vs(k.cpa, tg.cpa, true), h('CPA'))}
    ${kpi('CPC', aed(k.cpc, 2), spark(series.map((d) => d.cpc || 0), 'var(--s-spend)'), h('CPC'))}
    ${kpi('CTR', pct(k.ctr), spark(series.map((d) => d.ctr || 0), 'var(--s-rev)'), h('CTR'))}
  </section>
  <section class="grid g21">
    <div class="card"><header><h2>${esc(t('Spend and revenue, 30 days'))}</h2><div class="legend"><span><i style="background:var(--s-spend)"></i>${esc(t('Spend'))}</span><span><i style="background:var(--s-rev)"></i>${esc(t('Revenue'))}</span></div></header>
      ${lineChart(series, ['spend', 'revenue'], ['var(--s-spend)', 'var(--s-rev)'], [t('Spend'), t('Revenue')], (v) => aed(v))}</div>
    <div class="card"><header><h2>${esc(t('Cost trends'))} ${tip(tt('Low CPM and CPC with a high CTR is the goal for women in the GCC. These move every day; the autopilot reacts to 7-day trends, not single days.', 'الهدف: تكلفة ظهور ونقرة منخفضة ونسبة نقر عالية. الطيار الآلي يتفاعل مع اتجاه ٧ أيام لا يوم واحد.'))}</h2></header>
      <table><tbody>
      <tr><td>${esc(t('CPM'))} ${tip(h('CPM'))}</td><td class="n">${aed(k.cpm, 2)}</td><td style="width:45%">${spark(series.map((d) => d.cpm || 0), 'var(--s-spend)')}</td></tr>
      <tr><td>${esc(t('CPC'))}</td><td class="n">${aed(k.cpc, 2)}</td><td>${spark(series.map((d) => d.cpc || 0), 'var(--s-spend)')}</td></tr>
      <tr><td>${esc(t('CTR'))}</td><td class="n">${pct(k.ctr)}</td><td>${spark(series.map((d) => d.ctr || 0), 'var(--s-rev)')}</td></tr>
      </tbody></table>
      <h3 style="margin:14px 0 6px">${esc(t('Placements'))} <span class="muted small">30d</span></h3>
      <table><thead><tr><th></th><th class="n">${esc(t('CPM'))}</th><th class="n">${esc(t('CTR'))}</th><th class="n">${esc(t('CPA'))}</th></tr></thead><tbody>
      ${pc.map((p) => `<tr><td>${esc(p.placement)}</td><td class="n">${aed(p.cpm, 1)}</td><td class="n">${pct(p.ctr)}</td><td class="n">${aed(p.cpa)}</td></tr>`).join('') || `<tr><td colspan="4" class="muted">${esc(tt('No placement data yet.', 'لا توجد بيانات أماكن الظهور بعد.'))}</td></tr>`}
      </tbody></table></div>
  </section>
  <section class="grid g3">
    <div class="card"><header><h2>${esc(t('What it did'))}</h2><span class="muted small">${esc(t('Last 24 hours'))}</span></header>
      <div class="list">${(st.activity || []).slice(0, 7).map((a) => `<div><span class="chip">${esc(a.kind)}</span><span>${txt(a.text)}<br><span class="muted small">${esc(ago(a.at))} · ${esc(a.outcome || '')}</span></span></div>`).join('') || `<div class="muted">${esc(tt('Nothing yet today.', 'لا شيء بعد اليوم.'))}</div>`}</div></div>
    <div class="card"><header><h2>${esc(t('Alerts'))}</h2></header>
      <div class="list">${(st.alerts || []).map((a) => `<div class="alert ${esc(a.level)}">${txt(tt(a.text, a.text_ar))}</div>`).join('') || `<div class="muted">${esc(tt('All clear.', 'لا تنبيهات.'))}</div>`}</div></div>
    <div class="card"><header><h2>${esc(t('Arabic vs English'))} ${tip(tt('Arabic gets most of the tests and budget (your choice); the split moves with results, never below 55% Arabic.', 'تحصل العربية على معظم الاختبارات والميزانية؛ تتغير النسبة حسب النتائج ولا تقل عن ٥٥٪.'))}</h2><span class="muted small">30d</span></header>
      <table><thead><tr><th></th><th class="n">${esc(t('Spend'))}</th><th class="n">${esc(t('ROAS'))}</th><th class="n">${esc(t('CPA'))}</th><th class="n">${esc(t('CTR'))}</th></tr></thead><tbody>
      ${['ar', 'en'].map((l) => { const s = st.split[l] || {}; return `<tr><td>${esc(t(l === 'ar' ? 'Arabic' : 'English'))}</td><td class="n">${aed(s.spend)}</td><td class="n">${num(s.roas, 2)}</td><td class="n">${aed(s.cpa)}</td><td class="n">${pct(s.ctr)}</td></tr>`; }).join('')}
      </tbody></table>
      <p class="small muted">${esc(tt('Break-even', 'نقطة التعادل'))}: ${esc(t('CPA'))} ${aed(tg.breakeven_cpa)} · ${esc(t('ROAS'))} ${num(tg.breakeven_roas, 2)} ${tip(tt('From feed prices, a 45% default margin, fees and shipping until the shop sends real margins.', 'من أسعار المتجر وهامش افتراضي ٤٥٪ والرسوم والشحن إلى أن يرسل المتجر الهوامش الحقيقية.'))}</p></div>
  </section></div>`;
  el.querySelectorAll('[data-win]').forEach((b) => { b.onclick = () => { win = b.dataset.win; render(S, el); }; });
}
