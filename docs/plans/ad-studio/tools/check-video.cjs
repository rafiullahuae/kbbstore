// Video mode, driven like the owner would, from the built file on file:// (no server, no window.claude):
// import 2 clips + music (and a fake .mp4 that must be refused), split / delete / reorder / trim on the
// timeline, 9:16 + crop/zoom, mute one clip, English + Arabic text with styles and timing, stickers,
// MP4 export checked with ffprobe (+ frames grabbed with ffmpeg, + the muted clip really silent),
// export without sound, still-frame PNG size, save -> reload, project .zip export -> import into a clean
// browser, a hostile project zip, the WebM fallback, no console errors, no network, 390px no h-scroll.
// Needs ffmpeg / ffprobe. Writes screenshots + 3 exported frames to shots/video/.
//   python3 build.py && node tools/check-video.cjs
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os'), cp = require('child_process');
const ROOT = path.join(__dirname, '..'), FILE = 'file://' + path.join(ROOT, 'ad-studio.html'), SHOTS = path.join(ROOT, 'shots', 'video');
const TMP = process.env.VID_TMP || fs.mkdtempSync(path.join(os.tmpdir(), 'vid-check-'));
fs.mkdirSync(SHOTS, { recursive: true }); fs.mkdirSync(TMP, { recursive: true });
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sh = (cmd) => cp.execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
const probe = (f) => JSON.parse(sh(`ffprobe -v error -show_entries format=duration:stream=codec_type,codec_name,width,height,sample_rate,channels -of json "${f}"`));
function pngSize(buf) { return [buf.readUInt32BE(16), buf.readUInt32BE(20)]; }
// mean volume (dB) of one frequency band in a time window: proves which sounds are in the mix
function band(f, from, len, hz) { const o = cp.spawnSync('ffmpeg', ['-hide_banner', '-ss', String(from), '-t', String(len), '-i', f, '-af', `bandpass=f=${hz}:width_type=h:w=60,volumedetect`, '-f', 'null', '-'], { encoding: 'utf8' }).stderr; return +(/mean_volume: (-?[\d.]+) dB/.exec(o) || [0, -999])[1]; }

// ── test media (made here, so nothing is downloaded)
const A = path.join(TMP, 'vid-a.mp4'), B = path.join(TMP, 'vid-b.mov'), MUS = path.join(TMP, 'vid-music.mp3'), FAKE = path.join(TMP, 'vid-fake.mp4');
sh(`ffmpeg -v error -y -f lavfi -i testsrc2=size=1920x1080:rate=30:duration=6 -f lavfi -i sine=frequency=440:duration=6:sample_rate=48000 -c:v libx264 -pix_fmt yuv420p -g 30 -c:a aac -shortest "${A}"`);
sh(`ffmpeg -v error -y -f lavfi -i smptehdbars=size=1280x720:rate=30:duration=5 -f lavfi -i sine=frequency=880:duration=5:sample_rate=48000 -c:v libx264 -pix_fmt yuv420p -g 30 -c:a aac -shortest "${B}"`);
sh(`ffmpeg -v error -y -f lavfi -i sine=frequency=330:duration=12:sample_rate=44100 -c:a libmp3lame -b:a 128k "${MUS}"`);
fs.writeFileSync(FAKE, 'this is not a video');

function watch(p, errs, net) {
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message));
  p.on('request', (r) => { if (!/^(file|data|blob):/.test(r.url())) net.push(r.url()); }); p.on('dialog', (d) => d.accept());
}
async function open(ctx) {
  const p = await ctx.newPage(), errs = [], net = []; watch(p, errs, net);
  await p.goto(FILE); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  if (await p.evaluate(() => document.body.dataset.mode) !== 'video') await p.click('#modesw button[data-m="video"]');
  await p.waitForFunction(() => document.documentElement.dataset.vready === '1'); await p.evaluate(() => document.fonts.ready);
  return { p, errs, net };
}
async function dl(p, click, name) { const [d] = await Promise.all([p.waitForEvent('download', { timeout: 240000 }), click()]); const f = path.join(TMP, name || d.suggestedFilename()); await d.saveAs(f); return f; }
const V = (p, fn, arg) => p.evaluate(fn, arg);

(async () => {
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  let { p, errs, net } = await open(ctx);
  ok(await V(p, () => document.querySelector('#ads-main').hidden && !document.querySelector('#vmain').hidden), 'the Video switch shows the video editor and hides the ad editor');

  // ── import
  await p.setInputFiles('#v-file', [A, B, MUS, FAKE]);
  await p.waitForFunction(() => /Imported/.test(document.getElementById('v-status').textContent), null, { timeout: 60000 });
  const stt = await p.textContent('#v-status');
  ok(/Imported 3/.test(stt) && /vid-fake\.mp4/.test(stt), 'imported 2 clips + music, refused the fake .mp4: "' + stt.slice(0, 90) + '…"');
  let vp = await V(p, () => window.KBB_VIDEO.vp);
  ok(vp.clips.length === 2 && vp.au && Math.abs(vp.au.b - 12) < .05, 'two clips on the timeline (' + vp.clips.map((c) => c.b - c.a).join(' s, ') + ' s) and the music track (12 s)');
  await p.waitForFunction(() => Object.values(window.KBB_VIDEO.media).every((m) => m.k !== 'video' || (m.thumbs.length && m.peaks)), null, { timeout: 60000 });
  ok(await p.locator('.v-clip canvas.v-th').count() === 2 && await p.locator('.v-au canvas.v-th').count() === 1, 'thumbnail strips on the clips, waveform on the music');

  // ── split clip A at 3 s with the S key, delete the second chunk with Del
  await V(p, () => window.KBB_VIDEO.seek(3)); await p.locator('body').click({ position: { x: 5, y: 5 } }); await p.keyboard.press('s');
  vp = await V(p, () => window.KBB_VIDEO.vp);
  ok(vp.clips.length === 3 && vp.clips[0].b === 3 && vp.clips[1].a === 3, 'S split clip A at the playhead into 0–3 s and 3–6 s');
  await p.click(`.v-clip[data-id="${vp.clips[1].id}"]`); await p.keyboard.press('Delete');
  vp = await V(p, () => window.KBB_VIDEO.vp);
  ok(vp.clips.length === 2 && vp.clips[0].b === 3, 'Delete removed the 3–6 s chunk');
  await p.keyboard.press('Control+z'); ok((await V(p, () => window.KBB_VIDEO.vp.clips.length)) === 3, 'Ctrl+Z brings the chunk back');
  await p.keyboard.press('Control+y'); ok((await V(p, () => window.KBB_VIDEO.vp.clips.length)) === 2, 'Ctrl+Y deletes it again');

  // ── reorder: drag clip B (second) in front of clip A
  vp = await V(p, () => window.KBB_VIDEO.vp); const idB = vp.clips[1].id, idA = vp.clips[0].id;
  let bb = await p.locator(`.v-clip[data-id="${idB}"]`).boundingBox(); const ba = await p.locator(`.v-clip[data-id="${idA}"]`).boundingBox();
  await p.mouse.move(bb.x + bb.width / 2, bb.y + 25); await p.mouse.down(); await p.mouse.move(ba.x + 10, bb.y + 25, { steps: 8 }); await p.mouse.up();
  vp = await V(p, () => window.KBB_VIDEO.vp); ok(vp.clips[0].id === idB && vp.clips[1].id === idA, 'dragging clip B before clip A reorders them');

  // ── trim: drag B's right edge 1 s to the left
  const pps = await V(p, () => +document.getElementById('v-zoomv').textContent.split(' ')[0]);
  bb = await p.locator(`.v-clip[data-id="${idB}"] .v-ed.r`).boundingBox();
  await p.mouse.move(bb.x + bb.width / 2, bb.y + 25); await p.mouse.down(); await p.mouse.move(bb.x + bb.width / 2 - pps, bb.y + 25, { steps: 6 }); await p.mouse.up();
  vp = await V(p, () => window.KBB_VIDEO.vp); const bLen = vp.clips[0].b - vp.clips[0].a;
  ok(Math.abs(bLen - 4) < .1, 'dragging the right edge trims clip B to ' + bLen.toFixed(2) + ' s');
  await V(p, () => { const c = window.KBB_VIDEO.vp.clips[0]; c.b = 4; window.KBB_VIDEO.changed('t'); });    // exact 4 s for the checks below
  const TOTAL = await V(p, () => window.KBB_VIDEO.total()); ok(Math.abs(TOTAL - 7) < 1e-6, 'video is now ' + TOTAL + ' s (B 0–4 s, then A 0–3 s)');

  // ── artboard 9:16, crop / zoom / fit, mute B
  await p.click('#v-ab .chip[data-ab="square"]'); ok((await V(p, () => window.KBB_VIDEO.vp.ab)).h === 1080, 'artboard 1:1 → 1080×1080');
  await p.click('#v-ab .chip[data-ab="story"]'); ok((await V(p, () => window.KBB_VIDEO.vp.ab)).h === 1920, 'artboard 9:16 → 1080×1920');
  await p.click(`.v-clip[data-id="${idB}"]`); ok(await p.isVisible('#vc-props'), 'tapping a clip opens the Clip tab');
  await p.click('#vc-fit button[data-v="fit"]'); await p.click('#v-bgk button[data-v="blur"]');
  await p.check('#vc-mute');
  await p.click(`.v-clip[data-id="${idA}"]`);
  await p.locator('#vc-zoom').evaluate((n) => { n.value = '150'; n.dispatchEvent(new Event('input', { bubbles: true })); });
  // pan by dragging the picture on the preview
  await V(p, () => window.KBB_VIDEO.seek(5)); const cvb = await p.locator('#v-cv').boundingBox();
  await p.mouse.move(cvb.x + cvb.width * .5, cvb.y + cvb.height * .3); await p.mouse.down(); await p.mouse.move(cvb.x + cvb.width * .62, cvb.y + cvb.height * .3, { steps: 5 }); await p.mouse.up();
  vp = await V(p, () => window.KBB_VIDEO.vp);
  ok(vp.clips[0].fit === 'fit' && vp.clips[0].mute && vp.clips[1].z === 1.5 && vp.clips[1].px > 5, 'B: fit + blur fill, muted · A: zoom 150%, panned ' + vp.clips[1].px.toFixed(1) + '% by dragging the picture');

  // ── text: English (pill style, slide) and Arabic (gold style, pop), timed
  await V(p, () => window.KBB_VIDEO.seek(.5)); await p.click('.v-tab[data-vt="text"]'); await p.click('#vt-add-en'); await p.click('#vt-presets button[data-k="pill"]');
  await p.fill('#vt-txt', 'Glow Week\nup to 50% off');
  await p.fill('#vt-s', '0.5'); await p.locator('#vt-s').dispatchEvent('change'); await p.fill('#vt-e', '5'); await p.locator('#vt-e').dispatchEvent('change');
  await p.selectOption('#vt-ain', 'slide');
  await V(p, () => { const o = window.KBB_VIDEO.vp.ovs[0]; o.y = 22; window.KBB_VIDEO.changed('y'); });
  await V(p, () => window.KBB_VIDEO.seek(2)); await p.click('#vt-add-ar'); await p.click('#vt-presets button[data-k="gold"]');
  await p.fill('#vt-s', '2'); await p.locator('#vt-s').dispatchEvent('change'); await p.fill('#vt-e', '6.5'); await p.locator('#vt-e').dispatchEvent('change');
  await p.selectOption('#vt-ain', 'pop'); await p.locator('#vt-fs').evaluate((n) => { n.value = '9'; n.dispatchEvent(new Event('input', { bubbles: true })); });
  // drag the Arabic text down on the preview
  await V(p, () => window.KBB_VIDEO.seek(3)); await p.waitForTimeout(300);
  await p.mouse.move(cvb.x + cvb.width * .5, cvb.y + cvb.height * .5); await p.mouse.down(); await p.mouse.move(cvb.x + cvb.width * .5, cvb.y + cvb.height * .74, { steps: 5 }); await p.mouse.up();
  vp = await V(p, () => window.KBB_VIDEO.vp); const en = vp.ovs[0], ar = vp.ovs[1];
  ok(en.pill && en.s === .5 && en.e === 5 && en.ain === 'slide' && /Glow Week/.test(en.txt), 'English text: pill style, 0.5–5 s, slides in');
  ok(ar.fn === 'cairo' && ar.ol > 0 && ar.s === 2 && ar.e === 6.5 && ar.ain === 'pop' && ar.y > 65, 'Arabic text: Cairo gold + outline, 2–6.5 s, pops in, dragged down to y=' + ar.y.toFixed(1) + '%');
  ok(await p.locator('#vt-txt').getAttribute('dir') === 'rtl', 'the Arabic text field runs right-to-left');
  // stickers: a product and the logo
  await V(p, () => window.KBB_VIDEO.seek(0)); await p.click('.v-tab[data-vt="stick"]'); await p.click('#vs-prods button[title="Anua PDRN cream"]'); await p.click('#vs-addlogo');
  vp = await V(p, () => window.KBB_VIDEO.vp); ok(vp.ovs.length === 4 && vp.ovs[2].t === 'prod' && vp.ovs[3].t === 'logo', 'product cut-out and logo added as overlays');
  await V(p, () => { const o = window.KBB_VIDEO.vp.ovs; o[2].y = 60; o[2].x = 80; o[2].w = 22; o[2].e = 3.5; window.KBB_VIDEO.changed('st'); });

  // ── music: trimmed, faded, quieter
  await p.click('.v-tab[data-vt="audio"]');
  await p.fill('#va-a', '1'); await p.locator('#va-a').dispatchEvent('change'); await p.fill('#va-b', '9'); await p.locator('#va-b').dispatchEvent('change');
  for (const [id, v] of [['va-fi', '1'], ['va-fo', '1'], ['va-vol', '60']]) await p.locator('#' + id).evaluate((n, v) => { n.value = v; n.dispatchEvent(new Event('input', { bubbles: true })); }, v);
  vp = await V(p, () => window.KBB_VIDEO.vp); ok(vp.au.a === 1 && vp.au.b === 9 && vp.au.fi === 1 && vp.au.vol === 60, 'music trimmed to 1–9 s of the song, 1 s fades, 60%');

  // screenshots of the editor at 1280 (text selected)
  await p.click('.v-tab[data-vt="text"]'); await V(p, () => { window.KBB_VIDEO.select('ov', window.KBB_VIDEO.vp.ovs[1].id); window.KBB_VIDEO.showTab('text'); window.KBB_VIDEO.seek(3.2); });
  await p.waitForTimeout(600); await p.screenshot({ path: path.join(SHOTS, 'video-editor-1280.png'), fullPage: true });

  // ── export MP4
  await p.click('.v-tab[data-vt="export"]'); const what = await p.textContent('#vx-what'); ok(/MP4/.test(what), 'Export tab: ' + what);
  const t0 = Date.now(); const mp4 = await dl(p, () => p.click('#vx-go'), 'vid-export.mp4'); const secs = (Date.now() - t0) / 1000;
  const st = await p.textContent('#vx-st'); console.log('     ' + st);
  const pr = probe(mp4), vs = pr.streams.find((s) => s.codec_type === 'video'), as = pr.streams.find((s) => s.codec_type === 'audio');
  console.log('     ffprobe: ' + JSON.stringify(pr));
  ok(Math.abs(+pr.format.duration - 7) < .15, 'MP4 duration ' + pr.format.duration + ' s (expected 7)');
  ok(vs && vs.codec_name === 'h264' && vs.width === 1080 && vs.height === 1920, 'video stream h264 1080×1920');
  ok(as && /aac|opus/.test(as.codec_name) && as.channels === 2, 'audio stream ' + (as && as.codec_name) + ' stereo ' + (as && as.sample_rate) + ' Hz (AAC where the browser has an AAC encoder, Opus otherwise)');
  console.log('     export took ' + secs.toFixed(1) + ' s for 7 s of video');
  // the sound landed where it should: B (0–4 s) muted -> no 880 Hz; A (4–7 s) keeps its 440 Hz; music 330 Hz under both
  const b880 = band(mp4, 1.5, 2, 880), a440 = band(mp4, 4.5, 2, 440), b440 = band(mp4, 1.5, 2, 440), m330 = band(mp4, 2, 2, 330);
  ok(b880 < -50 && a440 > -30 && a440 - b440 > 12 && m330 > -35, `muted clip is silent (880 Hz ${b880} dB), clip A audible (440 Hz ${a440} dB vs ${b440} dB under B), music under it (330 Hz ${m330} dB)`);
  for (const [t, n] of [[1.0, 'frame-1s-fit-blur-english'], [3.2, 'frame-3s-both-texts'], [5.5, 'frame-5s-zoomed-arabic']]) sh(`ffmpeg -v error -y -ss ${t} -i "${mp4}" -frames:v 1 "${path.join(SHOTS, 'export-' + n + '.png')}"`);
  // crop landed: at 1 s (B, fit) the top and bottom are blur fill, not the bars; at 5.5 s (A, fill zoom) the frame is edge to edge
  const px = (file, x, y) => [...cp.execSync(`ffmpeg -v error -i "${file}" -vf "crop=1:1:${x}:${y},format=rgb24" -f rawvideo -`)];
  const top = px(path.join(SHOTS, 'export-frame-1s-fit-blur-english.png'), 540, 60), mid = px(path.join(SHOTS, 'export-frame-1s-fit-blur-english.png'), 20, 960);
  ok(top.reduce((a, b) => a + b, 0) > 40 && mid.reduce((a, b) => a + b, 0) > 40, 'B fitted with blur fill: above the picture is blurred colour (rgb ' + top + '), not black');
  const z1 = px(path.join(SHOTS, 'export-frame-5s-zoomed-arabic.png'), 4, 4), z2 = px(path.join(SHOTS, 'export-frame-5s-zoomed-arabic.png'), 1075, 1915);
  ok(z1.concat(z2).some((v) => v > 30), 'A filled edge to edge (corners rgb ' + z1 + ' / ' + z2 + '): frames in shots/video/export-*.png');

  // export without sound
  await p.check('#vx-noaudio'); const mp4b = await dl(p, () => p.click('#vx-go'), 'vid-export-nosound.mp4'); await p.uncheck('#vx-noaudio');
  const pr2 = probe(mp4b); ok(!pr2.streams.some((s) => s.codec_type === 'audio') && Math.abs(+pr2.format.duration - 7) < .15, 'Export without sound: no audio track, ' + pr2.format.duration + ' s');
  // still frame PNG at exact size
  await V(p, () => window.KBB_VIDEO.seek(3.2)); const png = await dl(p, () => p.click('#vx-png'), 'vid-still.png');
  const ps = pngSize(fs.readFileSync(png)); ok(ps[0] === 1080 && ps[1] === 1920, 'still frame PNG is ' + ps.join('×'));

  // ── save -> reload restores the project
  await p.click('#v-save'); await p.waitForFunction(() => /Saved/.test(document.getElementById('v-status').textContent));
  const before = await V(p, () => JSON.stringify(window.KBB_VIDEO.vp));
  await p.close(); { const r = await open(ctx); r.errs.push(...errs); r.net.push(...net); p = r.p; errs = r.errs; net = r.net; }
  const after = await V(p, () => JSON.stringify(window.KBB_VIDEO.vp));
  ok(after === before, 'reload: Video mode comes back with the same project (' + JSON.parse(after).clips.length + ' clips, ' + JSON.parse(after).ovs.length + ' overlays, music)');
  ok(await V(p, () => Object.keys(window.KBB_VIDEO.media).length) === 3, 'reload: the 3 media files come back from IndexedDB');

  // ── project export (.zip) -> import into a clean browser
  await p.click('#modesw button[data-m="ads"]'); await p.click('.tab[data-tab="export"]');
  const zip = await dl(p, () => p.click('#xp'), 'vid-project.zip');
  ok(/\.zip$/.test(zip) && fs.readFileSync(zip).slice(0, 2).toString() === 'PK', 'Export project gives a .zip (' + (fs.statSync(zip).size / 1048576).toFixed(1) + ' MB) when there are video projects');
  const ctx2 = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const q = await open(ctx2);
  ok(await V(q.p, () => window.KBB_VIDEO.vp.clips.length) === 0, 'clean browser starts with an empty video');
  await q.p.click('#modesw button[data-m="ads"]'); await q.p.click('.tab[data-tab="export"]');
  await q.p.setInputFiles('#xp-in', zip); await q.p.waitForFunction(() => /Imported/.test(document.getElementById('status').textContent), null, { timeout: 60000 });
  ok(/1 video project/.test(await q.p.textContent('#status')), 'import: ' + await q.p.textContent('#status'));
  await q.p.click('#modesw button[data-m="video"]'); await q.p.click('#v-mine').catch(() => {});
  await q.p.locator('#vmain details:has(#v-mine) summary').click(); await q.p.locator('#v-mine .it button', { hasText: 'Open' }).first().click();
  await q.p.waitForFunction(() => window.KBB_VIDEO.vp.clips.length === 2);
  const imp = await V(q.p, () => window.KBB_VIDEO.vp), orig = JSON.parse(before);
  const strip = (v) => JSON.stringify({ ab: v.ab, bg: v.bg, clips: v.clips.map((c) => [c.a, c.b, c.fit, c.z, c.px, c.mute]), ovs: v.ovs.map((o) => [o.t, o.txt, o.s, o.e, o.x, o.y]), au: v.au && [v.au.a, v.au.b, v.au.o, v.au.vol, v.au.fi, v.au.fo] });
  ok(strip(imp) === strip(orig), 'the imported project matches the original (clips, framing, texts, timing, music)');
  ok(await V(q.p, () => Object.values(window.KBB_VIDEO.media).map((m) => m.k).sort().join()) === 'audio,video,video', 'the clips and the song came inside the zip');
  // hostile zip: bad ids / types / paths are dropped, nothing breaks
  const JSZ = path.join(TMP, 'vid-hostile.zip');
  await V(q.p, async () => {
    const z = new JSZip(); z.file('project.json', JSON.stringify({ app: 'kbb-ad-studio', v: 1, projects: [], images: [], video: { v: 1, media: [{ id: '../x', type: 'text/html', file: '../../evil.html' }, { id: 'm000000000000', type: 'video/mp4', file: 'media/m000000000000.mp4' }], projects: [{ name: '<img src=x onerror=alert(1)>', clips: [{ m: 'm000000000000', a: 0, b: 5 }], ovs: [{ t: 'text', txt: '<b>hi</b>', fn: 'evil', fs: 9999, tc: 'red;x', s: -5, e: 1e9 }, { t: 'script' }] }] } }));
    z.file('media/m000000000000.mp4', 'not really a video');
    window.__hz = await z.generateAsync({ type: 'base64' });
  });
  fs.writeFileSync(JSZ, Buffer.from(await V(q.p, () => window.__hz), 'base64'));
  await q.p.click('#modesw button[data-m="ads"]'); await q.p.setInputFiles('#xp-in', JSZ); await q.p.waitForFunction(() => /Imported|Not imported/.test(document.getElementById('status').textContent) && !/Importing/.test(document.getElementById('status').textContent), null, { timeout: 30000 });
  const hz = await V(q.p, () => window.KBB_VIDEO.cleanVP({ name: '<img src=x onerror=alert(1)>', ovs: [{ t: 'text', txt: '<b>hi</b>', fn: 'evil', fs: 9999, tc: 'red;x', s: -5, e: 1e9 }, { t: 'script' }] }));
  ok(hz.ovs.length === 1 && hz.ovs[0].fn === 'poppins' && hz.ovs[0].fs === 40 && hz.ovs[0].tc === '#FFFFFF' && hz.ovs[0].s === 0 && hz.clips.length === 0, 'hostile project: unknown font/colour/size/type cleaned, clip with undecodable media dropped');
  ok(await V(q.p, () => Object.keys(window.KBB_VIDEO.media).length) === 3, 'the fake media file in the hostile zip was refused (still 3 files)');
  ok(q.errs.length === 0, 'clean browser: no console errors' + (q.errs.length ? ': ' + q.errs.join(' | ') : ''));

  // ── fallback: no WebCodecs -> MediaRecorder WebM in real time
  await p.click('#modesw button[data-m="video"]'); await V(p, () => { window.KBB_VIDEO.forceFallback = true; });
  await p.click('.v-tab[data-vt="export"]'); ok(/WebM/.test(await p.textContent('#vx-what')), 'without WebCodecs the Export tab says: ' + await p.textContent('#vx-what'));
  const webm = await dl(p, () => p.click('#vx-go'), 'vid-fallback.webm'); const pr3 = probe(webm);
  ok(pr3.streams.some((s) => s.codec_type === 'video' && /vp8|vp9/.test(s.codec_name) && s.width === 1080 && s.height === 1920) && pr3.streams.some((s) => s.codec_type === 'audio'), 'fallback WebM: ' + pr3.streams.map((s) => s.codec_name + (s.width ? ' ' + s.width + '×' + s.height : '')).join(' + '));
  await V(p, () => { window.KBB_VIDEO.forceFallback = false; });

  // ── Ads mode still there and working
  await p.click('#modesw button[data-m="ads"]'); ok(await p.isVisible('#stage .cv') && await p.isHidden('#vmain'), 'Ads mode is back, untouched');
  await p.click('#modesw button[data-m="video"]');

  // ── phone
  const ctx3 = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  const m = await open(ctx3);
  await m.p.setInputFiles('#v-file', [A, B, MUS]); await m.p.waitForFunction(() => /Imported/.test(document.getElementById('v-status').textContent), null, { timeout: 60000 });
  await V(m.p, () => { window.KBB_VIDEO.seek(1.2); window.KBB_VIDEO.addText({ txt: 'Glow Week', y: 20 }); window.KBB_VIDEO.addText({ txt: 'تخفيضات الجمعة البيضاء', fn: 'cairo', fw: 900, y: 78, pill: true, pc: '#E8306F' }); window.KBB_VIDEO.seek(2.2); });
  await m.p.waitForTimeout(600);
  const sw = await V(m.p, () => document.documentElement.scrollWidth); ok(sw === 390, '390 px: scrollWidth ' + sw + ' (no sideways scroll)');
  await m.p.screenshot({ path: path.join(SHOTS, 'video-editor-390.png'), fullPage: true });
  ok(m.errs.length === 0, 'phone: no console errors' + (m.errs.length ? ': ' + m.errs.join(' | ') : ''));

  ok(errs.length === 0, 'no console errors' + (errs.length ? ': ' + errs.join(' | ') : ''));
  const allNet = net.concat(q.net, m.net); ok(allNet.length === 0, 'no network requests' + (allNet.length ? ': ' + allNet.join(' ') : ''));
  await b.close();
  console.log(fails ? fails + ' FAILED' : 'ALL PASSED'); process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
