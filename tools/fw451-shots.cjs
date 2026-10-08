/*
 * Lane FW: the pictures and the browser proofs.               (Store → Security → Firewall)
 *
 *   . storage/fw-logs/env.sh; node tools/fw451-shots.cjs http://127.0.0.1:9934 <outdir>
 *
 * A. Every shop page type at 390 and 1280, firewall OFF then ON (enforce), as a
 *    visitor from a Protect country: the screenshots must be byte-identical, the
 *    requests the page makes the same, no console error, no horizontal scroll.
 * B. Hover-prefetch still fires with the firewall on and the click is served
 *    from it (navigation deliveryType "navigational-prefetch").
 * C. Protect in a real browser: the page hands out the proof, Add to cart
 *    works; a script posting without a page is refused.
 * D. The Firewall screen at 1280 and 390: top, live view, countries.
 *
 * The visitor's address is set by tools/fw451-router.php from X-Fw-Preview-Ip
 * (every browser here is 127.0.0.1, which the firewall never looks at).
 */
const { chromium, request } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const [BASE, OUT] = process.argv.slice(2);
fs.mkdirSync(OUT, { recursive: true });
const CN = '36.110.0.1';
const mode = (m) => execSync(`php artisan kbb:firewall ${m}`, { cwd: __dirname + '/..' }).toString().split('\n')[0];
const PAGES = {
    home: '/', category: '/collections/serums/', brand: '/brands/cosrx/',
    product: '/product/advanced-snail-96-mucin-power-essence/', shop: '/shop/', blog: '/blog/',
};
const out = { A: {}, B: {}, C: {}, D: {} };

async function ctx(b, W, ip, extra = {}) {
    const phone = W < 900;
    // A real browser's user agent: headless Chromium names itself
    // "HeadlessChrome", which the shop's existing "Ask bots to leave" refuses
    // on the cart — a different rule from the firewall, and not the one under test.
    const userAgent = phone
        ? 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'
        : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
    return b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, deviceScaleFactor: 1, isMobile: phone, hasTouch: phone, userAgent,
        extraHTTPHeaders: { 'X-Fw-Preview-Ip': ip }, reducedMotion: 'reduce', ...extra });
}

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

    /* ── A ─────────────────────────────────────────────────────────────── */
    const shots = {};
    // off, on, then off again: a page that differs between two OFF loads is
    // moving by itself (an animation, a counter), not because of the firewall.
    for (const m of ['off', 'on', 'off2']) {
        out.A['mode_' + m] = mode(m === 'off2' ? 'off' : m);
        execSync('sleep 3'); // let the PHP server's OPcache see the new compiled file
        for (const W of [390, 1280]) {
            for (const [name, path] of Object.entries(PAGES)) {
                const c = await ctx(b, W, CN);
                const p = await c.newPage();
                const errors = [], reqs = [];
                p.on('pageerror', (e) => errors.push(String(e)));
                p.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
                p.on('request', (r) => reqs.push(r.url().replace(BASE, '')));
                const res = await p.goto(BASE + path, { waitUntil: 'networkidle' });
                await p.evaluate(() => document.fonts && document.fonts.ready);
                await p.waitForTimeout(300);
                const buf = await p.screenshot({ animations: 'disabled', caret: 'hide' });
                const sw = await p.evaluate(() => document.documentElement.scrollWidth);
                const cookies = (await c.cookies()).map((x) => x.name);
                shots[`${m}-${W}-${name}`] = { buf, status: res.status(), sw, errors, reqs: reqs.length, pv: cookies.includes('kbb_pv') };
                if ((name === 'home' || name === 'product') && m !== 'off2') fs.writeFileSync(`${OUT}/shop-${name}-${W}-firewall-${m}.png`, buf);
                await c.close();
            }
        }
    }
    for (const W of [390, 1280]) {
        for (const name of Object.keys(PAGES)) {
            const a = shots[`off-${W}-${name}`], z = shots[`on-${W}-${name}`];
            const a2 = shots[`off2-${W}-${name}`];
            out.A[`${name}@${W}`] = { identical: a.buf.equals(z.buf), offVsOffAgain: a.buf.equals(a2.buf), status: [a.status, z.status], scrollWidth: [a.sw, z.sw],
                requests: [a.reqs, z.reqs], errors: [a.errors, z.errors], proofCookie: [a.pv, z.pv] };
        }
    }

    /* ── B: prefetch with the firewall on ──────────────────────────────── */
    mode('on');
    execSync('sleep 3');
    {
        const c = await ctx(b, 1280, CN);
        const p = await c.newPage();
        const pf = [];
        await p.goto(BASE + '/collections/serums/', { waitUntil: 'networkidle' });
        const link = await p.$('a[href*="/product/"]');
        const href = await link.getAttribute('href');
        const log = 'storage/framework/testing/fw451-preview/fw451-requests.log';
        const before = fs.readFileSync(log, 'utf8').split('\n').length;
        await link.hover();
        await p.waitForTimeout(1200);
        const lines = fs.readFileSync(log, 'utf8').split('\n').slice(before - 1).filter((l) => l.includes('prefetch'));
        await Promise.all([p.waitForLoadState('load'), link.click()]);
        const nav = await p.evaluate(() => { const e = performance.getEntriesByType('navigation')[0]; return { type: e.deliveryType, url: location.pathname }; });
        // and a real click lands on the first try: the element at the link's centre is the link
        out.B = { href, prefetchRequestsSeenByServer: lines.length, navigation: nav };
        await c.close();
    }

    /* ── C: Protect in Chromium ────────────────────────────────────────── */
    {
        const c = await ctx(b, 390, CN);
        const p = await c.newPage();
        await p.goto(BASE + PAGES.product, { waitUntil: 'networkidle' });
        const proof = (await c.cookies()).find((x) => x.name === 'kbb_pv');
        const add = await p.$('[data-kbb-add], [data-add], button:has-text("Add to")');
        let addStatus = null, ms = null;
        if (add) {
            const t = Date.now();
            const [resp] = await Promise.all([
                p.waitForResponse((r) => r.url().includes('/api/cart/add'), { timeout: 8000 }).catch(() => null),
                add.click(),
            ]);
            addStatus = resp ? resp.status() : 'no request';
            ms = Date.now() - t;
        }
        await p.waitForTimeout(600);
        await p.screenshot({ path: `${OUT}/protect-browser-added-390.png` });
        const cartCount = await p.evaluate(() => (window.KBB && window.KBB.cartCount) || null);
        const badge = await p.evaluate(() => { const e = document.querySelector('[data-cart-count], .cart-count, .kbb-cart-count'); return e ? e.textContent.trim() : null; });
        const bot = await request.newContext({ extraHTTPHeaders: { 'X-Fw-Preview-Ip': CN, Accept: 'application/json',
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' } });
        const r = await bot.post(BASE + '/api/cart/add', { data: { product_id: 1 } });
        out.C = { proofCookie: proof ? { httpOnly: proof.httpOnly, sameSite: proof.sameSite, len: proof.value.length } : null,
            browserAddToCart: addStatus, clickToResponseMs: ms, badge, botPostWithoutPage: r.status(), botBody: (await r.text()).slice(0, 120) };
        await bot.dispose();
        await c.close();
    }

    /* ── D: the Firewall screen ────────────────────────────────────────── */
    for (const W of [1280, 390]) {
        const c = await ctx(b, W, '94.200.10.20');
        const p = await c.newPage();
        const errors = [];
        p.on('pageerror', (e) => errors.push(String(e)));
        await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await p.fill('input[name=email]', 'owner@preview.test');
        await p.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
        // window.go, as the sidebar row does: ?go=firewall needs docs/fw-wiring.json applied.
        await p.evaluate(() => window.go('firewall'));
        await p.waitForSelector('[data-fwl-mode]', { timeout: 15000 });
        const sw = await p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
        await p.screenshot({ path: `${OUT}/firewall-top-${W}.png` });
        const live = await p.$('[data-fwl-live]');
        await live.evaluate((e) => e.closest('.sx-card').scrollIntoView());
        await p.screenshot({ path: `${OUT}/firewall-live-${W}.png` });
        await p.fill('[data-fwl-q]', 'chin');
        const cc = await p.$('.fwl-ccs');
        await p.fill('[data-fwl-q]', '');
        await cc.evaluate((e) => e.closest('.sx-card').scrollIntoView());
        await p.screenshot({ path: `${OUT}/firewall-countries-${W}.png` });
        const allow = await p.$('[data-fwl-mine]');
        await allow.evaluate((e) => e.closest('.sx-card').scrollIntoView());
        await p.screenshot({ path: `${OUT}/firewall-bots-allow-${W}.png` });
        await p.screenshot({ path: `${OUT}/firewall-full-${W}.png`, fullPage: true });
        const counts = await p.evaluate(() => ({ countries: document.querySelectorAll('.fwl-cc').length, bans: document.querySelectorAll('[data-fwl-unban]').length,
            title: document.querySelector('#ptitle').textContent, crumb: document.querySelector('#crumb').textContent }));
        out.D[W] = { scrollWidth: sw, errors, ...counts };
        await c.close();
    }

    console.log(JSON.stringify(out, null, 1));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
