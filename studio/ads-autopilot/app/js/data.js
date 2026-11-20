// The app's one view of the engine (docs/ENGINE-API.md) + the brain's own
// routes (/api/brain/x/...). Everything is normalised here into the shape the
// screens use, so a renamed engine field is a one-line change in this file.
import { api } from './api.js';

const pct = (v) => (v == null ? 0 : v / 100); // engine CTR is a percentage
const k = (x = {}) => ({ spend: x.spend || 0, purchases: x.purchases || 0, revenue: x.revenue || 0, roas: x.roas || 0, cpa: x.cpa || 0, cpc: x.cpc || 0,
  ctr: pct(x.ctr), cpm: x.cpm || 0, frequency: x.frequency || 0, clicks: x.clicks || 0, impressions: x.impressions || 0 });
const langOf = (name) => (/[؀-ۿ]|(^|[\s·|:])AR([\s·|:]|$)|arabic/i.test(name || '') ? 'ar' : /(^|[\s·|:])EN([\s·|:]|$)|english/i.test(name || '') ? 'en' : '');

export function normAccounts(r) {
  const list = Array.isArray(r) ? r : (r.accounts || []);
  return {
    selected: r.selected || (list.find((a) => a.selected) || {}).id || '',
    rows: list.map((a) => ({ id: a.id, name: a.name, currency: a.currency, status: a.account_status === 1 || a.status === 'ACTIVE' ? 'ACTIVE' : 'DISABLED',
      automate: !!(a.automated ?? a.automate), mode: a.autopilot || a.mode || 'off', limits_set: !!a.limits_set, backfill: a.backfill || null, links: a.links || {}, link_checks: a.link_checks || {} })),
  };
}

export async function loadState(id) {
  const h = { headers: { 'X-KAA-Account': id } };
  const [st, cyc, ins, log] = await Promise.all([
    api('/api/state', h), api('/api/brain/x/cycle', h).catch(() => ({})), api('/api/brain/x/insights', h).catch(() => ({})), api('/api/log?limit=40', h).catch(() => ({ entries: [] })),
  ]);
  const verd = Object.fromEntries(((cyc && cyc.verdicts) || []).map((v) => [v.object, v]));
  const entries = (log.entries || []).slice().reverse();
  const lastAct = {};
  for (const e of entries.slice().reverse()) { const t = e.data && (e.data.target || e.data.object); if (t) lastAct[t] = { kind: e.kind, at: e.at, why: e.summary }; }
  const tree = (st.campaigns || []).map((c) => ({
    id: c.id, name: c.name, status: c.effective_status || c.status, objective: c.objective, daily_budget_aed: c.daily_budget || 0, managed: !!c.managed,
    metrics7: k(c.last7), verdict: verd[c.id] || null,
    adsets: (c.adsets || []).map((s) => ({ id: s.id, name: s.name, status: s.effective_status || s.status, daily_budget_aed: s.daily_budget || 0, lang: langOf(s.name), learning_stage: s.learning_stage || '',
      metrics7: k(s.last7), verdict: verd[s.id] || null,
      ads: (s.ads || []).map((a) => ({ id: a.id, name: a.name, status: a.status, effective_status: a.effective_status, lang: langOf(a.name) || langOf(s.name), format: a.format || '',
        creative_id: a.creative_id, metrics7: k(a.last7), verdict: verd[a.id] || null, last_action: lastAct[a.id] || null })) })),
  }));
  const live = tree.reduce((n, c) => n + c.adsets.reduce((m, s) => m + s.ads.filter((a) => a.status === 'ACTIVE').length, 0), 0);
  const e = (cyc && cyc.economics) || {};
  const plan = cyc && cyc.plan && cyc.plan.week ? cyc.plan : null;
  const L = st.limits || {};
  const mode = L.autopilot || 'off';
  const fat = ((cyc && cyc.verdicts) || []).filter((v) => v.verdict === 'fatigued').length;
  const kills = ((cyc && cyc.proposals) || []).filter((p) => p.kind === 'pause').length;
  return {
    raw: st, account: Object.assign({}, st.account || {}, { status: (st.account || {}).account_status === 1 ? 'ACTIVE' : 'DISABLED' }),
    autopilot: { mode, next_cycle_at: validT(st.next_cycle), last_cycle_at: validT(st.last_cycle), next_sync_at: validT(st.next_sync), running: !!st.cycle_running, model: (cyc && cyc.model) || '',
      doing: mode === 'off' ? '' : `Watching ${live} live ads${kills ? `; ${kills} to stop` : ''}${fat ? `; ${fat} tiring, fresh creative queued` : ''}.`,
      doing_ar: mode === 'off' ? '' : `يراقب ${live} إعلاناً نشطاً${kills ? `؛ ${kills} للإيقاف` : ''}${fat ? `؛ ${fat} مُرهَق مع إعلان بديل` : ''}.` },
    limits: Object.assign({}, L), limits_set: !!st.limits_set, month_spend: st.month_spend || 0, committed_daily: st.committed_daily || 0,
    economics: e, targets: { cpa: e.target_cpa, roas: e.target_roas, breakeven_cpa: e.breakeven_cpa, breakeven_roas: e.breakeven_roas },
    kpis: { today: k((st.kpis || {}).today), d7: k((st.kpis || {})['7d']), d30: k((st.kpis || {})['30d']) },
    series: (ins && ins.series) || [], split: (ins && ins.split) || { ar: {}, en: {} }, placements: [],
    tree, plan, engine_plan: st.plan || null, report: st.report ? { text: st.report.text, at: st.report.at || st.report.date } : null,
    proposals: (cyc && cyc.proposals) || [], dropped: (cyc && cyc.dropped) || [], learnings: (cyc && cyc.learnings) || [], profile: (cyc && cyc.profile) || null,
    alerts: (st.alerts || []).map((a) => ({ level: a.level === 'error' ? 'danger' : a.level, text: a.text, at: a.at })),
    activity: entries.slice(0, 14).map((x) => ({ at: x.at, kind: x.kind, text: x.summary })),
    designs_pending: (cyc && cyc.designs) || [], copy_sets: (cyc && cyc.copy_sets) || [], backfill: st.backfill || null, links: st.links || {},
    creatives_count: typeof st.creatives === 'number' ? st.creatives : 0, claude_usage: st.claude_usage || null, shop: st.shop || {},
  };
}
function validT(t) { return t && !String(t).startsWith('0001') ? t : ''; }

export const markDesignDone = (id) => api('/api/brain/x/design-done', { method: 'POST', body: { id } });
export const brainSettings = () => api('/api/brain/x/settings');
export const saveBrainSettings = (s) => api('/api/brain/x/settings', { method: 'PUT', body: s });
