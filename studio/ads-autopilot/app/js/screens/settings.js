import { tt } from '../i18n.js';
import { esc, toast, tip, aed, num } from '../ui.js';
import { api, getAccount } from '../api.js';
import { refreshState, refreshAccounts } from '../main.js';
import { brainSettings, saveBrainSettings } from '../data.js';
import { mountGenerators } from '../creative/gen-ui.js';
import { mountOneKey, mountClaudeApp, mountCreationMode } from '../create/settings-cc.js';
import { mountCloud } from '../create/cloud.js';
import { mountUpdates } from './updates.js';

let tab = 'limits';
let wording = null;
const GCC = [['AE', 'UAE', 'الإمارات'], ['SA', 'Saudi Arabia', 'السعودية'], ['KW', 'Kuwait', 'الكويت'], ['QA', 'Qatar', 'قطر'], ['BH', 'Bahrain', 'البحرين'], ['OM', 'Oman', 'عُمان']];
const WORDING = { strict: ['Strict', 'صارمة', 'Blocks every risky pattern, incl. discounts and clinical words.', 'تمنع كل صياغة فيها مخاطرة، ومنها الخصومات والكلمات الطبية.'],
  balanced: ['Balanced', 'متوازنة', 'Default. Acne / blemish / pore products are advertised with product framing; blocks only "your acne"-style claims, cure claims, timed results, before/after and fake reviews.', 'الافتراضي: يُعلَن عن منتجات حب الشباب بصياغة تصف المنتج؛ يمنع فقط ادعاءات مثل «حبوبكِ» والعلاج والنتائج المؤقتة وقبل/بعد والمراجعات المزيفة.'],
  relaxed: ['Relaxed', 'مرنة', 'Before/after and strong negative words become warnings; cure claims and personal-attribute claims still block.', 'قبل/بعد والكلمات السلبية تصبح تحذيرات؛ ادعاءات العلاج تبقى ممنوعة.'] };
const LIMIT_KEYS = ['daily_aed', 'monthly_aed', 'max_budget_step_pct', 'cooldown_hours', 'max_active_ads', 'countries', 'autopilot', 'target_cpa_aed', 'kill_multiple', 'wording'];

export function limitsForm(L, opts = {}) {
  const set = L.daily_aed > 0 && L.monthly_aed > 0;
  const w = opts.wording || 'balanced';
  return `<div class="grid g3">
    <label class="f"><span>${esc(tt('Daily limit (AED)', 'الحد اليومي (درهم)'))} ${tip(tt('The most the autopilot may have running per day in this account.', 'أقصى إنفاق يومي في هذا الحساب.'))}</span><input name="daily_aed" type="number" min="0" step="10" value="${esc(L.daily_aed || '')}" placeholder="300"></label>
    <label class="f"><span>${esc(tt('Monthly limit (AED)', 'الحد الشهري (درهم)'))} ${tip(tt('Also set on Meta as the account spending limit, so Meta stops the ads even while your Mac is closed.', 'يُضبط أيضاً كحد إنفاق في ميتا، فتتوقف الإعلانات حتى وجهازك مغلق.'))}</span><input name="monthly_aed" type="number" min="0" step="100" value="${esc(L.monthly_aed || '')}" placeholder="9000"></label>
    <label class="f"><span>${esc(tt('Autopilot', 'الطيار الآلي'))}</span><select name="autopilot">${[['off', 'Off', 'متوقف'], ['shadow', 'Practice — plan & log only', 'تدريب — تخطيط وتسجيل فقط'], ['auto', 'Auto — acts inside limits', 'تلقائي — ينفّذ ضمن الحدود']].map(([m, en, ar]) => `<option value="${m}" ${L.autopilot === m ? 'selected' : ''} ${m !== 'off' && !set ? 'disabled' : ''}>${esc(tt(en, ar))}</option>`).join('')}</select>${set ? '' : `<span class="small" style="color:var(--warn)">${esc(tt('Set a daily and monthly limit to turn it on.', 'حدّدي الحد اليومي والشهري لتشغيله.'))}</span>`}</label>
  </div>
  <div style="margin-top:12px"><span class="small ink2">${esc(tt('Countries', 'الدول'))}</span><div class="row" style="margin-top:4px">${GCC.map(([c, en, ar]) => `<label class="chip"><input type="checkbox" name="country" value="${c}" ${(L.countries || []).includes(c) ? 'checked' : ''}> ${esc(tt(en, ar))}</label>`).join('')}</div></div>
  <div style="margin-top:12px"><span class="small ink2">${esc(tt('Ad wording rules', 'قواعد صياغة الإعلانات'))} ${tip(tt('How strict the policy checker is with ad text. You chose Balanced.', 'مدى صرامة فاحص الصياغة.'))}</span>
    <div class="seg" style="margin-top:4px">${Object.entries(WORDING).map(([k, v]) => `<button type="button" data-w="${k}" class="${w === k ? 'on' : ''}">${esc(tt(v[0], v[1]))}</button>`).join('')}</div>
    <p class="small muted" id="wdesc">${esc(tt(WORDING[w][2], WORDING[w][3]))}</p></div>
  ${opts.noAdvanced ? '' : `<details class="adv"><summary>${esc(tt('Advanced', 'متقدم'))}</summary><div class="grid g3" style="margin-top:8px">
    <label class="f"><span>${esc(tt('Max budget step %', 'أقصى خطوة للميزانية ٪'))} ${tip(tt('Largest single budget change; bigger jumps restart Meta\'s learning.', 'أكبر تغيير واحد؛ القفزات الأكبر تعيد تعلّم ميتا.'))}</span><input name="max_budget_step_pct" type="number" min="5" max="50" value="${esc(L.max_budget_step_pct || 20)}"></label>
    <label class="f"><span>${esc(tt('Cooldown (hours)', 'فترة الانتظار (ساعات)'))}</span><input name="cooldown_hours" type="number" min="24" max="336" value="${esc(L.cooldown_hours || 72)}"></label>
    <label class="f"><span>${esc(tt('Max active ads', 'أقصى عدد إعلانات نشطة'))}</span><input name="max_active_ads" type="number" min="1" max="50" value="${esc(L.max_active_ads || 6)}"></label>
    <label class="f"><span>${esc(tt('Target cost per order (AED)', 'تكلفة الطلب المستهدفة'))} ${tip(tt('Sizes Meta\'s own safety rule that pauses a non-buying ad while the Mac is closed.', 'يحدد قاعدة الأمان في ميتا التي توقف الإعلان غير المُربح وجهازك مغلق.'))}</span><input name="target_cpa_aed" type="number" min="0" value="${esc(L.target_cpa_aed || '')}"></label>
    <label class="f"><span>${esc(tt('Safety stop at × target', 'إيقاف الأمان عند × المستهدف'))}</span><input name="kill_multiple" type="number" min="2" max="6" step="0.5" value="${esc(L.kill_multiple || 3)}"></label></div></details>`}`;
}

export function readLimits(root, L) {
  const v = (n) => root.querySelector(`[name="${n}"]`);
  const out = {};
  for (const k of LIMIT_KEYS) if (L[k] !== undefined) out[k] = L[k];
  Object.assign(out, { daily_aed: +v('daily_aed').value || 0, monthly_aed: +v('monthly_aed').value || 0, autopilot: v('autopilot').value,
    countries: [...root.querySelectorAll('[name="country"]:checked')].map((c) => c.value) });
  for (const n of ['max_budget_step_pct', 'cooldown_hours', 'max_active_ads', 'target_cpa_aed', 'kill_multiple']) if (v(n)) out[n] = +v(n).value || 0;
  if (root.querySelector('[data-w]')) out.wording = readWording(root); // the ad-wording rule lives on /api/limits (the brain reads it there)
  return out;
}
export const readWording = (root) => (root.querySelector('[data-w].on') || {}).dataset?.w || 'balanced';
export function wireWording(root) {
  root.querySelectorAll('[data-w]').forEach((b) => { b.onclick = () => { root.querySelectorAll('[data-w]').forEach((x) => x.classList.toggle('on', x === b)); const w = WORDING[b.dataset.w]; root.querySelector('#wdesc').textContent = tt(w[2], w[3]); }; });
}

let CHECKING = false; // the engine has not tested the connections since it started: saved keys shown, "Checking…"
function connCard(name, title, c, fields) {
  const ok = c && c.ok;
  return `<div class="card" data-conn="${name}"><header><h2>${esc(title)}</h2><span class="row small"><span class="dot ${ok ? 'ok' : CHECKING ? 'warn' : 'bad'}"></span>${esc(ok ? tt('Connected', 'متصل') : CHECKING ? tt('Checking…', 'جارٍ التحقق…') : tt('Not connected', 'غير متصل'))}</span></header>
    ${c && c.error ? `<p class="alert danger small">${esc(c.error)}</p>` : ''}${c && c.warning ? `<p class="alert warn small">${esc(c.warning)}</p>` : ''}
    <div class="grid" style="gap:10px">${fields.map(([k, label, secret, val, ph]) => `<label class="f"><span>${esc(label)}</span><input name="${k}" ${secret ? 'type="password" autocomplete="off"' : ''} value="${secret ? '' : esc(val || '')}" placeholder="${esc(secret && val && val.set ? '•••• ' + val.last4 + ' ' + tt('(saved — type to replace)', '(محفوظ — اكتبي للاستبدال)') : secret && val && val.error ? tt('saved, but could not be read: see above', 'محفوظ لكن تعذّرت قراءته: انظري أعلاه') : ph || '')}"></label>`).join('')}</div>
    <div class="row" style="margin-top:10px"><button type="button" data-save="${name}">${esc(tt('Save & test', 'حفظ واختبار'))}</button></div></div>`;
}

export function render(S, el) {
  const q = /[?&]tab=(\w+)/.exec(location.hash); // #/settings?tab=updates (search, the reload after an update)
  if (q && ['limits', 'connections', 'accounts', 'advanced', 'updates'].includes(q[1])) { tab = q[1]; history.replaceState(null, '', '#/settings'); }
  const st = S.state, c = S.conn || {};
  el.innerHTML = `<div class="page"><div class="page-h"><div><h1>${esc(tt('Settings', 'الإعدادات'))}</h1><p>${esc(st.account.name || '')} · ${esc(getAccount())}</p></div></div><div class="tabs">${[['limits', 'Limits', 'الحدود'], ['connections', 'Connections', 'الاتصالات'], ['accounts', 'Accounts', 'الحسابات'], ['advanced', 'Advanced', 'متقدم'], ['updates', 'Updates', 'التحديثات']].map(([k, en, ar]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('')}</div><div id="tabv"></div></div>`;
  const v = el.querySelector('#tabv');
  if (tab === 'limits') {
    v.innerHTML = `<div class="card"><header><h2>${esc(tt('Limits', 'الحدود'))} · ${esc(st.account.name || '')}</h2><span class="sub">${esc(tt('Only going above these needs you. No limit = nothing launches.', 'فقط تجاوز هذه الحدود يحتاجك. بلا حدود = لا شيء يُطلق.'))}</span></header>${limitsForm(st.limits, { wording: st.limits.wording || (wording && wording.ad_wording) || 'balanced' })}
      <label class="row small" style="margin-top:10px"><input type="checkbox" id="promo" ${wording && wording.promo_approved ? 'checked' : ''}> ${esc(tt('An authorised promotion is running (allows discount wording, with original price + % shown)', 'يوجد عرض مرخّص (يسمح بصياغة الخصم مع إظهار السعر الأصلي والنسبة)'))}</label>
      <div class="row" style="margin-top:14px"><button type="button" id="saveL">${esc(tt('Save', 'حفظ'))}</button></div></div><div id="cm-mount" style="margin-top:16px"></div>`;
    wireWording(v);
    mountCreationMode(v.querySelector('#cm-mount'), getAccount());
    v.querySelector('#saveL').onclick = async () => {
      const prev = {}; for (const k of LIMIT_KEYS) if (st.limits[k] !== undefined) prev[k] = st.limits[k];
      const next = readLimits(v, st.limits);
      const prevW = wording;
      try {
        await api('/api/limits', { method: 'PUT', body: next });
        wording = await saveBrainSettings({ ad_wording: readWording(v), promo_approved: v.querySelector('#promo').checked });
        toast(tt('Saved.', 'تم الحفظ.'), async () => { await api('/api/limits', { method: 'PUT', body: prev }); if (prevW) wording = await saveBrainSettings(prevW); await refreshState(); toast(tt('Restored.', 'تمت الاستعادة.')); });
        await refreshState(); await refreshAccounts();
      } catch (e) { toast(e.message); }
    };
  } else if (tab === 'connections') {
    CHECKING = !!c.checking;
    const unread = ['meta', 'claude', 'shop'].some((k) => c[k] && /could not be read/.test(c[k].error || ''));
    v.innerHTML = `<div class="row"><p class="small muted">${esc(tt('Keys are stored in the macOS Keychain and never shown again; only the last 4 characters.', 'تُحفظ المفاتيح في سلسلة مفاتيح macOS ولا تظهر مرة أخرى؛ فقط آخر ٤ أحرف.'))}</p><button type="button" class="${unread ? '' : 'ghost '}small" id="conn-retest" style="margin-inline-start:auto">${esc(tt('Try again: read the keys and test', 'حاولي مرة أخرى: قراءة المفاتيح والاختبار'))}</button></div>${unread ? `<p class="alert warn small" id="conn-unread">${esc(tt('Your keys are still saved, but macOS did not let the app read them. Click “Always Allow” when macOS asks (your Mac login password), then Try again.', 'مفاتيحك ما زالت محفوظة، لكن macOS لم يسمح للتطبيق بقراءتها. اضغطي «السماح دائماً» عندما يسأل macOS (كلمة مرور جهازك)، ثم حاولي مرة أخرى.'))}</p>` : ''}<div class="grid g3">
      ${connCard('meta', 'Meta', c.meta, [['token', tt('System-user token', 'رمز مستخدم النظام'), true, c.meta?.token], ['app_id', tt('App ID', 'معرّف التطبيق'), false, c.meta?.app_id], ['app_secret', tt('App secret', 'سر التطبيق'), true, c.meta?.app_secret]])}
      ${connCard('claude', 'Claude', c.claude, [['api_key', tt('Anthropic API key', 'مفتاح Anthropic'), true, c.claude?.key]])}
      ${connCard('shop', tt('Shop', 'المتجر'), c.shop, [['feed_url', tt('Product feed', 'كتالوج المنتجات'), false, c.shop?.feed_url, 'https://kbeautybliss.com/feeds/meta-catalog.xml'], ['token', tt('Shop connection key', 'مفتاح ربط المتجر'), true, c.shop?.token]])}</div>
      <p class="small muted">${c.meta ? esc(tt('Ad accounts', 'الحسابات')) + ': ' + num(c.meta.ad_accounts) + ' · ' + esc(tt('token days left', 'أيام صلاحية الرمز')) + ': ' + (c.meta.token_days_left < 0 ? '∞' : num(c.meta.token_days_left)) + ' · ' : ''}${esc(tt('Shop products', 'منتجات المتجر'))}: ${num(c.shop?.products)} · ${esc(tt('orders source', 'مصدر الطلبات'))}: ${esc(c.shop?.orders_source || '—')}</p><div class="grid g2" style="margin-top:16px"><div id="mcp-mount"></div><div id="ok-mount"></div></div><div id="cloud-mount" style="margin-top:16px"></div>`;
    if (c.checking && !S.connWaiting) { // one request that answers when the first test is done (no polling)
      S.connWaiting = true;
      api('/api/connections/retest', { method: 'POST', noAccount: true }).then((r) => { S.conn = r; S.connWaiting = false; CHECKING = false; if (el.isConnected) render(S, el); }).catch(() => { S.connWaiting = false; });
    }
    v.querySelector('#conn-retest').onclick = async (e) => { e.target.disabled = true; try { S.conn = await api('/api/connections/retest', { method: 'POST', noAccount: true }); render(S, el); } catch (x) { toast(x.message); e.target.disabled = false; } };
    mountClaudeApp(v.querySelector('#mcp-mount')); // Claude desktop connector (create/settings-cc.js)
    mountOneKey(v.querySelector('#ok-mount')); // the ONE optional AI pictures & video key
    mountCloud(v.querySelector('#cloud-mount')); // cloud creation through the GitHub exchange (create/cloud.js)
    v.querySelectorAll('[data-save]').forEach((b) => {
      b.onclick = async () => {
        const card = b.closest('[data-conn]'), body = b.dataset.save === 'meta' ? { auth: 'system_user' } : {};
        card.querySelectorAll('input').forEach((i) => { if (i.value) body[i.name] = i.value; });
        try { S.conn[b.dataset.save] = await api(`/api/connections/${b.dataset.save}`, { method: 'PUT', body, noAccount: true }); const r = S.conn[b.dataset.save]; toast(r.ok ? tt('Saved and connected.', 'تم الحفظ والاتصال.') : r.error || 'not connected'); render(S, el); } catch (e) { toast(e.message); }
      };
    });
  } else if (tab === 'updates') {
    mountUpdates(v); // screens/updates.js keeps its own state: a re-render never loses an upload or an install
  } else if (tab === 'accounts') {
    v.innerHTML = `<div class="card"><header><h2>${esc(tt('Ad accounts', 'الحسابات الإعلانية'))}</h2><span class="sub">${esc(tt('Automate = the autopilot may act there (inside that account\'s limits).', 'أتمتة = يعمل الطيار الآلي فيه ضمن حدوده.'))}</span></header><table><thead><tr><th>${esc(tt('Account', 'الحساب'))}</th><th>${esc(tt('Automate', 'أتمتة'))}</th><th>${esc(tt('Links', 'الروابط'))}</th></tr></thead><tbody>${S.accounts.map((a) => `<tr><td><b>${esc(a.name)}</b><br><span class="small muted">${esc(a.id)} · ${esc(a.status)}${a.backfill ? ' · ' + esc(tt('history', 'السجل')) + ' ' + esc(a.backfill.state || '') : ''}</span></td>
      <td><input type="checkbox" data-auto="${esc(a.id)}" ${a.automate ? 'checked' : ''} ${a.status !== 'ACTIVE' ? 'disabled' : ''}></td>
      <td class="small">${['page', 'instagram', 'pixel', 'catalog'].map((k) => { const lc = (a.link_checks || {})[k]; return `<span class="chip ${lc && lc.ok ? 'acc' : ''}" title="${esc((lc && (lc.name || lc.error)) || '')}">${esc(k)} ${lc ? (lc.ok ? '✓' : '!') : '—'}</span>`; }).join(' ')}
        <button type="button" class="ghost small" data-autolink="${esc(a.id)}" title="${esc(tt('Pick this ad account\'s own Page, Instagram, pixel and catalog from its business', 'اختيار الصفحة والبكسل والكتالوج من نشاط هذا الحساب'))}">${esc(tt('Auto-link', 'ربط تلقائي'))}</button></td></tr>`).join('')}</tbody></table></div>`;
    // Link by hand (the owner, 21 Nov: Auto-link said "no business owns this ad account" and there was nowhere to pick
    // a Page): every Page / Instagram / pixel / catalog the token can see, per account, or an id typed in.
    const LK = [['page_id', 'pages', tt('Facebook Page', 'صفحة فيسبوك')], ['instagram_user_id', 'instagram_accounts', tt('Instagram', 'إنستغرام')], ['pixel_id', 'pixels', tt('Pixel / dataset', 'البكسل')], ['catalog_id', 'catalogs', tt('Catalog', 'الكتالوج')]];
    api('/api/accounts', { noAccount: true }).then((r) => {
      if (!v.isConnected) return;
      const bz = (r && r.businesses) || [], pool = {};
      for (const [, key] of LK) { pool[key] = []; const seen = {}; for (const b of bz) for (const x of (b[key] || b[key === 'instagram_accounts' ? 'instagram' : key] || [])) if (x && x.id && !seen[x.id]) { seen[x.id] = 1; pool[key].push({ id: x.id, name: (x.name || x.username || x.id) + (b.name ? ' · ' + b.name : '') }); } }
      const rows = (r && r.accounts) || [];
      const host = document.createElement('div'); host.className = 'card'; host.style.marginTop = '16px';
      host.innerHTML = `<header><h2>${esc(tt('Link by hand', 'الربط يدوياً'))}</h2><span class="sub">${esc(tt('Pick what each ad account uses, or paste an id from Meta Business Settings. A sales campaign needs a Page and the pixel; remarketing needs the catalog.', 'اختاري ما يستخدمه كل حساب، أو ألصقي المعرّف من إعدادات أعمال ميتا. حملة المبيعات تحتاج صفحة والبكسل؛ وإعادة الاستهداف تحتاج الكتالوج.'))}</span></header>
        ${rows.map((a) => `<details data-hand="${esc(a.id)}"><summary><b>${esc(a.name || a.id)}</b> <span class="small muted">${esc(a.id)}</span></summary><div class="grid g2" style="margin:10px 0">${LK.map(([f, key, label]) => { const cur = (a.links || {})[f] || ''; const opts = pool[key] || []; return `<label class="f"><span>${esc(label)}</span><select data-f="${f}"><option value="">${esc(tt('— none —', '— لا شيء —'))}</option>${opts.map((o) => `<option value="${esc(o.id)}" ${o.id === cur ? 'selected' : ''}>${esc(o.name)}</option>`).join('')}${cur && !opts.some((o) => o.id === cur) ? `<option value="${esc(cur)}" selected>${esc(cur)}</option>` : ''}</select><input data-typed="${f}" placeholder="${esc(tt('or paste the id', 'أو ألصقي المعرّف'))}" style="margin-top:4px"></label>`; }).join('')}</div><button type="button" data-handsave="${esc(a.id)}">${esc(tt('Save links', 'حفظ الروابط'))}</button></details>`).join('')}
        ${bz.some((b) => b.error) ? `<p class="small muted">${esc(tt('Meta refused some lists: ', 'رفضت ميتا بعض القوائم: '))}${esc(bz.filter((b) => b.error).map((b) => b.name + ': ' + b.error).join(' · ').slice(0, 400))}</p>` : ''}
        ${!Object.values(pool).some((l) => l.length) ? `<p class="small">${esc(tt('Meta showed no Pages or pixels to this key. In Business Settings → Users → System users → your system user → Assign assets, give it your Page, pixel (dataset) and catalog, then click Try again in Connections — or paste the ids here.', 'لم تُظهر ميتا صفحات أو بكسل لهذا المفتاح. في إعدادات الأعمال ← المستخدمون ← مستخدمو النظام ← مستخدمك ← تعيين الأصول، امنحيه الصفحة والبكسل والكتالوج، ثم اضغطي «حاول مجدداً» في الاتصالات — أو ألصقي المعرّفات هنا.'))}</p>` : ''}`;
      v.appendChild(host);
      host.querySelectorAll('[data-handsave]').forEach((b) => {
        b.onclick = async () => {
          const box = b.closest('[data-hand]'), links = {};
          for (const [f] of LK) { const typed = box.querySelector(`[data-typed="${f}"]`).value.trim(); const sel = box.querySelector(`[data-f="${f}"]`).value; links[f] = typed || sel; }
          try { const res = await api(`/api/accounts/${encodeURIComponent(b.dataset.handsave)}`, { method: 'PUT', body: { links }, noAccount: true }); const lc = res.link_checks || {}; const bad = Object.entries(lc).filter(([, c]) => c && !c.ok); toast(bad.length ? tt('Saved, but Meta refused: ', 'حُفظ، لكن ميتا رفضت: ') + bad.map(([k, c]) => k + ' (' + (c.error || '') + ')').join(', ') : tt('Linked and checked with Meta.', 'تم الربط والتحقق مع ميتا.')); await refreshAccounts(); render(S, el); } catch (e) { toast(e.message); }
        };
      });
    }).catch((e) => { if (v.isConnected) { const p = document.createElement('p'); p.className = 'small'; p.textContent = tt('Could not read the accounts list: ', 'تعذّرت قراءة قائمة الحسابات: ') + e.message; v.appendChild(p); } });
    v.querySelectorAll('[data-auto]').forEach((cb) => {
      cb.onchange = async () => { try { await api(`/api/accounts/${cb.dataset.auto}`, { method: 'PUT', body: { automated: cb.checked }, noAccount: true }); await refreshAccounts(); toast(tt('Saved.', 'تم الحفظ.')); } catch (e) { cb.checked = !cb.checked; toast(e.message); } };
    });
  } else {
    const e = st.economics || {};
    v.innerHTML = `<div class="grid g2"><div class="card"><header><h2>${esc(tt('Unit economics in use', 'اقتصاديات الوحدة المستخدمة'))}</h2></header><table><tbody>
      <tr><td>${esc(tt('Average order value', 'متوسط قيمة الطلب'))}</td><td class="n">${aed(e.aov)} <span class="muted small">(${esc(e.aov_source || '')})</span></td></tr>
      <tr><td>${esc(tt('Gross margin', 'الهامش الإجمالي'))}</td><td class="n">${num((e.margin_pct || 0) * 100)}% <span class="muted small">(${esc(e.margin_source || '')})</span></td></tr>
      <tr><td>${esc(tt('Break-even cost per order / ROAS', 'نقطة التعادل'))}</td><td class="n">${aed(e.breakeven_cpa)} / ${num(e.breakeven_roas, 2)}</td></tr>
      <tr><td>${esc(tt('Target cost per order / ROAS', 'المستهدف'))}</td><td class="n">${aed(e.target_cpa)} / ${num(e.target_roas, 2)}</td></tr>
      <tr><td>${esc(tt('Season now', 'الموسم الحالي'))}</td><td class="n">${esc(e.season || '—')} ×${num(e.season_tolerance, 2)}</td></tr></tbody></table></div>
      <div class="card"><header><h2>${esc(tt('Keyboard', 'لوحة المفاتيح'))}</h2></header><table><tbody>
      ${[['⌘K', tt('search', 'بحث')], ['1–9, 0', tt('screens', 'الشاشات')], ['A', tt('account switcher', 'تبديل الحساب')], ['[ ]', tt('previous / next account', 'الحساب السابق / التالي')], ['R', tt('run a cycle', 'تشغيل دورة')], ['T', tt('light / dark', 'فاتح / داكن')], ['L', 'English / العربية']].map(([k, d]) => `<tr><td><kbd>${esc(k)}</kbd></td><td>${esc(d)}</td></tr>`).join('')}</tbody></table>
      <p class="small"><a href="#/welcome">${esc(tt('Run the setup wizard again', 'تشغيل معالج الإعداد مرة أخرى'))}</a></p></div></div>
      <h2 style="margin-top:18px">${esc(tt('Generators and cut-out (advanced)', 'المولّدات والقصّ (متقدم)'))}</h2><p class="small muted">${esc(tt('Every AI provider, its own key and price. Most people only need the one key in Connections.', 'كل مزوّد ذكاء اصطناعي بمفتاحه وسعره. يكفي معظم الناس المفتاح الواحد في الاتصالات.'))}</p><div id="gen-mount"></div>`;
    mountGenerators(v.querySelector('#gen-mount')); // every provider + cut-out model (creative/gen-ui.js)
  }
  el.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => { tab = b.dataset.tab; render(S, el); }; });
}
export async function load() {
  if (wording) return false;
  wording = await brainSettings().catch(() => ({ ad_wording: 'balanced' }));
  return location.hash.startsWith('#/settings');
}
