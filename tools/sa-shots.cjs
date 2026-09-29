/*
 * Lane SA evidence. Chromium at 390 and 1280, the shots the owner asked for and
 * the numbers under each of them.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * WHAT IS MEASURED, and why each number is in the list:
 *
 *   document.documentElement.scrollWidth   with a TWELVE-member set on the
 *                                          page and with the popup OPEN. A fan
 *                                          that widens a 390px page gives the
 *                                          whole shop a horizontal scrollbar,
 *                                          which is what it did at 320 and 360
 *                                          before this branch.
 *   the set block's own margins            the two deliberate default moves.
 *   divider -> name, set row and plain     the owner's screenshot reads as a
 *                                          collision; these two numbers are
 *                                          what says whether it is one.
 *   the computed value of every property   so "this control drives that" is a
 *     a control claims to drive            measurement and not a claim.
 *   query count                            read off the page's own debug
 *                                          header where the preview exposes it.
 *
 * It measures with getBoundingClientRect and getComputedStyle, which is the
 * right tool for an evidence script and exactly what CLAUDE.md forbids in
 * SHIPPED page code. Nothing here is shipped: the rule is about how the shop
 * sizes itself, not about how a lane proves what it did.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.SA_BASE || 'http://127.0.0.1:8971';
const OUT = process.env.SA_OUT || '/home/user/lane-sa/docs/lane-sa-shots';
/* The same script is run twice — once at the shipped defaults and once with
   several controls moved — and the prefix is what keeps the two sets of
   pictures apart. "Shows they actually move" is a comparison, so both halves of
   it have to come out of the same harness or the comparison is between two
   harnesses. */
const TAG = process.env.SA_TAG || '';

const MEASURE = (scope) => {
    const root = document.querySelector(scope) || document;
    const cs = (el, p) => (el ? getComputedStyle(el)[p] : null);
    const r = (el) => (el ? el.getBoundingClientRect() : null);
    const round = (n) => (n == null ? null : Math.round(n * 100) / 100);

    const rows = [...document.querySelectorAll('.kbb-cartpage .items .ci')];
    const setRow = rows.find((x) => x.querySelector('.kset'));
    const plainRow = rows.find((x) => !x.querySelector('.kset'));

    const gapAbove = (row) => {
        if (!row) return null;
        const prev = row.previousElementSibling;
        const name = row.querySelector('.cn');
        if (!prev || !name) return null;
        return round(r(name).top - r(prev).bottom);
    };

    const kset = root.querySelector('.kset');
    const circles = [...root.querySelectorAll('.kset-c')];
    const pop = root.querySelector('.kset-pop');

    return {
        viewport: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        styleBlockPresent: !!document.querySelector('#kbb-set'),

        // The two deliberate default moves.
        ksetMarginTop: cs(kset, 'marginTop'),
        ksetMarginBottom: cs(kset, 'marginBottom'),
        ksetGap: cs(kset, 'gap'),

        // The owner's "is the divider crossing the name" question, both rows.
        gapDividerToSetName: gapAbove(setRow),
        gapDividerToPlainName: gapAbove(plainRow),
        cartRowPadding: cs(rows[1] || rows[0], 'padding'),
        cartNameMarginBottom: cs((rows[1] || rows[0] || document).querySelector?.('.cn'), 'marginBottom'),

        // The fan.
        circles: circles.length,
        circleWidth: round(r(circles[0])?.width),
        circleHeight: round(r(circles[0])?.height),
        circleRing: cs(circles[0], 'boxShadow'),
        fanWidth: round(r(root.querySelector('.kset-fan'))?.width),
        widestFanWidth: round(Math.max(0, ...[...document.querySelectorAll('.kset-fan')].map((f) => r(f).width))),

        // The opener and the popup.
        openerFontSize: cs(root.querySelector('.kset-btn'), 'fontSize'),
        openerFontWeight: cs(root.querySelector('.kset-btn'), 'fontWeight'),
        openerPadding: cs(root.querySelector('.kset-btn'), 'padding'),
        openerColor: cs(root.querySelector('.kset-btn'), 'color'),
        ariaExpanded: root.querySelector('.kset-btn')?.getAttribute('aria-expanded') ?? null,
        popupOpen: !!root.querySelector('.kset-pop.is-open'),
        popupWidth: round(r(root.querySelector('.kset-pop.is-open'))?.width),
        popupRightEdge: round(r(root.querySelector('.kset-pop.is-open'))?.right),
        popupLeftEdge: round(r(root.querySelector('.kset-pop.is-open'))?.left),
        popupPadding: cs(pop, 'padding'),
        popupRadius: cs(pop, 'borderRadius'),
        popupShadow: cs(pop, 'boxShadow'),
        popupLineSize: cs(root.querySelector('.kset-pop li'), 'fontSize'),
        headingSize: cs(root.querySelector('.kset-pop h4'), 'fontSize'),
        savingSize: cs(root.querySelector('.kset-save'), 'fontSize'),
        savingColor: cs(root.querySelector('.kset-save'), 'color'),

        // The close control.
        closeButtons: root.querySelectorAll('.kset-close').length,
        closeDisplay: cs(root.querySelector('.kset-close'), 'display'),
        closeWidth: round(r(root.querySelector('.kset-close'))?.width),
        closeHeight: round(r(root.querySelector('.kset-close'))?.height),
        closeColor: cs(root.querySelector('.kset-close'), 'color'),
        closeRightEdge: round(r(root.querySelector('.kset-close'))?.right),
        closeLeftEdge: round(r(root.querySelector('.kset-close'))?.left),
        popupPaddingInlineEnd: cs(pop, 'paddingInlineEnd'),

        // The product page's list.
        kslRows: document.querySelectorAll('.ksl-r').length,
        kslPhoto: cs(document.querySelector('.ksl-ph'), 'width'),
        kslPhotoRadius: cs(document.querySelector('.ksl-ph'), 'borderRadius'),
        kslRowPad: cs(document.querySelector('.ksl-r'), 'padding'),
        kslName: cs(document.querySelector('.ksl-nm'), 'fontSize'),
        kslBrand: cs(document.querySelector('.ksl-br'), 'fontSize'),
        kslQty: cs(document.querySelector('.ksl-q'), 'fontSize'),
        kslFoot: cs(document.querySelector('.ksl-f'), 'fontSize'),
        kslFolds: document.querySelectorAll('.ksl-more').length,
        kslShown: document.querySelectorAll('.ksl-rows .ksl-r').length,
        dir: document.documentElement.getAttribute('dir'),
    };
};

async function shoot(page, name, w, h, scope = 'body', fullPage = true) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(400);
    const m = await page.evaluate(MEASURE, scope);
    await page.screenshot({ path: `${OUT}/${TAG}${name}-${w}.png`, fullPage });
    console.log(JSON.stringify({ shot: `${TAG}${name}-${w}`, ...m }));
    return m;
}

async function fillBasket(page) {
    await page.goto(`${BASE}/product/lanesa-glow-starter-set/`, { waitUntil: 'networkidle' });

    /* THROUGH THE SHOP'S OWN DOOR. This application encrypts cookies, so a raw
       token planted from outside is not a basket the server can read — the page
       renders empty and the shot proves nothing. Posting to the same endpoint
       the Add-to-cart button posts to also means these shots exercise the real
       add path. */
    return page.evaluate(async (base) => {
        const token = decodeURIComponent(
            (document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)') || [])[2] || ''
        );
        const out = [];
        for (const slug of ['lanesa-glow-starter-set', 'lanesa-ceramide-moisturiser', 'lanesa-full-routine-set']) {
            const html = await (await fetch(base + '/product/' + slug + '/', { credentials: 'same-origin' })).text();
            const m = html.match(/data-product_id="(\d+)"/);
            if (!m) { out.push([slug, 'no id on page']); continue; }
            const r = await fetch(base + '/api/cart/add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ product_id: Number(m[1]), quantity: 1 }),
            });
            out.push([slug, r.status]);
        }
        return out;
    }, BASE);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });

    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });

    /* TWO CONTEXTS, and the difference is the whole of the third owner ask.
       `hasTouch` + a coarse pointer is what makes Chromium report
       `(hover:none) and (pointer:coarse)`, which is the branch the close button
       lives on and hover-to-open does not. A single desktop context would have
       shot the cross and the hover rule in the same picture, and both would
       have been wrong. */
    const desktop = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
    const touch = await browser.newContext({
        viewport: { width: 390, height: 1400 }, deviceScaleFactor: 2,
        hasTouch: true, isMobile: true,
    });

    const page = await desktop.newPage();
    console.log(JSON.stringify({ addedToBasket: await fillBasket(page) }));

    /* ── the cart page, closed and open ─────────────────────────────────── */
    for (const w of [390, 1280]) {
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await shoot(page, 'cart-page', w, 1500, '.items');
        await page.click('.items .kset-btn');
        await page.waitForTimeout(250);
        await shoot(page, 'cart-page-popup-open', w, 1500, '.items');
    }

    /* ── the checkout summary, where the popup opens upward and, on a phone,
          escapes the summary panel's own overflow:hidden ─────────────────── */
    for (const w of [390, 1280]) {
        await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
        await shoot(page, 'checkout', w, 1600, 'body');
        const btn = await page.$('.kbb-checkout .kset-btn');
        if (btn) { await btn.click(); await page.waitForTimeout(250); }
        await shoot(page, 'checkout-popup-open', w, 1600, 'body');
    }

    /* ── the set's own product page: the buy column's list ──────────────── */
    for (const w of [390, 1280]) {
        await page.goto(`${BASE}/product/lanesa-glow-starter-set/`, { waitUntil: 'networkidle' });
        await shoot(page, 'product-set-3', w, 1600, 'body');
        await page.goto(`${BASE}/product/lanesa-full-routine-set/`, { waitUntil: 'networkidle' });
        await shoot(page, 'product-set-12', w, 1600, 'body');
        await page.goto(`${BASE}/product/lanesa-empty-set/`, { waitUntil: 'networkidle' });
        await shoot(page, 'product-set-empty', w, 1200, 'body');
    }

    /* ── Arabic ─────────────────────────────────────────────────────────── */
    for (const w of [390, 1280]) {
        await page.goto(`${BASE}/ar/cart/`, { waitUntil: 'networkidle' });
        await shoot(page, 'ar-cart', w, 1500, '.items');
        const btn = await page.$('.items .kset-btn');
        if (btn) { await btn.click(); await page.waitForTimeout(250); }
        await shoot(page, 'ar-cart-popup-open', w, 1500, '.items');
        await page.goto(`${BASE}/ar/product/lanesa-glow-starter-set/`, { waitUntil: 'networkidle' });
        await shoot(page, 'ar-product-set', w, 1600, 'body');
    }

    /* ── the narrow widths the fan used to break ────────────────────────── */
    for (const w of [320, 360]) {
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await shoot(page, 'cart-page-narrow', w, 1500, '.items');
    }

    /* ── THE TOUCH DEVICE: the close button, which a pointer never sees ─── */
    const tpage = await touch.newPage();
    await fillBasket(tpage);

    await tpage.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    await shoot(tpage, 'touch-cart', 390, 1500, '.items');
    await tpage.tap('.items .kset-btn');
    await tpage.waitForTimeout(250);
    await shoot(tpage, 'touch-cart-popup-open', 390, 1500, '.items');

    /* AND THE CROSS REALLY CLOSES IT. Checked here, on the page that has an
       open popup — the first run of this script asked the question after
       navigating away and got "no close button", which is a harness fault that
       reads exactly like a missing feature. */
    console.log(JSON.stringify({
        crossCloses: await tpage.evaluate(() => {
            const btn = document.querySelector('.items .kset-close');
            if (!btn) return 'no close button';
            const before = !!document.querySelector('.items .kset-pop.is-open');
            btn.click();
            return {
                openBefore: before,
                openAfter: !!document.querySelector('.items .kset-pop.is-open'),
                ariaExpanded: document.querySelector('.items .kset-btn').getAttribute('aria-expanded'),
                focusIsOpener: document.activeElement === document.querySelector('.items .kset-btn'),
            };
        }),
    }));

    await tpage.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
    const cbtn = await tpage.$('.kbb-checkout .kset-btn');
    if (cbtn) { await cbtn.tap(); await tpage.waitForTimeout(250); }
    await shoot(tpage, 'touch-checkout-popup-open', 390, 1600, 'body');

    await tpage.goto(`${BASE}/ar/cart/`, { waitUntil: 'networkidle' });
    const abtn = await tpage.$('.items .kset-btn');
    if (abtn) { await abtn.tap(); await tpage.waitForTimeout(250); }
    await shoot(tpage, 'touch-ar-cart-popup-open', 390, 1500, '.items');

    /* ── HOVER, on the desktop context, with no click at all ────────────── */
    await page.setViewportSize({ width: 1280, height: 1400 });
    await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    await page.hover('.items .kset-btn');
    await page.waitForTimeout(250);
    console.log(JSON.stringify({
        shot: 'hover-opens-1280',
        ...(await page.evaluate((s) => {
            const root = document.querySelector(s);
            const pop = root.querySelector('.kset-pop');
            return {
                popupVisible: getComputedStyle(pop).display,
                usedIsOpenClass: pop.classList.contains('is-open'),
                ariaExpanded: root.querySelector('.kset-btn').getAttribute('aria-expanded'),
                closeVisible: getComputedStyle(root.querySelector('.kset-close')).display,
            };
        }, '.items')),
    }));
    await page.screenshot({ path: `${OUT}/hover-opens-1280.png`, fullPage: false });

    /* The pointer parked ON the popup: the bridge is what keeps it open across
       the gap, and this is the only way to know the bridge works. */
    const pop = await page.$('.items .kset-pop');
    const bb = await pop.boundingBox();
    await page.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2);
    await page.waitForTimeout(250);
    console.log(JSON.stringify({
        pointerParkedOnPopup: await page.evaluate(
            () => getComputedStyle(document.querySelector('.items .kset-pop')).display
        ),
    }));
    await page.screenshot({ path: `${OUT}/hover-pointer-on-popup-1280.png`, fullPage: false });

    await browser.close();
})();
