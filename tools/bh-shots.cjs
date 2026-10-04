// Lane BH: the brand page header, before/after, the ring, the quick edit's
// Logo field and Catalog -> Brands -> Edit -> Ring colour.
// node tools/bh-shots.cjs <before|after|admin> [port]
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MODE = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 9960}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-bh-shots');

async function measure(page) {
  return page.evaluate(() => {
    const hero = document.querySelector('.brw-hero');
    const r = (el) => (el ? Math.round(el.getBoundingClientRect().height * 10) / 10 : null);
    const logo = document.querySelector('.brw-hero .brw-logo--lg');
    const h1 = document.querySelector('.brw-hero .brw-h1');
    return {
      heroHeight: r(hero),
      logo: logo ? Math.round(logo.getBoundingClientRect().width) : null,
      logoTop: logo ? Math.round(logo.getBoundingClientRect().top) : null,
      h1Top: h1 ? Math.round(h1.getBoundingClientRect().top) : null,
      h1Font: h1 ? getComputedStyle(h1).fontSize : null,
      ring: logo ? getComputedStyle(logo).boxShadow : null,
      firstProductTop: document.querySelector('.kbb-pgrid') ? Math.round(document.querySelector('.kbb-pgrid').getBoundingClientRect().top + scrollY) : null,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const out = {};
  if (MODE === 'before' || MODE === 'after') {
    const slugs = MODE === 'before' ? ['anua'] : ['anua', 'cosrx', 'round-lab'];
    const paths = slugs.map((s) => `/brands/${s}/`);
    if (MODE === 'after') paths.push('/ar/brands/anua/');
    for (const p of paths) {
      for (const w of [390, 1280]) {
        const page = await browser.newPage({ viewport: { width: w, height: w === 390 ? 844 : 800 } });
        const res = await page.goto(BASE + p, { waitUntil: 'networkidle' });
        if (!res || res.status() !== 200) { out[`${p}@${w}`] = { status: res && res.status() }; await page.close(); continue; }
        const name = `${MODE}-${p.replace(/^\/|\/$/g, '').replace(/\//g, '-')}-${w}.png`;
        out[name] = await measure(page);
        await page.screenshot({ path: path.join(OUT, name), clip: { x: 0, y: 0, width: w, height: w === 390 ? 700 : 560 } });
        await page.close();
      }
    }
  } else {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    for (const w of [1280, 390]) {
      await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
      await page.goto(`${BASE}/brands/round-lab/`, { waitUntil: 'networkidle' });
      await page.waitForSelector('.kbb-qe-pill');
      await page.screenshot({ path: path.join(OUT, `qe-pill-round-lab-${w}.png`), clip: { x: 0, y: 0, width: w, height: 420 } });
      await page.click('.kbb-qe-pill');
      await page.waitForSelector('.kbb-qe__drop--logo');
      await page.waitForTimeout(600);
      await page.screenshot({ path: path.join(OUT, `qe-dialog-logo-field-before-${w}.png`) });
      if (w === 1280) {
        const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('.kbb-qe__drop--logo')]);
        await chooser.setFiles(path.join(__dirname, '..', 'storage', 'bh-prev', 'bh-roundlab-logo.png'));
        await page.waitForFunction(() => document.querySelector('.kbb-qe__logo-pv img'), null, { timeout: 15000 });
        await page.waitForTimeout(400);
        out.qeLogoPreview = await page.evaluate(() => ({ html: document.querySelector('.kbb-qe__logo-pv').innerHTML.slice(0, 300) }));
        await page.screenshot({ path: path.join(OUT, `qe-dialog-logo-uploaded-${w}.png`) });
        await page.click('.kbb-qe__btn--go');
        await page.waitForSelector('.kbb-qe', { state: 'detached' });
        await page.waitForTimeout(500);
        out.afterSave = await measure(page);
        out.afterSaveLogo = await page.evaluate(() => document.querySelector('.brw-hero .brw-logo--lg').outerHTML.slice(0, 300));
        await page.screenshot({ path: path.join(OUT, `qe-saved-in-place-${w}.png`), clip: { x: 0, y: 0, width: w, height: 420 } });
      } else {
        await page.click('.kbb-qe__btn:not(.kbb-qe__btn--go)');
      }
    }

    await page.setViewportSize({ width: 1280, height: 900 });
    const id = await page.evaluate(async (b) => (await (await fetch(`${b}/admin-api/brands`, { headers: { Accept: 'application/json' } })).json()).brands.find((x) => x.slug === 'anua').id, BASE);
    for (const w of [1280, 390]) {
      await page.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
      await page.goto(`${BASE}/admin?kbb-open=brand:${id}#catalog/brands`, { waitUntil: 'networkidle' });
      await page.waitForSelector('#bz-ring', { timeout: 15000 });
      await page.evaluate(() => document.getElementById('bz-ring').scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, `admin-brand-ring-colour-${w}.png`) });
      out[`adminRing${w}`] = await page.evaluate(() => ({
        placeholder: document.getElementById('bz-ring').placeholder,
        picker: document.getElementById('bz-ring-pick').value,
        note: document.getElementById('bz-ring').closest('.bz-fld').querySelector('.bz-note').textContent,
        contentScrollWidth: (document.getElementById('content') || document.documentElement).scrollWidth,
        contentWidth: (document.getElementById('content') || document.documentElement).clientWidth,
      }));
    }
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
