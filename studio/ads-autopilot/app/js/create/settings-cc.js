// Settings cards for creation:
//   Connections → "AI pictures & video": ONE optional key (a Google Gemini API key: Gemini image + Veo video), the
//                 single recommended default; every other provider lives under Settings → Advanced.
//   Connections → "Claude desktop (your Max plan)": install the local connector (config merged, old file backed up),
//                 the manual steps, live "connected" status, and the prompt to try.
//   Limits → "Creation": With me in Claude (Max) (default) | Automatic (API, credits); the API fallback is OFF.
import { tt, getLang } from '../i18n.js';
import { esc, toast } from '../ui.js';
import { api } from '../api.js';
import { loadCreation } from './banner.js';

export async function mountOneKey(el) {
  if (!el) return;
  const g = await api('/api/brain/x/gen/settings', { noAccount: true }).catch(() => null);
  if (!el.isConnected) return;
  const one = g && g.one_key;
  if (!one) { el.innerHTML = ''; return; }
  const on = one.key && one.key.set;
  el.innerHTML = `<div class="card" data-onekey><header><h2>${esc(tt('AI pictures & video', 'صور وفيديو بالذكاء الاصطناعي'))} <span class="chip">${esc(tt('optional', 'اختياري'))}</span></h2>
    <span class="sub">${esc(tt('One Google Gemini API key gives both: AI backgrounds (Gemini image) and moving scenes (Veo). Your real product photo always sits on top. Without it, everything still works: designs and motion are drawn on this Mac, free.', 'مفتاح Google Gemini واحد يعطي الاثنين: خلفيات (Gemini) ومشاهد متحركة (Veo). صورة منتجكِ الحقيقية فوقها دائماً. بدونه يعمل كل شيء: التصاميم والحركة تُرسم على جهازكِ مجاناً.'))}</span></header>
    <div class="row"><span class="dot ${on ? 'ok' : ''}"></span><b>${esc(on ? tt('Connected', 'متصل') + ' ••••' + one.key.last4 : tt('Not added', 'غير مضاف'))}</b>
      <span class="small muted">${esc(tt(`Picture ~$${Number(one.image_usd).toFixed(2)} · video ~$${Number(one.video_usd_per_s).toFixed(2)}/s · monthly budget $${Number(g.budget_usd).toFixed(0)} (spent $${Number(g.spent_usd).toFixed(2)}) · the price is shown before every one`, `الصورة ~$${Number(one.image_usd).toFixed(2)} · الفيديو ~$${Number(one.video_usd_per_s).toFixed(2)}/ث · الميزانية الشهرية $${Number(g.budget_usd).toFixed(0)} · يظهر السعر قبل كل مرة`))}</span></div>
    <div class="row" style="margin-top:10px"><input type="password" id="ok-key" autocomplete="off" placeholder="${esc(on ? '••••' + one.key.last4 : tt('Paste the Gemini API key', 'ألصقي مفتاح Gemini'))}" style="width:280px"><button type="button" id="ok-save">${esc(tt('Save', 'حفظ'))}</button>${on ? `<button type="button" class="ghost small" id="ok-del">${esc(tt('Remove', 'إزالة'))}</button>` : ''}
      <a class="small" href="${esc(one.key_url)}" target="_blank" rel="noopener noreferrer">${esc(tt('Get a key (Google AI Studio)', 'احصلي على مفتاح (Google AI Studio)'))}</a></div>
    <p class="small muted">${esc(one.terms)} · ${esc(tt('Models', 'النماذج'))}: ${esc(one.image_model)} + ${esc(one.video_model)} · ${esc(tt('Other providers: Settings → Advanced.', 'مزوّدون آخرون: الإعدادات ← متقدم.'))}</p></div>`;
  const save = async (key) => { try { await api('/api/brain/x/gen/one-key', { method: 'PUT', noAccount: true, body: { key } }); toast(key ? tt('Saved in the Keychain.', 'حُفظ في سلسلة المفاتيح.') : tt('Removed.', 'أُزيل.')); mountOneKey(el); } catch (e) { toast(e.message); } };
  el.querySelector('#ok-save').onclick = () => { const v = el.querySelector('#ok-key').value.trim(); if (v) save(v); };
  const d = el.querySelector('#ok-del'); if (d) d.onclick = () => save('');
}

export async function mountClaudeApp(el) {
  if (!el) return;
  const m = await api('/api/mcp', { noAccount: true }).catch(() => null);
  if (!el.isConnected || !m) return;
  const ar = getLang() === 'ar';
  el.innerHTML = `<div class="card" data-mcp><header><h2>${esc(tt('Claude desktop (your Max plan)', 'تطبيق Claude (باقة Max)'))}</h2>
    <span class="sub">${esc(tt('Create ads by talking to Claude in its own app, on your Max plan: no API credits. Claude reads the brief, makes the ads, and this app draws them, checks the wording and quality, and runs them inside your limits.', 'أنشئي الإعلانات بالحديث مع Claude في تطبيقه على باقة Max: بلا رصيد API. يقرأ Claude الموجز ويصنع الإعلانات، وهذا التطبيق يرسمها ويراجع الصياغة والجودة ويشغّلها ضمن حدودكِ.'))}</span></header>
    <div class="row"><span class="dot ${m.connected ? 'ok' : m.installed ? 'warn' : ''}"></span><b>${esc(m.connected ? tt('Connected', 'متصل') : m.installed ? tt('Installed — restart Claude', 'مثبّت — أعيدي تشغيل Claude') : tt('Not installed', 'غير مثبّت'))}</b>
      ${m.last_seen ? `<span class="small muted">${esc(tt('last used', 'آخر استخدام'))}: ${esc(new Date(m.last_seen).toLocaleTimeString(ar ? 'ar-AE' : 'en-AE'))}${m.last_tool ? ' · ' + esc(m.last_tool) : ''}</span>` : ''}
      <span class="spacer"></span><button type="button" id="mcp-install">${esc(m.installed ? tt('Reinstall connector', 'إعادة تثبيت الموصل') : tt('Install connector', 'تثبيت الموصل'))}</button></div>
    <ol class="cc-guide small" style="margin-top:10px"><li>${esc(tt('Install connector (above). Your existing Claude settings are kept; the old file is backed up next to it.', 'ثبّتي الموصل (أعلاه). تبقى إعدادات Claude كما هي؛ وتُحفظ نسخة من الملف القديم.'))}</li>
      <li>${esc(tt('Quit Claude completely (⌘Q) and open it again.', 'أغلقي Claude تماماً (⌘Q) ثم افتحيه.'))}</li>
      <li>${esc(tt('Keep this app open while you create (it draws the pictures). Try:', 'أبقي هذا التطبيق مفتوحاً أثناء الإنشاء (فهو يرسم الصور). جرّبي:'))}</li></ol>
    <pre class="report cc-brief" dir="${ar ? 'rtl' : 'ltr'}">${esc(ar ? m.try_ar : m.try)}</pre>
    <div class="row"><button type="button" class="ghost small" id="mcp-try">${esc(tt('Open Claude with this', 'افتحي Claude بهذا'))}</button><button type="button" class="ghost small" id="mcp-copy">${esc(tt('Copy', 'نسخ'))}</button></div>
    <details class="adv"><summary>${esc(tt('Manual setup', 'إعداد يدوي'))}</summary><p class="small muted">${esc(tt('Claude → Settings → Developer → Edit Config, then add this inside the file:', 'Claude ← الإعدادات ← المطوّر ← تعديل الإعدادات، ثم أضيفي هذا داخل الملف:'))} <code>${esc(m.config_path)}</code></p><pre class="report cc-brief" dir="ltr">${esc(JSON.stringify(m.entry, null, 2))}</pre></details></div>`;
  el.querySelector('#mcp-install').onclick = async () => {
    try { const r = await api('/api/mcp/install', { method: 'POST', noAccount: true }); toast(tt('Installed. ', 'تم التثبيت. ') + (r.backup ? tt('Old settings backed up. ', 'حُفظت نسخة من الإعدادات. ') : '') + tt('Quit Claude (⌘Q) and open it again.', 'أغلقي Claude (⌘Q) وافتحيه.')); mountClaudeApp(el); } catch (e) { toast(e.message); }
  };
  const prompt = ar ? m.try_ar : m.try;
  el.querySelector('#mcp-try').onclick = () => { navigator.clipboard?.writeText(prompt).catch(() => {}); location.href = 'claude://claude.ai/new?q=' + encodeURIComponent(prompt); };
  el.querySelector('#mcp-copy').onclick = () => navigator.clipboard?.writeText(prompt).then(() => toast(tt('Copied.', 'نُسخ.'))).catch(() => {});
}

export async function mountCreationMode(el, account) {
  if (!el) return;
  const s = await api('/api/brain/x/settings').catch(() => null);
  if (!el.isConnected || !s) return;
  const withMe = s.creation_mode !== 'api';
  el.innerHTML = `<div class="card" data-creation-mode><header><h2>${esc(tt('Creation', 'الإنشاء'))}</h2><span class="sub">${esc(tt('Who writes the new ads the autopilot needs (fresh creatives for tired ads, test slots, seasons, trending products).', 'من يكتب الإعلانات الجديدة التي يحتاجها الطيار الآلي (بدل المتعبة، خانات الاختبار، المواسم، المنتجات الرائجة).'))}</span></header>
    <label class="row"><input type="radio" name="cm" value="with_me" ${withMe ? 'checked' : ''}> <span><b>${esc(tt('With me in Claude (Max)', 'معي في Claude (Max)'))}</b> <span class="chip acc">${esc(tt('default · no credits', 'افتراضي · بلا رصيد'))}</span><br><span class="small muted">${esc(tt('It tells you (a Mac notification and a banner here) and you make them with Claude on your Max plan.', 'يخبركِ (إشعار على الماك وشريط هنا) وتصنعينها مع Claude على باقة Max.'))}</span></span></label>
    <label class="row" style="margin-top:8px"><input type="radio" name="cm" value="api" ${withMe ? '' : 'checked'}> <span><b>${esc(tt('Automatic (API)', 'تلقائي (API)'))}</b> <span class="chip">${esc(tt('uses API credits', 'يستخدم رصيد API'))}</span><br><span class="small muted">${esc(tt('The autopilot writes them itself through your Claude API key.', 'يكتبها الطيار الآلي بنفسه عبر مفتاح Claude API.'))}</span></span></label>
    <label class="row small" style="margin-top:10px"><input type="checkbox" id="cm-fb" ${s.fallback_hours > 0 ? 'checked' : ''}> ${esc(tt('If I have not started within', 'إن لم أبدأ خلال'))} <input type="number" id="cm-h" min="1" max="168" value="${esc(s.fallback_hours || 12)}" style="width:70px"> ${esc(tt('hours, create automatically with the API (uses credits)', 'ساعة، أنشئها تلقائياً عبر API (يستخدم رصيداً)'))}</label>
    <div class="row" style="margin-top:12px"><button type="button" id="cm-save">${esc(tt('Save', 'حفظ'))}</button></div></div>`;
  el.querySelector('#cm-save').onclick = async () => {
    const mode = el.querySelector('input[name=cm]:checked').value;
    const fb = el.querySelector('#cm-fb').checked ? Math.max(1, Math.min(168, +el.querySelector('#cm-h').value || 12)) : 0;
    try { await api('/api/brain/x/settings', { method: 'PUT', body: { creation_mode: mode, fallback_hours: fb } }); toast(tt('Saved.', 'تم الحفظ.')); await loadCreation(account); } catch (e) { toast(e.message); }
  };
}
