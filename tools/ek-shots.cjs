/* Lane EK — screenshots of Emails → Customer emails, the template builder and
   every tabbed Emails screen at 1280 and 390, with the numbers that matter.
   Run against tools/ek-preview.sh:  node tools/ek-shots.cjs http://127.0.0.1:9883 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:9883';
const OUT = path.join(__dirname, '..', 'docs', 'ek-shots');
const who = process.argv[3] || 'owner@preview.test';
const BEFORE = process.argv[4] === 'before';

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', who);
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}
async function measure(page) {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    tablists: [...document.querySelectorAll('#content [role=tablist]')].map(t => ({ group: t.dataset.kbt, tabs: [...t.querySelectorAll('[role=tab]')].filter(b => getComputedStyle(b).display !== 'none').map(b => b.textContent.trim()) })),
    title: (document.querySelector('#content .page-head h2') || {}).textContent,
  }));
}
async function go(page, id, wait) {
  await page.evaluate((i) => window.go(i), id);
  if (wait) await page.waitForSelector(wait, { timeout: 20000 });
  await page.waitForTimeout(900);
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const log = {};
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1900 : 2200 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', w, String(e)));
    await login(page);
    await page.evaluate(() => { try { localStorage.clear(); } catch (e) {} });

    const shots = [
      ['e3-customer-emails', 'emails-customer', '[data-ek="customer"] table'],
      ['emails-overview', 'emails', '[data-eml="emails"] [role=tablist]'],
      ['emails-sending', 'emails-sending', '[data-eml="emails-sending"] [role=tablist]'],
      ['emails-branding', 'emails-branding', '[data-eml="emails-branding"] [role=tablist]'],
      ['emails-sent', 'emails-sent', '[data-eml="emails-sent"] [role=tablist]'],
      ['mail-all-settings', 'mail', '[data-kbt="mail"]'],
    ];
    for (const [name, id, sel] of shots) {
      if (BEFORE && name.startsWith('e3')) continue;
      await go(page, id, BEFORE ? '#content .page-head' : sel);
      if (BEFORE) await page.waitForTimeout(1500);
      log[`${name}-${w}`] = await measure(page);
      await page.screenshot({ path: `${OUT}/${BEFORE ? 'before-' : ''}${name}-${w}.png`, fullPage: true });
    }
    if (BEFORE) { await ctx.close(); continue; }

    // Branding: each tab, to show the split.
    for (const tab of ['colours', 'footer', 'preview']) {
      await go(page, 'emails-branding', '[data-kbt="eml-branding"]');
      await page.click(`[data-kbt="eml-branding"] [data-kbt-tab="${tab}"]`);
      await page.waitForTimeout(tab === 'preview' ? 1500 : 300);
      await page.screenshot({ path: `${OUT}/emails-branding-${tab}-${w}.png`, fullPage: true });
    }
    // All mail settings: a second tab, and the keyboard.
    await go(page, 'mail', '[data-kbt="mail"]');
    await page.focus('[data-kbt="mail"] [aria-selected="true"]');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    log[`mail-keyboard-${w}`] = await page.evaluate(() => ({ selected: document.querySelector('[data-kbt="mail"] [aria-selected="true"]').textContent, focused: document.activeElement.textContent }));
    await page.screenshot({ path: `${OUT}/mail-all-settings-tab3-${w}.png`, fullPage: true });

    // The editor (e4) on Order shipped.
    await page.evaluate(() => { try { localStorage.removeItem('kbbtab:ek-editor'); } catch (e) {} });
    await go(page, 'emails-customer', '[data-ek-edit="order_status_shipped"]');
    await page.click('[data-kbt-panel="ek-customer"]:not([hidden]) [data-ek-edit="order_status_shipped"]');
    await page.waitForSelector('[data-ek-list] li', { timeout: 20000 });
    await page.waitForTimeout(2500);
    log[`e4-editor-${w}`] = await measure(page);
    await page.screenshot({ path: `${OUT}/e4-template-editor-${w}.png`, fullPage: true });

    // Drag and drop: "Items with pictures" above "Order number box", by pointer.
    const order = () => page.evaluate(() => [...document.querySelectorAll('[data-ek-list] li.ek-srow')].map(l => l.dataset.key));
    log[`dnd-before-${w}`] = await order();
    const from = await page.$('li.ek-srow[data-key="items"] [data-ek-drag]');
    const to = await page.$('li.ek-srow[data-key="chip"]');
    const fb = await from.boundingBox(), tb = await to.boundingBox();   // the TEST measures; the page does not
    await page.mouse.move(fb.x + fb.width / 2, fb.y + fb.height / 2);
    await page.mouse.down();
    await page.mouse.move(fb.x + 40, fb.y - 10, { steps: 6 });
    await page.mouse.move(tb.x + 60, tb.y + tb.height / 2, { steps: 12 });
    await page.screenshot({ path: `${OUT}/e4-drag-during-${w}.png`, fullPage: false });
    await page.mouse.up();
    await page.waitForTimeout(2500);
    log[`dnd-after-${w}`] = await order();
    await page.screenshot({ path: `${OUT}/e4-drag-after-${w}.png`, fullPage: true });

    // Keyboard alternative: the ↓ button on Help box.
    await page.click('li.ek-srow[data-key="help"] [data-ek-up]');
    await page.waitForTimeout(300);
    log[`kbd-after-${w}`] = await order();

    // Wording tab and the Arabic tab.
    await page.click('[data-kbt="ek-editor"] [data-kbt-tab="en"]');
    await page.fill('[data-ek-word="en.heading"]', 'Packed and on its way 🚚');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: `${OUT}/e4-wording-en-${w}.png`, fullPage: true });
    await page.click('[data-kbt="ek-editor"] [data-kbt-tab="ar"]');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: `${OUT}/e4-wording-ar-${w}.png`, fullPage: true });
    if (w < 500) {
      await page.click('[data-kbt="ek-editor"] [data-kbt-tab="preview"]');
      await page.waitForTimeout(2200);
      await page.screenshot({ path: `${OUT}/e4-preview-tab-${w}.png`, fullPage: true });
    }
    // Add a section.
    await page.click('[data-kbt="ek-editor"] [data-kbt-tab="sections"]');
    await page.click('[data-ek-addmenu]');
    await page.click('[data-ek-add="text"]');
    await page.fill('[data-ek-bf="text"]', 'Your order is **gift-wrapped** — see the [care guide](/blog/).');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: `${OUT}/e4-add-section-${w}.png`, fullPage: true });
    await page.click('[data-ek-ready]');
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/e4-ready-template-${w}.png`, fullPage: true });
    await ctx.close();
  }
  require('fs').writeFileSync(`${OUT}/${BEFORE ? 'before-' : ''}measurements.json`, JSON.stringify(log, null, 2));
  console.log(JSON.stringify(log, null, 1).slice(0, 3000));
  await b.close();
})();
