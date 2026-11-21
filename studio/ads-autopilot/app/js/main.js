// App shell: one page, hash routes, one store, SSE pushes. No framework.
// Layout: H · Sage Calm (full) -- top bar + grouped sidebar; look in css/theme.css.
import { boot, api, events, setAccount, getAccount, staleEngine } from './api.js';
import { tt, getLang, setLang } from './i18n.js';
import { esc, toast, confirmBox, skeleton } from './ui.js';
import { normAccounts, loadState, markDesignDone } from './data.js';
import * as overview from './screens/dashboard.js';
import * as portfolio from './screens/portfolio.js';
import * as campaigns from './screens/campaigns.js';
import * as creatives from './screens/creatives.js';
import * as videos from './screens/videos.js';
import * as audiences from './screens/audiences.js';
import * as research from './screens/research.js';
import * as learned from './screens/learned.js';
import * as plan from './screens/plan.js';
import * as strategy from './screens/strategy.js';
import * as activity from './screens/activity.js';
import * as approvals from './screens/approvals.js';
import * as settings from './screens/settings.js';
import * as welcome from './screens/welcome.js';
import { renderPending } from './creative/render.js';
import { openCreate } from './create/create.js';
import { autoFix, repriceSummary } from './create/reprice.js';
import { onRenderRequest } from './create/remote.js';
import { creationBanner, wireBanner, loadCreation, setCreation, CS } from './create/banner.js';
import { claudeCodeEvent } from './create/claudecode.js';
import { startupNote } from './screens/updates.js';

const I = {
  overview: '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>', portfolio: '<path d="M3 7h18v13H3zM8 7V4h8v3"/>',
  campaigns: '<path d="M4 5h16M4 12h10M4 19h6"/>', creatives: '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>',
  videos: '<rect x="3" y="5" width="14" height="14" rx="2"/><path d="M17 10l4-2v8l-4-2"/>', audiences: '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5M16 11a3 3 0 1 0 0-6M21 20c0-2-1.5-4-4-4.7"/>',
  research: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>', learned: '<path d="M12 3l9 5-9 5-9-5 9-5zM5 10.5V16c0 1.7 3.1 3 7 3s7-1.3 7-3v-5.5"/>',
  strategy: '<path d="M4 20l6-6 4 4 6-10M14 8h6v6"/>', plan: '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>', activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
  approvals: '<path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/>', settings: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
};
const GROUPS = [
  ['RUN', 'تشغيل', [['overview', 'Overview', 'نظرة عامة', overview], ['portfolio', 'Portfolio', 'المحفظة', portfolio], ['campaigns', 'Campaigns', 'الحملات', campaigns],
    ['creatives', 'Creatives', 'الإعلانات', creatives], ['videos', 'Videos', 'الفيديوهات', videos], ['audiences', 'Audiences', 'الجماهير', audiences]]],
  ['THINK', 'تفكير', [['strategy', 'Strategy', 'الاستراتيجية', strategy], ['research', 'Research', 'أبحاث السوق', research], ['learned', 'What it learned', 'ما تعلّمه', learned], ['plan', 'Plan', 'الخطة', plan]]],
  ['CONTROL', 'تحكّم', [['activity', 'Activity', 'السجل', activity], ['approvals', 'Approvals', 'الموافقات', approvals], ['settings', 'Settings', 'الإعدادات', settings]]],
];
const ROUTES = GROUPS.flatMap((g) => g[2]);
const SCREENS = Object.fromEntries([...ROUTES.map((r) => [r[0], r[3]]), ['welcome', welcome], ['dashboard', overview]]);

// ---------- store ----------
export const S = {
  info: null, accounts: [], conn: null, range: 'd7', query: '',
  states: new Map(), cache: new Map(), route: 'overview', switches: [],
  get state() { return this.states.get(getAccount()) || null; },
};
window.__kaa = S; // read by tools/brain-ui.cjs

export async function loadList(key, path) {
  const k = `${getAccount()}|${key}`;
  if (!S.cache.has(k)) S.cache.set(k, await api(path));
  return S.cache.get(k);
}
export function dropList(...keys) { for (const k of keys) S.cache.delete(`${getAccount()}|${k}`); }

export async function refreshState(id = getAccount()) {
  const st = await loadState(id);
  S.states.set(id, st);
  if (id === getAccount()) { paintTop(); paintNav(); rerender(); schedulePending(); }
  return st;
}
export async function refreshAccounts() {
  const r = normAccounts(await api('/api/accounts', { noAccount: true }));
  S.accounts = r.rows;
  paintAccount();
  return r;
}

// ---------- render ----------
const view = document.getElementById('view');
let renderGen = 0;
export function rerender() {
  const mod = SCREENS[S.route];
  if (!mod) return;
  const gen = ++renderGen;
  const t0 = performance.now();
  if (!['welcome', 'portfolio'].includes(S.route) && !S.state) { view.innerHTML = skeleton(); return; }
  mod.render(S, view);
  decorate();
  S.lastRender = performance.now() - t0;
  if (mod.load) mod.load(S).then((changed) => { if (changed && gen === renderGen) rerender(); }).catch((e) => toast(e.message));
}

// decorate: the Create-with-Claude button on Overview + Creatives, and the creation banner on Overview + Approvals
// (pushed over SSE; see create/banner.js). Rendered with the screen, once, no measuring.
function decorate() {
  let h = view.querySelector('.page-h');
  const pg = view.querySelector('.page');
  if (!h && pg && S.route === 'overview') { pg.insertAdjacentHTML('afterbegin', '<div class="page-h cc-ov"><div></div></div>'); h = pg.firstElementChild; }
  if (!h) return;
  if (['overview', 'creatives'].includes(S.route) && !h.querySelector('[data-cc-open]')) {
    h.insertAdjacentHTML('beforeend', `<button type="button" class="cc-btn" data-cc-open>✨ ${esc(tt('Create with Claude', 'أنشئي مع Claude'))} <kbd>C</kbd></button>`);
  }
  if (['overview', 'approvals'].includes(S.route) && CS.account === getAccount()) {
    const b = creationBanner();
    if (b) { h.insertAdjacentHTML('afterend', b); wireBanner(view, rerender); }
  }
}

function go() {
  let h = (location.hash || '#/overview').slice(2).split('?')[0];
  if (h === 'dashboard') h = 'overview';
  const route = SCREENS[h] ? h : 'overview';
  const t0 = performance.now();
  S.route = route;
  for (const a of document.querySelectorAll('#nav a')) a.classList.toggle('on', a.dataset.route === route);
  rerender();
  S.switches.push({ route, ms: Math.round((performance.now() - t0) * 10) / 10 });
  view.scrollTop = 0;
}

function counts() {
  const st = S.state;
  const vids = S.cache.get(`${getAccount()}|videos`);
  return { campaigns: st ? st.tree.length : '', videos: vids ? vids.length : '', approvals: st && st.autopilot.mode !== 'auto' ? (st.pending || st.proposals).length : '' };
}
function paintNav() {
  const c = counts();
  document.getElementById('nav').innerHTML = GROUPS.map(([g, gar, items]) => `<div class="grp">${esc(tt(g, gar))}</div>` + items.map(([id, en, ar]) =>
    `<a href="#/${id}" data-route="${id}" class="${S.route === id ? 'on' : ''}" title="${esc(tt(en, ar))}"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">${I[id]}</svg><span class="lbl">${esc(tt(en, ar))}</span>${c[id] ? `<span class="cnt ${id === 'approvals' ? 'hot' : ''}">${c[id]}</span>` : ''}</a>`).join('')).join('') +
    `<div class="nav-foot"><div class="conn" id="conn">${S.connHTML || ''}</div><div class="warm-at" id="warm-at">${warmAt()}</div><button class="ghost small" id="theme" type="button" title="${esc(tt('Light / dark (T)', 'فاتح / داكن (T)'))}">◐ ${esc(tt('Theme', 'المظهر'))}</button></div>`;
  document.getElementById('theme').onclick = toggleTheme;
}

const MODES = { off: ['Off', 'متوقف'], shadow: ['Practice', 'تدريب'], auto: ['Auto', 'تلقائي'] };
export const modeName = (m) => tt(...(MODES[m] || [m, m]));
function paintTop() {
  const st = S.state;
  const mode = st ? st.autopilot.mode : 'off';
  document.getElementById('range').innerHTML = [['today', 'Today', 'اليوم'], ['d7', '7 days', '٧ أيام'], ['d30', '30 days', '٣٠ يوماً']].map(([id, en, ar]) => `<button type="button" data-r="${id}" class="${S.range === id ? 'on' : ''}">${esc(tt(en, ar))}</button>`).join('');
  document.getElementById('mode').innerHTML = Object.keys(MODES).map((id) =>
    `<button type="button" data-mode="${id}" class="${mode === id ? 'on' : ''}" title="${esc(id === 'shadow' ? tt('Practice: it plans and logs; Meta is not touched.', 'تدريب: يخطط ويسجّل دون أي تغيير على ميتا.') : id === 'auto' ? tt('Auto: it acts inside your limits.', 'تلقائي: ينفّذ ضمن حدودك.') : tt('Off: nothing runs.', 'متوقف: لا شيء يعمل.'))}">${esc(modeName(id))}</button>`).join('');
  document.getElementById('kill').textContent = tt('Stop everything', 'إيقاف كل شيء');
  document.getElementById('search').placeholder = tt('Search screens, campaigns, ads…', 'ابحثي في الشاشات والحملات…');
  document.getElementById('cc-top').innerHTML = `✨ <span>${esc(tt('Create with Claude', 'أنشئي مع Claude'))}</span>`;
  document.getElementById('cc-top').title = tt('Create with Claude (C)', 'أنشئي مع Claude (C)');
  for (const b of document.querySelectorAll('#lang button')) b.classList.toggle('on', b.dataset.l === getLang());
}

function paintAccount() {
  const cur = S.accounts.find((a) => a.id === getAccount()) || S.accounts[0];
  const el = document.getElementById('acct');
  if (!cur) { el.innerHTML = ''; return; }
  const dot = (a) => (a.status !== 'ACTIVE' ? 'bad' : a.mode === 'auto' ? 'ok' : a.mode === 'shadow' ? 'warn' : '');
  el.innerHTML = `<button class="acct-btn" id="acct-btn" type="button" aria-haspopup="true" title="${esc(tt('Switch ad account (A, [ and ])', 'تبديل الحساب الإعلاني (A، [ و ])'))}"><span class="dot ${dot(cur)}"></span><b>${esc(cur.name)}</b><span class="muted small">${esc(cur.currency)}</span>▾</button>
  <div class="acct-menu" id="acct-menu" hidden>${S.accounts.map((a) => `<button type="button" data-acct="${esc(a.id)}" class="${a.id === cur.id ? 'on' : ''}"><span class="dot ${dot(a)}"></span><span><b>${esc(a.name)}</b><br><span class="muted small">${esc(a.id)} · ${esc(a.currency)}${a.status !== 'ACTIVE' ? ' · ' + esc(a.status) : ''}${a.automate ? '' : ' · ' + esc(tt('not automated', 'غير مؤتمت'))}</span></span><span class="pill ${esc(a.mode)}">${esc(modeName(a.mode))}</span></button>`).join('')}</div>`;
}

export function switchAccount(id) {
  if (!id || id === getAccount()) return;
  setAccount(id);
  try { localStorage.setItem('kaa.account', id); } catch {}
  api('/api/accounts/select', { method: 'POST', body: { account: id }, noAccount: true }).catch((x) => toast(tt('The engine did not record the account switch: ', 'لم يسجّل المحرك تبديل الحساب: ') + x.message));
  paintAccount(); paintTop(); paintNav();
  rerender(); // instant from the warmed cache
  refreshState(id).catch((e) => toast(e.message));
  loadCreation(id).then(() => rerender()).catch(() => {});
}

let pendingTimer = 0, fixTimer = 0;
function schedulePending() {
  clearTimeout(fixTimer); // every saved ad keeps today's price and each photo once, by itself (create/reprice.js)
  fixTimer = setTimeout(() => (window.requestIdleCallback || setTimeout)(() => autoFix((r) => {
    const id = getAccount();
    if (r.fixed || r.failed.length) toast(repriceSummary(r).join(' '));
    for (const k of ['ccsessions', 'creatives', 'designs']) S.cache.delete(`${id}|${k}`);
    dropList('creatives');
    if (S.route === 'creatives') rerender();
  }).catch(() => {})), 4000);
  const st = S.state;
  if (!st || !st.designs_pending || !st.designs_pending.length) return;
  clearTimeout(pendingTimer);
  pendingTimer = setTimeout(() => {
    (window.requestIdleCallback || setTimeout)(() => renderPending(st.designs_pending, async (ids) => { for (const id of ids) await markDesignDone(id).catch(() => {}); dropList('creatives'); await refreshState(); }));
  }, 400);
}

function toggleTheme() {
  const r = document.documentElement;
  const cur = r.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  r.dataset.theme = cur === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem('kaa.theme', r.dataset.theme); } catch {}
}

// ---------- events ----------
document.addEventListener('click', async (e) => {
  if (!(e.target instanceof Element)) return;
  const menu = document.getElementById('acct-menu');
  if (e.target.closest('#acct-btn')) { menu.hidden = !menu.hidden; return; }
  if (e.target.closest('#cc-top, [data-cc-open]')) { openCreate(); return; }
  const pick = e.target.closest('[data-acct]');
  if (pick) { menu.hidden = true; switchAccount(pick.dataset.acct); return; }
  if (menu && !e.target.closest('#acct')) menu.hidden = true;
  const r = e.target.closest('#range [data-r]');
  if (r) { S.range = r.dataset.r; paintTop(); rerender(); return; }
  const l = e.target.closest('#lang [data-l]');
  if (l && l.dataset.l !== getLang()) { setLang(l.dataset.l); paintTop(); paintNav(); paintAccount(); go(); return; }
  const m = e.target.closest('#mode [data-mode]');
  if (m && S.state && m.dataset.mode !== S.state.autopilot.mode) await setMode(m.dataset.mode);
});
export async function setMode(mode) {
  const st = S.state;
  if (mode !== 'off' && !st.limits_set) { toast(tt('Set a daily and a monthly limit first (Settings → Limits).', 'حدّدي الحد اليومي والشهري أولاً (الإعدادات ← الحدود).')); location.hash = '#/settings'; return; }
  const prev = st.limits.autopilot;
  try {
    await api('/api/limits', { method: 'PUT', body: { autopilot: mode } });
    toast(tt(`Autopilot: ${modeName(mode)}.`, `الطيار الآلي: ${modeName(mode)}.`), async () => { await api('/api/limits', { method: 'PUT', body: { autopilot: prev } }); await refreshState(); await refreshAccounts(); });
    await refreshState(); await refreshAccounts();
  } catch (x) { toast(x.message); }
}
document.getElementById('kill').onclick = async () => {
  const ok = await confirmBox(tt('Stop everything?', 'إيقاف كل شيء؟'), tt('Pauses every campaign this app made, in every ad account, and turns the autopilot off. Your own campaigns are not touched.', 'يوقف كل الحملات التي أنشأها التطبيق في كل الحسابات ويطفئ الطيار الآلي. حملاتك الخاصة لا تتأثر.'), tt('Stop everything', 'إيقاف كل شيء'), true);
  if (!ok) return;
  try { await api('/api/kill?account=all', { method: 'POST', noAccount: true }); toast(tt('Everything the app made is paused; autopilot is off.', 'تم إيقاف كل ما أنشأه التطبيق؛ الطيار الآلي متوقف.')); S.states.clear(); await refreshAccounts(); await refreshState(); } catch (x) { toast(x.message); }
};
const search = document.getElementById('search');
// places inside a screen the search finds by name (EN / AR words, at least 3 letters typed)
const SEARCH_MORE = [[['update', 'updates', 'upgrade', 'patch', 'roll back', 'rollback', 'version', 'تحديث', 'التحديثات', 'إصدار', 'نسخة', 'رجوع'], '#/settings?tab=updates']];
search.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  const q = search.value.trim().toLowerCase();
  if (!q) return;
  const more = q.length >= 3 && SEARCH_MORE.find(([words]) => words.some((w) => w.startsWith(q) || q.includes(w)));
  if (more) { location.hash = more[1]; search.blur(); return; }
  const r = ROUTES.find((x) => x[1].toLowerCase().includes(q) || x[2].includes(q));
  if (r) location.hash = '#/' + r[0]; else { S.query = q; location.hash = '#/campaigns'; rerender(); }
  search.blur();
});
document.addEventListener('keydown', (e) => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); search.focus(); search.select(); return; }
  if ((e.target instanceof Element && e.target.closest('input, textarea, select')) || e.metaKey || e.ctrlKey || e.altKey) return;
  const n = e.key === '0' ? 10 : parseInt(e.key, 10);
  if (n >= 1 && n <= ROUTES.length) { location.hash = '#/' + ROUTES[n - 1][0]; e.preventDefault(); }
  else if (e.key === 't' || e.key === 'T') toggleTheme();
  else if (e.key === 'c' || e.key === 'C') { e.preventDefault(); openCreate(); }
  else if (e.key === 'l' || e.key === 'L') { setLang(getLang() === 'ar' ? 'en' : 'ar'); paintTop(); paintNav(); paintAccount(); go(); }
  else if (e.key === 'r' || e.key === 'R') runCycle();
  else if (e.key === 'a' || e.key === 'A') document.getElementById('acct-btn')?.click();
  else if (e.key === ']' || e.key === '[') {
    const i = S.accounts.findIndex((a) => a.id === getAccount());
    const nx = S.accounts[(i + (e.key === ']' ? 1 : S.accounts.length - 1)) % S.accounts.length];
    if (nx) switchAccount(nx.id);
  }
});
// runCycle: says at once that it started (the owner, 22 Nov: "i clicked run cycle, nothing happend ... i didn't see
// any notice") — a full cycle reads every live ad from Meta, plans and launches, and can take minutes — keeps the
// button busy so a second click does not queue a second cycle, and ends with what it did and anything that failed.
let cycling = false;
export async function runCycle() {
  if (cycling) { toast(tt('A cycle is already running — it finishes by itself; Activity shows each step.', 'الدورة تعمل الآن وتنتهي وحدها؛ النشاط يُظهر كل خطوة.')); return; }
  cycling = true; window.__kaaCycling = true; // the Overview redraws itself meanwhile: the button stays "Running…"
  const btns = [...document.querySelectorAll('#cyc')];
  btns.forEach((b) => { b.disabled = true; b.dataset.label = b.textContent; b.textContent = tt('Running… (reading Meta)', 'تعمل… (قراءة Meta)'); });
  toast(tt('Cycle started: reading Meta, judging every ad, planning and launching inside your limits. It can take a few minutes; Activity shows each step.', 'بدأت الدورة: قراءة Meta وتقييم كل إعلان والتخطيط والإطلاق ضمن حدودك. قد تستغرق بضع دقائق؛ النشاط يُظهر كل خطوة.'));
  const t0 = Date.now();
  try {
    const r = await api('/api/cycle', { method: 'POST', body: { mode: S.state?.autopilot.mode === 'auto' ? 'auto' : 'shadow' } });
    const c = (r && r.counts) || {};
    const done = (c.done || 0) + (c.duplicate || 0), bad = (c.failed || 0) + (c.refused || 0);
    const secs = Math.round((Date.now() - t0) / 1000);
    const parts = [tt(`Cycle finished in ${secs} s: ${done} change(s) made on Meta`, `انتهت الدورة خلال ${secs} ث: ${done} تغيير على Meta`)];
    if (c.shadow) parts.push(tt(`${c.shadow} waiting for your approval (Approvals)`, `${c.shadow} بانتظار موافقتك (الموافقات)`));
    if (bad) parts.push(tt(`${bad} refused or failed (Activity says why)`, `${bad} رُفض أو فشل (النشاط يذكر السبب)`));
    if (r && r.starter && r.starter.why) parts.push(tt(`First launch: ${r.starter.why}`, `الإطلاق الأول: ${r.starter.why}`));
    for (const k of ['sync_error', 'safety_error', 'error']) if (r && r[k]) parts.push(`⚠ ${r[k]}`);
    toast(parts.join(' · '));
    await refreshState();
  } catch (x) { toast(tt('The cycle stopped: ', 'توقفت الدورة: ') + x.message); }
  finally {
    cycling = false; window.__kaaCycling = false;
    document.querySelectorAll('#cyc').forEach((b) => { b.disabled = false; b.textContent = b.dataset.label || tt('Run a cycle', 'تشغيل دورة'); });
  }
}
window.addEventListener('hashchange', go);

// ---------- warm-up bar (engine warmup.go) ----------
// While the engine refreshes itself (on start, after the Mac wakes, when this page comes back) a slim row under the
// top bar shows each step; offline it says it is waiting and when it tries again; finished, the nav foot says
// "Up to date · 10:42". The numbers on screen stay as they were meanwhile (no blank screens).
const WSTEP = { connections: ['Meta · Claude · shop', 'ميتا · Claude · المتجر'], sync: ['Meta numbers', 'أرقام ميتا'], research: ['Research', 'الأبحاث'], cycle: ['Plan', 'الخطة'], cloud: ['Cloud ads', 'إعلانات السحابة'] };
const WICON = { ok: '✓', skipped: '–', failed: '!', offline: '×', running: '…', waiting: '·' };
const hhmm = (iso) => new Date(iso).toLocaleTimeString(getLang() === 'ar' ? 'ar-AE' : 'en-AE', { hour: '2-digit', minute: '2-digit' });
const realTime = (iso) => !!iso && !String(iso).startsWith('0001');
const warmProblem = (w) => !w.running && !w.offline && (w.steps || []).some((x) => x.state === 'failed');
function warmAt() {
  const w = S.warm;
  if (!w || w.running || !realTime(w.last_full)) return '';
  return `<span class="dot ok"></span><span>${esc(tt('Up to date', 'محدَّث'))} · ${esc(hhmm(w.last_full))}</span>`;
}
function paintWarm() {
  const el = document.getElementById('warm'), at = document.getElementById('warm-at');
  if (at) at.innerHTML = warmAt();
  if (!el) return;
  const w = S.warm;
  if (!w || !(w.running || w.offline || warmProblem(w))) { el.hidden = true; el.innerHTML = ''; return; }
  const steps = (w.steps || []).map((x) => `<span class="ws ${esc(x.state)}" data-step="${esc(x.id)}" title="${esc(x.note || '')}">${esc(tt(...(WSTEP[x.id] || [x.id, x.id])))} <i>${WICON[x.state] || ''}</i></span>`).join('');
  let head, cls, more = '';
  if (w.running) { head = tt('Updating…', 'جارٍ التحديث…'); cls = 'run'; }
  else if (w.offline) {
    head = tt('No internet: waiting', 'لا يوجد اتصال بالإنترنت: ننتظر') + (realTime(w.retry_at) ? ` · ${tt('tries again at', 'المحاولة التالية')} ${hhmm(w.retry_at)}` : '');
    cls = 'off'; more = `<button type="button" class="ghost small" id="warm-try">${esc(tt('Try again', 'حاولي مرة أخرى'))}</button>`;
  } else {
    const bad = (w.steps || []).find((x) => x.state === 'failed');
    head = tt('Updated with a problem', 'تم التحديث مع مشكلة'); cls = 'warn';
    more = `<span class="wnote">${esc(String(bad.note || '').slice(0, 160))}</span><a href="#/settings">${esc(tt('Settings', 'الإعدادات'))}</a>`;
  }
  el.hidden = false;
  el.className = 'warm ' + cls;
  el.innerHTML = `<b>${esc(head)}</b><span class="wsteps">${steps}</span>${more}`;
  const b = document.getElementById('warm-try');
  if (b) b.onclick = () => warmNow('online');
}
async function warmNow(reason) {
  ensureEvents();
  try { const r = await api('/api/warmup', { method: 'POST', noAccount: true, body: { reason } }); S.warm = r.state; paintWarm(); }
  catch (x) { S.connHTML = `<span class="dot bad"></span><span>${esc(tt('Engine not answering: ', 'المحرك لا يستجيب: ') + x.message)}</span>`; paintNav(); }
}
// the stream: EventSource reconnects by itself after a drop, but not once the browser CLOSED it (a long sleep can):
// then a new one is opened when the page is shown again or the network comes back
let es = null, onEv = null;
function ensureEvents() { if (onEv && (!es || es.readyState === 2)) es = events(onEv); }
let hiddenAt = 0;
document.addEventListener('visibilitychange', () => {
  if (document.hidden) { hiddenAt = Date.now(); return; }
  ensureEvents();
  if (hiddenAt && Date.now() - hiddenAt > 5 * 60e3) warmNow('page'); // away a while: the engine refreshes (once)
  hiddenAt = 0;
});
window.addEventListener('online', () => warmNow('online'));

// ---------- start ----------
(async function start() {
  try { const th = localStorage.getItem('kaa.theme'); if (th) document.documentElement.dataset.theme = th; } catch {}
  document.documentElement.dataset.layout = (getComputedStyle(document.documentElement).getPropertyValue('--kaa-layout') || 'sidebar').trim() || 'sidebar';
  paintTop(); paintNav();
  view.innerHTML = skeleton();
  try { S.info = await boot(); } catch {
    S.connHTML = `<span class="dot bad"></span><span>${esc(tt('Engine not running', 'المحرك لا يعمل'))}</span>`; paintNav();
    view.innerHTML = `<div class="page"><div class="card empty"><b>${esc(tt('The engine is not answering', 'المحرك لا يستجيب'))}</b>${esc(tt('Open "Start KBB Ads Autopilot" again; this page reconnects by itself.', 'افتحي «Start KBB Ads Autopilot» مرة أخرى؛ هذه الصفحة تتصل تلقائياً.'))}</div></div>`;
    setTimeout(start, 3000);
    return;
  }
  const stale = staleEngine(S.info);
  if (stale) { // the app was updated but the old engine is still running: say so, run nothing on it
    const v = S.info || {};
    S.connHTML = `<span class="dot bad"></span><span>${esc(tt('Old engine running', 'محرك قديم يعمل'))}</span>`; paintNav();
    view.innerHTML = `<div class="page"><div class="alert danger stale-engine" id="stale-engine" role="alert"><div><b>${esc(tt('The app was updated, but the old engine is still running.', 'تم تحديث التطبيق، لكن المحرك القديم ما زال يعمل.'))}</b><br>${esc(tt('Close the KBB Ads Autopilot Terminal window, then double-click “Start KBB Ads Autopilot” again. Nothing is lost: your accounts, keys and history stay as they are.', 'أغلقي نافذة Terminal الخاصة بـ KBB Ads Autopilot، ثم انقري مرتين على «Start KBB Ads Autopilot» مرة أخرى. لا يضيع شيء: حساباتك ومفاتيحك وسجلك تبقى كما هي.'))}<br><span class="small muted" dir="ltr">${esc(stale === 'api' ? `engine ${v.version || '?'} (API level ${v.apiLevel ?? '?'}) is older than this app (needs API level 2)` : `engine started as ${v.version}, the app folder is now ${v.folderVersion}`)}</span></div></div></div>`;
    return;
  }
  // A failed read is NOT "no accounts": the old .catch(() => ({ accounts: [] })) sent him to the first-run wizard as if
  // his accounts were gone, and a failed /api/connections left the skeleton up for good. Say so, with Try again.
  let acc, connections;
  try { [acc, connections] = await Promise.all([api('/api/accounts', { noAccount: true }), api('/api/connections', { noAccount: true })]); }
  catch (x) {
    S.connHTML = `<span class="dot bad"></span><span>${esc(tt('Engine not answering', 'المحرك لا يستجيب'))}</span>`; paintNav();
    view.innerHTML = `<div class="page"><div class="card empty" id="start-failed" role="alert"><b>${esc(tt('Your accounts could not be read from the engine.', 'تعذّرت قراءة حساباتك من المحرك.'))}</b><span>${esc(tt('Nothing is lost. ', 'لم يضع شيء. ') + x.message)}</span><button type="button" id="start-retry">${esc(tt('Try again', 'حاولي مرة أخرى'))}</button></div></div>`;
    document.getElementById('start-retry').onclick = () => start();
    return;
  }
  const r = normAccounts(acc);
  S.accounts = r.rows; S.conn = connections;
  let saved = ''; try { saved = localStorage.getItem('kaa.account') || ''; } catch {}
  setAccount(S.accounts.find((a) => a.id === saved)?.id || r.selected || (S.accounts[0] || {}).id);
  const okAll = connections.meta.ok && connections.claude.ok && connections.shop.ok;
  S.connHTML = `<span class="dot ${okAll ? 'ok' : 'warn'}"></span><span>${esc(okAll ? tt('Meta · Claude · Shop connected', 'ميتا · Claude · المتجر متصلة') : tt('Check connections', 'تحقّقي من الاتصالات'))}</span>`;
  paintAccount();
  let onboarded = false; try { onboarded = localStorage.getItem('kaa.onboarded') === '1'; } catch {}
  if ((!onboarded && !okAll) || !S.accounts.length) { if (!location.hash.startsWith('#/welcome')) location.hash = '#/welcome'; }
  if (getAccount()) await Promise.all([refreshState().catch((e) => toast(e.message)), loadCreation(getAccount())]);
  go();
  if (!location.hash.includes('tab=updates')) startupNote(); // an automatic roll back he has not seen yet
  for (const a of S.accounts) if (a.id !== getAccount()) refreshState(a.id).catch(() => {});
  api('/api/warmup', { noAccount: true }).then((w) => { S.warm = w; paintWarm(); }).catch(() => {}); // an older engine has none: no bar
  onEv = (type, d) => {
    if (type === 'warmup') { const was = S.warm && S.warm.running; S.warm = d.data; paintWarm(); if (was && !d.data.running) refreshAccounts().catch(() => {}); return; }
    if (type === 'disconnected') { S.connHTML = `<span class="dot bad"></span><span>${esc(tt('Reconnecting…', 'إعادة الاتصال…'))}</span>`; paintNav(); return; }
    if (type === 'hello') { // (re)connected: anything pushed while the stream was down is read once, then pushes take over
      S.sseAt = Date.now();
      const id = getAccount();
      loadCreation(id).then(() => { CS.seen = true; if (id === getAccount() && ['overview', 'approvals'].includes(S.route)) rerender(); }).catch(() => {});
      return;
    }
    if (type === 'heartbeat') return;
    if (type === 'claudecode') { claudeCodeEvent(d.data); return; } // Settings → Connections card, live (create/claudecode.js)
    const acct = d && d.account;
    if (type === 'render.request') { // the Claude-app connector asks this tab to draw (create/remote.js)
      if (acct && acct !== getAccount()) switchAccount(acct);
      onRenderRequest(d);
      return;
    }
    if (type === 'creation') { // a creation request / notification, pushed the moment it exists
      const prev = new Set(((CS.account === acct && CS.view && CS.view.notifications) || []).map((n) => n.at));
      if (acct === getAccount()) {
        setCreation(acct, d.data);
        const fresh = (d.data.notifications || []).filter((n) => !prev.has(n.at));
        if (fresh.length && CS.seen) toast(`${fresh[0].title}: ${getLang() === 'ar' ? fresh[0].body_ar : fresh[0].body}`);
        CS.seen = true;
        if (['overview', 'approvals'].includes(S.route)) rerender();
      }
      return;
    }
    if (type === 'alert' || type === 'action.alert') toast((d.data && d.data.text) || d.text || 'Alert');
    if (type === 'dailyplan' || type === 'watch') { // today-ui.js: the daily plan / watching now, read again once
      if (acct) S.cache.delete(`${acct}|today`);
      if (acct === getAccount() && ['overview', 'plan'].includes(S.route)) rerender();
      return;
    }
    if (['metrics.updated', 'cycle.end', 'action.execution', 'action.kill', 'research.updated', 'backfill.progress', 'connections'].includes(type)) {
      const ids = acct ? [acct] : [...S.states.keys()];
      window.dispatchEvent(new CustomEvent('kaa:stale', { detail: ids })); // strategy / jobs / funnel / occasions caches
      for (const id of ids) { S.cache.forEach((_, k) => { if (k.startsWith(id + '|')) S.cache.delete(k); }); refreshState(id).catch(() => {}); }
      if (type !== 'backfill.progress') refreshAccounts().catch(() => {});
    }
  };
  es = events(onEv);
})();
