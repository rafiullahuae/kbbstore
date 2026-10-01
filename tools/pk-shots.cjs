/*
 * Lane PK evidence: the set panel's price button and the editor's Visit button,
 * driven through the real screen.
 *
 *   sh tools/pk-preview.sh 9470
 *   PK_BASE=http://127.0.0.1:9470 PK_OUT=storage/pk-logs/shots/after PK_PHASE=after \
 *     NODE_PATH=/home/user/kbbstore/node_modules node tools/pk-shots.cjs
 *
 * PK_PHASE=before photographs the shop as it is (the old "Use this total"
 * button), PK_PHASE=after the fixed one. Both press the SAME element
 * (#peo-usetotal, whose id did not change), so the two runs are one gesture on
 * two builds. Every figure is read from the DOM or from the editor's own load
 * endpoint and written to result.json -- nothing here is typed in by hand.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PK_BASE || 'http://127.0.0.1:9470';
const APP = path.resolve(__dirname, '..');
const PHASE = process.env.PK_PHASE || 'after';
const OUT = path.resolve(APP, process.env.PK_OUT || `storage/pk-logs/shots/${PHASE}`);

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

async function idOf(page, q, slug) {
    return page.evaluate(async ([term, s]) => {
        const r = await fetch('/admin-api/product-editor-list?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } });
        const b = await r.json();
        const hit = (b.products || []).find((p) => p.slug === s);
        return hit ? hit.id : null;
    }, [q, slug]);
}

async function load(page, id) {
    return page.evaluate(async (n) => {
        const r = await fetch('/admin-api/product-editor-load/' + n, { headers: { Accept: 'application/json' } });
        const b = await r.json();
        const p = b.product || b;
        return {
            price_aed: p.price_aed, sale_aed: p.sale_aed, price_mode: p.price_mode,
            discount_amount: p.discount_amount, discount_percent: p.discount_percent,
            set_parts_total_aed: p.set_parts_total_aed, set_effective_aed: p.set_effective_aed,
            set_basis_aed: p.set_basis_aed, sale_starts_at: p.sale_starts_at, sale_ends_at: p.sale_ends_at,
            readonly: p.readonly,
        };
    }, id);
}

async function open(page, id) {
    await page.evaluate((n) => window.peoEdit(n), id);
    await page.waitForTimeout(1600);
}

const card = (p, title) => p.locator('.peo-card', { has: p.locator('h3', { hasText: new RegExp('^' + title + '$') }) }).first();

/* What the owner is looking at: the Price card's two boxes, the mode select,
   the three tiles and the button's own label. */
async function editorState(page) {
    return page.evaluate(() => {
        const q = (s) => document.querySelector('#content ' + s);
        const tile = (k) => { const el = q('[data-peo-money="' + k + '"]'); return el ? el.textContent : null; };
        const btn = q('#peo-usetotal');
        return {
            price: (q('[data-bind="price_aed"]') || {}).value,
            sale: (q('[data-bind="sale_aed"]') || {}).value,
            mode: (q('#peo-pricemode') || {}).value,
            tiles: { parts: tile('parts'), price: tile('price'), saving: tile('saving') },
            button: btn ? btn.textContent : null,
            applied: (q('#peo-setapplied') || {}).textContent || null,
            sub: (q('.peo-bar .peo-sub') || {}).textContent,
        };
    });
}

async function shootSetAndPrice(page, name) {
    // The set panel and the Price card are in the same column; photograph the
    // viewport around the panel (an element shot of a card taller than the
    // screen paints the sticky bar through it -- Lane PI-A's note).
    await card(page, 'Price').scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await card(page, 'Price').screenshot({ path: `${OUT}/${name}-pricecard.png` });
    await card(page, 'What is in the box').locator('.peo-settiles').scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${name}-setpanel.png` });
}

async function pressAndSave(page, id, tag, width, result) {
    await open(page, id);
    result[`${tag}-${width}-opened`] = await editorState(page);
    await shootSetAndPrice(page, `${tag}-${width}-1-opened`);

    await page.click('#peo-usetotal');
    await page.waitForTimeout(500);
    result[`${tag}-${width}-pressed`] = await editorState(page);
    await shootSetAndPrice(page, `${tag}-${width}-2-pressed`);

    await page.click('#peo-save');
    await page.waitForTimeout(2500);
    result[`${tag}-${width}-saved`] = await editorState(page);
    result[`${tag}-${width}-server`] = await load(page, id);
    await shootSetAndPrice(page, `${tag}-${width}-3-saved`);
}

async function storefront(browser, slug, tag, result) {
    for (const [w, h] of [[390, 844], [1280, 900]]) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h } });
        const p = await ctx.newPage();
        await p.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
        await p.waitForTimeout(300);
        await p.screenshot({ path: `${OUT}/shop-${tag}-pdp-${w}.png` });
        const pdp = await p.evaluate(() => {
            const price = document.querySelector('.bb-price');
            const panel = document.querySelector('.ksl-panel');
            const foot = panel && panel.querySelector('.ksl-foot');
            return {
                priceText: price ? price.innerText.replace(/\s+/g, ' ').trim() : null,
                del: price ? [...price.querySelectorAll('del, s')].map((e) => e.textContent.trim()) : [],
                badge: [...document.querySelectorAll('[class*="badge"], [class*="off"], .bb-save, .bb-pct')]
                    .map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => /%/.test(t)).slice(0, 4),
                panelFoot: foot ? foot.innerText.replace(/\s+/g, ' ').trim() : null,
                scrollWidth: document.documentElement.scrollWidth,
            };
        });
        const panel = p.locator('.ksl-panel').first();
        if (await panel.count()) {
            await panel.locator('.ksl-foot').scrollIntoViewIfNeeded();
            await p.waitForTimeout(200);
            await p.screenshot({ path: `${OUT}/shop-${tag}-panel-${w}.png` });
        }

        await p.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
        await p.waitForTimeout(300);
        const cardInfo = await p.evaluate((s) => {
            const a = [...document.querySelectorAll('a[href*="/product/' + s + '/"]')];
            let el = a[0];
            for (let i = 0; el && i < 8 && !(el.querySelector && el.querySelector('del, s, [class*="price"]') && el.innerText.length > 20); i++) el = el.parentElement;
            if (!el) return null;
            el.setAttribute('data-pk-card', '1');
            const reg = el.querySelector('.kbb-card-reg');
            const now = el.querySelector('.kbb-card-price');
            return {
                text: el.innerText.replace(/\s+/g, ' ').trim().slice(0, 200),
                del: [...el.querySelectorAll('del, s')].map((e) => e.textContent.trim()),
                was: reg ? reg.textContent.trim() : null,
                wasDecoration: reg ? getComputedStyle(reg).textDecorationLine : null,
                now: now ? now.textContent.trim() : null,
                pct: (el.innerText.match(/-?\d+%/) || [null])[0],
            };
        }, slug);
        const c = p.locator('[data-pk-card="1"]').first();
        if (await c.count()) {
            await c.scrollIntoViewIfNeeded();
            await p.waitForTimeout(200);
            await c.screenshot({ path: `${OUT}/shop-${tag}-card-${w}.png` });
        }
        result[`shop-${tag}-${w}`] = { pdp, card: cardInfo };
        await ctx.close();
    }
}

async function visitShots(page, ids, width, result) {
    for (const [tag, id] of Object.entries(ids)) {
        await open(page, id);
        await page.locator('#content .peo-bar').first().screenshot({ path: `${OUT}/visit-${tag}-bar-${width}.png` });
        result[`visit-${tag}-${width}`] = await page.evaluate(() => {
            const v = document.querySelector('#content #peo-visit');
            const bar = document.querySelector('#content .peo-bar');
            return v ? {
                tag: v.tagName, text: v.textContent, href: v.getAttribute('href'), target: v.getAttribute('target'),
                rel: v.getAttribute('rel'), disabled: v.hasAttribute('disabled') || v.getAttribute('aria-disabled') === 'true',
                barHeight: Math.round(bar.offsetHeight), scrollWidth: document.documentElement.scrollWidth,
            } : { missing: true, barHeight: Math.round(bar.offsetHeight), scrollWidth: document.documentElement.scrollWidth };
        });
    }
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const result = { phase: PHASE, saves: [] };

    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await ctx.newPage();
    page.on('dialog', (d) => d.accept());
    page.on('response', async (r) => {
        if (r.url().includes('/admin-api/product-editor-save/')) {
            let body = null;
            try { body = await r.json(); } catch (e) { body = String(e); }
            result.saves.push({ status: r.status(), ok: body && body.ok, message: body && body.message, errors: body && body.errors });
        }
    });
    await signIn(page);

    const ids = {
        trio: await idOf(page, 'Glow Trio', 'pk-glow-trio-set'),
        duo: await idOf(page, 'Glow Duo', 'pk-glow-duo-set'),
        draft: await idOf(page, 'Coming Soon', 'pk-coming-soon-toner'),
        simple: await idOf(page, 'LED Booster', 'pk-led-booster'),
    };
    result.ids = ids;
    result['trio-server-before'] = await load(page, ids.trio);
    result['duo-server-before'] = await load(page, ids.duo);

    // Visit first, before any save moves anything.
    if (PHASE === 'after') await visitShots(page, ids, 1280, result);

    // 1. The owner's case: "An amount off the total", 131 off 1310, at 1280.
    //    The shop as he left it -- under the rule, before the button -- first.
    await storefront(browser, 'pk-glow-trio-set', 'trio-asis', result);
    await pressAndSave(page, ids.trio, 'trio', 1280, result);
    await storefront(browser, 'pk-glow-trio-set', 'trio', result);

    // 2. The percentage case, driven on the PHONE.
    const phoneCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const phone = await phoneCtx.newPage();
    phone.on('dialog', (d) => d.accept());
    await signIn(phone);
    await pressAndSave(phone, ids.duo, 'duo', 390, result);
    result.editorScrollWidth390 = await phone.evaluate(() => document.documentElement.scrollWidth);
    if (PHASE === 'after') await visitShots(phone, ids, 390, result);
    await storefront(browser, 'pk-glow-duo-set', 'duo', result);

    // 3. Catalog -> Products, the row actions.
    if (PHASE === 'after') {
        for (const [pg, w] of [[page, 1280], [phone, 390]]) {
            await pg.evaluate(() => window.go && window.go('catalog'));
            await pg.waitForTimeout(1800);
            const rows = await pg.evaluate(() => [...document.querySelectorAll('#catBody tr')].slice(0, 12).map((tr) => {
                const v = tr.querySelector('[data-cpvisit]');
                return v ? { name: (tr.querySelector('.pname') || {}).textContent, tag: v.tagName, text: v.textContent,
                    href: v.getAttribute('href'), target: v.getAttribute('target'), rel: v.getAttribute('rel'),
                    disabled: v.hasAttribute('disabled') } : null;
            }).filter(Boolean));
            result[`list-${w}`] = { rows, scrollWidth: await pg.evaluate(() => document.documentElement.scrollWidth) };
            // The table already scrolled sideways before this lane (1287px in a
            // 994px box at 1280); the row actions are its last column, so the
            // shot is taken with the table scrolled to its right-hand end.
            await pg.evaluate(() => {
                const t = document.querySelector('#catBody table');
                const s = t && t.parentElement;
                if (s) s.scrollLeft = s.scrollWidth;
                if (t) t.scrollIntoView({ block: 'start' });
            });
            await pg.waitForTimeout(300);
            await pg.screenshot({ path: `${OUT}/list-${w}.png` });
        }
    }

    fs.writeFileSync(`${OUT}/result.json`, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
