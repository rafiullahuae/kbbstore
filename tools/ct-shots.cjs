/* 2.60.396: the category "Edit header" panel, title header only. node tools/ct-shots.cjs <url> <out> <width> */
const { chromium } = require('playwright');
const [BASE, OUT, WS] = process.argv.slice(2);
const W = Number(WS);
const UA = W < 900 ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'
    : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const phone = W < 900;
    const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 1000 }, deviceScaleFactor: phone ? 2 : 1, userAgent: UA, isMobile: phone, hasTouch: phone });
    const p = await c.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test');
    await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
    await p.goto(`${BASE}/collections/sunscreens/`, { waitUntil: 'networkidle' });
    await p.click('.kbb-qe-pill');
    await p.waitForSelector('.kbb-che');
    const m = await p.evaluate(() => ({
        tabs: document.querySelectorAll('.kbb-che [role=tab]').length,
        sections: [...document.querySelectorAll('.kbb-che .kbb-phe-h')].map((e) => e.textContent.trim()).slice(0, 8),
        sw: document.documentElement.scrollWidth,
    }));
    await p.screenshot({ path: `${OUT}/category-panel-title-only-${W}.png` });
    console.log(JSON.stringify([W, m]), 'ERRORS', JSON.stringify(errors));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
