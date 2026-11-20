#!/usr/bin/env node
// Settings → Updates, end to end (the owner, 20 Nov 2026: "also build me the patch upload system in the auto pilot
// ads system too. must be super reliable."). Against the REAL engine (-mock: fake Meta / Claude / shop), in Chromium.
//
// In a temp copy of this tree, with the real tools: tools/package-mac.sh builds the installed version B (VERSION as
// it is), then B+1 as a FULL zip and B+2 as a PATCH (tools/package-patch.sh B+1 → B+2), and a broken patch B+1 → B+3
// whose app cannot load. The Mac engine in each package is swapped for a Linux build (this box runs Linux), so the
// engine that installs is the one that restarts. Then, through the page:
//   1  refusals: a tampered zip, an older version, a zip-slip entry, a patch for another version -- each refused with
//      its reason, the app folder byte-identical, nothing left behind
//   2  the full zip, uploaded in chunks with the connection DROPPED mid-upload: it resumes at the byte the engine
//      has (never from 0); the review card (from → to, notes, added / changed / removed, engine, checks); Install:
//      every step shown; the engine re-executes itself (same process, same port), the page reconnects, the health
//      check passes, the page reloads: "Updated to version B+1", "Roll back to B"
//   3  the patch (Arabic, 1280): only the changed files travel; installed onto exactly B+1; files removed / added
//   4  Roll back: back on B+1, the app folder byte-identical to before the patch
//   5  the broken patch: installed, fails its health check, rolled back BY ITSELF; the page says so
//   6  the data folder: every file the owner had is still there, unchanged; nothing of the package landed in it
//   7  the launcher: a new engine that cannot start at all is rolled back by "Start KBB Ads Autopilot.command"
// Pictures: shots/update-*-{en,ar}-{1440,1280}.jpg
//   NODE_PATH=<repo>/node_modules PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node tools/update-check.cjs
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const crypto = require('crypto');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const NAME = 'KBB Ads Autopilot';
const PORT = 44000 + Math.floor(Math.random() * 400);
let failures = 0, passes = 0;
const ok = (c, m) => { console.log(`${c ? 'PASS' : 'FAIL'}  ${m}`); if (c) passes++; else failures++; return c; };
const sh = (cmd, args, opts = {}) => execFileSync(cmd, args, Object.assign({ stdio: ['ignore', 'pipe', 'pipe'] }, opts)).toString();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const T = fs.mkdtempSync(path.join(os.tmpdir(), 'kaa-upd-'));
const SRC = path.join(T, 'src'), OUT = path.join(T, 'out'), APP = path.join(T, 'app'), HOME = path.join(T, 'home'), DATA = path.join(HOME, `${NAME} Data`);
fs.mkdirSync(OUT, { recursive: true });

/** every file under dir (skipping top-level names): path, mode, sha256 */
function treeSum(dir, skip = []) {
  const out = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const p = path.join(d, e.name), rel = path.relative(dir, p);
      if (skip.includes(rel)) continue;
      if (e.isDirectory()) { out.push(rel + '/'); walk(p); } else out.push(rel + ' ' + (fs.lstatSync(p).mode & 0o777).toString(8) + ' ' + crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex'));
    }
  };
  walk(dir);
  return out;
}
const sha = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
const bump = (v, n) => v.replace(/(\d+)$/, (d) => String(+d + n));

// ── the packages, built by the real tools from a copy of this tree ──
function setVersion(v, notes, mutate) {
  fs.writeFileSync(path.join(SRC, 'VERSION'), v + '\n');
  const rn = path.join(SRC, 'tools/release-notes.txt');
  const txt = fs.readFileSync(rn, 'utf8');
  if (!txt.includes(`## ${v}\n`)) fs.writeFileSync(rn, txt.replace(/\n## /, `\n## ${v}\n${notes.map((n) => '- ' + n).join('\n')}\n\n## `));
  mutate?.(path.join(SRC, 'app'));
}
/** package-mac.sh, then this box's engine in the place of the Intel Mac one, and update.json again */
function buildFull(tag) {
  sh('bash', [path.join(SRC, 'tools/package-mac.sh')], { cwd: SRC });
  const folder = path.join(T, 'full-' + tag, NAME);
  fs.mkdirSync(path.dirname(folder), { recursive: true });
  sh('cp', ['-a', path.join(SRC, 'dist', NAME), folder]);
  sh('go', ['build', '-trimpath', '-ldflags', `-s -w -X main.buildTag=${tag.startsWith('b') ? 'base' : 'n'}`, '-o', path.join(folder, 'engine/kaa-mac-amd64'), '.'], { cwd: path.join(SRC, 'engine'), env: Object.assign({}, process.env, { CGO_ENABLED: '0' }) });
  const v = fs.readFileSync(path.join(SRC, 'VERSION'), 'utf8').trim();
  sh('python3', ['-I', path.join(SRC, 'tools/engine-make-update-manifest.py'), folder, v, path.join(SRC, 'tools/release-notes.txt')], { cwd: SRC });
  return { folder, v };
}
function zipFolder(folder, zip) { sh('zip', ['-qry', '-X', zip, NAME], { cwd: path.dirname(folder) }); return zip; }
function addEntry(zip, name, body) {
  sh('python3', ['-I', '-c', 'import sys, zipfile\nz = zipfile.ZipFile(sys.argv[1], "a")\nz.writestr(zipfile.ZipInfo(sys.argv[2]), sys.argv[3]); z.close()', zip, name, body]);
}

(async () => {
  const t0 = Date.now();
  sh('bash', ['-c', `cd "${ROOT}" && tar --exclude=./dist --exclude=./shots --exclude='./.lane-*' --exclude='./engine/kaa-*' -cf - . | (mkdir -p "${SRC}" && cd "${SRC}" && tar -xf -)`]);
  const B = fs.readFileSync(path.join(SRC, 'VERSION'), 'utf8').trim(), N1 = bump(B, 1), N2 = bump(B, 2), N3 = bump(B, 3);
  const base = buildFull('base');
  setVersion(N1, ['Install updates from Settings → Updates.', 'Patches: only what changed travels.'], (app) => {
    fs.writeFileSync(path.join(app, 'update-proof.txt'), `installed by the updater: ${N1}\n`);
    fs.writeFileSync(path.join(app, 'update-old.txt'), 'goes away in the patch\n');
  });
  const f1 = buildFull('n1');
  const zipN1 = zipFolder(f1.folder, path.join(OUT, `${NAME} v${N1}.zip`));
  setVersion(N2, ['A patch: one file changed, one added, one removed.', 'Roll back keeps working.'], (app) => {
    fs.writeFileSync(path.join(app, 'update-proof.txt'), `installed by the patch: ${N2}\n`);
    fs.rmSync(path.join(app, 'update-old.txt'));
    fs.writeFileSync(path.join(app, 'update-new.txt'), 'added by the patch\n');
  });
  const f2 = buildFull('n2');
  const patchOut = sh('bash', [path.join(SRC, 'tools/package-patch.sh'), zipN1, '--to', f2.folder, '--out', OUT], { cwd: SRC });
  const zipP2 = path.join(OUT, `${NAME} patch ${N1} to ${N2}.zip`);
  ok(fs.existsSync(zipP2), `tools/package-patch.sh made “${path.basename(zipP2)}” (${patchOut.trim().split('\n').pop()})`);
  const pman = JSON.parse(sh('unzip', ['-p', zipP2, `${NAME}/update.json`]));
  const allN2 = Object.keys(JSON.parse(fs.readFileSync(path.join(f2.folder, 'update.json'), 'utf8')).files).length;
  ok(pman.kind === 'patch' && pman.format === 2 && pman.from === N1 && pman.version === N2 && pman.removed.includes('app/update-old.txt') && !pman.files['engine/kaa-mac-amd64'] && Object.keys(pman.result).length === allN2 && Object.keys(pman.files).length < 8,
    `the patch carries only what changed: ${Object.keys(pman.files).length} of ${allN2} files (${Object.keys(pman.files).join(', ')}), removes ${pman.removed.join(', ')}, the engine unchanged`);
  // the broken patch: B+1 → B+3, whose app's ui.js is gone (every screen imports it)
  setVersion(N3, ['A broken version: its app cannot load.'], (app) => fs.rmSync(path.join(app, 'js/ui.js')));
  const f3 = buildFull('n3');
  sh('bash', [path.join(SRC, 'tools/package-patch.sh'), zipN1, '--to', f3.folder, '--out', OUT], { cwd: SRC });
  const zipBroken = path.join(OUT, `${NAME} patch ${N1} to ${N3}.zip`);
  // bad packages
  const tampered = path.join(OUT, 'tampered.zip');
  { const d = path.join(T, 'tamper', NAME); fs.mkdirSync(path.dirname(d), { recursive: true }); sh('cp', ['-a', f1.folder, d]); fs.appendFileSync(path.join(d, 'app/index.html'), '<!-- changed after packing -->'); zipFolder(d, tampered); }
  const older = path.join(OUT, `${NAME} v${bump(B, -1).replace(/^(\d+\.\d+\.)-1$/, '$10')}.zip`);
  { const d = path.join(T, 'older', NAME); fs.mkdirSync(path.dirname(d), { recursive: true }); sh('cp', ['-a', f1.folder, d]); const m = JSON.parse(fs.readFileSync(path.join(d, 'update.json'), 'utf8')); m.version = '0.0.9'; fs.writeFileSync(path.join(d, 'update.json'), JSON.stringify(m)); zipFolder(d, older); }
  const zipslip = path.join(OUT, 'zipslip.zip');
  fs.copyFileSync(zipN1, zipslip); addEntry(zipslip, `${NAME}/../escaped.txt`, 'x');
  console.log(`      packages built in ${Math.round((Date.now() - t0) / 1000)} s: base ${B}, full ${N1} (${(fs.statSync(zipN1).size / 1e6).toFixed(1)} MB), patch ${N1}→${N2} (${(fs.statSync(zipP2).size / 1e3).toFixed(0)} KB)`);

  // ── the installed app (version B) and the owner's data ──
  sh('cp', ['-a', base.folder, APP]);
  fs.mkdirSync(path.join(DATA, 'owner-files'), { recursive: true });
  fs.writeFileSync(path.join(DATA, 'owner-files/notes.txt'), 'the owner\'s own file: never touched by an update\n');
  const exe = path.join(APP, 'engine/kaa-mac-amd64');
  const env = Object.assign({}, process.env, { HOME, KAA_SECRETS_FILE: '1' });
  delete env.KAA_BRAIN;
  const eng = spawn(exe, ['-dir', APP, '-data', DATA, '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '900s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let log = ''; eng.stdout.on('data', (d) => { log += d; }); eng.stderr.on('data', (d) => { log += d; });
  let exited = false; eng.on('exit', () => { exited = true; });
  const base_ = `http://127.0.0.1:${PORT}`;
  const waitLog = async (re, ms = 60000) => { const s = Date.now(); while (!re.test(log)) { if (Date.now() - s > ms) throw new Error('engine did not say ' + re + '\n' + log.slice(-3000)); await sleep(150); } };
  await waitLog(/running at/);
  const data0 = new Map(treeSum(DATA).map((l) => [l.split(' ')[0], l]));
  const app0 = treeSum(APP);

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(60000);
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/status of (401|409|422)|ERR_FAILED|ERR_CONNECTION|ERR_EMPTY_RESPONSE|net::/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  if (process.env.UPD_DEBUG) { page.on('response', (r) => { if (/api\/update/.test(r.url())) console.log('   ', r.status(), r.request().method(), r.url().replace(base_, '')); }); page.on('console', (m) => console.log('    console', m.type(), m.text().slice(0, 200))); }
  await page.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); if (!sessionStorage.getItem('kaa.t')) { localStorage.setItem('kaa.lang', 'en'); sessionStorage.setItem('kaa.t', '1'); } } catch {} });
  await page.goto(base_ + '/app/#/settings?tab=updates');
  await page.waitForFunction((v) => (document.querySelector('#upd-version') || {}).textContent?.includes(v), B);
  const lang = async (l) => { await page.click(`#lang [data-l="${l}"]`); await page.waitForFunction((x) => document.documentElement.lang === x && document.querySelector('#upd-root'), l); await sleep(250); };
  const shots = async (name, combos = [[1440, 900, 'en'], [1440, 900, 'ar'], [1280, 800, 'en'], [1280, 800, 'ar']]) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    for (const [w, h, l] of combos) {
      await page.setViewportSize({ width: w, height: h }); await lang(l);
      await page.waitForSelector('#upd-root .card');
      await page.screenshot({ path: path.join(SHOTS, `update-${name}-${l}-${w}.jpg`), type: 'jpeg', quality: 72, fullPage: true });
    }
    await page.setViewportSize({ width: 1440, height: 900 }); await lang('en');
  };
  const appSame = (want, why) => ok(JSON.stringify(treeSum(APP, ['.update'])) === JSON.stringify(want), why);
  const ack = async () => { if (await page.$('#upd-done [data-u="ack"]')) { await page.click('#upd-done [data-u="ack"]'); await page.waitForSelector('#upd-done', { state: 'detached' }); } };
  const waitFor = async (sel, ms = 60000) => {
    try { return await page.waitForSelector(sel, { timeout: ms }); } catch (e) { throw new Error(`${sel} did not show; the screen says: ${(await page.textContent('#view').catch(() => '')).replace(/\s+/g, ' ').slice(0, 600)}`); }
  };
  const waitReload = async () => {
    await page.waitForFunction(() => !window.__updMark && document.querySelector('#upd-done'), null, { timeout: 150000 });
  };

  // the keys, as the engine reads them (Keychain on a Mac; the 0600 file in this run): they must survive every restart
  const engineApi = async (p, method = 'GET', body) => { const info = await (await fetch(base_ + '/api/info')).json(); const r = await fetch(base_ + p, { method, headers: { 'X-KAA': info.token, 'content-type': 'application/json' }, body: body ? JSON.stringify(body) : undefined }); return { s: r.status, j: await r.json().catch(() => null) }; };
  const keysSet = async () => { const c = (await engineApi('/api/connections')).j; return c && c.meta.token.set && c.claude.key.set ? `meta …${c.meta.token.last4}, claude …${c.claude.key.last4}` : ''; };
  const keys0 = await keysSet();
  ok(keys0 !== '', `the saved keys before any update: ${keys0}`);
  const wrong = await engineApi('/api/connections/claude', 'PUT', { api_key: 'EAAwrongfield0000000000000000' });
  ok(wrong.s === 400 && /looks like a Meta token/.test(wrong.j.error) && (await keysSet()) === keys0, `a Meta token pasted into the Claude field is refused before it is stored: “${wrong.j && wrong.j.error}”`);
  ok((await page.textContent('#upd-version')).includes(B) && (await page.textContent('#upd-api')) === '2', `Settings → Updates shows version ${B} and engine API level 2`);
  // the search finds it
  await page.goto(base_ + '/app/#/overview'); await page.waitForSelector('#search');
  await page.fill('#search', 'update'); await page.press('#search', 'Enter');
  ok(await page.waitForSelector('#upd-version', { timeout: 15000 }).then(() => true, () => false), 'typing “update” in the search opens Settings → Updates');
  await shots('idle');

  // ── 1. refusals ──
  const refusals = [['tampered', tampered, /changed or damaged/], ['older', older, /older than the one you have/], ['zip-slip', zipslip, /outside its folder/],
    ['patch for another version', zipP2, new RegExp(`updates version ${N1.replace(/\./g, '\\.')} only, and you have ${B.replace(/\./g, '\\.')}. Use the full “${NAME} v${N2.replace(/\./g, '\\.')}\\.zip”`)]];
  for (const [n, z, want] of process.env.UPD_ONLY ? refusals.slice(3) : refusals) {
    await page.evaluate(() => document.querySelector('#upd-refused')?.remove()); // wait for THIS file's answer
    await page.setInputFiles('#upd-file', z);
    await page.waitForSelector('#upd-refused', { timeout: 60000 });
    const txt = (await page.textContent('#upd-refused')).replace(/\s+/g, ' ');
    ok(want.test(txt) && /Nothing was changed/.test(txt), `${n}: refused — “${txt.slice(0, 170)}”`);
    appSame(app0, `${n}: the app folder is byte-identical`);
    ok(!fs.existsSync(path.join(APP, '.update')), `${n}: nothing left behind (no .update folder)`);
  }
  await shots('refused');

  // ── 2. the full zip, with the connection dropped mid-upload ──
  const offsets = [];
  let dropped = false;
  await page.route('**/api/update/upload/chunk**', async (route) => {
    const off = +new URL(route.request().url()).searchParams.get('offset');
    offsets.push(off);
    if (off > 0 && !dropped) { dropped = true; await route.abort('connectionreset'); return; }
    await route.continue();
  });
  await page.setInputFiles('#upd-file', zipN1);
  await page.waitForSelector('#upd-resumed', { timeout: 60000 });
  const resumedTxt = await page.textContent('#upd-resumed');
  await waitFor('#upd-card', 90000);
  await page.unroute('**/api/update/upload/chunk**');
  const firstRetry = offsets.findIndex((o, i) => i > 0 && o === offsets[i - 1]);
  ok(dropped && firstRetry > 0 && offsets.filter((o) => o === 0).length === 1 && offsets.every((o, i) => i === 0 || o >= offsets[i - 1]),
    `upload: the connection dropped at byte ${offsets[firstRetry]} and it resumed THERE (chunk offsets ${JSON.stringify(offsets)}; never back to 0) — “${resumedTxt.trim()}”`);
  const card = (await page.textContent('#upd-card')).replace(/\s+/g, ' ');
  const nums = await page.$$eval('.upd-stat b', (b) => b.map((x) => x.textContent));
  ok(card.includes(B) && card.includes(N1) && card.includes('Install updates from Settings') && /Full version/.test(card) && /engine changes/i.test(card),
    `review card: ${B} → ${N1}, full version, what's new, “the engine changes” — stats ${JSON.stringify(nums)}`);
  const checks = await page.$$eval('#upd-checks li', (l) => l.map((x) => x.dataset.check));
  ok(['app', 'hashes', 'listed', 'paths', 'newer', 'size', 'space', 'signature'].every((c) => checks.includes(c)), `checks passed listed on the card: ${checks.join(', ')}`);
  appSame(app0, 'checking the package changed nothing in the app folder (staged in .update only)');
  await shots('review-full');

  const pid = eng.pid;
  await page.evaluate(() => { window.__updMark = 1; });
  const stepsSeen = new Set();
  const watchSteps = setInterval(() => page.$$eval('#upd-steps li', (l) => l.map((x) => x.dataset.step + ':' + x.className)).then((a) => a.forEach((s) => stepsSeen.add(s))).catch(() => {}), 120);
  await page.click('#upd-install');
  await page.waitForSelector('#upd-steps li[data-step="health"].run, #upd-steps li[data-step="health"].done', { timeout: 90000 });
  await page.screenshot({ path: path.join(SHOTS, 'update-installing-en-1440.jpg'), type: 'jpeg', quality: 72, fullPage: true });
  await waitReload();
  clearInterval(watchSteps);
  await waitLog(new RegExp(`Updated: ${NAME} ${N1.replace(/\./g, '\\.')} is running and healthy`), 30000);
  const done1 = (await page.textContent('#upd-done')).replace(/\s+/g, ' ');
  ok(/Updated to version/.test(done1) && done1.includes(N1) && /passed its health check/.test(done1), `after Install the page reloaded by itself: “${done1.slice(0, 120)}”`);
  ok(['verify', 'stage', 'swap', 'restart', 'reconnect', 'health'].every((s) => stepsSeen.has(s + ':done')), `every step was shown done: ${[...stepsSeen].filter((s) => s.endsWith(':done')).map((s) => s.split(':')[0]).join(' → ')}`);
  ok(!exited && eng.pid === pid && (log.match(/running at/g) || []).length === 2 && log.includes(`127.0.0.1:${PORT}`), 'the engine re-executed itself: same process, same port (the Terminal window keeps running it)');
  ok((await page.textContent('#upd-version')).includes(N1) && fs.readFileSync(path.join(APP, 'VERSION'), 'utf8').trim() === N1 && fs.existsSync(path.join(APP, 'app/update-proof.txt')), `version ${N1} is in place and shown`);
  ok(['engine/kaa-mac-arm64', 'engine/kaa-mac-amd64', `Start ${NAME}.command`].every((f) => (fs.statSync(path.join(APP, f)).mode & 0o111) === 0o111), 'both engines and the launcher keep their executable bit');
  ok((await page.textContent('#upd-rollback')).includes(B), `“Roll back to ${B}” is offered`);
  ok((await keysSet()) === keys0, `install → restart: the keys are still set (${keys0})`);
  ok(fs.existsSync(path.join(APP, '.update/previous/units.txt')) && !fs.existsSync(path.join(APP, '.update/health.json')), 'the previous version is kept (for Roll back and the launcher); the health marker is gone');
  await shots('after-full');
  await ack();
  const app1 = treeSum(APP, ['.update']);

  // ── 3. the patch, in Arabic at 1280 ──
  await page.setViewportSize({ width: 1280, height: 800 }); await lang('ar');
  await page.setInputFiles('#upd-file', zipP2);
  await waitFor('#upd-card[data-kind="patch"]');
  const stats = await page.$$eval('.upd-stat b', (b) => b.map((x) => x.textContent));
  ok(stats[0] === '1' && stats[2] === '1' && +stats[1] >= 2 && !(await page.textContent('#upd-engine')).includes('يتغيّر:'), `patch review (Arabic): 1 added, ${stats[1]} changed, 1 removed, the engine does not change`);
  await page.setViewportSize({ width: 1440, height: 900 }); await lang('en');
  await shots('review-patch');
  await page.setViewportSize({ width: 1280, height: 800 }); await lang('ar');
  const ampBefore = sha(exe);
  await page.evaluate(() => { window.__updMark = 1; });
  await page.click('#upd-install');
  await page.waitForSelector('#upd-steps li[data-step="health"].run, #upd-steps li[data-step="health"].done', { timeout: 90000 });
  await page.screenshot({ path: path.join(SHOTS, 'update-installing-ar-1280.jpg'), type: 'jpeg', quality: 72, fullPage: true });
  await waitReload();
  await page.setViewportSize({ width: 1440, height: 900 }); await lang('en');
  ok(fs.readFileSync(path.join(APP, 'VERSION'), 'utf8').trim() === N2 && !fs.existsSync(path.join(APP, 'app/update-old.txt')) && fs.existsSync(path.join(APP, 'app/update-new.txt')) && /patch/.test(fs.readFileSync(path.join(APP, 'app/update-proof.txt'), 'utf8')),
    `the patch landed: ${N2}, one file changed, one added, one removed`);
  ok(sha(exe) === ampBefore, 'the engine file was not rewritten by a patch that does not change it');
  const want2 = JSON.parse(fs.readFileSync(path.join(f2.folder, 'update.json'), 'utf8')).files;
  ok(Object.entries(want2).every(([rel, f]) => sha(path.join(APP, rel)) === f.sha256), `after the patch every one of the ${Object.keys(want2).length} files is byte-identical to the full ${N2} package`);
  await shots('after-patch');
  await ack();

  // ── 4. roll back ──
  await page.evaluate(() => { window.__updMark = 1; });
  await page.click('#upd-rollback');
  await page.click('#dlg button[value="yes"]');
  await waitReload();
  const back = (await page.textContent('#upd-done')).replace(/\s+/g, ' ');
  ok(/Back on version/.test(back) && back.includes(N1) && (await page.textContent('#upd-version')).includes(N1), `Roll back: the page says “${back.slice(0, 90)}”`);
  appSame(app1, 'after Roll back the app folder is byte-identical to before the patch');
  ok((await page.textContent('#upd-rollback')).includes(N2), `and the way forward again is “Roll back to ${N2}”`);
  await shots('rolled-back');
  await ack();

  // ── 5. the broken patch: health check fails → rolled back by itself ──
  await page.setInputFiles('#upd-file', zipBroken);
  await waitFor('#upd-card[data-kind="patch"]');
  await page.evaluate(() => { window.__updMark = 1; });
  await page.click('#upd-install');
  await page.waitForSelector('#upd-steps li[data-step="health"].fail', { timeout: 90000 });
  await page.screenshot({ path: path.join(SHOTS, 'update-health-failed-en-1440.jpg'), type: 'jpeg', quality: 72, fullPage: true });
  await waitReload();
  const auto = (await page.textContent('#upd-done')).replace(/\s+/g, ' ');
  ok(/Rolled back by itself/.test(auto) && auto.includes(N3) && /ui\.js is missing/.test(auto), `a version that fails its health check is rolled back by itself and the page says why: “${auto.slice(0, 200)}”`);
  appSame(app1, `the app folder is byte-identical to ${N1} again`);
  ok((await keysSet()) === keys0, 'after the automatic roll back (two restarts) the keys are still set');
  ok(/version \S+ failed its health check; version \S+ was put back/.test(log), 'the engine log says it, too');
  const hist = await page.$$eval('.upd-hist li', (l) => l.map((x) => x.textContent.replace(/\s+/g, ' ')));
  ok(hist.length >= 5 && /Rolled back by itself/.test(hist[0]), `history: ${hist.slice(0, 5).map((h) => h.slice(0, 60)).join(' | ')}`);
  await shots('auto-rollback');

  // ── 6. the data folder ──
  const data1 = new Map(treeSum(DATA).map((l) => [l.split(' ')[0], l]));
  const lost = [...data0.keys()].filter((k) => !data1.has(k) && !/(\.tmp|\.part[^/]*)$/.test(k)); // the engine's own half-written files come and go
  const pkgLike = [...data1.keys()].filter((k) => /^(app|engine)\//.test(k) || /update\.json|VERSION$/.test(k));
  ok(lost.length === 0 && data1.get('owner-files/notes.txt') === data0.get('owner-files/notes.txt') && pkgLike.length === 0,
    `the data folder: all ${data0.size} entries still there, the owner's file unchanged, nothing of a package in it${lost.length || pkgLike.length ? ` (lost ${lost.join(', ')}; package-like ${pkgLike.join(', ')})` : ''}`);
  // ── 6b. an old engine under a newer app; a card whose data does not load (the owner's 0.2.0 → 0.3.0, 20 Nov) ──
  const errs0 = errors.length;
  await page.route('**/api/mcp', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: '{"error":"no such endpoint"}' }));
  await page.route('**/api/brain/x/gen/settings', (r) => r.fulfill({ status: 404, contentType: 'application/json', body: '{"error":"no such endpoint"}' }));
  await page.goto(base_ + '/app/#/settings?tab=connections');
  await page.waitForSelector('#mcp-mount [data-failed]', { timeout: 20000 }).catch(() => {});
  const failed = await page.$$eval('[data-failed]', (c) => c.map((x) => x.textContent.replace(/\s+/g, ' ')));
  ok(failed.length === 2 && failed.every((x) => /older than the app/.test(x) && /Try again/.test(x)), `a card whose endpoint is missing is drawn with the reason and Try again, never left out (${failed.length} cards: “${(failed[0] || '').slice(0, 110)}”)`);
  await page.screenshot({ path: path.join(SHOTS, 'update-card-failed-en-1440.jpg'), type: 'jpeg', quality: 72, fullPage: true });
  await page.unroute('**/api/mcp'); await page.unroute('**/api/brain/x/gen/settings');
  errors.splice(errs0, errors.length - errs0, ...errors.slice(errs0).filter((e) => !/status of 404/.test(e))); // the 404s this step made on purpose
  await page.click('#mcp-mount [data-retry]');
  ok(await page.waitForSelector('#mcp-mount [data-mcp]', { timeout: 15000 }).then(() => true, () => false), 'Try again brings the Claude desktop card back once the engine answers');
  const vfile = path.join(APP, 'VERSION'), vnow = fs.readFileSync(vfile, 'utf8');
  fs.writeFileSync(vfile, '9.9.9\n'); // the folder replaced by hand while this engine runs
  await page.goto(base_ + '/app/#/overview'); await page.reload();
  const stale = await page.waitForSelector('#stale-engine', { timeout: 15000 }).then((e) => e.textContent(), () => '');
  ok(/old engine is still running/.test(stale) && /Close the KBB Ads Autopilot Terminal window/.test(stale) && !(await page.$('#view .card.kpi, #view [data-cc-open]')),
    `an engine older than the app on disk: a full-width banner, and nothing runs on it (“${stale.replace(/\s+/g, ' ').slice(0, 120)}”)`);
  await page.screenshot({ path: path.join(SHOTS, 'update-stale-engine-en-1440.jpg'), type: 'jpeg', quality: 72 });
  await page.evaluate(() => { localStorage.setItem('kaa.lang', 'ar'); }); await page.reload();
  await page.waitForSelector('#stale-engine'); await page.setViewportSize({ width: 1280, height: 800 });
  await page.screenshot({ path: path.join(SHOTS, 'update-stale-engine-ar-1280.jpg'), type: 'jpeg', quality: 72 });
  fs.writeFileSync(vfile, vnow);

  ok(errors.length === 0, `no console errors (${errors.slice(0, 3).join(' | ')})`);
  await browser.close();
  eng.kill('SIGTERM');

  // ── 7. the launcher: a new engine that cannot start at all ──
  const L = path.join(T, 'launcher');
  fs.mkdirSync(path.join(L, 'engine'), { recursive: true });
  fs.mkdirSync(path.join(L, '.update/previous'), { recursive: true });
  const fake = '#!/bin/bash\nif [ "$(cat VERSION)" = "9.0.2" ]; then echo "cannot start"; exit 3; fi\necho "STARTED $(cat VERSION)"\n';
  for (const e of ['kaa-mac-amd64', 'kaa-mac-arm64']) fs.writeFileSync(path.join(L, 'engine', e), fake, { mode: 0o755 });
  fs.copyFileSync(path.join(ROOT, `Start ${NAME}.command`), path.join(L, 'start.command'));
  fs.writeFileSync(path.join(L, 'VERSION'), '9.0.2\n');
  fs.writeFileSync(path.join(L, '.update/previous/VERSION'), '9.0.1\n');
  fs.writeFileSync(path.join(L, '.update/previous/units.txt'), 'VERSION\n');
  fs.writeFileSync(path.join(L, '.update/previous/info.json'), JSON.stringify({ version: '9.0.1', units: ['VERSION'] }, null, 1));
  fs.writeFileSync(path.join(L, '.update/health.json'), JSON.stringify({ kind: 'update', from: '9.0.1', to: '9.0.2', auto: true }, null, 1));
  let lout = '', lcode = 0;
  try { lout = execFileSync('bash', ['start.command'], { cwd: L, env: Object.assign({}, process.env, { HOME: path.join(T, 'lhome') }), input: '' }).toString(); } catch (e) { lout = String(e.stdout || ''); lcode = e.status; }
  const last = JSON.parse(fs.readFileSync(path.join(L, '.update/last.json'), 'utf8'));
  ok(lcode === 0 && /STARTED 9\.0\.1/.test(lout) && fs.readFileSync(path.join(L, 'VERSION'), 'utf8').trim() === '9.0.1' && last.kind === 'auto-rollback' && !fs.existsSync(path.join(L, '.update/health.json')),
    `launcher: version 9.0.2 cannot start → 9.0.1 put back by itself and started, with no question asked (“${lout.trim().split('\n').slice(-2).join(' / ')}”)`);

  fs.rmSync(T, { recursive: true, force: true });
  console.log(`\n${passes} passed, ${failures} failed (${Math.round((Date.now() - t0) / 1000)} s)`);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); try { fs.rmSync(T, { recursive: true, force: true }); } catch {} process.exit(1); });
