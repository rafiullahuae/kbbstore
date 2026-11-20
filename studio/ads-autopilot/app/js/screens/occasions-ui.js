// Approvals → Occasions: each occasion package the autopilot prepared in advance (engine /api/occasions): the dates
// (estimated for moon-sighted ones), every ad in Arabic and English with its picture and copy, the products, the
// budget ramp and the audience — Approve all, or reject / restore one ad. Nothing seasonal goes live without it.
import { tt } from '../i18n.js';
import { esc, toast } from '../ui.js';
import { api, getAccount, img, hydrate } from '../api.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id)));
export async function loadOccasions(force) {
  const k = getAccount();
  if (!force && cache.has(k)) return false;
  cache.set(k, await api('/api/occasions').catch(() => ({ packages: [], ads: {} })));
  return true;
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
  el.querySelectorAll('[data-occok]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try { const r = await api(`/api/jobs/${encodeURIComponent(b.dataset.occok)}/approve`, { method: 'POST', body: {} }); await after(r.run_error ? r.run_error : r.run ? tt('Approved and live: ', 'تمت الموافقة ونُشر: ') + Object.entries(r.run.counts || {}).map(([k, v]) => `${k} ${v}`).join(', ') : tt('Approved: it goes live at its time.', 'تمت الموافقة: يُنشر في موعده.')); } catch (e) { b.disabled = false; toast(e.message); }
  }; });
}
