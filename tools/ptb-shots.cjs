/*
 * Lane PT evidence: the old shop's category banner as the title background,
 * the old-site link in the Anua foam's description, and the two admin screens.
 *
 *   sh tools/ptb-preview.sh 9570                                   (AFTER)
 *   PTB_APP=<base tree> sh tools/ptb-preview.sh 9580 before        (BEFORE)
 *   PTB_BASE=http://127.0.0.1:9580 PTB_PHASE=before node tools/ptb-shots.cjs
 *   PTB_BASE=http://127.0.0.1:9570 PTB_PHASE=after  node tools/ptb-shots.cjs
 *
 * Measurements are taken by THIS harness in the browser -- getBoundingClientRect
 * and getComputedStyle are the instrument here, never shipped to a shopper.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PTB_BASE || 'http://127.0.0.1:9570';
const PHASE = process.env.PTB_PHASE || 'after';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PTB_OUT || 'docs/ptb-shots');
const FOAM = 'anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml';

const ctxFor = (browser, width) => browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    deviceScaleFactor: width < 600 ? 2 : 1,
});

async function measureCategory(page) {
    return page.evaluate(() => {
        const r = (el) => { const b = el.getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height), top: Math.round(b.top) }; };
        const cs = (el) => getComputedStyle(el);
        const out = {
            viewport: window.innerWidth,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            h1Count: document.querySelectorAll('h1').length,
        };
        const th = document.querySelector('[data-kbb-title-header]');
        if (th) {
            const t = th.querySelector('.kbb-th__title');
            const d = th.querySelector('.kbb-th__desc');
            const img = th.querySelector('.kbb-th__img');
            out.header = r(th);
            out.title = { text: t.innerText, fontSize: cs(t).fontSize, color: cs(t).color };
            out.description = d ? { ...r(d), fontSize: cs(d).fontSize, lineHeight: cs(d).lineHeight, clamped: d.classList.contains('kbb-th__desc--clamp') } : null;
            out.image = img ? { ...r(img), src: img.getAttribute('src'), naturalW: img.naturalWidth, objectFit: cs(img).objectFit } : null;
            out.readMore = !!th.querySelector('.kbb-th__more');
        } else {
            const h = document.querySelector('h1.ptitle');
            const s = document.querySelector('p.psub');
            out.plainTitle = h ? { text: h.innerText, fontSize: cs(h).fontSize, ...r(h) } : null;
            out.plainSub = s ? { text: s.innerText, fontSize: cs(s).fontSize } : null;
        }
        return out;
    });
}

async function category(browser, width, result) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    await page.goto(`${BASE}/collections/skincare/sunscreens/`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/${PHASE}-category-${width}.png` });
    result[`${PHASE}-category-${width}`] = await measureCategory(page);

    const more = page.locator('.kbb-th__more');
    if (await more.count()) {
        await more.click();
        await page.waitForTimeout(250);
        await page.screenshot({ path: `${OUT}/${PHASE}-category-${width}-readmore.png` });
        result[`${PHASE}-category-${width}-readmore`] = await measureCategory(page);
    }
    await ctx.close();
}

async function productLink(browser, width, result, label) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    await page.goto(`${BASE}/product/${FOAM}/`, { waitUntil: 'networkidle' });
    const link = page.locator('#details a', { hasText: 'Face Cleansers' }).first();
    const details = page.locator('#details');
    await details.scrollIntoViewIfNeeded();
    const readmore = page.locator('.dtabpanel.on .readmore');
    if (await readmore.count() && await readmore.isVisible()) {
        await readmore.click();
        await page.waitForTimeout(300);
    }
    if (await link.count()) {
        await link.scrollIntoViewIfNeeded();
        await link.hover();
        await page.waitForTimeout(200);
    }
    await details.screenshot({ path: `${OUT}/${label}-product-link-${width}.png` });
    result[`${label}-product-link-${width}`] = await page.evaluate(() => {
        const a = [...document.querySelectorAll('#details a')].find((x) => x.innerText.trim() === 'Face Cleansers');
        return {
            href: a ? a.getAttribute('href') : null,
            resolved: a ? a.href : null,
            target: a ? a.getAttribute('target') : null,
            rel: a ? a.getAttribute('rel') : null,
            scrollWidth: document.documentElement.scrollWidth,
        };
    });
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

async function adminLinks(browser, width, result, press) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.accept(); });
    await signIn(page);

    await page.evaluate(() => window.go('import'));
    await page.waitForTimeout(1800);
    await page.locator('#gbUMLoad').click();
    await page.waitForTimeout(2500);
    await page.locator('#ptOLPreview').click();
    await page.waitForTimeout(1800);
    const row = page.locator('#ptOldLinks');
    await row.scrollIntoViewIfNeeded();
    await page.evaluate(() => document.querySelector('#ptOldLinks').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/admin-old-links-${width}-preview.png` });
    const read = () => page.evaluate(() => ({
        counts: [...document.querySelectorAll('#ptOldLinks ~ .impgrid .impfile')].slice(0, 3).map((f) => f.innerText.replace(/\s+/g, ' ').trim()),
        samples: [...document.querySelectorAll('#ptOLSamples tbody tr')].map((tr) => tr.innerText.replace(/\s+/g, ' ').trim()),
        buttons: [...document.querySelectorAll('#ptOLFix, #ptOLPreview, #ptOLUndo')].map((b) => b.innerText + (b.disabled ? ' (disabled)' : '')),
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    result[`admin-old-links-${width}-preview`] = await read();

    if (press) {
        await page.locator('#ptOLFix').click();
        await page.waitForTimeout(2500);
        await page.evaluate(() => document.querySelector('#ptOldLinks').scrollIntoView({ block: 'start' }));
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/admin-old-links-${width}-fixed.png` });
        result[`admin-old-links-${width}-fixed`] = { ...(await read()), dialogs, message: await page.evaluate(() => (document.querySelector('#ptOldLinks ~ .impbanner') || {}).innerText || null) };
    }
    await ctx.close();
}

async function adminLayout(browser, width, result) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    await signIn(page);
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForTimeout(1800);
    const tab = page.locator('[data-sls-tab="catheader"]');
    await tab.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/admin-category-header-${width}.png`, fullPage: true });
    result[`admin-category-header-${width}`] = await page.evaluate(() => ({
        crumb: (document.querySelector('#crumb') || {}).innerText || null,
        title: (document.querySelector('#ptitle') || {}).innerText || null,
        tabs: [...document.querySelectorAll('[data-sls-tab]')].map((t) => t.innerText),
        fields: [...document.querySelectorAll('.sls-fields label')].map((l) => l.innerText),
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    await ctx.close();
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const result = {};

    for (const w of [390, 1280]) {
        await category(browser, w, result);
    }

    if (PHASE === 'before') {
        for (const w of [390, 1280]) await productLink(browser, w, result, 'before');
    } else {
        // The live shop's state: imported before this package, the link still old.
        for (const w of [390, 1280]) await productLink(browser, w, result, 'after-unfixed');
        await adminLayout(browser, 390, result);
        await adminLayout(browser, 1280, result);
        await adminLinks(browser, 390, result, false);
        await adminLinks(browser, 1280, result, true);
        for (const w of [390, 1280]) await productLink(browser, w, result, 'after-fixed');
    }

    await browser.close();
    fs.writeFileSync(`${OUT}/${PHASE}-measurements.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
})();
