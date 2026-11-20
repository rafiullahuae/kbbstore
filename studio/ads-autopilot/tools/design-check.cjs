#!/usr/bin/env node
// Design pipeline check (the ad design lane). Runs the REAL engine (-mock: fake
// Meta/Claude, never the real ones) and drives the app in Chromium:
//   1  the local cut-out model downloads once through the engine, SHA-256 verified
//      (skip with --no-model: the classic fallback is measured instead)
//   2  6 real feed products (kbeautybliss.com photos) are cut out; method + ms each
//   3  every family x format x AR/EN renders at the exact Meta size and is SCORED
//      (product share, text contrast on the real pixels, Reels safe zones, text
//      density, Arabic shaping); prints the numbers; fails below thresholds
//   4  carousel: one card per product + offer card, continuous background
//   5  motion: a 10 s 9:16 Arabic ad encoded to MP4 (WebCodecs H.264), < 3 MB
//   6  no console errors, no failed requests
// --shots writes shots/design-sheet-{ar,en}-{1,2}.jpg, shots/design-before-after.jpg,
// shots/design-motion-strip.jpg, shots/design-carousel.jpg, shots/design-sample-ar-9x16.mp4,
// shots/design-quality.json.
//   NODE_PATH=$(npm root -g) node tools/design-check.cjs [--shots] [--no-model] [--data DIR]
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const want = (f) => process.argv.includes(f);
const arg = (f, d) => { const i = process.argv.indexOf(f); return i > 0 ? process.argv[i + 1] : d; };
const PORT = 43100 + Math.floor(Math.random() * 500);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };

// 6 real products from the public feed (tools/fixtures/brain-products.json), with AR/EN copy.
const P = Object.fromEntries(JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures', 'brain-products.json'), 'utf8')).map((p) => [p.id, p]));
const COPY = {
  3214: { ar: { headline: 'هدوء فوري لبشرتكِ الحساسة', sub: 'هارتليف ٧٧٪ من أنوا', bullets: ['هارتليف ٧٧٪', 'ترطيب خفيف', 'للاستخدام اليومي'] }, en: { headline: 'Instant calm for sensitive skin', sub: 'Anua Heartleaf 77% toner', bullets: ['Heartleaf 77%', 'Light hydration', 'Daily use'] }, angle: 'calming heartleaf ingredient' },
  3195: { ar: { headline: 'صباحكِ يبدأ بلطف', sub: 'غسول جل بدرجة حموضة منخفضة', bullets: ['حموضة منخفضة', 'شجرة الشاي', 'صنع في كوريا'] }, en: { headline: 'The gentlest morning start', sub: 'Low pH Good Morning gel cleanser', bullets: ['Low pH', 'Tea tree + BHA', 'Made in Korea'] }, angle: 'gentle morning routine' },
  3206: { ar: { headline: 'توهّج على الطريقة الكورية', sub: 'سيروم البروبوليس والنياسيناميد', bullets: ['بروبوليس', 'نياسيناميد', 'قوام خفيف'] }, en: { headline: 'Glow, the K-beauty way', sub: 'Propolis + niacinamide serum', bullets: ['Propolis', 'Niacinamide', 'Lightweight'] }, angle: 'glass-skin glow' },
  3219: { ar: { headline: 'واقي شمس بقوام مائي', sub: 'خفيف كالماء، بلا أثر أبيض', bullets: ['قوام مائي', 'هيالورونيك', 'بلا أثر أبيض'] }, en: { headline: 'Sunscreen that feels like water', sub: 'Weightless, no white cast', bullets: ['Watery gel', 'Hyaluronic acid', 'No white cast'] }, angle: 'lightweight SPF texture' },
  3532: { ar: { headline: 'استيقظي ببشرة مشدودة ونضرة', sub: 'قناع الكولاجين الليلي', bullets: ['كولاجين', 'قناع ليلي', 'نضارة الصباح'] }, en: { headline: 'Wake up to bouncy skin', sub: 'Collagen night wrapping mask', bullets: ['Collagen', 'Overnight mask', 'Morning glow'] }, angle: 'premium night care' },
  3225: { ar: { headline: 'شفاه ناعمة طوال اليوم', sub: 'إسنس الشفاه بالسيراميد', bullets: ['سيراميد', 'ترطيب عميق', 'لمسة لامعة'] }, en: { headline: 'Soft lips all day', sub: 'Ceramide lip essence', bullets: ['Ceramide', 'Deep moisture', 'Soft shine'] }, angle: 'romantic gift' },
};
const ORDER = ['3214', '3195', '3206', '3219', '3532', '3225'];
const FAM_PRODUCTS = { hero: ['3206'], glow: ['3195'], ingredient: ['3214'], offer: ['3219'], routine: ['3195', '3214', '3219'], editorial: ['3532'], petals: ['3225'], texture: ['3219'], qa: ['3214'], collection: ['3206', '3214', '3532'] };
function spec(fam, lang) {
  const ids = FAM_PRODUCTS[fam], c = COPY[ids[0]], p = P[ids[0]];
  const L = c[lang];
  const routineBullets = fam === 'routine' ? (lang === 'ar' ? ['الغسول', 'التونر', 'الحماية'] : ['Cleanse', 'Tone', 'Protect']) : null;
  return { id: `chk-${fam}-${lang}`, lang, template: fam, images: ids.map((i) => P[i].image), product_ids: ids, headline: fam === 'routine' ? (lang === 'ar' ? 'روتينكِ الكوري في ٣ خطوات' : 'Your K-beauty routine in 3 steps') : fam === 'collection' ? (lang === 'ar' ? 'مختارات الأكثر مبيعاً' : 'Our best-sellers, together') : L.headline,
    sub: L.sub, bullets: routineBullets || L.bullets, badge: p.brand, cta: 'SHOP_NOW', price: lang === 'ar' ? `${Math.round(p.price)} درهم` : `AED ${Math.round(p.price)}`, angle: c.angle, numerals: 'arab', formats: ['1:1', '4:5', '9:16'] };
}

(async () => {
  const dataDir = arg('--data', path.join(os.tmpdir(), 'kaa-design-check-data'));
  fs.mkdirSync(dataDir, { recursive: true });
  const bin = path.join(os.tmpdir(), `kaa-engine-design-${process.pid}`);
  execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
  const env = Object.assign({}, process.env, fs.existsSync('/root/.ccr/ca-bundle.crt') ? { SSL_CERT_FILE: '/root/.ccr/ca-bundle.crt' } : {});
  const eng = spawn(bin, ['-dir', ROOT, '-data', dataDir, '-port', String(PORT), '-no-browser', '-mock', '-no-scheduler', '-idle', '1800s'], { stdio: ['ignore', 'pipe', 'pipe'], env });
  let engLog = '';
  eng.stdout.on('data', (d) => { engLog += d; }); eng.stderr.on('data', (d) => { engLog += d; });
  const stop = () => { try { eng.kill(); } catch {} try { fs.unlinkSync(bin); } catch {} };
  process.on('exit', stop);
  let up = false;
  for (let i = 0; i < 200 && !up; i++) { await new Promise((r) => setTimeout(r, 200)); up = await fetch(`http://127.0.0.1:${PORT}/api/info`).then((r) => r.ok).catch(() => false); }
  if (!up) { console.log(engLog.slice(-3000)); process.exit(2); }
  const base = `http://127.0.0.1:${PORT}/`;
  const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined, args: ['--autoplay-policy=no-user-gesture-required'] });
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  const errors = [], failed = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('requestfailed', (r) => { if (!/\/api\/events/.test(r.url()) && !/^blob:/.test(r.url())) failed.push(r.url() + ' ' + (r.failure() || {}).errorText); });
  page.on('response', (r) => { if (r.status() >= 400 && !/seg\/mask/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
  await page.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
  await page.goto(base);
  await page.waitForFunction(() => window.__kaa && window.__kaa.info, null, { timeout: 30000 });
  const H = async (fn, ...a) => page.evaluate(fn, ...a);

  // 1 model
  if (!want('--no-model')) {
    const t0 = Date.now();
    await H(async () => { const C = await import('/app/js/creative/cutout.js'); await C.segDownload('isnet'); });
    let st;
    for (let i = 0; i < 900; i++) {
      st = await H(async () => { const C = await import('/app/js/creative/cutout.js'); return C.segStatus(true); });
      if (st.have.isnet && st.have['ort-wasm']) break;
      if (Object.values(st.jobs || {}).some((j) => j.phase === 'failed')) break;
      await new Promise((r) => setTimeout(r, 1000));
    }
    const ok = st.have.isnet && st.have['ort-wasm'];
    check('cut-out model installed (SHA-256 verified)', ok, ok ? `ISNet ${(st.assets.find((a) => a.id === 'isnet').bytes / 1e6).toFixed(1)} MB + ONNX Runtime ${(st.assets.find((a) => a.id === 'ort-wasm').bytes / 1e6).toFixed(1)} MB in ${Math.round((Date.now() - t0) / 1000)} s (cached after the first run)` : JSON.stringify(st.jobs));
  } else await H(() => { try { localStorage.setItem('kaa.seg', 'off'); } catch {} });

  // 2 cut-outs
  const cuts = await H(async (urls) => {
    const R = await import('/app/js/creative/render.js');
    const out = [];
    for (const u of urls) { const t0 = performance.now(); const c = await R.loadImage(u); out.push({ u: u.split('/').pop().slice(0, 40), method: c && c.method, cutout: c && c.cutout, w: c && c.width, h: c && c.height, palette: c && c.palette, ms: Math.round(performance.now() - t0) }); }
    return out;
  }, ORDER.map((i) => P[i].image));
  for (const c of cuts) console.log(`      cut-out ${c.method.padEnd(16)} ${String(c.ms).padStart(6)} ms  ${c.w}x${c.h}  ${c.palette.join(' ')}  ${c.u}`);
  check('6 feed products cut out', cuts.length === 6 && cuts.every((c) => c.cutout), cuts.map((c) => c.method).join(', '));

  // 3 every family x format x language, scored
  const fams = await H(async () => (await import('/app/js/creative/render.js')).FAMILIES);
  const specs = [];
  for (const f of fams) for (const l of ['ar', 'en']) specs.push(spec(f, l));
  const rows = await H(async (specs) => {
    const R = await import('/app/js/creative/render.js');
    const Q = await import('/app/js/creative/quality.js');
    const out = [];
    for (const s of specs) for (const f of ['1:1', '4:5', '9:16']) {
      const t0 = performance.now();
      const cv = await R.renderSpec(s, f);
      const ms = Math.round(performance.now() - t0);
      const q = await Q.score(s, f, cv.assets);
      const png = await R.toPNG(cv);
      out.push({ fam: s.template, lang: s.lang, f, w: cv.width, h: cv.height, kb: Math.round(png.bytes / 1024), ms, ok: q.ok, score: q.score, fails: q.fails, c: q.checks });
    }
    return out;
  }, specs);
  const sizes = { '1:1': '1080x1080', '4:5': '1080x1350', '9:16': '1080x1920' };
  console.log('      family      lang fmt   score product contrast safe density  ms');
  for (const r of rows) console.log(`      ${r.fam.padEnd(11)} ${r.lang}   ${r.f.padEnd(5)} ${String(r.score).padStart(4)}   ${(r.c.product_share * 100).toFixed(0).padStart(3)}%   ${r.c.min_contrast.toFixed(1).padStart(5)}   ${r.c.safe_violations}   ${(r.c.text_density * 100).toFixed(0).padStart(3)}%  ${String(r.ms).padStart(4)}${r.ok ? '' : '  REFUSED: ' + r.fails.join('; ')}`);
  const bad = rows.filter((r) => `${r.w}x${r.h}` !== sizes[r.f]);
  const refused = rows.filter((r) => !r.ok);
  const avg = (k) => rows.reduce((a, r) => a + k(r), 0) / rows.length;
  check('every family x format x AR/EN renders at Meta sizes', rows.length === fams.length * 6 && !bad.length, `${rows.length} creatives (${fams.length} families x 3 formats x AR/EN), ${Math.min(...rows.map((r) => r.kb))}-${Math.max(...rows.map((r) => r.kb))} KB PNG, worst ${Math.max(...rows.map((r) => r.ms))} ms`);
  check('quality gate: all pass the thresholds', refused.length === 0, `avg score ${avg((r) => r.score).toFixed(1)}, min ${Math.min(...rows.map((r) => r.score))}; product share avg ${(avg((r) => r.c.product_share) * 100).toFixed(0)}% (min ${(Math.min(...rows.map((r) => r.c.product_share)) * 100).toFixed(0)}%), min text contrast ${Math.min(...rows.map((r) => r.c.min_contrast)).toFixed(2)}, safe-zone violations ${rows.reduce((a, r) => a + r.c.safe_violations, 0)}, max text density ${(Math.max(...rows.map((r) => r.c.text_density)) * 100).toFixed(0)}%${refused.length ? `; ${refused.length} refused` : ''}`);
  const arab = await H(async () => { const Q = await import('/app/js/creative/quality.js'); const R = await import('/app/js/creative/render.js'); const q = await Q.score({ lang: 'ar', template: 'hero', headline: 'لا', images: [] }, '1:1'); return { a: q.checks.arabic, d: R.digits('AED 105', { lang: 'ar' }) }; });
  check('Arabic shaping + numerals', arab.a.ok && arab.d === 'AED ١٠٥', `fonts ${JSON.stringify(arab.a.loaded)}, lam-alef ligature ${arab.a.ligature}, joined ${arab.a.joined}, digits "${arab.d}"`);
  // the gate refuses a bad creative (mutation-style proof)
  const gate = await H(async () => { const Q = await import('/app/js/creative/quality.js'); const q = await Q.score({ lang: 'en', template: 'hero', headline: 'x', images: [] }, '9:16'); return q; });
  check('quality gate refuses a creative with no product', !gate.ok, gate.fails.join('; '));

  // 4 carousel
  const car = await H(async (s) => { const R = await import('/app/js/creative/render.js'); const cards = await R.renderCarousel(s); window.__car = cards; return cards.map((c) => `${c.width}x${c.height}`); }, Object.assign(spec('collection', 'ar'), { bullets: ['تونر مهدّئ', 'سيروم التوهّج', 'قناع ليلي'] }));
  check('carousel cards', car.length === 4 && car.every((c) => c === '1080x1080'), `${car.length} cards ${car[0]} (3 products + offer card, RTL order)`);

  // 5 motion
  const mo = await H(async (s) => {
    const Mo = await import('/app/js/creative/motion.js');
    const t0 = performance.now();
    const r = await Mo.renderVideo(s, '9:16', 10, null, null);
    window.__mp4 = r.blob;
    const b = new Uint8Array(await r.blob.arrayBuffer());
    let bin = ''; for (let i = 0; i < b.length; i += 0x8000) bin += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000));
    window.__mp4b64 = btoa(bin);
    return { mime: r.mime, codec: r.codec, bytes: r.blob.size, w: r.width, h: r.height, ms: Math.round(performance.now() - t0), frames: r.frames, encoder: r.encoder };
  }, spec('glow', 'ar'));
  check('motion ad: 10 s 9:16 MP4 < 3 MB', mo.mime === 'video/mp4' && mo.bytes < 3e6 && mo.bytes > 100e3, `${mo.encoder} ${mo.codec} ${mo.w}x${mo.h}, ${mo.frames} frames, ${(mo.bytes / 1e6).toFixed(2)} MB, encoded in ${(mo.ms / 1000).toFixed(1)} s`);

  if (want('--shots')) {
    const mp4 = Buffer.from(await H(() => window.__mp4b64), 'base64');
    fs.writeFileSync(path.join(SHOTS, 'design-sample-ar-9x16.mp4'), mp4);
    try { const probe = execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=codec_name,width,height,nb_frames,duration', '-of', 'compact', path.join(SHOTS, 'design-sample-ar-9x16.mp4')]).toString().trim(); console.log('      ffprobe: ' + probe.replace(/\n/g, ' | ')); } catch (e) { console.log('      ffprobe failed: ' + e.message); }
    // contact sheets: rows = families, columns = 1:1, 4:5, 9:16
    for (const lang of ['ar', 'en']) for (const [part, fs5] of [[1, fams.slice(0, 5)], [2, fams.slice(5)]]) {
      const b64 = await H(async ([fs5, specs, lang]) => {
        const R = await import('/app/js/creative/render.js');
        const rowH = 400, pad = 18, cw = [400, 320, 225];
        const sheet = document.createElement('canvas'); sheet.width = cw.reduce((a, v) => a + v, 0) + pad * 4 + 150; sheet.height = fs5.length * (rowH + pad) + pad;
        const x = sheet.getContext('2d'); x.fillStyle = '#EFE9EB'; x.fillRect(0, 0, sheet.width, sheet.height);
        for (let r = 0; r < fs5.length; r++) {
          const s = specs.find((v) => v.template === fs5[r] && v.lang === lang);
          x.fillStyle = '#2B1F26'; x.font = '600 20px Inter'; x.fillText(fs5[r], pad, pad + r * (rowH + pad) + 30);
          let cx = 150 + pad;
          for (const [i, f] of ['1:1', '4:5', '9:16'].entries()) { const cv = await R.renderSpec(s, f); const h = rowH, w = Math.round(h * cv.width / cv.height); x.drawImage(cv, cx + (cw[i] - w) / 2, pad + r * (rowH + pad), w, h); cx += cw[i] + pad; }
        }
        return sheet.toDataURL('image/jpeg', 0.84).split(',')[1];
      }, [fs5, specs, lang]);
      fs.writeFileSync(path.join(SHOTS, `design-sheet-${lang}-${part}.jpg`), Buffer.from(b64, 'base64'));
    }
    // full-size samples
    for (const [fam, f, lang] of [['hero', '4:5', 'ar'], ['glow', '9:16', 'ar'], ['ingredient', '4:5', 'en'], ['offer', '1:1', 'ar'], ['editorial', '4:5', 'en'], ['routine', '1:1', 'ar']]) {
      const b = await H(async ([s, f]) => { const R = await import('/app/js/creative/render.js'); const cv = await R.renderSpec(s, f); const c = document.createElement('canvas'); c.width = cv.width / 2; c.height = cv.height / 2; c.getContext('2d').drawImage(cv, 0, 0, c.width, c.height); return c.toDataURL('image/jpeg', 0.86).split(',')[1]; }, [spec(fam, lang), f]);
      fs.writeFileSync(path.join(SHOTS, `design-${lang}-${fam}-${f.replace(':', 'x')}.jpg`), Buffer.from(b, 'base64'));
    }
    // before / after (the old renderer's shots vs the same family, format, language now)
    // same product and words as the old shot (COSRX cleanser / Anua toner)
    const same = (fam, lang, pid) => { const b = spec(fam, lang), c = COPY[pid][lang]; return Object.assign(b, { images: [P[pid].image], headline: c.headline, sub: c.sub, bullets: c.bullets, badge: P[pid].brand, price: lang === 'ar' ? `${Math.round(P[pid].price)} درهم` : `AED ${Math.round(P[pid].price)}` }); };
    const pairs = [['before-creative-ar-glow-9x16.jpg', 'glow', '9:16', 'ar', same('glow', 'ar', '3195')], ['before-creative-en-clean-4x5.jpg', 'hero', '4:5', 'en', same('hero', 'en', '3195')], ['before-creative-ar-ingredient-4x5.jpg', 'ingredient', '4:5', 'ar', same('ingredient', 'ar', '3214')]];
    const old = pairs.map((p) => { try { return 'data:image/jpeg;base64,' + fs.readFileSync(path.join(SHOTS, p[0])).toString('base64'); } catch { return ''; } });
    const ba = await H(async ([pairs, old]) => {
      const R = await import('/app/js/creative/render.js');
      const colH = 720, pad = 24;
      const items = [];
      for (let i = 0; i < pairs.length; i++) {
        const [, , f, , s] = pairs[i];
        const im = new Image(); if (old[i]) { im.src = old[i]; await im.decode(); }
        items.push({ old: old[i] ? im : null, neu: await R.renderSpec(s, f) });
      }
      const wOf = (c) => Math.round(colH * (c.naturalWidth || c.width) / (c.naturalHeight || c.height));
      const W = items.reduce((a, it) => a + (it.old ? wOf(it.old) : 0) + wOf(it.neu) + pad * 3, pad);
      const sheet = document.createElement('canvas'); sheet.width = W; sheet.height = colH + pad * 2 + 40;
      const x = sheet.getContext('2d'); x.fillStyle = '#EFE9EB'; x.fillRect(0, 0, W, sheet.height);
      let cx = pad; x.font = '600 22px Inter'; x.fillStyle = '#2B1F26';
      for (const it of items) {
        if (it.old) { x.fillText('before', cx, 30); x.drawImage(it.old, cx, 44, wOf(it.old), colH); cx += wOf(it.old) + pad; }
        x.fillText('after', cx, 30); x.drawImage(it.neu, cx, 44, wOf(it.neu), colH); cx += wOf(it.neu) + pad * 2;
      }
      return sheet.toDataURL('image/jpeg', 0.84).split(',')[1];
    }, [pairs, old]);
    fs.writeFileSync(path.join(SHOTS, 'design-before-after.jpg'), Buffer.from(ba, 'base64'));
    // motion strip: 8 frames of the same ad
    const strip = await H(async (s) => {
      const Mo = await import('/app/js/creative/motion.js'); const R = await import('/app/js/creative/render.js');
      const A = await R.assets(s), W = 1080, Hh = 1920, n = 8, w = 240, h = 427, dur = 10;
      const sheet = document.createElement('canvas'); sheet.width = n * (w + 12) + 12; sheet.height = h + 60;
      const x = sheet.getContext('2d'); x.fillStyle = '#EFE9EB'; x.fillRect(0, 0, sheet.width, sheet.height); x.font = '600 18px Inter'; x.fillStyle = '#2B1F26';
      const fr = document.createElement('canvas'); fr.width = W; fr.height = Hh; const fx = fr.getContext('2d');
      const plan = Mo.timeline(s, A, dur, null);
      for (let i = 0; i < n; i++) { const t = [0.3, 0.9, 1.6, 3, 4.6, 6.2, 8.2, 9.6][i]; Mo.frame(fx, s, W, Hh, A, t, dur, plan); x.drawImage(fr, 12 + i * (w + 12), 40, w, h); x.fillText(`${t.toFixed(1)} s`, 12 + i * (w + 12), 28); }
      return sheet.toDataURL('image/jpeg', 0.84).split(',')[1];
    }, spec('glow', 'ar'));
    fs.writeFileSync(path.join(SHOTS, 'design-motion-strip.jpg'), Buffer.from(strip, 'base64'));
    const carB = await H(() => { const cs = window.__car; const s = document.createElement('canvas'); s.width = cs.length * 412 + 12; s.height = 424; const x = s.getContext('2d'); x.fillStyle = '#EFE9EB'; x.fillRect(0, 0, s.width, s.height); cs.forEach((c, i) => x.drawImage(c, 12 + i * 412, 12, 400, 400)); return s.toDataURL('image/jpeg', 0.84).split(',')[1]; });
    fs.writeFileSync(path.join(SHOTS, 'design-carousel.jpg'), Buffer.from(carB, 'base64'));
    // the screens: Settings -> Connections -> Generators, a design's preview (phones + quality), the motion tab
    await page.goto(base + '#/settings'); await page.waitForTimeout(800);
    await page.click('[data-tab="connections"]'); await page.waitForSelector('[data-gen]', { timeout: 15000 });
    await page.setViewportSize({ width: 1440, height: 2200 }); await page.waitForTimeout(300);
    await (await page.$('#gen-mount')).screenshot({ path: path.join(SHOTS, 'design-settings-generators.jpg'), type: 'jpeg', quality: 78 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await H(async (d) => {
      const C = await import('/app/js/screens/creatives.js');
      window.__kaa.cache.set(`${window.__kaa.accounts[0].id}|designs`, [d]);
      window.__kaa.cache.set(`${window.__kaa.accounts[0].id}|creatives`, []);
      location.hash = '#/creatives';
      void C;
    }, spec('glow', 'ar'));
    await page.waitForTimeout(1200);
    await page.click('[data-d]'); await page.waitForSelector('#pq table', { timeout: 60000 }); await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(SHOTS, 'design-preview-dialog.jpg'), type: 'jpeg', quality: 78 });
    await page.keyboard.press('Escape');
    await page.click('[data-tab="motion"]'); await page.waitForTimeout(300);
    await page.click('#m-go'); await page.waitForFunction(() => window.__kaaMotion, null, { timeout: 240000 });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(SHOTS, 'design-motion-phone.jpg'), type: 'jpeg', quality: 78 });
    fs.writeFileSync(path.join(SHOTS, 'design-quality.json'), JSON.stringify({ at: new Date().toISOString(), cutouts: cuts, motion: mo, creatives: rows.map((r) => ({ family: r.fam, lang: r.lang, format: r.f, score: r.score, ok: r.ok, ...r.c, arabic: undefined })) }, null, 1));
  }

  // 6 errors
  check('no console errors, no failed requests', !errors.length && !failed.length, `${errors.length} errors, ${failed.length} failed${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''}${failed.length ? ': ' + failed.slice(0, 3).join(' | ') : ''}`);
  await browser.close();
  stop();
  const bad2 = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad2.length}/${results.length} passed`);
  process.exit(bad2.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
