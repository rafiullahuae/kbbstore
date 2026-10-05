/*
 * Lane AP -- the sidebar as a non-owner role receives it (server-filtered).
 *   node tools/ap-role-shot.cjs <port> <role>
 * Writes docs/ap-shots/1280-<role>-sidebar.png and prints the rows it got.
 */
const { chromium } = require('playwright');
const [PORT, ROLE] = [process.argv[2], process.argv[3] || 'support'];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 860 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' })).newPage();
  await p.goto(`http://127.0.0.1:${PORT}/admin/login`);
  await p.fill('input[name=email]', ROLE + '@preview.test'); await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('button[type=submit]')]);
  await p.waitForTimeout(800);
  await p.$$eval('#nav .nav-group', (gs) => gs.forEach((g) => g.classList.add('open')));
  await p.waitForTimeout(500);
  await p.screenshot({ path: `docs/ap-shots/1280-${ROLE}-sidebar.png` });
  const rows = await p.$$eval('#nav .nav-item', (bs) => bs.map((x) => x.dataset.go));
  console.log(ROLE, rows.length, rows.join(' '));
  await b.close();
})();
