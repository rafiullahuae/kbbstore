/*
 * Lane NF screenshots: the shop's 404 page in every design, English and
 * Arabic, at 390 and 1280; and Safety -> 404 page in the admin at 1280 and 390
 * with the Desktop / Mobile switch. Run against tools/nf-preview.sh (with
 * php tools/nf-wire.php applied for the admin half):
 *
 *     node tools/nf-shots.cjs http://127.0.0.1:10560 docs/nf-shots
 *
 * Every number printed is read from the page after it rendered — status,
 * scrollWidth, the headline's computed size, the illustration's bytes and the
 * layout-shift entries the browser recorded. The shop's own code measures
 * nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10560';
const OUT = path.resolve(process.argv[3] || 'docs/nf-shots');
const ONLY = process.argv[4] || '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };

function ctxOpts(w) {
    return w < 600
        ? { viewport: { width: w, height: 844 }, userAgent: UA_PHONE, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
        : { viewport: { width: w, height: 900 }, userAgent: UA };
}

async function login(browser, w) {
    const ctx = await browser.newContext(ctxOpts(w));
    const page = await ctx.newPage();
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    return { ctx, page };
}

async function saveConfig(page, patch) {
    return page.evaluate(async (patch) => {
        const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const h = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf };
        const cur = await (await fetch('/admin-api/not-found-page', { headers: h, credentials: 'same-origin' })).json();
        const cfg = Object.assign({}, cur.defaults, patch);
        const r = await fetch('/admin-api/not-found-page', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify({ config: cfg }) });
        return r.status;
    }, patch);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
    const admin = await login(browser, 1280);

    if (!ONLY || ONLY.includes('shop')) {
        for (const d of ['a', 'b', 'c', 'd']) {
            note(`design ${d}: save -> HTTP ${await saveConfig(admin.page, { design: d })}`);
            for (const lang of ['en', 'ar']) {
                for (const w of [390, 1280]) {
                    const ctx = await browser.newContext(ctxOpts(w));
                    const page = await ctx.newPage();
                    await page.addInitScript(() => {
                        window.__cls = 0;
                        new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; })
                            .observe({ type: 'layout-shift', buffered: true });
                    });
                    const url = `${BASE}${lang === 'ar' ? '/ar' : ''}/no-such-page-${d}/`;
                    const res = await page.goto(url, { waitUntil: 'networkidle' });
                    await page.waitForTimeout(2600);
                    // The shop's WhatsApp widget floats over every page and its
                    // greeting covered the headline at 390; hidden in the
                    // screenshot only, so the 404 page itself can be read.
                    await page.addStyleTag({ content: '#kbbWa{display:none!important}' });
                    const m = await page.evaluate(() => {
                        const h1 = document.querySelector('.nf-h'), art = document.querySelector('.nf-art');
                        const grid = document.querySelector('.nf-trend .kbb-pgrid');
                        return {
                            design: (document.querySelector('[data-nf]') || {}).dataset?.nf,
                            dir: document.documentElement.dir || 'ltr',
                            robots: (document.querySelector('meta[name=robots]') || {}).content,
                            sw: document.documentElement.scrollWidth, iw: innerWidth,
                            h1: h1 ? getComputedStyle(h1).fontSize : null,
                            artW: art ? Math.round(art.getBoundingClientRect().width) : 0,
                            artBytes: art ? art.outerHTML.length : 0,
                            cards: grid ? grid.querySelectorAll('.kbb-card, .kbb-tile, article').length : 0,
                            cols: grid ? getComputedStyle(grid).gridTemplateColumns.split(' ').length : 0,
                            header: !!document.querySelector('header'), footer: !!document.querySelector('footer'),
                            cls: Math.round(window.__cls * 10000) / 10000,
                        };
                    });
                    const file = `404-${d}-${lang}-${w}.jpg`;
                    await page.screenshot({ path: path.join(OUT, file), fullPage: true, type: 'jpeg', quality: 82 });
                    note(`${file}  HTTP ${res.status()} ${url.replace(BASE, '')} design=${m.design} dir=${m.dir} robots="${m.robots}" scrollWidth=${m.sw}/${m.iw} h1=${m.h1} art=${m.artW}px ${m.artBytes}B trend cols=${m.cols} header=${m.header} footer=${m.footer} CLS=${m.cls}`);
                    await ctx.close();
                }
            }
        }
        note(`back to defaults: HTTP ${await saveConfig(admin.page, {})}`);
    }

    if (!ONLY || ONLY.includes('admin')) {
        for (const w of [1280, 390]) {
            const { ctx, page } = w === 1280 ? admin : await login(browser, 390);
            let requests = 0;
            page.on('request', (r) => { if (r.url().includes('/admin-api/not-found-page')) requests++; });
            await page.goto(`${BASE}/admin/?go=notfoundpage`, { waitUntil: 'networkidle' });
            await page.waitForSelector('[data-nfx] .nfx-designs', { timeout: 15000 });
            await page.waitForTimeout(1500);
            const crumb = await page.evaluate(() => [(document.querySelector('#crumb') || {}).textContent, (document.querySelector('#ptitle') || {}).textContent, document.documentElement.scrollWidth]);
            await page.screenshot({ path: path.join(OUT, `admin-${w}-desktop.png`), fullPage: w === 390 });
            note(`admin-${w}-desktop.png  crumb=${crumb[0]} → ${crumb[1]} scrollWidth=${crumb[2]}/${w} GETs on open=${requests}`);
            requests = 0;
            await page.click('[data-nfx-dev="m"]');
            await page.waitForTimeout(1200);
            await page.screenshot({ path: path.join(OUT, `admin-${w}-mobile.png`), fullPage: w === 390 });
            const rows = await page.evaluate(() => [...document.querySelectorAll('.nfx-card h3')].map((h) => h.textContent).join(' | '));
            note(`admin-${w}-mobile.png  cards: ${rows}`);
            if (w === 1280) {
                // A change, shown before and after, and the requests it cost.
                await page.click('[data-nfx-dev="d"]');
                await page.waitForTimeout(800);
                await page.click('[data-nfx-design="a"]');
                await page.fill('[data-nfx-k="text.en.h"]', 'Oh honey, wrong aisle');
                await page.fill('[data-nfx-k="d.h1"]', '60').catch(() => {});
                await page.$eval('[data-nfx-k="d.h1"]', (el) => { el.value = 60; el.dispatchEvent(new Event('input', { bubbles: true })); });
                await page.waitForTimeout(1200);
                await page.screenshot({ path: path.join(OUT, 'admin-1280-edited.png') });
                const inFrame = await page.evaluate(() => {
                    const f = document.querySelector('[data-nfx-frame]');
                    const h = f && f.contentDocument && f.contentDocument.querySelector('.nf-h');
                    return h ? [h.textContent, getComputedStyle(h).fontSize, f.contentDocument.querySelector('[class^="nf nf-"]').className] : null;
                });
                note(`admin-1280-edited.png  preview h1="${inFrame && inFrame[0]}" size=${inFrame && inFrame[1]} class="${inFrame && inFrame[2]}"  admin-api requests while editing=${requests}`);
                await page.click('[data-nfx-lang="ar"]');
                await page.waitForTimeout(1200);
                await page.screenshot({ path: path.join(OUT, 'admin-1280-arabic.png') });
                await page.click('[data-nfx-reset]');
                await page.waitForTimeout(600);
                await page.screenshot({ path: path.join(OUT, 'admin-1280-reset.png') });
                note('admin-1280-arabic.png / admin-1280-reset.png  (reset is local until Save)');
            }
            if (w !== 1280) await ctx.close();
        }
    }

    fs.writeFileSync(path.join(OUT, 'numbers.txt'), lines.join('\n') + '\n');
    await browser.close();
})();
