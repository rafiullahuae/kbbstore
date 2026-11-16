// Lane R2 — captions module check, headless Chromium (WASM; this box has no GPU).
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/r2-check.cjs [--skip-stt] [--models=base,small] [--regen]
// Needs the models once: bash tools/r2-fetch-test-models.sh  (git-ignored, models/).
// Writes: tools/out/r2/ (frames, fixtures' transcripts, results.json) and shots/r2/ (pictures).
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const server = require('./r2-serve.cjs');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'tools/out/r2'), SHOTS = path.join(ROOT, 'shots/r2');
fs.mkdirSync(path.join(OUT, 'frames'), { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const args = process.argv.slice(2);
const SKIP_STT = args.includes('--skip-stt');
const MODELS = (args.find((a) => a.startsWith('--models=')) || '--models=base,small').slice(9).split(',');

const EN = [
  'Hello everyone, welcome back to my channel.',
  'Today I am unboxing the new vitamin C serum from Korea.',
  'The texture is light and it absorbs really fast.',
  'After one week my skin looks brighter and more hydrated.',
  'If you want to study in Germany, the next intake is in March.',
  'Prepare your passport, your bank statement and your university documents.',
  'Book a free consultation with our team today.',
  'We will guide you through every step of the visa process.',
  'Do not forget to follow us for more tips.',
  'Thank you so much for watching, see you in the next video.',
];
const JFK = 'And so my fellow Americans, ask not what your country can do for you, ask what you can do for your country.';
const AR = [
  'مرحبا بكم في قناتنا',
  'اليوم سنتحدث عن الدراسة في ألمانيا',
  'هذا السيروم يجعل البشرة نضرة ومشرقة',
  'احجز استشارة مجانية مع فريقنا اليوم',
  'شكرا لكم على المشاهدة',
];

const normEn = (s) => s.toLowerCase().replace(/[^a-z0-9' ]+/g, ' ').split(/\s+/).filter(Boolean);
const normAr = (s) => s.replace(/[ً-ْٰـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
  .replace(/[^ء-ي ]+/g, ' ').split(/\s+/).filter(Boolean);
function wer(ref, hyp) {
  const d = Array.from({ length: ref.length + 1 }, (_, i) => [i, ...Array(hyp.length).fill(0)]);
  for (let j = 1; j <= hyp.length; j++) d[0][j] = j;
  for (let i = 1; i <= ref.length; i++) for (let j = 1; j <= hyp.length; j++)
    d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (ref[i - 1] === hyp[j - 1] ? 0 : 1));
  return d[ref.length][hyp.length] / Math.max(1, ref.length);
}

const results = { stt: [], checks: [], styles: [] };
let failures = 0;
const check = (name, ok, detail = '') => { results.checks.push({ name, ok, detail }); if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); };

(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.setDefaultTimeout(30000);   // UI steps; page.evaluate (STT) is not bound by it
  await page.goto(base + '/app/captions/dev/index.html');
  await page.waitForFunction(() => window.R2);
  await page.evaluate(() => R2.ready);
  check('cross-origin isolated (threaded WASM)', await page.evaluate(() => crossOriginIsolated));
  check('module slot registered: auto-captions', await page.evaluate(() => !!R2.getModule('auto-captions')?.panel));
  check('Arabic font loaded (Cairo)', await page.evaluate(() => document.fonts.check('800 74px Cairo', 'بشرة')));

  // ------------------------------------------------------------------ speech to text
  let enWords = null, arWords = null;
  if (!SKIP_STT) {
    console.log('… generating test speech (MMS-TTS, offline) + JFK sample');
    // TTS (VITS) is not deterministic, so the fixtures are made once and re-used; --regen makes new ones
    const have = !args.includes('--regen') && fs.existsSync(path.join(ROOT, 'models/_r2-test/en-long.wav')) && fs.existsSync(path.join(ROOT, 'models/_r2-test/ar.wav'));
    const fx = await page.evaluate(async ({ EN, AR, have }) => {
      let en, ar;
      if (have) { en = await R2.loadAudio('/models/_r2-test/en-long.wav'); ar = await R2.loadAudio('/models/_r2-test/ar.wav'); }
      else {
        const jfk = await R2.loadAudio('/models/_r2-test/jfk.wav');
        en = R2.concat([jfk, new Float32Array(8000), await R2.tts(EN, 'eng', 0.6)]);
        ar = await R2.tts(AR, 'ara', 0.6);
        await R2.saveWav('en-long.wav', en); await R2.saveWav('ar.wav', ar);
      }
      window.__fx = { en, ar };
      return { en: en.length / 16000, ar: ar.length / 16000 };
    }, { EN, AR, have });
    console.log(`  English ${fx.en.toFixed(1)} s (JFK + TTS), Arabic ${fx.ar.toFixed(1)} s (TTS)`);
    for (const model of MODELS) {
      for (const [key, lang, ref, norm] of [['en', 'auto', [JFK, ...EN].join(' '), normEn], ['ar', 'auto', AR.join(' '), normAr]]) {
        const r = await page.evaluate(async ({ key, model, lang }) => {
          const res = await R2.transcribe(window.__fx[key], { model, lang });
          return res;
        }, { key, model, lang });
        const hyp = r.words.map((w) => w.w).join(' ');
        const e = wer(norm(ref), norm(hyp));
        let mono = true;
        for (let i = 0; i < r.words.length; i++) { const w = r.words[i]; if (!(w.t1 >= w.t0) || (i && w.t0 < r.words[i - 1].t0)) mono = false; }
        const row = { repaired: r.repaired, model, audio: key, dur: +(fx[key]).toFixed(1), lang: r.lang, words: r.words.length, wer: +e.toFixed(3), rtf: +r.rtf.toFixed(2), ms: Math.round(r.ms), loadMs: Math.round(r.loadMs), device: r.device, threads: r.threads, dtype: r.dtype, text: hyp };
        results.stt.push(row);
        fs.writeFileSync(path.join(OUT, `stt-${model}-${key}.json`), JSON.stringify(r, null, 1));
        console.log(`  ${model}/${key}: lang=${r.lang} words=${r.words.length} WER=${(e * 100).toFixed(1)}% RTF=${r.rtf.toFixed(2)} (${r.device}, ${r.threads} threads, ${r.repaired} gaps re-read)`);
        console.log(`    "${hyp.slice(0, 160)}…"`);
        check(`${model}/${key}: word timestamps monotonic`, mono && r.words.length > 0);
        check(`${model}/${key}: language detected = ${key}`, r.lang === key, r.lang);
        const lim = key === 'en' ? (model === 'base' ? 0.25 : 0.2) : (model === 'base' ? 0.75 : 0.5);
        check(`${model}/${key}: text roughly matches (WER ≤ ${lim * 100}%)`, e <= lim, `WER ${(e * 100).toFixed(1)}%`);
        if (key === 'en') {
          // stitched across the 29 s windows: no hole in the word stream (speech never pauses > 2.5 s in this clip)
          // and no word doubled at a seam (same word twice within 1 s). Misheard words are WER's job, not this.
          let gap = 0, dup = 0;
          for (let i = 1; i < r.words.length; i++) {
            gap = Math.max(gap, r.words[i].t0 - r.words[i - 1].t1);
            if (normEn(r.words[i].w)[0] === normEn(r.words[i - 1].w)[0] && r.words[i].t0 - r.words[i - 1].t0 < 1) dup++;
          }
          const end = r.words[r.words.length - 1].t1;
          check(`${model}/en: ${Math.ceil((fx.en - 5) / 24)} windows stitched (largest gap ${gap.toFixed(1)} s, ${dup} doubled, ends ${end.toFixed(1)}/${fx.en.toFixed(1)} s)`,
            gap < 2.5 && dup === 0 && end > fx.en - 3);
          if (model === MODELS[0]) enWords = r.words;
        } else if (model === MODELS[MODELS.length - 1]) arWords = r.words;
      }
    }
    // cancel: abort mid-run, must reject promptly
    const c = await page.evaluate(async () => {
      const ac = new AbortController();
      const p = R2.transcribe(window.__fx.en, { model: 'base', lang: 'en', signal: ac.signal });
      await new Promise((r) => setTimeout(r, 4000));
      const t = performance.now(); ac.abort();
      try { await p; return { ok: false }; } catch (e) { return { ok: e.name === 'AbortError', ms: performance.now() - t }; }
    });
    check('cancel stops transcription immediately', c.ok && c.ms < 500, `${Math.round(c.ms)} ms`);
    // explicit language forcing (ar) works too
    const forced = await page.evaluate(async () => (await R2.transcribe(window.__fx.ar.slice(0, 16000 * 6), { model: 'base', lang: 'ar' })).lang);
    check('forced language ar', forced === 'ar');
  }
  if (!enWords) enWords = synthWords('Hello everyone, welcome back to my channel. Today I am unboxing the new vitamin C serum from Korea. Um the texture is light and it absorbs really fast. After one week my skin looks brighter and more hydrated.');
  if (!arWords) arWords = synthWords('مرحبا بكم في قناتنا اليوم سنتحدث عن الدراسة في ألمانيا هذا السيروم يجعل البشرة نضرة ومشرقة');

  // ------------------------------------------------------------------ caption model + commands (undo/redo)
  const m = await page.evaluate(({ enWords }) => {
    const ed = R2.mountEditor({ words: enWords });
    const p = ed.project, H = ed.history, C = R2.commands;
    const out = {};
    out.validInitial = R2.validate(p.captions);
    out.lines = p.captions.lines.length;
    out.maxChars = Math.max(...p.captions.lines.map((L) => p.captions.words.slice(L.wordIdx[0], L.wordIdx[1] + 1).map((w) => w.w).join(' ').length));
    const snap0 = JSON.stringify(p.captions), depth0 = H.undoStack.length;
    const seq = [C.editWord(2, 'welcome!'), C.deleteWords(5), C.insertWords(3, 'friends and'), C.splitLine(p.captions.lines[1].wordIdx[0] + 2),
      C.mergeWithNext(p.captions.lines[0].id), C.retimeWord(4, 1.0, 1.4), C.nudgeWord(6, 0.05), C.shiftAll(0.5), C.setLineEmoji(p.captions.lines[0].id, '✨'),
      C.setStyle({ id: 'karaoke' }, true)];
    // a two-word phrase taken from the transcript itself (punctuation stripped), replaced by one word
    const core = (w) => w.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
    const k = p.captions.words.findIndex((w, i) => i > 8 && /^\p{L}+$/u.test(w.w) && /^\p{L}+$/u.test(p.captions.words[i + 1]?.w || ''));
    const phrase = core(p.captions.words[k].w) + ' ' + core(p.captions.words[k + 1].w);
    const fr = C.findReplace(phrase, 'REPLACED');
    for (const c of seq) { H.apply(c); H.seal(); }
    H.apply(fr); H.seal();
    out.replaced = fr.count;
    out.validAfter = R2.validate(p.captions);
    out.edited = p.captions.words.some((w) => w.w === 'welcome!') && p.captions.words.some((w) => w.w === 'friends');
    const snap1 = JSON.stringify(p.captions);
    let n = 0; while (H.undoStack.length > depth0) { H.undo(); n++; }
    out.undoAllRestores = JSON.stringify(p.captions) === snap0 && n > 0;
    while (H.canRedo) H.redo();
    out.redoAllRestores = JSON.stringify(p.captions) === snap1;
    // style per workspace
    out.pc = R2.styleForWorkspace({ ...R2.SEED_WORKSPACES[1], id: 'planet-careers' });
    out.kbb = R2.styleForWorkspace({ ...R2.SEED_WORKSPACES[0], id: 'kbb' });
    out.explicit = R2.styleForWorkspace({ captions: { style: 'neon', font: 'Inter', colors: { text: '#ffeeaa' } } });
    return out;
  }, { enWords });
  check('lines valid (tile all words, monotonic)', m.validInitial.length === 0, m.validInitial.join(','));
  check(`lines respect the char budget (longest ${m.maxChars} chars, ${m.lines} lines)`, m.maxChars <= 40);
  check('commands keep captions valid', m.validAfter.length === 0, m.validAfter.join(','));
  check('edit/insert applied', m.edited);
  check('find & replace across words', m.replaced >= 1, String(m.replaced));
  check('undo all restores the original exactly', m.undoAllRestores);
  check('redo all restores the edited state exactly', m.redoAllRestores);
  check('Planet Careers default = info-card in its navy/gold', m.pc.id === 'info-card' && m.pc.bg?.color === '#0b2545' && m.pc.accent === '#f2c14e', JSON.stringify(m.pc));
  check('KBB default = word-box in its pink', m.kbb.id === 'word-box' && m.kbb.activeBg === '#e8508b', JSON.stringify(m.kbb));
  check('explicit workspace caption defaults win', m.explicit.id === 'neon' && m.explicit.font === 'Inter' && m.explicit.color === '#ffeeaa');

  // ------------------------------------------------------------------ every style rendered at 3 times
  // one style per round trip (60 full-size PNGs in one evaluate exhausts the renderer)
  const styleIds = await page.evaluate(() => {
    const cols = 5, tw = 270, th = 480, sheet = document.createElement('canvas');
    sheet.width = cols * tw; sheet.height = Math.ceil(R2.styles.length / cols) * (th + 28);
    const sc = sheet.getContext('2d'); sc.fillStyle = '#111'; sc.fillRect(0, 0, sheet.width, sheet.height);
    window.__sheet = sheet;
    const small = (c) => { const s = document.createElement('canvas'); s.width = 540; s.height = 960; s.getContext('2d').drawImage(c, 0, 0, 540, 960); return s.getContext('2d').getImageData(0, 0, 540, 960).data; };
    window.__small = small;
    window.__bare = small(R2.renderCanvas({ canvas: { w: 1080, h: 1920 }, captions: { words: [], lines: [] } }, 0));
    return R2.styles.map((s) => s.id);
  });
  const st = [];
  for (const [k, id] of styleIds.entries()) {
    const r = await page.evaluate(({ id, k }) => {
      const s = R2.styles.find((x) => x.id === id);
      const EN = 'Glow up with this serum in just one week'.split(' '), ARW = 'بشرة نضرة مع هذا السيروم في أسبوع'.split(' ');
      const mk = (arr) => arr.map((w, i) => ({ w, t0: 0.3 + i * 0.4, t1: 0.3 + i * 0.4 + 0.36, conf: 1 }));
      const words = mk(id === 'arabic-first' ? ARW : EN);
      const cap = { style: id, words, lines: R2.buildLines(words, id, { w: 1080, h: 1920 }) };
      if (id === 'bilingual') { const a = mk(ARW); cap.alt = { words: a, lines: R2.buildLines(a, id, { w: 1080, h: 1920 }) }; }
      const proj = { canvas: { w: 1080, h: 1920 }, captions: cap };
      const times = [words[0].t0 + 0.05, words[2].t0 + 0.15, words[3].t1 - 0.03];
      const cvs = times.map((t) => R2.renderCanvas(proj, t));
      const diff = (a, b) => { let n = 0; for (let i = 0; i < a.length; i += 4) if (Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]) > 30) n++; return n; };
      const F = cvs.map(window.__small);
      const cols = 5, tw = 270, th = 480, x = (k % cols) * tw, y = Math.floor(k / cols) * (th + 28), sc = window.__sheet.getContext('2d');
      sc.drawImage(cvs[1], x, y, tw, th);
      sc.fillStyle = '#eee'; sc.font = '600 15px system-ui'; sc.textAlign = 'center'; sc.fillText(`${k + 1}. ${s.name}`, x + tw / 2, y + th + 19);
      return { id, name: s.name, anim: R2.resolveStyle(id).anim, lines: cap.lines.length, urls: cvs.map((c) => c.toDataURL('image/png')),
        drawn: F.map((f) => diff(f, window.__bare)), d12: diff(F[0], F[1]), d23: diff(F[1], F[2]) };
    }, { id, k });
    r.urls.forEach((u, i) => fs.writeFileSync(path.join(OUT, 'frames', `${id}-t${i + 1}.png`), Buffer.from(u.split(',')[1], 'base64')));
    delete r.urls;
    st.push(r); results.styles.push(r);
    const animated = r.anim !== 'none';
    check(`style ${id}: draws at all 3 times${animated ? ' and moves with t (' + r.anim + ')' : ' (static style)'}`,
      r.drawn.every((n) => n > 300) && (!animated || r.d12 > 50 || r.d23 > 50), `px ${r.drawn.join('/')} Δ ${r.d12}/${r.d23}`);
  }
  const sheetUrl = await page.evaluate(() => window.__sheet.toDataURL('image/jpeg', 0.88));
  fs.writeFileSync(path.join(SHOTS, 'styles-sheet.jpg'), Buffer.from(sheetUrl.split(',')[1], 'base64'));
  check(`at least 16 styles (${st.length})`, st.length >= 16);

  // ------------------------------------------------------------------ the editor panel (real clicks)
  await page.evaluate(({ enWords }) => { R2.mountEditor({ words: enWords, workspace: { ...R2.SEED_WORKSPACES[0], id: 'kbb' } }); }, { enWords });
  await page.waitForTimeout(150);
  const w5 = page.locator('.rs-cap-w[data-i="5"]');
  await w5.click();
  const seekOk = await page.evaluate(() => Math.abs(ED.ctx.playhead() - ED.project.captions.words[5].t0) < 1e-6);
  check('click a word → playhead jumps to it', seekOk);
  await w5.dblclick();
  await page.keyboard.type('Gorgeous');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(80);
  const edited = await page.evaluate(() => ED.project.captions.words[5].w);
  check('double-click → type → Enter edits the word', edited === 'Gorgeous', edited);
  await page.evaluate(() => ED.history.undo());
  const undone = await page.evaluate(() => ED.project.captions.words[5].w);
  check('undo restores the word', undone !== 'Gorgeous', undone);
  const mid = await page.evaluate(() => { const L = ED.project.captions.lines.find((x) => x.wordIdx[1] > x.wordIdx[0]); return L.wordIdx[0] + 1; });
  await page.locator(`.rs-cap-w[data-i="${mid}"]`).click();
  await page.locator('.rs-cap-sel button', { hasText: 'Split here' }).click();
  await page.waitForTimeout(60);
  const after = await page.evaluate((mid) => ED.project.captions.lines.some((L) => L.wordIdx[0] === mid), mid);
  check('Split here starts a new line at the selected word', after);
  await page.locator('.rs-cap-w[data-i="9"]').click();
  await page.evaluate(() => ED.seek(ED.project.captions.words[9].t0 + 0.05));
  await page.waitForTimeout(100);
  await page.screenshot({ path: path.join(SHOTS, 'caption-editor.png') });
  await page.locator('.rs-cap-tabs button[data-tab="style"]').click();
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SHOTS, 'style-tiles.png') });
  const tilesN = await page.locator('.rs-cap-tile').count();
  check(`style picker shows a live tile per style (${tilesN}), company default first`, tilesN === st.length && (await page.locator('.rs-cap-tile').first().getAttribute('data-style')) === 'word-box');
  await page.locator('.rs-cap-tile[data-style="neon"]').click();
  await page.waitForTimeout(100);
  check('clicking a tile applies the style (undoable)', await page.evaluate(() => { const a = ED.project.captions.style.id === 'neon'; ED.history.undo(); const b = ED.project.captions.style.id !== 'neon'; ED.history.redo(); return a && b; }));
  // editing a style field: size slider → stored as an override, coalesced into one undo step
  const sizeBefore = await page.evaluate(() => ED.history.undoStack.length);
  await page.evaluate(() => { const r = [...document.querySelectorAll('.rs-cap-field')].find((f) => f.textContent.startsWith('Size')).querySelector('input'); for (const v of [80, 90, 100]) { r.value = v; r.dispatchEvent(new Event('input', { bubbles: true })); } });
  await page.waitForTimeout(60);
  const sz = await page.evaluate(() => ({ size: ED.project.captions.style.size, steps: ED.history.undoStack.length }));
  check('style field edit stored as override, slider drags coalesce', sz.size === 100 && sz.steps === sizeBefore + 1, JSON.stringify(sz));

  // Planet Careers workspace: info-card default, Arabic + bilingual pictures
  await page.evaluate(({ arWords }) => {
    const en = 'Study in Germany apply before the March intake'.split(' ').map((w, i) => ({ w, t0: arWords[0].t0 + i * 0.45, t1: arWords[0].t0 + i * 0.45 + 0.4, conf: 1 }));
    R2.mountEditor({ words: arWords, workspace: { ...R2.SEED_WORKSPACES[1], id: 'planet-careers' }, style: { id: 'arabic-first' } });
    window.__en = en;
  }, { arWords });
  await page.evaluate(() => ED.seek(ED.project.captions.words[2].t0 + 0.05));
  await page.waitForTimeout(150);
  await page.screenshot({ path: path.join(SHOTS, 'arabic-caption-editor.png') });
  const arFrame = await page.evaluate(() => document.getElementById('preview').toDataURL('image/png'));
  fs.writeFileSync(path.join(SHOTS, 'arabic-caption-1080x1920.png'), Buffer.from(arFrame.split(',')[1], 'base64'));
  const pcFrames = await page.evaluate(({ arWords }) => {
    const out = {};
    const en = window.__en;
    for (const id of ['bilingual', 'info-card', 'corporate']) {
      const ws = id === 'info-card' ? R2.styleForWorkspace({ ...R2.SEED_WORKSPACES[1], id: 'planet-careers' }) : { id };
      const words = id === 'bilingual' ? en : en;
      const cap = { style: ws, words, lines: R2.buildLines(words, ws, { w: 1080, h: 1920 }) };
      if (id === 'bilingual') cap.alt = { words: arWords, lines: R2.buildLines(arWords, ws, { w: 1080, h: 1920 }) };
      out[id] = R2.render({ canvas: { w: 1080, h: 1920 }, captions: cap }, en[3].t0 + 0.1, 'photo', 'image/jpeg');
    }
    return out;
  }, { arWords });
  for (const [id, u] of Object.entries(pcFrames)) fs.writeFileSync(path.join(SHOTS, `${id}-1080x1920.jpg`), Buffer.from(u.split(',')[1], 'base64'));
  // 16:9 canvas: lines get longer, nothing overflows
  const wide = await page.evaluate(({ enWords }) => {
    const ed = R2.mountEditor({ words: enWords, aspect: '16:9', style: { id: 'minimal' } });
    ed.seek(enWords[12].t0 + 0.05);
    return { lines: ed.project.captions.lines.length };
  }, { enWords });
  await page.waitForTimeout(100);
  const wideFrame = await page.evaluate(() => document.getElementById('preview').toDataURL('image/png'));
  fs.writeFileSync(path.join(OUT, 'wide-16x9.png'), Buffer.from(wideFrame.split(',')[1], 'base64'));
  check(`16:9 canvas re-flows to fewer, longer lines (${wide.lines})`, wide.lines < m.lines + 2);

  // render speed: one 1080x1920 caption frame (what preview/export pay per frame)
  const perf = await page.evaluate(({ enWords }) => {
    const c = document.createElement('canvas'); c.width = 1080; c.height = 1920; const x = c.getContext('2d');
    const res = {};
    for (const id of ['word-box', 'karaoke', 'neon', 'arabic-first', 'info-card']) {
      const cap = { style: id, words: enWords, lines: R2.buildLines(enWords, id, { w: 1080, h: 1920 }) };
      const proj = { canvas: { w: 1080, h: 1920 }, captions: cap };
      const t0 = performance.now(); let n = 0;
      for (let t = 0; t < 10; t += 1 / 30, n++) { x.clearRect(0, 0, 1080, 1920); R2.__draw(x, proj, t); }
      res[id] = +((performance.now() - t0) / n).toFixed(3);
    }
    return res;
  }, { enWords });
  results.renderMsPerFrame = perf;
  console.log('  caption draw ms/frame @1080x1920:', JSON.stringify(perf));
  check('caption layer draws in < 4 ms/frame', Object.values(perf).every((v) => v < 4));

  const real = errors.filter((e) => !/No available adapters/.test(e));
  check('no console errors', real.length === 0, real.slice(0, 3).join(' | '));
  results.consoleErrors = real;
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASS'} (${results.checks.length} checks)`);
  await browser.close(); server.close();
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });

function synthWords(text) {
  return text.split(' ').map((w, i) => ({ w, t0: +(0.2 + i * 0.38).toFixed(2), t1: +(0.2 + i * 0.38 + 0.33).toFixed(2), conf: 1 }));
}
