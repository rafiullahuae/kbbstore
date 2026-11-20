// Overview (H · Sage Calm): live hero + limits, 6 KPI tiles with sparklines,
// spend vs sales + who is buying, creatives + trending now, plan / needs you / accounts.
import { readinessCard } from './funnel-ui.js';
import { tt, getLang } from '../i18n.js';
import { esc, aed, num, pct, ago, lineChart, spark, txt, tip, verdictChip, toast } from '../ui.js';
import { api, getAccount, authed } from '../api.js';
import { switchAccount, runCycle, S as STORE } from '../main.js';
import { renderSpec } from '../creative/render.js';

const HELP = {
  spend: ['Money Meta charged for ads in this period.', 'المبلغ الذي دفعته لميتا مقابل الإعلانات.'],
  purchases: ['Purchases Meta credits to the ads (7-day click / 1-day view). The last 2 days still grow.', 'المشتريات المنسوبة للإعلانات. آخر يومين ما زالا يزيدان.'],
  roas: ['Sales ÷ spend. Above break-even the ads make money.', 'المبيعات ÷ الإنفاق. فوق نقطة التعادل تربح الإعلانات.'],
  cpa: ['Spend ÷ orders: what one order costs in ads. Lower is better.', 'تكلفة الطلب الواحد. الأقل أفضل.'],
  cpc: ['Spend ÷ link clicks: the price of one visit.', 'سعر الزيارة الواحدة للمتجر.'],
  ctr: ['Link clicks ÷ impressions: how attractive the ads are.', 'النقرات ÷ مرات الظهور: جاذبية الإعلان.'],
};
const thumbs = new Map();

export function render(S, el) {
  const st = S.state;
  const win = S.range;
  const k = st.kpis[win];
  const prev = win === 'd7' ? st.kpis.d30 : null;
  const series = st.series || [];
  const mode = st.autopilot.mode;
  const live = mode !== 'off';
  const L = st.limits;
  const tg = st.targets || {};
  const doing = tt(st.autopilot.doing, st.autopilot.doing_ar) || tt('The autopilot is off for this account. Pick Practice or Auto at the top once your limits are set.', 'الطيار الآلي متوقف لهذا الحساب. اختاري «تدريب» أو «تلقائي» في الأعلى بعد تحديد الحدود.');
  const today = new Date().toISOString().slice(0, 10);
  const steps = [['sync', tt('Read Meta', 'قراءة ميتا'), !!st.raw.last_sync], ['judge', tt('Judge every ad', 'تقييم كل إعلان'), !!st.autopilot.last_cycle_at], ['plan', tt('Plan', 'التخطيط'), !!st.plan], ['act', tt(mode === 'auto' ? 'Act' : 'Log (practice)', mode === 'auto' ? 'التنفيذ' : 'التسجيل (تدريب)'), !!st.autopilot.last_cycle_at && String(st.autopilot.last_cycle_at).slice(0, 10) === today], ['report', tt('Daily report', 'التقرير اليومي'), !!st.report]];
  const dayPct = L.daily_aed ? Math.min(1, st.kpis.today.spend / L.daily_aed) : 0;
  const monPct = L.monthly_aed ? Math.min(1, st.month_spend / L.monthly_aed) : 0;
  const tile = (key, label, ar, val, fmt, lowerBetter, series2) => {
    const d = prev && prev[key] ? (k[key] - prev[key] / 30 * 7) / (prev[key] / 30 * 7 || 1) : null;
    const good = d == null ? '' : (lowerBetter ? d <= 0 : d >= 0) ? 'good-t' : 'bad-t';
    const delta = ['spend', 'purchases'].includes(key) && prev ? (k[key] - prev[key] * 7 / 30) / Math.max(1e-9, prev[key] * 7 / 30) : null;
    const dd = delta != null && isFinite(delta) ? `<span class="${(lowerBetter ? delta <= 0 : delta >= 0) ? 'good-t' : 'bad-t'}">${delta >= 0 ? '▲' : '▼'} ${num(Math.abs(delta) * 100)}%</span>` : (key === 'roas' && tg.roas ? `<span class="${k.roas >= tg.roas ? 'good-t' : 'bad-t'}">${tt('target', 'المستهدف')} ${num(tg.roas, 1)}</span>` : key === 'cpa' && tg.cpa ? `<span class="${k.cpa && k.cpa <= tg.cpa ? 'good-t' : 'bad-t'}">${tt('target', 'المستهدف')} ${aed(tg.cpa)}</span>` : '<span></span>');
    void good;
    return `<div class="kpi"><span class="l">${esc(tt(label, ar))} ${tip(tt(...HELP[key]))}</span><span class="v">${fmt(val)}</span><span class="d">${dd}${spark(series.map((s) => s[series2 || key] || 0), key === 'spend' ? 'var(--s-spend)' : 'var(--accent)')}</span></div>`;
  };
  const sp = st.split || {};
  const arS = (sp.ar && sp.ar.spend) || 0, enS = (sp.en && sp.en.spend) || 0, arShare = arS + enS ? arS / (arS + enS) : 0;
  const props = st.proposals || [];
  el.innerHTML = `<div class="page">${readinessCard(st, 'overview')}
  <section class="grid g21">
    <div class="hero" aria-label="${esc(tt('What it is doing now', 'ما يفعله الآن'))}">
      <span class="live">${live ? '<i></i>' : ''}${esc(live ? tt('Live · ' + (mode === 'auto' ? 'Auto' : 'Practice'), 'مباشر · ' + (mode === 'auto' ? 'تلقائي' : 'تدريب')) : tt('Off', 'متوقف'))}</span>
      <h2>${esc(live ? tt('Your ads are being looked after', 'إعلاناتك تحت الرعاية') : tt('Autopilot is off', 'الطيار الآلي متوقف'))}</h2>
      <p>${txt(doing)}</p>
      <div class="steps-pills">${steps.map(([, l, ok]) => `<span class="${ok ? 'done' : ''}">${ok ? '✓ ' : ''}${esc(l)}</span>`).join('')}</div>
      <p class="small">${esc(tt('Next cycle', 'الدورة التالية'))}: ${esc(st.autopilot.next_cycle_at ? new Date(st.autopilot.next_cycle_at).toLocaleTimeString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { hour: '2-digit', minute: '2-digit' }) : tt('when the schedule runs', 'حسب الجدول'))} · ${esc(tt('planned by', 'خطّط له'))} ${esc(st.autopilot.model || '—')}</p>
      <div class="row"><a href="#/plan"><button type="button" class="onhero solid">${esc(tt("Open today's plan", 'خطة اليوم'))}</button></a>
        <button type="button" class="onhero" id="why">${esc(tt('Ask Claude why', 'اسألي Claude لماذا'))}</button>
        <button type="button" class="onhero" id="rep">${esc(tt('Daily report AR/EN', 'التقرير اليومي ع/EN'))}</button>
        <button type="button" class="onhero" id="cyc">${esc(tt('Run a cycle', 'تشغيل دورة'))}</button></div>
    </div>
    <div class="card"><header><h2>${esc(tt('Your limits', 'حدودك'))}</h2><a href="#/settings">${esc(tt('Change limits', 'تغيير الحدود'))}</a></header>
      ${st.limits_set ? `<div class="spread small"><span>${esc(tt('Today', 'اليوم'))}</span><span class="num">${aed(st.kpis.today.spend)} / ${aed(L.daily_aed)}</span></div><div class="meter ${dayPct > 0.9 ? 'hot' : ''}"><i style="width:${(dayPct * 100).toFixed(1)}%"></i></div>
      <div class="spread small" style="margin-top:12px"><span>${esc(tt('This month', 'هذا الشهر'))}</span><span class="num">${aed(st.month_spend)} / ${aed(L.monthly_aed)}</span></div><div class="meter ${monPct > 0.9 ? 'hot' : ''}"><i style="width:${(monPct * 100).toFixed(1)}%"></i></div>
      <p class="small muted" style="margin-top:12px">${esc(tt('Meta itself enforces the monthly cap (account spending limit), so the ads stop even while your Mac is closed.', 'تفرض ميتا نفسها الحد الشهري، فتتوقف الإعلانات حتى وجهازك مغلق.'))}</p>
      <p class="small muted">${esc(tt('Step', 'خطوة'))} ±${num(L.max_budget_step_pct)}% · ${esc(tt('cooldown', 'انتظار'))} ${num(L.cooldown_hours)} h · ${esc(tt('max ads', 'أقصى إعلانات'))} ${num(L.max_active_ads)}</p>`
      : `<div class="alert warn">${esc(tt('No limits yet: nothing will launch until you set a daily and a monthly limit.', 'لا توجد حدود: لن يُطلق شيء حتى تحدّدي الحد اليومي والشهري.'))}</div><p><a href="#/settings"><button type="button">${esc(tt('Set limits', 'تحديد الحدود'))}</button></a></p>`}
    </div>
  </section>
  <section class="kpis">
    ${tile('spend', 'Spend', 'الإنفاق', k.spend, (v) => aed(v))}
    ${tile('purchases', 'Orders', 'الطلبات', k.purchases, (v) => num(v))}
    ${tile('roas', 'ROAS', 'العائد', k.roas, (v) => num(v, 2), false, 'revenue')}
    ${tile('cpa', 'Cost per order', 'تكلفة الطلب', k.cpa, (v) => aed(v), true, 'spend')}
    ${tile('cpc', 'CPC', 'تكلفة النقرة', k.cpc, (v) => aed(v, 2), true)}
    ${tile('ctr', 'CTR', 'نسبة النقر', k.ctr, (v) => pct(v))}
  </section>
  <section class="grid g21">
    <div class="card"><header><h2>${esc(tt('Spend vs sales · 30 days', 'الإنفاق مقابل المبيعات · ٣٠ يوماً'))}</h2><div class="legend"><span><i style="background:var(--s-spend)"></i>${esc(tt('Spend', 'الإنفاق'))}</span><span><i style="background:var(--s-rev)"></i>${esc(tt('Sales', 'المبيعات'))}</span></div></header>
      ${series.length ? lineChart(series, ['spend', 'revenue'], ['var(--s-spend)', 'var(--s-rev)'], [tt('Spend', 'الإنفاق'), tt('Sales', 'المبيعات')], (v) => aed(v)) : `<p class="muted">${esc(tt('Daily numbers appear after the first sync.', 'تظهر الأرقام اليومية بعد أول مزامنة.'))}</p>`}</div>
    <div class="card"><header><h2>${esc(tt('Who is buying', 'من يشتري'))}</h2><span class="sub">30d</span></header>
      <div class="spread small"><span>${esc(tt('Arabic ads share of spend', 'حصة الإعلانات العربية من الإنفاق'))}</span><b>${pct(arShare, 0)}</b></div>
      <div class="share" style="margin:6px 0 12px"><i style="width:${(arShare * 100).toFixed(1)}%;background:var(--accent)"></i><i style="flex:1;background:var(--surface3)"></i></div>
      <div class="list small">
        <div class="spread"><span>${esc(tt('Arabic ads', 'الإعلانات العربية'))}</span><span class="num">ROAS ${num((sp.ar || {}).roas, 2)} · ${aed((sp.ar || {}).cpa)}</span></div>
        <div class="spread"><span>${esc(tt('English ads', 'الإعلانات الإنجليزية'))}</span><span class="num">ROAS ${num((sp.en || {}).roas, 2)} · ${aed((sp.en || {}).cpa)}</span></div>
        <div class="spread"><span>${esc(tt('Audience', 'الجمهور'))}</span><span class="ink2">${esc(tt('Women 18–45 · Arabic · GCC', 'نساء ١٨–٤٥ · العربية · الخليج'))}</span></div>
        <div class="spread"><span>${esc(tt('Countries', 'الدول'))}</span><span>${(L.countries || []).map((c) => `<span class="chip">${esc(c)}</span>`).join(' ')}</span></div>
        <div class="spread"><span>${esc(tt('Placements', 'أماكن الظهور'))}</span><span class="ink2">${esc(tt('Reels & Stories first', 'الريلز والستوريز أولاً'))}</span></div>
      </div>
      <p class="small muted">${esc(tt('Break-even', 'نقطة التعادل'))}: ${aed(tg.breakeven_cpa)} · ROAS ${num(tg.breakeven_roas, 2)}</p></div>
  </section>
  <section class="grid g21">
    <div class="card"><header><h2>${esc(tt('Creatives', 'الإعلانات'))}</h2><a href="#/creatives">${esc(tt('All creatives', 'كل الإعلانات'))} →</a></header>
      <div class="lib" id="ov-cr">${creativeTiles(st)}</div></div>
    <div class="card"><header><h2>${esc(tt('Trending now', 'الرائج الآن'))}</h2><a href="#/research">${esc(tt('Research', 'الأبحاث'))} →</a></header><div id="ov-tr">${trendingHTML(S)}</div></div>
  </section>
  <section class="grid g3">
    <div class="card"><header><h2>${esc(tt('Plan · next 7 days', 'الخطة · ٧ أيام'))}</h2><a href="#/plan">→</a></header>
      <div class="list">${planRows(st)}</div></div>
    <div class="card"><header><h2>${esc(tt('Needs you', 'يحتاجك'))}</h2>${mode !== 'auto' && props.length ? `<span class="pill">${props.length}</span>` : ''}</header>
      <div class="list">${mode === 'auto' ? `<div class="muted">${esc(tt('Nothing: it acts inside your limits.', 'لا شيء: ينفّذ ضمن حدودك.'))}</div>` : props.slice(0, 4).map((p) => `<div><span class="chip">${esc(p.kind)}</span><span class="small">${esc(p.why)}</span></div>`).join('') || `<div class="muted">${esc(tt('Nothing waiting.', 'لا شيء بانتظارك.'))}</div>`}
      ${mode !== 'auto' && props.length ? `<div class="row"><button type="button" id="approve">${esc(tt('Approve', 'موافقة'))}</button><a href="#/approvals"><button type="button" class="ghost">${esc(tt('Review', 'مراجعة'))}</button></a></div>` : ''}</div></div>
    <div class="card"><header><h2>${esc(tt('All accounts', 'كل الحسابات'))}</h2><a href="#/portfolio">→</a></header>
      <div class="list">${S.accounts.map((a) => { const s2 = S.states.get(a.id); return `<div style="cursor:pointer" data-open="${esc(a.id)}"><span class="dot ${a.status !== 'ACTIVE' ? 'bad' : a.mode === 'auto' ? 'ok' : a.mode === 'shadow' ? 'warn' : ''}" style="margin-top:6px"></span><span style="flex:1"><b>${esc(a.name)}</b><br><span class="small muted">${esc(a.currency)}</span></span><span class="small num">ROAS ${s2 ? num(s2.kpis.d7.roas, 2) : '…'}</span><span class="pill ${esc(a.mode)}">${esc(tt({ off: 'Off', shadow: 'Practice', auto: 'Auto' }[a.mode] || a.mode, { off: 'متوقف', shadow: 'تدريب', auto: 'تلقائي' }[a.mode] || a.mode))}</span></div>`; }).join('')}</div></div>
  </section></div>`;
  el.querySelectorAll('[data-open]').forEach((n) => { n.onclick = () => switchAccount(n.dataset.open); });
  el.querySelector('#cyc').onclick = () => runCycle();
  el.querySelector('#rep').onclick = () => report(st);
  el.querySelector('#why').onclick = () => why(st);
  const ap = el.querySelector('#approve');
  if (ap) ap.onclick = async () => { try { const r = await api('/api/cycle', { method: 'POST', body: { mode: 'auto' } }); toast(tt(`Done: ${JSON.stringify(r.counts || {})}`, `تم: ${JSON.stringify(r.counts || {})}`)); } catch (e) { toast(e.message); } };
  fillThumbs(el, st);
}

function creativeTiles(st) {
  const ds = (st.designs_archive || STORE.cache.get(`${getAccount()}|designs`) || []).filter((d) => d.formats).slice(-4).reverse();
  if (!ds.length) return `<p class="muted">${esc(tt('Creatives appear here after the first plan.', 'تظهر الإعلانات هنا بعد أول خطة.'))}</p>`;
  return ds.map((d) => `<div class="cr"><div class="media" data-thumb="${esc(d.id)}">${thumbs.has(d.id) ? `<img src="${thumbs.get(d.id)}" alt="">` : '<div class="sk" style="height:100%"></div>'}<span class="tag">${esc((d.lang || '').toUpperCase())} · 4:5</span></div><div class="meta"><b>${txt(d.headline)}</b><span class="muted">${txt(d.sub || d.angle || '')}</span></div></div>`).join('');
}
async function fillThumbs(el, st) {
  const ds = (STORE.cache.get(`${getAccount()}|designs`) || []).slice(-4);
  for (const d of ds) {
    if (thumbs.has(d.id)) continue;
    const cv = await renderSpec(d, '4:5');
    const s = document.createElement('canvas'); s.width = 320; s.height = 400; s.getContext('2d').drawImage(cv, 0, 0, 320, 400);
    thumbs.set(d.id, s.toDataURL('image/jpeg', 0.82));
    const slot = el.querySelector(`[data-thumb="${CSS.escape(d.id)}"]`);
    if (slot && location.hash.match(/#\/(overview|dashboard)?$/) !== null) slot.querySelector('.sk, img') && (slot.querySelector('.sk, img').outerHTML = `<img src="${thumbs.get(d.id)}" alt="">`);
  }
}
function trendingHTML(S) {
  const tr = S.cache.get('|trending') || S.cache.get(`${getAccount()}|trending`);
  if (!tr) return '<div class="sk" style="height:160px"></div>';
  const items = (tr.items || []).slice(0, 6);
  if (!items.length) return `<p class="muted">${esc(tt('Claude checks what is trending every day; the first list appears after the first research run.', 'يبحث Claude يومياً عن الرائج؛ تظهر أول قائمة بعد أول بحث.'))}</p>`;
  return `<div>${items.map((it) => `<div class="trend"><span class="rk">${it.rank}</span><span><b>${esc(it.name)}</b> <span class="muted small">${esc(it.brand || '')}</span><br><span class="chip mom-${esc(it.momentum)}">${esc(it.momentum)}</span> <span class="chip">${esc(it.region === 'gcc' ? tt('GCC', 'الخليج') : tt('global', 'عالمي'))}</span></span><span class="chip ${it.in_stock ? 'acc' : ''}">${esc(it.in_stock ? tt('in stock', 'متوفر') : it.in_shop ? tt('sold out', 'نفد') : tt('not sold', 'غير متوفر'))}</span></div>`).join('')}</div>
    ${(tr.consider_stocking || []).length ? `<p class="small"><b>${esc(tt('Consider stocking', 'فكّري في توفيره'))}:</b> ${(tr.consider_stocking || []).slice(0, 4).map((x) => esc(x.name)).join(' · ')}</p>` : ''}`;
}
function planRows(st) {
  const p = st.plan;
  const rows = [];
  if (p) {
    for (const s of (p.seasonal || []).slice(0, 2)) rows.push([s.prep_from, `${s.name}`]);
    for (const x of (p.tests || []).slice(0, 3)) rows.push([tt('batch day', 'يوم الدفعة'), `${(x.lang || '').toUpperCase()} · ${x.name}`]);
  } else for (const s of ((st.engine_plan || {}).steps || []).slice(0, 5)) rows.push([s.when, s.title]);
  return rows.map(([w, t]) => `<div><span class="chip">${esc(w)}</span><span class="small">${esc(t)}</span></div>`).join('') || `<div class="muted">—</div>`;
}
function report(st) {
  const d = document.getElementById('dlg');
  const rep = (st.report && st.report.text) || '';
  const [en, ar] = rep.split(/\n\s*\nAR\n/);
  d.innerHTML = `<div class="dl"><div class="spread"><h2>${esc(tt('Daily report', 'التقرير اليومي'))}</h2><button class="ghost small" id="x">✕</button></div><pre class="report">${esc((en || '').replace(/^EN\n/, ''))}</pre><pre class="report rtl" dir="rtl" lang="ar">${esc(ar || '')}</pre></div>`;
  d.showModal(); d.querySelector('#x').onclick = () => d.close();
}
function why(st) {
  const d = document.getElementById('dlg');
  const vs = [];
  for (const c of st.tree) for (const s of c.adsets) for (const a of s.ads) if (a.verdict) vs.push([a.name, a.verdict]);
  d.innerHTML = `<div class="dl"><div class="spread"><h2>${esc(tt('Why it decided what it did', 'لماذا قرّر ذلك'))}</h2><button class="ghost small" id="x">✕</button></div>
    <div class="list">${vs.map(([n, v]) => `<div>${verdictChip(v)}<span><b>${txt(n)}</b><br><span class="small ink2">${esc(v.why)}</span></span></div>`).join('') || `<div class="muted">${esc(tt('No decisions yet.', 'لا قرارات بعد.'))}</div>`}
    ${(st.learnings || []).map((l) => `<div>💡 <span class="small">${esc(l)}</span></div>`).join('')}</div></div>`;
  d.showModal(); d.querySelector('#x').onclick = () => d.close();
}
export async function load(S) {
  const id = getAccount();
  let changed = false;
  if (!S.cache.has('|trending')) { S.cache.set('|trending', await api('/api/brain/x/trending').catch(() => ({ items: [] }))); changed = true; }
  if (!S.cache.has(`${id}|designs`)) { S.cache.set(`${id}|designs`, await api('/api/brain/x/designs').catch(() => [])); changed = true; }
  return changed && /#\/(overview|dashboard)?$/.test(location.hash || '#/overview');
}
void ago; void authed;
