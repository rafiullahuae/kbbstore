// The job queue on the Plan screen (engine /api/jobs): every upcoming job with its time, reason, budget, products,
// audience and format; Approve / Edit / Skip / Earlier / Later / Show the brief; "Ask Claude to replan" with a note.
import { tt } from '../i18n.js';
import { esc, aed, toast } from '../ui.js';
import { api, getAccount } from '../api.js';
import { pickProducts, dropCatalog } from './picker.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id)));
let editing = '', brief = null, busy = '', picked = null; // picked: the products chosen in the open editor (null = unchanged)
const KIND = { build_funnel: ['Funnel build', 'بناء المسار'], test: ['Creative test', 'اختبار إعلان'], refresh: ['Refresh', 'تجديد'], occasion: ['Occasion', 'مناسبة'],
  funnel_retargeting: ['Retargeting', 'إعادة استهداف'], funnel_existing: ['Existing customers', 'عملاء حاليون'], funnel_lookalike: ['Lookalikes', 'جمهور مشابه'] };
const STATUS = { planned: ['planned', 'مخطط'], approved: ['approved', 'موافق عليه'], skipped: ['skipped', 'متجاوز'], waiting: ['waiting', 'ينتظر'], done: ['done', 'تم'], failed: ['failed', 'فشل'] };

export async function loadJobs(force) {
  const k = getAccount();
  if (!force && cache.has(k)) return false;
  const [j, s] = await Promise.all([api('/api/jobs'), api('/api/brain/x/strategy').catch(() => ({ ranked: [] }))]);
  cache.set(k, { j, ranked: s.ranked || [] });
  return true;
}

const when = (t) => { const d = new Date(t); return d.toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };

const nameOf = (c, id) => (c.j.titles && c.j.titles[id]) || ((c.ranked || []).find((r) => r.id === id) || {}).title || id;

function editor(job, c) {
  const ids = picked || job.product_ids || [];
  return `<div class="card" data-editor style="margin-top:8px;background:var(--surface)"><header><h3>${esc(tt('Edit this job', 'تعديل هذه المهمة'))}</h3></header>
    <div class="grid g2"><div><label class="small">${esc(tt('Products (only what your playbook allows)', 'المنتجات (المسموح بها فقط)'))}</label>
      <div class="pk-chips">${ids.length ? ids.map((id, i) => `<span class="chip acc">${i + 1}. ${esc(nameOf(c, id))}</span>`).join('') : `<span class="small muted">${esc(tt('The autopilot picks the best-ranked ones.', 'يختار الطيار الآلي الأعلى ترتيباً.'))}</span>`}</div>
      <button type="button" class="ghost small" data-epick="${esc(job.id)}" style="margin-top:6px">${esc(tt('Choose products', 'اختاري المنتجات'))}</button></div>
    <div class="list small"><div><label>${esc(tt('Budget AED/day', 'الميزانية درهم/يوم'))} <input type="number" data-e="budget_aed" value="${job.budget_aed || ''}" min="0" style="width:90px"></label></div>
      <div><label>${esc(tt('Language', 'اللغة'))} <select data-e="lang"><option value="ar" ${job.lang !== 'en' ? 'selected' : ''}>العربية</option><option value="en" ${job.lang === 'en' ? 'selected' : ''}>English</option></select></label>
      <label>${esc(tt('Format', 'الشكل'))} <select data-e="format"><option value="image" ${job.format !== 'carousel' ? 'selected' : ''}>${esc(tt('Image', 'صورة'))}</option><option value="carousel" ${job.format === 'carousel' ? 'selected' : ''}>${esc(tt('Carousel', 'دوّار'))}</option></select></label></div>
      <div style="display:block"><label>${esc(tt('Copy direction', 'اتجاه النص'))}</label><input data-e="copy_direction" value="${esc(job.copy_direction || '')}" style="width:100%" dir="auto"></div>
      <div style="display:block"><label>${esc(tt('Your instruction for this job (goes into the brief)', 'تعليماتك لهذه المهمة (تُضاف إلى الموجز)'))}</label><textarea data-e="note" rows="2" dir="auto">${esc(job.note || '')}</textarea></div></div></div>
    <div class="row"><button type="button" data-esave="${esc(job.id)}">${esc(tt('Save changes', 'حفظ التغييرات'))}</button><button type="button" class="ghost" data-ecancel>${esc(tt('Cancel', 'إلغاء'))}</button></div></div>`;
}

export function jobsCard(S) {
  const c = cache.get(getAccount());
  if (!c) return '<div class="card"><div class="sk" style="height:180px"></div></div>';
  const { j } = c;
  const open = (j.jobs || []).filter((x) => ['planned', 'approved', 'waiting'].includes(x.status));
  const past = (j.jobs || []).filter((x) => !['planned', 'approved', 'waiting'].includes(x.status)).slice(-6).reverse();
  const practice = j.mode !== 'auto';
  const row = (x) => `<div data-job="${esc(x.id)}"><span class="chip ${x.status === 'approved' ? 'acc' : x.status === 'waiting' || x.status === 'failed' ? 'warn' : ''}">${esc(tt(...(STATUS[x.status] || [x.status, x.status])))}</span>
    <span style="flex:1"><b>${esc(tt(x.title, x.title_ar || x.title))}</b> <span class="chip">${esc(tt(...(KIND[x.kind] || [x.kind, x.kind])))}</span><br>
    <span class="small">${esc(when(x.when))}${x.budget_aed ? ` · ${aed(x.budget_aed)}/${esc(tt('day', 'يوم'))}` : ''}${x.lang ? ` · ${esc(x.lang.toUpperCase())}` : ''}${x.format ? ` · ${esc(x.format)}` : ''}${(x.product_ids || []).length ? ` · ${esc(tt('products', 'منتجات'))}: ${esc(x.product_ids.map((id) => nameOf(c, id)).join(' · '))}` : ''}</span><br>
    <span class="small ink2">${esc(x.why || '')}</span>${x.audience ? `<br><span class="small muted">${esc(tt('Audience', 'الجمهور'))}: ${esc(x.audience)}</span>` : ''}
    ${x.waiting ? `<br><span class="small" style="color:var(--warn)">${esc(tt('Waiting', 'ينتظر'))}: ${esc(x.products_gone ? tt(x.waiting, 'كل منتجات هذه المهمة مستبعدة — اختاري المنتجات') : x.waiting)}</span>` : ''}${x.products_gone ? ` <button type="button" class="small" data-jpick="${esc(x.id)}">${esc(tt('Choose products', 'اختاري المنتجات'))}</button>` : ''}${x.note ? `<br><span class="small">✎ ${esc(x.note)}</span>` : ''}${x.result ? `<br><span class="small muted">${esc(x.result)}</span>` : ''}
    ${editing === x.id ? editor(x, c) : ''}${brief && brief.job === x.id ? briefBox(brief) : ''}</span>
    <span class="row" style="max-width:260px;justify-content:flex-end">
      ${x.status !== 'approved' ? `<button type="button" class="small" data-jact="approve" data-id="${esc(x.id)}" ${busy ? 'disabled' : ''}>${esc(tt(practice || x.kind === 'occasion' ? 'Approve' : 'Approve', 'موافقة'))}</button>` : ''}
      <button type="button" class="ghost small" data-jedit="${esc(x.id)}">${esc(tt('Edit', 'تعديل'))}</button>
      <button type="button" class="ghost small" data-jact="skip" data-id="${esc(x.id)}">${esc(tt('Skip', 'تجاوز'))}</button>
      <button type="button" class="ghost small" data-move="-24" data-id="${esc(x.id)}" title="${esc(tt('a day earlier', 'قبل يوم'))}">◀ ${esc(tt('Earlier', 'أبكر'))}</button>
      <button type="button" class="ghost small" data-move="24" data-id="${esc(x.id)}" title="${esc(tt('a day later', 'بعد يوم'))}">${esc(tt('Later', 'لاحقاً'))} ▶</button>
      <button type="button" class="ghost small" data-brief="${esc(x.id)}">${esc(tt('Show the brief', 'عرض الموجز'))}</button></span></div>`;
  return `<div class="card" data-jobs><header><h2>${esc(tt('Upcoming jobs — yours to change', 'المهام القادمة — يمكنك تغييرها'))}</h2><span class="sub">${esc(practice ? tt('Practice: due jobs wait for your Approve', 'التدريب: المهام المستحقة تنتظر موافقتك') : tt('Auto: each runs at its time unless you skip it', 'تلقائي: تُنفّذ كل مهمة في وقتها ما لم تتجاوزيها'))}</span></header>
    ${open.length ? `<div class="list">${open.map(row).join('')}</div>` : `<p class="muted">${esc(tt('No upcoming jobs. Run a cycle to build the plan.', 'لا مهام قادمة. شغّلي دورة لبناء الخطة.'))}</p>`}
    <div class="row" style="margin-top:12px"><textarea data-replan rows="2" placeholder="${esc(tt('Tell Claude what to change, e.g. "more sunscreen, less toner, test a carousel"', 'أخبري Claude بما يجب تغييره'))}" dir="auto" style="flex:1">${esc(j.replan_note || '')}</textarea><button type="button" data-replango ${busy ? 'disabled' : ''}>${esc(tt('Ask Claude to replan', 'اطلبي من Claude إعادة التخطيط'))}</button></div>
    ${past.length ? `<details style="margin-top:10px"><summary class="small">${esc(tt('Recent jobs', 'المهام الأخيرة'))}</summary><div class="list">${past.map((x) => `<div><span class="chip">${esc(x.status)}</span><span class="small"><b>${esc(x.title)}</b> · ${esc(when(x.updated_at || x.when))}<br>${esc(x.result || (x.log || []).slice(-1).map((l) => l.text).join(''))}</span></div>`).join('')}</div></details>` : ''}</div>`;
}

function briefBox(b) {
  return `<div class="card" style="margin-top:8px;background:var(--surface)"><header><h3>${esc(tt('The brief Claude gets (read-only)', 'الموجز الذي يتلقاه Claude (للقراءة)'))}</h3></header>
    <p class="small"><b>${esc(tt('Products', 'المنتجات'))}:</b> ${esc((b.products || []).join(' · '))}</p>
    <pre class="report" dir="auto" style="white-space:pre-wrap;max-height:180px;overflow:auto">${esc(b.owner_brief || '')}</pre>
    ${b.copy_user ? `<details><summary class="small">${esc(tt('The full copy request', 'طلب النص الكامل'))}</summary><pre class="report" style="white-space:pre-wrap;max-height:240px;overflow:auto">${esc(b.copy_user)}</pre></details>` : ''}
    ${b.copy_system ? `<details><summary class="small">${esc(tt('The copywriter\'s standing rules', 'قواعد كاتب النصوص'))}</summary><pre class="report" style="white-space:pre-wrap;max-height:240px;overflow:auto">${esc(b.copy_system)}</pre></details>` : ''}
    <p class="small muted">${esc(tt('Add your own words with Edit → "Your instruction for this job": they become part of this brief.', 'أضيفي كلماتك عبر تعديل ← «تعليماتك لهذه المهمة».'))}</p></div>`;
}

export function wireJobs(S, el, rerender) {
  const after = async (msg) => { busy = ''; editing = ''; picked = null; await loadJobs(true); if (msg) toast(msg); rerender(); };
  el.querySelectorAll('[data-jact]').forEach((b) => { b.onclick = async () => {
    busy = b.dataset.id; rerender();
    try { const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.id)}/${b.dataset.jact}`, { method: 'POST', body: {} }); await after(r.run_error ? r.run_error : r.run ? tt('Ran now: ', 'نُفّذت الآن: ') + Object.entries(r.run.counts || {}).map(([k, v]) => `${k} ${v}`).join(', ') : tt('Done.', 'تم.')); } catch (e) { await after(e.message); }
  }; });
  el.querySelectorAll('[data-move]').forEach((b) => { b.onclick = async () => { try { await api(`/api/jobs/${encodeURIComponent(b.dataset.id)}`, { method: 'PUT', body: { move_hours: Number(b.dataset.move) } }); await after(tt('Moved.', 'تم النقل.')); } catch (e) { await after(e.message); } }; });
  el.querySelectorAll('[data-jedit]').forEach((b) => { b.onclick = () => { editing = editing === b.dataset.jedit ? '' : b.dataset.jedit; brief = null; picked = null; rerender(); }; });
  el.querySelectorAll('[data-ecancel]').forEach((b) => { b.onclick = () => { editing = ''; picked = null; rerender(); }; });
  const jobOf = (id) => ((cache.get(getAccount()) || { j: {} }).j.jobs || []).find((x) => x.id === id) || {};
  const pick = (id) => pickProducts({ title: tt(`Products for: ${jobOf(id).title || id}`, `منتجات: ${jobOf(id).title_ar || jobOf(id).title || id}`), selected: picked || jobOf(id).product_ids || [], max: 10,
    hint: tt('Only these run in this job, in this order.', 'هذه فقط تُنفّذ في هذه المهمة، بهذا الترتيب.') });
  el.querySelectorAll('[data-epick]').forEach((b) => { b.onclick = async () => { const ids = await pick(b.dataset.epick); if (ids) { picked = ids; rerender(); } }; });
  el.querySelectorAll('[data-jpick]').forEach((b) => { b.onclick = async () => {
    const ids = await pick(b.dataset.jpick); if (!ids) return;
    try { await api(`/api/jobs/${encodeURIComponent(b.dataset.jpick)}`, { method: 'PUT', body: { product_ids: ids } }); dropCatalog(); await after(tt('Saved: it runs with your products.', 'تم الحفظ: تُنفّذ بمنتجاتك.')); } catch (e) { toast(e.message); }
  }; });
  el.querySelectorAll('[data-esave]').forEach((b) => { b.onclick = async () => {
    const box = b.closest('[data-editor]'); const body = {};
    if (picked) body.product_ids = picked;
    const bud = box.querySelector('[data-e="budget_aed"]').value; if (bud !== '') body.budget_aed = Number(bud);
    for (const k of ['lang', 'format', 'copy_direction', 'note']) body[k] = box.querySelector(`[data-e="${k}"]`).value;
    try { await api(`/api/jobs/${encodeURIComponent(b.dataset.esave)}`, { method: 'PUT', body }); await after(tt('Saved: it runs as you edited it.', 'تم الحفظ: ستُنفّذ كما عدّلتِها.')); } catch (e) { toast(e.message); }
  }; });
  el.querySelectorAll('[data-brief]').forEach((b) => { b.onclick = async () => { if (brief && brief.job === b.dataset.brief) { brief = null; return rerender(); } try { brief = await api(`/api/jobs/${encodeURIComponent(b.dataset.brief)}/brief`); editing = ''; rerender(); } catch (e) { toast(e.message); } }; });
  const rp = el.querySelector('[data-replango]'); if (rp) rp.onclick = async () => {
    const note = el.querySelector('[data-replan]').value; // read before the re-render replaces the box
    busy = 'replan'; rerender();
    try { await api('/api/jobs/replan', { method: 'POST', body: { note } }); await after(tt('Replanned with your note (nothing was written to Meta).', 'أُعيد التخطيط بملاحظتك (لم يُكتب شيء في ميتا).')); } catch (e) { await after(e.message); }
  };
}
