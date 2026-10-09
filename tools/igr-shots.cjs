// Lane IGR screenshots and measurements:  node tools/igr-shots.cjs <port> <outdir> <before|after>
//
// instagram.com is not reachable from the sandbox, so every frame request to it
// is answered with a STAND-IN page labelled as one (tools/ige-shots.cjs carries
// the same stub and the reason). The requests are still counted.
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const port = process.argv[2], out = process.argv[3] || '.', phase = process.argv[4] || 'before';
const BASE = 'http://127.0.0.1:' + port;

function stub(url) {
  const reel = /\/reel\//.test(url);
  const code = (url.match(/\/(?:p|reel)\/([^/]+)/) || [])[1] || '';
  return `<!doctype html><meta charset=utf-8><body style="margin:0;font:14px system-ui;background:#fff;color:#262626">
<div style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #efefef"><span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(45deg,#feda75,#d62976,#4f5bd5)"></span><b>kbeauty.bliss</b></div>
<div style="aspect-ratio:${reel ? '9/16' : '4/5'};background:linear-gradient(160deg,#fde2ea,#e9defc);display:grid;place-items:center;text-align:center;color:#7a5b6b;padding:10px">STAND-IN for Instagram's ${reel ? 'reel' : 'post'} embed<br>${code}<br><small>(instagram.com is blocked in this sandbox)</small></div>
<div style="padding:10px 14px;color:#0095f6;font-weight:600">View more on Instagram</div></body>`;
}

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function shop(b, w, url, shot) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const ig = [], all = [], errors = [], scripts = [];
  page.on('request', r => { all.push(r.url()); if (r.url().includes('instagram.com')) ig.push(r.url()); if (r.resourceType() === 'script') scripts.push(r.url().replace(BASE, '')); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  await page.route('https://www.instagram.com/**', r => r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
  await page.addInitScript(() => {
    window.__cls = 0;
    new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  const res = await page.goto(BASE + url, { waitUntil: 'load' });
  await page.waitForTimeout(1200);
  const atLoad = { requests: all.length, instagram: ig.length };
  const m = await page.evaluate(() => ({
    scrollW: document.documentElement.scrollWidth, innerW: innerWidth, docH: document.documentElement.scrollHeight,
    kie: document.querySelectorAll('.kie').length, kieCards: document.querySelectorAll('.kie-card').length,
    sigCards: document.querySelectorAll('.sig-card').length, sptCards: document.querySelectorAll('.spt-cell').length,
    igp: document.querySelectorAll('.igp').length, inlineScripts: document.querySelectorAll('script:not([src])').length,
    h1: (document.querySelector('h1') || {}).textContent, robots: (document.querySelector('meta[name=robots]') || {}).content || null,
  }));
  // Scroll the whole page so every lazy frame that will load does, then read CLS.
  for (let y = 0; y < m.docH; y += 600) { await page.evaluate(v => window.scrollTo(0, v), y); await page.waitForTimeout(120); }
  await page.waitForTimeout(1200);
  const cls = await page.evaluate(() => +window.__cls.toFixed(4));
  if (shot) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${out}/${shot}-${phase}-${w}.png`, fullPage: true });
  }
  await ctx.close();
  return { url, w, status: res.status(), atLoad, afterScroll: { requests: all.length, instagram: ig.length }, cls, scripts: [...new Set(scripts)].length, ...m, errors };
}

// The homepage band where Instagram is drawn (the old .igp and/or IGE's .kie).
async function homeBand(b, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.route('https://www.instagram.com/**', r => r.fulfill({ status: 200, contentType: 'text/html', body: stub(r.request().url()) }));
  await page.goto(BASE + '/', { waitUntil: 'load' });
  const top = await page.evaluate(() => { const s = document.querySelector('.igp, .kie'); return s ? s.closest('section').getBoundingClientRect().top + scrollY : null; });
  if (top !== null) {
    await page.evaluate(y => window.scrollTo(0, y - 60), top);
    await page.waitForTimeout(2000);
    await page.screenshot({ path: `${out}/home-instagram-${phase}-${w}.png` });
  }
  const order = await page.evaluate(() => [...document.querySelectorAll('main section, .kbb-home > section')].map(s => s.className.split(' ').slice(0, 3).join(' ')).filter(c => /igp|kie|ig|spt/.test(c) || true).slice(0, 40));
  await ctx.close();
  return order;
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = { phase, shop: [] };

  for (const w of [390, 1280]) {
    report.shop.push(await shop(b, w, '/kbeautybliss-spotted/', 'spotted'));
    report.shop.push(await shop(b, w, '/', null));
    report.shop.push(await shop(b, w, '/delivery/', null));
    await homeBand(b, w);
  }

  // ── admin: the sidebar, and the Spotted page tab ──
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', e => errs.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
    await login(page);
    await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });
    if (w < 500) { await page.click('.menubtn'); await page.waitForTimeout(400); }
    await page.evaluate(() => { const g = document.querySelector('#side .nav-group[data-sec="Content"]'); if (g && !g.classList.contains('open')) g.querySelector('.nav-gh').click(); });
    await page.waitForTimeout(400);
    const nav = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('#side .nav-item')];
      const content = rows.filter(r => { const g = r.closest('[data-sec]'); return g && g.dataset.sec === 'Content'; });
      const inst = rows.filter(r => /instagram/i.test(r.textContent)).map(r => r.dataset.go + ':' + r.textContent.trim());
      const first = content[0] || rows.find(r => r.dataset.go === 'posts');
      if (first) first.scrollIntoView({ block: 'center', behavior: 'instant' });
      return { instagramRows: inst, contentRows: content.map(r => r.dataset.go) };
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/admin-sidebar-${phase}-${w}.png` });

    await page.goto(`${BASE}/admin/?go=spotted`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const tab = await page.$('[data-spt-tab="page"], [data-tab="page"], button:has-text("Spotted page")');
    if (tab) { await tab.click(); await page.waitForTimeout(600); }
    const ctl = await page.$('[name="page_igembeds"], [data-key="page_igembeds"]');
    if (ctl) await ctl.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${out}/admin-spotted-${phase}-${w}.png` });
    const conn = await page.goto(`${BASE}/admin/?go=instagram`, { waitUntil: 'networkidle' }).then(() => page.textContent('#ptitle')).catch(() => null);
    report['admin' + w] = { nav, goInstagramTitle: conn, scrollW: await page.evaluate(() => document.documentElement.scrollWidth), errors: errs };
    await ctx.close();
  }

  await b.close();
  fs.writeFileSync(`${out}/igr-${phase}.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
