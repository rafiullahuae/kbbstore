/*
 * Lane FW: "visitors must not feel this at all" — the first tap, proved in Chromium.
 *
 *   . storage/fw-logs/env.sh; node tools/fw451-proof.cjs http://127.0.0.1:9934 <outdir>
 *
 * Every visitor is from a Protect country (36.110.x, China), firewall ON.
 *
 *   S1  the page-load cookie never reaches the browser (kbb_pv stripped from
 *       EVERY response — a privacy setting, a cookie quirk): Add to cart,
 *       coupon and Place order must each succeed on the first tap.
 *   S2  the page-load cookie expired mid-visit (deleted after the page
 *       loaded): Add to cart on the first tap.
 *   S3  every Set-Cookie stripped from the first HTML (a page that never went
 *       through PHP): the same tap with the firewall OFF and ON — whatever
 *       the shop's own CSRF check answers, the firewall must answer the same.
 *   S4  a cold script (no page, no session; then a forged session cookie):
 *       still refused.
 */
const { chromium, request } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const [BASE, OUT] = process.argv.slice(2);
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const PRODUCT = '/product/advanced-snail-96-mucin-power-essence/';
const mode = (m) => { execSync(`php artisan kbb:firewall ${m}`, { cwd: __dirname + '/..' }); execSync('sleep 3'); };
const out = {};

function stripSetCookie(headers, which) {
    const h = { ...headers };
    const sc = h['set-cookie'];
    delete h['set-cookie'];
    if (sc && which !== 'all') {
        const keep = sc.split('\n').filter((c) => !c.startsWith(which + '='));
        if (keep.length) h['set-cookie'] = keep.join('\n');
    }
    return h;
}

/*
 * Route requests through a SEPARATE request context, forwarding the browser's
 * own Cookie header, and fulfil with the Set-Cookie lines filtered. (route.fetch()
 * would share the page's cookie jar, so a stripped cookie would be stored anyway.)
 */
async function routeVia(p, ip, decide) {
    const api = await request.newContext({ extraHTTPHeaders: { 'X-Fw-Preview-Ip': ip } });
    await p.route('**/*', async (route) => {
        const req = route.request();
        const which = decide(req);
        if (!which) return route.continue();
        const resp = await api.fetch(req, { headers: await req.allHeaders(), maxRedirects: 0 });
        await route.fulfill({ status: resp.status(), headers: stripSetCookie(resp.headers(), which), body: await resp.body() });
    });
    return api;
}

async function visitor(b, ip) {
    // No service worker: its fetches would bypass the routing that strips the cookie.
    const c = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA, serviceWorkers: 'block',
        extraHTTPHeaders: { 'X-Fw-Preview-Ip': ip } });
    const p = await c.newPage();
    const writes = [];
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    p.on('response', async (r) => {
        if (r.request().method() === 'POST' && !r.url().includes('/api/viewed') && !r.url().includes('/api/product-view')) {
            let body = '';
            try { body = (await r.text()).slice(0, 90); } catch (e) {}
            const cookie = (await r.request().allHeaders())['cookie'] || '';
            writes.push({ url: r.url().replace(BASE, ''), status: r.status(), sentPageLoadCookie: /(^|; )kbb_pv=/.test(cookie), body });
        }
    });
    return { c, p, writes, errors };
}

async function tapAdd(p) {
    const add = await p.$('[data-kbb-add], [data-add], button:has-text("Add to")');
    await add.click();
    await p.waitForTimeout(1200);
    // Anything the shop shows the shopper after the tap: a toast, an alert, an error line.
    return p.evaluate(() => [...document.querySelectorAll('[role=alert], .toast, .kbb-toast, .notice, .error, [class*=toast]')]
        .map((e) => e.textContent.trim()).filter((t) => t && /browser|blocked|session|error|wrong|try again/i.test(t)).slice(0, 3));
}

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    mode('on');

    /* S1 ─ kbb_pv never stored: add, coupon, place order ─────────────── */
    {
        const v = await visitor(b, '36.110.9.1');
        // Every page and every write answer: kbb_pv never reaches the browser.
        const api = await routeVia(v.p, '36.110.9.1', (req) => (req.url().startsWith(BASE) && !/\.(css|js|png|jpe?g|webp|svg|woff2?|ico)(\?|$)/.test(req.url()) ? 'kbb_pv' : null));
        await v.p.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
        const pvBefore = (await v.c.cookies()).some((x) => x.name === 'kbb_pv');
        const shown = await tapAdd(v.p);
        await v.p.screenshot({ path: `${OUT}/proof-s1-added-390.png` });

        await v.p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
        await v.p.fill('#kbb_coupon_code', 'FWTEN').catch(() => {});
        await v.p.click('#kbb_apply_coupon').catch(() => {});
        await v.p.waitForLoadState('networkidle');
        await v.p.waitForTimeout(800);
        const fill = async (name, value) => {
            const el = await v.p.$(`[name="${name}"]`);
            if (!el) return;
            const tag = await el.evaluate((e) => e.tagName);
            if (tag === 'SELECT') await el.selectOption(value).catch(() => {}); else await el.fill(value).catch(() => {});
        };
        await fill('billing_first_name', 'Lane');
        await fill('billing_last_name', 'Proof');
        await fill('billing_email', 'fw-proof@example.test');
        await fill('billing_phone', '+971501234567');
        await fill('billing_address_1', '1 Proof Street');
        await fill('billing_city', 'Dubai');
        await fill('billing_country', 'AE');
        await fill('billing_state', 'DU');
        // Whatever else the form requires: fill empty required text fields, pick a real option in empty selects.
        await v.p.evaluate(() => {
            document.querySelectorAll('form input[required], form [aria-required=true]').forEach((el) => {
                if ((el.tagName === 'INPUT') && !el.value && el.type !== 'checkbox' && el.type !== 'radio' && el.type !== 'hidden') {
                    el.value = 'Al Barsha'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            document.querySelectorAll('form select').forEach((el) => {
                if (!el.value && el.options.length > 1) { el.selectedIndex = 1; el.dispatchEvent(new Event('change', { bubbles: true })); }
            });
        });
        await v.p.waitForTimeout(800);
        const cod = await v.p.$('input[name=payment_method][value=cod]');
        if (cod) await cod.check({ force: true }).catch(() => {});
        await v.p.screenshot({ path: `${OUT}/proof-s1-checkout-390.png`, fullPage: false });
        const place = await v.p.$('[data-place]:visible');
        await Promise.all([v.p.waitForLoadState('networkidle'), place ? place.click() : null]);
        await v.p.waitForTimeout(2500);
        await v.p.screenshot({ path: `${OUT}/proof-s1-placed-390.png` });
        out.S1 = { kbb_pv_in_browser_at_end: (await v.c.cookies()).some((x) => x.name === 'kbb_pv'), kbb_pv_before_tap: pvBefore, shownAfterAdd: shown,
            finalUrl: v.p.url().replace(BASE, ''), writes: v.writes, errors: v.errors };
        await api.dispose();
        await v.c.close();
    }

    /* S2 ─ kbb_pv expired mid-visit ─────────────────────────────────── */
    {
        const v = await visitor(b, '36.110.9.2');
        await v.p.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
        const had = (await v.c.cookies()).some((x) => x.name === 'kbb_pv');
        const keep = (await v.c.cookies()).filter((x) => x.name !== 'kbb_pv');
        await v.c.clearCookies();
        await v.c.addCookies(keep);
        const shown = await tapAdd(v.p);
        out.S2 = { hadProofThenDeleted: had, shownAfterAdd: shown, writes: v.writes, proofReissued: (await v.c.cookies()).some((x) => x.name === 'kbb_pv') };
        await v.c.close();
    }

    /* S3 ─ every Set-Cookie stripped from the first HTML, firewall OFF vs ON ─ */
    for (const m of ['off', 'on']) {
        mode(m);
        const v = await visitor(b, '36.110.9.3');
        let first = true;
        const api = await routeVia(v.p, '36.110.9.3', (req) => {
            if (first && req.resourceType() === 'document') { first = false; return 'all'; }
            return null;
        });
        await v.p.goto(BASE + PRODUCT, { waitUntil: 'networkidle' });
        const shown = await tapAdd(v.p);
        out['S3_firewall_' + m] = { cookiesAtTap: (await v.c.cookies()).map((x) => x.name), shownAfterAdd: shown, writes: v.writes };
        await api.dispose();
        await v.c.close();
    }
    mode('on');

    /* S4 ─ cold scripts ───────────────────────────────────────────────── */
    {
        const bot = await request.newContext({ extraHTTPHeaders: { 'X-Fw-Preview-Ip': '36.110.9.4', Accept: 'application/json', 'User-Agent': UA } });
        const r1 = await bot.post(BASE + '/api/cart/add', { data: { product_id: 1 } });
        const r2 = await bot.post(BASE + '/api/cart/add', { data: { product_id: 1 }, headers: { Cookie: 'kbb_session=' + 'eyJpdiI6ImZvcmdlZCJ9'.repeat(3) } });
        const r3 = await bot.post(BASE + '/checkout/place', { form: { payment_method: 'cod' } });
        const r4 = await bot.post(BASE + '/checkout/coupon', { form: { coupon_code: 'FWTEN' } });
        out.S4 = { coldAdd: r1.status(), forgedSessionAdd: r2.status(), coldPlace: r3.status(), coldCoupon: r4.status(),
            jarAfter: (await bot.storageState()).cookies.map((x) => x.name) };
        await bot.dispose();
    }

    console.log(JSON.stringify(out, null, 1));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
