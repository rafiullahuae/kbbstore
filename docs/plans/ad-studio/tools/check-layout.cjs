// The Ad Studio's layout (18 Nov 2026). The owner: "can u plz also make the ads studio overall layout fit to screen,
// same like video editor". The video editor (studio/reel-studio/app/editor) is an app shell: it fills the window exactly,
// a top bar, panels that scroll on their own, the picture in the middle, the timeline at the bottom, and the page itself
// never scrolls. This checks the Ad Studio is the same, inside KBB Studio (served by its engine) and as the standalone
// file, at 1280×800, 1440×900, 1920×1080 and 2560×1440 (and 1024×768 for "nothing overflows sideways"), in Ads and Video:
//   * the document is exactly the window: scrollHeight == innerHeight, scrollWidth == innerWidth
//   * at fit zoom the whole design is on show for every size (Ads: post, square, story; Video: 9:16, 4:5, 1:1, 16:9),
//     inside its host, and it uses the room (one side ≥ 85% of the host)
//   * the panels scroll inside themselves (the template list, the video media tab) and the window never moves
//   * nothing lies over the top bar (elementFromPoint over its whole box lands in the top bar)
//   * zoom: 50–400 % and ⌘+ / ⌘− / ⌘0, panning when zoomed in; panels collapse and their widths are remembered
//   * the full-HD PNGs are the same pixels as before the layout change (tools/layout-export-baseline.json, written by
//     --baseline from the build before it; pixel data hashed, light and dark colour scheme)
// usage:  NODE_PATH=/home/user/kbbstore/node_modules node tools/check-layout.cjs [--baseline] [--shots before|after]
//   --baseline     writes tools/layout-export-baseline.json from the current build (run it on the build BEFORE a change)
//   --shots X      also writes shots/layout/X-{ads,video}-{1440x900,1920x1080}.png (inside KBB Studio)
//   --measure      prints the raw numbers only (no assertions): what the "before" report quotes
'use strict';
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs'), os = require('os'), cp = require('child_process');
const ROOT = path.join(__dirname, '..');
const FILE = 'file://' + path.join(ROOT, 'dist', 'ad-studio.html');
const REEL = path.resolve(ROOT, '..', '..', '..', 'studio', 'reel-studio');
const BASE = path.join(__dirname, 'layout-export-baseline.json');
const SHOTS = path.join(ROOT, 'shots', 'layout');
const arg = (k) => { const i = process.argv.indexOf(k); return i < 0 ? null : (process.argv[i + 1] || ''); };
const BASELINE = process.argv.includes('--baseline'), MEASURE = process.argv.includes('--measure'), SHOT = arg('--shots');
const VIEWS = [[1280, 800], [1440, 900], [1920, 1080], [2560, 1440]];
const EXPORTS = [['flash-sale', 'post'], ['flash-sale', 'square'], ['flash-sale', 'story'], ['eid-al-fitr', 'story'], ['pc-study-gb', 'post'], ['wa-hire-welder', 'square']];
let fails = 0; const ok = (c, m) => { if (MEASURE) return; console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };

// the engine, as KBB Studio runs it (a throwaway studio folder and HOME); skipped when the Linux engine is not built
function engine() {
  const BIN = path.join(REEL, 'engine', 'kbbstudio-linux-amd64');
  if (!fs.existsSync(BIN)) return Promise.resolve(null);
  const H = require(path.join(REEL, 'tools', 'studio-harness.cjs'));
  const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'kbblay-')), HOME = path.join(TMP, 'home'); fs.mkdirSync(HOME, { recursive: true });
  const APP = H.makeStudio();
  return new Promise((res, rej) => {
    const p = cp.spawn(BIN, ['--dir', APP, '--data', path.join(HOME, 'KBB Studio Data'), '--no-browser', '--idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, { HOME }) });
    let buf = '';
    p.stdout.on('data', (d) => { buf += d; const m = /running at (http:\/\/[^\s]+)\/app\//.exec(buf); if (m && !p.url) { p.url = m[1]; res(p); } });
    p.on('exit', (c) => { if (!p.url) rej(new Error('engine exited ' + c)); });
    p.cleanup = () => { try { p.kill('SIGTERM'); } catch (e) {} fs.rmSync(TMP, { recursive: true, force: true }); fs.rmSync(APP, { recursive: true, force: true }); };
  });
}

async function open(b, url, w, h, scheme) {
  const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: scheme || 'light' });
  await ctx.addInitScript(() => { try { if (location.protocol !== 'file:') localStorage.setItem('kbbstudio.setupLater', String(Date.now())); } catch (e) {} });
  const p = await ctx.newPage(), errs = [];
  p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); }); p.on('pageerror', (e) => errs.push(e.message)); p.on('dialog', (d) => d.accept());
  await p.goto(url); await p.waitForFunction(() => document.documentElement.dataset.ready === '1', null, { timeout: 60000 });
  await p.evaluate(() => document.fonts.ready);
  await p.evaluate(() => { const m = document.getElementById('mv'); if (m) m.hidden = true; });   // the move-your-designs banner is not the layout
  return { ctx, p, errs };
}
const mode = async (p, m) => {
  if (await p.evaluate(() => document.body.dataset.mode || 'ads') !== m) await p.click('#modesw button[data-m="' + m + '"]');
  if (m === 'video') await p.waitForFunction(() => document.documentElement.dataset.vready === '1');
  await p.waitForTimeout(150);
};

// the raw numbers of one screen
function measure(p, m) {
  return p.evaluate((m) => {
    const R = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), b: Math.round(r.bottom), r: Math.round(r.right) }; };
    const de = document.documentElement, top = document.querySelector('header.top');
    const cv = m === 'video' ? document.getElementById('v-cv') : document.querySelector('#stage .cv');
    const host = m === 'video' ? document.querySelector('.v-stage') : document.querySelector('.stagewrap');
    const panes = [...document.querySelectorAll(m === 'video' ? '.v-pane' : '.pane')].filter((x) => !x.hidden && x.offsetParent);
    // where the top bar is covered: sample its box, every hit must be inside it
    let over = 0, n = 0; const tr = top.getBoundingClientRect();
    for (let x = tr.left + 2; x < Math.min(tr.right, innerWidth) - 1; x += 24) for (let y = tr.top + 2; y < tr.bottom - 1; y += 8) { n++; const e = document.elementFromPoint(x, y); if (e && !top.contains(e)) over++; }
    return { vw: innerWidth, vh: innerHeight, sh: de.scrollHeight, sw: de.scrollWidth, top: R(top), canvas: R(cv), host: R(host),
      panes: panes.map((x) => ({ k: x.dataset.pane || x.dataset.vp, r: R(x), sh: x.scrollHeight, ch: x.clientHeight, oy: getComputedStyle(x).overflowY })), overTop: over, samples: n,
      tl: m === 'video' ? R(document.querySelector('.v-tlw')) : null };
  }, m);
}
const inside = (a, h, t = 1) => a && h && a.x >= h.x - t && a.y >= h.y - t && a.r <= h.r + t && a.b <= h.b + t;

// the full-HD PNG of a template, as pixel data hashed (encoder-independent)
function pixels(p, list) {
  return p.evaluate(async (list) => {
    const S = window.KBB_STUDIO, out = {};
    for (const [id, f] of list) {
      S.useTemplate(id); const blob = await S.renderFile(f, 0, 'png'), bm = await createImageBitmap(blob);
      const c = new OffscreenCanvas(bm.width, bm.height), x = c.getContext('2d'); x.drawImage(bm, 0, 0);
      const d = x.getImageData(0, 0, bm.width, bm.height).data, h = await crypto.subtle.digest('SHA-256', d);
      out[id + ':' + f] = bm.width + 'x' + bm.height + ':' + Array.from(new Uint8Array(h)).map((v) => v.toString(16).padStart(2, '0')).join('');
    }
    return out;
  }, list);
}

(async () => {
  const b = await chromium.launch({ executablePath: fs.existsSync('/opt/pw-browsers/chromium') ? '/opt/pw-browsers/chromium' : undefined });
  const eng = await engine().catch((e) => { console.log('engine: ' + e.message); return null; });
  const ctxs = [['file', FILE]]; if (eng) ctxs.unshift(['studio', eng.url + '/app/adstudio/ad-studio.html']); else console.log('NOTE the Linux engine is not built (studio/reel-studio/engine/build.sh): checking the standalone file only');
  if (SHOT) fs.mkdirSync(SHOTS, { recursive: true });

  if (BASELINE) {
    const out = {};
    for (const sch of ['light', 'dark']) { const o = await open(b, FILE, 1440, 900, sch); out[sch] = await pixels(o.p, EXPORTS); await o.ctx.close(); }
    fs.writeFileSync(BASE, JSON.stringify(out, null, 1) + '\n'); console.log('wrote ' + path.relative(ROOT, BASE) + ' (' + Object.keys(out.light).length + ' renders × 2 schemes)');
  }

  for (const [ck, url] of ctxs) {
    for (const [w, h] of VIEWS.concat(MEASURE ? [] : [[1024, 768]])) {
      const o = await open(b, url, w, h, 'light'), p = o.p, tag = ck + ' ' + w + '×' + h;
      for (const m of ['ads', 'video']) {
        await mode(p, m);
        const fmts = m === 'ads' ? ['post', 'square', 'story'] : ['story', 'post', 'square', 'wide'];
        const per = {};
        for (const f of fmts) {
          if (m === 'ads') await p.evaluate((f) => { const i = ['post', 'square', 'story'].indexOf(f); document.querySelectorAll('#fmts .chip')[i].click(); }, f);
          else await p.click('#v-ab button[data-ab="' + f + '"]');
          await p.waitForTimeout(120); per[f] = await measure(p, m);
        }
        const M = per[fmts[0]];
        if (MEASURE) {
          console.log(tag + ' ' + m + ': page ' + M.sw + '×' + M.sh + ' in ' + M.vw + '×' + M.vh + ' (scrolls ' + (M.sh - M.vh) + ' px down, ' + (M.sw - M.vw) + ' px across); ' +
            fmts.map((f) => f + ' ' + (per[f].canvas ? per[f].canvas.w + '×' + per[f].canvas.h + ' bottom ' + per[f].canvas.b : '-')).join(', ') +
            '; host ' + (M.host ? M.host.w + '×' + M.host.h : '-') + '; top bar covered ' + M.overTop + '/' + M.samples +
            '; panes ' + M.panes.map((x) => x.k + ' ' + x.r.w + '×' + x.r.h + ' (content ' + x.sh + ', ' + x.oy + ')').join(' '));
        } else {
          ok(M.sh === M.vh && M.sw === M.vw && await p.evaluate(() => scrollY === 0), tag + ' ' + m + ': the page is the window (' + M.sw + '×' + M.sh + ')');
          for (const f of fmts) {
            const c = per[f].canvas, hs = per[f].host, fill = Math.max(c.w / hs.w, c.h / hs.h);
            ok(inside(c, hs) && c.b <= M.vh && c.r <= M.vw && c.y >= M.top.b && fill >= .85, tag + ' ' + m + ' ' + f + ': the whole design is on show at fit (' + c.w + '×' + c.h + ' in ' + hs.w + '×' + hs.h + ', ' + Math.round(fill * 100) + '% of the room)');
          }
          ok(M.overTop === 0, tag + ' ' + m + ': nothing lies over the top bar (' + M.samples + ' points)');
          ok(M.panes.length > 0 && M.panes.every((x) => /auto|scroll/.test(x.oy) && x.r.b <= M.vh + 1), tag + ' ' + m + ': the panels end inside the window and scroll themselves (' + M.panes.map((x) => x.k + ' ' + x.r.h + '/' + x.sh).join(', ') + ')');
          if (m === 'video') ok(M.tl && M.tl.b <= M.vh && M.tl.y > per[fmts[0]].host.b - 2, tag + ' video: the timeline sits at the bottom, under the picture (' + (M.tl && M.tl.y + '–' + M.tl.b) + ')');
          // a panel's own scrolling never moves the window
          const shut = m === 'ads' && await p.evaluate(() => document.getElementById('lp-tg').getAttribute('aria-pressed') === 'false');
          if (shut) await p.click('#lp-tg');   // 1024: the template list is in the left panel, which starts shut there
          const sc = await p.evaluate((m) => { const x = [...document.querySelectorAll(m === 'video' ? '.v-pane' : '.pane')].find((x) => !x.hidden && x.offsetParent && x.scrollHeight > x.clientHeight + 4);
            if (!x) return null; x.scrollTop = 200; const r = { k: x.dataset.pane || x.dataset.vp, st: x.scrollTop, win: scrollY + document.documentElement.scrollTop }; x.scrollTop = 0; return r; }, m);
          if (m === 'ads' || sc) ok(sc && sc.st > 0 && sc.win === 0, tag + ' ' + m + ': a long panel (' + (sc && sc.k) + ') scrolls inside itself, the window stays put');
          if (shut) { await p.click('#lp-tg'); await p.evaluate(() => { try { localStorage.removeItem('kbbas:layout'); } catch (e) {} }); }
        }
        if (SHOT && ck === 'studio' && ((w === 1440 && h === 900) || (w === 1920 && h === 1080))) {
          if (m === 'ads') await p.evaluate(() => { const S = window.KBB_STUDIO; S.useTemplate('flash-sale'); document.querySelectorAll('#fmts .chip')[0].click(); });
          else await p.click('#v-ab button[data-ab="story"]');
          await p.waitForTimeout(250); await p.screenshot({ path: path.join(SHOTS, SHOT + '-' + m + '-' + w + 'x' + h + '.png') });
        }
      }
      if (!MEASURE) ok(o.errs.length === 0, tag + ': no console errors ' + o.errs.slice(0, 3).join(' | '));
      await o.ctx.close();
    }
  }

  if (!MEASURE && !BASELINE) {
    // zoom, panning, panels: driven like the owner would, at 1440×900 (the standalone file: same markup)
    const o = await open(b, ctxs[0][1], 1440, 900, 'light'), p = o.p;
    await p.evaluate(() => window.KBB_STUDIO.useTemplate('flash-sale'));
    const cw = () => p.evaluate(() => Math.round(document.querySelector('#stage .cv').getBoundingClientRect().width));
    const fitW = await cw();
    ok(await p.$eval('#zm-sel', (s) => s.value) === 'fit' && /%/.test(await p.textContent('#zm-v')), 'zoom opens at Fit (' + await p.textContent('#zm-v') + ')');
    await p.selectOption('#zm-sel', '200'); ok(Math.abs(await cw() - 2160) <= 2, '200% draws the 1080-px design 2160 px wide (' + await cw() + ')');
    const pan = await p.evaluate(() => { const s = document.querySelector('.stagewrap'); s.scrollLeft = 300; s.scrollTop = 300; return { l: s.scrollLeft, t: s.scrollTop, sw: document.documentElement.scrollWidth, sh: document.documentElement.scrollHeight }; });
    ok(pan.l > 0 && pan.t > 0 && pan.sw === 1440 && pan.sh === 900, 'zoomed in, the canvas pans inside its host (scroll ' + pan.l + ', ' + pan.t + ') and the page stays 1440×900');
    // space-drag pans too
    await p.keyboard.press('Control+0'); ok(await p.$eval('#zm-sel', (s) => s.value) === 'fit' && Math.abs(await cw() - fitW) <= 1, 'Ctrl+0 goes back to Fit');
    await p.keyboard.press('Control+='); await p.keyboard.press('Control+='); const z1 = await p.$eval('#zm-sel', (s) => s.value); ok(z1 !== 'fit' && +z1 >= 50, 'Ctrl++ (twice) zooms in (' + z1 + '%)');
    await p.keyboard.press('Control+-'); const z2 = await p.$eval('#zm-sel', (s) => s.value); ok(+z2 < +z1, 'Ctrl+− zooms out (' + z2 + '%)');
    await p.selectOption('#zm-sel', '400'); ok(Math.abs(await cw() - 4320) <= 2, '400% is 4320 px');
    await p.selectOption('#zm-sel', '50'); ok(Math.abs(await cw() - 540) <= 2, '50% is 540 px');
    // a drag on the zoomed design still moves the headline in design units (the editor maths does not care about zoom)
    const y0 = await p.evaluate(() => window.KBB_STUDIO.doc.geo.post.h1.y), bx = await p.locator('#stage .el[data-id="h1"]').boundingBox();
    await p.mouse.move(bx.x + bx.width / 2, bx.y + bx.height / 2); await p.mouse.down(); await p.mouse.move(bx.x + bx.width / 2, bx.y + bx.height / 2 + 27, { steps: 5 }); await p.mouse.up();
    const y1 = await p.evaluate(() => window.KBB_STUDIO.doc.geo.post.h1.y);
    ok(Math.abs((y1 - y0) - 27 / 540 * 100) < .6, 'at 50% a 27-px drag moves the headline 5% of the design (' + (y1 - y0).toFixed(2) + ')'); await p.keyboard.press('Control+z');
    await p.click('#zm-fit');
    // panels: collapse, resize, remembered
    const lw0 = await p.evaluate(() => Math.round(document.querySelector('.lpanel').getBoundingClientRect().width));
    await p.click('#lp-tg'); const col = await p.evaluate(() => ({ w: Math.round(document.querySelector('.lpanel').getBoundingClientRect().width), cv: Math.round(document.querySelector('#stage .cv').getBoundingClientRect().width) }));
    ok(col.w === 0 && col.cv >= fitW, 'the left panel collapses (' + lw0 + ' → ' + col.w + ' px) and the design keeps its fit (' + fitW + ' → ' + col.cv + ')'); await p.click('#lp-tg');
    const rz = await p.locator('.lpanel .rz').boundingBox(); await p.mouse.move(rz.x + rz.width / 2, rz.y + 200); await p.mouse.down(); await p.mouse.move(rz.x + rz.width / 2 + 60, rz.y + 200, { steps: 4 }); await p.mouse.up();
    const lw1 = await p.evaluate(() => Math.round(document.querySelector('.lpanel').getBoundingClientRect().width));
    const rz2 = await p.locator('#rpanel .rz').boundingBox(); await p.mouse.move(rz2.x + rz2.width / 2, rz2.y + 200); await p.mouse.down(); await p.mouse.move(rz2.x + rz2.width / 2 - 40, rz2.y + 200, { steps: 4 }); await p.mouse.up();
    const rw1 = await p.evaluate(() => Math.round(document.querySelector('#rpanel').getBoundingClientRect().width));
    await p.click('#rp-tg');
    await p.reload(); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
    const re = await p.evaluate(() => ({ l: Math.round(document.querySelector('.lpanel').getBoundingClientRect().width), r: Math.round(document.querySelector('#rpanel').getBoundingClientRect().width), rp: document.getElementById('rp-tg').getAttribute('aria-pressed') }));
    ok(Math.abs(lw1 - lw0 - 60) <= 2 && re.l === lw1 && re.r === 0 && re.rp === 'false', 'drag the left edge: ' + lw0 + ' → ' + lw1 + ' px; it, and the right panel shut, come back after a reload (' + JSON.stringify(re) + ')');
    await p.click('#rp-tg'); const re2 = await p.evaluate(() => Math.round(document.querySelector('#rpanel').getBoundingClientRect().width)); ok(Math.abs(re2 - rw1) <= 1, 'the right panel opens at its own width again (' + re2 + ')');
    await p.evaluate(() => { try { localStorage.removeItem('kbbas:layout'); } catch (e) {} });
    // selecting text: Properties on the right, Elements + Layers on the left, both on show at once
    await p.click('#stage .el[data-id="h1"]');
    ok(await p.isVisible('#p-txt') && await p.isVisible('#layers') && await p.isVisible('[data-add="heading"]'), 'a tap on the headline shows its properties (right) with Add and Layers (left)');
    ok(o.errs.length === 0, 'zoom / panels: no console errors ' + o.errs.slice(0, 3).join(' | '));
    await o.ctx.close();

    // 1024 wide: the left panel starts shut (the design keeps its room); opened, still nothing sideways
    const n = await open(b, ctxs[0][1], 1024, 768, 'light'), q = n.p;
    const w0 = await q.evaluate(() => ({ l: Math.round(document.querySelector('.lpanel').getBoundingClientRect().width), tg: document.getElementById('lp-tg').getAttribute('aria-pressed'), top: document.querySelector('header.top').offsetHeight }));
    await q.click('#lp-tg'); await q.waitForTimeout(100);
    const w1 = await q.evaluate(() => ({ l: Math.round(document.querySelector('.lpanel').getBoundingClientRect().width), sw: document.documentElement.scrollWidth, sh: document.documentElement.scrollHeight, cv: document.querySelector('#stage .cv').getBoundingClientRect().width }));
    ok(w0.l === 0 && w0.tg === 'false' && w0.top <= 60 && w1.l >= 200 && w1.sw === 1024 && w1.sh === 768 && w1.cv > 200, '1024×768: left panel shut at first, the top bar one row (' + w0.top + ' px); opened ' + w1.l + ' px, page ' + w1.sw + '×' + w1.sh + ', design ' + Math.round(w1.cv) + ' px wide');
    await n.ctx.close();

    // the pictures that leave the app are the same pixels as before
    if (!fs.existsSync(BASE)) ok(false, 'no tools/layout-export-baseline.json: run --baseline on the build before the layout change');
    else {
      const base = JSON.parse(fs.readFileSync(BASE, 'utf8'));
      for (const sch of ['light', 'dark']) {
        const q = await open(b, FILE, 1440, 900, sch), now = await pixels(q.p, EXPORTS);
        const diff = Object.keys(base[sch]).filter((k) => base[sch][k] !== now[k]);
        ok(diff.length === 0, 'exported PNGs (' + sch + '): ' + Object.keys(now).length + ' renders, the same pixels as before the layout' + (diff.length ? ' — differ: ' + diff.join(', ') : ''));
        await q.ctx.close();
      }
      if (eng) { const q = await open(b, ctxs[0][1], 1440, 900, 'light'), now = await pixels(q.p, EXPORTS.slice(0, 3)); const d = Object.keys(now).filter((k) => base.light[k] !== now[k]);
        ok(d.length === 0, 'exported PNGs inside KBB Studio (dark): the same pixels too' + (d.length ? ' — differ: ' + d.join(', ') : '')); await q.ctx.close(); }
    }
  }
  await b.close(); if (eng) eng.cleanup();
  if (!MEASURE) console.log(fails ? fails + ' FAILED' : 'ALL PASS');
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
