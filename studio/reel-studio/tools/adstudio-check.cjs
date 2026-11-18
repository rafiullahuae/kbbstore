#!/usr/bin/env node
// KBB Studio — the Ad Studio inside KBB Studio (18 Nov 2026). The owner: "why i can't see any ads studio here, like the
// one u gave me and i made several ads from it like national day, along with video editor, i need also the Ads studio too."
//
// Runs the Linux engine as "Start KBB Studio.command" runs the Mac one (--dir <app> --data <home>/KBB Studio Data, a
// throwaway HOME) and headless Chromium:
//   1. the home screen has the 🎨 Ad Studio tile and the top-bar entry; each opens the Ad Studio, served by the engine
//      (COOP/COEP on), saving to the data folder; "◀ KBB Studio" comes back; the company picked in KBB Studio is the one
//      it opens on, and a switch in the Ad Studio is kept in KBB Studio
//   2. a design made and saved lands on disk in KBB Studio Data/adstudio (projects/ and images/)
//   3. the engine restarted and a NEW browser profile (no browser storage): the design is still there and opens
//   4. the old standalone file (file://, docs/plans/ad-studio/dist/ad-studio.html) with designs in it shows the banner;
//      Backup everything -> Import backup inside KBB Studio gives the same designs (ids, names, elements); importing it a
//      second time adds nothing
//   5. ✂ cut-out under COOP/COEP (the MediaPipe WASM from ad-studio-cutout.js), PNG export and video export
//   6. zero console errors, no request leaves 127.0.0.1
// Pictures (1440×900) -> shots/adstudio/.
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/adstudio-check.cjs
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');
const H = require('./studio-harness.cjs');

const SHOTS = path.join(H.ROOT, 'shots', 'adstudio');
fs.mkdirSync(SHOTS, { recursive: true });
const STANDALONE = path.resolve(H.ROOT, '..', '..', 'docs', 'plans', 'ad-studio', 'dist', 'ad-studio.html');
const VIEW = { width: 1440, height: 900 };
let failures = 0;
const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) failures++; };

const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'kbbas-'));
const HOME = path.join(TMP, 'home'), APP = H.makeStudio(), DATA = path.join(HOME, 'KBB Studio Data');
fs.mkdirSync(HOME, { recursive: true });

function engine() {
  return new Promise((res, rej) => {
    const p = spawn(H.BIN, ['--dir', APP, '--data', DATA, '--no-browser', '--idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { HOME }) });
    let buf = '';
    p.stdout.on('data', async (d) => {
      buf += d;
      const m = /running at (http:\/\/[^\s]+)\/app\//.exec(buf);
      if (m && !p.url) { p.url = m[1]; res(p); }
    });
    p.stderr.on('data', (d) => { p.log = (p.log || '') + d; });
    p.on('exit', (c) => { p.exited = true; if (!p.url) rej(new Error('engine exited ' + c + ' ' + (p.log || ''))); });
  });
}
const PERSON = `(() => { const c = document.createElement('canvas'); c.width = 800; c.height = 1000; const x = c.getContext('2d');
  const g = x.createLinearGradient(0, 0, 800, 1000); g.addColorStop(0, '#d9c3a5'); g.addColorStop(.55, '#b98e6a'); g.addColorStop(1, '#5b6f73'); x.fillStyle = g; x.fillRect(0, 0, 800, 1000);
  for (let i = 0; i < 18; i++) { x.fillStyle = 'hsla(' + (30 + i * 23) % 360 + ',55%,' + (55 + (i % 3) * 10) + '%,.35)'; x.beginPath(); x.arc((i * 97) % 800, (i * 151) % 1000, 30 + (i % 4) * 18, 0, 7); x.fill(); }
  const cx = 400; x.fillStyle = '#1f3b70'; x.beginPath(); x.moveTo(cx - 280, 1000); x.bezierCurveTo(cx - 280, 700, cx - 220, 640, cx - 100, 630); x.lineTo(cx + 100, 630); x.bezierCurveTo(cx + 220, 640, cx + 280, 700, cx + 280, 1000); x.fill();
  x.fillStyle = '#d9a07e'; x.fillRect(cx - 50, 500, 100, 150);
  x.fillStyle = '#2b1a12'; x.beginPath(); x.ellipse(cx, 380, 140, 170, 0, 0, 7); x.fill();
  x.fillStyle = '#e2ad8c'; x.beginPath(); x.ellipse(cx, 415, 118, 150, 0, 0, 7); x.fill();
  for (const s of [-1, 1]) { x.fillStyle = '#fff'; x.beginPath(); x.ellipse(cx + s * 46, 400, 22, 12, 0, 0, 7); x.fill(); x.fillStyle = '#3a2416'; x.beginPath(); x.arc(cx + s * 46, 400, 9, 0, 7); x.fill(); }
  x.fillStyle = '#b2484f'; x.beginPath(); x.ellipse(cx, 500, 34, 9, 0, 0, 7); x.fill();
  return c.toDataURL('image/jpeg', .92); })()`;

const errors = [], offsite = [];
function watch(page, label) {
  // MediaPipe's own start-up line arrives as console.error (the same filter as docs/plans/ad-studio/tools/check-occupations.cjs)
  page.on('console', (m) => { if (m.type() === 'error' && !/^INFO: Created TensorFlow Lite XNNPACK delegate/.test(m.text())) errors.push(label + ': ' + m.text() + (m.location() && m.location().url ? ' @ ' + m.location().url.replace(/^http:\/\/[^/]+/, '') : '')); });
  page.on('pageerror', (e) => errors.push(label + ': ' + e.message));
  page.on('response', (r) => { if (r.status() >= 400) errors.push(label + ': HTTP ' + r.status() + ' ' + r.request().method() + ' ' + r.url().replace(/^http:\/\/[^/]+/, '')); });
  page.on('request', (r) => { if (!/^(data|blob|file):/.test(r.url()) && !/^http:\/\/(127\.0\.0\.1|localhost):\d+\//.test(r.url())) offsite.push(r.url()); });
  page.on('dialog', (d) => d.accept());
  return page;
}
async function newPage(browser, label) {
  const ctx = await browser.newContext({ viewport: VIEW, acceptDownloads: true });
  // the models are not what this checks: "Later" on the Setup screen, as the owner can press it
  await ctx.addInitScript(() => { try { if (location.protocol !== 'file:') localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  return { ctx, page: watch(await ctx.newPage(), label) };
}
const asReady = (p) => p.waitForFunction(() => document.documentElement.dataset.ready === '1', null, { timeout: 60000 });
async function homeReady(p, url) { await p.goto(url + '/app/'); await p.waitForSelector('#home-adstudio', { timeout: 60000 }); }
async function download(p, click, dir) {
  const [d] = await Promise.all([p.waitForEvent('download', { timeout: 180000 }), click()]);
  const f = path.join(dir || TMP, d.suggestedFilename()); await d.saveAs(f); return f;
}
const files = (d) => (fs.existsSync(d) ? fs.readdirSync(d).filter((n) => /\.json$/.test(n)) : []);

(async () => {
  const browser = await H.launchChrome();
  let e = await engine();
  const URL0 = e.url;
  ok(fs.existsSync(path.join(DATA, 'adstudio')), 'the engine made KBB Studio Data/adstudio');

  // ── 1. home: the tile and the top-bar entry
  let { ctx, page: p } = await newPage(browser, 'studio');
  await homeReady(p, URL0);
  const tile = await p.evaluate(() => { const t = document.querySelector('#home-adstudio'), b = document.querySelector('#adstudio-btn'), d = document.querySelector('[data-mod-home="designs"] small');
    return { tile: !!t && t.innerText, btn: !!b && b.textContent, inTop: !!b && !!b.closest('.topbar'), nextTo: !!b && !!document.querySelector('#configure-btn') && b.parentElement === document.querySelector('#configure-btn').parentElement, designs: d && d.textContent }; });
  ok(tile.tile && /Ad Studio/.test(tile.tile) && /templates and saved ads/.test(tile.tile), 'home: the Ad Studio tile says what it is — ' + JSON.stringify((tile.tile || '').split('\n').slice(0, 2)));
  ok(tile.btn === '🎨 Ad Studio' && tile.inTop && tile.nextTo, 'home: "🎨 Ad Studio" in the top bar next to Configure / Models');
  ok(/from a sentence/.test(tile.designs || '') && /TV-style and family templates/.test(tile.designs || ''), 'home: Brief → Designs says what it is — ' + tile.designs);
  await p.screenshot({ path: path.join(SHOTS, '1-home-tile.png') });
  // the company picked in KBB Studio is the one the Ad Studio opens on
  await p.evaluate(() => localStorage.setItem('kbbstudio.workspace', 'planet-careers'));
  await p.click('#home-adstudio-open');
  await p.waitForURL(/\/app\/adstudio\/ad-studio\.html$/); await asReady(p);
  const in1 = await p.evaluate(() => ({ kind: window.KBB_STUDIO.store.kind(), co: window.KBB_STUDIO.company().id, back: !document.querySelector('#kbb-back').hidden, where: document.querySelector('#where').textContent, coi: self.crossOriginIsolated }));
  ok(in1.kind === 'disk', 'the tile opens the Ad Studio, saving through the engine (' + in1.kind + '): ' + in1.where);
  ok(in1.coi === true, 'it runs cross-origin isolated (COOP/COEP from the engine) and still starts');
  ok(in1.co === 'planet-careers', 'it opens on the company picked in KBB Studio (' + in1.co + ')');
  ok(in1.back, '"◀ KBB Studio" is shown inside KBB Studio');
  await p.waitForSelector('#mv:not([hidden])', { timeout: 10000 }).catch(() => {});
  ok(await p.isVisible('#mv') && /Import backup/.test(await p.textContent('#mv')), 'with nothing saved yet it offers Import backup (for the designs in the old file)');
  await p.evaluate(() => window.KBB_STUDIO.setCompany('kbb'));
  await p.waitForFunction(() => localStorage.getItem('kbbstudio.workspace') === 'kbb');
  ok(true, 'a company switch in the Ad Studio is kept in KBB Studio');
  await p.click('#kbb-back'); await p.waitForSelector('#home-adstudio', { timeout: 60000 });
  ok(/\/app\/(#\/)?$/.test(p.url()) && /KBB/.test(await p.textContent('.ws-name')), '"◀ KBB Studio" goes back to the home screen, on KBB (' + await p.textContent('.ws-name') + ')');
  await p.click('#adstudio-btn'); await p.waitForURL(/\/app\/adstudio\/ad-studio\.html$/); await asReady(p);
  ok(await p.evaluate(() => window.KBB_STUDIO.store.kind() === 'disk' && window.KBB_STUDIO.company().id === 'kbb'), 'the top-bar entry opens it too, on KBB');

  // ── 2. make a design (a template, a picture, a name) and save it: on disk
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('flash-sale'));
  await p.fill('#pname', 'Disk test ad'); await p.locator('#pname').blur();
  const jpg = path.join(TMP, 'worker.jpg'); fs.writeFileSync(jpg, Buffer.from((await p.evaluate(PERSON)).split(',')[1], 'base64'));
  await p.click('#save'); await p.waitForFunction(() => /^Saved/.test(document.querySelector('#status').textContent), null, { timeout: 30000 });
  const savedId = await p.evaluate(() => window.KBB_STUDIO.doc.id);
  const onDisk = path.join(DATA, 'adstudio', 'projects', savedId + '.json');
  ok(fs.existsSync(onDisk) && JSON.parse(fs.readFileSync(onDisk, 'utf8')).doc.name === 'Disk test ad', 'Save writes the design to KBB Studio Data/adstudio/projects/' + savedId + '.json');
  ok(fs.existsSync(path.join(DATA, 'adstudio', 'kv', 'company.json')), 'the picked company is on disk too (adstudio/kv/company.json)');

  // ── 5a. cut-out under COOP/COEP: a worker photo slot (Work Abroad), his photo, ✂
  await p.evaluate(() => window.KBB_STUDIO.setCompany('planet-careers')); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers');
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('wa-hire-chef')); await p.click('.tab[data-tab="edit"]'); await p.evaluate(() => window.KBB_STUDIO.setSel(['fig']));
  await p.setInputFiles('#ph-file', jpg); await p.waitForFunction(() => window.KBB_STUDIO.doc.els.find((x) => x.id === 'fig').t === 'img');
  await p.evaluate(() => window.KBB_STUDIO.setSel(['fig']));
  const t0 = Date.now();
  await p.click('#ph-cut');
  await p.waitForFunction(() => /Cut out —|Not cut out/.test(document.querySelector('#status').textContent), null, { timeout: 120000 });
  const cut = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, el = S.doc.els.find((x) => x.id === 'fig'), src = S.images[el.img] && S.images[el.img].src; if (!src) return { err: document.querySelector('#status').textContent };
    const im = new Image(); im.src = src; await im.decode(); const c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight; const x = c.getContext('2d'); x.drawImage(im, 0, 0);
    const a = (fx, fy) => x.getImageData(Math.round(fx * c.width), Math.round(fy * c.height), 1, 1).data[3];
    return { name: el.name, img: el.img, corners: [a(.03, .03), a(.97, .03)], person: [a(.5, .42), a(.5, .8)] };
  });
  ok(cut.name === 'Worker photo (cut out)' && cut.corners.every((v) => v < 30) && cut.person.every((v) => v > 220), `✂ cut-out works under COOP/COEP in ${((Date.now() - t0) / 1000).toFixed(1)} s ` + JSON.stringify(cut));
  await p.click('#save'); await p.waitForFunction(() => /^Saved/.test(document.querySelector('#status').textContent), null, { timeout: 30000 });
  ok(cut.img && fs.existsSync(path.join(DATA, 'adstudio', 'images', cut.img + '.json')), 'the photo and its cut-out are pictures on disk (adstudio/images/, ' + files(path.join(DATA, 'adstudio', 'images')).length + ' files)');
  const pcId = await p.evaluate(() => window.KBB_STUDIO.doc.id);
  ok(pcId !== savedId, 'two different designs (' + savedId + ', ' + pcId + ')');

  // ── 5b. PNG and video export
  await p.click('.tab[data-tab="export"]');
  let f = await download(p, () => p.click('#dl-one'));
  const png = fs.readFileSync(f);
  ok(png.slice(1, 4).toString() === 'PNG' && png.readUInt32BE(16) === 1080 && png.readUInt32BE(20) === 1350, 'PNG export: ' + path.basename(f) + ' ' + png.readUInt32BE(16) + '×' + png.readUInt32BE(20));
  f = await download(p, () => p.evaluate(() => { window.KBB_STUDIO.recordVideo(2).then((b) => { window.__vid = b ? [b.size, b.type] : null; }); }));
  const vid = await p.evaluate(() => window.__vid), head = fs.readFileSync(f).slice(4, 8).toString();
  ok(vid && vid[0] > 10000 && (head === 'ftyp' || /webm$/.test(f)), 'video export: ' + path.basename(f) + ' ' + JSON.stringify(vid));
  await p.evaluate(() => window.KBB_STUDIO.setCompany('kbb')); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'kbb');
  await p.waitForTimeout(300);
  await ctx.close();

  // ── 3. engine restart + a new browser profile: still there
  await H.stopEngine(e); e = await engine();
  ({ ctx, page: p } = await newPage(browser, 'restart'));
  await p.goto(e.url + '/app/adstudio/ad-studio.html'); await asReady(p);
  const back = await p.evaluate(() => window.KBB_STUDIO.store.listProjects());
  ok([savedId, pcId].every((id) => back.some((x) => x.id === id)), 'after an engine restart, in a fresh browser profile, both designs are listed (' + back.map((x) => x.name).join(', ') + ')');
  ok(await p.locator('#mine .it', { hasText: 'Disk test ad' }).count() === 1, '"Disk test ad" is in My designs');
  await p.click('.tab[data-tab="tpl"]'); await p.evaluate(() => { document.querySelector('#mine-d').open = true; });
  await p.locator('#mine .it', { hasText: 'Disk test ad' }).getByRole('button', { name: 'Open' }).click();
  await p.waitForFunction((id) => window.KBB_STUDIO.doc.id === id, savedId);
  ok(await p.inputValue('#pname') === 'Disk test ad', 'and it opens');
  await ctx.close();

  // ── 4. the old standalone file: banner, Backup everything
  ({ ctx, page: p } = await newPage(browser, 'standalone'));
  await p.goto('file://' + STANDALONE); await asReady(p);
  ok(await p.evaluate(() => window.KBB_STUDIO.store.kind()) === 'browser' && await p.isHidden('#kbb-back'), 'the standalone file still saves in the browser, no "◀ KBB Studio"');
  ok(await p.isHidden('#mv'), 'an empty standalone file shows no banner');
  // the owner's designs: two KBB National Day ads, a Planet Careers one, a new company, a favourite, KBB's brand kit
  const made = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, ids = [], name = (v) => { const n = document.querySelector('#pname'); n.value = v; n.dispatchEvent(new Event('input')); n.dispatchEvent(new Event('change')); };
    const nd = S.templates.filter((t) => /^nd-/.test(t.id)).map((t) => t.id);
    // as he works: a template, his words, Save; the next one from another template, "Save as a copy", renamed, Save
    const one = async (tpl, n, first) => { S.useTemplate(tpl); if (!first) { document.querySelector('#saveas').click(); await new Promise((r) => setTimeout(r, 300)); } name(n); await S.saveNow(); ids.push(S.doc.id); };
    await one(nd[0] || 'flash-sale', 'National Day 30% off', true);
    await one(nd[1] || 'flash-sale', 'National Day — Anua');
    await S.setCompany('planet-careers'); await one('wa-hire-chef', 'Chefs for Poland', true);
    return { ids, nd };
  });
  ok(new Set(made.ids).size === 3, 'three designs saved in the old file (' + made.nd.length + ' National Day templates there)');
  await p.evaluate(() => window.KBB_STUDIO.setCompany('kbb'));
  // a favourite (the ★ on a template) and a company of his own, through the company file the studio already reads
  await p.evaluate(async () => {
    const S = window.KBB_STUDIO, c = JSON.parse(JSON.stringify(S.company())); c.studio.favs = [S.templates[3].id];
    await S.importCompany(JSON.stringify({ app: 'kbb-studio', kind: 'company', v: 1, kit: { id: 'gulf-perfumes', name: 'Gulf Perfumes', fullName: 'Gulf Perfumes LLC', industry: 'Perfume retail', colors: { primary: '#5a2a82', secondary: '#c9a13b', accent: '#e8306f', soft: '#f6effa', ink: '#1d1222' } }, studio: { tplSet: 'kbb', favs: [S.templates[5].id] }, projects: [], images: [] }));
    await S.setCompany('kbb');
  });
  await p.reload(); await asReady(p); await p.waitForSelector('#mv:not([hidden])', { timeout: 10000 }).catch(() => {});
  ok(await p.isVisible('#mv') && /Ad Studio now lives inside KBB Studio\. Click Backup everything, then in KBB Studio → Ad Studio → Import backup\./.test(await p.textContent('#mv')), 'with saved designs the standalone file shows the banner: ' + (await p.textContent('#mv-t')));
  await p.screenshot({ path: path.join(SHOTS, '3-standalone-banner.png') });
  const want = await p.evaluate(async () => { const S = window.KBB_STUDIO, l = await S.store.listProjects(); return Promise.all(l.map(async (x) => ({ id: x.id, name: x.name, co: x.co, doc: await S.store.getProject(x.id) }))); });
  const wantCos = await p.evaluate(() => window.KBB_STUDIO.companies().map((c) => c.id));
  const bk = await download(p, () => p.click('#mv-b'));
  const bj = JSON.parse(fs.readFileSync(bk, 'utf8'));
  ok(bj.app === 'kbb-ad-studio-backup' && bj.projects.length === want.length && bj.counts.companies >= 3, 'Backup everything (banner button): ' + path.basename(bk) + ' ' + JSON.stringify(bj.counts) + ', ' + Math.round(fs.statSync(bk).size / 1024) + ' KB');
  await p.click('.tab[data-tab="export"]'); ok(await p.isVisible('#bk-all') && await p.isVisible('label[for="bk-in"]'), 'Backup everything (.json) and Import backup… are in the Export tab as well');
  const proj = await download(p, () => p.click('#xp'));   // the old way to move designs: one project file
  await ctx.close();

  // ── 4b. Import backup inside KBB Studio, twice
  ({ ctx, page: p } = await newPage(browser, 'import'));
  await p.goto(e.url + '/app/adstudio/ad-studio.html'); await asReady(p);
  const before = (await p.evaluate(() => window.KBB_STUDIO.store.listProjects())).length;
  await p.setInputFiles('#bk-in', bk);
  await p.waitForFunction(() => /Backup imported|Not imported/.test(document.querySelector('#status').textContent), null, { timeout: 120000 });
  const s1 = await p.textContent('#status');
  ok(new RegExp('^Backup imported: ' + want.length + ' new design').test(s1), 'Import backup: ' + s1);
  const got = await p.evaluate(async () => { const S = window.KBB_STUDIO, l = await S.store.listProjects(); return Promise.all(l.map(async (x) => ({ id: x.id, name: x.name, co: x.co, doc: await S.store.getProject(x.id) }))); });
  const same = want.every((w) => { const g = got.find((x) => x.id === w.id); return g && g.name === w.name && g.co === w.co && JSON.stringify(g.doc.els) === JSON.stringify(w.doc.els); });
  ok(same && got.length === before + want.length, 'the same designs (ids, names, companies, every element): ' + want.map((w) => w.name).join(' · '));
  ok(want.every((w) => fs.existsSync(path.join(DATA, 'adstudio', 'projects', w.id + '.json'))), 'and they are on disk in KBB Studio Data/adstudio/projects');
  const cosNow = await p.evaluate(() => window.KBB_STUDIO.companies().map((c) => ({ id: c.id, favs: c.studio.favs })));
  ok(wantCos.every((id) => cosNow.some((c) => c.id === id)), 'the companies came along: ' + cosNow.map((c) => c.id).join(', '));
  ok((cosNow.find((c) => c.id === 'gulf-perfumes') || { favs: [] }).favs.length === 1, 'with their favourites');
  await p.click('.tab[data-tab="tpl"]'); await p.evaluate(() => { document.querySelector('#mine-d').open = true; document.querySelector('#mine-d').scrollIntoView({ block: 'center' }); window.scrollTo(0, 0); });
  await p.screenshot({ path: path.join(SHOTS, '4-import-result.png') });
  await p.setInputFiles('#bk-in', bk);
  await p.waitForFunction((s) => document.querySelector('#status').textContent !== s && /Backup imported|Not imported/.test(document.querySelector('#status').textContent), s1, { timeout: 120000 }).catch(() => {});
  const s2 = await p.textContent('#status');
  const n2 = (await p.evaluate(() => window.KBB_STUDIO.store.listProjects())).length;
  ok(/^Backup imported: 0 new design/.test(s2) && n2 === got.length && files(path.join(DATA, 'adstudio', 'projects')).length === n2, 'a second import adds nothing: ' + s2 + ' (' + n2 + ' designs)');
  // the old file opened as a project file (Import project…) still works
  // a backup dropped on the page imports the same way (and adds nothing a third time)
  const s3 = await p.evaluate(async (text) => {
    const dt = new DataTransfer(); dt.items.add(new File([text], 'KBB-Ad-Studio-backup.json', { type: 'application/json' }));
    const before = document.querySelector('#status').textContent;
    document.querySelector('#stage').dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }));
    document.querySelector('#stage').dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
    for (let i = 0; i < 200 && document.querySelector('#status').textContent === before; i++) await new Promise((r) => setTimeout(r, 50));
    for (let i = 0; i < 200 && !/Backup imported|Not imported/.test(document.querySelector('#status').textContent); i++) await new Promise((r) => setTimeout(r, 50));
    return document.querySelector('#status').textContent;
  }, fs.readFileSync(bk, 'utf8'));
  ok(/^Backup imported: 0 new design/.test(s3), 'a backup dropped on the page imports too: ' + s3);
  // the project file (Import project…) still works, and keeps its designs' ids (no copies)
  await p.click('.tab[data-tab="export"]');
  await p.setInputFiles('#xp-in', proj);
  await p.waitForFunction(() => /^Imported \d+ design|Not imported/.test(document.querySelector('#status').textContent), null, { timeout: 60000 });
  const s4 = await p.textContent('#status'), n4 = (await p.evaluate(() => window.KBB_STUDIO.store.listProjects())).length;
  ok(/^Imported [1-9]\d* design/.test(s4) && n4 === n2, 'Import project… still imports a project .json: ' + s4 + ' (' + n4 + ' designs, no copies)');
  // Ad Studio open inside KBB Studio, for the picture
  await p.evaluate(() => window.KBB_STUDIO.setCompany('kbb'));
  await p.click('.tab[data-tab="tpl"]').catch(() => {});
  await p.waitForTimeout(400);
  await p.screenshot({ path: path.join(SHOTS, '2-adstudio-inside.png') });
  await ctx.close();

  // ── 6. console and network
  ok(errors.length === 0, 'zero console errors' + (errors.length ? ':\n      ' + errors.slice(0, 10).join('\n      ') : ''));
  ok(offsite.length === 0, 'nothing left 127.0.0.1' + (offsite.length ? ': ' + offsite.slice(0, 5).join(' ') : ''));

  await browser.close();
  await H.stopEngine(e);
  fs.rmSync(TMP, { recursive: true, force: true }); fs.rmSync(APP, { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAILED` : '\nall passed');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
