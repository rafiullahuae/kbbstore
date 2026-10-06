/*
 * Lane PD screenshots: Growth & Marketing -> Push Notifications -> Devices, in
 * Chromium at 1280 and 390, against tools/pdv-preview.sh:
 *
 *     node tools/pdv-shots.cjs http://127.0.0.1:10780 docs/pdv-shots
 *
 * Every number printed is read from the rendered page for the report; the
 * screen's own code measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10780';
const OUT = path.resolve(process.argv[3] || 'docs/pdv-shots');
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };
const opts = (w) => (w < 600
    ? { viewport: { width: w, height: 844 }, userAgent: UA_PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
    : { viewport: { width: w, height: 900 } });
const UNCLIP = '.content{overflow:visible!important;height:auto!important}.main,.app{height:auto!important;min-height:100vh}body{overflow:visible!important}';
async function shot(page, file, clipToWrap) {
    const h = await page.addStyleTag({ content: UNCLIP });
    if (clipToWrap) {
        // The top of the screen (the bar and the first rows), not all 25 rows.
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.screenshot({ path: path.join(OUT, file), fullPage: false });
    } else {
        await page.screenshot({ path: path.join(OUT, file), fullPage: true });
    }
    await h.evaluate((n) => n.remove());
}
async function m(page) {
    return page.evaluate(() => {
        const r = (s) => document.querySelector(s);
        const box = (s) => { const e = r(s); if (!e) return null; const b = e.getBoundingClientRect(); return Math.round(b.width) + 'x' + Math.round(b.height); };
        const fs = (s) => { const e = r(s); return e ? getComputedStyle(e).fontSize : null; };
        return {
            scrollW: document.documentElement.scrollWidth, innerW: innerWidth,
            rows: document.querySelectorAll('.pn-dev').length, row: box('.pn-dev'), check: box('.pn-dev input[type=checkbox]'),
            name: fs('.pn-dev-name'), meta: fs('.pn-dev-meta'), btn: box('.pn-dev-acts .pn-btn'), send: box('.pn-dev-bar .pn-btn.is-primary'),
            pager: (r('.pn-pager span') || {}).textContent, first: (r('.pn-dev') || {}).innerText,
        };
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(opts(w));
        const page = await ctx.newPage();
        const api = [];
        page.on('request', (q) => { if (q.url().includes('/admin-api/push')) api.push(q.method() + ' ' + q.url().replace(BASE, '')); });
        await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name=email]', 'owner@preview.test');
        await page.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
        await page.goto(`${BASE}/admin/?go=push`, { waitUntil: 'networkidle' });
        await page.waitForSelector('.pn-tab[data-tab=devices]');
        api.length = 0;
        await page.click('.pn-tab[data-tab=devices]');
        await page.waitForSelector('.pn-dev');
        await page.waitForLoadState('networkidle');
        let x = await m(page);
        await shot(page, `pd-devices-${w}.png`);
        note(`pd-devices-${w}.png  requests=[${api.join(' | ')}] rows=${x.rows} row=${x.row} checkbox=${x.check} name=${x.name} meta=${x.meta} actionBtn=${x.btn} pager="${x.pager}" scrollWidth=${x.scrollW} (viewport ${x.innerW})`);
        note(`   first row: ${JSON.stringify(x.first)}`);

        // Select three: the phone seen 2 min ago, and the next two.
        for (let i = 0; i < 3; i++) await page.locator('.pn-dev input[type=checkbox]').nth(i).check();
        x = await m(page);
        await shot(page, `pd-selected-${w}.png`, true);
        note(`pd-selected-${w}.png  bar="${await page.$eval('.pn-dev-bar:nth-of-type(2)', (e) => e.innerText.replace(/\n/g, ' '))}" sendBtn=${x.send} scrollWidth=${x.scrollW}`);

        api.length = 0;
        await page.click('.pn-dev-bar .pn-btn.is-primary');
        await page.waitForSelector('.pn-dev .pn-pill.delivered, .pn-dev .pn-pill.gone, .pn-dev .pn-pill.failed');
        await page.waitForLoadState('networkidle');
        const res = await page.$$eval('.pn-dev', (els) => els.slice(0, 3).map((e) => e.getAttribute('data-device') + ':' + ((e.querySelector('.pn-pill.delivered,.pn-pill.gone,.pn-pill.failed') || {}).textContent || '-')));
        x = await m(page);
        await shot(page, `pd-results-${w}.png`, true);
        note(`pd-results-${w}.png  requests=[${api.join(' | ')}] results=${res.join(', ')} scrollWidth=${x.scrollW}`);

        // Nickname + "This is my phone" on a phone not yet named.
        const target = await page.$$eval('.pn-dev', (els) => { const e = els.find((d) => !d.querySelector('.pn-pill.mine') && d.querySelector('.pn-dev-acts .pn-btn') && /Add a nickname/.test(d.innerText)); return e ? e.getAttribute('data-device') : null; });
        const sel = `.pn-dev[data-device="${target}"]`;
        await page.click(`${sel} .pn-dev-acts .pn-btn:has-text("Add a nickname")`);
        await page.fill(`${sel} .pn-dev-acts input`, w > 600 ? "Rafi's iPhone" : "Sara's Android");
        await shot(page, `pd-nickname-edit-${w}.png`, true);
        api.length = 0;
        await page.click(`${sel} .pn-dev-acts .pn-btn:has-text("Save")`);
        await page.waitForSelector(`${sel} .pn-dev-acts .pn-btn:has-text("Rename")`);
        await page.click(`${sel} .pn-dev-acts .pn-btn:has-text("This is my phone")`);
        await page.waitForSelector(`${sel} .pn-pill.mine`);
        await page.waitForLoadState('networkidle');
        // Reload the list: his phone now leads it.
        await page.click('.pn-tab[data-tab=devices]');
        await page.waitForSelector('.pn-dev .pn-pill.mine');
        await page.waitForLoadState('networkidle');
        x = await m(page);
        await shot(page, `pd-mine-${w}.png`, true);
        note(`pd-mine-${w}.png  device #${target} requests=[${api.join(' | ')}] first row now: ${JSON.stringify(x.first)} scrollWidth=${x.scrollW}`);

        // From the campaign editor: "Test on chosen devices".
        await page.click('.pn-tab[data-tab=campaigns]');
        await page.waitForSelector('.pn-table tbody tr');
        await page.click('.pn-btn:has-text("New campaign")');
        await page.waitForSelector('.pn-field input');
        await page.fill('.pn-field input >> nth=0', 'Weekend glow sale: 20% off');
        await page.click('.pn-btn:has-text("Test on chosen devices")');
        await page.waitForSelector('.pn-info:has-text("Testing the campaign")');
        await page.waitForSelector('.pn-dev');
        x = await m(page);
        await shot(page, `pd-from-editor-${w}.png`, true);
        note(`pd-from-editor-${w}.png  banner="${await page.$eval('.pn-info', (e) => e.innerText.replace(/\n/g, ' '))}" scrollWidth=${x.scrollW}`);
        await ctx.close();
    }
    await browser.close();
    fs.writeFileSync(path.join(OUT, 'pdv-shots.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
