/*
 * Lane AR stage 2 -- pictures of the private Arabic preview link.
 *
 *   node tools/arr-preview-link-shots.cjs <port> <link-url-path-with-token> [out=docs/lane-ar-shots/round2]
 *
 * Run with Arabic OFF on the preview (language_ar_enabled = 0):
 *   1. a visitor opening /ar/            -> the ordinary 404
 *   2. the link (?kbb_arp=...)           -> 302 to /ar/, cookie set, the Arabic shop,
 *                                           with X-Robots-Tag noindex and no-store
 *   3. the English home for that visitor -> unchanged, no noindex
 *   4. the admin card, Translation → Settings (owner signed in)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [port, linkArg, out = 'docs/lane-ar-shots/round2'] = process.argv.slice(2);
const link = linkArg && linkArg !== '-' ? linkArg : fs.readFileSync('storage/ar-logs/arp-link.txt', 'utf8').trim();
const B = `http://127.0.0.1:${port}`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = {};
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: UA });
    const p = await ctx.newPage();
    const v = await p.goto(B + '/ar/', { waitUntil: 'load' });
    res[`visitor /ar ${w}`] = v.status();
    await p.screenshot({ path: `${out}/preview-link-visitor-404-${w}.png` });
    const first = await p.goto(B + link, { waitUntil: 'load' });
    const chain = first.request().redirectedFrom();
    const h = first.headers();
    res[`link ${w}`] = {
      redirected: !!chain, redirect_status: chain ? (await chain.response()).status() : null,
      final_url: p.url().replace(B, ''), status: first.status(), x_robots: h['x-robots-tag'], cache: h['cache-control'],
      lang: await p.evaluate(() => document.documentElement.lang + '/' + document.documentElement.dir),
      robots_meta: await p.evaluate(() => (document.querySelector('meta[name=robots]') || {}).content),
      cookie: (await ctx.cookies()).filter((c) => c.name === 'kbb_arp').map((c) => ({ httpOnly: c.httpOnly, sameSite: c.sameSite }))[0],
      sw: await p.evaluate(() => document.documentElement.scrollWidth),
    };
    await p.screenshot({ path: `${out}/preview-link-arabic-${w}.png` });
    const again = await p.goto(B + '/ar/shop/', { waitUntil: 'load' });
    res[`cookie /ar/shop ${w}`] = again.status();
    const other = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: UA });
    const q = await other.newPage();
    const en = await q.goto(B + '/', { waitUntil: 'load' });
    res[`other visitor / ${w}`] = { status: en.status(), x_robots: en.headers()['x-robots-tag'] || null, hreflang_ar: await q.evaluate(() => !!document.querySelector('link[hreflang=ar]')) };
    await other.close();
    await ctx.close();
  }
  // The admin card.
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, userAgent: UA });
  const p = await ctx.newPage();
  await p.goto(B + '/admin/login', { waitUntil: 'load' });
  await p.fill('input[name=email]', 'owner@preview.test');
  await p.fill('input[name=password]', 'preview-pass-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'load' }).catch(() => null), p.click('button[type=submit]')]);
  await p.evaluate(() => window.go && window.go('tr-settings'));
  await p.waitForTimeout(1500);
  const card = await p.$('text=Private Arabic preview link');
  res.admin_card = !!card;
  if (card) {
    const box = await card.evaluateHandle((n) => n.closest('.tr-card'));
    await box.asElement().screenshot({ path: `${out}/preview-link-admin-card-1280.png` });
  } else {
    await p.screenshot({ path: `${out}/preview-link-admin-page-1280.png` });
  }
  await browser.close();
  fs.writeFileSync(`${out}/preview-link.json`, JSON.stringify(res, null, 1));
  console.log(JSON.stringify(res, null, 1));
})();
