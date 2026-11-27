/* Lane TDS: the Time deal strip on the shop (390 / 1280, English and Arabic) and Appearance -> Time deal strip.
   BASE=http://127.0.0.1:<port> node tools/tds-shots.cjs   (against sh tools/fg-preview.sh) */
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-tds'); fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const b = await chromium.launch(); const rep = [];
  for (const [w, pth] of [[390, '/'], [1280, '/'], [390, '/ar/'], [1280, '/ar/'], [320, '/']]) {
    const m = w < 500;
    const ctx = await b.newContext({ viewport: { width: w, height: m ? 700 : 600 }, deviceScaleFactor: 2, isMobile: m, hasTouch: m });
    const p = await ctx.newPage(); const errs = []; p.on('pageerror', (e) => errs.push(e.message));
    await p.goto(BASE + pth, { waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
    const r = await p.evaluate(() => { const s = document.getElementById('kbb-tds'), B = s.querySelector('.kds-b').getBoundingClientRect(), h = document.elementFromPoint(B.left + B.width / 2, B.top + B.height / 2);
      return { h: Math.round(s.getBoundingClientRect().height), sw: document.documentElement.scrollWidth, buttonClickable: h === s.querySelector('.kds-b') || s.querySelector('.kds-b').contains(h), clock: [...s.querySelectorAll('[data-u]')].map((x) => x.textContent).join(':'), headerTop: Math.round(document.querySelector('header').getBoundingClientRect().top) }; });
    await p.screenshot({ path: `${OUT}/shop-${pth === '/' ? 'en' : 'ar'}-${w}.png`, clip: { x: 0, y: 0, width: w, height: m ? 260 : 220 } });
    rep.push({ w, pth, ...r, errs }); await ctx.close();
  }
  const ctx = await b.newContext({ viewport: { width: 1440, height: 1000 } }); const p = await ctx.newPage();
  await p.goto(BASE + '/admin/login'); await p.fill('#email', 'owner@example.com'); await p.fill('#password', 'preview-password');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.evaluate(() => window.go('timedeal')); await p.waitForSelector('[data-tds-pv] .kds', { timeout: 15000 }); await p.waitForTimeout(1500);
  await p.screenshot({ path: `${OUT}/admin-1440.png`, fullPage: true });
  rep.push({ admin: await p.evaluate(() => ({ nav: !!document.querySelector('[data-go="timedeal"]'), title: document.querySelector('#ptitle')?.textContent })) });
  await b.close(); fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(rep, null, 1)); console.log(JSON.stringify(rep));
})().catch((e) => { console.error(e); process.exit(1); });
