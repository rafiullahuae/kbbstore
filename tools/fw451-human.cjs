/*
 * Lane FW: a fast human, in Chromium, with InstantNav's hover-prefetch on.
 * node tools/fw451-human.cjs <base> <width>
 *
 * Skims like an impatient shopper from an ad: hovers every product card for a
 * quarter of a second (long enough for "moderate" eagerness to prefetch),
 * opens a category, a product, adds to cart, goes back, opens the next — as
 * fast as the pages allow, for about two minutes. The server side
 * (tools/fw451-router.php) logs every request the application would handle;
 * tools/fw451-peak.py turns that log into the peak per 10 s and per 60 s.
 */
const { chromium } = require('playwright');
const [BASE, WS] = process.argv.slice(2);
const W = Number(WS || 1280);
const phone = W < 900;
(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, isMobile: phone, hasTouch: phone });
    const p = await c.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    const t0 = Date.now();
    const hoverAll = async (sel, n, ms) => {
        const links = await p.$$(sel);
        for (const a of links.slice(0, n)) {
            try { await a.hover({ timeout: 800 }); } catch (e) {}
            await p.waitForTimeout(ms);
        }
        return links.length;
    };
    await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    let visited = 0;
    while (Date.now() - t0 < 120000) {
        await hoverAll('a[href*="/product/"], a[href*="/collections/"], a[href*="/brands/"]', 12, 250);
        const cats = await p.$$('a[href*="/collections/"], a[href*="/shop"]');
        if (cats.length) {
            await Promise.all([p.waitForLoadState('domcontentloaded'), cats[visited % cats.length].click({ timeout: 3000 }).catch(() => {})]);
        }
        await p.mouse.wheel(0, 900);
        await hoverAll('a[href*="/product/"]', 12, 250);
        const prods = await p.$$('a[href*="/product/"]');
        if (prods.length) {
            await Promise.all([p.waitForLoadState('domcontentloaded'), prods[(visited * 3) % prods.length].click({ timeout: 3000 }).catch(() => {})]);
            await p.waitForTimeout(400);
            const add = await p.$('[data-kbb-add], [data-add], button:has-text("Add to")');
            if (add) { await add.click({ timeout: 2000 }).catch(() => {}); await p.waitForTimeout(500); }
            await p.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
        }
        visited++;
        if (visited % 3 === 0) await p.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    }
    console.log(JSON.stringify({ width: W, rounds: visited, seconds: Math.round((Date.now() - t0) / 1000), errors }));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
