/*
 * Converting an imported product to a set, on the real product editor.
 *                                                     (Lane PI-A, item 10)
 *
 *   sh tools/pia-set-preview.sh 9450
 *   PIA_BASE=http://127.0.0.1:9450 PIA_OUT=storage/pia-logs/shots/convert \
 *     NODE_PATH=/home/user/kbbstore/node_modules node tools/pia-convert-shots.cjs
 *
 * Drives what the owner would do, through the screen and not the API:
 *   1. open "Medicube - PDRN Glow Booster Set (Pink Edition)" (a simple
 *      product with a WooCommerce id) in Catalog -> Product editor
 *   2. change Product type to Set (and accept whatever the screen asks)
 *   3. search the catalogue in "What is in the box", add the booster, the
 *      capsule cream's 50ml option and the serum, the serum twice
 *   4. Save, and read back what the server now holds
 * then photographs the editor and the storefront page at 390 and 1280.
 *
 * Every response of product-editor-save is recorded, and `dialogs` records
 * every confirm() the screen raised, word for word.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PIA_BASE || 'http://127.0.0.1:9450';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PIA_OUT || 'storage/pia-logs/shots/convert');
const SLUG = 'medicube-pdrn-glow-booster-set-pink-edition';
const PHASE = process.env.PIA_PHASE || 'all';

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
}

async function idOf(page, slug) {
    return page.evaluate(async (s) => {
        const r = await fetch('/admin-api/product-editor-list?q=' + encodeURIComponent('Pink Edition'), { headers: { Accept: 'application/json' } });
        const b = await r.json();
        const hit = (b.products || []).find((p) => p.slug === s);
        return hit ? hit.id : null;
    }, slug);
}

async function load(page, id) {
    return page.evaluate(async (n) => {
        const r = await fetch('/admin-api/product-editor-load/' + n, { headers: { Accept: 'application/json' } });
        return r.json();
    }, id);
}

async function open(page, id) {
    await page.evaluate((n) => window.peoEdit(n), id);
    await page.waitForTimeout(1600);
}

const boxCard = (p) => p.locator('.peo-card', { has: p.locator('h3', { hasText: /^What is in the box$/ }) }).first();
const stockCard = (p) => p.locator('.peo-card', { has: p.locator('h3', { hasText: /^Stock$/ }) }).first();

/* The editor's type field and, when drawn, the box panel -- clipped to what
   the owner is looking at rather than the whole console. */
async function shootEditor(page, name) {
    const typeField = page.locator('#peo-type').first();
    if (await typeField.count()) await typeField.scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${name}.png` });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const result = { dialogs: [], saves: [] };

    /* ── the conversion, driven through the screen at 1280 ─────────────── */
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await ctx.newPage();
    page.on('dialog', (d) => { result.dialogs.push(d.message()); d.accept(); });
    page.on('response', async (r) => {
        if (r.url().includes('/admin-api/product-editor-save/')) {
            let body = null;
            try { body = await r.json(); } catch (e) { body = String(e); }
            result.saves.push({ status: r.status(), ok: body && body.ok, message: body && body.message, errors: body && body.errors });
        }
    });

    await signIn(page);
    const id = await idOf(page, SLUG);
    result.id = id;
    result.before = await load(page, id);

    await open(page, id);
    await shootEditor(page, 'editor-before-1280');
    await page.locator('#peo-type').first().locator('xpath=ancestor::div[contains(@class,"peo-fld")][1]').screenshot({ path: `${OUT}/control-before-1280.png` });

    if (PHASE !== 'before') {
        await page.selectOption('#peo-type', 'set');
        await page.waitForTimeout(700);
        await shootEditor(page, 'editor-switched-1280');
        await page.locator('#peo-type').first().locator('xpath=ancestor::div[contains(@class,"peo-fld")][1]').screenshot({ path: `${OUT}/control-switched-1280.png` });

        for (const [q, variantLabel, qty] of [['Medicube AGE-R Booster', null, 1], ['Capsule Cream', '50', 1], ['Peptide Serum', null, 2]]) {
            await page.fill('#peo-setq', q);
            await page.waitForTimeout(900);
            const row = page.locator('.peo-setm.is-find').first();
            if (variantLabel) {
                const sel = row.locator('select.peo-setvar');
                if (await sel.count()) {
                    const opts = await sel.locator('option').allTextContents();
                    const pick = opts.find((o) => o.includes(variantLabel)) || opts[1];
                    await sel.selectOption({ label: pick });
                }
            }
            await row.locator('[data-peo-add]').click();
            await page.waitForTimeout(500);
            if (qty > 1) {
                const n = await page.locator('.peo-setm:not(.is-find) [data-peo-mq]').count();
                const input = page.locator('.peo-setm:not(.is-find) [data-peo-mq]').nth(n - 1);
                await input.fill(String(qty));
                await input.dispatchEvent('input');
                await input.dispatchEvent('change');
                await page.waitForTimeout(300);
            }
        }

        await boxCard(page).screenshot({ path: `${OUT}/editor-members-1280.png` });

        await page.click('#peo-save');
        await page.waitForTimeout(2500);
        await page.screenshot({ path: `${OUT}/editor-saved-1280.png` });

        result.after = await load(page, id);

        await open(page, id);
        await boxCard(page).screenshot({ path: `${OUT}/editor-reopened-box-1280.png` });
        await stockCard(page).screenshot({ path: `${OUT}/editor-reopened-stock-1280.png` });
    }
    await ctx.close();

    /* ── the editor at 390 ──────────────────────────────────────────────── */
    const phone = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
    phone.on('dialog', (d) => d.dismiss());
    await signIn(phone);
    await open(phone, id);
    await shootEditor(phone, PHASE === 'before' ? 'editor-before-390' : 'editor-after-390');
    if (PHASE !== 'before') {
        // Viewport shots on the phone: an element shot of a card taller than the
        // screen comes out with the sticky save bar painted through it.
        await boxCard(phone).locator('.peo-setlist').first().scrollIntoViewIfNeeded();
        await phone.waitForTimeout(300);
        await phone.screenshot({ path: `${OUT}/editor-members-390.png` });
        await stockCard(phone).screenshot({ path: `${OUT}/editor-stock-390.png` });
    }
    result.editorScrollWidth390 = await phone.evaluate(() => document.documentElement.scrollWidth);

    /* ── the storefront page, both widths ─────────────────────────────── */
    result.storefront = {};
    for (const [w, h] of [[390, 844], [1280, 900]]) {
        const p = await (await browser.newContext({ viewport: { width: w, height: h } })).newPage();
        for (const slug of [SLUG, 'pia-native-pdrn-set']) {
            await p.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
            const tag = slug === SLUG ? 'converted' : 'native';
            const panel = p.locator('.ksl-panel').first();
            const hasPanel = (await panel.count()) > 0;
            if (hasPanel) await panel.scrollIntoViewIfNeeded();
            await p.waitForTimeout(300);
            await p.screenshot({ path: `${OUT}/storefront-${tag}-${PHASE === 'before' ? 'before' : 'after'}-${w}.png` });
            result.storefront[`${tag}-${w}`] = await p.evaluate((has) => ({
                setPanel: has,
                panelText: has ? (document.querySelector('.ksl-panel').innerText || '').slice(0, 400) : null,
                price: (document.querySelector('.bb-price') || {}).innerText || null,
                tabs: [...document.querySelectorAll('#details .dtab')].map((b) => b.textContent.trim()),
                reviews: (document.querySelector('#bbRate, .bb-rate') || {}).innerText || null,
                scrollWidth: document.documentElement.scrollWidth,
            }), hasPanel);
        }
    }

    fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify({ id: result.id, dialogs: result.dialogs, saves: result.saves, storefront: result.storefront,
        editorScrollWidth390: result.editorScrollWidth390 }, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
