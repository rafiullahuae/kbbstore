#!/usr/bin/env node
// KBB Studio · SEARCH CHECK — the top bar's "Search anything… (⌘K)" and the hover help, measured (the owner, 18 Nov:
// "make sure search has every single details about our studio app" · "a short description for each function by
// mouse over"). Headless Chrome against the Linux engine:
//   coverage   every screen and tab state the ui-audit visits (tools/search-walk.cjs), for both companies, with every
//              folded section opened too: each visible control must be in the index (the curated catalogue or the
//              saved crawl, app/core/search-crawl-data.js) and must have words (text, aria-label or title). A new
//              button without an entry fails here until `--write` saves the crawl again.
//   hover      every indexed visible control has a hover card with a description; a sample per state is really
//              hovered: the card shows, stays inside the window, never covers its control, and no native title is left
//              on it; at 1280 and 2560 wide too
//   nav        40+ searches return the right top result; Enter opens the right place and the control ends up visible,
//              focused and ringed
//   typo       misspellings, synonyms and Arabic
//   latency    every keystroke of 60 queries over the full index, under 5 ms
//   console    zero console errors in all of it
//
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/search-check.cjs [--write] [--only coverage,hover,edges,nav,typo,latency,shots]
// Needs engine/kbbstudio-linux-amd64 + ffmpeg. Screenshots: shots/search/. Report: shots/search/check.txt.
'use strict';
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const H = require('./studio-harness.cjs');
const W = require('./search-walk.cjs');

const arg = (k) => process.argv.includes('--' + k);
const val = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const ONLY = val('only', '') ? val('only').split(',') : null;
const want = (p) => !ONLY || ONLY.includes(p);
const WRITE = arg('write');
const OUT = path.join(H.ROOT, 'tools/out/search'), SHOTS = path.join(H.ROOT, 'shots/search');
const DATA = path.join(H.ROOT, 'app/core/search-crawl-data.js');
fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(SHOTS, { recursive: true });
const BENIGN = /No available adapters|AudioContext was not allowed|net::ERR_ABORTED/;

let failures = 0; const lines = [];
const ok = (c, msg) => { const l = (c ? 'PASS ' : 'FAIL ') + msg; console.log(l); lines.push(l); if (!c) failures++; return c; };
const note = (msg) => { console.log('     ' + msg); lines.push('     ' + msg); };

// ── in-page helpers ──
/** crawl the screen as it is, then with every folded section open (put back afterwards) */
async function crawlState(page, { withHover }) {
  await page.waitForTimeout(300);   // dialogs fade in: a control at opacity 0 is not visible yet
  return page.evaluate(async (withHover) => {
    const C = await import('/app/core/search-crawl.js'); const S = await import('/app/core/search-store.js'); const HH = await import('/app/editor/hover-help.js');
    await S.getStore({ live: false });
    const snap = await S.snapshotKeys();
    const { CATALOGUE } = await import('/app/core/search-index.js');
    const root = C.currentScreen().root;
    const rows = [], gaps = [], unlabelled = [], noHelp = [], sample = [];
    const take = () => {
      const recs = C.crawl(root);
      for (const u of recs.unlabelled) unlabelled.push(u.outerHTML.slice(0, 140));
      for (const r of recs) {
        const c = S.catalogueFor(r.el, r);
        const { el, ...rest } = r;
        rows.push({ ...rest, cat: c ? CATALOGUE.indexOf(c) : null });
        if (!snap.has(r.key) && !c) gaps.push(`${r.kind} “${r.label}”${r.sub ? ' (' + r.sub + ')' : ''} in ${r.path.join(' → ') || C.currentScreen().name}`);
        const info = HH.helpFor(el);
        if (!info || !info.desc || !info.desc.trim()) noHelp.push(`“${r.label}” in ${r.path.join(' → ')}`);
      }
      return recs;
    };
    const first = take();
    // a sample to really hover: spread over the list, buttons and tabs first
    if (withHover) {
      const pick = first.filter((r) => r.el.getBoundingClientRect().height < 200);
      const step = Math.max(1, Math.floor(pick.length / 5));
      for (let i = 0; i < pick.length && sample.length < 5; i += step) { pick[i].el.setAttribute('data-kbs-probe', String(sample.length)); sample.push(pick[i].label); }
    }
    const closed = [...root.querySelectorAll('details:not([open])')].filter((d) => !d.closest('.modal-back') || root.classList.contains('modal-back'));
    for (const d of closed) d.open = true;
    if (closed.length) { await new Promise((r) => setTimeout(r, 250)); take(); for (const d of closed) d.open = false; }
    return { rows, gaps: [...new Set(gaps)], unlabelled: [...new Set(unlabelled)], noHelp: [...new Set(noHelp)], sample, screen: C.currentScreen().name };
  }, withHover);
}

/** hover one element; the card must show with words, inside the window, off the control, and no title left */
async function hoverProbe(page, sel) {
  const el = await page.$(sel); if (!el) return { skip: true };
  await page.mouse.move(2, 2); await page.waitForTimeout(80);
  await el.scrollIntoViewIfNeeded().catch(() => {}); await page.waitForTimeout(200);   // the scroll event lands a frame later and (rightly) hides a card
  const box = await el.boundingBox(); if (!box) return { skip: true };
  const px = box.x + box.width / 2, py = box.y + Math.min(box.height / 2, 12);
  // under something else at that spot (an open menu over it): a person cannot hover it either
  if (!(await page.evaluate(([sel, x, y]) => { const el = document.querySelector(sel), at = document.elementFromPoint(x, y); return !!el && !!at && (el === at || el.contains(at)); }, [sel, px, py]))) return { skip: true };
  await page.mouse.move(px, py);
  const t = await page.waitForSelector('.kbh.on', { timeout: 2000 }).catch(() => null);
  const r = await page.evaluate((sel) => {
    const el = document.querySelector(sel), c = document.querySelector('.kbh.on');
    if (!el) return { gone: true };
    const a = el.getBoundingClientRect();
    const titles = []; for (let p = el; p && p !== document.body; p = p.parentElement) if (p.hasAttribute('title')) titles.push(p.tagName);
    if (!c) return { shown: false, titles, label: el.textContent.trim().slice(0, 30) };
    const b = c.getBoundingClientRect();
    const overlap = Math.min(a.right, b.right) - Math.max(a.left, b.left) > 0.5 && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 0.5;
    return { shown: true, text: c.textContent, inside: b.left >= 0 && b.top >= 0 && b.right <= innerWidth && b.bottom <= innerHeight, overlap, titles, w: b.width, label: el.textContent.trim().slice(0, 30) };
  }, sel);
  void t; await page.mouse.move(2, 2); await page.waitForTimeout(60);
  return r;
}

// navigation: query → the top result's title, and where Enter must land
const NAV = [
  ['trim', /^Trim a clip/, '.tl-item.sel'],
  ['split', /^Split$/, 'section.timeline'],
  ['slow motion', /^Speed$/, '.side-tab.on[data-side="inspector"]'],
  ['noise', /Enhance voice|Noise strength/, null],
  ['logo', /logo badge|brand kit|Companies/, null],   // since v1.1.0 the one-click logo badge answers "logo" first
  ['export 4k', /Export resolution/, '.modal-back #x-go'],
  ['capsules', /Capsules carousel/, '.side-tab.on[data-side="mod:themes"]'],
  ['arabic captions', /Arabic captions|Translate captions/, '.side-tab.on[data-side="captions"]'],
  ['enhance', /^Enhance (video|voice)/, null],
  ['speed ramp', /^Speed ramp/, null],
  ['reverse', /^Reverse$/, '.side-tab.on[data-side="inspector"]'],
  ['freeze', /^Freeze frame$/, '.side-tab.on[data-side="inspector"]'],
  ['ducking', /^Duck music under speech$/, '.side-tab.on[data-side="mod:audio"]'],
  ['safe areas', /^Safe (areas|zones)$/, null],
  ['frame rate', /^Frame rate/, '.side-tab.on[data-side="inspector"]'],
  ['background colour', /^Background colour$/, '.side-tab.on[data-side="inspector"]'],
  ['snap', /^Snap$/, 'section.timeline'],
  ['marker', /^Marker$/, 'section.timeline'],
  ['duplicate', /^Duplicate$/, 'section.timeline'],
  ['unlink', /^Unlink$/, 'section.timeline'],
  ['translate', /^Translate( captions)?$/, '.side-tab.on[data-side="captions"]'],
  ['word sync', /^Sync words to the voice$/, '.side-tab.on[data-side="mod:sync"]'],
  ['font', /font/i, null],
  ['edit text', /^Theme element text/, null],
  ['theme font', /^Theme text style/, null],
  ['caption position', /^Caption position/, null],
  ['themes', /^Themes$/, '.side-tab.on[data-side="mod:themes"]'],
  ['transitions', /^Transitions$/, '.side-tab.on[data-side="mod:look"]'],
  ['crop', /^Crop$/, '.side-tab.on[data-side="inspector"]'],
  ['zoom ken burns', /^Zoom \(Ken Burns\)$/, '.side-tab.on[data-side="inspector"]'],
  ['volume', /^Clip volume$/, '.side-tab.on[data-side="inspector"]'],
  ['auto captions', /^Auto captions$/, '.doit-row'],
  ['remove silences', /^Remove silences$/, '.doit-row'],
  ['music', /^Music library$|^Add music$/, 'aside.bin'],
  ['sound effects', /^Sound effects/, 'aside.bin'],
  ['stickers', /^Stickers$/, 'aside.bin'],
  ['colour look', /^Colour looks$/, '.side-tab.on[data-side="mod:look"]'],
  ['remove background', /^Remove background$/, '.side-tab.on[data-side="mod:look"]'],
  ['skin', /^Skin touch-up$/, '.side-tab.on[data-side="mod:look"]'],
  ['loudness', /^Loudness for the platform$/, '.side-tab.on[data-side="mod:audio"]'],
  ['retention', /^Retention$/, '.side-tab.on[data-side="mod:viral"]'],
  ['hook', /^Hook builder$/, '.doit-row'],
  ['photos', /^Photos/, '.side-tab.on[data-side="mod:stock-photos"]'],
  ['blade', /^Blade$/, 'section.timeline'],
  ['undo', /^Undo$/, '.topbar'],
  ['keyboard shortcuts', /^Keyboard shortcuts$/, '.topbar'],
  ['shape 9:16', /Reel shape/, '.topbar'],
  ['adjust exposure', /^Adjust|^Exposure$/, '.side-tab.on[data-side="inspector"]'],
  ['pexels key', /^Pexels key$/, '.cfg-back .cfg-nav [data-sec="photos"].on'],
  ['contact details', /^Contact & social$/, '.cfg-back .cfg-nav [data-sec="contact"].on'],   // plain "contact" now opens the contact cards first
  ['folders', /^Folders & storage$/, '.cfg-back .cfg-nav [data-sec="folders"].on'],
  ['models', /^Models/, null],
  ['ad studio', /^Ad Studio$/, null],
  ['designs', /Designs/, null],
  // the Text behind editor beside the video (no text-behind element in this reel: it shows where to add one)
  ['edit text behind', /^Edit text behind…$/, null],
  ['text behind colour', /^Text behind · font, size, colour$/, null],
];
const TYPO = [
  ['trimm', /^Trim a clip$/], ['slowmo', /^Speed$/], ['captoin', /[Cc]aption/], ['enhanse voice', /^Enhance voice$/], ['transitons', /^Transitions$/],
  ['dupliacte', /^Duplicate$/], ['exprot', /^Export$/], ['bigger text', /size|Text: words/i], ['slow mo', /^Speed$/], ['noise', /Enhance voice|Noise strength/],
  ['logo', /logo badge|brand kit|Companies/], ['arabic', /Arabic|Translate/], ['remove background', /^Remove background$/], ['cutout', /^Remove background$/],
  ['subtitles', /[Cc]aption/], ['color grade', /Colour|Look/], ['fps', /Frame rate|fps/], ['green screen', /^(Remove background|Green screen)/], ['transparent background', /^Transparent background/], ['remove background', /^Remove background/], ['make it faster', /^Speed/],
  ['ترجمة', /Translate|Arabic/], ['قص', /^Split$|Trim/], ['تصدير', /^Export$/], ['موسيقى', /Music/], ['عربي', /Arabic|عربي|Translate/],
  ['older engine', /older engine/i], ['where are my files', /Where are my files|Folders/], ['4k', /4K/], ['lufs', /Loudness/],
];
const LAT_Q = ['trim', 'split the clip', 'slow motion', 'speed ramp', 'noise', 'enhance voice', 'logo', 'brand kit', 'export 4k', 'max quality', 'capsules', 'arabic captions', 'translate', 'pexels key',
  'unsplash', 'freeze frame', 'reverse', 'ducking', 'safe areas', 'frame rate', 'background colour', 'snap', 'marker', 'duplicate', 'unlink', 'word sync', 'font size', 'caption position', 'themes',
  'transitions', 'ad studio', 'models', 'folders', 'contact', 'keyboard', 'remove background', 'skin smoothing', 'retention', 'hook builder', 'cta', 'loop ending', 'tighten', 'colour look',
  'exposure', 'vignette', 'denoise', 'crop', 'rotate', 'opacity', 'zoom', 'volume', 'mute', 'fade in', 'lower third', 'price tag', 'stickers', 'flags', 'templates', 'recipe', 'trimm captoin slowmo'];

(async () => {
  const clip = path.join(H.ROOT, 'tools/out/ui/ui-clip.mp4');
  if (!fs.existsSync(clip)) { fs.mkdirSync(path.dirname(clip), { recursive: true }); execFileSync('ffmpeg', ['-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=s=720x1280:r=30:d=8', '-f', 'lavfi', '-i', 'sine=f=330:d=8', '-shortest', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-c:a', 'aac', clip]); }
  const STUDIO = H.makeStudio();
  const e = await H.startEngine(STUDIO);
  const browser = await H.launchChrome();
  const errors = [];
  const newPage = async (ws, vw = 1440, vh = 900) => {
    const context = await browser.newContext({ viewport: { width: vw, height: vh } });
    await context.addInitScript((id) => { try { localStorage.setItem('kbbstudio.workspace', id); localStorage.removeItem('kbbstudio.search.recent'); } catch {} }, ws);
    const page = await context.newPage(); page.setDefaultTimeout(45000);
    page.on('console', (m) => { if (m.type() === 'error' && !BENIGN.test(m.text())) errors.push(`[${ws}] ${m.text()}`); });
    page.on('pageerror', (err) => errors.push(`[${ws}] pageerror: ${err.message}`));
    return { context, page };
  };

  // ── 1 · coverage (+ --write) and hover, over every state ──
  if (WRITE || want('coverage') || want('hover')) {
    const all = new Map(); let states = 0, gaps = [], unl = [], noHelp = [], probes = 0; const probeBad = [];
    for (const ws of ['kbb', 'planet-careers']) {
      const { context, page } = await newPage(ws);
      await W.walk(page, e.url, ws, clip, async (name, err) => {
        if (err) { ok(false, `walk: ${name}`); return; }
        states++;
        const r = await crawlState(page, { withHover: want('hover') && !WRITE });
        for (const row of r.rows) { const k = row.key; if (!all.has(k)) all.set(k, { ...row, wss: new Set() }); all.get(k).wss.add(ws); if (row.cat != null) all.get(k).cat = row.cat; }
        for (const g of r.gaps) gaps.push(`[${ws} · ${name}] ${g}`);
        for (const u of r.unlabelled) unl.push(`[${ws} · ${name}] ${u}`);
        for (const n of r.noHelp) noHelp.push(`[${ws} · ${name}] ${n}`);
        if (want('hover') && !WRITE) for (let i = 0; i < r.sample.length; i++) {
          const p = await hoverProbe(page, `[data-kbs-probe="${i}"]`); if (p.skip || p.gone) continue;
          probes++;
          const bad = !p.shown ? 'no card' : !p.text?.trim() ? 'empty card' : !p.inside ? 'card outside the window' : p.overlap ? 'card covers its control' : p.titles.length ? 'a native title is left (' + p.titles.join(',') + ')' : '';
          if (bad) probeBad.push(`[${ws} · ${name}] “${r.sample[i]}”: ${bad}`);
        }
        await page.evaluate(() => document.querySelectorAll('[data-kbs-probe]').forEach((x) => x.removeAttribute('data-kbs-probe')));
      });
      await context.close();
    }
    note(`${states} states crawled (both companies, folded sections opened): ${all.size} distinct controls`);
    if (WRITE) {
      const paths = [], ctxs = [], pi = new Map(), ci = new Map();
      const idx = (m, arr, v) => { const k = JSON.stringify(v); if (!m.has(k)) { m.set(k, arr.length); arr.push(v); } return m.get(k); };
      const rows = [...all.values()].sort((a, b) => a.key.localeCompare(b.key)).map((r) => [r.label, r.icon || '', r.title || '', r.kind, idx(pi, paths, r.path), idx(ci, ctxs, r.ctx), r.sub || '', r.cat ?? null, r.wss.size > 1 ? '' : [...r.wss][0]]);
      fs.writeFileSync(DATA, '// KBB Studio — core/search-crawl-data.js: GENERATED by `node tools/search-check.cjs --write` (do not edit by hand).\n'
        + '// Every control the crawl (core/search-crawl.js) found on every screen and tab state, both companies, folded sections\n'
        + '// open: [label, icon, tooltip, kind, path#, place#, row, catalogue#, only-this-company]. Read by core/search-store.js.\n'
        + 'export default ' + JSON.stringify({ when: new Date().toISOString().slice(0, 10), paths, ctxs, rows }) + ';\n');
      note(`wrote ${path.relative(H.ROOT, DATA)}: ${rows.length} controls, ${(fs.statSync(DATA).size / 1024).toFixed(0)} KB`);
    }
    if (want('coverage') && !WRITE) {
      ok(!gaps.length, `coverage: every visible control on every state is in the index (${gaps.length} missing)`);
      for (const g of [...new Set(gaps)].slice(0, 40)) note('missing: ' + g);
      ok(!unl.length, `coverage: every visible control has words (text, aria-label or title) (${unl.length} without)`);
      for (const u of [...new Set(unl)].slice(0, 20)) note('no words: ' + u);
    }
    if (want('hover') && !WRITE) {
      ok(!noHelp.length, `hover: every indexed visible control has a description (${noHelp.length} without)`);
      for (const n of noHelp.slice(0, 20)) note('no help: ' + n);
      ok(probes >= 100 && !probeBad.length, `hover: ${probes} controls really hovered over all states — card shows with words, inside the window, off its control, no native title (${probeBad.length} bad)`);
      for (const b of probeBad.slice(0, 30)) note(b);
    }
  }

  // ── 2 · the editor: typo/synonyms, latency, navigation, tooltips at 1280/2560, screenshots ──
  if (!WRITE && (want('typo') || want('latency') || want('nav') || want('shots') || want('edges'))) {
    const { context, page } = await newPage('kbb');
    await page.goto(e.url + '/app/'); await page.waitForSelector('#su-later, .home'); if (await page.$('#su-later')) await page.click('#su-later');
    await W.openEditor(page, e.url, 'kbb', clip);
    await page.evaluate(async () => { const S = await import('/app/core/search-store.js'); await S.getStore(); });
    const size = await page.evaluate(async () => { const S = await import('/app/core/search-store.js'); const s = await S.getStore(); return { n: s.entries.length, cat: s.entries.filter((x) => x.cat).length, vocab: s.index.vocab }; });
    note(`index: ${size.n} entries (${size.cat} curated + ${size.n - size.cat} from the crawl), ${size.vocab} words`);

    if (want('shots')) {
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.click('.topbar .kbs-in'); await page.waitForSelector('.kbs-pop:not([hidden]) .kbs-empty'); await page.waitForTimeout(200);
      await page.screenshot({ path: path.join(SHOTS, '1-empty.png') });
      for (const [q, f] of [['trim', '2-trim'], ['noise', '3-noise'], ['slow mo', '4-slow-mo']]) {
        await page.fill('.topbar .kbs-in', q); await page.waitForTimeout(250); await page.screenshot({ path: path.join(SHOTS, f + '.png') });
      }
      await page.fill('.topbar .kbs-in', 'slow mo'); await page.waitForTimeout(150); await page.keyboard.press('Enter');
      await page.waitForSelector('.kbs-pulse'); await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(SHOTS, '5-selected-speed.png') });
      await page.keyboard.press('Control+k'); await page.keyboard.type('captoin'); await page.waitForTimeout(250); await page.screenshot({ path: path.join(SHOTS, '6-typo-captoin.png') });
      await page.fill('.topbar .kbs-in', 'xqzvw'); await page.waitForTimeout(250); await page.screenshot({ path: path.join(SHOTS, '7-no-result.png') });
      await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
      // hover cards
      for (const [sel, f] of [['.doit-btn[data-mod="suggest-style"]', '8-hover-suggest-style'], ['.side-tab[data-side="mod:look"]', '9-hover-look-tab'], ['section.timeline button[title^="Split"], section.timeline button[data-kbs-title^="Split"]', '10-hover-timeline-split']]) {
        const el = await page.$(sel); const b = el && await el.boundingBox();
        if (!b) { ok(false, 'shot: ' + sel); continue; }
        await page.mouse.move(2, 2); await page.waitForTimeout(450);
        await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2); await page.waitForSelector('.kbh.on'); await page.waitForTimeout(200);
        await page.screenshot({ path: path.join(SHOTS, f + '.png') });
      }
      await page.mouse.move(2, 2);
      note('screenshots → shots/search/');
    }

    if (want('typo')) {
      const res = await page.evaluate(async (T) => { const S = await import('/app/core/search-store.js'); const s = await S.getStore(); return T.map(([q]) => [q, s.index.search(q)[0]?.e.title || '(nothing)']); }, TYPO);
      let bad = 0;
      for (let i = 0; i < TYPO.length; i++) { const good = TYPO[i][1].test(res[i][1]); if (!good) { bad++; note(`typo/synonym “${res[i][0]}” → ${res[i][1]} (want ${TYPO[i][1]})`); } }
      ok(!bad, `typo, synonym and Arabic: ${TYPO.length - bad}/${TYPO.length} queries put the right result on top`);
    }

    if (want('latency')) {
      const lat = await page.evaluate(async (Q) => {
        const S = await import('/app/core/search-store.js'); const s = await S.getStore();
        s.index.search('warm up');
        // each query typed letter by letter from a cold word cache (as a person types it for the first time); typed three
        // times and the best of the three kept per keystroke, so a busy machine's scheduler is not counted as search time
        const runs = [0, 1, 2].map(() => { const ms = []; for (const q of Q) { s.index.clearCache(); for (let i = 1; i <= q.length; i++) { const t0 = performance.now(); s.index.search(q.slice(0, i)); ms.push(performance.now() - t0); } } return ms; });
        const ms = runs[0].map((x, i) => Math.min(x, runs[1][i], runs[2][i])).sort((a, b) => a - b);
        return { n: ms.length, med: ms[ms.length >> 1], p95: ms[Math.floor(ms.length * 0.95)], max: ms[ms.length - 1], entries: s.entries.length };
      }, LAT_Q);
      ok(lat.max < 5, `latency: ${lat.n} keystrokes over ${lat.entries} entries — median ${lat.med.toFixed(2)} ms, p95 ${lat.p95.toFixed(2)} ms, max ${lat.max.toFixed(2)} ms (limit 5 ms)`);
      // and through the real box: type a word, the list is drawn
      await page.click('.topbar .kbs-in'); await page.waitForSelector('.kbs-pop:not([hidden])');
      await page.keyboard.type('speed ramp', { delay: 15 }); await page.waitForTimeout(100);
      const ui = await page.evaluate(() => window.__kbsLastMs);
      note(`in the box: last keystroke searched in ${ui?.toFixed(2)} ms`);
      await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
    }

    if (want('nav')) {
      let good = 0;
      for (const [q, top, at] of NAV) {
        await page.mouse.move(2, 2);
        const isEditor = await page.$('.editor');
        if (!isEditor) await W.openEditor(page, e.url, 'kbb', clip);
        await page.keyboard.press('Escape');
        await page.keyboard.press(process.platform === 'darwin' ? 'Meta+k' : 'Control+k');
        const focused = await page.evaluate(() => document.activeElement?.classList.contains('kbs-in'));
        await page.keyboard.type(q); await page.waitForSelector('.kbs-o[data-i="0"]', { timeout: 4000 }).catch(() => {});
        const title = await page.$eval('.kbs-o[data-i="0"] .ot', (x) => x.textContent).catch(() => '(nothing)');
        const t1 = top.test(title);
        await page.evaluate(() => document.querySelectorAll('.kbs-pulse').forEach((x) => x.classList.remove('kbs-pulse')));
        if (/^(ad studio)$/.test(q)) {
          // leaves the page for the Ad Studio: check it went there, then come back
          await page.keyboard.press('Enter'); await page.waitForURL(/adstudio/, { timeout: 8000 }).catch(() => {});
          const there = /adstudio/.test(page.url());
          if (ok(focused && t1 && there, `nav “${q}” → ${title} → opens the Ad Studio`)) good++;
          await W.openEditor(page, e.url, 'kbb', clip); continue;
        }
        await page.keyboard.press('Enter');
        const landed = await page.waitForFunction(() => document.querySelector('.kbs-pulse') || document.querySelector('.modal .setup-form'), null, { timeout: 8000 }).then(() => true).catch(() => false);
        await page.waitForTimeout(250);
        const st = await page.evaluate((at) => {
          const ring = document.querySelector('.kbs-pulse') || document.querySelector('.modal .setup-form');
          if (!ring) return { ring: false };
          const r = ring.getBoundingClientRect(), a = document.activeElement;
          const visible = r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < innerHeight && r.right > 0 && r.left < innerWidth;
          const focus = !!a && a !== document.body && (ring.contains(a) || a.contains(ring) || ring === a || !!ring.closest('.setup-form'));
          return { ring: true, visible, focus, at: !at || !!document.querySelector(at), what: (ring.getAttribute('aria-label') || ring.textContent || '').trim().slice(0, 40) };
        }, at);
        const pass = focused && t1 && landed && st.ring && st.visible && st.focus && st.at;
        if (ok(pass, `nav “${q}” → top “${title}”${t1 ? '' : ' (want ' + top + ')'} → ${st.ring ? `ringed “${st.what}”` : 'nothing ringed'}${st.visible ? '' : ' NOT VISIBLE'}${st.focus ? ', focused' : ' NOT FOCUSED'}${st.at ? '' : ' (not at ' + at + ')'}`)) good++;
        // close what it opened (dialogs, Configure) for the next query
        for (let i = 0; i < 3 && await page.$('.modal-back'); i++) { await page.keyboard.press('Escape'); await page.waitForTimeout(100); }
        if (await page.$('.cfg-back')) { await page.click('#cfg-close'); await page.waitForTimeout(150); }
      }
      ok(good === NAV.length && NAV.length >= 40, `navigation: ${good}/${NAV.length} searches land on the right control, visible and focused`);
      // a one-shot action offers Run and Show me where; Run really runs it (Marker adds a marker)
      if (!(await page.$('.editor'))) await W.openEditor(page, e.url, 'kbb', clip);
      const before = await page.evaluate(() => kbbEditor.project.markers.length);
      await page.keyboard.press('Control+k'); await page.keyboard.type('marker'); await page.waitForSelector('.kbs-o[data-i="0"] .ob.run');
      const both = await page.$$eval('.kbs-o[data-i="0"] .ob', (b) => b.map((x) => x.textContent));
      await page.keyboard.press('Control+Enter'); await page.waitForTimeout(700);
      const after = await page.evaluate(() => kbbEditor.project.markers.length);
      ok(both.join('|') === 'Run|Show me where' && after === before + 1, `one-shot action: “Marker” offers ${both.join(' / ')}; ⌘↵ ran it (markers ${before} → ${after})`);
      // a control that needs a selection with nothing on the reel to select says so in one line
      await page.evaluate(() => { kbbEditor.select([]); });
    }

    if (want('edges')) {
      // the card at the window's edges, at 1280 and 2560 wide
      for (const [vw, vh] of [[1280, 800], [2560, 1440]]) {
        await page.setViewportSize({ width: vw, height: vh }); await page.waitForTimeout(300);
        let bad = [], n = 0;
        for (const sel of ['.export-btn', '.stop-btn', '.topbar .brand', '.doit-btn[data-mod="suggest-style"]', '.side-tab[data-side="inspector"]', '.side-tab[data-side="mod:designs"]', '.side-tab[data-side="mod:look"]', '.bin-tab', 'section.timeline .tl-tools button, section.timeline button', '.topbar .kbs-in']) {
          const p = await hoverProbe(page, sel); if (p.skip) continue; n++;
          if (!p.shown || !p.inside || p.overlap || p.titles.length) bad.push(`${sel}: ${!p.shown ? 'no card' : !p.inside ? 'outside' : p.overlap ? 'covers it' : 'native title left'}`);
        }
        ok(n >= 8 && !bad.length, `hover at ${vw}×${vh}: ${n} edge controls — card inside the window and off the control${bad.length ? ': ' + bad.join('; ') : ''}`);
      }
      // the search box itself never pushes the top bar out of shape (1280–2560)
      for (const vw of [1280, 1440, 1920, 2560]) {
        await page.setViewportSize({ width: vw, height: 900 }); await page.waitForTimeout(200);
        const m = await page.evaluate(() => {
          const t = document.querySelector('.topbar'), s = document.querySelector('.topbar .kbs'), sv = document.querySelector('.topbar .save-state'), a = document.querySelector('.topbar .aspect'), n = document.querySelector('.topbar .proj-name');
          const r = (x) => x.getBoundingClientRect(), tops = [...a.children].map((b) => Math.round(r(b).top));
          // nothing else in the bar gives up room for the search: the shapes stay on one row, the name keeps its width
          return { sw: t.scrollWidth, cw: t.clientWidth, w: r(s).width, gapL: r(s).left - r(sv).right, gapR: r(a).left - r(s).right, oneRow: new Set(tops).size === 1, name: Math.round(r(n).width), th: Math.round(r(t).height) };
        });
        await page.focus('.topbar .kbs-in');
        m.open = await page.$eval('.topbar .kbs-in', (x) => Math.round(x.getBoundingClientRect().width));
        await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
        ok(m.sw <= m.cw + 1 && m.w >= 36 && m.open >= 240 && m.gapL >= 0 && m.gapR >= 0 && m.oneRow && m.name >= 239 && m.th === 58, `top bar at ${vw}: search ${Math.round(m.w)} px wide (${m.open} px while typing) between “Saved” and the shapes (gaps ${Math.round(m.gapL)} / ${Math.round(m.gapR)} px); shapes on one row: ${m.oneRow}; name ${m.name} px; bar ${m.th} px; no overflow`);
      }
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    await context.close();
  }

  ok(!errors.length, `console: ${errors.length} errors`);
  for (const x of [...new Set(errors)].slice(0, 20)) note(x);
  await browser.close(); await H.stopEngine(e);
  fs.rmSync(STUDIO, { recursive: true, force: true });
  lines.unshift(`KBB Studio search check · ${new Date().toISOString()}`, `${failures ? failures + ' FAILED' : 'ALL PASS'}`);
  if (!WRITE) fs.writeFileSync(path.join(SHOTS, 'check.txt'), lines.join('\n') + '\n');
  console.log(failures ? `SEARCH CHECK: ${failures} FAILED` : 'SEARCH CHECK: ALL PASS');
  process.exit(failures ? 1 : 0);
})().catch((err) => { console.error(err); process.exit(2); });
