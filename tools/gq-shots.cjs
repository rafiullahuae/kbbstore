/*
 * Lane GQ evidence: "Gives per order ×2" (Marketing -> Gift offers), the
 * preview the owner asked for. Base: tools/go-shots.cjs.
 *
 *   GO_LANE=lane-gq GO_SEED=gq-seed.php sh tools/go-preview.sh 9560
 *   GO_BASE=http://127.0.0.1:<port> NODE_PATH=/home/user/kbbstore/node_modules node tools/gq-shots.cjs
 *
 * Writes docs/lane-gq-shots/*.png and result.json; every figure is read from
 * the page or the server. A normal Chrome user-agent: the preview's /cart/
 * answers 403 to HeadlessChrome.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.GO_BASE || 'http://127.0.0.1:9560';
const PORT = new URL(BASE).port;
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/lane-gq-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const result = { admin: {}, emails: {}, rows: {}, popup: {}, console: {} };
fs.mkdirSync(OUT, { recursive: true });

function tinker(file, env) {
    const out = execSync(`sh ${APP}/tools/go-tinker.sh ${APP}/tools/${file} ${PORT}`, { env: { ...process.env, GO_LANE: 'lane-gq', ...env }, encoding: 'utf8' });
    const vals = {};
    for (const m of out.matchAll(/^(\w+)=(.*)$/gm)) vals[m[1]] = m[2].trim();
    return vals;
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

async function shotHtml(page, html, name, waitImg) {
    for (const w of [390, 1280]) {
        await page.setViewportSize({ width: w, height: w === 390 ? 844 : 800 });
        await page.goto(BASE + '/kbb-preview-id.txt');
        await page.setContent(html, { waitUntil: 'load' });
        if (waitImg) await page.waitForFunction(() => { const i = document.querySelector('.kgo-ph img'); return !i || (i.complete && i.naturalWidth > 0); }, null, { timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: !name.startsWith('popup') });
        result.emails[name + '_scrollWidth_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);
    }
}

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });

    /* ---------------------------------------------------------- admin: the range card */
    const actx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
    const admin = await actx.newPage();
    const adminErrors = [];
    admin.on('console', (m) => { if (m.type() === 'error') adminErrors.push(m.text()); });
    await signIn(admin);
    for (const w of [1280, 390]) {
        await admin.setViewportSize({ width: w, height: w === 390 ? 844 : 900 });
        await admin.evaluate(() => window.go('giftoffers'));
        await admin.waitForSelector('[data-screen="giftoffers"] .go-tab');
        await admin.click('[data-tab="ranges"]');
        await admin.waitForTimeout(800);
        const sel = admin.locator('select[data-k^="qt:"]');
        result.admin['selects_' + w] = await sel.count();
        result.admin['values_' + w] = await sel.evaluateAll((els) => els.map((e) => e.value));
        const box = admin.locator('.go-box', { has: admin.locator('select[data-k^="qt:"] option[value="2"][selected]') }).first();
        await box.scrollIntoViewIfNeeded();
        result.admin['box_text_' + w] = (await box.innerText()).replace(/\s+/g, ' ');
        await box.screenshot({ path: `${OUT}/admin-range-box-x2-${w}.png` });
        const card = admin.locator('.go-card', { has: box }).first();
        await card.screenshot({ path: `${OUT}/admin-range-card-x2-${w}.png` });
        result.admin['scrollWidth_' + w] = await admin.evaluate(() => document.documentElement.scrollWidth);
    }
    // The control moving: ×2 -> ×3 on the draft shows "3 pcs"; Save is NOT pressed.
    await admin.setViewportSize({ width: 1280, height: 900 });
    const sel = admin.locator('select[data-k^="qt:"]').nth(2);
    await sel.selectOption('3');
    await admin.waitForTimeout(400);
    const box3 = admin.locator('.go-box', { has: admin.locator('select[data-k^="qt:"] option[value="3"][selected]') }).first();
    result.admin.after_select_3 = (await box3.innerText()).replace(/\s+/g, ' ');
    await box3.screenshot({ path: `${OUT}/admin-range-box-x3-unsaved-1280.png` });
    result.admin.console_errors = adminErrors;

    /* ---------------------------------------------------------- emails and popup (the admin's live preview) */
    const ep = await actx.newPage();
    const cartMail = await preview(admin, { place: 'cart', locale: 'en', bag: 404 });
    result.emails.cart_ticket_has_x2 = cartMail.includes('>×2</span>');
    await shotHtml(ep, cartMail, 'email-abandoned-cart-ticket-x2', false);
    for (const s of ['S3', 'S5', 'S1']) {
        const html = await preview(admin, { place: 'popup', locale: 'en', bag: 404, config: { places: { popup: true }, popup: { design: 'A', style: s, words: '3' } } });
        result.popup['preview_' + s + '_has_x2'] = html.includes('<span class="kgo-c3">×2</span>');
        await shotHtml(ep, html, `popup-preview-A-${s}-x2`, true);
    }

    /* ---------------------------------------------------------- the shop */
    for (const w of [390, 1280]) {
        const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, userAgent: UA });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

        const { TOKEN } = tinker('go-claim.php', { GO_DO: 'token' });
        await page.goto(`${BASE}/gift-offer/c/${TOKEN}`, { waitUntil: 'networkidle' });
        tinker('go-claim.php', { GO_DO: 'fill' });

        // Cart page.
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        const row = page.locator('.kgo-r').first();
        await row.scrollIntoViewIfNeeded();
        result.rows['cart_' + w] = await row.evaluate((e) => ({ h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width,
            name: e.querySelector('.kgo-nm b').textContent, chips: [...e.querySelectorAll('.kgo-meta i')].map((i) => i.textContent) }));
        result.rows['scrollWidth_cart_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);
        await row.screenshot({ path: `${OUT}/shop-row-x2-${w}.png` });
        await page.screenshot({ path: `${OUT}/shop-cart-page-x2-${w}.png`, fullPage: true });

        // Cart panel.
        await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
        await page.locator('[data-kbb-open="cart"]:visible').first().click();
        await page.waitForTimeout(1200);
        const prow = page.locator('.kgo-r:visible').first();
        result.rows['panel_chips_' + w] = await prow.evaluate((e) => [...e.querySelectorAll('.kgo-meta i')].map((i) => i.textContent));
        await page.screenshot({ path: `${OUT}/shop-cart-panel-x2-${w}.png` });

        // Checkout summary.
        await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1000);
        if (w === 390) { await page.click('#kbbSumRow').catch(() => {}); await page.waitForTimeout(800); }
        const crow = page.locator('.kgo-r:visible').first();
        if (await crow.count()) {
            await crow.scrollIntoViewIfNeeded();
            result.rows['checkout_' + w] = await crow.evaluate((e) => ({ h: e.getBoundingClientRect().height, chips: [...e.querySelectorAll('.kgo-meta i')].map((i) => i.textContent) }));
            await crow.screenshot({ path: `${OUT}/shop-checkout-row-x2-${w}.png` });
        }
        result.rows['scrollWidth_checkout_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);
        await page.screenshot({ path: `${OUT}/shop-checkout-summary-x2-${w}.png`, fullPage: true });

        // The order confirmation email, from an order whose gift attach() wrote.
        if (w === 1280) {
            const html = path.join(APP, 'storage/framework/testing/lane-gq-preview/confirm.html');
            const r = tinker('gq-claim.php', { GQ_DO: 'confirm', GQ_OUT: html });
            result.emails.confirm_order = r.ORDER;
            result.emails.confirm_gift_line_quantity = Number(r.GIFTQTY);
            const mail = fs.readFileSync(html, 'utf8');
            const m = mail.match(/<b>(\d+)&times;&nbsp;<\/b>Relief Sun/);
            result.emails.confirm_prints = m ? m[1] + '× Relief Sun' : null;
            await shotHtml(ep, mail, 'email-order-confirmation-x2', false);
        } else {
            tinker('go-claim.php', { GO_DO: 'unclaim' });
        }

        // The exit popup on the real shop.
        await page.goto(`${BASE}/product/go-glow-serum/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(800);
        await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseout', { clientY: -1, relatedTarget: null, bubbles: true })));
        await page.waitForSelector('[data-kgo-ov]', { timeout: 5000 });
        await page.waitForTimeout(800);
        result.popup['shop_chips_' + w] = await page.evaluate(() => [...document.querySelectorAll('[data-kgo-ov] .kgo-chips span')].map((s) => s.textContent));
        result.popup['shop_scrollWidth_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);
        await page.screenshot({ path: `${OUT}/shop-exit-popup-x2-${w}.png` });
        result.console['errors_' + w] = errors;
        await ctx.close();
    }

    fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 2));
    await browser.close();
    console.log(JSON.stringify(result, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
