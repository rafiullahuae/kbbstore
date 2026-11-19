#!/usr/bin/env node
// KBB Studio — Elements 2 check (18 Nov 2026): contact cards, the one-click logo badge, the country globe badges (one
// click "Badge every country I mention"), the cut-out transitions. Headless Chromium against the Linux engine, in the
// real editor.
//
//   catalogue  ≥ 12 contact-card designs, 5 logo shapes, ≥ 6 badge looks, ≥ 6 cut-out transitions; each one renders
//              (several moments) with no console error
//   cta        the values come from ⚙ Configure → Contact & social (the workspace's contactInfo / social); every word is a
//              text slot (inspector field, typed in the field, double-clicked on the video); the QR is a real code that
//              decodes (OpenCV) to the WhatsApp link
//   logo       preparation on synthetic logos: white margins trimmed + white background removed, a solid plate removed
//              (an app icon), transparent margins trimmed; the plate contrast; every shape placed in one click (ONE
//              undo step), in a corner inside the safe area, the logo pixels on the plate, whole video
//   badges     a synthetic caption word list with mentions (EN, AR with prefixes, Urdu, Hindi): the right countries at
//              the right times, two mentions of one country merged, ONE undo step; a caption edit re-derives them in
//              the same step as the edit
//   cut        each cut-out transition on a synthetic person clip (the drawn presenter, real person segmentation): the
//              ground-truth person region and the background region show the clip they should; a cut with no person
//              falls back to a wipe (and the status says so)
//   same       preview frame = export frame (mean difference < 6/255) with a card, a badge, a logo and a transition on
//   keep       undo / redo, save + reload (same items, same pixels), an old project draws pixel-identical
//   shots      shots/elements2/*.png: 8 stills + the inspectors at 1440×900
//   previews   tools/out/elements2/*.mp4 (432×768, 4 s) + a .jpg poster for every design, shape, look and transition
//
// usage: NODE_PATH=/home/user/kbbstore/node_modules node tools/el2-check.cjs [--only cta,logo,...] [--no-previews]
'use strict';
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, OUT = path.join(ROOT, 'tools', 'out', 'el2'), STUDIO = path.join(OUT, 'studio'), SHOTS = path.join(ROOT, 'shots', 'elements2'), PREV = path.join(ROOT, 'tools', 'out', 'elements2');
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const ONLY = arg('only', '') ? arg('only').split(',') : null, want = (s) => !ONLY || ONLY.includes(s);
const PREVIEWS = !process.argv.includes('--no-previews') && want('previews');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true }); fs.mkdirSync(PREV, { recursive: true });
const ff = (...a) => execFileSync('ffmpeg', ['-v', 'error', '-y', ...a], { maxBuffer: 1 << 30 });
let failures = 0; const results = [];
const ok = (cond, msg) => { const line = (cond ? 'PASS ' : 'FAIL ') + msg; console.log(line); results.push(line); if (!cond) failures++; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}
// the drawn presenter and b-roll of the background-removal check (tools/bg-check.cjs), with their ground-truth masks
const WORLD_JS = /const WORLD_JS = `([\s\S]*?)`;\n/.exec(fs.readFileSync(path.join(__dirname, 'bg-check.cjs'), 'utf8'))[1];
const FPS = 30;

(async () => {
  const T0 = Date.now();
  for (const d of ['app', 'vendor', 'brands', 'library', 'workspaces', 'settings', 'adstudio']) fs.rmSync(path.join(STUDIO, d), { recursive: true, force: true });
  fs.mkdirSync(path.join(STUDIO, 'models'), { recursive: true });
  for (const d of ['app', 'vendor', 'brands', 'library']) fs.cpSync(path.join(ROOT, d), path.join(STUDIO, d), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(STUDIO, 'models', 'manifest.json'));
  const LIB = path.join(STUDIO, 'library', 'el2-test'); fs.mkdirSync(LIB, { recursive: true });
  const e = await H.startEngine(STUDIO);
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined, args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist', '--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(180000);
  const errors = [], outside = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/No available adapters|AudioContext was not allowed|net::ERR_ABORTED/.test(m.text())) errors.push(m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  context.on('request', (r) => { const u = r.url(); if (!/^(http:\/\/127\.0\.0\.1:\d+\/|blob:|data:)/.test(u)) outside.push(u); });
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  const ev = (fn, a) => page.evaluate(fn, a);
  const shot = async (name) => { await page.waitForTimeout(350); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };
  // bring a section of the right column into view, under its sticky header
  const reveal = (sel) => ev((sel) => { const el = document.querySelector(sel); if (!el) return false; el.scrollIntoView({ block: 'start' }); let p = el.parentElement; while (p && p.scrollHeight <= p.clientHeight + 2) p = p.parentElement; if (p) p.scrollTop -= 70; return true; }, sel);

  // ── test media: the presenter (a person, 2 s) and the b-roll (no person, 2 s), drawn and encoded once ──
  await ev(WORLD_JS);
  for (const name of ['presenter', 'broll']) {
    const f = path.join(OUT, name + '.mp4');
    if (!fs.existsSync(f)) {
      const dir = path.join(OUT, name + '-frames'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
      for (let i = 0; i < 2 * FPS; i++) {
        const jpg = await ev(([nm, t]) => { const c = document.createElement('canvas'); c.width = 540; c.height = 960; window.W.draw(c, nm, t, 'rgb'); return c.toDataURL('image/jpeg', 0.93); }, [name, i / FPS]);
        fs.writeFileSync(path.join(dir, String(i).padStart(4, '0') + '.jpg'), Buffer.from(jpg.split(',')[1], 'base64'));
      }
      ff('-framerate', String(FPS), '-i', path.join(dir, '%04d.jpg'), '-f', 'lavfi', '-i', 'sine=frequency=220:duration=2:sample_rate=48000', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '16', '-pix_fmt', 'yuv420p', '-g', '15', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', f);
      fs.rmSync(dir, { recursive: true, force: true });
    }
    fs.copyFileSync(f, path.join(LIB, name + '.mp4'));
  }
  // synthetic logos: the KBB mark on a white square with wide white margins (PNG), and Planet Careers' app icon (a navy plate)
  const white = await ev(async () => {
    const svg = await (await fetch('/brands/kbb/logo-mark.svg')).text(), im = new Image(); im.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg); await im.decode();
    const c = document.createElement('canvas'); c.width = 600; c.height = 600; const x = c.getContext('2d'); x.fillStyle = '#ffffff'; x.fillRect(0, 0, 600, 600); x.drawImage(im, 150, 190, 300, 224);
    return c.toDataURL('image/png');
  });
  fs.writeFileSync(path.join(LIB, 'white-logo.png'), Buffer.from(white.split(',')[1], 'base64'));

  // ── the reel: presenter | b-roll | presenter (cuts at 2 s and 4 s) ──
  await page.setInputFiles('.home-drop input[type=file]', path.join(LIB, 'presenter.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  const setup = await ev(async () => {
    const ed = window.kbbEditor; window.ED = ed; window.CTX = ed.context();
    window.TH = await import('/app/themes/index.js'); window.EL = await import('/app/themes/elements.js'); window.C2 = await import('/app/themes/el2-cmds.js');
    window.CTA = await import('/app/themes/cta.js'); window.LG = await import('/app/themes/logo-badge.js'); window.GB = await import('/app/themes/globe-badge.js');
    window.CW = await import('/app/themes/country-words.js'); window.CT = await import('/app/themes/cutout-trans.js'); window.TT = await import('/app/themes/transitions.js');
    window.TX = await import('/app/themes/text.js'); window.TP = await import('/app/themes/text-panel.js'); window.TF = await import('/app/themes/text-fields.js');
    window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js'); window.P = await import('/app/core/project.js'); window.V = await import('/app/look/vision.js');
    window.QR = await import('/app/themes/qr.js');
    const b = await ed.media.importPath('library/el2-test/broll.mp4'); ed.addMediaToTimeline(b);
    const m = ed.project.media[0]; ed.addMediaToTimeline(m);
    window.items = () => ed.project.tracks.flatMap((t) => t.items);
    window.find = (id) => window.items().find((i) => i.id === id);
    window.steps = () => ed.history.undoStack.length;
    /** render the project at t (export path: decoded frames) into rgba at scale */
    window.RENDER = async (p, t, scale = 0.25) => {
      const W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh;
      const x = c.getContext('2d', { willReadFrequently: true });
      await window.R.prepareRender(p);
      const f = await window.E.openFrames(p, ed.media, scale);
      try { await f.load(t); window.R.renderFrame(x, p, t, { scale, source: f.source }); } finally { f.close(); }
      return { W, H: Hh, d: x.getImageData(0, 0, W, Hh).data, url: () => c.toDataURL('image/png') };
    };
    window.vclips = () => ed.project.tracks.find((t) => t.kind === 'video').items.slice().sort((a, b2) => a.t0 - b2.t0);
    return { clips: window.vclips().map((c) => [c.t0, c.out - c.in]), dur: ed.duration(), ws: ed.workspace?.id };
  });
  ok(setup.clips.length === 3 && Math.abs(setup.clips[1][0] - 2) < 0.05 && Math.abs(setup.clips[2][0] - 4) < 0.05, `test reel: presenter | b-roll | presenter, cuts at ${setup.clips.slice(1).map((c) => c[0].toFixed(2)).join(' and ')} s (${setup.ws})`);

  // ════════ catalogue ════════
  if (want('catalogue')) {
    const cat = await ev(async () => {
      const ids = (el) => window.EL.elementById(el)?.variants || [];
      const out = { cta: ids('cta'), logo: ids('logo'), cbadge: ids('cbadge'), endscreen: ids('endscreen'), cut: window.TT.TRANSITIONS.filter((x) => x.cut).map((x) => x.id), drawn: 0, empty: [] };
      const p = window.ED.project, c = document.createElement('canvas'); c.width = 270; c.height = 480; const g = c.getContext('2d', { willReadFrequently: true });
      for (const el of ['cta', 'logo', 'cbadge', 'endscreen']) for (const v of ids(el)) {
        const style = window.EL.elementStyle('europe', el, { variant: v, ws: window.ED.workspace }), box = window.EL.boxFor(el, p.canvas.w, p.canvas.h, v);
        if (el === 'cbadge') await window.GB.loadBadgeAssets(style.code); if (el === 'logo') await window.LG.prepareLogo(window.LG.logoSrcOf(style));
        const it = window.P.createItem('theme.el', { t0: 0, in: 0, out: 4, ...box, style });
        let ink = 0;
        for (const t of [0.3, 1.2, 2.5, 3.8]) {
          g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, 270, 480); g.setTransform(0.25, 0, 0, 0.25, 0, 0); g.translate(box.x, box.y);
          window.EL.drawElement(g, el, it, p, { box: { x: 0, y: 0, w: box.w, h: box.h }, local: t, preview: false, w: p.canvas.w, h: p.canvas.h });
          const d = g.getImageData(0, 0, 270, 480).data; for (let i = 3; i < d.length; i += 4) if (d[i] > 20) ink++;
        }
        out.drawn++; if (ink < 200) out.empty.push(el + ':' + v);
      }
      return out;
    });
    ok(cat.cta.length >= 12, `${cat.cta.length} contact-card designs (≥ 12): ${cat.cta.join(', ')}`);
    ok(cat.logo.length === 5 && cat.cbadge.length >= 6 && cat.cut.length >= 6, `${cat.logo.length} logo shapes (${cat.logo.join(', ')}), ${cat.cbadge.length} badge looks (${cat.cbadge.join(', ')}), ${cat.cut.length} cut-out transitions (${cat.cut.join(', ')})`);
    ok(cat.endscreen.length >= 10, `${cat.endscreen.length} ending-screen templates (≥ 10): ${cat.endscreen.join(', ')}`);
    ok(cat.empty.length === 0 && errors.length === 0, `all ${cat.drawn} designs / shapes / looks draw at four moments (none empty${cat.empty.length ? ': ' + cat.empty.join(', ') : ''}), ${errors.length} console errors`);
  }

  // ════════ contact cards ════════
  if (want('cta')) {
    const CONTACT = { phone: '+971 50 123 4567', whatsapp: '+971 55 765 4321', email: 'hello@planetcareers.ae', website: 'planetcareers.ae' };
    const cta = await ev(async (CONTACT) => {
      const ed = window.ED, ws = ed.workspace; ws.contactInfo = { ...CONTACT }; ws.social = { instagram: '@planetcareers', tiktok: 'planet.careers' };
      ed.seek(1); const s0 = window.steps();
      const it = window.TH.placeElement(window.CTX, 'cta', { theme: 'europe', variant: 'phonewa', t0: 0.5, dur: 5 });
      const s1 = window.steps(), item = window.find(it.id), fields = window.TP.fieldsFor(item, ed.project);
      const draws = []; const c = document.createElement('canvas'); c.width = 270; c.height = 480; const g = c.getContext('2d');
      g.setTransform(0.25, 0, 0, 0.25, 0, 0); window.EL.drawElement(g, 'cta', item, ed.project, { box: { x: 0, y: 0, w: item.w, h: item.h }, local: 2, preview: false, w: 1080, h: 1920 }, null, false, { draws });
      // every design picks its own values
      const per = {};
      for (const v of window.EL.elementById('cta').variants) {
        const st = { ...item.style, variant: v }, it2 = { ...item, style: st }, dr = [];
        window.EL.drawElement(g, 'cta', it2, ed.project, { box: { x: 0, y: 0, w: item.w, h: item.h }, local: 2, preview: false, w: 1080, h: 1920 }, null, false, { draws: dr });
        per[v] = dr.map((d) => d.text).join(' | ');
      }
      // the QR card's matrix
      const q = window.QR.qrMatrix(window.CTA.chatLink(item.style.contact));
      const qc = document.createElement('canvas'), n = (q.size + 8) * 8; qc.width = qc.height = n; const qg = qc.getContext('2d'); window.QR.drawQr(qg, q, 0, 0, n, '#000', '#fff', 4);
      return { steps: s1 - s0, id: it.id, contact: item.style.contact, keys: fields.map((f) => f.key + '=' + f.value), drawn: draws.map((d) => d.text), per, qr: qc.toDataURL('image/png'), link: window.CTA.chatLink(item.style.contact) };
    }, CONTACT);
    ok(cta.steps === 1 && cta.contact.phone === CONTACT.phone && cta.contact.whatsapp === CONTACT.whatsapp && cta.contact.instagram === '@planetcareers' && cta.contact.tiktok === '@planet.careers', `a contact card placed in ONE step takes Configure → Contact & social: ${JSON.stringify(cta.contact)}`);
    ok(cta.drawn.includes(CONTACT.phone) && cta.keys.some((k) => k === 'phone=' + CONTACT.phone), `Phone + WhatsApp draws the configured number and lists it as a field (${cta.keys.join(' · ')})`);
    const exp = { whatsapp: CONTACT.whatsapp, email: CONTACT.email, website: CONTACT.website, instagram: '@planetcareers', tiktok: '@planet.careers', qr: 'SCAN TO CHAT', strip: CONTACT.website };
    const miss = Object.entries(exp).filter(([v, s]) => !(cta.per[v] || '').includes(s));
    ok(!miss.length, `every design shows its own value (${Object.entries(exp).map(([v]) => v + ': ' + (cta.per[v] || '').split(' | ').slice(-1)[0]).join('; ')})${miss.length ? ' MISSING ' + miss.map((m) => m[0]).join(',') : ''}`);
    fs.writeFileSync(path.join(OUT, 'qr.png'), Buffer.from(cta.qr.split(',')[1], 'base64'));
    let dec = ''; try { dec = execFileSync('python3', ['-c', `import cv2,sys\nim=cv2.imread(sys.argv[1],0)\nprint(cv2.QRCodeDetector().detectAndDecode(im)[0])`, path.join(OUT, 'qr.png')]).toString().trim(); } catch (x) { dec = 'error ' + x.message; }
    ok(dec === cta.link, `the QR card's code decodes (OpenCV) to the WhatsApp link: “${dec}”`);
    // edited in the inspector field
    await ev((id) => { window.ED.select([id]); window.ED.seek(2.5); window.ED.showSide?.('inspector'); }, cta.id); await page.waitForTimeout(500);
    const hasField = await page.$('[data-th-text="phone"]');
    if (hasField) { await page.fill('[data-th-text="phone"]', '+971 4 000 0000'); await page.keyboard.press('Tab'); }
    await page.waitForTimeout(300);
    const f1 = await ev((id) => window.find(id).style.text?.phone, cta.id);
    ok(!!hasField && f1 === '+971 4 000 0000', `typing in the inspector's Phone field rewrites the card: ${f1}`);
    await reveal('[data-e2]'); await shot('cta-inspector-1440x900');
    // double-click on the video
    const ov = await page.locator('.pv-overlay').boundingBox();
    const at = await ev(({ id, key }) => { const it = window.find(id), b = window.TX.lastBoxes(id)?.boxes.find((x) => x.key === key), k = window.ED.preview.k; return b && { x: (it.x + b.x + b.w / 2) * k, y: (it.y + b.y + b.h / 2) * k }; }, { id: cta.id, key: 'bothLbl' });
    let dbl = null;
    if (at) {
      await page.mouse.dblclick(ov.x + at.x, ov.y + at.y);
      await page.waitForSelector('[data-th-canvas-text]', { timeout: 5000 }).catch(() => {});
      dbl = await ev(() => { const i = document.querySelector('[data-th-canvas-text]'); return i ? { key: i.dataset.thCanvasText, value: i.value } : null; });
      if (dbl) { await page.keyboard.type('RING US TODAY'); await page.keyboard.press('Enter'); await page.waitForTimeout(300); }
    }
    const f2 = await ev((id) => window.find(id).style.text?.bothLbl, cta.id);
    ok(dbl?.key === 'bothLbl' && f2 === 'RING US TODAY', `double-click the label on the video: an input opens on it (${dbl?.key} “${dbl?.value}”), typing replaces it → “${f2}”`);
    await shot('cta-on-video-1440x900');
  }

  // ════════ ending screens ════════
  if (want('end')) {
    const end = await ev(async () => {
      const ed = window.ED, ws = ed.workspace; ws.contactInfo = { phone: '+971 50 123 4567', whatsapp: '+971 55 765 4321', email: 'hello@planetcareers.ae', website: 'planetcareers.ae' }; ws.social = { instagram: '@planetcareers' };
      const ES = await import('/app/themes/endscreen.js'), steps = window.steps;
      const s0 = steps(), it = window.C2.addEndScreen(window.CTX, 'corporate'), s1 = steps(), D0 = window.C2.reelEnd2(ed.project);
      const item = () => window.find(it.id), endOf = (i) => +(i.t0 + (i.out - i.in)).toFixed(3);
      const a = { t0: item().t0, end: endOf(item()), dur: item().out - item().in, D: D0 };
      const words = (i, t) => { const dr = [], c = document.createElement('canvas'); c.width = 54; c.height = 96; const g = c.getContext('2d'); g.setTransform(0.05, 0, 0, 0.05, 0, 0); window.EL.drawElement(g, 'endscreen', i, ed.project, { box: { x: 0, y: 0, w: 1080, h: 1920 }, local: t, preview: false, w: 1080, h: 1920 }, null, false, { draws: dr }); return dr.map((d) => d.text); };
      const w1 = words(item(), 1.6);
      // a trim of the last clip: the ending screen still closes the reel, in the same undo step
      const last = window.vclips()[2], s2 = steps(); ed.apply(ed.ops.setItems([{ id: last.id, props: { out: +(last.out - 0.6).toFixed(3) } }], 'Trim')); ed.history.seal();
      const b = { end: endOf(item()), D: window.C2.reelEnd2(ed.project), steps: steps() - s2 };
      ed.history.undo(); const c2 = { end: endOf(item()) }; ed.history.redo(); ed.history.undo();
      // duration 1..10 s, the end kept; the animation re-times: the contacts are on at 60 % of any length, gone at the end
      const durs = {};
      for (const d of [1.2, 3, 6]) { window.C2.setEndDuration(window.CTX, it.id, d); ed.history.seal(); const i = item(); durs[d] = { t0: i.t0, end: endOf(i), mid: words(i, d * 0.6).includes('+971 50 123 4567'), out: words(i, d - 0.001).length }; }
      window.C2.setEndDuration(window.CTX, it.id, 3); ed.history.seal();
      // three slides: logo + thank you, then the contacts, then the call to action
      const st = JSON.parse(JSON.stringify(item().style)); st.slides = 3; ed.apply(ed.ops.setItems([{ id: it.id, props: { style: st } }], 'Slides')); ed.history.seal();
      const sl = [0.6, 1.5, 2.5].map((t) => { const w = words(item(), t); return { thanks: w.some((x) => /THANK YOU/.test(x)), phone: w.includes('+971 50 123 4567'), cta: w.some((x) => /CONSULTATION|SHOP/i.test(x)) }; });
      st.slides = 1; ed.apply(ed.ops.setItems([{ id: it.id, props: { style: st } }], 'Slides')); ed.history.seal();
      return { steps: s1 - s0, a, w1, b, c2, durs, sl, id: it.id };
    });
    ok(end.steps === 1 && Math.abs(end.a.end - end.a.D) < 0.002 && Math.abs(end.a.dur - 3) < 0.01, `Add ending screen: ONE undo step, the last 3 s of the reel (${end.a.t0}–${end.a.end} s, reel ${end.a.D} s)`);
    ok(['+971 50 123 4567', '+971 55 765 4321', 'planetcareers.ae', 'hello@planetcareers.ae', '@planetcareers'].every((x) => end.w1.includes(x)), `it shows Configure's phone, WhatsApp, website, email and handle (${end.w1.slice(0, 9).join(' · ')})`);
    ok(Math.abs(end.b.end - end.b.D) < 0.002 && end.b.steps === 1 && Math.abs(end.c2.end - end.a.end) < 0.002, `a 0.6 s trim of the last clip: it still closes the reel (ends ${end.b.end} = reel ${end.b.D}) in the trim's own undo step; undo puts it back at ${end.c2.end}`);
    ok(Object.values(end.durs).every((x) => Math.abs(x.end - end.a.D) < 0.002 && x.mid && x.out === 0), `duration 1.2 / 3 / 6 s, the end kept (${Object.entries(end.durs).map(([d, x]) => d + ' s → ' + x.t0 + '–' + x.end).join(', ')}); the animation re-times: contacts on at 60 %, nothing left at the last instant`);
    ok(end.sl[0].thanks && !end.sl[0].phone && end.sl[1].phone && !end.sl[1].thanks && end.sl[2].cta && !end.sl[2].phone, `3 slides: (1) logo + thank you ${JSON.stringify(end.sl[0])}, (2) contacts ${JSON.stringify(end.sl[1])}, (3) call to action ${JSON.stringify(end.sl[2])}`);
    // double-click a word of the ending screen on the video
    await ev((id) => { const it = window.find(id); window.ED.select([id]); window.ED.seek(it.t0 + 1.8); window.ED.showSide?.('inspector'); }, end.id); await page.waitForTimeout(700);
    const ov = await page.locator('.pv-overlay').boundingBox();
    const at = await ev(({ id, key }) => { const it = window.find(id), b = window.TX.lastBoxes(id)?.boxes.find((x) => x.key === key), k = window.ED.preview.k; return b && { x: (it.x + b.x + b.w / 2) * k, y: (it.y + b.y + b.h / 2) * k }; }, { id: end.id, key: 'website' });
    let dbl = null; if (at) { await page.mouse.dblclick(ov.x + at.x, ov.y + at.y); await page.waitForSelector('[data-th-canvas-text]', { timeout: 5000 }).catch(() => {}); dbl = await ev(() => document.querySelector('[data-th-canvas-text]')?.dataset.thCanvasText || null); if (dbl) { await page.keyboard.press('Escape'); } }
    ok(dbl === 'website', `double-click the website on the ending screen opens its editor (${dbl})`);
    await reveal('[data-e2]'); await shot('ending-screen-inspector-1440x900');
  }

  // ════════ logo badge ════════
  if (want('logo')) {
    const prep = await ev(async () => {
      const out = {};
      for (const [k, src] of [['white', 'library/el2-test/white-logo.png'], ['plate', 'brands/planet-careers/logo-app-icon.png'], ['transparent', 'brands/planet-careers/logo-mark.png'], ['kbb', 'brands/kbb/logo-mark.svg']]) {
        const L = await window.LG.prepareLogo(src);
        // what is left where the background was: the corners of the trimmed logo should be see-through
        const g = L.cv.getContext('2d', { willReadFrequently: true }), d = g.getImageData(0, 0, L.w, L.h).data, a = (x, y) => d[(y * L.w + x) * 4 + 3];
        // and how much of the removed background colour is still opaque
        let left = 0, opq = 0; const bg = L.bg ? [1, 3, 5].map((i) => parseInt(L.bg.slice(i, i + 2), 16)) : null;
        for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 200) { opq++; if (bg && Math.abs(d[i] - bg[0]) + Math.abs(d[i + 1] - bg[1]) + Math.abs(d[i + 2] - bg[2]) < 40) left++; }
        out[k] = { removed: L.removed, bg: L.bg, w: L.w, h: L.h, from: L.trim.from, left: +(left / Math.max(1, opq) * 100).toFixed(2), corners: [a(0, 0), a(L.w - 1, 0), a(0, L.h - 1), a(L.w - 1, L.h - 1)], fill: +L.fill.toFixed(2), plate: window.LG.choosePlate(L, window.ED.workspace?.colors || {}), cols: L.cols.map((c) => c.col) };
      }
      return out;
    });
    const W0 = prep.white;
    ok(W0.removed === 'white' && W0.w < W0.from[0] * 0.6 && W0.corners.every((a) => a < 30), `white-background logo: white removed, ${W0.from.join('×')} trimmed to ${W0.w}×${W0.h} (the mark's own box), corners see-through (${W0.corners.join(',')})`);
    ok(prep.plate.removed === 'solid' && /^#0[0-9a-f]2/i.test(prep.plate.bg || '') && prep.plate.left < 1 && prep.plate.w < prep.plate.from[0] * 0.8, `app icon on a navy plate: the solid background (${prep.plate.bg}) removed (${prep.plate.left} % of what is left is still that colour), ${prep.plate.from.join('×')} → ${prep.plate.w}×${prep.plate.h}`);
    ok(prep.transparent.removed === 'transparent' && prep.kbb.w > 0, `transparent mark: margins trimmed (${prep.transparent.from.join('×')} → ${prep.transparent.w}×${prep.transparent.h}); KBB SVG mark ${prep.kbb.w}×${prep.kbb.h}`);
    const contrast = (a, b) => { const L = (h) => { const n = parseInt(h.slice(1), 16), f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(n >> 16 & 255) + 0.7152 * f(n >> 8 & 255) + 0.0722 * f(n & 255); }; const x = L(a), y = L(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const cr = Object.fromEntries(Object.entries(prep).map(([k, v]) => [k, +contrast(v.plate, v.cols[0]).toFixed(2)]));
    ok(Object.values(cr).every((x) => x >= 3), `plates chosen for contrast with each logo's main colour: ${Object.entries(prep).map(([k, v]) => `${k} ${v.cols[0]} on ${v.plate} = ${cr[k]}:1`).join(', ')}`);
    // one click, each shape: the synthetic white logo as the company's logo
    const shapes = await ev(async () => {
      const ed = window.ED, ws = ed.workspace, keep = { logo: ws.logo, logoFull: ws.logoFull }, out = [];
      ws.logo = 'library/el2-test/white-logo.png'; ws.logoFull = 'brands/planet-careers/logo.png';
      for (const shape of ['circle', 'rsquare', 'square', 'pill', 'bare']) {
        const s0 = window.steps(), it = window.C2.addLogo(window.CTX, shape), s1 = window.steps(), item = window.find(it.id);
        await window.LG.prepareLogo(window.LG.logoSrcOf(item.style));
        const r = await window.RENDER(ed.project, 1.0, 0.25), px = (x, y) => { const i = (Math.round(y) * r.W + Math.round(x)) * 4; return [r.d[i], r.d[i + 1], r.d[i + 2]]; };
        const cx = (item.x + item.w / 2) * 0.25, cy = (item.y + item.h / 2) * 0.25;
        // logo ink: KBB pink somewhere in the middle third of the badge
        let pink = 0; for (let y = item.y * 0.25 + item.h * 0.08; y < (item.y + item.h) * 0.25 - item.h * 0.02; y++) for (let x = item.x * 0.25 + item.w * 0.05; x < (item.x + item.w) * 0.25 - item.w * 0.01; x++) { const [R2, G, B] = px(x, y); if (R2 > 150 && G < 110 && B > 60 && B < 150) pink++; }
        const sa = window.P.safeAreas(ed.project.canvas).action;
        out.push({ shape, steps: s1 - s0, whole: item.t0 === 0 && Math.abs(item.t0 + item.out - window.C2.reelEnd2(ed.project)) < 0.01, inSafe: item.x >= sa.x - 1 && item.y >= sa.y - 1 && item.x + item.w <= sa.x + sa.w + ed.project.canvas.w * 0.07 + 1, pink, n: window.items().filter((i) => i.style?.el === 'logo').length, plate: window.LG.plateOf(item.style, window.LG.preparedLogo(window.LG.logoSrcOf(item.style))), centre: px(cx, cy) });
      }
      Object.assign(ws, keep);
      return out;
    });
    ok(shapes.every((s) => s.steps === 1 && s.whole && s.inSafe && s.n === 1), `one click per shape = ONE undo step, whole video, top-right inside the safe area, one badge at a time: ${shapes.map((s) => `${s.shape} (${s.steps} step, plate ${s.plate})`).join(', ')}`);
    ok(shapes.every((s) => s.pink > 20), `the logo is drawn on every badge (pink pixels: ${shapes.map((s) => s.shape + ' ' + s.pink).join(', ')})`);
    const ctl = await ev(async () => {
      const ed = window.ED, it = window.C2.wholeLogo(ed.project), s0 = window.steps();
      window.C2.setBadge(window.CTX, it.id, { corner: 'bl' }, 'Logo corner'); ed.history.seal();
      const a = { ...window.find(it.id) }; const s1 = window.steps();
      window.C2.setBadge(window.CTX, it.id, { size: 0.3 }, 'Logo size'); window.C2.setBadge(window.CTX, it.id, { size: 0.34 }, 'Logo size'); ed.history.seal();
      const b = { ...window.find(it.id) }, s2 = window.steps();
      return { a: [a.x, a.y, a.w], b: [b.x, b.y, b.w], s1: s1 - s0, s2: s2 - s1, H: ed.project.canvas.h };
    });
    ok(ctl.s1 === 1 && ctl.a[1] > ctl.H * 0.5 && ctl.s2 === 1 && ctl.b[2] > ctl.a[2], `corner → bottom-left moves the box (${ctl.a.join(',')}), a size drag is ONE step (${ctl.b.join(',')})`);
    // the owner, 19 Nov: "allow me to use logos from any company i want, in any company"
    const other = await ev(async () => {
      const ed = window.ED, ctx = ed.context(), ws = ctx.workspace, o = ctx.workspaces.find((w) => w.id !== ws.id && (w.logo || w.logoFull));
      if (!o) return { none: true, n: ctx.workspaces.length };
      const keep = JSON.stringify(ws), s0 = window.steps();
      const it = window.C2.addLogo(ctx, 'circle', { from: o.id }), item = window.find(it.id);
      const end = window.C2.addEndScreen(ctx, 'corporate'), e = window.find(end.id);
      const again = window.C2.addLogo(ctx, 'square'), a2 = window.find(again.id);   // a new shape keeps the chosen company
      const back = window.C2.addLogo(ctx, 'circle', { from: ws.id }), b2 = window.find(back.id);
      // the panel offers every company with a logo
      ed.showSide('mod:themes'); await new Promise((r) => setTimeout(r, 400));
      const sel = document.querySelector('[data-e2-logo-from]');
      const out = { ws: ws.id, o: o.id, oLogo: o.logo || o.logoFull, logo: item.style.logo || item.style.logoFull, from: item.style.logoFrom, endLogo: e.style.logo, endFrom: e.style.logoFrom,
        kept: a2.style.logoFrom, back: b2.style.logoFrom || null, backLogo: b2.style.logo, wsLogo: ws.logo, same: JSON.stringify(ws) === keep, steps: window.steps() - s0,
        opts: sel ? [...sel.options].map((x) => x.value) : null };
      for (let i = 0; i < 4; i++) ed.history.undo();
      return out;
    });
    ok(!other.none && other.logo === other.oLogo && other.from === other.o && other.endLogo === other.oLogo && other.kept === other.o && other.back === null && other.backLogo === other.wsLogo && other.same && other.steps === 4,
      `another company's logo on this company's reel: ${other.o}'s logo (${other.logo}) on the ${other.ws} reel's badge and ending screen, kept for a new shape, back to its own in one click; this company's brand kit untouched; ${other.steps} undo steps`);
    ok(Array.isArray(other.opts) && other.opts.includes(other.o) && other.opts.includes(other.ws), `Themes → One click → Logo of: ${JSON.stringify(other.opts)}`);
    await ev(() => { const it = window.C2.wholeLogo(window.ED.project); window.ED.select([it.id]); window.ED.seek(1.5); window.ED.showSide?.('inspector'); }); await page.waitForTimeout(600);
    await reveal('[data-e2]'); await shot('logo-inspector-1440x900');
  }

  // ════════ country badges for every mention ════════
  if (want('badges')) {
    // a mention every ~1.4 s (fillers between), the last ones in Arabic (with a joined prefix), Urdu and Hindi
    const SCRIPT = 'Hello everyone today we talk about Germany and the German job seeker route then for families Canada is next on our list and after that Europe in general with options again'.split(' ').concat(['كندا', 'هي', 'خيار', 'رائع', 'جدا', 'اليوم', 'حقا', 'یورپ', 'میں', 'بھی', 'مواقع', 'ہیں', 'بہت', 'وفي', 'بألمانيا', 'فرص', 'كثيرة', 'للعمل', 'هناك', 'ايضا', 'कनाडा', 'में', 'भी']).join(' ');
    const bd = await ev(async (SCRIPT) => {
      const ed = window.ED, C = await import('/app/captions/commands.js'), M = await import('/app/captions/model.js');
      let t = 0.1; const words = SCRIPT.split(' ').map((w) => { const o = { t0: +t.toFixed(2), t1: +(t + 0.08).toFixed(2), w, conf: 1 }; t += 0.1; return o; });
      ed.apply(C.setCaptions({ style: 'word-box', lang: 'en', words, lines: M.buildLines(words, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
      const s0 = window.steps(), r = window.C2.badgeMentions(window.CTX, { look: 'glass', dur: 0.5, gap: 1, min: 0.5 }), s1 = window.steps();
      const list = window.C2.mentionBadges(ed.project).map((i) => ({ code: i.style.code, t0: i.t0, t1: +(i.t0 + i.out).toFixed(2), words: i.style.mention.words }));
      const at = (w) => words.find((x) => x.w === w)?.t0;
      const D = window.C2.reelEnd2(ed.project);
      // a caption edit: "Canada" becomes "Australia" -> the badge follows, in the same undo step as the edit
      const w2 = words.map((w) => (w.w === 'Canada' ? { ...w, w: 'Australia' } : w));
      const s2 = window.steps(); ed.apply(C.setCaptions({ ...ed.project.captions, words: w2, lines: M.buildLines(w2, 'word-box', ed.project.canvas) }, ed.project.canvas)); ed.history.seal();
      const after = window.C2.mentionBadges(ed.project).map((i) => i.style.code), s3 = window.steps();
      ed.history.undo(); const undone = window.C2.mentionBadges(ed.project).map((i) => i.style.code); ed.history.redo();
      return { n: r.n, steps: s1 - s0, list, D, last: words[words.length - 1].t1, at: { Germany: at('Germany'), German: at('German'), Canada: at('Canada'), Europe: at('Europe'), ar: at('بألمانيا'), ur: at('یورپ'), hi: at('कनाडा') }, after, undone, editSteps: s3 - s2 };
    }, SCRIPT);
    const L = bd.list, codes = L.map((x) => x.code).join(',');
    ok(bd.steps === 1 && bd.n === L.length && L.length >= 7 && bd.last < bd.D, `Badge every country I mention: ${bd.n} badges in ONE undo step: ${L.map((x) => `${x.code} ${x.t0}–${x.t1} [${x.words.join(', ')}]`).join(' · ')}`);
    ok(L[0]?.code === 'de' && Math.abs(L[0].t0 - (bd.at.Germany - 0.15)) < 0.02 && L[0].words.includes('Germany') && L[0].words.includes('German'), `the first badge is Germany from its word's time (${L[0]?.t0} s, word at ${bd.at.Germany} s), “Germany” and “German” 0.3 s apart merged into one (${L[0]?.words.join(', ')})`);
    ok(/^de,ca,europe,/.test(codes), `order follows the speech: ${codes}`);
    const tail = L.slice(-4).map((x) => `${x.code}@${x.t0}`).join(', ');
    ok(L.slice(-4).map((x) => x.code).join(',') === 'ca,europe,de,ca' && Math.abs(L[L.length - 2].t0 - (bd.at.ar - 0.15)) < 0.02 && Math.abs(L[L.length - 1].t0 - (bd.at.hi - 0.15)) < 0.02, `Arabic “كندا” → ca, Urdu “یورپ” → europe, Arabic “بألمانيا” (joined prefix removed) → de at ${bd.at.ar} s, Hindi “कनाडा” → ca at ${bd.at.hi} s: ${tail}`);
    ok(bd.after[1] === 'au' && bd.after.length === L.length && bd.editSteps === 1 && bd.undone[1] === 'ca', `a caption edit (Canada → Australia) re-derives the badges in the same undo step (${bd.after.join(',')}); undo brings Canada back (${bd.undone.join(',')})`);
    await ev(() => { const it = window.C2.mentionBadges(window.ED.project)[0]; window.ED.select([it.id]); window.ED.seek(it.t0 + 0.9); window.ED.showSide?.('inspector'); }); await page.waitForTimeout(900);
    await reveal('[data-e2]'); await shot('badge-inspector-1440x900');
    await ev(() => window.ED.showSide?.('mod:themes')); await page.waitForTimeout(900);
    await reveal('[data-e2-quick]');
    await shot('themes-one-click-card-1440x900');
  }

  // ════════ cut-out transitions ════════
  if (want('cut')) {
    // find the person once (real segmentation of the drawn presenter), as the panel does when one is placed
    const cut = await ev(async () => {
      const ed = window.ED, kinds = window.TT.TRANSITIONS.filter((x) => x.cut).map((x) => x.id), out = {};
      const clear = () => { const ids = window.items().filter((i) => i.type === 'theme.trans').map((i) => i.id); if (ids.length) ed.apply(ed.ops.removeItems(ids)); };
      clear();
      const placed = window.TT.placeTransition(window.CTX, 'cut-bgswap', { at: 2, theme: 'europe', dur: 1 });
      const t0 = performance.now(); await window.C2.afterCutPlaced(window.CTX, placed); const tAn = performance.now() - t0;
      const A = window.vclips()[0], B = window.vclips()[1];
      const cover = window.V.coverageAt(A.media, 1.9), coverB = window.V.coverageAt(B.media, 0.1);
      // the ground truth (drawn), at the render's size
      const gt = (t) => { const c = document.createElement('canvas'); c.width = 270; c.height = 480; window.W.draw(c, 'presenter', t, 'mask'); const d = c.getContext('2d').getImageData(0, 0, 270, 480).data, m = new Uint8Array(270 * 480); for (let i = 0; i < m.length; i++) m[i] = d[i * 4] > 127 ? 1 : 0; return m; };
      const erode = (m, r) => { const o = new Uint8Array(m.length); for (let y = r; y < 480 - r; y++) for (let x = r; x < 270 - r; x++) { let all = 1; for (let dy = -r; dy <= r && all; dy += r) for (let dx = -r; dx <= r; dx += r) if (!m[(y + dy) * 270 + x + dx]) { all = 0; break; } o[y * 270 + x] = all; } return o; };
      const dilate = (m, r) => { const o = new Uint8Array(m.length); for (let y = 0; y < 480; y++) for (let x = 0; x < 270; x++) { let any = 0; for (let dy = -r; dy <= r && !any; dy += 2) for (let dx = -r; dx <= r; dx += 2) { const yy = y + dy, xx = x + dx; if (yy >= 0 && yy < 480 && xx >= 0 && xx < 270 && m[yy * 270 + xx]) { any = 1; break; } } o[y * 270 + x] = any; } return o; };
      const dist = (f, g2, m, inv = false) => { let s = 0, n = 0; for (let i = 0; i < m.length; i++) if (!!m[i] !== inv) { const j = i * 4; s += Math.abs(f[j] - g2[j]) + Math.abs(f[j + 1] - g2[j + 1]) + Math.abs(f[j + 2] - g2[j + 2]); n++; } return n ? +(s / n / 3).toFixed(1) : -1; };
      const without = () => { const q = JSON.parse(JSON.stringify(ed.project)); for (const tr of q.tracks) tr.items = tr.items.filter((i) => i.type !== 'theme.trans'); return q; };
      for (const kind of kinds) {
        clear(); window.TT.placeTransition(window.CTX, kind, { at: 2, theme: 'europe', dur: 1 });
        const probes = { before: 1.95, early: 1.7, after: 2.12, mid: 1.8 }, rec = {};
        for (const [k, t] of Object.entries(probes)) {
          const f = (await window.RENDER(ed.project, t)).d, a = (await window.RENDER(without(), t)).d;
          const bFirst = (await window.RENDER(without(), 2.02)).d, aLast = (await window.RENDER(without(), 1.98)).d;
          const m = gt(t < 2 ? t : 1.99), core = erode(m, 6), far = dilate(m, 16);
          rec[k] = { core: { A: dist(f, t < 2 ? a : aLast, core), B: dist(f, t < 2 ? bFirst : a, core) }, bg: { A: dist(f, t < 2 ? a : aLast, far, true), B: dist(f, t < 2 ? bFirst : a, far, true) }, sat: 0 };
          if (kind === 'cut-freeze' && k === 'before') { let s = 0, n = 0; for (let i = 0; i < far.length; i++) if (!far[i]) { const j = i * 4, mx = Math.max(f[j], f[j + 1], f[j + 2]), mn = Math.min(f[j], f[j + 1], f[j + 2]); s += mx - mn; n++; } rec[k].sat = +(s / n).toFixed(1); }
          if (kind === 'cut-flash' && k === 'mid') { const ring = dilate(m, 6); let s = 0, n = 0; for (let i = 0; i < m.length; i++) if (ring[i] && !m[i]) { const j = i * 4; s += Math.abs(f[j] - a[j]) + Math.abs(f[j + 1] - a[j + 1]) + Math.abs(f[j + 2] - a[j + 2]); n++; } rec[k].ring = +(s / n / 3).toFixed(1); }
          if (kind === 'cut-textbehind' && k === 'mid') { let s = 0, n = 0; for (let y = 110; y < 175; y++) for (let x = 10; x < 260; x++) { const i = y * 270 + x; if (far[i]) continue; const j = i * 4; s += Math.abs(f[j] - a[j]) + Math.abs(f[j + 1] - a[j + 1]) + Math.abs(f[j + 2] - a[j + 2]); n++; } rec[k].text = +(s / Math.max(1, n) / 3).toFixed(1); }
        }
        out[kind] = { ...rec, status: window.CT.cutStatus(ed.project, window.items().find((i) => i.type === 'theme.trans')) };
      }
      // no person: the b-roll → presenter cut, "person stays" → a plain wipe
      clear(); const fb = window.TT.placeTransition(window.CTX, 'cut-bgswap', { at: 4, theme: 'europe', dur: 1 })[0];
      await window.C2.afterCutPlaced(window.CTX, [fb]);
      const st = window.CT.cutStatus(ed.project, window.find(fb.id));
      const f = (await window.RENDER(ed.project, 3.95)).d, a = (await window.RENDER(without(), 3.95)).d, c2 = (await window.RENDER(without(), 4.02)).d;
      const left = new Uint8Array(270 * 480), right = new Uint8Array(270 * 480); for (let y = 0; y < 480; y++) for (let x = 0; x < 270; x++) { if (x < 75) left[y * 270 + x] = 1; if (x > 150) right[y * 270 + x] = 1; }
      out.fallback = { status: st, leftNext: dist(f, c2, left), leftPrev: dist(f, a, left), rightPrev: dist(f, a, right) };
      clear();
      return { out, tAn: Math.round(tAn), cover, coverB };
    });
    const O = cut.out, isA = (r) => r.A < 14 && r.A < r.B, isB = (r) => r.B < 14 && r.B < r.A;
    ok(cut.cover > 0.08 && (cut.coverB ?? 0) < 0.03, `the person is found offline in ${cut.tAn} ms (presenter covers ${(cut.cover * 100).toFixed(1)} % of the frame, the b-roll ${((cut.coverB ?? 0) * 100).toFixed(1)} %)`);
    const fmt = (r) => `person A ${r.core.A}/B ${r.core.B}, background A ${r.bg.A}/B ${r.bg.B}`;
    ok(isA(O['cut-bgswap'].before.core) && isB(O['cut-bgswap'].before.bg) && isA(O['cut-bgswap'].after.core) && isB(O['cut-bgswap'].after.bg), `person stays, background swaps: before the cut ${fmt(O['cut-bgswap'].before)}; after it ${fmt(O['cut-bgswap'].after)} (mean |difference| to each clip, /255)`);
    ok(isA(O['cut-popout'].before.core) && isB(O['cut-popout'].before.bg), `person pop-out: ${fmt(O['cut-popout'].before)}`);
    ok(isB(O['cut-silin'].early.core) && isA(O['cut-silin'].early.bg), `silhouette wipe (inside): early on the next clip shows inside the person, the first outside: ${fmt(O['cut-silin'].early)}`);
    ok(isA(O['cut-silout'].before.core) && isB(O['cut-silout'].before.bg), `silhouette iris (outside): ${fmt(O['cut-silout'].before)}`);
    ok(isA(O['cut-flash'].mid.core) && O['cut-flash'].mid.ring > 20, `colour-flash outline: the person kept (${O['cut-flash'].mid.core.A}), the ring round them lit (${O['cut-flash'].mid.ring} from the clip), the white flash on the cut (${O['cut-flash'].before.core.A} at 1.95 s)`);
    ok(isA(O['cut-freeze'].before.core) && O['cut-freeze'].before.sat < 12 && isA(O['cut-freeze'].after.core) && O['cut-freeze'].after.bg.B < 14, `cut-out freeze: the background drained to grey (saturation ${O['cut-freeze'].before.sat}) with the person live (${O['cut-freeze'].before.core.A}); after the cut the frozen cut-out over the next clip (${fmt(O['cut-freeze'].after)})`);
    ok(isA(O['cut-textbehind'].mid.core) && O['cut-textbehind'].mid.text > 20, `text behind the person: the person stays in front (${O['cut-textbehind'].mid.core.A}), the title shows round them (${O['cut-textbehind'].mid.text})`);
    const F = O.fallback;
    ok(F.status.fallback && /wipe/.test(F.status.msg) && F.leftNext < 14 && F.rightPrev < 14, `no person before the cut (b-roll → presenter): “${F.status.msg}”; it wipes: left of the edge the next clip (${F.leftNext}), right of it the clip before (${F.rightPrev})`);
    ok(Object.entries(O).filter(([k]) => k !== 'fallback').every(([, v]) => v.status && !v.status.fallback && !v.status.pending), `every cut-out transition on the presenter cut reports the person found (${O['cut-bgswap'].status.msg})`);
  }

  // ════════ preview = export ════════
  if (want('same')) {
    const same = await ev(async () => {
      const ed = window.ED, ws = ed.workspace; ws.logo = ws.logo || 'brands/planet-careers/logo-app-icon.svg';
      if (!window.items().some((i) => i.style?.el === 'cta')) window.TH.placeElement(window.CTX, 'cta', { theme: 'europe', variant: 'call', t0: 0.5, dur: 5 });
      if (!window.C2.wholeLogo(ed.project)) window.C2.addLogo(window.CTX, 'circle');
      if (!window.items().some((i) => i.style?.el === 'cbadge')) window.TH.placeElement(window.CTX, 'cbadge', { theme: 'country-de', variant: 'flagring', t0: 0, dur: 5.5 });
      window.TT.placeTransition(window.CTX, 'cut-popout', { at: 2, theme: 'europe', dur: 1 });
      if (!window.C2.endScreen(ed.project)) window.C2.addEndScreen(window.CTX, 'flag');
      await window.V.ensureAnalysis(ed.project); await window.R.prepareRender(ed.project);
      const r = await window.E.exportMp4(ed.project, ed.media, { quality: 'high', width: 540, height: 960 });
      const file = `workspaces/${ed.project.workspace}/exports/el2-same.mp4`; await ed.engine.writeFile(file, r.blob);
      return file;
    });
    const out = [];
    for (const t of [1.0, 1.95, 2.3, 3.2, 4.6]) {
      await ev((t) => window.ED.seek(t), t);
      await page.waitForFunction(() => window.kbbEditor.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(800);
      const pv = await ev(() => { const c = document.createElement('canvas'); c.width = 135; c.height = 240; const g = c.getContext('2d', { willReadFrequently: true }); g.drawImage(window.ED.preview.canvas, 0, 0, 135, 240); return Array.from(g.getImageData(0, 0, 135, 240).data).filter((_, i) => i % 4 !== 3); });
      const ex = frameRGB(path.join(STUDIO, same), t, 135, 240);
      let s = 0; for (let i = 0; i < ex.length; i++) s += Math.abs(ex[i] - pv[i]);
      for (const [nm, px] of [['pv', pv], ['ex', ex]]) fs.writeFileSync(path.join(OUT, `same-${t}-${nm}.ppm`), Buffer.concat([Buffer.from('P6\n135 240\n255\n'), Buffer.from(Uint8Array.from(px))]));
      out.push([t, +(s / ex.length).toFixed(2)]);
    }
    ok(out.every(([, d]) => d < 6), `preview = export with a card, a logo, a badge, a cut-out transition and an ending screen (4.6 s): mean difference ${out.map(([t, d]) => `${t}s ${d}`).join(', ')} (/255, < 6)`);
    await ev(() => { const it = window.items().find((i) => i.type === 'theme.trans'); window.ED.select([it.id]); window.ED.seek(1.95); window.ED.showSide?.('inspector'); }); await page.waitForTimeout(1200);
    await reveal('[data-e2-cut]'); await shot('cut-transition-inspector-1440x900');
  }

  // ════════ keep: undo, save + reload, old projects ════════
  if (want('keep')) {
    const before = await ev(async () => {
      const ed = window.ED, mine = window.items().filter((i) => /^(cta|logo|cbadge|endscreen)$/.test(i.style?.el || '') || /^cut-/.test(i.style?.kind || ''));
      const r = await window.RENDER(ed.project, 1.95, 0.2);
      return { n: mine.length, json: JSON.stringify(mine.map((i) => [i.id, i.type, i.t0, i.out, i.x, i.y, i.w, i.h, i.style])), px: Array.from(r.d) };
    });
    await ev(() => window.ED.save());
    await page.goto(e.url + '/app/'); await page.waitForSelector('.recent'); await page.click('.recent'); await page.waitForSelector('.tl-item', { timeout: 60000 });
    await page.waitForFunction(() => window.kbbEditor?.project?.tracks);
    const after = await ev(async () => {
      const ed = window.kbbEditor; window.ED = ed; window.R = await import('/app/core/render.js'); window.E = await import('/app/core/export.js'); window.V = await import('/app/look/vision.js');
      window.items = () => ed.project.tracks.flatMap((t) => t.items);
      window.RENDER = async (p, t, scale) => { const W = Math.round(p.canvas.w * scale), Hh = Math.round(p.canvas.h * scale), c = document.createElement('canvas'); c.width = W; c.height = Hh; const x = c.getContext('2d', { willReadFrequently: true }); await window.R.prepareRender(p); const f = await window.E.openFrames(p, ed.media, scale); try { await f.load(t); window.R.renderFrame(x, p, t, { scale, source: f.source }); } finally { f.close(); } return { d: x.getImageData(0, 0, W, Hh).data }; };
      const mine = window.items().filter((i) => /^(cta|logo|cbadge|endscreen)$/.test(i.style?.el || '') || /^cut-/.test(i.style?.kind || ''));
      const r = await window.RENDER(ed.project, 1.95, 0.2);
      return { n: mine.length, json: JSON.stringify(mine.map((i) => [i.id, i.type, i.t0, i.out, i.x, i.y, i.w, i.h, i.style])), px: Array.from(r.d) };
    });
    let dr = 0; for (let i = 0; i < before.px.length; i++) dr = Math.max(dr, Math.abs(before.px[i] - after.px[i]));
    ok(after.n === before.n && after.json === before.json && dr <= 3, `save + reload: ${after.n} items (cards, logo, badges, transitions) come back identical, max pixel difference ${dr}`);
    const undo = await ev(async () => {
      const ed = window.ED, CTX = ed.context(), C2 = await import('/app/themes/el2-cmds.js'), TH = await import('/app/themes/index.js');
      const n = () => window.items().length, s = () => ed.history.undoStack.length;
      const n0 = n(), s0 = s(); TH.placeElement(CTX, 'cta', { theme: 'europe', variant: 'qr', t0: 0.2 }); const n1 = n(), s1 = s();
      ed.history.undo(); const n2 = n(); ed.history.redo(); const n3 = n();
      return { n0, n1, n2, n3, steps: s1 - s0 };
    });
    ok(undo.steps === 1 && undo.n1 === undo.n0 + 1 && undo.n2 === undo.n0 && undo.n3 === undo.n1, `add a card = ONE step; undo ${undo.n1} → ${undo.n2} items, redo → ${undo.n3}`);
    // an old project (no el2 items) draws exactly as the shipped tree: the catalogue only appended, elementStyle and
    // boxFor give the old elements the same values
    const old = await ev(async () => {
      const EL = await import('/app/themes/elements.js'), out = [];
      for (const el of ['title', 'lower', 'end', 'globe', 'sticker', 'capsules']) { const st = EL.elementStyle('europe', el, {}); out.push(Object.keys(st).filter((k) => !['theme', 'el', 'colors', 'variant', 'whole', 'text', 'brand', 'logo', 'caps'].includes(k))); }
      return { extra: out.flat(), boxes: ['title', 'lower', 'globe'].map((el) => JSON.stringify(EL.boxFor(el, 1080, 1920))) };
    });
    ok(old.extra.length === 0, `older elements get exactly the style they had (no new keys: ${JSON.stringify(old.extra)}), boxes unchanged (${old.boxes.join(' ')})`);
  }

  // ════════ stills ════════
  if (want('shots')) {
    const stills = await ev(async () => {
      const ed = window.ED, R = await import('/app/core/render.js'), H2 = await import('/app/themes/hover.js'), EL = await import('/app/themes/elements.js'), P = await import('/app/core/project.js'), LG = await import('/app/themes/logo-badge.js'), GB = await import('/app/themes/globe-badge.js'), TT = await import('/app/themes/transitions.js');
      const ws = ed.workspace, mk = (items, trans) => { const p = H2.miniProject('9:16'); p.tracks[0].items.push(P.createItem('clip', { id: 'hp-clip-0-0', t0: 0, in: 0, out: 2.2, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, variant: 0 }), P.createItem('clip', { id: 'hp-clip-1-2.2', t0: 2.2, in: 0, out: 2, x: 0, y: 0, w: p.canvas.w, h: p.canvas.h, variant: 1 })); for (const it of items) p.tracks[2].items.push(it); if (trans) p.tracks[1].items.push(TT.transItem(p, { a: p.tracks[0].items[0], b: p.tracks[0].items[1], t: 2.2 }, trans, { theme: 'europe', dur: 1.2 })); return p; };
      const el = (e2, v, extra = {}, box) => { const p0 = H2.miniProject('9:16'), st = { ...EL.elementStyle('europe', e2, { variant: v, ws }), ...extra }; return P.createItem('theme.el', { t0: 0, in: 0, out: 4, ...(box || EL.boxFor(e2, p0.canvas.w, p0.canvas.h, v)), style: st }); };
      const W = 432, Hh = 768, shots = {};
      const snap = async (name, p, t) => { for (const it of p.tracks.flatMap((x) => x.items)) { if (it.style?.el === 'logo') await LG.prepareLogo(LG.logoSrcOf(it.style)); if (it.style?.el === 'cbadge') await GB.loadBadgeAssets(it.style.code); } const c = document.createElement('canvas'); c.width = W; c.height = Hh; H2.drawMini(c.getContext('2d'), W, Hh, p, t); shots[name] = c.toDataURL('image/png'); };
      const sq = (x, y, s) => ({ x, y, w: s, h: s * 1.32 });
      await snap('01-cta-phone-whatsapp', mk([el('cta', 'phonewa')]), 2);
      await snap('02-cta-qr-scan-to-chat', mk([el('cta', 'qr')]), 2.4);
      await snap('03-cta-book-consultation', mk([el('cta', 'consult', { lang: 'en' })]), 2);
      await snap('04-logo-badges-kbb-and-planet-careers', mk([el('logo', 'circle', { logo: 'brands/kbb/logo-mark.svg' }, { x: 60, y: 150, w: 260, h: 260 }), el('logo', 'rsquare', { logo: 'brands/planet-careers/logo-app-icon.png' }, { x: 760, y: 150, w: 260, h: 260 }), el('logo', 'pill', { logoFull: 'brands/planet-careers/logo.png', logo: 'brands/planet-careers/logo-mark.png' }, { x: 240, y: 1300, w: 600, h: 230 })]), 2);
      await snap('05-country-badges-europe-glass-germany-flagring', mk([el('cbadge', 'glass', { code: 'europe' }, sq(70, 160, 330)), el('cbadge', 'flagring', { code: 'de' }, sq(680, 160, 330))]), 2.2);
      await snap('06-country-badges-stamp-neon-uae', mk([el('cbadge', 'stamp', { code: 'ca' }, sq(70, 160, 330)), el('cbadge', 'neon', { code: 'ae', lang: 'ar' }, sq(680, 160, 330))]), 2.2);
      await snap('07-cutout-person-stays-background-swaps', mk([], 'cut-bgswap'), 2.1);
      await snap('08-cutout-text-behind-person', mk([], 'cut-textbehind'), 1.9);
      const ES = await import('/app/themes/endscreen.js'), es = el('endscreen', 'whatsapp', {}, { x: 0, y: 0, w: 1080, h: 1920 }); await ES.loadEndAssets(es.style);
      await snap('09-ending-screen-big-whatsapp', mk([es]), 1.8);
      return shots;
    });
    for (const [n, d] of Object.entries(stills)) fs.writeFileSync(path.join(SHOTS, n + '.png'), Buffer.from(d.split(',')[1], 'base64'));
    ok(Object.keys(stills).length === 9, `9 stills in shots/elements2/: ${Object.keys(stills).join(', ')}`);
  }

  // ════════ previews: every design, shape, look and transition as a 4 s 432×768 mp4 + poster ════════
  if (PREVIEWS) {
    const specs = await ev(() => {
      const EL = window.EL || null, list = [];
      return import('/app/themes/elements.js').then((EL2) => import('/app/themes/transitions.js').then((TT) => {
        for (const v of EL2.elementById('cta').variants) list.push({ kind: 'el', el: 'cta', v, name: 'cta-' + v });
        for (const v of EL2.elementById('logo').variants) for (const [co, logo, full] of [['kbb', 'brands/kbb/logo-mark.svg', 'brands/kbb/logo.svg'], ['planet-careers', 'brands/planet-careers/logo-app-icon.png', 'brands/planet-careers/logo.png']]) list.push({ kind: 'el', el: 'logo', v, name: `logo-${v}-${co}`, extra: { logo, logoFull: full } });
        for (const v of EL2.elementById('cbadge').variants) list.push({ kind: 'el', el: 'cbadge', v, name: 'badge-' + v, extra: { code: { glass: 'europe', flat: 'de', flagring: 'gb', neon: 'ae', stamp: 'ca', minimal: 'au' }[v] } });
        for (const v of EL2.elementById('endscreen').variants) list.push({ kind: 'el', el: 'endscreen', v, name: 'ending-' + v, extra: { code: 'de' } });
        list.push({ kind: 'el', el: 'endscreen', v: 'gradient', name: 'ending-gradient-3-slides', extra: { slides: 3 } });
        for (const t of TT.TRANSITIONS.filter((x) => x.cut)) list.push({ kind: 'trans', v: t.id, name: 'transition-' + t.id });
        void EL; return list;
      }));
    });
    const tP = Date.now();
    for (const sp of specs) {
      const dir = path.join(OUT, 'pf'); fs.rmSync(dir, { recursive: true, force: true }); fs.mkdirSync(dir, { recursive: true });
      const frames = await ev(async (sp) => {
        const H2 = await import('/app/themes/hover.js'), EL = await import('/app/themes/elements.js'), P = await import('/app/core/project.js'), LG = await import('/app/themes/logo-badge.js'), GB = await import('/app/themes/globe-badge.js'), TT = await import('/app/themes/transitions.js');
        const p = H2.miniProject('9:16'), W = p.canvas.w, Hh = p.canvas.h;
        p.tracks[0].items.push(P.createItem('clip', { id: 'hp-clip-0-0', t0: 0, in: 0, out: 2, x: 0, y: 0, w: W, h: Hh, variant: 0 }), P.createItem('clip', { id: 'hp-clip-1-2', t0: 2, in: 0, out: 2.2, x: 0, y: 0, w: W, h: Hh, variant: 1 }));
        if (sp.kind === 'trans') p.tracks[1].items.push(TT.transItem(p, { a: p.tracks[0].items[0], b: p.tracks[0].items[1], t: 2 }, sp.v, { theme: 'europe', dur: TT.transitionById(sp.v).dur * 1.5 }));
        else {
          const st = { ...EL.elementStyle('europe', sp.el, { variant: sp.v, ws: window.ED.workspace }), ...(sp.extra || {}) };
          const box = sp.el === 'endscreen' ? { x: 0, y: 0, w: W, h: Hh } : sp.el === 'cbadge' ? { x: 90, y: 200, w: 420, h: 554 } : sp.el === 'logo' ? (sp.v === 'pill' ? { x: 190, y: 220, w: 700, h: 270 } : { x: 330, y: 200, w: 420, h: 420 }) : EL.boxFor(sp.el, W, Hh, sp.v);
          p.tracks[2].items.push(P.createItem('theme.el', { t0: 0.15, in: 0, out: 3.75, ...box, style: st }));
          if (sp.el === 'logo') await LG.prepareLogo(LG.logoSrcOf(st)); if (sp.el === 'cbadge') await GB.loadBadgeAssets(st.code); if (sp.el === 'endscreen') await (await import('/app/themes/endscreen.js')).loadEndAssets(st);
        }
        const c = document.createElement('canvas'); c.width = 432; c.height = 768; const g = c.getContext('2d'), out = [];
        for (let i = 0; i < 96; i++) { H2.drawMini(g, 432, 768, p, i / 24); out.push(c.toDataURL('image/jpeg', 0.9)); }
        return out;
      }, sp);
      frames.forEach((d, i) => fs.writeFileSync(path.join(dir, String(i).padStart(3, '0') + '.jpg'), Buffer.from(d.split(',')[1], 'base64')));
      ff('-framerate', '24', '-i', path.join(dir, '%03d.jpg'), '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', path.join(PREV, sp.name + '.mp4'));
      fs.copyFileSync(path.join(dir, sp.kind === 'trans' ? '046.jpg' : '060.jpg'), path.join(PREV, sp.name + '.jpg'));
    }
    fs.rmSync(path.join(OUT, 'pf'), { recursive: true, force: true });
    const n = fs.readdirSync(PREV).filter((f) => f.endsWith('.mp4')).length;
    ok(n >= specs.length, `${specs.length} previews (432×768, 4 s, mp4 + jpg poster) in tools/out/elements2/ in ${((Date.now() - tP) / 1000).toFixed(0)} s`);
  }

  ok(errors.length === 0, `no console errors (${errors.length}${errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''})`);
  ok(outside.length === 0, `no network outside the engine (${outside.length})`);
  fs.writeFileSync(path.join(SHOTS, 'check.txt'), results.join('\n') + `\n\n${failures ? failures + ' FAILED' : 'ALL PASS'} · ${((Date.now() - T0) / 1000).toFixed(0)} s\n`);
  console.log(`\n${failures ? failures + ' FAILED' : 'ALL PASS'} · ${((Date.now() - T0) / 1000).toFixed(0)} s`);
  await browser.close(); await H.stopEngine(e);
  process.exit(failures ? 1 : 0);
})().catch(async (err) => { console.error(err); process.exit(2); });
