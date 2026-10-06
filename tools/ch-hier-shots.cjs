/*
 * Lane CH (hierarchy) screenshots, in Chromium at 1280 and 390, against
 * tools/ch-hier-preview.sh:
 *
 *     node tools/ch-hier-shots.cjs http://127.0.0.1:10620 docs/ch-hier-shots
 *
 *   1. a category page breadcrumb on the FLAT shop (before)
 *   2. Catalog -> Categories -> Copy hierarchy -> the dry run
 *   3. after Apply: the summary, the Categories list, and the category page
 *      breadcrumb -- at the SAME address as before
 *
 * Every number printed is read from the rendered page, for the report.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10620';
const OUT = path.resolve(process.argv[3] || 'docs/ch-hier-shots');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };

function ctxOpts(w) {
    return w < 600
        ? { viewport: { width: w, height: 844 }, userAgent: UA_PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
        : { viewport: { width: w, height: 900 }, userAgent: UA };
}

async function crumb(page, url) {
    const res = await page.goto(url, { waitUntil: 'networkidle' });
    return page.evaluate((status) => {
        const c = document.querySelector('.crumb');
        const ld = [...document.querySelectorAll('script[type="application/ld+json"]')]
            .map((s) => { try { return JSON.parse(s.textContent); } catch (e) { return null; } })
            .flat().filter((n) => n && n['@type'] === 'BreadcrumbList')[0];
        return {
            status,
            url: location.pathname,
            crumb: c ? c.textContent.replace(/\s+/g, ' ').trim() : null,
            crumbLinks: c ? c.querySelectorAll('a').length : 0,
            crumbFont: c ? getComputedStyle(c).fontSize : null,
            ld: ld ? ld.itemListElement.map((i) => i.name).join(' > ') : null,
            canonical: (document.querySelector('link[rel=canonical]') || {}).href || null,
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
        };
    }, res.status());
}

async function login(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

    // 1. before: the flat shop's category page.
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(ctxOpts(w));
        const page = await ctx.newPage();
        const m = await crumb(page, `${BASE}/collections/oil-cleansers/`);
        const file = `ch-before-category-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file) });
        note(`${file}  ${m.status} ${m.url} crumb="${m.crumb}" links=${m.crumbLinks} jsonld="${m.ld}" scrollWidth=${m.scrollW} (viewport ${m.innerW})`);
        await ctx.close();
    }

    // 2. the dry run, at both widths (nothing is written by it) -- the phone
    // first, so both show the flat shop.
    for (const w of [390, 1280]) {
        const ctx = await browser.newContext(ctxOpts(w));
        const page = await ctx.newPage();
        await login(page);
        await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });
        await page.evaluate(() => window.go('category-tree'));
        await page.waitForSelector('#ct-hier', { timeout: 15000 });
        let file = `ch-categories-button-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file) });
        note(`${file}  button="${await page.$eval('#ct-hier', (b) => b.textContent)}"`);

        await page.click('#ct-hier');
        await page.waitForSelector('#chs-fetch');
        await page.click('#chs-fetch');
        await page.waitForSelector('.chs-tree', { timeout: 20000 });
        const m = await page.evaluate(() => ({
            counts: [...document.querySelectorAll('.chs-count')].map((c) => c.querySelector('b').textContent + ' ' + c.querySelector('span').textContent).join(' | '),
            rows: document.querySelectorAll('.chs-row').length,
            apply: (document.querySelector('#chs-apply') || {}).textContent,
            box: Math.round(document.querySelector('.chs-box').getBoundingClientRect().width),
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
        }));
        file = `ch-dry-run-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file) });
        note(`${file}  counts: ${m.counts}; tree rows=${m.rows}; button="${m.apply}"; dialog width=${m.box}px; scrollWidth=${m.scrollW} (viewport ${m.innerW})`);

        // 3. apply (at 1280 only -- it is a write), then the list.
        if (w === 1280) {
            await page.click('#chs-apply');
            await page.waitForSelector('.chs-ok', { timeout: 20000 });
            file = 'ch-applied-1280.png';
            await page.screenshot({ path: path.join(OUT, file) });
            note(`${file}  "${await page.$eval('.chs-ok', (e) => e.textContent)}"`);
            await page.click('[data-chs-close]');
        }
        await ctx.close();
    }

    // The Categories list after Apply, indented by depth.
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(ctxOpts(w));
        const page = await ctx.newPage();
        await login(page);
        await page.goto(`${BASE}/admin/`, { waitUntil: 'networkidle' });
        await page.evaluate(() => window.go('category-tree'));
        await page.waitForSelector('#ct-root .ct-row', { timeout: 15000 });
        const m = await page.evaluate(() => ({
            rows: [...document.querySelectorAll('#ct-root .ct-row[data-id]')].map((r) => {
                const main = r.querySelector('.ct-main');
                return (parseInt(main.style.paddingLeft, 10) / 16) + ':' + (r.querySelector('.ct-path') || {}).textContent;
            }).join(' '),
            scrollW: document.documentElement.scrollWidth,
            innerW: innerWidth,
        }));
        const file = `ch-after-list-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file), fullPage: true });
        note(`${file}  depth:address ${m.rows}; scrollWidth=${m.scrollW} (viewport ${m.innerW})`);
        await ctx.close();
    }

    // After: the same address, now with its parents.
    for (const w of [1280, 390]) {
        const ctx = await browser.newContext(ctxOpts(w));
        const page = await ctx.newPage();
        const m = await crumb(page, `${BASE}/collections/oil-cleansers/`);
        let file = `ch-after-category-${w}.png`;
        await page.screenshot({ path: path.join(OUT, file) });
        note(`${file}  ${m.status} ${m.url} crumb="${m.crumb}" links=${m.crumbLinks} font=${m.crumbFont} jsonld="${m.ld}" canonical=${m.canonical} scrollWidth=${m.scrollW} (viewport ${m.innerW})`);
        const r = await page.goto(`${BASE}/collections/skincare/cleansers/oil-cleansers/`, { waitUntil: 'networkidle' });
        const first = r.request().redirectedFrom();
        note(`  /collections/skincare/cleansers/oil-cleansers/ -> ${new URL(page.url()).pathname} (${first ? (await first.response()).status() : 'no redirect'})`);
        await ctx.close();
    }

    fs.writeFileSync(path.join(OUT, 'numbers.txt'), lines.join('\n') + '\n');
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
