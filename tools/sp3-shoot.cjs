// Lane SP3 screenshots. Run from the worktree root against tools/sp3-preview.sh:
//   node tools/sp3-shoot.cjs <phase> [port]
// Writes docs/sp-shots/<phase>-<width>.png and prints the measured numbers.
// getBoundingClientRect here is the HARNESS measuring the page; the shop and
// the panel measure nothing.
const { chromium } = require('playwright');
const phase = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10180}`;
const OUT = `${__dirname}/../docs/sp-shots`;
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

const measure = () => {
  const r = (s) => { const e = document.querySelector(s); if (!e || getComputedStyle(e).display === 'none') return null; const b = e.getBoundingClientRect(); return { top: Math.round(b.top * 10) / 10, bottom: Math.round(b.bottom * 10) / 10 }; };
  const header = document.querySelector('body > header, header.hd-sticky, header');
  const hb = header ? Math.round(header.getBoundingClientRect().bottom * 10) / 10 : 0;
  const strip = r('.kbb-pb-strip');
  const ph = r('[data-kbb-ph] > picture') || r('[data-kbb-ph]');
  const grid = r('.kbb-pgrid');
  const first = [strip, ph].filter(Boolean).sort((a, b) => a.top - b.top)[0] || null;
  const last = [strip, ph].filter(Boolean).sort((a, b) => b.bottom - a.bottom)[0] || null;
  return {
    innerWidth: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    siteHeaderBottom: hb,
    strip: !!strip,
    firstBlock: first === strip && strip ? 'strip' : (ph ? 'header' : null),
    gapSiteHeaderToFirstBlock: first ? Math.round((first.top - hb) * 10) / 10 : null,
    gapBetweenBlocks: strip && ph ? Math.round(((strip.top < ph.top) ? ph.top - strip.bottom : strip.top - ph.bottom) * 10) / 10 : null,
    gapLastBlockToProducts: last && grid ? Math.round((grid.top - last.bottom) * 10) / 10 : null,
  };
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

const log = async (page, tag, w) => console.log(tag, w, JSON.stringify(await page.evaluate(measure)));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2, userAgent: w > 500 ? UA_D : UA });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('pageerror', String(e)));
    // Each width starts from the seeded state: strip off, the header as seeded.
    if (phase === 'panel' || phase === 'admin') require('child_process').execSync(`sh ${__dirname}/sp3-reset.sh`);
    if (phase === 'before' || phase === 'after') {
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      await log(page, phase, w);
      await page.screenshot({ path: `${OUT}/${phase}-${w}.png` });
    } else if (phase === 'panel') {
      await login(page);
      await openPanel(page);
      // The Strip section, scrolled into view, then switched ON: the strip appears live.
      await page.locator('.kbb-phe-strip').scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/panel-strip-off-${w}.png` });
      await page.click('.kbb-phe-strip input[type=checkbox]');
      await page.waitForTimeout(200);
      await log(page, 'strip-on-live', w);
      await page.screenshot({ path: `${OUT}/panel-strip-on-${w}.png` });
      // Drag reorder: header area above the strip, by dragging the strip row down.
      await page.locator('.kbb-phe-blocks').scrollIntoViewIfNeeded();
      await log(page, 'drag-before', w);
      await page.screenshot({ path: `${OUT}/drag-before-${w}.png` });
      await page.dragAndDrop('.kbb-phe-blocks li[data-block=strip]', '.kbb-phe-blocks li[data-block=header]');
      await page.waitForTimeout(200);
      await log(page, 'drag-after', w);
      await page.screenshot({ path: `${OUT}/drag-after-${w}.png` });
      // Spacing at 0 (the default) and moved.
      await page.locator('.kbb-phe-space').scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/spacing-0-${w}.png` });
      for (const [k, v] of [['top', 24], ['mid', 16]]) {
        await page.$eval(`.kbb-phe-space input[data-k=${k}]`, (el, v) => { el.value = String(v); el.dispatchEvent(new Event('input', { bubbles: true })); }, v);
      }
      await page.waitForTimeout(200);
      await log(page, 'spacing-moved', w);
      await page.screenshot({ path: `${OUT}/spacing-moved-${w}.png` });
      // Save for this page and read it back from the server.
      await page.click('.kbb-phe-foot button:has-text("Save")');
      await page.waitForSelector('.kbb-phe', { state: 'detached', timeout: 15000 });
      await page.goto(`${BASE}/super-sale/`, { waitUntil: 'networkidle' });
      await log(page, 'saved', w);
      await page.screenshot({ path: `${OUT}/saved-${w}.png` });
    } else if (phase === 'admin') {
      // Pages -> Page header: the same Order and Spacing sections, from the console.
      await login(page);
      await page.evaluate(() => window.go('pageheader'));
      await page.waitForSelector('.phs-stage', { timeout: 20000 });
      await page.waitForSelector('.kbb-phe-space', { timeout: 20000 });
      await page.locator('.kbb-phe-blocks').first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      console.log('admin', w, JSON.stringify(await page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, spacing: [...document.querySelectorAll('.kbb-phe-space input')].map((i) => i.dataset.k + '=' + i.value), blocks: [...document.querySelectorAll('.kbb-phe-blocks li')].map((l) => l.dataset.block) }))));
      await page.screenshot({ path: `${OUT}/admin-pageheader-${w}.png` });
    }
    await ctx.close();
  }
  await b.close();
})();
