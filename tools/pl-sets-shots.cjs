/*
 * "Set shown first, by brand", photographed.                       (Lane PL)
 *
 *   admin      Store -> Site Search -> Sets in search, with Anua's choice made
 *              (seeded) and then a second one picked in the screen and saved,
 *              so the save path is driven by the real UI, not only the seed
 *   search     the header search box typed "anua" five times: the chosen set
 *              at #1 every time, under the random rule
 *
 * Usage: sh tools/pl-sets-preview.sh   (prints the port)
 *        PL_BASE=http://127.0.0.1:9470 node tools/pl-sets-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PL_BASE || 'http://127.0.0.1:9470';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PL_OUT || 'storage/pl-logs/shots');
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
    await page.waitForTimeout(1000);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const measure = {};

    for (const [w, h] of WIDTHS) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        const m = (measure[w] = {});

        // ── the admin list ───────────────────────────────────────────────
        await signIn(page);
        await page.evaluate(() => window.go('search'));
        await page.waitForSelector('[data-sstab="sets"]', { timeout: 15000 });
        await page.click('[data-sstab="sets"]');
        await page.waitForSelector('select[data-ssbrand]', { timeout: 10000 });
        await page.waitForTimeout(300);

        m.adminRows = await page.$$eval('.ssbrand .mmrow', (rows) => rows.map((r) => ({
            brand: r.querySelector('.mmlbl b')?.textContent,
            count: r.querySelector('.ssbn')?.textContent,
            chosen: r.querySelector('select')?.selectedOptions[0]?.textContent,
            options: [...(r.querySelector('select')?.options || [])].map((o) => o.textContent),
            selectWidth: Math.round(r.querySelector('select')?.getBoundingClientRect().width || 0),
        })));
        m.adminScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        m.adminClientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        const card = page.locator('.mmcard').first();
        await card.screenshot({ path: `${OUT}/admin-sets-by-brand-${w}.png` });
        await page.screenshot({ path: `${OUT}/admin-sets-by-brand-page-${w}.png`, fullPage: true });

        // Pick COSRX's set in the screen and save, at 1280 only (once).
        if (w === 1280) {
            const cosrx = page.locator('.ssbrand .mmrow', { hasText: 'COSRX' }).locator('select');
            await cosrx.selectOption({ label: 'COSRX Snail Routine Set' });
            m.dirtyAfterPick = await page.textContent('#ssDirty');
            const [resp] = await Promise.all([
                page.waitForResponse((r) => r.url().includes('/admin-api/site-search') && r.request().method() === 'POST'),
                page.click('#ssSave'),
            ]);
            m.saveStatus = resp.status();
            m.saveBody = await resp.json();
            await page.waitForTimeout(400);
            m.savedMessage = await page.textContent('#ssDirty');
            await card.screenshot({ path: `${OUT}/admin-sets-by-brand-saved-${w}.png` });
            m.afterSave = await page.$$eval('.ssbrand .mmrow', (rows) => rows.map((r) => ({
                brand: r.querySelector('.mmlbl b')?.textContent,
                chosen: r.querySelector('select')?.selectedOptions[0]?.textContent,
            })));
        }

        // ── the storefront search box ───────────────────────────────────
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        const field = page.locator('.search-in input[type="search"]').first();
        if (!(await field.isVisible())) {
            const opener = page.locator('[data-search-open], .search-toggle, .hd-search, button[aria-label*="earch"]').first();
            if (await opener.count()) await opener.click();
        }

        m.firstRows = [];
        for (let i = 0; i < 5; i++) {
            await field.fill('');
            await page.waitForTimeout(150);
            await field.fill(i % 2 ? 'Anua' : 'anua');
            await page.waitForTimeout(900);
            m.firstRows.push(await page.$$eval('#kbbSuggest a[data-sg-row] .sn', (els) => els[0]?.childNodes[0]?.textContent || null));
        }
        // And straight from the endpoint, 20 more times.
        m.apiFirsts = await page.evaluate(async (base) => {
            const seen = {};
            for (let i = 0; i < 20; i++) {
                const j = await (await fetch(base + '/api/search?q=anua')).json();
                const p = (j.groups || []).find((g) => g.key === 'products');
                const f = p?.items?.[0]?.label;
                seen[f] = (seen[f] || 0) + 1;
            }
            return seen;
        }, BASE);
        m.searchScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        m.searchClientWidth = await page.evaluate(() => document.documentElement.clientWidth);
        await page.screenshot({ path: `${OUT}/search-anua-${w}.png` });

        // Medicube: no choice, so the rule still rotates.
        m.medicubeFirsts = await page.evaluate(async (base) => {
            const seen = {};
            for (let i = 0; i < 20; i++) {
                const j = await (await fetch(base + '/api/search?q=medicube')).json();
                const p = (j.groups || []).find((g) => g.key === 'products');
                const f = p?.items?.[0]?.label;
                seen[f] = (seen[f] || 0) + 1;
            }
            return seen;
        }, BASE);
        m.errors = errors;
        await ctx.close();
    }

    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(measure, null, 2));
    console.log(JSON.stringify(measure, null, 2));
    await browser.close();
})();
