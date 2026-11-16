#!/usr/bin/env node
// KBB Studio — R1 check: engine security, then a real editing session in Chrome (import, split, trim, move,
// marquee, undo, save/reload), an MP4 export verified with ffprobe, zero console errors, screenshots.
//
//   tools/make-test-clips.sh && NODE_PATH=/home/user/kbbstore/node_modules node tools/check-r1.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const http = require('http');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');


const SHOTS = path.join(H.ROOT, 'shots', 'r1');
const CLIPS = path.join(H.ROOT, 'tools', 'out');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0;
const ok = (c, msg) => { console.log((c ? 'PASS ' : 'FAIL ') + msg); if (!c) failures++; };

function raw(e, method, p, { token = e.token, host, body } = {}) {
  return new Promise((res) => {
    const u = new URL(e.url);
    const req = http.request({ host: u.hostname, port: u.port, method, path: p, headers: Object.assign({ Host: host || u.host }, token ? { 'X-RS-Token': token } : {}, body ? { 'Content-Type': 'application/json' } : {}) }, (r) => {
      let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => res({ status: r.statusCode, body: d }));
    });
    req.on('error', (err) => res({ status: 0, body: String(err) }));
    if (body) req.write(typeof body === 'string' ? body : JSON.stringify(body));
    req.end();
  });
}

async function engineChecks(e, dir) {
  const q = encodeURIComponent;
  ok((await raw(e, 'GET', '/api/info', { token: '' })).status === 401, 'API without the session token: 401');
  ok((await raw(e, 'GET', '/api/info', { host: 'evil.example:80' })).status === 403, 'a foreign Host header (DNS rebinding): 403');
  for (const p of ['../', 'workspaces/../..', 'workspaces/../../etc', '/etc', 'app/../../..', 'workspaces/./x', 'workspaces//x']) {
    ok((await raw(e, 'GET', '/api/list?path=' + q(p))).status === 403, `list ${p}: refused`);
  }
  for (const p of ['workspaces/../app/x.js', 'app/evil.js', 'vendor/x.js', '/tmp/x', 'workspaces/.hidden/x', 'engine/x', 'workspaces/a\\..\\..\\x']) {
    ok((await raw(e, 'PUT', '/api/write?path=' + q(p), { body: 'x' })).status === 403, `write ${p}: refused`);
  }
  ok(![200, 206].includes((await raw(e, 'GET', '/app/%2e%2e/engine/main.go')).status), 'static %2e%2e traversal: not served');
  ok(![200, 206].includes((await raw(e, 'GET', '/engine/main.go')).status), 'files outside the served roots: not served');
  fs.mkdirSync(path.join(dir, 'workspaces'), { recursive: true });
  fs.symlinkSync('/etc', path.join(dir, 'workspaces', 'evil'));
  ok((await raw(e, 'GET', '/workspaces/evil/hostname')).status === 403, 'symlink escaping the folder: read refused');
  ok((await raw(e, 'PUT', '/api/write?path=' + q('workspaces/evil/x'), { body: 'x' })).status === 403, 'symlink escaping the folder: write refused');
  fs.unlinkSync(path.join(dir, 'workspaces', 'evil'));
  ok((await raw(e, 'PUT', '/api/write?path=' + q('workspaces/zz-test/a.txt'), { body: 'hello' })).status === 200, 'a write inside workspaces/ works');
  ok((await raw(e, 'GET', '/workspaces/zz-test/a.txt')).body === 'hello', '...and reads back');
  ok((await raw(e, 'POST', '/api/delete?path=' + q('workspaces/zz-test'))).status === 403, 'deleting a whole workspace: refused');
  fs.rmSync(path.join(dir, 'workspaces', 'zz-test'), { recursive: true, force: true });
  // the internet gate
  ok((await raw(e, 'GET', '/net/get?url=' + q('https://huggingface.co/x/y/resolve/main/config.json'))).status === 403, 'net without a grant: refused');
  ok((await raw(e, 'POST', '/api/permission', { body: { url: 'https://example.com/', scope: 'exact' } })).status === 403, 'a grant for a host off the allowlist: refused');
  ok((await raw(e, 'POST', '/api/permission', { body: { url: 'http://huggingface.co/a/b/', scope: 'repo' } })).status === 403, 'a plain-http grant: refused');
  ok((await raw(e, 'POST', '/api/permission', { body: { url: 'https://huggingface.co/', scope: 'repo' } })).status === 403, 'a grant for all of huggingface.co: refused');
  ok((await raw(e, 'POST', '/api/permission', { body: { url: 'https://huggingface.co/a/b/resolve/main/c.json', scope: 'exact' } })).status === 200, 'an exact grant is accepted');
  ok((await raw(e, 'GET', '/net/get?url=' + q('https://huggingface.co/a/b/resolve/main/other.json'))).status === 403, '...and covers ONLY that exact item');
  ok((await raw(e, 'GET', '/net/get?url=' + q('https://evil.example/a/b/resolve/main/c.json'))).status === 403, 'net to a host off the allowlist: refused');
  ok((await raw(e, 'POST', '/net/download', { body: { url: 'https://huggingface.co/a/b/resolve/main/c.json', dest: 'workspaces/x' } })).status === 403, 'downloads outside models/: refused');
  ok((await raw(e, 'POST', '/api/models/install', { body: { ids: ['whisper-base'] } })).status === 400, 'model install without Allow: refused');
}

(async () => {
  for (const f of ['portrait.mp4', 'wide.mp4']) if (!fs.existsSync(path.join(CLIPS, f))) throw new Error('run tools/make-test-clips.sh first');
  const dir = H.makeStudio();
  const e = await H.startEngine(dir);
  await engineChecks(e, dir);

  const browser = await H.launchChrome();
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  const shot = async (name, w = 1280, h = 800) => { await page.setViewportSize({ width: w, height: h }); await page.waitForTimeout(450); await page.screenshot({ path: path.join(SHOTS, `${name}-${w}.png`) }); };
  const ed = (fn, arg) => page.evaluate(fn, arg);
  const center = async (sel) => { const b = await page.locator(sel).first().boundingBox(); return { x: b.x + b.width / 2, y: b.y + b.height / 2, b }; };
  const drag = async (from, to, steps = 12, mid) => { await page.mouse.move(from.x, from.y); await page.mouse.down(); await page.mouse.move(to.x, to.y, { steps }); if (mid) await mid(); await page.mouse.up(); await page.waitForTimeout(150); };

  await page.goto(e.url + '/app/');
  await page.waitForSelector('.setup-form', { timeout: 8000 });
  ok(true, 'first run opens the model setup');
  await shot('setup', 1280, 800);
  await page.click('#su-later');
  await page.waitForSelector('.home-main');
  ok((await page.locator('.ws-switch .ws-name').textContent()) === 'KBB', 'the KBB company is seeded and active');
  await shot('home', 1280, 800); await shot('home', 1920, 1080);
  // companies: switch to Planet Careers and back
  await page.click('.ws-switch'); await page.click('.ws-item:has-text("Planet Careers")');
  await page.waitForSelector('.home-hero h1:has-text("Planet Careers")');
  ok(true, 'switching to Planet Careers is instant');
  await page.click('.ws-switch'); await page.click('.ws-item:has-text("KBB")');
  await page.waitForSelector('.home-hero h1:has-text("K-Beauty Bliss")');
  await page.setViewportSize({ width: 1280, height: 800 });

  // a new reel from a dropped video
  await page.setInputFiles('.home-drop input[type=file]', path.join(CLIPS, 'portrait.mp4'));
  await page.waitForSelector('.tl-item.t-clip', { timeout: 30000 });
  await page.setInputFiles('.bin input[type=file]', path.join(CLIPS, 'wide.mp4'));
  await page.waitForFunction(() => kbbEditor.project.media.length === 2, null, { timeout: 30000 });
  await page.locator('.mcard').nth(1).locator('button.add').click();
  await page.waitForFunction(() => kbbEditor.project.tracks.find((t) => t.kind === 'video').items.length === 2);
  let s = await ed(() => ({ d: kbbEditor.duration(), m: kbbEditor.project.media.map((m) => [m.w, m.h, Math.round(m.dur), m.hasAudio]), ws: kbbEditor.project.workspace }));
  ok(Math.abs(s.d - 28) < 0.05 && s.m[0].join() === '1080,1920,20,true' && s.m[1].join() === '1920,1080,8,true', `imported 20 s 9:16 + 8 s 16:9 with sound, timeline ${s.d.toFixed(2)} s`);
  ok(s.ws === 'kbb', 'the project remembers its company (workspace: kbb)');

  // text + sticker at 2 s
  await ed(() => kbbEditor.seek(2));
  await page.click('.bin-tab:has-text("Text")'); await page.click('.preset:has-text("Title")');
  await page.click('.bin-tab:has-text("Stickers")'); await page.locator('.emoji').first().click();
  await page.waitForFunction(() => kbbEditor.project.tracks.some((t) => t.items.some((i) => i.type === 'sticker')));
  await page.click('.bin-tab:has-text("Media")');
  await page.waitForTimeout(800);

  // MARQUEE on the monitor, starting over the video
  const st = (await page.locator('.pv-overlay').boundingBox());
  await ed(() => kbbEditor.select([]));
  await drag({ x: st.x + st.width * 0.04, y: st.y + st.height * 0.05 }, { x: st.x + st.width * 0.96, y: st.y + st.height * 0.62 }, 14, async () => {
    ok(await page.locator('.marquee').count() === 1, 'a marquee box is drawn while dragging over the video');
    await page.screenshot({ path: path.join(SHOTS, 'marquee-1280.png') });
  });
  s = await ed(() => kbbEditor.selectedItems().map((x) => x.item.type).sort().join());
  ok(s === 'sticker,text', `marquee started over the video selected the text and the sticker, not the video (${s})`);
  // move both on the canvas, then undo
  const before = await ed(() => kbbEditor.selectedItems().map((x) => [x.item.x, x.item.y]));
  const tb = await ed(() => { const it = kbbEditor.selectedItems().find((x) => x.item.type === 'text').item; return { x: it.x + it.w / 2, y: it.y + it.h / 2, k: kbbEditor.preview.k }; });
  const p0 = { x: st.x + tb.x * tb.k, y: st.y + tb.y * tb.k };
  await drag(p0, { x: p0.x + 40, y: p0.y + 60 });
  const after = await ed(() => kbbEditor.selectedItems().map((x) => [x.item.x, x.item.y]));
  ok(after.every((a, i) => a[0] !== before[i][0] && a[1] !== before[i][1]), 'dragging moves BOTH selected items');
  await page.keyboard.press('Control+z');
  const undone = await ed(() => kbbEditor.selectedItems().map((x) => [x.item.x, x.item.y]));
  ok(JSON.stringify(undone) === JSON.stringify(before), 'one undo puts both back (a drag is one undo step)');
  // shift-click toggles
  const selB = await ed(() => [...kbbEditor.sel].join());
  await page.keyboard.down('Shift'); await page.mouse.click(p0.x, p0.y); await page.keyboard.up('Shift');
  const selA = await ed(() => kbbEditor.selectedItems().map((x) => x.item.type).join());
  ok(selA === 'sticker', `Shift-click removes one item from the selection (${selA})`);
  await page.keyboard.press('Escape');

  // SPLIT at 5 s
  await ed(() => kbbEditor.seek(5));
  await page.locator('.k-video .tl-item').first().click();
  await ed(() => kbbEditor.seek(5));
  await page.keyboard.press('s');
  s = await ed(() => kbbEditor.project.tracks.find((t) => t.kind === 'video').items.map((i) => [+i.t0.toFixed(2), +i.in.toFixed(2), +i.out.toFixed(2)]));
  ok(s.length === 3 && s[0][2] === 5 && s[1][0] === 5 && s[1][1] === 5, 'S splits the clip at the playhead: ' + JSON.stringify(s));
  // TRIM the first piece's right edge
  const snap0 = await ed(() => JSON.stringify(kbbEditor.project.tracks));
  const r = await page.locator('.k-video .tl-item').first().locator('.trim.r').boundingBox();
  await drag({ x: r.x + 3, y: r.y + r.height / 2 }, { x: r.x - 60, y: r.y + r.height / 2 });
  const out = await ed(() => kbbEditor.project.tracks.find((t) => t.kind === 'video').items[0].out);
  ok(out < 4.9, `trim handle shortens the clip (out ${out.toFixed(2)} s)`);
  await page.keyboard.press('Control+z');
  ok((await ed(() => JSON.stringify(kbbEditor.project.tracks))) === snap0, 'undo restores the trim exactly');
  await page.keyboard.press('Control+Shift+z');
  ok((await ed(() => kbbEditor.project.tracks.find((t) => t.kind === 'video').items[0].out)) === out, 'redo re-applies it');
  // MOVE the text in the timeline
  const t0 = await ed(() => kbbEditor.project.tracks.find((t) => t.kind === 'text').items[0].t0);
  const ti = await center('.k-text .tl-item');
  await drag(ti, { x: ti.x + 80, y: ti.y });
  const t1 = await ed(() => kbbEditor.project.tracks.find((t) => t.kind === 'text').items[0].t0);
  ok(t1 > t0 + 0.5, `dragging a clip in the timeline moves it (${t0.toFixed(2)} → ${t1.toFixed(2)} s)`);
  // MARQUEE in the timeline
  await ed(() => kbbEditor.select([]));
  const lane = await page.locator('.k-video .tl-lane').boundingBox();
  const ov = await page.locator('.k-text .tl-lane').boundingBox();
  await drag({ x: lane.x + 2, y: ov.y + 2 }, { x: lane.x + lane.width * 0.5, y: lane.y + lane.height - 4 });
  s = await ed(() => kbbEditor.sel.size);
  ok(s >= 3, `marquee in the timeline selects clips across tracks (${s})`);
  await page.keyboard.press('Escape');
  // copy/paste/duplicate + ripple delete + markers
  const n0 = await ed(() => kbbEditor.project.tracks.reduce((a, t) => a + t.items.length, 0));
  await page.locator('.k-text .tl-item').first().click();
  await page.keyboard.press('Control+d');
  ok((await ed(() => kbbEditor.project.tracks.reduce((a, t) => a + t.items.length, 0))) === n0 + 1, '⌘D duplicates');
  await page.keyboard.press('Control+z');
  await page.locator('.k-video .tl-item').nth(1).click();
  const dBefore = await ed(() => kbbEditor.duration());
  await page.keyboard.press('Shift+Delete');
  const dAfter = await ed(() => kbbEditor.duration());
  ok(dAfter < dBefore - 1, `ripple delete closes the gap (${dBefore.toFixed(1)} → ${dAfter.toFixed(1)} s)`);
  await page.keyboard.press('Control+z');
  await page.keyboard.press('m');
  ok((await ed(() => kbbEditor.project.markers.length)) === 1, 'M adds a marker');
  // group
  await ed(() => kbbEditor.select(kbbEditor.project.tracks.flatMap((t) => t.items.filter((i) => i.type === 'text' || i.type === 'sticker').map((i) => i.id))));
  await page.keyboard.press('Control+g');
  await ed(() => kbbEditor.select([]));
  const stk = await ed(() => kbbEditor.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'sticker').id);
  await ed((id) => kbbEditor.select([id]), stk);
  ok((await ed(() => kbbEditor.sel.size)) === 2, 'grouped items select together');
  await page.keyboard.press('Escape');

  // screenshots of the editor
  await ed(() => kbbEditor.seek(3));
  await page.locator('.k-video .tl-item').first().click();
  await shot('editor-inspector', 1280, 800); await shot('editor-inspector', 1920, 1080);
  await ed(() => kbbEditor.select([]));
  await shot('editor', 1280, 800); await shot('editor', 1920, 1080);
  await page.setViewportSize({ width: 1280, height: 800 });
  await ed(() => { const it = kbbEditor.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'text'); kbbEditor.seek(it.t0 + 0.5); kbbEditor.sel = new Set([it.id]); kbbEditor.inspector.render(true); kbbEditor.timeline.renderSelection(); kbbEditor.preview.drawOverlay(); });
  await shot('inspector-text', 1280, 800);
  await ed(() => kbbEditor.select([]));
  await page.click('.doit-btn[data-mod="auto-captions"]');
  ok(await page.locator('.toast:has-text("coming in the next update")').count() === 1, '"Do it for me" slots say "coming in the next update" until a module registers');

  // playback: Space plays (the clock moves), Space pauses
  await ed(() => kbbEditor.seek(0));
  await page.keyboard.press('Space'); await page.waitForTimeout(1200); await page.keyboard.press('Space');
  const tp = await ed(() => kbbEditor.t);
  ok(tp > 0.7 && tp < 2.5, `Space plays and pauses (played to ${tp.toFixed(2)} s)`);
  // aspect switch re-lays the reel and is one undo step
  await page.click('.aspect button[data-v="1:1"]');
  const sq = await ed(() => [kbbEditor.project.canvas.w, kbbEditor.project.canvas.h, kbbEditor.project.tracks.find((t) => t.kind === 'video').items[0].w]);
  ok(sq[0] === 1080 && sq[1] === 1080 && sq[2] >= 1080, `1:1 re-lays the reel (${sq.join('×')})`);
  await shot('editor-square', 1280, 800);
  await page.keyboard.press('Control+z');
  ok((await ed(() => kbbEditor.project.canvas.aspect)) === '9:16', 'undo returns to 9:16');

  // save + reload
  await ed(() => kbbEditor.save());
  const items = await ed(() => kbbEditor.project.tracks.map((t) => t.items.length).join());
  await page.reload();
  await page.waitForSelector('.tl-item', { timeout: 20000 });
  ok((await ed(() => kbbEditor.project.tracks.map((t) => t.items.length).join())) === items, 'autosaved project reopens identical after a reload');

  // EXPORT
  await page.click('.export-btn');
  await page.waitForSelector('.export-form');
  await shot('export-dialog', 1280, 800);
  await page.setViewportSize({ width: 1280, height: 800 });
  const T = await ed(() => kbbEditor.duration());
  await page.click('#x-go');
  await page.waitForFunction(() => kbbEditor.lastExport, null, { timeout: 300000 });
  const lx = await ed(() => kbbEditor.lastExport);
  ok(!lx.error, 'export finished: ' + (lx.note || lx.error));
  await page.waitForTimeout(500);
  await shot('export-done', 1280, 800);
  const file = path.join(dir, lx.path || 'none');
  if (fs.existsSync(file)) {
    const pr = JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration,size:stream=codec_type,codec_name,width,height,r_frame_rate,sample_rate,channels', '-of', 'json', file]).toString());
    const v = pr.streams.find((x) => x.codec_type === 'video'), a = pr.streams.find((x) => x.codec_type === 'audio');
    const dur = +pr.format.duration;
    ok(v && v.codec_name === 'h264' && v.width === 1080 && v.height === 1920 && v.r_frame_rate === '30/1', `video stream h264 1080×1920 30 fps (${v && v.codec_name} ${v && v.width}×${v && v.height} ${v && v.r_frame_rate})`);
    ok(a && ['aac', 'opus'].includes(a.codec_name) && +a.sample_rate === 48000 && a.channels === 2, `audio stream ${a && a.codec_name} 48 kHz stereo`);
    ok(Math.abs(dur - T) < 0.15, `duration ${dur.toFixed(2)} s matches the timeline ${T.toFixed(2)} s`);
    ok(+pr.format.size > 200000, `file size ${(+pr.format.size / 1048576).toFixed(1)} MB`);
    // preview = export: the frame at 1 s through renderFrame matches the exported frame (mean abs difference)
    const png = path.join(CLIPS, 'r1-export-1s.png');
    execFileSync('ffmpeg', ['-v', 'error', '-y', '-ss', '1.0', '-i', file, '-frames:v', '1', '-vf', 'scale=270:480', png]);
    const exp = fs.readFileSync(png).toString('base64');
    const diff = await page.evaluate(async (b64) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
      const ed = window.kbbEditor; const R = await import('/app/core/render.js');
      const m = ed.project.media[0]; const v = document.createElement('video'); v.muted = true; v.src = ed.media.url(m);
      await new Promise((r) => (v.onloadeddata = r)); v.currentTime = 1.0; await new Promise((r) => (v.onseeked = r));
      const a = new OffscreenCanvas(270, 480), b = new OffscreenCanvas(270, 480);
      R.renderFrame(a.getContext('2d'), ed.project, 1.0, { scale: 270 / ed.project.canvas.w, source: (it) => (it.media === m.id ? v : null) });
      b.getContext('2d').drawImage(img, 0, 0, 270, 480);
      const A = a.getContext('2d').getImageData(0, 0, 270, 480).data, B = b.getContext('2d').getImageData(0, 0, 270, 480).data;
      let s = 0; for (let i = 0; i < A.length; i += 4) s += Math.abs(A[i] - B[i]) + Math.abs(A[i + 1] - B[i + 1]) + Math.abs(A[i + 2] - B[i + 2]);
      return s / (A.length / 4) / 3;
    }, exp);
    ok(diff < 12, `preview = export: frame at 1 s differs by ${diff.toFixed(1)}/255 on average`);
  } else ok(false, 'exported file exists in the workspace exports/ folder');

  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.slice(0, 5).join(' | ') : ''));
  // Stop in the app shuts the engine down
  await page.click('.modal-head .icon-btn');
  await page.click('.stop-btn');
  await page.waitForSelector('.stopped');
  await new Promise((r) => setTimeout(r, 1200));
  ok(e.exited === true, 'the Stop button shuts the engine down');
  await browser.close();
  await H.stopEngine(e);
  // closing the last tab stops the engine by itself (the event stream is the heartbeat)
  const e2 = await H.startEngine(dir, ['--idle', '2s']);
  const b2 = await H.launchChrome(); const p2 = await b2.newPage();
  await p2.goto(e2.url + '/app/'); await p2.waitForSelector('.home-main, .setup-form');
  await p2.close();
  await new Promise((r) => setTimeout(r, 4500));
  ok(e2.exited === true, 'closing the browser tab stops the engine within seconds');
  await b2.close(); await H.stopEngine(e2);
  fs.rmSync(dir, { recursive: true, force: true });
  console.log(failures ? `\n${failures} FAILED` : '\nall R1 checks passed');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(1); });
