#!/usr/bin/env node
// KBB Studio — Configure → ⬆ Updates, measured (the owner, 19 Nov 2026: "give me function to upload patch directly in
// the studio setting, to make updates onwards").
//
// Builds the REAL package with tools/package-mac.sh, unpacks it as the installed app folder (version as built), then
// makes packages from it with tools/make-update-manifest.py: a good 1.3.0, and bad ones (a file changed after packing,
// a zip-slip entry, a link, an older version, an engine missing). Runs the Linux engine as the launcher runs the Mac
// one (--dir <app> --data <home>/KBB Studio Data) and drives headless Chrome at 1440x900:
//   1. every bad package is refused through the UI with its reason, and the app folder stays byte-identical (no
//      .update left behind either)
//   2. the good one: the review card (from → to, what's new, size), Install, the engine re-executes itself (same
//      process id, same port), the page reloads by itself and says "Updated to version 1.3.0"; the folder has the new
//      files, the Mac engines keep their x bit, the data folder is untouched
//   3. Roll back: back on the built version, the app folder byte-identical to before the update, data untouched,
//      history lists both
// Pictures -> shots/update/ (01-before, 02-refused, 03-review, 04-after, 05-rolled-back).
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/update-check.cjs
'use strict';
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const crypto = require('crypto');
const { spawn, execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const SHOTS = path.join(H.ROOT, 'shots', 'update');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; return c; };

const T = fs.mkdtempSync(path.join(os.tmpdir(), 'kbb-upd-'));
const DIST = path.join(H.ROOT, 'dist', 'KBB Studio');
const APP = path.join(T, 'app'), HOME = path.join(T, 'home'), DATA = path.join(HOME, 'KBB Studio Data');

/** every file under dir: path, mode, sha256 (skip: top-level names to leave out) */
function treeSum(dir, skip = []) {
  const out = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const p = path.join(d, e.name), rel = path.relative(dir, p);
      if (skip.includes(rel)) continue;
      if (e.isDirectory()) { out.push(rel + '/'); walk(p); } else {
        const st = fs.lstatSync(p);
        out.push(rel + ' ' + (st.mode & 0o777).toString(8) + ' ' + crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex'));
      }
    }
  };
  walk(dir);
  return out.join('\n');
}
const VERSION_RE = /export const APP_VERSION = '([^']*)'/;
const appVersion = (dir) => VERSION_RE.exec(fs.readFileSync(path.join(dir, 'app/core/config.js'), 'utf8'))[1];

/** a package: the built folder copied, its version set, mutate(folder, 'pre'|'post') around the manifest, zipped */
function makePkg(name, version, mutate) {
  const dir = path.join(T, 'pkg-' + name), folder = path.join(dir, 'KBB Studio');
  fs.mkdirSync(dir, { recursive: true });
  execFileSync('cp', ['-a', DIST, folder]);
  const cfg = path.join(folder, 'app/core/config.js');
  fs.writeFileSync(cfg, fs.readFileSync(cfg, 'utf8').replace(VERSION_RE, `export const APP_VERSION = '${version}'`));
  mutate?.(folder, 'pre');
  execFileSync('python3', ['-I', path.join(H.ROOT, 'tools/make-update-manifest.py'), folder, version, path.join(T, 'notes.txt')]);
  mutate?.(folder, 'post');
  const zip = path.join(T, name + '.zip');
  execFileSync('zip', ['-qry', '-X', zip, 'KBB Studio'], { cwd: dir });
  fs.rmSync(dir, { recursive: true, force: true });
  return zip;
}
/** add a raw entry (a name zip(1) will not write) with Python's zipfile */
function addEntry(zip, name, body, symlink = false) {
  execFileSync('python3', ['-I', '-c', `import sys, zipfile
z = zipfile.ZipFile(sys.argv[1], 'a')
i = zipfile.ZipInfo(sys.argv[2])
if sys.argv[4] == '1': i.external_attr = (0o120777 << 16)
z.writestr(i, sys.argv[3]); z.close()`, zip, name, body, symlink ? '1' : '0']);
}

// the engine's own start-up notes, rewritten at every start (data.go), not the owner's data
const DATA_SKIP = ['migration.log', '.looked-for-old-folders'];
const freePort = () => new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });

(async () => {
  // ── the real package, the installed folder, the packages ──
  execFileSync('bash', [path.join(H.ROOT, 'tools/package-mac.sh')], { stdio: ['ignore', 'ignore', 'inherit'] });
  const BUILT = appVersion(DIST);
  ok(fs.existsSync(path.join(DIST, 'update.json')), `tools/package-mac.sh writes update.json into the package (version ${BUILT})`);
  const man = JSON.parse(fs.readFileSync(path.join(DIST, 'update.json'), 'utf8'));
  ok(man.version === BUILT && man.notes.length > 0 && man.files['engine/kbbstudio-mac-arm64'] && man.files['engine/kbbstudio-mac-amd64'] && 'signature' in man,
    `its manifest: version, ${man.notes.length} note(s), ${Object.keys(man.files).length} files with sha256, both Mac engines, a signature field reserved`);
  execFileSync('cp', ['-a', DIST, APP]);
  // the update installed in this check: the version after the one just built (whatever APP_VERSION is today)
  const NEW = BUILT.replace(/(\d+)$/, (d) => String(+d + 1));
  fs.writeFileSync(path.join(T, 'notes.txt'), `## ${NEW}\n- Install updates from Configure → Updates.\n- Move a reel to another company (⇄ in Recent reels).\n## 1.0.0\n- old\n`);
  const good = makePkg('good', NEW, (f, when) => { if (when === 'pre') fs.writeFileSync(path.join(f, 'app/update-proof.txt'), 'installed by the updater\n'); });
  const bad = {
    tampered: [makePkg('tampered', NEW, (f, when) => { if (when === 'post') fs.appendFileSync(path.join(f, 'app/index.html'), '<!-- changed -->'); }), /changed or damaged/],
    zipslip: [(() => { const z = path.join(T, 'zipslip.zip'); fs.copyFileSync(good, z); addEntry(z, 'KBB Studio/../escaped.txt', 'x'); return z; })(), /outside its folder/],
    symlink: [(() => { const z = path.join(T, 'symlink.zip'); fs.copyFileSync(good, z); addEntry(z, 'KBB Studio/app/link', '/etc/passwd', true); return z; })(), /link/],
    older: [makePkg('older', '1.0.0'), /older than the one you have/],
    noengine: [makePkg('noengine', NEW, (f, when) => {
      if (when !== 'post') return;
      fs.rmSync(path.join(f, 'engine/kbbstudio-mac-amd64'));
      const m = JSON.parse(fs.readFileSync(path.join(f, 'update.json'), 'utf8')); delete m.files['engine/kbbstudio-mac-amd64'];
      fs.writeFileSync(path.join(f, 'update.json'), JSON.stringify(m));
    }), /missing the engine for Intel/],
    notzip: [(() => { const z = path.join(T, 'notes.zip'); fs.writeFileSync(z, 'not a zip'); return z; })(), /not a zip file/],
  };

  // the owner's data: a company, a reel, a key -- must come through every step unchanged
  fs.mkdirSync(path.join(DATA, 'workspaces/kbb/projects/pkeep/media'), { recursive: true });
  fs.writeFileSync(path.join(DATA, 'workspaces/kbb/projects/pkeep/project.json'), JSON.stringify({ v: 1, id: 'pkeep', workspace: 'kbb', name: 'Kept reel', modified: new Date().toISOString(), canvas: { aspect: '9:16' } }));
  fs.writeFileSync(path.join(DATA, 'workspaces/kbb/projects/pkeep/media/clip.mp4'), 'not really a video');

  const port = await freePort();
  const eng = spawn(H.BIN, ['--dir', APP, '--data', DATA, '--port', String(port), '--no-browser', '--idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { HOME }) });
  let out = '';
  eng.stdout.on('data', (d) => { out += d; });
  eng.stderr.on('data', (d) => { out += d; });
  let exited = false; eng.on('exit', () => { exited = true; });
  const waitOut = async (re, ms = 30000) => { const t0 = Date.now(); while (!re.test(out)) { if (Date.now() - t0 > ms) throw new Error('engine did not say ' + re + '\n' + out); await new Promise((r) => setTimeout(r, 100)); } };
  await waitOut(/running at/);
  const url = `http://127.0.0.1:${port}`;

  const browser = await H.launchChrome();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  page.setDefaultTimeout(60000);
  const errors = [];
  // a refused package is answered 422, which Chrome also prints as a failed resource: expected, the rest counts
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 422/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const shot = async (n) => { await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, n + '.png') }); };
  const openUpdates = async () => { await page.click('#configure-btn'); await page.click('.cfg-nav [data-sec="updates"]'); await page.waitForSelector('#upd-version'); };

  await page.goto(url + '/app/');
  await page.waitForSelector('.home');
  await page.waitForTimeout(800);   // the first load seeds the companies; the data snapshot is taken after it
  const data0 = treeSum(DATA, DATA_SKIP);
  const app0 = treeSum(APP);
  await openUpdates();
  ok((await page.textContent('#upd-version')).includes(BUILT), `Configure → Updates shows the version running (${BUILT})`);
  await shot('01-before');

  // ── 1. refusals ──
  for (const [name, [zip, want]] of Object.entries(bad)) {
    await page.setInputFiles('#upd-file', zip);
    await page.waitForSelector('#upd-refused', { timeout: 60000 });
    const txt = await page.textContent('#upd-refused');
    ok(want.test(txt) && /Nothing was changed/.test(txt), `${name}: refused — “${txt.replace(/\s+/g, ' ').slice(0, 150)}”`);
    ok(treeSum(APP) === app0 && !fs.existsSync(path.join(APP, '.update')), `${name}: the app folder is byte-identical, nothing left behind`);
    if (name === 'tampered') await shot('02-refused');
  }

  // ── 2. the good package ──
  await page.setInputFiles('#upd-file', good);
  await page.waitForSelector('#upd-card');
  const card = await page.textContent('#upd-card');
  ok(card.includes(BUILT) && card.includes(NEW) && card.includes('Install updates from Configure') && /MB/.test(card), 'the review card: from → to, what’s new, size, before anything changes');
  ok(treeSum(APP, ['.update']) === app0, 'checking the package changed nothing in the app folder (staged in .update only)');
  await shot('03-review');
  const pid = eng.pid;
  let nav = page.waitForNavigation({ timeout: 90000 });
  await page.click('#upd-install');
  await nav;
  await waitOut(new RegExp(`Updated: KBB Studio ${NEW.replace(/\./g, '\\.')} is running`), 60000);
  await page.waitForSelector('#upd-done', { timeout: 90000 });
  const done = await page.textContent('#upd-done');
  ok(done.includes(`Updated to version ${NEW}`), `the page reloaded by itself and says “${done.split('.')[0]}.”`);
  ok((await page.textContent('#upd-version')).includes(NEW), 'the version shown is the new one');
  ok(!exited && eng.pid === pid && (out.match(/running at/g) || []).length === 2 && out.includes(`127.0.0.1:${port}`), 'the engine re-executed itself in the same process (same window), on the same port');
  ok(appVersion(APP) === NEW && fs.existsSync(path.join(APP, 'app/update-proof.txt')), 'the app folder has the new files');
  ok(['engine/kbbstudio-mac-arm64', 'engine/kbbstudio-mac-amd64', 'Start KBB Studio.command'].every((f) => (fs.statSync(path.join(APP, f)).mode & 0o111) === 0o111), 'both Mac engines and the launcher keep their executable bit');
  const data1 = treeSum(DATA, DATA_SKIP);
  if (data1 !== data0) console.log('     data diff:', data1.split('\n').filter((l) => !data0.split('\n').includes(l)), data0.split('\n').filter((l) => !data1.split('\n').includes(l)));
  ok(data1 === data0, 'the data folder (companies, reels, keys) is untouched');
  ok(fs.existsSync(path.join(APP, '.update/previous/info.json')) && fs.existsSync(path.join(APP, '.update/previous/units.txt')), 'the previous version is kept for Roll back (and for the launcher)');
  ok((await page.textContent('#upd-rollback')).includes(BUILT), `“Roll back to ${BUILT}” is offered`);
  await shot('04-after');

  // ── 3. roll back ──
  await page.click('#upd-rollback');
  nav = page.waitForNavigation({ timeout: 90000 });
  await page.click('.modal-foot .btn.primary');
  await nav;
  await waitOut(new RegExp(`Updated: KBB Studio ${BUILT.replace(/\./g, '\\.')} is running`), 60000);
  await page.waitForSelector('#upd-done', { timeout: 90000 });
  ok(/Back on version/.test(await page.textContent('#upd-done')) && (await page.textContent('#upd-version')).includes(BUILT), `rolled back: the page says it is back on ${BUILT}`);
  ok(treeSum(APP, ['.update']) === app0, 'after Roll back the app folder is byte-identical to before the update');
  ok(treeSum(DATA, DATA_SKIP) === data0, 'the data folder is still untouched');
  const hist = await page.$$eval('.upd-hist li', (l) => l.map((x) => x.textContent));
  ok(hist.length === 2 && hist[0].includes(`Rolled back ${NEW} → `) && new RegExp(`Updated .* → ${NEW.replace(/\./g, '\\.')}`).test(hist[1]), `history: ${JSON.stringify(hist)}`);
  ok((await page.textContent('#upd-rollback')).includes(NEW), `and the way forward again is “Roll back to ${NEW}”`);
  await page.evaluate(() => document.querySelector('.cfg-main').scrollTo(0, 0));
  await shot('05-rolled-back');
  // the owner's kept reel still opens
  await page.click('#cfg-close');
  ok(await page.waitForSelector('.recent[title="Kept reel"]', { timeout: 15000 }).then(() => true, () => false), 'the reel in the data folder is still listed');

  ok(errors.length === 0, `no console errors (${errors.slice(0, 3).join(' | ')})`);
  await browser.close();
  eng.kill('SIGTERM');
  fs.rmSync(T, { recursive: true, force: true });
  fs.rmSync(path.join(H.ROOT, 'dist'), { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
