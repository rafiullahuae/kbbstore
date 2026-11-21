// Settings → Connections → "Cloud creation (GitHub)" (engine/exchange_wire.go). Ads keep being made while the Mac
// is closed: every creation request also goes to a private GitHub repository as a job; a scheduled Claude Code task
// on the owner's Max plan makes them every 2 hours; this app brings them back on open / wake / every 15 min.
// One key: a fine-grained GitHub token for that ONE repository (Contents: read and write), kept in the Keychain.
// "Create GitHub key" opens GitHub's own page pre-filled (name, description, owner, expiry, contents=write — the
// parameters GitHub documents); what GitHub cannot pre-fill is the checklist under it.
import { tt, getLang } from '../i18n.js';
import { esc, toast, failCard } from '../ui.js';
import { api } from '../api.js';

const when = (iso) => (iso && !String(iso).startsWith('0001') ? new Date(iso).toLocaleString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const STATE = { waiting: ['waiting in the cloud', 'بانتظار السحابة'], working: ['being made', 'قيد الإنشاء'], finished: ['finished', 'انتهى'], imported: ['brought back', 'أُعيد'], failed: ['failed', 'فشل'], withdrawn: ['withdrawn (you made it here)', 'سُحب (صنعتِه هنا)'], ignored: ['not imported', 'لم يُستورد'] };

export async function mountCloud(el) {
  if (!el) return;
  let x = null, err = null;
  try { x = await api('/api/exchange', { noAccount: true }); } catch (e) { err = e; }
  if (!el.isConnected) return;
  if (!x) return failCard(el, tt('Cloud creation (GitHub)', 'الإنشاء في السحابة (GitHub)'), err, () => mountCloud(el));
  const c = x.counts || {}, ok = x.connected, ar = getLang() === 'ar';
  const jobs = (x.jobs || []).slice(0, 5);
  el.innerHTML = `<div class="card" data-cloud><header><h2>${esc(tt('Cloud creation (GitHub)', 'الإنشاء في السحابة (GitHub)'))} <span class="chip acc">${esc(tt('Max plan · no credits', 'باقة Max · بلا رصيد'))}</span></h2>
    <span class="sub">${esc(tt('Ads keep being made while your Mac is closed: a scheduled Claude task on your Max plan picks up each request every 2 hours and makes the ads; this app brings them back when you open it, checks the wording and quality again, and shows them in Approvals.', 'تستمر صناعة الإعلانات وجهازكِ مغلق: مهمة Claude مجدولة على باقة Max تأخذ كل طلب كل ساعتين وتصنع الإعلانات؛ ويعيدها هذا التطبيق عند فتحه، ويراجع الصياغة والجودة مجدداً، ويعرضها في الموافقات.'))}</span></header>
    <div class="row"><span class="dot ${ok ? 'ok' : x.token && x.token.set ? 'warn' : ''}"></span><b>${esc(ok ? tt('Connected', 'متصل') + ' · ' + x.owner + '/' + x.repo : tt('Not connected', 'غير متصل'))}</b>
      ${x.token && x.token.set ? `<span class="small muted">••••${esc(x.token.last4 || '')}${x.expires && x.expires !== '<nil>' ? ' · ' + esc(tt('key expires', 'ينتهي المفتاح')) + ' ' + esc(String(x.expires).slice(0, 10)) : ''}</span>` : ''}
      <span class="spacer"></span>${ok ? `<button type="button" class="ghost small" id="cx-sync">${esc(tt('Sync now', 'زامني الآن'))}</button>` : ''}</div>
    ${ok ? `<div class="row small" style="margin-top:8px;gap:14px;flex-wrap:wrap">
      <span>${esc(tt('Waiting in the cloud', 'بانتظار السحابة'))}: <b>${+c.waiting || 0}</b></span><span>${esc(tt('Being made', 'قيد الإنشاء'))}: <b>${+c.working || 0}</b></span>
      <span>${esc(tt('Finished', 'انتهت'))}: <b>${+c.finished || 0}</b></span><span>${esc(tt('Failed', 'فشلت'))}: <b>${+c.failed || 0}</b></span></div>
      <p class="small muted">${esc(tt('Last sync', 'آخر مزامنة'))}: ${esc(when(x.last_sync))} · ${esc(tt('sent', 'إرسال'))}: ${esc(when(x.last_push))} · ${esc(tt('brought back', 'استلام'))}: ${esc(when(x.last_pull))} · ${esc(tt(`every ${x.every_min} min while open, and on open / wake`, `كل ${x.every_min} دقيقة والتطبيق مفتوح، وعند الفتح / الاستيقاظ`))}</p>
      ${x.last_error ? `<p class="small" style="color:var(--bad)">${esc(x.last_error)}</p>` : ''}
      ${jobs.length ? `<ul class="small cc-guide">${jobs.map((j) => `<li>${esc(j.title || j.id)} — ${esc((STATE[j.state] || [j.state || '', j.state || ''])[ar ? 1 : 0])}${j.kept ? ' · ' + esc(tt(`${j.kept} ad(s)`, `${j.kept} إعلان`)) : ''}${(j.rejected || []).length ? ' · ' + esc(tt(`${j.rejected.length} refused`, `${j.rejected.length} مرفوض`)) : ''}</li>`).join('')}</ul>` : ''}` : ''}
    <ol class="cc-guide small" style="margin-top:10px">
      <li>${esc(tt('Press “Create GitHub key”. GitHub opens with the name, description, owner, a one-year expiry and Contents: Read and write already filled in.', 'اضغطي «إنشاء مفتاح GitHub». يفتح GitHub والاسم والوصف والمالك وصلاحية سنة و«المحتوى: قراءة وكتابة» معبّأة.'))}
        <div class="row" style="margin-top:6px"><a class="btn" id="cx-new" href="${esc(x.token_url)}" target="_blank" rel="noopener noreferrer">${esc(tt('Create GitHub key', 'إنشاء مفتاح GitHub'))}</a></div></li>
      <li>${esc(tt('On that page, check what GitHub cannot fill in for you:', 'في تلك الصفحة، تأكدي مما لا يملؤه GitHub عنكِ:'))}
        <ul><li>${esc(tt('Resource owner', 'مالك المورد'))}: <b dir="ltr">${esc(x.owner)}</b></li>
        <li>${esc(tt('Repository access → Only select repositories →', 'الوصول للمستودعات ← مستودعات محددة فقط ←'))} <b dir="ltr">${esc(x.repo)}</b> (${esc(tt('only this one', 'هذا فقط'))})</li>
        <li>${esc(tt('Permissions → Repository → Contents: Read and write (Metadata: Read-only is added by GitHub). Nothing else.', 'الصلاحيات ← المستودع ← المحتوى: قراءة وكتابة (يضيف GitHub البيانات الوصفية: قراءة فقط). لا شيء غير ذلك.'))}</li>
        <li>${esc(tt('Press Generate token, then copy it (it starts with github_pat_).', 'اضغطي Generate token ثم انسخيه (يبدأ بـ github_pat_).'))}</li></ul></li>
      <li>${esc(tt('Paste it here and press Save & test. The app checks it reaches this repository only, can read and write it, and keeps it in your Mac’s Keychain.', 'ألصقيه هنا واضغطي حفظ واختبار. يتأكد التطبيق أنه يصل لهذا المستودع فقط ويقرأ ويكتب فيه، ويحفظه في سلسلة مفاتيح جهازكِ.'))}</li></ol>
    <div class="row" style="margin-top:8px;flex-wrap:wrap"><input id="cx-owner" value="${esc(x.owner)}" dir="ltr" style="width:140px" aria-label="owner"><span>/</span><input id="cx-repo" value="${esc(x.repo)}" dir="ltr" style="width:170px" aria-label="repository">
      <input type="password" id="cx-key" autocomplete="off" dir="ltr" placeholder="${esc(x.token && x.token.set ? '••••' + (x.token.last4 || '') : 'github_pat_…')}" style="width:240px">
      <button type="button" id="cx-save">${esc(tt('Save & test', 'حفظ واختبار'))}</button>${x.token && x.token.set ? `<button type="button" class="ghost small" id="cx-del">${esc(tt('Remove key', 'إزالة المفتاح'))}</button>` : ''}</div>
    <p class="small muted">${esc(tt('Only product facts, your Playbook wording and summed-up lessons go to the repository — never a key, a customer or an order. The cloud task itself is set up once in Claude Code (a scheduled task; its instructions are studio/ads-autopilot/cloud/WORKER.md in the kbbstore repository).', 'يذهب إلى المستودع فقط: معلومات المنتجات وصياغة دليلكِ والدروس المجمّعة — لا مفاتيح ولا عملاء ولا طلبات. تُعدّ المهمة السحابية مرة واحدة في Claude Code (مهمة مجدولة؛ تعليماتها في studio/ads-autopilot/cloud/WORKER.md في مستودع kbbstore).'))}</p></div>`;
  const save = el.querySelector('#cx-save');
  save.onclick = async () => {
    save.disabled = true;
    try {
      await api('/api/exchange', { method: 'PUT', noAccount: true, body: { owner: el.querySelector('#cx-owner').value.trim(), repo: el.querySelector('#cx-repo').value.trim(), token: el.querySelector('#cx-key').value.trim() } });
      toast(tt('Connected. The key is in the Keychain; jobs go to the cloud from now on.', 'تم الاتصال. المفتاح في سلسلة المفاتيح؛ تذهب الطلبات للسحابة من الآن.'));
      mountCloud(el);
    } catch (e) {
      // the refusal stays on the card (the owner, 21 Nov: a toast came and went, the card still said "Not connected"
      // with no reason): what GitHub or the check said, in full, until the next try
      toast(e.message); save.disabled = false;
      let p = el.querySelector('#cx-err');
      if (!p) { p = document.createElement('p'); p.id = 'cx-err'; p.className = 'alert danger small'; save.parentElement.insertAdjacentElement('afterend', p); }
      p.textContent = tt('Not saved: ', 'لم يُحفظ: ') + e.message;
    }
  };
  const sy = el.querySelector('#cx-sync');
  if (sy) sy.onclick = async () => { sy.disabled = true; try { await api('/api/exchange/sync', { method: 'POST', noAccount: true }); toast(tt('Synced.', 'تمت المزامنة.')); } catch (e) { toast(e.message); } mountCloud(el); };
  const d = el.querySelector('#cx-del');
  if (d) d.onclick = async () => { try { await api('/api/exchange', { method: 'DELETE', noAccount: true }); toast(tt('Removed.', 'أُزيل.')); mountCloud(el); } catch (e) { toast(e.message); } };
}
