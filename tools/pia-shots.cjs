/*
 * Imported descriptions and the search dropdown, photographed.     (Lane PI-A)
 *
 *   PIA_BASE=http://127.0.0.1:9400 PIA_OUT=storage/pia-logs/shots/before \
 *     NODE_PATH=/home/user/kbbstore/node_modules node tools/pia-shots.cjs
 *
 * Needs a preview from tools/bg-preview.sh with tools/pia-seed.php run into it.
 *
 * At 390 and 1280, in Chromium:
 *   search    the field focused, "Popular right now" open
 *   short     the blurb under the title, collapsed and after "Read more"
 *   desc      Product details -> Description, opened in full (the desktop tab's
 *             Read more is pressed; the phone accordion is open by default)
 *   dashes    the "- " line version of the same copy
 *
 * And the numbers, written to measure.json beside the pictures: <p>/<li>/<br>
 * counts inside the description, whether any of the seeded payloads ran
 * (window.__piaXss stays undefined), scrollWidth against the viewport, the
 * price text the dropdown shows, and the blurb's collapsed and opened heights.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PIA_BASE || 'http://127.0.0.1:9400';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PIA_OUT || 'storage/pia-logs/shots/run');
const WIDTHS = [[390, 844], [1280, 900]];

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const measure = {};

    for (const [w, h] of WIDTHS) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const dialogs = [];
        page.on('dialog', (d) => { dialogs.push(d.message()); d.dismiss(); });
        const m = (measure[w] = {});

        // ── the product page ──────────────────────────────────────────────
        await page.goto(`${BASE}/product/pia-pdrn-glow-booster-set/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);

        const blurb = page.locator('.bb-desc').first();
        await blurb.scrollIntoViewIfNeeded();
        m.blurbTag = await blurb.evaluate((el) => el.tagName);
        m.blurbText = (await blurb.innerText()).slice(0, 160);
        m.blurbHasLiteralDiv = m.blurbText.includes('<div>');
        m.blurbCollapsedHeight = await blurb.evaluate((el) => Math.round(el.getBoundingClientRect().height));
        await page.screenshot({ path: `${OUT}/short-${w}.png`, fullPage: true, clip: await clipAround(page, '.bb-desc', 120, 80) });
        await page.locator('label.bb-more').first().click();
        await page.waitForTimeout(150);
        m.blurbOpenHeight = await blurb.evaluate((el) => Math.round(el.getBoundingClientRect().height));
        await page.screenshot({ path: `${OUT}/short-open-${w}.png`, fullPage: true, clip: await clipAround(page, '.bb-desc', 120, 80) });

        // Whichever presentation this width draws: the tab panel (both widths
        // today) or the accordion. The first VISIBLE description wins.
        const scope = '.details .dcontent:visible';
        const more = page.locator('.dpanel .dtabpanel.on .readmore');
        if (await more.isVisible()) await more.click();
        await page.waitForTimeout(200);
        const desc = page.locator(scope).first();
        await desc.scrollIntoViewIfNeeded();
        Object.assign(m, await desc.evaluate((el) => ({
            descP: el.querySelectorAll('p').length,
            descLi: el.querySelectorAll('li').length,
            descBr: el.querySelectorAll('br').length,
            descH: el.querySelectorAll('h2,h3,h4,h5,h6').length,
            descScripts: el.querySelectorAll('script').length,
            descOnAttrs: [...el.querySelectorAll('*')].filter((n) => [...n.attributes].some((a) => /^on/i.test(a.name))).length,
            descJsHrefs: [...el.querySelectorAll('a[href]')].filter((a) => /^\s*javascript:/i.test(a.getAttribute('href'))).length,
            descHeight: Math.round(el.getBoundingClientRect().height),
            descFirstParagraphGap: (() => {
                const ps = el.querySelectorAll('p');
                if (ps.length < 2) return null;
                return Math.round(ps[1].getBoundingClientRect().top - ps[0].getBoundingClientRect().bottom);
            })(),
        })));
        await page.screenshot({ path: `${OUT}/desc-${w}.png`, fullPage: true, clip: await clipAround(page, scope, 60, 40) });

        m.piaXss = await page.evaluate(() => window.__piaXss ?? null);
        m.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        m.viewport = w;

        // JSON-LD: the Product node's description must be text.
        m.jsonLdDescription = await page.evaluate(() => {
            for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
                try {
                    const j = JSON.parse(s.textContent);
                    const nodes = Array.isArray(j['@graph']) ? j['@graph'] : [j];
                    for (const n of nodes) if (n['@type'] === 'Product') return n.description ?? null;
                } catch (e) { /* not ours */ }
            }
            return null;
        });

        // ── the "- " lines product ─────────────────────────────────────────
        await page.goto(`${BASE}/product/pia-pdrn-dashes/`, { waitUntil: 'networkidle' });
        const more2 = page.locator('.dpanel .dtabpanel.on .readmore');
        if (await more2.isVisible()) await more2.click();
        await page.waitForTimeout(200);
        const dd = page.locator(scope).first();
        await dd.scrollIntoViewIfNeeded();
        Object.assign(m, await dd.evaluate((el) => ({
            dashP: el.querySelectorAll('p').length,
            dashBr: el.querySelectorAll('br').length,
        })));
        await page.screenshot({ path: `${OUT}/dashes-${w}.png`, fullPage: true, clip: await clipAround(page, scope, 60, 40) });
        m.dashScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);

        // An "&" in an imported excerpt: the blurb, the meta tag, the JSON-LD.
        m.dashBlurbText = await page.locator('.bb-desc').first().innerText();
        m.dashBlurbBr = await page.locator('.bb-desc br').count();
        m.dashMetaDescription = await page.locator('meta[name="description"]').getAttribute('content');
        m.dashJsonLdDescription = await page.evaluate(() => {
            for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
                try {
                    const j = JSON.parse(s.textContent);
                    if (j['@type'] === 'Product') return j.description ?? null;
                } catch (e) { /* not ours */ }
            }
            return null;
        });
        await page.screenshot({ path: `${OUT}/dashes-short-${w}.png`, fullPage: true, clip: await clipAround(page, '.bb-desc', 120, 60) });

        // Quick view: the same row through /quick-view/{id}, read as the modal reads it.
        m.quickViewBlurb = await page.evaluate(async (base) => {
            const id = document.querySelector('form.kbb-cart-form')?.dataset.product_id;
            const r = await fetch(`${base}/quick-view/${id}`, { headers: { Accept: 'application/json' } });
            const j = await r.json();
            const d = document.createElement('div');
            d.innerHTML = j.html || '';
            return d.querySelector('.qv-blurb')?.textContent ?? null;
        }, BASE);

        // ── a row whose columns never went through the sanitiser ──────────
        await page.goto(`${BASE}/product/pia-raw-unsanitised/`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(300);
        const tab = page.locator('.details .dcontent:visible').first();
        await tab.scrollIntoViewIfNeeded().catch(() => {});
        await tab.hover().catch(() => {});
        await page.locator('.details .dcontent b').first().hover().catch(() => {});
        await page.waitForTimeout(200);
        Object.assign(m, await page.evaluate(() => {
            const all = [...document.querySelectorAll('.details .dcontent, .bb-desc')];
            return {
                rawXss: window.__piaXss ?? null,
                rawScripts: all.reduce((n, el) => n + el.querySelectorAll('script').length, 0),
                rawOnAttrs: all.reduce((n, el) => n + [...el.querySelectorAll('*')].filter((x) => [...x.attributes].some((a) => /^on/i.test(a.name))).length, 0),
                rawStyleAttrs: all.reduce((n, el) => n + el.querySelectorAll('[style]').length, 0),
                rawJsHrefs: all.reduce((n, el) => n + [...el.querySelectorAll('a[href]')].filter((a) => /^\s*javascript:/i.test(a.getAttribute('href'))).length, 0),
            };
        }));
        await page.screenshot({ path: `${OUT}/raw-row-${w}.png` });

        // ── the search dropdown ───────────────────────────────────────────
        await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
        const field = page.locator('.search-in input[type="search"]').first();
        if (!(await field.isVisible())) {
            const opener = page.locator('[data-search-open], .search-toggle, .hd-search, button[aria-label*="earch"]').first();
            if (await opener.count()) await opener.click();
        }
        await field.focus();
        await page.waitForSelector('#kbbSuggest.on', { timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(400);
        m.popularPrices = await page.$$eval('#kbbSuggest .sgi small', (els) => els.map((e) => e.textContent));
        m.popularPriceHasMarkup = m.popularPrices.some((t) => t.includes('<span'));
        m.searchScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
        await page.screenshot({ path: `${OUT}/search-${w}.png` });

        // And typed: the suggestion rows, which carry a price of their own.
        await field.fill('medicube');
        await page.waitForTimeout(900);
        m.suggestPrices = await page.$$eval('#kbbSuggest .sp', (els) => els.map((e) => e.textContent));
        m.suggestPriceHasMarkup = m.suggestPrices.some((t) => t.includes('<'));
        await page.screenshot({ path: `${OUT}/search-typed-${w}.png` });
        m.dialogs = dialogs;

        await ctx.close();
    }

    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(measure, null, 2));
    console.log(JSON.stringify(measure, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });

/* A clip box around an element, in page coordinates, clamped to the page. */
async function clipAround(page, selector, padTop, padBottom) {
    const box = await page.locator(selector).first().evaluate((el) => {
        const r = el.getBoundingClientRect();
        return { x: 0, y: r.top + window.scrollY, h: r.height, w: document.documentElement.clientWidth };
    });
    const y = Math.max(0, box.y - padTop);
    return { x: 0, y, width: box.w, height: Math.min(1600, box.h + padTop + padBottom) };
}
