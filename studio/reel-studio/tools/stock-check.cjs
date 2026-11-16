// KBB Studio · the 📷 Photos tab end to end, against the local Pexels / Unsplash mock (tools/stock-mock.cjs), in Chrome:
//  - app/stock/api.js: search URLs, response normalising (off-host URLs dropped), save paths, sidecars
//  - the engine: photo hosts only with a per-URL grant; keys only in settings/ (0600), never served, never in a project,
//    a sidecar or the page; the key goes to its own API host only
//  - the tab: ⚙ Photo sources (save, Test with a wrong key -> refused, right key -> works), occupation quick filter ->
//    the permission question -> 24 previews with credits and "Photos provided by …"; ⬇ Save into
//    library/people/<occupation>/ with a sidecar; Unsplash's download endpoint is triggered before the picture;
//    Saved photos -> + Add to reel; 390 px; 0 console errors; nothing reaches the real internet
//  - shots/stock/*.png
// node tools/stock-check.cjs
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const { ROOT, makeStudio, startEngine, stopEngine, launchChrome } = require('./studio-harness.cjs');
const { startMock } = require('./stock-mock.cjs');
const OUT = path.join(ROOT, 'tools', 'out', 'stock'), SHOTS = path.join(ROOT, 'shots', 'stock');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const PK = 'MockPexelsKey_0123456789abcdefghijklmnopqrstuvwxyzABCDE', UK = 'MockUnsplashAccess_0123456789abcdefghij';

(async () => {
  fs.mkdirSync(path.join(OUT, 'pics'), { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
  // ── api.js, in node
  const A = await import(path.join(ROOT, 'app', 'stock', 'api.js'));
  ok(A.searchUrl('pexels', { query: ' welder ', orientation: 'portrait' }) === 'https://api.pexels.com/v1/search?query=welder&per_page=24&page=1&orientation=portrait'
    && A.searchUrl('unsplash', { query: 'nurse', orientation: 'square', page: 2 }) === 'https://api.unsplash.com/search/photos?query=nurse&per_page=24&page=2&content_filter=high&orientation=squarish', 'search URLs (Pexels v1, Unsplash search/photos, orientation names)');
  const nb = A.normalize('pexels', { total_results: 2, per_page: 24, photos: [{ id: 1, src: { medium: 'https://images.pexels.com/a.jpg', large2x: 'https://images.pexels.com/a.jpg' }, photographer: 'X' }, { id: 2, src: { medium: 'https://evil.com/a.jpg', large2x: 'https://images.pexels.com/b.jpg' } }, { id: 3, src: { medium: 'http://images.pexels.com/a.jpg', large2x: 'https://images.pexels.com/b.jpg' } }] });
  const nu = A.normalize('unsplash', { total: 1, total_pages: 1, results: [{ id: 'ab', urls: { small: 'https://images.unsplash.com/p?w=400', raw: 'https://images.unsplash.com/p?ixid=1' }, links: { html: 'https://unsplash.com/photos/ab', download_location: 'https://api.unsplash.com/photos/ab/download?ixid=1' }, user: { name: 'Y', links: { html: 'https://unsplash.com/@y' } } }, { id: 'cd', urls: { small: 'https://images.unsplash.com/q' }, links: {}, user: {} }] });
  ok(nb.photos.length === 1 && nu.photos.length === 1 && nu.photos[0].full === 'https://images.unsplash.com/p?ixid=1&w=2160&q=85&fm=jpg' && /utm_source=kbb_studio&utm_medium=referral/.test(nu.photos[0].photographerUrl) && /utm_source/.test(nu.photos[0].pageUrl), 'normalize keeps only photos on their own hosts over https; Unsplash credits carry utm_source / utm_medium; no download_location -> dropped');
  const sp = A.savePath(nu.photos[0], 'nurse'), sp2 = A.savePath(nb.photos[0], '../etc'), sc = A.sidecar(nu.photos[0], { occupation: 'nurse', query: 'nurse worker' });
  ok(sp.image === 'library/people/nurse/unsplash-ab-y.jpg' && sp2.dir === 'library/people/other' && sc.credit === 'Photo by Y on Unsplash' && sc.licence === 'Unsplash License' && sc.source === 'unsplash' && sc.url && sc.photographer === 'Y', 'save path library/people/<occupation>/ (bad names -> other), sidecar has source, photographer, licence, credit, URL');

  // ── mock pictures: occupation figures on a soft ground (labelled MOCK), JPEG
  const figs = JSON.parse(execFileSync('python3', ['-c', `import sys,json,importlib.util; sys.path.insert(0,'${path.join(ROOT, 'library', 'occupations')}'); s=importlib.util.spec_from_file_location('b','${path.join(ROOT, 'library', 'occupations', 'build-occupations.py')}'); b=importlib.util.module_from_spec(s); s.loader.exec_module(b); d=json.load(open('${path.join(ROOT, 'library', 'occupations', 'occupations.json')}')); print(json.dumps([b.figure_for(o) for o in d['occupations'] if o['id'] in ('welder','nurse','electrician','chef','caregiver','plumber','security-guard','heavy-driver')]))`], { maxBuffer: 1 << 24 }).toString());
  const b = await launchChrome();
  const pics = [];
  { const pg = await b.newPage({ viewport: { width: 600, height: 750 } });
    const grounds = ['#C9D6E3', '#E8DCCB', '#CFE3D4', '#E6D0D6', '#D6D2E8', '#E3E0C9', '#CBE2E6', '#E8D5C4'];
    for (let i = 0; i < figs.length; i++) {
      await pg.setContent(`<body style="margin:0;width:600px;height:750px;background:linear-gradient(160deg,${grounds[i]},#7d8b99);position:relative;overflow:hidden"><div style="position:absolute;inset:60px 0 0 0">${figs[i]}</div><div style="position:absolute;left:14px;top:12px;font:700 22px sans-serif;color:#fff;background:#0008;padding:4px 10px;border-radius:6px">MOCK PHOTO</div></body>`);
      const f = path.join(OUT, 'pics', `p${i}.jpg`); await pg.screenshot({ path: f, type: 'jpeg', quality: 85 }); pics.push(f);
    }
    await pg.close(); }
  const studioJpg = pics[0];

  const mock = await startMock({ pexelsKey: PK, unsplashKey: UK, pics });
  const dir = makeStudio();
  const e = await startEngine(dir, ['--test-photo-base', mock.url]);
  const api = (p, o = {}) => fetch(e.url + p, Object.assign({}, o, { headers: Object.assign({ 'X-RS-Token': e.token }, o.headers || {}) }));

  // ── engine gate, from outside the app
  const ungranted = await api('/net/get?url=' + encodeURIComponent('https://api.pexels.com/v1/search?query=x'));
  ok(ungranted.status === 403 && mock.log.length === 0, 'the engine refuses a photo URL nobody granted (403), nothing reached the mock');
  const offhost = await api('/api/permission', { method: 'POST', body: JSON.stringify({ url: 'https://pexels.com.evil.com/x', scope: 'exact' }) });
  ok(offhost.status === 403, 'a grant for a look-alike host is refused');

  const page = await b.newPage({ viewport: { width: 1280, height: 1000 } });
  const errs = [], outside = [];
  // the one expected console line: Chrome logs the deliberate wrong-key test's 401 as a failed resource
  page.on('console', (m) => { if (m.type() === 'error' && !/status of 401/.test(m.text())) errs.push(m.text()); }); page.on('pageerror', (x) => errs.push(x.message));
  page.on('request', (r) => { if (!r.url().startsWith(e.url) && !/^(data|blob):/.test(r.url())) outside.push(r.url()); });
  await page.goto(e.url + '/app/');
  if (await page.locator('#su-later').waitFor({ timeout: 15000 }).then(() => true, () => false)) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', studioJpg);
  await page.waitForSelector('.tl-item', { timeout: 30000 });
  const tab = page.locator('.side-tab[data-side="mod:stock-photos"]');
  ok(await tab.count() === 1, 'the editor has a 📷 Photos tab');
  await tab.click(); await page.waitForSelector('.sx details');

  // ── ⚙ Photo sources
  await page.click('.sx summary:has-text("Photo sources")');
  ok(/no key yet/.test(await page.locator('.sx details').nth(2).innerText()), 'no keys at first');
  await page.locator('.sx .sx-chips button', { hasText: 'Welder' }).click();
  ok(/Add your free Pexels key/.test(await page.locator('.sx .sx-muted[aria-live]').innerText()), 'a search without a key says where to add one');
  const keyIn = (src) => page.locator(`.sx input[aria-label="${src} API key"]`);
  const rowBtn = (src, label) => keyIn(src).locator('xpath=..').locator('button', { hasText: label });
  await keyIn('Pexels').fill('WrongPexelsKey_0000000000000000000000000000'); await rowBtn('Pexels', 'Save').click();
  await page.waitForFunction(() => /key saved …0000/.test(document.querySelector('.sx').innerText));
  await rowBtn('Pexels', 'Test').click(); await page.waitForSelector('.modal');
  const keysShot = await page.screenshot();
  await page.click('.modal button:has-text("Allow once")');
  await page.waitForFunction(() => /refused it \(401\)/.test(document.querySelector('.sx').innerText));
  ok(true, 'Test with a wrong key: "Pexels refused it (401)"');
  await keyIn('Pexels').fill(PK); await rowBtn('Pexels', 'Save').click(); await page.waitForFunction(() => /key saved …BCDE/.test(document.querySelector('.sx').innerText));
  await rowBtn('Pexels', 'Test').click(); await page.click('.modal button:has-text("Allow once")');
  await page.waitForFunction(() => /✓ Works/.test(document.querySelector('.sx').innerText)); ok(true, 'Test with the right key: "✓ Works"');
  await keyIn('Unsplash').fill(UK); await rowBtn('Unsplash', 'Save').click(); await page.waitForFunction(() => /key saved …ghij/.test(document.querySelector('.sx').innerText));
  const pageHtml = await page.content();
  ok(!pageHtml.includes(PK) && !pageHtml.includes(UK), 'the keys are not in the page (inputs cleared; the app sees only the last four)');
  const kf = path.join(dir, 'settings', 'photo-sources.json');
  ok(fs.existsSync(kf) && (fs.statSync(kf).mode & 0o777) === 0o600 && JSON.parse(fs.readFileSync(kf)).pexels === PK, 'keys stored by the engine in settings/photo-sources.json (0600)');
  ok((await fetch(e.url + '/settings/photo-sources.json')).status !== 200 && (await api('/api/list?path=settings')).status === 403, 'settings/ is neither served nor listable');
  const lastAuth = (host) => mock.log.filter((x) => x.host === host).pop();
  ok(lastAuth('api.pexels.com').auth === PK, 'the engine sent the Pexels key to api.pexels.com as "Authorization: <key>"');

  // ── search by occupation (Pexels)
  await page.click('.sx summary:has-text("Photo sources")');
  await page.locator('.sx .sx-chips button', { hasText: 'Welder' }).click();
  await page.waitForSelector('.modal'); ok(/api\.pexels\.com/.test(await page.locator('.modal').innerText()) && /welder worker/.test(await page.locator('.modal').innerText()), 'the permission question names the host and the words that go out');
  await page.click('#sx-allow-session');
  await page.waitForFunction(() => document.querySelectorAll('.sx .sx-grid .sx-card .sx-img[src^="blob:"]').length >= 24, null, { timeout: 30000 });
  const g1 = await page.evaluate(() => ({ n: document.querySelectorAll('.sx details:first-of-type .sx-card').length, cr: document.querySelector('.sx .sx-card .sx-cr').textContent, by: document.querySelector('.sx details:first-of-type').innerText.includes('Photos provided by Pexels'), occ: document.querySelector('.sx select[aria-label="Occupation"]').value }));
  ok(g1.n === 24 && /^Photo by .+ on Pexels$/.test(g1.cr) && g1.by && g1.occ === 'welder', `24 previews for Welder, each credited (“${g1.cr}”), "Photos provided by Pexels" shown, folder = welder`);
  const imgReqs = mock.log.filter((x) => x.host === 'images.pexels.com');
  ok(imgReqs.length >= 24 && imgReqs.every((x) => !x.auth), `previews hotlinked from the URLs the API returned (${imgReqs.length}), and the key never goes to images.pexels.com`);
  await page.waitForTimeout(300);
  await page.evaluate(() => document.querySelector('.sx .sx-status-anchor, .sx .sx-grid').scrollIntoView({ block: 'start' }));
  const tabShot = await page.screenshot();
  await page.locator('.sx .sx-card .sx-act button', { hasText: 'Save' }).first().click();
  await page.waitForFunction(() => /✓ Saved/.test(document.querySelector('.sx .sx-card').innerText), null, { timeout: 20000 });
  const wdir = path.join(dir, 'library', 'people', 'welder'), wf = fs.existsSync(wdir) ? fs.readdirSync(wdir) : [];
  const wj = wf.find((f) => f.endsWith('.json')), wjpg = wf.find((f) => f.endsWith('.jpg'));
  const wside = wj ? JSON.parse(fs.readFileSync(path.join(wdir, wj))) : {};
  ok(wjpg && fs.statSync(path.join(wdir, wjpg)).size > 5000 && wside.source === 'pexels' && wside.licence === 'Pexels License' && /^Photo by .+ on Pexels$/.test(wside.credit) && /^https:\/\/images\.pexels\.com\//.test(wside.url) && wside.occupation === 'welder' && /^https:\/\/www\.pexels\.com\/photo\//.test(wside.pageUrl),
    `⬇ Save -> library/people/welder/${wjpg} + ${wj} (${wside.credit}, ${wside.licence})`);

  // ── Unsplash: the download endpoint before the picture
  await page.locator('.sx .sx-row button', { hasText: 'Unsplash' }).first().click();
  await page.locator('.sx .sx-chips button', { hasText: 'Nurse' }).click();
  await page.waitForFunction(() => document.querySelectorAll('.sx details:first-of-type .sx-card').length === 24 && /on Unsplash/.test(document.querySelector('.sx .sx-card .sx-cr').textContent), null, { timeout: 30000 });
  ok(await page.locator('.modal').count() === 0, '"Allow for this session" covers the next search (no second question)');
  const ua = lastAuth('api.unsplash.com'); ok(ua.auth === 'Client-ID ' + UK && ua.ver === 'v1', 'the Unsplash key goes as "Client-ID <key>" with Accept-Version: v1');
  const crLink = await page.$eval('.sx .sx-card .sx-cr a', (a) => a.href);
  ok(/unsplash\.com\/@.+utm_source=kbb_studio&utm_medium=referral/.test(crLink), 'Unsplash credit links carry utm_source / utm_medium: ' + crLink);
  await page.waitForFunction(() => document.querySelectorAll('.sx .sx-grid .sx-card .sx-img[src^="blob:"]').length >= 24, null, { timeout: 30000 });
  await page.evaluate(() => document.querySelector('.sx .sx-grid').scrollIntoView({ block: 'start' }));
  const unsplashShot = await page.screenshot();
  const before = mock.log.length;
  await page.locator('.sx .sx-card .sx-act button', { hasText: 'Save' }).first().click();
  await page.waitForFunction(() => /✓ Saved/.test(document.querySelector('.sx .sx-card').innerText), null, { timeout: 20000 });
  const seq = mock.log.slice(before).map((x) => x.host + x.path);
  const iDl = seq.findIndex((s) => /api\.unsplash\.com\/photos\/.+\/download/.test(s)), iImg = seq.findIndex((s) => /^images\.unsplash\.com/.test(s));
  ok(iDl >= 0 && iImg > iDl, 'Unsplash: links.download_location is triggered, then the picture is fetched ' + JSON.stringify(seq));
  const nf = fs.readdirSync(path.join(dir, 'library', 'people', 'nurse')), ns = JSON.parse(fs.readFileSync(path.join(dir, 'library', 'people', 'nurse', nf.find((f) => f.endsWith('.json')))));
  ok(ns.source === 'unsplash' && /on Unsplash$/.test(ns.credit) && ns.licence === 'Unsplash License' && /utm_source/.test(ns.photographerUrl), 'the Unsplash sidecar: ' + ns.credit + ' · ' + ns.licence);

  // ── saved photos -> the reel
  await page.waitForFunction(() => document.querySelectorAll('.sx details:nth-of-type(2) .sx-card').length === 2);
  ok(true, 'Saved photos lists both, with their credits');
  const items0 = await page.evaluate(() => window.kbbEditor.project.tracks.reduce((s, t) => s + t.items.length, 0));
  await page.locator('.sx details:nth-of-type(2) .sx-card button', { hasText: 'Add to reel' }).first().click();
  await page.waitForFunction((n) => window.kbbEditor.project.tracks.reduce((s, t) => s + t.items.length, 0) === n + 1, items0, { timeout: 15000 });
  const added = await page.evaluate(() => { const p = window.kbbEditor.project; return p.media.find((m) => /^library\/people\//.test(m.src)); });
  ok(added && added.kind === 'image', '+ Add to reel puts the saved photo on the timeline (' + (added && added.src) + ')');
  await page.evaluate(() => document.querySelector('.sx details:nth-of-type(2)').scrollIntoView());
  const savedShot = await page.screenshot();
  await page.waitForTimeout(1500);   // the project saves itself
  let leak = 0; try { leak = execFileSync('grep', ['-rl', '--', PK.slice(0, 20), path.join(dir, 'workspaces'), path.join(dir, 'library')], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim().split('\n').filter(Boolean).length; } catch { leak = 0; }   // grep exits 1 when nothing matches
  ok(leak === 0, 'the Pexels key is in no project, export or library file');

  // ── phone width
  await page.setViewportSize({ width: 390, height: 844 }); await page.waitForTimeout(300);
  const sw = await page.evaluate(() => document.querySelector('.sx').scrollWidth <= document.querySelector('.sx').clientWidth + 1);
  ok(sw, 'the Photos tab has no sideways scroll at 390 px');
  const phone = await page.screenshot();
  ok(errs.length === 0, 'console errors: ' + errs.slice(0, 3).join(' | '));
  ok(outside.length === 0, 'the browser itself made no request outside the engine (' + outside.length + ')');

  for (const [n, buf] of [['photos-tab-1280.png', tabShot], ['photos-unsplash-1280.png', unsplashShot], ['photo-sources-1280.png', keysShot], ['saved-photos-1280.png', savedShot], ['photos-tab-390.png', phone]]) fs.writeFileSync(path.join(SHOTS, n), buf);
  await b.close(); await stopEngine(e); await mock.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})().catch((x) => { console.error(x); process.exit(1); });
