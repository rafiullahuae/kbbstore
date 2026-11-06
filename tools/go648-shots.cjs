/*
 * 2.60.648 evidence (the owner, 6 Nov): the popup line that says only what
 * she gets, the cart row's clock as a stopwatch pill, and the confetti on
 * Claim. Run after `sh tools/go-preview.sh <port>`:
 *   GO_BASE=http://127.0.0.1:<port> node tools/go648-shots.cjs
 * Writes docs/lane-go-shots/648-*.png and 648-result.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const BASE = process.env.GO_BASE || 'http://127.0.0.1:9530';
const PORT = new URL(BASE).port;
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/lane-go-shots');
const r = {};
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
fs.mkdirSync(OUT, { recursive: true });

function tinker(what) {
    const out = execSync(`sh ${APP}/tools/go-tinker.sh ${APP}/tools/go-claim.php ${PORT}`, { env: { ...process.env, GO_DO: what }, encoding: 'utf8' });
    const m = out.match(/^(\w+)=(.*)$/m);
    return m ? m[2].trim() : null;
}

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const actx = await b.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2, userAgent: UA });
    const admin = await actx.newPage();
    await admin.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await admin.fill('input[name=email]', 'owner@preview.test');
    await admin.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle' }), admin.click('button[type=submit], input[type=submit]')]);
    const html = await admin.evaluate(async () => {
        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const res = await fetch('/admin-api/gift-offers/preview', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
            body: JSON.stringify({ place: 'popup', locale: 'en', config: { places: { popup: true }, popup: { design: 'A', style: 'S3', words: '3' } } }) });
        return (await res.json()).html;
    });
    const pp = await actx.newPage();
    const errors = [];
    pp.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    for (const w of [390, 1280]) {
        await pp.setViewportSize({ width: w, height: w === 390 ? 844 : 800 });
        await pp.goto(BASE + '/kbb-preview-id.txt');
        await pp.setContent(html, { waitUntil: 'load' });
        await pp.waitForTimeout(800);
        r['popup_text_' + w] = await pp.evaluate(() => (document.querySelector('.kgo-pp') || document.body).innerText.replace(/\s+/g, ' ').slice(0, 300));
        await pp.screenshot({ path: `${OUT}/648-popup-${w}.png` });
        await pp.click('[data-kgo-claim]');
        await pp.waitForTimeout(250);
        await pp.screenshot({ path: `${OUT}/648-claim-burst-${w}.png` });
        r['confetti_pieces_' + w] = await pp.evaluate(() => document.querySelectorAll('.kgo-cf b, .kgo-cf i').length);
        r['confetti_taps_through_' + w] = await pp.evaluate(() => { const c = document.querySelector('.kgo-cf'); return c ? getComputedStyle(c).pointerEvents : null; });
        await pp.waitForTimeout(700);
        await pp.screenshot({ path: `${OUT}/648-claim-rain-${w}.png` });
        await pp.waitForTimeout(3200);
        r['confetti_left_after_4s_' + w] = await pp.evaluate(() => document.querySelectorAll('.kgo-cf').length);
    }
    r.popup_console_errors = errors;

    for (const w of [390, 1280]) {
        const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2, userAgent: UA });
        const page = await ctx.newPage();
        const errs = [];
        page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
        const token = tinker('token');
        await page.goto(`${BASE}/gift-offer/c/${token}`, { waitUntil: 'networkidle' });
        tinker('fill');
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(2200);
        const row = page.locator('.kgo-r').first();
        await row.scrollIntoViewIfNeeded();
        r['row_' + w] = await row.evaluate((e) => ({ h: e.getBoundingClientRect().height, w: e.getBoundingClientRect().width, clock: e.querySelector('.kgo-clk').textContent,
            pill_bg: getComputedStyle(e.querySelector('.kgo-tw')).backgroundColor, font: getComputedStyle(e.querySelector('.kgo-clk')).fontFamily }));
        r['scrollWidth_' + w] = await page.evaluate(() => document.documentElement.scrollWidth);
        r['cart_console_errors_' + w] = errs;
        const bx = await row.boundingBox();
        await page.screenshot({ path: `${OUT}/648-cart-row-${w}.png`, clip: { x: Math.max(0, bx.x - 16), y: Math.max(0, bx.y - 70), width: Math.min(w, bx.width + 32), height: bx.height + 140 } });
        await ctx.close();
    }
    fs.writeFileSync(`${OUT}/648-result.json`, JSON.stringify(r, null, 2));
    console.log(JSON.stringify(r, null, 1));
    await b.close();
})();
