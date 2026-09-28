/*
 * Lane JS screenshots: the storefront's JavaScript security sweep.
 *
 * WHAT MAKES THIS MORE THAN A PICTURE OF A PAGE. Three seeded values are
 * hostile and the harness MEASURES what the browser made of each, rather than
 * describing it:
 *
 *   drawer     — every `<a>` in the mobile drawer, and its href AS THE BROWSER
 *                RESOLVED IT (`a.href`, not the attribute). Before the fix a
 *                `javascript:alert(1)` menu row resolved to a javascript: URL
 *                and ran on tap; after it, the anchor's href is `#`.
 *
 *   reviewImgs — every `<img>` in the review modal, its `src` attribute and
 *                whether it is one of the shop's own. Before the fix a review
 *                with a `javascript:` and a protocol-relative photo address
 *                produced three <img> elements; after it, one.
 *
 *   injected   — every network request whose PATH IS `/uploads/js/injected.png`.
 *                EXACT PATH, not includes(): after the fix the browser may
 *                still fetch one address that CONTAINS that substring (an
 *                escaped string as a single broken URL on this shop's own
 *                host), and a substring test would count that as a hit and
 *                report the fix as no fix at all.
 *
 *   cartError  — the cart notice's innerHTML after a refused coupon, plus how
 *                many ELEMENTS the notice contains. An escaped message is one
 *                element with text in it; an unescaped one grows a child the
 *                server never sent.
 *
 * An ordinary menu row, an ordinary review photo and an ordinary page are
 * measured beside each, because "nothing that already works may change" is the
 * other half of the claim and the only proof of it is the same numbers.
 *
 * Usage: node tools/js-shots.cjs <width> <base-url> <out-dir> <label>
 */
const { chromium } = require('playwright');
const fs = require('fs');

const WIDTH = +process.argv[2];
const BASE = process.argv[3];
const OUT = process.argv[4];
const LABEL = process.argv[5];
const CHROME = process.env.JS_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const wait = (p, ms) => p.waitForTimeout(ms);

/* The numbers every page is judged on, hostile or not. `scrollWidth` is here
   because horizontal overflow is what a restyle causes first, and this lane
   must not cause one. */
const frame = (page, name) => page.evaluate((n) => ({
    page: n,
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}), name);

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    const browser = await chromium.launch({ executablePath: CHROME });
    const ctx = await browser.newContext({ viewport: { width: WIDTH, height: 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    let injected = [];
    page.on('request', (r) => {
        let path = null;
        try { path = new URL(r.url()).pathname; } catch (e) { path = null; }
        if (path === '/uploads/js/injected.png') injected.push(r.url());
    });

    // A javascript: href that actually fires would raise a dialog and hang the
    // run; this answers it and records that it happened, which is the loudest
    // possible failure signal.
    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });

    const out = [];
    const shot = async (name) => {
        await page.screenshot({ path: `${OUT}/${LABEL}-${name}-${WIDTH}.png`, fullPage: false });
    };

    /* ------------------------------------------------- 1. the drawer --- */

    injected = [];
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await wait(page, 400);
    out.push({ ...(await frame(page, 'home')), injectedRequests: injected.length });
    await shot('home');

    // The drawer is a phone control; on a wide viewport the burger is hidden,
    // so it is opened through the same function the burger calls.
    await page.evaluate(() => window.kbbOpenDrawer && window.kbbOpenDrawer('mnav'));
    await wait(page, 500);

    const drawer = await page.evaluate(() => {
        const rows = [...document.querySelectorAll('#mlist a, #mlist button')];
        return {
            rows: rows.length,
            // `a.href` is the RESOLVED url — what the browser will actually do
            // with it — and the attribute is what the markup says. Both, so a
            // reader can see the escape and the gate separately.
            links: rows.filter((el) => el.tagName === 'A').map((el) => ({
                label: el.textContent.trim().slice(0, 20),
                attr: el.getAttribute('href'),
                resolved: el.href,
            })),
        };
    });

    out.push({ page: 'drawer-top', ...drawer });
    await shot('drawer');

    // The second level, which has three href sites of its own.
    await page.evaluate(() => {
        const t = document.querySelector('#mlist [data-kbb-msub]');
        if (t) t.click();
    });
    await wait(page, 400);

    out.push({
        page: 'drawer-sub',
        ...(await page.evaluate(() => {
            const rows = [...document.querySelectorAll('#msub a')];
            return {
                rows: rows.length,
                links: rows.map((el) => ({
                    label: el.textContent.trim().slice(0, 20),
                    attr: el.getAttribute('href'),
                    resolved: el.href,
                })),
            };
        })),
    });
    await shot('drawer-sub');

    /* ------------------------------------------- 2. the review modal --- */

    injected = [];
    await page.goto(`${BASE}/product/js-sweep-serum/`, { waitUntil: 'networkidle' });
    await wait(page, 500);
    out.push({ ...(await frame(page, 'product')), injectedRequests: injected.length });
    await shot('product');

    // The hostile review first — it is the one seeded with three photo
    // addresses, two of which must never become an <img>.
    const openReview = async (which) => {
        await page.evaluate((w) => {
            const cards = [...document.querySelectorAll('.sr-card')];
            const card = cards.find((c) => (c.querySelector('.sr-data')?.textContent || '').includes(w));
            if (card) card.click();
        }, which);
        await wait(page, 600);
    };

    injected = [];
    await openReview('Imported Row');
    out.push({
        page: 'review-modal-hostile',
        injectedRequests: injected.length,
        ...(await page.evaluate(() => {
            const imgs = [...document.querySelectorAll('[data-sr-mbody] img')];
            return {
                imgs: imgs.length,
                srcs: imgs.map((i) => i.getAttribute('src')),
                resolved: imgs.map((i) => i.src),
                // A photo that loaded is one the browser could decode; a
                // refused address never becomes an element at all.
                complete: imgs.map((i) => i.complete && i.naturalWidth > 0),
            };
        })),
    });
    await shot('review-modal-hostile');

    await page.keyboard.press('Escape');
    await wait(page, 300);

    injected = [];
    await openReview('Ordinary Shopper');
    out.push({
        page: 'review-modal-ordinary',
        injectedRequests: injected.length,
        ...(await page.evaluate(() => {
            const imgs = [...document.querySelectorAll('[data-sr-mbody] img')];
            return {
                imgs: imgs.length,
                srcs: imgs.map((i) => i.getAttribute('src')),
                complete: imgs.map((i) => i.complete && i.naturalWidth > 0),
            };
        })),
    });
    await shot('review-modal-ordinary');

    await page.keyboard.press('Escape');
    await wait(page, 300);

    /* --------------------------------------------- 3. the cart notice --- */

    // A refused coupon is the one path that fills #kbbCartNotices. The response
    // is intercepted so the message carries markup — which is the value the
    // server could one day send and the browser's handling of it is the thing
    // under test, not which PHP file composed it.
    await page.goto(`${BASE}/product/js-sweep-serum/`, { waitUntil: 'networkidle' });
    await page.click('#mainAdd');
    await wait(page, 1200);

    await page.route('**/coupon*', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        body.error = 'That code is not valid.<img src=/uploads/js/injected.png onerror=alert(9)>';
        await route.fulfill({ response, body: JSON.stringify(body) });
    });

    injected = [];
    await page.goto(`${BASE}/cart`, { waitUntil: 'networkidle' });
    await wait(page, 500);
    out.push({ ...(await frame(page, 'cart')), injectedRequests: injected.length });
    await shot('cart');

    injected = [];
    await page.evaluate(() => {
        const field = document.getElementById('kbbCartCoupon');
        if (!field) return;
        field.value = 'NOPE';
        field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    });
    await wait(page, 1500);

    out.push({
        page: 'cart-error',
        injectedRequests: injected.length,
        dialogs: dialogs.length,
        ...(await page.evaluate(() => {
            const n = document.getElementById('kbbCartNotices');
            return {
                html: n ? n.innerHTML : null,
                text: n ? n.textContent.trim() : null,
                // One element and no more: an escaped message cannot grow a
                // child the server never sent.
                elements: n ? n.querySelectorAll('*').length : 0,
                injectedImgs: n ? n.querySelectorAll('img').length : 0,
            };
        })),
    });
    await shot('cart-error');

    /* ------------------------------------ 4. the search panel, intact --- */

    // Not a fix of this lane's — but its four escapers MOVED to safe.js, so the
    // panel is photographed to prove the move changed nothing.
    injected = [];
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.evaluate(() => {
        const i = document.querySelector('#kbbSearch, [data-kbb-search] input, input[name="s"]');
        if (i) { i.focus(); i.value = 'serum'; i.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    await wait(page, 1500);

    out.push({
        page: 'search-panel',
        injectedRequests: injected.length,
        ...(await page.evaluate(() => {
            const rows = [...document.querySelectorAll('[data-sg-row]')];
            return {
                rows: rows.length,
                hrefs: rows.slice(0, 4).map((r) => r.getAttribute('href')),
            };
        })),
    });
    await shot('search-panel');

    out.push({ page: 'TOTALS', dialogsRaised: dialogs.length, dialogMessages: dialogs });

    fs.writeFileSync(`${OUT}/${LABEL}-measurements-${WIDTH}.json`, JSON.stringify(out, null, 2));
    console.log(JSON.stringify(out, null, 2));

    await browser.close();
})();
