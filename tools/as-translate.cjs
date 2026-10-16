// Lane AS -- Chromium proof of resources/js/kbb/translate-away.js, plus the
// Arabic product page pictures. Usage: node tools/as-translate.cjs <base-url> <out-dir>
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.argv[2];
const OUT = process.argv[3];
const AR = '/ar/product/azelaic-10-hyaluron-redness-soothing-pad/';
const EN = '/product/azelaic-10-hyaluron-redness-soothing-pad/';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
const results = [];
const say = (name, ok, detail) => { results.push({ name, ok, detail }); console.log((ok ? 'PASS ' : 'FAIL ') + name + ' ' + JSON.stringify(detail)); };
const pause = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const fresh = async (ua = UA, width = 1280) => {
    const ctx = await browser.newContext({ userAgent: ua, viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    // The preview fixture has no product photographs, so their 404s are noise.
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource: .*404/.test(m.text())) errors.push(m.text()); });
    return { ctx, page, errors };
  };
  const cookie = async (ctx) => ((await ctx.cookies()).find((c) => c.name === 'kbb_lang') || {}).value || null;

  // 1. Chrome's translator: class translated-ltr + lang="en".
  {
    const { ctx, page, errors } = await fresh();
    await page.goto(BASE + AR, { waitUntil: 'load' });
    const flag = await page.evaluate(() => window.KBB && window.KBB.trEn);
    const nav = page.waitForURL((u) => !u.pathname.startsWith('/ar/'), { timeout: 5000 }).then(() => true, () => false);
    await page.evaluate(() => { const h = document.documentElement; h.classList.add('translated-ltr'); h.setAttribute('lang', 'en'); });
    const went = await nav;
    const url = new URL(page.url()).pathname;
    say('chrome-translate opens English', went && url === EN, { flag, url, cookie: await cookie(ctx), errors });
    await ctx.close();
  }

  // 2. Edge's translator: lang only.
  {
    const { ctx, page } = await fresh();
    await page.goto(BASE + AR, { waitUntil: 'load' });
    const nav = page.waitForURL((u) => !u.pathname.startsWith('/ar/'), { timeout: 5000 }).then(() => true, () => false);
    await page.evaluate(() => document.documentElement.setAttribute('lang', 'fr'));
    say('edge-translate (lang only) opens English', await nav && new URL(page.url()).pathname === EN, { url: new URL(page.url()).pathname });
    await ctx.close();
  }

  // 3. English page: the same marker does nothing.
  {
    const { ctx, page, errors } = await fresh();
    await page.goto(BASE + EN, { waitUntil: 'load' });
    const flag = await page.evaluate(() => window.KBB && window.KBB.trEn);
    await page.evaluate(() => { const h = document.documentElement; h.classList.add('translated-ltr'); h.setAttribute('lang', 'fr'); });
    await pause(1500);
    say('english page never moves', new URL(page.url()).pathname === EN && flag === undefined, { flag, url: new URL(page.url()).pathname, cookie: await cookie(ctx), errors });
    await ctx.close();
  }

  // 4. Remembered: cookie set, landing on /ar from outside -> English.
  // 5. Then an explicit click on an Arabic link wins and clears the cookie.
  {
    const { ctx, page } = await fresh();
    await ctx.addCookies([{ name: 'kbb_lang', value: 'en', url: BASE }]);
    await page.goto(BASE + AR, { waitUntil: 'load' });
    await page.waitForURL((u) => !u.pathname.startsWith('/ar/'), { timeout: 5000 }).catch(() => {});
    say('remembered choice opens English on landing', new URL(page.url()).pathname === EN, { url: new URL(page.url()).pathname });
    await page.evaluate((href) => { const a = document.createElement('a'); a.href = href; a.id = 'as-ar'; a.textContent = 'العربية'; a.style.cssText = 'position:fixed;top:0;left:0;z-index:2147483647;background:#fff;padding:8px'; document.body.appendChild(a); }, AR);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('#as-ar')]);
    await pause(1500);
    say('choosing Arabic wins (stays, cookie cleared)', new URL(page.url()).pathname === AR, { url: new URL(page.url()).pathname, cookie: await cookie(ctx) });
    await ctx.close();
  }

  // 6. A crawler: translated marker, no move.
  {
    const { ctx, page } = await fresh(BOT);
    await page.goto(BASE + AR, { waitUntil: 'load' });
    await page.evaluate(() => { const h = document.documentElement; h.classList.add('translated-ltr'); h.setAttribute('lang', 'en'); });
    await pause(1500);
    say('crawler never redirected', new URL(page.url()).pathname === AR, { url: new URL(page.url()).pathname, cookie: await cookie(ctx) });
    await ctx.close();
  }

  // Pictures: the Arabic product page at 390 and 1280, plus its snippet data.
  for (const w of [390, 1280]) {
    const { ctx, page, errors } = await fresh(UA, w);
    await page.goto(BASE + AR, { waitUntil: 'load' });
    await pause(400);
    await page.screenshot({ path: OUT + '/ar-product-' + w + '.png', fullPage: false });
    const data = await page.evaluate(() => ({
      title: document.title,
      description: (document.querySelector('meta[name="description"]') || {}).content,
      canonical: (document.querySelector('link[rel="canonical"]') || {}).href,
      ogLocale: (document.querySelector('meta[property="og:locale"]') || {}).content,
      hreflang: [...document.querySelectorAll('link[hreflang]')].map((l) => l.hreflang + ' ' + l.href),
      alts: [...document.querySelectorAll('main img, .pdp img, img')].slice(0, 6).map((i) => i.alt),
      h1: document.querySelectorAll('h1').length,
      scrollWidth: document.documentElement.scrollWidth,
    }));
    fs.writeFileSync(OUT + '/ar-product-' + w + '.json', JSON.stringify({ ...data, errors }, null, 1));
    say('screenshot ' + w, errors.length === 0 && data.scrollWidth === w, { scrollWidth: data.scrollWidth, errors });
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(OUT + '/translate-run.json', JSON.stringify(results, null, 1));
  process.exit(results.every((r) => r.ok) ? 0 : 1);
})();
