#!/usr/bin/env node
// KBB Studio — moving a reel to another company, measured (the owner, 19 Nov 2026: "allow me facility to move the
// project from one company to another"). Home → Recent reels → ⇄.
//
// The Linux engine as the launcher runs the Mac one (--dir <app> --data <home>/KBB Studio Data), headless Chrome at
// 1440x900:
//   1. a reel made in KBB from a real video (tools/make-test-clips.sh), saved with its media
//   2. ⇄ → Planet Careers → Move: KBB's list no longer has it; the folder (media and all) is in Planet Careers, its
//      project.json says so and its media paths point there; nothing else in the data folder changed
//   3. the toast's "Open it there": it opens in Planet Careers and PLAYS (the playhead moves, the video decodes)
//   4. a reel with the same id already in the target: refused in the dialog with a clear message, nothing changed
// Pictures -> shots/move/ (01-dialog, 02-moved, 03-open-in-target, 04-collision).
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/move-check.cjs
'use strict';
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const crypto = require('crypto');
const { spawn } = require('child_process');
const H = require('./studio-harness.cjs');

const SHOTS = path.join(H.ROOT, 'shots', 'move');
fs.mkdirSync(SHOTS, { recursive: true });
const CLIP = path.join(H.ROOT, 'tools', 'out', 'portrait.mp4');
if (!fs.existsSync(CLIP)) throw new Error('run tools/make-test-clips.sh first');
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; return c; };

const T = fs.mkdtempSync(path.join(os.tmpdir(), 'kbb-move-'));
const HOME = path.join(T, 'home'), DATA = path.join(HOME, 'KBB Studio Data');
const SKIP = ['migration.log', '.looked-for-old-folders']; // the engine's own start-up notes (data.go)
function treeSum(dir, skip = []) {
  const out = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const p = path.join(d, e.name), rel = path.relative(dir, p).split(path.sep).join('/');
      if (skip.some((s) => rel === s || rel.startsWith(s + '/'))) continue;
      if (e.isDirectory()) { out.push(rel + '/'); walk(p); } else out.push(rel + ' ' + crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex'));
    }
  };
  walk(dir);
  return out.join('\n');
}
const freePort = () => new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });

(async () => {
  const APP = H.makeStudio();
  const port = await freePort();
  const eng = spawn(H.BIN, ['--dir', APP, '--data', DATA, '--port', String(port), '--no-browser', '--idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { HOME }) });
  let out = ''; eng.stdout.on('data', (d) => { out += d; }); eng.stderr.on('data', (d) => { out += d; });
  for (let i = 0; i < 300 && !/running at/.test(out); i++) await new Promise((r) => setTimeout(r, 100));
  const url = `http://127.0.0.1:${port}`;
  const browser = await H.launchChrome();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(60000);
  const errors = [];
  // the collision is answered 409, which Chrome also prints as a failed resource: expected; everything else counts
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 409/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const shot = async (n) => { await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, n + '.png') }); };
  const ws = (id) => path.join(DATA, 'workspaces', id);

  // ── 1. a reel with a real video, in KBB ──
  await page.goto(url + '/app/');
  await page.waitForSelector('.home');
  await page.fill('input[aria-label="Reel name"]', 'Serum launch');
  await page.setInputFiles('.home-drop input[type=file]', CLIP);
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0, null, { timeout: 60000 });
  const id = await page.evaluate(() => kbbEditor.project.id);
  const pj = (w) => path.join(ws(w), 'projects', id, 'project.json');
  for (let i = 0; i < 100; i++) { if (fs.existsSync(pj('kbb')) && fs.readFileSync(pj('kbb'), 'utf8').includes('/media/')) break; await new Promise((r) => setTimeout(r, 200)); }
  const media0 = fs.readdirSync(path.join(ws('kbb'), 'projects', id, 'media'));
  ok(media0.length === 1 && fs.readFileSync(pj('kbb'), 'utf8').includes(`workspaces/kbb/projects/${id}/media/`), `a KBB reel with its video saved (${media0[0]})`);
  await page.goto(url + '/app/');
  await page.waitForSelector(`.recent[title="Serum launch"]`);
  const kbbCount = await page.$$eval('.recent', (l) => l.length);
  const reelDirs = [`workspaces/kbb/projects/${id}`, `workspaces/planet-careers/projects/${id}`, 'workspaces/planet-careers/projects'];
  const others0 = treeSum(DATA, [...SKIP, ...reelDirs]);
  const reel0 = treeSum(path.join(ws('kbb'), 'projects', id), ['project.json']);

  // ── 2. move it ──
  await page.hover(`.recent[title="Serum launch"]`);
  await page.click(`.recent[title="Serum launch"] .move`);
  await page.waitForSelector('.move-dlg');
  const opts = await page.$$eval('.move-opt', (l) => l.map((x) => x.dataset.co));
  ok(opts.length >= 1 && !opts.includes('kbb') && opts.includes('planet-careers'), `the dialog lists the other companies (${opts.join(', ')})`);
  await page.click('.move-opt[data-co="planet-careers"]');
  await shot('01-dialog');
  await page.click('#move-go');
  await page.waitForSelector('.move-toast');
  await page.waitForFunction((n) => document.querySelectorAll('.recent').length === n - 1, kbbCount);
  ok(!(await page.$(`.recent[title="Serum launch"]`)), 'KBB’s Recent reels no longer list it');
  ok(/moved to Planet Careers/.test(await page.textContent('.move-toast')), `the toast says where it went: “${(await page.textContent('.move-toast')).trim()}”`);
  await page.mouse.move(1430, 450);
  await page.$eval('.recent-card', (e) => e.scrollIntoView({ block: 'end' }));
  await shot('02-moved');
  ok(!fs.existsSync(path.join(ws('kbb'), 'projects', id)) && fs.existsSync(pj('planet-careers')), 'the reel’s folder moved to Planet Careers');
  const p = JSON.parse(fs.readFileSync(pj('planet-careers'), 'utf8'));
  ok(p.workspace === 'planet-careers' && p.media.every((m) => m.src.startsWith(`workspaces/planet-careers/projects/${id}/media/`)) && !JSON.stringify(p).includes(`workspaces/kbb/projects/${id}`),
    'project.json: its company is Planet Careers and every media path points into its new folder');
  ok(p.brand === 'kbb', 'it keeps its own look (brand kit “kbb”, not restyled)');
  ok(treeSum(path.join(ws('planet-careers'), 'projects', id), ['project.json']) === reel0, 'its media came along byte for byte');
  ok(treeSum(DATA, [...SKIP, ...reelDirs]) === others0, 'nothing outside the two reel folders changed');

  // ── 3. open it there, and play ──
  await page.click('#move-open');
  await page.waitForFunction(() => window.kbbEditor?.project.workspace === 'planet-careers' && kbbEditor.project.media[0]?.dur > 0, null, { timeout: 60000 });
  const played = await page.evaluate(async () => {
    const E = kbbEditor, m = E.project.media[0];
    E.seek(0); E.play(); await new Promise((r) => setTimeout(r, 1500)); const t = E.t; E.pause();
    const v = document.createElement('video'); v.muted = true; v.src = E.media.url(m);
    const decoded = await new Promise((r) => { v.onloadeddata = () => r(v.videoWidth); v.onerror = () => r(0); });
    return { t, src: m.src, decoded, company: document.querySelector('.ws-switch')?.textContent || '' };
  });
  ok(played.t > 0.6 && played.decoded === 1080 && played.src.includes('planet-careers'), `it opens in Planet Careers and plays (playhead ${played.t.toFixed(2)} s after 1.5 s, the video decodes ${played.decoded}px wide from its new place)`);
  await shot('03-open-in-target');

  // ── 4. the same id already in the target ──
  fs.mkdirSync(path.join(ws('kbb'), 'projects', id), { recursive: true });
  fs.writeFileSync(pj('kbb'), JSON.stringify(Object.assign({}, p, { workspace: 'kbb', name: 'Serum launch (KBB copy)', media: [] })));
  await page.goto(url + '/app/');   // Planet Careers is the company open now
  await page.waitForSelector(`.recent[title="Serum launch"]`);
  const before = treeSum(DATA, SKIP);
  await page.hover(`.recent[title="Serum launch"]`);
  await page.click(`.recent[title="Serum launch"] .move`);
  await page.click('.move-opt[data-co="kbb"]');
  await page.click('#move-go');
  await page.waitForSelector('.move-err:not([hidden])');
  const msg = await page.textContent('.move-err');
  ok(/already has a reel with the same id/.test(msg) && /Nothing was changed/.test(msg), `collision refused in the dialog: “${msg}”`);
  ok(await page.isVisible('.move-dlg'), 'the dialog stays open so he can pick another company or cancel');
  await shot('04-collision');
  ok(treeSum(DATA, SKIP) === before, 'nothing changed on disk');

  ok(errors.length === 0, `no console errors (${errors.slice(0, 3).join(' | ')})`);
  await browser.close();
  eng.kill('SIGTERM');
  fs.rmSync(T, { recursive: true, force: true });
  fs.rmSync(APP, { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
