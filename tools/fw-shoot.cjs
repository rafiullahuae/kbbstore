// Lane FW screenshots. Run from the worktree root against tools/fw-preview.sh:
//   node tools/fw-shoot.cjs <phase> [port]     phase: before | after | panel
// Writes docs/fw-shots/<phase>-*.png and appends the measured numbers to
// docs/fw-shots/numbers.txt. getBoundingClientRect here is the HARNESS measuring
// the page; the shop and the panel measure nothing.
//
// A REAL SCROLLBAR: Playwright starts headless Chromium with --hide-scrollbars,
// under which 100vw and the layout width are the same number and a bleed built
// on 100vw would pass here and scroll sideways on every Windows laptop. The
// flag is dropped, so innerWidth (1280) and clientWidth (1280 - the bar) differ
// and scrollWidth is measured against the narrower one.
const { chromium } = require('playwright');
const fs = require('fs');
const phase = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10480}`;
const OUT = `${__dirname}/../docs/fw-shots`;
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const measure = () => {
  const img = document.querySelector('[data-kbb-ph] > picture img');
  const b = img ? img.getBoundingClientRect() : null;
  const r1 = (n) => Math.round(n * 10) / 10;
  return {
    innerWidth: window.innerWidth,
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    picLeft: b ? r1(b.left) : null,
    picRight: b ? r1(b.right) : null,
    picWidth: b ? r1(b.width) : null,
    picHeight: b ? r1(b.height) : null,
    radius: img ? getComputedStyle(img).borderTopLeftRadius : null,
    wrap: (document.querySelector('[data-kbb-ph]') || {}).className || null,
  };
};

const lines = [];
const log = async (page, tag, w) => {
  const m = await page.evaluate(measure);
  const line = `${phase} ${tag} @${w}: ${JSON.stringify(m)}`;
  console.log(line);
  lines.push(line);
};

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function openPanel(page) {
  await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
  await page.waitForSelector('button.kbb-qe-pill:has-text("Edit header")', { timeout: 15000 });
  await page.click('button.kbb-qe-pill:has-text("Edit header")');
  await page.waitForSelector('.kbb-phe', { timeout: 15000 });
  await page.waitForTimeout(300);
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', ignoreDefaultArgs: ['--hide-scrollbars'] });
  const widths = phase === 'panel' ? [390, 1280] : [390, 1280, 1920];
  for (const w of widths) {
    const phone = w < 500;
    const ctx = await b.newContext({ viewport: { width: w, height: phone ? 844 : 900 }, deviceScaleFactor: phone ? 2 : 1, isMobile: phone, hasTouch: phone, userAgent: phone ? UA : UA_D });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('pageerror', String(e)));
    if (phase === 'before' || phase === 'after') {
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      await log(page, 'page', w);
      await page.screenshot({ path: `${OUT}/${phase}-${w}.png` });
    } else if (phase === 'panel') {
      require('child_process').execSync(`sh ${__dirname}/fw-reset.sh`);
      await login(page);
      await openPanel(page);
      // The panel opens on the device being looked at's own half.
      if (!phone) await page.click('.kbb-phe-seg[aria-label=Device] button:has-text("Desktop")');
      else await page.click('.kbb-phe-seg[aria-label=Device] button:has-text("Phone")');
      await page.locator('.kbb-phe-seg[aria-label="Picture width"]').scrollIntoViewIfNeeded();
      await page.waitForTimeout(200);
      await log(page, 'panel-default', w);
      await page.screenshot({ path: `${OUT}/panel-default-${w}.png` });
      // Flip this device to the other choice, live.
      const to = phone ? 'Normal' : 'Full width';
      await page.click(`.kbb-phe-seg[aria-label="Picture width"] button:has-text("${to}")`);
      await page.waitForTimeout(250);
      await log(page, `panel-live-${to.replace(' ', '-').toLowerCase()}`, w);
      await page.screenshot({ path: `${OUT}/panel-${to.replace(' ', '-').toLowerCase()}-${w}.png` });
      if (!phone) {
        // Save desktop = Full width for this page and read it back as a shopper sees it.
        await page.click('.kbb-phe-foot button:has-text("Save")');
        await page.waitForSelector('.kbb-phe', { state: 'detached', timeout: 15000 });
        for (const ww of [1280, 1920]) {
          const c2 = await b.newContext({ viewport: { width: ww, height: 900 }, userAgent: UA_D });
          const p2 = await c2.newPage();
          await p2.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
          await log(p2, 'saved-desktop-full', ww);
          await p2.screenshot({ path: `${OUT}/saved-desktop-full-${ww}.png` });
          await c2.close();
        }
      }
    }
    await ctx.close();
  }
  await b.close();
  fs.appendFileSync(`${OUT}/numbers.txt`, lines.join('\n') + '\n');
})();
