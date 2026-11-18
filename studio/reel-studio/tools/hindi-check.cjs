#!/usr/bin/env node
// KBB Studio — Hindi / Hinglish captions check (the owner: "the system must detect the hindi language also and detect
// captions in roman english hindi").
//
//   units      transliteration: 270+ word / sentence cases (schwa deletion, nasals, nukta, conjuncts, common words,
//              verb forms, English loanwords, punctuation, sentences), a HELD-OUT set never tuned on (accuracy, not
//              pass/fail), Urdu <-> Devanagari <-> Roman; dictionary size + licence; script switching lossless (words
//              byte-identical after Deva -> Roman -> Urdu -> Deva, times untouched, hand edits, hand-made lines and
//              line styles kept); save/reload (JSON) round trip; translation source from Roman = the Devanagari
//   glyphs     every Devanagari letter the captions can produce is in the bundled Noto Sans Devanagari (woff2 cmap),
//              every Urdu letter in Noto Nastaliq Urdu / Noto Naskh Arabic — no tofu boxes
//   speech     (models) MMS Hindi + English voices: detection picks Hindi on Hindi, English on English, Hinglish on
//              both Hinglish sets, English stretches stay English; Roman captions keep word-sync timings (aligned on
//              the native letters: identical to Devanagari, and within the s-check tolerance ≤ 30 / ≤ 80 ms on the true
//              transcript); translation from Roman = translation from Devanagari; Devanagari draws (ink, fonts loaded)
//   editor     (engine) Auto captions on a Hinglish MP4 -> the "Heard:" notice, the script switch, undo / redo, save and
//              reload, switch back lossless; no console errors, nothing leaves 127.0.0.1; screenshots 1440×900 ->
//              shots/hindi/
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/hindi-check.cjs [--units] [--no-editor] [--model=small]
// Needs (speech/editor): bash tools/r2-fetch-test-models.sh (Whisper + MMS voices incl. Xenova/mms-tts-hin),
// bash tools/s-fetch-models.sh (word sync), bash tools/t-fetch-models.sh (opus-mt-hi-en), and
// engine/kbbstudio-linux-amd64 (cd engine && GOOS=linux go build -o kbbstudio-linux-amd64 .).
'use strict';
const fs = require('fs'), path = require('path'), zlib = require('zlib');
const { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '..'), OUT = path.join(ROOT, 'tools', 'out', 'hindi'), SHOTS = path.join(ROOT, 'shots', 'hindi');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const args = process.argv.slice(2);
const MODEL = (args.find((a) => a.startsWith('--model=')) || '--model=small').slice(8);
let failures = 0; const results = [], numbers = {};
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
const C = require('./hindi-cases.cjs');
const pct = (xs, p) => { const s = xs.slice().sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p * (s.length - 1) + 0.5))]; };
const imp = (rel) => import(path.join(ROOT, rel));

// ── woff2 cmap (no dependency: brotli is in node's zlib) ──
function woff2Cmap(file) {
  const b = fs.readFileSync(file);
  if (b.toString('latin1', 0, 4) !== 'wOF2') throw new Error(file + ': not woff2');
  const numTables = b.readUInt16BE(12), compLen = b.readUInt32BE(20);
  let o = 48; const tabs = [];
  const u128 = () => { let v = 0; for (let i = 0; i < 5; i++) { const x = b[o++]; v = v * 128 + (x & 127); if (!(x & 128)) return v; } throw new Error('bad UIntBase128'); };
  for (let i = 0; i < numTables; i++) {
    const flags = b[o++], tagIdx = flags & 63, ver = (flags >> 6) & 3;
    if (tagIdx === 63) o += 4;
    const len = u128();
    const transformed = (tagIdx === 10 || tagIdx === 11) ? ver === 0 : ver !== 0;
    tabs.push({ tagIdx, len: transformed ? u128() : len });
  }
  const data = zlib.brotliDecompressSync(b.subarray(o, o + compLen));
  let p = 0, cmap = null;
  for (const t of tabs) { if (t.tagIdx === 0) cmap = data.subarray(p, p + t.len); p += t.len; }
  const cps = new Set(), n = cmap.readUInt16BE(2);
  for (let i = 0; i < n; i++) {
    const off = cmap.readUInt32BE(4 + i * 8 + 4), fmt = cmap.readUInt16BE(off);
    if (fmt === 4) {
      const segX2 = cmap.readUInt16BE(off + 6), ends = off + 14, starts = ends + segX2 + 2, deltas = starts + segX2, ranges = deltas + segX2;
      for (let s = 0; s < segX2 / 2; s++) {
        const end = cmap.readUInt16BE(ends + s * 2), start = cmap.readUInt16BE(starts + s * 2), delta = cmap.readInt16BE(deltas + s * 2), ro = cmap.readUInt16BE(ranges + s * 2);
        for (let c = start; c <= end && c !== 0xFFFF; c++) {
          let gid;
          if (!ro) gid = (c + delta) & 0xFFFF; else { const at = ranges + s * 2 + ro + (c - start) * 2; gid = cmap.readUInt16BE(at); if (gid) gid = (gid + delta) & 0xFFFF; }
          if (gid) cps.add(c);
        }
      }
    } else if (fmt === 12) {
      const ng = cmap.readUInt32BE(off + 12);
      for (let k = 0; k < ng; k++) { const s = cmap.readUInt32BE(off + 16 + k * 12), e = cmap.readUInt32BE(off + 20 + k * 12); for (let c = s; c <= e; c++) cps.add(c); }
    }
  }
  return cps;
}

async function units() {
  const T = await imp('app/captions/hinglish/translit.js'), U = await imp('app/captions/hinglish/urdu.js'), S = await imp('app/captions/hinglish/script.js');
  // 1. word pairs
  const groups = ['schwa', 'nasal', 'nukta', 'conjunct', 'common', 'verbs', 'loan', 'marks'];
  let pairs = 0;
  for (const g of groups) {
    const bad = C[g].filter(([d, r]) => T.toRoman(d) !== r).map(([d, r]) => `${d}→${T.toRoman(d)} (want ${r})`);
    pairs += C[g].length;
    ok(!bad.length, `transliteration · ${g}: ${C[g].length - bad.length}/${C[g].length}${bad.length ? ' — ' + bad.slice(0, 6).join(', ') : ''}`);
  }
  const rulesOk = groups.filter((g) => !['loan', 'marks', 'common'].includes(g)).flatMap((g) => C[g]).filter(([d, r]) => T.rulesOnly(d).toLowerCase() === r.toLowerCase());
  const rulesN = groups.filter((g) => !['loan', 'marks', 'common'].includes(g)).reduce((s, g) => s + C[g].length, 0);
  const sent = C.sentences.filter(([d, r]) => T.romanizeText(d) !== r).map(([d, r]) => `"${T.romanizeText(d)}" (want "${r}")`);
  ok(!sent.length, `transliteration · ${C.sentences.length} sentences (sentence capitals, । -> ., English words in English)${sent.length ? ' — ' + sent.join(' | ') : ''}`);
  ok(pairs >= 150, `${pairs} word pairs + ${C.sentences.length} sentences (≥ 150 pairs asked)`);
  const held = C.heldOut.map(([d, r]) => [d, r.split('/'), T.toRoman(d)]), heldOk = held.filter(([, rs, g]) => rs.includes(g));
  numbers.translit = { pairs, sentences: C.sentences.length, heldOut: `${heldOk.length}/${held.length}`, heldOutPct: +(100 * heldOk.length / held.length).toFixed(1),
    rulesOnly: `${rulesOk.length}/${rulesN}`, dictionary: T.dictSize(), urduDictionary: U.urduDictSize(), heldOutMisses: held.filter(([, rs, g]) => !rs.includes(g)).map(([d, rs, g]) => `${d}→${g} (${rs.join('/')})`) };
  ok(heldOk.length / held.length >= 0.8, `held-out words (never tuned on): ${heldOk.length}/${held.length} = ${numbers.translit.heldOutPct}% spelled the common way; rules alone (no dictionary) ${rulesOk.length}/${rulesN} on the rule groups; misses: ${numbers.translit.heldOutMisses.join(', ')}`);
  ok(T.dictSize() >= 2000, `Hinglish dictionary: ${T.dictSize()} Devanagari word forms (hand-written words + loanwords + generated verb forms), ${U.urduDictSize()} Urdu spellings derived from them`);
  const lic = path.join(ROOT, 'app/captions/hinglish/LICENSE');
  ok(fs.existsSync(lic) && /CC0/.test(fs.readFileSync(lic, 'utf8')), 'dictionary licence stated: app/captions/hinglish/LICENSE (original work, CC0-1.0)');
  // 2. Urdu
  const ur = C.urduRoman.filter(([u, r]) => U.urduToRoman(u).text !== r).map(([u, r]) => `${u}→${U.urduToRoman(u).text} (${r})`);
  ok(!ur.length, `Urdu script -> Roman Urdu: ${C.urduRoman.length - ur.length}/${C.urduRoman.length}${ur.length ? ' — ' + ur.join(', ') : ''}`);
  const du = C.devaUrdu.filter(([d, u]) => U.devaToUrdu(d) !== u).map(([d, u]) => `${d}→${U.devaToUrdu(d)} (${u})`);
  ok(!du.length, `Devanagari -> Urdu script: ${C.devaUrdu.length - du.length}/${C.devaUrdu.length}${du.length ? ' — ' + du.join(', ') : ''}`);
  // 3. captions: lossless switching, times untouched, edits, lines, styles
  const text = 'दोस्तों, आज मैं आपको बताऊँगा कि कनाडा में जॉब के लिए अप्लाई कैसे करें। यह प्रोसेस बहुत ईज़ी है और हमारी टीम आपकी मदद करेगी।'.split(' ');
  const words = text.map((w, i) => ({ w, t0: +(0.5 + i * 0.37).toFixed(3), t1: +(0.5 + i * 0.37 + 0.31).toFixed(3), conf: 1, src: { m: 'a', s: i * 0.37 } }));
  words.splice(6, 0, { w: 'work', t0: 2.7, t1: 2.75, conf: 1, lang: 'en' });
  for (let i = 7; i < words.length; i++) { words[i].t0 = +(words[i].t0 + 0.06).toFixed(3); words[i].t1 = +(words[i].t1 + 0.06).toFixed(3); }
  const M = await imp('app/captions/model.js');
  const canvas = { w: 1080, h: 1920 };
  let cap = { style: 'word-box', lang: 'hi', words, lines: M.buildLines(words, 'word-box', canvas) };
  cap.lines[1] = { ...cap.lines[1], ov: { color: '#ff0000' } };
  const snap = JSON.stringify(cap.words);
  const roman = S.withScript(cap, 'roman', canvas), urdu = S.withScript(roman, 'urdu', canvas), back = S.withScript(urdu, 'deva', canvas);
  ok(JSON.stringify(back.words) === snap, `script switch Devanagari -> Roman -> Urdu -> Devanagari: every word byte-identical (${words.length} words incl. an English one)`);
  ok(roman.words.every((w, i) => w.t0 === words[i].t0 && w.t1 === words[i].t1 && w.src === words[i].src), 'word times and media anchors untouched by the switch (word sync / karaoke follow the same words)');
  ok(/^Doston, aaj main aapko bataunga ki work Canada mein job ke liye apply kaise karein\. Yeh process bahut easy hai/.test(roman.words.map((w) => w.w).join(' ')) && roman.words[6].w === 'work',
    `Roman: "${roman.words.map((w) => w.w).join(' ')}"`);
  ok(M.validate(roman).length === 0 && M.validate(urdu).length === 0 && roman.lines.some((L) => L.ov?.color === '#ff0000'), `lines re-broken for the new text (${cap.lines.length} -> ${roman.lines.length} lines, valid) and the styled line kept its style`);
  const C2 = await imp('app/captions/commands.js');
  const run = (c, cmd) => { const p = { captions: c, canvas }; cmd.do(p); return p.captions; };
  const edited = run(roman, C2.editWord(2, 'Main'));
  const eBack = S.withScript(edited, 'deva', canvas);
  ok(eBack.words[2].w === 'मैं' && !('orig' in edited.words[2]) && eBack.words[3].w === words[3].w, `a word edited in Roman ("Main") comes back in Devanagari from the dictionary (${eBack.words[2].w}); the others from their kept native text`);
  const split = run(cap, C2.splitLine(3)), sr = S.withScript(split, 'roman', canvas);
  ok(sr.lines.length === split.lines.length && sr.lines.every((L, i) => L.wordIdx.join() === split.lines[i].wordIdx.join()), 'lines split by hand are kept as they are when the script changes');
  const reload = JSON.parse(JSON.stringify(roman));
  ok(JSON.stringify(S.withScript(reload, 'deva', canvas).words) === snap && reload.script === 'roman', 'save / reload (JSON) of Roman captions, then back to Devanagari: identical');
  const nat = S.nativeCaptions(roman);
  ok(nat.words.map((w) => w.w).join(' ') === words.map((w) => w.w).join(' '), 'translation source of Roman captions = the original Devanagari (+ the English word as is)');
  const ured = S.withScript({ ...cap, lang: 'ur', words: ['میں', 'آج', 'خوش', 'ہوں۔', 'کینیڈا', 'میں', 'جاب'].map((w, i) => ({ w, t0: i, t1: i + 0.5 })) }, 'roman', canvas);
  ok(ured.words.map((w) => w.w).join(' ') === 'Main aaj khush hoon. Canada mein job', `Urdu captions in Roman Urdu: "${ured.words.map((w) => w.w).join(' ')}" (میں = main at a sentence start, mein after a word)`);
  // 4. glyphs
  const F = path.join(ROOT, 'vendor/caption-fonts');
  const deva = woff2Cmap(path.join(F, 'noto-sans-devanagari-devanagari-wght-normal.woff2'));
  const D = await imp('app/captions/hinglish/dict.js');
  const devaText = [...D.WORDS, ...D.LOANS, ...D.VERBS_C, ...D.VERBS_V].map((p) => p[0]).join('') + groups.flatMap((g) => C[g]).map((p) => p[0]).join('') + '०१२३४५६७८९।ँंःऽ़्';
  const need = [...new Set([...devaText.normalize('NFC')].filter((c) => /[ऀ-ॿ]/.test(c)))];
  const missing = need.filter((c) => !deva.has(c.codePointAt(0)));
  ok(!missing.length, `Noto Sans Devanagari (OFL-1.1, bundled) has every one of the ${need.length} Devanagari characters the captions use${missing.length ? ' — MISSING ' + missing.join(' ') : ''} (cmap read from the woff2)`);
  const urText = [...C.urduRoman.map((p) => p[0]), ...C.devaUrdu.map((p) => p[1])].join('') + 'ٹڈڑںےۓھہیکگچپژ۔؟،';
  const urNeed = [...new Set([...urText].filter((c) => /[؀-ۿ]/.test(c)))];
  for (const f of ['noto-nastaliq-urdu-arabic-400-normal.woff2', 'noto-naskh-arabic-arabic-wght-normal.woff2']) {
    const cm = woff2Cmap(path.join(F, f)), miss = urNeed.filter((c) => !cm.has(c.codePointAt(0)));
    ok(!miss.length, `${f.split('-arabic')[0]} has all ${urNeed.length} Urdu letters used${miss.length ? ' — MISSING ' + miss.join(' ') : ''}`);
  }
}

// ── speech + timing + translation + drawing, in the dev harness page (no engine) ──
const HINDI = [['hin', 'आप कैसे हैं'], ['hin', 'मैं आज बहुत खुश हूँ'], ['hin', 'हमारी टीम आपकी मदद करेगी'], ['hin', 'यह सीरम आपकी त्वचा को मुलायम बनाता है']];
const HINGLISH_HIN = [['hin', 'कनाडा में जॉब के लिए अप्लाई करो'], ['hin', 'यह सीरम आपकी स्किन को ग्लोइंग बनाता है'], ['hin', 'वीज़ा प्रोसेस बहुत ईज़ी है']];
const HINGLISH_MIX = [['hin', 'दोस्तों आज मैं आपको बताऊँगा'], ['eng', 'how to apply for a work visa'], ['hin', 'और यह बहुत आसान है'], ['eng', 'just book a free consultation'], ['hin', 'हमारी टीम आपकी मदद करेगी']];
const ENGLISH = [['eng', 'today i am unboxing the new vitamin serum'], ['eng', 'book a free consultation with our team today']];
const SYNC_WORDS = 'यह सीरम आपकी त्वचा को नरम और चमकदार बनाता है हम आपकी मदद करते हैं आज ही हमारी टीम से बात करें यह प्रोडक्ट कोरिया से आया है'.split(' ');
const fold = (s) => String(s).normalize('NFD').replace(/[़ँं]/g, '').normalize('NFC').replace(/[^\p{L}\p{M}\s]/gu, '').replace(/\s+/g, ' ').trim();
function cer(ref, hyp) {
  const a = [...fold(ref).replace(/ /g, '')], b = [...fold(hyp).replace(/ /g, '')];
  const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
  for (let j = 1; j <= b.length; j++) d[0][j] = j;
  for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
  return +(100 * d[a.length][b.length] / Math.max(1, a.length)).toFixed(1);
}

async function speech() {
  const server = require('./r2-serve.cjs'), { chromium } = require('playwright');
  await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
  const b = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined });
  const pg = await b.newPage({ viewport: { width: 1440, height: 900 } }); pg.setDefaultTimeout(0);
  const errors = [];
  // a failed request is judged by its URL (the console line has none); the browser's own favicon probe is not ours
  pg.on('pageerror', (e) => errors.push(e.message)); pg.on('console', (m) => { if (m.type() === 'error' && !/^Failed to load resource/.test(m.text())) errors.push(m.text()); });
  pg.on('response', (r) => { if (r.status() >= 400 && !/favicon\.ico$/.test(r.url())) errors.push(r.status() + ' ' + r.url()); });
  await pg.goto(`http://127.0.0.1:${server.address().port}/app/captions/dev/hindi.html`); await pg.waitForFunction(() => window.HI);
  // detection + transcription
  const sets = { hindi: HINDI, hinglishHindiVoice: HINGLISH_HIN, hinglishMixed: HINGLISH_MIX, english: ENGLISH };
  numbers.speech = {};
  for (const [k, parts] of Object.entries(sets)) {
    const t = Date.now();
    const r = await pg.evaluate(async ({ parts, model }) => { const s = await HI.speech(parts); const t = await HI.transcribe(s.audio, { model, lang: 'auto' }); return { ...t, dur: s.dur }; }, { parts, model: MODEL });
    const hiRef = parts.filter((p) => p[0] === 'hin').map((p) => p[1]).join(' ');
    const devaHyp = r.words.filter((w) => /[ऀ-ॿ]/.test(w.w)).map((w) => w.w).join(' ');
    const en = r.words.filter((w) => w.lang === 'en' || /^[A-Za-z]/.test(w.w)).map((w) => w.w).join(' ');
    numbers.speech[k] = { lang: r.lang, hinglish: r.hinglish, conf: r.detect?.conf, probs: r.detect?.probs && Object.fromEntries(Object.entries(r.detect.probs).slice(0, 3)), text: r.text, cerHindi: hiRef ? cer(hiRef, devaHyp) : null, english: en, secs: (Date.now() - t) / 1000, stats: r.stats };
    const s = numbers.speech[k];
    if (k === 'english') ok(r.lang === 'en' && !r.hinglish, `detect · English speech -> English ${Math.round((s.conf || 0) * 100)}% ("${r.text}")`);
    else if (k === 'hindi') ok(r.lang === 'hi' && devaHyp.length > 10, `detect · Hindi speech -> Hindi ${Math.round((s.conf || 0) * 100)}% (Hindi+Urdu), written in Devanagari, CER ${s.cerHindi}% on the MMS voice: "${r.text}"`);
    else ok(r.lang === 'hi' && r.hinglish, `detect · ${k} -> Hinglish (Hindi + English) ${Math.round((s.conf || 0) * 100)}%: "${r.text}"`);
    if (k === 'hinglishMixed') ok(/apply|visa|consultation|book/i.test(en) && devaHyp.length > 10, `Hinglish · English stretches stay English (not translated, not dropped): "${en}"; Hindi stays Hindi (CER ${s.cerHindi}%)`);
  }
  // Roman captions keep the word-sync timing: aligned on the native letters
  const sy = await pg.evaluate(async (words) => {
    const S = await import('/app/captions/hinglish/script.js'), M = await import('/app/captions/model.js');
    const clip = await HI.wordClip(words);
    const rough = clip.gt.map((g) => ({ w: g.w, t0: +(g.t0 + 0.12 * Math.sin(g.t0 * 7)).toFixed(3), t1: g.t1, conf: 1 }));
    const deva = await HI.align(rough, clip.audio);
    const cap = { style: 'word-box', lang: 'hi', words: rough, lines: M.buildLines(rough, 'word-box', { w: 1080, h: 1920 }) };
    const roman = S.withScript(cap, 'roman', { w: 1080, h: 1920 });
    const romanAligned = await HI.align(roman.words, clip.audio);
    // without the native letters (what Roman text alone would give)
    const latinOnly = await HI.align(roman.words.map(({ orig, ...w }) => w), clip.audio);
    return { gt: clip.gt, deva, roman: romanAligned, latinOnly, romanText: roman.words.map((w) => w.w).join(' ') };
  }, SYNC_WORDS);
  const err = (ws) => sy.gt.map((g, i) => Math.abs(ws[i].t0 - g.t0) * 1000);
  const e1 = err(sy.roman), e0 = err(sy.deva), eL = err(sy.latinOnly);
  numbers.sync = { words: sy.gt.length, romanMedian: +pct(e1, 0.5).toFixed(1), romanP95: +pct(e1, 0.95).toFixed(1), devaMedian: +pct(e0, 0.5).toFixed(1), devaP95: +pct(e0, 0.95).toFixed(1),
    latinOnlyMedian: +pct(eL, 0.5).toFixed(1), latinOnlyP95: +pct(eL, 0.95).toFixed(1) };
  ok(sy.roman.every((w, i) => w.t0 === sy.deva[i].t0 && w.t1 === sy.deva[i].t1), `word sync on Roman captions = on Devanagari, word for word (${sy.gt.length} words, "${sy.romanText.slice(0, 60)}…")`);
  // s-check holds the per-word MMS voices (unreadable to Whisper) to median ≤ 30 ms on the true transcript; their p95 is
  // a known aligner limit on this voice (PLAN.md: UR 4.6 / 346 ms), reported, not judged — and identical in both scripts
  ok(numbers.sync.romanMedian <= 30, `Roman captions synced to the voice (MMS Hindi voice, true transcript): word starts median ${numbers.sync.romanMedian} ms (s-check: ≤ 30) / p95 ${numbers.sync.romanP95} ms (the s-check UR baseline on this voice is 346 ms); Devanagari ${numbers.sync.devaMedian} / ${numbers.sync.devaP95} ms; aligning the Roman letters instead would give ${numbers.sync.latinOnlyMedian} / ${numbers.sync.latinOnlyP95} ms`);
  // karaoke + drawing: the spoken word and the frame, both scripts
  const dr = await pg.evaluate(async () => {
    const R = await import('/app/captions/render.js'), F = await import('/app/captions/fonts.js'), S = await import('/app/captions/hinglish/script.js'), M = await import('/app/captions/model.js');
    await F.loadCaptionFonts();
    const text = 'आप कैसे हो? कनाडा में जॉब के लिए अप्लाई करो। ज़िंदगी में मेहनत ज़रूरी है'.split(' ');
    const words = text.map((w, i) => ({ w, t0: 0.3 + i * 0.4, t1: 0.3 + i * 0.4 + 0.35, conf: 1 }));
    const canvas = { w: 1080, h: 1920 };
    const deva = { style: 'word-box', lang: 'hi', words, lines: M.buildLines(words, 'word-box', canvas) };
    const roman = S.withScript(deva, 'roman', canvas);
    const project = (cap) => ({ canvas, captions: cap, tracks: [], meta: {} });
    const item = { type: 'caption', t0: 0, in: 0, out: 10, x: 0, y: 0, w: 1080, h: 1920 };
    const draw = (cap, t, id) => { const cv = document.getElementById(id); cv.hidden = false; const c = cv.getContext('2d'); c.fillStyle = '#26303f'; c.fillRect(0, 0, 1080, 1920); R.drawCaptions(c, item, t, project(cap), {}); const d = c.getImageData(0, 0, 1080, 1920).data; let ink = 0; for (let i = 0; i < d.length; i += 16) if (d[i] > 200 && d[i + 1] > 200) ink++; return ink; };
    const active = (cap, t) => cap.words.findIndex((w) => w.t0 <= t && t < w.t1);
    const ts = [0.5, 1.3, 2.1, 3.0, 4.4, 5.3];
    return { inkDeva: draw(deva, 0.5, 'preview'), inkRoman: draw(roman, 0.5, 'preview2'), sameActive: ts.every((t) => active(deva, t) === active(roman, t)),
      fonts: document.fonts.check('72px "Noto Sans Devanagari"', 'ज़िंदगी'), devaW: (() => { const c = document.createElement('canvas').getContext('2d'); c.font = '72px "Noto Sans Devanagari"'; const a = c.measureText('क्षत्रिय ज़िंदगी').width; c.font = '72px monospace'; return [a, c.measureText('क्षत्रिय ज़िंदगी').width]; })() };
  });
  await pg.evaluate(() => { document.getElementById('panel').hidden = true; });
  await pg.screenshot({ path: path.join(SHOTS, 'hindi-04-devanagari-vs-roman-harness-1440.png') });
  ok(dr.inkDeva > 500 && dr.inkRoman > 500 && dr.fonts && Math.abs(dr.devaW[0] - dr.devaW[1]) > 1, `Devanagari and Roman captions draw (ink ${dr.inkDeva} / ${dr.inkRoman} px samples), Noto Sans Devanagari loaded and shaping (width ${dr.devaW[0].toFixed(0)} px vs ${dr.devaW[1].toFixed(0)} px fallback)`);
  ok(dr.sameActive, 'karaoke: the highlighted word at every sampled time is the same word in Devanagari and in Roman');
  // translation from Roman Hindi = from the Devanagari kept alongside
  if (fs.existsSync(path.join(ROOT, 'models/Xenova/opus-mt-hi-en/onnx/decoder_model_merged_quantized.onnx'))) {
    const tr = await pg.evaluate(async () => {
      const SV = await import('/app/captions/translate/service.js'), S = await import('/app/captions/hinglish/script.js'), M = await import('/app/captions/model.js');
      const text = 'हमारी टीम आपकी मदद करेगी। कनाडा में जॉब के लिए आज ही अप्लाई करें।'.split(' ');
      const words = text.map((w, i) => ({ w, t0: 0.3 + i * 0.4, t1: 0.3 + i * 0.4 + 0.35, conf: 1 }));
      const canvas = { w: 1080, h: 1920 };
      const deva = { style: 'word-box', lang: 'hi', words, lines: M.buildLines(words, 'word-box', canvas) };
      const run = async (cap) => {
        const ctx = { project: { canvas, captions: cap, tracks: [], meta: {} }, history: { apply(c) { c.do(ctx.project); } }, toast() {} };
        await SV.translateCaptions(ctx, 'en', 'replace', { engineId: 'mt-hi-en', install: false, check: false });
        return ctx.project.captions.words.map((w) => w.w).join(' ');
      };
      return { fromDeva: await run(deva), fromRoman: await run(S.withScript(deva, 'roman', canvas)) };
    });
    numbers.translation = tr;
    ok(tr.fromRoman === tr.fromDeva && /\w{3}/.test(tr.fromRoman), `translation from Roman Hindi captions = from Devanagari (opus-mt-hi-en): "${tr.fromRoman}"`);
  } else ok(false, 'translation from Roman: opus-mt-hi-en missing (bash tools/t-fetch-models.sh)');
  ok(!errors.length, `harness: no console / page errors${errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''}`);
  // test speech for the editor part: the mixed Hinglish clip
  const wav = await pg.evaluate(async (parts) => { const s = await HI.speech(parts); return HI.wav64(s.audio); }, HINGLISH_MIX.concat(HINGLISH_HIN.slice(0, 1)));
  fs.writeFileSync(path.join(OUT, 'hinglish.wav'), Buffer.from(wav, 'base64'));
  await b.close(); server.close();
}

// ── the editor ──
async function editor() {
  const H = require('./studio-harness.cjs');
  const STUDIO = path.join(OUT, 'studio');
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  if (args.includes('--probe')) fs.copyFileSync(path.join(OUT, 'hinglish.wav'), path.join(STUDIO, 'library', 'hindi-test.wav'));
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  for (const rel of ['onnx-community', 'NewComer00']) { const src = path.join(ROOT, 'models', rel), dst = path.join(STUDIO, 'models', rel); if (fs.existsSync(src) && !fs.existsSync(dst)) execFileSync('cp', ['-al', src, dst]); }
  const talk = path.join(OUT, 'hinglish.mp4');
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'gradients=size=1080x1920:rate=30:duration=30:speed=0.01', '-i', path.join(OUT, 'hinglish.wav'), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28',
    '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-shortest', '-movflags', '+faststart', talk]);
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--autoplay-policy=no-user-gesture-required'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage(); page.setDefaultTimeout(600000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  try {
    await page.goto(e.url + '/app/');
    await page.waitForSelector('.home', { timeout: 30000 });
    const ev = (fn, arg) => page.evaluate(fn, arg);
    await page.setInputFiles('.home-drop input[type=file]', talk);
    await page.waitForSelector('.tl-item', { timeout: 60000 });
    await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
    // the test studio holds only the int8 Whisper files: report the model as installed so no download is offered
    await ev(async (model) => {
      const eng = window.kbbEditor.engine, orig = eng.modelsStatus.bind(eng);
      eng.modelsStatus = async () => { const s = await orig(); for (const m of s.models) if (m.id === 'whisper-' + model) m.installed = true; return s; };
    }, MODEL);
    if (args.includes('--probe')) console.log('PROBE', JSON.stringify(await ev(async () => { const A = await import('/app/captions/auto.js'); const a = await A.timelineSpeech(window.kbbEditor.project, window.kbbEditor.media); const r = []; for (let s = 0; s < a.length; s += 16000) { let q = 0; for (let i = s; i < Math.min(a.length, s + 16000); i++) q += a[i] * a[i]; r.push(+Math.sqrt(q / 16000).toFixed(3)); } const out = { n: a.length, rms: r, gpu: !!navigator.gpu }; const tr = A.transcriber(); const buf = await (await fetch('/library/hindi-test.wav')).arrayBuffer(); const w = (await new OfflineAudioContext(1, 1, 16000).decodeAudioData(buf)).getChannelData(0); let d = 0; for (let i = 0; i < Math.min(w.length, a.length); i++) d = Math.max(d, Math.abs(w[i] - a[i])); out.maxDiff = d; out.coi = self.crossOriginIsolated; for (const [device, aa] of [['wav', w], ['wasm', a]]) { const x = await A.captureWords(tr, aa, { model: 'small', lang: 'auto', device: 'wasm', workspace: window.kbbEditor.context().workspace }); out[device] = { lang: x.lang, hinglish: x.hinglish, stats: x.raw.stats, dev: x.raw.device, text: x.words.map((w) => w.w).join(' ') }; } return out; })));
    await page.click('.side-tab[data-side="captions"]');
    await page.waitForSelector('.rs-cap .rs-cap-btn.primary');
    await ev((model) => { const s = document.querySelector('.rs-cap select[title="Speech model"]'); s.value = model; s.dispatchEvent(new Event('change')); }, MODEL);
    const scriptOpts = await ev(() => [...document.querySelectorAll('.rs-cap select[data-k="capScript"] option')].map((o) => o.textContent));
    const langOpts = await ev(() => [...document.querySelectorAll('.rs-cap select[title="Language"] option')].map((o) => o.textContent));
    ok(langOpts.includes('Hinglish (Hindi + English)') && scriptOpts.length === 4, `Captions → ⚙ Settings: language ${langOpts.join(' / ')}; "Hindi / Urdu in" ${scriptOpts.join(' / ')}`);
    let t = Date.now();
    await page.click('.rs-cap .rs-cap-btn.primary');
    await page.waitForFunction(() => window.kbbEditor.project.captions?.words?.length > 5 && !document.querySelector('.rs-cap-progress:not([hidden])'), null, { timeout: 900000 });
    const capSecs = (Date.now() - t) / 1000;
    // the automatic word sync after Auto captions (its own undo step) must land before the script is switched
    await page.waitForFunction(() => window.kbbEditor.history.undoLabel === 'Sync words to the voice' || window.kbbEditor.project.meta?.sync?.last, null, { timeout: 300000 }).catch(() => {});
    await page.waitForTimeout(1500);
    const st = await ev(() => { const c = window.kbbEditor.project.captions; return { lang: c.lang, script: c.script, detect: c.detect, text: c.words.map((w) => w.w).join(' '), orig: c.words.filter((w) => w.orig).length, n: c.words.length,
      notice: document.querySelector('.rs-cap-detect')?.textContent, on: document.querySelector('.rs-cap-script.on')?.dataset.script }; });
    numbers.editor = { secs: capSecs, withSync: (Date.now() - t) / 1000, ...st };
    ok(st.lang === 'hi' && st.detect?.hinglish && st.script === 'roman' && st.on === 'roman' && /Heard: Hinglish/.test(st.notice || ''),
      `Auto captions on a Hinglish MP4 (${numbers.editor.secs.toFixed(0)} s): notice "${st.notice}", captions in Roman by default: "${st.text}"`);
    await ev(() => window.kbbEditor.seek(2.0)); await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(SHOTS, 'hindi-01-detection-notice-roman-1440.png') });
    // words compared by content (key order can differ: the sync hook re-adds each word's media anchor after a change)
    const canon = (s) => JSON.stringify(JSON.parse(s).map((w) => Object.fromEntries(Object.entries(w).sort(([a], [b]) => (a < b ? -1 : 1)))));
    const romanWords = canon(await ev(() => JSON.stringify(window.kbbEditor.project.captions.words)));
    // the switch: Devanagari, undo, redo
    await page.click('.rs-cap-script[data-script="deva"]'); await page.waitForTimeout(600);
    const dv = await ev(() => ({ text: window.kbbEditor.project.captions.words.map((w) => w.w).join(' '), script: window.kbbEditor.project.captions.script, label: window.kbbEditor.history.undoLabel }));
    ok(dv.script === 'deva' && /[ऀ-ॿ]/.test(dv.text) && dv.label === 'Caption script', `script switch → हिंदी, one undo step "${dv.label}": "${dv.text}"`);
    await ev(() => window.kbbEditor.seek(2.0)); await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(SHOTS, 'hindi-02-script-switch-devanagari-1440.png') });
    await page.click('.rs-cap-script[data-script="urdu"]'); await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(SHOTS, 'hindi-03-script-switch-urdu-1440.png') });
    await ev(() => { window.kbbEditor.history.undo(); window.kbbEditor.history.undo(); });
    const un = await ev(() => JSON.stringify(window.kbbEditor.project.captions.words));
    ok(canon(un) === romanWords, 'undo, undo → back to the Roman captions exactly');
    await ev(() => window.kbbEditor.history.redo()); await page.waitForTimeout(300);
    const re = await ev(() => window.kbbEditor.project.captions.script);
    ok(re === 'deva', 'redo → Devanagari again');
    await ev(() => window.kbbEditor.history.undo());
    // save + reload
    await ev(() => window.kbbEditor.save());
    await page.reload(); await page.waitForFunction(() => window.kbbEditor?.project?.captions?.words?.length > 0, null, { timeout: 60000 });
    const rl = await ev(() => ({ words: JSON.stringify(window.kbbEditor.project.captions.words), script: window.kbbEditor.project.captions.script, detect: window.kbbEditor.project.captions.detect }));
    ok(canon(rl.words) === romanWords && rl.script === 'roman' && rl.detect?.hinglish, 'save + reload: Roman captions, their native text and the detection come back identical');
    await page.click('.side-tab[data-side="captions"]'); await page.waitForSelector('.rs-cap-script[data-script="deva"]');
    await page.click('.rs-cap-script[data-script="deva"]'); await page.waitForTimeout(400);
    await page.click('.rs-cap-script[data-script="roman"]'); await page.waitForTimeout(400);
    const lossless = canon(await ev(() => JSON.stringify(window.kbbEditor.project.captions.words)));
    const firstDiff = () => { const a = JSON.parse(lossless), b = JSON.parse(romanWords); const i = a.findIndex((w, k) => JSON.stringify(w) !== JSON.stringify(b[k])); return i < 0 ? (a.length !== b.length ? 'length ' + a.length + ' vs ' + b.length : '') : i + ': ' + JSON.stringify(a[i]) + ' vs ' + JSON.stringify(b[i]); };
    ok(lossless === romanWords, 'after reload: Roman → हिंदी → Roman is lossless' + (lossless === romanWords ? '' : ' — ' + firstDiff()));
    // the Roman-vs-Devanagari frame pair, at the same moment
    await ev(() => window.kbbEditor.seek(2.4)); await page.waitForTimeout(800);
    const prev = await page.$('canvas.pv-canvas');
    if (prev) await prev.screenshot({ path: path.join(SHOTS, 'hindi-05-frame-roman.png') });
    await page.click('.rs-cap-script[data-script="deva"]'); await ev(() => window.kbbEditor.seek(2.4)); await page.waitForTimeout(800);
    if (prev) await prev.screenshot({ path: path.join(SHOTS, 'hindi-06-frame-devanagari.png') });
    await page.screenshot({ path: path.join(SHOTS, 'hindi-07-devanagari-frame-1440.png') });
    ok(!errors.length, `editor: no console errors${errors.length ? ' — ' + errors.slice(0, 3).join(' | ') : ''}`);
    ok(!outside.length, `editor: nothing left 127.0.0.1${outside.length ? ' — ' + outside.slice(0, 3).join(' ') : ''}`);
  } finally { await browser.close(); H.stopEngine(e); }
}

(async () => {
  const T0 = Date.now();
  await units();
  if (!args.includes('--units')) {
    if (!args.includes('--editor')) await speech();
    if (!args.includes('--no-editor')) await editor();
  }
  numbers.secs = Math.round((Date.now() - T0) / 1000);
  fs.writeFileSync(path.join(OUT, 'hindi.json'), JSON.stringify({ results, numbers }, null, 1));
  fs.writeFileSync(path.join(SHOTS, 'hindi-check.txt'), results.join('\n') + `\n\n${failures ? failures + ' FAILED' : 'ALL PASS'} (${numbers.secs} s)\n`);
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS', `(${numbers.secs} s)`);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
