/*
 * Lane SA — reproduce and MEASURE the cart-page set row before touching it.
 *                                                                    (Lane SA)
 *
 * The owner sent a screenshot of the cart page with a red arrow at the set
 * line: *"the only this i need is the row spacing i need little bit up spacing
 * or give control for set rows too on backend for cart page."* In that picture
 * the set's product name reads as though the divider above it is crossing it.
 *
 * So this script does not change anything and does not draw anything pretty. It
 * answers four questions with numbers, at 390 and at 1280:
 *
 *   1. how tall is a SET row, and how tall is a PLAIN row beside it
 *   2. how many pixels are there between the divider above a row and the top of
 *      that row's product NAME — on a set row and on a plain row
 *   3. does anything actually OVERLAP: is the name's top above the divider
 *   4. document.documentElement.scrollWidth, including with a twelve-member set
 *      on the page
 *
 * It measures in the BROWSER with getBoundingClientRect, which is the right
 * tool for an evidence script and is exactly what CLAUDE.md forbids in shipped
 * page code. Nothing here is shipped: the rule is about how the shop sizes
 * itself, not about how a lane proves what it did.
 */
const { chromium } = require('playwright');

const BASE = process.env.SA_BASE || 'http://127.0.0.1:8971';

const PROBE = () => {
    const out = { viewport: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth, rows: [] };
    const px = (el, p) => (el ? Math.round(parseFloat(getComputedStyle(el)[p]) * 100) / 100 : null);

    document.querySelectorAll('.kbb-cartpage .items .ci').forEach((row, i) => {
        const name = row.querySelector('.cn');
        const kset = row.querySelector('.kset');
        const rect = row.getBoundingClientRect();
        const prev = row.previousElementSibling;

        out.rows.push({
            i,
            isSet: !!kset,
            name: name ? name.textContent.trim().slice(0, 34) : null,
            rowHeight: Math.round(rect.height * 100) / 100,
            rowPadTop: px(row, 'paddingTop'),
            rowPadBottom: px(row, 'paddingBottom'),
            // The divider is the PREVIOUS row's border-bottom, so its y is that
            // row's bottom edge.
            dividerY: prev ? Math.round(prev.getBoundingClientRect().bottom * 100) / 100 : null,
            nameTopY: name ? Math.round(name.getBoundingClientRect().top * 100) / 100 : null,
            gapDividerToName: prev && name
                ? Math.round((name.getBoundingClientRect().top - prev.getBoundingClientRect().bottom) * 100) / 100
                : null,
            ksetMarginTop: kset ? px(kset, 'marginTop') : null,
            ksetMarginBottom: kset ? px(kset, 'marginBottom') : null,
            ksetHeight: kset ? Math.round(kset.getBoundingClientRect().height * 100) / 100 : null,
            // Does the set block collide with the row below it?
            ksetBottomToRowBottom: kset
                ? Math.round((rect.bottom - kset.getBoundingClientRect().bottom) * 100) / 100
                : null,
            circles: row.querySelectorAll('.kset-c').length,
            fanWidth: row.querySelector('.kset-fan')
                ? Math.round(row.querySelector('.kset-fan').getBoundingClientRect().width * 100) / 100
                : null,
            fanRight: row.querySelector('.kset-fan')
                ? Math.round(row.querySelector('.kset-fan').getBoundingClientRect().right * 100) / 100
                : null,
            midWidth: row.querySelector('.cmid')
                ? Math.round(row.querySelector('.cmid').getBoundingClientRect().width * 100) / 100
                : null,
        });
    });

    return out;
};

(async () => {
    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    // Fill the basket through the shop's own door: cookies are encrypted, so a
    // planted token is not a basket the server can read.
    await page.goto(`${BASE}/product/lanesa-glow-starter-set/`, { waitUntil: 'networkidle' });

    const added = await page.evaluate(async (base) => {
        const token = decodeURIComponent(
            (document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)') || [])[2] || ''
        );
        const out = [];
        for (const slug of ['lanesa-glow-starter-set', 'lanesa-ceramide-moisturiser', 'lanesa-full-routine-set']) {
            const r0 = await fetch(base + '/product/' + slug + '/', { credentials: 'same-origin' });
            const html = await r0.text();
            const m = html.match(/data-product_id="(\d+)"/);
            if (!m) { out.push([slug, 'no id']); continue; }
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

    console.log(JSON.stringify({ addedToBasket: added }));

    for (const w of [390, 1280]) {
        await page.setViewportSize({ width: w, height: 1400 });
        await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);
        const m = await page.evaluate(PROBE);
        console.log(JSON.stringify({ width: w, ...m }, null, 1));
    }

    await browser.close();
})();
