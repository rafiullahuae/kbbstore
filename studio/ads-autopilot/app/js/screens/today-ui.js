// Today: the Daily Plan (every morning, AR + EN) and "Watching now" (the hourly close check of every live and new ad),
// on Overview and Plan. One GET /api/today per account, kept in the cache until the engine pushes "dailyplan" /
// "watch" (no polling). Buttons: write today's plan again, check now.
import { tt, getLang } from '../i18n.js';
import { esc, aed, num, pct, toast } from '../ui.js';
import { api, getAccount } from '../api.js';

const key = () => `${getAccount()}|today`;
const hm = (iso) => { if (!iso || String(iso).startsWith('0001')) return '—'; return new Date(iso).toLocaleTimeString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { hour: '2-digit', minute: '2-digit' }); };
const L = (l) => (getLang() === 'ar' ? l.ar || l.text : l.text);
const VERD = { too_early: ['Too early', 'مبكر', ''], promising: ['Promising', 'واعد', 'acc'], watch: ['In range', 'ضمن المعدّل', ''], weak: ['Weak signal', 'إشارة ضعيفة', 'warn'], kill: ['Stopped early', 'أُوقف مبكراً', 'warn'] };
const PACE = { on_track: ['On track', 'ضمن المسار', 'acc'], behind: ['Behind', 'أبطأ', 'warn'], ahead: ['Ahead', 'أسرع', 'warn'], early: ['Early in the day', 'بداية اليوم', ''], no_budget: ['No budget running', 'لا ميزانية تعمل', ''] };

export async function loadToday(S) {
  if (S.cache.has(key())) return false;
  S.cache.set(key(), await api('/api/today').catch(() => ({})));
  return true;
}

export function dailyPlanCard(S) {
  const t = S.cache.get(key());
  if (!t) return '<div class="card" data-today><div class="sk" style="height:140px"></div></div>';
  const d = t.daily_plan;
  const v = t.volume || {};
  if (!d) {
    return `<div class="card" data-today><header><h2>${esc(tt("Today's plan", 'خطة اليوم'))}</h2><button type="button" class="ghost small" data-today-plan>${esc(tt('Write it now', 'اكتبيها الآن'))}</button></header>
      <p class="muted">${esc(tt('Written every morning (the first open of the day, or 07:00 while the app is open).', 'تُكتب كل صباح (أول فتح لليوم أو الساعة ٧ والتطبيق مفتوح).'))}</p></div>`;
  }
  const y = d.yesterday || {};
  const ar = getLang() === 'ar';
  const cr = d.create || {};
  return `<div class="card" data-today><header><h2>${esc(tt("Today's plan", 'خطة اليوم'))} · ${esc(d.date)}</h2><span class="small muted">${esc(tt('written', 'كُتبت'))} ${esc(hm(d.at))} · <button type="button" class="ghost small" data-today-plan>${esc(tt('Write again', 'أعيدي كتابتها'))}</button></span></header>
    <div class="kpis kpis-4" style="margin:0 0 10px">
      <div class="kpi"><span class="l">${esc(tt('Yesterday spend', 'إنفاق أمس'))}</span><span class="v">${aed(y.spend)}</span></div>
      <div class="kpi"><span class="l">${esc(tt('Shop orders', 'طلبات المتجر'))}</span><span class="v">${num(y.shop_orders)}</span></div>
      <div class="kpi"><span class="l">${esc(tt('Cost per order', 'تكلفة الطلب'))}</span><span class="v">${y.shop_orders ? aed(y.cpa) : '—'}</span></div>
      <div class="kpi"><span class="l">ROAS</span><span class="v">${y.spend ? num(y.roas, 2) : '—'}</span></div>
    </div>
    <p class="small muted">${esc(tt('Orders and revenue from the shop (every channel); spend from Meta.', 'الطلبات والإيرادات من المتجر (كل القنوات)؛ الإنفاق من Meta.'))} ${esc(tt('Live now', 'نشطة الآن'))}: <b>${num(d.live)}</b>${v.live_max ? ` / ${esc(tt('target', 'الهدف'))} ${num(v.live_min)}–${num(v.live_max)}` : ''}</p>
    <h3>${esc(tt('Today, with times', 'اليوم بالأوقات'))}</h3>
    <div class="list small" data-today-jobs>${(d.today || []).map((l) => `<div><span ${ar ? 'dir="rtl" lang="ar"' : ''}>${esc(L(l))}</span></div>`).join('') || `<div class="muted">—</div>`}</div>
    ${cr.needed ? `<div class="alert ${cr.mode === 'with_me' ? 'info' : ''}" style="margin-top:8px">${esc(ar ? cr.note_ar : cr.note)} ${cr.mode === 'with_me' ? `<a href="#/creatives">${esc(tt('Start creating', 'ابدئي الإنشاء'))} →</a>` : ''}</div>` : `<p class="small muted">${esc(ar ? cr.note_ar : cr.note)}</p>`}
    ${(d.changed || []).length ? `<details><summary class="small">${esc(tt('What it changed since the last plan', 'ما غيّره منذ الخطة السابقة'))} (${d.changed.length})</summary><div class="list small">${d.changed.map((l) => `<div><span class="muted">${esc(hm(l.at))}</span> <span>${esc(L(l))}</span></div>`).join('')}</div></details>` : ''}
    ${(d.watching || []).length ? `<h3>${esc(tt('What it is watching', 'ما يراقبه'))}</h3><div class="list small">${d.watching.slice(0, 6).map((l) => `<div>• <span ${ar ? 'dir="rtl" lang="ar"' : ''}>${esc(L(l))}</span></div>`).join('')}</div>` : ''}
    <details><summary class="small">${esc(tt('The written plan (AR + EN)', 'الخطة المكتوبة (ع + EN)'))}</summary><div class="bi"><pre class="report">${esc(d.text_en)}</pre><pre class="report rtl" dir="rtl" lang="ar">${esc(d.text_ar)}</pre></div></details>
  </div>`;
}

export function watchingCard(S) {
  const t = S.cache.get(key());
  if (!t) return '<div class="card" data-watch><div class="sk" style="height:140px"></div></div>';
  const w = t.watch || {};
  const items = w.items || [];
  const p = w.pacing || {};
  const pc = PACE[p.state] || ['—', '—', ''];
  const ar = getLang() === 'ar';
  return `<div class="card" data-watch><header><h2>${esc(tt('Watching now', 'المراقبة الآن'))}</h2><span class="small muted">${esc(tt('checked', 'فُحص'))} ${esc(hm(w.checked_at))} · ${esc(tt('next', 'التالي'))} ${esc(hm(w.next_at))} · <button type="button" class="ghost small" data-watch-check>${esc(tt('Check now', 'افحصي الآن'))}</button></span></header>
    <div class="spread small" style="margin-bottom:8px"><span>${esc(tt('Spend pacing', 'وتيرة الإنفاق'))}: <span class="chip ${pc[2]}">${esc(tt(pc[0], pc[1]))}</span> ${p.budget_aed ? `${aed(p.spent_aed)} / ~${aed(p.expected_aed)} ${esc(tt('by now', 'حتى الآن'))}` : ''}</span><span class="muted">${esc(tt('early stops', 'إيقاف مبكر'))} ${num(w.early_kills || 0)} · ${esc(tt('reworded', 'أُعيدت صياغتها'))} ${num(w.rewords || 0)}</span></div>
    ${(w.problems || []).map((x) => `<div class="alert warn small" style="margin-bottom:6px">${esc(ar ? x.text_ar : x.text)}${x.fix ? ` <b>${esc(x.fix)}</b>` : ''}</div>`).join('')}
    ${items.length ? `<div class="tw"><table class="small" data-watch-rows><thead><tr><th>${esc(tt('Ad', 'الإعلان'))}</th><th>${esc(tt('Age', 'العمر'))}</th><th class="n">CTR</th><th class="n">CPC</th><th class="n">${esc(tt('Carts', 'السلات'))}</th><th>${esc(tt('Early signal', 'الإشارة المبكرة'))}</th></tr></thead><tbody>
      ${items.slice(0, 14).map((it) => { const vv = VERD[(it.early || {}).verdict] || ['—', '—', '']; return `<tr><td><b>${esc(String(it.name || "").replace(/^KAA:\s*/, ""))}</b>${it.new ? ` <span class="chip acc">${esc(tt('new', 'جديد'))}</span>` : ''}<br><span class="muted">${esc(it.status)}</span></td><td>${it.age_hours < 72 ? `${num(it.age_hours)} h` : `${num(it.age_hours / 24)} ${esc(tt('d', 'ي'))}`}<br><span class="muted">${esc(tt('next', 'التالي'))} ${esc(it.new ? hm(it.next_check) : tt('daily', 'يومياً'))}</span></td><td class="n">${it.impressions ? pct(it.ctr) : '—'}</td><td class="n">${it.clicks ? aed(it.cpc, 2) : '—'}</td><td class="n">${num(it.add_to_cart)}</td><td><span class="chip ${vv[2]}">${esc(tt(vv[0], vv[1]))}</span><br><span class="muted" ${ar ? 'dir="rtl" lang="ar"' : ''}>${esc(ar ? (it.early || {}).why_ar : (it.early || {}).why)}</span></td></tr>`; }).join('')}
      </tbody></table></div>${items.length > 14 ? `<p class="small muted">+${items.length - 14}</p>` : ''}`
      : `<p class="muted">${esc(tt('No live ads yet. Each new ad is checked every hour for its first 72 hours against your account\'s medians; it is stopped early only on strong evidence.', 'لا إعلانات نشطة بعد. كل إعلان جديد يُفحص كل ساعة في أول ٧٢ ساعة مقابل متوسطات حسابك، ولا يُوقف مبكراً إلا بدليل قوي.'))}</p>`}
    <p class="small muted">${esc(tt('Medians of your ads', 'متوسطات إعلاناتك'))}: CTR ${pct((w.medians || {}).ctr)} · CPC ${aed((w.medians || {}).cpc, 2)} · ${esc(tt('carts per 100 clicks', 'سلات لكل ١٠٠ نقرة'))} ${num(((w.medians || {}).atc_per_click || 0) * 100)}</p>
  </div>`;
}

export function wireToday(S, el, again) {
  const go = async (btn, path, ok) => {
    btn.disabled = true;
    try { S.cache.set(key(), await api(path, { method: 'POST', body: {} })); toast(ok); again(); } catch (e) { toast(e.message); btn.disabled = false; }
  };
  el.querySelectorAll('[data-today-plan]').forEach((b) => { b.onclick = () => go(b, '/api/today/plan', tt("Today's plan written", 'كُتبت خطة اليوم')); });
  el.querySelectorAll('[data-watch-check]').forEach((b) => { b.onclick = () => go(b, '/api/today/check', tt('Checked', 'تم الفحص')); });
}
