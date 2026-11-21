// The product picker — one component for Approvals (every waiting ad / ad set / product set), Plan → Upcoming jobs
// and Approvals → Occasions. The owner, 21 Nov 2026: "those products i don't want to promote, give me a way to skip
// on products or choose the products myself".
//
// The catalogue comes once per account (engine GET /api/catalog: rank and why, stock, price, new / trending, pinned,
// what the Playbook keeps out — shown disabled with the reason, never hidden). Search and the filters run in the
// page: no request per keystroke. Photos load for the rows on show only (60 at a time).
//
// productChips(): the chips a card shows, each with Pin and "Don't promote this product again"; wireChips() runs
// them (engine POST /api/products/{id}/pin|avoid) with an Undo toast (POST /api/undo).
import { tt } from '../i18n.js';
import { esc, aed, toast } from '../ui.js';
import { api, getAccount, img, hydrate } from '../api.js';

const cache = new Map();
window.addEventListener('kaa:stale', (e) => (e.detail || []).forEach((id) => cache.delete(id)));
export const dropCatalog = () => cache.delete(getAccount());

export function catalog() {
  const k = getAccount();
  if (!cache.has(k)) cache.set(k, api('/api/catalog').then((r) => r.products || []).catch((e) => { cache.delete(k); throw e; }));
  return cache.get(k);
}

const PAGE = 60;
const short = (t) => { const s = String(t || ''); const i = s.lastIndexOf(' - '); return (i > 20 ? s.slice(0, i) : s).slice(0, 60); };
const why = (s) => String(s || '');

let dlg = null;
function box() {
  if (!dlg) { dlg = document.createElement('dialog'); dlg.className = 'pk'; dlg.setAttribute('aria-label', 'Choose products'); document.body.append(dlg); }
  return dlg;
}

// pickProducts: resolves with the chosen ids in the owner's order, or null on Cancel.
export async function pickProducts({ title, selected = [], min = 1, max = 10, hint = '' }) {
  const d = box();
  d.innerHTML = `<div class="dl"><h2>${esc(title)}</h2><div class="sk" style="height:320px"></div></div>`;
  d.showModal();
  let all;
  try { all = await catalog(); } catch (e) { d.close(); toast(e.message); return null; }
  const chosen = selected.filter((id) => all.some((p) => p.id === id && p.allowed));
  let q = '', filt = 'all', shown = PAGE;
  const match = (p) => {
    if (filt === 'new' && !p.new) return false;
    if (filt === 'trending' && !p.trending) return false;
    if (filt === 'pinned' && !p.pinned) return false;
    if (filt === 'chosen' && !chosen.includes(p.id)) return false;
    if (!q) return true;
    const h = `${p.title} ${p.brand || ''} ${p.category || ''}`.toLowerCase();
    return q.split(/\s+/).every((w) => h.includes(w));
  };
  const row = (p) => {
    const on = chosen.includes(p.id);
    const stock = p.stock == null ? (p.in_stock ? tt('in stock', 'متوفر') : tt('out of stock', 'غير متوفر')) : `${p.stock} ${tt('in stock', 'متوفر')}`;
    return `<label class="pk-row${p.allowed ? '' : ' off'}${on ? ' on' : ''}" data-pid="${esc(p.id)}">
      <input type="checkbox" value="${esc(p.id)}" ${on ? 'checked' : ''} ${p.allowed ? '' : 'disabled'}>
      <img data-src="${esc(img(p.image || ''))}" alt="" width="52" height="52" loading="lazy">
      <span class="pk-t"><b dir="auto">${esc(p.title)}</b>
        <span class="small muted">${esc(p.brand || '')}${p.brand ? ' · ' : ''}${p.sale && p.sale < p.price ? `<s>${aed(p.price)}</s> ${aed(p.sale)}` : aed(p.price)} · ${esc(stock)}</span>
        <span class="pk-b">${p.rank ? `<span class="chip">#${p.rank}</span>` : ''}${p.pinned ? `<span class="chip acc">📌 ${esc(tt('pinned', 'مثبّت'))}</span>` : ''}${p.new ? `<span class="chip acc">${esc(tt('new', 'جديد'))}</span>` : ''}${p.trending ? `<span class="chip acc">${esc(tt('trending', 'رائج'))}</span>` : ''}${!p.allowed ? `<span class="chip warn">${esc(p.avoided ? tt('Avoided (Strategy)', 'مستبعد (الاستراتيجية)') : p.reason)}</span>` : ''}</span>
        ${p.why && p.why.length ? `<span class="small ink2">${esc(why(p.why.slice(0, 2).join(' · ')))}</span>` : ''}</span></label>`;
  };
  const paint = () => {
    const list = all.filter(match);
    const L = d.querySelector('[data-list]');
    L.innerHTML = list.slice(0, shown).map(row).join('') + (list.length > shown ? `<button type="button" class="ghost small" data-more>${esc(tt(`Show ${Math.min(PAGE, list.length - shown)} more`, `عرض ${Math.min(PAGE, list.length - shown)} أخرى`))}</button>` : '') + (list.length ? '' : `<p class="muted small">${esc(tt('No product matches.', 'لا منتج مطابق.'))}</p>`);
    hydrate(L);
    d.querySelector('[data-n]').textContent = tt(`${chosen.length} chosen`, `${chosen.length} مختار`);
    d.querySelector('[data-order]').innerHTML = chosen.map((id, i) => { const p = all.find((x) => x.id === id); return `<span class="chip acc">${i + 1}. ${esc(short(p ? p.title : id))}<button type="button" class="x" data-un="${esc(id)}" aria-label="${esc(tt('Remove', 'إزالة'))}">×</button></span>`; }).join('');
    d.querySelector('[data-save]').disabled = chosen.length < min || chosen.length > max;
    L.querySelector('[data-more]')?.addEventListener('click', () => { shown += PAGE; paint(); });
    d.querySelectorAll('[data-un]').forEach((b) => { b.onclick = () => { chosen.splice(chosen.indexOf(b.dataset.un), 1); paint(); }; });
  };
  d.innerHTML = `<form method="dialog" class="dl pk-dl"><header class="pk-h"><h2>${esc(title)}</h2>${hint ? `<p class="small ink2">${esc(hint)}</p>` : ''}</header>
    <div class="row pk-tools"><input type="search" data-q placeholder="${esc(tt('Search by name or brand', 'ابحثي بالاسم أو العلامة'))}" dir="auto" aria-label="${esc(tt('Search', 'بحث'))}">
      <span class="seg" role="tablist">${[['all', 'All', 'الكل'], ['new', 'New', 'جديد'], ['trending', 'Trending', 'رائج'], ['pinned', 'Pinned', 'مثبّت'], ['chosen', 'Chosen', 'المختار']].map(([k, en, ar]) => `<button type="button" class="${k === filt ? 'on' : ''}" data-f="${k}">${esc(tt(en, ar))}</button>`).join('')}</span></div>
    <div class="pk-order" data-order></div>
    <div class="pk-list" data-list></div>
    <div class="row pk-f"><span class="small muted" data-n></span><span class="small muted">${esc(tt(`choose ${min === max ? min : `${min} to ${max}`}; the order is the order they run in`, `اختاري ${min === max ? min : `${min} إلى ${max}`}؛ الترتيب هو ترتيب التشغيل`))}</span>
      <span style="flex:1"></span><button value="no" class="ghost">${esc(tt('Cancel', 'إلغاء'))}</button><button value="yes" data-save>${esc(tt('Use these products', 'استخدمي هذه المنتجات'))}</button></div></form>`;
  const qi = d.querySelector('[data-q]');
  let tmr = 0;
  qi.oninput = () => { clearTimeout(tmr); tmr = setTimeout(() => { q = qi.value.trim().toLowerCase(); shown = PAGE; paint(); }, 120); };
  qi.onkeydown = (e) => { if (e.key === 'Enter') e.preventDefault(); };
  d.querySelectorAll('[data-f]').forEach((b) => { b.onclick = () => { filt = b.dataset.f; d.querySelectorAll('[data-f]').forEach((x) => x.classList.toggle('on', x === b)); shown = PAGE; paint(); }; });
  d.querySelector('[data-list]').addEventListener('change', (e) => {
    const c = e.target; if (!c.matches('input[type=checkbox]')) return;
    const i = chosen.indexOf(c.value);
    if (c.checked && i < 0) { if (chosen.length >= max) { c.checked = false; toast(tt(`At most ${max}.`, `${max} كحد أقصى.`)); return; } chosen.push(c.value); }
    if (!c.checked && i >= 0) chosen.splice(i, 1);
    c.closest('.pk-row').classList.toggle('on', c.checked);
    paint();
  });
  paint();
  qi.focus();
  return new Promise((ok) => { d.onclose = () => ok(d.returnValue === 'yes' ? chosen.slice() : null); });
}

// ── chips on the cards ──

// productChips: the products a card names, each with Pin and "Don't promote this product again".
export function productChips(products, opts = {}) {
  if (!products || !products.length) return '';
  return `<span class="pk-chips">${products.map((p) => `<span class="chip pk-chip${p.pinned ? ' acc' : ''}" title="${esc(p.title)}">${p.pinned ? '📌 ' : ''}<span dir="auto">${esc(short(p.title))}</span>${p.price ? ` <span class="muted">${aed(p.price)}</span>` : ''}
    <button type="button" class="x" data-pin="${esc(p.id)}" data-t="${esc(p.title)}" title="${esc(p.pinned ? tt('Unpin', 'إلغاء التثبيت') : tt('Pin: always considered, ranked first', 'تثبيت: يُقدَّم دائماً'))}" aria-label="${esc(tt('Pin', 'تثبيت'))}">📌</button>
    <button type="button" class="x" data-avoid="${esc(p.id)}" data-t="${esc(p.title)}" title="${esc(tt("Don't promote this product again", 'لا تروّج لهذا المنتج مجدداً'))}" aria-label="${esc(tt("Don't promote this product again", 'لا تروّج لهذا المنتج مجدداً'))}">⊘</button></span>`).join('')}${opts.more ? `<span class="chip">+${opts.more}</span>` : ''}</span>`;
}

export async function undoLast(after) {
  try { const r = await api('/api/undo', { method: 'POST', body: {} }); toast(tt('Undone: ', 'تم التراجع: ') + (r.undone || '')); } catch (e) { toast(e.message); }
  dropCatalog();
  if (after) await after();
}

// wireChips: Pin / Don't promote again on every chip under el; after() refreshes the screen.
export function wireChips(el, after) {
  el.querySelectorAll('[data-avoid]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try {
      const r = await api(`/api/products/${encodeURIComponent(b.dataset.avoid)}/avoid`, { method: 'POST', body: {} });
      dropCatalog();
      await after();
      toast(tt(`Won't be promoted again: ${short(r.title)}. ${r.rebuilt} waiting proposals rebuilt${r.choose ? `, ${r.choose} need your products` : ''}${r.jobs ? `, ${r.jobs} jobs changed` : ''}.`,
        `لن يُروَّج له مجدداً: ${short(r.title)}. أُعيد بناء ${r.rebuilt} مقترح${r.choose ? `، ${r.choose} تحتاج منتجاتك` : ''}${r.jobs ? `، تغيّرت ${r.jobs} مهام` : ''}.`), () => undoLast(after));
    } catch (e) { b.disabled = false; toast(e.message); }
  }; });
  el.querySelectorAll('[data-pin]').forEach((b) => { b.onclick = async () => {
    b.disabled = true;
    try {
      const r = await api(`/api/products/${encodeURIComponent(b.dataset.pin)}/pin`, { method: 'POST', body: {} });
      dropCatalog();
      await after();
      toast(r.pinned ? tt(`Pinned: ${short(b.dataset.t)} is ranked first from now on.`, `مثبّت: ${short(b.dataset.t)} في المقدمة من الآن.`) : tt(`Unpinned: ${short(b.dataset.t)}.`, `أُلغي التثبيت: ${short(b.dataset.t)}.`), () => undoLast(after));
    } catch (e) { b.disabled = false; toast(e.message); }
  }; });
}
