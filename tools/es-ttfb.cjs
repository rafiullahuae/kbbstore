/* Median server time (responseStart - requestStart) of /checkout/ and
   /my-account/edit-address over N loads, signed in with a basket.
   node tools/es-ttfb.cjs <port> [n] */
const { chromium } = require('playwright');
(async () => {
  const BASE = 'http://127.0.0.1:' + process.argv[2]; const N = +(process.argv[3] || 15);
  const ids = JSON.parse(await (await fetch(BASE + '/es-ids.json')).text());
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36' })).newPage();
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await page.evaluate(async () => { await fetch('/my-account/login', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.KBB.csrf }, body: 'email=aisha%40preview.test&password=preview-secret-1' }); });
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  for (const id of ids) await page.evaluate(async (pid) => { await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }); }, id);
  const out = {};
  for (const path of ['/checkout/', '/my-account/edit-address']) {
    const t = [];
    for (let i = 0; i < N + 2; i++) {
      await page.goto(BASE + path, { waitUntil: 'load' });
      const nav = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return { ms: n.responseStart - n.requestStart, bytes: n.decodedBodySize }; });
      if (i >= 2) t.push(nav);
    }
    const ms = t.map((x) => x.ms).sort((a, b) => a - b);
    out[path] = { medianMs: Math.round(ms[Math.floor(ms.length / 2)]), htmlBytes: t[0].bytes };
  }
  console.log(JSON.stringify(out));
  await browser.close();
})();
