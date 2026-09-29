/*
 * Appearance → Set → Desktop / Mobile, photographed.                (Lane SA)
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

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const { style, script } = partialBlocks();

    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();

    page.on('console', (m) => { if (m.type() === 'error') console.log(JSON.stringify({ consoleError: m.text() })); });
    page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
    ]);

    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);

    await page.addStyleTag({ content: style });
    await page.addScriptTag({ content: script });
    await page.waitForTimeout(300);

    await page.evaluate(() => window.go('setap'));
    await page.waitForTimeout(1600);

    for (const w of [390, 1280]) await shoot(page, 'admin-set-desktop-tab', w, 1600);

    await page.evaluate(() => document.querySelector('[data-sap-tab="mob"]').click());
    await page.waitForTimeout(900);
    for (const w of [390, 1280]) await shoot(page, 'admin-set-mobile-tab', w, 1600);

    /* Back to Desktop, move three controls, and photograph the unsaved bar and
       the preview redrawing from the BUFFER — which is the whole point of the
       Save button the owner asked for on Banners. Nothing is saved here. */
    await page.evaluate(() => document.querySelector('[data-sap-tab="desk"]').click());
    await page.waitForTimeout(500);
    /* ONE AT A TIME, with a wait between. The screen redraws when the bar's
       DIRTINESS flips, so an element captured before the first change is
       detached by the second — three sets in one evaluate registered exactly
       one, which is what the first run of this script photographed. */
    for (const [key, value] of [['circle', 110], ['overlap', 20], ['btn_f', 130], ['fan_max', 4], ['save_c', '#B42318']]) {
        await page.evaluate(([k, v]) => {
            const el = document.querySelector('[data-sap-key="' + k + '"]');
            el.value = v;
            el.dispatchEvent(new Event('input', { bubbles: true }));
            /* And `change`, which is what a slider fires when it is RELEASED —
               the event the screen redraws on, so the per-field "shipped"
               buttons appear in the picture the way they do for a real hand. */
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, [key, value]);
        await page.waitForTimeout(450);
    }
    await page.waitForTimeout(1400);
    for (const w of [390, 1280]) await shoot(page, 'admin-set-unsaved-changes', w, 1600);

    console.log(JSON.stringify({
        unsavedBar: await page.evaluate(() => document.querySelector('.sap-count').textContent),
        saveEnabled: await page.evaluate(() => !document.querySelector('[data-sap-save]').disabled),
    }));

    await browser.close();
})();
