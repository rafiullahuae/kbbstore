// Captions round (Lane K) — headless Chromium check of capture, non-speech, script, templates and overrides.
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/k-check.cjs [--skip-stt] [--model=base|small] [--regen]
// Needs the models once: bash tools/r2-fetch-test-models.sh (git-ignored, models/). Fixtures are made once in the
// browser (MMS-TTS + the JFK sample + a library music track) into models/_r2-test/k-*.wav and re-used.
// Writes tools/out/k/ (results.json, transcripts, exports) and shots/k/ (pictures at 1920x1080 and 1280x800).
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const server = require('./r2-serve.cjs');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'tools/out/k'), SHOTS = path.join(ROOT, 'shots/k');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const args = process.argv.slice(2);
const SKIP_STT = args.includes('--skip-stt');
const MODEL = (args.find((a) => a.startsWith('--model=')) || '--model=base').slice(8);
const ONLY = (args.find((a) => a.startsWith('--only=')) || '').slice(7).split(',').filter(Boolean);   // music,silence,long,vocab
const want = (k) => !ONLY.length || ONLY.includes(k);

const JFK = 'And so my fellow Americans, ask not what your country can do for you, ask what you can do for your country.';
const TAIL = ['Book a free consultation with our team today.', 'We will guide you through every step of the visa process.'];
const QUIET = ['Today I am unboxing the new vitamin C serum from Korea.', 'After one week my skin looks brighter and more hydrated.'];
const VOCAB_EN = ['I love the Beauty of Joseon sunscreen and the Torriden serum.', 'Apply for the Schengen visa and the Chancenkarte this year.'];

const normEn = (s) => s.toLowerCase().replace(/[^a-z0-9' ]+/g, ' ').split(/\s+/).filter(Boolean);
function wer(ref, hyp) {
  const d = Array.from({ length: ref.length + 1 }, (_, i) => [i, ...Array(hyp.length).fill(0)]);
  for (let j = 1; j <= hyp.length; j++) d[0][j] = j;
  for (let i = 1; i <= ref.length; i++) for (let j = 1; j <= hyp.length; j++)
    d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (ref[i - 1] === hyp[j - 1] ? 0 : 1));
  return d[ref.length][hyp.length] / Math.max(1, ref.length);
}

const results = { checks: [], capture: {}, styles: {} };
let failures = 0;
const check = (name, ok, detail = '') => { results.checks.push({ name, ok: !!ok, detail }); if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); };
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name) });
const saveUrl = (url, file) => fs.writeFileSync(file, Buffer.from(url.split(',')[1], 'base64'));

(async () => {
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const errors = [], offsite = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('request', (r) => { const u = new URL(r.url()); if (!['127.0.0.1', 'localhost'].includes(u.hostname) && !/^(data|blob):/.test(r.url())) offsite.push(r.url()); });
  page.setDefaultTimeout(30000);
  await page.goto(base + '/app/captions/dev/index.html');
  await page.waitForFunction(() => window.R2);
  await page.evaluate(() => R2.ready);

  // ------------------------------------------------------------------ pure units (no model)
  const u = await page.evaluate(() => {
    const N = R2.nonspeech, V = R2.vocab, S = R2.script;
    const mk = (s, t0 = 1) => s.split(' ').map((w, i) => ({ w, t0: t0 + i * 0.4, t1: t0 + i * 0.4 + 0.35, conf: 1 }));
    const out = {};
    const tags = ['[Music]', '♪', '(applause)', '[Laughter]', '[BLANK_AUDIO]', '(music)', '[موسيقى]', '(تصفيق)', '(ضحك)', '♪♪'];
    out.tags = tags.map((t) => [t, N.soundTagOf(t)]);
    const multi = N.tagWords(mk('Hello (upbeat music) there ♪ la la la ♪ friends'));
    out.multi = multi.map((w) => [w.w, w.tag || '']);
    out.plain = ['Music', 'music', 'applause', 'هلا', '(see', 'note)'].map((t) => N.soundTagOf(t));
    // repeated-phrase loop
    const loop = mk('so I said I am going to go I am going to go I am going to go I am going to go home');
    const d = N.findHallucinations(loop, null);
    out.loopDropped = [...d.keys()].length; out.loopKept = loop.filter((_, i) => !d.has(i)).map((w) => w.w).join(' ');
    // near-miss spellings
    const vocab = V.vocabFor({ id: 'kbb', name: 'KBB', industry: 'Beauty / K-beauty retail' });
    const pcv = V.vocabFor({ id: 'planet-careers', name: 'Planet Careers', industry: 'Immigration & study-visa consultancy' });
    const fix = (s, v) => V.correctTerms(mk(s), v).words.map((w) => w.w).join(' ');
    out.fix = [
      fix('I love Cosrex and the Medi cube toner.', vocab),
      fix('the Torridon serum from Beauty of Josan', vocab),
      fix('Apply for the Shengen visa and the Chancenkarta today.', pcv),
      fix('Study in Germeny or Portugall this year', pcv),
      fix('the weather is nice and the serum is light', vocab),
    ];
    out.vocabN = [vocab.length, pcv.length];
    out.promptEn = V.promptFor(vocab, 'en'); out.promptAr = V.promptFor(pcv, 'ar');
    // alignEdit: change one word, delete one, insert two; unchanged words keep their timing
    const ws = mk('welcome back to my channel today');
    const ed = S.alignEdit(ws, 'welcome back to our lovely channel today!');
    out.align = ed.map((w) => [w.w, w.t0, w.t1]);
    out.alignKeep = ed[0].t0 === ws[0].t0 && ed[0].t1 === ws[0].t1 && ed[ed.length - 1].t0 === ws[5].t0;
    return out;
  });
  results.units = u;
  const tagOk = u.tags.every(([t, k]) => !!k) && u.tags.find((x) => x[0] === '(applause)')[1] === 'applause' && u.tags.find((x) => x[0] === '[Laughter]')[1] === 'laughter'
    && u.tags.find((x) => x[0] === '[BLANK_AUDIO]')[1] === 'blank' && u.tags.find((x) => x[0] === '[موسيقى]')[1] === 'music' && u.tags.find((x) => x[0] === '(تصفيق)')[1] === 'applause';
  check('sound tags recognised: [Music] ♪ (applause) [Laughter] [BLANK_AUDIO] (music) [موسيقى] (تصفيق) (ضحك) ♪♪', tagOk, JSON.stringify(u.tags));
  check('multi-word tag "(upbeat music)" and sung "♪ la la la ♪" marked; plain words untouched',
    u.multi.filter(([, t]) => t).length === 7 && u.multi[0][1] === '' && u.multi[u.multi.length - 1][1] === '' && u.plain.every((x) => x === null), JSON.stringify(u.multi));
  check('repeated-phrase hallucination collapsed to one copy', u.loopDropped === 15 && u.loopKept === 'so I said I am going to go home', `${u.loopDropped} dropped → "${u.loopKept}"`);
  check('near-miss spellings corrected from the company vocabulary', u.fix[0] === 'I love COSRX and the Medicube toner.' && u.fix[1] === 'the Torriden serum from Beauty of Joseon'
    && u.fix[2] === 'Apply for the Schengen visa and the Chancenkarte today.' && u.fix[3] === 'Study in Germany or Portugal this year' && u.fix[4] === 'the weather is nice and the serum is light', JSON.stringify(u.fix));
  check('script edit aligned word by word, unchanged words keep their timing', u.alignKeep && u.align.map((x) => x[0]).join(' ') === 'welcome back to our lovely channel today!', JSON.stringify(u.align));
  console.log(`  vocabulary: KBB ${u.vocabN[0]} terms, Planet Careers ${u.vocabN[1]} terms; prompt EN "${u.promptEn.slice(0, 90)}…"`);

  // ------------------------------------------------------------------ speech: fixtures
  const F = path.join(ROOT, 'models/_r2-test');
  if (!SKIP_STT) {
    const have = !args.includes('--regen') && ['k-music.wav', 'k-silence.wav', 'k-vocab.wav'].every((f) => fs.existsSync(path.join(F, f))) && fs.existsSync(path.join(F, 'en-long.wav'));
    console.log(`… ${have ? 'loading' : 'making'} fixtures (library music + JFK + MMS-TTS)`);
    const fx = await page.evaluate(async ({ have, TAIL, QUIET, VOCAB_EN }) => {
      const L = (n) => R2.loadAudio('/models/_r2-test/' + n);
      let music, silence, voc;
      const jfk = await L('jfk.wav');
      const en = await L('en-long.wav').catch(() => null);
      if (have) { music = await L('k-music.wav'); silence = await L('k-silence.wav'); voc = await L('k-vocab.wav'); }
      else {
        // music+speech: 8 s music alone, JFK over the music (music ducked), 7 s music alone, two TTS sentences dry
        const track = (await R2.loadAudio16('/library/music/dream-big.m4a'));
        const m = track.subarray(16000 * 4, 16000 * 34);
        const peak = m.reduce((a, v) => Math.max(a, Math.abs(v)), 0) || 1;
        const mus = m.map((v) => v / peak * 0.35);
        const tail = await R2.tts(TAIL, 'eng', 0.5);
        let a = R2.concat([mus.slice(0, 16000 * 8), new Float32Array(jfk.length), mus.slice(0, 16000 * 7), tail]);
        a = R2.mix(a, jfk, 8, 1);
        a = R2.mix(a, mus.slice(16000 * 8, 16000 * 8 + jfk.length).map((v) => v * 0.25), 8, 1);
        music = a;
        // speech, 14 s of near-silence (room tone), speech
        const s1 = await R2.tts([QUIET[0]], 'eng', 0.3), s2 = await R2.tts([QUIET[1]], 'eng', 0.3);
        silence = R2.concat([s1, R2.noise(14, 0.0008), s2]);
        voc = await R2.tts(VOCAB_EN, 'eng', 0.6);
        await R2.saveWav('k-music.wav', music); await R2.saveWav('k-silence.wav', silence); await R2.saveWav('k-vocab.wav', voc);
      }
      window.__k = { music, silence, voc, en, jfk };
      return { music: music.length / 16000, silence: silence.length / 16000, voc: voc.length / 16000, en: en ? en.length / 16000 : 0 };
    }, { have, TAIL, QUIET, VOCAB_EN });
    console.log(`  music+speech ${fx.music.toFixed(1)} s, silence ${fx.silence.toFixed(1)} s, vocabulary ${fx.voc.toFixed(1)} s, en-long ${fx.en.toFixed(1)} s`);
    if (!fx.en) throw new Error('models/_r2-test/en-long.wav missing: run tools/r2-check.cjs once first (it makes the English fixture)');

    // ---- 1. music + speech: tags hidden, music-only stretches found, JFK still captioned
    if (want('music')) {
    const ms = await page.evaluate(async (model) => {
      const r = await R2.capture(window.__k.music, { model, lang: 'en', workspace: { id: 'kbb', name: 'KBB', industry: 'Beauty' } });
      const cap = { style: 'word-box', words: r.words, lines: R2.buildLines(r.words, 'word-box', { w: 1080, h: 1920 }), music: r.music, soundTags: 'hide' };
      const visHide = R2.script.cues(cap, 'hide').map((c) => c.text).join(' ');
      const visText = R2.script.cues(cap, 'text').map((c) => c.text).join(' ');
      window.__musicCap = cap;
      return { raw: r.raw.words.map((w) => w.w).join(' '), words: r.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, tag: w.tag || null })),
        music: r.music, an: { music: r.raw.analysis.music, speech: r.raw.analysis.speech, silence: r.raw.analysis.silence }, dropped: r.dropped, stats: r.raw.stats, visHide, visText };
    }, MODEL);
    results.capture.music = ms;
    fs.writeFileSync(path.join(OUT, 'capture-music.json'), JSON.stringify(ms, null, 1));
    const tagsSeen = ms.words.filter((w) => w.tag).map((w) => w.w);
    console.log(`  music+speech raw: "${ms.raw.slice(0, 200)}"`);
    console.log(`  tags Whisper wrote: ${JSON.stringify(tagsSeen)}; dropped ${JSON.stringify(ms.dropped.map((d) => d.w + '/' + d.why))}; music-only ${JSON.stringify(ms.music)}`);
    const cov = (runs, a, b) => { let s = 0; for (const [x, y] of runs) s += Math.max(0, Math.min(b, y) - Math.max(a, x)); return s / (b - a); };
    const jfkEnd = 8 + 11;
    const intro = cov(ms.music, 0.5, 7.5), mid = cov(ms.music, jfkEnd + 1, jfkEnd + 6.5);
    check(`music-only stretches found (intro ${(intro * 100).toFixed(0)} %, middle ${(mid * 100).toFixed(0)} % covered)`, intro >= 0.6 && mid >= 0.6);
    check('no music tag or ♪ shows in the captions (Hide)', !/[♪♫\[\]()]|music|موسيق/i.test(ms.visHide), ms.visHide.slice(0, 120));
    const inMusicWords = ms.words.filter((w) => !w.tag && ((w.t0 + w.t1) / 2 < 7.5 || ((w.t0 + w.t1) / 2 > jfkEnd + 1 && (w.t0 + w.t1) / 2 < jfkEnd + 6.5)));
    check('no invented words inside the music-only stretches', inMusicWords.length === 0, inMusicWords.map((w) => w.w).join(' '));
    const jfkHyp = ms.words.filter((w) => !w.tag && w.t0 > 7 && w.t1 < jfkEnd + 1.5).map((w) => w.w).join(' ');
    const e1 = wer(normEn(JFK), normEn(jfkHyp));
    check(`speech over music still captioned (JFK under music WER ${(e1 * 100).toFixed(1)} %)`, e1 <= 0.3, jfkHyp);
    const tailHyp = ms.words.filter((w) => !w.tag && w.t0 > jfkEnd + 6).map((w) => w.w).join(' ');
    check(`speech after the music captioned (WER ${(wer(normEn(TAIL.join(' ')), normEn(tailHyp)) * 100).toFixed(1)} %)`, wer(normEn(TAIL.join(' ')), normEn(tailHyp)) <= 0.3, tailHyp);
    // frames inside a music-only stretch: nothing in Hide, a ♪ in Show-icon mode
    const mf = await page.evaluate(() => {
      const cap = window.__musicCap, t = cap.music.length ? (cap.music[0][0] + cap.music[0][1]) / 2 : 4;
      const proj = (mode) => ({ canvas: { w: 1080, h: 1920 }, captions: { ...cap, soundTags: mode } });
      const bare = R2.renderCanvas({ canvas: { w: 1080, h: 1920 }, captions: { words: [], lines: [] } }, t);
      const px = (c) => { const a = c.getContext('2d').getImageData(0, 0, 1080, 1920).data, b = bare.getContext('2d').getImageData(0, 0, 1080, 1920).data; let n = 0; for (let i = 0; i < a.length; i += 16) if (Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]) > 30) n++; return n; };
      const hide = R2.renderCanvas(proj('hide'), t), icon = R2.renderCanvas(proj('icon'), t);
      return { t, hide: px(hide), icon: px(icon), url: icon.toDataURL('image/jpeg', 0.85) };
    });
    saveUrl(mf.url, path.join(SHOTS, 'music-icon-1080x1920.jpg'));
    check(`during music only: Hide draws nothing (${mf.hide} px), Show ♪ draws the icon (${mf.icon} px)`, mf.hide === 0 && mf.icon > 200);
    }

    // ---- 2. silence: no words in the silent stretch; an injected "Thanks for watching" there is removed
    if (want('silence')) {
    const sl = await page.evaluate(async (model) => {
      const r = await R2.capture(window.__k.silence, { model, lang: 'en' });
      const s1 = r.words.length ? r.words[0] : null;
      const an = r.raw.analysis;
      const sil = an.silence.slice().sort((a, b) => (b[1] - b[0]) - (a[1] - a[0]))[0] || [0, 0];
      // inject Whisper's favourite silence hallucination in the middle of the silent stretch, and a loop
      const mid = (sil[0] + sil[1]) / 2;
      const fake = ['Thanks', 'for', 'watching!'].map((w, i) => ({ w, t0: mid + i * 0.3, t1: mid + i * 0.3 + 0.28, conf: 1 }));
      const inj = [...r.raw.words, ...fake].sort((a, b) => a.t0 - b.t0);
      const c2 = R2.nonspeech.cleanNonSpeech(inj, an, {});
      return { raw: r.raw.words.map((w) => w.w).join(' '), words: r.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 })), sil, dropped: r.dropped,
        injectedLeft: c2.words.filter((w) => w.t0 >= sil[0] && w.t1 <= sil[1]).map((w) => w.w), injectedDropped: c2.dropped.map((d) => d.w + '/' + d.why), stats: r.raw.stats };
    }, MODEL);
    results.capture.silence = sl;
    console.log(`  silence raw: "${sl.raw}"  silent stretch ${sl.sil.map((x) => x.toFixed(1)).join('–')} s; Whisper's own extras dropped: ${JSON.stringify(sl.dropped.map((d) => d.w + '/' + d.why))}`);
    const inSil = sl.words.filter((w) => w.t0 > sl.sil[0] + 0.3 && w.t1 < sl.sil[1] - 0.3);
    check(`no caption words inside the ${(sl.sil[1] - sl.sil[0]).toFixed(1)} s silent stretch`, sl.sil[1] - sl.sil[0] > 10 && inSil.length === 0, inSil.map((w) => w.w).join(' '));
    check('"Thanks for watching!" hallucinated in silence is removed', sl.injectedLeft.length === 0 && sl.injectedDropped.length === 3, JSON.stringify(sl.injectedDropped));
    // VAD gate on a long gap: speech, 45 s of room tone, speech -> the planner steps over the gap (never decoded)
    const vg = await page.evaluate(() => {
      const k = window.__k.silence, n = 16000 * 3.7;
      const a = R2.concat([k.slice(0, n), R2.noise(45, 0.0008), k.slice(k.length - n)]);
      const plan = R2.planWindows(a.length / 16000, R2.nonspeech.classifyAudio(a));
      const decoded = plan.reduce((s, p) => s + (p.e - p.s), 0);
      return { plan: plan.map((p) => [+p.s.toFixed(1), +p.e.toFixed(1)]), decoded, dur: a.length / 16000 };
    });
    check(`VAD gate: a 45 s silence is never decoded (${vg.decoded.toFixed(1)} of ${vg.dur.toFixed(1)} s go to Whisper, windows ${JSON.stringify(vg.plan)})`, vg.decoded < 12 && vg.plan.length === 2);
    }

    // ---- 3. the long English clip: stitching across windows, dropped-speech recovery, real outro kept
    if (want('long')) {
    const lg = await page.evaluate(async (model) => {
      const a = window.__k.en;
      const base = await R2.capture(a, { model, lang: 'en', workspace: { id: 'kbb', name: 'KBB', industry: 'Beauty' } });
      // Whisper skipping a stretch (measured live: whisper-small dropped 16 s of clear speech in one window):
      // pretend the first pass lost 20-31 s; the second listen must bring it back
      const drop = [[20, 31]];
      const lost = await R2.capture(a, { model, lang: 'en', debugDrop: drop, speakers: false, workspace: { id: 'kbb', name: 'KBB', industry: 'Beauty' } });
      const plan = R2.planWindows(a.length / 16000, base.raw.analysis);
      return { words: base.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1, spk: w.spk ?? null })), stats: base.raw.stats, plan, dropped: base.dropped, fixes: base.fixes, speakers: base.speakers,
        lost: lost.words.map((w) => ({ w: w.w, t0: w.t0, t1: w.t1 })), lostStats: lost.raw.stats, drop };
    }, MODEL);
    results.capture.long = { stats: lg.stats, plan: lg.plan, dropped: lg.dropped, fixes: lg.fixes, speakers: lg.speakers, lostStats: lg.lostStats };
    fs.writeFileSync(path.join(OUT, 'capture-long.json'), JSON.stringify(lg, null, 1));
    const ref = normEn([JFK, 'Hello everyone, welcome back to my channel.', 'Today I am unboxing the new vitamin C serum from Korea.', 'The texture is light and it absorbs really fast.',
      'After one week my skin looks brighter and more hydrated.', 'If you want to study in Germany, the next intake is in March.', 'Prepare your passport, your bank statement and your university documents.',
      'Book a free consultation with our team today.', 'We will guide you through every step of the visa process.', 'Do not forget to follow us for more tips.', 'Thank you so much for watching, see you in the next video.'].join(' '));
    const hyp = lg.words.map((w) => w.w).join(' ');
    const e2 = wer(ref, normEn(hyp));
    let gap = 0, dup = 0;
    for (let i = 1; i < lg.words.length; i++) { gap = Math.max(gap, lg.words[i].t0 - lg.words[i - 1].t1); if (normEn(lg.words[i].w)[0] === normEn(lg.words[i - 1].w)[0] && lg.words[i].t0 - lg.words[i - 1].t0 < 1) dup++; }
    console.log(`  long: ${lg.words.length} words WER ${(e2 * 100).toFixed(1)} %; windows ${JSON.stringify(lg.plan.map((p) => [p.s, +p.e.toFixed(2), p.cut]))}; stats ${JSON.stringify(lg.stats)}`);
    check(`windows end in pauses (${lg.stats.cuts} of ${lg.plan.length - 1} seams), no doubled word, largest gap ${gap.toFixed(1)} s, WER ${(e2 * 100).toFixed(1)} %`, lg.stats.cuts >= 1 && dup === 0 && gap < 2.5 && e2 <= 0.08);
    check('the real spoken "Thank you so much for watching" is kept', /thank you so much for watching/i.test(hyp.replace(/[,.]/g, '')), hyp.slice(-80));
    const inDrop = (ws) => ws.filter((w) => (w.t0 + w.t1) / 2 > 20 && (w.t0 + w.t1) / 2 < 31);
    const want = inDrop(lg.words), got = inDrop(lg.lost);
    const e3 = wer(normEn(want.map((w) => w.w).join(' ')), normEn(got.map((w) => w.w).join(' ')));
    check(`dropped speech recovered by the second listen (${got.length}/${want.length} words back in 20–31 s, ${lg.lostStats.reread} re-reads, ${lg.lostStats.recovered} words, WER vs. no-drop ${(e3 * 100).toFixed(1)} %)`,
      lg.lostStats.recovered > 0 && got.length >= want.length * 0.85 && e3 <= 0.2);
    check('punctuation and casing kept', /[A-Z]/.test(hyp) && /[.,]/.test(hyp));
    // two voices (JFK + the TTS voice): speaker turns, best effort
    const s0 = lg.words.filter((w) => w.t1 < 11).map((w) => w.spk), s1 = lg.words.filter((w) => w.t0 > 13).map((w) => w.spk);
    results.capture.long.speakerSplit = { jfk: s0, tts: s1.slice(0, 10) };
    console.log(`  speakers: ${lg.speakers} (JFK part ${JSON.stringify([...new Set(s0)])}, TTS part ${JSON.stringify([...new Set(s1)])})`);
    results.enWords = lg.words;
    const other = s1.filter((x) => x !== s0[0]).length / Math.max(1, s1.length);
    check(`speaker turns detected (best effort): ${lg.speakers} voices, JFK = one speaker, ${(other * 100).toFixed(0)} % of the TTS voice labelled the other`, lg.speakers === 2 && new Set(s0).size === 1 && other >= 0.7);

    // ---- 4. vocabulary prompt: the company's words reach Whisper; timing is unchanged by the prompt
    }
    if (want('vocab')) {
    const vc = await page.evaluate(async (model) => {
      // a company that typed its own words (Words to spell right): they lead the prompt and the post-pass
      const ws = { id: 'kbb', name: 'KBB', industry: 'Beauty / K-beauty retail', captions: { vocab: ['Beauty of Joseon', 'Torriden', 'Schengen', 'Chancenkarte'] } };
      const plain = await R2.capture(window.__k.voc, { model, lang: 'en', vocab: [], prompt: '', speakers: false });
      const biased = await R2.capture(window.__k.voc, { model, lang: 'en', workspace: ws, speakers: false });
      const j0 = await R2.capture(window.__k.jfk, { model, lang: 'en', vocab: [], prompt: '', speakers: false });
      const j1 = await R2.capture(window.__k.jfk, { model, lang: 'en', workspace: ws, speakers: false });
      return { plain: plain.words.map((w) => w.w).join(' '), biased: biased.words.map((w) => w.w).join(' '), prompted: biased.raw.stats.prompted, fixes: biased.fixes,
        prompt: biased.raw.prompt, jfk0: j0.words.map((w) => [w.w, w.t0]), jfk1: j1.words.map((w) => [w.w, w.t0]) };
    }, MODEL);
    results.capture.vocab = vc;
    const terms = ['Beauty of Joseon', 'Torriden', 'Schengen', 'Chancenkarte'];
    const hits = (s) => terms.filter((t) => s.toLowerCase().includes(t.toLowerCase())).length;
    console.log(`  vocabulary: plain "${vc.plain}"\n              biased "${vc.biased}" (prompted windows ${vc.prompted}, fixes ${JSON.stringify(vc.fixes)})`);
    const lt = (s) => s.toLowerCase().replace(/[^a-z]/g, '');
    const shift = vc.jfk0.length === vc.jfk1.length ? vc.jfk0.filter((x, i) => lt(x[0]) === lt(vc.jfk1[i][0])).map((x) => Math.abs(x[1] - vc.jfk1[vc.jfk0.indexOf(x)][1])).sort((a, b) => a - b) : [];
    const medShift = shift.length ? shift[shift.length >> 1] : 9;
    check(`vocabulary bias: ${hits(vc.biased)}/4 terms spelled right with the prompt + post-pass (${hits(vc.plain)}/4 without)`, vc.prompted >= 1 && hits(vc.biased) > hits(vc.plain) && hits(vc.biased) >= 2);
    check(`prompt never leaks into captions and keeps word timing (JFK median shift ${medShift.toFixed(3)} s)`, !/Beauty of Joseon|Chancenkarte/i.test(vc.jfk1.map((x) => x[0]).join(' ')) && medShift < 0.12
      && wer(normEn(JFK), normEn(vc.jfk1.map((x) => x[0]).join(' '))) <= 0.1);

    }
  }

  // ------------------------------------------------------------------ script view + exports (UI)
  const enWords = results.enWords || synthWords(JFK + ' Hello everyone, welcome back to my channel. Today I am unboxing the new vitamin C serum from Korea.');
  await page.evaluate(({ enWords }) => {
    window.__writes = [];
    const ws = { ...R2.SEED_WORKSPACES[0], id: 'kbb' };
    const words = enWords.map((w) => ({ ...w, conf: 1 }));
    // a music tag + an Arabic alt track so the bilingual exports exist
    words.unshift({ w: '[Music]', t0: 0, t1: 0.2, conf: 1, tag: 'music' });
    const alt = { lang: 'ar', words: [{ w: 'مرحبا', t0: words[1].t0, t1: words[1].t1 + 1, conf: 1 }, { w: 'بكم', t0: words[1].t1 + 1, t1: words[1].t1 + 2, conf: 1 }] };
    R2.mountEditor({ words, workspace: ws, engine: R2.fakeEngine, alt, extra: { soundTags: 'hide' } });
  }, { enWords });
  await page.locator('.rs-cap-tabs button[data-tab="script"]').click();
  await page.waitForTimeout(200);
  await page.locator('.rs-cap-check input').first().check();
  await page.waitForTimeout(100);
  for (const x of ['txt', 'srt', 'vtt', 'bi-txt', 'bi-srt']) { await page.locator(`.rs-cap-scriptbar [data-x="${x}"]`).click(); await page.waitForTimeout(60); }
  const ex = await page.evaluate(() => {
    const c = ED.project.captions, S = R2.script;
    const w = window.__writes;
    const get = (ext) => w.find((x) => x.path.endsWith(ext) && !x.path.includes('bilingual'));
    const srt = get('.srt').data, vtt = get('.vtt').data;
    const ps = S.parseSubtitles(srt), pv = S.parseSubtitles(vtt);
    const mono = (cs) => cs.every((q, i) => q.t1 > q.t0 && (!i || q.t0 >= cs[i - 1].t1 - 1e-6));
    const vis = S.cues(c).map((q) => q.text).join(' ');
    return { paths: w.map((x) => x.path), n: ps.length, nv: pv.length, mono: mono(ps) && mono(pv), vttHead: vtt.startsWith('WEBVTT'),
      textEq: ps.map((q) => q.text).join(' ') === vis && pv.map((q) => q.text).join(' ') === vis,
      wordsEq: ps.map((q) => q.text).join(' ').split(/\s+/).join(' ') === c.words.filter((x) => !x.tag).map((x) => x.w).join(' '),
      noTag: !/\[Music\]/.test(srt), txt: get('.txt').data.slice(0, 200), bi: w.find((x) => x.path.endsWith('-bilingual.srt')).data.split('\n').slice(0, 5).join(' / ') };
  });
  results.exports = ex;
  fs.writeFileSync(path.join(OUT, 'exports.json'), JSON.stringify(ex, null, 1));
  check(`script exports saved into workspaces/kbb/exports/ (${ex.paths.length} files)`, ex.paths.length === 5 && ex.paths.every((p) => p.startsWith('workspaces/kbb/exports/')), ex.paths.join(', '));
  check(`SRT (${ex.n} cues) and VTT (${ex.nv}) parse, timings monotonic`, ex.n > 3 && ex.n === ex.nv && ex.mono && ex.vttHead);
  check('exported text equals the captions word for word (tags hidden)', ex.textEq && ex.wordsEq && ex.noTag);
  check('bilingual SRT carries the second language', /مرحبا/.test(ex.bi), ex.bi);
  await page.setViewportSize({ width: 1280, height: 800 });
  await shot(page, 'script-1280x800.png');
  // edit a paragraph in the Script tab -> the caption words change, timing kept, one undo step
  const para = page.locator('.rs-cap-para').first();
  const before = await page.evaluate(() => ({ words: JSON.stringify(ED.project.captions.words), n: ED.history.undoStack.length }));
  const orig = await para.textContent();
  const changed = orig.replace(/\bcountry\b/, 'nation');
  await para.click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.keyboard.type(changed);
  await page.keyboard.press('Enter');
  await page.waitForTimeout(150);
  const after = await page.evaluate(() => ({ words: ED.project.captions.words, n: ED.history.undoStack.length, valid: R2.validate(ED.project.captions) }));
  const nationW = after.words.find((w) => /nation/i.test(w.w));
  const oldW = JSON.parse(before.words).find((w) => /country/i.test(w.w));
  check('editing the script changes the caption words (timing kept, captions valid, one undo step)', !!nationW && Math.abs(nationW.t0 - oldW.t0) < 1e-6 && after.valid.length === 0 && after.n === before.n + 1,
    nationW ? `${nationW.w} ${nationW.t0}` : 'no edit');
  const undone = await page.evaluate((b) => { ED.history.undo(); return JSON.stringify(ED.project.captions.words) === b; }, before.words);
  check('undo restores the script edit exactly', undone);
  await page.setViewportSize({ width: 1920, height: 1080 });
  await shot(page, 'script-1920x1080.png');

  // ------------------------------------------------------------------ overrides: per line / per word, transcript dots, pixels
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.locator('.rs-cap-tabs button[data-tab="transcript"]').click();
  await page.waitForTimeout(150);
  const L1 = await page.evaluate(() => { const L = ED.project.captions.lines.find((x) => !ED.project.captions.words[x.wordIdx[0]].tag && x.wordIdx[1] - x.wordIdx[0] >= 3); return { id: L.id, a: L.wordIdx[0], t: (L.t0 + L.t1) / 2 }; });
  await page.locator(`.rs-cap-w[data-i="${L1.a + 1}"]`).click();
  await page.locator('.rs-cap-sel [data-ov="line"]').click();
  await page.waitForTimeout(80);
  // line: red, bigger; word: highlight + emoji (through the real controls)
  await page.evaluate(() => {
    const box = document.querySelector('.rs-cap-ov');
    const fld = (name) => [...box.querySelectorAll('.rs-cap-field')].find((f) => f.firstChild.textContent === name);
    const col = fld('Colour').querySelector('input[type=color]'); col.value = '#ff0000'; col.dispatchEvent(new Event('input', { bubbles: true }));
    const sz = fld('Size').querySelector('input[type=range]'); for (const v of [90, 110, 120]) { sz.value = v; sz.dispatchEvent(new Event('input', { bubbles: true })); }
  });
  await page.waitForTimeout(120);
  await page.locator('.rs-cap-sel [data-ov="word"]').click();
  await page.waitForTimeout(80);
  await page.evaluate(() => {
    const box = document.querySelector('.rs-cap-ov');
    const fld = (name) => [...box.querySelectorAll('.rs-cap-field')].find((f) => f.firstChild.textContent === name);
    const hl = fld('Highlight').querySelector('input[type=checkbox]'); hl.click();
    const e = fld('Emoji').querySelector('input'); e.value = '✨'; e.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(150);
  const ov = await page.evaluate((L1) => {
    const c = ED.project.captions, L = c.lines.find((x) => x.id === L1.id), w = c.words[L1.a + 1];
    return { lov: L.ov, wov: w.ov, dots: document.querySelectorAll('.rs-cap-line.has-ov').length, wdots: document.querySelectorAll('.rs-cap-w.ov').length, steps: ED.history.undoStack.length };
  }, L1);
  check('line override (colour, size) and word override (highlight, emoji) stored', ov.lov?.color === '#ff0000' && ov.lov?.size === 120 && !!ov.wov?.hl && ov.wov?.emoji === '✨', JSON.stringify({ l: ov.lov, w: ov.wov }));
  check('styled line and word shown with a dot in the transcript', ov.dots === 1 && ov.wdots === 1);
  await page.evaluate((t) => ED.seek(t), L1.t);
  await page.waitForTimeout(100);
  await shot(page, 'overrides-1280x800.png');
  const pix = await page.evaluate((L1) => {
    const p = ED.project, t = L1.t;
    const red = (c) => { const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 0; i < d.length; i += 4) if (d[i] > 235 && d[i + 1] < 30 && d[i + 2] < 30) n++; return n; };
    const withOv = R2.renderCanvas(p, t);
    const plain = R2.renderCanvas({ ...p, captions: { ...p.captions, lines: p.captions.lines.map(({ ov, ...L }) => L), words: p.captions.words.map(({ ov, ...w }) => w) } }, t);
    // preview / export path (core renderFrame through the caption item) vs. the layer on its own: same pixels
    const a = document.createElement('canvas'); a.width = 1080; a.height = 1920; R2.renderFrame(a.getContext('2d'), p, t, {});
    const b = document.createElement('canvas'); b.width = 1080; b.height = 1920; R2.renderFrame(b.getContext('2d'), p, t, { preview: true });
    const da = a.getContext('2d').getImageData(0, 0, 1080, 1920).data, db = b.getContext('2d').getImageData(0, 0, 1080, 1920).data;
    let diff = 0; for (let i = 0; i < da.length; i++) if (da[i] !== db[i]) diff++;
    const blocks = R2.captionBlocks(a.getContext('2d'), p, t, null);
    return { redOv: red(withOv), redPlain: red(plain), exportVsPreview: diff, fontPx: blocks[0]?.lay.px, url: withOv.toDataURL('image/jpeg', 0.88), redExport: red(a) };
  }, L1);
  saveUrl(pix.url, path.join(SHOTS, 'line-override-1080x1920.jpg'));
  check(`per-line override renders (${pix.redOv} red px vs ${pix.redPlain} without; export frame ${pix.redExport}), identical in preview and export`, pix.redOv > 3000 && pix.redPlain < 50 && pix.redExport > 3000 && pix.exportVsPreview === 0);
  // Reset to style
  await page.locator(`.rs-cap-line[data-line="${L1.id}"] [data-x="reset-line"]`).click();
  await page.waitForTimeout(100);
  const rs = await page.evaluate((L1) => { const c = ED.project.captions; return !c.lines.find((x) => x.id === L1.id).ov && !c.words[L1.a + 1].ov; }, L1);
  check('"Reset to style" removes the line and word overrides (undoable)', rs && await page.evaluate((L1) => { ED.history.undo(); return !!ED.project.captions.lines.find((x) => x.id === L1.id).ov; }, L1));

  // ------------------------------------------------------------------ drag a caption on the canvas
  const dr = await page.evaluate((L1) => {
    ED.seek(L1.t);
    const b = R2.captionBlocks(document.createElement('canvas').getContext('2d'), ED.project, L1.t, null)[0];
    const cv = document.getElementById('preview'), r = cv.getBoundingClientRect(), k = r.width / 1080;
    return { x: r.left + (b.box.x + b.box.w / 2) * k, y: r.top + (b.box.y + b.box.h / 2) * k, k, steps: ED.history.undoStack.length };
  }, L1);
  await page.mouse.move(dr.x, dr.y); await page.mouse.down();
  for (let i = 1; i <= 6; i++) await page.mouse.move(dr.x + i * 5, dr.y - i * 25);
  await page.mouse.up();
  await page.waitForTimeout(100);
  const dd = await page.evaluate((L1) => { const L = ED.project.captions.lines.find((x) => x.id === L1.id); return { pos: L.ov?.pos, steps: ED.history.undoStack.length, other: ED.project.captions.lines.filter((x) => x.id !== L1.id && x.ov?.pos).length }; }, L1);
  check(`drag a caption on the canvas moves that line only (pos ${JSON.stringify(dd.pos)}), one undo step`, dd.pos && dd.pos.y < 0.7 && dd.other === 0 && dd.steps === dr.steps + 1);
  const dr2 = await page.evaluate((L1) => {
    const b = R2.captionBlocks(document.createElement('canvas').getContext('2d'), ED.project, L1.t, null)[0];
    const r = document.getElementById('preview').getBoundingClientRect(), k = r.width / 1080;
    return { x: r.left + (b.box.x + b.box.w / 2) * k, y: r.top + (b.box.y + b.box.h / 2) * k, y0: ED.project.captions.style.pos };
  }, L1);
  await page.keyboard.down('Shift');
  await page.mouse.move(dr2.x, dr2.y); await page.mouse.down();
  for (let i = 1; i <= 5; i++) await page.mouse.move(dr2.x, dr2.y + i * 12);
  await page.mouse.up(); await page.keyboard.up('Shift');
  await page.waitForTimeout(80);
  const da = await page.evaluate(() => ED.project.captions.style.pos);
  check('Shift-drag moves every caption (the style position)', !!da && typeof da.y === 'number' && JSON.stringify(da) !== JSON.stringify(dr2.y0), JSON.stringify(da));
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.locator(`.rs-cap-w[data-i="${L1.a + 1}"]`).click();
  await page.locator('.rs-cap-sel [data-ov="line"]').click();
  await page.waitForTimeout(150);
  await shot(page, 'overrides-1920x1080.png');

  // ------------------------------------------------------------------ Music & sound tags setting (undoable, per project)
  await page.evaluate(() => { document.querySelector('.rs-cap-settings').open = true; });
  await page.locator('[data-k="soundTags"]').selectOption('icon');
  await page.waitForTimeout(60);
  const tg = await page.evaluate(() => { const a = ED.project.captions.soundTags; ED.history.undo(); const b = ED.project.captions.soundTags; ED.history.redo(); return [a, b]; });
  check('"Music & sound tags" setting stored per project and undoable', tg[0] === 'icon' && tg[1] === 'hide', JSON.stringify(tg));

  // ------------------------------------------------------------------ template gallery
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.locator('.rs-cap-tabs button[data-tab="style"]').click();
  await page.waitForTimeout(500);
  const g = await page.evaluate(() => ({ n: document.querySelectorAll('.rs-cap-tile').length, cats: [...new Set([...document.querySelectorAll('.rs-cap-tile')].map((t) => t.dataset.cat))] }));
  check(`template gallery: ${g.n} styles (≥ 50) in ${g.cats.length} categories`, g.n >= 50 && ['Viral', 'Minimal', 'Luxury', 'Bold', 'Corporate', 'Arabic', 'Bilingual', 'Fun'].every((c) => g.cats.includes(c)), g.cats.join(', '));
  await page.fill('.rs-cap-search', 'gold');
  await page.waitForTimeout(80);
  const sq = await page.evaluate(() => [...document.querySelectorAll('.rs-cap-tile:not([hidden])')].map((t) => t.dataset.style));
  check(`search "gold" finds the gold templates (${sq.join(', ')})`, sq.length >= 2 && sq.includes('k-gold-serif') && sq.length < g.n / 3);
  await page.fill('.rs-cap-search', '');
  await page.locator('.rs-cap-cats [data-cat="Arabic"]').click();
  await page.waitForTimeout(80);
  const ar = await page.evaluate(() => [...document.querySelectorAll('.rs-cap-tile:not([hidden])')].map((t) => t.dataset.cat));
  check(`category filter "Arabic" (${ar.length} tiles, all Arabic)`, ar.length >= 4 && ar.every((c) => c === 'Arabic'));
  await shot(page, 'gallery-arabic-1280x800.png');
  await page.locator('.rs-cap-cats [data-cat="All"]').click();
  await page.locator('.rs-cap-tile[data-style="k-hormozi"] .rs-cap-fav').click();
  await page.waitForTimeout(150);
  await page.locator('.rs-cap-tile[data-style="k-gold-serif"] .rs-cap-fav').click();
  await page.waitForTimeout(150);
  const fv = await page.evaluate(() => ({ favs: ED.ctx.workspace.captions?.favs, write: (window.__writes || []).filter((w) => w.path === 'workspaces/kbb/workspace.json').pop() }));
  check('favourites per company saved into workspaces/kbb/workspace.json', JSON.stringify(fv.favs) === '["k-hormozi","k-gold-serif"]' && fv.write && JSON.parse(fv.write.data).captions.favs.length === 2, JSON.stringify(fv.favs));
  await page.locator('.rs-cap-cats [data-cat="★ Favourites"]').click();
  await page.waitForTimeout(80);
  const fs2 = await page.evaluate(() => [...document.querySelectorAll('.rs-cap-tile:not([hidden])')].map((t) => t.dataset.style));
  check('Favourites filter shows exactly the starred templates', fs2.length === 2 && fs2.includes('k-hormozi'), fs2.join(','));
  await page.locator('.rs-cap-cats [data-cat="All"]').click();
  await page.locator('.rs-cap-tile[data-style="k-comic"]').click();
  await page.waitForTimeout(80);
  check('picking a template applies it (undoable)', await page.evaluate(() => { const a = ED.project.captions.style.id === 'k-comic'; ED.history.undo(); const b = ED.project.captions.style.id !== 'k-comic'; ED.history.redo(); return a && b; }));
  await page.waitForTimeout(300);
  await shot(page, 'gallery-1280x800.png');
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.waitForTimeout(300);
  await shot(page, 'gallery-1920x1080.png');
  // the live tiles move (animated) — two snapshots of one tile differ
  const live = await page.evaluate(async () => {
    const cv = document.querySelector('.rs-cap-tile[data-style="k-comic"] canvas'); cv.scrollIntoView({ block: 'center' }); await new Promise((r) => setTimeout(r, 300));
    const a = cv.toDataURL(); await new Promise((r) => setTimeout(r, 700)); const b = cv.toDataURL(); await new Promise((r) => setTimeout(r, 700)); const c = cv.toDataURL(); return a !== b || b !== c;   // a sample's tail holds still for ~0.5 s
  });
  check('gallery tiles are live (animated)', live);

  // ------------------------------------------------------------------ every style renders in EN and AR without error
  const all = await page.evaluate(() => {
    const EN = 'Glow up with this serum in just one week'.split(' '), ARW = 'بشرة نضرة مع هذا السيروم في أسبوع'.split(' ');
    const mk = (arr) => arr.map((w, i) => ({ w, t0: 0.3 + i * 0.4, t1: 0.3 + i * 0.4 + 0.36, conf: 1 }));
    const bare = R2.renderCanvas({ canvas: { w: 1080, h: 1920 }, captions: { words: [], lines: [] } }, 0);
    const bd = bare.getContext('2d').getImageData(0, 0, 1080, 1920).data;
    const px = (c) => { const d = c.getContext('2d').getImageData(0, 0, 1080, 1920).data; let n = 0; for (let i = 0; i < d.length; i += 16) if (Math.abs(d[i] - bd[i]) + Math.abs(d[i + 1] - bd[i + 1]) + Math.abs(d[i + 2] - bd[i + 2]) > 30) n++; return n; };
    const out = [];
    const cols = 10, tw = 162, th = 288, sheet = document.createElement('canvas');
    sheet.width = cols * tw; sheet.height = Math.ceil(R2.styles.length * 2 / cols) * (th + 20);
    const sc = sheet.getContext('2d'); sc.fillStyle = '#111'; sc.fillRect(0, 0, sheet.width, sheet.height);
    let k = 0;
    for (const s of R2.styles) {
      const r = { id: s.id };
      for (const lang of ['en', 'ar']) {
        try {
          const words = mk(lang === 'ar' ? ARW : EN);
          const cap = { style: s.id, words, lines: R2.buildLines(words, s.id, { w: 1080, h: 1920 }) };
          if (R2.resolveStyle(s.id).bilingual) { const a = mk(lang === 'ar' ? EN : ARW); cap.alt = { words: a, lines: R2.buildLines(a, s.id, { w: 1080, h: 1920 }) }; }
          const c = R2.renderCanvas({ canvas: { w: 1080, h: 1920 }, captions: cap }, words[2].t0 + 0.15);
          r[lang] = px(c);
          const x = (k % cols) * tw, y = Math.floor(k / cols) * (th + 20); k++;
          sc.drawImage(c, x, y, tw, th); sc.fillStyle = '#eee'; sc.font = '600 11px system-ui'; sc.textAlign = 'center'; sc.fillText(`${s.name} (${lang})`, x + tw / 2, y + th + 14);
        } catch (e) { r[lang] = -1; r.err = String(e.message || e); }
      }
      out.push(r);
    }
    return { out, sheet: sheet.toDataURL('image/jpeg', 0.85) };
  });
  saveUrl(all.sheet, path.join(SHOTS, 'all-styles-en-ar.jpg'));
  results.styles = all.out;
  const bad = all.out.filter((r) => !(r.en > 200 && r.ar > 200));
  check(`every style (${all.out.length}) renders in English and Arabic without error`, all.out.length >= 50 && bad.length === 0, bad.map((r) => `${r.id}:${r.en}/${r.ar}${r.err ? ' ' + r.err : ''}`).join(' '));

  // ------------------------------------------------------------------ header / transcript at both sizes, dropped list
  await page.evaluate(({ enWords }) => {
    const words = enWords.map((w) => ({ ...w, conf: 1 }));
    R2.mountEditor({ words, workspace: { ...R2.SEED_WORKSPACES[1], id: 'planet-careers' }, engine: R2.fakeEngine,
      extra: { soundTags: 'hide', dropped: [{ w: 'Thanks', t0: 40, t1: 40.3, why: 'outro' }, { w: 'for', t0: 40.3, t1: 40.5, why: 'outro' }, { w: 'watching!', t0: 40.5, t1: 41, why: 'outro' }] } });
    document.querySelector('.rs-cap-settings').open = true; document.querySelector('.rs-cap-vocabbox').open = true; document.querySelector('.rs-cap-dropped').open = true;
  }, { enWords });
  await page.evaluate(() => ED.seek(ED.project.captions.words[30]?.t0 + 0.05 || 1));
  await page.waitForTimeout(150);
  await shot(page, 'transcript-1920x1080.png');
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.waitForTimeout(100);
  await shot(page, 'transcript-1280x800.png');
  await page.locator('.rs-cap-dropped button').first().click();
  await page.waitForTimeout(80);
  check('a word the cleaner removed can be put back (undoable)', await page.evaluate(() => ED.project.captions.words.some((w) => w.w === 'Thanks') && ED.project.captions.dropped.length === 2));
  await page.evaluate(() => { document.querySelector('.rs-cap-vocab').value = 'Planet Careers\nChancenkarte\nStipendium Hungaricum'; });
  await page.locator('.rs-cap-vocabbox button').click();
  await page.waitForTimeout(80);
  check('company vocabulary saved into workspace.json', await page.evaluate(() => { const w = (window.__writes || []).filter((x) => x.path === 'workspaces/planet-careers/workspace.json').pop(); return !!w && JSON.parse(w.data).captions.vocab.includes('Chancenkarte'); }));

  const real = errors.filter((e) => !/No available adapters/.test(e));
  check('no console errors', real.length === 0, real.slice(0, 3).join(' | '));
  check('no network except the local studio (models are local)', offsite.length === 0, offsite.slice(0, 3).join(' '));
  results.consoleErrors = real;
  fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASS'} (${results.checks.length} checks)`);
  await browser.close(); server.close();
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });

function synthWords(text) {
  return text.split(' ').map((w, i) => ({ w, t0: +(0.2 + i * 0.38).toFixed(2), t1: +(0.2 + i * 0.38 + 0.33).toFixed(2), conf: 1 }));
}
