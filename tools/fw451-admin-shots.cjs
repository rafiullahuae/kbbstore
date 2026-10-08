/*
 * Lane FW: the Firewall screen, card by card, at 1280 and 390.
 *   node tools/fw451-admin-shots.cjs http://127.0.0.1:9934 <outdir>
 */
const { chromium } = require('playwright');
const [BASE, OUT] = process.argv.slice(2);
(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const res = {};
    for (const W of [1280, 390]) {
        const phone = W < 900;
        const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, isMobile: phone, hasTouch: phone,
            extraHTTPHeaders: { 'X-Fw-Preview-Ip': '94.200.10.20' } });
        const p = await c.newPage();
        const errors = [];
        p.on('pageerror', (e) => errors.push(String(e)));
        await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name=email]', 'owner@preview.test');
        await p.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
        await p.evaluate(() => window.go('firewall'));
        await p.waitForSelector('[data-fwl-mode]');
        await p.screenshot({ path: `${OUT}/firewall-${W}-0-screen.png` });
        const cards = await p.$$('#content .sx-card');
        const names = ['1-status', '2-live', '3-limits', '4-countries', '5-bots', '6-allow', '7-data'];
        for (let i = 0; i < cards.length && i < names.length; i++) {
            await cards[i].screenshot({ path: `${OUT}/firewall-${W}-${names[i]}.png` });
        }
        await p.click('[data-fwl-all]');
        const cc = await p.$('.fwl-ccs');
        await (await cc.evaluateHandle((e) => e.closest('.sx-card'))).asElement().screenshot({ path: `${OUT}/firewall-${W}-4b-every-country.png` });
        res[W] = { errors, cards: cards.length, scrollWidth: await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]),
            rows: await p.evaluate(() => document.querySelectorAll('.fwl-cc').length) };
        await c.close();
    }
    console.log(JSON.stringify(res));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
