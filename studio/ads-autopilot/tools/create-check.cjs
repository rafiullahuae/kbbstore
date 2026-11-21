#!/usr/bin/env node
// Create with Claude + the Claude-app connector, end to end on the REAL engine (-mock: fake Meta, fake Claude with
// scripted create answers, fake feed; never the real services). Drives the app in Chromium and a minimal MCP client
// over the engine's own `-mcp` stdio process:
//   1  a Practice cycle in "with me" mode makes a Creation request, pushed to the open Overview over SSE (no reload),
//      the Mac notification ran (osascript stubbed by KAA_NOTIFY_CMD), Start opens claude://claude.ai/new?q=<brief>
//   2  the box (C key): one sentence → 3 ideas rendered in 1:1 / 4:5 / 9:16, AR + EN, with a motion version
//   3  "make the headline shorter" changes ONLY the headlines; "idea 2: more pink" redraws ONLY idea 2
//   4  Run it (approve for the next cycle) + Launch now → a test ad set through the approve path, passes the executor
//      in Practice; the same launch again makes nothing new
//   5  history: the session is saved with what ran; Creatives → Made with Claude shows it
//   6  MCP: initialize → tools/list → get_brief → create_ad_set (progress + previews) → the same call again = the same
//      session → revise (only the headline) → save_to_library twice = the same ids → run_it
//   7  reliability: the tab is closed mid-render and reopened → the same tool call finishes once (kept until done)
//   8  Settings → Connections (Claude desktop card: install merges + backs up the config; the one key) and
//      Settings → Limits → Creation (With me = default, API fallback off)
//   9  no console errors, no failed requests
// --shots: shots/create-*.jpg at 1440×900 and 1280×800, EN + AR.
//   NODE_PATH=… node tools/create-check.cjs [--shots]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const SHOT = process.argv.includes('--shots');
const PORT = 43700 + Math.floor(Math.random() * 300);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-create-'));
  const dataDir = path.join(tmp, 'data');
  fs.mkdirSync(dataDir);
  const bin = path.join(tmp, 'engine');
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  // stubs: the Mac notification and `open` (the Claude deep link) write to files; Claude desktop's config is a temp file
  const notified = path.join(tmp, 'notified.txt'), opened = path.join(tmp, 'opened.txt'), cfg = path.join(tmp, 'Claude', 'claude_desktop_config.json');
  fs.writeFileSync(path.join(tmp, 'notify.sh'), `#!/bin/sh\necho "$1|$2" >> '${notified}'\n`, { mode: 0o700 });
  fs.writeFileSync(path.join(tmp, 'open.sh'), `#!/bin/sh\necho "$1" >> '${opened}'\n`, { mode: 0o700 });
  fs.mkdirSync(path.dirname(cfg));
  fs.writeFileSync(cfg, JSON.stringify({ mcpServers: { other: { command: '/usr/bin/other', args: [] } }, globalShortcut: 'Alt+Space' }, null, 2));
  const env = Object.assign({}, process.env, { KAA_NOTIFY_CMD: path.join(tmp, 'notify.sh'), KAA_OPEN_CMD: path.join(tmp, 'open.sh'), KAA_CLAUDE_CONFIG: cfg },
    fs.existsSync('/root/.ccr/ca-bundle.crt') ? { SSL_CERT_FILE: '/root/.ccr/ca-bundle.crt' } : {});
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
  await sleep(1500); // the mock links the account after connecting
  await api('/api/limits', 'PUT', { daily_aed: 300, monthly_aed: 9000, autopilot: 'shadow', countries: ['AE', 'SA'], max_active_ads: 14 });

  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const errors = [], failed = [];
  const watch = (page) => {
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('requestfailed', (r) => { const u = r.url(); if (!/\/api\/events|\/api\/render|^blob:|^data:|^claude:|\/api\/brain\/x\/create/.test(u)) failed.push(u + ' ' + (r.failure() || {}).errorText); });
    page.on('response', (r) => { if (r.status() >= 400 && !/seg\/mask/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
  };
  await ctx.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); localStorage.setItem('kaa.seg', 'off'); } catch {} });
  let page = await ctx.newPage();
  watch(page);
  await page.goto(base + '/app/#/overview');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
  const H = (fn, ...a) => page.evaluate(fn, ...a);
  const shot = async (name, w, h) => { if (!SHOT) return; await page.setViewportSize({ width: w, height: h }); await sleep(250); await page.screenshot({ path: path.join(SHOTS, `create-${name}-${w}.jpg`), quality: 82, type: 'jpeg' }); };
  const setLang = async (l) => { await H((x) => { const b = document.querySelector(`#lang [data-l="${x}"]`); if (b && !b.classList.contains('on')) b.click(); }, l); await sleep(300); };

  // 1 ─ the creation request, pushed live to the open Overview (once its event stream is up)
  await page.waitForFunction(() => window.__kaa.sseAt, null, { timeout: 10000 });
  const t0 = Date.now();
  const cyc = await api('/api/cycle', 'POST', { mode: 'shadow' });
  let cyc2 = null;
  let banner = await page.waitForSelector('[data-creation] .cc-req:not(.soon)', { timeout: 8000 }).then(() => true).catch(() => false);
  let tc = t0;
  if (!banner) { tc = Date.now(); cyc2 = await api('/api/cycle', 'POST', { mode: 'shadow' }); banner = await page.waitForSelector('[data-creation] .cc-req:not(.soon)', { timeout: 8000 }).then(() => true).catch(() => false); }
  const pushMs = Date.now() - tc; // from the cycle call that made the request (the first may be the starter set)
  const cv = (await api('/api/brain/x/creation')).j;
  const req = (cv.requests || [])[0];
  const notes = fs.existsSync(notified) ? fs.readFileSync(notified, 'utf8') : '';
  check('creation request (with-me mode, no API copy)', !!req && cv.mode === 'with_me' && cv.fallback_hours === 0, `${(cv.requests || []).length} request(s): ${req ? req.title : '—'} · cycle ${cyc.s}${cyc2 ? '/' + cyc2.s : ''}`);
  check('banner pushed over SSE (no reload)', banner, `banner on the open Overview ${pushMs} ms after the cycle call`);
  check('Mac notification ran (stub)', /New ads needed/.test(notes), JSON.stringify(notes.trim().split('\n')[0] || ''));
  await shot('banner-en', 1440, 900); await shot('banner-en', 1280, 800);
  await setLang('ar'); await shot('banner-ar', 1440, 900); await shot('banner-ar', 1280, 800); await setLang('en');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.click('[data-cstart]');
  await page.waitForSelector('#dlg .cc-brief', { timeout: 5000 });
  await sleep(300);
  const op = fs.existsSync(opened) ? fs.readFileSync(opened, 'utf8').trim() : '';
  const q = op ? new URL(op).searchParams.get('q') || '' : '';
  check('Start opens Claude with the brief prefilled', op.startsWith('claude://claude.ai/new?q=') && q.includes(req.id) && q.includes('get_brief'), op.slice(0, 70) + '…');
  await shot('start-en', 1440, 900);
  await H(() => document.getElementById('dlg').close());

  // 2 ─ the box: C key, one sentence
  await page.keyboard.press('c');
  await page.waitForSelector('dialog.cc[open] #cc-in');
  await shot('box-empty-en', 1440, 900);
  await page.fill('#cc-in', '3 ads for sunscreen for oily skin');
  const tBox = Date.now();
  await page.click('#cc-go');
  await page.waitForFunction(() => window.__kaaCreate.session && document.querySelectorAll('dialog.cc .cc-slot img').length >= 3 && !document.querySelector('dialog.cc .cc-status .cc-spin'), null, { timeout: 240000 });
  const boxMs = Date.now() - tBox;
  const s1 = await H(() => JSON.parse(JSON.stringify(window.__kaaCreate.session)));
  const formats = new Set(s1.designs.flatMap((d) => d.formats)), langs = new Set(s1.designs.map((d) => d.lang));
  const arDrawn = await H(() => [...window.__kaaCreate.drawn.keys()].length);
  const vids = await H(() => [...window.__kaaCreate.videos.values()].map((v) => v.bytes));
  check('prompt → set (3 ideas, 3 formats, AR + EN)', s1.ideas.length === 3 && formats.size === 3 && langs.has('ar') && langs.has('en') && arDrawn >= 9, `${s1.ideas.length} ideas · ${s1.designs.length} designs · formats ${[...formats].join(' ')} · ${arDrawn} drawn · ${boxMs} ms`);
  check('motion version made', vids.length >= 1 && vids.every((b) => b > 10000), `${vids.length} MP4 · ${vids.map((b) => (b / 1e6).toFixed(2) + ' MB').join(', ')}`);
  check('product picked from the ask', s1.ideas.every((i) => i.product_ids[0] === '4410'), s1.ideas.map((i) => i.product_ids.join()).join(' / '));
  await shot('box-ar-view-en', 1440, 900); await shot('box-ar-view-en', 1280, 800);
  await H(() => document.querySelector('#cc-lang [data-v="en"]').click());
  await page.waitForFunction(() => [...window.__kaaCreate.drawn.keys()].length >= 18 && !document.querySelector('dialog.cc .cc-status .cc-spin'), null, { timeout: 240000 });
  await shot('box-en', 1440, 900); await shot('box-en', 1280, 800);

  // 3 ─ follow-ups change only what was asked
  const edit = async (text) => {
    const before = await H(() => window.__kaaCreate.session.turns.length);
    await page.fill('#cc-in', text); await page.click('#cc-go');
    await page.waitForFunction((n) => window.__kaaCreate.session.turns.length > n && !window.__kaaCreate.busy && !document.querySelector('dialog.cc .cc-status .cc-spin'), before, { timeout: 240000 });
    return H(() => ({ s: JSON.parse(JSON.stringify(window.__kaaCreate.session)), last: window.__kaaCreate.stats.last.slice() }));
  };
  const reopen = async () => { await H(() => { const d = document.getElementById('cc'); if (d && d.open) d.close(); }); await page.click('#cc-top'); await page.waitForSelector('dialog.cc[open]'); };
  const strip = (d, k) => { const x = Object.assign({}, d); for (const f of k) delete x[f]; return JSON.stringify(x); };
  const e1 = await edit('make the headline shorter');
  const hlOnly = e1.s.designs.every((d, i) => d.headline !== s1.designs[i].headline && strip(d, ['headline']) === strip(s1.designs[i], ['headline']));
  check('edit "headline shorter" changes only the headlines', hlOnly && JSON.stringify(e1.s.turns.at(-1).changed) === '["headline"]', `changed ${JSON.stringify(e1.s.turns.at(-1).changed)} · ${e1.s.designs.filter((d, i) => d.headline !== s1.designs[i].headline).length}/${e1.s.designs.length} headlines moved, nothing else`);
  const e2 = await edit('idea 2: more pink');
  const moved = e2.s.designs.filter((d, i) => JSON.stringify(d) !== JSON.stringify(e1.s.designs[i]));
  check('edit "idea 2: more pink" redraws only idea 2', moved.length > 0 && moved.every((d) => d.idea === 2 && d.palette && d.palette.length) && e2.last.every((k) => k.includes('-2-')), `${moved.length} designs changed (idea ${[...new Set(moved.map((d) => d.idea))].join()}); redrawn: ${e2.last.length} slot(s)`);
  await shot('box-edit-en', 1440, 900);
  await setLang('ar');
  await H(() => { window.__kaaCreate.view = 'ar'; });
  await reopen();
  await page.waitForFunction(() => document.querySelectorAll('dialog.cc .cc-slot img').length >= 3, null, { timeout: 60000 });
  await sleep(500);
  await shot('box-ar', 1440, 900); await shot('box-ar', 1280, 800);
  await setLang('en');
  await reopen();
  await page.waitForSelector('dialog.cc[open] #cc-run');

  // 4 ─ Run it, then Launch now (approve path, Practice mode)
  await page.click('#cc-run');
  await page.waitForFunction(() => window.__kaaLaunch, null, { timeout: 180000 });
  const lib = (await api('/api/creatives')).j;
  const approved = lib.filter((c) => c.approved);
  check('Run it → approved creatives for the next cycle', approved.length >= 2 && approved.every((c) => c.source === 'owner'), `${approved.length} approved of ${lib.length} in the library`);
  await H(() => { window.__kaaLaunch = null; });
  await page.click('#cc-launch');
  await page.waitForSelector('#dlg[open] button[value="yes"]'); await page.click('#dlg button[value="yes"]');
  await page.waitForFunction(() => window.__kaaLaunch, null, { timeout: 180000 });
  const L = await H(() => window.__kaaLaunch);
  const pend = (await api('/api/proposals')).j.proposals.filter((p) => p.id.startsWith('claude-'));
  const why = [...new Set(pend.filter((p) => p.status !== 'done').map((p) => `${p.kind}: ${(p.result && (p.result.error || (p.result.decision && p.result.decision.reason))) || p.status}`))].slice(0, 4);
  check('Launch now passes the executor (Practice)', (L.counts.done || 0) >= 4 && !L.counts.refused && !L.counts.failed && pend.every((p) => p.status === 'done'), `counts ${JSON.stringify(L.counts)} · ${pend.length} proposals ${[...new Set(pend.map((p) => p.status))].join()}${why.length ? ' · ' + why.join(' | ') : ''}`);
  const again = await api('/api/launch', 'POST', { creative_ids: L.ids, launch: true });
  const pend2 = (await api('/api/proposals')).j.proposals.filter((p) => p.id.startsWith('claude-'));
  check('the same launch again makes nothing new', again.s === 200 && pend2.length === pend.length && (again.j.again || again.j.counts), `HTTP ${again.s} · proposals ${pend.length} → ${pend2.length}`);
  await shot('box-launched-en', 1440, 900);

  // 5 ─ history
  const hist = (await api('/api/brain/x/create')).j;
  const h0 = hist.find((s) => s.id === s1.id);
  check('history saved (prompt, turns, what ran)', h0 && h0.turns.length === 3 && h0.saved.length >= 6 && h0.ran.length >= 2 && h0.proposals.length >= 4, h0 ? `${h0.turns.length} turns · ${h0.saved.length} saved · ${h0.ran.length} ran · ${h0.proposals.length} proposals · outcome ${h0.outcome && h0.outcome.verdict}` : 'missing');
  await page.keyboard.press('Escape');
  await page.goto(base + '/app/#/creatives');
  await page.waitForSelector('[data-tab="claude"]');
  await page.click('[data-tab="claude"]');
  await page.waitForSelector('.cc-hist [data-sess]', { timeout: 10000 });
  check('Creatives → Made with Claude lists it', await H((id) => !!document.querySelector(`[data-sess="${id}"]`), s1.id), 'card shown');
  await shot('history-en', 1440, 900); await shot('history-en', 1280, 800);
  await setLang('ar'); await shot('history-ar', 1440, 900); await setLang('en');

  // 6 ─ MCP over the engine's own stdio process
  mcp = spawn(bin, ['-mcp', '-data', dataDir], { stdio: ['pipe', 'pipe', 'pipe'] });
  let buf = '';
  const waiting = new Map(), progress = [];
  mcp.stdout.on('data', (d) => {
    buf += d;
    let i;
    while ((i = buf.indexOf('\n')) >= 0) {
      const ln = buf.slice(0, i); buf = buf.slice(i + 1);
      if (!ln.trim()) continue;
      const m = JSON.parse(ln);
      if (m.method === 'notifications/progress') progress.push(m.params);
      else if (waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); }
    }
  });
  let rid = 0;
  const rpc = (method, params) => new Promise((ok) => { const id = ++rid; waiting.set(id, ok); mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n'); });
  const tool = async (name, args, token) => { const t = Date.now(); const r = await rpc('tools/call', { name, arguments: args, _meta: token ? { progressToken: token } : undefined }); r.ms = Date.now() - t; return r; };
  const textOf = (r) => { try { return JSON.parse(r.result.content[0].text); } catch { return r.result ? r.result.content[0].text : r.error; } };
  const init = await rpc('initialize', { protocolVersion: '2025-11-25', capabilities: {}, clientInfo: { name: 'create-check', version: '1' } });
  mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
  const tl = await rpc('tools/list', {});
  const names = tl.result.tools.map((t) => t.name);
  check('MCP initialize + tools/list', init.result.protocolVersion === '2025-11-25' && ['get_brief', 'list_products', 'get_research', 'create_ad_set', 'revise', 'save_to_library', 'run_it'].every((n) => names.includes(n)), `${init.result.serverInfo.name} · ${names.join(', ')}`);
  const brief = await tool('get_brief', {});
  const bj = textOf(brief);
  check('get_brief', !brief.result.isError && bj.brand_rules && bj.products_to_push.length >= 1 && Array.isArray(bj.open_requests), `${bj.products_to_push.length} products · ${(bj.open_requests || []).length} open request(s) · ${brief.ms} ms`);
  const prods = textOf(await tool('list_products', { query: 'cleanser' }));
  const idea = (h) => ({ product_ids: ['3195'], angle: 'gentle low-pH morning cleanse', hook: 'Calm mornings start here', template: 'ingredient', formats: ['1:1', '4:5', '9:16'], show_price: true,
    ar: { headline: h ? 'تهدئة لطيفة' : 'صباحكِ يبدأ بلطف وهدوء', primary_text: 'غسول COSRX الصباحي بدرجة حموضة منخفضة، خطوة يومية لطيفة. أصلي من كوريا، توصيل داخل الإمارات.', sub: 'غسول الصباح اللطيف', bullets: ['حموضة منخفضة', 'شجرة الشاي'], badge: 'COSRX', cta: 'SHOP_NOW' },
    en: { headline: h ? 'Gentle calm' : 'A calm, gentle morning start', primary_text: 'COSRX Low pH Good Morning cleanser, a gentle daily step. Authentic from Korea, delivered across the UAE.', sub: 'Low pH gel cleanser', bullets: ['Low pH', 'Tea tree'], badge: 'COSRX', cta: 'SHOP_NOW' } });
  const args = { ideas: [idea(false)], request_id: bj.open_requests[0] ? bj.open_requests[0].id : '', prompt: 'from the Claude app' };
  progress.length = 0;
  const c1 = await tool('create_ad_set', args, 'p1');
  const c1j = textOf(c1), imgs = (c1.result.content || []).filter((x) => x.type === 'image');
  if (c1.result.isError) console.log('   create_ad_set said:', c1.result.content[0].text);
  check('create_ad_set → previews (rendered by the app)', !c1.result.isError && c1j.session_id && imgs.length === 2 && progress.some((p) => p.progressToken === 'p1'), `${c1j.session_id} · ${imgs.length} images (${imgs.map((i) => Math.round(i.data.length * 0.75 / 1024) + ' KB').join(', ')}) · ${progress.length} progress notes · ${c1.ms} ms`);
  const c2 = await tool('create_ad_set', args);
  const sessAfter = (await api('/api/brain/x/create')).j.filter((s) => s.source === 'claude_app');
  check('the same call again = the same session (idempotent)', textOf(c2).session_id === c1j.session_id && sessAfter.length === 1, `${textOf(c2).session_id} · ${sessAfter.length} Claude-app session(s) · ${c2.ms} ms`);
  const rq = (await api('/api/brain/x/creation')).j.requests.find((r) => r.id === args.request_id);
  check('the creation request is marked done by it', !args.request_id || (rq && rq.status === 'done' && rq.session_id === c1j.session_id), rq ? `${rq.id} → ${rq.status}` : 'none');
  const rv = await tool('revise', { session_id: c1j.session_id, changes: { ar: { headline: 'تهدئة لطيفة' }, en: { headline: 'Gentle calm' } }, instruction: 'shorter headline' });
  const rvj = textOf(rv);
  const sB = (await api('/api/brain/x/create')).j.find((s) => s.id === c1j.session_id);
  check('revise changes only the headline', JSON.stringify(rvj.changed) === '["headline"]' && sB.designs.every((d) => /Gentle calm|تهدئة لطيفة/.test(d.headline)) && sB.ideas[0].template === 'ingredient' && sB.ideas[0].show_price === true, `changed ${JSON.stringify(rvj.changed)} · ${(rv.result.content || []).filter((x) => x.type === 'image').length} new previews · ${rv.ms} ms`);
  const sv1 = textOf(await tool('save_to_library', { session_id: c1j.session_id }));
  const sv2 = textOf(await tool('save_to_library', { session_id: c1j.session_id }));
  const libN = (await api('/api/creatives')).j.length;
  // 21 Nov, the owner's Claude desktop: save_to_library failed 3x with "send session_id" although Claude said it sent
  // the id. The save must work whatever the argument is called, and with no id at all (= the session just made).
  const sv3 = textOf(await tool('save_to_library', { sessionId: c1j.session_id }));
  const sv4 = textOf(await tool('save_to_library', {}));
  check('save_to_library with sessionId / with no id saves the same session', JSON.stringify(sv3.saved_creative_ids) === JSON.stringify(sv1.saved_creative_ids) && JSON.stringify(sv4.saved_creative_ids) === JSON.stringify(sv1.saved_creative_ids), `${(sv3.saved_creative_ids || []).length} / ${(sv4.saved_creative_ids || []).length}`);
  check('save_to_library twice = the same creatives', sv1.saved_creative_ids.length >= 4 && JSON.stringify(sv1.saved_creative_ids) === JSON.stringify(sv2.saved_creative_ids), `${sv1.saved_creative_ids.length} saved · library ${libN}`);
  const ri = await tool('run_it', { session_id: c1j.session_id, launch_now: true });
  const rij = textOf(ri);
  check('run_it → executor (Practice), inside the limits', !ri.result.isError && (rij.counts.done || 0) >= 3 && !rij.counts.refused, `counts ${JSON.stringify(rij.counts)} · ${ri.ms} ms`);
  const ri2 = textOf(await tool('run_it', { session_id: c1j.session_id, launch_now: true }));
  check('run_it again launches nothing new', ri2.again === true, `again=${ri2.again}`);
  await api('/api/limits', 'PUT', { max_active_ads: 1 });
  const full = await api('/api/launch', 'POST', { creative_ids: sv1.saved_creative_ids.filter((x) => /-1x1$/.test(x)), launch: true });
  await api('/api/limits', 'PUT', { max_active_ads: 14 });
  check('a launch above max active ads is refused up front, clearly', full.s === 409 && /no free ad slot/.test(full.j.error), `HTTP ${full.s}: ${full.j && full.j.error}`);
  check('Products tool', Array.isArray(prods) && prods.length === 2 && prods.map((p) => p.id).sort().join() === '3195,7301', `cleanser → ${prods.map((p) => p.id).join()} (the mock feed has two cleansers since 20 Nov)`);

  // 7 ─ reliability: close the tab mid-render, reopen, the same call finishes once
  const args2 = { ideas: [Object.assign(idea(false), { angle: 'a calm low-pH routine', template: 'glow' })] };
  progress.length = 0;
  const pending = tool('create_ad_set', args2, 'p2');
  await page.goto(base + '/app/#/overview');
  for (let i = 0; i < 100 && !progress.some((p) => p.progressToken === 'p2' && p.progress >= 1); i++) await sleep(50);
  await page.close(); // the tab dies mid-render
  await sleep(800);
  page = await ctx.newPage(); watch(page);
  await page.goto(base + '/app/#/overview');
  const r2 = await pending;
  const r2j = textOf(r2);
  const r3 = await tool('create_ad_set', args2);
  const nSess = (await api('/api/brain/x/create')).j.filter((s) => s.source === 'claude_app').length;
  check('tab closed mid-render → reopened → finished once', !r2.result.isError && (r2.result.content || []).some((x) => x.type === 'image') && textOf(r3).session_id === r2j.session_id && nSess === 2, `${r2j.session_id || r2.result.content[0].text} · ${r2.ms} ms · sessions ${nSess}`);
  const noTab = await (async () => { const pg = page; await pg.close(); await sleep(500); const r = await tool('revise', { session_id: r2j.session_id, changes: { show_price: false } }); return r; })();
  check('no tab open → a clear "open the app" answer', noTab.result.isError === true && /Open KBB Ads Autopilot/.test(noTab.result.content[0].text), noTab.result.content[0].text.slice(0, 90));
  page = await ctx.newPage(); watch(page);
  await page.goto(base + '/app/#/settings');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });

  // 8 ─ Settings
  await page.click('[data-tab="connections"]');
  await page.waitForSelector('[data-mcp] #mcp-install');
  await page.click('#mcp-install');
  await page.waitForFunction(() => /Connected|Installed|متصل|مثبّت/.test(document.querySelector('[data-mcp]').textContent) && !/Not installed/.test(document.querySelector('[data-mcp]').textContent), null, { timeout: 5000 });
  const conf = JSON.parse(fs.readFileSync(cfg, 'utf8'));
  const backups = fs.readdirSync(path.dirname(cfg)).filter((f) => f.includes('.bak-'));
  const st = (await api('/api/mcp')).j;
  check('Install connector merges + backs up the config', conf.mcpServers.other && conf.globalShortcut === 'Alt+Space' && conf.mcpServers['kbb-ads-autopilot'].args[0] === '-mcp' && backups.length === 1 && st.connected, `servers: ${Object.keys(conf.mcpServers).join(', ')} · backup ${backups[0]} · connected ${st.connected} (${st.calls} calls)`);
  await page.waitForSelector('[data-onekey]');
  await sleep(4500); await H(() => document.querySelector('[data-mcp]').scrollIntoView({ block: 'start' }));
  await shot('settings-connections-en', 1440, 900); await shot('settings-connections-en', 1280, 800);
  await setLang('ar'); await page.click('[data-tab="connections"]'); await page.waitForSelector('[data-onekey]'); await page.waitForSelector('[data-mcp]');
  await H(() => document.querySelector('[data-mcp]').scrollIntoView({ block: 'start' }));
  await shot('settings-connections-ar', 1440, 900); await shot('settings-connections-ar', 1280, 800); await setLang('en');
  await page.click('[data-tab="limits"]');
  await page.waitForSelector('[data-creation-mode]');
  const modeUI = await H(() => ({ withMe: document.querySelector('[data-creation-mode] input[value="with_me"]').checked, fb: document.querySelector('#cm-fb').checked }));
  check('Creation card: With me (default), API fallback off', modeUI.withMe && !modeUI.fb, JSON.stringify(modeUI));
  await H(() => document.querySelector('[data-creation-mode]').scrollIntoView());
  await shot('settings-creation-en', 1440, 900);
  await setLang('ar'); await page.click('[data-tab="limits"]'); await page.waitForSelector('[data-creation-mode]'); await H(() => document.querySelector('[data-creation-mode]').scrollIntoView());
  await shot('settings-creation-ar', 1440, 900); await setLang('en');

  // 9 ─ clean
  check('no console errors', errors.length === 0, errors.slice(0, 3).join(' | ') || '0');
  check('no failed requests', failed.length === 0, failed.slice(0, 3).join(' | ') || '0');
  await browser.close();
  const bad = results.filter((r) => !r.ok).length;
  console.log(`\n${results.length - bad}/${results.length} passed`);
  if (bad) console.log(engLog.split('\n').filter((l) => /error|panic/i.test(l)).slice(-8).join('\n'));
  stop();
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(3); });
