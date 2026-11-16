// KBB Studio · BRIEF → DESIGNS + CONFIGURE, end to end (headless Chrome + the Linux engine):
//   - the rule reader on EN + AR briefs (KBB National Day 30% Anua + BoJ; Planet Careers study Germany Sep; work abroad
//     welders Poland), model answers validated (invented products / numbers / promises refused), parser fallback without
//     any language model
//   - 6–8 designs per brief that ALL pass every hard design rule in every size, re-checked independently; exported PNGs
//     at the right pixel sizes (full HD and 4K)
//   - offline image generation (SDXS on WASM, 512 px) -> pictures, prompts that ask for text are refused, upscale x2,
//     a design that uses the picture still passes; icons + colour schemes produced
//   - ⚙ Configure: Claude key saved privately (0600, not readable through the file API, never in projects/exports/logs),
//     Test against tools/d-claude-mock.cjs, Smart brief through the engine (only brief + catalogue names sent),
//     Disconnect clears; photo keys; models; folders; languages
//   - no request leaves the engine's origin; no console errors
//   - screenshots 1920×1080 + 1280×800 -> shots/d/
// node tools/d-check.cjs [--no-ai]
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const { ROOT, makeStudio, startEngine, stopEngine, launchChrome } = require('./studio-harness.cjs');
const { startClaudeMock } = require('./d-claude-mock.cjs');
const SHOTS = path.join(ROOT, 'shots', 'd'), OUT = path.join(ROOT, 'tools', 'out', 'd');
const NO_AI = process.argv.includes('--no-ai');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const KEY = 'sk-ant-api03-MockKey_0123456789abcdefghijklmnopqrstuvwxyz-QZ9T';
const SDXS = 'creador/sdxs-512-dreamshaper-onnx', SWIN = 'Xenova/swin2SR-classical-sr-x2-64';

(async () => {
  fs.mkdirSync(SHOTS, { recursive: true }); fs.mkdirSync(OUT, { recursive: true });
  const { BRIEFS, catalogue, workspace } = await import(path.join(ROOT, 'tools', 'd-briefs.mjs'));
  // ── pure modules, in node ──
  const B = await import(path.join(ROOT, 'app/designs/lib/brief.js'));
  const Pm = await import(path.join(ROOT, 'app/designs/imagegen/prompts.js'));
  const Pal = await import(path.join(ROOT, 'app/designs/lib/palette.js'));
  const cat = catalogue();
  const specs = Object.fromEntries(BRIEFS.map((b) => [b.id, B.parseBrief(b.text, workspace(b.ws), cat)]));
  for (const id of ['kbb-en', 'kbb-ar']) { const s = specs[id]; ok(s.kind === 'offer' && s.occasion?.id === 'uae-national-day' && s.offer?.value === 30 && s.brands.includes('Anua') && s.brands.includes('Beauty of Joseon') && s.products.includes('anua') && s.missingBrands.includes('Beauty of Joseon') && s.sizes.join() === 'story,post' && s.tone === 'luxury', `${id}: National Day · 30% · Anua + BoJ (BoJ flagged: no cut-out) · story+post · luxury`); }
  for (const id of ['pc-study-en', 'pc-study-ar']) { const s = specs[id]; ok(s.kind === 'study' && s.countries.join() === 'de' && s.intake === 'Sep' && s.cta.en === 'Book a free consultation', `${id}: study · Germany · Sep intake · consultation CTA`); }
  for (const id of ['pc-work-en', 'pc-work-ar']) { const s = specs[id]; ok(s.kind === 'hiring' && s.countries.join() === 'pl' && s.occupations.join() === 'welder' && /WhatsApp/.test(s.cta.en), `${id}: hiring · welder · Poland · WhatsApp CTA`); }
  ok(specs['kbb-ar'].briefLang === 'ar' && specs['kbb-ar'].languages[0] === 'ar', 'an Arabic brief makes Arabic the first language');
  const v = B.validateSpec(specs['kbb-en'], { kind: 'offer', products: ['anua', 'fakeprod'], countries: ['zz'], copy: [{ lang: 'en', headline: 'Glow for the Union', offer: '30% OFF', cta: 'Shop' }, { lang: 'en', headline: 'Mega 50% Sale', offer: '50% off', cta: 'Shop' }, { lang: 'en', headline: 'Guaranteed glow', offer: '', cta: 'Buy' }] }, cat);
  ok(v.refused.some((r) => /fakeprod/.test(r)) && v.refused.some((r) => /50/.test(r)) && v.refused.some((r) => /Guaranteed/.test(r)) && v.spec.copySuggestions.length === 1, 'model answers validated: unknown product, invented "50%" and "guaranteed" refused; the honest line kept');
  ok(Pm.textRequest('a poster that says SALE 50%') && Pm.textRequest('neon sign with the words GLOW') && Pm.textRequest('خلفية مع نص') && !Pm.textRequest('pink silk with petals, no text, no letters'), 'image prompts asking for text are refused (EN / AR); "no text" passes');
  ok(!Pm.sanitize('a serum bottle on silk').ok && Pm.sanitize('soft pink silk with petals').ok && /no text/.test(Pm.sanitize('soft pink silk').prompt), 'prompts asking the model to draw a product are refused; every prompt ends with "no text …"');
  const prompts = Pm.promptsFor(specs['pc-work-en'], { acc: '#BE0B32', bg2: '#002768' }, cat);
  ok(prompts[0].kind === 'worker' && /welder|weld/i.test(prompts[0].prompt) && /coverall|helmet|apron|jacket|gloves/i.test(prompts[0].prompt) && prompts.every((p) => !Pm.textRequest(p.prompt.replace(/no text, no letters, no logo, no watermark/, ''))), 'Work Abroad prompts come from the occupation library (uniform + tools), never ask for text');
  let palN = 0, palOk = true;
  for (const id of Object.keys(specs)) { const ps = Pal.schemesFor(specs[id], workspace(BRIEFS.find((b) => b.id === id).ws), cat); palN += ps.length; palOk &&= ps.length >= 3 && ps.every((p) => !Pal.paletteCheck(p).length); }
  ok(palOk, `colour schemes per brief: ${palN} in all, ≥ 3 each, every one contrast-checked (text ≥ 4.5:1)`);

  // ── the studio: engine + Claude mock + the offline models ──
  const mock = await startClaudeMock({ key: KEY });
  const dir = makeStudio();
  const haveAI = !NO_AI && fs.existsSync(path.join(ROOT, 'models', SDXS, 'unet_fp16.onnx'));
  for (const r of [SDXS, SWIN]) if (!NO_AI && fs.existsSync(path.join(ROOT, 'models', r))) { fs.mkdirSync(path.dirname(path.join(dir, 'models', r)), { recursive: true }); execFileSync('cp', ['-al', path.join(ROOT, 'models', r), path.join(dir, 'models', r)]); }
  const e = await startEngine(dir, ['--test-claude-base', mock.url]);
  const b = await launchChrome();
  const ctxB = await b.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await ctxB.newPage();
  const errs = [], outside = [];
  let neg = false; // during the deliberate refusals (bad key 400, settings read 403, call after Disconnect 409) Chrome logs the failed resource
  page.on('console', (m) => { if (m.type() === 'error' && !(neg && /status of (400|403|409)/.test(m.text()))) errs.push(m.text()); });
  page.on('pageerror', (x) => errs.push(x.message));
  page.on('request', (r) => { if (!r.url().startsWith(e.url) && !/^(data|blob):/.test(r.url())) outside.push(r.url()); });
  await page.goto(e.url + '/app/');
  // first run: skip the model setup screen
  await page.waitForSelector('#su-later, .home', { timeout: 30000 });
  if (await page.$('#su-later')) await page.click('#su-later');
  await page.waitForSelector('[data-mod-home="designs"]', { timeout: 30000 });
  ok(!!(await page.$('#configure-btn')) && !!(await page.$('#home-brief')), 'home: ⚙ Configure in the top bar and the Brief → Designs card');

  // ── per brief: generate through the real modules, re-check independently, export ──
  const results = {};
  for (const br of BRIEFS) {
    const r = await page.evaluate(async ({ br }) => {
      const W = await import('/app/editor/workspaces.js'), L = await import('/app/designs/llm.js'), Gn = await import('/app/designs/generate.js'), Ct = await import('/app/designs/catalogue.js'), Ru = await import('/app/designs/rules.js'), Ed = await import('/app/designs/editor.js');
      W.switchWorkspace(br.ws);
      const ws = W.state.current, cat = await Ct.catalogueAll();
      const rd = await L.readBrief(br.text, ws, cat, { smart: false, useLocal: true });
      const t0 = performance.now();
      const res = await Gn.generate(rd.spec, ws, cat, { count: 8 });
      const ms = Math.round(performance.now() - t0);
      const indep = res.designs.map((D) => D.sizes.map((s) => Ru.check(D, s)).every((c) => c.pass));
      const fams = new Set(res.designs.map((D) => D.family));
      let exp = [];
      if (res.designs[0]) { exp = await Ed.exportDesign(res.designs[0], { scale: 1 }); exp = exp.concat(await Ed.exportDesign(res.designs[0], { sizes: [res.designs[0].sizes[0]], scale: 2 })); await Ed.saveDesign(res.designs[0]); }
      const minContrast = Math.min(...res.designs.flatMap((D) => D.sizes.map((s) => Ru.check(D, s).items.filter((i) => i.contrast).reduce((m, i) => Math.min(m, i.contrast), 99))));
      return { via: rd.via, note: rd.note, n: res.designs.length, ms, indep, distinct: fams.size, sizes: rd.spec.sizes, exp, minContrast, langs: [...new Set(res.designs.map((D) => D.lang))], scores: res.designs.map((D) => D.score) };
    }, { br });
    results[br.id] = r;
    ok(r.via === 'rules' && /built-in/i.test(r.note), `${br.id}: read offline by the built-in reader without any language model ("${r.note}")`);
    ok(r.n >= 6 && r.n <= 8 && r.distinct === r.n && r.indep.every(Boolean), `${br.id}: ${r.n} distinct designs, every one passes every hard rule in ${r.sizes.join(' + ')} (re-checked) · min contrast ${r.minContrast.toFixed(1)}:1 · scores ${r.scores.join(',')} · ${r.ms} ms`);
    const want = { story: [1080, 1920], post: [1080, 1350], square: [1080, 1080] };
    const expOk = r.exp.length === r.sizes.length + 1 && r.exp.slice(0, -1).every((x, i) => x.w === want[r.sizes[i]][0] && x.h === want[r.sizes[i]][1]) && r.exp.at(-1).w === want[r.sizes[0]][0] * 2 && r.exp.at(-1).h === want[r.sizes[0]][1] * 2;
    let disk = true;
    for (const x of r.exp) { const f = path.join(dir, x.path); const buf = fs.existsSync(f) ? fs.readFileSync(f) : null; disk &&= !!buf && buf.readUInt32BE(16) === x.w && buf.readUInt32BE(20) === x.h; }
    ok(expOk && disk, `${br.id}: exported PNGs ${r.exp.map((x) => x.w + '×' + x.h).join(', ')} (full HD + 4K), sizes read back from the files`);
    if (br.id === 'kbb-ar') ok(r.langs.includes('ar'), 'the Arabic brief gives Arabic-first designs');
  }

  // ── the screen: brief → designs grid, screenshots ──
  await page.evaluate(async () => { const W = await import('/app/editor/workspaces.js'); W.switchWorkspace('kbb'); });
  await page.goto(e.url + '/app/'); await page.waitForSelector('#home-brief');
  await page.fill('#home-brief', BRIEFS[0].text);
  await page.click('#home-designs');
  await page.waitForFunction(() => document.querySelectorAll('.dz-card').length >= 6, null, { timeout: 60000 });
  const cards = await page.$$eval('.dz-card', (x) => x.map((c) => c.dataset.family));
  ok(cards.length >= 6 && new Set(cards).size === cards.length, `Designs screen: ${cards.length} distinct designs from the typed brief (${cards.join(', ')})`);
  ok(await page.$eval('#dz-spec', (x) => /Anua/.test(x.textContent) && /Beauty of Joseon/.test(x.textContent) && /no photo yet/.test(x.textContent) && /30% OFF/.test(x.textContent)), 'the reading shows offer, brands, products and the missing cut-out note');
  await page.click('.dz-tabs [data-t="colours"]'); await page.waitForSelector('.dz-pal');
  await page.screenshot({ path: path.join(SHOTS, 'designs-grid-1920.png') });
  await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'designs-grid-1280.png') });
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.click('.dz-tabs [data-t="icons"]'); await page.waitForFunction(() => document.querySelectorAll('#dz-icons img').length >= 6);
  const nIcons = await page.$$eval('#dz-icons img', (x) => x.length);
  await page.click('.dz-tabs [data-t="art"]'); await page.waitForSelector('#dz-arts canvas');
  const nArt = await page.$$eval('#dz-arts canvas', (x) => x.length);
  ok(nIcons >= 6 && nArt >= 20, `icons chosen by meaning (${nIcons} styled) and ${nArt} procedural artwork kinds`);
  await page.screenshot({ path: path.join(SHOTS, 'visuals-icons-art-1920.png') });

  // ── the design editor: select, edit text, recolour, move, undo, export ──
  await page.click('.dz-card');
  await page.waitForSelector('#de-canvas');
  await page.waitForFunction(() => /rule/.test(document.querySelector('#de-rules')?.textContent || ''), null, { timeout: 20000 });
  await page.click('[data-el="headline"]');
  await page.fill('#de-text', 'Celebrate in Glow'); await page.$eval('#de-text', (t) => t.dispatchEvent(new Event('change')));
  await page.waitForTimeout(500);
  const box = await page.$eval('#de-canvas', (c) => { const r = c.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
  await page.mouse.move(box.x + box.w / 2, box.y + box.h * 0.2); await page.mouse.down(); await page.mouse.move(box.x + box.w / 2 + 30, box.y + box.h * 0.2 + 10, { steps: 4 }); await page.mouse.up();
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'editor-1920.png') });
  await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'editor-1280.png') });
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.keyboard.press('Escape');
  await page.click('#de-export'); await page.selectOption('#de-x-sizes', 'all'); await page.selectOption('#de-x-q', '2'); await page.click('#de-x-go');
  await page.waitForFunction(() => document.querySelector('.de')?.dataset.lastExport, null, { timeout: 30000 });
  const lastExp = JSON.parse(await page.$eval('.de', (x) => x.dataset.lastExport));
  ok(lastExp.length === 2 && lastExp[0].w === 2160 && lastExp[0].h === 3840 && lastExp[1].h === 2700, `editor export, every size in 4K: ${lastExp.map((x) => x.w + '×' + x.h).join(', ')}`);
  const edited = await page.evaluate(async () => { const exp = JSON.parse(document.querySelector('.de').dataset.lastExport); return exp[0].path; });
  ok(fs.existsSync(path.join(dir, edited)), 'the edited design is on disk in exports/designs');
  await page.click('#de-undo'); await page.waitForTimeout(300);
  await page.click('#de-save'); await page.waitForTimeout(800);
  ok((await fs.promises.readdir(path.join(dir, 'workspaces', 'kbb', 'designs'))).some((f) => f.endsWith('.json')), 'Save writes the design (and its thumbnail) to workspaces/kbb/designs/');

  // ── offline AI pictures (SDXS on WASM here; WebGPU on the Mac) ──
  if (haveAI) {
    await page.click('#de-back');
    await page.goto(e.url + '/app/'); await page.waitForSelector('#home-brief'); // a fresh page: the picture model needs the memory
    const ai = await page.evaluate(async () => {
      const IG = await import('/app/designs/imagegen/index.js');
      const out = { refused: null };
      try { await IG.generate('a shop sign that says SALE 30%', { n: 1, device: 'wasm' }); } catch (err) { out.refused = err.refused ? err.message : 'threw ' + err.message; }
      const t0 = performance.now();
      const c = await IG.generate('soft pink silk fabric with rose petals, luxury beauty background, studio light', { n: 2, seed: 4242, device: 'wasm' });
      out.ms = Math.round(performance.now() - t0); out.n = c.length; out.each = c.map((x) => x.ms); out.size = [c[0].canvas.width, c[0].canvas.height]; out.flags = c.map((x) => x.flags.map((f) => f.id));
      const again = await IG.generate('soft pink silk fabric with rose petals, luxury beauty background, studio light', { n: 1, seed: 4242, device: 'wasm' });
      const a = c[0].canvas.getContext('2d').getImageData(0, 0, 64, 64).data, bb = again[0].canvas.getContext('2d').getImageData(0, 0, 64, 64).data;
      let d = 0; for (let i = 0; i < a.length; i++) d += Math.abs(a[i] - bb[i]); out.seedDiff = d / a.length;
      let up = c[0].canvas;
      try { const t1 = performance.now(); up = await IG.upscale(c[0].canvas, { device: 'wasm' }); out.upMs = Math.round(performance.now() - t1); out.upMethod = up.dataset.method; } catch (err) { out.upErr = err.message; }
      // Swin2SR itself (the Mac GPU path) tried on the CPU, to report honestly what happens there
      IG.unload(); try { const t2 = performance.now(); const s2 = await IG.swin(c[0].canvas, 'wasm'); out.swin = 'Swin2SR ' + s2.width + 'px in ' + Math.round(performance.now() - t2) + ' ms'; } catch (err) { out.swin = 'error ' + err.message; }
      out.up = [up.width, up.height];
      out.path = await IG.save('kbb', up, { prompt: c[0].prompt, seed: c[0].seed, upscaled: !!out.upMs });
      out.png = c[0].canvas.toDataURL('image/jpeg', 0.9); out.png2 = c[1].canvas.toDataURL('image/jpeg', 0.9);
      // designs that use the picture still pass every rule
      const W = await import('/app/editor/workspaces.js'), B = await import('/app/designs/lib/brief.js'), Gn = await import('/app/designs/generate.js'), Ct = await import('/app/designs/catalogue.js');
      W.switchWorkspace('kbb'); const cat = await Ct.catalogueAll();
      const spec = B.parseBrief('New arrival: Biodance collagen eye cream, calm, square and story', W.state.current, cat);
      const res = await Gn.generate(spec, W.state.current, cat, { count: 8, image: out.path });
      out.withImage = res.designs.filter((D) => D.els.some((x) => x.src === out.path) || D.bgImage).length; out.designs = res.designs.length;
      return out;
    });
    fs.writeFileSync(path.join(OUT, 'ai-1.jpg'), Buffer.from(ai.png.split(',')[1], 'base64')); fs.writeFileSync(path.join(OUT, 'ai-2.jpg'), Buffer.from(ai.png2.split(',')[1], 'base64'));
    delete ai.png; delete ai.png2;
    console.log('     image generation:', JSON.stringify(ai));
    ok(/text in the picture/.test(ai.refused || ''), 'a prompt that asks for words in the picture is refused before the model runs');
    ok(ai.n === 2 && ai.size[0] === 512 && ai.flags.every((f) => !f.includes('blank')), `2 pictures from SDXS on WASM (CPU) at 512×512 in ${ai.each.map((x) => (x / 1000).toFixed(1) + ' s').join(' / ')}`);
    ok(ai.seedDiff < 1, `seed lock: the same seed gives the same picture (mean pixel difference ${ai.seedDiff.toFixed(2)})`);
    ok(ai.up[0] === 1024 && !ai.upErr, `enlarge x2 on the CPU (${ai.upMethod}) → ${ai.up.join('×')} in ${ai.upMs} ms · Swin2SR tried on this machine: ${ai.swin}`);
    ok(fs.existsSync(path.join(dir, ai.path)) && JSON.parse(fs.readFileSync(path.join(dir, ai.path.replace(/\.png$/, '.json')), 'utf8')).licence.includes('RAIL'), 'the picture is saved in the company library with its prompt, seed, model and licence');
    ok(ai.withImage >= 1 && ai.designs >= 6, `designs with the AI picture: ${ai.withImage} of ${ai.designs}, all passing the rules`);
    // the Visuals panel, with pictures
    await page.goto(e.url + '/app/'); await page.waitForSelector('#home-brief');
    await page.fill('#home-brief', 'New arrival: Biodance collagen eye cream, calm, story and post'); await page.click('#home-designs');
    await page.waitForFunction(() => document.querySelectorAll('.dz-card').length >= 6, null, { timeout: 60000 });
    await page.click('.dz-tabs [data-t="ai"]'); await page.waitForSelector('#dz-ai-go:not([disabled])', { timeout: 20000 });
    await page.selectOption('#dz-n', '2'); await page.click('#dz-lock'); await page.fill('#dz-seed', '777');
    await page.click('#dz-ai-go');
    await page.waitForFunction(() => document.querySelectorAll('#dz-cands .dz-cand').length >= 2 && /pictures ·/.test(document.querySelector('#dz-ai-msg').textContent), null, { timeout: 240000 });
    await page.click('#dz-cands .dz-cand');
    await page.waitForFunction(() => /used in the designs/.test(document.querySelector('#dz-ai-msg').textContent), null, { timeout: 240000 });
    await page.waitForFunction(() => /designs · every rule/.test(document.querySelector('#dz-prog').textContent), null, { timeout: 60000 });
    await page.screenshot({ path: path.join(SHOTS, 'ai-visuals-1920.png') });
    await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(SHOTS, 'ai-visuals-1280.png') });
    await page.setViewportSize({ width: 1920, height: 1080 });
  } else console.log('SKIP image generation (no models/' + SDXS + ' here, or --no-ai)');

  // ── ⚙ Configure ──
  await page.goto(e.url + '/app/'); await page.waitForSelector('#configure-btn');
  await page.click('#configure-btn'); await page.waitForSelector('.cfg-back [data-co]');
  ok((await page.$$('.cfg-back [data-co]')).length >= 2, 'Configure → Companies lists KBB and Planet Careers with their kits');
  await page.screenshot({ path: path.join(SHOTS, 'configure-companies-1920.png') });
  await page.click('[data-sec="claude"]'); await page.waitForSelector('#cl-key');
  neg = true; await page.fill('#cl-key', 'not-a-key'); await page.click('#cl-save'); await page.waitForFunction(() => /does not look like/.test(document.querySelector('#cl-out').textContent));
  ok(true, 'a wrong-looking key is refused with a plain message');
  await page.fill('#cl-key', KEY);
  ok(await page.$eval('#cl-key', (i) => i.type === 'password'), 'the key field is hidden by default (👁 Show to check it)');
  await page.click('#cl-save'); await page.waitForSelector('#cl-status.cfg-ok', { timeout: 10000 });
  const st = await (await fetch(e.url + '/api/claude', { headers: { 'X-RS-Token': e.token } })).text();
  ok(!st.includes('MockKey') && st.includes('…Z9T'.slice(1)) && /"set":true/.test(st), 'the app only ever sees "set" and the last four characters');
  const kf = path.join(dir, 'settings', 'claude.json');
  ok((fs.statSync(kf).mode & 0o777) === 0o600 && fs.readFileSync(kf, 'utf8').includes(KEY), 'the key is in settings/claude.json, mode 0600');
  const leak = await page.evaluate(async () => { const r = await fetch('/settings/claude.json'); const t = await r.text(); const r2 = await fetch('/api/stat?path=settings/claude.json', { headers: { 'X-RS-Token': document.querySelector('meta[name="rs-token"]').content } }); return { code: r.status, has: t.includes('sk-ant'), stat: r2.status }; });
  ok(leak.code === 403 && !leak.has && leak.stat === 403, 'the file API cannot read settings/claude.json (403)');
  await page.waitForTimeout(200); neg = false;
  const before = mock.log.length;
  await page.click('#cl-test');
  await page.waitForSelector('.modal-back .btn.primary'); await page.screenshot({ path: path.join(SHOTS, 'configure-claude-permission-1920.png') });
  await page.click('.modal-back .btn.primary');
  await page.waitForFunction(() => /Works/.test(document.querySelector('#cl-out').textContent), null, { timeout: 15000 });
  const tReq = mock.log[before];
  ok(mock.log.length === before + 1 && tReq.headers['x-api-key'] === KEY && tReq.headers['anthropic-version'] === '2026-10-21' && tReq.body.model === 'claude-sonnet-5-5' && !('temperature' in tReq.body), 'Test: one request to the (mock) Messages API with the key, anthropic-version 2026-10-21, the default model claude-sonnet-5-5');
  await page.click('#cl-smart'); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'configure-claude-1920.png') });
  await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(200);
  await page.screenshot({ path: path.join(SHOTS, 'configure-claude-1280.png') });
  await page.setViewportSize({ width: 1920, height: 1080 });
  // Smart brief through the engine (permission already given this session)
  const smart = await page.evaluate(async (text) => {
    const W = await import('/app/editor/workspaces.js'), L = await import('/app/designs/llm.js'), Ct = await import('/app/designs/catalogue.js');
    W.switchWorkspace('kbb'); const cat = await Ct.catalogueAll();
    const r = await L.readBrief(text, W.state.current, cat, { smart: true });
    return { via: r.via, refused: r.refused, products: r.spec.products, copy: r.spec.copySuggestions?.map((c) => c.headline) };
  }, BRIEFS[0].text);
  const sReq = mock.log.at(-1), sUser = sReq.body.messages[0].content;
  ok(smart.via === 'claude' && smart.refused.some((x) => /fakeprod/.test(x)) && smart.refused.some((x) => /50/.test(x)) && !smart.products.includes('fakeprod'), `Smart brief: read by Claude through the engine; its invented product and "50%" were refused (${smart.refused.length} refused)`);
  ok(sReq.body.messages.length === 1 && sUser.includes(BRIEFS[0].text) && /anua = Anua/.test(sUser) && !/MockKey|workspace\.json|kbeautybliss\.com\/|\.png|\.mp4/.test(sUser) && sReq.body.output_config.format.type === 'json_schema' && sReq.headers['anthropic-beta'] === 'server-side-fallback-2026-07-01', 'only the brief text and the catalogue names were sent (structured JSON output, refusal fallback on)');
  const tr = await page.evaluate(async () => { const C = await import('/app/settings/claude.js'); return C.claudeRun({ task: 'translate', lines: ['30% off today'], from: 'en', to: 'ar', glossary: ['Anua'] }); });
  ok(tr.result?.lines?.[0] === '[ar] 30% off today', 'the translation lane\'s hook: claudeRun({task:"translate"}) returns {lines} through the engine');
  // photo keys, models, folders, languages
  await page.click('[data-sec="photos"]'); await page.waitForSelector('[data-photo="pexels"] input[type=password]');
  await page.fill('[data-photo="pexels"] input[type=password]', 'PexelsKey_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGH');
  await page.click('[data-photo="pexels"] .btn.primary'); await page.waitForFunction(() => /key saved/.test(document.querySelector('[data-photo="pexels"]').textContent));
  ok(fs.statSync(path.join(dir, 'settings', 'photo-sources.json')).mode % 512 === 0o600, 'Photo sources: the Pexels key is saved by the engine (0600), shown as "key saved …"');
  await page.click('[data-sec="models"]'); await page.waitForSelector('[data-model="image-ai"]');
  const mtxt = await page.$eval('[data-model="image-ai"]', (x) => x.textContent);
  ok(/983 MB|0\.98 GB|983/.test(mtxt) && /RAIL/.test(mtxt) && /Image AI/.test(mtxt), 'Models: the picture model is listed with its size, group and licence');
  await page.screenshot({ path: path.join(SHOTS, 'configure-models-1920.png') });
  await page.click('[data-sec="folders"]'); await page.waitForSelector('[data-folder="workspaces"]');
  await page.click('[data-folder="workspaces"] button'); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'configure-folders-1920.png') });
  await page.click('[data-sec="languages"]'); await page.waitForSelector('#lang-cap');
  await page.selectOption('#lang-cap', 'ar'); await page.click('#lang-save'); await page.waitForTimeout(300);
  const prefs = await (await fetch(e.url + '/api/prefs', { headers: { 'X-RS-Token': e.token } })).json();
  ok(prefs.captionLang === 'ar' && prefs.smartBrief === true, 'Languages and Smart brief are saved as preferences (settings/prefs.json)');
  await page.click('[data-sec="contact"]'); await page.waitForSelector('[data-contact="kbb"]');
  await page.fill('[data-contact="kbb"] input[data-k="whatsapp"]', '+971 50 000 0000'); await page.click('[data-contact="kbb"] .btn.primary'); await page.waitForTimeout(400);
  ok(JSON.parse(fs.readFileSync(path.join(dir, 'workspaces', 'kbb', 'workspace.json'), 'utf8')).contactInfo?.whatsapp === '+971 50 000 0000', 'Contact & social saved per company');
  await page.click('[data-sec="claude"]'); await page.waitForSelector('#cl-disc:not([disabled])');
  await page.click('#cl-disc'); await page.click('.modal-back .btn.primary'); await page.waitForSelector('#cl-status.cfg-muted');
  neg = true;
  const afterDisc = await page.evaluate(async () => { const C = await import('/app/settings/claude.js'); try { await C.claudeRun({ task: 'test' }, async () => true); return 'ran'; } catch (err) { return err.message; } });
  ok(!fs.existsSync(kf) && /not connected/i.test(afterDisc), 'Disconnect deletes the key; a later call is refused ("Claude is not connected")');

  // ── nothing leaked, nothing went outside ──
  await page.close(); await b.close(); await stopEngine(e);
  const grepKey = (d) => { let hit = []; const walk = (p) => { for (const f of fs.readdirSync(p, { withFileTypes: true })) { const q = path.join(p, f.name); if (f.isDirectory()) walk(q); else if (f.size < 64e6 && fs.readFileSync(q).includes('MockKey_0123')) hit.push(q); } }; if (fs.existsSync(d)) walk(d); return hit; };
  const hits = [...grepKey(path.join(dir, 'workspaces')), ...grepKey(path.join(dir, 'library')), ...grepKey(path.join(dir, 'brands'))];
  ok(!hits.length && !(e.log || '').includes('MockKey'), 'the key appears in no project, design, export, library file or engine log' + (hits.length ? ': ' + hits.join(', ') : ''));
  ok(!outside.length, 'no request left the engine (Claude went through the engine to the local mock)' + (outside.length ? ': ' + outside.slice(0, 3).join(', ') : ''));
  ok(!errs.length, 'no console errors' + (errs.length ? ': ' + errs.slice(0, 4).join(' | ') : ''));
  mock.close();
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(fails ? `\n${fails} FAILED` : '\nALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
