/*
 * LANE M4 — Appearance → Mobile menu, signed in as the owner, at 390 and 1280:
 * the Style, Quick links, Sizes and Sub-menu animation cards. With `move` it
 * then moves a few controls on the 1280 screen and presses Save, so the shop
 * can be photographed after (docs/m4-shots/moved).
 *
 *   KBB_BASE=http://127.0.0.1:10790 node tools/m4-admin-shots.cjs [move]
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10790';
const OUT = path.join(__dirname, '..', 'docs', 'm4-shots', 'admin');
fs.mkdirSync(OUT, { recursive: true });
const MOVE = process.argv[2] === 'move';

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message.split('\n')[0]));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('mobilemenu'));
    await page.waitForSelector('#m4Chips .m4c-row', { timeout: 15000 });
    await page.waitForTimeout(400);
    const cardOf = (title) => page.locator('.mmcard', { has: page.locator('.mmhd b', { hasText: new RegExp('^' + title + '$') }) }).first();
    const tag = MOVE ? 'moved' : 'default';
    for (const [title, file] of [['Style', 'style'], ['Quick links', 'quick-links'], ['Sizes', 'sizes'], ['Sub-menu animation', 'animation']]) {
      const c = cardOf(title);
      await c.scrollIntoViewIfNeeded();
      await c.screenshot({ path: `${OUT}/${tag}-${file}-${width}.png` });
    }
    if (width === 1280) await page.screenshot({ path: `${OUT}/${tag}-screen-1280.png`, fullPage: false });
    const m = await page.evaluate(() => ({
      crumb: (document.querySelector('#crumb') || {}).textContent,
      cards: [...document.querySelectorAll('.mmcard .mmhd b')].map((b) => b.textContent),
      chips: [...document.querySelectorAll('#m4Chips .m4c-en')].map((i) => i.value),
      scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
    }));
    console.log(width, JSON.stringify(m), errors);

    if (MOVE && width === 1280) {
      const range = async (k, v) => page.evaluate(([k, v]) => { const el = document.querySelector(`input[data-mm="${k}"]`); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); }, [k, v]);
      await range('row_h', 52); await range('row_fs', 15); await range('chip_fs', 13); await range('chip_h', 36); await range('panel_w_pct', 92);
      await page.selectOption('select[data-mm="sub_anim"]', 'fade');
      // A sixth link, picked from the shop's own list, and the second one turned gold.
      await page.click('#m4Chips [data-m4="add"]');
      const last = page.locator('#m4Chips .m4c-row').last();
      await last.locator('.m4c-en').fill('Sunscreens');
      await last.locator('.m4c-ar').fill('واقيات الشمس');
      await last.locator('[data-m4="pick"]').click();
      const sel = last.locator('select[data-m4="pickto"]');
      await sel.waitFor({ timeout: 10000 });
      const val = await sel.evaluate((s) => { const o = [...s.options].find((x) => /sunscreen/i.test(x.textContent)) || s.options[1]; return o.value; });
      await sel.selectOption(val);
      await page.locator('#m4Chips .m4c-row').nth(1).locator('input[value="gold"]').check();
      // A link that is refused: shown red before Save, and the server says why.
      await page.locator('#m4Chips .m4c-row').nth(2).locator('[data-m4="url"]').fill('javascript:alert(1)');
      await cardOf('Quick links').screenshot({ path: `${OUT}/moved-quick-links-bad-1280.png` });
      await page.click('#mmSave');
      await page.waitForTimeout(800);
      const refused = await page.textContent('#mmDirty');
      await page.locator('#m4Chips .m4c-row').nth(2).locator('[data-m4="url"]').fill('/best-sellers/');
      await page.click('#mmSave');
      await page.waitForTimeout(800);
      const saved = await page.textContent('#mmDirty');
      console.log('refused:', refused, '| saved:', saved, '| picked:', val);
      await cardOf('Quick links').screenshot({ path: `${OUT}/moved-quick-links-1280.png` });
      await cardOf('Sizes').screenshot({ path: `${OUT}/moved-sizes-1280.png` });
      await page.locator('.mmpv').screenshot({ path: `${OUT}/moved-preview-1280.png` });
    }
    await ctx.close();
  }
  await browser.close();
})();
