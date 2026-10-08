/*
 * Lane PX: "Fetch missing pictures from the old server" in Chromium, at 1280
 * and 390, on both screens: before (Check), during (a run, mid-way), Stop,
 * and the finished report. The old server is faked by tools/px-router.php.
 *
 *   node tools/px453-shots.cjs http://127.0.0.1:9953 <outdir>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [BASE, OUT] = process.argv.slice(2);
fs.mkdirSync(OUT, { recursive: true });
const out = {};

async function login(b, W) {
    const phone = W < 900;
    const c = await b.newContext({ viewport: { width: W, height: phone ? 844 : 900 }, deviceScaleFactor: 1, isMobile: phone, hasTouch: phone,
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36' });
    const p = await c.newPage();
    const errors = [];
    p.on('pageerror', (e) => errors.push(String(e)));
    p.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + (m.location() && m.location().url)); });
    p.on('response', (res) => { if (res.status() >= 400) errors.push(res.status() + ' ' + res.url()); });
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[name=email]', 'owner@preview.test');
    await p.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit], input[type=submit]')]);
    return { c, p, errors };
}
const sw = (p) => p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
const nums = (p) => p.evaluate(() => [...document.querySelectorAll('[data-oldpics] .opx-n')].map((n) => n.innerText.replace(/\s+/g, ' ')));

(async () => {
    const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
    for (const W of [1280, 390]) {
        const r = out[W] = {};
        const { c, p, errors } = await login(b, W);

        /* Store → Import */
        await p.evaluate(() => window.go('import'));
        await p.waitForSelector('[data-oldpics] [data-opx-root]', { timeout: 20000 });
        await p.waitForSelector('[data-oldpics] [data-opx=check]');
        const card = await p.$('[data-oldpics]');
        await card.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await p.screenshot({ path: `${OUT}/import-before-${W}.png` });
        await p.click('[data-oldpics] [data-opx=check]');
        await p.waitForFunction(() => /Checked:/.test(document.querySelector('[data-oldpics] .opx-msg')?.textContent || ''));
        r.check = await nums(p);
        r.checkMsg = await p.textContent('[data-oldpics] .opx-msg');
        await card.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await p.screenshot({ path: `${OUT}/import-checked-${W}.png` });

        await p.click('[data-oldpics] [data-opx=fetch]');
        await p.waitForFunction(() => /still to try/.test(document.querySelector('[data-oldpics] .opx-msg')?.textContent || ''), null, { timeout: 30000 });
        r.during = await nums(p);
        await (await p.$('[data-oldpics]')).evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await p.screenshot({ path: `${OUT}/import-running-${W}.png` });
        await p.click('[data-oldpics] [data-opx=stop]');
        await p.waitForSelector('[data-oldpics] [data-opx=fetch]:not([disabled])', { timeout: 30000 });
        r.stopped = await nums(p);
        r.stopMsg = await p.textContent('[data-oldpics] .opx-msg');
        await p.screenshot({ path: `${OUT}/import-stopped-${W}.png` });
        r.resumeLabel = await p.textContent('[data-oldpics] [data-opx=fetch]');
        await p.click('[data-oldpics] [data-opx=fetch]');
        await p.waitForFunction(() => /Finished/.test(document.querySelector('[data-oldpics] .opx-msg')?.textContent || ''), null, { timeout: 60000 });
        r.done = await nums(p);
        r.doneMsg = await p.textContent('[data-oldpics] .opx-msg');
        await (await p.$('[data-oldpics]')).evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await p.screenshot({ path: `${OUT}/import-finished-${W}.png` });
        r.importScroll = await sw(p);
        // The button answers a real click: elementFromPoint over Check is Check.
        r.checkHit = await p.evaluate(() => { const el = document.querySelector('[data-oldpics] [data-opx=check]'); const q = el.getBoundingClientRect();
            return document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2) === el; });

        /* Platform → Domain switch, last step */
        await p.evaluate(() => window.go('domainswitch'));
        await p.waitForSelector('.dwi-step', { timeout: 20000 });
        await p.evaluate(() => { const last = [...document.querySelectorAll('.dwi-head')].pop(); last.click(); });
        await p.waitForSelector('[data-dw-screen] [data-oldpics] [data-opx-root]', { timeout: 20000 });
        const dw = await p.$('[data-dw-screen] [data-oldpics]');
        await dw.evaluate((e) => e.previousElementSibling.scrollIntoView({ block: 'start' }));
        await p.screenshot({ path: `${OUT}/domainswitch-${W}.png` });
        r.dwNums = await nums(p);
        r.dwScroll = await sw(p);
        r.dwHostingerLine = await p.evaluate(() => [...document.querySelectorAll('.dwi-sum li')].map((l) => l.textContent).find((t) => /Hostinger/.test(t)));
        r.errors = errors;
        await c.close();
        if (W === 1280) require('child_process').execSync('php storage/px-logs/preview/reset.php', { cwd: __dirname + '/..' });
    }
    console.log(JSON.stringify(out, null, 1));
    await b.close();
})().catch((e) => { console.error(e); process.exit(1); });
