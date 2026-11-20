// App shell: one page, hash routes, one store, SSE pushes. No framework.
import { boot, api, events, setAccount, getAccount } from './api.js';
import { t, tt, getLang, setLang } from './i18n.js';
import { esc, toast, confirmBox, inTime, skeleton } from './ui.js';
import * as portfolio from './screens/portfolio.js';
import * as dashboard from './screens/dashboard.js';
import * as campaigns from './screens/campaigns.js';
import * as creatives from './screens/creatives.js';
import * as research from './screens/research.js';
import * as plan from './screens/plan.js';
import * as learned from './screens/learned.js';
import * as activity from './screens/activity.js';
import * as settings from './screens/settings.js';
import * as welcome from './screens/welcome.js';
import { renderPending } from './creative/render.js';

const ROUTES = [
  ['portfolio', 'Portfolio', portfolio, '<path d="M3 7h18v13H3zM8 7V4h8v3"/>'],
  ['dashboard', 'Dashboard', dashboard, '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>'],
  ['campaigns', 'Campaigns', campaigns, '<path d="M4 5h16M4 12h10M4 19h6"/>'],
  ['creatives', 'Creatives', creatives, '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>'],
  ['research', 'Research', research, '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'],
  ['plan', 'Plan', plan, '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 10h18M8 2v4M16 2v4"/>'],
  ['learned', 'Learned', learned, '<path d="M12 3l9 5-9 5-9-5 9-5zM5 10.5V16c0 1.7 3.1 3 7 3s7-1.3 7-3v-5.5"/>'],
  ['activity', 'Activity', activity, '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
  ['settings', 'Settings', settings, '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>'],
];
const SCREENS = Object.fromEntries([...ROUTES.map((r) => [r[0], r[2]]), ['welcome', welcome]]);

// ---------- store ----------
export const S = {
  info: null, accounts: [], conn: null, onboarding: null,
  states: new Map(), // account id -> /api/state (cached so switching back is instant)
  cache: new Map(),  // `${account}|${key}` -> list data
  get state() { return this.states.get(getAccount()) || null; },
  route: 'dashboard', switches: [],
};
window.__kaa = S; // read by tools/brain-ui.cjs (switch timings)

export async function loadList(key, path) {
  const k = `${getAccount()}|${key}`;
  if (!S.cache.has(k)) S.cache.set(k, await api(path));
  return S.cache.get(k);
}
export function dropList(...keys) { for (const k of keys) S.cache.delete(`${getAccount()}|${k}`); }

async function refreshState(id = getAccount()) {
  const st = await api('/api/state', { headers: { 'X-KAA-Account': id } });
  S.states.set(id, st);
  if (id === getAccount()) { paintTop(); rerender(); schedulePending(); }
  return st;
}
async function refreshAccounts() { S.accounts = await api('/api/accounts', { noAccount: true }); paintAccount(); }

// ---------- render ----------
const view = document.getElementById('view');
let renderGen = 0;
export function rerender() {
  const mod = SCREENS[S.route];
  if (!mod) return;
  const gen = ++renderGen;
  const t0 = performance.now();
  if (S.route !== 'welcome' && S.route !== 'portfolio' && !S.state) { view.innerHTML = skeleton(); return; }
  mod.render(S, view);
  S.lastRender = performance.now() - t0;
  if (mod.load) mod.load(S).then((changed) => { if (changed && gen === renderGen) rerender(); }).catch((e) => toast(e.message));
}
// a late answer may only repaint the screen that asked for it
export const stillOn = (route) => S.route === route;

function go() {
  const h = (location.hash || '#/dashboard').slice(2).split('?')[0];
  const route = SCREENS[h] ? h : 'dashboard';
  const t0 = performance.now();
  S.route = route;
  for (const a of document.querySelectorAll('#nav a')) a.classList.toggle('on', a.dataset.route === route);
  document.getElementById('title').textContent = route === 'welcome' ? tt('Set up', 'الإعداد') : t(ROUTES.find((r) => r[0] === route)?.[1] || '');
  rerender();
  const ms = performance.now() - t0;
  S.switches.push({ route, ms: Math.round(ms * 10) / 10 });
  view.scrollTop = 0;
}

function paintNav() {
  document.getElementById('nav').innerHTML = ROUTES.map(([id, label, , icon], i) =>
    `<a href="#/${id}" data-route="${id}"><svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">${icon}</svg>${esc(t(label))}<kbd>${i + 1}</kbd></a>${id === 'portfolio' ? '<div class="sep"></div>' : ''}`).join('');
  document.getElementById('lang').textContent = getLang() === 'ar' ? 'English' : 'العربية';
  document.getElementById('cycle').textContent = t('Run cycle');
  document.getElementById('kill').textContent = t('Kill switch');
}

function paintTop() {
  const st = S.state;
  const mode = st ? st.autopilot.mode : '';
  const m = document.getElementById('mode');
  m.className = `pill ${mode}`;
  m.textContent = st ? `${t('Autopilot')}: ${t(mode)}` : '';
  m.dataset.tip = tt('Off: nothing runs. Shadow: it plans and logs, Meta is untouched. Auto: it acts inside your limits.', 'متوقف: لا شيء يعمل. مراقبة: يخطط ويسجّل دون أي تغيير على ميتا. تلقائي: ينفّذ ضمن حدودك.');
  document.getElementById('next').textContent = st && mode !== 'off' ? `${t('Next cycle')}: ${inTime(st.autopilot.next_cycle_at)}` : '';
}

function paintAccount() {
  const cur = S.accounts.find((a) => a.id === getAccount()) || S.accounts[0];
  const el = document.getElementById('acct');
  if (!cur) { el.innerHTML = ''; return; }
  const dot = (a) => (a.status !== 'ACTIVE' ? 'bad' : a.mode === 'auto' ? 'ok' : a.mode === 'shadow' ? 'warn' : '');
  el.innerHTML = `<button class="acct-btn" id="acct-btn" type="button" aria-haspopup="true" title="${esc(tt('Switch ad account (A, [ and ])', 'تبديل الحساب الإعلاني (A، [ و ])'))}"><span class="dot ${dot(cur)}"></span><b>${esc(cur.name)}</b><span class="muted small">${esc(cur.currency)} · ${esc(t(cur.mode))}</span>▾</button>
  <div class="acct-menu" id="acct-menu" hidden>${S.accounts.map((a) => `<button type="button" data-acct="${esc(a.id)}" class="${a.id === cur.id ? 'on' : ''}"><span class="dot ${dot(a)}"></span><span><b>${esc(a.name)}</b><br><span class="muted small">${esc(a.id)} · ${esc(a.currency)}${a.status !== 'ACTIVE' ? ' · ' + esc(a.status) : ''}</span></span><span class="pill ${esc(a.mode)}">${esc(t(a.mode))}</span></button>`).join('')}</div>`;
}

export function switchAccount(id) {
  if (!id || id === getAccount()) return;
  setAccount(id);
  try { localStorage.setItem('kaa.account', id); } catch {}
  paintAccount();
  paintTop();
  rerender(); // instant from cache when we have it
  refreshState(id).catch((e) => toast(e.message));
}

let pendingTimer = 0;
function schedulePending() {
  const st = S.state;
  if (!st || !st.designs_pending || !st.designs_pending.length) return;
  clearTimeout(pendingTimer);
  pendingTimer = setTimeout(() => {
    const run = () => renderPending(st.designs_pending, async () => { dropList('creatives'); await refreshState(); });
    (window.requestIdleCallback || setTimeout)(run);
  }, 400);
}

// ---------- events ----------
document.addEventListener('click', (e) => {
  if (!(e.target instanceof Element)) return;
  const ab = e.target.closest('#acct-btn');
  const menu = document.getElementById('acct-menu');
  if (ab) { menu.hidden = !menu.hidden; return; }
  const pick = e.target.closest('[data-acct]');
  if (pick) { menu.hidden = true; switchAccount(pick.dataset.acct); return; }
  if (menu && !e.target.closest('#acct')) menu.hidden = true;
});
document.getElementById('theme').onclick = () => {
  const r = document.documentElement;
  const cur = r.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  r.dataset.theme = cur === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem('kaa.theme', r.dataset.theme); } catch {}
};
document.getElementById('lang').onclick = () => { setLang(getLang() === 'ar' ? 'en' : 'ar'); paintNav(); paintAccount(); paintTop(); go(); };
document.getElementById('cycle').onclick = async () => {
  try { await api('/api/cycle', { method: 'POST', body: { mode: S.state?.autopilot.mode === 'auto' ? 'auto' : 'shadow' } }); toast(tt('Cycle started…', 'بدأت الدورة…')); } catch (e) { toast(e.message); }
};
document.getElementById('kill').onclick = async () => {
  const ok = await confirmBox(tt('Stop everything?', 'إيقاف كل شيء؟'), tt('Pauses every campaign this app made, in every account, and turns the autopilot off. Your own campaigns are not touched.', 'يوقف كل الحملات التي أنشأها التطبيق في كل الحسابات ويطفئ الطيار الآلي. حملاتك الخاصة لا تتأثر.'), t('Kill switch'), true);
  if (!ok) return;
  try { await api('/api/kill', { method: 'POST' }); toast(tt('Everything the app made is paused; autopilot is off.', 'تم إيقاف كل ما أنشأه التطبيق؛ الطيار الآلي متوقف.')); S.states.clear(); await refreshAccounts(); await refreshState(); } catch (e) { toast(e.message); }
};
document.addEventListener('keydown', (e) => {
  if ((e.target instanceof Element && e.target.closest('input, textarea, select')) || e.metaKey || e.ctrlKey || e.altKey) return;
  const n = parseInt(e.key, 10);
  if (n >= 1 && n <= ROUTES.length) { location.hash = '#/' + ROUTES[n - 1][0]; e.preventDefault(); }
  else if (e.key === 't' || e.key === 'T') document.getElementById('theme').click();
  else if (e.key === 'l' || e.key === 'L') document.getElementById('lang').click();
  else if (e.key === 'r' || e.key === 'R') document.getElementById('cycle').click();
  else if (e.key === 'a' || e.key === 'A') document.getElementById('acct-btn')?.click();
  else if (e.key === ']' || e.key === '[') {
    const i = S.accounts.findIndex((a) => a.id === getAccount());
    const nx = S.accounts[(i + (e.key === ']' ? 1 : S.accounts.length - 1)) % S.accounts.length];
    if (nx) switchAccount(nx.id);
  }
});
window.addEventListener('hashchange', go);

// ---------- start ----------
(async function start() {
  try { const th = localStorage.getItem('kaa.theme'); if (th) document.documentElement.dataset.theme = th; } catch {}
  // layout comes from theme.css (--kaa-layout): one read at start, never in a loop
  document.documentElement.dataset.layout = (getComputedStyle(document.documentElement).getPropertyValue('--kaa-layout') || 'sidebar').trim() || 'sidebar';
  paintNav();
  view.innerHTML = skeleton();
  const conn = document.getElementById('conn');
  try {
    S.info = await boot();
  } catch {
    conn.innerHTML = `<span class="dot bad"></span><span>${esc(tt('Engine not running', 'المحرك لا يعمل'))}</span>`;
    view.innerHTML = `<div class="page"><div class="card empty"><b>${esc(tt('The engine is not answering', 'المحرك لا يستجيب'))}</b>${esc(tt('Open "Start KBB Ads Autopilot" again; this page reconnects by itself.', 'افتحي «Start KBB Ads Autopilot» مرة أخرى؛ هذه الصفحة تتصل تلقائياً.'))}</div></div>`;
    setTimeout(start, 3000);
    return;
  }
  const [accounts, onboarding, connections] = await Promise.all([api('/api/accounts', { noAccount: true }), api('/api/onboarding', { noAccount: true }), api('/api/connections', { noAccount: true })]);
  S.accounts = accounts; S.onboarding = onboarding; S.conn = connections;
  let saved = ''; try { saved = localStorage.getItem('kaa.account') || ''; } catch {}
  setAccount(accounts.find((a) => a.id === saved)?.id || (accounts.find((a) => a.automate) || accounts[0] || {}).id);
  const okAll = connections.meta.ok && connections.claude.ok && connections.shop.ok;
  conn.innerHTML = `<span class="dot ${okAll ? 'ok' : 'warn'}"></span><span>${esc(okAll ? tt('Meta · Claude · Shop connected', 'ميتا · Claude · المتجر متصلة') : tt('Check connections', 'تحقّقي من الاتصالات'))}</span>`;
  paintAccount();
  if (!onboarding.done && !location.hash.startsWith('#/welcome')) location.hash = '#/welcome';
  await refreshState();
  go();
  // warm the other accounts so switching is instant
  for (const a of accounts) if (a.id !== getAccount()) refreshState(a.id).catch(() => {});
  events((type, d) => {
    if (type === 'disconnected') { conn.innerHTML = `<span class="dot bad"></span><span>${esc(tt('Reconnecting…', 'إعادة الاتصال…'))}</span>`; return; }
    if (type === 'hello' || type === 'heartbeat') { conn.innerHTML = `<span class="dot ok"></span><span>${esc(tt('Live', 'متصل الآن'))}</span>`; return; }
    if (type === 'alert') toast(d.text || 'Alert');
    if (type === 'cycle.start') toast(tt('Cycle running…', 'الدورة قيد التشغيل…'));
    if (type === 'metrics.updated' || type === 'cycle.end' || type === 'accounts.updated') {
      const ids = d.account ? [d.account] : [...S.states.keys()];
      for (const id of ids) { S.cache.forEach((_, k) => { if (k.startsWith(id + '|')) S.cache.delete(k); }); refreshState(id).catch(() => {}); }
      refreshAccounts().catch(() => {});
    }
  });
})();
