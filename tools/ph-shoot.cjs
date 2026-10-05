// Lane PH screenshots. Run from the worktree root against tools/ph-preview.sh:
//   node tools/ph-shoot.cjs before|after|editor|admin [port]
// Writes docs/ph-shots/<phase>-<width>.png and prints the measured numbers.
// getBoundingClientRect here is the HARNESS measuring the page; the shop and
// the panel measure nothing.
const { chromium } = require('playwright');
const phase = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10130}`;
const OUT = `${__dirname}/../docs/ph-shots`;

const measure = () => {
  const h = (el) => (el ? Math.round(el.getBoundingClientRect().height * 10) / 10 : null);
  const shown = (el) => !!el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 1;
  const head = document.querySelector('.kbb-home [data-kbb-ph]') || document.querySelector('.kbb-home .sec > .wrap > .sh');
  const h1 = document.querySelector('.kbb-home .sec h1');
  const strip = document.querySelector('.kbb-pb-strip');
  return {
    innerWidth: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    headerHeight: h(head),
    imageHeight: h(document.querySelector('.kbb-ph-i img')),
    stripHeight: h(strip),
    stripItemsVisible: strip ? [...strip.querySelectorAll('li')].filter(shown).map((li) => li.textContent.trim()) : [],
    h1Text: h1 ? h1.textContent.replace(/\s+/g, ' ').trim() : null,
    titleVisible: !!h1 && h1.getBoundingClientRect().width > 2,
    titleFontSize: h1 ? getComputedStyle(h1).fontSize : null,
    dot: h1 ? getComputedStyle(h1, '::before').content : null,
    countVisible: shown(document.querySelector('.kbb-home .sec h1 .cnt')),
    allProductsVisible: shown(document.querySelector('.kbb-home .sec .lnk')),
  };
};

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('pageerror', String(e)));
    if (phase === 'before' || phase === 'after') {
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      console.log(phase, w, JSON.stringify(await page.evaluate(measure)));
      await page.screenshot({ path: `${OUT}/${phase}-${w}.png` });
    } else if (phase === 'editor') {
      // Each width starts from the shipped state (pass a reset command as the 4th argument).
      if (process.argv[4]) require('child_process').execSync(process.argv[4]);
      await login(page);
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      await page.waitForSelector('button.kbb-qe-pill:has-text("Edit header")', { timeout: 15000 });
      await page.screenshot({ path: `${OUT}/editor-button-${w}.png` });
      await page.click('button.kbb-qe-pill:has-text("Edit header")');
      await page.waitForSelector('.kbb-phe', { timeout: 15000 });
      await page.waitForTimeout(300);
      console.log('editor-open', w, JSON.stringify(await page.evaluate(measure)));
      await page.screenshot({ path: `${OUT}/editor-open-${w}.png` });
      // Choose a picture from the Media Library, live.
      await page.click('.kbb-phe .kbb-phe-pic button:has-text("Choose")');
      await page.waitForSelector('.kbb-phe-grid button', { timeout: 15000 });
      await page.screenshot({ path: `${OUT}/editor-library-${w}.png` });
      await page.click('.kbb-phe-grid button >> nth=1');
      await page.waitForTimeout(500);
      // Centre it, and move the picture above the title row.
      await page.click('.kbb-phe-seg button:has-text("Centre")');
      await page.waitForTimeout(300);
      console.log('editor-live', w, JSON.stringify(await page.evaluate(measure)));
      await page.screenshot({ path: `${OUT}/editor-live-${w}.png` });
      // Save for this page, and read the page again from the server.
      await page.click('.kbb-phe-foot button:has-text("Save")');
      await page.waitForSelector('.kbb-phe', { state: 'detached', timeout: 15000 });
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      console.log('saved', w, JSON.stringify(await page.evaluate(measure)));
      await page.screenshot({ path: `${OUT}/editor-saved-${w}.png` });
    } else if (phase === 'admin') {
      await login(page);
      await page.evaluate(() => window.go('pageheader'));
      await page.waitForSelector('.phs-stage', { timeout: 20000 });
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/admin-global-${w}.png`, fullPage: true });
      await page.click('.phs-list button:has-text("Super Sale")');
      await page.waitForSelector('.phs-stage', { timeout: 20000 });
      console.log('admin', w, JSON.stringify(await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, scopes: [...document.querySelectorAll('.phs-list button')].map((b) => b.textContent) }))));
      await page.screenshot({ path: `${OUT}/admin-supersale-${w}.png`, fullPage: true });
      await page.evaluate(() => window.go('pagebanners'));
      await page.waitForSelector('[data-pbs-idev]', { timeout: 20000 });
      await page.waitForTimeout(300);
      console.log('banners', w, JSON.stringify(await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, devs: [...document.querySelectorAll('[data-pbs-idev]')].map((s) => s.value) }))));
      await page.screenshot({ path: `${OUT}/admin-strip-${w}.png`, fullPage: true });
    }
    await ctx.close();
  }
  await b.close();
})();
