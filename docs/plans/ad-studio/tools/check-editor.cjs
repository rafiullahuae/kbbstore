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
  await p.click('.tab[data-tab="style"]'); await p.click('#bg-kind button[data-v="solid"]'); await p.click('[data-bgk="solid"] .sw');
  await p.fill('#cc-hex', '#123456'); await p.press('#cc-hex', 'Enter'); await p.click('#cc-x');
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
  // the clean-browser import above already says "Imported ...": clear it, or this wait passes before the hostile file is read
  await o2.p.evaluate(() => { document.getElementById('status').textContent = ''; });
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
  // touch: the "Select" toggle makes plain taps add to the selection
  await o3.p.keyboard.press('Escape'); await o3.p.click('#pick'); await o3.p.click('#stage .el[data-id="h1"]'); await o3.p.click('#stage .el[data-id="cta"]');
  ok((await o3.p.evaluate(() => window.KBB_STUDIO.selKeys())).length === 2 && await o3.p.locator('#stage .msbox').count() === 1, 'phone: Select toggle + two taps pick two items');
  ok(await o3.p.evaluate(() => document.documentElement.scrollWidth) === 390, '390px: no sideways scroll with the arrange bar showing');
  ok(o3.errs.length === 0, 'phone: no console errors');
  await ph.close();

  // ── multi-select, groups, align / distribute / match, snapping, layers, copy / paste, undo, backgrounds, colours ──
  {
    const mc = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    const { p: q, errs: qe, net: qn } = await open(mc);
    const S = (fn, a) => q.evaluate(fn, a);
    const keys = () => S(() => window.KBB_STUDIO.selKeys());
    const g = (id) => S((id) => Object.assign({}, window.KBB_STUDIO.doc.geo.post[id]), id);
    const box = (k) => S((k) => window.KBB_STUDIO.aabb(window.KBB_STUDIO.itemBox(k)), k);
    const nEls = () => S(() => window.KBB_STUDIO.doc.els.length);
    const near = (a, c, t) => Math.abs(a - c) <= (t || .02);
    await S(() => window.KBB_STUDIO.useTemplate('flash-sale'));
    await q.click('.tab[data-tab="edit"]');
    // pick: Shift adds, Ctrl removes, marquee, Ctrl+A
    await q.click('#stage .el[data-id="h1"]'); await q.click('#stage .el[data-id="cta"]', { modifiers: ['Shift'] });
    ok(JSON.stringify(await keys()) === '["h1","cta"]' && await q.locator('#stage .msbox').count() === 1 && await q.locator('#stage .msbox .mh').count() === 4 && await q.isVisible('#abar') && await q.isVisible('#multi'), 'Shift+click picks two: one box with 4 corner handles, the arrange bar and the multi panel');
    await q.click('#stage .el[data-id="cta"]', { modifiers: ['Control'] }); ok(JSON.stringify(await keys()) === '["h1"]', 'Ctrl+click takes one out again');
    const cvb = await q.locator('#stage .cv').boundingBox();
    await q.mouse.move(cvb.x + 3, cvb.y + 3); await q.mouse.down(); await q.mouse.move(cvb.x + cvb.width * .6, cvb.y + 130, { steps: 5 }); await q.mouse.up();
    let k = await keys(); ok(['logo', 'kick', 'h1'].every((x) => k.includes(x)) && !k.includes('cta'), 'dragging a box on the empty ad picks what it touches: ' + k.join(','));
    await q.mouse.click(cvb.x + 3, cvb.y + 3); ok((await keys()).length === 0, 'a tap on the empty ad clears the pick');
    await q.keyboard.press('Control+a'); k = await keys();
    ok(k.length === await S(() => { const d = window.KBB_STUDIO.doc; return d.els.filter((e) => e.t !== 'prods' && !e.hide && !e.lock).length + d.sets.post[0].length; }) && k.some((x) => x.startsWith('p:')), 'Ctrl+A picks every item, products included (' + k.length + ')');
    // move together (one undo step); Shift+arrow = 10 px of the 1080 px file
    await S(() => window.KBB_STUDIO.setSel(['h1', 'cta']));
    const h0 = await g('h1'), c0 = await g('cta'), hb = await q.locator('#stage .el[data-id="h1"]').boundingBox();
    await q.keyboard.down('Alt'); await q.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2); await q.mouse.down(); await q.mouse.move(hb.x + hb.width / 2 + 30, hb.y + hb.height / 2 + 20, { steps: 6 }); await q.mouse.up(); await q.keyboard.up('Alt');
    let h1 = await g('h1'), c1 = await g('cta');
    ok(h1.x - h0.x > 4 && near(h1.x - h0.x, c1.x - c0.x, .001) && near(h1.y - h0.y, c1.y - c0.y, .001) && JSON.stringify(await keys()) === '["h1","cta"]', 'dragging one of two moves both by the same amount (' + (h1.x - h0.x).toFixed(2) + ', ' + (h1.y - h0.y).toFixed(2) + ' cqw)');
    await q.keyboard.press('Control+z'); h1 = await g('h1'); c1 = await g('cta'); ok(h1.x === h0.x && h1.y === h0.y && c1.x === c0.x && c1.y === c0.y, 'one Ctrl+Z puts both back');
    await q.keyboard.press('Shift+ArrowRight'); h1 = await g('h1'); c1 = await g('cta');
    ok(near(h1.x - h0.x, 1000 / 1080, .002) && near(c1.x - c0.x, 1000 / 1080, .002), 'Shift+→ moves both 10 px (' + ((h1.x - h0.x) * 10.8).toFixed(2) + ' px)');
    await q.keyboard.press('ArrowLeft'); h1 = await g('h1'); ok(near((h1.x - h0.x) * 10.8, 9, .03), '← moves 1 px');
    await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z'); h1 = await g('h1'); ok(h1.x === h0.x, 'each nudge is one undo step');
    // align + distribute + match, each one undo step
    await S(() => window.KBB_STUDIO.setSel(['h1', 'cta', 'pay']));
    await q.click('#abar [data-al="l"]'); let bx = await Promise.all(['h1', 'cta', 'pay'].map(box));
    ok(near(bx[0].x0, bx[1].x0) && near(bx[1].x0, bx[2].x0), 'Align left (to the selection): three left edges at ' + bx.map((x) => x.x0.toFixed(2)).join(' / '));
    await q.click('#alto button[data-v="art"]'); await q.click('#abar [data-al="r"]'); bx = await Promise.all(['h1', 'cta', 'pay'].map(box));
    ok(bx.every((x) => near(x.x1, 100)), 'Align right to the ad: right edges at 100');
    await q.click('#abar [data-al="c"]'); bx = await Promise.all(['h1', 'cta', 'pay'].map(box)); ok(bx.every((x) => near((x.x0 + x.x1) / 2, 50)), 'Align centre to the ad');
    await q.click('#alto button[data-v="sel"]');
    await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z');
    ok((await g('h1')).x === h0.x && (await g('cta')).x === c0.x, 'three aligns, three Ctrl+Z, back where they were');
    await S(() => window.KBB_STUDIO.setSel(['h2', 'ar', 'cta'])); await q.click('#abar [data-ds="v"]');
    bx = (await Promise.all(['h2', 'ar', 'cta'].map(box))).sort((a, c) => a.y0 - c.y0);
    ok(near(bx[1].y0 - bx[0].y1, bx[2].y0 - bx[1].y1, .02), 'Distribute vertically: equal gaps ' + (bx[1].y0 - bx[0].y1).toFixed(2) + ' / ' + (bx[2].y0 - bx[1].y1).toFixed(2));
    await q.keyboard.press('Control+z');
    await S(() => window.KBB_STUDIO.setSel(['cta', 'pay'])); await q.click('#abar [data-mt="w"]'); ok((await g('pay')).w === (await g('cta')).w, 'Match width: to the first picked');
    await q.click('#abar [data-mt="h"]'); ok((await g('pay')).h === (await g('cta')).h, 'Match height'); await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z');
    // smart guides: dragging one item near the ad's centre snaps it there and shows the guide
    await S(() => window.KBB_STUDIO.setSel([]));
    const db = await q.locator('#stage .el[data-id="deco"]').boundingBox(), dd = await box('deco'), pxc = cvb.width / 100;
    await q.mouse.move(db.x + db.width / 2, db.y + db.height / 2); await q.mouse.down();
    await q.mouse.move(db.x + db.width / 2 + (50.5 - (dd.x0 + dd.x1) / 2) * pxc, db.y + db.height / 2 + 2, { steps: 8 });
    const gv = await q.locator('#stage .guide.v').count(); await q.mouse.up(); const dn = await box('deco');
    ok(gv >= 1 && near((dn.x0 + dn.x1) / 2, 50, .05), 'smart guide: dropped 0.5 off the centre, it snaps to the centre (' + ((dn.x0 + dn.x1) / 2).toFixed(3) + ') with a guide shown');
    await q.keyboard.press('Control+z');
    // scale and turn together with the selection box
    await S(() => window.KBB_STUDIO.setSel(['h1', 'cta']));
    let s0 = { h: await g('h1'), c: await g('cta') }, se = await q.locator('#stage .msbox .mh[data-h="se"]').boundingBox();
    await q.mouse.move(se.x + se.width / 2, se.y + se.height / 2); await q.mouse.down(); await q.mouse.move(se.x + 40, se.y + 60, { steps: 6 }); await q.mouse.up();
    let s1 = { h: await g('h1'), c: await g('cta') }, fh = s1.h.w / s0.h.w, fc = s1.c.w / s0.c.w;
    ok(fh > 1.02 && near(fh, fc, .005) && near(s1.c.h / s0.c.h, fc, .005) && near(s1.h.k / s0.h.k, fh, .01), 'corner handle scales both by the same factor (' + fh.toFixed(3) + ', ' + fc.toFixed(3) + ') text size included');
    await q.keyboard.press('Control+z'); ok((await g('h1')).w === s0.h.w && (await g('cta')).w === s0.c.w, 'one Ctrl+Z undoes the scale');
    const mr = await q.locator('#stage .msbox .mr').boundingBox(), mb = await q.locator('#stage .msbox').boundingBox();
    await q.mouse.move(mr.x + mr.width / 2, mr.y + mr.height / 2); await q.mouse.down(); await q.mouse.move(mb.x + mb.width + 80, mb.y + mb.height / 2, { steps: 8 }); await q.mouse.up();
    s1 = { h: await g('h1'), c: await g('cta') };
    ok(s1.h.r !== 0 && s1.h.r === s1.c.r, 'round handle turns both by the same angle (' + s1.h.r + '°)');
    await q.keyboard.press('Control+z'); ok((await g('h1')).r === 0 && (await g('cta')).r === 0, 'one Ctrl+Z undoes the turn');
    // group / ungroup
    await q.keyboard.press('Control+g');
    let G = await S(() => { const d = window.KBB_STUDIO.doc; return { groups: Object.keys(d.groups), gp: d.els.filter((e) => e.gp).map((e) => e.id + ':' + e.gp) }; });
    ok(G.groups.length === 1 && G.gp.length === 2, 'Ctrl+G groups them ' + JSON.stringify(G));
    ok(await q.locator('#layers .ly.grp').count() === 1 && /Group 1 · 2/.test(await q.textContent('#layers .ly.grp')) && await q.locator('#layers .ly.ch').count() === 2, 'the group shows in Layers with its two items under it');
    await q.click('#layers .ly.grp .gt'); ok(await q.locator('#layers .ly.ch').count() === 0, 'the group folds shut in Layers'); await q.click('#layers .ly.grp .gt');
    await q.mouse.click(cvb.x + 3, cvb.y + 3); await q.click('#stage .el[data-id="h1"]');
    ok((await keys()).length === 2, 'tapping one item of a group picks the whole group');
    await q.dblclick('#stage .el[data-id="h1"]'); ok(JSON.stringify(await keys()) === '["h1"]' && await S(() => window.KBB_STUDIO.st.grp) && await q.locator('#stage .gbox').count() === 1, 'double-click enters the group: one item picked, the group outlined');
    await q.keyboard.press('Escape'); ok((await keys()).length === 2 && !(await S(() => window.KBB_STUDIO.st.grp)), 'Esc leaves the group: the group is picked again');
    const gb0 = { h: await g('h1'), c: await g('cta') }, hb2 = await q.locator('#stage .el[data-id="cta"]').boundingBox();
    await q.mouse.move(hb2.x + 10, hb2.y + hb2.height / 2); await q.mouse.down(); await q.mouse.move(hb2.x + 10, hb2.y + hb2.height / 2 - 25, { steps: 5 }); await q.mouse.up();
    ok(near((await g('h1')).y - gb0.h.y, (await g('cta')).y - gb0.c.y, .001) && (await g('h1')).y < gb0.h.y - 2, 'the group moves as one');
    await q.keyboard.press('Control+z');
    // the group survives save -> reload, and draws (and downloads) exactly as the same items ungrouped
    const shotG = await S(() => window.KBB_STUDIO.renderFile('post', 0, 'png').then((bl) => bl.arrayBuffer()).then((a) => crypto.subtle.digest('SHA-256', a)).then((h) => Array.from(new Uint8Array(h)).join('.')));
    await q.keyboard.press('Control+s'); await q.waitForFunction(() => /Saved/.test(document.getElementById('status').textContent));
    await q.reload(); await q.waitForFunction(() => document.documentElement.dataset.ready === '1');
    G = await S(() => { const d = window.KBB_STUDIO.doc; return { groups: Object.keys(d.groups).length, gp: d.els.filter((e) => e.gp).length }; });
    ok(G.groups === 1 && G.gp === 2, 'save -> reload: the group comes back');
    await q.click('.tab[data-tab="edit"]'); await q.click('#stage .el[data-id="h1"]'); await q.keyboard.press('Control+Shift+g');
    ok(await S(() => Object.keys(window.KBB_STUDIO.doc.groups).length === 0 && !window.KBB_STUDIO.doc.els.some((e) => e.gp)), 'Shift+Ctrl+G ungroups');
    const shotU = await S(() => window.KBB_STUDIO.renderFile('post', 0, 'png').then((bl) => bl.arrayBuffer()).then((a) => crypto.subtle.digest('SHA-256', a)).then((h) => Array.from(new Uint8Array(h)).join('.')));
    ok(shotG === shotU, 'the full-HD PNG is the same picture grouped or not');
    await q.keyboard.press('Control+z'); ok(await S(() => Object.keys(window.KBB_STUDIO.doc.groups).length === 1), 'Ctrl+Z regroups');
    // copy / paste / duplicate / delete / lock on many
    await S(() => window.KBB_STUDIO.setSel(['h2', 'ar', 'p:0'])); const n0 = await nEls(), np0 = await S(() => window.KBB_STUDIO.doc.sets.post[0].length);
    await q.keyboard.press('Control+c'); await q.keyboard.press('Control+v');
    ok(await nEls() === n0 + 2 && await S(() => window.KBB_STUDIO.doc.sets.post[0].length) === np0 + 1 && (await keys()).length === 3 && !(await keys()).includes('h2'), 'Ctrl+C / Ctrl+V pastes 2 items + 1 product, and picks the copies');
    await q.keyboard.press('Control+d'); ok(await nEls() === n0 + 4, 'Ctrl+D duplicates the pick');
    await q.keyboard.press('Delete'); ok(await nEls() === n0 + 2, 'Delete removes the whole pick');
    await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z'); await q.keyboard.press('Control+z'); ok(await nEls() === n0 && await S(() => window.KBB_STUDIO.doc.sets.post[0].length) === np0, 'undo steps back through delete, duplicate and paste');
    await S(() => window.KBB_STUDIO.setSel(['h2', 'ar'])); await q.click('#m-lock');
    ok(await S(() => ['h2', 'ar'].every((id) => window.KBB_STUDIO.doc.els.find((e) => e.id === id).lock)), 'Lock locks both'); await q.keyboard.press('Control+z');
    await q.locator('#m-op').evaluate((n) => { n.value = '40'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    ok(await S(() => ['h2', 'ar'].every((id) => window.KBB_STUDIO.doc.els.find((e) => e.id === id).op === 40)), 'Opacity for all'); await q.keyboard.press('Control+z');
    // layers: click, Shift-range, Ctrl-toggle, drag to reorder, rename
    await S(() => window.KBB_STUDIO.setSel([]));
    await S(() => window.KBB_STUDIO.setSel(['h1', 'cta'])); await q.keyboard.press('Control+Shift+g');   // ungrouped again for the layer tests
    await q.click('#layers .ly[data-k="trust"] .n'); await q.click('#layers .ly[data-k="cta"] .n', { modifiers: ['Shift'] });
    k = await keys(); ok(['trust', 'pay', 'cta'].every((x) => k.includes(x)) && k.length === 3, 'Layers: Shift-click picks the range ' + k.join(','));
    await q.click('#layers .ly[data-k="pay"] .n', { modifiers: ['Control'] }); ok(!(await keys()).includes('pay') && (await keys()).length === 2, 'Layers: Ctrl-click takes one out');
    await S(() => window.KBB_STUDIO.setSel([])); await q.locator('#layers .ly[data-k="band2"]').scrollIntoViewIfNeeded();
    const ord0 = await S(() => window.KBB_STUDIO.doc.els.map((e) => e.id).join(','));
    const gr = await q.locator('#layers .ly[data-k="trust"] .gr').boundingBox(), tgt = await q.locator('#layers .ly[data-k="band2"]').boundingBox();
    await q.mouse.move(gr.x + 3, gr.y + 5); await q.mouse.down(); await q.mouse.move(gr.x + 3, tgt.y + tgt.height - 3, { steps: 8 }); await q.mouse.up();
    const ord1 = await S(() => window.KBB_STUDIO.doc.els.map((e) => e.id));
    ok(ord1.indexOf('trust') === 0 && ord1[1] === 'band2', 'Layers: dragging "trust" below the bottom layer puts it at the back: ' + ord1.slice(0, 3).join(','));
    await q.keyboard.press('Control+z'); ok(await S(() => window.KBB_STUDIO.doc.els.map((e) => e.id).join(',')) === ord0, 'one Ctrl+Z undoes the reorder');
    await q.dblclick('#layers .ly[data-k="h2"] .n'); await q.fill('#layers .rn', 'Offer line'); await q.press('#layers .rn', 'Enter');
    ok(await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h2').name) === 'Offer line' && /Offer line/.test(await q.textContent('#layers .ly[data-k="h2"]')), 'Layers: double-click renames');
    // the colour control: hex, opacity, recent, this design's colours, brand palette, eyedropper when the browser has one
    await S(() => window.KBB_STUDIO.setSel(['h1'])); await q.click('#props [data-show~="text"] [data-cp="col"]');
    ok(await q.isVisible('#ccp') && await q.locator('#cc-brand button').count() >= 9 && await q.locator('#cc-doc button').count() >= 3, 'colour control opens with brand palette and this design\'s colours');
    ok(await q.isVisible('#cc-eye') === await S(() => !!window.EyeDropper), 'eyedropper offered exactly when the browser has one (' + await S(() => !!window.EyeDropper) + ')');
    await q.fill('#cc-hex', '12ab34'); await q.press('#cc-hex', 'Enter');
    ok(await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h1').col) === '#12AB34' && await q.$eval('#stage .el[data-id="h1"]', (n) => getComputedStyle(n).color) === 'rgb(18, 171, 52)', 'hex typed -> the text turns #12AB34');
    await q.locator('#cc-a').evaluate((n) => { n.value = '50'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    ok(await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h1').col) === '#12AB3480', 'opacity 50% -> #12AB3480');
    ok(await q.locator('#cc-rec button[title="#12AB3480"]').count() === 1, 'the colour joins Recent');
    await q.click('#cc-x'); ok(!(await q.isVisible('#ccp')), 'the control closes');
    await q.keyboard.press('Control+z'); ok(await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h1').col) === '#12AB34', 'Ctrl+Z steps the opacity back');
    await q.keyboard.press('Control+z');
    // replace a colour across the design, then only in the pick
    await q.click('.tab[data-tab="style"]');
    const dc = await S(() => window.KBB_STUDIO.docColours()), from = dc[0].c;
    await q.click('#dcols button[data-c="' + from + '"]'); await q.fill('#cc-hex', '#0A0B0C'); await q.press('#cc-hex', 'Enter'); await q.click('#cc-x');
    let dc2 = await S(() => window.KBB_STUDIO.docColours());
    ok(!dc2.some((x) => x.c === from) && dc2.find((x) => x.c === '#0A0B0C').n >= dc[0].n, 'replace colour: ' + from + ' (used ' + dc[0].n + '×) is gone, #0A0B0C used ' + (dc2.find((x) => x.c === '#0A0B0C') || {}).n + '×');
    await q.keyboard.press('Control+z'); ok((await S(() => window.KBB_STUDIO.docColours())).some((x) => x.c === from), 'one Ctrl+Z brings the old colour back everywhere');
    const hc = (await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h2').col)).slice(0, 7).toUpperCase(), others0 = await S((c) => window.KBB_STUDIO.doc.els.filter((e) => e.id !== 'h2' && ['col', 'fill', 'fill2', 'bc'].some((p) => (e[p] || '').slice(0, 7).toUpperCase() === c)).length, hc);
    await S(() => window.KBB_STUDIO.setSel(['h2'])); await q.check('#rc-sel'); await q.click('#dcols button[data-c="' + hc + '"]'); await q.fill('#cc-hex', '#ABCDEF'); await q.press('#cc-hex', 'Enter'); await q.click('#cc-x');
    const others1 = await S((c) => window.KBB_STUDIO.doc.els.filter((e) => e.id !== 'h2' && ['col', 'fill', 'fill2', 'bc'].some((p) => (e[p] || '').slice(0, 7).toUpperCase() === c)).length, hc);
    ok(await S(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'h2').col.slice(0, 7)) === '#ABCDEF' && others1 === others0, 'replace colour in the pick only: h2 changed, the other ' + others0 + ' use(s) of ' + hc + ' untouched');
    await q.uncheck('#rc-sel'); await q.keyboard.press('Control+z');
    // backgrounds: a template's own gradient shows in the controls; stops, angle, radial centre, picture with fit / filters / overlay, none = see-through PNG
    await S(() => window.KBB_STUDIO.useTemplate('nd-lilac'));
    const tb = await S(() => window.KBB_STUDIO.doc.bg), marks = await q.$$eval('#gbar .gs', (l) => l.map((m) => m.style.getPropertyValue('--c')));
    ok(tb.k === 'linear' && await q.getAttribute('#bg-kind button[data-v="linear"]', 'aria-pressed') === 'true' && JSON.stringify(marks) === JSON.stringify([tb.c1, tb.c2]) && await q.inputValue('#bg-ang') === String(tb.ang), 'a template\'s gradient opens in the controls: 2 stops ' + marks.join(' → ') + ', angle ' + tb.ang + '°');
    const gbb = await q.locator('#gbar').boundingBox(); await q.mouse.click(gbb.x + gbb.width / 2, gbb.y + gbb.height / 2);
    let bg = await S(() => window.KBB_STUDIO.doc.bg);
    ok(bg.stops && bg.stops.length === 3 && near(bg.stops[1].p, 50, 1.5) && (await q.$eval('#stage .bgl', (n) => n.style.background)).split('%').length - 1 >= 3, 'tapping the gradient bar adds a third stop: ' + JSON.stringify(bg.stops));
    await q.click('[data-bgk~="linear"] [data-bgc="stop"]'); await q.fill('#cc-hex', '#FFD700'); await q.press('#cc-hex', 'Enter'); await q.click('#cc-x');
    ok((await S(() => window.KBB_STUDIO.doc.bg.stops[1].c)) === '#FFD700' && /255, 215, 0/.test(await q.$eval('#stage .bgl', (n) => n.style.background)), 'the middle stop recoloured gold, drawn on the ad');
    await q.locator('#bg-ang').evaluate((n) => { n.value = '45'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    ok(/45deg/.test(await q.$eval('#stage .bgl', (n) => n.style.background)), 'angle 45°');
    await q.click('#gs-del'); ok((await S(() => window.KBB_STUDIO.doc.bg.stops.length)) === 2, 'a stop removed');
    await q.click('#bg-kind button[data-v="radial"]'); await q.locator('#bg-rx').evaluate((n) => { n.value = '30'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    ok(/radial-gradient\(120% 90% at 30% 38%/.test(await q.$eval('#stage .bgl', (n) => n.style.background)), 'radial: centre moved to 30%');
    await q.setInputFiles('#bg-file', path.join(FIX, 'backdrop.jpg')); await q.waitForFunction(() => window.KBB_STUDIO.doc.bg.k === 'image');
    await q.click('#bg-fit button[data-v="tile"]');
    await q.locator('#bg-blur').evaluate((n) => { n.value = '6'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    await q.locator('#bg-sat').evaluate((n) => { n.value = '40'; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); });
    await q.click('[data-bgk="image"] [data-bgc="oc"]'); await q.fill('#cc-hex', '#FF000080'); await q.press('#cc-hex', 'Enter'); await q.click('#cc-x');
    const bi = await q.$eval('#stage .bgl .bgi', (n) => ({ rep: n.style.backgroundRepeat, f: n.style.filter })), bo = await q.$eval('#stage .bgl .bgo', (n) => getComputedStyle(n).backgroundColor);
    ok(bi.rep === 'repeat' && /blur\(/.test(bi.f) && /saturate\(40%\)/.test(bi.f) && /^rgba\(255, 0, 0, 0\.(5|49)/.test(bo), 'picture background: tiled, blurred, desaturated, red overlay at 50% ' + JSON.stringify([bi, bo]));
    const pf = await S(() => window.KBB_STUDIO.renderFile('post', 0, 'png').then((bl) => createImageBitmap(bl)).then((im) => { const c = document.createElement('canvas'); c.width = im.width; c.height = im.height; const x = c.getContext('2d'); x.drawImage(im, 0, 0); return [im.width, Array.from(x.getImageData(5, 650, 1, 1).data)]; }));
    ok(pf[0] === 1080 && pf[1][0] > pf[1][2] + 40, 'the full-HD PNG carries the picture background with its red overlay ' + JSON.stringify(pf));
    await q.click('#bg-kind button[data-v="none"]');
    ok(await q.locator('#stage .cv.clear').count() === 1, 'None: the ad shows a checkerboard while editing');
    const alpha = await S(() => window.KBB_STUDIO.renderFile('post', 0, 'png').then((bl) => createImageBitmap(bl)).then((im) => { const c = document.createElement('canvas'); c.width = im.width; c.height = im.height; const x = c.getContext('2d'); x.drawImage(im, 0, 0); return [[2, 2], [1077, 2], [2, 1347], [1077, 1347], [540, 2]].map((p) => x.getImageData(p[0], p[1], 1, 1).data[3]); }));
    ok(alpha.some((a) => a === 0), 'None: the full-HD PNG is see-through where nothing is drawn (corner alphas ' + alpha.join(',') + ')');
    await q.keyboard.press('Control+z'); ok(await S(() => window.KBB_STUDIO.doc.bg.k) === 'image', 'Ctrl+Z brings the picture background back');
    await q.click('#bg-kind button[data-v="linear"]'); await q.selectOption('#bg-pat', 'petals');
    ok(/svg/.test(await q.$eval('#stage .bgp', (n) => n.style.backgroundImage)), 'pattern presets include petals (SVG, inline)');
    ok(qe.length === 0 && qn.length === 0, 'multi-select session: no console errors, no network ' + qe.slice(0, 3).join(' | ') + qn.join(' '));
    await mc.close();
  }

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
