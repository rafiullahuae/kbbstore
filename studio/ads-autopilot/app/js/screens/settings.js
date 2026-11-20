import { t, tt } from '../i18n.js';
import { esc, toast, tip, aed, num } from '../ui.js';
import { api } from '../api.js';

let tab = 'limits';
const GCC = [['AE', 'UAE', 'الإمارات'], ['SA', 'Saudi Arabia', 'السعودية'], ['KW', 'Kuwait', 'الكويت'], ['QA', 'Qatar', 'قطر'], ['BH', 'Bahrain', 'البحرين'], ['OM', 'Oman', 'عُمان']];
const WORDING = { strict: ['Strict', 'Blocks every risky pattern, incl. discounts and clinical words.', 'صارمة: تمنع كل صياغة فيها مخاطرة.'], balanced: ['Balanced', 'Default. Acne/blemish/pore products advertised with product framing; blocks only "your acne"-style claims, cure claims, timed results, before/after, fake reviews.', 'الافتراضي: يُعلَن عن منتجات حب الشباب بصياغة تصف المنتج؛ يمنع فقط ادعاءات مثل «حبوبكِ» والعلاج والنتائج المؤقتة وقبل/بعد والمراجعات المزيفة.'], relaxed: ['Relaxed', 'Before/after and strong negative wording become warnings; cure claims and personal-attribute assertions still block.', 'مرنة: قبل/بعد والكلمات السلبية تصبح تحذيرات؛ ادعاءات العلاج تبقى ممنوعة.'] };

export function limitsForm(L, opts = {}) {
  const set = L.daily_aed > 0 && L.monthly_aed > 0;
  return `<div class="grid g3">
    <label class="f"><span>${esc(t('Daily limit (AED)'))} ${tip(tt('The most the autopilot may have running per day in this account, all campaigns together.', 'أقصى إنفاق يومي للطيار الآلي في هذا الحساب لكل الحملات معاً.'))}</span><input name="daily_aed" type="number" min="0" step="10" value="${esc(L.daily_aed || '')}" placeholder="300"></label>
    <label class="f"><span>${esc(t('Monthly limit (AED)'))} ${tip(tt('Also set on Meta as the account spending limit, so Meta stops the ads even while your Mac is closed.', 'يُضبط أيضاً كحد إنفاق للحساب في ميتا، فتتوقف الإعلانات حتى لو كان جهازك مغلقاً.'))}</span><input name="monthly_aed" type="number" min="0" step="100" value="${esc(L.monthly_aed || '')}" placeholder="9000"></label>
    <label class="f"><span>${esc(t('Autopilot'))}</span><select name="autopilot">${['off', 'shadow', 'auto'].map((m) => `<option value="${m}" ${L.autopilot === m ? 'selected' : ''} ${m !== 'off' && !set ? 'disabled' : ''}>${esc(t(m))}${m === 'shadow' ? ' — ' + esc(tt('plan & log only', 'تخطيط وتسجيل فقط')) : m === 'auto' ? ' — ' + esc(tt('acts inside limits', 'ينفّذ ضمن الحدود')) : ''}</option>`).join('')}</select>${set ? '' : `<span class="small" style="color:var(--warn)">${esc(tt('Set a daily and monthly limit to turn it on.', 'حدّدي الحد اليومي والشهري لتشغيله.'))}</span>`}</label>
  </div>
  <div style="margin-top:12px"><span class="small ink2">${esc(tt('Countries', 'الدول'))}</span><div class="row" style="margin-top:4px">${GCC.map(([c, en, ar]) => `<label class="chip"><input type="checkbox" name="country" value="${c}" ${(L.countries || []).includes(c) ? 'checked' : ''}> ${esc(tt(en, ar))}</label>`).join('')}</div></div>
  <div style="margin-top:12px"><span class="small ink2">${esc(t('Ad wording rules'))} ${tip(tt('How strict the policy checker is with ad text. The owner chose Balanced.', 'مدى صرامة فحص صياغة الإعلانات.'))}</span>
    <div class="seg" style="margin-top:4px">${Object.entries(WORDING).map(([k, v]) => `<button type="button" data-w="${k}" class="${(L.ad_wording || 'balanced') === k ? 'on' : ''}" title="${esc(tt(v[1], v[2]))}">${esc(t(v[0]))}</button>`).join('')}</div>
    <p class="small muted" id="wdesc">${esc(tt(WORDING[L.ad_wording || 'balanced'][1], WORDING[L.ad_wording || 'balanced'][2]))}</p></div>
  ${opts.noAdvanced ? '' : `<details class="adv"><summary>${esc(t('Advanced'))}</summary><div class="grid g3" style="margin-top:8px">
    <label class="f"><span>${esc(tt('Max budget step %', 'أقصى خطوة تغيير للميزانية ٪'))} ${tip(tt('Largest single budget change. Bigger jumps restart Meta\'s learning.', 'أكبر تغيير واحد للميزانية؛ القفزات الأكبر تعيد تعلّم ميتا.'))}</span><input name="max_budget_step_pct" type="number" min="5" max="50" value="${esc(L.max_budget_step_pct || 20)}"></label>
    <label class="f"><span>${esc(tt('Cooldown (hours)', 'فترة الانتظار (ساعات)'))}</span><input name="cooldown_hours" type="number" min="24" max="336" value="${esc(L.cooldown_hours || 72)}"></label>
    <label class="f"><span>${esc(tt('Max active ads', 'أقصى عدد إعلانات نشطة'))}</span><input name="max_active_ads" type="number" min="1" max="50" value="${esc(L.max_active_ads || 10)}"></label></div></details>`}`;
}

export function readLimits(root, L) {
  const v = (n) => root.querySelector(`[name="${n}"]`);
  const out = Object.assign({}, L, {
    daily_aed: +v('daily_aed').value || 0, monthly_aed: +v('monthly_aed').value || 0, autopilot: v('autopilot').value,
    countries: [...root.querySelectorAll('[name="country"]:checked')].map((c) => c.value),
    ad_wording: (root.querySelector('[data-w].on') || {}).dataset?.w || 'balanced',
  });
  for (const n of ['max_budget_step_pct', 'cooldown_hours', 'max_active_ads']) if (v(n)) out[n] = +v(n).value || L[n];
  return out;
}
export function wireWording(root) {
  root.querySelectorAll('[data-w]').forEach((b) => { b.onclick = () => { root.querySelectorAll('[data-w]').forEach((x) => x.classList.toggle('on', x === b)); const w = WORDING[b.dataset.w]; root.querySelector('#wdesc').textContent = tt(w[1], w[2]); }; });
}

function connCard(name, title, c, fields) {
  const ok = c && c.ok;
  return `<div class="card" data-conn="${name}"><header><h2>${esc(title)}</h2><span class="row small"><span class="dot ${ok ? 'ok' : 'bad'}"></span>${esc(ok ? t('Connected') : t('Not connected'))}</span></header>
    ${c && c.error ? `<p class="alert danger small">${esc(c.error)}</p>` : ''}
    <div class="grid" style="gap:10px">${fields.map(([k, label, secret, val, ph]) => `<label class="f"><span>${esc(label)}</span><input name="${k}" ${secret ? 'type="password" autocomplete="off"' : ''} value="${secret ? '' : esc(val || '')}" placeholder="${esc(secret && val && val.set ? '•••• ' + val.last4 + ' ' + tt('(saved — type to replace)', '(محفوظ — اكتبي للاستبدال)') : ph || '')}"></label>`).join('')}</div>
    <div class="row" style="margin-top:10px"><button type="button" data-save="${name}">${esc(tt('Save & test', 'حفظ واختبار'))}</button></div></div>`;
}

export function render(S, el) {
  const st = S.state, c = S.conn || {};
  el.innerHTML = `<div class="page"><div class="tabs">${[['limits', 'Limits'], ['connections', 'Connections'], ['accounts', 'Accounts'], ['advanced', 'Advanced']].map(([k, l]) => `<button type="button" data-tab="${k}" class="${tab === k ? 'on' : ''}">${esc(t(l))}</button>`).join('')}</div><div id="tabv"></div></div>`;
  const v = el.querySelector('#tabv');
  if (tab === 'limits') {
    v.innerHTML = `<div class="card"><header><h2>${esc(t('Limits'))} · ${esc(st.account.name)}</h2><span class="muted small">${esc(tt('Only going above these needs you. No limit = nothing launches.', 'فقط تجاوز هذه الحدود يحتاج موافقتك. بلا حدود = لا شيء يُطلق.'))}</span></header>${limitsForm(st.limits)}
      <div class="row" style="margin-top:14px"><button type="button" id="saveL">${esc(t('Save'))}</button></div></div>`;
    wireWording(v);
    v.querySelector('#saveL').onclick = async () => {
      const prev = st.limits, next = readLimits(v, st.limits);
      try {
        await api('/api/limits', { method: 'PUT', body: next });
        toast(tt('Limits saved.', 'تم حفظ الحدود.'), async () => { await api('/api/limits', { method: 'PUT', body: prev }); toast(tt('Restored.', 'تمت الاستعادة.')); });
      } catch (e) { toast(e.message); }
    };
  } else if (tab === 'connections') {
    v.innerHTML = `<p class="small muted">${esc(tt('Keys are stored in the macOS Keychain and never shown again; only the last 4 characters.', 'تُحفظ المفاتيح في سلسلة مفاتيح macOS ولا تظهر مرة أخرى؛ فقط آخر ٤ أحرف.'))}</p><div class="grid g3">
      ${connCard('meta', 'Meta', c.meta, [['token', tt('System-user token', 'رمز مستخدم النظام'), true, c.meta?.token], ['pixel_id', tt('Pixel / dataset ID', 'معرّف البكسل'), false, c.meta?.pixel_id], ['catalog_id', tt('Catalog ID', 'معرّف الكتالوج'), false, c.meta?.catalog_id], ['whatsapp', tt('WhatsApp Business number (for click-to-WhatsApp)', 'رقم واتساب للأعمال'), false, c.meta?.whatsapp, '+9715…']])}
      ${connCard('claude', 'Claude', c.claude, [['key', tt('Anthropic API key', 'مفتاح Anthropic'), true, c.claude?.key]])}
      ${connCard('shop', tt('Shop', 'المتجر'), c.shop, [['url', tt('Shop address', 'عنوان المتجر'), false, c.shop?.url]])}</div>
      <p class="small muted">${c.meta?.business ? esc(tt('Business portfolio', 'محفظة الأعمال') + ': ' + c.meta.business.name) + ' · ' : ''}${esc(tt('Shop products', 'منتجات المتجر'))}: ${num(c.shop?.products)} · ${esc(tt('orders source', 'مصدر الطلبات'))}: ${esc(c.shop?.orders_source || '—')}</p>`;
    v.querySelectorAll('[data-save]').forEach((b) => {
      b.onclick = async () => {
        const card = b.closest('[data-conn]'), body = {};
        card.querySelectorAll('input').forEach((i) => { if (i.value) body[i.name] = i.value; });
        try { S.conn = await api(`/api/connections/${b.dataset.save}`, { method: 'PUT', body, noAccount: true }); toast(S.conn[b.dataset.save].ok ? tt('Saved and connected.', 'تم الحفظ والاتصال.') : S.conn[b.dataset.save].error); render(S, el); } catch (e) { toast(e.message); }
      };
    });
  } else if (tab === 'accounts') {
    v.innerHTML = `<div class="card"><header><h2>${esc(tt('Which accounts to automate', 'الحسابات المؤتمتة'))}</h2></header><table><tbody>${S.accounts.map((a) => `<tr><td><b>${esc(a.name)}</b><br><span class="small muted">${esc(a.id)} · ${esc(a.status)}</span></td><td><span class="pill ${esc(a.mode)}">${esc(t(a.mode))}</span></td><td>${a.limits_set ? esc(tt('limits set', 'الحدود محددة')) : `<span style="color:var(--warn)">${esc(tt('no limits', 'بلا حدود'))}</span>`}</td><td><a href="#/portfolio">${esc(tt('Manage', 'إدارة'))}</a></td></tr>`).join('')}</tbody></table></div>`;
  } else {
    const e = st.economics || {};
    v.innerHTML = `<div class="grid g2"><div class="card"><header><h2>${esc(tt('Unit economics in use', 'اقتصاديات الوحدة المستخدمة'))}</h2></header><table><tbody>
      <tr><td>${esc(tt('Average order value', 'متوسط قيمة الطلب'))}</td><td class="n">${aed(e.aov)} <span class="muted small">(${esc(e.aov_source || '')})</span></td></tr>
      <tr><td>${esc(tt('Gross margin', 'الهامش الإجمالي'))}</td><td class="n">${num((e.margin_pct || 0) * 100)}% <span class="muted small">(${esc(e.margin_source || '')})</span></td></tr>
      <tr><td>${esc(tt('Break-even CPA / ROAS', 'نقطة التعادل'))}</td><td class="n">${aed(e.breakeven_cpa)} / ${num(e.breakeven_roas, 2)}</td></tr>
      <tr><td>${esc(tt('Target CPA / ROAS', 'المستهدف'))}</td><td class="n">${aed(e.target_cpa)} / ${num(e.target_roas, 2)}</td></tr>
      <tr><td>${esc(tt('Season now', 'الموسم الحالي'))}</td><td class="n">${esc(e.season || '—')} ×${num(e.season_tolerance, 2)}</td></tr></tbody></table></div>
      <div class="card"><header><h2>${esc(t('Keyboard'))}</h2></header><table><tbody>
      ${[['1–9', tt('screens', 'الشاشات')], ['A', tt('account switcher', 'تبديل الحساب')], ['[ ]', tt('previous / next account', 'الحساب السابق / التالي')], ['R', tt('run a cycle', 'تشغيل دورة')], ['T', tt('light / dark', 'فاتح / داكن')], ['L', 'English / العربية']].map(([k, d]) => `<tr><td><kbd>${esc(k)}</kbd></td><td>${esc(d)}</td></tr>`).join('')}</tbody></table>
      <p class="small"><a href="#/welcome">${esc(tt('Run the setup wizard again', 'تشغيل معالج الإعداد مرة أخرى'))}</a></p></div></div>`;
  }
  el.querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => { tab = b.dataset.tab; render(S, el); }; });
}
