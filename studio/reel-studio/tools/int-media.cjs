#!/usr/bin/env node
// KBB Studio — integration test media (into tools/out/int/, never committed):
//   kbb-talk-916.mp4   ~35 s 1080x1920, animated KBB-pink background, an English beauty "unboxing" voice (flite TTS)
//                      with pauses and an "um" (for Remove silences / Auto reel / Suggest style)
//   pc-talk-169.mp4    ~30 s 1920x1080, navy/teal background, an English study-visa voice (flite) — reframed to 9:16
//   kbb-ar-916.mp4     ~12 s 1080x1920, an Arabic voice (MMS-TTS ara run offline in Chromium through the studio's own
//                      vendored transformers.js; the TTS weights are fetched ONCE into tools/out/int-cache/ by curl)
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/int-media.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const OUT = path.join(H.ROOT, 'tools', 'out', 'int');
const CACHE = path.join(H.ROOT, 'tools', 'out', 'int-cache');
fs.mkdirSync(OUT, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
const SR = 16000;

const KBB = [
  ['Hi everyone, welcome back to my channel.', 0.7],
  ['Um, today I am unboxing my new order from K-Beauty Bliss.', 1.4],
  ['The box arrived in just two days.', 0.6],
  ['First, the vitamin C serum.', 0.5],
  ['The texture is light and it absorbs really fast.', 1.6],
  ['Then this gentle green tea cleanser, my favourite.', 0.8],
  ['After one week my skin looks brighter and more hydrated.', 1.2],
  ['Honestly, I did not expect results so fast.', 0.9],
  ['Shop now at the link in bio and order today.', 1.0],
];
const PC = [
  ['If you want to study in Germany, the next intake is in March.', 0.8],
  ['Prepare your passport, your bank statement and your university documents.', 1.5],
  ['Our consultants have helped hundreds of students get their student visa.', 0.7],
  ['We will guide you through every step of the visa process.', 1.2],
  ['Germany has free tuition at public universities.', 0.9],
  ['Book a free consultation with Planet Careers today.', 1.0],
];
const AR = ['مرحبا بكم في قناتنا', 'اليوم سنتحدث عن الدراسة في ألمانيا', 'هذا السيروم يجعل البشرة نضرة ومشرقة', 'احجز استشارة مجانية مع فريقنا اليوم'];

function wav(x, sr = SR) {
  const b = Buffer.alloc(44 + x.length * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + x.length * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(sr, 24); b.writeUInt32LE(sr * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(x.length * 2, 40);
  for (let i = 0; i < x.length; i++) b.writeInt16LE(Math.round(Math.max(-1, Math.min(1, x[i])) * 32767), 44 + i * 2);
  return b;
}
const pcm = (file) => { const buf = execFileSync('ffmpeg', ['-v', 'error', '-i', file, '-ac', '1', '-ar', String(SR), '-f', 'f32le', '-'], { maxBuffer: 1 << 30 }); return new Float32Array(buf.buffer.slice(buf.byteOffset, buf.byteOffset + buf.length)); };
function speak(lines, voice) {
  const parts = [new Float32Array(Math.round(0.4 * SR))];
  lines.forEach(([text, pause], i) => {
    const tf = path.join(OUT, `line-${i}.txt`), wf = path.join(OUT, `line-${i}.wav`);
    fs.writeFileSync(tf, text);
    ff('-f', 'lavfi', '-i', `flite=textfile=${tf}:voice=${voice}`, '-ar', String(SR), '-ac', '1', wf);
    parts.push(pcm(wf), new Float32Array(Math.round(pause * SR)));
    fs.rmSync(tf); fs.rmSync(wf);
  });
  return concat(parts);
}
function concat(parts) { const n = parts.reduce((s, p) => s + p.length, 0), out = new Float32Array(n); let o = 0; for (const p of parts) { out.set(p, o); o += p.length; } return out; }

/** a moving gradient (four looks, a hard cut between them so scene detection has something to find) + the voice */
function video(file, wavFile, dur, { w, h, looks, label }) {
  const seg = dur / looks.length;
  const ins = [], fl = [];
  looks.forEach(([c0, c1, c2], i) => {
    ins.push('-f', 'lavfi', '-i', `gradients=s=${w}x${h}:c0=0x${c0}:c1=0x${c1}:c2=0x${c2}:nb_colors=3:speed=0.015:d=${seg.toFixed(3)}:r=30:seed=${i + 3}`);
    fl.push(`[${i}:v]drawtext=text='${label[i]}':fontsize=${Math.round(w * 0.06)}:fontcolor=white@0.85:x=(w-tw)/2:y=h*0.18:shadowcolor=black@0.35:shadowx=3:shadowy=3,` +
      `drawbox=x=(iw/2-${Math.round(w * 0.18)})+${Math.round(w * 0.12)}*sin(t*1.3):y=ih*0.42+${Math.round(h * 0.05)}*cos(t*0.9):w=${Math.round(w * 0.36)}:h=${Math.round(w * 0.36)}:color=white@0.35:t=fill[v${i}]`);
  });
  const filter = fl.join(';') + ';' + looks.map((_, i) => `[v${i}]`).join('') + `concat=n=${looks.length}:v=1:a=0[v]`;
  ff(...ins, '-i', wavFile, '-filter_complex', filter, '-map', '[v]', '-map', `${looks.length}:a`, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p', '-g', '60',
    '-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-shortest', '-movflags', '+faststart', file);
}

async function arabicVoice() {
  const repo = 'Xenova/mms-tts-ara', files = ['config.json', 'tokenizer.json', 'tokenizer_config.json', 'onnx/model.onnx'];
  for (const f of files) {
    const p = path.join(CACHE, repo, f);
    if (fs.existsSync(p) && fs.statSync(p).size > 0) continue;
    fs.mkdirSync(path.dirname(p), { recursive: true });
    execFileSync('curl', ['-sSfL', '--retry', '3', '-o', p, `https://huggingface.co/${repo}/resolve/main/${f}`]);
  }
  const dir = H.makeStudio();
  fs.cpSync(path.join(CACHE, repo), path.join(dir, 'models', repo), { recursive: true });
  const e = await H.startEngine(dir);
  const b = await H.launchChrome();
  try {
    const page = await b.newPage();
    await page.goto(e.url + '/app/captions/dev/index.html');
    await page.waitForFunction(() => window.R2);
    const arr = await page.evaluate(async (AR) => Array.from(await R2.tts(AR, 'ara', 0.7)), AR);
    return new Float32Array(arr);
  } finally { await b.close(); await H.stopEngine(e); fs.rmSync(dir, { recursive: true, force: true }); }
}

(async () => {
  const t0 = Date.now();
  const kbbWav = path.join(OUT, 'kbb-voice.wav'), pcWav = path.join(OUT, 'pc-voice.wav'), arWav = path.join(OUT, 'ar-voice.wav');
  if (!fs.existsSync(kbbWav)) fs.writeFileSync(kbbWav, wav(speak(KBB, 'slt')));
  if (!fs.existsSync(pcWav)) fs.writeFileSync(pcWav, wav(speak(PC, 'rms')));
  if (!fs.existsSync(arWav)) fs.writeFileSync(arWav, wav(await arabicVoice()));
  const dur = (f) => +execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', f]).toString().trim();
  const pink = [['C6395F', 'E8789A', 'FFF0F4'], ['A82F53', 'C6395F', 'F6C1D4'], ['2A2228', 'C6395F', 'BE8E2E'], ['E8789A', 'FFF0F4', 'C6395F']];
  const navy = [['0B2545', '13A89E', '0A1B30'], ['13A89E', '0B2545', 'F2C14E'], ['0A1B30', '0B2545', '13A89E'], ['0B2545', 'F2C14E', '13A89E']];
  const jobs = [
    ['kbb-talk-916.mp4', kbbWav, { w: 1080, h: 1920, looks: pink, label: ['Unboxing', 'Vitamin C serum', 'Green tea cleanser', 'Glow'] }],
    ['pc-talk-169.mp4', pcWav, { w: 1920, h: 1080, looks: navy, label: ['Study in Germany', 'Documents', 'Visa steps', 'Book now'] }],
    ['kbb-ar-916.mp4', arWav, { w: 1080, h: 1920, looks: pink.slice(0, 2), label: ['Arabic', 'Serum'] }],
  ];
  for (const [name, w, opt] of jobs) {
    const f = path.join(OUT, name);
    if (!fs.existsSync(f)) video(f, w, dur(w), opt);
    console.log(name, dur(f).toFixed(2), 's', (fs.statSync(f).size / 1e6).toFixed(1), 'MB');
  }
  console.log(`done in ${((Date.now() - t0) / 1000).toFixed(0)} s`);
})().catch((e) => { console.error(e); process.exit(1); });
