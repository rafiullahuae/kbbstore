const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
  const pg = await ctx.newPage(); const log = []; pg.on('pageerror', (e) => log.push('pageerror ' + e.message));
  ctx.on('response', async (r) => { if (/checkout\/sign-in/.test(r.url())) { let t = ''; try { t = (await r.text()).slice(0, 90); } catch (e) {} log.push([r.request().method(), r.status(), r.url().replace(process.env.BASE, ''), t]); } });
  await pg.route(/js\.stripe\.com/, (r) => r.abort());
  await pg.goto(process.env.BASE + '/shop/', { waitUntil: 'networkidle' });
  await pg.evaluate(async () => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: 683, quantity: 1 }) }); });
  await pg.goto(process.env.BASE + '/checkout/', { waitUntil: 'domcontentloaded' }); await pg.waitForTimeout(800);
  const before = await pg.evaluate(() => window.KBB.csrf);
  // the token is renewed elsewhere in the same browser: an admin sign-in in another tab
  const other = await ctx.newPage();
  await other.goto(process.env.BASE + '/admin/login');
  await other.fill('#email', 'co13@example.com'); await other.fill('#password', 'preview-pass-123');
  await Promise.all([other.waitForNavigation(), other.click('button[type=submit]')]);
  await other.close();
  await pg.bringToFront();
  await pg.evaluate(() => document.querySelector('.co-signin').click()); await pg.waitForTimeout(400);
  await pg.fill('[data-si-form="login"] input[type=email]', 'si-test@example.com'); await pg.fill('#kbbSiPw', 'right-password-1');
  await pg.evaluate(() => document.querySelector('[data-si-form="login"]').requestSubmit());
  await pg.waitForTimeout(3000);
  log.push(['token renewed by the fix', before !== await pg.evaluate(() => window.KBB.csrf)]);
  log.push(['dialog', await pg.evaluate(() => { const d = document.getElementById('kbbSignIn'); return d ? (d.open ? d.innerText.replace(/\s+/g, ' ').slice(0, 120) : 'closed') : 'gone'; })]);
  log.push(['email box now', await pg.evaluate(() => (document.getElementById('billing_email') || {}).value || '')]);
  console.log(JSON.stringify(log, null, 1)); await b.close();
})();
