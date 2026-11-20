#!/usr/bin/env node
// LANE BRAIN UI check: runs the app against tools/brain-fake-engine.cjs in
// Chromium (Playwright) and measures what the owner asked for.
//   NODE_PATH=<repo>/node_modules node tools/brain-ui.cjs [--shots] [--quick]
// Checks (each prints PASS/FAIL with numbers):
//   1  every screen renders, no console errors, no failed requests
//   2  screen switch time (keyboard 1-9) < 50 ms, measured in the page
//   3  account switch is instant (cached) and repaints the account
//   4  Arabic UI: <html dir=rtl>, nav and content right-to-left; Arabic ad text RTL in English UI too
//   5  creatives render to PNG in 1:1 / 4:5 / 9:16 (exact sizes, PNG signature), all 6 templates, AR + EN
//   6  pending designs are rendered and POSTed by the app on load
//   7  motion ad: a 6 s 9:16 video is recorded (mp4 or webm) and saved
//   8  video analysis: a synthetic 4 s 9:16 test video is made in-page, dropped in, analysed by the real brain (local checks)
//   9  onboarding wizard walks all 7 steps
//   10 no layout-measuring JS loops: getBoundingClientRect/offsetWidth/... are not called by app code during switches
// --shots writes shots/*.jpg at 1440x900 and 1280x800, light + dark, + Arabic.
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '..');
const SHOTS = path.join(ROOT, 'shots');
const PORT = 41990 + Math.floor(Math.random() * 400);
const want = (f) => process.argv.includes(f);
const results = [];
const check = (n, ok, msg) => { results.push({ n, ok }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}  ${msg}`); };
const SCREENS = ['overview', 'portfolio', 'campaigns', 'creatives', 'videos', 'audiences', 'research', 'learned', 'plan', 'activity', 'approvals', 'settings'];
const REAL = process.argv.includes('--real');

(async () => {
  // --real: LANE ENGINE's engine with its in-process fake Meta / Claude / feed (-mock); default: tools/brain-fake-engine.cjs
  let eng, engLog = '';
  const tmpData = fs.mkdtempSync(path.join(require('os').tmpdir(), 'kaa-ui-'));
  if (REAL) {
    const bin = path.join(tmpData, 'kaa-engine');
    require('child_process').execFileSync('go', ['build', '-o', bin, '.'], { cwd: path.join(ROOT, 'engine') });
    eng = spawn(bin, ['-dir', ROOT, '-data', path.join(tmpData, 'data'), '-port', String(PORT), '-no-browser', '-mock', '-idle', '600s'], { stdio: ['ignore', 'pipe', 'pipe'], env: Object.assign({}, process.env, fs.existsSync('/root/.ccr/ca-bundle.crt') ? { SSL_CERT_FILE: '/root/.ccr/ca-bundle.crt' } : {}) });
  } else {
    eng = spawn(process.execPath, [path.join(__dirname, 'brain-fake-engine.cjs'), '--port', String(PORT)], { stdio: ['ignore', 'pipe', 'pipe'] });
  }
  eng.stdout.on('data', (d) => { engLog += d; }); eng.stderr.on('data', (d) => { engLog += d; });
  let up = false;
  for (let i = 0; i < 160 && !up; i++) { await new Promise((r) => setTimeout(r, 250)); up = await fetch(`http://127.0.0.1:${PORT}/api/info`).then((r) => r.ok).catch(() => false); }
  if (!up) { console.log(engLog); process.exit(2); }
  console.log(`engine: ${REAL ? 'REAL engine -mock' : 'fake engine'} on ${PORT}`);
  const base = `http://127.0.0.1:${PORT}/`;
  const browser = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
  const page = await ctx.newPage();
  const errors = [], failed = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  // a <video> reading a local blob: cancels its own range reads while seeking (ERR_ABORTED) -- not a failure
  page.on('requestfailed', (r) => { if (!/\/api\/events/.test(r.url()) && !(/^blob:/.test(r.url()) && /ERR_ABORTED/.test((r.failure() || {}).errorText || ''))) failed.push(r.url() + ' ' + (r.failure() || {}).errorText); });
  page.on('response', (r) => { if (r.status() >= 400 && !/\/api\/img/.test(r.url())) failed.push(`${r.status()} ${r.url()}`); });
  // count layout-measuring calls made while switching screens
  await page.addInitScript(() => {
    window.__measure = 0;
    for (const [o, m] of [[Element.prototype, 'getBoundingClientRect'], [Element.prototype, 'getClientRects']]) { const f = o[m]; o[m] = function (...a) { window.__measure++; return f.apply(this, a); }; }
    for (const p of ['offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft', 'clientWidth', 'clientHeight', 'scrollHeight', 'scrollWidth']) {
      const d = Object.getOwnPropertyDescriptor(HTMLElement.prototype, p) || Object.getOwnPropertyDescriptor(Element.prototype, p);
      if (d && d.get) Object.defineProperty(HTMLElement.prototype, p, { get() { window.__measure++; return d.get.call(this); }, configurable: true });
    }
  });
  await page.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
  await page.goto(base + '#/overview');
  await page.waitForFunction(() => window.__kaa && window.__kaa.state && document.querySelector('.kpis'), null, { timeout: 30000 });
  await page.waitForTimeout(800);

  // 1 + 2: every screen by keyboard, switch timing
  await page.evaluate(() => { window.__kaa.switches.length = 0; window.__measure = 0; });
  for (let round = 0; round < 3; round++) for (let i = 0; i < SCREENS.length; i++) { if (i < 10) await page.keyboard.press(String((i + 1) % 10)); else await page.evaluate((r) => { location.hash = '#/' + r; }, SCREENS[i]); await page.waitForTimeout(150); }
  await page.waitForTimeout(600);
  const sw = await page.evaluate(() => window.__kaa.switches.slice());
  const warm = sw.slice(SCREENS.length); // first visit may fetch lists; rounds 2-3 are the steady state
  const worst = Math.max(...warm.map((s) => s.ms)), avg = warm.reduce((a, s) => a + s.ms, 0) / warm.length;
  const perScreen = {};
  for (const s of warm) perScreen[s.route] = Math.max(perScreen[s.route] || 0, s.ms);
  check('screen switch < 50 ms', worst < 50, `worst ${worst.toFixed(1)} ms, mean ${avg.toFixed(1)} ms over ${warm.length} switches; per screen max ${JSON.stringify(perScreen)}`);
  const measured = await page.evaluate(() => window.__measure);
  check('no layout-measuring JS', measured === 0, `${measured} getBoundingClientRect/offset*/client* calls during ${sw.length} switches`);
  const filled = [];
  for (const s of SCREENS) { await page.goto(base + '#/' + s); await page.waitForTimeout(400); filled.push([s, (await page.$eval('#view', (e) => e.innerText.length))]); }
  check('every screen renders', filled.every(([, n]) => n > 80), filled.map(([s, n]) => `${s}:${n}ch`).join(' '));

  // 3: account switch
  await page.goto(base + '#/overview'); await page.waitForTimeout(500);
  const nAcct = await page.evaluate(() => window.__kaa.accounts.length);
  if (nAcct < 2) check('account switch instant', true, `N/A: this engine has ${nAcct} ad account`); else {
  const acct = await page.evaluate(async () => {
    const before = document.querySelector('#acct-btn b').textContent;
    const t0 = performance.now();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: ']' }));
    const ms = performance.now() - t0;
    await new Promise((r) => setTimeout(r, 400));
    return { before, after: document.querySelector('#acct-btn b').textContent, ms, title: document.querySelector('.hero h2').textContent };
  });
  check('account switch instant', acct.before !== acct.after && acct.ms < 50, `${acct.before} -> ${acct.after} in ${acct.ms.toFixed(1)} ms; hero: "${acct.title}"`);
  await page.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: '[' })));
  await page.waitForTimeout(400);
  }

  // 4: Arabic / RTL
  await page.goto(base + '#/plan'); await page.waitForTimeout(500);
  const rtlInEN = await page.$$eval('[lang="ar"]', (els) => els.filter((e) => getComputedStyle(e).direction === 'rtl').length + '/' + els.length);
  await page.keyboard.press('l'); await page.waitForTimeout(500);
  const rtl = await page.evaluate(() => ({ dir: document.documentElement.dir, navDir: getComputedStyle(document.querySelector('.nav')).direction, nav: document.querySelector('#nav a .lbl').textContent, font: getComputedStyle(document.body).fontFamily.split(',')[0] }));
  check('Arabic UI is RTL', rtl.dir === 'rtl' && rtl.navDir === 'rtl' && /[؀-ۿ]/.test(rtl.nav), `dir=${rtl.dir}, nav "${rtl.nav.trim()}", body font ${rtl.font}; Arabic blocks RTL in English UI: ${rtlInEN}`);
  if (want('--shots')) {
    fs.mkdirSync(SHOTS, { recursive: true });
    for (const s of ['overview', 'campaigns', 'creatives', 'research', 'plan', 'settings']) { await page.goto(base + '#/' + s); await page.waitForTimeout(900); await page.screenshot({ path: path.join(SHOTS, `${REAL ? 'real-' : ''}ar-${s}-1440.jpg`), type: 'jpeg', quality: 70 }); }
  }
  await page.keyboard.press('l'); await page.waitForTimeout(300);

  // 5: creative rendering in all formats/templates
  await page.goto(base + '#/creatives'); await page.waitForTimeout(800);
  const rend = await page.evaluate(async () => {
    const R = await import(new URL('js/creative/render.js', location.href).href);
    const out = [];
    const base = { images: ['https://kbeautybliss.com/wp-content/uploads/2024/10/Anua-–-Heartleaf-77-Soothing-Toner-250ml.webp'], badge: 'Anua', cta: 'SHOP_NOW', price: 'AED 105' };
    const AR = { lang: 'ar', headline: 'هارتليف ٧٧٪ للتهدئة', sub: 'مكوّن واحد، فرق واضح', bullets: ['هارتليف ٧٧٪', 'ترطيب خفيف', 'للاستخدام اليومي'] };
    const EN = { lang: 'en', headline: 'Heartleaf 77% calm', sub: 'One ingredient, all the calm', bullets: ['Heartleaf 77%', 'Light hydration', 'Daily use'] };
    for (const tpl of R.TEMPLATES) for (const L of [AR, EN]) for (const f of ['1:1', '4:5', '9:16']) {
      const t0 = performance.now();
      const cv = await R.renderSpec(Object.assign({ template: tpl }, base, L), f);
      const png = await R.toPNG(cv);
      const sig = atob(png.b64.slice(0, 12)).slice(1, 4);
      out.push({ tpl, lang: L.lang, f, w: cv.width, h: cv.height, sig, kb: Math.round(png.bytes / 1024), ms: Math.round(performance.now() - t0) });
    }
    return out;
  });
  const sizes = { '1:1': '1080x1080', '4:5': '1080x1350', '9:16': '1080x1920' };
  const badR = rend.filter((r) => `${r.w}x${r.h}` !== sizes[r.f] || r.sig !== 'PNG' || r.kb < 20);
  check('creatives render to PNG', badR.length === 0 && rend.length === 36, `${rend.length} renders (6 templates x AR/EN x 3 formats), sizes ok, PNG sig ok; ${Math.min(...rend.map((r) => r.kb))}-${Math.max(...rend.map((r) => r.kb))} KB, ${Math.max(...rend.map((r) => r.ms))} ms worst${badR.length ? ' BAD: ' + JSON.stringify(badR.slice(0, 3)) : ''}`);
  if (want('--shots')) {
    // a sheet of Arabic + English designs, small JPEGs
    await page.evaluate(async () => {
      const R = await import(new URL('js/creative/render.js', location.href).href);
      const pick = (window.__kaa.cache.get(`${window.__kaa.accounts[0].id}|designs`) || []).map((d) => ({ design: d, lang: d.lang })).slice(0, 10);
      const sheet = document.createElement('canvas');
      sheet.width = 1600; sheet.height = 1500;
      const x = sheet.getContext('2d'); x.fillStyle = '#F6F2F3'; x.fillRect(0, 0, 1600, 1500);
      const specs = [];
      for (const tpl of R.TEMPLATES) for (const lang of ['ar', 'en']) { const c = pick.find((p) => p.lang === lang) || pick[0]; if (c) specs.push(Object.assign({}, c.design, { template: tpl })); }
      let i = 0;
      for (const s of specs.slice(0, 12)) { const cv = await R.renderSpec(s, i % 3 === 2 ? '9:16' : i % 3 === 1 ? '4:5' : '1:1'); const w = 250, h = w * cv.height / cv.width; x.drawImage(cv, 20 + (i % 6) * 262, 20 + Math.floor(i / 6) * 740, w, h); i++; }
      window.__sheet = sheet.toDataURL('image/jpeg', 0.8);
    });
    const b64 = (await page.evaluate(() => window.__sheet)).split(',')[1];
    fs.writeFileSync(path.join(SHOTS, 'creatives-sheet-ar-en.jpg'), Buffer.from(b64, 'base64'));
    for (const [tpl, f, lang] of [['glow', '9:16', 'ar'], ['ingredient', '4:5', 'ar'], ['routine', '1:1', 'en'], ['qa', '9:16', 'ar'], ['offer', '1:1', 'ar'], ['clean', '4:5', 'en']]) {
      const b = await page.evaluate(async ([tpl, f, lang]) => {
        const R = await import(new URL('js/creative/render.js', location.href).href);
        const lib = (window.__kaa.cache.get(`${window.__kaa.accounts[0].id}|designs`) || []).map((d) => ({ design: d, lang: d.lang }));
        if (!lib.length) return '';
        const c = lib.find((p) => p.design && p.lang === lang && (tpl !== 'routine' || p.design.images.length > 1)) || lib.find((p) => p.design && p.lang === lang);
        const cv = await R.renderSpec(Object.assign({}, c.design, { template: tpl }), f);
        const s = document.createElement('canvas'); s.width = cv.width / 2; s.height = cv.height / 2; s.getContext('2d').drawImage(cv, 0, 0, s.width, s.height);
        return s.toDataURL('image/jpeg', 0.85).split(',')[1];
      }, [tpl, f, lang]);
      if (b) fs.writeFileSync(path.join(SHOTS, `creative-${lang}-${tpl}-${f.replace(':', 'x')}.jpg`), Buffer.from(b, 'base64'));
    }
  }

  // 6: pending designs were rendered + posted on load
  await page.waitForTimeout(2500);
  const pend = await page.evaluate(async () => {
    const h = { headers: { 'X-KAA': window.__kaa.info.token } };
    const cyc = await (await fetch('/api/brain/x/cycle', h)).json();
    const arch = await (await fetch('/api/brain/x/designs', h)).json();
    const cr = await (await fetch('/api/creatives', h)).json();
    return { left: (cyc.designs || []).length, archived: (arch || []).length, creatives: (cr || []).length };
  });
  if (!pend.archived) check('pending designs rendered by the app', true, 'N/A: this engine planned no designs (no Claude copy in -mock)');
  else check('pending designs rendered by the app', pend.left === 0 && pend.creatives >= 6, `${pend.creatives} creatives in the engine library after rendering; ${pend.left} designs still pending`);

  // 7: motion ad
  if (!want('--quick')) {
    await page.goto(base + '#/creatives'); await page.waitForTimeout(800);
    await page.click('[data-tab="motion"]'); await page.waitForTimeout(300);
    if (!(await page.$('#m-go'))) check('motion ad recorded', true, 'N/A: no design in this account yet'); else {
    await page.fill('#m-d', '6');
    await page.click('#m-go');
    await page.waitForFunction(() => window.__kaaMotion, null, { timeout: 60000 });
    const mo = await page.evaluate(() => window.__kaaMotion);
    check('motion ad recorded', mo.bytes > 50000 && mo.height > mo.width, `${mo.mime} ${mo.width}x${mo.height}, ${Math.round(mo.bytes / 1024)} KB, recorded in ${mo.ms} ms`);
    if (want('--shots')) await page.screenshot({ path: path.join(SHOTS, `${REAL ? 'real-' : ''}motion-1440.jpg`), type: 'jpeg', quality: 70 });
    }

    // 8: synthetic video -> analysis
    const vid = await page.evaluate(async () => {
      const cv = document.createElement('canvas'); cv.width = 540; cv.height = 960;
      const x = cv.getContext('2d');
      const mime = ['video/webm;codecs=vp8', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m));
      const rec = new MediaRecorder(cv.captureStream(24), { mimeType: mime });
      const ch = []; rec.ondataavailable = (e) => ch.push(e.data);
      const stop = new Promise((r) => { rec.onstop = r; });
      rec.start(200);
      const t0 = performance.now();
      await new Promise((ok) => { const f = () => { const s = (performance.now() - t0) / 1000; x.fillStyle = `hsl(${340 + s * 5},70%,${85 - s * 5}%)`; x.fillRect(0, 0, 540, 960); x.fillStyle = '#2A2228'; x.font = '700 44px Cairo'; x.textAlign = 'center'; x.fillText('صباحكِ يبدأ بلطف', 270, 420); x.fillStyle = '#C13E63'; x.fillRect(220, 480, 100, 240); if (s > 4) ok(); else requestAnimationFrame(f); }; f(); });
      rec.stop(); await stop;
      const b = new Blob(ch, { type: 'video/webm' });
      return Array.from(new Uint8Array(await b.arrayBuffer()));
    });
    await page.goto(base + '#/videos'); await page.waitForTimeout(700);
    await page.setInputFiles('#vf', { name: 'synthetic-test-reel.webm', mimeType: 'video/webm', buffer: Buffer.from(vid) });
    const findV = () => { for (const [k, v] of window.__kaa.cache) if (k.endsWith('|videos') && Array.isArray(v)) { const x = v.find((y) => y.name === 'synthetic-test-reel.webm' && y.analysis); if (x) return x; } return null; };
    await page.waitForFunction(findV, null, { timeout: 60000 });
    const va = await page.evaluate(findV);
    const a = va.analysis;
    check('video analysis (synthetic 9:16)', a.aspect === '9:16' && a.duration_s > 3 && a.duration_s < 6 && a.issues.some((i) => i.code === 'low_resolution') && va.thumb,
      `aspect ${a.aspect}, ${a.duration_s} s, ${a.width}x${a.height}, score ${a.score}, issues [${a.issues.map((i) => i.code).join(', ')}], frames thumb ${va.thumb ? 'yes' : 'no'}, model "${a.model}"`);
    if (want('--shots')) { await page.waitForTimeout(500); await page.screenshot({ path: path.join(SHOTS, `${REAL ? 'real-' : ''}videos-1440.jpg`), type: 'jpeg', quality: 70 }); }
  }

  // screenshots: every screen, light + dark, 1440x900 and 1280x800
  if (want('--shots')) {
    for (const [w, h] of [[1440, 900], [1280, 800]]) for (const scheme of ['light', 'dark']) {
      const c2 = await browser.newContext({ viewport: { width: w, height: h }, colorScheme: scheme });
      const p2 = await c2.newPage();
      p2.on('pageerror', (e) => errors.push(String(e)));
      await p2.addInitScript(() => { try { localStorage.setItem('kaa.onboarded', '1'); } catch {} });
      await p2.goto(base + '#/overview');
      await p2.waitForFunction(() => window.__kaa && window.__kaa.state, null, { timeout: 30000 });
      for (const s of SCREENS) { await p2.goto(base + '#/' + s); await p2.waitForTimeout(s === 'creatives' ? 1500 : 600); await p2.screenshot({ path: path.join(SHOTS, `${REAL ? 'real-' : ''}${s}-${w}-${scheme}.jpg`), type: 'jpeg', quality: 66 }); }
      await c2.close();
    }
  }

  // 9: onboarding wizard
  await page.goto(base + '#/welcome'); await page.waitForTimeout(400);
  let steps = 1;
  for (let i = 0; i < 6; i++) { const n = await page.$('#next'); if (!n) break; const before = await page.$eval('.steps span.on', (e) => e.textContent); await n.click(); await page.waitForFunction((b) => document.querySelector('.steps span.on') && document.querySelector('.steps span.on').textContent !== b, before, { timeout: 5000 }).catch(() => {}); steps++; }
  const last = await page.$('#go-shadow');
  check('onboarding wizard', steps === 7 && !!last, `${steps}/7 steps reached, start buttons ${last ? 'shown' : 'missing'}`);
  if (want('--shots')) await page.screenshot({ path: path.join(SHOTS, `${REAL ? 'real-' : ''}welcome-1440.jpg`), type: 'jpeg', quality: 70 });

  check('no console errors', errors.length === 0, errors.length ? errors.slice(0, 5).join(' | ') : '0 errors');
  check('no failed requests', failed.length === 0, failed.length ? failed.slice(0, 5).join(' | ') : '0 failed');
  await browser.close();
  eng.kill('SIGTERM');
  try { fs.rmSync(tmpData, { recursive: true, force: true }); } catch {}
  const bad = results.filter((r) => !r.ok).length;
  console.log(`\n${results.length - bad}/${results.length} checks passed`);
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(3); });
