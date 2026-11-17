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
//   - 📺 the broadcast ad templates (ads.js): every template × 1:1 / 4:5 / 9:16 / 16:9 × EN / AR passes every rule,
//     Arabic mirrors, facts are placeholders, company details fill contact / button / logo / photo slots, export =
//     preview (PNG and the MP4's frame at the still), save → reload, an older saved design still opens; renders of
//     every template (4:5 PNG + 9:16 MP4) -> tools/out/ads2/
//   - the design families (careers / travel / occupations / bright future, ads-fam.js): every family template × format ×
//     EN / AR through the same rules / RTL / facts checks, the family filter on the screen, export = preview, save →
//     reload, renders (4:5 PNG + 9:16 MP4) -> tools/out/families/
//   - screenshots 1920×1080 + 1280×800 -> shots/d/ (the ad-template screen -> tools/out/d/)
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
  page.on('response', (r) => { if (r.status() === 404) console.log('     (404 ' + r.url().replace(e.url, '') + ')'); });
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

  // ── 📺 the broadcast ad templates (ads.js + broadcast.js on the themes' art kit) ──
  await adTemplates(page, e, dir);

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

// ── the broadcast ad templates, every one × every format × EN/AR ──
//   rules     every hard design rule passes as the template comes (no fixing needed) in 1:1, 4:5, 9:16 and 16:9
//   RTL       Arabic designs mirror the English composition and their words are Arabic
//   facts     placeholders only: no number that reads as a fee, salary, rate or date; no claim the editor would warn on
//   company   contact, button, logo and photo slots from the company's details (⚙ Configure)
//   export    the PNG is the preview pixel for pixel; the MP4's frame at the still is the still (ffmpeg-decoded)
//   save      a saved template design reloads and draws the same pixels; an older saved design still opens unchanged
//   brief     a brief now leads with broadcast templates, still 6–8 distinct designs passing every rule
//   renders   every template in 4:5 (PNG) and 9:16 (432×768 MP4, 8 s) → tools/out/ads2/
async function adTemplates(page, e, dir) {
  const OUT2 = path.join(ROOT, 'tools', 'out', 'ads2');
  fs.rmSync(OUT2, { recursive: true, force: true }); fs.mkdirSync(OUT2, { recursive: true });
  await page.goto(e.url + '/app/'); await page.waitForSelector('#home-brief');
  const r = await page.evaluate(async () => {
    const W = await import('/app/editor/workspaces.js'), Ct = await import('/app/designs/catalogue.js'), Ad = await import('/app/designs/ads.js'), Ru = await import('/app/designs/rules.js'), R = await import('/app/designs/render.js'), Cp = await import('/app/designs/lib/copy.js'), Ed = await import('/app/designs/editor.js'), Mo = await import('/app/designs/motion.js'), Gn = await import('/app/designs/generate.js');
    const cat = await Ct.catalogueAll(), out = { cells: 0, fails: [], rtl: [], facts: [], claims: [], ids: Ad.AD_TEMPLATES.map((t) => t.id), worst: 99 };
    const pix = (c) => c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    const diff = (a, b) => { let m = 0, s = 0; for (let i = 0; i < a.length; i++) { const d = Math.abs(a[i] - b[i]); s += d; if (d > m) m = d; } return { max: m, mean: s / a.length }; };
    const FACT = /(\d+\s*%|\b(aed|usd|eur|gbp|€|£|\$)\s*\d|\d+\s*(days?|weeks?|months?|years?)\b|\b(approval|success)\s+rate|\b\d{3,}\b)/i;
    const okNum = (s) => s.replace(/\+971 XX XXX XXXX|PC 0001|DD · MM · YYYY|DD MMM( YYYY)?|XX%?|‹[^›]*›/g, '');
    for (const t of Ad.AD_TEMPLATES) {
      W.switchWorkspace(t.co); const ws = W.state.current;
      const sets = { en: t.slides ? Ad.makeCarousel(t.id, ws, cat, { lang: 'en' }) : [Ad.makeAd(t.id, ws, cat, { lang: 'en' })], ar: t.slides ? Ad.makeCarousel(t.id, ws, cat, { lang: 'ar' }) : [Ad.makeAd(t.id, ws, cat, { lang: 'ar' })] };
      for (const [lang, Ds] of Object.entries(sets)) for (const [k, D] of Ds.entries()) {
        await R.prepare(D);
        for (const s of Ad.AD_SIZES) {
          out.cells++;
          if (!D.sizes.includes(s)) { out.fails.push(`${t.id} ${lang} ${s}: size missing`); continue; }
          const c = Ru.check(D, s);
          if (!c.pass) out.fails.push(`${t.id} ${lang}#${k} ${s}: ` + c.checks.filter((x) => !x.ok && !['grid', 'words'].includes(x.id)).map((x) => x.msg).join('; '));
          for (const it of c.items) if (it.contrast) out.worst = Math.min(out.worst, it.contrast);
        }
        // RTL: the Arabic design mirrors the English one, its words are Arabic and right-aligned
        if (lang === 'ar') {
          const E = sets.en[k];
          for (const s of Ad.AD_SIZES) {
            const [Wd] = R.PX[s];
            for (const el of D.els) {
              const ga = D.geo[s][el.id], ge = E.geo[s][el.id];
              if (!ga || !ge || ga.hd) continue;
              if (Math.abs(ga.x - (Wd - ge.x - ge.w)) > 1) out.rtl.push(`${t.id} ${s} ${el.id} not mirrored`);
              if ((el.t === 'text' || el.t === 'btn') && ge.al === 'l' && ga.al !== 'r') out.rtl.push(`${t.id} ${s} ${el.id} not right-aligned`);
            }
          }
          for (const el of D.els) if (['headline', 'kicker', 'cta'].includes(el.role) && el.txt && !/[؀-ۿ]/.test(el.txt) && !/^\{?[A-Z0-9 &%‹›]+$/.test(el.txt) && !/^[A-Z][A-Za-z0-9 .'-]+$/.test(el.txt)) out.rtl.push(`${t.id} ${el.id} "${el.txt}" is not Arabic`);
        }
        // facts: placeholders only, and nothing the editor's claim checker would warn about
        for (const el of D.els) {
          const words = [el.txt, ...Object.values(el.words || {})].filter(Boolean);
          for (const w of words) {
            if (FACT.test(okNum(w))) out.facts.push(`${t.id} ${el.id}: "${w}"`);
            const cw = Cp.claimWarnings(w); if (cw.length) out.claims.push(`${t.id} ${el.id}: "${w}" ${cw.join(' ')}`);
          }
        }
      }
    }
    // the company's details (⚙ Configure) reach every ad
    W.switchWorkspace('planet-careers'); const pc = W.state.current, keep = structuredClone(pc);
    Object.assign(pc, { contactInfo: { whatsapp: '+971 50 123 4567', website: 'planet-example.com' }, endCard: { ...pc.endCard, cta: 'Talk to an adviser', ctaAr: 'تحدّث مع مستشار' }, adPhotos: { worker: 'brands/planet-careers/preview.png' } });
    const Dc = Ad.makeAd('pc-split', pc, cat, { lang: 'en' }), Dca = Ad.makeAd('pc-hiring', pc, cat, { lang: 'ar' });
    const txt = (D, id) => D.els.find((x) => x.id === id)?.txt;
    out.company = { contact: txt(Dc, 'contact'), cta: txt(Dc, 'cta'), ctaAr: txt(Dca, 'cta'), contactAr: txt(Dca, 'contact'), photo: Dc.els.find((x) => x.id === 'photo')?.src, logo: Dc.brand.logo, kitLogo: Gn.brandOf(pc).logo, hasLogo: Dc.els.some((x) => x.t === 'logo') };
    Object.keys(pc).forEach((k) => delete pc[k]); Object.assign(pc, keep);
    // export = preview (PNG), the still = the frame at t = still, the MP4 frame at the still (checked in node)
    W.switchWorkspace('planet-careers');
    const D1 = Ad.makeAd('pc-hiring', W.state.current, cat, { lang: 'en' }); await R.prepare(D1);
    const [png] = await Ed.exportDesign(D1, { sizes: ['post'], scale: 1, type: 'png' });
    const bm = await createImageBitmap(await (await fetch('/' + png.path)).blob()), cv = document.createElement('canvas'); cv.width = bm.width; cv.height = bm.height; cv.getContext('2d').drawImage(bm, 0, 0);
    out.exportVsPreview = diff(pix(cv), pix(R.renderCanvas(D1, 'post', 1)));
    const mo = Mo.motionOf(D1);
    out.stillVsT = diff(pix(R.renderCanvas(D1, 'story', 0.4)), pix(R.renderCanvas(D1, 'story', 0.4, { t: mo.still })));
    out.early = diff(pix(R.renderCanvas(D1, 'story', 0.4)), pix(R.renderCanvas(D1, 'story', 0.4, { t: 0.5 }))).mean;
    const [mp4] = await Ed.exportDesign(D1, { sizes: ['story'], type: 'mp4', width: 432 });
    out.mp4 = mp4; out.still = mo.still;
    const sc = document.createElement('canvas'); sc.width = 108; sc.height = 192; sc.getContext('2d').drawImage(R.renderCanvas(D1, 'story', 0.4), 0, 0, 108, 192);
    const sd = sc.getContext('2d').getImageData(0, 0, 108, 192).data; out.stillRGB = []; for (let i = 0; i < sd.length; i += 4) out.stillRGB.push(sd[i], sd[i + 1], sd[i + 2]);
    // save → reload draws the same pixels
    const D2 = Ad.makeAd('kbb-offer', (W.switchWorkspace('kbb'), W.state.current), cat, { lang: 'ar' });
    const sp = await Ed.saveDesign(D2), back = await (await import('/app/core/engine.js')).engine.readFile(sp, 'json'); await R.prepare(back);
    out.reload = diff(pix(R.renderCanvas(D2, 'story', 0.5)), pix(R.renderCanvas(back, 'story', 0.5))); out.reloadPath = sp;
    // the brief now leads with broadcast templates
    const B = await import('/app/designs/lib/brief.js'); W.switchWorkspace('planet-careers');
    const spec = B.parseBrief('Work abroad: welders wanted for Poland, apply on WhatsApp. Story, post and 16:9 wide.', W.state.current, cat);
    const g = await Gn.generate(spec, W.state.current, cat, { count: 8 });
    out.brief = { n: g.designs.length, ads: g.designs.filter((D) => D.ad).map((D) => D.family), distinct: new Set(g.designs.map((D) => D.family)).size, pass: g.designs.every((D) => D.sizes.every((s) => Ru.check(D, s).pass)), sizes: spec.sizes, wide: g.designs.filter((D) => D.sizes.includes('wide')).length };
    // the design families (careers / travel / occupations / bright future): in the catalogue, out of a brief's picks,
    // Arabic pieces run right to left, export = preview, the still = the frame at t = still, save → reload
    const F = Ad.AD_TEMPLATES.filter((t) => t.fam), pcws = (W.switchWorkspace('planet-careers'), W.state.current);
    out.fam = { n: F.length, by: Object.fromEntries(['careers', 'travel', 'occupations', 'future'].map((f) => [f, F.filter((t) => t.fam === f).length])), inBrief: Ad.adTemplatesFor(pcws, spec).filter((t) => t.fam).length, pieces: new Set(F.map((t) => t.piece)).size };
    const Fa = Ad.makeAd('fam-cv', pcws, cat, { lang: 'ar' }); await R.prepare(Fa);
    out.fam.rtl = Ad.makeAd('fam-path', pcws, cat, { lang: 'ar' }).els.filter((e) => e.t === 'art').every((e) => e.rtl) && !Ad.makeAd('fam-path', pcws, cat, { lang: 'en' }).els.some((e) => e.rtl);
    const [fpng] = await Ed.exportDesign(Fa, { sizes: ['post'], scale: 1, type: 'png' });
    const fbm = await createImageBitmap(await (await fetch('/' + fpng.path)).blob()), fcv = document.createElement('canvas'); fcv.width = fbm.width; fcv.height = fbm.height; fcv.getContext('2d').drawImage(fbm, 0, 0);
    out.fam.exportVsPreview = diff(pix(fcv), pix(R.renderCanvas(Fa, 'post', 1))).max;
    out.fam.stillVsT = diff(pix(R.renderCanvas(Fa, 'story', 0.4)), pix(R.renderCanvas(Fa, 'story', 0.4, { t: Mo.motionOf(Fa).still }))).max;
    out.fam.early = diff(pix(R.renderCanvas(Fa, 'story', 0.4)), pix(R.renderCanvas(Fa, 'story', 0.4, { t: 0.6 }))).mean;
    const Fw = Ad.makeAd('fam-worker', pcws, cat, { lang: 'en' }); Fw.els.find((e) => e.t === 'art').role = 'chef'; await R.prepare(Fw);
    const fsp = await Ed.saveDesign(Fw), fback = await (await import('/app/core/engine.js')).engine.readFile(fsp, 'json'); await R.prepare(fback);
    out.fam.reload = diff(pix(R.renderCanvas(Fw, 'square', 0.5)), pix(R.renderCanvas(fback, 'square', 0.5))).max; out.fam.reloadRole = fback.els.find((e) => e.t === 'art').role;
    const Fs = Ad.makeAd('fam-worker', pcws, cat, { lang: 'en', spec: { ...spec, occupations: ['welder'] } });
    out.fam.briefRole = Fs.els.find((e) => e.t === 'art').role;
    return out;
  });
  ok(!r.fails.length, `${r.ids.length} broadcast templates (${r.ids.join(', ')}) × 1:1 / 4:5 / 9:16 / 16:9 × EN / AR (${r.cells} design-sizes, carousel slides included): every hard rule passes as made · lowest text contrast ${r.worst.toFixed(1)}:1` + (r.fails.length ? '\n     ' + r.fails.slice(0, 8).join('\n     ') : ''));
  ok(!r.rtl.length, 'Arabic: every element mirrored, every text right-aligned where the English is left-aligned, headline / kicker / button in Arabic' + (r.rtl.length ? ': ' + r.rtl.slice(0, 6).join(' | ') : ''));
  ok(!r.facts.length, 'facts are placeholders only (no fee, salary, rate, duration or date in any default word)' + (r.facts.length ? ': ' + r.facts.slice(0, 6).join(' | ') : ''));
  ok(!r.claims.length, 'no default word trips the claim checker (guaranteed / 100% / medical)' + (r.claims.length ? ': ' + r.claims.slice(0, 4).join(' | ') : ''));
  const C = r.company;
  ok(/\+971 50 123 4567/.test(C.contact) && /planet-example\.com/.test(C.contact) && /\+971 50 123 4567/.test(C.contactAr) && /واتساب/.test(C.contactAr) && C.cta === 'Talk to an adviser' && C.ctaAr === 'تحدّث مع مستشار' && C.photo === 'brands/planet-careers/preview.png' && C.logo === C.kitLogo && C.hasLogo, `contact, button (EN + AR), logo and the worker photo slot come from the company's details ("${C.contact}")`);
  ok(r.exportVsPreview.max === 0, `export = preview: the exported PNG equals the preview pixel for pixel (max difference ${r.exportVsPreview.max})`);
  ok(r.stillVsT.max === 0 && r.early > 1, `the still is the animation's frame at t = ${r.still} s exactly; at 0.5 s the entrances are still running (mean difference ${r.early.toFixed(1)})`);
  const mp4 = path.join(dir, r.mp4.path);
  const pr = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=codec_name,width,height,nb_frames', '-of', 'json', mp4]).toString());
  const fr = execFileSync('ffmpeg', ['-v', 'error', '-ss', String(r.still + 0.017), '-i', mp4, '-frames:v', '1', '-vf', 'scale=108:192', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 24 });
  let dsum = 0; for (let i = 0; i < fr.length; i++) dsum += Math.abs(fr[i] - r.stillRGB[i]);
  const vdiff = dsum / fr.length;
  ok(pr.streams[0].codec_name === 'h264' && pr.streams[0].width === 432 && pr.streams[0].height === 768 && Math.abs(+pr.format.duration - 8) < 0.2 && vdiff < 8, `animated export: H.264 MP4 432×768, ${(+pr.format.duration).toFixed(2)} s; its frame at the still matches the still PNG (mean difference ${vdiff.toFixed(1)} of 255, compression only)`);
  ok(r.reload.max === 0, `save → reload: ${r.reloadPath.split('/').pop()} draws the same pixels after reading it back`);
  const Fm = r.fam;
  ok(Fm.n >= 20 && Object.values(Fm.by).every((n) => n >= 5) && Fm.pieces === Fm.n && Fm.inBrief === 0, `design families: ${Fm.n} templates (${Object.entries(Fm.by).map(([k, v]) => k + ' ' + v).join(', ')}), each its own piece; a brief still leads with the broadcast templates (${Fm.inBrief} family templates in its picks)`);
  ok(Fm.rtl && Fm.exportVsPreview === 0 && Fm.stillVsT === 0 && Fm.early > 1 && Fm.reload === 0 && Fm.reloadRole === 'chef' && Fm.briefRole === 'welder', `families: Arabic pieces run right to left; export = preview (max ${Fm.exportVsPreview}); the still is the frame at t = still, at 0.6 s the piece is still building (mean ${Fm.early.toFixed(1)}); save → reload keeps the trade (${Fm.reloadRole}) and the pixels; a brief's welders draw a welder`);
  ok(r.brief.n >= 6 && r.brief.n <= 8 && r.brief.distinct === r.brief.n && r.brief.pass && r.brief.ads.length >= 3 && r.brief.sizes.includes('wide') && r.brief.wide >= 3, `a brief leads with broadcast templates: ${r.brief.n} distinct designs (${r.brief.ads.join(', ')} first), all passing every rule, ${r.brief.wide} of them also in 16:9`);
  // an older saved design (before the broadcast templates) still opens and draws as it did
  const legacy = JSON.parse(fs.readFileSync(path.join(ROOT, 'tools', 'fixtures', 'legacy-design.json'), 'utf8'));
  fs.mkdirSync(path.join(dir, 'workspaces', 'planet-careers', 'designs'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'workspaces', 'planet-careers', 'designs', legacy.id + '.json'), JSON.stringify(legacy));
  fs.copyFileSync(path.join(ROOT, 'tools', 'fixtures', 'legacy-design.png'), path.join(dir, 'workspaces', 'planet-careers', 'designs', legacy.id + '.png'));   // its thumbnail, as saveDesign writes one
  const lg = await page.evaluate(async ({ id }) => {
    const R = await import('/app/designs/render.js'), Ru = await import('/app/designs/rules.js'), { engine } = await import('/app/core/engine.js');
    const D = await engine.readFile(`workspaces/planet-careers/designs/${id}.json`, 'json'); await R.prepare(D);
    return { pass: D.sizes.every((s) => Ru.check(D, s).pass), url: R.renderCanvas(D, D.sizes[0], 0.5).toDataURL('image/png') };
  }, { id: legacy.id }).catch((x) => ({ err: x.message }));
  let ldiff = 99;
  if (lg.url) {
    const now = await page.evaluate(async ({ a, b }) => {
      const load = async (u) => { const bm = await createImageBitmap(await (await fetch(u)).blob()); const c = document.createElement('canvas'); c.width = bm.width; c.height = bm.height; c.getContext('2d').drawImage(bm, 0, 0); return c.getContext('2d').getImageData(0, 0, c.width, c.height).data; };
      const x = await load(a), y = await load(b); if (x.length !== y.length) return 99; let s = 0; for (let i = 0; i < x.length; i++) s += Math.abs(x[i] - y[i]); return s / x.length;
    }, { a: lg.url, b: 'data:image/png;base64,' + fs.readFileSync(path.join(ROOT, 'tools', 'fixtures', 'legacy-design.png')).toString('base64') });
    ldiff = now;
  }
  ok(lg.pass && ldiff < 1.5, `an older saved design (tools/fixtures/legacy-design.json, "${legacy.name}") still passes and draws as before (mean pixel difference ${ldiff.toFixed(2)})`);
  // the screen: 📺 Ad templates, open one, edit a piece's words, play, export
  await page.evaluate(async () => { const W = await import('/app/editor/workspaces.js'); W.switchWorkspace('planet-careers'); });
  await page.goto(e.url + '/app/'); await page.waitForSelector('#home-brief');
  await page.click('[data-mod-home="designs"] .tile-actions .btn:not(.primary)');
  await page.waitForSelector('#dz-mode'); await page.click('#dz-mode [data-mode="templates"]');
  await page.waitForSelector('#dz-t-fam');
  const SH = path.join(ROOT, 'tools', 'out', 'd');
  // the family filter: Careers / Travel / Occupations / Bright future beside the broadcast templates
  const famScreen = {};
  for (const f of ['careers', 'travel', 'occupations', 'future']) {
    await page.click(`#dz-t-fam [data-fam="${f}"]`);
    await page.waitForFunction((f) => { const c = [...document.querySelectorAll('#dz-templates .dz-card')]; return c.length >= 5 && c.every((x) => x.dataset.fam === f) && c.every((x) => x.querySelector('canvas')); }, f, { timeout: 120000 });
    famScreen[f] = await page.$$eval('#dz-templates .dz-card', (x) => x.length);
    if (f === 'careers') { await page.screenshot({ path: path.join(SH, 'ad-templates-careers-1920.png') }); await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300); await page.screenshot({ path: path.join(SH, 'ad-templates-careers-1280.png') }); await page.setViewportSize({ width: 1920, height: 1080 }); }
  }
  ok(Object.values(famScreen).every((n) => n >= 5), `Designs → 📺 Ad templates → family filter: ${Object.entries(famScreen).map(([k, v]) => k + ' ' + v).join(', ')} templates, each card from its own family`);
  await page.click('#dz-t-fam [data-fam="broadcast"]');
  await page.waitForFunction(() => document.querySelectorAll('#dz-templates .dz-card canvas').length >= 13 && [...document.querySelectorAll('#dz-templates .dz-card')].every((x) => x.dataset.fam === 'broadcast'), null, { timeout: 120000 });
  const nT = await page.$$eval('#dz-templates .dz-card', (x) => x.length);
  ok(nT === 10, `Designs → 📺 Ad templates → Broadcast & bulletin lists the company's ${nT} broadcast templates (carousels as one strip of slides)`);
  await page.screenshot({ path: path.join(SH, 'ad-templates-1920.png') });
  await page.setViewportSize({ width: 1280, height: 800 }); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SH, 'ad-templates-1280.png') });
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.selectOption('#dz-t-lang', 'ar'); await page.waitForFunction(() => document.querySelectorAll('#dz-templates .dz-card canvas').length >= 13, null, { timeout: 120000 });
  await page.click('#dz-templates [data-tpl="pc-stamp"] canvas');
  await page.waitForSelector('#de-canvas'); await page.waitForTimeout(800);
  await page.click('[data-el="passport"]'); await page.waitForSelector('.de-word');
  const words = await page.$$eval('.de-word', (x) => x.map((i) => i.dataset.word));
  await page.fill('[data-word="date"]', '01 · 01 · 2027'); await page.$eval('[data-word="date"]', (i) => i.dispatchEvent(new Event('change')));
  await page.waitForTimeout(400);
  const sizesSeg = await page.$$eval('#de-sizes button', (x) => x.map((b) => b.dataset.v));
  await page.click('#de-play'); await page.waitForFunction(() => document.querySelector('.de')?.dataset.played, null, { timeout: 20000 });
  await page.screenshot({ path: path.join(SH, 'ad-editor-ar-1920.png') });
  const ed = await page.evaluate(() => { const c = document.querySelector('#de-canvas'); return { w: c.width, played: document.querySelector('.de').dataset.played }; });
  ok(words.includes('approved') && words.includes('date') && sizesSeg.join() === 'story,post,square,wide' && +ed.played >= 7.9, `editor: a template opens in all four formats (${sizesSeg.join(', ')}); the passport piece's words (${words.join(', ')}) are editable; ▶ Play ran the ${ed.played} s animation once and stopped`);
  await page.click('#de-back');
  // the renders: every template in 4:5 (PNG) and 9:16 (MP4 432×768, 8 s)
  const list = await page.evaluate(async () => (await import('/app/designs/ads.js')).AD_TEMPLATES.filter((t) => !t.fam).map((t) => [t.id, t.co, t.slides || 0]));
  let nPng = 0, nMp4 = 0, bad = [];
  for (const [id, co, slides] of list) {
    const res = await page.evaluate(async ({ id, co, slides }) => {
      const W = await import('/app/editor/workspaces.js'), Ct = await import('/app/designs/catalogue.js'), Ad = await import('/app/designs/ads.js'), Ed = await import('/app/designs/editor.js');
      W.switchWorkspace(co); const ws = W.state.current, cat = await Ct.catalogueAll();
      const Ds = slides ? Ad.makeCarousel(id, ws, cat, {}) : [Ad.makeAd(id, ws, cat, {})], out = [];
      for (const [k, D] of Ds.entries()) {
        D.name = id + (slides ? '-slide' + (k + 1) : '');
        const [p] = await Ed.exportDesign(D, { sizes: ['post'], type: 'png' });
        const [v] = await Ed.exportDesign(D, { sizes: ['story'], type: 'mp4', width: 432 });
        out.push({ png: p.path, mp4: v.path, name: D.name });
      }
      return out;
    }, { id, co, slides });
    for (const x of res) {
      fs.copyFileSync(path.join(dir, x.png), path.join(OUT2, x.name + '-4x5.png')); nPng++;
      const mp = path.join(OUT2, x.name + '-9x16.mp4'); fs.copyFileSync(path.join(dir, x.mp4), mp);
      const q = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=width,height', '-of', 'json', mp]).toString());
      if (q.streams[0].width !== 432 || q.streams[0].height !== 768 || Math.abs(+q.format.duration - 8) > 0.2) bad.push(x.name); else nMp4++;
    }
  }
  ok(!bad.length && nMp4 === nPng, `renders: ${nPng} stills (4:5, 1080×1350 PNG) and ${nMp4} animated 9:16 MP4s (432×768, 8 s) in tools/out/ads2/` + (bad.length ? ' bad: ' + bad.join(', ') : ''));
  // the design families: every template as a 4:5 still and a 9:16 animated MP4 → tools/out/families/<family>-<id>
  const OUTF = path.join(ROOT, 'tools', 'out', 'families'); fs.mkdirSync(OUTF, { recursive: true });
  const fl = await page.evaluate(async () => (await import('/app/designs/ads.js')).AD_TEMPLATES.filter((t) => t.fam).map((t) => [t.id, t.fam]));
  let fP = 0, fM = 0; const fbad = [];
  for (const [id, fam] of fl) {
    const x = await page.evaluate(async ({ id }) => {
      const W = await import('/app/editor/workspaces.js'), Ct = await import('/app/designs/catalogue.js'), Ad = await import('/app/designs/ads.js'), Ed = await import('/app/designs/editor.js');
      W.switchWorkspace('planet-careers'); const D = Ad.makeAd(id, W.state.current, await Ct.catalogueAll(), {}); D.name = id;
      const [p] = await Ed.exportDesign(D, { sizes: ['post'], type: 'png' }), [v] = await Ed.exportDesign(D, { sizes: ['story'], type: 'mp4', width: 432 });
      return { png: p.path, mp4: v.path };
    }, { id });
    const base = path.join(OUTF, `${fam}-${id.replace(/^fam-/, '')}`);
    fs.copyFileSync(path.join(dir, x.png), base + '-4x5.png'); fP++;
    fs.copyFileSync(path.join(dir, x.mp4), base + '-9x16.mp4');
    const q = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration:stream=width,height', '-of', 'json', base + '-9x16.mp4']).toString());
    if (q.streams[0].width !== 432 || q.streams[0].height !== 768 || Math.abs(+q.format.duration - 8) > 0.2) fbad.push(id); else fM++;
  }
  ok(!fbad.length && fM === fP && fP >= 20, `family renders: ${fP} stills (4:5 PNG) and ${fM} animated 9:16 MP4s (432×768, 8 s) in tools/out/families/` + (fbad.length ? ' bad: ' + fbad.join(', ') : ''));
}
