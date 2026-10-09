/*
 * Lane FW2: Store → Security → Firewall, one picture per tab at 1280 and 390,
 * each tab opened by its deep link (#firewall/<tab>), with the requests each
 * open made, console errors and the page's scrollWidth.
 *   node tools/fw2-tab-shots.cjs http://127.0.0.1:9944 <outdir>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [BASE, OUT] = process.argv.slice(2);
fs.mkdirSync(OUT, { recursive: true });
const TABS = ['overview', 'live', 'rules', 'countries', 'bots', 'lists', 'data'];
(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const res = {};
    for (const W of [1280, 390]) {
        const phone = W < 900;
        const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, isMobile: phone, hasTouch: phone,
            extraHTTPHeaders: { 'X-Fw-Preview-Ip': '94.200.10.20' } });
        const p = await c.newPage();
        const errors = [];
        let api = [];
        p.on('pageerror', (e) => errors.push(String(e)));
        p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        p.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
        p.on('request', (r) => { if (/admin-api\/(security\/firewall|cart-tracking)/.test(r.url())) api.push(r.method() + ' ' + r.url().replace(/^.*admin-api/, '')); });
        await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name=email]', 'owner@preview.test');
        await p.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
        res[W] = {};
        for (const t of TABS) {
            api = [];
            // A FRESH LOAD of the console at the tab's own address (the query
            // makes it a real navigation, not a same-document hash change).
            await p.goto(`${BASE}/admin?fresh=${W}${t}#firewall/${t}`, { waitUntil: 'networkidle' });
            await p.waitForSelector(`[data-fwl-screen="${t}"]`, { timeout: 15000 });
            await p.waitForFunction(() => !/Loading/.test(document.querySelector('.fwl [role=tabpanel]').textContent), null, { timeout: 15000 });
            // The console scrolls inside its own panel, so the window is made as
            // tall as the tab before the picture, then put back.
            const h = await p.evaluate(() => Math.ceil(document.querySelector('.fwl').getBoundingClientRect().height) + 260);
            await p.setViewportSize({ width: W, height: Math.max(phone ? 844 : 900, h) });
            await p.waitForTimeout(200);
            await (await p.$('.fwl')).screenshot({ path: `${OUT}/firewall-${W}-${TABS.indexOf(t) + 1}-${t}.png` });
            await p.setViewportSize({ width: W, height: phone ? 844 : 900 });
            res[W][t] = {
                hash: await p.evaluate(() => location.hash),
                selected: await p.evaluate(() => document.querySelector('.subtab.on').textContent),
                sections: await p.evaluate(() => [...document.querySelectorAll('.fwl .sec-title')].map((e) => e.textContent)),
                saves: await p.evaluate(() => document.querySelectorAll('.fwl [data-fwl-save]').length),
                scrollWidth: await p.evaluate(() => [document.documentElement.scrollWidth, innerWidth]),
                requests: api,
            };
        }
        // A #firewall/<tab> typed into an OPEN console on another screen.
        await p.evaluate(() => window.go('dash'));
        await p.evaluate(() => { location.hash = '#firewall/data'; });
        await p.waitForSelector('[data-fwl-screen="data"]', { timeout: 15000 });
        res[W].hashFromDashboard = await p.evaluate(() => document.querySelector('.subtab.on').textContent);
        // Switching by click: the address follows, and no reload happens.
        api = [];
        await p.click('[data-fwl-tab="countries"]');
        await p.click('[data-fwl-filter="protect"]');
        res[W].click = { hash: await p.evaluate(() => location.hash), rows: await p.evaluate(() => document.querySelectorAll('.fwl-cc').length), requests: api };
        res[W].errors = errors;
        await c.close();
    }
    console.log(JSON.stringify(res, null, 1));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
