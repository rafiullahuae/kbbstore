/*
 * Lane CH screenshots: the category page's "Edit header" panel, in Chromium at
 * 390 and 1280. Run against tools/ch-preview.sh:
 *
 *     node tools/ch-shots.cjs http://127.0.0.1:10200 docs/ch-shots
 *
 * Every number printed is read from the page (computed styles and layout
 * boxes), for the report; the shop's own code measures nothing.
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10200';
const OUT = path.resolve(process.argv[3] || 'docs/ch-shots');
const PAGE = `${BASE}/collections/sunscreens/`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

async function measure(page) {
    return page.evaluate(() => {
        const box = (sel) => { const e = document.querySelector(sel); if (!e || e.hidden) return null; const r = e.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), h: Math.round(r.height), w: Math.round(r.width) }; };
        const vis = (sel) => [...document.querySelectorAll(sel)].find((e) => getComputedStyle(e).display !== 'none') || null;
        const boxOf = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), h: Math.round(r.height), w: Math.round(r.width) }; };
        const thEl = vis('[data-kbb-title-header]');
        const areaEl = vis('[data-kbb-ch]');
        const th = boxOf(thEl);
        const area = boxOf(areaEl);
        const grid = box('.wrap.shop');
        const crumb = box('.wrap > .crumb');
        const head = th || area;
        const css = (sel, p) => { const e = document.querySelector(sel); return e ? getComputedStyle(e)[p] : null; };
        return {
            header: th ? 'title' : (area ? 'custom' : 'none'),
            headerH: head ? head.h : null,
            headerW: head ? head.w : null,
            marginTop: thEl ? getComputedStyle(thEl).marginTop : null,
            marginBottom: thEl ? getComputedStyle(thEl).marginBottom : null,
            innerPad: thEl ? getComputedStyle(thEl.querySelector('.kbb-th__inner')).padding : null,
            gapBelow: head && grid ? grid.top - (head.top + head.h) : null,
            title: ((thEl || areaEl) && (thEl || areaEl).querySelector('h1') || {}).textContent || null,
            titleSize: (thEl || areaEl) ? getComputedStyle((thEl || areaEl).querySelector('h1')).fontSize : null,
            bannerH: areaEl && areaEl.querySelector('.kbb-pb') ? Math.round(areaEl.querySelector('.kbb-pb').getBoundingClientRect().height) : null,
            stripH: areaEl && areaEl.querySelector('.kbb-pb-strip') ? Math.round(areaEl.querySelector('.kbb-pb-strip').getBoundingClientRect().height) : null,
            pill: !!document.querySelector('.kbb-qe-pill'),
            pills: document.querySelectorAll('.kbb-qe-pill').length,
            panel: !!document.querySelector('.kbb-che'),
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
        };
    });
}

async function login(ctx) {
    const page = await ctx.newPage();
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    return page;
}

async function openPanel(page) {
    await page.goto(PAGE, { waitUntil: 'networkidle' });
    await page.waitForSelector('.kbb-qe-pill', { timeout: 15000 });
    return page;
}

async function save(page) {
    await page.click('.kbb-che button.is-primary');
    try {
        await page.waitForSelector('.kbb-che', { state: 'detached', timeout: 15000 });
    } catch (e) {
        throw new Error('Save did not close the panel: ' + await page.$eval('.kbb-che .kbb-phe-err', (el) => el.textContent));
    }
}

async function setRange(page, key, value) {
    await page.$eval(`.kbb-che [data-key="${key}"] input[type=range]`, (el, v) => {
        el.value = String(v);
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
}

(async () => {
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const log = [];
    const errors = [];

    for (const w of (process.argv[4] ? [Number(process.argv[4])] : [1280, 390])) {
        const phone = w < 500;
        const ctx = await browser.newContext({ viewport: { width: w, height: phone ? 844 : 1000 }, deviceScaleFactor: phone ? 2 : 1, userAgent: phone ? UA_PHONE : UA, isMobile: phone, hasTouch: phone });
        const page = await login(ctx);
        page.on('pageerror', (e) => errors.push(`${w} pageerror ${e}`));
        page.on('console', (m) => { if (m.type() === 'error') errors.push(`${w} console ${m.text()}`); });
        page.on('dialog', (d) => d.accept());

        // 1. The page with its Edit header button.
        await openPanel(page);
        log.push([w, '1-button', await measure(page)]);
        await page.screenshot({ path: `${OUT}/1-button-${w}.png`, fullPage: false });

        // 2. The panel's title-header tab, after editing name, description, picture and sizes.
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-che', { timeout: 15000 });
        await page.fill('.kbb-che input[aria-label="Name"]', 'Sunscreens for the UAE sun');
        await page.fill('.kbb-che textarea[aria-label="Description"]', 'Light, no white cast & reef-friendly <SPF50+> picks for every day.');
        // A phone picture from the Media Library: the picker opens under the row pressed.
        const rows = await page.$$('.kbb-che .kbb-phe-pic');
        await rows[1].$eval('button', (b) => b.click());
        await page.waitForSelector('.kbb-che-picker .kbb-phe-grid button', { timeout: 15000 });
        const pickerUnderRow = await page.evaluate(() => { const p = document.querySelector('.kbb-che-picker'); return !!(p && p.previousElementSibling && p.previousElementSibling.classList.contains('kbb-phe-pic')); });
        log.push([w, 'picker-under-row', pickerUnderRow]);
        await page.click('.kbb-che-picker .kbb-phe-grid button[aria-label="ch-sun-phone.jpg"]');
        await page.waitForTimeout(700);
        if (phone) {
            await page.click('.kbb-che .kbb-phe-seg button:has-text("Phone")').catch(() => {});
        }
        const dev = phone ? 'phone' : 'desktop';
        await setRange(page, `h_${dev}`, phone ? 240 : 360);
        await setRange(page, `mt_${dev}`, phone ? 12 : 20);
        await setRange(page, `mb_${dev}`, phone ? 30 : 40);
        await setRange(page, `py_${dev}`, phone ? 24 : 48);
        await page.click(`.kbb-che .kbb-phe-sel:has-text("Alignment") button:has-text("Centre")`);
        await page.waitForTimeout(900);
        log.push([w, '2-title-tab', await measure(page)]);
        await page.screenshot({ path: `${OUT}/2-panel-title-${w}.png`, fullPage: false });
        await save(page);
        await page.waitForTimeout(500);
        log.push([w, '2b-saved-title', await measure(page)]);
        await page.screenshot({ path: `${OUT}/2b-saved-title-${w}.png`, fullPage: false });

        // 3. The Custom header area tab, then Save, then the page as a shopper sees it.
        await openPanel(page);
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-che', { timeout: 15000 });
        await page.click('.kbb-che [data-tab="custom"]');
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${OUT}/3a-panel-custom-top-${w}.png`, fullPage: false });
        // The banner picture, from the Media Library.
        const bannerRow = await page.$('.kbb-che .kbb-phe-pic:has-text("Banner picture")');
        await bannerRow.$eval('button', (b) => b.click());
        await page.waitForSelector('.kbb-che-picker .kbb-phe-grid button', { timeout: 15000 });
        await page.click('.kbb-che-picker .kbb-phe-grid button[aria-label="ch-sun-banner.jpg"]');
        await page.waitForTimeout(500);
        log.push([w, '3-custom-tab', await measure(page)]);
        await page.screenshot({ path: `${OUT}/3-panel-custom-${w}.png`, fullPage: false });
        await save(page);

        const shopper = await browser.newContext({ viewport: { width: w, height: phone ? 844 : 1000 }, deviceScaleFactor: phone ? 2 : 1, userAgent: phone ? UA_PHONE : UA, isMobile: phone, hasTouch: phone });
        const sp = await shopper.newPage();
        sp.on('pageerror', (e) => errors.push(`${w} shopper pageerror ${e}`));
        await sp.goto(PAGE, { waitUntil: 'networkidle' });
        log.push([w, '4-custom-on-shopper', await measure(sp)]);
        const editorBytes = await sp.evaluate(() => performance.getEntriesByType('resource').filter((r) => /category-header-editor|page-header-editor|storefront-admin/.test(r.name)).map((r) => r.name));
        log.push([w, 'shopper-editor-chunks', editorBytes]);
        await sp.screenshot({ path: `${OUT}/4-custom-on-${w}.png`, fullPage: false });

        // 5. Switched back to the title header.
        await openPanel(page);
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-che', { timeout: 15000 });
        await page.click('.kbb-che [data-tab="title"]');
        await page.waitForTimeout(900);
        await save(page);
        await sp.goto(PAGE, { waitUntil: 'networkidle' });
        log.push([w, '5-switched-back-shopper', await measure(sp)]);
        await sp.screenshot({ path: `${OUT}/5-switched-back-${w}.png`, fullPage: false });

        // And the custom look survived the switch: tab 2 still holds the banner picture.
        await openPanel(page);
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-che', { timeout: 15000 });
        await page.click('.kbb-che [data-tab="custom"]');
        await page.waitForTimeout(300);
        log.push([w, 'custom-kept', await page.evaluate(() => !!document.querySelector('[data-kbb-ch] .kbb-pb img[src*="ch-sun-banner"]'))]);
        await page.click('.kbb-che .kbb-phe-x');
        await page.waitForTimeout(300);
        log.push([w, 'cancel-restored', await measure(page)]);

        await shopper.close();
        await ctx.close();
    }

    await browser.close();
    for (const l of log) console.log(JSON.stringify(l));
    console.log('ERRORS', JSON.stringify(errors));
})().catch((e) => { console.error(e); process.exit(1); });
