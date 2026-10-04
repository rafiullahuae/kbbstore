/*
 * Lane FT: the site footer's last row, before and after the owner's "remove
 * the effect from the very last row of the footer. animation not from the
 * logo." Before = the shine switched back on ('bar', what shipped); after =
 * the shipped default ('off'). Run against tools/ft-preview.sh.
 *
 *   node tools/ft-lastrow-shots.cjs 1280   (and 390)
 */
const { chromium } = require('playwright');

const BASE = process.env.FT_BASE || 'http://127.0.0.1:10030';
const OUT = process.env.FT_OUT || (__dirname + '/../docs/lane-ft-shots');
const CHROME = process.env.FT_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const width = +process.argv[2] || 1280;
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

  const set = (v) => page.evaluate(async (v) => {
    const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/admin-api/slim-footer', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t },
      body: JSON.stringify({ settings: { site_sheen: v } }) });
    return r.status;
  }, v);

  const out = [];
  for (const [label, value] of [['before', 'bar'], ['after', 'off']]) {
    const status = await set(value);
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    // The floating WhatsApp button sits over the last row's corner; it is not
    // part of the footer, so it is hidden for the picture only.
    await page.addStyleTag({ content: '[class^="kbw"],[class*=" kbw"]{display:none!important}' });
    const bot = page.locator('footer.kft .kft-bot');
    await bot.scrollIntoViewIfNeeded();
    await page.waitForTimeout(1800);   // mid-sweep, if there is a sweep
    const m = await page.evaluate(() => {
      const f = document.querySelector('footer.kft');
      const b = f.querySelector('.kft-bot');
      const n = f.querySelector('.kft-name');
      return {
        footerClass: f.className,
        lastRowEffect: getComputedStyle(b, '::before').animationName,
        lastRowEffectContent: getComputedStyle(b, '::before').content,
        bigNameAnimation: n ? getComputedStyle(n).animationName : null,
        lastRowHeight: Math.round(b.getBoundingClientRect().height),
        scrollWidth: document.documentElement.scrollWidth,
      };
    });
    // Page coordinates, not the viewport's: the clip is taken off the full page.
    const box = await page.evaluate(() => { const r = document.querySelector('footer.kft').getBoundingClientRect(); return { y: r.top + window.scrollY, height: r.height }; });
    await page.screenshot({ path: `${OUT}/lastrow-${label}-${width}.png`,
      clip: { x: 0, y: Math.max(0, box.y + box.height - (width < 600 ? 260 : 330)), width, height: width < 600 ? 260 : 330 } , fullPage: true });
    out.push({ width, label, site_sheen: value, save: status, ...m });
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
