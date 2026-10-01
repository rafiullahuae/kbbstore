/*
 * Lane PJ-B evidence: the owner's Rey Global Section, before and after, at 390
 * and 1280, plus Content -> HTML Blocks and the "Remove all files" button.
 *
 *   sh tools/pjb-blocks-preview.sh 9470                 (AFTER, PJB_IMPORT=1 PJB_FILES=1)
 *   PJB_APP=<base tree> sh tools/pjb-blocks-preview.sh 9480 before
 *   PJB_BASE=http://127.0.0.1:9480 PJB_PHASE=before node tools/pjb-blocks-shots.cjs
 *   PJB_BASE=http://127.0.0.1:9470 PJB_PHASE=after  node tools/pjb-blocks-shots.cjs
 *
 * Measurements are taken by THIS harness in the browser -- getBoundingClientRect
 * and getComputedStyle are the instrument here, never shipped to a shopper.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PJB_BASE || 'http://127.0.0.1:9470';
const PHASE = process.env.PJB_PHASE || 'after';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PJB_OUT || 'docs/pjb-shots');
const SLUG = 'anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml';

async function measure(page) {
    return page.evaluate(() => {
        const r = (el) => { const b = el.getBoundingClientRect(); return { w: Math.round(b.width * 10) / 10, h: Math.round(b.height * 10) / 10 }; };
        const visible = (el) => el.offsetParent !== null;
        const block = [...document.querySelectorAll('.kbb-eblock')].find(visible);
        const out = {
            viewport: window.innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            shortcodeTextOnPage: document.body.innerText.includes('rey_global_section'),
        };
        if (!block) return out;
        const items = [...block.querySelectorAll('.kbb-eblock__item')];
        const head = block.querySelector('.kbb-eblock__heading');
        const title = block.querySelector('.kbb-eblock__title');
        const text = block.querySelector('.kbb-eblock__text');
        const img = block.querySelector('.kbb-eblock__img');
        const cs = (el) => el ? getComputedStyle(el) : null;
        out.block = r(block);
        out.heading = head ? { ...r(head), fontSize: cs(head).fontSize, fontWeight: cs(head).fontWeight } : null;
        out.items = items.map((it) => ({ ...r(it), top: Math.round(it.getBoundingClientRect().top) }));
        out.itemsPerRow = new Set(out.items.map((i) => i.top)).size === 1 ? items.length : `${new Set(out.items.map((i) => i.top)).size} rows`;
        out.image = img ? { ...r(img), attrW: img.getAttribute('width'), attrH: img.getAttribute('height'), loading: img.getAttribute('loading'), naturalW: img.naturalWidth, src: img.getAttribute('src') } : null;
        out.title = title ? { fontSize: cs(title).fontSize, fontWeight: cs(title).fontWeight, textTransform: cs(title).textTransform } : null;
        out.text = text ? { fontSize: cs(text).fontSize, lineHeight: cs(text).lineHeight } : null;
        return out;
    });
}

async function product(browser, width, result) {
    const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1 });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });

    const details = page.locator('#details');
    await details.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    // As the shopper first sees it: the panel as drawn, before any tap.
    await details.screenshot({ path: `${OUT}/${PHASE}-description-${width}.png` });
    result[`${PHASE}-${width}`] = await measure(page);

    // Read more, so the whole block is in the frame (the panel is clamped at every width).
    {
        const more = page.locator('.dtabpanel.on .readmore');
        if (await more.count() && await more.isVisible()) {
            await more.click();
            await page.waitForTimeout(350);
            await details.screenshot({ path: `${OUT}/${PHASE}-description-${width}-expanded.png` });
            result[`${PHASE}-${width}-expanded`] = await measure(page);
        }
    }

    // RTL is not live yet, but the block must survive dir="rtl" -- forced here.
    if (PHASE === 'after') {
        await page.evaluate(() => document.documentElement.setAttribute('dir', 'rtl'));
        await page.waitForTimeout(200);
        await details.screenshot({ path: `${OUT}/after-description-${width}-rtl.png` });
        result[`after-${width}-rtl`] = await measure(page);
    }

    await ctx.close();
}

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
}

async function admin(browser, width, result) {
    const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1 });
    const page = await ctx.newPage();
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.accept(); });
    await signIn(page);

    // Content -> HTML Blocks
    await page.evaluate(() => window.go('htmlblocks'));
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/admin-html-blocks-${width}.png` });
    result[`admin-blocks-${width}`] = await page.evaluate(() => ({
        rows: [...document.querySelectorAll('#hb-root tbody tr')].map((tr) => tr.innerText.replace(/\s+/g, ' ').trim()),
        scrollWidth: document.documentElement.scrollWidth,
    }));

    // Open the imported block in the editor.
    const row = page.locator('#hb-root tr[data-hb-open]').first();
    if (await row.count()) {
        await row.click();
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT}/admin-html-block-editor-${width}.png`, fullPage: false });
    }

    // Store -> Store Import / Export, card 1, before and after Remove all files.
    await page.evaluate(() => window.go('import'));
    await page.waitForTimeout(1800);
    const card = page.locator('.card.pad', { hasText: '1 · Your exports' }).first();
    await card.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-import-card-${width}-before.png` });
    const btn = page.locator('#impForgetAll');
    result[`admin-import-${width}`] = {
        button: await btn.count() ? await btn.evaluate((b) => ({ text: b.innerText, ...(() => { const r = b.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; })() })) : null,
        removeButtonsBefore: await page.locator('.impforget').count(),
        scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
    };
    if (await btn.count() && width >= 1000) {
        await btn.click();
        await page.waitForTimeout(1800);
        await page.locator('.card.pad', { hasText: '1 · Your exports' }).first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: `${OUT}/admin-import-card-${width}-after.png` });
        result[`admin-import-${width}`].removeButtonsAfter = await page.locator('.impforget').count();
        result[`admin-import-${width}`].buttonAfter = await page.locator('#impForgetAll').count();
        result[`admin-import-${width}`].dialogs = dialogs;
    }
    await ctx.close();
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const result = {};
    const errors = [];

    for (const w of [390, 1280]) {
        await product(browser, w, result);
    }

    if (PHASE === 'after') {
        // Admin at 390 first: it must not press the button (1280 does, last).
        await admin(browser, 390, result);
        await admin(browser, 1280, result);
    }

    await browser.close();
    fs.writeFileSync(`${OUT}/${PHASE}-measurements.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    if (errors.length) console.log('errors', errors);
})();
