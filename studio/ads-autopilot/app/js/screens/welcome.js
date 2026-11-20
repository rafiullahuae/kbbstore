// First-run wizard: Meta -> Claude -> shop -> accounts -> limits -> goals -> start.
import { t, tt } from '../i18n.js';
import { esc, toast } from '../ui.js';
import { api, getAccount } from '../api.js';
import { limitsForm, readLimits, wireWording } from './settings.js';

let step = 0;
const goals = new Set(['sales', 'arabic_first', 'low_cpc']);
const STEPS = [['Meta', 'ميتا'], ['Claude', 'Claude'], ['Shop', 'المتجر'], ['Accounts', 'الحسابات'], ['Limits', 'الحدود'], ['Goals', 'الأهداف'], ['Start', 'البدء']];
const GOALS = [['sales', 'More sales at a profit', 'مبيعات أكثر بربح'], ['arabic_first', 'Arabic audience first', 'الجمهور العربي أولاً'], ['low_cpc', 'Low CPC / CPM, high CTR', 'تكلفة نقرة وظهور منخفضة ونسبة نقر عالية'],
  ['whatsapp', 'WhatsApp conversations', 'محادثات واتساب'], ['retarget', 'Win back visitors and carts', 'استعادة الزوار والسلال المتروكة'], ['seasons', 'Big seasonal pushes (Ramadan, Eid, White Friday)', 'مواسم كبيرة (رمضان، العيد، الجمعة البيضاء)'], ['videos', 'Use my videos', 'استخدام فيديوهاتي']];

export function render(S, el) {
  const c = S.conn || {};
  const cur = S.accounts.find((a) => a.id === getAccount()) || {};
  const ok = (x) => `<span class="row small"><span class="dot ${x && x.ok ? 'ok' : 'bad'}"></span>${esc(x && x.ok ? t('Connected') : t('Not connected'))}</span>`;
  const body = [
    () => `<h2>${esc(tt('Connect Meta', 'ربط ميتا'))}</h2><p class="ink2">${esc(tt('Paste the system-user token from Meta Business Settings → Users → System users. The app reads the whole business portfolio: every ad account, its history and results.', 'الصقي رمز مستخدم النظام من إعدادات أعمال ميتا. يقرأ التطبيق محفظة الأعمال كاملة: كل حساب وسجله ونتائجه.'))}</p>
      <label class="f"><span>${esc(tt('System-user token', 'رمز مستخدم النظام'))}</span><input id="w-in" type="password" autocomplete="off" placeholder="${esc(c.meta?.token?.set ? '•••• ' + c.meta.token.last4 : 'EAA…')}"></label>${ok(c.meta)}
      ${c.meta?.business ? `<p class="small">${esc(tt('Portfolio', 'المحفظة'))}: <b>${esc(c.meta.business.name)}</b> · ${S.accounts.length} ${esc(tt('ad accounts found', 'حسابات إعلانية'))}</p>` : ''}`,
    () => `<h2>${esc(tt('Connect Claude', 'ربط Claude'))}</h2><p class="ink2">${esc(tt('Claude plans, researches the market and writes the Arabic and English ads. The key is stored in your Mac\'s Keychain.', 'يخطط Claude ويبحث في السوق ويكتب الإعلانات بالعربية والإنجليزية. يُحفظ المفتاح في سلسلة مفاتيح جهازك.'))}</p>
      <label class="f"><span>${esc(tt('Anthropic API key', 'مفتاح Anthropic'))}</span><input id="w-in" type="password" autocomplete="off" placeholder="${esc(c.claude?.key?.set ? '•••• ' + c.claude.key.last4 : 'sk-ant-…')}"></label>${ok(c.claude)}`,
    () => `<h2>${esc(tt('Your shop', 'متجرك'))}</h2><p class="ink2">${esc(tt('Products, prices and photos come from the shop\'s catalog feed.', 'المنتجات والأسعار والصور من كتالوج المتجر.'))}</p>
      <label class="f"><span>${esc(tt('Shop address', 'عنوان المتجر'))}</span><input id="w-in" value="${esc(c.shop?.url || 'https://kbeautybliss.com')}"></label>${ok(c.shop)} <span class="small muted">${esc(String(c.shop?.products || 0))} ${esc(tt('products', 'منتجاً'))}</span>`,
    () => `<h2>${esc(tt('Which ad accounts should it run?', 'أي الحسابات يديرها؟'))}</h2><p class="ink2">${esc(tt('It learns from all of them; it only acts in the ones you tick. Each gets its own limits, plan and log.', 'يتعلّم منها كلها؛ ولا يعمل إلا في التي تختارينها. لكل حساب حدوده وخطته وسجله.'))}</p>
      <div class="list">${S.accounts.map((a) => `<div><input type="checkbox" data-acc="${esc(a.id)}" ${a.automate ? 'checked' : ''} ${a.status !== 'ACTIVE' ? 'disabled' : ''}><span><b>${esc(a.name)}</b><br><span class="small muted">${esc(a.id)} · ${esc(a.status)} · ${esc(String(a.history_days || 0))} ${esc(tt('days of history', 'يوماً من السجل'))}</span></span></div>`).join('')}</div>`,
    () => `<h2>${esc(tt('Spending limits', 'حدود الإنفاق'))} · ${esc(cur.name || '')}</h2><p class="ink2">${esc(tt('Hard limits it can never cross. Only going above them needs you. Switch account (top left) to set limits for another one.', 'حدود صارمة لا يتجاوزها أبداً. بدّلي الحساب (أعلى اليسار) لتحديد حدود حساب آخر.'))}</p><div id="lim">${limitsForm((S.state || {}).limits || {}, { noAdvanced: true })}</div>`,
    () => `<h2>${esc(tt('What matters most?', 'ما الأهم لكِ؟'))}</h2><div class="list">${GOALS.map(([k, en, ar]) => `<div><input type="checkbox" data-goal="${k}" ${goals.has(k) ? 'checked' : ''}><span>${esc(tt(en, ar))}</span></div>`).join('')}</div>`,
    () => `<h2>${esc(tt('Ready', 'جاهز'))}</h2><p class="ink2">${esc(tt('It will run cycles while this app is open, catch up when you open it, and keep Meta\'s own spending limit and safety rules in place while your Mac is closed. You get a daily report in Arabic and English.', 'سيعمل في دورات ما دام التطبيق مفتوحاً، ويلحق بما فاته عند فتحه، ويُبقي حد الإنفاق وقواعد الأمان في ميتا أثناء إغلاق جهازك. يصلك تقرير يومي بالعربية والإنجليزية.'))}</p>
      <div class="row"><button type="button" id="go-auto">${esc(t('Start autopilot'))}</button><button type="button" class="ghost" id="go-shadow">${esc(tt('Watch first (shadow mode)', 'المراقبة أولاً (وضع المراقبة)'))}</button></div>`,
  ];
  el.innerHTML = `<div class="wizard"><div class="steps">${STEPS.map((s, i) => `<span class="${i === step ? 'on' : i < step ? 'done' : ''}">${i < step ? '✓ ' : ''}${esc(tt(s[0], s[1]))}</span>`).join('')}</div>
    <div class="card" style="padding:24px;display:flex;flex-direction:column;gap:12px">${body[step]()}
    <div class="spread" style="margin-top:8px"><button type="button" class="ghost" id="back" ${step === 0 ? 'disabled' : ''}>${esc(t('Back'))}</button>${step < STEPS.length - 1 ? `<button type="button" id="next">${esc(t('Continue'))}</button>` : ''}</div></div>
    <p class="small muted" style="text-align:center"><a href="#/dashboard" id="skip">${esc(tt('Skip for now', 'تخطٍّ الآن'))}</a></p></div>`;
  if (step === 4) wireWording(el);
  el.querySelector('#back').onclick = () => { step = Math.max(0, step - 1); render(S, el); };
  const next = el.querySelector('#next');
  if (next) next.onclick = async () => {
    try {
      const v = el.querySelector('#w-in')?.value || '';
      if (step === 0 && v) S.conn = await api('/api/connections/meta', { method: 'PUT', body: { token: v }, noAccount: true });
      if (step === 1 && v) S.conn = await api('/api/connections/claude', { method: 'PUT', body: { key: v }, noAccount: true });
      if (step === 2) S.conn = await api('/api/connections/shop', { method: 'PUT', body: { url: v }, noAccount: true });
      if (step <= 2 && v && !S.conn[['meta', 'claude', 'shop'][step]].ok) { toast(S.conn[['meta', 'claude', 'shop'][step]].error); return; }
      if (step === 3) for (const cb of el.querySelectorAll('[data-acc]')) await api(`/api/accounts/${cb.dataset.acc}`, { method: 'PUT', body: { automate: cb.checked }, noAccount: true });
      if (step === 4) { const L = readLimits(el, (S.state || {}).limits || {}); L.autopilot = 'off'; await api('/api/limits', { method: 'PUT', body: L }); }
      if (step === 5) { goals.clear(); el.querySelectorAll('[data-goal]:checked').forEach((g) => goals.add(g.dataset.goal)); await api('/api/onboarding', { method: 'POST', body: { goals: [...goals] }, noAccount: true }); }
      step++; render(S, el);
    } catch (e) { toast(e.message); }
  };
  const start = async (mode) => {
    try {
      const L = Object.assign({}, (S.state || {}).limits || {});
      await api('/api/limits', { method: 'PUT', body: Object.assign(L, { autopilot: mode }) });
      await api('/api/onboarding', { method: 'POST', body: { done: true }, noAccount: true });
      S.onboarding = { done: true };
      toast(mode === 'auto' ? tt('Autopilot started.', 'بدأ الطيار الآلي.') : tt('Watching in shadow mode.', 'يراقب في وضع المراقبة.'));
      step = 0; location.hash = '#/dashboard';
    } catch (e) { toast(e.message); }
  };
  el.querySelector('#go-auto')?.addEventListener('click', () => start('auto'));
  el.querySelector('#go-shadow')?.addEventListener('click', () => start('shadow'));
  el.querySelector('#skip').onclick = async () => { await api('/api/onboarding', { method: 'POST', body: { done: true }, noAccount: true }).catch(() => {}); };
}
