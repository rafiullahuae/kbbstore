// Work Abroad (16 Nov 2026), driven like the owner would, from the built file on file://:
//  - 44 image-first Work Abroad templates x 3 sizes: each has a worker photo slot with the occupation's figure, its tool
//    icons, every built-in picture drawn; honest copy (no digit, currency, % or "guarantee" outside an ‹edit› box;
//    the "Requirements and terms confirmed at consultation." note on every one)
//  - the occupation library in the file: 100 occupations, each with a figure and three tool icons
//  - the Occupation picker beside the Country picker: welder -> nurse swaps figure, plural / title / "a ..." / Arabic,
//    tool icons + names, accents, keeps the owner's own line, one Ctrl+Z undoes it; every WA template x 12 occupations
//  - a photo in a worker slot: cover-fit at the slot (head kept), still a slot; the picker keeps his photo;
//    ✂ Cut out the person (MediaPipe selfie segmenter from ad-studio-cutout.js): the background goes, the person stays
//  - 0 console errors, 0 network requests
//  - shots/work-abroad-picker.png (before / after the picker, the cut-out, the phone)
// node tools/check-occupations.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os');
const ROOT = path.join(__dirname, '..'), HTML = path.join(ROOT, 'ad-studio.html'), FILE = 'file://' + HTML;
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'adstudio-wa-'));
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };

// a synthetic person (the same idea as the reel studio's l-check): a head, hair, neck and a navy uniform on a busy ground
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

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage(); const errs = [], net = [];
  p.on('console', (m) => { if (m.type() === 'error' && !/^INFO: Created TensorFlow Lite XNNPACK delegate/.test(m.text())) errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message)); p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url())) net.push(r.url()); });
  await p.goto(FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1'); await p.evaluate(() => document.fonts.ready);
  await p.evaluate(() => window.KBB_STUDIO.setCompany('planet-careers')); await p.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers');

  // ── the library in the file
  const lib = await p.evaluate(() => { const S = window.KBB_STUDIO, O = S.occupations, miss = [];
    O.forEach((o) => { if (!S.hasArt('fig:' + o.id)) miss.push('fig:' + o.id); o.tools.forEach((t) => { if (!S.hasArt('oi:' + t[0])) miss.push('oi:' + t[0]); }); if (!o.t[1] || !/[؀-ۿ]/.test(o.t[1])) miss.push(o.id + ' arabic'); });
    return { n: O.length, sectors: new Set(O.map((o) => o.s)).size, miss, opts: document.querySelectorAll('#occ option').length, groups: document.querySelectorAll('#occ optgroup').length }; });
  ok(lib.n >= 80 && lib.miss.length === 0, `occupation library: ${lib.n} occupations in ${lib.sectors} sectors, each with a figure, 3 tool icons and an Arabic name; missing: ${lib.miss.slice(0, 5).join(' ')}`);
  ok(lib.opts === lib.n && lib.groups === lib.sectors, `the Occupation picker lists all ${lib.opts} occupations in ${lib.groups} sector groups`);

  // ── every Work Abroad template
  const tp = await p.evaluate(() => {
    const S = window.KBB_STUDIO, W = S.templates.filter((t) => t.cat === 'pc-work'), bad = [], fams = new Set();
    for (const t of W) {
      fams.add(t.fam);
      const d = S.fromTemplate(t.id);
      if (d.occ !== t.occ || !d.cty) bad.push(t.id + ' occ/cty');
      const fig = d.els.filter((e) => e.t === 'art' && e.wk && e.ph && /^fig:/.test(e.art));
      if (!fig.some((e) => e.art === 'fig:' + t.occ)) bad.push(t.id + ' no worker slot');
      d.els.filter((e) => e.t === 'art').forEach((e) => { if (!S.hasArt(e.art)) bad.push(t.id + ' missing art ' + e.art); });
      const txt = d.els.filter((e) => typeof e.txt === 'string').map((e) => e.txt);
      txt.forEach((s) => { const plain = s.replace(/‹[^›]*›/g, ''); if (/[0-9٠-٩]|AED|€|\$|£|%|guarantee|100 ?%|approved/i.test(plain) && !/^\d$/.test(plain.trim())) bad.push(t.id + ' claim? "' + s.slice(0, 40) + '"'); if (/\{[A-Z0-9]+\}/.test(s)) bad.push(t.id + ' token left ' + s.slice(0, 30)); });
      if (!txt.some((s) => /Requirements and terms confirmed at consultation/.test(s))) bad.push(t.id + ' no note');
      if (!txt.some((s) => /‹edit›/.test(s))) bad.push(t.id + ' no edit box');
      if (!['post', 'square', 'story'].every((f) => d.geo[f] && d.geo[f].fig && d.geo[f].fig.w > 10)) bad.push(t.id + ' figure size');
      // the worker is the hero: its slot is at least a fifth of the post
      const g = d.geo.post.fig; if (g.w * (g.h || g.w * 1.25) < 100 * 125 * (t.fam === 'wacollage' || t.fam === 'wateam' || t.fam === 'waid' ? .06 : .2)) bad.push(t.id + ' figure small ' + Math.round(g.w * g.h));
    }
    return { n: W.length, fams: fams.size, bad };
  });
  ok(tp.n >= 40 && tp.fams >= 10 && tp.bad.length === 0, `Work Abroad templates: ${tp.n} in ${tp.fams} layout families, worker slot + figure + tools, honest copy; problems: ${tp.bad.slice(0, 6).join(' | ')}`);

  // ── the picker sweep: every WA template x 12 occupations, then back
  const sweep = await p.evaluate(() => {
    const S = window.KBB_STUDIO, W = S.templates.filter((t) => t.cat === 'pc-work'), O = S.occupations, bad = []; let n = 0;
    const pick = ['nurse', 'plumber', 'chef', 'security-guard', 'teacher', 'farm-worker', 'it-specialist', 'housemaid', 'forklift-operator', 'barber', 'butcher', 'electrician'];
    for (const t of W) {
      S.useTemplate(t.id);
      for (const id of pick) {
        if (id === S.doc.occ) continue;
        const o = O.find((x) => x.id === id); S.setOccupation(id); n++;
        const d = S.doc;
        if (d.occ !== id) bad.push(t.id + ':' + id + ' occ');
        if (!d.els.some((e) => e.art === 'fig:' + id)) bad.push(t.id + ':' + id + ' figure');
        d.els.filter((e) => e.oti != null).forEach((e) => { if (e.art !== 'oi:' + o.tools[e.oti][0]) bad.push(t.id + ':' + id + ' tool icon'); });
        const named = d.els.filter((e) => e.ock); if (named.length && !named.every((e) => [o.t[0], o.t[1], o.l, o.pl[0], o.pl[1], ...o.tools.map((q) => q[1])].some((w) => e.txt.indexOf(w) >= 0))) bad.push(t.id + ':' + id + ' words ' + named.map((e) => e.txt).join('/').slice(0, 60));
        const imgs = [...document.querySelectorAll('#stage img.ii')]; if (imgs.some((i) => !i.getAttribute('src'))) bad.push(t.id + ':' + id + ' empty art');
      }
    }
    return { n, bad };
  });
  ok(sweep.bad.length === 0, `occupation picker: ${sweep.n} swaps (every Work Abroad template x 12 occupations), problems: ${sweep.bad.slice(0, 5).join(' | ')}`);

  // ── one click in the real UI: welder -> nurse
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('wa-hire-welder')); await p.click('.tab[data-tab="edit"]');
  ok(await p.isVisible('#occw') && await p.isVisible('#ctyw') && await p.$eval('#occ', (s) => s.value) === 'welder', 'Occupation picker shows beside the Country picker, set to Welder');
  await p.click('#stage .el[data-id="h2"]'); await p.fill('#p-txt', 'My own line'); await p.locator('#p-txt').blur();
  await p.keyboard.press('Escape'); await p.keyboard.press('Escape');
  const before = await p.screenshot();
  const g = () => p.evaluate(() => { const d = window.KBB_STUDIO.doc, e = (id) => d.els.find((x) => x.id === id) || {}; return { occ: d.occ, cty: d.cty, h1: e('h1').txt, ar: e('ar').txt, h2: e('h2').txt, fig: e('fig').art, t0: e('ti0').art, n0: e('tn0').txt, glow: e('g1').fill }; });
  const w0 = await g();
  await p.selectOption('#occ', 'nurse'); await p.waitForTimeout(120);
  const w1 = await g();
  ok(w1.occ === 'nurse' && w1.h1 === 'Nurses' && /ممرض/.test(w1.ar) && !/لحّام/.test(w1.ar) && w1.fig === 'fig:nurse' && w1.t0 === 'oi:stethoscope' && w1.n0 === 'Stethoscope' && w1.h2 === 'My own line' && w1.glow !== w0.glow && w1.cty === 'pl',
    'Welder -> Nurse in one click: ' + JSON.stringify(w1));
  await p.waitForFunction(() => { const i = document.querySelector('#stage .el[data-id="fig"] img'); return i && i.complete && i.naturalWidth > 0; }); ok(true, 'the nurse figure draws');
  const after = await p.screenshot();
  await p.selectOption('#cty', 'de'); await p.waitForTimeout(80);
  { const w3 = await g(); ok(w3.occ === 'nurse' && w3.cty === 'de' && w3.h1 === 'Nurses' && /ألمانيا/.test(w3.ar) && /ممرض/.test(w3.ar), 'the Country picker after it: Germany, still nurses ' + w3.ar); }
  await p.locator('#stage').click({ position: { x: 3, y: 3 } }).catch(() => {});
  await p.keyboard.press('Control+z'); await p.keyboard.press('Control+z');
  const w2 = await g(); ok(w2.occ === 'welder' && w2.h1 === 'Welders' && w2.fig === 'fig:welder' && w2.cty === 'pl', 'two Ctrl+Z: back to welders in Poland');
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('pc-consult')); ok(await p.isHidden('#occw'), 'no Occupation picker on a design without an occupation');

  // ── his photo in the worker slot, then cut out
  const jpg = path.join(TMP, 'worker.jpg'); fs.writeFileSync(jpg, Buffer.from((await p.evaluate(PERSON)).split(',')[1], 'base64'));
  await p.evaluate(() => window.KBB_STUDIO.useTemplate('wa-hire-chef')); await p.click('.tab[data-tab="edit"]'); await p.evaluate(() => window.KBB_STUDIO.setSel(['fig']));
  ok(await p.isVisible('#ph-row') && await p.isHidden('#ph-cut'), 'the worker figure is a photo slot ("Put a photo here")');
  await p.setInputFiles('#ph-file', jpg); await p.waitForFunction(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'fig').t === 'img');
  const ph = await p.evaluate(() => { const e = window.KBB_STUDIO.doc.els.find((x) => x.id === 'fig'), im = document.querySelector('#stage .el[data-id="fig"] img'), cs = getComputedStyle(im); return { wk: e.wk, ph: e.ph, sh: e.sh, fit: cs.objectFit, pos: cs.objectPosition }; });
  ok(ph.wk && ph.ph && ph.sh === 'rect' && ph.fit === 'cover' && ph.pos === '50% 18%', 'his photo fills the slot (cover, head kept) and stays a slot ' + JSON.stringify(ph));
  ok(await p.isVisible('#ph-cut'), '"✂ Cut out the person" is offered on it');
  await p.selectOption('#occ', 'waiter'); await p.waitForTimeout(80);
  ok(await p.evaluate(() => { const d = window.KBB_STUDIO.doc; return d.occ === 'waiter' && d.els.find((e) => e.id === 'fig').t === 'img'; }), 'the Occupation picker keeps his photo (only the words, tools and accents change)');
  await p.evaluate(() => window.KBB_STUDIO.setSel(['fig']));
  const t0 = Date.now();
  await p.click('#ph-cut');
  await p.waitForFunction(() => /Cut out —|Not cut out/.test(document.body.innerText), null, { timeout: 120000 });
  const cut = await p.evaluate(async () => {
    const S = window.KBB_STUDIO, e = S.doc.els.find((x) => x.id === 'fig'), src = S.images[e.img] && S.images[e.img].src; if (!src) return { err: 'no image' };
    const im = new Image(); im.src = src; await im.decode(); const c = document.createElement('canvas'); c.width = im.naturalWidth; c.height = im.naturalHeight; const x = c.getContext('2d'); x.drawImage(im, 0, 0);
    const a = (fx, fy) => x.getImageData(Math.round(fx * c.width), Math.round(fy * c.height), 1, 1).data[3];
    return { name: e.name, corners: [a(.03, .03), a(.97, .03), a(.03, .5), a(.97, .5)], person: [a(.5, .42), a(.5, .8), a(.3, .9)], w: c.width, h: c.height };
  });
  ok(cut.name === 'Worker photo (cut out)' && cut.corners.every((v) => v < 30) && cut.person.every((v) => v > 220), `✂ Cut out in ${((Date.now() - t0) / 1000).toFixed(1)} s: background alpha ${cut.corners.join(',')} · person alpha ${cut.person && cut.person.join(',')} (${cut.w}×${cut.h})`);
  await p.keyboard.press('Escape'); await p.keyboard.press('Escape');
  const cutShot = await p.screenshot();
  await p.locator('#stage').click({ position: { x: 3, y: 3 } }).catch(() => {});
  await p.keyboard.press('Control+z'); ok(await p.evaluate(() => window.KBB_STUDIO.doc.els.find((e) => e.id === 'fig').name) === 'Worker photo', 'Ctrl+Z brings the background back');

  // ── phone
  const ph2 = await b.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 }); const e2 = []; ph2.on('pageerror', (e) => e2.push(e.message));
  await ph2.goto(FILE); await ph2.waitForFunction(() => document.documentElement.dataset.ready === '1');
  await ph2.evaluate(() => window.KBB_STUDIO.setCompany('planet-careers')); await ph2.waitForFunction(() => window.KBB_STUDIO.company().id === 'planet-careers');
  await ph2.evaluate(() => window.KBB_STUDIO.useTemplate('wa-poster-nurse')); await ph2.waitForTimeout(200);
  const sw = await ph2.evaluate(() => document.documentElement.scrollWidth);
  ok(sw <= 390 && await ph2.isVisible('#occw'), `phone 390 px: the picker shows, scrollWidth ${sw}`);
  const phone = await ph2.screenshot();
  ok(errs.length === 0 && e2.length === 0, 'console errors: ' + errs.concat(e2).slice(0, 3).join(' | '));
  ok(net.length === 0, 'network requests: ' + net.length);

  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:20px;background:#E9EDF1;font:600 15px Inter,system-ui,sans-serif;color:#0A1B30;width:${1280 * 2 + 60}px}h1{margin:0 0 4px;font-size:26px}p{margin:0 0 14px;color:#55606E;font-weight:500}.g{display:grid;grid-template-columns:1280px 1280px;gap:20px}figure{margin:0}figcaption{margin:0 0 6px}img{display:block;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.18)}</style>
    <h1>KBB Studio · Ad Studio · Work Abroad · the Occupation picker</h1><p>Top bar, next to ▶ Play: Country · <b>Occupation</b> (100 occupations in 10 sectors). One click swaps the worker, the job title in English and Arabic, the tools and the accents; the owner's own lines stay; Ctrl+Z undoes it. A worker figure is a photo slot: Edit → Put a photo here… → ✂ Cut out the person.</p>
    <div class="g"><figure><figcaption>We're Hiring · Welders in Poland (an own line typed in)</figcaption><img src="data:image/png;base64,${before.toString('base64')}" width="1280"></figure>
    <figure><figcaption>Occupation → Nurse: one click</figcaption><img src="data:image/png;base64,${after.toString('base64')}" width="1280"></figure>
    <figure><figcaption>A photo in the slot, then ✂ Cut out the person (synthetic test photo; offline MediaPipe)</figcaption><img src="data:image/png;base64,${cutShot.toString('base64')}" width="1280"></figure>
    <figure><figcaption>Phone 390 px · Poster · Nurse</figcaption><img src="data:image/png;base64,${phone.toString('base64')}" width="390"></figure></div>`;
  const sp = await b.newPage({ viewport: { width: 1280 * 2 + 100, height: 900 } }); await sp.setContent(html); await sp.screenshot({ path: path.join(ROOT, 'shots', 'work-abroad-picker.png'), fullPage: true }); await sp.close();
  await b.close();
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})();
