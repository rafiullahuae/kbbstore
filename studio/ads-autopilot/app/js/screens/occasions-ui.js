// Approvals → Occasions: each occasion package the autopilot prepared in advance (engine /api/occasions): the dates
// (estimated for moon-sighted ones), every ad in Arabic and English with its picture and copy, the products, the
// budget ramp and the audience — Approve all, or reject / restore one ad. Nothing seasonal goes live without it.
import { tt } from '../i18n.js';
import { esc, aed, toast } from '../ui.js';
import { api, getAccount, img, hydrate } from '../api.js';
import { pickProducts, wireChips, dropCatalog } from './picker.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id)));
export async function loadOccasions(force) {
  const k = getAccount();
  if (!force && cache.has(k)) return false;
  cache.set(k, await api('/api/occasions').catch(() => ({ packages: [], ads: {} })));
  return true;
}

const short = (t) => { const s = String(t || ''); const i = s.lastIndexOf(' - '); return (i > 20 ? s.slice(0, i) : s).slice(0, 60); };

// slotCards: the package's product slots (default 5, Strategy → Occasions): photo, units sold in 30 / 90 days, New /
// Trending / pinned, why it fits ("rich texture · barrier · winter"), ✕ (not for this occasion: the next suggestion
// takes the slot), 📌 pin, ⊘ never promote. Empty slots show as dashed boxes until Find products fills them.
function slotCards(j, picks, n) {
  const cards = picks.map((p) => {
    const bad = /can no longer run/.test(p.reason || '');
    return `<div class="slot${bad ? ' bad' : ''}" data-slot="${esc(p.id)}"><img data-src="${esc(img(p.image || ''))}" alt="" width="48" height="48" loading="lazy">
      <span><b dir="auto" title="${esc(p.title)}">${p.pinned ? '📌 ' : ''}${esc(short(p.title))}</b>
        <span class="small muted">${p.price ? `${esc(aed(p.price))} · ` : ''}${esc(tt(`sold ${p.units_30d || 0} / ${p.units_90d || 0}`, `مبيع ${p.units_30d || 0} / ${p.units_90d || 0}`))} <span title="${esc(tt('units sold in the last 30 / 90 days', 'الوحدات المباعة في آخر ٣٠ / ٩٠ يوماً'))}">(30/90 ${esc(tt('d', 'ي'))})</span></span>
        <span class="pk-b">${p.new ? `<span class="chip acc">${esc(tt('New', 'جديد'))}</span>` : ''}${p.trending ? `<span class="chip acc">${esc(tt('Trending', 'رائج'))}</span>` : ''}</span>
        <span class="small why" dir="auto">${esc(tt(p.reason || '', p.reason_ar || p.reason || ''))}</span></span>
      <span class="acts"><button type="button" data-slotrm="${esc(p.id)}" data-job="${esc(j.id)}" data-t="${esc(p.title)}" title="${esc(tt('Remove from this occasion: the next suggestion takes its place', 'إزالة من هذه المناسبة: يحل محله المقترح التالي'))}" aria-label="${esc(tt('Remove', 'إزالة'))}">✕</button><button type="button" data-pin="${esc(p.id)}" data-t="${esc(p.title)}" title="${esc(p.pinned ? tt('Unpin', 'إلغاء التثبيت') : tt('Pin: always considered, ranked first', 'تثبيت: يُقدَّم دائماً'))}" aria-label="${esc(tt('Pin', 'تثبيت'))}">📌</button><button type="button" data-avoid="${esc(p.id)}" data-t="${esc(p.title)}" title="${esc(tt('Never promote this product (everywhere)', 'لا تروّج لهذا المنتج أبداً (في كل مكان)'))}" aria-label="${esc(tt('Never promote', 'لا تروّج أبداً'))}">⊘</button></span></div>`;
  });
  for (let i = picks.length; i < n; i++) cards.push(`<div class="slot empty small">${esc(tt('Empty slot — Find products fills it', 'خانة فارغة — «ابحث عن منتجات» يملؤها'))}</div>`);
  return `<div class="slots" data-slots="${esc(j.id)}">${cards.join('')}</div>`;
}

export function occasionsCard() {
  const o = cache.get(getAccount());
  if (!o) return '';
  const pk = (o.packages || []).filter((j) => ['planned', 'approved', 'waiting'].includes(j.status));
  const next = (o.upcoming || []).filter((u) => (o.occasions || []).some((x) => x.id === u.occasion && x.on)).slice(0, 4);
  return `<div class="card" data-occasions><header><h2>${esc(tt('Occasions', 'المناسبات'))}</h2><span class="sub">${esc(o.auto_approve ? tt('auto-approve is ON', 'الموافقة التلقائية مفعّلة') : tt('nothing seasonal goes live without your approval', 'لا شيء موسمي يُنشر دون موافقتك'))}</span></header>
  ${pk.length ? pk.map((j) => { const ads = (o.ads || {})[j.id] || []; const rej = new Set(j.rejected || []); const oc = j.occasion || {};
    return `<div class="card" style="background:var(--surface);margin-bottom:10px" data-pkg="${esc(j.id)}"><header><h3>${esc(tt(j.title, j.title_ar))}</h3><span class="chip ${j.status === 'approved' ? 'acc' : 'warn'}">${esc(j.status)}</span></header>
      <p class="small">${esc(oc.start || '')} → ${esc(oc.end || '')}${oc.estimated ? ` <span class="muted">(${esc(tt('estimated date: it follows the moon sighting', 'تاريخ تقديري: يتبع رؤية الهلال'))})</span>` : ''} · ${esc(tt('goes live', 'يُنشر'))} ${esc(new Date(j.when).toLocaleDateString())} · ${esc(j.audience || '')}</p>
      <p class="small ink2">${esc(j.why || '')}</p>
      ${j.raise_to ? `<div class="alert warn small" data-occraise-box>${esc(tt(`It waits: ${j.waiting || ''}.`, `ينتظر: إعلاناتها لا تتسع تحت الحد الأقصى للإعلانات النشطة. ارفعيه إلى ${j.raise_to} ليُنشر كاملاً.`))} <button type="button" class="small" data-occraise="${esc(j.id)}" data-n="${esc(j.raise_to)}">${esc(tt(`Raise to ${j.raise_to} and go live`, `ارفعيه إلى ${j.raise_to} وانشري`))}</button></div>` : ''}
      ${j.products_gone ? `<p class="small pk-wait">${esc(tt(j.waiting || '', 'كل منتجات هذه المهمة مستبعدة — اختاري المنتجات'))}</p>` : ''}
      ${(() => { const picks = (o.picks || {})[j.id] || (j.product_ids || []).map((id) => ({ id, title: (o.titles || {})[id] || id })); const n = Math.max(j.slots || o.slots || 5, picks.length);
        return `<div class="row" style="justify-content:space-between"><b class="small">${esc(tt(`Products ${picks.length} of ${n}`, `المنتجات ${picks.length} من ${n}`))}</b><span class="row"><button type="button" class="small" data-occfind="${esc(j.id)}" title="${esc(tt('Reads the shop again (sales, stock, new arrivals) and fills every empty slot; your pinned and kept products stay', 'يقرأ المتجر من جديد (المبيعات، المخزون، الجديد) ويملأ كل خانة فارغة؛ منتجاتك المثبتة والمحتفظ بها تبقى'))}">${esc(tt('Find products', 'ابحث عن منتجات'))}</button><button type="button" class="ghost small" data-occpick="${esc(j.id)}">${esc(tt('Change products', 'تغيير المنتجات'))}</button></span></div>${slotCards(j, picks, n)}`; })()}
      <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">${ads.map((a) => `<div class="card" style="padding:10px;${rej.has(a.id) ? 'opacity:.45' : ''}"><img data-src="${esc(img(a.image_url || ''))}" alt="" loading="lazy" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;background:var(--surface3)">
        <span class="chip">${esc((a.lang || '').toUpperCase())}</span><p class="small" dir="auto"><b>${esc(a.headline)}</b><br>${esc(a.primary_text)}</p>
        <button type="button" class="ghost small" data-occad="${rej.has(a.id) ? 'restore-ad' : 'reject-ad'}" data-job="${esc(j.id)}" data-cr="${esc(a.id)}">${esc(rej.has(a.id) ? tt('Put back', 'إرجاع') : tt('Reject this ad', 'رفض هذا الإعلان'))}</button></div>`).join('')}</div>
      <div class="row" style="margin-top:8px">${j.status !== 'approved' ? `<button type="button" data-occok="${esc(j.id)}">${esc(tt('Approve all', 'الموافقة على الكل'))}</button>` : ''}<a class="small" href="#/plan">${esc(tt('Edit products, copy direction or dates on the Plan', 'تعديل المنتجات أو النص أو التواريخ من الخطة'))}</a></div></div>`; }).join('')
    : `<p class="muted small">${esc(tt('No occasion package waiting. Next:', 'لا حزم مناسبات بانتظارك. القادمة:'))} ${next.map((u) => `${esc(tt(u.name, u.name_ar))} ${esc(u.start)}${u.estimated ? '*' : ''} (${esc(tt('prepared from', 'تُحضّر من'))} ${esc(u.prep_from)})`).join(' · ') || '—'}</p>`}</div>`;
}

export function wireOccasions(el, rerender) {
  hydrate(el); // the pictures come with the session token (blob: URLs)
  const after = async (msg) => { await loadOccasions(true); if (msg) toast(msg); rerender(); };
  el.querySelectorAll('[data-occad]').forEach((b) => { b.onclick = async () => { try { await api(`/api/jobs/${encodeURIComponent(b.dataset.job)}/${b.dataset.occad}`, { method: 'POST', body: { creative_id: b.dataset.cr } }); await after(); } catch (e) { toast(e.message); } }; });
  wireChips(el.querySelector('[data-occasions]') || el, async () => { await loadOccasions(true); rerender(); });
  el.querySelectorAll('[data-occpick]').forEach((b) => { b.onclick = async () => {
    const j = ((cache.get(getAccount()) || {}).packages || []).find((x) => x.id === b.dataset.occpick) || {};
    const ids = await pickProducts({ title: tt(`Products for: ${j.title || ''}`, `منتجات: ${j.title_ar || j.title || ''}`), selected: j.product_ids || [], max: 8,
      hint: tt('Its ads are prepared again for these products, in Arabic and English.', 'تُحضَّر إعلاناتها من جديد لهذه المنتجات، بالعربية والإنجليزية.') });
    if (!ids) return;
    try { await api(`/api/jobs/${encodeURIComponent(j.id)}`, { method: 'PUT', body: { product_ids: ids } }); dropCatalog(); await after(tt('Prepared again with your products.', 'حُضّرت من جديد بمنتجاتك.')); } catch (e) { toast(e.message); }
  }; });
  const changed = (r, removed) => {
    const a = r.added || [];
    if (!a.length && !(r.removed || []).length) return tt('Nothing changed: these are still the best products.', 'لا تغيير: هذه ما زالت أفضل المنتجات.');
    const rm = removed ? tt(`Removed ${short(removed)}. `, `أُزيل ${short(removed)}. `) : (r.removed || []).length ? tt(`Replaced ${r.removed.length} that can no longer run (${r.removed_titles}). `, `استُبدل ${r.removed.length} لم يعد متاحاً (${r.removed_titles}). `) : '';
    return rm + (a.length ? tt(`Added ${a.length}: ${r.added_titles}.`, `أُضيف ${a.length}: ${r.added_titles}.`) : tt(`No other product fits: ${r.filled} of ${r.slots}.`, `لا منتج آخر مناسب: ${r.filled} من ${r.slots}.`));
  };
  el.querySelectorAll('[data-slotrm]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try { const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.job)}/remove-product`, { method: 'POST', body: { product_id: b.dataset.slotrm } }); await after(changed(r, b.dataset.t)); } catch (e) { b.disabled = false; toast(e.message); }
  }; });
  el.querySelectorAll('[data-occfind]').forEach((b) => { b.onclick = async () => {
    b.disabled = true; b.textContent = tt('Finding…', 'جارٍ البحث…');
    try { const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.occfind)}/find-products`, { method: 'POST', body: {} }); dropCatalog(); await after(changed(r)); } catch (e) { b.disabled = false; toast(e.message); }
  }; });
  // an approved package that does not fit under Max active ads: raise it to the number that fits, then run it whole
  el.querySelectorAll('[data-occraise]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try {
      await api('/api/limits', { method: 'PUT', body: { max_active_ads: Number(b.dataset.n) } });
      const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.occraise)}/approve`, { method: 'POST', body: {} });
      await after(r.run_error ? r.run_error : tt(`Max active ads is ${b.dataset.n}: the package is live.`, `الحد الأقصى ${b.dataset.n}: الحزمة منشورة.`));
    } catch (e) { b.disabled = false; toast(e.message); }
  }; });
  el.querySelectorAll('[data-occok]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try { const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.occok)}/approve`, { method: 'POST', body: {} }); await after(r.run_error ? r.run_error : r.run ? tt('Approved and live: ', 'تمت الموافقة ونُشر: ') + Object.entries(r.run.counts || {}).map(([k, v]) => `${k} ${v}`).join(', ') : tt('Approved: it goes live at its time.', 'تمت الموافقة: يُنشر في موعده.')); } catch (e) { b.disabled = false; toast(e.message); }
  }; });
}
