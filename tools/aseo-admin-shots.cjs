// Lane ASEO -- Store -> SEO & Meta -> Settings -> "Arabic search appearance (/ar)",
// at 1280 and 390, then an edit saved and read back on /ar/.
// Usage: NODE_PATH=$(npm root -g) node tools/aseo-admin-shots.cjs <base> <out-dir>
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = process.argv[3];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin#seo', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await page.evaluate(() => window.go('seo'));
    await page.waitForSelector('#seo_home_kw_ar', { timeout: 15000 });
    const sec = page.locator('.sm-sec', { hasText: 'Arabic search appearance' });
    await sec.scrollIntoViewIfNeeded();
    await sec.screenshot({ path: OUT + '/admin-arabic-seo-' + w + '.png' });
    const vals = await page.evaluate(() => ['seo_sitename_ar', 'seo_home_t_ar', 'seo_home_d_ar', 'seo_def_d_ar', 'seo_home_kw_ar']
      .map((id) => { const el = document.getElementById(id); return id + '=' + el.value.length + ' chars dir=' + el.dir; }));
    console.log(w, JSON.stringify(vals), 'scrollWidth', await page.evaluate(() => document.documentElement.scrollWidth), 'errors', JSON.stringify(errors));
    if (w === 1280) {
      // Before / after: a new Arabic homepage title, saved, then read off /ar/.
      const before = await (await ctx.request.get(BASE + '/ar/')).text();
      const was = (before.match(/<title>(.*?)<\/title>/) || [])[1];
      await page.fill('#seo_home_t_ar', 'كي بيوتي بليس — عناية كورية أصلية في الإمارات');
      await page.click('#set_save_seo');
      await page.waitForTimeout(1200);
      await sec.screenshot({ path: OUT + '/admin-arabic-seo-1280-edited.png' });
      const after = await (await ctx.request.get(BASE + '/ar/')).text();
      console.log('ar title before:', was, '| after save:', (after.match(/<title>(.*?)<\/title>/) || [])[1]);
      await page.fill('#seo_home_t_ar', 'كي بيوتي بليس — منتجات العناية الكورية الأصلية في الإمارات');
      await page.click('#set_save_seo');
      await page.waitForTimeout(1200);
    }
    await ctx.close();
  }
  await browser.close();
})();
