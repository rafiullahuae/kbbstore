// Settings → Connections → "Claude Code on this Mac (Max plan)" (engine/claudecode.go). The owner: "i clicked on start,
// each time i need to press enter in the claude app? why don't the claude work auto when the laptop is on". With Claude
// Code installed and signed in to his Max account, every creation request is made automatically while this app is
// open: no Enter, no API credits. The card: found / version / signed in, the On/Off toggle, the official install and
// sign-in steps (code.claude.com/docs/en/setup and /authentication), Test, and the last runs. Live over SSE
// ("claudecode"), no polling.
import { tt, getLang } from '../i18n.js';
import { esc, toast, failCard } from '../ui.js';
import { api } from '../api.js';

let EL = null;
const when = (iso) => (iso && !String(iso).startsWith('0001') ? new Date(iso).toLocaleString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
const KIND = { ok: ['made', 'صُنعت'], usage_limit: ['usage limit', 'حدّ الاستخدام'], not_signed_in: ['not signed in', 'غير مسجّل'], timeout: ['timed out', 'انتهت المهلة'], missing: ['not installed', 'غير مثبّت'], no_ads: ['no ads', 'بلا إعلانات'], closed: ['closed elsewhere', 'أُغلق في مكان آخر'] };

export async function mountClaudeCode(el) {
  if (!el) return;
  let v = null, err = null;
  try { v = await api('/api/claudecode', { noAccount: true }); } catch (e) { err = e; }
  if (!el.isConnected) return;
  if (!v) return failCard(el, tt('Claude Code on this Mac (Max plan)', 'Claude Code على هذا الجهاز (باقة Max)'), err, () => mountClaudeCode(el));
  EL = el;
  paint(el, v);
}

// the engine pushed a new state (a run started / moved / ended, the CLI checked again)
export function claudeCodeEvent(v) { if (EL && EL.isConnected && v) paint(EL, v); }

function paint(el, v) {
  const ar = getLang() === 'ar', d = v.detect || {}, cur = v.current;
  const yes = (ok, a, b) => `<span class="cc-ok ${ok ? 'on' : ''}">${ok ? '✓' : '✕'} ${esc(tt(a, b))}</span>`;
  const auto = !!v.auto;
  const status = cur ? tt('Making ads now…', 'يصنع الإعلانات الآن…') : !d.found ? tt('Not installed', 'غير مثبّت') : !d.ready ? tt('Needs your sign-in', 'يحتاج تسجيل دخولكِ') : auto ? tt('On: makes every request by itself', 'مفعّل: يصنع كل طلب بنفسه') : tt('Ready, automatic is off', 'جاهز، التلقائي متوقف');
  const runs = (v.runs || []).slice(0, 5);
  el.innerHTML = `<div class="card" data-ccode><header><h2>${esc(tt('Claude Code on this Mac (Max plan)', 'Claude Code على هذا الجهاز (باقة Max)'))} <span class="chip acc">${esc(tt('no Enter · no credits', 'بلا Enter · بلا رصيد'))}</span></h2>
    <span class="sub">${esc(tt('While this app is open, every creation request is made by Claude Code on your Max plan, on its own: it reads the brief, writes Arabic + English ads, this app draws them and checks wording and quality, and they wait for you in Approvals. Start creating stays there if you want to do it with Claude yourself.', 'ما دام التطبيق مفتوحاً، يصنع Claude Code كل طلب إنشاء بنفسه على باقة Max: يقرأ الموجز ويكتب إعلانات عربية وإنجليزية، ويرسمها هذا التطبيق ويراجع الصياغة والجودة، وتنتظركِ في الموافقات. يبقى «ابدئي الإنشاء» إن أردتِ صنعها بنفسكِ مع Claude.'))}</span></header>
    <div class="row" style="flex-wrap:wrap;gap:10px"><span class="dot ${d.ready ? 'ok' : d.found ? 'warn' : ''}"></span><b data-cc-status>${esc(status)}</b>
      ${yes(d.found, 'detected', 'موجود')}${d.version ? `<span class="small muted" dir="ltr">${esc(d.version)}</span>` : ''}${yes(d.signed_in && d.ready, 'signed in (Max)', 'مسجّل (Max)')}
      <span class="spacer"></span>
      <button type="button" class="tgl ${auto ? 'on' : ''}" role="switch" aria-checked="${auto}" id="cc-auto" ${d.ready ? '' : 'disabled'}><span class="tgl-k"></span><span class="tgl-t">${esc(auto ? tt("Automatic: On", "تلقائي: مفعّل") : tt("Automatic: Off", "تلقائي: متوقف"))}</span></button>
      <button type="button" class="ghost small" id="cc-test" ${d.found ? '' : 'disabled'}>${esc(tt('Test', 'اختبار'))}</button></div>
    ${cur ? `<p class="small cc-live" data-cc-live>⏳ ${esc(cur.title || '')} · ${esc(tt('started', 'بدأ'))} ${esc(when(cur.start))}${cur.tool ? ' · ' + esc(cur.tool) : ''}</p>` : ''}
    ${v.paused_until ? `<p class="small alert warn">${esc(tt('Your Claude usage limit was reached: automatic runs wait until', 'وصلتِ حدّ استخدام Claude: تنتظر التشغيلات التلقائية حتى'))} ${esc(when(v.paused_until))}.</p>` : ''}
    ${d.problem ? `<p class="small" style="color:var(--bad)" data-cc-problem>${esc(ar ? d.problem_ar || d.problem : d.problem)}</p>` : ''}
    <p class="small" id="cc-test-out" hidden></p>
    ${!d.found ? `<ol class="cc-guide small" style="margin-top:8px" data-cc-install>
      <li>${esc(tt('Open Terminal (Spotlight: ⌘ Space, type Terminal) and paste the official installer, then press Return:', 'افتحي Terminal (‏⌘ Space ثم اكتبي Terminal) والصقي أمر التثبيت الرسمي ثم اضغطي Return:'))}<pre class="report cc-brief" dir="ltr">curl -fsSL https://claude.ai/install.sh | bash</pre><span class="muted">${esc(tt('(or, with Homebrew: brew install --cask claude-code)', '(أو مع Homebrew: brew install --cask claude-code)'))}</span></li>
      <li>${esc(tt('Open a NEW Terminal window and check it: claude --version prints a number.', 'افتحي نافذة Terminal جديدة وتحققي: claude --version يطبع رقماً.'))}</li>
      <li>${esc(tt('Then sign in (next step) and press Test here.', 'ثم سجّلي الدخول (الخطوة التالية) واضغطي «اختبار» هنا.'))}</li></ol>` : ''}
    ${d.found && !d.ready ? `<ol class="cc-guide small" style="margin-top:8px" data-cc-signin>
      <li>${esc(tt('In Terminal type claude and press Return. A browser opens: sign in with the Claude account that has your Max plan.', 'في Terminal اكتبي claude واضغطي Return. يفتح المتصفح: سجّلي الدخول بحساب Claude الذي عليه باقة Max.'))}</li>
      <li>${esc(tt('If it is already open, type /login inside it. When it says Login successful, close it (type /exit).', 'إن كان مفتوحاً، اكتبي ‎/login‎ داخله. عندما يقول Login successful أغلقيه (‎/exit‎).'))}</li>
      <li>${esc(tt('Do not choose an API key or Console login: that bills credits. Then press Test.', 'لا تختاري مفتاح API أو Console: فذلك برصيد. ثم اضغطي «اختبار».'))}</li></ol>` : ''}
    ${runs.length ? `<ul class="small cc-guide" data-cc-runs>${runs.map((r) => `<li>${esc(when(r.start))} · ${esc(r.title || r.request)} — <b>${esc((KIND[r.kind] || [r.state, r.state])[ar ? 1 : 0])}</b>${r.state === 'done' ? ' · ' + esc(tt(`${r.ads} ad(s)`, `${r.ads} إعلان`)) + (r.held ? ' · ' + esc(tt(`${r.held} held back`, `${r.held} موقوف`)) : '') : r.reason ? ' · ' + esc(r.reason) : ''} · ${Math.round(r.seconds || 0)} s</li>`).join('')}</ul>` : ''}
    <details class="adv"><summary>${esc(tt('What it may do', 'ما يُسمح له به'))}</summary><p class="small muted">${esc(tt('Only this app\'s own tools (no Terminal commands, no file changes, no web), one request at a time, stopped after', 'أدوات هذا التطبيق فقط (بلا أوامر Terminal ولا تعديل ملفات ولا إنترنت)، طلب واحد في كل مرة، ويتوقف بعد'))} ${esc(v.timeout_min)} ${esc(tt('minutes. Your Anthropic API key is never given to it, so it always uses your Max plan. Never launches an ad on its own.', 'دقائق. لا يُعطى مفتاح Anthropic API أبداً، فيستخدم باقة Max دائماً. لا يُطلق إعلاناً بنفسه.'))}</p><pre class="report cc-brief" dir="ltr">${esc((v.allowed_tools || []).join('\n'))}</pre>
      <p class="small"><a href="https://code.claude.com/docs/en/setup" target="_blank" rel="noopener noreferrer">code.claude.com/docs/en/setup</a> · <a href="https://code.claude.com/docs/en/authentication" target="_blank" rel="noopener noreferrer">…/authentication</a></p></details></div>`;
  const tg = el.querySelector('#cc-auto');
  tg.onclick = async () => {
    tg.disabled = true;
    try { const r = await api('/api/claudecode', { method: 'PUT', noAccount: true, body: { auto: !auto } }); toast(r.auto ? tt('On: Claude Code makes new requests by itself.', 'مفعّل: يصنع Claude Code الطلبات الجديدة بنفسه.') : tt('Off: use Start creating.', 'متوقف: استخدمي «ابدئي الإنشاء».')); paint(el, r); } catch (e) { toast(e.message); tg.disabled = false; }
  };
  const tb = el.querySelector('#cc-test');
  tb.onclick = async () => {
    tb.disabled = true; tb.textContent = tt('Testing…', 'جارٍ الاختبار…');
    let r;
    try { r = await api('/api/claudecode/test', { method: 'POST', noAccount: true }); } catch (e) { r = { ok: false, reason: e.message }; }
    if (!el.isConnected) return;
    try { paint(el, await api('/api/claudecode', { noAccount: true })); } catch {}
    const o = el.querySelector('#cc-test-out');
    o.hidden = false; o.className = 'small alert ' + (r.ok ? 'good' : 'danger');
    o.textContent = r.ok ? tt(`Works: Claude Code answered “${r.reply || 'ok'}” in ${Math.round(r.seconds || 0)} s on your Max plan.`, `يعمل: أجاب Claude Code «${r.reply || 'ok'}» خلال ${Math.round(r.seconds || 0)} ث على باقة Max.`) : tt('Test failed: ', 'فشل الاختبار: ') + (r.reason || '');
  };
}
