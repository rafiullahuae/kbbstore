#!/usr/bin/env node
// LANE BRAIN fake engine: serves app/ and the CONTRACT.md endpoints with
// realistic fixture data so the UI can be built and checked without LANE
// ENGINE's server, Meta or Claude. NOT shipped; never talks to Meta or Claude.
//
//   node tools/brain-fake-engine.cjs [--port 41990]
//
// The verdicts, plan and report are the REAL brain's (rules-only): the fixture
// CycleInput is piped through engine/brain/cmd/brain-dry once at start.
// Product data: tools/fixtures/brain-products.json (12 items from the public
// feed kbeautybliss.com/feeds/meta-catalog.xml, 20 Nov 2026). Product photos
// are fetched once through /api/img (kbeautybliss.com only) and cached in
// the OS temp dir; offline they fall back to a drawn placeholder.
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { execFileSync, spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'app');
const argPort = process.argv.indexOf('--port');
const PORT = argPort > 0 ? +process.argv[argPort + 1] : +(process.env.PORT || 41990);
const TOKEN = 'fake-' + Math.random().toString(36).slice(2, 10);
const IMG_CACHE = path.join(os.tmpdir(), 'kaa-brain-img');
fs.mkdirSync(IMG_CACHE, { recursive: true });

// ---------- deterministic fixture ----------
let seed = 20261120;
const rnd = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff);
const DAY = 86400000;
const now = new Date(process.env.KAA_NOW || Date.now());
const dstr = (t) => new Date(t + 4 * 3600000).toISOString().slice(0, 10); // Asia/Dubai date
const today = dstr(now.getTime());
const products = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'brain-products.json'), 'utf8'));
const P = Object.fromEntries(products.map((p) => [p.id, p]));

const creatives = [];
function addCreative(c) { creatives.push(Object.assign({ format: '1:1', cta: 'SHOP_NOW', source: 'brain', lint_ok: true, created_at: new Date(now - 20 * DAY).toISOString() }, c)); }
const pairs = [
  { pair: 'p-cleanse', pid: ['3195'], angle: 'gentle morning routine', template: 'routine',
    ar: { h: 'صباحكِ يبدأ بلطف', t: 'غسول جل بدرجة حموضة منخفضة من COSRX، لطيف على البشرة الحساسة ويمنحها إحساساً بالانتعاش كل صباح.', sub: 'خطوة الصباح الأولى', b: ['درجة حموضة منخفضة', 'زيت شجرة الشاي', 'صنع في كوريا'] },
    en: { h: 'The gentlest morning start', t: 'COSRX Low pH Good Morning Gel Cleanser: a fresh, gentle cleanse made for sensitive and blemish-prone skin.', sub: 'Step one, every morning', b: ['Low pH', 'Tea tree + BHA', 'Made in Korea'] } },
  { pair: 'p-glow', pid: ['3206'], angle: 'glass-skin glow', template: 'glow',
    ar: { h: 'توهّج على الطريقة الكورية', t: 'سيروم Beauty of Joseon بالبروبوليس والنياسيناميد لإطلالة ندية ومشرقة تدوم طوال اليوم.', sub: 'إشراقة البشرة الزجاجية', b: ['بروبوليس', 'نياسيناميد', 'قوام خفيف'] },
    en: { h: 'Glow, the K-beauty way', t: 'Beauty of Joseon Glow Serum with propolis and niacinamide for a dewy, radiant look that lasts all day.', sub: 'The glass-skin glow', b: ['Propolis', 'Niacinamide', 'Lightweight'] } },
  { pair: 'p-heartleaf', pid: ['3214'], angle: 'calming heartleaf ingredient', template: 'ingredient',
    ar: { h: 'هارتليف ٧٧٪ للتهدئة', t: 'تونر Anua بخلاصة الهارتليف، يمنح البشرة إحساساً بالهدوء والترطيب من أول لمسة.', sub: 'مكوّن واحد، فرق واضح', b: ['هارتليف ٧٧٪', 'ترطيب خفيف', 'للاستخدام اليومي'] },
    en: { h: 'Heartleaf 77% calm', t: 'Anua Heartleaf 77% Soothing Toner: a calming, hydrating step your whole routine will love.', sub: 'One ingredient, all the calm', b: ['Heartleaf 77%', 'Light hydration', 'Daily use'] } },
  { pair: 'p-sun', pid: ['3219'], angle: 'summer-proof lightweight SPF texture', template: 'clean',
    ar: { h: 'واقي شمس بقوام مائي', t: 'جل Isntree المائي بحمض الهيالورونيك، خفيف كالماء ولا يترك أثراً أبيض تحت شمس الإمارات.', sub: 'خفيف كالماء', b: ['قوام مائي', 'هيالورونيك', 'بلا أثر أبيض'] },
    en: { h: 'Sunscreen that feels like water', t: 'Isntree Hyaluronic Acid Watery Sun Gel: weightless, no white cast, made for UAE sunshine.', sub: 'Weightless daily SPF', b: ['Watery gel', 'Hyaluronic acid', 'No white cast'] } },
];
for (const p of pairs) for (const lang of ['ar', 'en']) {
  const x = p[lang];
  addCreative({ id: `${p.pair}-${lang}`, pair_id: p.pair, lang, headline: x.h, primary_text: x.t, description: lang === 'ar' ? 'جمال كوري أصلي' : 'Authentic K-beauty',
    product_ids: p.pid, angle: p.angle, template: p.template, link: P[p.pid[0]].url,
    design: { id: `d-${p.pair}-${lang}`, pair_id: p.pair, lang, template: p.template, formats: ['1:1', '4:5', '9:16'], product_ids: p.pid,
      images: p.pid.map((i) => P[i].image), headline: x.h, sub: x.sub, bullets: x.b, badge: P[p.pid[0]].brand, cta: 'SHOP_NOW',
      price: lang === 'ar' ? `${P[p.pid[0]].price} درهم` : `AED ${P[p.pid[0]].price}`, angle: p.angle } });
}

// campaign tree: one Advantage+ sales campaign, Arabic and English ad sets
const tree = [{ id: '120210000000001', name: 'KBB · Sales · Advantage+', status: 'ACTIVE', objective: 'OUTCOME_SALES', daily_budget_aed: 0, made_by_app: true,
  created_at: new Date(now - 40 * DAY).toISOString(), adsets: [
    { id: '120210000000011', campaign_id: '120210000000001', name: 'AR · GCC women · Broad', status: 'ACTIVE', daily_budget_aed: 180, lang: 'ar', countries: ['AE', 'SA', 'KW'],
      learning_stage: 'SUCCESS', last_significant_edit: new Date(now - 18 * DAY).toISOString(), created_at: new Date(now - 40 * DAY).toISOString(), ads: [] },
    { id: '120210000000012', campaign_id: '120210000000001', name: 'EN · UAE · Broad', status: 'ACTIVE', daily_budget_aed: 70, lang: 'en', countries: ['AE'],
      learning_stage: 'LEARNING', last_significant_edit: new Date(now - 4 * DAY).toISOString(), created_at: new Date(now - 40 * DAY).toISOString(), ads: [] },
  ] }];
// per-ad behaviour: [creative, adset index, share, ctr, cvr, cpm, aov, fatigueFromDay, ageDays]
const adDefs = [
  ['p-cleanse-ar', 0, 0.30, 0.018, 0.075, 22, 168, 0, 34],   // winner
  ['p-glow-ar', 0, 0.28, 0.015, 0.050, 24, 190, 0, 34],      // ok
  ['p-heartleaf-ar', 0, 0.22, 0.017, 0.055, 23, 150, 16, 30], // fatigues
  ['p-sun-ar', 0, 0.20, 0.0035, 0.0, 26, 140, 0, 12],         // weak hook, no sales
  ['p-cleanse-en', 1, 0.45, 0.013, 0.045, 25, 160, 0, 30],
  ['p-glow-en', 1, 0.35, 0.011, 0.022, 27, 180, 0, 30],       // loser
  ['p-sun-en', 1, 0.20, 0.010, 0.030, 26, 150, 0, 5],
];
const metrics = [];
let adN = 100;
for (const [cid, si, share, ctr, cvr, cpm, aov, fat, age] of adDefs) {
  const set = tree[0].adsets[si];
  const id = '1202100000' + adN++;
  const c = creatives.find((x) => x.id === cid);
  set.ads.push({ id, adset_id: set.id, name: `${c.lang.toUpperCase()} · ${c.angle} · ${P[c.product_ids[0]].brand}`, status: 'ACTIVE', effective_status: 'ACTIVE',
    creative_id: cid, lang: c.lang, review_feedback: '', created_at: new Date(now - age * DAY).toISOString() });
  for (let k = Math.min(age, 35); k >= 0; k--) {
    const t = now.getTime() - k * DAY;
    const dayIdx = age - k;
    const part = k === 0 ? 0.45 : 1;
    let spend = set.daily_budget_aed * share * (0.85 + rnd() * 0.3) * part;
    let ctrD = ctr * (0.85 + rnd() * 0.3);
    let freq = 1.1 + dayIdx * 0.03;
    if (fat && dayIdx > fat) { ctrD *= Math.max(0.45, 1 - (dayIdx - fat) * 0.06); freq = 2.2 + (dayIdx - fat) * 0.12; }
    const cpmD = cpm * (1 + (fat && dayIdx > fat ? (dayIdx - fat) * 0.03 : 0)) * (0.9 + rnd() * 0.2);
    const impr = Math.round(spend / cpmD * 1000);
    const clicks = Math.round(impr * ctrD);
    let purch = 0;
    const exp = clicks * cvr * (fat && dayIdx > fat ? 0.6 : 1);
    purch = Math.floor(exp) + (rnd() < exp - Math.floor(exp) ? 1 : 0);
    if (k <= 1) purch = Math.round(purch * 0.7); // settling: not all attributed yet
    metrics.push({ date: dstr(t), level: 'ad', object_id: id, spend: +spend.toFixed(2), impressions: impr, reach: Math.round(impr / Math.max(freq, 1)),
      clicks, purchases: purch, revenue: +(purch * aov * (0.9 + rnd() * 0.2)).toFixed(2), frequency: +freq.toFixed(2), settling: k <= 2 });
  }
}
function rollup(level, idOf) {
  const m = {};
  for (const r of metrics.filter((r) => r.level === 'ad')) {
    const id = idOf(r.object_id); if (!id) continue;
    const k = id + '|' + r.date;
    const a = (m[k] = m[k] || { date: r.date, level, object_id: id, spend: 0, impressions: 0, reach: 0, clicks: 0, purchases: 0, revenue: 0, frequency: 0, settling: r.settling });
    a.spend += r.spend; a.impressions += r.impressions; a.reach += r.reach; a.clicks += r.clicks; a.purchases += r.purchases; a.revenue += r.revenue; a.frequency = Math.max(a.frequency, r.frequency);
  }
  return Object.values(m);
}
const adParent = {};
for (const s of tree[0].adsets) for (const a of s.ads) adParent[a.id] = s.id;
metrics.push(...rollup('adset', (id) => adParent[id]), ...rollup('campaign', () => tree[0].id));

let limits = { daily_aed: 300, monthly_aed: 9000, max_budget_step_pct: 20, cooldown_hours: 72, max_active_ads: 10, countries: ['AE', 'SA', 'KW'], autopilot: 'shadow' };
const research = { updated_at: new Date(now - 2 * DAY).toISOString(), notes: [
  { id: 'n1', topic: 'season', title: 'UAE peak calendar: White Friday, National Day, DSF, Ramadan 2027', title_ar: 'مواسم الإمارات: الجمعة البيضاء، عيد الاتحاد، مهرجان دبي للتسوق، رمضان ٢٠٢٧',
    body: 'Ramadan 2027 is expected to start about 8 Feb (moon sighting), Eid al-Fitr about 9 Mar. CPMs rise in DSF (mid-Dec to mid-Jan) and sharply in Ramadan and Eid, notably for beauty.',
    body_ar: 'يُتوقع أن يبدأ رمضان ٢٠٢٧ في حدود ٨ فبراير وعيد الفطر في حدود ٩ مارس. ترتفع تكلفة الظهور خلال مهرجان دبي للتسوق وبشكل أكبر في رمضان والعيد، خصوصاً لمنتجات الجمال.',
    angles: ['Ramadan night-time routine', 'Eid glow gifting'], strength: 0.8, date: new Date(now - 2 * DAY).toISOString(),
    sources: [{ url: 'https://www.khaleejtimes.com/uae/ramadan-eid-al-fitr-eid-al-adha-2027-likely-dates', title: 'Khaleej Times: Ramadan, Eid 2027 likely dates' },
      { url: 'https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/united-arab-emirates', title: 'SuperAds: UAE Facebook CPM' }] },
  { id: 'n2', topic: 'format', title: 'Creative diversity is now the main lever on Meta', title_ar: 'تنوّع الإعلانات هو الرافعة الأهم على ميتا الآن',
    body: 'Meta calls creative diversification a foundational best practice: genuinely different concepts (persona, message, format), not near-duplicates.',
    body_ar: 'تعتبر ميتا تنويع الإعلانات ممارسة أساسية: أفكار مختلفة فعلاً في الشخصية والرسالة والشكل، لا نسخاً متقاربة.',
    angles: ['texture close-up reel', 'routine in three steps', 'ingredient spotlight'], strength: 0.8, date: new Date(now - 2 * DAY).toISOString(),
    sources: [{ url: 'https://www.jonloomer.com/meta-andromeda/', title: 'Jon Loomer: Meta Andromeda' }, { url: 'https://ppc.land/andromeda/', title: 'PPC Land: Andromeda' }] },
  { id: 'n3', topic: 'arabic_audience', title: 'Ads in the UAE should be in Arabic (MSA or Emirati dialect)', title_ar: 'الإعلانات في الإمارات يجب أن تكون بالعربية (الفصحى أو اللهجة الإماراتية)',
    body: 'The advertising standards ask for Modern Standard Arabic or Emirati dialect; health or therapeutic claims need prior approval.',
    body_ar: 'تطلب معايير الإعلان استخدام العربية الفصحى أو اللهجة الإماراتية، وتحتاج الادعاءات الصحية أو العلاجية إلى موافقة مسبقة.',
    angles: ['Arabic-first hooks', 'benefit-led wording'], strength: 0.6, date: new Date(now - 9 * DAY).toISOString(),
    sources: [{ url: 'https://www.tamimi.com/law-update/february-4/articles/advertising-standards-for-the-uae/', title: 'Al Tamimi: Advertising standards for the UAE' }] },
] };
const log = [];
function addLog(kind, object, summary, outcome, t) { log.push({ id: 'L' + (log.length + 1), time: new Date(t || Date.now()).toISOString(), kind, object, summary, outcome }); }
addLog('execution', tree[0].adsets[1].id, 'New ad "EN · summer-proof lightweight SPF texture" added to EN · UAE · Broad (paused, then activated).', 'executed', now - 4 * DAY);
addLog('execution', tree[0].adsets[0].id, 'AR · GCC women · Broad budget AED 150 → 180 (winner, +20%).', 'executed', now - 6 * DAY);
addLog('brain.learning', '', 'Arabic routine hooks out-click ingredient hooks 1.3x at similar CPM.', 'learning', now - 6 * DAY);

// ---------- run the real brain (rules-only) on the fixture ----------
function cycleInput() {
  return { now: now.toISOString(), limits, account: { id: 'act_1234567890', name: 'K-Beauty Bliss', currency: 'AED', timezone: 'Asia/Dubai', spend_cap: 9000 },
    tree, metrics, shop: { products, orders: [], orders_source: 'none', fetched_at: now.toISOString() }, creatives, research, history: log };
}
let brainOut = null;
function runBrain() {
  const t0 = Date.now();
  const r = spawnSync('go', ['run', './cmd/brain-dry'], { cwd: path.join(ROOT, 'engine', 'brain'), input: JSON.stringify(cycleInput()), encoding: 'utf8', maxBuffer: 64 << 20 });
  if (r.status !== 0) { console.error('brain-dry failed:', r.stderr); return; }
  brainOut = JSON.parse(r.stdout);
  console.log(`brain-dry: ${brainOut.verdicts.length} verdicts, ${brainOut.proposals.length} proposals in ${Date.now() - t0} ms`);
}
runBrain();
let pending = [];
// two designs "the last cycle asked for": the UI renders and POSTs them
pending.push(Object.assign({}, creatives.find((c) => c.id === 'p-glow-ar').design, { id: 'd-new-glow-ar', pair_id: 'p-new-glow', template: 'offer', headline: 'هدية التوهّج لموسم الأعياد', sub: 'جاهزة للإهداء' }));
pending.push(Object.assign({}, creatives.find((c) => c.id === 'p-glow-en').design, { id: 'd-new-glow-en', pair_id: 'p-new-glow', template: 'offer', headline: 'The glow gift of the season', sub: 'Ready to gift' }));
if (brainOut) for (const p of brainOut.proposals) addLog('proposal', p.target, `${p.kind} ${p.target}: ${p.why}`, limits.autopilot === 'auto' ? 'executed' : 'shadow', now - 3600000);

// ---------- derived state ----------
function agg(rows) {
  const a = rows.reduce((a, r) => { a.spend += r.spend; a.impressions += r.impressions; a.clicks += r.clicks; a.purchases += r.purchases; a.revenue += r.revenue; a.frequency = Math.max(a.frequency, r.frequency); return a; },
    { spend: 0, impressions: 0, clicks: 0, purchases: 0, revenue: 0, frequency: 0 });
  a.roas = a.spend ? a.revenue / a.spend : 0; a.cpa = a.purchases ? a.spend / a.purchases : 0; a.cpc = a.clicks ? a.spend / a.clicks : 0;
  a.ctr = a.impressions ? a.clicks / a.impressions : 0; a.cpm = a.impressions ? a.spend / a.impressions * 1000 : 0;
  for (const k of Object.keys(a)) a[k] = +a[k].toFixed(4);
  return a;
}
const since = (n) => dstr(now.getTime() - n * DAY);
function state() {
  const camp = metrics.filter((r) => r.level === 'campaign');
  const v = Object.fromEntries((brainOut ? brainOut.verdicts : []).map((x) => [x.object, x]));
  const lastAct = {};
  for (const l of log) if (l.object) lastAct[l.object] = { kind: l.kind, at: l.time, why: l.summary };
  const m7 = (id) => agg(metrics.filter((r) => r.object_id === id && r.date >= since(7)));
  const t = JSON.parse(JSON.stringify(tree));
  for (const c of t) { c.metrics7 = m7(c.id); c.verdict = v[c.id] || null;
    for (const s of c.adsets) { s.metrics7 = m7(s.id); s.verdict = v[s.id] || null;
      for (const a of s.ads) { a.metrics7 = m7(a.id); a.verdict = v[a.id] || null; a.last_action = lastAct[a.id] || null; } } }
  const byLang = (lang) => agg(metrics.filter((r) => r.level === 'ad' && r.date >= since(30) && t[0].adsets.some((s) => s.ads.some((a) => a.id === r.object_id && a.lang === lang))));
  const days = [...new Set(camp.map((r) => r.date))].sort().slice(-30);
  const e = brainOut ? brainOut.economics : {};
  const nextCycle = new Date(Math.ceil(Date.now() / (4 * 3600000)) * 4 * 3600000);
  return {
    now: new Date().toISOString(), app: 'KBB Ads Autopilot', fixture: true,
    autopilot: { mode: limits.autopilot, next_cycle_at: nextCycle.toISOString(), last_cycle_at: new Date(now - 3600000).toISOString(), running: false, model: brainOut ? brainOut.model : '' },
    account: cycleInput().account, limits, limits_set: limits.daily_aed > 0 && limits.monthly_aed > 0, economics: e,
    kpis: { today: agg(camp.filter((r) => r.date === today)), d7: agg(camp.filter((r) => r.date >= since(7))), d30: agg(camp.filter((r) => r.date >= since(30))) },
    targets: { cpa: e.target_cpa, roas: e.target_roas, breakeven_cpa: e.breakeven_cpa, breakeven_roas: e.breakeven_roas, ctr: 0.012, cpc: 2.5 },
    series: days.map((d) => { const a = agg(camp.filter((r) => r.date === d)); return { date: d, spend: a.spend, revenue: a.revenue, purchases: a.purchases }; }),
    split: { ar: byLang('ar'), en: byLang('en') },
    tree: t, plan: brainOut ? brainOut.plan : null, report: brainOut ? { text: brainOut.report, at: new Date(now - 3600000).toISOString() } : null,
    proposals: brainOut ? brainOut.proposals : [], learnings: log.filter((l) => l.kind === 'brain.learning').map((l) => l.summary),
    alerts: [
      ...(limits.autopilot !== 'auto' ? [{ level: 'info', text: `Autopilot is in ${limits.autopilot} mode: proposals are logged, nothing changes on Meta.`, at: new Date().toISOString() }] : []),
      ...(brainOut ? brainOut.verdicts.filter((x) => x.verdict === 'fatigued').map((x) => ({ level: 'warn', text: `Ad ${x.object} shows creative fatigue (${(x.signals || []).join(', ')}).`, at: new Date().toISOString() })) : []),
      { level: 'info', text: 'Orders and margins are not connected yet (shop endpoint pending): break-even uses feed prices and default margin.', at: new Date().toISOString() },
    ],
    activity: log.filter((l) => Date.parse(l.time) > Date.now() - 2 * DAY).slice(-12).reverse().map((l) => ({ at: l.time, kind: l.kind, text: l.summary, outcome: l.outcome })),
    designs_pending: pending, creatives_count: creatives.length, research_updated_at: research.updated_at,
  };
}
let connections = {
  meta: { ok: true, account: { id: 'act_1234567890', name: 'K-Beauty Bliss', currency: 'AED', timezone: 'Asia/Dubai', spend_cap: 9000 }, error: '', token: { set: true, last4: 'x9Qa' }, pixel_id: '1234567890123', catalog_id: '987654321' },
  claude: { ok: true, model: 'claude-sonnet-5-5', error: '', key: { set: true, last4: '7f2c' } },
  shop: { ok: true, products: 902, orders_source: 'none', error: '', url: 'https://kbeautybliss.com' },
};

// ---------- HTTP ----------
const sse = new Set();
function emit(type, data) { const s = `event: ${type}\ndata: ${JSON.stringify(data || {})}\n\n`; for (const r of sse) r.write(s); }
setInterval(() => emit('heartbeat', { at: new Date().toISOString() }), 15000).unref();
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json', '.txt': 'text/plain; charset=utf-8' };
function json(res, code, v) { res.writeHead(code, { 'content-type': 'application/json', 'cache-control': 'no-store' }); res.end(JSON.stringify(v)); }
function body(req) { return new Promise((ok) => { let b = ''; req.on('data', (c) => { b += c; if (b.length > 40e6) req.destroy(); }); req.on('end', () => { try { ok(b ? JSON.parse(b) : {}); } catch { ok(null); } }); }); }

function placeholder(res) {
  res.writeHead(200, { 'content-type': 'image/svg+xml' });
  res.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="#F6F2F3"/><rect x="220" y="120" width="160" height="360" rx="30" fill="#E0567B" opacity=".85"/><rect x="245" y="80" width="110" height="60" rx="12" fill="#2A2228"/></svg>');
}
function serveImg(res, u) {
  let url;
  try { url = new URL(u); } catch { return placeholder(res); }
  if (url.protocol !== 'https:' || !/(^|\.)kbeautybliss\.com$/.test(url.hostname)) return json(res, 400, { error: 'only kbeautybliss.com images' });
  const f = path.join(IMG_CACHE, Buffer.from(u).toString('base64url').slice(-80));
  if (!fs.existsSync(f)) {
    const r = spawnSync('curl', ['-sfL', '--max-time', '20', '-o', f, u]);
    if (r.status !== 0 || !fs.existsSync(f) || fs.statSync(f).size < 500) { try { fs.unlinkSync(f); } catch {} return placeholder(res); }
  }
  res.writeHead(200, { 'content-type': /\.webp$/i.test(url.pathname) ? 'image/webp' : /\.png$/i.test(url.pathname) ? 'image/png' : 'image/jpeg', 'cache-control': 'max-age=86400' });
  fs.createReadStream(f).pipe(res);
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const p = url.pathname;
  if (!p.startsWith('/api/')) {
    let f = path.normalize(path.join(APP, p === '/' ? 'index.html' : p));
    if (!f.startsWith(APP) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('not found'); }
    res.writeHead(200, { 'content-type': MIME[path.extname(f)] || 'application/octet-stream', 'cache-control': 'no-cache' });
    return fs.createReadStream(f).pipe(res);
  }
  if (p === '/api/info') return json(res, 200, { app: 'KBB Ads Autopilot', version: '0.1.0-fake', apiLevel: 1, token: TOKEN, dataDir: '~/KBB Ads Autopilot Data' });
  const tok = req.headers['x-kaa'] || url.searchParams.get('t');
  if (tok !== TOKEN) return json(res, 401, { error: 'missing X-KAA token' });
  if (p === '/api/img') return serveImg(res, url.searchParams.get('u') || '');
  if (p === '/api/events') {
    res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store', connection: 'keep-alive' });
    res.write('event: hello\ndata: {}\n\n'); sse.add(res); req.on('close', () => sse.delete(res)); return;
  }
  if (p === '/api/state' && req.method === 'GET') return json(res, 200, state());
  if (p === '/api/connections' && req.method === 'GET') return json(res, 200, connections);
  const cm = p.match(/^\/api\/connections\/(meta|claude|shop)$/);
  if (cm && req.method === 'PUT') {
    const b = (await body(req)) || {}; const k = cm[1]; const c = connections[k];
    // never echo secrets back: keep {set,last4} only
    if (k === 'meta') { if (b.token) c.token = { set: true, last4: String(b.token).slice(-4) }; Object.assign(c, { pixel_id: b.pixel_id || c.pixel_id, catalog_id: b.catalog_id || c.catalog_id }); c.ok = !b.token || /^EAA/.test(b.token); c.error = c.ok ? '' : 'Meta said: Invalid OAuth access token (code 190)'; }
    if (k === 'claude') { if (b.key) c.key = { set: true, last4: String(b.key).slice(-4) }; c.ok = !b.key || /^sk-ant-/.test(b.key); c.error = c.ok ? '' : 'Claude said: invalid x-api-key (401)'; }
    if (k === 'shop') { c.url = b.url || c.url; c.ok = true; }
    return json(res, 200, connections);
  }
  if (p === '/api/limits') {
    if (req.method === 'GET') return json(res, 200, limits);
    const b = await body(req);
    if (!b) return json(res, 400, { error: 'bad JSON' });
    const n = Object.assign({}, limits, b);
    if (n.autopilot !== 'off' && !(n.daily_aed > 0 && n.monthly_aed > 0)) return json(res, 400, { error: 'Set a daily and a monthly limit before turning the autopilot on.' });
    if (n.daily_aed * 28 > n.monthly_aed * 1.5 && n.monthly_aed > 0 && n.daily_aed > n.monthly_aed) return json(res, 400, { error: 'Daily limit is above the monthly limit.' });
    limits = n; addLog('settings', '', `Limits saved: AED ${n.daily_aed}/day, AED ${n.monthly_aed}/month, autopilot ${n.autopilot}.`, 'saved');
    emit('metrics.updated', {}); return json(res, 200, limits);
  }
  if (p === '/api/cycle' && req.method === 'POST') {
    emit('cycle.start', { at: new Date().toISOString() });
    setTimeout(() => { runBrain(); addLog('cycle', '', `Cycle finished (${limits.autopilot}): ${brainOut ? brainOut.proposals.length : 0} proposals.`, limits.autopilot); emit('cycle.end', {}); emit('metrics.updated', {}); }, 300);
    return json(res, 202, { started: true });
  }
  if (p === '/api/kill' && req.method === 'POST') {
    limits.autopilot = 'off';
    for (const c of tree) if (c.made_by_app) { c.status = 'PAUSED'; addLog('execution', c.id, `KILL SWITCH: campaign ${c.name} paused, autopilot off.`, 'executed'); }
    emit('alert', { level: 'danger', text: 'Kill switch: everything the app made is paused; autopilot is off.' }); emit('metrics.updated', {});
    return json(res, 200, { ok: true });
  }
  if (p === '/api/log') {
    const after = url.searchParams.get('after');
    let rows = log; if (after) rows = rows.filter((l) => l.id > after || l.time > after);
    return json(res, 200, rows.slice().reverse());
  }
  if (p === '/api/research') return json(res, 200, research);
  if (p === '/api/creatives') {
    if (req.method === 'GET') return json(res, 200, creatives.map((c) => Object.assign({}, c, { png: undefined })).map((c) => {
      const ids = new Set(); for (const s of tree[0].adsets) for (const a of s.ads) if (a.creative_id === c.id) ids.add(a.id);
      c.results = agg(metrics.filter((r) => ids.has(r.object_id))); c.ads = [...ids]; return c; }));
    const b = await body(req);
    if (!b || !b.lang || !b.format || !(b.png || b.video)) return json(res, 400, { error: 'need lang, format, png' });
    const buf = Buffer.from(String(b.png || '').replace(/^data:image\/png;base64,/, ''), 'base64');
    if (b.png && buf.slice(1, 4).toString() !== 'PNG') return json(res, 400, { error: 'not a PNG' });
    const id = b.id || `cr-${Date.now().toString(36)}-${creatives.length}`;
    const out = path.join(os.tmpdir(), 'kaa-brain-creatives'); fs.mkdirSync(out, { recursive: true });
    fs.writeFileSync(path.join(out, `${id}-${b.format.replace(':', 'x')}.png`), buf);
    creatives.push({ id, pair_id: b.pair_id || '', lang: b.lang, format: b.format, headline: b.headline || '', primary_text: b.primary_text || '', cta: b.cta || 'SHOP_NOW',
      link: b.link || '', product_ids: b.product_ids || [], angle: b.angle || '', template: b.template || '', source: b.source || 'brain', design: b.design || null,
      image_url: `/api/creatives/${id}.png`, bytes: buf.length, created_at: new Date().toISOString(), lint_ok: true });
    if (b.design_id) pending = pending.filter((d) => d.id !== b.design_id || b.format !== (d.formats || []).slice(-1)[0]);
    addLog('creative', id, `Creative rendered: ${b.lang.toUpperCase()} ${b.format} "${b.headline || ''}" (${Math.round(buf.length / 1024)} KB).`, 'saved');
    emit('metrics.updated', {});
    return json(res, 201, { id, bytes: buf.length });
  }
  const bm = p.match(/^\/api\/brain\/(plan|research|copy)$/);
  if (bm && req.method === 'POST') {
    const b = (await body(req)) || {};
    if (bm[1] === 'copy') { // fixture copy (no Claude here): shows the flow end to end
      const pr = P[(b.product_ids || [])[0]] || products[0];
      const pair = 'p-gen-' + Date.now().toString(36);
      const ttl = pr.title.split(' - ').slice(1).join(' - ') || pr.title;
      const sets = [
        { lang: 'ar', headline: `${pr.brand}: لمسة كورية يومية`, sub: 'روتينك اليومي ببساطة', bullets: ['جمال كوري أصلي', 'قوام خفيف', 'للاستخدام اليومي'], primary_text: `${ttl} من ${pr.brand}، خطوة بسيطة تمنح بشرتكِ إحساساً بالانتعاش كل يوم.` },
        { lang: 'en', headline: `${pr.brand}, your daily K-beauty step`, sub: 'Simple, every day', bullets: ['Authentic K-beauty', 'Light texture', 'Daily use'], primary_text: `${ttl} by ${pr.brand}: a simple step for skin that feels fresh every day.` },
      ];
      const designs = sets.map((s) => ({ id: `d-${pair}-${s.lang}`, pair_id: pair, lang: s.lang, template: b.template || 'clean', formats: ['1:1', '4:5', '9:16'], product_ids: [pr.id], images: [pr.image],
        headline: s.headline, sub: s.sub, bullets: s.bullets, badge: pr.brand, cta: 'SHOP_NOW', price: s.lang === 'ar' ? `${pr.price} درهم` : `AED ${pr.price}`, angle: b.angle || 'daily routine', copy_id: `${pair}-${s.lang}`, primary_text: s.primary_text }));
      pending.push(...designs);
      return json(res, 200, { copy_sets: sets.map((s) => Object.assign({ id: `${pair}-${s.lang}`, pair_id: pair, cta: 'SHOP_NOW', lint: { ok: true } }, s)), designs });
    }
    if (bm[1] === 'plan') { runBrain(); return json(res, 200, brainOut); }
    return json(res, 200, research);
  }
  const ci = p.match(/^\/api\/creatives\/([\w-]+)\.png$/);
  if (ci) { const dir = path.join(os.tmpdir(), 'kaa-brain-creatives'); const f = fs.existsSync(dir) && fs.readdirSync(dir).find((x) => x.startsWith(ci[1] + '-')); if (!f) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': 'image/png' }); return fs.createReadStream(path.join(dir, f)).pipe(res); }
  json(res, 404, { error: 'no such endpoint' });
});
server.listen(PORT, '127.0.0.1', () => console.log(`fake engine on http://127.0.0.1:${PORT}/ (token ${TOKEN})`));
