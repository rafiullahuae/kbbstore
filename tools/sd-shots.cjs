/*
 * 2.60.396 screenshots: the strip on desktop / on mobile, apart, switched from
 * /super-sale/'s Edit header panel. Run against tools/ch-preview.sh:
 *   node tools/sd-shots.cjs <url> docs/sd-shots <width>
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.argv[2];
const OUT = path.resolve(process.argv[3] || 'docs/sd-shots');
const W = Number(process.argv[4] || 1280);
const PAGE = `${BASE}/super-sale/`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

const strip = (page) => page.evaluate(() => {
    const ul = document.querySelector('.kbb-pb-strip');
    if (!ul) return { strip: 'absent', sw: document.documentElement.scrollWidth };
    const r = ul.getBoundingClientRect();
    return { strip: getComputedStyle(ul).display === 'none' ? 'hidden' : 'shown', h: Math.round(r.height), cls: ul.className, sw: document.documentElement.scrollWidth };
});

(async () => {
    const phone = W < 900;
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const opts = { viewport: { width: W, height: phone ? 844 : 900 }, deviceScaleFactor: phone ? 2 : 1, userAgent: phone ? UA_PHONE : UA, isMobile: phone, hasTouch: phone };
    const ctx = await browser.newContext(opts);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    const openPanel = async () => {
        await page.goto(PAGE, { waitUntil: 'networkidle' });
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-phe .kbb-phe-strip');
    };
    const save = async () => {
        await page.click('.kbb-phe .kbb-phe-btn.is-primary');
        await page.waitForSelector('.kbb-phe', { state: 'detached', timeout: 15000 });
    };
    const shopper = async (name) => {
        const c = await browser.newContext(opts);
        const p = await c.newPage();
        await p.goto(PAGE, { waitUntil: 'networkidle' });
        await p.screenshot({ path: `${OUT}/${name}-${W}.png` });
        const m = await strip(p);
        console.log(JSON.stringify([W, name, m]));
        await c.close();
    };

    // 1. Strip switched on for the page: on both devices.
    await openPanel();
    await page.check('.kbb-phe-strip .kbb-phe-tog input >> nth=0');
    await save();
    await shopper('1-on-both');

    // 2. The panel with "On desktop" off: live preview, before Save.
    await openPanel();
    const dev = page.locator('.kbb-phe-strip .kbb-phe-tog').nth(1).locator('input');
    await dev.nth(0).uncheck();
    await page.locator('.kbb-phe-strip').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/2-panel-desktop-off-${W}.png` });
    console.log(JSON.stringify([W, '2-panel-preview', await strip(page)]));
    await save();
    await shopper('3-desktop-off');

    // 4. Desktop back on, mobile off.
    await openPanel();
    await dev.nth(0).check();
    await dev.nth(1).uncheck();
    await save();
    await shopper('4-mobile-off');

    console.log('ERRORS', JSON.stringify(errors));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
