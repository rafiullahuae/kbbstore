/*
 * Every covered screen, driven once: change one control the way a hand
 * would (a slider dragged, a select picked, a box typed in, a switch
 * flipped), leave to the Dashboard, and read the Unfinished list.  (Lane PM)
 *
 * The static test pins that each screen REGISTERS; this proves each one
 * actually lands a row when it is left with a change on it, and that no
 * screen raises a dialog on the way out.
 *
 * Usage: sh tools/pm-drafts-preview.sh
 *        PM_BASE=http://127.0.0.1:9520 NODE_PATH=/home/user/kbbstore/node_modules node tools/pm-drafts-sweep.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PM_BASE || 'http://127.0.0.1:9520';
const OUT = path.resolve(__dirname, '..', process.env.PM_OUT || 'storage/pm-logs/shots');

/* [go() id, the key its row is stored under] */
const SCREENS = [
    ['header', 'header'], ['prodstyles', 'prodstyles'], ['acctpanel', 'acctpanel'],
    ['search', 'search'], ['mobilemenu', 'mobilemenu'], ['newsletter', 'newsletter'],
    ['mobilehdr', 'mobilehdr'], ['dividers', 'dividers'], ['productpage', 'productpage'],
    ['homepage', 'homepage'], ['cartpanel', 'cartpanel'], ['cartpage', 'cartpage'],
    ['checkoutpage', 'checkoutpage'], ['pagewash', 'pagewash'], ['slimfooter', 'slimfooter'],
    ['sitelayout', 'sitelayout'], ['security', 'security'], ['ugcstyle', 'ugcstyle'],
    ['rev-settings', 'rev-settings'], ['rev-badge', 'rev-badge'], ['setap', 'setap'],
];

/* Change ONE control in #content the way a hand would; say which. */
function changeOne() {
    const host = document.querySelector('#content');
    const fire = (el, types) => types.forEach((t) => el.dispatchEvent(new Event(t, { bubbles: true })));
    const range = host.querySelector('input[type=range]:not([disabled])');
    if (range) {
        const max = Number(range.max || 100), min = Number(range.min || 0), step = Number(range.step || 1);
        range.value = String(Number(range.value) + step <= max ? Number(range.value) + step : Number(range.value) - step || min);
        fire(range, ['input', 'change']);
        return 'range ' + (range.dataset && JSON.stringify(Object.assign({}, range.dataset)));
    }
    const sel = [...host.querySelectorAll('select:not([disabled])')].find((s) => s.options.length > 1);
    if (sel) {
        sel.selectedIndex = (sel.selectedIndex + 1) % sel.options.length;
        fire(sel, ['input', 'change']);
        return 'select ' + JSON.stringify(Object.assign({}, sel.dataset));
    }
    const tog = host.querySelector('.ectog, [role=switch], input[type=checkbox]:not([disabled])');
    if (tog) { tog.click(); return 'switch ' + JSON.stringify(Object.assign({}, tog.dataset)); }
    const box = host.querySelector('input[type=text]:not([disabled]), textarea:not([disabled])');
    if (box) { box.value = box.value + ' (edited)'; fire(box, ['input', 'change']); return 'text ' + JSON.stringify(Object.assign({}, box.dataset)); }
    return null;
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
    const errors = [];
    const dialogs = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('dialog', async (d) => { dialogs.push(d.type() + ': ' + d.message()); await d.dismiss().catch(() => {}); });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

    const results = [];
    for (const [id, key] of SCREENS) {
        await page.evaluate((i) => window.go(i), id);
        await page.waitForTimeout(1800);
        // Page background opens on its Preview tab, which has no controls.
        if (id === 'pagewash') { await page.click('#content [data-pwb-tab]:not([data-pwb-tab="preview"])'); await page.waitForTimeout(300); }
        const changed = await page.evaluate(changeOne);
        await page.waitForTimeout(450);
        await page.evaluate(() => window.go('dash'));
        await page.waitForTimeout(450);
        const keys = await page.evaluate(() => Object.keys(window.kbbDrafts.list()));
        results.push({ screen: id, control: changed, kept: keys.includes(key) });
    }

    // Grid sections: one grid made through the screen's own "Add a blank
    // grid", a control changed in its editor, the screen left.
    await page.evaluate(() => window.go('gridsections'));
    await page.waitForSelector('[data-gss-add=""]', { timeout: 15000 });
    await page.click('[data-gss-add=""]');
    await page.waitForSelector('#gss-save', { timeout: 15000 });
    await page.waitForTimeout(500);
    const gridControl = await page.evaluate(changeOne);
    await page.waitForTimeout(450);
    await page.evaluate(() => window.go('dash'));
    await page.waitForTimeout(450);
    const gridKeys = await page.evaluate(() => Object.keys(window.kbbDrafts.list()).filter((k) => k.indexOf('gridsections:') === 0));
    results.push({ screen: 'gridsections', control: gridControl, kept: gridKeys.length === 1, key: gridKeys[0] });

    await page.click('#kbbDraftsBtn');
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/10-sweep-list-1280.png`, fullPage: false });
    const listed = await page.evaluate(() => [...document.querySelectorAll('#kbbDraftsList .kbbdr-what b')].map((b) => b.textContent));

    console.log(JSON.stringify({ results, listed, dialogs, errors }, null, 2));
    fs.writeFileSync(`${OUT}/sweep.json`, JSON.stringify({ results, listed, dialogs, errors }, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
