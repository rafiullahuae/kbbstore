const { chromium } = require('playwright');
const BASE = process.env.PK_BASE;
const OUT = process.env.OUT;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const p = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage();
    await p.goto(BASE + '/admin/login'); await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await p.goto(BASE + '/admin', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    await p.evaluate(() => window.go('catalog')); await p.waitForTimeout(1800);
    const m = await p.evaluate(() => {
      const t = document.querySelector('#catBody table');
      const sc = t.parentElement;
      const rows = [...t.querySelectorAll('tbody tr')].slice(0, 6).map((tr) => tr.offsetHeight);
      const ths = [...t.querySelectorAll('thead th')].map((th) => th.offsetWidth);
      return { tableW: t.offsetWidth, scrollerW: sc.clientWidth, scrollerScrollW: sc.scrollWidth, rowHeights: rows, thWidths: ths, docScrollW: document.documentElement.scrollWidth };
    });
    console.log(w, JSON.stringify(m));
    // the row-action column, scrolled into view
    const last = p.locator('#catBody tbody tr').nth(2).locator('td').last();
    await last.scrollIntoViewIfNeeded();
    await p.waitForTimeout(200);
    if (OUT) await p.locator('#catBody table').screenshot({ path: `${OUT}-${w}.png` });
  }
  await b.close();
})();
