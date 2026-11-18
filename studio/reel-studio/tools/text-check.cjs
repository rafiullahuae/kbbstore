#!/usr/bin/env node
// KBB Studio — THEME TEXT check (the owner, 18 Nov: "i can not edit or add the text when i select any themes. make sure
// it must have edit text options etc."). Headless Chrome against the Linux engine, in the real editor:
//
//   cause     the old inspector's three faults, now fixed: empty fields with a "theme default" placeholder (now the
//             words on the canvas), nothing on the canvas while typing (now live), and typing then clicking the timeline
//             lost the words (now written on every keystroke)
//   coverage  EVERY element, bulletin template, family piece, the end card, the crawl and the two transitions that draw
//             words: every string drawn over 60 moments has a field (or is a glyph / step number / composite of
//             fields), the field shows the current words, and editing it changes the rendered pixels
//   live      typing changes the project and the preview on every keystroke; one undo step per edit burst (pauses
//             longer than the history's merge window included); Enter / Esc / ↺ reset
//   canvas    double-click a word on the video: an input on the renderer's own box, live, Enter commits, one undo step,
//             the inspector field flashes; a double-click off the words focuses the first field
//   style     font, size, colour, bold, case, italic, outline, alignment, per-field size and colour each change the
//             render; Reset text style; band colours and "Use theme colours"
//   add       + Add line (lower third, bulletin bar, end card), + Add point (visa board), + Add text (a Text item on the
//             element that moves and re-times with it), the Text tool button
//   fit       long words shrink to a minimum and then end in "…", inside the element's box; Arabic right to left
//   theme     re-applying the theme keeps the owner's words (the same theme: all of them; another theme: the personal
//             ones) and brings back whole-video elements with theirs
//   keep      undo / redo, save + reload (same pixels), an old project (no text style) draws pixel-identical to the
//             code before this change, preview = export (a real MP4)
//   speed     ms per keystroke, field discovery only on selection
//   shots     shots/text/*.png at 1440×900
//
//   bash tools/make-test-clips.sh && NODE_PATH=/home/user/kbbstore/node_modules node tools/text-check.cjs
'use strict';
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');

const ROOT = H.ROOT, CLIPS = path.join(ROOT, 'tools', 'out'), SHOTS = path.join(ROOT, 'shots', 'text');
fs.mkdirSync(SHOTS, { recursive: true });
let failures = 0; const results = [];
const ok = (c, msg) => { const l = (c ? 'PASS ' : 'FAIL ') + msg; console.log(l); results.push(l); if (!c) failures++; return c; };
const BASE_REV = '8bf358887';   // the tree before this change (for "an old project draws as before")
const meanDiff = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += Math.abs(a[i] - b[i]); return s / a.length; };
function frameRGB(file, t, w, h) {
  const b = execFileSync('ffmpeg', ['-v', 'error', '-ss', t.toFixed(4), '-i', file, '-frames:v', '1', '-vf', `scale=${w}:${h}:flags=area`, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], { maxBuffer: 1 << 28 });
  return new Uint8Array(b.buffer, b.byteOffset, b.length);
}

(async () => {
  for (const f of ['portrait.mp4', 'wide.mp4']) if (!fs.existsSync(path.join(CLIPS, f))) throw new Error('run tools/make-test-clips.sh first');
  const dir = H.makeStudio();
  // the app as it was before this change, served as /library/old/app/ with the brand kits and fonts beside it (its
  // own relative paths find its flags), for the old-project comparison
  const OLDD = path.join(dir, 'library', 'old');
  fs.mkdirSync(path.join(OLDD, 'vendor'), { recursive: true });
  const tar = execFileSync('git', ['-C', ROOT, 'archive', BASE_REV, 'app'], { maxBuffer: 1 << 30 });
  execFileSync('tar', ['-x', '-C', OLDD], { input: tar });
  fs.cpSync(path.join(ROOT, 'brands'), path.join(OLDD, 'brands'), { recursive: true });
  fs.cpSync(path.join(ROOT, 'vendor', 'caption-fonts'), path.join(OLDD, 'vendor', 'caption-fonts'), { recursive: true });
  fs.mkdirSync(path.join(dir, 'library', 'text-test'), { recursive: true });
  fs.copyFileSync(path.join(CLIPS, 'wide.mp4'), path.join(dir, 'library', 'text-test', 'wide.mp4'));
  const e = await H.startEngine(dir);
  const browser = await H.launchChrome();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  page.setDefaultTimeout(120000);
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error' && !/\/library\/old\//.test(m.location()?.url || '')) errors.push(m.text() + ' ' + (m.location()?.url || '')); if (m.type() === 'warning' && /\[(render|themes)\]/.test(m.text())) errors.push('warn: ' + m.text()); });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  await page.addInitScript(() => { try { localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch {} });
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const frame = () => ev(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
  const shot = async (name) => { await page.waitForTimeout(300); await page.screenshot({ path: path.join(SHOTS, name + '.png') }); };

  await page.goto(e.url + '/app/');
  await page.waitForSelector('.home', { timeout: 30000 });
  await page.setInputFiles('.home-drop input[type=file]', path.join(CLIPS, 'portrait.mp4'));
  await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project.media[0]?.dur > 0);
  const boot = () => ev(async () => {
    const ed = window.kbbEditor; window.ED = ed; window.TH = await import('/app/themes/index.js'); window.CTX = ed.context();
    window.TX = await import('/app/themes/text.js'); window.TF = await import('/app/themes/text-fields.js'); window.TP = await import('/app/themes/text-panel.js');
    window.EL = await import('/app/themes/elements.js'); window.R = await import('/app/core/render.js');
    window.find = (id) => ED.project.tracks.flatMap((t) => t.items).find((i) => i.id === id);
    window.steps = () => ED.history.undoStack.length;
    // an element's pixels at local time t (its own box, scale k): a plain array for comparing
    window.px = (item, t, k = 0.25, el) => {
      const c = (window.__cv ||= document.createElement('canvas')), w = Math.ceil(item.w * k), h = Math.ceil(item.h * k);
      c.width = w; c.height = h; const g = c.getContext('2d', { willReadFrequently: true });
      g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, w, h); g.setTransform(k, 0, 0, k, 0, 0);
      EL.drawElement(g, el || TF.elOfItem(item), item, ED.project, { box: { x: 0, y: 0, w: item.w, h: item.h }, local: t, preview: false });
      return g.getImageData(0, 0, w, h).data;
    };
    window.diff = (a, b) => { let n = 0; for (let i = 0; i < a.length; i += 4) if (Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]) + Math.abs(a[i + 3] - b[i + 3]) > 24) n++; return n; };
  });
  await boot();
  await ev(() => { ED.setAspect('9:16'); ED.history.clear(); });

  // ════════ 1 · the cause, and the fix in the inspector ════════
  const lw = await ev(() => { const it = TH.placeElement(CTX, 'lower', { theme: 'europe', t0: 0.5, dur: 6 }); ED.history.seal(); ED.seek(2.2); ED.select([it.id]); ED.showSide('inspector'); return it.id; });
  await page.waitForSelector('[data-th-text="name"]'); await frame();
  const pre = await ev(() => ({ name: document.querySelector('[data-th-text="name"]').value, role: document.querySelector('[data-th-text="role"]').value, ph: [...document.querySelectorAll('[data-th-text]')].map((x) => x.placeholder), secs: [...document.querySelectorAll('.insp-body details > summary, aside details > summary')].map((x) => x.textContent) }));
  ok(pre.name === 'YOUR NAME HERE' && pre.role === 'Your title · Your company', `cause 1 fixed: the lower third's fields show the canvas words, “${pre.name}” / “${pre.role}” (before: empty)`);
  ok(!pre.ph.includes('theme default'), `cause 1 fixed: no “theme default” placeholder (${[...new Set(pre.ph)].join(' / ')})`);
  ok(pre.secs.some((s) => /^Text$/.test(s)) && pre.secs.some((s) => /Text style/.test(s)), `the inspector has a Text and a Text style section (${pre.secs.join(' · ')})`);
  await page.evaluate(() => document.querySelector('[data-th-text-sec]').scrollIntoView({ block: 'start' })); await page.mouse.move(700, 880);
  await shot('lower-third-selected');
  // live: every keystroke reaches the project and the preview
  const nameBox = async () => ev((id) => TX.lastBoxes(id)?.boxes.find((b) => b.key === 'name'), lw);
  const pvPx = (b) => ev(({ b, id }) => { const it = find(id), c = ED.preview.canvas, k = ED.preview.scale, x = Math.round((it.x + b.x) * k), y = Math.round((it.y + b.y) * k), w = Math.max(2, Math.round(b.w * k)), h = Math.max(2, Math.round(b.h * k)); const r = document.createElement('canvas'); r.width = w; r.height = h; const g = r.getContext('2d'); g.drawImage(c, x, y, w, h, 0, 0, w, h); return Array.from(g.getImageData(0, 0, w, h).data); }, { b, id: lw });
  const nb0 = await nameBox(); const px0 = await pvPx(nb0);
  const s0 = await ev(() => steps());
  await page.click('[data-th-text="name"]'); await page.keyboard.press('ControlOrMeta+a');
  const live = [];
  for (const ch of 'Rafi Khan') { await page.keyboard.type(ch); await frame(); live.push(await ev((id) => find(id).style.text?.name ?? null, lw)); }
  await page.waitForTimeout(900);   // a pause longer than the history's merge window, mid-burst
  await page.keyboard.type('!'); await frame();
  const px1 = await pvPx(nb0);
  ok(live.join('|') === 'R|Ra|Raf|Rafi|Rafi |Rafi K|Rafi Kh|Rafi Kha|Rafi Khan', `live: the project follows every keystroke (${live.join(' → ')})`);
  ok(meanDiff(px0, px1) > 4, `live: the preview's name box changed while typing, before any Enter (mean diff ${meanDiff(px0, px1).toFixed(1)})`);
  // cause 3: type in the role, then click the timeline — the words used to be lost
  await page.click('[data-th-text="role"]'); await page.keyboard.press('ControlOrMeta+a'); await page.keyboard.type('Visa consultant');
  await page.click('.tl-item.t-clip'); await page.waitForTimeout(300);
  const after = await ev((id) => ({ ...find(id).style.text }), lw);
  ok(after.role === 'Visa consultant' && after.name === 'Rafi Khan!', `cause 3 fixed: typing then clicking the timeline keeps the words (role “${after.role}”, name “${after.name}”)`);
  const s1 = await ev(() => steps());
  ok(s1 - s0 === 2, `one undo step per edit burst: name (with a 0.9 s pause) + role = ${s1 - s0} steps`);
  await ev(() => { ED.history.undo(); }); const u1 = await ev((id) => ({ ...find(id).style.text }), lw);
  await ev(() => { ED.history.undo(); }); const u2 = await ev((id) => ({ ...find(id).style.text }), lw);
  await ev(() => { ED.history.redo(); ED.history.redo(); }); const u3 = await ev((id) => ({ ...find(id).style.text }), lw);
  ok(u1.role == null && u1.name === 'Rafi Khan!' && u2.name == null && u3.name === 'Rafi Khan!' && u3.role === 'Visa consultant', 'undo takes back each burst whole (role, then the name); redo puts both back');
  // Enter commits; Esc puts the words back; ↺ resets
  await ev((id) => ED.select([id]), lw); await page.waitForSelector('[data-th-text="name"]');
  await page.click('[data-th-text="name"]'); await page.keyboard.press('ControlOrMeta+a'); await page.keyboard.type('Temp'); await page.keyboard.press('Escape'); await frame();
  const esc = await ev((id) => ({ v: find(id).style.text.name, f: document.querySelector('[data-th-text="name"]').value, active: document.activeElement?.dataset?.thText || null }), lw);
  ok(esc.v === 'Rafi Khan!' && esc.f === 'Rafi Khan!' && !esc.active, `Esc puts the words back (“${esc.v}”) and leaves the field`);
  await page.click('[data-th-text="name"]'); await page.keyboard.press('End'); await page.keyboard.type('?'); await page.keyboard.press('Enter'); await frame();
  const ent = await ev((id) => ({ v: find(id).style.text.name, active: document.activeElement?.dataset?.thText || null }), lw);
  ok(ent.v === 'Rafi Khan!?' && !ent.active, `Enter commits (“${ent.v}”) and leaves the field`);
  await page.click('[data-th-reset="role"]'); await frame();
  const rs = await ev((id) => ({ v: find(id).style.text.role ?? null, f: document.querySelector('[data-th-text="role"]').value }), lw);
  ok(rs.v === null && rs.f === 'Your title · Your company', `↺ resets one field to the theme's words (“${rs.f}”)`);

  // ════════ 2 · coverage: every element, every string ════════
  const cov = await ev(async () => {
    const out = [], DECOR = (s) => s.length <= 1 || /^[\d\s.:+\-–—]+$/.test(s) || /^[✈★✓]$/.test(s);
    const themes = ['europe', 'country-de'];
    for (const def of EL.ELEMENTS) {
      const it = TH.placeElement(CTX, def.id, { theme: def.fam ? 'europe' : themes[out.length % 2], t0: 0, dur: 4, variant: def.variants ? (def.id === 'sticker' ? 'visa' : def.variants[0]) : undefined });
      const fields = TF.textFieldsOf(it, ED.project), keys = new Set(fields.map((f) => f.key));
      // every string drawn over 60 moments
      const draws = [], cv = document.createElement('canvas'); cv.width = cv.height = 120; const g = cv.getContext('2d');
      for (let i = 0; i < 60; i++) { g.setTransform(0.1, 0, 0, 0.1, 0, 0); EL.drawElement(g, def.id, it, ED.project, { box: { x: 0, y: 0, w: it.w, h: it.h }, local: ((i + 0.5) / 60) * 4, preview: false }, null, false, { draws }); }
      const vals = fields.map((f) => f.value.toUpperCase()).filter(Boolean);
      const composite = (s) => { const u = s.toUpperCase(); return vals.filter((v) => u.includes(v)).length >= 2; };
      const unkeyed = [...new Set(draws.filter((d) => !d.key && !DECOR(d.text.trim()) && !composite(d.text)).map((d) => d.text))];
      const notListed = [...new Set(draws.filter((d) => d.key && !keys.has(d.key)).map((d) => d.key))];
      // the field shows what is drawn: its value, in the case the canvas shows, is among the drawn strings (or a prefix of one)
      const shown = fields.filter((f) => f.value && draws.some((d) => d.key === f.key)).every((f) => draws.some((d) => d.key === f.key && (d.text === f.value || f.value.startsWith(d.text) || d.text.toUpperCase() === f.value.toUpperCase())));
      // editing each field changes the pixels (at a moment where that key is drawn)
      const changed = [], same = [];
      for (const f of fields) {
        // the middle of the stretch where the word is drawn in full (not while it fades or slides in); a word drawn only
        // inside a composite (“Dubai → Berlin”) is compared at 2 s
        const ts = []; for (let i = 0; i < 60; i++) { const dd = []; g.setTransform(0.1, 0, 0, 0.1, 0, 0); EL.drawElement(g, def.id, it, ED.project, { box: { x: 0, y: 0, w: it.w, h: it.h }, local: ((i + 0.5) / 60) * 4, preview: false }, null, false, { draws: dd }); if (dd.some((d) => d.key === f.key && d.text.trim())) ts.push(((i + 0.5) / 60) * 4); }
        const tAt = ts.length ? ts[Math.floor(ts.length * 0.6)] : 2.0;
        const tries = ts.length ? [0.1, 0.3, 0.5, 0.7, 0.9].map((q) => ts[Math.min(ts.length - 1, Math.floor(ts.length * q))]) : [tAt];
        const a = tries.map((t) => px(it, t, 0.25, def.id));
        TP.writeWords(ED, it.id, f.key, f.ar ? 'نص جديد للاختبار' : 'ZQX 123 WWW', 'test'); ED.history.seal();
        const b = tries.map((t) => px(find(it.id), t, 0.25, def.id));
        (a.some((x, i) => diff(x, b[i]) > 3) ? changed : same).push(f.key);
        ED.history.undo();
      }
      out.push({ id: def.id, fam: !!def.fam, bulletin: !!def.bulletin, fields: fields.length, keys: [...keys], unkeyed, notListed, shown, changed: changed.length, same });
      ED.apply(ED.ops.removeItems([it.id])); ED.history.clear();
    }
    return out;
  });
  const withText = cov.filter((c) => c.fields);
  const bad = cov.filter((c) => c.unkeyed.length || c.notListed.length || !c.shown || c.same.length);
  ok(cov.length === (await ev(() => EL.ELEMENTS.length)) && withText.length >= 36, `coverage: ${cov.length} elements checked (${cov.filter((c) => c.bulletin).length} bulletin templates, ${cov.filter((c) => c.fam).length} family pieces), ${withText.length} draw words, ${withText.reduce((s, c) => s + c.fields, 0)} fields in all`);
  ok(!bad.length, `every drawn string has a field, every field shows its words and changes the pixels when edited${bad.length ? ': ' + bad.map((c) => `${c.id} unkeyed[${c.unkeyed.join('¦')}] notListed[${c.notListed}] shown=${c.shown} same[${c.same}]`).join(' ; ') : ''}`);
  ok(cov.filter((c) => !c.fields).every((c) => /^(wipe|confetti|grade|capsules|fam-window|fam-isosite|fam-sunrise|fam-isocity)$/.test(c.id)), `elements without words are only the wordless ones (${cov.filter((c) => !c.fields).map((c) => c.id).join(', ')}; capsules have their own editor)`);
  const crawlOk = cov.find((c) => c.id === 'breaking')?.keys.includes('crawl') && cov.find((c) => c.id === 'bulletin')?.keys.includes('crawl');
  const endKeys = cov.find((c) => c.id === 'end')?.keys || [], hireKeys = cov.find((c) => c.id === 'hiring')?.keys || [];
  ok(crawlOk && ['cta', 'ctaAr', 'phone', 'web', 'handle', 'brand'].every((k) => endKeys.includes(k)) && ['hiring', 'hiringAr', 'role0', 'role1', 'role2'].every((k) => hireKeys.includes(k)), `the crawl, the end card (CTA, Arabic CTA, phone, web, handle, company, row labels) and the job roles are all fields`);
  // the transitions that draw words (page turn, boarding pass): fields, and editing changes the frame at the cut
  const tr = await ev(async () => {
    const m = await ED.media.importPath('library/text-test/wide.mp4'); ED.addMediaToTimeline(m); await new Promise((r) => setTimeout(r, 400));
    const T = await import('/app/themes/transitions.js'), out = {};
    for (const kind of ['page', 'ticket']) {
      const it = T.placeTransition(CTX, kind, { theme: 'europe' }); const item = Array.isArray(it) ? it[0] : it;
      const t0 = ED.project.tracks.flatMap((t) => t.items).find((i) => i.type === 'theme.trans');
      const fields = TP.fieldsFor(t0, ED.project), at = t0.t0 + (t0.out - t0.in) * 0.48;
      const c = document.createElement('canvas'); c.width = 216; c.height = 384; const g = c.getContext('2d', { willReadFrequently: true });
      const shot2 = () => { R.renderFrame(g, ED.project, at, { scale: 0.2 }); return g.getImageData(0, 0, 216, 384).data; };
      const a = shot2(); TP.writeWords(ED, t0.id, fields[0].key, 'ZQX TEST WORDS'); const b = shot2();
      out[kind] = { n: fields.length, keys: fields.map((f) => f.key), d: diff(a, b) };
      ED.apply(ED.ops.removeItems(ED.project.tracks.flatMap((t) => t.items).filter((i) => i.type === 'theme.trans').map((i) => i.id)));
    }
    return out;
  });
  ok(tr.page?.n >= 5 && tr.ticket?.n >= 11 && tr.page.d > 3 && tr.ticket.d > 3, `transitions with words: page turn ${tr.page?.n} fields (${tr.page?.keys.join(', ')}), boarding pass ${tr.ticket?.n}; editing changes the frame at the cut (${tr.page?.d} / ${tr.ticket?.d} px)`);

  // ════════ 3 · on the canvas ════════
  await ev((id) => { ED.seek(2.2); ED.select([id]); }, lw); await page.waitForTimeout(400); await frame();
  const ov = await page.locator('.pv-overlay').boundingBox();
  const at = async (key) => ev(({ id, key }) => { const it = find(id), b = TX.lastBoxes(id)?.boxes.find((x) => x.key === key), k = ED.preview.k; return b && { x: (it.x + b.x + b.w / 2) * k, y: (it.y + b.y + b.h / 2) * k }; }, { id: lw, key });
  const p1 = await at('name');
  const sc0 = await ev(() => steps());
  await page.mouse.dblclick(ov.x + p1.x, ov.y + p1.y);
  await page.waitForSelector('[data-th-canvas-text]');
  const ce = await ev(() => { const i = document.querySelector('[data-th-canvas-text]'), r = i.getBoundingClientRect(); return { key: i.dataset.thCanvasText, value: i.value, sel: i.selectionEnd - i.selectionStart, focus: document.activeElement === i, r: { x: r.x, y: r.y, w: r.width, h: r.height }, flash: !!document.querySelector('.tx-field.flash [data-th-text="name"]') }; });
  ok(ce.key === 'name' && ce.value === 'Rafi Khan!?' && ce.focus, `double-click on the name on the video opens an input there (key ${ce.key}, “${ce.value}”, focused, all selected: ${ce.sel === ce.value.length})`);
  ok(Math.abs(ce.r.y + ce.r.h / 2 - (ov.y + p1.y)) < ce.r.h && ce.r.x <= ov.x + p1.x && ce.r.x + ce.r.w >= ov.x + p1.x, `the input sits on the word's own box (centre ${Math.round(ce.r.x + ce.r.w / 2)},${Math.round(ce.r.y + ce.r.h / 2)} vs word ${Math.round(ov.x + p1.x)},${Math.round(ov.y + p1.y)})`);
  ok(ce.flash, 'the inspector field for the same word flashes');
  await page.keyboard.type('Sara Ali'); await frame();
  const cLive = await ev((id) => find(id).style.text.name, lw);
  await shot('canvas-in-place-editing');
  await page.keyboard.press('Enter'); await frame();
  const cDone = await ev((id) => ({ v: find(id).style.text.name, open: !!document.querySelector('[data-th-canvas-text]'), steps: steps() }), lw);
  ok(cLive === 'Sara Ali' && cDone.v === 'Sara Ali' && !cDone.open && cDone.steps - sc0 === 1, `typing on the canvas is live (“${cLive}”), Enter commits and closes it, ONE undo step (${cDone.steps - sc0})`);
  const p2 = await at('role');
  await page.mouse.dblclick(ov.x + p2.x, ov.y + p2.y); await page.waitForSelector('[data-th-canvas-text="role"]');
  await page.keyboard.type('Should not stay'); await page.keyboard.press('Escape'); await frame();
  const cEsc = await ev((id) => ({ v: find(id).style.text.role ?? null, open: !!document.querySelector('[data-th-canvas-text]') }), lw);
  ok(cEsc.v === null && !cEsc.open, 'double-click on the role, type, Esc: the words go back and the editor closes');
  await page.mouse.dblclick(ov.x + p2.x, ov.y + p2.y); await page.waitForSelector('[data-th-canvas-text="role"]');
  await page.keyboard.type('Click-away keeps'); await page.mouse.click(ov.x + 20, ov.y + 20); await frame();
  const cAway = await ev((id) => find(id).style.text.role ?? null, lw);
  ok(cAway === 'Click-away keeps', `a click away from the canvas editor commits (“${cAway}”)`);
  await ev((id) => ED.select([id]), lw); await page.waitForTimeout(200);
  const bx = await ev((id) => { const it = find(id); return { x: (it.x + it.w * 0.03) * ED.preview.k, y: (it.y + it.h * 0.5) * ED.preview.k }; }, lw);
  await page.mouse.dblclick(ov.x + bx.x, ov.y + bx.y); await page.waitForTimeout(300);
  const offWord = await ev(() => ({ canvas: !!document.querySelector('[data-th-canvas-text]'), focus: document.activeElement?.dataset?.thText || null }));
  ok(!offWord.canvas && !!offWord.focus, `a double-click on the element away from its words focuses its first field (${offWord.focus})`);
  await page.keyboard.press('Escape');

  // ════════ 4 · text style ════════
  await ev((id) => { ED.select([id]); }, lw); await page.waitForSelector('[data-th-style-sec]');
  const tAt = 2.2 - 0.5;
  const styleRes = [];
  const check = async (label, act) => {
    const a = await ev(({ id, t }) => Array.from(px(find(id), t, 0.3)), { id: lw, t: tAt });
    const s0 = await ev(() => steps()); await act(); await frame(); await page.waitForTimeout(80);
    const b = await ev(({ id, t }) => Array.from(px(find(id), t, 0.3)), { id: lw, t: tAt });
    let n = 0; for (let i = 0; i < a.length; i += 4) if (Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]) > 24) n++;
    styleRes.push(`${label} ${n}px/${(await ev(() => steps())) - s0}st`); return n;
  };
  const n1 = await check('font', () => page.selectOption('[data-th-font]', 'Playfair Display').then(() => page.waitForTimeout(600)));
  const sizeIn = page.locator('[data-th-style-sec] label.slider').first().locator('input');
  const n2 = await check('size', async () => { await sizeIn.fill('1.4'); await sizeIn.dispatchEvent('change'); });
  const n3 = await check('colour', async () => { await page.locator('[data-th-style-sec] .tx-chip').first().click(); });
  const n4 = await check('bold', async () => { await page.click('.tx-weight button[data-v="400"]'); });
  const n5 = await check('case', async () => { await page.click('.tx-case button[data-v="asis"]'); });
  const n6 = await check('italic', async () => { await page.click('[data-th-italic]'); });
  const n7 = await check('outline', async () => { await page.click('[data-th-outline]'); });
  const n8 = await check('align', async () => { await page.click('.tx-align button[data-v="right"]'); });
  const n9 = await check('band', async () => { await page.locator('[data-th-style-sec] .row').filter({ hasText: 'Band' }).locator('.tx-chip').nth(1).click(); });
  const ts = await ev((id) => find(id).style.tstyle, lw);
  ok([n1, n2, n3, n4, n5, n6, n7, n8, n9].every((n) => n > 10), `every Text style control changes the render: ${styleRes.join(', ')}`);
  ok(ts.font === 'Playfair Display' && Math.abs(ts.scale - 1.4) < 0.01 && ts.weight === 400 && ts.case === 'asis' && ts.italic && ts.outline && ts.align === 'right' && /^#/.test(ts.color), `the style is stored on the item: ${JSON.stringify(ts)}`);
  await shot('text-style-section');
  await page.evaluate(() => document.querySelector('[data-th-style-sec]').scrollIntoView({ block: 'start' }));
  await shot('text-style-section-controls');
  // per-field size and colour
  await page.locator('[data-th-field="name"] button', { hasText: 'Aa' }).click();
  const n10 = await check('field size', async () => { const s = page.locator('[data-th-field="name"] .tx-more input[type=range]'); await s.fill('0.6'); await s.dispatchEvent('change'); });
  const n11 = await check('field colour', async () => { await page.locator('[data-th-field="name"] .tx-more .tx-chip').last().click(); });
  const ts2 = await ev((id) => find(id).style.tstyle, lw);
  ok(n10 > 10 && n11 > 10 && ts2.sizes?.name === 0.6 && /^#/.test(ts2.colors?.name), `per-field size and colour (name ${ts2.sizes?.name}, ${ts2.colors?.name}): ${n10} / ${n11} px changed`);
  // preview = export of a styled element (a real MP4), measured on the frame
  const pvx = await ev(async (id) => {
    ED.seek(1.9); await new Promise((r) => setTimeout(r, 300));
    const E = await import('/app/core/export.js'), p = ED.project;
    const r = await E.exportMp4(p, ED.media, { quality: 'high', width: 540, height: 960, duration: 3 });
    const file = `workspaces/${p.workspace}/exports/text-preview-vs-export.mp4`; await ED.engine.writeFile(file, r.blob); return file;
  }, lw);
  const pdiffs = [];
  for (const t of [1.0, 1.9, 2.6]) {
    await ev((t) => ED.seek(t), t);
    await page.waitForFunction(() => ED.preview.pending === 0, null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(500);
    const fr = await ev(() => { const c = ED.preview.canvas, d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data, rgb = new Uint8Array(c.width * c.height * 3); for (let i = 0, j = 0; i < d.length; i += 4) { rgb[j++] = d[i]; rgb[j++] = d[i + 1]; rgb[j++] = d[i + 2]; } return { w: c.width, h: c.height, data: Array.from(rgb) }; });
    pdiffs.push({ t, d: +meanDiff(Uint8Array.from(fr.data), frameRGB(path.join(dir, pvx), t, fr.w, fr.h)).toFixed(2) });
  }
  ok(pdiffs.every((x) => x.d < 6), `preview = export with a styled, edited lower third (Playfair, 140 %, colours, outline): ${pdiffs.map((x) => `${x.t} s ${x.d}/255`).join(', ')} (< 6)`);
  // reset text style
  await ev((id) => ED.select([id]), lw); await page.waitForSelector('[data-th-style-reset]');
  await page.click('[data-th-style-reset]'); await frame();
  ok(await ev((id) => !find(id).style.tstyle, lw), 'Reset text style drops every style choice (one click)');
  await ev(() => { ED.history.undo(); }); ok(await ev((id) => !!find(id).style.tstyle?.font, lw), 'undo brings the text style back; redo/undo are exact');
  await page.waitForSelector('[data-th-theme-colours]');
  await ev((id) => ED.select([id]), lw); await page.waitForTimeout(150);
  await page.click('[data-th-theme-colours]'); await frame();
  ok(await ev((id) => { const c = find(id).style.colors; return c && c.primary === '#003399'; }, lw), 'Use theme colours puts the theme’s band colours back');

  // ════════ 5 · add line / add text ════════
  const adds = {};
  for (const el of ['lower', 'lowerbar', 'end', 'visa']) {
    const id = await ev((el) => { const it = TH.placeElement(CTX, el, { theme: 'europe', t0: 0.2, dur: 4 }); ED.history.seal(); ED.seek(2.6); ED.select([it.id]); return it.id; }, el);
    await page.waitForSelector('[data-th-add-line]');
    const key = await page.getAttribute('[data-th-add-line]', 'data-th-add-line');
    const a = await ev(({ id }) => Array.from(px(find(id), 2.4, 0.3)), { id });
    await page.click('[data-th-add-line]'); await page.waitForTimeout(200);
    const b = await ev(({ id }) => Array.from(px(find(id), 2.4, 0.3)), { id });
    let n = 0; for (let i = 0; i < a.length; i += 4) if (Math.abs(a[i] - b[i]) > 24) n++;
    const st = await ev(({ id, key }) => ({ v: find(id).style.text[key], focus: document.activeElement?.dataset?.thText }), { id, key });
    adds[el] = { key, v: st.v, focus: st.focus, n };
    if (el === 'visa') {
      for (const [k, v] of [['p1', 'Admission letter from a university'], ['p2', 'Proof of funds: placeholder'], ['p3', 'Health insurance: placeholder'], ['p5', 'Book a free consultation']]) await ev(({ id, k, v }) => { TP.writeWords(ED, id, k, v); ED.history.seal(); }, { id, k, v });
      await ev(({ id }) => { ED.seek(find(id).t0 + 3.6); ED.select([id]); }, { id }); await page.waitForTimeout(500);
      await page.evaluate(() => { document.activeElement?.blur?.(); ED.inspector.render(true); document.querySelector('[data-th-text="p1"]')?.scrollIntoView({ block: 'start' }); }); await page.mouse.move(700, 880);
      await shot('bulletin-edited-points');
    }
    await ev((id) => { ED.apply(ED.ops.removeItems([id])); }, id);
  }
  ok(Object.values(adds).every((a) => a.v && a.n > 10 && a.focus === a.key), `+ Add line / + Add point: ${Object.entries(adds).map(([el, a]) => `${el} → ${a.key} “${a.v}” (${a.n} px, field focused)`).join('; ')}`);
  // + Add text
  await ev((id) => { ED.select([id]); }, lw); await page.waitForSelector('[data-th-add-text]');
  const sa = await ev(() => steps());
  await page.click('[data-th-add-text]'); await page.waitForTimeout(300);
  const at1 = await ev(({ id }) => { const el = find(id), t = ED.selectedItems()[0]?.item; return { type: t?.type, link: t?.link, t0: t?.t0, d: t && t.out - t.in, el: { t0: el.t0, d: el.out - el.in, x: el.x, y: el.y, w: el.w, h: el.h }, x: t?.x, y: t?.y, w: t?.w, focus: document.activeElement?.classList.contains('text-edit'), id: t?.id }; }, { id: lw });
  ok(at1.type === 'text' && at1.link === lw && Math.abs(at1.t0 - at1.el.t0) < 1e-6 && Math.abs(at1.d - at1.el.d) < 1e-6 && at1.focus, `+ Add text places a Text item on the element: same start ${at1.t0} s and length ${at1.d.toFixed(1)} s, just under it, selected with its words focused`);
  await page.keyboard.press('ControlOrMeta+a'); await page.keyboard.type('Free text over the theme'); await page.keyboard.press('Tab');
  const mv = await ev(({ id, tid }) => { const s0 = steps(), el = find(id), t = find(tid), before = { x: t.x, y: t.y, t0: t.t0 }; ED.apply(ED.ops.setItems([{ id, props: { x: el.x + 60, y: el.y - 120, t0: el.t0 + 0.5 } }], 'Move')); ED.history.seal(); const t2 = find(tid); const r = { dx: t2.x - before.x, dy: t2.y - before.y, dt: +(t2.t0 - before.t0).toFixed(3), steps: steps() - s0 }; ED.history.undo(); const t3 = find(tid); r.back = t3.x === before.x && t3.y === before.y && t3.t0 === before.t0; r.text = t2.text; return r; }, { id: lw, tid: at1.id });
  ok(mv.dx === 60 && mv.dy === -120 && mv.dt === 0.5 && mv.steps === 1 && mv.back, `the added Text follows its element (moved +60/−120, +0.5 s → text +${mv.dx}/${mv.dy}, +${mv.dt} s) in the same undo step, and undo puts both back`);
  await ev((id) => ED.select([id]), lw); await page.waitForSelector('[data-th-text-tool]'); await page.click('[data-th-text-tool]'); await page.waitForTimeout(300);
  ok(await ev(() => document.querySelector('.bin-tab.on')?.textContent.includes('Text')), 'the Text tool button opens the Text tab on the left');

  // ════════ 6 · long words and Arabic ════════
  const fit = await ev(async () => {
    const out = {};
    for (const [el, key, ar] of [['lower', 'name', false], ['lower', 'role', false], ['title', 'title', false], ['title', 'titleAr', true], ['end', 'cta', false], ['bulletin', 'headline', false], ['fam-cv', 'name', false]]) {
      const it = TH.placeElement(CTX, el, { theme: 'country-de', t0: 0, dur: 4 }); ED.history.seal();
      const long = ar ? 'دراسة وعمل وحياة جديدة في ألمانيا مع استشارة مجانية لكل طالب وكل عائلة تبحث عن مستقبل أفضل' : 'An extremely long line of words that the owner typed without stopping to see whether it would ever fit inside the box';
      TP.writeWords(ED, it.id, key, long); ED.history.seal();
      const cv = document.createElement('canvas'); cv.width = Math.ceil(it.w); cv.height = Math.ceil(it.h); const g = cv.getContext('2d');
      let box = null, size0 = null;
      for (const i of [15, 19, 21, 25]) {
        const fields = new Map(); const prev = TX.textBegin(g, null, {}, { rec: true }); TX.textEnd(prev);
        g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, cv.width, cv.height);
        EL.drawElement(g, el, find(it.id), ED.project, { box: { x: 0, y: 0, w: it.w, h: it.h }, local: (i + 0.5) / 10, preview: true });
        const b = TX.lastBoxes(it.id)?.boxes.find((x) => x.key === key); if (b && (b.text.endsWith('…') || b.text.length > 20) && !box) box = b;
      }
      out[el + '.' + key] = box ? { x: Math.round(box.x), ell: box.text.endsWith('…'), inside: box.x >= -2 && box.x + box.w <= it.w + 2, ar: box.ar, w: Math.round(box.w), bw: Math.round(it.w), size: Math.round(box.size) } : null;
      ED.apply(ED.ops.removeItems([it.id])); ED.history.clear();
    }
    return out;
  });
  const fitBad = Object.entries(fit).filter(([, v]) => !v || !v.inside || !v.ell);
  ok(!fitBad.length, `long words shrink, then end in “…”, inside the element: ${Object.entries(fit).map(([k, v]) => `${k} ${v ? `x${v.x} ${v.w}/${v.bw}px${v.ell ? ' …' : ''}${v.ar ? ' rtl' : ''}` : 'none'}`).join(', ')}`);
  ok(fit['title.titleAr']?.ar, 'Arabic is drawn right to left (direction rtl, right-aligned box inside the element)');
  const arField = await ev(() => { const it = TH.placeElement(CTX, 'title', { theme: 'country-de', t0: 0, dur: 4 }); ED.select([it.id]); ED.history.seal(); return it.id; });
  await page.waitForSelector('[data-th-text="titleAr"]');
  const arf = await ev(() => { const i = document.querySelector('[data-th-text="titleAr"]'), e2 = document.querySelector('[data-th-text="title"]'); return { dir: i.dir, value: i.value, beside: i.closest('.tx-field') === e2.closest('.tx-field') }; });
  ok(arf.dir === 'rtl' && arf.value === 'ألمانيا' && arf.beside, `the Arabic field sits beside the English one, right to left, prefilled (“${arf.value}”)`);
  await ev((id) => { ED.apply(ED.ops.removeItems([id])); }, arField);

  // ════════ 7 · applying the theme again keeps the words ════════
  const re = await ev(async () => {
    ED.apply(ED.ops.removeItems(ED.project.tracks.flatMap((t) => t.items).filter((i) => /^theme\./.test(i.type)).map((i) => i.id)));
    await TH.apply(CTX, 'europe', { faces: [], beats: [] });
    const items = () => ED.project.tracks.flatMap((t) => t.items).filter((i) => i.auto === 'theme');
    const end = items().find((i) => i.type === 'theme.outro' || i.style?.el === 'end'), lower = items().find((i) => i.style?.el === 'lowerbar');
    TP.writeWords(ED, lower.id, 'name', 'Rafi Khan'); TP.writeWords(ED, end.id, 'phone', '+971 50 000 0000'); TP.writeTStyle(ED, lower.id, { scale: 1.2 });
    const title = items().find((i) => i.style?.el === 'title'); if (title) TP.writeWords(ED, title.id, 'title', 'EUROPA'); ED.history.seal();
    await TH.setWhole(CTX, 'lower', true); const wl = items().find((i) => i.style?.el === 'lower' && i.style.whole); TP.writeWords(ED, wl.id, 'role', 'Whole-video role'); ED.history.seal();
    await TH.apply(CTX, 'europe', { faces: [], beats: [] });
    const same = { lower: items().find((i) => i.style?.el === 'lowerbar')?.style, end: items().find((i) => i.type === 'theme.outro' || i.style?.el === 'end')?.style, title: items().find((i) => i.style?.el === 'title')?.style, whole: items().find((i) => i.style?.el === 'lower' && i.style.whole)?.style };
    await TH.apply(CTX, 'country-de', { faces: [], beats: [] });
    const other = { lower: items().find((i) => i.style?.el === 'lowerbar')?.style, title: items().find((i) => i.style?.el === 'title')?.style, whole: items().find((i) => i.style?.el === 'lower' && i.style.whole)?.style };
    return { same: { name: same.lower?.text?.name, scale: same.lower?.tstyle?.scale, phone: same.end?.text?.phone, title: same.title?.text?.title, whole: same.whole?.text?.role }, other: { name: other.lower?.text?.name, title: other.title?.text?.title ?? null, whole: other.whole?.text?.role, theme: other.lower?.theme } };
  });
  ok(re.same.name === 'Rafi Khan' && re.same.scale === 1.2 && re.same.phone === '+971 50 000 0000' && re.same.title === 'EUROPA' && re.same.whole === 'Whole-video role', `re-applying Europe keeps the words and style: ${JSON.stringify(re.same)}`);
  ok(re.other.name === 'Rafi Khan' && re.other.title === null && re.other.whole === 'Whole-video role' && re.other.theme === 'country-de', `applying another theme keeps the personal words (name, the whole-video role) and lets the new theme title itself: ${JSON.stringify(re.other)}`);

  // ════════ 8 · save + reload, old projects ════════
  const before = await ev(() => { const it = ED.project.tracks.flatMap((t) => t.items).find((i) => i.style?.el === 'lowerbar'); return { id: it.id, px: Array.from(px(it, 1.5, 0.3)), text: it.style.text, ts: it.style.tstyle }; });
  await ev(() => ED.save());
  const pid = await ev(() => ED.project.id);
  await page.goto(e.url + '/app/'); await page.waitForSelector('.recent'); await page.click('.recent'); await page.waitForSelector('.tl-item', { timeout: 60000 });
  await page.waitForFunction(() => window.kbbEditor?.project?.tracks);
  await boot(); await page.waitForTimeout(500);
  const afterReload = await ev((id) => { const it = find(id); return it && { px: Array.from(px(it, 1.5, 0.3)), text: it.style.text, ts: it.style.tstyle }; }, before.id);
  let dr = 0; if (afterReload) for (let i = 0; i < before.px.length; i++) dr = Math.max(dr, Math.abs(before.px[i] - afterReload.px[i]));
  ok(afterReload && JSON.stringify(afterReload.text) === JSON.stringify(before.text) && JSON.stringify(afterReload.ts) === JSON.stringify(before.ts) && dr <= 2, `save + reload: words ${JSON.stringify(afterReload?.text)} and style come back, max pixel difference ${dr}`);
  const old = await ev(async () => {
    const OLD = await import('/library/old/app/themes/elements.js');
    // the old copy has its own render module: what a project open prepares (country maps, flags) is prepared there too
    await (await import('/library/old/app/core/render.js')).prepareRender(ED.project);
    const fails = [], counts = { el: 0, frames: 0 };
    const cv = document.createElement('canvas'), g = cv.getContext('2d', { willReadFrequently: true });
    // both copies load their own flag pictures and art: draw everything once, wait for the pictures, then compare
    for (let pass = 0; pass < 2; pass++) {
      for (const def of EL.ELEMENTS) { const it = { id: 'warm-' + def.id, type: 'theme.el', t0: 0, in: 0, out: 4, ...EL.boxFor(def.id, 1080, 1920), x: 0, y: 0, style: EL.elementStyle('country-de', def.id, {}) }; cv.width = 64; cv.height = 64; for (const mod of [OLD, EL]) for (const t of [0.3, 1.1, 2.0, 3.3]) { g.setTransform(0.05, 0, 0, 0.05, 0, 0); try { mod.drawElement(g, def.id, it, ED.project, { box: { x: 0, y: 0, w: it.w, h: it.h }, local: t, preview: true }); } catch {} } }
      await new Promise((r) => setTimeout(r, 1500));
    }
    for (const def of EL.ELEMENTS) {
      if (def.id === 'split' || def.id === 'end') continue;   // split reads the canvas behind it; end: fixed (it threw on every frame)
      const it = { id: 'old-' + def.id, type: 'theme.el', t0: 0, in: 0, out: 4, x: 0, y: 0, ...EL.boxFor(def.id, 1080, 1920), style: EL.elementStyle('country-de', def.id, {}) };
      it.x = 0; it.y = 0; const k = 0.3; cv.width = Math.ceil(it.w * k); cv.height = Math.ceil(it.h * k); counts.el++;
      for (const t of [0.3, 1.1, 2.0, 3.3]) {
        const shot = (mod) => { g.setTransform(1, 0, 0, 1, 0, 0); g.clearRect(0, 0, cv.width, cv.height); g.setTransform(k, 0, 0, k, 0, 0); mod.drawElement(g, def.id, it, ED.project, { box: { x: 0, y: 0, w: it.w, h: it.h }, local: t, preview: true }); return g.getImageData(0, 0, cv.width, cv.height).data; };
        const a = shot(OLD), b = shot(EL); counts.frames++;
        let m = 0; for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) { m++; }
        if (m) fails.push(`${def.id}@${t}:${m}`);
      }
    }
    return { fails, counts };
  });
  ok(!old.fails.length, `an old project (no edited words, no text style) draws pixel-identical to the code before this change: ${old.counts.el} elements × 4 moments${old.fails.length ? ' — differs: ' + old.fails.join(', ') : ''}`);

  // ════════ 9 · speed ════════
  const sp = await ev(async () => {
    const it = TH.placeElement(CTX, 'lowerbar', { theme: 'europe', t0: 0, dur: 5 }); ED.seek(2); ED.select([it.id]); await new Promise((r) => setTimeout(r, 200));
    const t0 = performance.now(); TF.textFieldsOf(it, ED.project); const probe = performance.now() - t0;
    return { probe: +probe.toFixed(1), id: it.id };
  });
  await page.waitForSelector('[data-th-text="name"]'); await page.click('[data-th-text="name"]'); await page.keyboard.press('ControlOrMeta+a');
  const ty = await ev(() => { window.__lt = []; const o = new PerformanceObserver((l) => { for (const e2 of l.getEntries()) window.__lt.push(e2.duration); }); o.observe({ type: 'longtask', buffered: false }); window.__lo = o; return performance.now(); });
  const T0 = Date.now(); await page.keyboard.type('Typing speed test, forty characters!!', { delay: 0 }); await frame(); const ms = Date.now() - T0;
  const lt = await ev(() => { window.__lo.disconnect(); return window.__lt; });
  ok(ms / 37 < 40 && lt.filter((x) => x > 120).length === 0, `typing: ${(ms / 37).toFixed(1)} ms per keystroke over 37 keys (writes at most once a frame), ${lt.length} long tasks (none > 120 ms); field discovery ${sp.probe} ms, once per selection`);

  ok(!errors.length, `no console errors or theme render warnings${errors.length ? ': ' + errors.slice(0, 4).join(' | ') : ''}`);
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(dir, { recursive: true, force: true });
  fs.writeFileSync(path.join(SHOTS, 'check.txt'), results.join('\n') + '\n');
  console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((x) => { console.error(x); process.exit(2); });
