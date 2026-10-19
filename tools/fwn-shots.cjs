/*
 * Lane FWN: Store → Security → Firewall → Cloud networks, at 1280 and 390,
 * a seeded cloud refusal in Live activity, and the Data tab's cloud lists.
 *   sh tools/fw451-preview.sh 10731   (preview database only)
 *   node tools/fwn-shots.cjs http://127.0.0.1:10731 docs/lane-fwn-shots
 */
const { chromium } = require('playwright');
const [BASE, OUT] = process.argv.slice(2);

async function login(b, W, ip) {
    const phone = W < 900;
    const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, isMobile: phone, hasTouch: phone,
        extraHTTPHeaders: { 'X-Fw-Preview-Ip': ip } });
    const p = await c.newPage();
    p.errors = [];
    p.on('pageerror', (e) => p.errors.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') p.errors.push(m.text()); });
    p.on('response', (r) => { if (r.status() === 404) p.errors.push('404 ' + r.url()); });
    p.on('dialog', (d) => d.accept());
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test');
    await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
    return { c, p };
}

async function api(p, path, body) {
    return p.evaluate(async ([path, body]) => {
        const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch('/admin-api/security/firewall' + path, { method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(body) });
        return r.status;
    }, [path, body]);
}

async function tab(p, t) {
    await p.evaluate((t) => { location.hash = '#firewall/' + t; window.go('firewall'); }, t);
    await p.waitForSelector(`[data-fwl-screen="${t}"]`);
    await p.waitForTimeout(400);
}

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    const res = {};
    const { c, p } = await login(b, 1280, '94.200.10.20');
    res.enforce = await api(p, '', { values: { mode: 'enforce', scope: 'site' } });

    // Seed: the owner's own Live-activity addresses, plus one UAE shopper.
    const visits = [];
    for (const [ip, n] of [['47.84.129.63', 3], ['43.173.182.215', 2], ['43.172.194.156', 1], ['94.200.10.20', 1]]) {
        const v = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, extraHTTPHeaders: { 'X-Fw-Preview-Ip': ip } });
        const vp = await v.newPage();
        for (let i = 0; i < n; i++) {
            const r = await vp.goto(`${BASE}/`, { waitUntil: 'load' });
            visits.push([ip, r.status()]);
        }
        if (ip === '47.84.129.63') {
            await vp.screenshot({ path: `${OUT}/fwn-refused-390.png` });
            res.refusalText = (await vp.textContent('body')).replace(/\s+/g, ' ').trim().slice(0, 200);
        }
        await v.close();
    }
    res.visits = visits;

    await tab(p, 'clouds');
    await p.screenshot({ path: `${OUT}/fwn-clouds-1280.png`, fullPage: true });
    res.clouds1280 = await p.evaluate(() => ({ rows: document.querySelectorAll('[data-fwl-cloud]').length,
        on: document.querySelectorAll('[data-fwl-cloud].on').length, sw: document.documentElement.scrollWidth, w: innerWidth }));

    // The control, after: DigitalOcean switched to Block and saved.
    await p.click('[data-fwl-cloud="digitalocean"]');
    await p.click('[data-fwl-save="clouds"]');
    await p.waitForSelector('[data-fwl-save="clouds"]:not([disabled])');
    await p.waitForTimeout(3500); // the "Saved." toast fades
    await p.evaluate(() => document.querySelector('[data-fwl-cloud="huawei"]').scrollIntoView({ block: 'start' }));
    await p.screenshot({ path: `${OUT}/fwn-clouds-1280-after.png` });
    res.after = await p.evaluate(() => document.querySelectorAll('[data-fwl-cloud].on').length);
    await api(p, '/clouds', { clouds: { digitalocean: 'allow' } });
    await p.waitForTimeout(3500); // the "Saved." toast fades

    await tab(p, 'live');
    await p.waitForSelector('.fwl-li');
    await p.screenshot({ path: `${OUT}/fwn-live-1280.png`, fullPage: true });
    res.live = await p.evaluate(() => [...document.querySelectorAll('.fwl-li')].slice(0, 6).map((e) => e.textContent.replace(/\s+/g, ' ').trim()));

    await tab(p, 'data');
    await p.waitForSelector('[data-fwl-data="clouds"]:not([disabled])');
    const sec = await p.evaluateHandle(() => document.querySelector('[data-fwl-data="clouds"]').closest('.card'));
    await sec.asElement().scrollIntoViewIfNeeded();
    await p.screenshot({ path: `${OUT}/fwn-data-1280.png` });
    res.data = await p.evaluate(() => document.querySelector('[data-fwl-data="clouds"]').closest('.card').textContent.replace(/\s+/g, ' ').trim().slice(0, 300));
    res.errors1280 = p.errors;
    await c.close();

    const m = await login(b, 390, '94.200.10.20');
    await tab(m.p, 'clouds');
    res.clouds390 = await m.p.evaluate(() => ({ sw: document.documentElement.scrollWidth, w: innerWidth,
        toggles: [...document.querySelectorAll('[data-fwl-cloud]')].map((e) => { e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(); return [r.width, r.height, document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === e]; }) }));
    await m.p.evaluate(() => document.querySelector('#content').scrollIntoView());
    await m.p.evaluate(() => { const s = document.querySelector('.subtab.on'); if (s) s.scrollIntoView({ inline: 'center', block: 'nearest' }); window.scrollTo(0, 0); document.querySelectorAll('*').forEach((e) => { if (e.scrollTop) e.scrollTop = 0; }); });
    await m.p.screenshot({ path: `${OUT}/fwn-clouds-390.png` });
    res.errors390 = m.p.errors;
    await m.c.close();

    console.log(JSON.stringify(res, null, 1));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
