/*
 * Cart panel freeze fix: drive every way the panel used to die, in Chromium.
 *
 *   sh tools/cr-preview.sh            # prints the port
 *   node tools/cr-shots.cjs 11470
 *
 * Scenarios (each in a fresh browser context):
 *   before    the OLD bundle (git e7305565) with an expired session: the freeze
 *   expired   the NEW bundle, session expired while away: 419 -> token -> retry
 *   server500 /add answers an HTML 500: the real cart is repainted
 *   offline   /add and /drawer both drop: the previous panel is put back
 *   wake      91 minutes away, tab shown again: token refreshed BEFORE the tap
 *   normal    an ordinary add: no /token request at all
 *
 * Writes PNGs and a JSON report to storage/cr-shots/.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = require('/usr/lib/node_modules/playwright')); }

const PORT = process.argv[2] || '11470';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'storage', 'cr-shots');
fs.mkdirSync(OUT, { recursive: true });

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

const OLD_BUNDLE =execSync('git show e7305565:public/build/assets/app-TW9IldZm.js', { cwd: path.join(__dirname, '..') }).toString();

const launch = async () => {
    try { return await chromium.launch(); } catch { return chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); }
};

/* Cookies the session owns. The basket cookie is NOT one of them: it lives 30
   days, which is exactly why the owner's shoppers came back to a full basket. */
const expireSession = async (ctx) => {
    const keep = (await ctx.cookies()).filter((c) => !/session|XSRF-TOKEN/i.test(c.name));
    await ctx.clearCookies();
    if (keep.length) await ctx.addCookies(keep);
};

const panelState = (page) => page.evaluate(() => {
    const f = document.querySelector('#cart .kc-fragment');
    if (!f) return 'none';
    if (f.dataset.busy === '1' || f.querySelector('.kc-skel')) return 'SKELETON';
    return f.querySelector('.kc-item') ? 'cart-with-items' : 'cart-empty';
});

const run = async (name, width, opts = {}) => {
    const browser = await launch();
    // A real browser's user agent: BlockGate (rightly) answers a headless one
    // 403 on every cart URL, which is not the shopper this is about.
    const ctx = await browser.newContext(width < 600
        ? { viewport: { width, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE }
        : { viewport: { width, height: 900 }, userAgent: MAC });
    const page = await ctx.newPage();
    const log = { name, width, requests: [], console: [], panel: null };

    page.on('console', (m) => { if (m.type() === 'error') log.console.push(m.text()); });
    page.on('response', (r) => {
        const u = new URL(r.url());
        if (u.pathname.startsWith('/api/cart')) log.requests.push(`${r.request().method()} ${u.pathname} ${r.status()}`);
        else if (r.status() >= 400) log.requests.push(`!! ${r.status()} ${u.pathname}`);
    });
    page.on('requestfailed', (r) => {
        const u = new URL(r.url());
        if (u.pathname.startsWith('/api/cart')) log.requests.push(`${r.method()} ${u.pathname} FAILED`);
    });

    if (opts.oldBundle) {
        await page.route('**/build/assets/app-*.js', (route) => route.fulfill({ status: 200, contentType: 'text/javascript', body: OLD_BUNDLE }));
    }
    if (opts.clock) await page.clock.install();

    // One add first, so the panel has something real in it and a basket cookie
    // exists — the shopper who comes back has a basket.
    await page.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
    const addSel = '[data-kbb-add]';
    await page.locator(addSel).first().click();
    await page.waitForFunction(() => {
        const f = document.querySelector('#cart .kc-fragment');
        return f && f.dataset.busy !== '1' && f.querySelector('.kc-item');
    }, null, { timeout: 15000 });
    await page.keyboard.press('Escape');
    await page.evaluate(() => document.querySelector('[data-kbb-close]')?.click());
    await page.waitForTimeout(400);
    log.requests.push('--- shopper goes away ---');

    if (opts.expire) await expireSession(ctx);
    if (opts.failAdd) await page.route('**/api/cart/add', (route) => opts.failAdd(route));
    if (opts.failDrawer) await page.route('**/api/cart/drawer', (route) => route.abort('internetdisconnected'));
    if (opts.clock) {
        await page.clock.fastForward('01:31:00');
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.waitForTimeout(800);
        log.requests.push('--- tab shown again ---');
    }

    // The tap that used to freeze the panel.
    await page.locator(addSel).nth(1).click();
    await page.waitForTimeout(opts.wait || 4000);

    log.panel = await panelState(page);
    log.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    await page.screenshot({ path: path.join(OUT, `${name}-${width}.png`) });
    await browser.close();
    return log;
};

(async () => {
    const report = [];
    for (const width of [390, 1280]) {
        report.push(await run('before', width, { oldBundle: true, expire: true }));
        report.push(await run('expired', width, { expire: true }));
    }
    report.push(await run('server500', 390, {
        failAdd: (route) => route.fulfill({ status: 500, contentType: 'text/html', body: '<!doctype html><title>Server Error</title><h1>500</h1>' }),
    }));
    report.push(await run('offline', 390, { failAdd: (route) => route.abort('internetdisconnected'), failDrawer: true }));
    report.push(await run('wake', 390, { expire: true, clock: true }));
    report.push(await run('normal', 1280, {}));
    report.push(await run('normalold', 1280, { oldBundle: true }));

    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 2));
    for (const r of report) {
        console.log(`${r.name.padEnd(10)} ${String(r.width).padEnd(5)} panel=${r.panel.padEnd(16)} sw=${r.scrollWidth} console=${r.console.length}`);
        for (const q of r.requests) console.log('      ' + q);
        for (const c of r.console) console.log('      ERR ' + c);
    }
})();
