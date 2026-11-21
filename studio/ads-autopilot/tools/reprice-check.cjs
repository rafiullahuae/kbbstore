#!/usr/bin/env node
// Prices on the ads (the owner, 21 Nov: "the system is picking the actual price, not the sale price ... the designs
// are absolutely stunning, i don't want to lose them, just the corrections i need in the price"). On the REAL engine
// (-mock: fake Meta, fake feed where COSRX 3195 is AED 70 with a sale price of AED 59 — the owner's exact case):
//   1  a new set made in the Claude app (MCP) prints the SALE price (AED 59), never the regular one
//   2  the old state is rebuilt (the session's designs carry "AED 70", saved, and launched live on Meta)
//   3  Creatives → Made with Claude → "Correct prices on all ads": every saved picture and the video are redrawn
//   4  the same library ids (packages / queues keep pointing at them), the session's designs now say AED 59
//   5  the design is untouched: the pixels that changed sit inside the price chip only (diff box < 12% of the area)
//   6  the live ads: the old ad paused, a new ad with the corrected creative live in the same ad set
//   7  a second run finds nothing; no console errors
// --shots: shots/reprice-*.png (before / after, 390 and 1280).
//   NODE_PATH=… node tools/reprice-check.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const SHOT = process.argv.includes('--shots');
const PORT = 44100 + Math.floor(Math.random() * 300);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const find = (dir, name) => { for (const f of fs.readdirSync(dir, { withFileTypes: true })) { const p = path.join(dir, f.name); if (f.isDirectory()) { const x = find(p, name); if (x) return x; } else if (f.name === name) return p; } return null; };

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-reprice-'));
  const dataDir = path.join(tmp, 'data');
  fs.mkdirSync(dataDir);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: '/bin/true', KAA_OPEN_CMD: '/bin/true', KAA_CLAUDE_CONFIG: path.join(tmp, 'cfg.json') });
  const eng = spawn(bin, ['-dir', ROOT, '-data', dataDir, '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '1800s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let engLog = '';
  eng.stdout.on('data', (d) => { engLog += d; }); eng.stderr.on('data', (d) => { engLog += d; });
  let mcp = null;
  const stop = () => { try { eng.kill(); } catch {} try { mcp && mcp.kill(); } catch {} };
  process.on('exit', stop);
  const base = `http://127.0.0.1:${PORT}`;
  let up = false;
  for (let i = 0; i < 200 && !up; i++) { await sleep(150); up = await fetch(base + '/api/info').then((r) => r.ok).catch(() => false); }
  if (!up) { console.log(engLog.slice(-3000)); process.exit(2); }
  const info = await (await fetch(base + '/api/info')).json();
  const api = async (p, method = 'GET', body) => { const r = await fetch(base + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  for (let i = 0; i < 100; i++) { const a = await api('/api/accounts'); if (a.j && a.j.accounts && a.j.accounts.length) break; await sleep(200); }
  await sleep(1500);
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'], max_active_ads: 14 });

  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  await ctx.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.seg', 'off'); } catch {} });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(base + '/app/#/overview');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state && window.__kaa.sseAt, null, { timeout: 30000 });
  const H = (fn, ...a) => page.evaluate(fn, ...a);

  // MCP: the Claude app's path
  mcp = spawn(bin, ['-mcp', '-data', dataDir], { stdio: ['pipe', 'pipe', 'pipe'] });
  let buf = '';
  const waiting = new Map();
  mcp.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const ln = buf.slice(0, i); buf = buf.slice(i + 1); if (!ln.trim()) continue; const m = JSON.parse(ln); if (waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } } });
  let rid = 0;
  const rpc = (method, params) => new Promise((ok) => { const id = ++rid; waiting.set(id, ok); mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n'); });
  const tool = (name, args) => rpc('tools/call', { name, arguments: args });
  const textOf = (r) => { try { return JSON.parse(r.result.content[0].text); } catch { return r.result ? r.result.content[0].text : r.error; } };
  await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'reprice-check', version: '1' } });
  mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
  const idea = { product_ids: ['3195'], angle: 'gentle low-pH morning cleanse', hook: 'Calm mornings start here', template: 'glow', formats: ['1:1', '4:5', '9:16'], show_price: true, motion: true,
    ar: { headline: 'صباحكِ يبدأ بلطف وهدوء', primary_text: 'غسول COSRX الصباحي بدرجة حموضة منخفضة، خطوة يومية لطيفة. أصلي من كوريا، توصيل داخل الإمارات.', sub: 'غسول الصباح اللطيف', bullets: ['حموضة منخفضة', 'شجرة الشاي'], badge: 'COSRX', cta: 'SHOP_NOW' },
    en: { headline: 'Fresher-looking skin, fast', primary_text: 'COSRX Low pH Good Morning cleanser, a gentle daily step. Authentic from Korea, delivered across the UAE.', sub: 'Low pH gel cleanser', bullets: ['Low pH', 'Tea tree'], badge: 'COSRX', cta: 'SHOP_NOW' } };

  // 1 ─ a new set prints the sale price
  const c1 = textOf(await tool('create_ad_set', { ideas: [idea], prompt: 'new set' }));
  let ss = (await api('/api/brain/x/create')).j;
  const s1 = ss.find((s) => s.id === c1.session_id);
  const prices = [...new Set(s1.designs.map((d) => `${d.lang}:${d.price}`))];
  check('a new design prints the SALE price', s1.designs.every((d) => d.lang === 'en' ? d.price === 'AED 59' : d.price === '59 درهم'), prices.join(' · '));
  const lp = textOf(await tool('list_products', { query: 'cleanser' }));
  const p3195 = (Array.isArray(lp) ? lp : []).find((p) => p.id === '3195');
  check('Claude is told the sale price', p3195 && p3195.price_aed === 59, p3195 ? `list_products price_aed ${p3195.price_aed}` : JSON.stringify(lp).slice(0, 200));

  // 2 ─ the owner's state before the fix: the same set with the regular price, saved, launched
  const sessFile = find(dataDir, 'create_sessions.json');
  const all = JSON.parse(fs.readFileSync(sessFile, 'utf8'));
  for (const s of all) if (s.id === s1.id) for (const d of s.designs) d.price = d.lang === 'en' ? 'AED 70' : '70 درهم';
  fs.writeFileSync(sessFile, JSON.stringify(all));
  const sv = textOf(await tool('save_to_library', { session_id: s1.id }));
  const ids = sv.saved_creative_ids || [];
  // the box also saves the motion version: one, drawn here from the same (old-price) design, kept with the set
  const dv = s1.designs.find((d) => d.lang === 'en');
  const vb64 = await H(async (d) => { const { renderVideo } = await import('/app/js/creative/motion.js'); const r = await renderVideo(Object.assign({}, d, { price: 'AED 70' }), '9:16', 6, null, () => {}); const b = new Uint8Array(await r.blob.arrayBuffer()); let s = ''; for (let i = 0; i < b.length; i += 32768) s += String.fromCharCode.apply(null, b.subarray(i, i + 32768)); return { b64: btoa(s), mime: r.mime }; }, dv);
  const vid = 'cc-0000aaaa' + dv.id.replace(/[^a-z0-9]/g, '').slice(-6) + '-mp4';
  const vr = await api('/api/creatives', 'POST', { id: vid, lang: 'en', format: '9:16', video: vb64.b64, headline: dv.headline, primary_text: dv.primary_text || dv.headline, cta: 'SHOP_NOW', link: 'https://kbeautybliss.com/', product_ids: dv.product_ids, angle: dv.angle, source: 'owner' });
  await api('/api/brain/x/create/' + s1.id, 'PUT', { saved: [vid] });
  if (vr.s === 200) ids.push(vid);
  const vids = ids.filter((x) => /-mp4$/.test(x));
  const ri = textOf(await tool('run_it', { session_id: s1.id, launch_now: true }));
  let lib = (await api('/api/creatives')).j;
  const before = Object.fromEntries(lib.filter((c) => ids.includes(c.id)).map((c) => [c.id, c]));
  const liveBefore = Object.values(before).filter((c) => c.meta_creative_id);
  const imgOf = async (id) => Buffer.from(await (await fetch(`${base}/api/creatives/${id}/file?t=${Date.now()}`, { headers: { 'X-KAA': info.token } })).arrayBuffer()).toString('base64');
  const pngBefore = {};
  for (const id of ids.filter((x) => !/-mp4$/.test(x))) pngBefore[id] = await imgOf(id);
  check('old state: saved with AED 70 and launched', ids.length >= 5 && vids.length === 1 && liveBefore.length >= 1, `${ids.length} saved (${vids.length} video) · run_it ${JSON.stringify(ri.counts || ri)} · ${liveBefore.length} on Meta`);
  await api('/api/limits', 'PUT', { autopilot: 'auto' });
  await sleep(31000); // the owner's live ads are hours old; Meta allows one edit per object every 30 s
  const st0 = (await api('/api/state')).j;
  const adsOf = (st) => (st.tree || st.campaigns || []).flatMap((c) => (c.adsets || []).flatMap((s) => (s.ads || []).map((a) => Object.assign({ set: s.id }, a))));
  const oldMeta = new Set(liveBefore.map((c) => c.meta_creative_id));
  const liveOld = adsOf(st0).filter((a) => oldMeta.has(a.creative_id) && a.status === 'ACTIVE');

  // 3 ─ the button
  await page.goto(base + '/app/#/creatives');
  await page.waitForSelector('[data-tab="claude"]');
  await page.click('[data-tab="claude"]');
  await page.waitForSelector('#rp-go', { timeout: 15000 });
  if (SHOT) { for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(300); await page.screenshot({ path: path.join(SHOTS, `reprice-button-${w}.png`) }); } await page.setViewportSize({ width: 1280, height: 900 }); }
  const sw = await H(() => document.documentElement.scrollWidth);
  const t0 = Date.now();
  await page.click('#rp-go');
  await page.waitForFunction(() => document.querySelector('#rp-go') && !document.querySelector('#rp-go').disabled && /show the sale price|already shows|could not/.test(document.querySelector('#rp-out').textContent), null, { timeout: 300000 });
  const said = await H(() => document.querySelector('#rp-out').innerText);
  const vidAfter = await (await fetch(`${base}/api/creatives/${vid}/file`, { headers: { 'X-KAA': info.token } })).arrayBuffer();
  const ms = Date.now() - t0;
  if (SHOT) { await sleep(200); await page.screenshot({ path: path.join(SHOTS, 'reprice-done-1280.png') }); }
  check('the button redraws every saved picture and the video', new RegExp(`^${ids.length} picture`).test(said) && Buffer.from(vidAfter).toString('base64') !== vb64.b64, `${ms} ms · video ${vb64.mime} ${(vidAfter.byteLength / 1e6).toFixed(2)} MB · “${said.replace(/\n/g, ' | ')}”`);

  // 4 ─ same ids, new media, designs say AED 59
  lib = (await api('/api/creatives')).j;
  const after = Object.fromEntries(lib.filter((c) => ids.includes(c.id)).map((c) => [c.id, c]));
  check('the same library ids, redrawn once each', ids.every((id) => after[id] && after[id].media_rev === 1 && after[id].repriced_at && after[id].approved === before[id].approved), `${Object.keys(after).length}/${ids.length} kept · approved flags kept`);
  ss = (await api('/api/brain/x/create')).j;
  const s1b = ss.find((s) => s.id === s1.id);
  check('the session now says the sale price', s1b.designs.every((d) => /59/.test(d.price)) && JSON.stringify(s1b.designs.map((d) => Object.assign({}, d, { price: '' }))) === JSON.stringify(s1.designs.map((d) => Object.assign({}, d, { price: '' }))), s1b.designs.map((d) => d.price).join(' · '));

  // 5 ─ the design is untouched: only the price chip's pixels moved
  const diffs = [];
  for (const id of Object.keys(pngBefore)) {
    const b = pngBefore[id], a = await imgOf(id);
    const r = await H(async ([b, a]) => {
      const load = (s) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = 'data:image/png;base64,' + s; });
      const [ib, ia] = await Promise.all([load(b), load(a)]);
      const W = ib.width, Hh = ib.height, cv = (i) => { const c = document.createElement('canvas'); c.width = W; c.height = Hh; const x = c.getContext('2d'); x.drawImage(i, 0, 0); return x.getImageData(0, 0, W, Hh).data; };
      const pb = cv(ib), pa = cv(ia);
      let x0 = W, y0 = Hh, x1 = -1, y1 = -1, n = 0;
      for (let y = 0; y < Hh; y++) for (let x = 0; x < W; x++) { const k = (y * W + x) * 4; if (Math.abs(pb[k] - pa[k]) + Math.abs(pb[k + 1] - pa[k + 1]) + Math.abs(pb[k + 2] - pa[k + 2]) > 24) { n++; if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
      return { W, H: Hh, same: ia.width === W && ia.height === Hh, n, box: x1 < 0 ? 0 : ((x1 - x0 + 1) * (y1 - y0 + 1)) / (W * Hh), rect: [x0, y0, x1, y1] };
    }, [b, a]);
    diffs.push(Object.assign({ id }, r));
  }
  check('only the price changed in each picture', diffs.every((d) => d.same && d.n > 0 && d.box < 0.12), diffs.map((d) => `${d.id.slice(-8)} ${(d.box * 100).toFixed(1)}% box`).join(' · '));
  if (SHOT) { // before | after of the 4:5, the owner's view
    const k = Object.keys(pngBefore).find((x) => /4x5$/.test(x) && after[x].lang === 'en') || Object.keys(pngBefore)[0];
    const b = pngBefore[k], a = await imgOf(k);
    const p2 = await ctx.newPage();
    await p2.setContent(`<body style="margin:0;background:#f4f2ee;font:600 15px system-ui"><div style="display:flex;gap:16px;padding:16px"><figure style="margin:0;flex:1"><figcaption>Before · regular price</figcaption><img src="data:image/png;base64,${b}" style="width:100%"></figure><figure style="margin:0;flex:1"><figcaption>After · sale price</figcaption><img src="data:image/png;base64,${a}" style="width:100%"></figure></div></body>`);
    for (const w of [1280, 390]) { await p2.setViewportSize({ width: w, height: 900 }); await sleep(200); await p2.screenshot({ path: path.join(SHOTS, `reprice-before-after-${w}.png`), fullPage: true }); }
    await p2.close();
  }

  // 6 ─ live ads swapped
  const st1 = (await api('/api/state')).j;
  const ads1 = adsOf(st1);
  const fresh = new Set(Object.values(after).map((c) => c.meta_creative_id).filter(Boolean));
  const oldNow = ads1.filter((a) => liveOld.some((o) => o.id === a.id));
  const newAds = ads1.filter((a) => fresh.has(a.creative_id) && a.status === 'ACTIVE');
  const jobs = ((await api('/api/jobs')).j.jobs || []).filter((j) => j.kind === 'reprice');
  if (process.env.RP_DEBUG) console.log(JSON.stringify(jobs.map((j) => ({ s: j.status, r: j.result, w: j.waiting, log: (j.log || []).map((l) => l.text) })), null, 1), JSON.stringify((await api('/api/proposals')).j.proposals.filter((p) => /reprice/.test(p.id)).map((p) => [p.id, p.status, p.result && (p.result.error || JSON.stringify(p.result.decision))]), null, 1));
  check('live ads: old paused, corrected one live in the same ad set', liveOld.length >= 1 && oldNow.every((a) => a.status === 'PAUSED') && newAds.length >= liveOld.length && liveOld.every((o) => newAds.some((n) => n.set === o.set)),
    `${liveOld.length} live before → ${oldNow.filter((a) => a.status === 'PAUSED').length} paused, ${newAds.length} new live · jobs ${jobs.map((j) => j.status).join(',')}`);

  // 7 ─ again finds nothing, no errors
  const again = await api('/api/reprice', 'POST', {});
  check('a second run finds nothing to redraw', again.s === 200 && again.j.items.length === 0, `${again.j.items.length} left · ${again.j.done.length} done`);
  check('no horizontal scroll, no console errors', sw <= 1280 && !errors.length, `scrollWidth ${sw} · ${errors.length} errors ${errors.slice(0, 3).join(' | ')}`);

  await browser.close();
  stop();
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} passed`);
  if (bad.length) console.log(engLog.slice(-2500));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
