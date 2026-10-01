/*
 * "Unfinished (N)" in the admin top bar, photographed and driven.   (Lane PM)
 *
 * The owner: "if i leave anything, on admin top bar there should be proper log
 * ... where i can see all my leaved things, and i can go directly there ... it
 * should not give me the weired warning like thing."
 *
 * Per width (390 and 1280, each in a fresh browser context, so a fresh
 * localStorage):
 *   1. Header: type in a text box, leave to the Dashboard. Every dialog the
 *      page raises is recorded -- the pass condition is that there are none.
 *   2. The top bar reads "Unfinished (1)"; the list opens and is photographed.
 *   3. Open: the Header screen comes back with the typed value in its box and
 *      the "Your unfinished changes are back" bar above it.
 *   4. Save (on that bar): the POST goes, the row and the button disappear.
 * At 1280 only, also:
 *   5. Banners: open the set named `Spring <b>sale</b> & "more"`, rename it,
 *      leave -- no dialog; the list prints the name as text.
 *   6. A refresh with unsaved banner typing: no "Leave site?" prompt, and the
 *      row is still there afterwards.
 *   7. Catalog -> Reorder: move a product, switch Category -> Brand (this was
 *      the "Discard them?" confirm) -- no dialog; Open brings the order back.
 *   8. Stale: Header typed, then the header saved "elsewhere" (a direct POST),
 *      then Open -- the bar says so and waits; "Use my changes" applies them.
 *   9. Discard on a row asks "Are you sure?" through the reset guard.
 *
 * Usage: sh tools/pm-drafts-preview.sh   (prints the port)
 *        PM_BASE=http://127.0.0.1:9520 NODE_PATH=/home/user/kbbstore/node_modules node tools/pm-drafts-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PM_BASE || 'http://127.0.0.1:9520';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PM_OUT || 'storage/pm-logs/shots');
const WIDTHS = [[390, 844], [1280, 900]];

async function signIn(page) {
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
}

const go = (page, id, sub) => page.evaluate(([i, s]) => window.go(i, s), [id, sub]);
const settle = (page) => page.waitForTimeout(450);

async function topBar(page) {
    return page.evaluate(() => {
        const home = document.getElementById('kbbUnfinished');
        const btn = document.getElementById('kbbDraftsBtn');
        const r = btn.getBoundingClientRect();
        return {
            shown: !home.hidden,
            text: btn.textContent.trim(),
            inTopBar: !!home.closest('.main .top'),
            btnW: Math.round(r.width), btnH: Math.round(r.height),
            btnFont: getComputedStyle(btn).fontSize,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        };
    });
}

async function panel(page) {
    return page.evaluate(() => {
        const p = document.getElementById('kbbDraftsPanel');
        const r = p.getBoundingClientRect();
        return {
            open: !p.hidden,
            left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width),
            viewport: window.innerWidth,
            rows: [...p.querySelectorAll('.kbbdr-row')].map((li) => ({
                label: li.querySelector('b').textContent,
                labelHasElements: li.querySelector('b').children.length,
                meta: li.querySelector('span').textContent,
            })),
            focused: document.activeElement && document.activeElement.textContent,
            expanded: document.getElementById('kbbDraftsBtn').getAttribute('aria-expanded'),
            rowFont: (p.querySelector('.kbbdr-what b') && getComputedStyle(p.querySelector('.kbbdr-what b')).fontSize) || null,
            scrollWidth: document.documentElement.scrollWidth,
        };
    });
}

async function bar(page) {
    return page.evaluate(() => {
        const b = document.getElementById('kbbDraftBar');
        return {
            shown: !b.hidden,
            mode: b.getAttribute('data-mode'),
            text: b.textContent.replace(/\s+/g, ' ').trim(),
            height: Math.round(b.getBoundingClientRect().height),
        };
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const report = {};

    for (const [w, h] of WIDTHS) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const errors = [];
        const dialogs = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        page.on('dialog', async (d) => { dialogs.push(d.type() + ': ' + d.message()); await d.dismiss().catch(() => {}); });
        const m = (report[w] = {});

        await signIn(page);
        m.beforeAnything = await topBar(page);

        // ── 1. Header: type, leave ─────────────────────────────────────────
        await go(page, 'header');
        await page.waitForSelector('#hdSave', { timeout: 15000 });
        // The first text box the Header screen has, on whichever tab holds it.
        m.headerTab = await page.evaluate(() => (HD.tabs.find((t) => t.fields.some((f) => f.type === 'text')) || {}).key);
        await page.click(`[data-hdtab="${m.headerTab}"]`);
        const box = page.locator('input[type=text][data-hd]').first();
        m.headerField = await box.getAttribute('data-hd');
        m.headerSaved = await box.inputValue();
        m.typed = 'Bliss Lab ' + (Date.now() % 10000);
        await box.fill(m.typed);
        await settle(page);
        await page.screenshot({ path: `${OUT}/1-header-typed-${w}.png` });
        await go(page, 'dash');
        await settle(page);
        m.dialogsAfterLeavingHeader = dialogs.slice();

        // ── 2. the top bar and its list ────────────────────────────────────
        m.afterLeaving = await topBar(page);
        await page.locator('.main .top').screenshot({ path: `${OUT}/2-topbar-unfinished-${w}.png` });
        await page.click('#kbbDraftsBtn');
        await page.waitForTimeout(150);
        m.panel = await panel(page);
        await page.screenshot({ path: `${OUT}/3-list-open-${w}.png` });
        await page.keyboard.press('Escape');
        m.afterEscape = await page.evaluate(() => ({
            open: !document.getElementById('kbbDraftsPanel').hidden,
            focusIsButton: document.activeElement === document.getElementById('kbbDraftsBtn'),
        }));

        // ── 3. Open ─────────────────────────────────────────────────────────
        await page.click('#kbbDraftsBtn');
        await page.click('[data-kbbdr-open="header"]');
        await page.waitForSelector('#hdSave', { timeout: 15000 });
        await settle(page);
        m.restoredValue = await page.locator(`input[data-hd="${m.headerField}"]`).inputValue();
        m.restoreBar = await bar(page);
        m.dirtyMarker = await page.evaluate(() => getComputedStyle(document.querySelector('#hdDirty')).visibility);
        await page.screenshot({ path: `${OUT}/4-header-restored-${w}.png` });
        m.scrollWidthRestored = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);

        // ── 4. Save from the bar ───────────────────────────────────────────
        const [resp] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/admin-api/header') && r.request().method() === 'POST'),
            page.click('#kbbDraftBarA'),
        ]);
        m.saveStatus = resp.status();
        await settle(page);
        m.afterSave = { top: await topBar(page), bar: await bar(page),
            stored: await page.evaluate(() => Object.keys(window.kbbDrafts.list())) };
        await page.screenshot({ path: `${OUT}/5-header-saved-${w}.png` });

        if (w === 1280) {
            // ── 5. Banners: rename the set, leave ───────────────────────────
            await go(page, 'banners');
            await page.waitForSelector('[data-bns-open]', { timeout: 15000 });
            await page.click('[data-bns-open]');
            await page.waitForSelector('#bns-saveset', { timeout: 15000 });
            const nameBox = page.locator('input[data-bns-set="name"]').first();
            m.bannerNameSelector = await nameBox.count();
            if (m.bannerNameSelector) {
                await nameBox.fill('Spring sale, renamed');
            }
            await settle(page);
            m.bannerCountOnScreen = await page.textContent('#bns-saveset');
            await go(page, 'dash');
            await settle(page);
            m.dialogsAfterLeavingBanners = dialogs.slice();

            // ── 6. a refresh with banner typing: still there ───────────────
            await go(page, 'banners');
            await page.waitForSelector('[data-bns-open]', { timeout: 15000 });
            await page.click('[data-bns-open]');
            await page.waitForSelector('#bns-saveset', { timeout: 15000 });
            await settle(page);
            m.bannerBarOnReopen = await bar(page);
            await page.reload({ waitUntil: 'networkidle' });
            await page.waitForTimeout(800);
            m.dialogsAfterRefresh = dialogs.slice();
            m.afterRefresh = await topBar(page);

            // ── 7. Reorder ─────────────────────────────────────────────────
            await go(page, 'catalog', 'reorder');
            await page.waitForSelector('#reSave', { timeout: 15000 });
            await settle(page);
            m.reorderBefore = await page.evaluate(() => reorderLocal.map((p) => p.name));
            await page.evaluate(() => reorderLocalMove(reorderLocal[reorderLocal.length - 1].id, 0));
            await settle(page);
            m.reorderMoved = await page.evaluate(() => reorderLocal.map((p) => p.name));
            await page.click('#reTypeBrand');
            await settle(page);
            m.dialogsAfterReorderSwitch = dialogs.slice();

            // ── 8. stale: typed, then saved elsewhere ──────────────────────
            await go(page, 'header');
            await page.waitForSelector('#hdSave', { timeout: 15000 });
            await page.locator(`input[data-hd="${m.headerField}"]`).fill('My unfinished words');
            await settle(page);
            await go(page, 'dash');
            await settle(page);
            m.elsewhere = await page.evaluate(async (field) => {
                const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/header';
                const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
                const cur = await (await fetch(base, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
                const settings = {};
                cur.tabs.forEach((t) => t.fields.forEach((f) => { settings[f.key] = f.value; }));
                settings[field] = 'Saved in another tab';
                const r = await fetch(base, { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': tok },
                    body: JSON.stringify({ settings }) });
                return r.status;
            }, m.headerField);

            await page.click('#kbbDraftsBtn');
            await page.waitForTimeout(150);
            m.panelThree = await panel(page);
            await page.screenshot({ path: `${OUT}/6-list-three-rows-${w}.png` });
            await page.click('[data-kbbdr-open="header"]');
            await page.waitForSelector('#hdSave', { timeout: 15000 });
            await settle(page);
            m.staleBar = await bar(page);
            m.staleValueOnScreen = await page.locator(`input[data-hd="${m.headerField}"]`).inputValue();
            await page.screenshot({ path: `${OUT}/7-header-stale-${w}.png` });
            await page.click('#kbbDraftBarA');
            await settle(page);
            m.afterUseMine = { bar: await bar(page), value: await page.locator(`input[data-hd="${m.headerField}"]`).inputValue() };

            // ── 7b. Reorder: Open brings the order back ────────────────────
            await go(page, 'dash');
            await settle(page);
            const reorderKey = await page.evaluate(() => Object.keys(window.kbbDrafts.list()).find((k) => k.indexOf('reorder:') === 0));
            m.reorderKey = reorderKey;
            await page.click('#kbbDraftsBtn');
            await page.click(`[data-kbbdr-open="${reorderKey}"]`);
            await page.waitForSelector('#reSave', { timeout: 15000 });
            await settle(page);
            m.reorderRestored = await page.evaluate(() => ({ names: reorderLocal.map((p) => p.name), dirty: reorderDirty,
                saveEnabled: !document.querySelector('#reSave').disabled }));
            m.reorderBar = await bar(page);
            await page.screenshot({ path: `${OUT}/8-reorder-restored-${w}.png` });

            // ── 9. Discard asks first ──────────────────────────────────────
            await go(page, 'dash');
            await settle(page);
            const bannerKey = await page.evaluate(() => Object.keys(window.kbbDrafts.list()).find((k) => k.indexOf('banners:') === 0));
            m.bannerKey = bannerKey;
            await page.click('#kbbDraftsBtn');
            await page.click(`[data-kbbdr-discard="${bannerKey}"]`);
            await page.waitForTimeout(200);
            m.sure = await page.evaluate(() => ({
                shown: document.getElementById('kbbSureBg').classList.contains('on'),
                text: document.getElementById('kbbSureText').textContent,
            }));
            await page.screenshot({ path: `${OUT}/9-discard-asks-${w}.png` });
            await page.click('#kbbSureNo');
            m.afterNo = Object.keys(await page.evaluate(() => window.kbbDrafts.list()));
            await page.click(`[data-kbbdr-discard="${bannerKey}"]`);
            await page.waitForTimeout(150);
            await page.click('#kbbSureYes');
            await page.waitForTimeout(200);
            m.afterYes = Object.keys(await page.evaluate(() => window.kbbDrafts.list()));
            m.dialogsTotal = dialogs.slice();
        }

        m.errors = errors;
        await ctx.close();
    }

    await browser.close();
    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
