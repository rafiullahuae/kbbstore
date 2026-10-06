/*
 * Lane SX shots: "Sold-out products".
 *   URL=http://127.0.0.1:10760 node tools/soldout-shots.cjs
 * Admin controls at 390 and 1280, and /collections/toners/ + /brands/glowtest/
 * before (Use the shop default = Show as usual), with "end", and with "hide".
 */
const { chromium } = require('playwright');
const URL = process.env.URL;
const OUT = process.env.OUT || 'docs/soldout-shots';
const W = [390, 1280];

async function measure(page) {
  return page.evaluate(() => {
    const main = document.querySelector('main#content') || document.body;
    const cards = [...main.querySelectorAll('.kbb-card')];
    const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent).join('');
    const m = ld.match(/"numberOfItems":(\d+)/);
    return {
      cards: cards.length,
      order: cards.map((c) => (c.querySelector('.kbb-card-nm') || c).textContent.trim().replace(/^(Toner|Glow) /, '').replace(' (sold out)', '*')),
      soldPills: main.querySelectorAll('.kbb-soldout').length,
      itemList: m ? Number(m[1]) : null,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

async function storefront(b, path, tag) {
  for (const w of W) {
    const page = await (await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    await page.goto(URL + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/${tag}-${w}.png`, fullPage: true });
    console.log(tag, w, JSON.stringify(await measure(page)));
    await page.close();
  }
}

async function admin(b, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
  const page = await ctx.newPage();
  await page.goto(URL + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function shootField(page, sel, file) {
  await page.locator(sel).scrollIntoViewIfNeeded();
  await page.evaluate((s) => document.querySelector(s).closest('div').scrollIntoView({ block: 'center' }), sel);
  await page.waitForTimeout(300);
  await page.screenshot({ path: file });
}

async function editCategory(page, mode, file) {
  await page.evaluate(() => window.go('category-tree'));
  await page.waitForSelector('[data-edit]');
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const btn = [...document.querySelectorAll('[data-edit]')].find((b) => (b.closest('.ct-row') || b).textContent.includes('Toners'));
    btn.click();
  });
  await page.waitForSelector('#ct-soldout');
  if (mode !== null) await page.selectOption('#ct-soldout', mode);
  if (file) await shootField(page, '#ct-soldout', file);
  const opts = await page.$$eval('#ct-soldout option', (os) => os.map((o) => (o.selected ? '[x] ' : '[ ] ') + o.textContent));
  if (mode !== null) {
    await page.click('#ct-save');
    await page.waitForTimeout(1200);
  }
  return opts;
}

(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  // 1. BEFORE: nothing chosen anywhere -- the page as it ships.
  await storefront(b, '/collections/toners/', 'category-before');
  await storefront(b, '/brands/glowtest/', 'brand-before');

  // 2. The three controls, at both widths.
  for (const w of W) {
    const page = await admin(b, w);
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForSelector('[data-sls-tab="grid"]', { timeout: 20000 });
    await page.click('[data-sls-tab="grid"]');
    await page.waitForSelector('[data-sls-key="sold_out"]');
    await shootField(page, '[data-sls-key="sold_out"]', `${OUT}/admin-site-layout-sold-out-${w}.png`);
    console.log('global', w, JSON.stringify(await page.$$eval('[data-sls-key="sold_out"] option', (os) => os.map((o) => (o.selected ? '[x] ' : '[ ] ') + o.textContent))),
      'scrollWidth', await page.evaluate(() => document.documentElement.scrollWidth));

    console.log('category', w, JSON.stringify(await editCategory(page, null, `${OUT}/admin-category-sold-out-${w}.png`)));
    await page.keyboard.press('Escape');
    await page.evaluate(() => document.querySelectorAll('[data-close]').forEach((x) => x.click()));

    await page.evaluate(() => window.go('brands-manager'));
    await page.waitForSelector('[data-bedit]');
    await page.waitForTimeout(500);
    await page.evaluate(() => [...document.querySelectorAll('[data-bedit]')].find((x) => (x.closest('.bz-row,[data-id],li') || x.parentElement.parentElement).textContent.includes('Glowtest')).click());
    await page.waitForSelector('#bz-soldout');
    if (w === 1280) await page.selectOption('#bz-soldout', 'end');
    await shootField(page, '#bz-soldout', `${OUT}/admin-brand-sold-out-${w}.png`);
    console.log('brand', w, JSON.stringify(await page.$$eval('#bz-soldout option', (os) => os.map((o) => (o.selected ? '[x] ' : '[ ] ') + o.textContent))));
    if (w === 1280) { await page.click('#bz-save'); await page.waitForTimeout(1200); }
    await page.context().close();
  }

  // 3. Toners -> "Show at the very end", saved through the Edit dialog.
  let page = await admin(b, 1280);
  await editCategory(page, 'end', `${OUT}/admin-category-set-end-1280.png`);
  await storefront(b, '/collections/toners/', 'category-end');
  await storefront(b, '/brands/glowtest/', 'brand-end');

  // 4. Toners -> "Hide from listings".
  await editCategory(page, 'hide', `${OUT}/admin-category-set-hide-1280.png`);
  await storefront(b, '/collections/toners/', 'category-hide');
  const prod = await (await b.newContext()).newPage();
  const r = await prod.goto(URL + '/product/toner-t2/');
  console.log('hidden product page status', r.status());
  await b.close();
})();
