/*
 * Lane GO evidence: Marketing -> Gift offers, driven through the real screens.
 *
 *   sh tools/go-preview.sh 9530
 *   GO_BASE=http://127.0.0.1:9530 NODE_PATH=/home/user/kbbstore/node_modules node tools/go-shots.cjs
 *
 * Writes docs/lane-go-shots/*.png and docs/lane-go-shots/result.json. Every figure
 * in result.json is read from the page or the server, never typed in.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.GO_BASE || 'http://127.0.0.1:9530';
const PORT = new URL(BASE).port;
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/lane-go-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const result = { emails: {}, rows: {}, popup: {}, console: {}, admin: {} };
fs.mkdirSync(OUT, { recursive: true });

function tinker(what) {
    const out = execSync(`sh ${APP}/tools/go-tinker.sh ${APP}/tools/go-claim.php ${PORT}`, { env: { ...process.env, GO_DO: what }, encoding: 'utf8' });
    const m = out.match(/^(\w+)=(.*)$/m);
    return m ? m[2].trim() : null;
}

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(700);
}

async function preview(page, body) {
    return page.evaluate(async (b) => {
        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const r = await fetch('/admin-api/gift-offers/preview', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' }, body: JSON.stringify(b) });
        return (await r.json()).html;
    }, body);
}

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });

    /* ---------------------------------------------------------- admin */
    const actx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
    const admin = await actx.newPage();
    await signIn(admin);
    for (const w of [1280, 390]) {
        await admin.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
        await admin.evaluate(() => window.go('giftoffers'));
        await admin.waitForSelector('[data-screen="giftoffers"] .go-tab');
        await admin.waitForTimeout(600);
        for (const tab of ['settings', 'ranges', 'popup', 'preview', 'test', 'report']) {
            await admin.click(`[data-tab="${tab}"]`);
            await admin.waitForTimeout(tab === 'preview' ? 2500 : 700);
            await admin.screenshot({ path: `${OUT}/admin-${tab}-${w}.png`, fullPage: tab !== 'preview' });
            if (tab === 'preview') await admin.screenshot({ path: `${OUT}/admin-preview-full-${w}.png`, fullPage: true });
        }
        result.admin['scrollWidth_' + w] = await admin.evaluate(() => document.documentElement.scrollWidth);
    }
    // The search box finds a product and the boxes show a role, stock and allocation.
    await admin.setViewportSize({ width: 1280, height: 900 });
    await admin.click('[data-tab="ranges"]');
    await admin.waitForTimeout(500);
    await admin.click('[data-act="pick"]');
    await admin.fill('[data-k^="q:"]', 'snail');
    await admin.waitForTimeout(1200);
    await admin.screenshot({ path: `${OUT}/admin-ranges-search-1280.png`, fullPage: true });
    // Live preview: dark mode, phone; then the popup.
    await admin.click('[data-tab="preview"]');
    await admin.waitForTimeout(2500);
    await admin.click('[data-pv="scheme"]');
    await admin.waitForTimeout(900);
    await admin.screenshot({ path: `${OUT}/admin-preview-dark-1280.png`, fullPage: true });
    await admin.selectOption('[data-k="pv:place"]', 'popup');
    await admin.waitForTimeout(2500);
    await admin.screenshot({ path: `${OUT}/admin-preview-popup-1280.png`, fullPage: false });

    /* ---------------------------------------------------------- emails */
    const ep = await actx.newPage();
    for (const [name, body] of [['cart-en', { place: 'cart', locale: 'en' }], ['reminder1-en', { place: 'reminder_1', locale: 'en' }],
        ['reminder2-ar', { place: 'reminder_2', locale: 'ar' }], ['cart-ar', { place: 'cart', locale: 'ar' }]]) {
        const html = await preview(admin, body);
        for (const scheme of ['light', 'dark']) {
            for (const w of [390, 1280]) {
                await ep.setViewportSize({ width: w, height: 900 });
                await ep.emulateMedia({ colorScheme: scheme });
                await ep.setContent(html, { waitUntil: 'load' });
                await ep.waitForTimeout(300);
                await ep.screenshot({ path: `${OUT}/email-${name}-${scheme}-${w}.png`, fullPage: true });
            }
        }
        result.emails[name + '_bytes'] = Buffer.byteLength(html);
    }
    // The dark-mode item-name fix, measured: the link's colour before and after.
    {
        const html = await preview(admin, { place: 'cart', locale: 'en' });
        await ep.setViewportSize({ width: 390, height: 900 });
        await ep.emulateMedia({ colorScheme: 'dark' });
        const before = html.replace('    .ink a{color:#F7EEF2!important}\n', '');
        await ep.setContent(before, { waitUntil: 'load' });
        result.emails.item_link_dark_before = await ep.evaluate(() => getComputedStyle(document.querySelector('.ink a[href*="/product/"]')).color);
        await ep.screenshot({ path: `${OUT}/email-items-dark-BEFORE-390.png`, fullPage: true });
        await ep.setContent(html, { waitUntil: 'load' });
        result.emails.item_link_dark_after = await ep.evaluate(() => getComputedStyle(document.querySelector('.ink a[href*="/product/"]')).color);
        await ep.emulateMedia({ colorScheme: 'light' });
        result.emails.item_link_light = await ep.evaluate(() => getComputedStyle(document.querySelector('.ink a[href*="/product/"]')).color);
    }
    result.emails.sizes = JSON.parse(tinker('sizes') || '{}');

    /* Warm the shop's image copies, so the previews draw the product photos. */
    {
        const warm = await actx.newPage();
        for (const u of ['/collections/skincare/', '/product/go-dokdo-toner/', '/product/go-relief-sun/', '/product/go-rice-toner/', '/product/go-snail-essence/']) {
            await warm.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
        }
        await warm.waitForTimeout(1500);
        await warm.close();
    }
    /* ---------------------------------------------------------- popup designs */
    const pp = await actx.newPage();
    const combos = [['A', 'S3'], ['B', 'S3'], ['C', 'S3'], ['D', 'S3'], ['A', 'S1'], ['A', 'S2'], ['A', 'S4'], ['A', 'S5']];
    for (const [d, s] of combos) {
        const html = await preview(admin, { place: 'popup', locale: 'en', config: { places: { popup: true }, popup: { design: d, style: s, words: '3' } } });
        for (const w of [390, 1280]) {
            await pp.setViewportSize({ width: w, height: w === 390 ? 844 : 800 });
            await pp.goto(BASE + '/kbb-preview-id.txt');
            await pp.setContent(html, { waitUntil: 'load' });
            await pp.waitForFunction(() => { const i = document.querySelector('.kgo-ph img'); return !!i && i.complete && i.naturalWidth > 0; }, null, { timeout: 5000 }).catch(() => {});
            await pp.waitForTimeout(700);
            if (d === 'C' && s === 'S3') {
                await pp.screenshot({ path: `${OUT}/popup-${d}-${s}-closed-${w}.png` });
                await pp.click('[data-kgo-open]');
                await pp.waitForTimeout(500);
            }
            await pp.screenshot({ path: `${OUT}/popup-${d}-${s}-${w}.png` });
        }
    }
    {
        const html = await preview(admin, { place: 'popup', locale: 'ar', config: { places: { popup: true }, popup: { design: 'A', style: 'S3', words: '3' } } });
        await pp.setViewportSize({ width: 390, height: 844 });
        await pp.goto(BASE + '/kbb-preview-id.txt');
        await pp.setContent(html, { waitUntil: 'load' });
        await pp.waitForTimeout(700);
        await pp.screenshot({ path: `${OUT}/popup-A-S3-ar-390.png` });
        await pp.click('[data-kgo-claim]');
        await pp.waitForTimeout(650);
        await pp.screenshot({ path: `${OUT}/popup-claim-tick-preview-390.png` });
        await pp.goto(BASE + '/kbb-preview-id.txt');
        await pp.setContent(html, { waitUntil: 'load' });
        await pp.waitForTimeout(700);
        await pp.screenshot({ path: `${OUT}/popup-A-S3-ar-390.png` });
    }

    /* ---------------------------------------------------------- the shop */
    for (const w of [390, 1280]) {
        const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, userAgent: UA });
        const page = await ctx.newPage();
        const errors = [];
        const scripts = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('request', (r) => { if (r.url().includes('gift-offer-popup')) scripts.push(r.url()); });

        // An email's claim link: a fresh basket, the gift attached; then two products in it.
        const token = tinker('token');
        await page.goto(`${BASE}/gift-offer/c/${token}`, { waitUntil: 'networkidle' });
        tinker('fill');
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1200);
        const row = page.locator('.kgo-r').first();
        await row.scrollIntoViewIfNeeded();
        result.rows['cart_' + w] = await row.evaluate((e) => ({ h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width, clock: e.querySelector('.kgo-clk').textContent }));
        await page.screenshot({ path: `${OUT}/shop-cart-page-${w}.png`, fullPage: true });
        await row.screenshot({ path: `${OUT}/shop-row-${w}.png` });
        result.rows['scrollWidth_cart_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);

        await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
        await page.locator('[data-kbb-open="cart"]:visible').first().click();
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT}/shop-cart-panel-${w}.png` });

        await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1200);
        const crow = page.locator('.kgo-r:visible').first();
        if (await crow.count()) {
            await crow.scrollIntoViewIfNeeded();
            result.rows['checkout_' + w] = await crow.evaluate((e) => ({ h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width }));
        }
        await page.screenshot({ path: `${OUT}/shop-checkout-${w}.png`, fullPage: true });
        if (w === 390) {
            await page.click('#kbbSumRow');
            await page.waitForTimeout(800);
            await page.screenshot({ path: `${OUT}/shop-checkout-summary-open-390.png`, fullPage: true });
        }

        // The exit popup on the real shop: the basket loses its claim, then a move to leave.
        tinker('unclaim');
        await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(800);
        result.popup['script_before_intent_' + w] = scripts.length;
        await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseout', { clientY: -1, relatedTarget: null, bubbles: true })));
        await page.waitForSelector('[data-kgo-ov]', { timeout: 5000 });
        await page.waitForTimeout(700);
        result.popup['script_after_intent_' + w] = scripts.length;
        await page.screenshot({ path: `${OUT}/shop-popup-${w}.png` });
        await page.click('[data-kgo-claim]');
        await page.waitForTimeout(650);
        await page.screenshot({ path: `${OUT}/shop-popup-tick-${w}.png` });
        await page.waitForURL(/\/cart/, { timeout: 8000 });
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT}/shop-after-popup-claim-${w}.png`, fullPage: true });
        result.rows['popup_claim_clock_' + w] = await page.locator('.kgo-clk').first().textContent();
        result.console['errors_' + w] = errors;
        await ctx.close();
    }

    fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 2));
    await browser.close();
    console.log(JSON.stringify(result, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
