// Preview before it runs (the owner, 22 Nov: "for run, i want a popup by seeing the final things which will actually
// run. incase i don't like anything, i can re-generate instantely or remove any product etc.").
// Run a cycle (Auto) → the engine plans the whole cycle and checks every change WITHOUT sending anything to Meta
// (engine/preview.go) → this window shows every ad that would go live: picture, words, product, ad set, budget,
// landing page, Page / Instagram, and what the checks said. Per ad: Remove · Other product (the next product takes
// its place) · Never promote (the product leaves for good). Run sends only what is left; Cancel sends nothing.
import { api, hydrate, img } from '../api.js';
import { tt } from '../i18n.js';
import { esc, toast, aed } from '../ui.js';

const P = { skipCr: new Set(), skipPr: new Set(), data: null, busy: false };
export const previewState = P; // for checks
if (typeof window !== 'undefined') window.__kaaPreview = P;

function dlg() {
  let d = document.getElementById('pv');
  if (!d) {
    d = document.createElement('dialog');
    d.id = 'pv'; d.className = 'cc';
    document.body.appendChild(d);
  }
  return d;
}

async function load(d) {
  P.busy = true;
  d.innerHTML = `<div class="dl"><h2>${esc(tt('Preparing what will run…', 'تجهيز ما سيُنشر…'))}</h2><p class="ink2">${esc(tt('Reading Meta and your shop, planning and checking every change. Nothing is sent to Meta. This can take a few minutes.', 'قراءة Meta ومتجرك والتخطيط وفحص كل تغيير. لا يُرسل شيء إلى Meta. قد يستغرق بضع دقائق.'))}</p><div class="sk" style="height:160px"></div></div>`;
  try {
    P.data = await api('/api/cycle', { method: 'POST', body: { mode: 'preview', skip_creatives: [...P.skipCr], skip_products: [...P.skipPr] } });
  } catch (e) { P.data = { error: String(e.message || e) }; }
  P.busy = false;
  paint(d);
}

const CHECK = { ok: ['✓ ready', '✓ جاهز', 'acc'], waits: ['waits', 'ينتظر', 'warn'], refused: ['✕ refused', '✕ مرفوض', 'bad'] };

function paint(d) {
  const r = P.data || {};
  const pv = r.preview || { ads: [], others: [] };
  const ads = (pv.ads || []).filter((a) => !P.skipCr.has(a.creative_id));
  const ok = ads.filter((a) => a.check === 'ok').length;
  const first = (pv.ads || [])[0] || {};
  const errs = ['sync_error', 'error', 'safety_error'].map((k) => r[k]).filter(Boolean);
  d.innerHTML = `<div class="dl" style="max-height:88vh;overflow:auto">
    <header class="row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0">${esc(tt('What will run now', 'ما سيُنشر الآن'))}</h2>
      <span class="small muted">${esc(first.page ? tt(`From ${first.page} · ${first.instagram}`, `من ${first.page} · ${first.instagram}`) : '')}</span></header>
    ${r.error && !r.preview ? `<p class="alert warn">${esc(r.error)}</p>` : ''}
    ${errs.map((e) => `<p class="alert warn small">${esc(e)}</p>`).join('')}
    <p class="small ink2">${esc(tt(`${ads.length} ad(s) would go live${ads.length !== ok ? ` (${ads.length - ok} would be refused or wait: the reason is on each)` : ''}. Nothing has been sent to Meta yet.`, `${ads.length} إعلان سيُنشر. لم يُرسل شيء إلى Meta بعد.`))}</p>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px">${ads.map((a) => card(a)).join('') || `<p class="muted">${esc(tt('No new ad this time.', 'لا إعلان جديد هذه المرة.'))}</p>`}</div>
    ${(pv.others || []).length ? `<details style="margin-top:12px" open><summary class="small"><b>${esc(tt(`Other changes (${pv.others.length})`, `تغييرات أخرى (${pv.others.length})`))}</b></summary><ul class="small" style="margin:6px 0 0;padding-inline-start:18px">${pv.others.map((o) => `<li>${esc(o.title)} — <span class="${o.check === 'ok' ? '' : 'muted'}">${esc(o.check === 'ok' ? tt('ready', 'جاهز') : (o.reason || o.check))}</span></li>`).join('')}</ul></details>` : ''}
    ${P.skipCr.size || P.skipPr.size ? `<p class="small muted">${esc(tt(`Left out: ${P.skipCr.size} ad(s), ${P.skipPr.size} product(s) for this run.`, `مستبعد: ${P.skipCr.size} إعلان، ${P.skipPr.size} منتج لهذا التشغيل.`))} <button type="button" class="link" data-pv-reset>${esc(tt('Put them back', 'إرجاعها'))}</button></p>` : ''}
    <div class="row" style="justify-content:flex-end;gap:8px;margin-top:14px;flex-wrap:wrap">
      <button type="button" class="ghost" data-pv-cancel>${esc(tt('Cancel — send nothing', 'إلغاء — لا إرسال'))}</button>
      <button type="button" class="ghost" data-pv-refresh>↻ ${esc(tt('Prepare again', 'جهّزي من جديد'))}</button>
      <button type="button" data-pv-run>${esc(tt(`Run ${ads.length ? ads.length + ' ad(s) ' : ''}now`, `انشري ${ads.length ? ads.length + ' إعلان ' : ''}الآن`))}</button></div></div>`;
  hydrate(d);
  d.querySelector('[data-pv-cancel]').onclick = () => { d.close(); toast(tt('Cancelled: nothing was sent to Meta.', 'أُلغي: لم يُرسل شيء إلى Meta.')); };
  d.querySelector('[data-pv-refresh]').onclick = () => load(d);
  const rs = d.querySelector('[data-pv-reset]'); if (rs) rs.onclick = () => { P.skipCr.clear(); P.skipPr.clear(); load(d); };
  d.querySelector('[data-pv-run]').onclick = () => run(d);
  d.querySelectorAll('[data-pv-remove]').forEach((b) => { b.onclick = () => { P.skipCr.add(b.dataset.pvRemove); paint(d); }; });
  d.querySelectorAll('[data-pv-other]').forEach((b) => { b.onclick = () => { P.skipPr.add(b.dataset.pvOther); load(d); }; });
  d.querySelectorAll('[data-pv-never]').forEach((b) => { b.onclick = async () => {
    try {
      const cur = await api('/api/playbook');
      const avoid = [...(((cur || {}).playbook || {}).avoid || []), { kind: 'product', value: b.dataset.pvNever, label: b.dataset.title || '' }];
      await api('/api/playbook', { method: 'PUT', body: { avoid } });
      toast(tt('Never promoted again (Strategy → Products shows it).', 'لن يُروَّج له مجدداً.'));
      load(d);
    } catch (e) { toast(e.message); }
  }; });
}

function card(a) {
  const [lbl, lblAr, cls] = CHECK[a.check] || CHECK.waits;
  const pic = a.image ? (a.image.startsWith('/api/') ? `<img data-src="${esc(a.image)}" alt="">` : `<img data-src="${esc(img(a.image))}" alt="">`) : '<div class="sk" style="height:100%"></div>';
  const pid = (a.products || [])[0], title = (a.titles || [])[0] || '';
  const rtl = a.lang === 'ar';
  return `<div class="card" style="padding:10px;display:flex;flex-direction:column;gap:6px" data-pv-ad="${esc(a.creative_id)}">
    <div style="aspect-ratio:${a.format === '9:16' ? '9/16' : a.format === '4:5' ? '4/5' : '1'};max-height:260px;border-radius:10px;overflow:hidden;background:var(--surface3)">${pic.replace('<img ', '<img style="width:100%;height:100%;object-fit:cover" ')}</div>
    <div class="row small" style="gap:6px;flex-wrap:wrap"><span class="chip ${cls}">${esc(tt(lbl, lblAr))}</span><span class="chip">${esc((a.lang || '').toUpperCase() || '—')}</span>${a.budget_aed ? `<span class="chip" title="${esc(tt('The budget its campaign / ad set shares between its ads', 'ميزانية الحملة / المجموعة المشتركة بين إعلاناتها'))}">${esc(tt('campaign', 'الحملة'))} ${esc(aed(a.budget_aed))}/${esc(tt('day', 'يوم'))}</span>` : ''}</div>
    <b class="small" ${rtl ? 'dir="rtl" lang="ar"' : ''}>${esc(a.headline || a.name)}</b>
    <span class="small ink2" ${rtl ? 'dir="rtl" lang="ar"' : ''} style="display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden">${esc(a.text || '')}</span>
    ${title ? `<span class="small"><b>${esc(tt('Product', 'المنتج'))}:</b> ${esc(title)}</span>` : ''}
    <span class="small muted">${esc(a.adset || '')}${a.campaign ? ' · ' + esc(a.campaign) : ''}</span>
    <span class="small muted" style="overflow-wrap:anywhere">→ ${esc(a.link || '')}</span>
    ${a.check !== 'ok' && a.why ? `<span class="small" style="color:var(--warn)">${esc(a.why)}</span>` : ''}
    <div class="row" style="gap:6px;flex-wrap:wrap;margin-top:auto">
      <button type="button" class="ghost small" data-pv-remove="${esc(a.creative_id)}">✕ ${esc(tt('Remove', 'إزالة'))}</button>
      ${pid ? `<button type="button" class="ghost small" data-pv-other="${esc(pid)}" title="${esc(tt('The next product takes its place', 'المنتج التالي يأخذ مكانه'))}">↻ ${esc(tt('Other product', 'منتج آخر'))}</button>
      <button type="button" class="ghost small" data-pv-never="${esc(pid)}" data-title="${esc(title)}" title="${esc(tt('Never promote this product again', 'لا تروّجي لهذا المنتج مجدداً'))}">⊘ ${esc(tt('Never promote', 'لا تروّجي'))}</button>` : ''}</div></div>`;
}

async function run(d) {
  const btn = d.querySelector('[data-pv-run]');
  btn.disabled = true; btn.textContent = tt('Running… (sending to Meta)', 'يُنشر… (إرسال إلى Meta)');
  try {
    const r = await api('/api/cycle', { method: 'POST', body: { mode: 'auto', skip_creatives: [...P.skipCr], skip_products: [...P.skipPr] } });
    d.close();
    const c = (r && r.counts) || {};
    const bad = (c.failed || 0) + (c.refused || 0);
    toast(tt(`Done: ${(c.done || 0) + (c.duplicate || 0)} change(s) made on Meta${bad ? `, ${bad} not done (Overview → Last cycle says why)` : ''}. Meta reviews new ads before they show as Active.`, `تم: ${(c.done || 0) + (c.duplicate || 0)} تغيير على Meta${bad ? `، ${bad} لم يتم` : ''}.`));
    P.skipCr.clear(); P.skipPr.clear();
    return r;
  } catch (e) { btn.disabled = false; btn.textContent = tt('Run now', 'انشري الآن'); toast(tt('Not run: ', 'لم يُنشر: ') + e.message); }
  return null;
}

// openPreview: the window, prepared fresh. Resolves when it closes.
export function openPreview(after) {
  const d = dlg();
  P.skipCr.clear(); P.skipPr.clear(); P.data = null;
  if (!d.open) d.showModal();
  d.onclose = () => { if (after) after(); };
  return load(d);
}
