/*
 * Lane FB: photographs and measurements of the footer's app row, against the
 * preview from tools/fbapp-preview.sh. Usage:
 *   node tools/fbapp-shots.cjs <base> main     (laptop switch OFF, as shipped)
 *   node tools/fbapp-shots.cjs <base> laptop   (after tools/fbapp-set.sh site_d_app_laptop true)
 * Writes docs/fbapp-shots/*.png and prints the numbers as JSON. The measuring
 * here is the HARNESS's (getBoundingClientRect on a test page), never the shop's.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.argv[2];
const MODE = process.argv[3] || 'main';
const OUT = __dirname + '/../docs/fbapp-shots';
fs.mkdirSync(OUT, { recursive: true });

const UA = {
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
  android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
  insta: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0',
};

const measure = () => {
  const r = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return b.height ? +b.height.toFixed(1) : 0; };
  const b = document.querySelector('.kfa-tx b');
  const wa = document.getElementById('kbbWa');
  const name = document.querySelector('.kft-name'), row = document.querySelector('.kfa'), bot = document.querySelector('.kft-bot');
  return {
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    row: r('.kfa'), glass: r('.kfa-g'), button: r('.kfa-bt'), lineBox: r('.kfa-ln'),
    headlineFont: b ? getComputedStyle(b).fontSize : null,
    lineFont: document.querySelector('.kfa-ln') ? getComputedStyle(document.querySelector('.kfa-ln')).fontSize : null,
    headlineCut: b ? b.scrollWidth > b.clientWidth : null,
    rowDisplay: row ? getComputedStyle(row).display : 'not printed',
    order: name && row && bot ? (name.getBoundingClientRect().bottom <= row.getBoundingClientRect().top + 0.5 && row.getBoundingClientRect().bottom <= bot.getBoundingClientRect().top + 0.5) : null,
    waVisibility: wa ? getComputedStyle(wa).visibility : 'no button',
    htmlClass: document.documentElement.className,
    typing: (document.querySelector('.kfa-ty') || {}).textContent,
  };
};

async function open(browser, { w, ua, lang = '', reduce = false, h }) {
  const mobile = w < 800;
  const ctx = await browser.newContext({
    viewport: { width: w, height: h || (mobile ? 844 : 900) }, deviceScaleFactor: mobile ? 2 : 1,
    isMobile: mobile, hasTouch: mobile, userAgent: ua || (mobile ? UA.iphone : undefined),
    reducedMotion: reduce ? 'reduce' : 'no-preference',
  });
  const page = await ctx.newPage();
  await page.goto(BASE + (lang ? '/' + lang + '/' : '/'), { waitUntil: 'networkidle' });
  return { ctx, page };
}

const toBottom = (page) => page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));

async function footerShot(page, name) {
  const clip = await page.evaluate(() => {
    const n = document.querySelector('.kft-name') || document.querySelector('.kft-main');
    const f = document.querySelector('footer.kft').getBoundingClientRect();
    const top = n.getBoundingClientRect().top - 40;
    return { x: 0, y: Math.max(0, top + window.scrollY), width: document.documentElement.clientWidth, height: f.bottom - Math.max(0, top) };
  });
  await page.screenshot({ path: `${OUT}/${name}.png`, clip, fullPage: true });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};

  if (MODE === 'main') {
    // 390 English: the row, the numbers, then the typing as a short sequence of frames.
    let { ctx, page } = await open(browser, { w: 390 });
    await toBottom(page); await page.waitForTimeout(400);
    out['en-390'] = await page.evaluate(measure);
    await footerShot(page, 'en-390');
    const frames = [];
    for (const t of [0, 1700, 2300, 3000, 3700, 4600, 6500]) {
      await page.waitForTimeout(t - (frames.length ? frames[frames.length - 1].t : 0));
      const f = { t, text: await page.evaluate(() => document.querySelector('.kfa-ty').textContent), dir: await page.evaluate(() => document.querySelector('.kfa-ty').dir), row: await page.evaluate(() => +document.querySelector('.kfa').getBoundingClientRect().height.toFixed(1)) };
      frames.push(f);
      await page.locator('.kfa').screenshot({ path: `${OUT}/typing-${frames.length}.png` });
    }
    out.typing = frames;
    // WhatsApp: row on screen -> hidden; scroll up -> back, and the typing stops.
    await page.screenshot({ path: `${OUT}/wa-row-in-view-390.png` });
    out.waInView = await page.evaluate(measure);
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' })); await page.waitForTimeout(400);
    const t1 = await page.evaluate(() => document.querySelector('.kfa-ty').textContent);
    await page.screenshot({ path: `${OUT}/wa-scrolled-up-390.png` });
    out.waScrolledUp = await page.evaluate(measure);
    await page.waitForTimeout(2500);
    out.typingStoppedOffScreen = { before: t1, after: await page.evaluate(() => document.querySelector('.kfa-ty').textContent), run: await page.evaluate(() => document.querySelector('.kfa').classList.contains('kfa-run')) };
    await ctx.close();

    // iPhone sheet (English and Arabic), Android fallback sheet, Instagram sheet.
    for (const [name, ua, lang] of [['sheet-iphone-390', UA.iphone, ''], ['sheet-iphone-390-ar', UA.iphone, 'ar'], ['sheet-android-390', UA.android, ''], ['sheet-instagram-390', UA.insta, '']]) {
      ({ ctx, page } = await open(browser, { w: 390, ua, lang }));
      await toBottom(page); await page.waitForTimeout(300);
      const reqs = [];
      page.on('request', (r) => reqs.push(r.url()));
      await page.click('.kfa-bt'); await page.waitForTimeout(300);
      out[name] = { requestsAfterTap: reqs.length, title: await page.evaluate(() => (document.querySelector('.kfa-sh h2') || {}).textContent) };
      await page.screenshot({ path: `${OUT}/${name}.png` });
      await ctx.close();
    }

    // Arabic 390.
    ({ ctx, page } = await open(browser, { w: 390, lang: 'ar' }));
    await toBottom(page); await page.waitForTimeout(400);
    out['ar-390'] = await page.evaluate(measure);
    await footerShot(page, 'ar-390');
    await page.waitForTimeout(2600);
    await page.locator('.kfa').screenshot({ path: `${OUT}/typing-ar-english-line.png` });
    out['ar-390'].secondLine = await page.evaluate(() => [document.querySelector('.kfa-ty').dir, document.querySelector('.kfa-ty').textContent]);
    await ctx.close();

    // Reduced motion: the first line stays, nothing types.
    ({ ctx, page } = await open(browser, { w: 390, reduce: true }));
    await toBottom(page); await page.waitForTimeout(5000);
    out.reducedMotion = await page.evaluate(() => ({ text: document.querySelector('.kfa-ty').textContent, run: document.querySelector('.kfa').classList.contains('kfa-run') }));
    await ctx.close();

    // Tablet 820 (iPad portrait) and 1280 laptop, switch off.
    ({ ctx, page } = await open(browser, { w: 820, ua: UA.iphone.replace('iPhone; CPU iPhone OS', 'iPad; CPU OS') }));
    await toBottom(page); await page.waitForTimeout(400);
    out['ipad-820'] = await page.evaluate(measure);
    await footerShot(page, 'ipad-820');
    await ctx.close();
    ({ ctx, page } = await open(browser, { w: 1280 }));
    await toBottom(page); await page.waitForTimeout(400);
    out['laptop-1280-off'] = await page.evaluate(measure);
    await footerShot(page, 'laptop-1280-off');
    await ctx.close();
  } else {
    let { ctx, page } = await open(browser, { w: 1280 });
    await toBottom(page); await page.waitForTimeout(400);
    out['laptop-1280-on'] = await page.evaluate(measure);
    await footerShot(page, 'laptop-1280-on');
    await page.click('.kfa-bt'); await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/laptop-1280-qr.png` });
    await page.locator('.kfa-qr svg').screenshot({ path: `${OUT}/qr-only.png` });
    await ctx.close();
  }

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
