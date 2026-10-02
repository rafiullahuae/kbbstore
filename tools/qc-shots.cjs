/*
 * Lane QC evidence: the category header's designs as tiles, phone and laptop
 * apart, the live preview, fine-tuning, and the per-category editor with an
 * uploaded banner.
 *
 *   sh tools/qc-preview.sh 9950
 *   QC_BASE=http://127.0.0.1:9950 node tools/qc-shots.cjs
 *
 * Writes docs/qc-shots/*.png, docs/qc-shots/measurements.json and
 * docs/qc-shots/overview.png. getBoundingClientRect / getComputedStyle are
 * this HARNESS's instruments, never shipped to anyone.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.QC_BASE || 'http://127.0.0.1:9950';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/qc-shots');
const ROOT = path.resolve(APP, 'storage/framework/testing/lane-qc-after/webroot');
const WIDTHS = [390, 1280];
const result = {};

const ctxFor = (browser, width, height) => browser.newContext({
    viewport: { width, height: height || (width < 600 ? 844 : 900) },
    deviceScaleFactor: 1,
});

function watch(page, bag) {
    page.on('pageerror', (e) => bag.push('pageerror: ' + String(e)));
    page.on('console', (m) => { if (m.type() === 'error') bag.push('console: ' + m.text()); });
    page.on('response', (r) => { if (r.status() >= 400 && !/favicon/.test(r.url())) bag.push(r.status() + ' ' + r.url()); });
}

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
}

const widths = () => {
    const c = document.querySelector('#content');
    return {
        docScrollWidth: document.documentElement.scrollWidth,
        docClientWidth: document.documentElement.clientWidth,
        contentScrollWidth: c ? c.scrollWidth : null,
        contentClientWidth: c ? c.clientWidth : null,
    };
};

const liveState = () => {
    const s = document.querySelector('[data-sls-live] .kbb-th') || document.querySelector('[data-ct-hdrlive] .kbb-th');
    if (!s) return null;
    const r = s.getBoundingClientRect();
    const t = s.querySelector('.kbb-th__title');
    return {
        classes: s.className,
        style: s.getAttribute('style'),
        w: Math.round(r.width), h: Math.round(r.height),
        titleSize: t ? getComputedStyle(t).fontSize : null,
        bg: getComputedStyle(s).backgroundColor,
        icons: s.querySelector('.kbb-th__icons') ? getComputedStyle(s.querySelector('.kbb-th__icons')).backgroundColor : null,
    };
};

async function openSiteLayout(page) {
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForTimeout(1500);
    await page.locator('[data-sls-tab="catheader"]').click();
    await page.waitForTimeout(1200);
}

async function adminLayout(browser, width) {
    const errors = [];
    const r = {};

    /* 1. The whole tab, Phone mode then Laptop mode -- a tall window, so the
          whole column is in one picture. */
    let ctx = await ctxFor(browser, width, width < 600 ? 7600 : 5200);
    let page = await ctx.newPage();
    watch(page, errors);
    await signIn(page);
    await openSiteLayout(page);
    await page.screenshot({ path: `${OUT}/admin-catheader-phone-full-${width}.png` });
    r.full = await page.evaluate(() => ({
        groups: [...document.querySelectorAll('[role=radiogroup]')].map((g) => g.getAttribute('data-thk-group') + ':' + g.querySelectorAll('[role=radio]').length),
        checked: [...document.querySelectorAll('[role=radiogroup]')].map((g) => (g.querySelector('[aria-checked=true]') || {}).getAttribute('aria-label')),
        tabStops: [...document.querySelectorAll('[role=radio][tabindex="0"]')].length,
    }));
    Object.assign(r.full, await page.evaluate(widths));
    await page.locator('[data-sls-pv="laptop"]').first().click();
    await page.waitForTimeout(600);
    await page.screenshot({ path: `${OUT}/admin-catheader-laptop-full-${width}.png` });
    r.laptopMode = await page.evaluate(liveState);
    await page.locator('[data-sls-tab="catheadersize"]').click();
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/admin-catheadersize-full-${width}.png` });
    await ctx.close();

    /* 2. Choosing B, then tuning its icon colour, at an ordinary window: the
          tiles and the live preview on screen together. */
    ctx = await ctxFor(browser, width);
    page = await ctx.newPage();
    watch(page, errors);
    await signIn(page);
    await openSiteLayout(page);
    await page.selectOption('[data-sls-pvwith]', 'box');
    await page.waitForTimeout(500);
    const boxTiles = page.locator('[data-sls-tiles="box"]');
    await page.evaluate(() => {
        const t = document.querySelector('[data-sls-tiles="box"]');
        const c = document.querySelector('#content');
        const live = document.querySelector('[data-sls-live]');
        const stuck = innerWidth < 1100 && live ? live.getBoundingClientRect().height : 0;
        c.scrollTop += t.getBoundingClientRect().top - c.getBoundingClientRect().top - stuck - 12;
    });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/pick-1-before-${width}.png` });
    r.before = await page.evaluate(liveState);

    await page.locator('[data-sls-tiles="box"] [data-thk-value="cream"]').click();
    await page.waitForTimeout(500);
    await page.evaluate(() => {
        const t = document.querySelector('[data-sls-tiles="box"]');
        const c = document.querySelector('#content');
        const live = document.querySelector('[data-sls-live]');
        const stuck = innerWidth < 1100 && live ? live.getBoundingClientRect().height : 0;
        c.scrollTop += t.getBoundingClientRect().top - c.getBoundingClientRect().top - stuck - 12;
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/pick-2-chose-B-${width}.png` });
    r.afterB = await page.evaluate(liveState);
    r.afterBChecked = await page.evaluate(() => (document.querySelector('[data-sls-tiles="box"] [aria-checked=true]') || {}).getAttribute('aria-label'));

    // Tune B's icon colour: the box under the tiles.
    const ic = page.locator('#sls-cat_header_cream_ic');
    await ic.fill('#8A4B2A');
    await ic.dispatchEvent('input');
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const t = document.querySelector('[data-sls-tune="cream"]');
        const c = document.querySelector('#content');
        const live = document.querySelector('[data-sls-live]');
        const stuck = innerWidth < 1100 && live ? live.getBoundingClientRect().height : 0;
        c.scrollTop += t.getBoundingClientRect().top - c.getBoundingClientRect().top - stuck - 12;
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/pick-3-tuned-B-icons-${width}.png` });
    r.tunedB = await page.evaluate(liveState);

    // The keyboard: focus the chosen tile, ArrowRight moves to C and chooses it.
    await page.locator('[data-sls-tiles="box"] [aria-checked=true]').focus();
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(400);
    r.keyboard = await page.evaluate(() => ({
        checked: (document.querySelector('[data-sls-tiles="box"] [aria-checked=true]') || {}).getAttribute('aria-label'),
        focused: document.activeElement && document.activeElement.getAttribute('aria-label'),
        live: (document.querySelector('[data-sls-live] .kbb-th') || {}).className,
    }));
    await page.keyboard.press('End');
    await page.waitForTimeout(300);
    await page.keyboard.press('Home');
    await page.waitForTimeout(300);
    r.keyboardHome = await page.evaluate(() => (document.querySelector('[data-sls-tiles="box"] [aria-checked=true]') || {}).getAttribute('aria-label'));

    // A treatment tile, on a picture: 3 Frosted panel.
    await page.selectOption('[data-sls-pvwith]', 'dark');
    await page.locator('[data-sls-tiles="treat-img"] [data-thk-value="frost"]').click();
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const t = document.querySelector('[data-sls-tiles="treat-img"]');
        const c = document.querySelector('#content');
        const live = document.querySelector('[data-sls-live]');
        const stuck = innerWidth < 1100 && live ? live.getBoundingClientRect().height : 0;
        c.scrollTop += t.getBoundingClientRect().top - c.getBoundingClientRect().top - stuck - 12;
    });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/pick-4-chose-3-frosted-${width}.png` });
    r.afterFrost = await page.evaluate(liveState);

    // Preview with one of his categories: its real banner and words.
    const catValue = await page.evaluate(() => {
        const o = [...document.querySelectorAll('[data-sls-pvwith] option')].find((x) => /Toners/.test(x.textContent));
        return o ? o.value : null;
    });
    if (catValue) {
        await page.selectOption('[data-sls-pvwith]', catValue);
        await page.waitForTimeout(600);
        await page.evaluate(() => { document.querySelector('#content').scrollTop = 0; });
        await page.waitForTimeout(200);
        await page.locator('[data-sls-live]').screenshot({ path: `${OUT}/preview-with-category-${width}.png` });
        r.previewWith = { value: catValue, live: await page.evaluate(liveState) };
    }
    // No description: the generic line.
    await page.selectOption('[data-sls-pvwith]', 'nodesc');
    await page.waitForTimeout(400);
    await page.locator('[data-sls-live]').screenshot({ path: `${OUT}/preview-generic-line-${width}.png` });
    r.generic = await page.evaluate(() => (document.querySelector('[data-sls-live] .kbb-th__desc') || {}).textContent);

    Object.assign(r, { widths: await page.evaluate(widths) });
    r.errors = errors;
    result[`admin-sitelayout-${width}`] = r;
    await ctx.close();
}

async function adminEditor(browser, width) {
    const errors = [];
    const r = {};
    const ctx = await ctxFor(browser, width, 2600);
    const page = await ctx.newPage();
    watch(page, errors);
    await signIn(page);
    await page.evaluate(() => window.go('category-tree'));
    await page.waitForTimeout(1800);
    const id = await page.evaluate(() => {
        const row = [...document.querySelectorAll('.ct-row')].find((x) => (x.querySelector('.ct-path') || {}).innerText === '/collections/lip-care/');
        return row ? row.dataset.id : null;
    });
    await page.locator(`[data-edit="${id}"]`).click();
    await page.waitForTimeout(1200);
    await page.evaluate(() => document.querySelector('#ct-hdr').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await page.locator('#ct-hdr').screenshot({ path: `${OUT}/editor-1-no-banner-${width}.png` });
    r.before = await page.evaluate(liveState);

    // Upload banner: the Media Library opens straight onto the file chooser.
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser', { timeout: 8000 }),
        page.locator('#ct-hdrup').click(),
    ]);
    await chooser.setFiles(path.join(ROOT, 'uploads/qc/banner-2400x600.jpg'));
    await page.waitForFunction(() => /\/uploads\//.test((document.getElementById('ct-hdrimg') || {}).value || ''), null, { timeout: 20000 });
    await page.waitForTimeout(900);
    r.uploaded = await page.evaluate(() => ({
        address: document.getElementById('ct-hdrimg').value,
        pickerOpen: !!document.querySelector('.mp-back:not([hidden])'),
        state: document.getElementById('ct-hdrupstate').textContent,
        thumb: (() => { const i = document.getElementById('ct-hdrthumb'); return i ? [i.naturalWidth, i.naturalHeight] : null; })(),
    }));
    await page.evaluate(() => document.querySelector('#ct-hdr').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await page.locator('#ct-hdr').screenshot({ path: `${OUT}/editor-2-banner-uploaded-${width}.png` });
    r.afterUpload = await page.evaluate(liveState);

    // Laptop: 3 Frosted panel, centred, for THIS category only.
    await page.locator('[data-ct-hdrdevbar] [data-ct-hdrdev="laptop"]').click();
    await page.waitForTimeout(400);
    await page.locator('[data-thk-group="ct-treat"] [data-thk-value="frost"]').click();
    await page.waitForTimeout(300);
    await page.locator('[data-thk-group="ct-align"] [data-thk-value="center"]').click();
    await page.waitForTimeout(500);
    await page.evaluate(() => document.querySelector('#ct-hdr').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await page.locator('#ct-hdr').screenshot({ path: `${OUT}/editor-3-laptop-frost-centre-${width}.png` });
    r.laptopChoice = await page.evaluate(liveState);
    r.modal = await page.evaluate(() => ({
        boxScrollWidth: document.querySelector('.ct-modal-box').scrollWidth,
        boxClientWidth: document.querySelector('.ct-modal-box').clientWidth,
        groups: [...document.querySelectorAll('#ct-hdr [role=radiogroup]')].map((g) => g.getAttribute('data-thk-group') + ':' + g.querySelectorAll('[role=radio]').length),
    }));

    if (width === 1280) {
        // Save it, and read the category page back.
        await page.locator('#ct-save').click();
        await page.waitForTimeout(1500);
        r.saved = await page.evaluate(async () => {
            const res = await fetch(location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/categories', { headers: { Accept: 'application/json' } });
            const j = await res.json();
            const c = (j.categories || []).find((x) => x.slug === 'lip-care');
            return c ? { header_image: c.header_image, header_style: c.header_style } : null;
        });
    }
    Object.assign(r, { widths: await page.evaluate(widths) });
    r.errors = errors;
    result[`admin-editor-${width}`] = r;
    await ctx.close();
}

async function storefront(browser, width, slug, name) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    const errors = [];
    watch(page, errors);
    await page.goto(`${BASE}/collections/${slug}/`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    const th = page.locator('[data-kbb-title-header]').first();
    if (await th.count()) await th.screenshot({ path: `${OUT}/${name}-${width}.png` });
    await page.screenshot({ path: `${OUT}/${name}-page-${width}.png` });
    result[`${name}-${width}`] = await page.evaluate(() => {
        const s = document.querySelector('[data-kbb-title-header]');
        if (!s) return null;
        const r = s.getBoundingClientRect();
        const inner = s.querySelector('.kbb-th__inner');
        const text = s.querySelector('.kbb-th__text');
        const title = s.querySelector('.kbb-th__title');
        const img = s.querySelector('.kbb-th__img');
        const tr = text.getBoundingClientRect();
        let visible = null;
        if (img && img.naturalWidth) {
            const scale = Math.max(r.width / img.naturalWidth, r.height / img.naturalHeight);
            visible = { natural: [img.naturalWidth, img.naturalHeight], shownWidthPct: Math.round(100 * r.width / (img.naturalWidth * scale)), shownHeightPct: Math.round(100 * r.height / (img.naturalHeight * scale)), objectPosition: getComputedStyle(img).objectPosition };
        }
        return {
            classes: s.className,
            header: { w: Math.round(r.width), h: Math.round(r.height) },
            alignItems: getComputedStyle(s).alignItems,
            textAlign: getComputedStyle(inner).textAlign,
            innerAlignItems: getComputedStyle(inner).alignItems,
            textCentreOffset: Math.round((tr.left + tr.width / 2) - (r.left + r.width / 2)),
            textBottomGap: Math.round(r.bottom - tr.bottom),
            frost: getComputedStyle(text).backdropFilter,
            textBg: getComputedStyle(text).backgroundColor,
            boxBg: getComputedStyle(s).backgroundColor,
            scrim: getComputedStyle(s.querySelector('.kbb-th__scrim')).backgroundImage.slice(0, 60),
            titleSize: getComputedStyle(title).fontSize,
            visible,
            scrollWidth: document.documentElement.scrollWidth,
            viewport: innerWidth,
        };
    });
    result[`${name}-${width}`] && (result[`${name}-${width}`].errors = errors);
    await ctx.close();
}

async function overview(browser) {
    const ctx = await ctxFor(browser, 1400, 900);
    const page = await ctx.newPage();
    const img = (f) => `file://${OUT}/${f}`;
    const fig = (f, cap, w) => fs.existsSync(path.join(OUT, f))
        ? `<figure style="margin:0;width:${w || 'auto'}"><img src="${img(f)}" style="max-width:100%;border:1px solid #ddd;border-radius:8px"><figcaption>${cap}</figcaption></figure>` : '';
    const html = `<!doctype html><meta charset="utf-8"><style>
      body{font:14px/1.5 system-ui,sans-serif;margin:24px;color:#222;background:#fff}
      h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:26px 0 8px;border-top:1px solid #eee;padding-top:14px}
      .row{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap} figcaption{font-size:12px;color:#555;margin-top:4px}
      p{max-width:1100px;margin:4px 0}
    </style>
    <h1>Category header — the designs, phone and laptop, live preview (Lane QC)</h1>
    <p>Appearance → Site layout → Category header: the option sheet's designs A–F and 1–5 as clickable tiles, a Phone | Laptop switch, a live preview drawn with the shop's stylesheet, and fine-tuning under the chosen design. Catalog → Categories → Edit → Category header: the same, per category, with Upload banner.</p>
    <h2>1 · Choosing B, then tuning its icon colour — the live preview follows (1280)</h2>
    <div class="row">${fig('pick-1-before-1280.png', 'Before: A · Blush icons', '440px')}${fig('pick-2-chose-B-1280.png', 'Clicked B · Cream icons', '440px')}${fig('pick-3-tuned-B-icons-1280.png', 'B’s icon colour tuned to #8A4B2A', '440px')}</div>
    <h2>2 · The same at 390 — preview sticky above the tiles</h2>
    <div class="row">${fig('pick-1-before-390.png', 'Before', '230px')}${fig('pick-2-chose-B-390.png', 'Clicked B', '230px')}${fig('pick-3-tuned-B-icons-390.png', 'B tuned', '230px')}${fig('pick-4-chose-3-frosted-390.png', 'Clicked 3 · Frosted panel', '230px')}</div>
    <h2>3 · The category editor: live preview of the category, Upload banner, tiles per device</h2>
    <div class="row">${fig('editor-2-banner-uploaded-1280.png', 'Banner uploaded (1280)', '420px')}${fig('editor-3-laptop-frost-centre-390.png', 'Laptop: 3 + Centre for this category (390)', '260px')}</div>
    <h2>4 · One category, phone A + 2 at the start, laptop B + 3 centred (storefront)</h2>
    <div class="row">${fig('split-box-390.png', 'Phone 390: A + 2, start', '300px')}${fig('split-box-1280.png', 'Laptop 1280: B + 3, centred', '760px')}</div>
    <div class="row">${fig('split-pic-390.png', 'Phone 390, banner: 2 · dark fade, start', '300px')}${fig('split-pic-1280.png', 'Laptop 1280, banner: 3 · frosted, centred', '760px')}</div>
    `;
    fs.writeFileSync(path.join(OUT, '_overview.html'), html);
    await page.goto('file://' + path.join(OUT, '_overview.html'));
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/overview.png`, fullPage: true });
    fs.unlinkSync(path.join(OUT, '_overview.html'));
    await ctx.close();
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    for (const w of WIDTHS) {
        await storefront(browser, w, 'qc-split-box', 'split-box');
        await storefront(browser, w, 'qc-split-pic', 'split-pic');
        await storefront(browser, w, 'skincare/sunscreens', 'default-pic');
        await storefront(browser, w, 'face-masks', 'default-box');
    }
    await storefront(browser, 1680, 'skincare/sunscreens', 'default-pic');
    for (const w of WIDTHS) {
        await adminLayout(browser, w);
        await adminEditor(browser, w);
    }
    // The uploaded banner on its category page, after the 1280 editor saved it.
    for (const w of WIDTHS) await storefront(browser, w, 'lip-care', 'saved-banner');
    await overview(browser);
    await browser.close();
    fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(result, null, 2) + '\n');
    console.log(JSON.stringify(result, null, 1).slice(0, 200));
})();
