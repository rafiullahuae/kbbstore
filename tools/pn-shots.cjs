/*
 * Lane PN screenshots: Growth & Marketing -> Push Notifications, every tab,
 * in Chromium at 390 and 1280, against tools/pn-preview.sh:
 *
 *     node tools/pn-shots.cjs http://127.0.0.1:10660 docs/pn-shots
 *
 * Every number printed is read from the rendered page (scrollWidth, sizes,
 * request counts) for the report; the screen's own code measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10660';
const OUT = path.resolve(process.argv[3] || 'docs/pn-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };
const opts = (w) => (w < 600
    ? { viewport: { width: w, height: 844 }, userAgent: UA_PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
    : { viewport: { width: w, height: 900 }, userAgent: UA });

// The console scrolls inside .content; for a whole-screen picture let the
// document grow instead (shots only -- the console itself is unchanged).
const UNCLIP = '.content{overflow:visible!important;height:auto!important}.main,.app{height:auto!important;min-height:100vh}body{overflow:visible!important}';
async function shot(page, file) {
    const h = await page.addStyleTag({ content: UNCLIP });
    await page.screenshot({ path: path.join(OUT, file), fullPage: true });
    await h.evaluate((n) => n.remove());
}

async function metrics(page) {
    return page.evaluate(() => ({
        crumb: (document.querySelector('#crumb') || {}).textContent,
        title: (document.querySelector('#ptitle') || {}).textContent,
        scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
        wrapW: Math.round((document.querySelector('.pn-wrap') || { getBoundingClientRect: () => ({ width: 0 }) }).getBoundingClientRect().width),
        h: document.documentElement.scrollHeight,
    }));
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(opts(w));
        const page = await ctx.newPage();
        const api = [];
        page.on('request', (r) => { if (r.url().includes('/admin-api/push')) api.push(r.method() + ' ' + r.url().replace(BASE, '')); });
        await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name=email]', 'owner@preview.test');
        await page.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
        await page.goto(`${BASE}/admin/?go=push`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.pn-table tbody tr', { timeout: 15000 });
        const navRow = await page.evaluate(() => [...document.querySelectorAll('#nav .nav-group[data-sec="Growth & Marketing"] [data-go]')].map((b) => b.dataset.go).join(','));
        let m = await metrics(page);
        await shot(page, `pn-campaigns-${w}.png`);
        note(`pn-campaigns-${w}.png  crumb="${m.crumb} / ${m.title}" sidebar G&M=[${navRow}] rows=${await page.$$eval('.pn-table tbody tr', (r) => r.length)} scrollWidth=${m.scrollW} (viewport ${m.innerW}) height=${m.h}`);

        // The editor: open the draft, press Sharjah, watch the live count.
        await page.click('.pn-table tbody tr:nth-child(1)');
        await page.waitForSelector('#pnTitle');
        await page.waitForFunction(() => /\d/.test((document.querySelector('#pnLive b') || {}).textContent || ''), null, { timeout: 15000 });
        const before = await page.$eval('#pnLive', (e) => e.textContent);
        api.length = 0;
        await page.fill('#pnTitle', 'Sharjah: free delivery today');
        await page.type('#pnBody', ' Tap to shop.');
        const typed = api.length;
        const dubai = await page.$('.pn-chip >> text=Dubai');
        await dubai.click();
        await page.waitForTimeout(900);
        const after = await page.$eval('#pnLive', (e) => e.textContent);
        const counts = api.filter((a) => a.includes('/count')).length;
        await page.fill('input[type=datetime-local]', await page.evaluate(() => { const d = new Date(Date.now() + 86400000); d.setHours(10, 30, 0, 0); return d.toISOString().slice(0, 16); }));
        m = await metrics(page);
        const sizes = await page.evaluate(() => {
            const n = document.querySelector('.pn-notif');
            const r = n.getBoundingClientRect();
            return { notifW: Math.round(r.width), notifH: Math.round(r.height), titleFont: getComputedStyle(n.querySelector('b')).fontSize, bodyFont: getComputedStyle(n.querySelector('p')).fontSize };
        });
        await shot(page, `pn-editor-${w}.png`);
        note(`pn-editor-${w}.png  live count before="${before}" after pressing Dubai="${after}"; admin-api requests while typing title+body=${typed}, count requests for one chip=${counts}; notification ${sizes.notifW}x${sizes.notifH} title ${sizes.titleFont} body ${sizes.bodyFont}; scrollWidth=${m.scrollW} (viewport ${m.innerW})`);
        const phone = await page.$('.pn-phone');
        await phone.screenshot({ path: path.join(OUT, `pn-notification-mock-${w}.png`) });
        note(`pn-notification-mock-${w}.png  (labelled mock: the lock-screen card the worker's showNotification() produces from {t, b})`);

        // The report of the sent campaign.
        await page.click('.pn-btn:text-is("Back")');
        await page.waitForSelector('.pn-table tbody tr');
        const sentRow = await page.$('.pn-table tbody tr:has(.pn-pill.sent)');
        await sentRow.click();
        await page.waitForSelector('.pn-tiles');
        m = await metrics(page);
        const tiles = await page.$$eval('.pn-tile', (t) => t.map((x) => x.textContent.replace(/\s+/g, ' ').trim()).join(' | '));
        await shot(page, `pn-report-${w}.png`);
        note(`pn-report-${w}.png  ${tiles}; byEmirate rows=${await page.$$eval('.pn-table tbody tr', (r) => r.length)} scrollWidth=${m.scrollW}`);

        for (const [tab, file] of [['automations', 'pn-automations'], ['analytics', 'pn-analytics'], ['settings', 'pn-settings']]) {
            await page.click(`.pn-tab[data-tab="${tab}"]`);
            await page.waitForFunction((t) => document.querySelector(`.pn-tab[data-tab="${t}"][aria-selected="true"]`) && !/Loading/.test(document.querySelector('.pn-wrap').textContent), tab, { timeout: 15000 });
            if (tab === 'automations') {
                await page.click('details summary');
            }
            m = await metrics(page);
            await shot(page, `${file}-${w}.png`);
            const extra = tab === 'analytics'
                ? await page.$$eval('.pn-tile', (t) => t.map((x) => x.textContent.replace(/\s+/g, ' ').trim()).join(' | '))
                : await page.$$eval('.pn-sw', (s) => s.map((x) => x.textContent + '=' + x.getAttribute('aria-checked')).join(', '));
            note(`${file}-${w}.png  ${extra}; scrollWidth=${m.scrollW} (viewport ${m.innerW}) height=${m.h}`);
        }

        if (w === 1280) {
            // Before/after of a control: quiet hours to 23:00, saved and read back.
            await page.fill('input[type=time] >> nth=0', '23:00');
            await page.click('.pn-btn:text-is("Save settings")');
            await page.waitForTimeout(800);
            await page.reload({ waitUntil: 'networkidle' });
            await page.click('.pn-tab[data-tab="settings"]');
            await page.waitForSelector('input[type=time]');
            const v = await page.$eval('input[type=time]', (e) => e.value);
            await shot(page, 'pn-settings-saved-1280.png');
            note(`pn-settings-saved-1280.png  quiet hours from after save+reload = ${v}`);
        }
        await ctx.close();
    }
    await browser.close();
    fs.writeFileSync(path.join(OUT, 'measurements.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
