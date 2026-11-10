/*
 * Lane RP evidence (order #56680): a basket line added at AED 85, the price
 * raised to AED 89, and the "Price updated" note on the cart panel, the cart
 * page and the checkout, at 390 and 1280; plus Place order pressed on a page
 * drawn before the raise.
 *
 *   GO_LANE=lane-rpx GO_SEED=rpx-seed.php sh tools/go-preview.sh 9640
 *   RP_BASE=http://127.0.0.1:9640 NODE_PATH=/home/user/kbbstore/node_modules node tools/rpx-shots.cjs
 *
 * Writes docs/lane-rp-shots/price-refresh/*.png and result.json (figures read off the page).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.RP_BASE || 'http://127.0.0.1:9640';
const PORT = new URL(BASE).port;
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/lane-rp-shots/price-refresh');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const result = {};
fs.mkdirSync(OUT, { recursive: true });

function price(fils) {
    return execSync(`sh ${APP}/tools/go-tinker.sh ${APP}/tools/rpx-price.php ${PORT}`,
        { env: { ...process.env, GO_LANE: 'lane-rpx', RP_PRICE: String(fils) }, encoding: 'utf8' });
}

async function basketAt85(browser, width) {
    price(8500);
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    return { ctx, page, errors };
}

async function measure(page) {
    return page.evaluate(() => {
        const seen = [...document.querySelectorAll('[role=status]')].filter((e) => /Price updated/.test(e.textContent) && e.getBoundingClientRect().height > 0);
        const n = seen.find((e) => !e.closest('.kc-item')) || seen[0];
        const cs = n ? getComputedStyle(n) : null;
        return {
            note: n ? n.textContent : null,
            noteClass: n ? n.className : null,
            fontSize: cs ? cs.fontSize : null,
            color: cs ? cs.color : null,
            scrollWidth: document.documentElement.scrollWidth,
            width: window.innerWidth,
        };
    });
}

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    const mistId = Number(price(8500).match(/id=(\d+)/)[1]);

    for (const w of [390, 1280]) {
        /* the panel, on the home page */
        let s = await basketAt85(browser, w);
        await s.page.evaluate(async (id) => {
            const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf },
                body: JSON.stringify({ product_id: id, quantity: 1 }) });
            return r.status;
        }, mistId);
        price(8900);
        await s.page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        await s.page.click('[data-kbb-cart]');
        await s.page.waitForTimeout(700);
        result[`panel-${w}`] = { ...(await measure(s.page)), errors: s.errors };
        await s.page.screenshot({ path: `${OUT}/panel-${w}.png` });
        await s.ctx.close();

        /* the cart page */
        s = await basketAt85(browser, w);
        await s.page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf },
            body: JSON.stringify({ product_id: id, quantity: 1 }) }).then((r) => r.status), mistId);
        price(8900);
        await s.page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        result[`cart-${w}`] = { ...(await measure(s.page)), errors: s.errors };
        await s.page.screenshot({ path: `${OUT}/cart-${w}.png`, fullPage: w === 1280 });
        await s.ctx.close();

        /* the checkout */
        s = await basketAt85(browser, w);
        await s.page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf },
            body: JSON.stringify({ product_id: id, quantity: 1 }) }).then((r) => r.status), mistId);
        price(8900);
        await s.page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
        result[`checkout-${w}`] = { ...(await measure(s.page)), errors: s.errors };
        result[`checkout-${w}`].visible = await s.page.evaluate(() => [...document.querySelectorAll('[role=status]')]
            .filter((e) => /Price updated/.test(e.textContent)).map((e) => { const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, y: r.top + scrollY, in: e.closest('[id],[class]')?.className || '' }; }));
        await s.page.screenshot({ path: `${OUT}/checkout-${w}.png`, fullPage: true });
        await s.ctx.close();

        /* Place order on a checkout drawn at 85, after the raise to 89 */
        s = await basketAt85(browser, w);
        await s.page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf },
            body: JSON.stringify({ product_id: id, quantity: 1 }) }).then((r) => r.status), mistId);
        await s.page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
        price(8900);
        const answer = await s.page.evaluate(async () => {
            const f = document.getElementById('kbbCheckoutForm') || document.querySelector('form[action*="place"]');
            const fd = new FormData(f);
            const set = { billing_email: 'buyer@example.com', billing_phone: '+971500000000', billing_first_name: 'Aisha Khan',
                billing_last_name: 'Khan', billing_address_1: '12 Marina Walk', billing_city: 'Dubai', billing_state: 'Dubai',
                billing_country: 'AE', payment_method: 'cod' };
            for (const k in set) fd.set(k, set[k]);
            const r = await fetch(f.action, { method: 'POST', credentials: 'same-origin', body: fd, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf } });
            return { status: r.status, body: await r.json().catch(() => null) };
        });
        result[`place-${w}`] = answer;
        await s.ctx.close();
    }

    fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    await browser.close();
    price(8500);
})().catch((e) => { console.error(e); process.exit(1); });
