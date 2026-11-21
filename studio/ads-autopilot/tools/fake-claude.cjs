#!/usr/bin/env node
// A stand-in for the `claude` CLI, for tools/claudecode-ui.cjs (KAA_CLAUDE_BIN points the engine at it; a -mock
// engine never runs a real CLI). It answers `--version` and `auth status` like Claude Code does, and for a
// `claude -p … --mcp-config <file>` run it does what Claude would: starts the connector named in the config (the REAL
// `engine -mcp`, with the config's env), and calls get_brief → create_ad_set (the request_id from the prompt) →
// save_to_library over MCP stdio, so the app's tab draws and the quality gate runs for real. Then it prints the
// headless JSON result. FAKE_CC_HOLD_MS: wait that long after get_brief (so a screenshot catches the live card).
// FAKE_CC_LOG: a file the run's argv and environment are appended to (the tool checks no API key reached it).
'use strict';
const { spawn } = require('child_process');
const fs = require('fs');
const a = process.argv.slice(2);
const out = (o) => process.stdout.write(JSON.stringify(o) + '\n');
if (a[0] === '--version') { console.log('2.1.330 (Claude Code)'); process.exit(0); }
if (a[0] === 'auth') { out({ loggedIn: true, authMethod: 'claude.ai' }); process.exit(0); }
const flag = (f) => { const i = a.indexOf(f); return i >= 0 ? a[i + 1] : undefined; };
const prompt = flag('-p') || '';
if (process.env.FAKE_CC_LOG) fs.appendFileSync(process.env.FAKE_CC_LOG, JSON.stringify({ argv: a, env: Object.keys(process.env) }) + '\n');
if (!flag('--mcp-config')) { out({ type: 'result', subtype: 'success', is_error: false, result: 'ok', num_turns: 1 }); process.exit(0); }
const cfg = JSON.parse(fs.readFileSync(flag('--mcp-config'), 'utf8'));
const srv = Object.values(cfg.mcpServers)[0];
const mcp = spawn(srv.command, srv.args, { env: Object.assign({}, process.env, srv.env || {}), stdio: ['pipe', 'pipe', 'inherit'] });
let buf = '', id = 0;
const wait = new Map();
mcp.stdout.on('data', (d) => { buf += d; let i; while ((i = buf.indexOf('\n')) >= 0) { const l = buf.slice(0, i); buf = buf.slice(i + 1); try { const m = JSON.parse(l); if (m.id && wait.has(m.id)) { wait.get(m.id)(m); wait.delete(m.id); } } catch {} } });
const rpc = (method, params) => new Promise((res) => { const n = ++id; wait.set(n, res); mcp.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: n, method, params }) + '\n'); });
const tool = async (name, args) => { const m = await rpc('tools/call', { name, arguments: args }); const r = m.result || {}; const t = (r.content || []).find((c) => c.type === 'text'); if (r.isError) throw new Error(`${name}: ${t && t.text}`); try { return JSON.parse(t.text); } catch { return t && t.text; } };
(async () => {
  let turns = 0;
  await rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'fake-claude', version: '1' } });
  const brief = await tool('get_brief', {}); turns++;
  const rid = (prompt.match(/request_id (cq[0-9a-f]+)/) || [])[1];
  const req = (brief.open_requests || []).find((r) => r.id === rid) || {};
  await new Promise((r) => setTimeout(r, +process.env.FAKE_CC_HOLD_MS || 0));
  const set = (lang) => (lang === 'ar' ? { headline: 'توهّج زجاجي كل صباح', primary_text: 'قوام خفيف يمنح بشرتكِ إشراقة يومية.', sub: 'ترطيب يدوم', cta: 'SHOP_NOW' } : { headline: 'Glass-skin glow, every morning', primary_text: 'A light texture for a dewy, daily glow.', sub: 'Lasting hydration', cta: 'SHOP_NOW' });
  const made = await tool('create_ad_set', { request_id: rid, prompt: 'auto-run', ideas: [{ product_ids: (req.product_ids || []).slice(0, 1), angle: req.angle || 'glow', hook: 'glass skin', template: 'glow', formats: ['1:1', '4:5'], show_price: true, ar: set('ar'), en: set('en') }] }); turns++;
  const saved = await tool('save_to_library', { session_id: made.session_id }); turns++;
  const n = (saved.saved_creative_ids || []).length, held = (saved.held_back_by_quality_check || []).length;
  // run_it the way a model may name the session (sessionId, 0.5.2's sessionArg) and asking for launch_now: an
  // unattended run must never launch (KAA_MCP_NO_LAUNCH), so the answer only approves
  const ran = await tool('run_it', { sessionId: made.session_id, launch_now: true }); turns++;
  if (process.env.FAKE_CC_LOG) fs.appendFileSync(process.env.FAKE_CC_LOG, JSON.stringify({ run_it: ran, saved: saved.saved_creative_ids || [] }) + '\n');
  out({ type: 'result', subtype: 'success', is_error: false, result: `Saved ${n} ads, ${held} held back.`, num_turns: turns });
  mcp.stdin.end();
  process.exit(0);
})().catch((e) => { out({ type: 'result', subtype: 'error_during_execution', is_error: true, result: String(e.message || e), num_turns: 0 }); process.exit(1); });
