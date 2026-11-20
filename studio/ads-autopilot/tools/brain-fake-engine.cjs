#!/usr/bin/env node
// LANE BRAIN fake engine: serves app/ (at /app/, with the engine's CSP) and the
// REAL engine API shapes (docs/ENGINE-API.md) + the brain's /api/brain/x/ routes
// with realistic multi-account fixture data,
// so the UI can be built and checked without LANE ENGINE, Meta or Claude.
// NOT shipped. Never talks to Meta or Claude.
//
//   node tools/brain-fake-engine.cjs [--port 41990]
//
// Verdicts, plans, reports, learning profiles and video analyses are the REAL
// brain's (rules-only / local-only): fixtures are piped through
// engine/brain/cmd/brain-dry. Products: tools/fixtures/brain-products.json (12
// items from the public feed kbeautybliss.com/feeds/meta-catalog.xml, 20 Nov
// 2026). Photos are fetched once via /api/img (kbeautybliss.com only) into the
// OS temp dir; offline they fall back to a drawn placeholder.
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawnSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const APP = path.join(ROOT, 'app');
const argPort = process.argv.indexOf('--port');
const PORT = argPort > 0 ? +process.argv[argPort + 1] : +(process.env.PORT || 41990);
const TOKEN = 'fake-' + Math.random().toString(36).slice(2, 10);
const TMP = path.join(os.tmpdir(), 'kaa-brain-' + PORT);
for (const d of ['img', 'creatives', 'videos']) fs.mkdirSync(path.join(TMP, d), { recursive: true });
process.on('exit', () => { try { fs.rmSync(TMP, { recursive: true, force: true }); } catch {} });
for (const s of ['SIGINT', 'SIGTERM']) process.on(s, () => process.exit(0));

// ---------- deterministic fixtures ----------
let seed = 20261120;
const rnd = () => ((seed = (seed * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff);
const DAY = 86400000;
const now = new Date(process.env.KAA_NOW || Date.now());
const dstr = (t) => new Date(t + 4 * 3600000).toISOString().slice(0, 10); // Asia/Dubai
const today = dstr(now.getTime());
const iso = (t) => new Date(t).toISOString();
const products = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'brain-products.json'), 'utf8'));
const P = Object.fromEntries(products.map((p) => [p.id, p]));

const PAIRS = [
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
  { pair: 'p-routine', pid: ['3199', '3214', '3254'], angle: 'three-step routine carousel', template: 'routine',
    ar: { h: 'روتين من ثلاث خطوات', t: 'غسول، تونر، كريم مائي: روتين كوري بسيط للبشرة المعرّضة للحبوب.', sub: 'روتين بسيط وفعّال', b: ['غسول لطيف', 'تونر مهدّئ', 'كريم مائي'] },
    en: { h: 'Your 3-step K-beauty routine', t: 'Cleanser, toner, gel cream: a simple Korean routine for blemish-prone skin.', sub: 'Simple routine', b: ['Gentle cleanse', 'Calming toner', 'Gel cream'] } },
];
function creativesFor() {
  const out = [];
  for (const p of PAIRS) for (const lang of ['ar', 'en']) {
    const x = p[lang];
    out.push({ id: `${p.pair}-${lang}`, pair_id: p.pair, lang, format: '1:1', cta: 'SHOP_NOW', source: 'brain', lint_ok: true, created_at: iso(now - 30 * DAY),
      headline: x.h, primary_text: x.t, description: lang === 'ar' ? 'جمال كوري أصلي' : 'Authentic K-beauty', product_ids: p.pid, angle: p.angle, template: p.template, link: P[p.pid[0]].url,
      design: { id: `d-${p.pair}-${lang}`, pair_id: p.pair, lang, template: p.template, formats: ['1:1', '4:5', '9:16'], product_ids: p.pid, images: p.pid.map((i) => P[i].image),
        headline: x.h, sub: x.sub, bullets: x.b, badge: P[p.pid[0]].brand, cta: 'SHOP_NOW', price: lang === 'ar' ? `${P[p.pid[0]].price} درهم` : `AED ${P[p.pid[0]].price}`, angle: p.angle } });
  }
  return out;
}

// [creative, adset index, share, ctr, cvr, cpm, aov, fatigueFromDay, ageDays, format, status]
const ACCOUNTS = [
  { id: 'act_1234567890', name: 'K-Beauty Bliss · UAE', currency: 'AED', status: 'ACTIVE', automate: true,
    limits: { daily_aed: 300, monthly_aed: 9000, max_budget_step_pct: 20, cooldown_hours: 72, max_active_ads: 10, countries: ['AE', 'SA', 'KW'], autopilot: 'shadow', ad_wording: 'balanced' },
    sets: [{ name: 'AR · GCC women · Broad', budget: 180, lang: 'ar', countries: ['AE', 'SA', 'KW'], stage: 'SUCCESS', edit: 18 }, { name: 'EN · UAE · Broad', budget: 70, lang: 'en', countries: ['AE'], stage: 'LEARNING', edit: 4 }],
    ads: [
      ['p-cleanse-ar', 0, 0.30, 0.018, 0.075, 22, 168, 0, 34, 'single_video'], ['p-glow-ar', 0, 0.28, 0.015, 0.050, 24, 190, 0, 34, 'single_image'],
      ['p-heartleaf-ar', 0, 0.22, 0.017, 0.055, 23, 150, 16, 30, 'single_image'], ['p-sun-ar', 0, 0.20, 0.0035, 0.0, 26, 140, 0, 12, 'single_image'],
      ['p-cleanse-en', 1, 0.45, 0.013, 0.045, 25, 160, 0, 30, 'single_image'], ['p-glow-en', 1, 0.35, 0.011, 0.022, 27, 180, 0, 30, 'single_image'],
      ['p-sun-en', 1, 0.20, 0.010, 0.030, 26, 150, 0, 5, 'single_image'],
      ['p-routine-ar', 0, 0.25, 0.016, 0.06, 21, 260, 25, 110, 'carousel', 'PAUSED'], ['p-routine-en', 1, 0.25, 0.012, 0.03, 25, 240, 20, 100, 'carousel', 'PAUSED'],
    ] },
  { id: 'act_2234567890', name: 'K-Beauty Bliss · KSA', currency: 'AED', status: 'ACTIVE', automate: false,
    limits: { daily_aed: 0, monthly_aed: 0, max_budget_step_pct: 20, cooldown_hours: 72, max_active_ads: 8, countries: ['SA'], autopilot: 'off', ad_wording: 'balanced' },
    sets: [{ name: 'AR · KSA women · Broad', budget: 90, lang: 'ar', countries: ['SA'], stage: 'FAIL', edit: 30 }],
    ads: [['p-glow-ar', 0, 0.5, 0.012, 0.03, 18, 175, 0, 60, 'single_image'], ['p-heartleaf-ar', 0, 0.5, 0.014, 0.035, 18, 150, 30, 60, 'single_image']] },
  { id: 'act_3234567890', name: 'KBB · Old boutique account', currency: 'AED', status: 'DISABLED', automate: false,
    limits: { daily_aed: 0, monthly_aed: 0, max_budget_step_pct: 20, cooldown_hours: 72, max_active_ads: 5, countries: ['AE'], autopilot: 'off', ad_wording: 'balanced' },
    sets: [{ name: 'EN · Dubai · Interests', budget: 40, lang: 'en', countries: ['AE'], stage: 'SUCCESS', edit: 200 }],
    ads: [['p-sun-en', 0, 1, 0.009, 0.02, 30, 150, 40, 120, 'single_image', 'PAUSED']], endedDaysAgo: 150 },
];

function buildAccount(cfg, n) {
  const A = { cfg, creatives: creativesFor(), metrics: [], log: [], pending: [], videos: [], brain: null, profile: null };
  const camp = { id: `1202${n}0000000001`, name: 'KBB · Sales · Advantage+', status: cfg.status === 'DISABLED' ? 'PAUSED' : 'ACTIVE', objective: 'OUTCOME_SALES', daily_budget_aed: 0, made_by_app: n === 1, created_at: iso(now - 120 * DAY), adsets: [] };
  cfg.sets.forEach((s, i) => camp.adsets.push({ id: `1202${n}000000001${i}`, campaign_id: camp.id, name: s.name, status: camp.status, daily_budget_aed: s.budget, lang: s.lang, countries: s.countries,
    learning_stage: s.stage, last_significant_edit: iso(now - s.edit * DAY), created_at: iso(now - 120 * DAY), ads: [] }));
  A.tree = [camp];
  let adN = 100;
  const end = cfg.endedDaysAgo || 0;
  for (const [cid, si, share, ctr, cvr, cpm, aov, fat, age, format, status] of cfg.ads) {
    const set = camp.adsets[si];
    const id = `1202${n}0000${adN++}`;
    const c = A.creatives.find((x) => x.id === cid);
    const paused = status === 'PAUSED' || camp.status !== 'ACTIVE';
    const stopAt = paused ? Math.max(end, Math.floor(age * 0.35)) : 0; // paused ads stopped spending some days ago
    set.ads.push({ id, adset_id: set.id, name: `${c.lang.toUpperCase()} · ${c.angle} · ${P[c.product_ids[0]].brand}`, status: paused ? 'PAUSED' : 'ACTIVE', effective_status: paused ? 'PAUSED' : 'ACTIVE',
      creative_id: cid, lang: c.lang, format, review_feedback: '', created_at: iso(now - (age + end) * DAY) });
    for (let k = age + end; k >= stopAt; k--) {
      const t = now.getTime() - k * DAY, dayIdx = age + end - k, part = k === 0 ? 0.45 : 1;
      const spend = set.daily_budget_aed * share * (0.85 + rnd() * 0.3) * part;
      let ctrD = ctr * (0.85 + rnd() * 0.3), freq = 1.1 + dayIdx * 0.03;
      if (fat && dayIdx > fat) { ctrD *= Math.max(0.45, 1 - (dayIdx - fat) * 0.06); freq = 2.2 + (dayIdx - fat) * 0.12; }
      const cpmD = cpm * (1 + (fat && dayIdx > fat ? (dayIdx - fat) * 0.03 : 0)) * (0.9 + rnd() * 0.2);
      const impr = Math.round(spend / cpmD * 1000), clicks = Math.round(impr * ctrD);
      const exp = clicks * cvr * (fat && dayIdx > fat ? 0.6 : 1);
      let purch = Math.floor(exp) + (rnd() < exp - Math.floor(exp) ? 1 : 0);
      if (k <= 1) purch = Math.round(purch * 0.7);
      const row = { date: dstr(t), level: 'ad', object_id: id, spend: +spend.toFixed(2), impressions: impr, reach: Math.round(impr / Math.max(freq, 1)), clicks, purchases: purch,
        revenue: +(purch * aov * (0.9 + rnd() * 0.2)).toFixed(2), frequency: +freq.toFixed(2), settling: k <= 2 };
      A.metrics.push(row);
      // placement breakdown rows (reels/stories carry ~70% at lower CPM for this audience)
      const reel = 0.45 + rnd() * 0.1, story = 0.2;
      for (const [pl, sh, cpmF, ctrF] of [['reels', reel, 0.8, 1.15], ['stories', story, 0.85, 1.0], ['feed', 1 - reel - story, 1.35, 0.85]]) {
        const s2 = spend * sh, im = Math.round(s2 / (cpmD * cpmF) * 1000), cl = Math.round(im * ctrD * ctrF);
        A.metrics.push({ date: row.date, level: 'ad', object_id: id, placement: pl, spend: +s2.toFixed(2), impressions: im, clicks: cl, purchases: Math.round(purch * sh * ctrF), revenue: +(row.revenue * sh * ctrF).toFixed(2), frequency: row.frequency, settling: row.settling });
      }
    }
  }
  const parent = {};
  for (const s of camp.adsets) for (const a of s.ads) parent[a.id] = s.id;
  const roll = (level, idOf) => {
    const m = {};
    for (const r of A.metrics) {
      if (r.level !== 'ad' || r.placement) continue;
      const k = idOf(r.object_id) + '|' + r.date;
      const a = (m[k] = m[k] || { date: r.date, level, object_id: idOf(r.object_id), spend: 0, impressions: 0, reach: 0, clicks: 0, purchases: 0, revenue: 0, frequency: 0, settling: r.settling });
      a.spend += r.spend; a.impressions += r.impressions; a.reach += r.reach; a.clicks += r.clicks; a.purchases += r.purchases; a.revenue += r.revenue; a.frequency = Math.max(a.frequency, r.frequency);
    }
    return Object.values(m);
  };
  A.metrics.push(...roll('adset', (id) => parent[id]), ...roll('campaign', () => camp.id));
  if (n === 1) {
    addLog(A, 'execution', camp.adsets[1].id, 'New ad "EN · summer-proof lightweight SPF texture" added to EN · UAE · Broad (created paused, then activated).', 'executed', now - 4 * DAY);
    addLog(A, 'execution', camp.adsets[0].id, 'AR · GCC women · Broad budget AED 150 → 180 (winner, +20%).', 'executed', now - 6 * DAY);
    addLog(A, 'brain.learning', '', 'Arabic routine hooks out-click ingredient hooks 1.3x at similar CPM.', 'learning', now - 6 * DAY);
    A.pending.push(Object.assign({}, A.creatives.find((c) => c.id === 'p-glow-ar').design, { id: 'd-new-glow-ar', pair_id: 'p-new-glow', template: 'offer', headline: 'هدية التوهّج لموسم الأعياد', sub: 'جاهزة للإهداء' }));
    A.pending.push(Object.assign({}, A.creatives.find((c) => c.id === 'p-glow-en').design, { id: 'd-new-glow-en', pair_id: 'p-new-glow', template: 'offer', headline: 'The glow gift of the season', sub: 'Ready to gift' }));
    A.videos.push({ id: 'v-demo-1', name: 'cosrx-morning-reel.mp4', path: 'videos/v-demo-1.mp4', bytes: 8_400_000, status: 'past', meta_video_id: '', meta_ad_ids: [set0ad(camp)], added_at: iso(now - 3 * DAY),
      results: { source: 'meta', spend: 1761, impressions: 80000, clicks: 1440, purchases: 113, revenue: 18984, views_3s: 30400, thruplays: 9100, note: '' }, thumb: '',
      analysis: { duration_s: 14.2, width: 1080, height: 1920, aspect: '9:16', placements: ['reels', 'stories'], hook: 'Fingertips squeeze the gel in close-up, Arabic line «صباحكِ يبدأ بلطف»', hook_strength: 8, language: 'ar',
        on_screen_text: ['صباحكِ يبدأ بلطف', 'COSRX Low pH'], captions: true, products_shown: [{ product_id: '3195', title: P['3195'].title, confidence: 0.92 }], first_product_s: 0.5, angle: 'routine',
        issues: [{ code: 'quality', severity: 'tip', detail: 'Slightly warm white balance in the last shot.' }], suggestions: ['Past results (meta): AED 1761 spent, 113 purchases, CPA AED 16, ROAS 10.78, CTR 1.80%, hook rate 38%.', 'Cut a 9 s version ending on the product.'],
        trims: [{ from_s: 11.5, to_s: 14.2, why: 'logo hold is long' }], score: 94, score_parts: { hook: 24, aspect: 15, duration: 15, captions: 10, safe_zone: 10, product_early: 10, arabic: 10 },
        summary: 'A strong Arabic routine reel: product in hand from the first second.', summary_ar: 'ريل روتين قوي بالعربية: المنتج ظاهر من الثانية الأولى.', model: 'claude-sonnet-5-5', analysed_at: iso(now - 3 * DAY) } });
  }
  return A;
}
function set0ad(camp) { return camp.adsets[0].ads[0].id; }
function addLog(A, kind, object, summary, outcome, t) { A.log.push({ id: 'L' + String(A.log.length + 1).padStart(5, '0'), time: iso(t || Date.now()), kind, object, summary, outcome }); }

const accounts = ACCOUNTS.map((c, i) => buildAccount(c, i + 1));
const acc = (id) => accounts.find((a) => a.cfg.id === id) || accounts[0];
const research = { updated_at: iso(now - 2 * DAY), notes: [
  { id: 'n1', topic: 'season', title: 'UAE peak calendar: White Friday, National Day, DSF, Ramadan 2027', title_ar: 'مواسم الإمارات: الجمعة البيضاء، عيد الاتحاد، مهرجان دبي للتسوق، رمضان ٢٠٢٧',
    body: 'Ramadan 2027 is expected to start about 8 Feb (moon sighting), Eid al-Fitr about 9 Mar. CPMs rise in DSF (mid-Dec to mid-Jan) and sharply in Ramadan and Eid, notably for beauty.',
    body_ar: 'يُتوقع أن يبدأ رمضان ٢٠٢٧ في حدود ٨ فبراير وعيد الفطر في حدود ٩ مارس. ترتفع تكلفة الظهور خلال مهرجان دبي للتسوق وبشكل أكبر في رمضان والعيد، خصوصاً لمنتجات الجمال.',
    angles: ['Ramadan night-time routine', 'Eid glow gifting'], strength: 0.8, date: iso(now - 2 * DAY),
    sources: [{ url: 'https://www.khaleejtimes.com/uae/ramadan-eid-al-fitr-eid-al-adha-2027-likely-dates', title: 'Khaleej Times: Ramadan, Eid 2027 likely dates' }, { url: 'https://www.superads.ai/facebook-ads-costs/cpm-cost-per-mille/united-arab-emirates', title: 'SuperAds: UAE Facebook CPM' }] },
  { id: 'n2', topic: 'format', title: 'Creative diversity is now the main lever on Meta', title_ar: 'تنوّع الإعلانات هو الرافعة الأهم على ميتا الآن',
    body: 'Meta calls creative diversification a foundational best practice: genuinely different concepts (persona, message, format), not near-duplicates.',
    body_ar: 'تعتبر ميتا تنويع الإعلانات ممارسة أساسية: أفكار مختلفة فعلاً في الشخصية والرسالة والشكل، لا نسخاً متقاربة.',
    angles: ['texture close-up reel', 'routine in three steps', 'ingredient spotlight'], strength: 0.8, date: iso(now - 2 * DAY),
    sources: [{ url: 'https://www.jonloomer.com/meta-andromeda/', title: 'Jon Loomer: Meta Andromeda' }, { url: 'https://ppc.land/andromeda/', title: 'PPC Land: Andromeda' }] },
  { id: 'n3', topic: 'arabic_audience', title: 'Ads in the UAE should be in Arabic (MSA or Emirati dialect)', title_ar: 'الإعلانات في الإمارات يجب أن تكون بالعربية (الفصحى أو اللهجة الإماراتية)',
    body: 'The advertising standards ask for Modern Standard Arabic or Emirati dialect; health or therapeutic claims need prior approval.', body_ar: 'تطلب معايير الإعلان استخدام العربية الفصحى أو اللهجة الإماراتية، وتحتاج الادعاءات الصحية أو العلاجية إلى موافقة مسبقة.',
    angles: ['Arabic-first hooks', 'benefit-led wording'], strength: 0.6, date: iso(now - 9 * DAY),
    sources: [{ url: 'https://www.tamimi.com/law-update/february-4/articles/advertising-standards-for-the-uae/', title: 'Al Tamimi: Advertising standards for the UAE' }] },
] };

// ---------- the real brain ----------
function brainDry(args, input) {
  const r = spawnSync('go', ['run', './cmd/brain-dry', ...args], { cwd: path.join(ROOT, 'engine', 'brain'), input: JSON.stringify(input), encoding: 'utf8', maxBuffer: 256 << 20 });
  if (r.status !== 0) { console.error('brain-dry', args.join(' '), 'failed:', r.stderr.slice(0, 400)); return null; }
  return JSON.parse(r.stdout);
}
function cycleInput(A) {
  return { now: now.toISOString(), limits: A.cfg.limits, account: { id: A.cfg.id, name: A.cfg.name, currency: A.cfg.currency, timezone: 'Asia/Dubai', spend_cap: A.cfg.limits.monthly_aed },
    tree: A.tree, metrics: A.metrics, shop: { products, orders: [], orders_source: 'none', fetched_at: now.toISOString() }, creatives: A.creatives, research, history: A.log, videos: A.videos };
}
let portfolio = null;
function train() {
  const t0 = Date.now();
  const r = brainDry(['-portfolio'], accounts.map(cycleInput));
  if (r) { portfolio = r.portfolio; r.profiles.forEach((p, i) => { accounts[i].profile = p; }); }
  console.log(`brain-dry -portfolio: ${accounts.length} profiles in ${Date.now() - t0} ms`);
}
function runBrain(A) {
  const t0 = Date.now();
  const inp = cycleInput(A); inp.profile = A.profile; inp.portfolio = portfolio;
  const out = brainDry([], inp);
  if (out) { A.brain = out; console.log(`brain-dry ${A.cfg.id}: ${out.verdicts.length} verdicts, ${out.proposals.length} proposals in ${Date.now() - t0} ms`); }
}
train();
for (const A of accounts) runBrain(A);
for (const p of accounts[0].brain ? accounts[0].brain.proposals : []) addLog(accounts[0], 'proposal', p.target, `${p.kind} ${p.target}: ${p.why}`, 'shadow', now - 3600000);

// ---------- derived state, in the REAL engine's shapes (docs/ENGINE-API.md) ----------
function agg(rows) {
  const a = rows.reduce((a, r) => { a.spend += r.spend; a.impressions += r.impressions; a.clicks += r.clicks; a.purchases += r.purchases; a.revenue += r.revenue; a.frequency = Math.max(a.frequency, r.frequency || 0); return a; },
    { spend: 0, impressions: 0, clicks: 0, purchases: 0, revenue: 0, frequency: 0 });
  a.roas = a.spend ? a.revenue / a.spend : 0; a.cpa = a.purchases ? a.spend / a.purchases : 0; a.cpc = a.clicks ? a.spend / a.clicks : 0;
  a.ctr = a.impressions ? a.clicks / a.impressions * 100 : 0; // engine: CTR in percent
  a.cpm = a.impressions ? a.spend / a.impressions * 1000 : 0;
  for (const k of Object.keys(a)) a[k] = +a[k].toFixed(4);
  return a;
}
const since = (n) => dstr(now.getTime() - n * DAY);
const totals = (A) => A.metrics.filter((r) => r.level === 'campaign');
function kpis(A) { const c = totals(A); return { today: agg(c.filter((r) => r.date === today)), '7d': agg(c.filter((r) => r.date >= since(7))), '30d': agg(c.filter((r) => r.date >= since(30))) }; }
const limitsSet = (A) => A.cfg.limits.daily_aed > 0 && A.cfg.limits.monthly_aed > 0;
function engineLimits(A) { const L = A.cfg.limits; return { daily_aed: L.daily_aed, monthly_aed: L.monthly_aed, max_budget_step_pct: L.max_budget_step_pct, cooldown_hours: L.cooldown_hours, max_active_ads: L.max_active_ads, countries: L.countries, autopilot: L.autopilot, target_cpa_aed: L.target_cpa_aed || 0, kill_multiple: L.kill_multiple || 3, wording: L.wording || '' }; }
function alertsOf(A) {
  const out = [];
  if (!limitsSet(A)) out.push({ at: now.toISOString(), level: 'warn', text: 'No limits set: the autopilot cannot launch anything in this account.' });
  if (A.cfg.status === 'DISABLED') out.push({ at: now.toISOString(), level: 'error', text: 'Meta disabled this ad account; its history is used for learning only.' });
  for (const x of A.brain ? A.brain.verdicts : []) if (x.verdict === 'fatigued') out.push({ at: now.toISOString(), level: 'warn', text: `Ad ${x.object} shows creative fatigue (${(x.signals || []).join(', ')}); a fresh creative is queued.` });
  out.push({ at: now.toISOString(), level: 'info', text: 'Orders and margins are not connected yet: break-even uses feed prices and a default margin.' });
  return out;
}
function engineState(A) {
  const m7 = (id) => agg(A.metrics.filter((r) => r.object_id === id && !r.placement && r.date >= since(7)));
  const camps = A.tree.map((c) => ({ id: c.id, name: c.name, objective: c.objective, status: c.status, effective_status: c.status, daily_budget: c.daily_budget_aed, managed: c.made_by_app, created_time: c.created_at, last7: m7(c.id),
    adsets: c.adsets.map((s) => ({ id: s.id, campaign_id: c.id, name: s.name, status: s.status, effective_status: s.status, daily_budget: s.daily_budget_aed, countries: s.countries, learning_stage: s.learning_stage, last7: m7(s.id),
      ads: s.ads.map((a) => ({ id: a.id, adset_id: s.id, campaign_id: c.id, name: a.name, status: a.status, effective_status: a.effective_status, creative_id: a.creative_id, format: a.format, last7: m7(a.id) })) })) }));
  const monthStart = today.slice(0, 8) + '01';
  const plan = A.brain ? { updated_at: A.brain.plan.generated_at, summary: A.brain.plan.summary, steps: (A.brain.plan.tests || []).map((t) => ({ when: 'batch day', title: t.name, detail: t.hypothesis })) } : null;
  return { account_id: A.cfg.id, automated: A.cfg.automate, at: new Date().toISOString(),
    account: { id: A.cfg.id, name: A.cfg.name, currency: A.cfg.currency, timezone: 'Asia/Dubai', spend_cap: A.cfg.limits.monthly_aed, amount_spent: 0, account_status: A.cfg.status === 'ACTIVE' ? 1 : 2 },
    links: { page_id: '2000000000' + A.n, instagram_user_id: '5000000000' + A.n, pixel_id: '3000000000' + A.n, catalog_id: A.n === 1 ? '400000000001' : '', whatsapp_number: A.n === 1 ? '+971500000000' : '' },
    link_checks: {}, limits: engineLimits(A), limits_set: limitsSet(A), campaigns: camps, kpis: kpis(A),
    committed_daily: camps.reduce((n, c) => n + (c.status === 'ACTIVE' ? c.daily_budget + c.adsets.reduce((m, s) => m + (s.status === 'ACTIVE' ? s.daily_budget : 0), 0) : 0), 0),
    month_spend: +totals(A).filter((r) => r.date >= monthStart).reduce((n, r) => n + r.spend, 0).toFixed(2),
    plan, report: A.brain ? { date: today, text: A.brain.report, at: iso(now - 3600000) } : null, alerts: alertsOf(A),
    last_sync: iso(now - 20 * 60000), last_cycle: iso(now - 3600000), next_sync: iso(Date.now() + 40 * 60000), next_cycle: iso(Math.ceil(Date.now() / (4 * 3600000)) * 4 * 3600000), cycle_running: false,
    backfill: { state: 'done', done: 37, total: 37, rows: A.metrics.length }, connections: { meta: true, claude: true, shop: true }, shop: { products: products.length, orders_source: 'none' },
    research_at: research.updated_at, creatives: A.creatives.length };
}
function accountRow(A) {
  return { id: A.cfg.id, name: A.cfg.name, currency: A.cfg.currency, account_status: A.cfg.status === 'ACTIVE' ? 1 : 2, business_id: '900000000001', relation: 'owned', automated: A.cfg.automate, limits_set: limitsSet(A), autopilot: A.cfg.limits.autopilot,
    links: engineState(A).links, link_checks: A.n === 1 ? { page: { ok: true, name: 'K-Beauty Bliss' }, instagram: { ok: true, name: 'kbeautybliss' }, pixel: { ok: true, name: 'kbeautybliss.com pixel' }, catalog: { ok: true, name: 'KBB catalog' }, whatsapp: { ok: true, name: '+971500000000' } } : { page: { ok: true, name: 'K-Beauty Bliss' } },
    backfill: { state: 'done', rows: A.metrics.length }, selected: A.cfg.id === selected };
}
let selected = accounts[0].cfg.id;
const connections = {
  meta: { ok: true, auth: 'system_user', token: { set: true, last4: 'x9Qa' }, app_secret: { set: false }, app_id: '', api_version: 'v27.0', token_type: 'SYSTEM_USER', token_days_left: -1, ad_accounts: accounts.length, access_tier: 'standard_access' },
  claude: { ok: true, model: 'claude-sonnet-5-5', key: { set: true, last4: '7f2c' } },
  shop: { ok: true, products: 902, orders_source: 'none', feed_url: 'https://kbeautybliss.com/feeds/meta-catalog.xml', token: { set: false } },
  secret_store: 'file',
};
// trending fixture: real product names from the shop's own feed + an item the shop does not sell; labelled as fixture
const trending = { updated_at: iso(now - 5 * 3600000), fixture: true, items: [
  { rank: 1, name: 'Heartleaf 77% Soothing Toner', brand: 'Anua', kind: 'product', momentum: 'hot', region: 'gcc', why: 'Fixture: a bestseller the shop carries.', why_ar: 'بيانات تجريبية: منتج رائج يبيعه المتجر.', sources: [{ url: 'https://kbeautybliss.com/feeds/meta-catalog.xml', title: 'Shop feed (fixture)' }], in_shop: true, in_stock: true, product_ids: ['3214'] },
  { rank: 2, name: 'Glow Serum: Propolis + Niacinamide', brand: 'Beauty of Joseon', kind: 'product', momentum: 'rising', region: 'global', why: 'Fixture.', why_ar: 'بيانات تجريبية.', sources: [{ url: 'https://kbeautybliss.com/feeds/meta-catalog.xml', title: 'Shop feed (fixture)' }], in_shop: true, in_stock: true, product_ids: ['3206'] },
  { rank: 3, name: 'PDRN Pink Peptide Serum', brand: 'Medicube', kind: 'product', momentum: 'new', region: 'global', why: 'Fixture: an item the shop does not sell.', why_ar: 'بيانات تجريبية: منتج غير متوفر في المتجر.', sources: [{ url: 'https://kbeautybliss.com/feeds/meta-catalog.xml', title: 'Fixture' }], in_shop: false, in_stock: false },
  { rank: 4, name: 'Rice', brand: '', kind: 'ingredient', momentum: 'steady', region: 'gcc', why: 'Fixture.', why_ar: 'بيانات تجريبية.', sources: [{ url: 'https://kbeautybliss.com/feeds/meta-catalog.xml', title: 'Fixture' }], in_shop: false, in_stock: false },
], selling: ['3214', '3206'] };
trending.consider_stocking = trending.items.filter((x) => !x.in_shop && x.kind !== 'ingredient');
for (const A of accounts) A.n = accounts.indexOf(A) + 1;
for (const A of accounts) { A.archive = A.creatives.filter((c) => c.design).map((c) => c.design).concat(A.pending); A.done = new Set(); A.settings = { ad_wording: A.cfg.limits.ad_wording || 'balanced', promo_approved: false }; }

// ---------- HTTP ----------
const sse = new Set();
function emit(type, data, account = '') { const s = `event: ${type}\ndata: ${JSON.stringify({ account, data: data || {} })}\n\n`; for (const r of sse) r.write(s); }
setInterval(() => emit('heartbeat', { at: new Date().toISOString() }), 15000).unref();
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.json': 'application/json', '.txt': 'text/plain; charset=utf-8' };
function json(res, code, v) { res.writeHead(code, { 'content-type': 'application/json', 'cache-control': 'no-store' }); res.end(JSON.stringify(v)); }
function rawBody(req, max) { return new Promise((ok, no) => { const c = []; let n = 0; req.on('data', (d) => { n += d.length; if (n > max) { req.destroy(); no(new Error('too big')); } else c.push(d); }); req.on('end', () => ok(Buffer.concat(c))); }); }
async function body(req) { try { const b = await rawBody(req, 40e6); return b.length ? JSON.parse(b.toString()) : {}; } catch { return null; } }
function placeholder(res) {
  res.writeHead(200, { 'content-type': 'image/svg+xml' });
  res.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600" fill="#F6F2F3"/><rect x="220" y="120" width="160" height="360" rx="30" fill="#3f7d5c" opacity=".85"/><rect x="245" y="80" width="110" height="60" rx="12" fill="#1f2a24"/></svg>');
}
function serveImg(res, u) {
  let url; try { url = new URL(u); } catch { return placeholder(res); }
  if (url.protocol !== 'https:' || !/(^|\.)kbeautybliss\.com$/.test(url.hostname)) return json(res, 400, { error: 'only https://kbeautybliss.com images' });
  const f = path.join(TMP, 'img', Buffer.from(u).toString('base64url').slice(-80));
  if (!fs.existsSync(f)) {
    const r = spawnSync('curl', ['-sfL', '--max-time', '20', '-o', f, u]);
    if (r.status !== 0 || !fs.existsSync(f) || fs.statSync(f).size < 500) { try { fs.unlinkSync(f); } catch {} return placeholder(res); }
  }
  res.writeHead(200, { 'content-type': /\.webp$/i.test(url.pathname) ? 'image/webp' : /\.png$/i.test(url.pathname) ? 'image/png' : 'image/jpeg', 'cache-control': 'max-age=86400' });
  fs.createReadStream(f).pipe(res);
}
function insights(A) {
  const days = [...new Set(totals(A).map((r) => r.date))].sort().slice(-30);
  const lang = {}; for (const s of A.tree[0].adsets) for (const a of s.ads) lang[a.id] = a.lang;
  const sp = (l) => { const a = agg(A.metrics.filter((r) => r.level === 'ad' && !r.placement && r.date >= since(30) && lang[r.object_id] === l)); return { spend: a.spend, purchases: a.purchases, revenue: a.revenue, cpa: a.cpa, roas: a.roas, ctr: a.ctr / 100 }; };
  return { series: days.map((d) => { const a = agg(totals(A).filter((r) => r.date === d)); return { date: d, spend: a.spend, revenue: a.revenue, purchases: a.purchases, cpc: a.cpc, cpm: a.cpm, ctr: a.ctr / 100 }; }), split: { ar: sp('ar'), en: sp('en') } };
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const p = url.pathname;
  if (!p.startsWith('/api/')) {
    if (p === '/') { res.writeHead(302, { location: '/app/' }); return res.end(); }
    const rel = p.replace(/^\/app\/?/, '/');
    const f = path.normalize(path.join(APP, rel === '/' ? 'index.html' : rel));
    if (!p.startsWith('/app') || !f.startsWith(APP) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('not found'); }
    res.writeHead(200, { 'content-type': MIME[path.extname(f)] || 'application/octet-stream', 'cache-control': 'no-cache',
      'content-security-policy': "default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob: data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'" });
    return fs.createReadStream(f).pipe(res);
  }
  if (p === '/api/info') return json(res, 200, { app: 'KBB Ads Autopilot', version: '0.3.0-fake', apiLevel: 2, token: TOKEN, dataDir: '~/KBB Ads Autopilot Data' });
  const tok = req.headers['x-kaa'] || (p === '/api/events' ? url.searchParams.get('t') : '');
  if (tok !== TOKEN) return json(res, 401, { error: 'missing X-KAA token' });
  if (p === '/api/update') return json(res, 200, { version: '0.3.0', engine: '0.3.0-fake', apiLevel: 2, canUpdate: false, why: 'fake engine', history: [], busy: false, paused: false });
  const A = acc(req.headers['x-kaa-account'] || url.searchParams.get('account') || selected);
  if (p === '/api/events') {
    res.writeHead(200, { 'content-type': 'text/event-stream', 'cache-control': 'no-store', connection: 'keep-alive' });
    res.write('event: hello\ndata: {}\n\n'); sse.add(res); req.on('close', () => sse.delete(res)); return;
  }
  // ---- app-wide ----
  if (p === '/api/accounts' && req.method === 'GET') return json(res, 200, { selected, accounts: accounts.map(accountRow), businesses: [{ id: '900000000001', name: 'K-Beauty Bliss portfolio', ad_accounts: accounts.map((x) => x.cfg.id) }], read_at: now.toISOString(), global_limits: { daily_aed: 0, monthly_aed: 0 } });
  if (p === '/api/accounts/select' && req.method === 'POST') { const b = (await body(req)) || {}; selected = acc(b.account).cfg.id; return json(res, 200, { selected }); }
  const am = p.match(/^\/api\/accounts\/(act_\d+)$/);
  if (am && req.method === 'PUT') { const b = (await body(req)) || {}; const X = acc(am[1]); if (typeof b.automated === 'boolean') X.cfg.automate = b.automated; emit('connections', {}, X.cfg.id); return json(res, 200, { account: X.cfg.id, automated: X.cfg.automate, links: engineState(X).links, link_checks: {} }); }
  if (p === '/api/learnings') {
    const out = [];
    for (const X of accounts) for (const [dim, keyOf] of [['format', (a) => a.format], ['country', () => 'AE'], ['objective', () => 'OUTCOME_SALES']]) {
      const by = {};
      for (const s of X.tree[0].adsets) for (const a of s.ads) { const k = keyOf(a); const g = (by[k] = by[k] || { ids: new Set(), ads: 0 }); g.ids.add(a.id); g.ads++; }
      for (const [k, g] of Object.entries(by)) { const a = agg(X.metrics.filter((r) => r.level === 'ad' && !r.placement && g.ids.has(r.object_id))); out.push({ account_id: X.cfg.id, dimension: dim, key: k, spend: a.spend, purchases: a.purchases, revenue: a.revenue, ads: g.ads, cpa: a.cpa, roas: a.roas, from: since(150), to: today }); }
    }
    return json(res, 200, out);
  }
  if (p === '/api/research') return json(res, 200, { updated_at: research.updated_at, trends: research.notes.map((n) => ({ date: n.date.slice(0, 10), title: n.title, body: n.body, source: n.sources[0].url })), competitors: [], angles: [], arabic_insights: [], sources: research.notes.flatMap((n) => n.sources.map((s) => s.url)) });
  if (p === '/api/brain/research' && req.method === 'POST') { research.updated_at = new Date().toISOString(); emit('research.updated', { at: research.updated_at }); return json(res, 200, { updated_at: research.updated_at, trends: [], competitors: [], angles: [], arabic_insights: [], sources: [] }); }
  if (p === '/api/connections' && req.method === 'GET') return json(res, 200, connections);
  const cm = p.match(/^\/api\/connections\/(meta|claude|shop)$/);
  if (cm && req.method === 'PUT') {
    const b = (await body(req)) || {}; const k = cm[1]; const c = connections[k];
    if (k === 'meta') { if (b.token) c.token = { set: true, last4: String(b.token).slice(-4) }; c.ok = !b.token || /^EAA/.test(b.token); c.error = c.ok ? '' : 'Meta said: Invalid OAuth access token (code 190)'; }
    if (k === 'claude') { if (b.api_key) c.key = { set: true, last4: String(b.api_key).slice(-4) }; c.ok = !b.api_key || /^sk-ant-/.test(b.api_key); c.error = c.ok ? '' : 'Claude said: invalid x-api-key (401)'; }
    if (k === 'shop') { c.feed_url = b.feed_url || c.feed_url; c.ok = true; }
    return json(res, 200, c);
  }
  if (p === '/api/kill' && req.method === 'POST') {
    const out = [];
    for (const X of accounts) { const paused = []; X.cfg.limits.autopilot = 'off'; for (const c of X.tree) if (c.made_by_app && c.status === 'ACTIVE') { c.status = 'PAUSED'; paused.push(c.id); addLog(X, 'kill', c.id, `KILL SWITCH: campaign ${c.name} paused, autopilot off.`, 'executed'); } out.push({ account: X.cfg.id, paused, failed: [] }); }
    emit('action.kill', {}); return json(res, 200, { accounts: out });
  }
  // ---- per account ----
  // the media-buying lane's screens (Strategy, Plan jobs, funnel, occasions, learning): small fixed answers
  if (p === '/api/playbook') return json(res, 200, { account: A.cfg.id, playbook: { version: 0, instructions: 'Focus on the latest arrivals and the products trending now.', focus: [], avoid: [], freshness_pct: 70, min_stock: 3, min_margin_pct: 0, countries: [], age_min: 18, age_max: 45, gender: 'women', languages: ['ar', 'en'], exclude_buyers_days: 180, split: { prospecting: 70, retargeting: 20, existing: 10 }, small_split: { prospecting: 80, retargeting: 20, existing: 0 }, offers_allowed: true, free_gift: true, coupon_code: 'GLOW', gifts: [], pay_later: true, econ: { vat_pct: 5, bnpl_fee_pct: 7, card_fee_pct: 2.5, cod_fee_pct: 1.5, delivery_aed: 15, gift_cost_aed: 0 }, knobs: {}, self_tune: 'bounds', tune_bounds: { hard_stop_min: 2, hard_stop_max: 4, fatigue_freq_min: 2, fatigue_freq_max: 3.5 } }, excluded: [], products: 3, allowed: 3 });
  if (p === '/api/warmup') { const w = { running: false, reason: '', runs: 0, steps: [] }; return json(res, 200, req.method === 'POST' ? { state: w } : w); } // the QA lane's warm-up: nothing to refresh here
  if (p === '/api/funnel') return json(res, 200, { account: A.cfg.id, tier: 'growth', target_cpa: 60, pieces: [], audiences: [] });
  if (p === '/api/occasions') return json(res, 200, { account: A.cfg.id, occasions: [], upcoming: [], packages: [], ads: {}, auto_approve: false });
  if (p === '/api/jobs') return json(res, 200, { account: A.cfg.id, jobs: [], mode: 'shadow' });
  if (p === '/api/brain/x/strategy') return json(res, 200, { economics: {}, funnel: [], rules: [], ranked: [], agency: [], seasons: [], split: {}, tier: 'growth' });
  if (p === '/api/brain/x/learning') return json(res, 200, { weekly: [], health: [], lessons: [], tuned: {}, changes: [], open: 0, scored: 0, cells: 0, self_tune: 'bounds' });
  if (p === '/api/state' && req.method === 'GET') return json(res, 200, engineState(A));
  if (p === '/api/limits') {
    if (req.method === 'GET') return json(res, 200, engineLimits(A));
    const b = await body(req);
    if (!b) return json(res, 400, { error: 'bad JSON' });
    const allowed = ['daily_aed', 'monthly_aed', 'max_budget_step_pct', 'cooldown_hours', 'max_active_ads', 'countries', 'autopilot', 'target_cpa_aed', 'kill_multiple', 'wording'];
    const unknown = Object.keys(b).filter((k) => !allowed.includes(k));
    if (unknown.length) return json(res, 400, { error: `bad JSON: json: unknown field "${unknown[0]}"` }); // the engine refuses unknown fields
    const n = Object.assign({}, A.cfg.limits, b);
    if (n.autopilot !== 'off' && !(n.daily_aed > 0 && n.monthly_aed > 0)) return json(res, 400, { error: 'Set a daily and a monthly limit before turning the autopilot on.' });
    if (n.monthly_aed > 0 && n.daily_aed > n.monthly_aed) return json(res, 400, { error: 'The daily limit is above the monthly limit.' });
    A.cfg.limits = n;
    addLog(A, 'config', '', `Limits saved: AED ${n.daily_aed}/day, AED ${n.monthly_aed}/month, autopilot ${n.autopilot}.`, 'saved');
    emit('metrics.updated', {}, A.cfg.id); return json(res, 200, engineLimits(A));
  }
  if (p === '/api/cycle' && req.method === 'POST') {
    const b = (await body(req)) || {};
    runBrain(A);
    const props = A.brain ? A.brain.proposals : [];
    const mode = b.mode === 'auto' && A.cfg.limits.autopilot !== 'off' ? 'auto' : 'shadow';
    addLog(A, 'cycle', '', `Cycle finished (${mode}): ${props.length} proposals.`, mode);
    emit('cycle.end', {}, A.cfg.id);
    return json(res, 200, { account: A.cfg.id, counts: mode === 'auto' ? { done: props.length } : { shadow: props.length }, results: [], proposals: props.length, ms: 40 });
  }
  if (p === '/api/log') { const lim = +url.searchParams.get('limit') || 200; return json(res, 200, { account: A.cfg.id, entries: A.log.slice(-lim).map((l, i) => ({ seq: i + 1, at: l.time, kind: l.kind, summary: l.summary, data: { target: l.object, outcome: l.outcome } })), last: A.log.length }); }
  if (p === '/api/creatives') {
    if (req.method === 'GET') return json(res, 200, A.creatives.map((c) => ({ id: c.id, lang: c.lang, format: c.format, headline: c.headline, primary_text: c.primary_text, cta: c.cta, link: c.link, product_ids: c.product_ids, angle: c.angle, source: c.source, created_at: c.created_at, media: c.media || 'png', used: !!c.used })));
    const b = await body(req);
    const allowed = ['id', 'lang', 'format', 'png', 'video', 'headline', 'primary_text', 'cta', 'link', 'product_ids', 'angle', 'source'];
    const unknown = Object.keys(b || {}).filter((k) => !allowed.includes(k));
    if (!b || unknown.length) return json(res, 400, { error: `bad JSON: json: unknown field "${unknown[0]}"` });
    if (!['ar', 'en'].includes(b.lang) || !['1:1', '4:5', '9:16'].includes(b.format) || !(b.png || b.video)) return json(res, 400, { error: 'need lang, format, png or video' });
    if (!String(b.link || '').startsWith('https://kbeautybliss.com/')) return json(res, 400, { error: 'link must be a page of https://kbeautybliss.com/' });
    const buf = Buffer.from(String(b.png || b.video), 'base64');
    const id = `cr-${Date.now().toString(36)}-${A.creatives.length}`;
    fs.writeFileSync(path.join(TMP, 'creatives', id + (b.video ? '.media' : '.png')), buf);
    A.creatives.push({ id, lang: b.lang, format: b.format, headline: b.headline || '', primary_text: b.primary_text || '', cta: b.cta || 'SHOP_NOW', link: b.link, product_ids: b.product_ids || [], angle: b.angle || '', source: b.source || 'brain', created_at: new Date().toISOString(), media: b.video ? 'mp4' : 'png' });
    addLog(A, 'config', id, `creative added: ${id} (${b.lang} ${b.format}, ${b.source || 'brain'}, ${Math.round(buf.length / 1024)} KB)`, 'saved');
    return json(res, 200, A.creatives[A.creatives.length - 1]);
  }
  // the engine's per-proposal approvals (proposals.go): the last plan's proposals with what became of each
  if (p === '/api/proposals' && req.method === 'GET') {
    const auto = A.cfg.limits.autopilot === 'auto';
    A.decided = A.decided || {};
    const items = (A.brain ? A.brain.proposals : []).map((q) => { const pr = Object.assign({}, q.params); if (pr.daily_budget_aed != null) { pr.daily_budget = pr.daily_budget_aed; delete pr.daily_budget_aed; }
      return { id: q.id, kind: q.kind, target: String(q.target || '').replace(/^new:/, 'tmp:'), params: pr, why: q.why, confidence: q.confidence, evidence: q.evidence || [], status: A.decided[q.id] || (auto ? 'done' : 'shadow'), by: A.decided[q.id] ? 'owner' : 'cycle' }; });
    return json(res, 200, { cycle_at: now.toISOString(), mode: auto ? 'auto' : 'shadow', proposals: items });
  }
  const pm = /^\/api\/proposals\/([A-Za-z0-9:._-]+)\/(approve|reject)$/.exec(p);
  if (pm && req.method === 'POST') {
    A.decided = A.decided || {};
    const q = (A.brain ? A.brain.proposals : []).find((x) => x.id === pm[1]);
    if (!q) return json(res, 404, { error: 'no proposal ' + pm[1] + ' in the last plan' });
    if (A.decided[q.id]) return json(res, 409, { error: 'this proposal is not waiting for a decision (it is ' + A.decided[q.id] + ')' });
    A.decided[q.id] = pm[2] === 'approve' ? 'done' : 'rejected';
    addLog(A, 'approval', q.target, 'owner ' + (pm[2] === 'approve' ? 'approved ' : 'said not now to ') + q.kind + ' ' + q.target, A.decided[q.id]);
    return json(res, 200, pm[2] === 'approve' ? { account: A.cfg.id, id: q.id, ran: 1, counts: { done: 1 }, results: [] } : { account: A.cfg.id, id: q.id, status: 'rejected' });
  }
  const cf = /^\/api\/creatives\/([a-z0-9][a-z0-9_-]{2,63})\/file$/.exec(p);
  if (cf && req.method === 'GET') {
    const c = A.creatives.find((x) => x.id === cf[1]);
    const f = c && path.join(TMP, 'creatives', c.id + (c.media === 'mp4' ? '.media' : '.png'));
    if (!c || !fs.existsSync(f)) return json(res, 404, { error: 'this creative has no stored picture or video' });
    res.writeHead(200, { 'content-type': c.media === 'mp4' ? 'video/mp4' : 'image/png', 'cache-control': 'private, max-age=86400' });
    return res.end(fs.readFileSync(f));
  }
  if (p === '/api/brain/plan' && req.method === 'POST') { runBrain(A); return json(res, 200, { plan: A.brain.plan, proposals: A.brain.proposals, report: A.brain.report }); }
  // ---- the brain's own routes (/api/brain/x/), as engine/brain/httpx.go serves them ----
  const x = p.startsWith('/api/brain/x/') ? p.slice('/api/brain/x/'.length) : null;
  if (x !== null) {
    if (x === 'img') return serveImg(res, url.searchParams.get('u') || '');
    if (x === 'cycle') return json(res, 200, Object.assign({}, A.brain || {}, { designs: A.pending.filter((d) => !A.done.has(d.id)), profile: A.profile }));
    if (x === 'design-done' && req.method === 'POST') { const b = (await body(req)) || {}; A.done.add(b.id); A.pending = A.pending.filter((d) => d.id !== b.id); return json(res, 200, { ok: true }); }
    if (x === 'designs') return json(res, 200, A.archive);
    // design lane: no models / no generator keys in the fake engine (the real engine's shapes, empty)
    if (x === 'seg/status') return json(res, 200, { have: {}, models: [], assets: [], jobs: {} });
    if (x.startsWith('seg/mask')) { res.writeHead(204); return res.end(); }
    if (x === 'gen/settings') return json(res, 200, { providers: [], budget_usd: 0, spent_usd: 0, image: '', video: '', month: '' });
    // Create with Claude: creation requests, empty here (the real shape of engine/brain/creation.go creationView)
    if (x === 'creation') return json(res, 200, { mode: 'with_me', fallback_hours: 0, requests: [], notifications: [] });
    if (x === 'insights') return json(res, 200, insights(A));
    if (x === 'profile') return json(res, 200, A.profile);
    if (x === 'research') return json(res, 200, research);
    if (x === 'trending') { if (req.method === 'POST') trending.updated_at = new Date().toISOString(); return json(res, 200, trending); }
    if (x === 'settings') { if (req.method === 'PUT') { const b = (await body(req)) || {}; A.settings = { ad_wording: ['strict', 'relaxed'].includes(b.ad_wording) ? b.ad_wording : 'balanced', promo_approved: !!b.promo_approved }; } return json(res, 200, A.settings); }
    if (x === 'copy' && req.method === 'POST') { // fixture copy (no Claude here): shows the flow end to end
      const b = (await body(req)) || {};
      const pr = P[(b.product_ids || [])[0]] || products[0];
      const pair = 'p-gen-' + Date.now().toString(36);
      const ttl = pr.title.split(' - ').slice(1).join(' - ') || pr.title;
      const sets = [
        { lang: 'ar', headline: `${pr.brand}: لمسة كورية يومية`, sub: 'روتين يومي ببساطة', bullets: ['جمال كوري أصلي', 'قوام خفيف', 'للاستخدام اليومي'], primary_text: `${ttl} من ${pr.brand}، خطوة بسيطة تمنح البشرة إحساساً بالانتعاش كل يوم.` },
        { lang: 'en', headline: `${pr.brand}, the daily K-beauty step`, sub: 'Simple, every day', bullets: ['Authentic K-beauty', 'Light texture', 'Daily use'], primary_text: `${ttl} by ${pr.brand}: a simple step for skin that feels fresh every day.` },
      ];
      const designs = sets.map((s) => ({ id: `d-${pair}-${s.lang}`, pair_id: pair, lang: s.lang, template: b.template || 'clean', formats: ['1:1', '4:5', '9:16'], product_ids: [pr.id], images: [pr.image],
        headline: s.headline, sub: s.sub, bullets: s.bullets, badge: pr.brand, cta: 'SHOP_NOW', price: s.lang === 'ar' ? `${pr.price} درهم` : `AED ${pr.price}`, angle: b.angle || 'daily routine', copy_id: `${pair}-${s.lang}` }));
      A.archive.push(...designs);
      return json(res, 200, { copy_sets: sets.map((s) => Object.assign({ id: `${pair}-${s.lang}`, pair_id: pair, cta: 'SHOP_NOW', lint: { ok: true } }, s)), designs });
    }
    if (x === 'videos' && req.method === 'GET') return json(res, 200, A.videos);
    if (x === 'videos' && req.method === 'POST') {
      let buf; try { buf = await rawBody(req, 300e6); } catch { return json(res, 413, { error: 'video over 300 MB' }); }
      const name = decodeURIComponent(req.headers['x-filename'] || 'video.mp4').replace(/[^\w.\- ]/g, '_').slice(0, 120);
      const id = 'v' + Date.now().toString(16);
      fs.writeFileSync(path.join(TMP, 'videos', id + (path.extname(name) || '.mp4')), buf);
      const v = { id, name, path: `videos/${id}${path.extname(name)}`, bytes: buf.length, status: req.headers['x-status'] === 'past' ? 'past' : 'new', meta_video_id: '', meta_ad_ids: [], results: null, analysis: null, thumb: '', added_at: new Date().toISOString() };
      A.videos.unshift(v);
      addLog(A, 'config', id, `video added: ${name} (${(buf.length / 1048576).toFixed(1)} MB)`, 'saved');
      return json(res, 200, v);
    }
    const vm = x.match(/^videos\/(v[\w-]+)(?:\/(analyse|thumb))?$/);
    if (vm) {
      const v = A.videos.find((y) => y.id === vm[1]); if (!v) return json(res, 404, { error: 'no such video' });
      if (vm[2] === 'thumb') { const f = path.join(TMP, 'videos', v.id + '.jpg'); if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': 'image/jpeg' }); return fs.createReadStream(f).pipe(res); }
      const b = await body(req);
      if (!b) return json(res, 400, { error: 'bad JSON' });
      if (vm[2] === 'analyse') {
        if (b.frames && b.frames[0]) { fs.writeFileSync(path.join(TMP, 'videos', v.id + '.jpg'), Buffer.from(b.frames[0].jpeg, 'base64')); v.thumb = `/api/brain/x/videos/${v.id}/thumb`; }
        if (b.results) v.results = Object.assign({ source: 'owner' }, b.results);
        const a = brainDry(['-video'], { video: { id: v.id, name: v.name, duration_s: b.duration_s, width: b.width, height: b.height, has_audio: !!b.has_audio, frames: (b.frames || []).slice(0, 10), results: v.results, notes: b.notes || '', ad_wording: A.settings.ad_wording }, shop: { products } });
        if (!a) return json(res, 500, { error: 'analysis failed' });
        if (a.model === 'local-only') a.model = 'local-only (the fake engine does not call Claude)';
        v.analysis = a;
      } else { if (b.status) v.status = b.status; if (b.results) v.results = Object.assign({ source: 'owner' }, b.results); }
      return json(res, 200, v);
    }
    return json(res, 404, { error: 'no such brain endpoint' });
  }
  json(res, 404, { error: 'no such endpoint' });
});
server.listen(PORT, '127.0.0.1', () => console.log(`fake engine on http://127.0.0.1:${PORT}/ (token ${TOKEN})`));
