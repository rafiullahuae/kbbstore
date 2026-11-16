// Translation lane (T) — the caption translator, checked for real:
//   part 1 (harness page, real models, headless Chromium): metrics match sacreBLEU; the chosen model per pair scores
//     at least its floor on the 60-sentence ad test set THROUGH the studio pipeline; glossary names, numbers/prices
//     and codes come through 100 %; re-split timings are monotonic and stay inside every original line; RTL pixels
//     (Arabic / Urdu read right to left, Urdu in Nastaliq); undo-able commands restore the original exactly.
//   part 2 (the REAL editor: Linux engine + Chrome): a captioned ad clip (Whisper on MMS-TTS speech) translated with
//     ONE click EN→AR (replace), EN→UR (bilingual), EN→HI (second track) and an Arabic clip AR→EN; undo restores the
//     original; per-language TXT / SRT / VTT exports parse; no console errors; no network outside the local engine.
//   Pictures 1920x1080 + 1280x800 -> shots/t/.  Results -> tools/out/t/check.json.
//
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/t-check.cjs [--n=60] [--skip-editor] [--skip-accuracy]
// Needs: bash tools/t-fetch-models.sh (models/, git-ignored); whisper-base/small + mms-tts-eng/ara in models/ (r2);
//        engine/kbbstudio-linux-amd64 (go build in engine/).
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');
const server = require('./r2-serve.cjs');
const H = require('./studio-harness.cjs');
const { startClaudeMock } = require('./d-claude-mock.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools/out/t'), SHOTS = path.join(ROOT, 'shots/t');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const args = process.argv.slice(2);
const arg = (k, d) => (args.find((a) => a.startsWith(`--${k}=`)) || `--${k}=${d}`).split('=')[1];
const N = +arg('n', 60);
const SKIP_ED = args.includes('--skip-editor'), SKIP_ACC = args.includes('--skip-accuracy');
// chrF floors per pair: the chosen engine's score on the test set (tools/out/t/eval-table.md) minus ~3 points of
// headroom for run-to-run variation in the WASM kernels. A model swap or a pipeline change that loses accuracy fails.
const FLOOR = { 'en-ar': 55, 'ar-en': 60, 'en-ur': 48, 'ur-en': 52, 'en-hi': 52, 'hi-en': 58 };

const results = { checks: [], accuracy: {}, timings: {}, editor: {} };
let failures = 0;
const check = (name, ok, detail = '') => { results.checks.push({ name, ok: !!ok, detail }); if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); };
const save = () => fs.writeFileSync(path.join(OUT, 'check.json'), JSON.stringify(results, null, 1));

async function part1(browser) {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const base = `http://127.0.0.1:${server.address().port}`;
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const errors = [], offsite = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('request', (r) => { const u = new URL(r.url()); if (!['127.0.0.1', 'localhost'].includes(u.hostname) && !/^(data|blob):/.test(r.url())) offsite.push(r.url()); });
  page.setDefaultTimeout(0);
  await page.goto(base + '/app/captions/translate/dev/index.html');
  await page.waitForFunction(() => window.TR?.ready);
  await page.addScriptTag({ type: 'module', content: `
    import * as SV from '/app/captions/translate/service.js'; import * as TC from '/app/captions/translate/commands.js';
    import * as MD from '/app/captions/model.js'; import * as RD from '/app/captions/render.js'; import * as FN from '/app/captions/fonts.js';
    import * as SC from '/app/captions/script.js';
    Object.assign(window.TR, { SV, TC, MD, RD, FN, SC, more: true });` });
  await page.waitForFunction(() => window.TR.more);

  // ---------------------------------------------------------------- metrics
  const m = await page.evaluate(() => {
    const h = ['The cat sat on the mat.', 'Get 20% off all Korean skincare.', 'احصل على خصم 20% على جميع منتجات'];
    const r = ['The cat is on the mat.', 'Get 20% off all of the Korean skin care.', 'احصل على خصم 20% على كل المنتجات'];
    return { c: TR.corpusChrf(h, r), s: h.map((a, i) => TR.chrf(a, r[i])), b: TR.corpusBleu(h, r) };
  });
  // reference values from sacreBLEU 2.x (pip install sacrebleu; corpus_chrf / sentence_chrf / corpus_bleu on the same strings)
  check('chrF and BLEU match sacreBLEU (72.934 / 67.173, 76.751, 71.660 / 51.855)', Math.abs(m.c - 72.934) < 0.01 && Math.abs(m.s[0] - 67.173) < 0.01
    && Math.abs(m.s[1] - 76.751) < 0.01 && Math.abs(m.s[2] - 71.660) < 0.01 && Math.abs(m.b - 51.855) < 0.01, `chrF ${m.c.toFixed(3)} [${m.s.map((x) => x.toFixed(3))}] BLEU ${m.b.toFixed(3)}`);

  // ---------------------------------------------------------------- glossary units (no model)
  const g = await page.evaluate(() => {
    const G = TR.glossary, gl = TR.TEST_GLOSSARY.map((x) => (typeof x === 'string' ? { term: x, keep: true } : x));
    let round = 0, n = 0, slots = 0;
    for (const x of TR.TESTSET) for (const t of ['ar', 'ur', 'hi']) {
      const mk = G.mask(x.en, gl, t);
      slots += mk.slots.length;
      // a model that copies the placeholders: unmask must give back every name / number exactly
      const u = G.unmask(mk.text, mk.slots);
      n++; if (!u.missing.length && !G.glossaryMisses(x.en, u.text, gl, t).length && !G.numberMisses(x.en, u.text).length && !G.keepMisses(x.en, u.text).length) round++;
    }
    const seeds = { kbb: G.glossaryFor({ id: 'kbb', name: 'KBB', industry: 'Beauty / K-beauty retail', captions: { vocab: ['Glow Serum X'] } }).length,
      pc: G.glossaryFor({ id: 'planet-careers', name: 'Planet Careers', industry: 'Immigration & study-visa consultancy' }).map((x) => x.term) };
    return { round, n, slots, digits: G.westernDigits('٢٠٪ و ١٬٥٠٠ ۳۵۰۰ २०'), nums: G.numbersIn('AED 1,500 and 20% on 30 June'), seeds };
  });
  check(`glossary + keep-exactly masks round-trip on every test sentence × ar/ur/hi (${g.slots} protected spans)`, g.round === g.n, `${g.round}/${g.n}`);
  check('Eastern Arabic / Persian / Devanagari digits normalised', g.digits === '20% و 1,500 3500 20', g.digits);
  check('numbers found with separators and currency', JSON.stringify(g.nums) === '["1500","20","30"]', JSON.stringify(g.nums));
  check('glossary seeded from the captions vocabulary (KBB brands; Planet Careers names, Chancenkarte, Schengen fixed)', g.seeds.kbb > 60 && g.seeds.pc.includes('Chancenkarte') && g.seeds.pc.includes('Schengen') && g.seeds.pc.includes('Planet Careers'), `KBB ${g.seeds.kbb} entries, PC ${g.seeds.pc.length}`);

  // ---------------------------------------------------------------- accuracy of the chosen model per pair
  if (!SKIP_ACC) {
    const DIRS = [['en', 'ar'], ['ar', 'en'], ['en', 'ur'], ['ur', 'en'], ['en', 'hi'], ['hi', 'en']];
    for (const [s, t] of DIRS) {
      const r = await page.evaluate(async ({ s, t, N }) => {
        const id = TR.SV.candidatesFor(s, t)[0], eng = TR.ENGINES[id];
        window.__tr ||= new TR.Translator();
        await window.__tr.load(eng);
        const gl = TR.TEST_GLOSSARY.map((x) => (typeof x === 'string' ? { term: x, keep: true } : x));
        const set = TR.TESTSET.slice(0, N), src = set.map((x) => x[s]), ref = set.map((x) => x[t]);
        const t0 = performance.now();
        const out = [];
        for (let i = 0; i < src.length; i += 6) out.push(...await TR.pipeline.translateBatch(window.__tr, eng, src.slice(i, i + 6), s, t, { glossary: gl }));
        const G = TR.glossary;
        let termN = 0, termOk = 0, numN = 0, numOk = 0, keepN = 0, keepOk = 0;
        out.forEach((o, i) => {
          const th = G.glossaryHits(src[i], gl).length; termN += th; termOk += th - G.glossaryMisses(src[i], o.out, gl, t).length;
          const nn = G.numbersIn(src[i]).length; numN += nn; numOk += nn - G.numberMisses(src[i], o.out).length;
          const kn = G.keepSpans(src[i]).filter((k) => k.kind !== 'number').length; keepN += kn; keepOk += kn - G.keepMisses(src[i], o.out).length;
        });
        const hyp = out.map((o) => o.out);
        return { engine: id, chrf: TR.corpusChrf(hyp, ref), bleu: TR.corpusBleu(hyp, ref), termN, termOk, numN, numOk, keepN, keepOk,
          attempts: out.reduce((a, o) => { a[o.attempt] = (a[o.attempt] || 0) + 1; return a; }, {}), sPer: (performance.now() - t0) / 1000 / src.length, hyp };
      }, { s, t, N });
      const k = s + '-' + t;
      results.accuracy[k] = { ...r, chrf: +r.chrf.toFixed(1), bleu: +r.bleu.toFixed(1), sPer: +r.sPer.toFixed(2) };
      fs.writeFileSync(path.join(OUT, `check-${k}.txt`), r.hyp.join('\n') + '\n');
      check(`${k}: ${r.engine} chrF ${r.chrf.toFixed(1)} ≥ ${FLOOR[k]} (BLEU ${r.bleu.toFixed(1)}, ${r.sPer.toFixed(2)} s/sentence, n=${N})`, r.chrf >= FLOOR[k] || N < 60, JSON.stringify(r.attempts));
      check(`${k}: glossary names untouched ${r.termOk}/${r.termN}, numbers/prices ${r.numOk}/${r.numN}, codes/links ${r.keepOk}/${r.keepN}`, r.termOk === r.termN && r.numOk === r.numN && r.keepOk === r.keepN);
      save();
    }
  }

  // ---------------------------------------------------------------- re-split timing (real model output on caption lines)
  const tm = await page.evaluate(async () => {
    // captions built the way Auto captions builds them: every test sentence spoken with natural word lengths and pauses
    let t = 0.4, seed = 7;
    const rnd = () => { seed = (seed * 16807) % 2147483647; return seed / 2147483647; };
    const words = [];
    for (const x of TR.TESTSET.slice(0, 24)) {
      for (const w of x.en.split(' ')) { const d = 0.12 + w.length * 0.045 + rnd() * 0.1; words.push({ w, t0: +t.toFixed(3), t1: +(t + d).toFixed(3), conf: 1 }); t += d + 0.03 + rnd() * 0.08; }
      t += 0.35 + rnd() * 0.5;
    }
    const out = {};
    for (const style of ['word-box', 'one-word', 'k-bi-card']) {
      const lines = TR.MD.buildLines(words, style, { w: 1080, h: 1920 });
      const cap = { words, lines, lang: 'en' };
      const eng = TR.ENGINES[TR.SV.candidatesFor('en', 'ar')[0]];
      window.__tr ||= new TR.Translator(); await window.__tr.load(eng);
      const r = await TR.pipeline.translateTrack(cap, { src: 'en', tgt: 'ar', eng, tr: window.__tr, glossary: [] });
      // one-word styles get one cue per TRANSLATED word, spread over the sentence (pipeline.layoutOneWord): those words
      // must stay inside their sentence's spoken span; every other style keeps the source lines and their exact times
      const one = style === 'one-word';
      const spans = TR.pipeline.sentencesOf(cap).map((s) => [s.chunks[0].t0, s.chunks[s.chunks.length - 1].t1]);
      let inside = 0, mono = true, n = 0, lineKept = 0, wordsPerLine = [];
      for (const L of r.lines) {
        const O = lines.find((x) => x.id === L.id);
        if (one || (O && O.t0 === L.t0 && O.t1 === L.t1)) lineKept++;
        wordsPerLine.push(L.wordIdx[1] - L.wordIdx[0] + 1);
        for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) {
          n++; const w = r.words[i];
          const ok = one ? spans.some(([a, b]) => w.t0 >= a - 1e-9 && w.t1 <= b + 1e-9) : (O && w.t0 >= O.t0 - 1e-9 && w.t1 <= O.t1 + 1e-9);
          if (ok && w.t1 >= w.t0) inside++;
        }
      }
      for (let k = 1; k < r.lines.length; k++) if (r.lines[k].t0 < r.lines[k - 1].t1 - 1e-9) mono = false;
      r.words.forEach((w, i) => { if (i && w.t0 < r.words[i - 1].t1 - 1e-9) mono = false; });
      out[style] = { lines: lines.length, outLines: r.lines.length, lineKept, inside, n, mono, errs: TR.MD.validate({ words: r.words, lines: r.lines }),
        sents: r.sents.length, minWords: Math.min(...wordsPerLine), eg: r.lines.slice(0, 3).map((L) => r.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => `${w.w}[${w.t0.toFixed(2)}-${w.t1.toFixed(2)}]`).join(' ')) };
    }
    return out;
  });
  results.timings = tm;
  for (const [st, r] of Object.entries(tm)) {
    const one = st === 'one-word';
    check(one ? `timings (${st}): ${r.n} translated words, one cue each, monotonic and inside their sentence's spoken span (${r.inside}/${r.n}); model.validate clean`
      : `timings (${st}): ${r.n} translated words monotonic and inside their original line (${r.inside}/${r.n}); ${r.lineKept}/${r.outLines} lines keep their exact start/end; model.validate clean`,
      r.mono && r.inside === r.n && r.lineKept === r.outLines && !r.errs.length && (one ? r.outLines === r.n : r.outLines >= r.lines * 0.9), `${r.sents} sentences → ${r.outLines} cues (source ${r.lines})`);
  }

  // ---------------------------------------------------------------- commands: undo restores the original exactly
  const cm = await page.evaluate(async () => {
    const words = TR.TESTSET.slice(0, 4).flatMap((x, k) => x.en.split(' ').map((w, i) => ({ w, t0: k * 5 + i * 0.4, t1: k * 5 + i * 0.4 + 0.3, conf: 1 })));
    const project = { canvas: { w: 1080, h: 1920 }, captions: { style: 'word-box', lang: 'en', words, lines: TR.MD.buildLines(words, 'word-box', { w: 1080, h: 1920 }) } };
    const orig = JSON.stringify(project.captions);
    const eng = TR.ENGINES[TR.SV.candidatesFor('en', 'ur')[0]];
    window.__tr ||= new TR.Translator(); await window.__tr.load(eng);
    const res = await TR.pipeline.translateTrack(project.captions, { src: 'en', tgt: 'ur', eng, tr: window.__tr, glossary: [] });
    const out = {};
    const c1 = TR.TC.applyTranslation(res, 'replace'); c1.do(project);
    out.replaced = project.captions.lang === 'ur' && /[؀-ۿ]/.test(project.captions.words[0].w);
    c1.undo(project); out.undo1 = JSON.stringify(project.captions) === orig;
    c1.do(project);
    const c2 = TR.TC.editSentence('ur', 0, 'ترمیم شدہ جملہ 20% کے ساتھ'); c2.do(project);
    out.edited = project.captions.words.map((w) => w.w).join(' ').includes('ترمیم');
    const c3 = TR.TC.restoreOriginal(); c3.do(project);
    out.restored = JSON.stringify(project.captions.words) === JSON.stringify(JSON.parse(orig).words) && project.captions.lang === 'en';
    const c4 = TR.TC.showLang('ur', 'bilingual'); c4.do(project);
    out.bilingual = project.captions.alt?.lang === 'ur' && TR.RD.captionBlocks(document.createElement('canvas').getContext('2d'), project, project.captions.words[2].t0, null).length === 2;
    for (const c of [c4, c3, c2, c1]) c.undo(project);
    out.undoAll = JSON.stringify(project.captions) === orig;
    return out;
  });
  check('commands: translate (replace) → undo gives the original back byte for byte', cm.replaced && cm.undo1, JSON.stringify(cm));
  check('commands: edit a line → restore original → bilingual (2 blocks on screen) → undo ×4 = original', cm.edited && cm.restored && cm.bilingual && cm.undoAll);

  // ---------------------------------------------------------------- RTL pixels
  await page.evaluate(() => TR.FN.loadCaptionFonts());
  const px = await page.evaluate(async () => {
    const res = {};
    const draw = (lang, text, style = 'word-box') => {
      const ws = text.split(' ').map((w, i) => ({ w, t0: i, t1: i + 0.9, conf: 1 }));
      const project = { canvas: { w: 1080, h: 1920 }, captions: { style: { id: style, maxRows: 1, rowChars: 60, bg: { type: 'none' }, activeBg: '#00ff00', anim: 'highlight-box' }, lang, words: ws, lines: [{ id: 'l1', t0: 0, t1: ws.length, wordIdx: [0, ws.length - 1] }] } };
      const cv = document.createElement('canvas'); cv.width = 1080; cv.height = 1920;
      const c = cv.getContext('2d'); c.fillStyle = '#000'; c.fillRect(0, 0, 1080, 1920);
      TR.RD.drawCaptions(c, null, 0.3, project);          // the FIRST word (logical order) is the spoken one
      const d = c.getImageData(0, 0, 1080, 1920).data;
      let sx = 0, n = 0, inkTop = 1920, inkBot = 0;
      for (let y = 0; y < 1920; y++) for (let x = 0; x < 1080; x++) {
        const k = (y * 1080 + x) * 4;
        if (d[k + 1] > 200 && d[k] < 80 && d[k + 2] < 80) { sx += x; n++; }
        if (d[k] > 200 && d[k + 1] > 200 && d[k + 2] > 200) { inkTop = Math.min(inkTop, y); inkBot = Math.max(inkBot, y); }
      }
      const blocks = TR.RD.captionBlocks(c, project, 0.3, null);
      return { cx: n ? sx / n : -1, family: blocks[0]?.lay.family, rtl: blocks[0]?.lay.rtl, ink: inkBot - inkTop, url: cv.toDataURL('image/png') };
    };
    res.en = draw('en', 'Get twenty percent off today');
    res.ar = draw('ar', 'احصل على خصم كبير اليوم');
    res.ur = draw('ur', 'آج ہی بڑی رعایت حاصل کریں');
    res.urAsAr = draw('ar', 'آج ہی بڑی رعایت حاصل کریں');
    res.fontsOk = document.fonts.check('400 60px "Noto Nastaliq Urdu"', 'اردو') && document.fonts.check('600 60px "Noto Sans Arabic"', 'عربي');
    return res;
  });
  for (const k of ['en', 'ar', 'ur']) fs.writeFileSync(path.join(SHOTS, `rtl-${k}-1080x1920.png`), Buffer.from(px[k].url.split(',')[1], 'base64'));
  check('RTL pixels: the first (spoken) Arabic word is drawn on the RIGHT, the first English word on the LEFT', px.ar.rtl && px.ar.cx > 600 && !px.en.rtl && px.en.cx > 0 && px.en.cx < 480, `AR highlight x=${px.ar.cx.toFixed(0)}, EN x=${px.en.cx.toFixed(0)}`);
  check('Urdu draws right to left in Noto Nastaliq Urdu (taller than the same words in Naskh/Cairo), fonts offline', px.ur.rtl && px.ur.cx > 600 && px.ur.family === 'Noto Nastaliq Urdu' && px.ur.ink > px.urAsAr.ink && px.fontsOk,
    `UR x=${px.ur.cx.toFixed(0)} family=${px.ur.family} ink ${px.ur.ink}px vs ${px.urAsAr.family} ${px.urAsAr.ink}px`);

  const real = errors.filter((x) => !/No available adapters|404 \(Not Found\)/.test(x));
  check('harness: no console errors', real.length === 0, real.slice(0, 3).join(' | '));
  check('harness: no network outside 127.0.0.1', offsite.length === 0, offsite.slice(0, 3).join(' '));
  await page.close();
  save();
}

// ======================================================================== part 2: the real editor
async function fixtures(browser) {
  const F = path.join(ROOT, 'models/_r2-test');
  const EN = ['Get twenty percent off all Korean skincare this weekend only.', 'Pay in four interest free instalments with Tabby.',
    'Book a free consultation with Planet Careers today.', 'We will guide you through every step of the visa process.'];
  const AR = ['احجز استشارة مجانية مع فريقنا اليوم.', 'سنرشدك في كل خطوة من خطوات التأشيرة.', 'التوصيل مجاني في جميع أنحاء الإمارات.'];
  if (fs.existsSync(path.join(F, 't-en-ad.wav')) && fs.existsSync(path.join(F, 't-ar-ad.wav'))) return;
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const page = await browser.newPage();
  page.setDefaultTimeout(0);
  await page.goto(`http://127.0.0.1:${server.address().port}/app/captions/dev/index.html`);
  await page.waitForFunction(() => window.R2);
  await page.evaluate(async ({ EN, AR }) => {
    const en = await R2.tts(EN, 'eng', 0.55), ar = await R2.tts(AR, 'ara', 0.55);
    await R2.saveWav('t-en-ad.wav', R2.concat([new Float32Array(6400), en])); await R2.saveWav('t-ar-ad.wav', R2.concat([new Float32Array(6400), ar]));
  }, { EN, AR });
  await page.close();
}

async function part2(browser) {
  await fixtures(browser);
  const clip = (wav, name, c) => {
    const out = path.join(OUT, name);
    if (!fs.existsSync(out)) execFileSync('ffmpeg', ['-y', '-v', 'error', '-f', 'lavfi', '-i', `gradients=s=720x1280:r=30:c0=${c[0]}:c1=${c[1]}:speed=0.01`, '-i', wav,
      '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', '-b:a', '128k', out]);
    return out;
  };
  const enClip = clip(path.join(ROOT, 'models/_r2-test/t-en-ad.wav'), 't-en-ad.mp4', ['0xe9b9a0', '0x8e5a6c']);
  const arClip = clip(path.join(ROOT, 'models/_r2-test/t-ar-ad.wav'), 't-ar-ad.mp4', ['0x1d3557', '0x457b9d']);

  const STUDIO = H.makeStudio();
  // the models this check uses, linked into the throwaway studio (the engine checks their sizes: they are the real files)
  for (const org of ['Xenova', 'onnx-community']) fs.symlinkSync(path.join(ROOT, 'models', org), path.join(STUDIO, 'models', org));
  // Connect Claude goes to a local stand-in of the Messages API (tools/d-claude-mock.cjs), never to api.anthropic.com
  const KEY = 'sk-ant-test-' + 'x'.repeat(32);
  const mock = await startClaudeMock({ key: KEY });
  const e = await H.startEngine(STUDIO, ['--test-claude-base', mock.url]);
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const page = await context.newPage();
  page.setDefaultTimeout(0);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => {
    for (const [w, h] of [[1920, 1080], [1280, 800]]) { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(500); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}x${h}.png`) }); }
    await page.setViewportSize({ width: 1920, height: 1080 }); await page.waitForTimeout(200);
  };

  await page.goto(e.url + '/app/');
  if (await page.locator('#su-later').waitFor({ timeout: 15000 }).then(() => true, () => false)) await page.click('#su-later');
  await page.waitForSelector('.home', { timeout: 30000 });
  const inst = await ev(async () => { const E = (await import('/app/core/engine.js')).engine; const st = await E.modelsStatus(); return st.models.filter((m) => m.installed).map((m) => m.id); });
  check('engine: the translation models in models/ count as installed (manifest sizes match)', ['mt-m2m100', 'mt-ar-en'].every((x) => inst.includes(x)), inst.join(', '));

  async function openClip(file, lang) {
    await page.setInputFiles('.home-drop input[type=file]', file);
    await page.waitForSelector('.tl-item');
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    // real captions: the studio's own capture pipeline (Whisper, WASM) on this clip's soundtrack
    const r = await ev(async (lang) => {
      const ed = kbbEditor, p = ed.project;
      const { Transcriber } = await import('/app/captions/stt.js'); const { captureWords } = await import('/app/captions/auto.js');
      const C = await import('/app/captions/commands.js'); const P = await import('/app/core/project.js');
      const a = await ed.media.decodeAudio(p.media[0].id);
      const tr = new Transcriber();
      const cw = await captureWords(tr, a.mono16k, { model: lang === 'ar' ? 'small' : 'base', lang, device: 'wasm', workspace: ed.workspace, speakers: false });
      tr.dispose?.();
      const tk = p.tracks.find((t) => t.kind === 'caption');
      ed.history.apply(ed.ops.batch('Captions', [C.setCaptions({ style: 'word-box', lang: cw.lang, words: cw.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, conf: w.conf ?? 1, ...(w.tag ? { tag: w.tag } : {}) })) }, p.canvas),
        ed.ops.addItems([{ trackId: tk.id, item: P.createItem('caption', { t0: 0, in: 0, out: P.projectDuration(p), x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, name: 'Captions' }) }])]));
      return { lang: cw.lang, text: cw.words.map((w) => w.w).join(' '), n: cw.words.length };
    }, lang);
    await page.click('.side-tab[data-side="captions"]');
    await page.waitForSelector('.rs-cap-line');
    return r;
  }
  const state = () => ev(() => { const c = kbbEditor.project.captions; return { lang: c.lang, text: c.words.map((w) => w.w).join(' '), words: c.words.length, lines: c.lines.length, alt: c.alt?.lang || null,
    tracks: Object.keys(c.tracks || {}), trackItems: kbbEditor.project.tracks.flatMap((t) => t.items.filter((i) => i.capTrack).map((i) => i.capTrack)),
    mode: c.tr?.mode || null, active: c.tr?.active || null, json: JSON.stringify({ w: c.words, l: c.lines }), steps: kbbEditor.history.undoStack.length }; });
  async function translateUI(tgt, mode) {
    await page.click('[data-x="open-translate"]');
    await page.waitForSelector('.rs-tr select[data-k="tgt"]');
    await page.selectOption('.rs-tr select[data-k="tgt"]', tgt);
    await page.selectOption('.rs-tr select[data-k="mode"]', mode);
    const before = await state();
    const t0 = Date.now();
    await page.click('.rs-tr [data-x="translate"]');
    await page.waitForFunction((n) => kbbEditor.history.undoStack.length > n && !document.querySelector('.rs-tr .rs-cap-progress:not([hidden])'), before.steps, { timeout: 600000 });
    await page.waitForTimeout(300);
    return { before, after: await state(), ms: Date.now() - t0 };
  }
  const timingsOk = () => ev(() => {
    const c = kbbEditor.project.captions, set = (k) => (k === 'main' ? c : k === 'alt' ? c.alt : c.tracks[k]);
    const src = c.tr.source, out = [];
    for (const k of ['main', 'alt', ...Object.keys(c.tracks || {})]) {
      const S = set(k); if (!S || S === src || S.words === src.words) continue;
      let ok = true;
      for (const L of S.lines) { const O = src.lines.find((x) => x.id === L.id); for (let i = L.wordIdx[0]; i <= L.wordIdx[1]; i++) { const w = S.words[i]; if (!O || w.t0 < O.t0 - 1e-9 || w.t1 > O.t1 + 1e-9 || w.t1 < w.t0 || (i && w.t0 < S.words[i - 1].t1 - 1e-9)) ok = false; } }
      out.push(ok);
    }
    return out.length > 0 && out.every(Boolean);
  });

  // ---------------- English ad clip
  const en = await openClip(enClip, 'en');
  results.editor.enCaptions = en;
  check('editor: English ad clip captioned by Whisper (real capture pipeline)', en.lang === 'en' && en.n > 20, `${en.n} words: ${en.text.slice(0, 120)}…`);
  await ev(() => kbbEditor.seek(2.2));
  await shot('t-editor-original');

  // "Best quality (Claude)" with NO key saved: falls back to the offline engine silently, nothing sent anywhere
  const fb = await ev(async () => {
    const S = await import('/app/captions/translate/service.js'); const ctx = kbbEditor.context();
    const n = kbbEditor.history.undoStack.length;
    const r = await S.translateCaptions(ctx, 'ar', 'replace', { engineId: 'claude', check: false });
    const out = { engine: r.result.engine, lang: kbbEditor.project.captions.lang, steps: kbbEditor.history.undoStack.length - n };
    kbbEditor.history.undo();
    return out;
  });
  check('Claude not connected: "Best quality (Claude)" falls back to the offline engine silently (no request made)', fb.engine === 'mt-m2m100' && fb.lang === 'ar' && fb.steps === 1 && mock.log.length === 0, JSON.stringify(fb));

  // EN -> AR, replace, ONE click
  const ar = await translateUI('ar', 'replace');
  results.editor.ar = { ms: ar.ms, text: ar.after.text };
  check(`editor: one click EN→AR (replace) in ${(ar.ms / 1000).toFixed(1)} s — captions now Arabic, one undo step`, ar.after.lang === 'ar' && /[؀-ۿ]{3}/.test(ar.after.text) && ar.after.steps === ar.before.steps + 1, ar.after.text.slice(0, 140));
  const kept = await ev(async ({ src, out }) => {
    const G = await import('/app/captions/translate/glossary.js');
    const gl = G.glossaryFor(kbbEditor.workspace);
    const terms = G.glossaryHits(src, gl).map((h) => G.termIn(h.g, 'ar'));
    return { terms, termsOk: terms.every((t) => out.includes(t)), nums: G.numbersIn(src), numMiss: G.numberMisses(src, out) };
  }, { src: en.text, out: ar.after.text });
  check(`editor: EN→AR keeps the company's names exactly (${kept.terms.join(', ') || 'none spoken'}) and every number (${kept.nums.join(', ') || 'none'})`,
    kept.termsOk && !kept.numMiss.length && kept.terms.length > 0, JSON.stringify(kept));
  check('editor: EN→AR word timings monotonic and inside the original lines', await timingsOk());
  await page.waitForSelector('.rs-tr-row');
  const rows = await page.locator('.rs-tr-row').count();
  check('editor: review panel lists every sentence side by side (source | translation)', rows >= 4 && await page.locator('.rs-tr-row .rs-tr-src').count() === rows && await page.locator('.rs-tr-row .rs-tr-out[dir="rtl"]').count() === rows, `${rows} rows`);
  await ev(() => kbbEditor.seek(2.2));
  await shot('t-editor-en-ar-replace');
  const exportFrame = async (name, t) => {
    const url = await ev(async (t) => { const R = await import('/app/core/render.js'); const p = kbbEditor.project; const a = document.createElement('canvas'); a.width = p.canvas.w; a.height = p.canvas.h; R.renderFrame(a.getContext('2d'), p, t, {}); return a.toDataURL('image/jpeg', 0.85); }, t);
    fs.writeFileSync(path.join(SHOTS, name), Buffer.from(url.split(',')[1], 'base64'));
  };
  await exportFrame('t-frame-ar-1080x1920.jpg', 2.2);

  // edit one line in the review panel -> captions follow (one more undo step)
  const st0 = await state();
  const first = page.locator('.rs-tr-row .rs-tr-out').first();
  await first.click();
  await page.keyboard.press('End');
  await page.keyboard.type(' ✨');
  await page.keyboard.press('Enter');
  await page.waitForFunction((n) => kbbEditor.history.undoStack.length > n, st0.steps);
  const st1 = await state();
  check('editor: editing a translated line in the review flows into the captions (one undo step)', st1.text.includes('✨') && st1.steps === st0.steps + 1);
  // re-translate one line
  const st1b = await state();
  await page.locator('.rs-tr-row [data-x="retr"]').nth(1).click();
  await page.waitForFunction((n) => kbbEditor.history.undoStack.length > n, st1b.steps);
  check('editor: ↻ re-translates one line (one undo step)', (await state()).steps === st1b.steps + 1);
  // exports per language
  await page.selectOption('.rs-tr select[data-k="exp"]', 'ar');
  for (const k of ['txt', 'srt', 'vtt']) { await page.click(`.rs-tr [data-x="exp-${k}"]`); await page.waitForTimeout(250); }
  await page.selectOption('.rs-tr select[data-k="exp"]', 'src');
  await page.click('.rs-tr [data-x="exp-srt"]'); await page.waitForTimeout(250);
  const exps = fs.readdirSync(path.join(STUDIO, 'workspaces')).flatMap((w) => { const d = path.join(STUDIO, 'workspaces', w, 'exports'); return fs.existsSync(d) ? fs.readdirSync(d).map((f) => path.join(d, f)) : []; });
  const parse = await ev(async (files) => { const S = await import('/app/captions/script.js'); return files.map(([f, s]) => [f, S.parseSubtitles(s).length, S.parseSubtitles(s).map((c) => c.text).join(' ')]); },
    exps.filter((f) => /\.(srt|vtt)$/.test(f)).map((f) => [path.basename(f), fs.readFileSync(f, 'utf8')]));
  const arSrt = parse.find(([f]) => /-ar\.srt$/.test(f)), arVtt = parse.find(([f]) => /-ar\.vtt$/.test(f)), enSrt = parse.find(([f]) => /-en\.srt$/.test(f));
  const arTxt = exps.find((f) => /-ar\.txt$/.test(f));
  check('editor: per-language exports — AR TXT / SRT / VTT and the original EN SRT saved by the engine and parse', !!(arSrt && arSrt[1] > 2 && /[؀-ۿ]/.test(arSrt[2]) && arVtt && arVtt[1] === arSrt[1] && arTxt && /[؀-ۿ]/.test(fs.readFileSync(arTxt, 'utf8')) && enSrt && /Tabby/.test(enSrt[2])),
    parse.map(([f, n]) => `${f}: ${n} cues`).join(', '));
  for (const f of exps) fs.copyFileSync(f, path.join(OUT, 'editor-' + path.basename(f).replace(/^.*-(\w+\.\w+)$/, '$1')));
  // undo back to the original, byte for byte
  const steps = (await state()).steps - ar.before.steps;
  for (let i = 0; i < steps; i++) await ev(() => kbbEditor.history.undo());
  const back = await state();
  check(`editor: ⌘Z ×${steps} restores the original English captions exactly`, back.json === ar.before.json && back.lang === 'en');
  for (let i = 0; i < steps; i++) await ev(() => kbbEditor.history.redo());

  // EN -> UR, bilingual
  const ur = await translateUI('ur', 'bilingual');
  results.editor.ur = { ms: ur.ms, text: await ev(() => kbbEditor.project.captions.alt?.words.map((w) => w.w).join(' ')) };
  check(`editor: one click EN→UR (bilingual) in ${(ur.ms / 1000).toFixed(1)} s — English stays, Urdu under it, a bilingual style`, ur.after.lang === 'en' && ur.after.alt === 'ur' && await ev(async () => (await import('/app/captions/styles.js')).resolveStyle(kbbEditor.project.captions.style).bilingual), results.editor.ur.text?.slice(0, 120));
  check('editor: EN→UR timings monotonic and inside the original lines', await timingsOk());
  await ev(() => kbbEditor.seek(6.2));
  await shot('t-editor-en-ur-bilingual');
  await exportFrame('t-frame-ur-bilingual-1080x1920.jpg', 6.2);

  // EN -> HI, second track
  const hi = await translateUI('hi', 'track');
  check(`editor: one click EN→HI (second caption track) in ${(hi.ms / 1000).toFixed(1)} s — its own track and item`, hi.after.tracks.includes('hi') && hi.after.trackItems.includes('hi') && hi.after.steps === hi.before.steps + 1);
  await ev(() => kbbEditor.seek(9.5));
  await shot('t-editor-en-hi-track');
  await exportFrame('t-frame-hi-track-1080x1920.jpg', 9.5);
  await page.click('.rs-tr [data-x="restore"]');
  await page.waitForTimeout(300);
  const rs = await state();
  check('editor: ↺ Restore original — English captions, no bilingual line, no translated track (translations kept for later)', rs.lang === 'en' && !rs.alt && !rs.trackItems.length && rs.json === ar.before.json
    && await ev(() => Object.keys(kbbEditor.project.captions.tr.langs).length === 3));

  // ---------------- Best quality (Claude) through Connect Claude (mock Messages API)
  await ev(async (key) => (await import('/app/settings/claude.js')).claudeSave({ key }), KEY);
  await page.click('.rs-cap-tabs button[data-tab="transcript"]'); await page.click('[data-x="open-translate"]');
  await page.waitForSelector('.rs-tr [data-x="claude"]:not([disabled])', { timeout: 15000 });
  await page.selectOption('.rs-tr select[data-k="tgt"]', 'ar'); await page.selectOption('.rs-tr select[data-k="mode"]', 'replace');
  const cb = await state();
  await page.click('.rs-tr [data-x="claude"]');
  await page.waitForSelector('.modal-back .btn.primary');                     // the once-per-session question (what is sent)
  await shot('t-editor-claude-ask');
  await page.click('.modal-back .btn.primary');
  await page.waitForFunction((n) => kbbEditor.history.undoStack.length > n && !document.querySelector('.rs-tr .rs-cap-progress:not([hidden])'), cb.steps);
  const ca = await state();
  const req = mock.log.find((x) => x.url === '/v1/messages' && /Translate from/.test(x.body?.messages?.[0]?.content || ''));
  const sent = req?.body?.messages?.[0]?.content || '';
  check('Best quality (Claude): one click → asked once → the engine sends ONLY the caption sentences + glossary words to the (mock) Messages API with the saved key; captions replaced, one undo step',
    !!req && req.headers['x-api-key'] === KEY && ca.lang === 'ar' && /^\[ar\] /.test(ca.text) && ca.steps === cb.steps + 1 && sent.length < 6000 && !/data:|blob:|\.mp4|\.wav/.test(sent),
    `${mock.log.length} request(s), ${sent.length} chars sent; captions: ${ca.text.slice(0, 80)}`);
  check('Best quality (Claude): timings re-split onto the original lines like the offline engines', await timingsOk());
  await ev(() => kbbEditor.history.undo());
  check('Best quality (Claude): undo restores the original', (await state()).json === ar.before.json);

  // ---------------- Arabic clip: AR -> EN
  await ev(() => kbbEditor.goHome());
  await page.waitForSelector('.home', { timeout: 30000 });
  const arc = await openClip(arClip, 'ar');
  check('editor: Arabic ad clip captioned by Whisper', arc.lang === 'ar' && arc.n > 6, arc.text.slice(0, 100));
  const ae = await translateUI('en', 'replace');
  results.editor.arEn = { ms: ae.ms, text: ae.after.text };
  check(`editor: one click AR→EN (replace) in ${(ae.ms / 1000).toFixed(1)} s — captions now English`, ae.after.lang === 'en' && /[A-Za-z]{3}/.test(ae.after.text) && !/[؀-ۿ]/.test(ae.after.text), ae.after.text.slice(0, 140));
  await ev(() => kbbEditor.seek(2));
  await shot('t-editor-ar-en-replace');
  await ev(() => kbbEditor.history.undo());
  const ab = await state();
  check('editor: undo puts the Arabic captions back exactly', ab.json === ae.before.json && ab.lang === 'ar');

  const real = errors.filter((x) => !/No available adapters|AudioContext was not allowed/.test(x));
  check('editor: no console errors', real.length === 0, real.slice(0, 3).join(' | '));
  check('editor: no network outside the local engine', outside.length === 0, outside.slice(0, 3).join(' '));
  await context.close(); await H.stopEngine(e); mock.close();
  fs.rmSync(STUDIO, { recursive: true, force: true });
}

(async () => {
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined,
    args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  try {
    await part1(browser);
    if (!SKIP_ED) await part2(browser);
  } finally { save(); await browser.close(); server.close(); }
  console.log(failures ? `${failures} FAILED` : 'ALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
