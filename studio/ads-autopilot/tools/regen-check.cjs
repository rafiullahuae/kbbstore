#!/usr/bin/env node
// The owner, 21 Nov: "give me option to re-generate everything for specific idea, or complete [set] ... few ads are
// now missing as i didn't want to change the design. also give facility to edit the products if any product i don't
// need in the creatives set" + "in approvals also i need re-schedule or re-generate, which reset and ready for
// re-generation" + "generate should give options, the ad copy, the creatives or complete".
// On the REAL engine (-mock, KAA_MOCK_SHOP_API=1), driven in Chromium:
//   Create window
//     1  each idea shows its products; ✕ takes one out of a 2-product idea: ONLY that idea's designs change, the
//        words stay, every other idea is byte-identical
//     2  "+ Add or swap" puts a product in (from the shop list)
//     3  "↻ Redraw & save" on one idea: every size of both languages (+ its video) saved, the SAME design
//     4  "↻ Redraw all & save": every idea; the library holds every size of every design (nothing missing)
//   Approvals → Occasions (Winter package)
//     5  Regenerate → Ad copy: new words on its ads with today's price, same ids, back to "waiting for approval"
//     6  Regenerate → Creatives: a creation request for Claude (Max); once Claude's set is saved, its designed 4:5
//        pictures REPLACE the package's ads (shown with their pictures)
//     7  Reschedule: the go-live day moves
//     8  no console errors; screenshots 1280 / 390
//   NODE_PATH=… node tools/regen-check.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const SHOT = process.argv.includes('--shots');
const PORT = 45300 + Math.floor(Math.random() * 300);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-regen-'));
  const dataDir = path.join(tmp, 'data');
  fs.mkdirSync(dataDir);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: '/bin/true', KAA_OPEN_CMD: '/bin/true', KAA_CLAUDE_CONFIG: path.join(tmp, 'cfg.json'), KAA_MOCK_SHOP_API: '1' });
  delete env.KAA_BRAIN;
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
  for (let i = 0; i < 60; i++) { const c = (await api('/api/connections')).j; if (c && c.meta && c.meta.ok && c.shop && c.shop.ok) break; await sleep(250); }
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'], max_active_ads: 14 });
  await api('/api/cycle', 'POST', { mode: 'shadow' }); // the Winter package is prepared

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

  mcp = spawn(bin, ['-mcp', '-data', dataDir], { stdio: ['pipe', 'pipe', 'pipe'] });
  let buf = '';
  const waiting = new Map();
  mcp.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const ln = buf.slice(0, i); buf = buf.slice(i + 1); if (!ln.trim()) continue; const m = JSON.parse(ln); if (waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } } });
  let rid = 0;
  const rpc = (method, params) => new Promise((ok) => { const id = ++rid; waiting.set(id, ok); mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n'); });
  const tool = (name, args) => rpc('tools/call', { name, arguments: args });
  const textOf = (r) => { try { return JSON.parse(r.result.content[0].text); } catch { return r.result ? r.result.content[0].text : r.error; } };
  await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'regen-check', version: '1' } });
  mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');

  const prods = (await api('/api/brain/x/products')).j;
  const [pA, pB, pC] = prods;
  const copy = (h) => ({ ar: { headline: 'ترطيب ونضارة ' + h, primary_text: 'عناية كورية أصلية بتوصيل داخل الإمارات.', sub: 'ترطيب', bullets: ['ترطيب', 'نضارة'], badge: 'K-Beauty', cta: 'SHOP_NOW' },
    en: { headline: 'Glow and hydration ' + h, primary_text: 'Authentic Korean skincare, delivered across the UAE.', sub: 'Hydration', bullets: ['Hydration', 'Glow'], badge: 'K-Beauty', cta: 'SHOP_NOW' } });
  const ideas = [Object.assign({ product_ids: [pA.id, pB.id], angle: 'a two-step routine', hook: 'Two steps', template: 'routine', formats: ['1:1', '4:5', '9:16'], show_price: true, motion: true }, copy('1')),
    Object.assign({ product_ids: [pC.id], angle: 'one hero', hook: 'One hero', template: 'glow', formats: ['1:1', '4:5', '9:16'], show_price: true }, copy('2'))];
  const c1 = textOf(await tool('create_ad_set', { ideas, prompt: 'two ideas' }));
  let s1 = (await api('/api/brain/x/create')).j.find((s) => s.id === c1.session_id);

  // 1 ─ products row; ✕ one product out of idea 1
  await page.goto(base + '/app/#/creatives'); await page.waitForSelector('[data-tab="claude"]'); await page.click('[data-tab="claude"]');
  await page.waitForSelector(`[data-sess="${s1.id}"]`, { timeout: 15000 }); await page.click(`[data-sess="${s1.id}"]`);
  await page.waitForSelector('dialog.cc[open] [data-prods="1"]', { timeout: 15000 });
  await page.waitForFunction(() => document.querySelectorAll('dialog.cc .cc-slot img').length >= 2, null, { timeout: 120000 });
  await page.waitForFunction((t) => document.querySelector('[data-prods="1"]').textContent.includes(t.slice(0, 20)), pA.title, { timeout: 15000 });
  const row1 = await H(() => document.querySelector('[data-prods="1"]').innerText);
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(400); await page.screenshot({ path: path.join(SHOTS, `regen-box-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });
  const before = JSON.stringify(s1.designs.filter((d) => d.idea === 2));
  await page.click(`[data-rmprod="1|${pB.id}"]`);
  await page.waitForFunction((id) => window.__kaaCreate.session.designs.filter((d) => d.idea === 1).every((d) => d.product_ids.length === 1 && d.product_ids[0] === id), pA.id, { timeout: 15000 });
  let s2 = (await api('/api/brain/x/create')).j.find((s) => s.id === s1.id);
  const i1 = s2.designs.filter((d) => d.idea === 1), i1old = s1.designs.filter((d) => d.idea === 1);
  check('✕ takes a product out: only that idea changes, the words stay', JSON.stringify(s2.designs.filter((d) => d.idea === 2)) === before && i1.every((d, k) => d.headline === i1old[k].headline && d.primary_text === i1old[k].primary_text && d.images.length === 1) && s2.ideas[0].product_ids.join() === pA.id,
    `row “${row1.replace(/\n/g, ' ').slice(0, 90)}” · idea 1 now ${i1[0].product_ids.join()} (${i1[0].images.length} photo) · idea 2 byte-identical`);

  // 2 ─ + Add a product back from the list
  await H(([t]) => { const inp = document.querySelector('[data-addprod="1"]'); inp.value = t; inp.dispatchEvent(new Event('change')); }, [pC.title]);
  await page.waitForFunction((id) => window.__kaaCreate.session.designs.filter((d) => d.idea === 1).every((d) => d.product_ids.includes(id)), pC.id, { timeout: 15000 });
  s2 = (await api('/api/brain/x/create')).j.find((s) => s.id === s1.id);
  check('+ Add puts a product in', s2.ideas[0].product_ids.join() === [pA.id, pC.id].join(), `idea 1 → ${s2.ideas[0].product_ids.join(', ')}`);

  // 3 ─ Redraw & save one idea
  await page.waitForFunction(() => !document.querySelector('dialog.cc .cc-status .cc-spin'), null, { timeout: 120000 }).catch(() => {});
  await H(() => { window.__kaaRedraw = null; });
  await page.click('[data-redraw="1"]');
  await page.waitForFunction(() => window.__kaaRedraw, null, { timeout: 240000 });
  const r1 = await H(() => window.__kaaRedraw);
  const lib1 = (await api('/api/creatives')).j;
  const ids1 = (r1.ids || []);
  check('↻ Redraw & save (idea 1): every size of both languages + the video, saved', !r1.error && ids1.filter((x) => !/-mp4$/.test(x)).length === 6 && r1.videos >= 1 && ids1.every((id) => lib1.some((c) => c.id === id && c.price && c.layout === 2)),
    `${ids1.length} saved (${r1.videos} video), ${r1.weak.length} below the quality check and kept · ${r1.error || ''}`);

  // 4 ─ Redraw all & save: nothing missing
  await H(() => { window.__kaaRedraw = null; });
  await page.click('#cc-redraw');
  await page.waitForFunction(() => window.__kaaRedraw, null, { timeout: 300000 });
  const r2 = await H(() => window.__kaaRedraw);
  s2 = (await api('/api/brain/x/create')).j.find((s) => s.id === s1.id);
  const lib2 = (await api('/api/creatives')).j.map((c) => c.id);
  const want = s2.designs.length * 3;
  check('↻ Redraw all & save: every size of every design is in the library', !r2.error && r2.ids.filter((x) => !/-mp4$/.test(x)).length === want && r2.ids.every((id) => lib2.includes(id)), `${r2.ids.length} saved for ${s2.designs.length} designs × 3 sizes (+ ${r2.videos} video)`);
  await H(() => { const d = document.querySelector('dialog.cc[open]'); if (d) d.close(); });

  // 5 ─ Approvals → Occasions → Winter: Regenerate → Ad copy
  const occ = async () => (await api('/api/occasions')).j;
  let o = await occ();
  const W = (o.packages || []).find((j) => j.occasion && j.occasion.occasion === 'winter');
  if (!W) { check('winter package', false, 'none'); process.exit(1); }
  await api(`/api/jobs/${W.id}/approve`, 'POST', {}); // the owner had approved it (his screenshot)
  const adsBefore = ((await occ()).ads || {})[W.id] || [];
  await page.goto(base + '/app/#/approvals');
  await page.waitForSelector(`[data-occregen="${W.id}"]`, { timeout: 20000 });
  if (SHOT) for (const w of [1280, 390]) { await page.setViewportSize({ width: w, height: 900 }); await sleep(400); const el = await page.$(`[data-occregen-box="${W.id}"]`); await el.scrollIntoViewIfNeeded(); await page.screenshot({ path: path.join(SHOTS, `regen-approvals-${w}.png`) }); }
  await page.setViewportSize({ width: 1280, height: 900 });
  const sw = await H(() => document.documentElement.scrollWidth);
  await page.selectOption(`[data-occregen-what="${W.id}"]`, 'copy');
  await page.click(`[data-occregen="${W.id}"]`);
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((x) => /New ad copy/.test(x.innerText)), null, { timeout: 60000 });
  o = await occ();
  const W2 = o.packages.find((j) => j.id === W.id);
  const adsAfter = (o.ads || {})[W.id] || [];
  check('Regenerate → Ad copy: new words, same ads, waiting for approval again', W2.status === 'planned' && adsAfter.length === adsBefore.length && adsAfter.every((a, k) => a.id === adsBefore[k].id) && (W2.log || []).some((l) => /new ad copy for \d+ ad/.test(l.text)),
    `status approved → ${W2.status} · ${adsAfter.length} ads, same ids · ${(W2.log || []).slice(-2).map((l) => l.text).join(' | ')}`);

  // 6 ─ Regenerate → Creatives: Claude's set replaces the package's ads
  await page.selectOption(`[data-occregen-what="${W.id}"]`, 'creatives');
  await page.click(`[data-occregen="${W.id}"]`);
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((x) => /New designs are being made/.test(x.innerText)), null, { timeout: 60000 });
  o = await occ();
  const W3 = o.packages.find((j) => j.id === W.id);
  const reqs = (await api('/api/brain/x/creation')).j.requests || [];
  const rq = reqs.find((r) => r.id === W3.creation_req);
  check('Regenerate → Creatives: a creation request for Claude (Max)', !!rq && rq.status === 'open' && rq.product_ids.join() === W3.product_ids.join(), rq ? `${rq.id} · ${rq.title} · ${rq.product_ids.length} products` : JSON.stringify(W3).slice(0, 200));
  // Claude (Claude Code on the Mac / the Claude app) answers that request and saves
  const pw = W3.product_ids[0];
  const ci = textOf(await tool('create_ad_set', { request_id: rq.id, ideas: [Object.assign({ product_ids: [pw], angle: 'winter barrier care', hook: 'Winter skin', template: 'glow', formats: ['1:1', '4:5', '9:16'], show_price: true }, copy('W'))] }));
  const sv = textOf(await tool('save_to_library', { session_id: ci.session_id }));
  o = await occ();
  const W4 = o.packages.find((j) => j.id === W.id);
  const ads4 = (o.ads || {})[W.id] || [];
  await page.goto(base + '/app/#/approvals'); await page.waitForSelector(`[data-pkg="${W.id}"]`);
  await sleep(1500);
  const shown = await H((id) => [...document.querySelectorAll(`[data-pkg="${id}"] img`)].filter((i) => /^blob:/.test(i.src) && i.naturalWidth > 0).length, W.id);
  check('…once saved, the designed pictures replace the package ads (shown)', !W4.creation_req && ads4.length >= 2 && ads4.every((a) => a.media === 'png' && /-4x5$/.test(a.id)) && ads4.every((a) => (sv.saved_creative_ids || []).includes(a.id)) && shown >= ads4.length,
    `${ads4.length} designed ads in the package (${ads4.map((a) => a.id.slice(-12)).join(', ')}) · ${shown} pictures shown`);
  if (SHOT) { const el = await page.$(`[data-pkg="${W.id}"]`); await el.scrollIntoViewIfNeeded(); await page.screenshot({ path: path.join(SHOTS, 'regen-approvals-designed-1280.png') }); }

  // 7 ─ Reschedule
  const day = new Date(Date.now() + 9 * 86400e3);
  const ds = `${day.getFullYear()}-${String(day.getMonth() + 1).padStart(2, '0')}-${String(day.getDate()).padStart(2, '0')}`;
  await page.fill(`[data-occwhen="${W.id}"]`, ds);
  await page.click(`[data-occresched="${W.id}"]`);
  await page.waitForFunction(() => [...document.querySelectorAll('.toast')].some((x) => /Rescheduled/.test(x.innerText)), null, { timeout: 30000 });
  const W5 = (await occ()).packages.find((j) => j.id === W.id);
  const got = new Date(W5.when);
  check('Reschedule moves the go-live day', got.getDate() === day.getDate() && got.getMonth() === day.getMonth(), `${ds} → ${W5.when}`);

  check('no horizontal scroll, no console errors', sw <= 1280 && !errors.length, `scrollWidth ${sw} · ${errors.length} ${errors.slice(0, 3).join(' | ')}`);
  await browser.close();
  stop();
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} passed`);
  if (bad.length) console.log(engLog.slice(-2000));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
