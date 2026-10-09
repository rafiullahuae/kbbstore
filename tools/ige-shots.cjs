// Lane IGE screenshots and measurements:  node tools/ige-shots.cjs <port> <outdir>
//
// instagram.com is NOT reachable from the sandbox this was built in (the egress
// proxy refuses the CONNECT). So every request to www.instagram.com is
// intercepted: "facade" shots hold it open (the frame stays blank and the
// facade shows through), "loaded" shots answer it with a STAND-IN page shaped
// like Instagram's embed and labelled as one. The request itself is still
// counted, which is what the speed numbers need.
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const port = process.argv[2] || 10970, out = process.argv[3] || '.';
const BASE = 'http://127.0.0.1:' + port;
const fs = require('fs');

function stub(url) {
  const reel = /\/reel\//.test(url), cap = /captioned/.test(url);
  const code = (url.match(/\/(?:p|reel)\/([^/]+)/) || [])[1] || '';
  return `<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width"><body style="margin:0;font:14px system-ui;background:#fff;color:#262626">
<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #efefef"><span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(45deg,#feda75,#d62976,#4f5bd5)"></span><b>kbeauty.bliss</b><span style="margin-left:auto;background:#0095f6;color:#fff;padding:5px 10px;border-radius:8px;font-size:12px">View profile</span></div>
<div style="aspect-ratio:${reel ? '9/16' : '4/5'};background:linear-gradient(160deg,#fde2ea,#e9defc);display:grid;place-items:center;text-align:center;color:#7a5b6b;padding:10px">STAND-IN for Instagram's ${reel ? 'reel' : 'post'} embed<br>${code}<br><small>(instagram.com is blocked in this sandbox)</small></div>
<div style="padding:10px 14px;color:#0095f6;font-weight:600;border-bottom:1px solid #efefef">View more on Instagram</div>
<div style="padding:10px 14px">♡ &nbsp; 💬 &nbsp; ➤</div><div style="padding:0 14px 6px;font-weight:600">1,234 likes</div>
${cap ? '<div style="padding:4px 14px 8px"><b>kbeauty.bliss</b> Our favourite glass-skin routine, step by step.</div><div style="padding:0 14px 8px;color:#8e8e8e">View all 48 comments</div>' : ''}
<div style="padding:10px 14px;border-top:1px solid #efefef;color:#8e8e8e">Add a comment…</div></body>`;
}

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function setOptions(page, options, items) {
  return page.evaluate(async ([options, items]) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
    const root = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
    const body = { options };
    if (items) body.items = items;
    const r = await fetch(root + '/admin-api/ig-embeds', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(m.pop()) }, body: JSON.stringify(body) });
    return r.status;
  }, [options, items || null]);
}

async function measurePage(b, url, w, mode) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const ig = [], all = [], errors = [];
  page.on('request', r => { all.push(r.url()); if (r.url().includes('instagram.com')) ig.push(r.url()); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  await page.route('https://www.instagram.com/**', r => mode === 'hold' ? null : r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
  await page.addInitScript(() => {
    window.__cls = 0; window.__lcp = 0;
    new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver(l => { for (const e of l.getEntries()) window.__lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
  });
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForTimeout(1500);
  const atLoad = { requests: all.length, instagram: ig.length };
  const m = await page.evaluate(() => {
    const s = document.querySelector('.kie');
    const r = s && s.getBoundingClientRect();
    return { lcp: Math.round(window.__lcp), cls: +window.__cls.toFixed(4), scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
      sectionTop: r ? Math.round(r.top + scrollY) : null, docH: document.documentElement.scrollHeight,
      spec: document.querySelectorAll('script[type=speculationrules]').length,
      boxes: s ? [...s.querySelectorAll('.kie-box')].slice(0, 2).map(b => Math.round(b.getBoundingClientRect().height)) : [] };
  });
  let afterScroll = null;
  if (m.sectionTop !== null) {
    const before = await page.evaluate(() => [...document.querySelectorAll('.kie-box')].map(b => Math.round(b.getBoundingClientRect().height)));
    await page.evaluate(y => window.scrollTo(0, y - 80), m.sectionTop);
    await page.waitForTimeout(2000);
    const after = await page.evaluate(() => [...document.querySelectorAll('.kie-box')].map(b => Math.round(b.getBoundingClientRect().height)));
    afterScroll = { instagram: ig.length, cls: await page.evaluate(() => +window.__cls.toFixed(4)), boxesSame: JSON.stringify(before) === JSON.stringify(after) };
  }
  await ctx.close();
  return { url: url.replace(BASE, ''), w, atLoad, ...m, afterScroll, errors };
}

async function lcpRuns(b, url, w, n) {
  const out = [];
  for (let i = 0; i < n; i++) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 } });
    const page = await ctx.newPage();
    await page.route('https://www.instagram.com/**', r => r.fulfill({ status: 200, contentType: 'text/html', body: '<body></body>' }));
    await page.addInitScript(() => { window.__l = null; new PerformanceObserver(l => { for (const e of l.getEntries()) window.__l = e; }).observe({ type: 'largest-contentful-paint', buffered: true }); });
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(800);
    out.push(await page.evaluate(() => ({ t: Math.round(window.__l.startTime), el: window.__l.element ? window.__l.element.tagName + '.' + String(window.__l.element.className).split(' ')[0] : null })));
    await ctx.close();
  }
  const ts = out.map(o => o.t).sort((a, b) => a - b);
  return { median: ts[Math.floor(ts.length / 2)], els: [...new Set(out.map(o => o.el))] };
}

async function clickCheck(b, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 } });
  const page = await ctx.newPage();
  await page.route('https://www.instagram.com/**', r => r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
  await page.goto(BASE + '/', { waitUntil: 'load' });
  await page.evaluate(() => document.querySelector('.kie-more').scrollIntoView({ block: 'center', behavior: 'instant' }));
  await page.waitForTimeout(1200);
  const hit = await page.evaluate(() => [...document.querySelectorAll('.kie-more')].slice(0, 3).map(a => {
    a.scrollIntoView({ block: 'center', behavior: 'instant' });
    const r = a.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return el === a || a.contains(el);
  }));
  const [popup] = await Promise.all([page.waitForEvent('popup', { timeout: 5000 }).catch(() => null), page.click('.kie-more')]);
  const opened = popup ? popup.url() : null;
  // A product link in the next section still answers a first click.
  const prod = await page.evaluate(() => { const s = document.querySelector('.kie'); let a = null; for (const x of document.querySelectorAll('a[href*="/product/"]')) { if (s && (s.compareDocumentPosition(x) & Node.DOCUMENT_POSITION_FOLLOWING)) { a = x; break; } } if (!a) return null; a.scrollIntoView({ block: 'center', behavior: 'instant' }); const r = a.getBoundingClientRect(); const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { href: a.getAttribute('href'), hit: !!(el && el.closest('a[href*="/product/"]')) }; });
  let nav = null;
  if (prod) { await Promise.all([page.waitForNavigation({ timeout: 8000 }).catch(() => null), page.click(`a[href="${prod.href}"] >> nth=0`)]); nav = page.url(); }
  await ctx.close();
  return { w, viewOnInstagramHit: hit, popup: opened, productAfterSection: prod, navigatedTo: nav && nav.replace(BASE, '') };
}

async function sectionShot(b, w, name, mode, url) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
  const page = await ctx.newPage();
  await page.route('https://www.instagram.com/**', r => mode === 'hold' ? null : r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
  await page.goto(BASE + (url || '/'), { waitUntil: 'load' });
  // Scroll the section into view so the lazy frames load, then photograph the
  // section itself: the heading and the first row at 1280, the first two cards
  // at 390.
  await page.evaluate(() => { const s = document.querySelector('.kie'); window.scrollTo({ top: s.getBoundingClientRect().top + scrollY - 120, behavior: 'instant' }); });
  await page.waitForTimeout(mode === 'hold' ? 600 : 2200);
  const box = await page.evaluate(() => {
    const s = document.querySelector('.kie'), host = s.closest('section') || s;
    const cards = [...s.querySelectorAll('.kie-card')];
    const top = host.getBoundingClientRect().top + scrollY;
    const last = cards[Math.min(cards.length, innerWidth > 900 ? 3 : 2) - 1] || s;
    const bottom = Math.max(last.getBoundingClientRect().bottom, s.querySelector('.kie-list').getBoundingClientRect().top + 10) + scrollY + 24;
    return { x: 0, y: Math.max(0, top), width: innerWidth, height: Math.min(bottom - top, 4000) };
  });
  await page.screenshot({ path: `${out}/${name}-${w}.png`, fullPage: true, clip: box });
  await ctx.close();
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = { measures: [], clicks: [] };

  // ── admin screen ──
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', e => errs.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
    await page.route('https://www.instagram.com/**', r => r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
    await login(page);
    await page.goto(`${BASE}/admin/?go=igembeds`, { waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForSelector('[data-ige-stage]', { timeout: 20000 });
    const nav = await page.evaluate(() => { const r = [...document.querySelectorAll('.side .nav-item')].filter(x => x.dataset.go === 'igembeds'); return { rows: r.length, label: r[0] && r[0].textContent.trim(), group: r[0] && (r[0].closest('.nav-group') || {}).dataset && r[0].closest('.nav-group').dataset.sec }; });
    report['admin' + w] = { nav, title: await page.textContent('#ptitle'), crumb: await page.textContent('#crumb'), scrollW: await page.evaluate(() => document.documentElement.scrollWidth) };
    // Paste with a refused line, to show the refusal.
    await page.fill('[data-ige-paste]', 'https://www.instagram.com/p/DAbcPost005/?igsh=x\njavascript:alert(1)\nhttps://www.instagram.com/share/reel/BAabcdef12/');
    await page.click('[data-ige-add]');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${out}/admin-${w}.png`, fullPage: w > 500 });
    await page.evaluate(() => document.querySelector('[data-ige-opt="style"], .ige-styles').scrollIntoView({ block: 'start', behavior: 'instant' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/admin-styles-${w}.png` });
    await page.evaluate(() => document.querySelector('[data-ige-save]').scrollIntoView({ block: 'end', behavior: 'instant' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/admin-options-${w}.png` });
    if (w < 500) {
      await page.evaluate(() => document.querySelector('.ige-prev').scrollIntoView({ block: 'start' }));
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${out}/admin-preview-${w}.png` });
    } else {
      await page.click('[data-ige-live]');
      await page.waitForTimeout(1500);
      await page.evaluate(() => document.querySelector('.ige-prev').scrollIntoView({ block: 'start' }));
      await page.screenshot({ path: `${out}/admin-preview-live-${w}.png` });
      await page.click('[data-ige-dev="d"]');
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${out}/admin-preview-laptop-live-${w}.png` });
    }
    report['admin' + w].errors = errs;
    await ctx.close();
  }

  // ── a logged-in page for switching options ──
  const actx = await b.newContext();
  const apage = await actx.newPage();
  await login(apage);
  await apage.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });

  // ── measurements: home with the section, near mode ──
  for (const w of [390, 1280]) {
    report.measures.push({ label: 'home+section near', ...(await measurePage(b, BASE + '/', w, 'fulfil')) });
  }
  await setOptions(apage, { load: 'tap' });
  for (const w of [390, 1280]) report.measures.push({ label: 'home+section tap', ...(await measurePage(b, BASE + '/', w, 'fulfil')) });
  await setOptions(apage, { load: 'near' });
  for (const w of [390, 1280]) report.measures.push({ label: 'delivery page shortcode (near, near top)', ...(await measurePage(b, BASE + '/delivery/', w, 'fulfil')) });
  for (const w of [390, 1280]) report.clicks.push(await clickCheck(b, w));
  report.lcpWith = {}; for (const w of [390, 1280]) report.lcpWith[w] = await lcpRuns(b, BASE + '/', w, 7);

  // ── shots: each card style, facade and loaded ──
  for (const style of ['clean', 'soft', 'polaroid', 'ring']) {
    await setOptions(apage, { style });
    for (const w of [1280, 390]) await sectionShot(b, w, `home-style-${style}-loaded`, 'fulfil');
  }
  await setOptions(apage, { style: 'clean' });
  for (const w of [1280, 390]) await sectionShot(b, w, 'home-facade', 'hold');
  await setOptions(apage, { load: 'tap' });
  for (const w of [1280, 390]) await sectionShot(b, w, 'home-tap-facade', 'hold');
  await setOptions(apage, { load: 'near', caption: true });
  for (const w of [1280, 390]) await sectionShot(b, w, 'home-captioned-loaded', 'fulfil');
  await setOptions(apage, { caption: false });
  for (const w of [1280, 390]) await sectionShot(b, w, 'page-shortcode-slider-loaded', 'fulfil', '/delivery/');

  // ── the same pages with the list emptied: the "without" numbers ──
  await setOptions(apage, {}, []);
  for (const w of [390, 1280]) report.measures.push({ label: 'home without section', ...(await measurePage(b, BASE + '/', w, 'fulfil')) });
  report.lcpWithout = {}; for (const w of [390, 1280]) report.lcpWithout[w] = await lcpRuns(b, BASE + '/', w, 7);

  await actx.close();
  await b.close();
  fs.writeFileSync(`${out}/report.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
