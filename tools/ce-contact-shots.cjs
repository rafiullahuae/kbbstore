/*
 * Store -> Customers -> (a customer) -> Edit email / phone, photographed. (Lane CE)
 *
 *   01-customer   Jovana's page: "jovana8b@yahoo.co · 00971504697062  [Edit]"
 *   02-hint       the dialog: "Did you mean jovana8b@yahoo.com?" [Use it] [Keep as typed]
 *   03-checks     after "Use it": "Also correct it on their 3 orders" (ticked) and
 *                 "Send the order confirmation again to jovana8b@yahoo.com" ticked, order picked
 *   04-confirm    the confirmation step
 *   05-done       the result
 *   06-customer   her page afterwards
 *   07-order      the order screen with the two notes, and its "Edit email / phone"
 *   08-account    (CE_WHO=sara) a registered customer: the sign-in warning, gmial.com hint
 *
 * Usage: sh tools/ce-contact-preview.sh            (prints the port; reseeds)
 *        CE_W=1280 node tools/ce-contact-shots.cjs
 *        sh tools/ce-contact-preview.sh && CE_W=390 node tools/ce-contact-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.CE_BASE || 'http://127.0.0.1:9640';
const W = +(process.env.CE_W || 1280);
const H = W < 600 ? 844 : 900;
const WHO = process.env.CE_WHO || 'jovana';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.CE_OUT || 'storage/ce-logs/shots');
const IDS = JSON.parse(fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-ce-contact-preview/webroot/ce-ids.json'), 'utf8'));

const sw = (page) => page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = []; let stage = 'login';
    const requests = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + JSON.stringify(m.location()) + ' after ' + stage); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/customers/') && r.url().includes('contact')) requests.push(r.method() + ' ' + r.url().replace(BASE, '')); });

    const shot = async (name, sel) => {
        const file = path.join(OUT, `${W}-${WHO}-${name}.png`);
        if (sel) await page.locator(sel).screenshot({ path: file });
        else await page.screenshot({ path: file, fullPage: true });
        return file;
    };
    const m = {};

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);

    stage = 'admin-loaded';
    const id = IDS[WHO];
    await page.evaluate(() => window.go('customers'));
    await page.waitForSelector(`[data-cuview="${id}"]`, { timeout: 15000 });
    await page.click(`[data-cuview="${id}"]`);
    await page.waitForSelector('#cuEditContact', { timeout: 15000 });
    await page.waitForTimeout(300);
    if (WHO === 'jovana') await shot('01-customer');
    m.customer = await sw(page);

    // The Edit button answers a real click on the first try.
    m.editHit = await page.evaluate(() => {
        const b = document.getElementById('cuEditContact').getBoundingClientRect();
        const el = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
        return el && el.id === 'cuEditContact';
    });
    await page.click('#cuEditContact');
    await page.waitForSelector('#kxeEmail', { timeout: 15000 });
    await page.waitForSelector('#kxeHint');
    m.hint = await page.textContent('#kxeHint');
    await shot(WHO === 'jovana' ? '02-hint' : '08-account', '#kxe');
    m.dialog = await sw(page);

    if (WHO === 'sara') {
        // Type a typo by hand, keystroke by keystroke: the hint follows, no request goes out.
        const before = requests.length;
        await page.fill('#kxeEmail', '');
        await page.type('#kxeEmail', 'sara.khan@hotmial.com', { delay: 15 });
        m.typedHint = await page.textContent('#kxeHint');
        m.requestsWhileTyping = requests.length - before;
        await page.click('[data-kxe="keep"]');
        m.hintAfterKeep = await page.locator('#kxeHint').count();
        await shot('09-account-keep', '#kxe');
        console.log(JSON.stringify({ W, WHO, m, errors, requests }, null, 1));
        await browser.close();
        return;
    }

    await page.click('[data-kxe="use"]');
    m.emailAfterUse = await page.inputValue('#kxeEmail');
    m.followLabel = (await page.textContent('label.kxe-chk:nth-of-type(1)')).trim();
    await page.check('#kxeResend');
    await page.waitForSelector('#kxeOrder');
    m.pick = await page.$$eval('#kxeOrder option', (o) => o.map((x) => x.textContent));
    await shot('03-checks', '#kxe');

    await page.click('#kxeSave');
    await page.waitForSelector('#kxeSend');
    await shot('04-confirm', '#kxe');

    await page.click('#kxeSend');
    await page.waitForSelector('.kxe-ok', { timeout: 15000 });
    m.done = await page.textContent('.kxe-ok');
    await shot('05-done', '#kxe');

    await page.click('[data-kxe="close"]');
    await page.waitForSelector('#cuEditContact', { timeout: 15000 });
    await page.waitForTimeout(400);
    await shot('06-customer');

    await page.evaluate(() => window.go('orders'));
    const orderId = 2; // 56302, processing -- the one the latest-order default picks
    await page.waitForSelector(`[data-olview="${orderId}"]`, { timeout: 15000 });
    await page.click(`[data-olview="${orderId}"]`);
    await page.waitForSelector('#odCustContact', { timeout: 15000 });
    await page.waitForTimeout(500);
    m.orderNotes = await page.evaluate(() => Array.from(document.querySelectorAll('#content *')).filter((e) => e.children.length === 0 && /re-sent to|Contact corrected/.test(e.textContent)).map((e) => e.textContent.trim()));
    m.order = await sw(page);
    await shot('07-order');
    const noteCard = page.locator('#content *:text-matches("Order confirmation re-sent to", "i")').first();
    await noteCard.scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    const box = await noteCard.evaluateHandle((e) => e.closest('.odcard, .card') || e.parentElement);
    await box.asElement().screenshot({ path: path.join(OUT, `${W}-${WHO}-07b-order-notes.png`) });

    console.log(JSON.stringify({ W, WHO, m, errors, requests }, null, 1));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
