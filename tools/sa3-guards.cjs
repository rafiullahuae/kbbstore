/*
 * Appearance → Set — the guards the re-layout had to keep.          (Lane SA3)
 *
 * SetAppearanceScreenGroupsTest asserts these are still IN the file. This
 * script asserts they still WORK, in a browser, on the wired console:
 *
 *   · the bar counts every change, not just the first
 *   · Discard asks, and puts them back
 *   · leaving by the sidebar asks, and Cancel really stays
 *   · the per-group "Put this group back to shipped" asks and resets the group
 *   · Save writes, and the bar goes quiet
 *   · the preview keeps its popup OPEN across a live drag, which is the thing
 *     a re-render on every pixel could never do
 *
 * It prints one JSON line per step and shoots the two dialogs' states.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SA_BASE || 'http://127.0.0.1:8993';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SA_OUT || `${APP}/docs/lane-sa3-shots`;

const bar = () => {
    const c = document.querySelector('.sap-count');
    const s = document.querySelector('[data-sap-save]');
    return { text: c ? c.textContent : null, saveDisabled: s ? s.disabled : null };
};

const drag = (key, v) => {
    const el = document.querySelector('[data-sap-key="' + key + '"]');
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
};

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({
        executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    });
    const ctx = await browser.newContext({ viewport: { width: 1680, height: 1400 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log(JSON.stringify({ pageerror: String(e) })));

    let dialogs = [];
    page.on('dialog', async (d) => {
        dialogs.push({ type: d.type(), message: d.message() });
        await d.dismiss();          // Cancel, every time, until we say otherwise
    });

    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('setap'));
    await page.waitForTimeout(2200);

    console.log(JSON.stringify({ step: 'opened', ...(await page.evaluate(bar)) }));

    /* ── 1. THE COUNT COUNTS PAST ONE ────────────────────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-group="panel"]').click());
    await page.waitForTimeout(400);
    for (const [k, v] of [['p_panel_r', 26], ['p_panel_pt', 18], ['p_over', 20], ['p_ring', 44], ['p_sh_y', 5]]) {
        await page.evaluate(drag, [k, v]).catch(() => {});
        await page.evaluate(([key, val]) => {
            const el = document.querySelector('[data-sap-key="' + key + '"]');
            el.value = String(val);
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, [k, v]);
        await page.waitForTimeout(120);
    }
    await page.waitForTimeout(500);
    console.log(JSON.stringify({ step: 'five moved', ...(await page.evaluate(bar)) }));
    await page.screenshot({ path: `${OUT}/guard-bar-counts-1680.png`, fullPage: true });

    /* ── 2. LEAVING BY THE SIDEBAR ASKS, AND CANCEL STAYS ───────────── */
    dialogs = [];
    await page.evaluate(() => window.go('dashboard'));
    await page.waitForTimeout(600);
    console.log(JSON.stringify({
        step: 'leave prompt',
        dialogs,
        stillOnSet: await page.evaluate(() => document.querySelector('#ptitle').textContent),
        ...(await page.evaluate(bar)),
    }));

    /* ── 3. THE GROUP RESET ASKS, AND RESETS ONLY THAT GROUP ────────── */
    dialogs = [];
    await page.evaluate(() => document.querySelector('[data-sap-group="box"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const el = document.querySelector('[data-sap-key="circle"]');
        el.value = '80';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);
    const beforeReset = await page.evaluate(bar);

    page.removeAllListeners('dialog');
    page.on('dialog', async (d) => { dialogs.push({ type: d.type(), message: d.message() }); await d.accept(); });

    await page.evaluate(() => document.querySelector('[data-sap-groupreset]').click());
    await page.waitForTimeout(600);
    console.log(JSON.stringify({
        step: 'group reset', beforeReset, dialogs, after: await page.evaluate(bar),
    }));

    /* ── 4. DISCARD ASKS, AND PUTS EVERYTHING BACK ──────────────────── */
    dialogs = [];
    await page.evaluate(() => document.querySelector('[data-sap-discard]').click());
    await page.waitForTimeout(700);
    console.log(JSON.stringify({ step: 'discard', dialogs, ...(await page.evaluate(bar)) }));
    await page.screenshot({ path: `${OUT}/guard-after-discard-1680.png`, fullPage: true });

    /* ── 5. SAVE WRITES ─────────────────────────────────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-group="panel"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        const el = document.querySelector('[data-sap-key="p_panel_r"]');
        el.value = '24';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector('[data-sap-save]').click());
    await page.waitForTimeout(1200);
    console.log(JSON.stringify({ step: 'saved', ...(await page.evaluate(bar)) }));
    await page.screenshot({ path: `${OUT}/guard-after-save-1680.png`, fullPage: true });

    /* ── 6. THE POPUP STAYS OPEN THROUGH A LIVE DRAG ────────────────── */
    await page.evaluate(() => document.querySelector('[data-sap-w="760"]').click());
    await page.waitForTimeout(1500);
    const frame = () => page.frames().find(f => f !== page.mainFrame());

    await frame().evaluate(() => {
        const b = document.querySelector('.kset-btn');
        if (b) b.click();
    });
    await page.waitForTimeout(400);
    const openBefore = await frame().evaluate(() => document.querySelectorAll('.kset-pop.is-open').length);

    /* WITH the `change` a real mouseup fires, and with a section switch and a
       breakpoint switch on top — every redraw that used to throw the frame
       away. If the popup is still open after all of that, the preview really
       is surviving the owner's hands. */
    await page.evaluate(() => {
        const el = document.querySelector('[data-sap-key="p_over"]');
        for (let i = 0; i <= 8; i++) {
            el.value = String(10 + i * 3);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForTimeout(600);
    await page.evaluate(() => document.querySelector('[data-sap-group="words"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector('[data-sap-tab="mob"]').click());
    await page.waitForTimeout(400);
    await page.evaluate(() => document.querySelector('[data-sap-tab="desk"]').click());
    await page.waitForTimeout(600);
    const openAfter = await frame().evaluate(() => document.querySelectorAll('.kset-pop.is-open').length);
    console.log(JSON.stringify({ step: 'popup survives a live drag', openBefore, openAfter }));
    await page.screenshot({ path: `${OUT}/guard-popup-open-1680.png`, fullPage: true });

    await browser.close();
})();
