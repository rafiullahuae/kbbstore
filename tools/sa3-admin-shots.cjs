/*
 * Appearance → Set — the two-column re-layout, photographed.       (Lane SA3)
 *
 * The owner asked for four things and each has a picture here:
 *
 *   the preview on the right      admin-set-<group>-<w>.png at 1280 and 1680
 *   real sections                 every one of the eight groups, one at a time
 *   super nice                    the same
 *   real time                     admin-set-live-before / -after, which are the
 *                                 SAME document with a slider dragged between
 *                                 them and NO request in between
 *
 * …and the Arabic preview, which this screen could not draw at all before.
 *
 * Its head — the login, the admin shell's own style and script blocks, the
 * `window.go('setap')` navigation — is Lane SA's, copied rather than imported
 * because a .cjs in tools/ has no module boundary worth inventing.
 *
 * It measures with getBoundingClientRect and getComputedStyle, which is the
 * right tool for evidence and exactly what CLAUDE.md forbids in SHIPPED page
 * code. Nothing here ships.
 *
 * ── IT DOES NOT INJECT THE PARTIAL, AND THAT IS A CHANGE ──────────────────
 *
 * Lane SA's and Lane SA2's shot scripts read the screen off disk and injected
 * its <style> and <script> into the loaded console, because
 * app.blade.php did not yet carry the include. It does now
 * (`@include('admin.partials.set-appearance-screen')`, and routes/web.php
 * requires routes/set-appearance-admin.php), so injecting runs the screen
 * TWICE: two copies of the script, two window.go wrappers, two delegated input
 * listeners — and the first copy's `draft` is null because the second copy's
 * wrapper is the one window.go now points at, so every slider threw
 * `Cannot set properties of null` into the console while still working.
 *
 * Measured here before it was taken out. This script navigates, and nothing
 * more.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SA_BASE || 'http://127.0.0.1:8993';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SA_OUT || `${APP}/docs/lane-sa3-shots`;

const measure = () => {
    const side = document.querySelector('.sap-side');
    const left = document.querySelector('.sap-left');
    const frame = document.querySelector('#sap-frame');
    const r = (el) => (el ? el.getBoundingClientRect() : null);
    const box = r(side);
    const lbox = r(left);
    const fbox = r(frame);

    return {
        viewport: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        pageHeight: document.documentElement.scrollHeight,
        contentScrollWidth: document.querySelector('#content') ? document.querySelector('#content').scrollWidth : null,
        /* The measurement "avoid the long page" is really about. The console
           scrolls an inner element, so documentElement.scrollHeight is just the
           viewport and says nothing. */
        contentScrollHeight: document.querySelector('#content') ? document.querySelector('#content').scrollHeight : null,
        wrapHeight: document.querySelector('.sap-wrap')
            ? Math.round(document.querySelector('.sap-wrap').getBoundingClientRect().height) : null,
        crumb: document.querySelector('#crumb') ? document.querySelector('#crumb').textContent : null,
        title: document.querySelector('#ptitle') ? document.querySelector('#ptitle').textContent : null,
        sidebarRows: document.querySelectorAll('#nav [data-go="setap"]').length,
        openTab: document.querySelector('.sap-tab[aria-selected="true"]')
            ? document.querySelector('.sap-tab[aria-selected="true"]').textContent : null,
        openGroup: document.querySelector('.sap-g[aria-current="true"]')
            ? document.querySelector('.sap-g[aria-current="true"]').textContent : null,
        groupChips: document.querySelectorAll('.sap-g').length,
        cards: document.querySelectorAll('.sap-card').length,
        controlsOnScreen: document.querySelectorAll('[data-sap-key]').length,
        sliders: document.querySelectorAll('input[type=range][data-sap-key]').length,
        switches: document.querySelectorAll('input[type=checkbox][data-sap-key]').length,
        colours: document.querySelectorAll('[data-sap-swatch]').length,
        shippedBadges: document.querySelectorAll('[data-sap-reset]').length,
        bar: document.querySelector('.sap-count') ? document.querySelector('.sap-count').textContent : null,
        saveDisabled: document.querySelector('[data-sap-save]') ? document.querySelector('[data-sap-save]').disabled : null,
        ungrouped: [...document.querySelectorAll('.sap-title')].filter(t => t.textContent.includes('Not yet grouped')).length,
        sideSticky: side ? getComputedStyle(side).position : null,
        sideTop: side ? getComputedStyle(side).top : null,
        sideWidth: box ? Math.round(box.width) : null,
        sideLeft: box ? Math.round(box.left) : null,
        leftWidth: lbox ? Math.round(lbox.width) : null,
        frameWidth: fbox ? Math.round(fbox.width) : null,
        frameHeight: fbox ? Math.round(fbox.height) : null,
        twoColumns: !!(box && lbox && box.left > lbox.left + lbox.width - 4),
    };
};

async function shoot(page, name, w, h) {
    await page.setViewportSize({ width: w, height: h || 1500 });
    await page.waitForTimeout(650);
    const m = await page.evaluate(measure);
    await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
    console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
    return m;
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1500 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    page.on('pageerror', e => console.log(JSON.stringify({ pageerror: String(e) })));
    page.on('console', m => { if (m.type() === 'error') console.log(JSON.stringify({ consoleerror: m.text() })); });

    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('setap'));
    await page.waitForTimeout(2200);

    /* How many requests the preview endpoint really takes. Counted from here
       on, so the initial render is request 1. */
    let previews = 0;
    page.on('request', r => { if (r.url().includes('/set-appearance/preview')) previews++; });

    const groups = await page.evaluate(() => [...document.querySelectorAll('.sap-g')]
        .map(b => ({ id: b.dataset.sapGroup, label: b.textContent.trim() })));
    console.log(JSON.stringify({ desktopGroups: groups }));

    /* The measurement "avoid the long page" is judged against: how tall the
       screen is with nothing scrolled and nothing opened. Run with SA_BLADE
       pointed at the old file for the before. */
    if (process.env.SA_MEASURE_ONLY) {
        for (const w of [1280, 1680, 390]) {
            await page.setViewportSize({ width: w, height: 900 });
            await page.waitForTimeout(700);
            console.log(JSON.stringify({ measureOnly: w, ...(await page.evaluate(measure)) }));
            await page.screenshot({ path: `${OUT}/${process.env.SA_TAG || ''}whole-${w}.png`, fullPage: true });
        }
        await browser.close();
        return;
    }

    /* ── 1. every group, at 1280 and 1680 ─────────────────────────────── */
    for (const g of groups) {
        await page.evaluate((id) => document.querySelector('[data-sap-group="' + id + '"]').click(), g.id);
        await page.waitForTimeout(500);
        for (const w of [1280, 1680]) await shoot(page, `desk-${g.id}`, w);
    }

    /* ── 2. the phone, because he reviews on one ──────────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-group="panel"]').click());
    await page.waitForTimeout(400);
    await shoot(page, 'desk-panel', 390, 1400);

    /* ── 3. the Mobile tab ────────────────────────────────────────────── */
    await page.setViewportSize({ width: 1680, height: 1500 });
    await page.evaluate(() => document.querySelector('[data-sap-tab="mob"]').click());
    await page.waitForTimeout(900);
    const mobGroups = await page.evaluate(() => [...document.querySelectorAll('.sap-g')]
        .map(b => ({ id: b.dataset.sapGroup, label: b.textContent.trim() })));
    console.log(JSON.stringify({ mobileGroups: mobGroups }));

    for (const g of mobGroups.map(x => x.id)) {
        await page.evaluate((id) => document.querySelector('[data-sap-group="' + id + '"]').click(), g);
        await page.waitForTimeout(500);
        await shoot(page, `mob-${g}`, 1680);
    }
    await shoot(page, 'mob-where', 390, 1200);

    /* ── 4. REAL TIME: one document, a slider dragged, no request ─────── */
    await page.setViewportSize({ width: 1680, height: 1500 });
    await page.evaluate(() => document.querySelector('[data-sap-tab="desk"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector('[data-sap-group="panel"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector('[data-sap-w="760"]').click());
    await page.waitForTimeout(1400);

    const insideFrame = async () => {
        const f = page.frames().find(x => x !== page.mainFrame());
        if (!f) return null;
        return f.evaluate(() => {
            const ksl = document.querySelector('.ksl');
            const chip = document.querySelector('.ksl .ksl-ph, .ksl img, .ksl .ksl-row');
            const cs = ksl ? getComputedStyle(ksl) : null;
            const setRow = document.querySelector('.ci.ci-set');
            const plainRow = document.querySelector('.ci:not(.ci-set)');
            return {
                overlay: (document.getElementById('kbb-set-live') || {}).textContent ?
                    document.getElementById('kbb-set-live').textContent.length : 0,
                kslPhoto: cs ? cs.getPropertyValue('--ksl-ph').trim() : null,
                kslPanelPadS: cs ? cs.getPropertyValue('--ksl-pps').trim() : null,
                kslPanelRadius: cs ? cs.getPropertyValue('--ksl-pr').trim() : null,
                kslOver: cs ? cs.getPropertyValue('--ksl-over').trim() : null,
                photoBox: chip ? Math.round(chip.getBoundingClientRect().width) : null,
                setRowPad: setRow ? getComputedStyle(setRow).padding : null,
                plainRowPad: plainRow ? getComputedStyle(plainRow).padding : null,
                dir: document.documentElement.dir,
                lang: document.documentElement.lang,
            };
        });
    };

    const before = await insideFrame();
    const previewsBefore = previews;
    await shoot(page, 'live-before', 1680);

    /* Drag "Photograph size" and "How far the photographs hang past the panel"
       through their full travel, the way a hand does — 24 input events. */
    await page.evaluate(() => {
        const set = (key, v) => {
            const el = document.querySelector('[data-sap-key="' + key + '"]');
            el.value = String(v);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        };
        for (let i = 0; i <= 11; i++) set('p_over', 10 + i * 2);
        for (let i = 0; i <= 11; i++) set('p_panel_r', 18 + i * 2);
    });
    await page.waitForTimeout(500);

    const after = await insideFrame();
    const previewsDuringDrag = previews - previewsBefore;
    await shoot(page, 'live-after', 1680);

    console.log(JSON.stringify({
        realTime: { before, after, previewRequestsDuringDrag: previewsDuringDrag, inputEvents: 24 },
    }));

    /* ── 5. a STRUCTURAL control, which does take the server path ─────── */
    const previewsBeforeStructural = previews;
    await page.evaluate(() => document.querySelector('[data-sap-group="parts"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const el = document.querySelector('[data-sap-key="p_photo_on"]');
        el.checked = false;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(1600);
    const structural = await insideFrame();
    await shoot(page, 'live-structural', 1680);
    console.log(JSON.stringify({
        structural: { ...structural, previewRequests: previews - previewsBeforeStructural },
    }));

    // …and put it back so the Arabic shots are the shipped rendering.
    await page.evaluate(() => {
        const el = document.querySelector('[data-sap-key="p_photo_on"]');
        el.checked = true;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(1600);

    /* ── 6. the set row and the ordinary row ──────────────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-group="cart"]').click());
    await page.waitForTimeout(500);
    const cartBefore = await insideFrame();
    await shoot(page, 'cart-rows-before', 1680);

    await page.evaluate(() => {
        const set = (key, v) => {
            const el = document.querySelector('[data-sap-key="' + key + '"]');
            el.value = String(v);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        };
        set('ci_pad_t', 34);
        set('ci_pad_b', 34);
    });
    await page.waitForTimeout(1500);
    const cartAfter = await insideFrame();
    await shoot(page, 'cart-rows-after', 1680);
    console.log(JSON.stringify({ cartRows: { before: cartBefore, after: cartAfter } }));

    /* ── 7. العربية ──────────────────────────────────────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-group="panel"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const b = document.querySelector('[data-sap-loc="ar"]');
        if (b) b.click();
    });
    await page.waitForTimeout(1800);
    const arabic = await insideFrame();
    await shoot(page, 'arabic-panel', 1680);
    await shoot(page, 'arabic-panel', 390, 1400);
    console.log(JSON.stringify({ arabic }));

    await browser.close();
})();
