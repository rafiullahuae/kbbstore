// Strategy (top of THINK): "How it decides", in plain words with this account's live numbers, and everything he can
// steer: his Playbook (instructions, focus / avoid, freshness, audience, budget split, offers), the decision rules
// (with defaults and a reset), the self-tuning switch, the occasions, "Our playbook" (the agency rules) and the
// ranked products with the why (pin / avoid inline). Data: /api/brain/x/strategy, /api/playbook, /api/occasions.
import { tt } from '../i18n.js';
import { esc, aed, num, toast, tip } from '../ui.js';
import { api, getAccount } from '../api.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id))); // account → {s, p, o}
let draft = null, draftFor = '', dirty = false, tab = 'how';
const TABS = [['how', 'How it decides', 'كيف يقرّر'], ['playbook', 'Your playbook', 'تعليماتك'], ['products', 'Products', 'المنتجات'], ['rules', 'Rules', 'القواعد'], ['occasions', 'Occasions', 'المناسبات'], ['agency', 'Our playbook', 'منهجنا']];

export async function load() {
  const k = getAccount();
  if (cache.has(k)) return false;
  const [s, p, o, v] = await Promise.all([api('/api/brain/x/strategy'), api('/api/playbook'), api('/api/occasions'), api('/api/volume').catch(() => null)]);
  cache.set(k, { s, p, o, v });
  if (!dirty || draftFor !== k) { draft = JSON.parse(JSON.stringify(p.playbook)); draftFor = k; dirty = false; } // unsaved edits survive a refresh
  return true;
}
export function drop() { cache.delete(getAccount()); }

const L = (en, ar) => esc(tt(en, ar));
const ruleChips = (rs, kind) => (rs || []).map((r, i) => `<span class="chip ${kind === 'avoid' ? 'warn' : 'acc'}">${esc(r.kind)}: ${esc(r.label || r.value)} <button type="button" class="x" data-rm="${kind}:${i}" aria-label="remove">×</button></span>`).join(' ');

function how(s, o) {
  const e = s.economics || {};
  return `<div class="grid g2">
  <div class="card"><header><h2>${L('The targets it works to', 'الأهداف التي يعمل بها')} ${tip(tt('Computed from the shop\'s real orders (average order value) and cost prices (margin), minus VAT, delivery, payment fees and refunds.', 'محسوبة من طلبات المتجر الحقيقية وأسعار التكلفة.'))}</h2><span class="sub">${esc(s.orders_source === 'shop-api' ? tt('from real shop orders', 'من طلبات المتجر الحقيقية') : tt('estimated until the shop API is connected', 'تقديرية حتى ربط المتجر'))}</span></header>
    <table><tbody>
    <tr><td>${L('Average order', 'متوسط الطلب')}</td><td class="n">${aed(e.aov)} <span class="muted small">${esc(e.aov_source || '')}</span></td></tr>
    <tr><td>${L('Gross margin', 'الهامش')}</td><td class="n">${num((e.margin_pct || 0) * 100)}% <span class="muted small">${esc(e.margin_source || '')}</span></td></tr>
    <tr><td><b>${L('Break-even cost per order', 'تكلفة الطلب عند التعادل')}</b></td><td class="n"><b>${aed(e.breakeven_cpa)}</b> · ROAS ${num(e.breakeven_roas, 2)}</td></tr>
    <tr><td><b>${L('Target cost per order', 'تكلفة الطلب المستهدفة')}</b></td><td class="n"><b>${aed(e.target_cpa)}</b> · ROAS ${num(e.target_roas, 2)}</td></tr>
    <tr><td>${L('Season now', 'الموسم الحالي')}</td><td class="n">${esc(e.season || '—')}</td></tr>
    <tr><td>${L('Shop orders a week', 'طلبات المتجر أسبوعياً')}</td><td class="n">${e.orders_per_week ? num(e.orders_per_week) : '—'}</td></tr></tbody></table>
    <details open><summary class="small"><b>${L('The math, per average order', 'الحساب لكل طلب متوسط')}</b></summary><ol class="small ink2" style="margin:6px 0;padding-inline-start:18px">${(e.math || []).map((m) => `<li>${esc(m)}</li>`).join('')}</ol></details>
    <div class="row small" data-econ>${[['vat_pct', 'VAT %', 'الضريبة ٪'], ['bnpl_fee_pct', 'Tabby/Tamara %', 'تابي/تمارا ٪'], ['card_fee_pct', 'Cards %', 'البطاقات ٪'], ['cod_fee_pct', 'Cash (Quiqup) %', 'الدفع عند الاستلام ٪'], ['delivery_aed', 'Delivery AED', 'التوصيل درهم'], ['gift_cost_aed', 'Free gift cost AED', 'تكلفة الهدية درهم']].map(([k, en, ar]) => `<label>${esc(tt(en, ar))} <input type="number" step="0.1" min="0" data-econf="${k}" value="${((draft.econ || {})[k]) ?? ''}" style="width:70px"></label>`).join('')}</div>
    ${!((draft.econ || {}).gift_cost_aed) ? `<p class="small" style="color:var(--warn)">${L('Add your free-gift cost per order: until then the targets assume AED 0.', 'أضيفي تكلفة الهدية لكل طلب: حتى ذلك الحين تفترض الأهداف ٠ درهم.')}</p>` : ''}</div>
  <div class="card"><header><h2>${L('The funnel it runs', 'مسار المبيعات')}</h2><span class="sub">${esc(s.tier === 'small' ? tt('small-budget tier', 'شريحة الميزانية الصغيرة') : tt('growth tier', 'شريحة النمو'))} · ${aed(s.daily_aed)}/${L('day', 'يوم')}</span></header>
    ${(s.funnel || []).map((f) => `<div style="margin-bottom:10px"><div class="spread small"><b>${esc(tt(f.stage, f.stage_ar))}</b><span class="num">${num(f.share)}% · ${aed(f.aed)}</span></div><div class="bar-s"><i style="width:${Math.max(0, Math.min(100, f.share))}%"></i></div><span class="small ink2">${esc(f.how)}</span></div>`).join('')}
    <p class="small muted">${L('Chat ads (WhatsApp / Messenger) are not used: you do not offer them.', 'إعلانات المحادثة (واتساب / ماسنجر) غير مستخدمة.')}</p></div></div>
  <div class="grid g2"><div class="card"><header><h2>${L('When it stops, keeps, scales', 'متى يوقف ويُبقي ويزيد')}</h2><a href="#" data-tab="rules" class="small">${L('Change', 'تغيير')}</a></header>
    <div class="list">${(s.rules || []).slice(0, 6).map((r) => `<div><span class="chip ${r.source === 'yours' ? 'acc' : r.source === 'self-tuned' ? 'warn' : ''}">${esc(r.source)}</span><span><b>${esc(tt(r.label, r.label_ar))}: ${num(r.value, r.unit === '%' ? 0 : 1)} ${esc(r.unit)}</b><br><span class="small ink2">${esc(r.why)}</span></span></div>`).join('')}</div></div>
  <div class="card"><header><h2>${L('Arabic first, and the calendar', 'العربية أولاً والتقويم')}</h2></header>
    <p>${L('Arabic share of new tests', 'حصة العربية من الاختبارات الجديدة')}: <b>${num((s.arabic_share || 0) * 100)}%</b> · ${L('weekly batch day', 'يوم الدفعة الأسبوعي')}: <b>${esc(s.batch_day)}</b> · ${L('self-tuning', 'الضبط الذاتي')}: <b>${esc(s.self_tune)}</b></p>
    <div class="list">${((o && o.upcoming) || []).filter((u) => (o.occasions || []).some((x) => x.id === u.occasion && x.on)).slice(0, 6).map((x) => `<div><span class="chip">${esc(x.start)}</span><span><b>${esc(tt(x.name, x.name_ar))}</b>${x.estimated ? ` <span class="muted small">(${L('estimated', 'تقديري')})</span>` : ''}<br><span class="small ink2">${L('ads prepared from', 'تُحضّر الإعلانات من')} ${esc(x.prep_from)} · ${esc(x.angle)}</span></span></div>`).join('')}</div></div></div>`;
}

function playbook(p, s) {
  const d = draft;
  const ex = p.excluded || [];
  const sp = d.split || {}, ss = d.small_split || {};
  return `<div class="card"><header><h2>${L('Your instructions — always obeyed', 'تعليماتك — تُتّبع دائماً')} ${tip(tt('Sent to Claude word for word, first, in every plan and every ad brief. Arabic or English.', 'تُرسل إلى Claude حرفياً وأولاً في كل خطة وكل طلب إعلان.'))}</h2><span class="sub">v${num(p.playbook.version || 0)} · ${L('applies at the next cycle', 'تُطبّق في الدورة القادمة')}</span></header>
    <textarea data-f="instructions" rows="4" dir="auto">${esc(d.instructions)}</textarea></div>
  <div class="grid g2">
  <div class="card"><header><h2>${L('Products', 'المنتجات')}</h2><span class="sub">${num(p.allowed)} / ${num(p.products)} ${L('allowed', 'مسموح')}</span></header>
    <p class="small"><b>${L('Focus (pinned)', 'التركيز (مثبّت)')}</b> ${tip(tt('Always considered in every batch.', 'تُؤخذ دائماً في الاعتبار.'))}<br>${ruleChips(d.focus, 'focus') || `<span class="muted">${L('none', 'لا شيء')}</span>`}</p>
    <p class="small"><b>${L('Avoid (never advertised)', 'تجنّب (لا يُعلن عنه أبداً)')}</b> ${tip(tt('Removed in code before Claude sees the products.', 'تُحذف برمجياً قبل أن يرى Claude المنتجات.'))}<br>${ruleChips(d.avoid, 'avoid') || `<span class="muted">${L('none', 'لا شيء')}</span>`}</p>
    <div class="row"><select data-new="kind"><option value="keyword">${L('keyword', 'كلمة')}</option><option value="brand">${L('brand', 'علامة')}</option><option value="category">${L('category', 'فئة')}</option><option value="product">${L('product id', 'رقم منتج')}</option></select>
      <input data-new="value" placeholder="${esc(tt('e.g. snail mucin', 'مثال: snail mucin'))}" style="flex:1"><button type="button" class="small" data-add="focus">${L('Pin', 'تثبيت')}</button><button type="button" class="ghost small" data-add="avoid">${L('Avoid', 'تجنّب')}</button></div>
    <label class="small" style="display:block;margin-top:12px">${L('Prefer new & trending', 'فضّل الجديد والرائج')}: <b data-out="fresh">${num(d.freshness_pct)}%</b><input type="range" min="0" max="100" step="5" data-f="freshness_pct" value="${num(d.freshness_pct)}" style="width:100%"></label>
    <div class="row small"><label>${L('Min stock', 'أدنى مخزون')} <input type="number" data-f="min_stock" value="${d.min_stock}" min="0" style="width:70px"></label><label>${L('Min margin %', 'أدنى هامش ٪')} <input type="number" data-f="min_margin_pct" value="${d.min_margin_pct}" min="0" max="90" style="width:70px"></label></div>
    ${ex.length ? `<p class="small muted">${L('Taken out now', 'مستبعد الآن')}: ${ex.slice(0, 6).map((x) => esc(x.title) + ' — ' + esc(x.reason)).join(' · ')}${ex.length > 6 ? ' …' : ''}</p>` : ''}</div>
  <div class="card"><header><h2>${L('Audience', 'الجمهور')}</h2></header>
    <div class="row small"><label>${L('Countries', 'الدول')} <input data-f="countries" value="${esc((d.countries || []).join(', '))}" placeholder="AE, SA" style="width:120px"></label>
      <label>${L('Age', 'العمر')} <input type="number" data-f="age_min" value="${d.age_min}" min="18" max="65" style="width:60px">–<input type="number" data-f="age_max" value="${d.age_max}" min="18" max="65" style="width:60px"></label></div>
    <div class="row small" style="margin-top:8px"><label>${L('Gender', 'الجنس')} <select data-f="gender"><option value="women" ${d.gender === 'women' ? 'selected' : ''}>${L('women (suggestion)', 'نساء (اقتراح)')}</option><option value="all" ${d.gender === 'all' ? 'selected' : ''}>${L('everyone', 'الجميع')}</option></select></label>
      <label><input type="checkbox" data-lang="ar" ${(d.languages || []).includes('ar') ? 'checked' : ''}> ${L('Arabic ads', 'إعلانات عربية')}</label><label><input type="checkbox" data-lang="en" ${(d.languages || []).includes('en') ? 'checked' : ''}> ${L('English ads', 'إعلانات إنجليزية')}</label></div>
    <label class="small" style="display:block;margin-top:8px">${L('Leave out people who bought in the last', 'استبعاد من اشترى خلال آخر')} <input type="number" data-f="exclude_buyers_days" value="${d.exclude_buyers_days}" min="0" max="180" style="width:70px"> ${L('days (0 = no)', 'يوماً (٠ = لا)')}</label>
    <h3 style="margin-top:14px">${L('Budget split', 'توزيع الميزانية')}</h3>
    <table class="small"><thead><tr><th></th><th>${L('Prospecting', 'عملاء جدد')}</th><th>${L('Retargeting', 'إعادة استهداف')}</th><th>${L('Existing', 'حاليون')}</th></tr></thead><tbody>
    <tr><td>${L('under', 'أقل من')} ${aed((d.knobs || {}).small_budget_aed || 150)}</td>${['prospecting', 'retargeting', 'existing'].map((k) => `<td><input type="number" data-split="small_split.${k}" value="${ss[k] || 0}" min="0" max="100" style="width:60px"></td>`).join('')}</tr>
    <tr><td>${L('above', 'أكثر من')}</td>${['prospecting', 'retargeting', 'existing'].map((k) => `<td><input type="number" data-split="split.${k}" value="${sp[k] || 0}" min="0" max="100" style="width:60px"></td>`).join('')}</tr></tbody></table>
    <h3 style="margin-top:14px">${L('Offers', 'العروض')} ${tip(tt('Never a percentage or an amount off: the checker blocks it in every mode. Only these.', 'لا نسبة ولا مبلغ خصم أبداً: فقط هذه.'))}</h3>
    <div class="row small"><label><input type="checkbox" data-f="offers_allowed" ${d.offers_allowed ? 'checked' : ''}> ${L('Use offers in ads', 'استخدام العروض في الإعلانات')}</label><label><input type="checkbox" data-f="free_gift" ${d.free_gift ? 'checked' : ''}> ${L('Free gift with every order', 'هدية مجانية مع كل طلب')}</label>
      <label>${L('Coupon code', 'كود الخصم')} <input data-f="coupon_code" value="${esc(d.coupon_code || '')}" style="width:90px"></label>
      <label><input type="checkbox" data-f="pay_later" ${d.pay_later ? 'checked' : ''}> ${L('Pay in 4 with Tabby / Tamara', 'قسّميها على ٤ دفعات مع تابي / تمارا')}</label></div>
    <label class="small" style="display:block;margin-top:6px">${L('Gifts we give (names, comma-separated; never invented)', 'الهدايا التي نقدمها (بالاسم)')} <input data-f="gifts" value="${esc((d.gifts || []).join(', '))}" style="width:100%"></label></div></div>`;
}

function products(s) {
  return `<div class="card"><header><h2>${L('Ranked products — what it will advertise first', 'ترتيب المنتجات — ما سيُعلن عنه أولاً')} ${tip(tt('Score = freshness (trending + new arrival, by your slider) + recent sales + margin + stock − time already in ads + what won here before. Old evergreens only climb on their own sales.', 'النتيجة = الحداثة + المبيعات + الهامش + المخزون − مدة الظهور.'))}</h2><span class="sub">${L('prefer new & trending', 'فضّل الجديد')} ${num(s.freshness_pct)}%</span></header>
    <table><thead><tr><th>#</th><th>${L('Product', 'المنتج')}</th><th class="n">${L('Score', 'النتيجة')}</th><th>${L('Why', 'السبب')}</th><th></th></tr></thead><tbody>
    ${(s.ranked || []).map((r, i) => `<tr><td>${i + 1}</td><td><b>${esc(r.title)}</b>${r.pinned ? ' <span class="chip acc">📌</span>' : ''}${r.trending ? ` <span class="chip">${L('trending', 'رائج')}</span>` : ''}<br><span class="small muted">${esc(r.brand || '')} · ${aed(r.price)}</span></td><td class="n">${num(r.score, 1)}</td>
      <td class="small ink2">${(r.why || []).map(esc).join(' · ')}</td><td class="row"><button type="button" class="ghost small" data-pin="${esc(r.id)}" data-label="${esc(r.title)}">${L('Pin', 'تثبيت')}</button><button type="button" class="ghost small" data-avoid="${esc(r.id)}" data-label="${esc(r.title)}">${L('Avoid', 'تجنّب')}</button></td></tr>`).join('')}</tbody></table></div>`;
}

// Activity level + "How much it runs" (engine/volume.go): saved at once (he asked for it applied, not hidden).
const LEVELS = [['steady', 'Steady', 'هادئ', "Today's behaviour: one weekly batch, the planner decides the count.", 'السلوك الحالي: دفعة أسبوعية واحدة والمخطِّط يقرّر العدد.'],
  ['active', 'Active', 'نشِط', '8–12 live ads, a batch of 4–6 new ads every Mon + Thu, losers refilled the same day, winners iterated within 48 h.', '٨–١٢ إعلاناً نشطاً، دفعة من ٤–٦ كل اثنين وخميس، استبدال الخاسر في اليوم نفسه، وتطوير الرابح خلال ٤٨ ساعة.'],
  ['aggressive', 'Aggressive', 'جريء', '12–20 live ads, batches Mon / Wed / Fri, up to 3 prospecting campaigns when the budget feeds them.', '١٢–٢٠ إعلاناً نشطاً، دفعات الاثنين والأربعاء والجمعة، حتى ٣ حملات جذب إن كفت الميزانية.']];
const CSTAT = { live: ['live', 'تعمل', 'acc'], planned: ['planned', 'مخطّطة', 'acc'], needs_budget: ['needs budget', 'تحتاج ميزانية', 'warn'], not_at_this_level: ['not at this level', 'ليست في هذا المستوى', ''] };
function volume(v) {
  if (!v || !v.volume) return '';
  const x = v.volume, cur = (draft.knobs || {}).activity_level || x.level;
  const fill = x.live_max ? Math.min(1, x.live / x.live_max) : 0;
  const DAR = { mon: 'الاثنين', tue: 'الثلاثاء', wed: 'الأربعاء', thu: 'الخميس', fri: 'الجمعة', sat: 'السبت', sun: 'الأحد' };
  const days = (x.batch_days || []).map((d) => tt(d[0].toUpperCase() + d.slice(1), DAR[d] || d)).join(' + ');
  return `<div class="grid g2" data-volume><div class="card"><header><h2>${L('Activity level', 'مستوى النشاط')} ${tip(tt('How many ads it runs and how often it tests new ones. Never above your limits: new ads go into the budgets you already have; a new campaign is built only when the budget feeds its learning.', 'كم إعلاناً يشغّل وكم مرة يختبر جديداً. لا يتجاوز حدودك أبداً.'))}</h2><span class="small muted">${L('saved at once', 'يُحفظ فوراً')}</span></header>
    <div class="seg" data-levels>${LEVELS.map(([id, en, ar]) => `<button type="button" data-level="${id}" class="${cur === id ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div>
    <div class="list small" style="margin-top:10px">${LEVELS.map(([id, en, ar, d, dar]) => `<div><span class="chip ${cur === id ? 'acc' : ''}">${esc(tt(en, ar))}</span><span class="ink2">${esc(tt(d, dar))}</span></div>`).join('')}</div>
    <p class="small">${esc(tt(x.why, x.why_ar))}</p></div>
  <div class="card"><header><h2>${L('How much it runs', 'كم يشغّل')}</h2><span class="sub">${esc(tt('target CPA', 'تكلفة الطلب المستهدفة'))} ${aed(x.target_cpa)}</span></header>
    <div class="spread small"><span>${L('Live prospecting ads now', 'إعلانات الجذب النشطة الآن')}</span><b data-live>${num(x.live)}${x.live_max ? ` / ${esc(tt('target', 'الهدف'))} ${num(x.live_min)}–${num(x.live_max)}` : ''}</b></div>
    ${x.live_max ? `<div class="meter"><i style="width:${(fill * 100).toFixed(1)}%"></i></div>` : ''}
    ${x.limit_capped ? `<div class="alert warn small" style="margin-top:8px">${esc(tt(`Your Max active ads is ${x.max_active_ads}: it aims for ${x.live_min}–${x.live_max} instead of ${x.level_min}–${x.level_max}.`, `الحد الأقصى للإعلانات النشطة ${x.max_active_ads}: الهدف ${x.live_min}–${x.live_max} بدلاً من ${x.level_min}–${x.level_max}.`))} <button type="button" class="small" data-raise="${x.level_max + x.other_live}">${esc(tt(`Raise to ${x.level_max + x.other_live}`, `ارفعيه إلى ${x.level_max + x.other_live}`))}</button></div>` : ''}
    <p class="small">${L('New ads', 'الإعلانات الجديدة')}: <b>${x.batch_max ? `${x.batch_min ? num(x.batch_min) + '–' : esc(tt('up to ', 'حتى '))}${num(x.batch_max)}` : '—'}</b> ${L('every', 'كل')} <b>${esc(days)}</b>${x.refill_same_day ? ` · ${L('losers refilled the same day', 'الخاسر يُستبدل في اليوم نفسه')} · ${L('winners iterated within', 'تطوير الرابح خلال')} ${num(x.iterate_hours)} h` : ''}</p>
    <h3>${L('Campaigns and why', 'الحملات ولماذا')}</h3>
    <div class="list small" data-campaigns>${(x.campaigns || []).map((c) => { const cs = CSTAT[c.status] || [c.status, c.status, '']; return `<div><span class="chip ${cs[2]}">${esc(tt(cs[0], cs[1]))}</span><span><b>${esc(tt(c.name, c.name_ar))}</b><br><span class="ink2">${esc(tt(c.why, c.why_ar))}</span></span></div>`; }).join('')}</div>
    ${(v.upcoming || []).length ? `<h3>${L('Next new ads', 'الإعلانات الجديدة القادمة')}</h3><div class="list small">${v.upcoming.slice(0, 6).map((j) => `<div><span class="chip">${esc(new Date(j.when).toLocaleString(tt('en-AE', 'ar-AE'), { weekday: 'short', hour: '2-digit', minute: '2-digit' }))}</span><span>${esc(tt(j.title, j.title_ar))}${j.waiting ? ` <span class="muted">(${esc(j.waiting)})</span>` : ''}</span></div>`).join('')}</div><a href="#/plan" class="small">${L('All upcoming jobs', 'كل المهام القادمة')} →</a>` : ''}
  </div></div>`;
}

function rules(s) {
  const k = draft.knobs || {};
  return `${volume(cache.get(getAccount()).v)}<div class="card"><header><h2>${L('Decision rules', 'قواعد القرار')}</h2><button type="button" class="ghost small" data-resetknobs>${L('Reset all to defaults', 'إعادة الكل للافتراضي')}</button></header>
    <table><thead><tr><th>${L('Rule', 'القاعدة')}</th><th class="n">${L('Now', 'الآن')}</th><th class="n">${L('Default', 'الافتراضي')}</th><th>${L('Your value', 'قيمتك')}</th><th>${L('Why', 'السبب')}</th></tr></thead><tbody>
    ${(s.rules || []).map((r) => { const pctKnob = ['kill_p', 'scale_p'].includes(r.knob); const v = k[r.knob]; const shown = v ? (pctKnob ? v * 100 : v) : ''; return `<tr><td><b>${esc(tt(r.label, r.label_ar))}</b> <span class="chip ${r.source === 'yours' ? 'acc' : r.source === 'self-tuned' ? 'warn' : ''}">${esc(r.source)}</span></td><td class="n">${num(r.value, 2)} ${esc(r.unit)}</td><td class="n muted">${num(r.default, 2)}</td>
      <td><input type="number" data-knob="${esc(r.knob)}" data-pct="${pctKnob ? 1 : 0}" value="${shown}" placeholder="${esc(tt('default', 'افتراضي'))}" min="${r.min}" max="${r.max}" step="${r.step}" style="width:90px"></td><td class="small ink2">${esc(r.why)}</td></tr>`; }).join('')}</tbody></table></div>
  <div class="card"><header><h2>${L('Let it tune its own rules', 'السماح له بضبط قواعده')} ${tip(tt('It back-tests its stop threshold on this account\'s own ads every week and moves it one step at a time, only inside your bounds. Every change is logged on "What it learned" and can be undone.', 'يختبر حدّ الإيقاف على إعلانات الحساب أسبوعياً ويحرّكه خطوة واحدة داخل حدودك فقط.'))}</h2></header>
    <div class="seg" data-tune>${[['off', 'Off', 'متوقف'], ['bounds', 'Within bounds', 'داخل الحدود'], ['ask', 'Ask me', 'اسألني']].map(([v, en, ar]) => `<button type="button" data-tunev="${v}" class="${draft.self_tune === v ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div>
    <p class="small">${L('Stop multiple bounds', 'حدود مضاعف الإيقاف')}: <input type="number" data-tb="hard_stop_min" value="${draft.tune_bounds.hard_stop_min}" step="0.5" style="width:70px">–<input type="number" data-tb="hard_stop_max" value="${draft.tune_bounds.hard_stop_max}" step="0.5" style="width:70px"> × ${L('break-even CPA', 'تكلفة التعادل')}
    ${s.tuned && s.tuned.hard_stop_mult ? ` · ${L('now', 'الآن')} <b>${num(s.tuned.hard_stop_mult, 1)}×</b> (${esc(s.tuned.applied ? tt('applied', 'مطبّق') : tt('proposed, waiting for you', 'مقترح بانتظارك'))})` : ''}</p></div>`;
}

function occasions(o) {
  const list = draft.occasions && draft.occasions.length ? draft.occasions : o.occasions;
  const next = {};
  for (const u of o.upcoming || []) if (!next[u.occasion] || u.start < next[u.occasion].start) next[u.occasion] = u;
  return `<div class="card"><header><h2>${L('Occasions', 'المناسبات')} ${tip(tt('At each occasion\'s lead time it prepares the ads (Arabic + English), the products and a budget ramp, and puts the package in Approvals → Occasions. Nothing seasonal goes live without your approval.', 'يحضّر الإعلانات والمنتجات والميزانية قبل كل مناسبة ويضعها في الموافقات.'))}</h2>
    <label class="small"><input type="checkbox" data-f="auto_approve_occasions" ${draft.auto_approve_occasions ? 'checked' : ''}> ${L('Auto-approve occasions', 'موافقة تلقائية على المناسبات')}</label></header>
    <table><thead><tr><th>${L('On', 'مفعّل')}</th><th>${L('Occasion', 'المناسبة')}</th><th>${L('Next', 'القادمة')}</th><th>${L('Ready days before', 'جاهز قبل (أيام)')}</th><th>${L('Your offer', 'عرضك')}</th></tr></thead><tbody>
    ${list.map((x, i) => { const n = next[x.id]; return `<tr><td><input type="checkbox" data-occ="${i}" data-k="on" ${x.on ? 'checked' : ''}></td><td><b>${esc(tt(x.name, x.name_ar))}</b>${(x.countries || []).length ? ` <span class="chip">${esc(x.countries.join(' '))}</span>` : ''}<br><span class="small ink2">${esc(x.angle)} · ${esc(x.tone)}</span></td>
      <td class="small">${n ? `${esc(n.start)}${n.estimated ? ` <span class="muted">(${L('estimated', 'تقديري')})</span>` : ''}` : '—'}</td><td><input type="number" data-occ="${i}" data-k="lead_days" value="${x.lead_days}" min="0" max="60" style="width:60px"></td><td><input data-occ="${i}" data-k="offer" value="${esc(x.offer || '')}" placeholder="${esc(tt('none (no discount wording)', 'لا شيء'))}"></td></tr>`; }).join('')}</tbody></table>
    <div class="row" style="margin-top:10px"><input data-newocc="name" placeholder="${esc(tt('Your own occasion', 'مناسبتك الخاصة'))}"><input type="date" data-newocc="date"><button type="button" class="small" data-addocc>${L('Add', 'إضافة')}</button></div></div>`;
}

function agency(s) {
  return `<div class="card"><header><h2>${L('Our playbook — how agencies win on small budgets', 'منهجنا — كيف تربح الوكالات بميزانيات صغيرة')}</h2><span class="sub">${L('Meta = read on Meta\'s own pages; practice = practitioner heuristic', 'Meta = من صفحات ميتا؛ practice = خبرة عملية')}</span></header>
    <div class="list">${(s.agency || []).map((r) => `<div><span class="chip ${r.source === 'meta' ? 'acc' : ''}">${esc(r.source)}</span><span><b>${esc(tt(r.rule, r.rule_ar))}</b><br><span class="small ink2">${L('Why', 'السبب')}: ${esc(r.why)}</span><br><span class="small muted">${esc(r.ref)} · ${L('enforced', 'التطبيق')}: ${esc(r.enforced)}</span></span></div>`).join('')}</div></div>`;
}

export function render(S, el) {
  const c = cache.get(getAccount());
  if (c && draftFor !== getAccount()) { draft = JSON.parse(JSON.stringify(c.p.playbook)); draftFor = getAccount(); dirty = false; }
  if (!c || !draft) { el.innerHTML = '<div class="page"><div class="sk" style="height:42px"></div><div class="grid g2"><div class="sk" style="height:260px"></div><div class="sk" style="height:260px"></div></div></div>'; return; }
  const { s, p, o } = c;
  const body = { how: () => how(s, o), playbook: () => playbook(p, s), products: () => products(s), rules: () => rules(s), occasions: () => occasions(o), agency: () => agency(s) }[tab]();
  el.innerHTML = `<div class="page" data-strategy><div class="page-h"><div><h1>${L('Strategy', 'الاستراتيجية')}</h1><p>${L('How it decides, with this account\'s live numbers — and everything you can steer.', 'كيف يقرّر بأرقام هذا الحساب — وكل ما يمكنك توجيهه.')}</p></div>
    <div class="row">${dirty ? `<span class="chip warn">${L('unsaved', 'غير محفوظ')}</span>` : ''}<button type="button" class="ghost small" data-reset>${L('Reset playbook', 'إعادة التعليمات')}</button><button type="button" data-save ${dirty ? '' : 'disabled'}>${L('Save', 'حفظ')}</button></div></div>
    <div class="seg" style="margin-bottom:12px">${TABS.map(([id, en, ar]) => `<button type="button" data-tab="${id}" class="${tab === id ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div>${body}</div>`;
  wire(S, el);
}

function set(path, v) { const ks = path.split('.'); let o = draft; while (ks.length > 1) { const k = ks.shift(); o[k] = o[k] || {}; o = o[k]; } o[ks[0]] = v; dirty = true; }
function occList() { const c = cache.get(getAccount()); if (!draft.occasions || !draft.occasions.length) draft.occasions = JSON.parse(JSON.stringify(c.o.occasions)); return draft.occasions; }

function wire(S, el) {
  const re = () => render(S, el);
  el.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = (e) => { e.preventDefault(); tab = b.dataset.tab; re(); }; });
  el.querySelectorAll('[data-f]').forEach((i) => {
    i.oninput = i.onchange = () => {
      const f = i.dataset.f; let v = i.type === 'checkbox' ? i.checked : i.value;
      if (i.type === 'number' || i.type === 'range') v = Number(v);
      if (f === 'countries' || f === 'gifts') v = String(v).split(',').map((x) => x.trim()).filter(Boolean);
      set(f, v);
      if (f === 'freshness_pct') { const o = el.querySelector('[data-out="fresh"]'); if (o) o.textContent = `${v}%`; }
      const sv = el.querySelector('[data-save]'); if (sv) sv.disabled = false;
    };
  });
  el.querySelectorAll('[data-lang]').forEach((i) => { i.onchange = () => { const ls = new Set(draft.languages || []); if (i.checked) ls.add(i.dataset.lang); else ls.delete(i.dataset.lang); draft.languages = [...ls]; dirty = true; re(); }; });
  el.querySelectorAll('[data-econf]').forEach((i) => { i.onchange = () => { set('econ.' + i.dataset.econf, Number(i.value)); re(); }; });
  el.querySelectorAll('[data-split]').forEach((i) => { i.onchange = () => { set(i.dataset.split, Number(i.value)); re(); }; });
  el.querySelectorAll('[data-knob]').forEach((i) => { i.onchange = () => { const v = i.value === '' ? 0 : Number(i.value); set('knobs.' + i.dataset.knob, i.dataset.pct === '1' ? v / 100 : v); re(); }; });
  el.querySelectorAll('[data-tb]').forEach((i) => { i.onchange = () => { set('tune_bounds.' + i.dataset.tb, Number(i.value)); re(); }; });
  el.querySelectorAll('[data-tunev]').forEach((b) => { b.onclick = () => { set('self_tune', b.dataset.tunev); re(); }; });
  el.querySelectorAll('[data-level]').forEach((b) => { b.onclick = async () => {
    try {
      const r = await api('/api/playbook', { method: 'PUT', body: { knobs: { activity_level: b.dataset.level } } });
      draft.knobs = draft.knobs || {}; draft.knobs.activity_level = r.playbook.knobs.activity_level;
      const c = cache.get(getAccount()); c.p = r; c.v = await api('/api/volume').catch(() => c.v);
      toast(tt('Activity level saved: the plan follows at once. Logged in Activity.', 'حُفظ مستوى النشاط: تتبعه الخطة فوراً.')); re();
    } catch (e) { toast(e.message); }
  }; });
  const rz = el.querySelector('[data-raise]'); if (rz) rz.onclick = async () => {
    try { await api('/api/limits', { method: 'PUT', body: { max_active_ads: Number(rz.dataset.raise) } }); const c = cache.get(getAccount()); c.v = await api('/api/volume'); toast(tt('Max active ads raised (Settings → Limits).', 'رُفع الحد الأقصى للإعلانات النشطة.')); re(); } catch (e) { toast(e.message); }
  };
  const rk = el.querySelector('[data-resetknobs]'); if (rk) rk.onclick = () => { draft.knobs = {}; dirty = true; re(); };
  el.querySelectorAll('[data-rm]').forEach((b) => { b.onclick = () => { const [k, i] = b.dataset.rm.split(':'); draft[k].splice(Number(i), 1); dirty = true; re(); }; });
  el.querySelectorAll('[data-add]').forEach((b) => { b.onclick = () => {
    const kind = el.querySelector('[data-new="kind"]').value, value = el.querySelector('[data-new="value"]').value.trim();
    if (!value) return; (draft[b.dataset.add] = draft[b.dataset.add] || []).push({ kind, value }); dirty = true; re();
  }; });
  const pinAvoid = (attr, key) => el.querySelectorAll(`[${attr}]`).forEach((b) => { b.onclick = () => { (draft[key] = draft[key] || []).push({ kind: 'product', value: b.getAttribute(attr), label: b.dataset.label }); dirty = true; toast(tt('Added — press Save to apply.', 'أُضيف — اضغطي حفظ للتطبيق.')); re(); }; });
  pinAvoid('data-pin', 'focus'); pinAvoid('data-avoid', 'avoid');
  el.querySelectorAll('[data-occ]').forEach((i) => { i.onchange = () => { const l = occList(); const x = l[Number(i.dataset.occ)]; x[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.type === 'number' ? Number(i.value) : i.value; dirty = true; re(); }; });
  const ao = el.querySelector('[data-addocc]'); if (ao) ao.onclick = () => {
    const name = el.querySelector('[data-newocc="name"]').value.trim(), date = el.querySelector('[data-newocc="date"]').value;
    if (!name || !date) return toast(tt('Name and date, please.', 'الاسم والتاريخ من فضلك.'));
    occList().push({ name, name_ar: name, kind: 'fixed', month: Number(date.slice(5, 7)), day: Number(date.slice(8, 10)), days: 1, lead_days: 14, angle: name, tone: '', on: true, custom: true }); dirty = true; re();
  };
  const sv = el.querySelector('[data-save]'); if (sv) sv.onclick = () => save(S, el, draft);
  const rs = el.querySelector('[data-reset]'); if (rs) rs.onclick = () => save(S, el, { reset: true });
}

async function save(S, el, body) {
  try {
    const r = await api('/api/playbook', { method: 'PUT', body });
    cache.delete(getAccount()); draft = null;
    await load(); draft = JSON.parse(JSON.stringify(r.playbook)); draftFor = getAccount(); dirty = false;
    toast(tt('Saved — it applies at the next cycle. Logged in Activity.', 'تم الحفظ — يُطبّق في الدورة القادمة.'));
    render(S, el);
  } catch (e) { toast(e.message); }
}
