// Creation requests (engine/brain/creation.go): "3 new Arabic Reels needed for the snail essence (2 ads tiring).
// Start creating". Pushed over SSE (event "creation") the moment a cycle decides it -- no polling; the banner sits on
// Overview and Approvals. Start opens the Claude desktop app (his Max plan) with the brief prefilled; "Create here"
// uses the in-app box (API credits, said before it runs).
import { tt, getLang } from '../i18n.js';
import { esc, toast, txt } from '../ui.js';
import { api } from '../api.js';
import { openCreate } from './create.js';

export const CS = { view: null, account: '' };

export async function loadCreation(account) {
  try { CS.view = await api('/api/brain/x/creation'); CS.account = account; } catch { CS.view = null; }
  return CS.view;
}
export function setCreation(account, view) { CS.view = view; CS.account = account; }

export function openRequests() { return ((CS.view && CS.view.requests) || []).filter((r) => r.status === 'open' || r.status === 'started'); }

export function creationBanner() {
  const reqs = openRequests();
  const ns = ((CS.view && CS.view.notifications) || []).filter((n) => n.kind === 'due_soon' && Date.now() - Date.parse(n.at) < 4 * 3600e3);
  if (!reqs.length && !ns.length) return '';
  const ar = getLang() === 'ar';
  return `<div class="cc-banner" data-creation>${ns.slice(0, 1).map((n) => `<div class="cc-req soon"><span class="cc-ico">⏰</span><div><b>${esc(n.title)}</b><br><span class="small">${txt(ar ? n.body_ar : n.body)}</span></div></div>`).join('')}
    ${reqs.slice(0, 3).map((r) => `<div class="cc-req"><span class="cc-ico">✨</span><div class="cc-req-t"><b>${txt(ar ? r.title_ar : r.title)}</b><br><span class="small muted">${esc(r.status === 'started' ? tt('Started in Claude — waiting for your ads.', 'بدأ في Claude — بانتظار إعلاناتكِ.') : tt('The autopilot needs these; it will not write them with credits.', 'يحتاجها الطيار الآلي؛ لن يكتبها برصيد.'))}</span></div>
      <div class="row"><button type="button" data-cstart="${esc(r.id)}">${esc(r.status === 'started' ? tt('Open in Claude again', 'افتحي في Claude مجدداً') : tt('Start creating', 'ابدئي الإنشاء'))}</button><button type="button" class="ghost small" data-chere="${esc(r.id)}">${esc(tt('Create here (API)', 'أنشئي هنا (API)'))}</button><button type="button" class="ghost small" data-cnot="${esc(r.id)}">${esc(tt('Not now', 'ليس الآن'))}</button></div></div>`).join('')}</div>`;
}

export function wireBanner(root, after) {
  root.querySelectorAll('[data-cstart]').forEach((b) => { b.onclick = () => start(b.dataset.cstart); });
  root.querySelectorAll('[data-chere]').forEach((b) => { b.onclick = () => { const r = openRequests().find((x) => x.id === b.dataset.chere); openCreate({ fresh: true, request: r, prompt: r ? `${r.count} ads for ${r.product}: ${r.angle}` : '' }); }; });
  root.querySelectorAll('[data-cnot]').forEach((b) => { b.onclick = async () => { try { await api(`/api/brain/x/creation/${b.dataset.cnot}/dismiss`, { method: 'POST' }); await loadCreation(CS.account); if (after) after(); } catch (e) { toast(e.message); } }; });
}

// Start: the engine opens Claude (Mac) with the brief prefilled and on the clipboard; here we show it too.
export async function start(id) {
  let r;
  try { r = await api(`/api/brain/x/creation/${id}/start`, { method: 'POST' }); } catch (e) { toast(e.message); return; }
  navigator.clipboard?.writeText(r.brief).catch(() => {});
  const d = document.getElementById('dlg');
  d.style.maxWidth = '720px';
  d.innerHTML = `<div class="dl"><h2>✨ ${esc(tt('Create it in Claude', 'أنشئيه في Claude'))}</h2>
    <p>${esc(r.opened ? tt('Claude is opening with this request filled in (it is on your clipboard too). Press send there; the previews come back here and in Claude.', 'يُفتح Claude والطلب مكتوب فيه (وهو في الحافظة أيضاً). اضغطي إرسال هناك؛ تصل المعاينات هنا وفي Claude.') : tt('Open Claude and paste this (it is on your clipboard):', 'افتحي Claude والصقي هذا (في الحافظة):'))}</p>
    <pre class="report cc-brief" dir="ltr">${esc(r.brief)}</pre>
    <div class="row" style="justify-content:flex-end"><button type="button" class="ghost" id="cb-copy">${esc(tt('Copy', 'نسخ'))}</button><a class="btn" id="cb-open" href="${esc(r.url)}">${esc(tt('Open Claude', 'افتحي Claude'))}</a><button type="button" id="cb-x">${esc(tt('Done', 'تم'))}</button></div></div>`;
  d.querySelector('#cb-copy').onclick = () => { navigator.clipboard?.writeText(r.brief).then(() => toast(tt('Copied.', 'نُسخ.'))).catch(() => {}); };
  d.querySelector('#cb-x').onclick = () => d.close();
  d.showModal();
}
