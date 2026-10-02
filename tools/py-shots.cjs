/*
 * Lane PY evidence: the category title header on every category, and the
 * lettered option sheet the owner answers ("B + 3").
 *
 *   sh tools/py-preview.sh 9700                                  (AFTER, this tree)
 *   PY_APP=<base tree> sh tools/py-preview.sh 9710 before         (BEFORE, 2.60.347's tree)
 *   PY_BASE=http://127.0.0.1:9700 PY_BEFORE=http://127.0.0.1:9710 node tools/py-shots.cjs
 *
 * Every option is a REAL STOREFRONT RENDER: tools/py-seed.php makes one
 * category per option with that option as the category's own override, and
 * this photographs the header element of each, at 390 and 1280.
 *
 * Measurements are taken by THIS harness in the browser -- getBoundingClientRect
 * and getComputedStyle are the instrument here, never shipped to a shopper.
 * Contrast is the title's colour against the pixels actually behind it, read
 * from a screenshot of the header with the title's glyphs (and shadows) made
 * transparent: so the darkening, the fade, the frosted panel and the label all
 * count, and a text-shadow does not.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PY_BASE || 'http://127.0.0.1:9700';
const BEFORE = process.env.PY_BEFORE || '';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PY_OUT || 'docs/py-options');
const WIDTHS = [390, 1280];

const BOXES = { a: 'Blush icons', b: 'Cream icons', c: 'Mint icons', d: 'Lilac icons', e: 'Plain soft colour', f: 'My own colours' };
const TREATMENTS = { 1: 'Soft shadow', 2: 'Dark fade on the text side', 3: 'Frosted panel', 4: 'Solid label', 5: 'None' };
const GROUNDS = { dark: 'busy, dark picture', light: 'light picture', box: 'light box' };

const ctxFor = (browser, width) => browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    deviceScaleFactor: 1,
});

/* Luminance and contrast, WCAG 2.x. */
const lum = ([r, g, b]) => {
    const c = [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };

/* Decode a PNG in a blank page, and return the pixels inside one rectangle. */
async function pixels(decoder, png, rect) {
    return decoder.evaluate(async ({ b64, rect }) => {
        const img = new Image();
        img.src = 'data:image/png;base64,' + b64;
        await img.decode();
        const c = document.createElement('canvas');
        c.width = img.naturalWidth; c.height = img.naturalHeight;
        const g = c.getContext('2d');
        g.drawImage(img, 0, 0);
        const x = Math.max(0, Math.floor(rect.x)), y = Math.max(0, Math.floor(rect.y));
        const w = Math.max(1, Math.min(c.width - x, Math.floor(rect.w))), h = Math.max(1, Math.min(c.height - y, Math.floor(rect.h)));
        const d = g.getImageData(x, y, w, h).data;
        const out = [];
        for (let i = 0; i < d.length; i += 4 * 7) out.push([d[i], d[i + 1], d[i + 2]]);
        return out;
    }, { b64: png.toString('base64'), rect });
}

async function measureHeader(page, decoder) {
    const th = page.locator('[data-kbb-title-header]').first();
    if (!(await th.count())) return null;

    const m = await page.evaluate(() => {
        const s = document.querySelector('[data-kbb-title-header]');
        const t = s.querySelector('.kbb-th__title');
        const d = s.querySelector('.kbb-th__desc');
        const sr = s.getBoundingClientRect();
        const tr = t.getBoundingClientRect();
        const cs = getComputedStyle(t);
        return {
            classes: s.className,
            header: { w: Math.round(sr.width), h: Math.round(sr.height) },
            title: {
                text: t.innerText, fontSize: cs.fontSize, fontWeight: cs.fontWeight, color: cs.color,
                textAlign: cs.textAlign, left: Math.round(tr.left - sr.left), right: Math.round(sr.right - tr.right),
            },
            description: d ? { fontSize: getComputedStyle(d).fontSize } : null,
            titleRect: { x: tr.left - sr.left, y: tr.top - sr.top, w: tr.width, h: tr.height },
            radius: getComputedStyle(s).borderTopLeftRadius,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            viewport: window.innerWidth,
        };
    });

    // The pixels behind the title, with the title's ink (and its shadow) gone.
    await page.addStyleTag({ content: '.kbb-th__title{color:transparent!important;text-shadow:none!important} .kbb-th__title *{color:transparent!important}', }).then((h) => h.evaluate((n) => n.setAttribute('data-py-probe', '1')));
    const bg = await th.screenshot();
    await page.evaluate(() => document.querySelectorAll('[data-py-probe]').forEach((n) => n.remove()));

    const ink = m.title.color.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
    const px = await pixels(decoder, bg, m.titleRect);
    const ratios = px.map((p) => contrast(ink, p)).sort((a, b) => a - b);
    m.contrast = {
        worst: +ratios[0].toFixed(2),
        median: +ratios[Math.floor(ratios.length / 2)].toFixed(2),
        note: 'title colour against the pixels behind the title; text-shadow not counted',
    };
    delete m.titleRect;
    return m;
}

async function shotHeader(browser, decoder, base, url, file, width, result) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    const res = await page.goto(base + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(150);
    const th = page.locator('[data-kbb-title-header]').first();
    if (await th.count()) {
        await th.screenshot({ path: `${OUT}/${file}.png` });
        result[file] = await measureHeader(page, decoder);
    } else {
        result[file] = { status: res.status(), header: null };
    }
    await ctx.close();
}

async function shotPage(browser, decoder, base, url, file, width, result) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    await page.goto(base + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);
    await page.screenshot({ path: `${OUT}/${file}.png` });
    const header = await measureHeader(page, decoder);
    const plain = await page.evaluate(() => {
        const h = document.querySelector('h1.ptitle');
        const r = h ? h.getBoundingClientRect() : null;
        const grid = document.querySelector('.kbb-pgrid, .pgrid, .grid');
        return {
            h1Count: document.querySelectorAll('h1').length,
            plainTitle: h ? { text: h.innerText, fontSize: getComputedStyle(h).fontSize, top: Math.round(r.top), height: Math.round(r.height) } : null,
            firstProductTop: grid ? Math.round(grid.getBoundingClientRect().top) : null,
            scrollWidth: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
            dir: document.documentElement.getAttribute('dir'),
        };
    });
    result[file] = { ...plain, header };
    await ctx.close();
}

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
}

const adminWidths = () => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    contentScrollWidth: (document.querySelector('#content') || {}).scrollWidth || null,
    contentClientWidth: (document.querySelector('#content') || {}).clientWidth || null,
});

async function adminLayout(browser, width, result) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    await signIn(page);
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForTimeout(1500);

    for (const tab of ['catheader', 'catheadersize']) {
        await page.locator(`[data-sls-tab="${tab}"]`).click();
        await page.waitForTimeout(500);
        await page.screenshot({ path: `${OUT}/admin-${tab}-${width}.png`, fullPage: true });
        result[`admin-${tab}-${width}`] = await page.evaluate((w) => ({
            crumb: (document.querySelector('#crumb') || {}).innerText || null,
            title: (document.querySelector('#ptitle') || {}).innerText || null,
            tabs: [...document.querySelectorAll('[data-sls-tab]')].map((t) => t.innerText),
            fields: [...document.querySelectorAll('.sls-fields label')].map((l) => l.innerText),
            previews: document.querySelectorAll('.sls-pv-list .kbb-th').length,
            ...w,
        }), null).then(async (o) => ({ ...o, ...(await page.evaluate(adminWidths)) }));
    }

    // BEFORE AND AFTER A CONTROL MOVES: Centred + Frosted panel + Mint, unsaved.
    await page.locator('[data-sls-tab="catheader"]').click();
    await page.waitForTimeout(300);
    await page.selectOption('#sls-cat_header_align', 'center');
    await page.selectOption('#sls-cat_header_treatment', 'frost');
    await page.selectOption('#sls-cat_header_box_style', 'mint');
    await page.waitForTimeout(400);
    const card = page.locator('.sls-wrap > .sls-card').last();
    await card.screenshot({ path: `${OUT}/admin-preview-moved-${width}.png` });
    await page.locator('[data-sls-reload]').first().click();
    await page.waitForTimeout(1200);
    await page.locator('[data-sls-tab="catheader"]').click();
    await page.waitForTimeout(300);
    await page.locator('.sls-wrap > .sls-card').last().screenshot({ path: `${OUT}/admin-preview-default-${width}.png` });
    await ctx.close();
}

async function adminEditor(browser, width, result) {
    // Tall, so the whole panel fits inside the dialog's own scroller for its picture.
    const ctx = await browser.newContext({ viewport: { width, height: 2400 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await signIn(page);
    await page.evaluate(() => window.go('category-tree'));
    await page.waitForTimeout(1800);
    await page.screenshot({ path: `${OUT}/admin-categories-list-${width}.png` });
    const id = await page.evaluate(() => {
        const row = [...document.querySelectorAll('.ct-row')].find((r) => (r.querySelector('.ct-path') || {}).innerText === '/collections/skincare/sunscreens/');
        return row ? row.dataset.id : null;
    });
    await page.locator(`[data-edit="${id}"]`).click();
    await page.waitForTimeout(600);
    await page.evaluate(() => document.querySelector('#ct-hdr').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/admin-category-editor-${width}.png` });
    await page.locator('#ct-hdr').screenshot({ path: `${OUT}/admin-category-editor-panel-${width}.png` });
    result[`admin-category-editor-${width}`] = await page.evaluate(() => ({
        open: document.querySelector('#ct-hdr').open,
        title: document.querySelector('#ct-hdrtitle').value,
        importedNote: (document.querySelector('[data-hdr-imported]') || {}).innerText || null,
        picture: document.querySelector('#ct-hdrimg').value,
        selects: [...document.querySelectorAll('#ct-hdr select')].map((s) => s.id + '=' + (s.value || '(shop)')),
        statCount: (document.querySelector('[data-hdr-count] b') || {}).innerText || null,
        modalScrollWidth: document.querySelector('.ct-modal-box').scrollWidth,
        modalClientWidth: document.querySelector('.ct-modal-box').clientWidth,
    })).then(async (o) => ({ ...o, ...(await page.evaluate(adminWidths)) }));
    await ctx.close();
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const decoderCtx = await browser.newContext();
    const decoder = await decoderCtx.newPage();
    await decoder.goto('about:blank');
    const result = {};

    for (const w of WIDTHS) {
        for (const l of Object.keys(BOXES)) await shotHeader(browser, decoder, BASE, `/collections/py-box-${l}/`, `box-${l}-${w}`, w, result);
        for (const n of Object.keys(TREATMENTS)) {
            for (const g of Object.keys(GROUNDS)) await shotHeader(browser, decoder, BASE, `/collections/py-t${n}-${g}/`, `t${n}-${g}-${w}`, w, result);
        }
        for (const a of ['start', 'center', 'end']) {
            for (const g of ['dark', 'box']) await shotHeader(browser, decoder, BASE, `/collections/py-align-${a}-${g}/`, `align-${a}-${g}-${w}`, w, result);
            await shotHeader(browser, decoder, BASE, `/ar/collections/py-align-${a}-box/`, `align-${a}-box-ar-${w}`, w, result);
        }

        // The defaults, as shipped, English and Arabic.
        await shotPage(browser, decoder, BASE, '/collections/skincare/sunscreens/', `after-picture-en-${w}`, w, result);
        await shotPage(browser, decoder, BASE, '/collections/lip-care/', `after-box-en-${w}`, w, result);
        await shotPage(browser, decoder, BASE, '/ar/collections/skincare/sunscreens/', `after-picture-ar-${w}`, w, result);
        await shotPage(browser, decoder, BASE, '/ar/collections/lip-care/', `after-box-ar-${w}`, w, result);
        await shotPage(browser, decoder, BASE, '/collections/face-masks/', `after-box-nodesc-en-${w}`, w, result);

        // And what does NOT move: /shop/ and the brand filter.
        await shotPage(browser, decoder, BASE, '/shop/', `after-shop-${w}`, w, result);

        if (BEFORE) {
            await shotPage(browser, decoder, BEFORE, '/collections/skincare/sunscreens/', `before-picture-en-${w}`, w, result);
            await shotPage(browser, decoder, BEFORE, '/collections/lip-care/', `before-box-en-${w}`, w, result);
            await shotPage(browser, decoder, BEFORE, '/ar/collections/lip-care/', `before-box-ar-${w}`, w, result);
            await shotPage(browser, decoder, BEFORE, '/shop/', `before-shop-${w}`, w, result);
        }

        await adminLayout(browser, w, result);
        await adminEditor(browser, w, result);
    }

    await browser.close();
    fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(result, null, 2) + '\n');
    console.log('wrote', Object.keys(result).length, 'measurements to', OUT);
})();
