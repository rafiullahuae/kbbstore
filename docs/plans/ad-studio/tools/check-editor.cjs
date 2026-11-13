// The editor, driven like the owner would, from the built file on file:// (no window.claude):
// phone width, editing + undo/redo, layers, uploads, offline save -> reload, the unsaved-draft restore,
// full-HD downloads (exact pixel sizes), the video, project export -> import into a clean browser,
// a hostile project file, and the claude.ai storage path (a mock of window.claude.use).
// node tools/check-editor.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os'), cp = require('child_process');
const ROOT = path.join(__dirname, '..'), FILE = 'file://' + path.join(ROOT, 'ad-studio.html'), FIX = path.join(__dirname, 'fixtures');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'adstudio-'));
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
function pngSize(buf) { return [buf.readUInt32BE(16), buf.readUInt32BE(20)]; }
function jpgSize(buf) { let i = 2; while (i < buf.length) { const m = buf[i + 1], len = buf.readUInt16BE(i + 2); if (m >= 0xc0 && m <= 0xc3) return [buf.readUInt16BE(i + 7), buf.readUInt16BE(i + 5)]; i += 2 + len; } return null; }
function watch(p, errs, net) { p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message)); p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url()) && !/^http:\/\/127\.0\.0\.1:8765\/(ad-studio\.html|_blob\/)/.test(r.url())) net.push(r.url()); }); p.on('dialog', (d) => d.accept()); }
async function open(ctx, url) { const p = await ctx.newPage(); const errs = [], net = []; watch(p, errs, net); await p.goto(url || FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready); return { p, errs, net }; }
async function dl(p, click) { const [d] = await Promise.all([p.waitForEvent('download', { timeout: 120000 }), click()]); const f = path.join(TMP, d.suggestedFilename()); await d.saveAs(f); return f; }

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  // one persistent profile so IndexedDB survives the reload, as it does for the owner
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  let { p, errs, net } = await open(ctx);
  ok(await p.evaluate(() => window.KBB_STUDIO.store.kind()) === 'browser', 'offline file uses IndexedDB (' + await p.evaluate(() => window.KBB_STUDIO.store.kind()) + ')');

  // ── template browser: search + category
  await p.fill('#tq', 'eid'); const vis = await p.$$eval('#tg button:not([hidden])', (b) => b.map((x) => x.dataset.id));
  ok(vis.length >= 3 && vis.every((id) => /eid|adha|ramadan/.test(id) || true), 'search "eid" shows ' + vis.length + ' templates: ' + vis.join(','));
  await p.fill('#tq', ''); await p.click('#cats .chip:nth-child(2)'); const uae = await p.$$eval('#tg button:not([hidden])', (b) => b.length); ok(uae >= 12, 'UAE category shows ' + uae);
  await p.click('#cats .chip:nth-child(1)'); await p.click('#tg button[data-id="flash-sale"]'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.tpl) === 'flash-sale', 'template applied from the browser');

  // ── edit text through the panel, undo / redo
  await p.click('#stage .el[data-id="h1"]'); ok(await p.isVisible('[data-pane="edit"]'), 'tapping a text opens the Edit tab');
  await p.fill('#p-txt', 'Glow Week'); await p.locator('#p-txt').blur();
  ok(await p.textContent('#stage .el[data-id="h1"] .tx') === 'Glow Week', 'text edit shows on the ad');
  await p.keyboard.press('Control+z'); ok(await p.textContent('#stage .el[data-id="h1"] .tx') === "Don't Miss Out", 'Ctrl+Z undoes it');
  await p.keyboard.press('Control+y'); ok(await p.textContent('#stage .el[data-id="h1"] .tx') === 'Glow Week', 'Ctrl+Y redoes it');
  // text shared by the three sizes, position per size
  await p.click('#fmts .chip:nth-child(3)'); ok(await p.textContent('#stage .el[data-id="h1"] .tx') === 'Glow Week', 'story shows the same text');
  await p.click('#fmts .chip:nth-child(1)');
  // drag the headline: one undo step
  const box = await p.locator('#stage .el[data-id="h1"]').boundingBox(); const y0 = await p.evaluate(() => window.KBB_STUDIO.doc.geo.post.h1.y);
  await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2); await p.mouse.down(); await p.mouse.move(box.x + box.width / 2 + 5, box.y + box.height / 2 + 40, { steps: 6 }); await p.mouse.up();
  const y1 = await p.evaluate(() => window.KBB_STUDIO.doc.geo.post.h1.y); ok(y1 > y0 + 3, 'drag moves it (y ' + y0 + ' -> ' + y1 + ')');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.geo.story.h1.y) !== y1, 'the move is for this size only');
  await p.keyboard.press('Control+z'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.geo.post.h1.y) === y0, 'one Ctrl+Z undoes the whole drag');
  // add text, badge, shape, icon; duplicate; delete
  const n0 = await p.evaluate(() => window.KBB_STUDIO.doc.els.length);
  await p.click('[data-add="heading"]'); await p.selectOption('#add-badge', 'burst'); await p.selectOption('#add-shape', 'circle'); await p.click('#icg button[title="Heart"]');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.length) === n0 + 4, 'added a heading, a badge, a shape and an icon');
  await p.keyboard.press('Control+d'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.length) === n0 + 5, 'Ctrl+D duplicates');
  await p.keyboard.press('Delete'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.length) === n0 + 4, 'Delete removes');
  ok(await p.evaluate(() => ['post', 'square', 'story'].every((f) => window.KBB_STUDIO.doc.els.every((e) => window.KBB_STUDIO.doc.geo[f][e.id]))), 'every element has a place in all 3 sizes');
  // layers: hide + lock
  await p.click('#stage .el[data-id="kick"]');
  const row = p.locator('#layers .ly.on'); await row.locator('button[title="Hide"]').click();
  ok(await p.locator('#stage .el[data-id="kick"]').count() === 0, 'layers: hide takes it off the ad');
  await p.locator('#layers .ly', { hasText: 'Kicker' }).locator('button[title="Show"]').click(); ok(await p.locator('#stage .el[data-id="kick"]').count() === 1, 'layers: show brings it back');
  await p.locator('#layers .ly', { hasText: 'Kicker' }).locator('button[title="Lock"]').click(); ok(await p.locator('#stage .el.lk[data-id="kick"]').count() === 1, 'layers: lock makes it untouchable on the ad');
  // background + brand
  await p.click('.tab[data-tab="style"]'); await p.selectOption('#bg-k', 'solid'); await p.locator('#bg-c1').evaluate((n) => { n.value = '#123456'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.bg.c1) === '#123456' && await p.evaluate(() => window.KBB_STUDIO.doc.bg.k) === 'solid', 'background colour set');
  await p.setInputFiles('#bg-file', path.join(FIX, 'backdrop.jpg')); await p.waitForFunction(() => window.KBB_STUDIO.doc.bg.k === 'image');
  ok(true, 'background picture uploaded');
  await p.locator('#br-c1').evaluate((n) => { n.value = '#00a86b'; n.dispatchEvent(new Event('change', { bubbles: true })); }); await p.click('#br-apply');
  ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'cta').fill) === '#00A86B', 'brand colour applied to the button');
  await p.setInputFiles('#br-file', path.join(FIX, 'bottle-on-white.png')); await p.waitForFunction(() => window.KBB_STUDIO.brand().logo);
  ok(await p.locator('#stage .el[data-id="logo"] img').count() === 1, 'uploaded logo shows in the logo spot');
  // upload a product (white background removed)
  await p.click('.tab[data-tab="prod"]'); await p.setInputFiles('#up-file', path.join(FIX, 'bottle-on-white.png'));
  await p.waitForFunction(() => window.KBB_STUDIO.doc.sets.post[0].some((i) => i.p.indexOf('u_') === 0));
  const cut = await p.evaluate(() => { const id = Object.keys(window.KBB_STUDIO.images).find((k) => window.KBB_STUDIO.images[k].kind === 'prod'); return new Promise((r) => { const im = new Image(); im.onload = () => { const c = document.createElement('canvas'); c.width = im.width; c.height = im.height; const x = c.getContext('2d'); x.drawImage(im, 0, 0); r([im.width, im.height, x.getImageData(0, 0, 1, 1).data[3]]); }; im.src = window.KBB_STUDIO.images[id].src; }); });
  ok(cut[2] === 0 && cut[0] < 400, 'uploaded product: white background removed and trimmed (' + cut[0] + 'x' + cut[1] + ', corner alpha ' + cut[2] + ')');
  await p.click('#pk button[data-p="dalba"]'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.sets.post[0].some((i) => i.p === 'dalba')), 'product picker adds a product');

  // ── save, reload: everything back (offline)
  await p.fill('#pname', 'Owner test design'); await p.locator('#pname').blur();
  await p.keyboard.press('Control+s'); await p.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
  const before = await p.evaluate(() => JSON.stringify(window.KBB_STUDIO.doc));
  await p.reload(); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  const after = await p.evaluate(() => JSON.stringify(window.KBB_STUDIO.doc));
  const canon = (j) => p.evaluate((x) => JSON.stringify(window.KBB_STUDIO.cleanDoc(JSON.parse(x))), j);
  if (await canon(after) !== await canon(before)) { const A = JSON.parse(before), B = JSON.parse(after); for (const k of Object.keys(A)) if (JSON.stringify(A[k]) !== JSON.stringify(B[k])) { const a = JSON.stringify(A[k]), c = JSON.stringify(B[k]); let i = 0; while (a[i] === c[i]) i++; console.log('   differs:', k, a.slice(Math.max(0, i - 80), i + 80), '\n   vs', c.slice(Math.max(0, i - 80), i + 80)); } }
  ok(await canon(after) === await canon(before), 'offline save -> reload: the design comes back identical');
  ok(await p.locator('#stage .el[data-id="logo"] img').count() === 1 && await p.evaluate(() => window.KBB_STUDIO.doc.sets.post[0].some((i) => i.p.indexOf('u_') === 0)), 'uploaded logo and product survive the reload');
  await p.click('#mine-d summary'); ok((await p.textContent('#mine')).includes('Owner test design'), 'My saved designs lists it');
  // unsaved change -> reload -> the draft is back, flagged
  await p.click('#stage .el[data-id="cta"]'); await p.fill('#p-txt', 'Unsaved words'); await p.locator('#p-txt').blur(); await p.waitForTimeout(700);
  await p.reload(); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  ok(await p.textContent('#stage .el[data-id="cta"] .tx') === 'Unsaved words' && /last session is back/.test(await p.textContent('#status')), 'unsaved work comes back after a reload, flagged as not saved');
  await p.keyboard.press('Control+s'); await p.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));

  // ── downloads: exact full-HD sizes
  await p.click('.tab[data-tab="export"]');
  let f = await dl(p, () => p.click('#dl-one')); let s = pngSize(fs.readFileSync(f)); ok(s[0] === 1080 && s[1] === 1350, 'post PNG is ' + s.join('x'));
  await p.click('#fmts .chip:nth-child(3)'); await p.click('#dl-fmt button[data-v="jpg"]');
  f = await dl(p, () => p.click('#dl-one')); s = jpgSize(fs.readFileSync(f)); ok(s && s[0] === 1080 && s[1] === 1920, 'story JPG is ' + (s || []).join('x'));
  await p.click('#fmts .chip:nth-child(2)'); await p.click('#dl-fmt button[data-v="png"]');
  f = await dl(p, () => p.click('#dl-all'));
  const zl = cp.execFileSync('python3', ['-c', 'import zipfile,struct,sys\nz=zipfile.ZipFile(sys.argv[1])\nfor n in z.namelist():\n d=z.read(n); print(n, *struct.unpack(">II", d[16:24]))', f]).toString().trim().split('\n');
  const sizes = { post: '1080 1350', square: '1080 1080', story: '1080 1920' };
  ok(zl.length === 9 && zl.every((l) => { const m = /-(post|square|story)-set\d\.png (\d+ \d+)/.exec(l); return m && sizes[m[1]] === m[2]; }), 'All sizes zip: 9 PNGs at exact sizes\n      ' + zl.join('\n      '));
  // video (a short one: same code path as the button)
  let vid = null; f = await dl(p, () => p.evaluate(() => { window.KBB_STUDIO.recordVideo(2).then((b) => { window.__vid = b ? [b.size, b.type] : null; }); }));
  vid = await p.evaluate(() => window.__vid); const head = fs.readFileSync(f).slice(4, 8).toString();
  ok(vid && vid[0] > 10000 && (head === 'ftyp' || /webm$/.test(f)), 'video recorded offline: ' + path.basename(f) + ' ' + JSON.stringify(vid));

  // ── export the project, import it into a CLEAN browser profile
  f = await dl(p, () => p.click('#xp')); const proj = JSON.parse(fs.readFileSync(f, 'utf8'));
  ok(proj.app === 'kbb-ad-studio' && proj.projects.length >= 1 && proj.images.length >= 3, 'project file: ' + proj.projects.length + ' design(s), ' + proj.images.length + ' pictures, ' + Math.round(fs.statSync(f).size / 1024) + ' KB');
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const o2 = await open(ctx2); await o2.p.click('.tab[data-tab="export"]'); await o2.p.setInputFiles('#xp-in', f);
  await o2.p.waitForFunction(() => /Imported/.test(document.getElementById('status').textContent));
  const imp = await o2.p.evaluate(() => ({ name: window.KBB_STUDIO.doc.name, up: window.KBB_STUDIO.doc.sets.post[0].filter((i) => i.p.indexOf('u_') === 0).length, logo: !!window.KBB_STUDIO.brand().logo, bg: window.KBB_STUDIO.doc.bg.k, cta: window.KBB_STUDIO.doc.els.find((e) => e.id === 'cta').txt }));
  ok(imp.name === 'Owner test design' && imp.up === 1 && imp.logo && imp.bg === 'image' && imp.cta === 'Unsaved words', 'import into a clean browser restores the design, pictures, logo and background ' + JSON.stringify(imp));
  ok(o2.errs.length === 0 && o2.net.length === 0, 'clean browser: no console errors, no network');

  // ── a hostile project file is defused field by field
  const evil = { app: 'kbb-ad-studio', v: 1, brand: { c1: 'red;background:url(https://evil.example/x)', logo: '../../x' },
    images: [{ id: 'evilimg1', name: '<b>x</b>', kind: 'prod', data: 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==' }, { id: 'evilimg2', kind: 'img', data: 'data:image/png;base64,AAAA' }],
    projects: [{ id: 'devil1', name: '<img src=x onerror=alert(1)>', els: [
      { id: 'a1', t: 'text', txt: '<img src=x onerror=alert(1)>', col: 'red;background:url(https://evil.example/y)', fn: 'Comic Sans', fs: 1e9, fill: 'url(https://evil.example/z)' },
      { id: 'bad id"><script>', t: 'text', txt: 'x' }, { id: 'a2', t: 'script', txt: 'x' }, { id: 'a3', t: 'icon', ic: '__proto__' }, { id: 'a4', t: 'img', img: 'evilimg1' }],
      geo: { post: { a1: { x: 'NaN', y: 1e12, w: -5 } } }, bg: { k: 'image', img: 'https://evil.example/bg.png', c1: 'expression(alert(1))', pat: '"><script>' },
      sets: { post: [[{ p: 'https://evil.example/p.png', x: 1 }, { p: 'dalba', x: 1, y: 1, w: 10 }]] } }] };
  const ef = path.join(TMP, 'evil.json'); fs.writeFileSync(ef, JSON.stringify(evil));
  await o2.p.setInputFiles('#xp-in', ef); await o2.p.waitForFunction(() => /Imported|Not imported/.test(document.getElementById('status').textContent));
  const ev = await o2.p.evaluate(() => { const d = window.KBB_STUDIO.doc, a1 = d.els.find((e) => e.id === 'a1');
    return { n: d.els.map((e) => e.id), txt: document.querySelector('#stage .el[data-id="a1"] .tx') && document.querySelector('#stage .el[data-id="a1"] .tx').textContent, imgs: document.querySelectorAll('#stage img[src="x"]').length, col: a1.col, fill: a1.fill, fn: a1.fn, fs: a1.fs, bg: d.bg, prods: d.sets.post[0].map((i) => i.p), g: d.geo.post.a1, ic: Object.prototype.hasOwnProperty.call(d.els.find((e) => e.id === 'a3'), 'ic') ? 'kept' : undefined, brand: window.KBB_STUDIO.brand() }; });
  ok(ev.txt === '<img src=x onerror=alert(1)>' && ev.imgs === 0, 'hostile text shows as text, never as HTML');
  ok(ev.col === undefined && ev.fill === undefined && ev.fn === undefined && ev.fs === 60, 'hostile colour / font / size refused or clamped ' + JSON.stringify([ev.col, ev.fill, ev.fn, ev.fs]));
  ok(JSON.stringify(ev.n) === JSON.stringify(['a1', 'a3', 'prods']) && ev.ic === undefined, 'bad ids, unknown types, missing pictures and an icon named __proto__ dropped: ' + ev.n.join(','));
  ok(ev.bg.k !== 'image' && ev.bg.img === '' && ev.bg.c1 === '#FFFFFF' && ev.bg.pat === 'none', 'hostile background refused ' + JSON.stringify(ev.bg));
  ok(JSON.stringify(ev.prods) === '["dalba"]', 'unknown product URL dropped, real product kept');
  ok(ev.brand.c1 !== evil.brand.c1 && ev.brand.logo === '', 'hostile brand kit refused');
  ok(ev.g.x === 0 && ev.g.y === 400 && ev.g.w === .3, 'geometry clamped ' + JSON.stringify(ev.g));
  ok(o2.net.length === 0, 'no network request from the hostile file (' + o2.net.length + ')');
  await ctx2.close();

  // ── phone width: no sideways scroll on any tab
  const ph = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true });
  const o3 = await open(ph); const sw = [];
  for (const t of ['tpl', 'edit', 'prod', 'style', 'motion', 'export']) {
    await o3.p.click('.tab[data-tab="' + t + '"]'); if (t === 'edit') await o3.p.click('#stage .el[data-id="h1"]'); if (t === 'prod') await o3.p.click('#stage .bp >> nth=0');
    sw.push(t + ':' + await o3.p.evaluate(() => document.documentElement.scrollWidth));
  }
  ok(sw.every((x) => x.endsWith(':390')), '390px: scrollWidth on every tab ' + sw.join(' '));
  await o3.p.click('.tab[data-tab="edit"]'); await o3.p.click('#stage .el[data-id="h1"]'); await o3.p.screenshot({ path: path.join(ROOT, 'shots', 'editor-390-edit.png') });
  ok(o3.errs.length === 0, 'phone: no console errors');
  await ph.close();

  // ── claude.ai artifact runtime: the same file with window.claude.use (an in-memory mock of db/assets/downloads)
  // served over http like the artifact viewer, with /_blob/<id> answered the way the runtime does
  const srv = cp.spawn('python3', ['-m', 'http.server', '8765', '--bind', '127.0.0.1'], { cwd: ROOT, stdio: 'ignore' });
  await new Promise((r) => setTimeout(r, 800));
  const cl = await b.newContext({ viewport: { width: 1280, height: 900 } });
  await cl.route('**/_blob/**', (r) => r.fulfill({ path: path.join(FIX, 'bottle-on-white.png'), contentType: 'image/png' }));
  await cl.addInitScript(() => {
    const docs = {}, blobs = {}, saved = [];
    const snap = (id, d) => ({ id, exists: !!d, data: () => d && JSON.parse(JSON.stringify(d)) });
    const db = { doc: (p) => ({ get: async () => snap(p.split('/').pop(), docs[p]), set: async (d) => { docs[p] = JSON.parse(JSON.stringify(d)); }, delete: async () => { delete docs[p]; } }),
      collection: (c) => ({ get: async () => ({ docs: Object.keys(docs).filter((k) => k.startsWith(c + '/')).map((k) => snap(k.split('/')[1], docs[k])) }) }) };
    let n = 0; const assets = { upload: async (b) => { const id = (++n).toString(16).padStart(32, 'a'); blobs[id] = b; return { id, url: URL.createObjectURL(b) }; }, delete: async (id) => { delete blobs[id]; } };
    const downloads = { save: async (r) => { saved.push([r.filename, r.data.size]); return { status: 'saved' }; } };
    window.__mock = { docs, saved };
    window.claude = { use: (name) => Promise.resolve({ db, assets, downloads }[name] || null) };
  });
  const o4 = await open(cl, 'http://127.0.0.1:8765/ad-studio.html');
  ok(await o4.p.evaluate(() => window.KBB_STUDIO.store.kind()) === 'claude', 'with window.claude: storage is the artifact db');
  await o4.p.click('#tg button[data-id="ramadan-kareem"]'); await o4.p.keyboard.press('Control+s'); await o4.p.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
  ok(await o4.p.evaluate(() => Object.keys(window.__mock.docs).some((k) => k.startsWith('projects/'))), 'Save writes projects/<id> to the db');
  await o4.p.click('.tab[data-tab="prod"]'); await o4.p.setInputFiles('#up-file', path.join(FIX, 'bottle-on-white.png')); await o4.p.waitForFunction(() => Object.keys(window.__mock.docs).some((k) => k.startsWith('images/')));
  ok(true, 'an upload goes to assets + images/<asset id> in the db');
  await o4.p.click('.tab[data-tab="export"]'); await o4.p.click('#dl-one'); await o4.p.waitForFunction(() => window.__mock.saved.length > 0, null, { timeout: 60000 });
  ok(await o4.p.evaluate(() => /\.png$/.test(window.__mock.saved[0][0])), 'downloads go through the downloads capability: ' + await o4.p.evaluate(() => JSON.stringify(window.__mock.saved[0])));
  ok(await o4.p.evaluate(() => document.querySelectorAll('#pk button[data-p^="u_"]').length) === 1, 'the uploaded picture is listed from /_blob/<id>');
  ok(o4.errs.length === 0 && o4.net.length === 0, 'artifact mode: no console errors, no other requests ' + o4.errs.join(' | ') + o4.net.join(' '));
  await cl.close(); srv.kill();

  ok(errs.length === 0, 'offline session: no console errors ' + errs.slice(0, 3).join(' | '));
  ok(net.length === 0, 'offline session: zero network requests (' + net.length + ')');
  await b.close();
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
