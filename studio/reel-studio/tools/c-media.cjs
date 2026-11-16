#!/usr/bin/env node
// KBB Studio — Lane C test media (into tools/out/c/, never committed). Every voice is flite TTS (offline, in ffmpeg);
// every transcript is written from the SAME script with the real per-sentence timing, so tests can check that features
// land on the right words (b-roll on "serum", a short starting on a sentence) without depending on speech recognition.
//   webinar-15min.mp4 (+ .words.json)  ~15 min 1280x720 Planet Careers webinar: 10 topics, a slide per topic
//   kbb-talk.mp4      (+ .words.json)  ~30 s 1080x1920 KBB talk naming products, prices, a country
//   pc-talk.mp4       (+ .words.json)  ~20 s 1920x1080 Planet Careers talk (re-laid out into four shapes)
//   lib/IMG_2041.jpg                    a serum bottle photo with a meaningless name (the image tagger must find it)
//   lib/sunscreen-on-the-beach.mp4      a clip whose NAME says what it is (file-name tagging)
//   lib/passport-and-documents.jpg      for Planet Careers b-roll
//   trend-128bpm.wav                    a 128 BPM drum loop (measured for the cue sheet)
// usage: node tools/c-media.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.join(ROOT, 'tools', 'out', 'c');
const TMP = path.join(OUT, 'tmp');
fs.mkdirSync(path.join(OUT, 'lib'), { recursive: true }); fs.mkdirSync(TMP, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const SR = 16000;
const FONT = fs.existsSync('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf') ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf';

function pcm(file) { const b = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(SR), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(b.buffer.slice(b.byteOffset, b.byteOffset + b.length)); }
function wav(x, file, sr = SR) {
  const b = Buffer.alloc(44 + x.length * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + x.length * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(sr, 24); b.writeUInt32LE(sr * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(x.length * 2, 40);
  for (let i = 0; i < x.length; i++) b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), 44 + i * 2);
  fs.writeFileSync(file, b);
}
/** speak sentences; returns {audio, words:[{t0,t1,w}], sents:[{t0,t1,text,topic}]} — word times spread by letters inside the voiced part */
function speak(lines, voice = 'slt', gap = 0.45) {
  const parts = [new Float32Array(Math.round(0.5 * SR))]; let t = 0.5; const words = [], sents = [];
  lines.forEach(({ text, pause = gap, topic }, i) => {
    const tf = path.join(TMP, 's.txt'), wf = path.join(TMP, 's.wav');
    fs.writeFileSync(tf, text.replace(/[‘’]/g, "'"));
    ff('-f', 'lavfi', '-i', `flite=textfile=${tf}:voice=${voice}`, '-ar', String(SR), '-ac', '1', wf);
    const x = pcm(wf);
    // voiced span (trim flite's own lead/tail silence) for word timing
    let a = 0, b = x.length - 1; const thr = 0.02;
    while (a < x.length && Math.abs(x[a]) < thr) a++; while (b > a && Math.abs(x[b]) < thr) b--;
    const v0 = t + a / SR, v1 = t + b / SR, ws = text.split(/\s+/).filter(Boolean), L = ws.reduce((s, w) => s + w.replace(/[^\w']/g, '').length + 1.5, 0);
    let c = v0;
    for (const w of ws) { const d = (v1 - v0) * (w.replace(/[^\w']/g, '').length + 1.5) / L; words.push({ t0: +c.toFixed(3), t1: +(c + d * 0.92).toFixed(3), w, conf: 1 }); c += d; }
    sents.push({ t0: +v0.toFixed(3), t1: +v1.toFixed(3), text, topic });
    parts.push(x, new Float32Array(Math.round(pause * SR)));
    t += x.length / SR + pause;
  });
  const n = parts.reduce((s, p) => s + p.length, 0), audio = new Float32Array(n); let o = 0; for (const p of parts) { audio.set(p, o); o += p.length; }
  return { audio, words, sents, dur: n / SR };
}

// ── the webinar: 10 topics, each a hook question + 15 statements made from its own facts ──
const TOPICS = [
  ['Why study abroad', 'study abroad', ['an international degree opens doors to global careers', 'students who study abroad learn to live independently', 'employers value graduates with international experience', 'living in another culture builds confidence and language skills', 'many countries let graduates stay and work after the degree']],
  ['Free tuition in Germany', 'Germany', ['public universities in Germany charge no tuition fees', 'German universities only ask for a small semester contribution', 'Germany has hundreds of programmes taught in English', 'a German degree is respected by employers across Europe', 'Germany gives graduates eighteen months to find a job']],
  ['Your document checklist', 'documents', ['your passport must be valid for the whole study period', 'the admission letter from the university is the key document', 'you need certified copies of your transcripts and certificates', 'health insurance documents are required for the visa', 'keep scanned copies of every document in one folder']],
  ['The blocked account', 'blocked account', ['the blocked account proves you can pay your living costs', 'the blocked account amount is set every year by the embassy', 'you can open a blocked account online in a few days', 'each month a fixed amount is released from the blocked account', 'the bank statement for the blocked account goes with your visa file']],
  ['English tests', 'IELTS', ['most universities accept IELTS or TOEFL scores', 'an IELTS score of six point five is a common requirement', 'some universities accept a medium of instruction letter instead of IELTS', 'book your IELTS test at least two months before the deadline', 'practise speaking every day before your IELTS exam']],
  ['The visa interview', 'interview', ['in the visa interview the officer checks that your plan is genuine', 'explain clearly why you chose this course and this university', 'bring original documents to the interview, not only copies', 'answer interview questions briefly and honestly', 'a calm and prepared interview makes a strong impression']],
  ['Working in Canada', 'Canada', ['students in Canada can work part time during the semester', 'Canada offers a post graduation work permit after your studies', 'Canadian work experience counts towards permanent residence', 'Toronto and Vancouver have the most part time jobs for students', 'Canada needs skilled graduates in health and technology']],
  ['Cost of living', 'rent', ['rent is the biggest monthly cost for most students', 'shared flats keep your rent much lower than a studio', 'food costs drop when you cook at home most days', 'a student travel card cuts your transport costs in half', 'plan your monthly budget before you book your flight']],
  ['Scholarships', 'scholarships', ['many scholarships cover tuition and part of your living costs', 'scholarships reward strong grades and a clear motivation letter', 'apply for scholarships early because deadlines come before admissions', 'government scholarships often include a monthly stipend', 'small university scholarships are easier to win and add up']],
  ['Intakes and next steps', 'intake', ['the main intake in Europe starts in September', 'some universities also have a smaller intake in February or March', 'start your application at least eight months before the intake', 'missing the intake deadline can delay your plans by a whole year', 'book a free consultation and we will plan your intake timeline']],
];
const FRAMES = [
  (f) => `Here is something important: ${f}.`, (f) => `Many students do not know that ${f}.`, (f) => `Remember, ${f}.`,
];
function webinarScript() {
  const lines = [];
  TOPICS.forEach(([title, key, facts], ti) => {
    lines.push({ text: `So, what do you need to know about ${key}?`, pause: 1.6, topic: ti });
    let k = 0;
    for (let r = 0; r < 3; r++) for (const f of facts) { lines.push({ text: FRAMES[(k + r) % 3](f), pause: k % 5 === 4 ? 0.9 : 0.45, topic: ti }); k++; }
    lines[lines.length - 1].pause = 1.8;
  });
  return lines;
}

function slidesVideo(file, sents, dur, W, H, fps, titleOf) {
  // one slide per topic: a coloured card with the topic title (drawtext enabled by time)
  const cols = ['0x0b2545', '0x13a89e', '0x1d3557', '0x2a9d8f', '0x264653', '0x3d405b', '0x14213d', '0x006d77', '0x283618', '0x5f0f40'];
  const bounds = []; let cur = -1;
  sents.forEach((s) => { if (s.topic !== cur) { bounds.push({ t: Math.max(0, s.t0 - 0.4), topic: s.topic }); cur = s.topic; } });
  const draws = bounds.map((b, i) => { const t1 = bounds[i + 1] ? bounds[i + 1].t : dur + 1, txt = titleOf(b.topic).replace(/[:']/g, '');
    return `drawbox=x=0:y=0:w=iw:h=ih:color=${cols[b.topic % cols.length]}:t=fill:enable='between(t,${b.t.toFixed(2)},${t1.toFixed(2)})',drawtext=fontfile=${FONT}:text='${txt}':fontcolor=white:fontsize=${Math.round(H / 11)}:x=(w-text_w)/2:y=(h-text_h)/2:enable='between(t,${b.t.toFixed(2)},${t1.toFixed(2)})'`; });
  const vf = draws.join(',') + `,drawtext=fontfile=${FONT}:text='Planet Careers webinar':fontcolor=white@0.7:fontsize=${Math.round(H / 26)}:x=40:y=h-80`;
  return vf;
}

function build(name, lines, W, H, fps, vf, voice) {
  const s = speak(lines, voice);
  const wf = path.join(TMP, name + '.wav'); wav(s.audio, wf);
  const mp4 = path.join(OUT, name + '.mp4');
  ff('-f', 'lavfi', '-i', `color=c=0x222222:s=${W}x${H}:r=${fps}:d=${s.dur.toFixed(2)}`, '-i', wf, '-vf', typeof vf === 'function' ? vf(s) : vf,
    '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '96k', '-shortest', '-movflags', '+faststart', mp4);
  fs.writeFileSync(path.join(OUT, name + '.words.json'), JSON.stringify({ dur: s.dur, words: s.words, sents: s.sents }));
  fs.rmSync(wf);
  console.log(name, s.dur.toFixed(1) + ' s', s.words.length + ' words', (fs.statSync(mp4).size / 1e6).toFixed(1) + ' MB');
}

const only = process.argv.slice(2);
const want = (n) => !only.length || only.includes(n);

if (want('kbb-talk')) build('kbb-talk', [
  { text: 'Hi everyone, this is my new evening routine.', pause: 0.8 },
  { text: 'First the Biodance Caviar serum, it is only 99 AED this week.', pause: 0.6 },
  { text: 'The texture is light and my skin looks glowing in the morning.', pause: 0.7 },
  { text: 'Then I use a few drops of serum on the cheeks and pat it in.', pause: 0.6 },
  { text: 'In the day I never skip sunscreen, my favourite is the numbuzin sun essence.', pause: 0.6 },
  { text: 'It is thirty percent off right now, with fast delivery from Dubai.', pause: 0.6 },
  { text: 'Shop now at the link in bio.', pause: 0.8 },
], 1080, 1920, 30, `drawbox=x=0:y=0:w=iw:h=ih:color=0xC6395F@0.85:t=fill,drawbox=x=300:y=520:w=480:h=620:color=0xf1c7b1:t=fill,drawtext=fontfile=${FONT}:text='KBB':fontcolor=white:fontsize=90:x=(w-text_w)/2:y=1400`, 'slt');

if (want('pc-talk')) build('pc-talk', [
  { text: 'Thinking about studying in Germany next year?', pause: 0.7 },
  { text: 'Public universities in Germany charge no tuition fees.', pause: 0.6 },
  { text: 'You need your passport, the admission letter and a blocked account.', pause: 0.7 },
  { text: 'Book a free consultation with Planet Careers today.', pause: 0.8 },
], 1920, 1080, 30, `drawbox=x=0:y=0:w=iw:h=ih:color=0x0b2545:t=fill,drawbox=x=1180:y=180:w=420:h=560:color=0xe0b49a:t=fill,drawtext=fontfile=${FONT}:text='Planet Careers':fontcolor=0xf2c14e:fontsize=80:x=120:y=200`, 'slt');

if (want('webinar')) {
  const lines = webinarScript();
  const s0 = Date.now();
  build('webinar-15min', lines, 1280, 720, 15, (s) => slidesVideo(null, s.sents, s.dur, 1280, 720, 15, (ti) => TOPICS[ti][0]), 'slt');
  console.log('webinar built in', ((Date.now() - s0) / 1000).toFixed(0), 's');
}

if (want('lib')) {
  // a real serum bottle photo with a meaningless camera name: only the image tagger can say what it is
  const P = path.join(ROOT, 'brands', 'kbb', 'products');
  ff('-f', 'lavfi', '-i', 'color=c=0xf4ece6:s=1080x1350', '-i', path.join(P, 'marymay.webp'), '-filter_complex', '[1]scale=-2:1100[p];[0][p]overlay=(W-w)/2:(H-h)/2', '-frames:v', '1', path.join(OUT, 'lib', 'IMG_2041.jpg'));
  ff('-f', 'lavfi', '-i', 'color=c=0xf6d365:s=1080x1920:r=30:d=4', '-vf', `drawbox=x=0:y=1100:w=iw:h=820:color=0xe9c46a:t=fill,drawtext=fontfile=${FONT}:text='SPF 50':fontcolor=white:fontsize=160:x=(w-text_w)/2:y=600`, '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', path.join(OUT, 'lib', 'sunscreen-on-the-beach.mp4'));
  ff('-f', 'lavfi', '-i', 'color=c=0xeeeeee:s=1080x1350', '-frames:v', '1', '-vf', `drawbox=x=200:y=300:w=420:h=600:color=0x0b2545:t=fill,drawtext=fontfile=${FONT}:text='PASSPORT':fontcolor=0xf2c14e:fontsize=60:x=240:y=560,drawbox=x=560:y=420:w=380:h=520:color=white:t=fill`, path.join(OUT, 'lib', 'passport-and-documents.jpg'));
  console.log('library files');
}

if (want('trend')) {
  // 128 BPM: kick on every beat, hat on the off-beats, 12 s, first beat at 0.25 s
  const sr = 44100, T = 12, x = new Float32Array(sr * T), bpm = 128, d = 60 / bpm;
  for (let t = 0.25; t < T; t += d) { const i0 = Math.round(t * sr); for (let i = 0; i < sr * 0.18 && i0 + i < x.length; i++) { const f = 120 * Math.exp(-i / (sr * 0.03)) + 45; x[i0 + i] += 0.9 * Math.sin(2 * Math.PI * f * i / sr) * Math.exp(-i / (sr * 0.06)); } }
  for (let t = 0.25 + d / 2; t < T; t += d) { const i0 = Math.round(t * sr); for (let i = 0; i < sr * 0.04 && i0 + i < x.length; i++) x[i0 + i] += 0.25 * (Math.random() * 2 - 1) * Math.exp(-i / (sr * 0.008)); }
  wav(x, path.join(OUT, 'trend-128bpm.wav'), sr);
  console.log('trend sound');
}
fs.rmSync(TMP, { recursive: true, force: true });
