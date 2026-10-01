const { chromium } = require('playwright');
const BASE = process.env.PK_BASE;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await (await b.newContext({ viewport: { width: Number(process.env.W || 390), height: 844 } })).newPage();
  await p.goto(BASE + '/admin/login'); await p.fill('input[name=email]', 'owner@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(BASE + '/admin', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
  for (const id of (process.env.IDS || '25,28,29').split(',')) {
    await p.evaluate((n) => window.peoEdit(+n), id); await p.waitForTimeout(1300);
    const m = await p.evaluate(() => {
      const bar = document.querySelector('#content .peo-bar');
      return { h: bar.offsetHeight, inner: bar.clientWidth, items: [...bar.children].map((c) => [c.id || c.className, c.offsetWidth, c.offsetTop]) };
    });
    console.log(id, JSON.stringify(m));
  }
  await b.close();
})();
