// Admin screen shots for Lane WA: node admin.cjs <port> <outdir>
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const port = process.argv[2] || 9950, out = process.argv[3];
const BASE = 'http://127.0.0.1:' + port;
(async () => {
  const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  for (const w of (process.env.WIDTHS || '1280,390').split(',').map(Number)) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', String(e)));
    page.on('console', m => { if (m.type() === 'error') console.log('console', m.text()); });
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin/?go=wabutton`, { waitUntil: 'networkidle' }).catch(()=>{});
    await page.waitForSelector('[data-wab-tab]', { timeout: 20000 });
    const navRow = await page.evaluate(() => { const r = [...document.querySelectorAll('.side .nav-item')].find(x => x.dataset.go === 'wabutton'); return r ? r.textContent.trim() : null; });
    console.log('nav row', navRow, 'title', await page.textContent('#ptitle'), 'crumb', await page.textContent('#crumb'));
    for (const tab of ['design', 'position', 'message', 'capsule']) {
      await page.click(`[data-wab-tab="${tab}"]`);
      if (process.env.DEV === 'd' || (tab === 'position' && w > 500)) await page.click('button[data-wab-dev="d"]');
      await page.waitForTimeout(2300);
      const m = await page.evaluate(() => ({ scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
        stage: (s => s && Math.round(s.getBoundingClientRect().width))(document.querySelector('[data-wab-stage]')),
        btn: (s => s && (r => ({w: Math.round(r.width), right: Math.round(s.closest('[data-wab-stage]').getBoundingClientRect().right - r.right), bottom: Math.round(s.closest('[data-wab-stage]').getBoundingClientRect().bottom - r.bottom)}))(s.getBoundingClientRect()))(document.querySelector('[data-wab-btn]')) }));
      console.log(w, tab, JSON.stringify(m));
      if (w < 500) { await page.evaluate(() => { document.querySelector('.wab-tabs').scrollIntoView({ block: 'start' }); const m = document.querySelector('#content'); window.scrollBy(0, -130); (document.scrollingElement || document.documentElement).scrollTop -= 0; }); await page.waitForTimeout(300); }
      await page.screenshot({ path: `${out}/admin-${tab}-${w}.png`, fullPage: w > 500 });
      if (w < 500 && tab === 'design') { await page.evaluate(() => document.querySelector('[data-wab-preview]').scrollIntoView({ block: 'start' })); await page.waitForTimeout(400); await page.screenshot({ path: `${out}/admin-preview-${w}.png` }); }
    }
    if (w > 500) {
      // Before and after: drag the size bar to 36, switch the preview to Arabic.
      await page.click('[data-wab-tab="design"]');
      await page.click('button[data-wab-dev="m"]');
      await page.$eval('#wab-size', el => { el.value = '36'; el.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.waitForTimeout(600);
      const k = await page.evaluate(() => ({ k: document.querySelector('[data-wab-btn]').style.getPropertyValue('--k'), w: Math.round(document.querySelector('[data-wab-btn] .kbw-a').getBoundingClientRect().width), label: document.querySelector('[data-wab-val="size"]').textContent }));
      console.log('size drag', JSON.stringify(k));
      await page.screenshot({ path: `${out}/admin-size36-${w}.png` });
      await page.$eval('#wab-size', el => { el.value = '60'; el.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.click('button[data-wab-lang="ar"]');
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${out}/admin-preview-arabic-${w}.png` });
      await page.click('[data-wab-tab="design"]');
      await page.selectOption('#wab-design', 'E');
      await page.click('button[data-wab-lang="en"]');
      await page.waitForTimeout(1200);
      await page.screenshot({ path: `${out}/admin-design-E-${w}.png` });
    }
    await ctx.close();
  }
  await b.close();
})();
