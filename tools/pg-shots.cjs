/*
 * Lane PG screenshots: Catalog -> Pagination, and a category with pagination
 * on (Serums) and off (Toners), in Chromium at 390 and 1280. Run against
 * tools/pg-preview.sh:
 *
 *     node tools/pg-shots.cjs http://127.0.0.1:10460 docs/pg-shots
 *
 * Every number printed is read from the page after it has rendered (counts of
 * elements, scrollWidth, the document's height), for the report; the shop's
 * own code measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10460';
const OUT = path.resolve(process.argv[3] || 'docs/pg-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };

function ctxOpts(w, js = true) {
    return w < 600
        ? { viewport: { width: w, height: 844 }, userAgent: UA_PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true, javaScriptEnabled: js }
        : { viewport: { width: w, height: 900 }, userAgent: UA, javaScriptEnabled: js };
}

async function listing(page) {
    return page.evaluate(() => {
        const grid = document.querySelector('#grid');
        const hrefs = new Set([...(grid ? grid.querySelectorAll('a[href*="/product/"]') : [])].map((a) => a.getAttribute('href')));
        const pager = document.querySelector('.kbb-pager');
        return {
            products: hrefs.size,
            pageLinks: document.querySelectorAll('.kbb-pager a.page-numbers').length,
            pager: pager ? (pager.dataset.load || 'arrows') : 'none',
            pagerShown: !!pager && getComputedStyle(pager).display !== 'none',
            relNext: !!document.querySelector('link[rel=next], a[rel=next]'),
            canonical: (document.querySelector('link[rel=canonical]') || {}).href || null,
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
            docH: document.documentElement.scrollHeight,
        };
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    // The machine's Chromium; the npm package may expect a newer build than is installed.
    const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

    // ── the storefront: on (Serums) and off (Toners), as a shopper sees it ──
    for (const w of [390, 1280]) {
        for (const [slug, state] of [['serums', 'on'], ['toners', 'off']]) {
            const ctx = await browser.newContext(ctxOpts(w));
            const page = await ctx.newPage();
            // What the server drew, before the scroll loader can add a batch...
            const res = await page.goto(`${BASE}/collections/${slug}/`, { waitUntil: 'domcontentloaded' });
            const first = await listing(page);
            // ...and the page as the shopper sees it once it has settled.
            await page.waitForLoadState('networkidle');
            const m = await listing(page);
            const file = `pg-category-${state}-${w}.png`;
            await page.screenshot({ path: path.join(OUT, file), fullPage: true });
            note(`${file}  HTTP ${res.status()}  /collections/${slug}/  products(server)=${first.products} products(settled)=${m.products} pageLinks=${m.pageLinks} pager=${m.pager} relNext=${m.relNext} scrollWidth=${m.scrollW} (viewport ${m.innerW}) docHeight=${m.docH} canonical=${m.canonical}`);
            await ctx.close();
        }

        // The same "on" category with JavaScript off: the numbered page links a
        // crawler (and a shopper without JS) gets underneath the scroll loader.
        const ctx = await browser.newContext(ctxOpts(w, false));
        const page = await ctx.newPage();
        await page.goto(`${BASE}/collections/serums/`, { waitUntil: 'load' });
        const m = await listing(page);
        const pager = await page.$('.kbb-pager');
        const file = `pg-category-on-links-${w}.png`;
        if (pager) {
            await pager.scrollIntoViewIfNeeded();
            await page.screenshot({ path: path.join(OUT, file) });
        }
        note(`${file}  (no JS)  products=${m.products} pageLinks=${m.pageLinks} pagerShown=${m.pagerShown} scrollWidth=${m.scrollW}`);

        // Old page-2 addresses.
        const r2 = await page.goto(`${BASE}/collections/toners/?paged=2`, { waitUntil: 'load' });
        note(`  /collections/toners/?paged=2 -> ${r2.url()} (redirect chain status ${r2.request().redirectedFrom() ? (await r2.request().redirectedFrom().response()).status() : 'none'})`);
        await ctx.close();
    }

    // ── the admin screen ──
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(ctxOpts(w));
        const page = await ctx.newPage();
        await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
        await page.fill('input[name=email]', 'owner@preview.test');
        await page.fill('input[name=password]', 'preview-secret-1');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
        await page.goto(`${BASE}/admin/?go=pagination`, { waitUntil: 'networkidle' });
        await page.waitForSelector('[data-pgx] .pgx-card h3', { timeout: 15000 });

        const m = await page.evaluate(() => ({
            crumb: (document.querySelector('#crumb') || {}).textContent,
            title: (document.querySelector('#ptitle') || {}).textContent,
            navRow: !!document.querySelector('#nav .nav-group[data-sec="Catalog"] [data-go="pagination"]'),
            navOrder: [...document.querySelectorAll('#nav .nav-group[data-sec="Catalog"] [data-go]')].map((b) => b.dataset.go).join(','),
            pageRows: document.querySelectorAll('[data-pgx] .pgx-rows:not([data-pgx-list]) .pgx-row').length,
            overrides: document.querySelectorAll('[data-pgx-list] .pgx-row').length,
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
        }));
        let file = `pg-admin-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file), fullPage: true });
        note(`${file}  crumb="${m.crumb} / ${m.title}" sidebarRow=${m.navRow} catalogOrder=[${m.navOrder}] listingPageRows=${m.pageRows} overrideRows=${m.overrides} scrollWidth=${m.scrollW} (viewport ${m.innerW})`);

        // The picker: typing filters the list the page already has.
        let requests = 0;
        page.on('request', (r) => { if (r.url().includes('/admin-api/')) requests++; });
        await page.fill('[data-pgx-find]', 'serum');
        const found = await page.$$eval('[data-pgx-list] .pgx-row', (rows) => rows.length);
        file = `pg-admin-search-${w}.png`;
        const card = await page.$('[data-pgx-list]');
        await card.scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(OUT, file), fullPage: true });
        note(`${file}  typed "serum": rows=${found} admin-api requests while typing=${requests}`);

        // Before/after of a control: Serums -> off, saved.
        if (w === 1280) {
            const sel = await page.$('[data-pgx-list] select[data-pgx-kind="category"]');
            await sel.selectOption('off');
            const badge = await page.$eval('[data-pgx-list] .pgx-row .pgx-now', (e) => e.textContent);
            await page.click('[data-pgx-save]');
            await page.waitForFunction(() => /Saved/.test((document.querySelector('.pgx-status') || {}).textContent || ''), null, { timeout: 15000 });
            await page.fill('[data-pgx-find]', '');
            file = 'pg-admin-saved-1280.png';
            await page.screenshot({ path: path.join(OUT, file), fullPage: true });
            const after = await page.$$eval('[data-pgx-list] .pgx-row', (rows) => rows.length);
            note(`${file}  Serums set to "Pagination off", badge now "${badge}", saved; overrides listed=${after}`);

            const sp = await ctx.newPage();
            await sp.goto(`${BASE}/collections/serums/`, { waitUntil: 'networkidle' });
            const s = await listing(sp);
            note(`  /collections/serums/ after save: products=${s.products} pageLinks=${s.pageLinks} pager=${s.pager}`);

            // Put it back so the preview stays as seeded.
            await page.fill('[data-pgx-find]', 'serum');
            await (await page.$('[data-pgx-list] select[data-pgx-kind="category"]')).selectOption('follow');
            await page.click('[data-pgx-save]');
            await page.waitForFunction(() => /Saved/.test((document.querySelector('.pgx-status') || {}).textContent || ''), null, { timeout: 15000 });
        }
        await ctx.close();
    }

    await browser.close();
    fs.writeFileSync(path.join(OUT, 'numbers.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
