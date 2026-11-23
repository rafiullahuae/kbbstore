// Running now (the owner, 22 Nov: "where i can see what's running currently, i need the live real time progress
// with proper list"): the engine's live list (engine/live.go), pushed over the event stream the moment each step
// changes. Used by the Overview card, the "● Running" pill and the preview window while it waits.
import { tt } from '../i18n.js';
import { esc } from '../ui.js';

const ICON = { ok: ['✓', 'acc'], warn: ['!', 'warn'], fail: ['✕', 'bad'], info: ['•', ''], run: ['⟳', 'warn'] };
const hm = (t) => { const d = new Date(t); return isNaN(d) || d.getFullYear() < 2000 ? '' : d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };

export function liveSteps(l, max = 60) {
  const steps = (l && l.steps) || [];
  const shown = steps.slice(-max);
  return `<ol class="small" style="list-style:none;margin:6px 0 0;padding:0">${shown.map((s) => { const [ic, cls] = ICON[s.status] || ICON.info;
    return `<li style="display:flex;gap:8px;align-items:flex-start;padding:5px 0;border-top:1px solid var(--line)"><span class="chip ${cls}" style="min-width:22px;justify-content:center${s.status === 'run' ? ';animation:ccspin 1.2s linear infinite' : ''}">${ic}</span><span style="flex:1;min-width:0;overflow-wrap:anywhere"><b>${esc(s.title)}</b>${s.detail ? `<br><span class="muted">${esc(s.detail)}</span>` : ''}</span></li>`; }).join('') || `<li class="muted">${esc(tt('Starting…', 'يبدأ…'))}</li>`}</ol>
  ${steps.length > max ? `<p class="small muted">${esc(tt(`… and ${steps.length - max} earlier step(s)`, `… و${steps.length - max} خطوة سابقة`))}</p>` : ''}`;
}

// the Overview card: while something runs, and for 10 minutes after it ends
export function liveCard(l) {
  if (!l || !l.kind) return '<div id="live-card"></div>';
  const recent = l.running || (l.ended && Date.now() - new Date(l.ended).getTime() < 10 * 60e3);
  if (!recent) return '<div id="live-card"></div>';
  const done = (l.steps || []).filter((s) => s.status === 'ok').length, bad = (l.steps || []).filter((s) => s.status === 'fail' || s.status === 'warn').length;
  return `<div id="live-card" class="card" data-live style="border-color:${l.running ? 'var(--accent)' : 'var(--line)'}"><header><h2>${l.running ? '● ' : ''}${esc(l.running ? tt('Running now', 'يعمل الآن') : tt('Just finished', 'انتهى للتو'))} · ${esc(l.kind)}</h2>
    <span class="small muted">${esc(tt(`started ${hm(l.started)}`, `بدأ ${hm(l.started)}`))}${l.ended && !l.running ? ' · ' + esc(tt(`ended ${hm(l.ended)}`, `انتهى ${hm(l.ended)}`)) : ''} · ${esc(tt(`${done} done${bad ? `, ${bad} need a look` : ''}`, `${done} تم${bad ? `، ${bad} تحتاج نظرة` : ''}`))}</span></header>
    ${l.running && l.now ? `<p class="small" style="margin:4px 0 0"><b>${esc(tt('Now', 'الآن'))}:</b> ${esc(l.now)}</p>` : ''}${liveSteps(l)}</div>`;
}

// The top bar (the owner, 23 Nov: "while running we can close this popup and it should run in the background with live
// progress bar something on the very top of the app"): on every page while something runs — what runs, what it does
// this moment, how many steps are done, a moving bar — and a click opens Overview → Running now.
export function paintPill(l) {
  let p = document.getElementById('livepill');
  if (!p) {
    p = document.createElement('button');
    p.id = 'livepill'; p.type = 'button';
    p.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:70;border:0;border-radius:0;padding:6px 16px 8px;text-align:start;background:var(--accent);color:var(--on-accent,#fff);font-size:13px;box-shadow:0 2px 10px rgba(0,0,0,.25);overflow:hidden;white-space:nowrap;text-overflow:ellipsis';
    p.innerHTML = '<span data-t></span><i style="position:absolute;left:0;bottom:0;height:3px;width:30%;background:rgba(255,255,255,.85);animation:kaabar 1.4s linear infinite"></i>';
    if (!document.getElementById('kaabar-css')) { const st = document.createElement('style'); st.id = 'kaabar-css'; st.textContent = '@keyframes kaabar{0%{transform:translateX(-100%)}100%{transform:translateX(340%)}}@media (prefers-reduced-motion:reduce){#livepill i{animation:none;width:100%;opacity:.5}}'; document.head.appendChild(st); }
    p.onclick = () => { location.hash = '#/overview'; setTimeout(() => { const c = document.getElementById('live-card'); if (c) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300); };
    document.body.appendChild(p);
  }
  p.hidden = !(l && l.running);
  pad();
  if (l && l.running) {
    const steps = l.steps || [], ok = steps.filter((x) => x.status === 'ok').length, bad = steps.filter((x) => x.status === 'fail').length;
    p.querySelector('[data-t]').textContent = `● ${tt('Running', 'يعمل')}: ${l.kind}${l.now ? ' — ' + l.now : ''} · ${tt(`${ok} done${bad ? `, ${bad} not done` : ''}`, `${ok} تم${bad ? `، ${bad} لم يتم` : ''}`)} · ${tt('click for every step', 'اضغطي لكل خطوة')}`;
  }
}

// the page moves down while a bar is at the top, so the bar never covers the app's own top controls
function pad() {
  const on = [document.getElementById('kaaresult'), document.getElementById('livepill')].some((x) => x && !x.hidden);
  const r = document.getElementById('kaaresult'), p = document.getElementById('livepill');
  // the bar's own height (it wraps to 3 lines on a phone): this is the app, not a shop page
  const h = r && !r.hidden ? r.offsetHeight : p && !p.hidden ? p.offsetHeight : 0;
  document.body.style.paddingTop = on ? `${h}px` : '';
  if (r && p) p.style.top = r.hidden ? '0' : 'auto', p.style.bottom = r.hidden ? 'auto' : '0';
}

// The result bar: when a run he started ends, it STAYS at the top until he closes it — what was done, what was not,
// and where every step is (the owner: "after run it should give success message etc. and option to close").
export function showResult(text, ok) {
  let b = document.getElementById('kaaresult');
  if (!b) { b = document.createElement('div'); b.id = 'kaaresult'; b.setAttribute('role', 'status'); document.body.appendChild(b); }
  b.style.cssText = `position:fixed;top:0;left:0;right:0;z-index:71;display:flex;flex-wrap:wrap;gap:8px 10px;align-items:center;padding:10px 16px;background:${ok ? 'var(--accent)' : 'var(--warn)'};color:${ok ? 'var(--on-accent,#fff)' : '#1a1400'};font-size:14px;box-shadow:0 2px 12px rgba(0,0,0,.3)`;
  const bs = 'background:rgba(255,255,255,.18);color:inherit;border:1px solid currentColor;border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer';
  b.innerHTML = `<span style="flex:1 1 260px;min-width:0;overflow-wrap:break-word">${ok ? '✓ ' : '! '}${esc(text)}</span>
    <span style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" data-r-see style="${bs}">${esc(tt('Every step', 'كل خطوة'))}</button>
    <button type="button" data-r-launched style="${bs}">${esc(tt('Launched on Meta', 'ما أُطلق'))}</button>
    <button type="button" data-r-x style="${bs};font-weight:600" aria-label="${esc(tt('Close', 'إغلاق'))}">✕ ${esc(tt('Close', 'إغلاق'))}</button></span>`;
  b.hidden = false;
  pad();
  b.querySelector('[data-r-x]').onclick = () => { b.hidden = true; pad(); };
  b.querySelector('[data-r-see]').onclick = () => { location.hash = '#/overview'; setTimeout(() => { const c = document.getElementById('live-card'); if (c) c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 300); };
  b.querySelector('[data-r-launched]').onclick = () => { try { sessionStorage.setItem('kaa.plantab', 'launched'); } catch {} location.hash = '#/plan'; };
}

// The Run it window (the owner, 23 Nov: "when i click Run on any routine, i'm not seeing any message or something").
// Pressing Run it opens this and it STAYS: what was sent, then every step live (engine/live.go) until the job ends,
// then the outcome in words, with where to look next. A Run it that has to wait says why, here, not in a toast.
// r = the /api/launch answer; ads = [{ name }] what was sent.
export function openRunDialog(r, ads) {
  let d = document.getElementById('rundlg');
  if (!d) { d = document.createElement('dialog'); d.id = 'rundlg'; d.className = 'cc'; document.body.appendChild(d); }
  const opened = Date.now() - 5000;
  const mine = (l) => l && l.kind && new Date(l.started).getTime() >= opened && !/^Cycle|Preview|Your Run a cycle/.test(l.kind);
  let last = mine(window.__kaaLive) ? window.__kaaLive : null;
  const head = () => {
    if (r.waiting) return `<p class="alert warn">${esc(tt('Not sent yet: ', 'لم يُرسل بعد: '))}${esc(r.note || '')}</p><p class="small">${esc(tt('They are saved on Plan → Upcoming jobs and go live as soon as there is room.', 'محفوظة في الخطة ← المهام القادمة وتُنشر حين يتوفر مكان.'))}</p>`;
    if (!r.starting) return `<p class="alert warn">${esc(r.note || tt('Saved.', 'حُفظ.'))}</p>`;
    if (!last) return `<p class="small ink2">${esc(tt('Starting… If a cycle is running, this waits for it, then runs (up to a few minutes).', 'يبدأ… إن كانت دورة تعمل ينتظرها ثم يعمل.'))}</p>`;
    const steps = last.steps || [], ok = steps.filter((s) => s.status === 'ok').length, bad = steps.filter((s) => s.status === 'fail' || s.status === 'warn');
    if (last.running) return `<p class="small"><b>● ${esc(tt('Running now', 'يعمل الآن'))}:</b> ${esc(last.now || '')}</p>`;
    return bad.length
      ? `<p class="alert warn"><b>${esc(tt(`Finished: ${ok} step(s) done, ${bad.length} not done.`, `انتهى: ${ok} تم، ${bad.length} لم يتم.`))}</b> ${esc(tt('The reason is on each ✕ below.', 'السبب مذكور عند كل ✕ أدناه.'))}</p>`
      : `<p class="alert" style="border-color:var(--accent)"><b>✓ ${esc(tt(`Done: ${ok} step(s) on Meta.`, `تم: ${ok} خطوة على Meta.`))}</b> ${esc(tt('Meta reviews new ads (usually minutes) before they show as Active in Ads Manager.', 'تراجع Meta الإعلانات الجديدة قبل أن تظهر نشطة.'))}</p>`;
  };
  const paint = () => {
    d.innerHTML = `<div class="dl" style="max-height:88vh;overflow:auto"><h2 style="margin:0 0 6px">${esc(tt(`Run it: ${ads.length} ad(s)`, `تشغيل: ${ads.length} إعلان`))}</h2>${head()}
      <details ${last ? '' : 'open'}><summary class="small"><b>${esc(tt('What was sent', 'ما أُرسل'))}</b></summary><ol class="small" style="margin:6px 0;padding-inline-start:20px">${ads.map((a) => `<li dir="auto">${esc(a.name)}</li>`).join('')}</ol></details>
      ${last ? `<p class="small" style="margin:10px 0 0"><b>${esc(last.kind)}</b> — ${esc(tt('every step, live', 'كل خطوة مباشرة'))}</p>${liveSteps(last, 80)}` : ''}
      <div class="row" style="justify-content:flex-end;gap:8px;margin-top:12px;flex-wrap:wrap">
        <button type="button" class="ghost" data-rd-go="#/plan">${esc(tt('Plan → Upcoming jobs', 'الخطة ← المهام القادمة'))}</button>
        <button type="button" class="ghost" data-rd-go="#/campaigns">${esc(tt('Campaigns', 'الحملات'))}</button>
        <button type="button" data-rd-close>${esc(last && !last.running ? tt('Close', 'إغلاق') : tt('Close (it keeps running)', 'إغلاق (يستمر العمل)'))}</button></div></div>`;
    d.querySelectorAll('[data-rd-close]').forEach((b) => { b.onclick = () => d.close(); });
    d.querySelectorAll('[data-rd-go]').forEach((b) => { b.onclick = () => { d.close(); location.hash = b.dataset.rdGo; }; });
  };
  const on = (e) => { if (mine(e.detail)) { last = e.detail; if (d.open) paint(); } };
  window.addEventListener('kaa-live', on);
  d.onclose = () => window.removeEventListener('kaa-live', on);
  paint();
  if (!d.open) d.showModal();
  return d;
}
