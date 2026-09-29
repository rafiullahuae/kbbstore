/*
 * Appearance → Set — the TWO NEW CARDS and the preview.            (Lane SA2)
 *
 * tools/sa-admin-shots.cjs photographs the whole screen; this one photographs
 * the two things Lane SA2 added, scoped to the card so they are legible in a
 * folder that has to stay under a few megabytes:
 *
 *   Desktop · Set list — the panel and the hang    the nine new sliders
 *   Mobile  · Set list — the panel and the hang    their phone twins
 *   Live preview                                   which now draws the product
 *                                                  page's list as well as the
 *                                                  two cart rows
 *
 * Its head — the login, the admin shell's own style and script blocks, the
 * `window.go('setap')` navigation — is Lane SA's, copied rather than imported
 * because a .cjs in tools/ has no module boundary worth inventing for two
 * scripts.
 *
 * It measures with getBoundingClientRect and getComputedStyle, which is the
 * right tool for evidence and exactly what CLAUDE.md forbids in SHIPPED page
 * code. Nothing here ships.
 *
 * Originally: Appearance → Set → Desktop / Mobile, photographed.     (Lane SA)
 *
 * ── WHY THE SCREEN IS INJECTED RATHER THAN INCLUDED ────────────────────────
 *
 * resources/views/admin/app.blade.php is the INTEGRATOR's file and this lane
 * may not edit it, so the console in this preview does not yet carry
 * `@include('admin.partials.set-appearance-screen')`. Rather than photograph a
 * screen that is not there, this script reads the REAL partial off disk, takes
 * out the Blade wrapper (the comment and the verbatim markers — the file is
 * otherwise plain <style> and <script>) and injects exactly those two blocks
 * into the loaded console. What is photographed is therefore the same bytes the
 * integrator will include, running against the same endpoints, with the same
 * window.go wrapper and the same sidebar registration.
 *
 * The moment the include lands this script can be replaced with a plain
 * `window.go('setap')`. Copied from tools/set-admin-shots.cjs, which Lane SET
 * wrote for the same reason.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SA_BASE || 'http://127.0.0.1:8971';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SA_OUT || `${APP}/docs/lane-sa-shots`;

function partialBlocks() {
    let src = fs.readFileSync(`${APP}/resources/views/admin/partials/set-appearance-screen.blade.php`, 'utf8');
    src = src.replace(/\{\{--[\s\S]*?--\}\}/g, '').replace(/@verbatim|@endverbatim/g, '');
    return {
        style: /<style>([\s\S]*?)<\/style>/.exec(src)[1],
        script: /<script>([\s\S]*?)<\/script>/.exec(src)[1],
    };
}

async function shoot(page, name, w, h) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(700);
    const m = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
        contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
        crumb: document.querySelector('#crumb')?.textContent ?? null,
        title: document.querySelector('#ptitle')?.textContent ?? null,
        sidebarRows: document.querySelectorAll('#nav [data-go="setap"]').length,
        openTab: document.querySelector('.sap-tab[aria-selected="true"]')?.textContent ?? null,
        cards: document.querySelectorAll('.sap-card').length,
        controls: document.querySelectorAll('[data-sap-key]').length,
        sliders: document.querySelectorAll('input[type=range][data-sap-key]').length,
        switches: document.querySelectorAll('input[type=checkbox][data-sap-key]').length,
        colours: document.querySelectorAll('[data-sap-swatch]').length,
        shippedBadges: document.querySelectorAll('[data-sap-reset]').length,
        bar: document.querySelector('.sap-count')?.textContent ?? null,
        saveDisabled: document.querySelector('[data-sap-save]')?.disabled ?? null,
        previewHasSet: !!document.querySelector('#sap-frame')?.getAttribute('srcdoc')?.includes('kset-fan'),
    }));
    await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
    console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
}


/* Lane SA2: the two NEW cards and the preview that now draws the list. */
(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const { style, script } = partialBlocks();
    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1600 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.addStyleTag({ content: style });
    await page.addScriptTag({ content: script });
    await page.waitForTimeout(300);
    await page.evaluate(() => window.go('setap'));
    await page.waitForTimeout(1800);

    const cardTitles = await page.evaluate(() => [...document.querySelectorAll('.sap-title')].map(t => t.textContent.trim()));
    console.log(JSON.stringify({ desktopCards: cardTitles }));

    const shootCard = async (needle, name, w) => {
        await page.setViewportSize({ width: w, height: 1600 });
        await page.waitForTimeout(500);
        const h = await page.evaluateHandle((n) => {
            const t = [...document.querySelectorAll('.sap-title')].find(x => x.textContent.includes(n));
            return t ? t.closest('.sap-card') : null;
        }, needle);
        const el = h.asElement();
        if (!el) { console.log(JSON.stringify({ miss: needle })); return; }
        await el.scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        await el.screenshot({ path: `${OUT}/${name}-${w}.png` });
        console.log(JSON.stringify({ shot: `${name}-${w}` }));
    };

    for (const w of [1280, 390]) {
        await shootCard('the panel and the hang', 'admin-set-panel-card', w);
        await shootCard('Live preview', 'admin-set-preview', w);
    }

    await page.evaluate(() => document.querySelector('[data-sap-tab="mob"]').click());
    await page.waitForTimeout(900);
    console.log(JSON.stringify({ mobileCards: await page.evaluate(() => [...document.querySelectorAll('.sap-title')].map(t => t.textContent.trim())) }));
    for (const w of [1280, 390]) await shootCard('the panel and the hang', 'admin-set-mobile-panel-card', w);

    await browser.close();
})();
