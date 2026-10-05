// Lane FW: Pages -> Page header (console) with the desktop picture at Full width:
// the stage preview must not scroll the console sideways. node tools/fw-console-probe.cjs <port>
const { chromium } = require('playwright');
const BASE = `http://127.0.0.1:${process.argv[2]}`;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', ignoreDefaultArgs: ['--hide-scrollbars'] });
  const c = await b.newContext({ viewport: { width: 1280, height: 900 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36' });
  const p = await c.newPage();
  p.on('pageerror', (e) => console.log('pageerror', String(e)));
  await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test');
  await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
  await p.evaluate(() => window.go('pageheader'));
  await p.waitForSelector('.phs-stage', { timeout: 20000 });
  await p.waitForSelector('.kbb-phe-seg[aria-label="Picture width"]', { timeout: 20000 });
  // /super-sale/'s own look, which carries the seeded picture.
  await p.click('.phs-btn:has-text("Super Sale")');
  await p.waitForSelector('.phs-stage [data-kbb-ph] picture img', { timeout: 20000 });
  const m = () => p.evaluate(() => { const st = document.querySelector('.phs-stage').getBoundingClientRect(); const i = document.querySelector('.phs-stage [data-kbb-ph] picture img'); const r = i && i.getBoundingClientRect(); const vis = i && { l: Math.max(r.left, st.left), r: Math.min(r.right, st.right) }; return { client: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth, stage: [Math.round(st.left), Math.round(st.right)], picVisible: vis && [Math.round(vis.l), Math.round(vis.r)], wrap: (document.querySelector('.phs-stage [data-kbb-ph]') || {}).className }; });
  console.log('console normal', JSON.stringify(await m()));
  await p.click('.kbb-phe-seg[aria-label=Device] button:has-text("Desktop")').catch(() => {});
  await p.click('.kbb-phe-seg[aria-label="Picture width"] button:has-text("Full width")');
  await p.waitForTimeout(250);
  console.log('console full', JSON.stringify(await m()));
  await p.locator('.phs-stage').scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${__dirname}/../docs/fw-shots/console-full-1280.png` });
  await b.close();
})();
