#!/usr/bin/env node
// KBB Studio — the owner's data survives updates (17 Nov 2026). The owner: "whenever i replaced the files, it asked me
// again these all the download, why is that? why the system is not saving data".
//
// Runs the Linux engine exactly as "Start KBB Studio.command" runs the Mac one (--dir <app> --data ~/KBB Studio Data),
// with a throwaway HOME, and headless Chromium:
//   1. first run with the models (and companies, keys, music, a brand kit) in the app folder: all moved into
//      KBB Studio Data, the Setup screen does not show; a company picked in the UI is remembered
//   2. the app folder replaced with a fresh copy (an update): no Setup; companies, keys, music, brand kit and the picked
//      company all still there -- in a NEW browser profile, so nothing comes from browser storage; same origin
//   3. an older KBB Studio folder in ~/Downloads: its models are brought over; a look-alike without the engine is not
//   4. a wrong-size model file is not counted as installed (Setup shows, with "kept in KBB Studio Data"); "Later" in the
//      UI survives a restart in a new browser profile
//   5. the preferred port busy: the engine falls back to another one and still works
// Model files are sparse files of the manifest's sizes (nothing is downloaded). Pictures -> shots/data/.
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/data-check.cjs
'use strict';
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const { spawn } = require('child_process');
const H = require('./studio-harness.cjs');

const SHOTS = path.join(H.ROOT, 'shots', 'data');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; };
const MAN = JSON.parse(fs.readFileSync(path.join(H.ROOT, 'models', 'manifest.json'), 'utf8'));
const files = MAN.models.flatMap((m) => m.files.map((f) => ({ model: m.id, required: m.required, rel: m.repo + '/' + f.path, size: f.size })));
const UNIQUE = new Set(files.map((f) => f.rel)).size; // a few files are shared by two models

function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, e.name), d = path.join(dst, e.name);
    if (e.isDirectory()) copyDir(s, d); else if (e.isFile()) fs.copyFileSync(s, d);
  }
}
/** a fresh app folder as the zip ships it: app, vendor, built-in brands and library, the model LIST, the engine */
function freshApp(dir) {
  fs.rmSync(dir, { recursive: true, force: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) copyDir(path.join(H.ROOT, d), path.join(dir, d));
  fs.mkdirSync(path.join(dir, 'models'), { recursive: true });
  fs.copyFileSync(path.join(H.ROOT, 'models', 'manifest.json'), path.join(dir, 'models', 'manifest.json'));
  fs.mkdirSync(path.join(dir, 'engine'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'engine', 'kbbstudio-mac-arm64'), 'engine'); // what marks a KBB Studio folder
  return dir;
}
/** every manifest file at its size (sparse: no disk used); `short` = these rel paths one byte short */
function putModels(dir, { short = [] } = {}) {
  for (const f of files) {
    const p = path.join(dir, 'models', f.rel);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, '');
    fs.truncateSync(p, short.includes(f.rel) ? f.size - 1 : f.size);
  }
}
const put = (p, body, mode = 0o644) => { fs.mkdirSync(path.dirname(p), { recursive: true }); fs.writeFileSync(p, body, { mode }); };
const exists = (p) => fs.existsSync(p);
const modelFilesIn = (dir) => { const out = []; const walk = (d) => { for (const e of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, e.name); if (e.isDirectory()) walk(p); else if (p !== path.join(dir, 'manifest.json')) out.push(p); } }; if (exists(dir)) walk(dir); return out; };

const freePort = () => new Promise((res) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => res(p)); }); });

function start(home, app, port, log) {
  return new Promise((res, rej) => {
    const p = spawn(H.BIN, ['--dir', app, '--data', path.join(home, 'KBB Studio Data'), '--port', String(port), '--no-browser', '--idle', '600s'],
      { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { HOME: home }) });
    let out = '';
    p.log = '';
    p.stdout.on('data', async (d) => {
      out += d;
      const m = /running at (http:\/\/[^\s]+)\/app\//.exec(out);
      if (m && !p.url) {
        p.url = m[1]; p.out = out;
        const html = await (await fetch(p.url + '/app/')).text();
        p.token = /rs-token" content="([^"]+)/.exec(html)[1];
        res(p);
      }
    });
    p.stderr.on('data', (d) => { p.log += d; fs.appendFileSync(log, d); });
    p.on('exit', (c) => { p.exited = true; if (!p.url) rej(new Error('engine exited ' + c + ' ' + p.log)); });
  });
}
const api = async (e, p, body) => {
  const r = await fetch(e.url + p, body ? { method: 'POST', headers: { 'X-RS-Token': e.token }, body: JSON.stringify(body) } : { headers: { 'X-RS-Token': e.token } });
  return r.json();
};

/** open the app in a NEW browser profile (empty storage); returns what shows */
async function openApp(browser, e, shot) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (err) => errors.push(err.message));
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.setup-screen, .ws-switch', { timeout: 20000 });
  await page.waitForTimeout(300);
  const setup = await page.$('.setup-screen') !== null;
  if (shot) await page.screenshot({ path: path.join(SHOTS, shot) });
  return { ctx, page, setup, errors };
}

(async () => {
  const LOG = path.join(H.ROOT, 'tools', 'out', 'data-check-engine.log');
  fs.mkdirSync(path.dirname(LOG), { recursive: true });
  fs.writeFileSync(LOG, '');
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'kbb-data-check-'));
  const browser = await H.launchChrome();
  const engines = [];
  const stop = async (e) => { await H.stopEngine(e); };
  try {
    // ── 1. first run: the owner's things are in the app folder ──
    const home = path.join(tmp, 'home1'), app = path.join(home, 'Desktop', 'KBB Studio'), data = path.join(home, 'KBB Studio Data');
    freshApp(app);
    putModels(app);
    const kit = JSON.parse(fs.readFileSync(path.join(H.ROOT, 'brands', 'kbb', 'kit.json'), 'utf8'));
    put(path.join(app, 'brands', 'acme', 'kit.json'), JSON.stringify(Object.assign(kit, { id: 'acme', name: 'Acme Trading' })));
    put(path.join(app, 'workspaces', 'acme', 'workspace.json'), JSON.stringify({ id: 'acme', name: 'Acme Trading', industry: 'Trading', brandRef: 'acme', ownKit: 1, kitSeeded: 1, created: '2026-01-01T00:00:00.000Z', colors: { primary: '#225588' }, fonts: {}, endCard: {} }));
    put(path.join(app, 'workspaces', 'acme', 'projects', 'p1', 'project.json'), '{"id":"p1"}');
    put(path.join(app, 'settings', 'photo-sources.json'), JSON.stringify({ pexels: 'PEXELSKEY_abcdefghijklmnop1234' }), 0o644);
    const song = fs.readdirSync(path.join(H.ROOT, 'library', 'music')).find((f) => f.endsWith('.m4a'));
    fs.copyFileSync(path.join(H.ROOT, 'library', 'music', song), path.join(app, 'library', 'music', 'user', 'my-song.m4a'));
    put(path.join(app, 'library', 'people', 'nurse', 'pexels-1.jpg'), 'jpg');

    const port = await freePort();
    let e = await start(home, app, port, LOG); engines.push(e);
    const origin1 = e.url;
    ok(modelFilesIn(path.join(app, 'models')).length === 0 && modelFilesIn(path.join(data, 'models')).length === UNIQUE,
      `1. first run: all ${UNIQUE} model files moved from the app folder into KBB Studio Data (app folder keeps only manifest.json)`);
    ok(exists(path.join(data, 'workspaces', 'acme', 'projects', 'p1', 'project.json')) && !exists(path.join(app, 'workspaces', 'acme')), '1. companies and projects moved');
    ok(exists(path.join(data, 'library', 'music', 'user', 'my-song.m4a')) && exists(path.join(data, 'library', 'people', 'nurse', 'pexels-1.jpg')), '1. user music and saved photos moved');
    ok(exists(path.join(data, 'brands', 'acme', 'kit.json')) && !exists(path.join(data, 'brands', 'kbb')) && exists(path.join(app, 'brands', 'kbb', 'kit.json')), '1. own brand kit moved, built-in kits stay in the app folder');
    const kmode = fs.statSync(path.join(data, 'settings', 'photo-sources.json')).mode & 0o777, smode = fs.statSync(path.join(data, 'settings')).mode & 0o777;
    ok(kmode === 0o600 && smode === 0o700, `1. keys moved and private: settings ${smode.toString(8)}, photo-sources.json ${kmode.toString(8)}`);
    const mlog = fs.readFileSync(path.join(data, 'migration.log'), 'utf8');
    ok(/models: brought \d+ files/.test(mlog) && /moved workspaces\/acme/.test(mlog), '1. migration.log says what moved: ' + mlog.trim().split('\n').length + ' lines');
    ok(/kept in ~\/KBB Studio Data and survive updates/.test(e.out), '1. the Terminal says where the data is kept');
    let v = await openApp(browser, e, '1-first-run-1280.png');
    ok(!v.setup, '1. the Setup screen does not show (every model already installed)');
    // pick a company in the UI: remembered by the engine, not by the browser
    await v.page.click('.ws-switch');
    await v.page.click('.ws-menu .ws-item:has-text("Planet Careers")'); // not the one open (Acme, the oldest, opens first)
    await v.page.waitForTimeout(800);
    const prefs1 = await api(e, '/api/prefs');
    ok(prefs1.ui?.['kbbstudio.workspace'] === 'planet-careers', '1. the company picked in the UI is saved in KBB Studio Data/settings/prefs.json: ' + JSON.stringify(prefs1.ui));
    ok(v.errors.length === 0, '1. no page errors' + (v.errors.length ? ': ' + v.errors.join(' | ') : ''));
    await v.ctx.close(); await stop(e);

    // ── 2. update: the app folder is replaced by a fresh copy ──
    freshApp(app);
    e = await start(home, app, port, LOG); engines.push(e);
    ok(e.url === origin1, `2. same port, same origin after a restart (${e.url})`);
    v = await openApp(browser, e, '2-after-update-1280.png');
    ok(!v.setup, '2. after replacing the app folder: the Setup screen does not show');
    const wsName = await v.page.textContent('.ws-switch .ws-name');
    ok(/Planet Careers/.test(wsName), `2. the company picked before the update is open again in a new browser profile (“${wsName.trim()}”)`);
    const wsList = (await api(e, '/api/list?path=workspaces')).map((x) => x.name);
    ok(wsList.includes('acme') && wsList.includes('kbb'), '2. companies still there: ' + wsList.join(', '));
    const keys = await api(e, '/api/photo-keys');
    ok(keys.pexels?.set === true, '2. the Pexels key is still set (' + (keys.pexels?.hint || '') + ')');
    const um = (await api(e, '/api/list?path=library/music/user')).map((x) => x.name);
    const builtIn = (await api(e, '/api/list?path=library/music')).map((x) => x.name);
    ok(um.includes('my-song.m4a') && builtIn.includes(song) && builtIn.includes('user'), '2. user music listed with the built-in music');
    const st1 = (await fetch(e.url + '/library/music/user/my-song.m4a')).status, st2 = (await fetch(e.url + '/library/music/' + song)).status;
    ok(st1 === 200 && st2 === 200, `2. both are served (user ${st1}, built-in ${st2})`);
    const br = (await api(e, '/api/list?path=brands')).map((x) => x.name);
    ok(br.includes('acme') && br.includes('kbb') && br.includes('planet-careers'), '2. brand kits: ' + br.join(', '));
    const people = (await api(e, '/api/list?path=library/people/nurse')).map((x) => x.name);
    ok(people.includes('pexels-1.jpg'), '2. saved photos still there');
    const proj = (await api(e, '/api/list?path=workspaces/acme/projects')).map((x) => x.name);
    ok(proj.includes('p1'), '2. projects still there');
    ok(['workspaces', 'settings'].every((d) => !exists(path.join(app, d))), '2. the app folder holds no user data');
    const ms = await api(e, '/api/models');
    ok(ms.models.every((m) => m.installed), `2. every model installed (${ms.models.length})`);
    ok(v.errors.length === 0, '2. no page errors' + (v.errors.length ? ': ' + v.errors.join(' | ') : ''));
    await v.ctx.close(); await stop(e);

    // ── 3. an older KBB Studio folder left in ~/Downloads ──
    const home3 = path.join(tmp, 'home3'), app3 = path.join(home3, 'Applications', 'KBB Studio');
    freshApp(app3);
    const old = freshApp(path.join(home3, 'Downloads', 'KBB Studio'));
    putModels(old);
    const decoy = path.join(home3, 'Documents', 'Some folder');
    fs.mkdirSync(path.join(decoy, 'models'), { recursive: true });
    fs.copyFileSync(path.join(H.ROOT, 'models', 'manifest.json'), path.join(decoy, 'models', 'manifest.json'));
    putModels(decoy);
    e = await start(home3, app3, await freePort(), LOG); engines.push(e);
    const data3 = path.join(home3, 'KBB Studio Data');
    ok(modelFilesIn(path.join(data3, 'models')).length === UNIQUE && modelFilesIn(path.join(old, 'models')).length === 0, '3. the models of the old folder in ~/Downloads were brought into KBB Studio Data');
    ok(modelFilesIn(path.join(decoy, 'models')).length === UNIQUE, '3. a folder without engine/kbbstudio-mac-* is not taken for a KBB Studio folder');
    ok(fs.readFileSync(path.join(data3, 'migration.log'), 'utf8').includes(path.join('Downloads', 'KBB Studio')), '3. migration.log names the old folder');
    v = await openApp(browser, e);
    ok(!v.setup, '3. no Setup screen');
    await v.ctx.close(); await stop(e);

    // ── 4. a wrong-size model file is never counted as installed ──
    const home4 = path.join(tmp, 'home4'), app4 = path.join(home4, 'KBB Studio');
    freshApp(app4);
    const req = files.find((f) => f.required && f.size > 1000);
    putModels(app4, { short: [req.rel] });
    put(path.join(app4, 'models', files.find((f) => f.required && f.model !== req.model).rel + '.part'), 'half'); // an unfinished download beside a finished one: ignored (the finished one wins)
    const port4 = await freePort();
    e = await start(home4, app4, port4, LOG); engines.push(e);
    const data4 = path.join(home4, 'KBB Studio Data');
    ok(!exists(path.join(data4, 'models', req.rel)) && fs.statSync(path.join(app4, 'models', req.rel)).size === req.size - 1, `4. ${req.rel} (one byte short) was not moved as if it were whole`);
    const ms4 = await api(e, '/api/models');
    ok(ms4.models.find((m) => m.id === req.model).installed === false && ms4.models.filter((m) => m.id !== req.model).every((m) => m.installed), `4. ${req.model} is not installed; the others are`);
    v = await openApp(browser, e, '4-setup-1280.png');
    ok(v.setup, '4. the Setup screen shows for the missing model');
    const txt = await v.page.textContent('.setup-form');
    ok(txt.includes('Your models are kept in KBB Studio Data and survive updates.') && txt.includes('~/KBB Studio Data/models'), '4. Setup says the models are kept in KBB Studio Data and where');
    const row = await v.page.textContent(`.model-row[data-model="${req.model}"] .msize`);
    ok(!row.includes('Installed'), `4. the row reads “${row.trim()}”, not Installed`);
    await v.page.click('#su-later');
    await v.page.waitForSelector('.ws-switch', { timeout: 10000 });
    await v.page.waitForTimeout(800);
    await v.ctx.close(); await stop(e);
    e = await start(home4, app4, port4, LOG); engines.push(e);
    v = await openApp(browser, e);
    ok(!v.setup, '4. "Later" pressed in the UI survives a restart in a new browser profile (Setup does not come back today)');
    await v.ctx.close(); await stop(e);

    // ── 5. the preferred port is busy ──
    const busy = net.createServer().listen(port4, '127.0.0.1');
    await new Promise((r) => busy.on('listening', r));
    e = await start(home4, app4, port4, LOG); engines.push(e);
    const got = +new URL(e.url).port;
    ok(got !== port4 && got > 0 && (await fetch(e.url + '/app/')).status === 200, `5. port ${port4} busy: the engine took ${got} and serves the app`);
    ok(/port \d+ is busy/.test(e.log), '5. it says so in the Terminal');
    await stop(e);
    busy.close();
  } catch (err) {
    console.error(err);
    failures++;
  } finally {
    for (const e of engines) await H.stopEngine(e);
    await browser.close();
    fs.rmSync(tmp, { recursive: true, force: true });
  }
  console.log(failures ? `\n${failures} FAILED` : '\nall passed');
  process.exit(failures ? 1 : 0);
})();
