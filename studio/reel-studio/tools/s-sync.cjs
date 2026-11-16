// Lane S — word-onset accuracy on clips with KNOWN word times (EN flite + EN/AR/UR MMS voices), before (Whisper only)
// and after (forced alignment + onset snapping). Used by tools/s-check.cjs; runnable alone:
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/s-sync.cjs [--langs=en,ar,ur] [--whisper=base]
// Needs: bash tools/s-fetch-models.sh (alignment model) + the Whisper/MMS test models (tools/r2-fetch-test-models.sh,
// plus Xenova/mms-tts-hin for the Hindustani voice). Writes tools/out/s/sync.json.
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'tools/out/s');
fs.mkdirSync(path.join(OUT, 'flite'), { recursive: true });

const EN = ('Today I am unboxing the new vitamin C serum from Korea. After one week my skin looks brighter and more hydrated. ' +
  'Book a free consultation with our team today. We will guide you through every step of the visa process. ' +
  'This sunscreen is light, it never leaves a white cast, and it costs only forty nine dirhams.').split(/\s+/);
const AR = 'هذا السيروم الجديد من كوريا يجعل بشرتك ناعمة ومشرقة. احجز استشارة مجانية مع فريقنا اليوم. نحن نساعدك في كل خطوة من خطوات التأشيرة. السعر فقط تسعة وأربعون درهما.'.split(/\s+/);
// Hindustani: voiced from Devanagari (MMS Hindi voice), captioned in Urdu script — the same spoken words.
const UR_PAIRS = [
  ['یہ', 'यह'], ['سیرم', 'सीरम'], ['آپ', 'आप'], ['کی', 'की'], ['جلد', 'जिल्द'], ['کو', 'को'], ['نرم', 'नर्म'], ['اور', 'और'], ['چمکدار', 'चमकदार'], ['بناتا', 'बनाता'], ['ہے۔', 'है'],
  ['ہم', 'हम'], ['آپ', 'आप'], ['کی', 'की'], ['مدد', 'मदद'], ['کرتے', 'करते'], ['ہیں۔', 'हैं'],
  ['آج', 'आज'], ['ہی', 'ही'], ['ہماری', 'हमारी'], ['ٹیم', 'टीम'], ['سے', 'से'], ['بات', 'बात'], ['کریں۔', 'करें'],
  ['یہ', 'यह'], ['پروڈکٹ', 'प्रोडक्ट'], ['کوریا', 'कोरिया'], ['سے', 'से'], ['آئی', 'आई'], ['ہے۔', 'है'],
];

function fliteWords(words, voice = 'slt') {
  return words.map((w, i) => {
    const f = path.join(OUT, 'flite', `en-${voice}-${i}.wav`), t = w.replace(/[^\w']/g, '');
    if (!fs.existsSync(f)) execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', `flite=text='${t}':voice=${voice}`, '-ar', '16000', '-ac', '1', f]);
    return '/tools/out/s/flite/' + path.basename(f);
  });
}

const norm = (s) => String(s).toLowerCase().normalize('NFC').replace(/[ً-ْٰـ]/g, '').replace(/[^\p{L}\p{N}']/gu, '')
  .replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[ہھ]/g, 'ه').replace(/[یے]/g, 'ي').replace(/ک/g, 'ك');
/** LCS match of ground-truth words to hypothesis words (by normalized text): [[gi, hi]] */
function match(gt, hyp) {
  const a = gt.map((w) => norm(w.w)), b = hyp.map((w) => norm(w.w)), n = a.length, m = b.length;
  const d = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) d[i][j] = a[i] && a[i] === b[j] ? d[i + 1][j + 1] + 1 : Math.max(d[i + 1][j], d[i][j + 1]);
  const out = []; let i = 0, j = 0;
  while (i < n && j < m) { if (a[i] && a[i] === b[j]) { out.push([i, j]); i++; j++; } else if (d[i + 1][j] >= d[i][j + 1]) i++; else j++; }
  return out;
}
const pct = (xs, p) => { if (!xs.length) return null; const s = xs.slice().sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.floor(p * (s.length - 1) + 0.5))]; };
function score(gt, hyp) {
  const m = match(gt, hyp), e0 = m.map(([g, h]) => Math.abs(hyp[h].t0 - gt[g].t0) * 1000), e1 = m.map(([g, h]) => Math.abs(hyp[h].t1 - gt[g].t1) * 1000);
  const signed = m.map(([g, h]) => (hyp[h].t0 - gt[g].t0) * 1000);
  return { matched: m.length, of: gt.length, startMedian: r1(pct(e0, 0.5)), startP95: r1(pct(e0, 0.95)), startMax: r1(Math.max(...e0)), bias: r1(pct(signed, 0.5)),
    endMedian: r1(pct(e1, 0.5)), endP95: r1(pct(e1, 0.95)), within30: m.length ? r1(100 * e0.filter((x) => x <= 30).length / m.length) : null };
}
const r1 = (x) => (x == null ? null : Math.round(x * 10) / 10);

/** page: a Playwright page on app/sync/dev/index.html (S ready). Returns per language {clip, before, after, snapOnly, ...}. */
async function measure(page, { langs = ['en', 'ar', 'ur'], whisper = 'base', log = console.log } = {}) {
  const out = {};
  const sets = [];
  if (langs.includes('en')) {
    sets.push({ key: 'en-flite', lang: 'en', text: EN, src: { wavs: fliteWords(EN) } });
    // words run together (no pause between them: snapping has no quiet to find, the model alone places them)
    sets.push({ key: 'en-flite-joined', lang: 'en', text: EN, src: { wavs: fliteWords(EN) }, seed: 9, gap: [-0.03, 0.0] });
    sets.push({ key: 'en-flite-rms', lang: 'en', text: EN, src: { wavs: fliteWords(EN, 'rms') }, seed: 11 });
  }
  if (langs.includes('ar')) sets.push({ key: 'ar-mms', lang: 'ar', text: AR, src: { voice: 'ara', words: AR.map((w) => w.replace(/[.،]/g, '')) }, seed: 13 });
  if (langs.includes('ur')) sets.push({ key: 'ur-mms', lang: 'ur', text: UR_PAIRS.map((p) => p[0]), src: { voice: 'hin', words: UR_PAIRS.map((p) => p[1]) }, seed: 17 });
  for (const s of sets) {
    const r = await page.evaluate(async ({ s, whisper }) => {
      const parts = s.src.wavs ? await Promise.all(s.src.wavs.map((u) => S.loadWav(u))) : await S.voice(s.src.words, s.src.voice);
      const clip = S.build(parts, s.text, { seed: s.seed || 7, ...(s.gap ? { gap: s.gap } : {}) });
      const t = performance.now();
      const wh = await S.whisper(clip.audio, { lang: s.lang, model: whisper });
      const whMs = performance.now() - t;
      const snap = await S.align(wh, clip.audio, { model: false });
      const al = await S.align(wh, clip.audio, { model: true });
      const ctc = await S.align(wh, clip.audio, { model: true, snap: false });
      // the aligner on the TRUE transcript (separates alignment error from recognition error)
      const ideal = await S.align(clip.gt.map((g) => ({ w: g.w, t0: g.t0 + 0.12 * Math.sin(g.t0 * 7), t1: g.t1 })), clip.audio, { model: true });
      return { wav: S.wav64(clip.audio), ctc: ctc.words, dur: clip.dur, gt: clip.gt, whisper: wh, whMs, snap: snap.words, aligned: al.words, stats: al.stats, alignMs: al.ms, loadMs: al.loadMs, ideal: ideal.words };
    }, { s, whisper });
    out[s.key] = { dur: r1(r.dur), words: r.gt.length, whisperWords: r.whisper.length, whisperMs: Math.round(r.whMs), alignMs: Math.round(r.alignMs), modelLoadMs: Math.round(r.loadMs || 0),
      before: score(r.gt, r.whisper), snapOnly: score(r.gt, r.snap), after: score(r.gt, r.aligned), trueTranscript: score(r.gt, r.ideal), stats: r.stats };
    fs.writeFileSync(path.join(OUT, `sync-${s.key}.json`), JSON.stringify({ gt: r.gt, whisper: r.whisper, ctc: r.ctc, aligned: r.aligned, ideal: r.ideal }, null, 1));
    fs.writeFileSync(path.join(OUT, `sync-${s.key}.wav`), Buffer.from(r.wav, 'base64'));
    out[s.key].ctcOnly = score(r.gt, r.ctc);
    const o = out[s.key];
    log(`${s.key}: ${o.words} words, ${o.dur}s · Whisper-only start median ${o.before.startMedian} ms p95 ${o.before.startP95} · snap-only ${o.snapOnly.startMedian}/${o.snapOnly.startP95} · aligned ${o.after.startMedian}/${o.after.startP95} (matched ${o.after.matched}/${o.words}) · true-transcript ${o.trueTranscript.startMedian}/${o.trueTranscript.startP95} · align ${o.alignMs} ms`);
  }
  fs.writeFileSync(path.join(OUT, 'sync.json'), JSON.stringify(out, null, 1));
  return out;
}
module.exports = { measure, score, match };

if (require.main === module) {
  (async () => {
    const server = require('./r2-serve.cjs');
    const { chromium } = require('playwright');
    await new Promise((r) => (server.listening ? r() : server.once('listening', r)));
    const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined });
    const page = await browser.newPage();
    page.on('console', (m) => { if (m.type() === 'error') console.log('console:', m.text()); });
    page.on('pageerror', (e) => console.log('pageerror:', e.message));
    page.setDefaultTimeout(0);
    await page.goto(`http://127.0.0.1:${server.address().port}/app/sync/dev/index.html`);
    await page.waitForFunction(() => window.S);
    const a = process.argv.slice(2);
    const langs = ((a.find((x) => x.startsWith('--langs=')) || '--langs=en,ar,ur').slice(8)).split(',');
    const whisper = (a.find((x) => x.startsWith('--whisper=')) || '--whisper=base').slice(10);
    await measure(page, { langs, whisper });
    await browser.close(); server.close();
  })().catch((e) => { console.error(e); process.exit(1); });
}
