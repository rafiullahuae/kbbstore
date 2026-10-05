/*
 * Lane BS screenshots: the homepage Brands section per device and per phone
 * look, with the brand pictures each width downloads, and the Brands popup.
 *
 *   node tools/bs-shots.cjs PORT shop <label>          the section at 390 + 1280
 *   node tools/bs-shots.cjs PORT pick                  choose 4 images + 8 brands in the popup, save
 *   node tools/bs-shots.cjs PORT look <text|image|logo_image>   set Look · phone in the popup, save
 *
 * Against storage/bs-logs/bs-preview.sh. It DRIVES the popup (selects, the
 * Media Library, Save section) rather than writing settings, so a control
 * that is drawn and does nothing shows up as a section that did not move.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const PORT = process.argv[2] || 10150;
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'bs-shots');
const stage = process.argv[3] || 'shop';
const arg = process.argv[4] || '';

async function login(b, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('pageerror', String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function openBrands(page, api) {
  await page.evaluate(() => window.go('hpcontent'));
  await page.waitForSelector('[data-hph-edit="brands"]', { timeout: 20000 });
  const before = api.length;
  await page.click('[data-hph-edit="brands"]');
  await page.waitForSelector('[data-hph-tab="products"]');
  await page.waitForTimeout(300);
  return api.length - before;
}

async function save(page) {
  await page.click('[data-hph-save]');
  await page.waitForFunction(() => /Saved/.test((document.getElementById('hph-msg') || {}).textContent || ''), null, { timeout: 20000 });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  if (stage === 'shop') {
    for (const w of [390, 1280]) {
      const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 } });
      const page = await ctx.newPage();
      const pics = [];
      page.on('request', (r) => { if (/\/uploads\/bs\//.test(r.url())) pics.push(r.url().split('/').pop()); });
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
      // For the picture only: the sticky header and the WhatsApp bubble sit
      // over a tall section when it is scrolled into view. No request.
      await page.addStyleTag({ content: '.kbb-home header{position:static!important}[class^="kbw"],[class*=" kbw"]{display:none!important}' });
      await page.locator('section.hs-brands').scrollIntoViewIfNeeded();
      await page.waitForTimeout(900);
      await page.waitForLoadState('networkidle');
      const m = await page.evaluate(() => {
        const sec = document.querySelector('section.hs-brands');
        const shown = [...sec.querySelectorAll('.hs-brand')].filter((a) => a.getBoundingClientRect().width > 0);
        const r = shown[0].getBoundingClientRect();
        const lb = shown[0].querySelector('.hs-blb b');
        return {
          cls: sec.className.split(' ').filter((c) => /^hs-brm-/.test(c)).join(' '),
          scrollWidth: document.documentElement.scrollWidth,
          cards: shown.length,
          card: Math.round(r.width) + 'x' + Math.round(r.height),
          nameFont: lb && lb.getBoundingClientRect().width > 0 ? getComputedStyle(lb).fontSize : 'hidden',
          section: Math.round(sec.getBoundingClientRect().height),
        };
      });
      const kinds = { banner: 0, card: 0, logo: 0 };
      pics.forEach((p) => { kinds[p.split('-')[0]] = (kinds[p.split('-')[0]] || 0) + 1; });
      console.log(`${arg} ${w}: ${JSON.stringify(m)} brandPictureRequests=${pics.length} ${JSON.stringify(kinds)}`);
      // Sticky and fixed layers (the phone header, the chat bubble) would sit
      // over a section taller than the screen; hidden for the picture only.
      await page.evaluate(() => document.querySelectorAll('body *').forEach((e) => {
        const p = getComputedStyle(e).position;
        if (p === 'sticky' || p === 'fixed') e.style.setProperty('visibility', 'hidden', 'important');
      }));
      await page.locator('section.hs-brands').screenshot({ path: `${OUT}/${arg}-${w}.png` });
      await ctx.close();
    }
  }

  if (stage === 'pick') {
    const { page } = await login(b, 1280);
    const api = [];
    page.on('request', (r) => { if (/\/admin-api\//.test(r.url())) api.push(r.method() + ' ' + r.url().replace(BASE, '')); });
    const opened = await openBrands(page, api);
    await page.click('[data-hph-tab="products"]');
    // Eight brands, in an order of his choosing.
    for (const name of ['Anua', 'COSRX', 'Beauty of Joseon', 'Round Lab', 'Torriden', 'Skin1004', 'Mixsoon', 'Medicube']) {
      const sel = page.locator('[data-hph-chipadd="f:home_br_picks"]');
      const val = await sel.evaluate((s, n) => [...s.options].find((o) => o.textContent === n).value, name);
      await sel.selectOption(val);
      await page.waitForTimeout(80);
    }
    const beforePick = api.length;
    for (let i = 0; i < 4; i++) {
      await page.locator('[data-hph-pimg]').nth(i).click();
      await page.waitForSelector('[data-mp-url]', { timeout: 15000 });
      await page.locator('[data-mp-url]').nth(i).click();
      await page.click('#mp-ok');
      await page.waitForTimeout(250);
    }
    const pickCalls = api.slice(beforePick);
    const rows = await page.$$eval('.hph-pick.is-img', (l) => l.map((x) => x.querySelector('b').textContent + (x.querySelector('img.hph-th') ? ' [img]' : '')));
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    await page.screenshot({ path: `${OUT}/popup-images-1280.png` });
    const beforeSave = api.length;
    await save(page);
    console.log('popup open calls', opened, 'pick calls', JSON.stringify(pickCalls), 'save calls', JSON.stringify(api.slice(beforeSave)), 'rows', JSON.stringify(rows), 'scrollWidth', sw);
    await page.click('[data-hph-tab="layout"]');
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/popup-layout-1280.png` });
    // The same popup on a phone.
    await page.setViewportSize({ width: 390, height: 844 });
    await page.click('[data-hph-tab="products"]');
    await page.waitForTimeout(200);
    console.log('popup 390 scrollWidth', await page.evaluate(() => document.documentElement.scrollWidth),
      'dialog', await page.evaluate(() => Math.round(document.querySelector('.hph-dlg').getBoundingClientRect().width)));
    await page.screenshot({ path: `${OUT}/popup-images-390.png` });
  }

  if (stage === 'look') {
    const { page } = await login(b, 1280);
    const api = [];
    page.on('request', (r) => { if (/\/admin-api\//.test(r.url())) api.push(r.method() + ' ' + r.url().replace(BASE, '')); });
    await openBrands(page, api);
    await page.click('[data-hph-tab="layout"]');
    await page.selectOption('[data-hph-k="home_br_layout_m"]', arg);
    await save(page);
    console.log('look', arg, 'saved; value now', await page.$eval('[data-hph-k="home_br_layout_m"]', (s) => s.value));
  }

  await b.close();
})();
