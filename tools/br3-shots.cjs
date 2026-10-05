/*
 * Lane BR3 screenshots: the brand Panel header's box, position and type
 * controls, in Chromium at 390 and 1280. Run against tools/br3-preview.sh:
 *
 *     node tools/br3-shots.cjs http://127.0.0.1:10381 docs/br3-shots          # after
 *     node tools/br3-shots.cjs http://127.0.0.1:10391 docs/br3-shots before   # BR2's tree
 *
 * Every number printed is read from the page (computed styles and layout
 * boxes) for the report; the shop's own code measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10381';
const OUT = path.resolve(process.argv[3] || 'docs/br3-shots');
const MODE = process.argv[4] || 'after';
const PAGE = `${BASE}/brands/anua/`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const WIDTHS = [390, 1280];
const lines = [];

const ctxFor = (browser, w) => browser.newContext({
    viewport: { width: w, height: w < 600 ? 844 : 900 },
    userAgent: w < 600 ? UA_PHONE : UA,
    deviceScaleFactor: 1,
});

async function measure(page) {
    return page.evaluate(() => {
        const q = (s) => document.querySelector(s);
        const r = (e) => (e ? e.getBoundingClientRect() : null);
        const cs = (e, p) => (e ? getComputedStyle(e)[p] : null);
        const hdr = r(q('.brw-phw')); const ban = r(q('.brw-ph__media'));
        const panelEl = q('.brw-ph__panel'); const idEl = q('.brw-ph__id'); const descEl = q('.brw-ph__desc');
        const phone = cs(panelEl, 'display') === 'contents';
        const box = phone ? r(idEl) : r(panelEl);
        const logo = r(q('.brw-ph .brw-logo--lg')); const id = r(idEl); const desc = r(descEl);
        const n = Math.round;
        return {
            cls: (q('.brw-ph') || {}).className,
            hdr: `${n(hdr.width)}x${n(hdr.height)}`,
            banner: `${n(ban.width)}x${n(ban.height)}`,
            box: `${phone ? 'capsule' : 'panel'} ${n(box.width)}x${n(box.height)} at L${n(box.left - ban.left)} T${n(box.top - ban.top)} R${n(ban.right - box.right)} B${n(ban.bottom - box.bottom)}`,
            pad: phone ? cs(idEl, 'padding') : cs(panelEl, 'padding'),
            logo: `${n(logo.width)}x${n(logo.height)}`,
            name: cs(q('.brw-ph__name'), 'fontSize'),
            desc: cs(descEl, 'fontSize'),
            gap: phone ? `banner-to-card ${n(desc.top - ban.bottom)}` : `name-row-to-desc ${n(desc.top - id.bottom)}`,
            card: phone ? `card ${n(desc.width)}x${n(desc.height)} pad ${cs(descEl, 'padding')}` : '',
            sw: document.documentElement.scrollWidth,
        };
    });
}

function note(name, m) {
    const line = `${name} hdr ${m.hdr} banner ${m.banner} | ${m.box} pad ${m.pad} | logo ${m.logo} name ${m.name} desc ${m.desc} | ${m.gap} ${m.card} | sw ${m.sw}`;
    lines.push(line);
    console.log(line);
}

async function shootPage(browser, name) {
    for (const w of WIDTHS) {
        const ctx = await ctxFor(browser, w);
        const page = await ctx.newPage();
        await page.goto(PAGE, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
        note(`${name}-${w}`, await measure(page));
        await ctx.close();
    }
}

async function login(browser, w) {
    const ctx = await ctxFor(browser, w);
    const page = await ctx.newPage();
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    return { ctx, page };
}

async function openPopup(page) {
    await page.goto(PAGE, { waitUntil: 'networkidle' });
    await page.waitForSelector('.kbb-qe-pill', { timeout: 15000 });
    await page.click('.kbb-qe-pill');
    await page.waitForSelector('.kbb-qe__layout', { timeout: 15000 });
    await page.waitForSelector('.kbb-qe__pv-stage .brw-ph', { timeout: 15000 });
    await page.waitForTimeout(400);
}

const device = (page, d) => page.$eval(`input[name=kbb-qe-ly-device][value=${d}]`, (i) => { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); });
const pick = (page, key, v) => page.$eval(`input[name=kbb-qe-ly-${key}][value="${v}"]`, (i) => { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); });
const bar = (page, key, v) => page.$eval(`#kbb-qe-ly-${key}`, (i, val) => { i.value = String(val); i.dispatchEvent(new Event('input', { bubbles: true })); }, v);

/** The pop-up scrolled to its Header layout, on the given device's controls. */
async function shootControls(page, name, d) {
    await device(page, d);
    // The top of the Header layout -- the controls for both devices and the
    // Laptop / Phone switch -- just under the pinned preview, then its end.
    await page.$eval('.kbb-qe__layout', (e) => {
        const body = e.closest('.kbb-qe__body');
        const pin = body.querySelector('.kbb-qe__pv--pin');
        body.scrollTop += e.getBoundingClientRect().top - body.getBoundingClientRect().top - (pin ? pin.getBoundingClientRect().height : 0) - 60;
    });
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${name}-top.png` });
    await page.$eval('.kbb-qe__layout', (e) => e.scrollIntoView({ block: 'end' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: `${OUT}/${name}.png` });
}

/** What the previews say, read off their elements (classes and properties). */
const previewState = (page) => page.$$eval('.kbb-qe__pv-stage .brw-ph', (els) => els.map((e) => `${e.className} | ${e.getAttribute('style')}`));

async function applyState(page, name, d, picks, bars) {
    await page.click('.kbb-qe__layout button.kbb-qe__link');
    for (const [k, v] of picks) await pick(page, k, v);
    for (const [k, v] of bars) await bar(page, k, v);
    await shootControls(page, `popup-${name}`, d);
    lines.push(`popup-${name} preview before Save (no request): ${JSON.stringify(await previewState(page))}`);
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/quick-edit/brand/') && r.request().method() === 'POST' && !r.url().endsWith('/preview')),
        page.click('.kbb-qe__btn--go'),
    ]);
    await page.waitForTimeout(600);
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

    if (MODE === 'before') {
        await shootPage(browser, 'before-br2');
        fs.appendFileSync(`${OUT}/numbers.txt`, `${lines.join('\n')}\n`);
        await browser.close();
        return;
    }

    lines.push(`Lane BR3 -- measured in Chromium (Playwright) on ${PAGE.replace(BASE, '')}, tools/br3-preview.sh.`,
        'hdr = the whole header (.brw-phw); box = the laptop panel or the phone capsule, its distance from the banner\'s Left/Top/Right/Bottom; pad = its padding;',
        'gap = name row to description (laptop) or banner bottom to description card (phone); sw = documentElement.scrollWidth.', '');

    await shootPage(browser, 'default');

    // The pop-up at each width, its Laptop and its Phone controls.
    for (const w of WIDTHS) {
        const { ctx, page } = await login(browser, w);
        await openPopup(page);
        await shootControls(page, `popup-laptop-controls-${w}`, 'laptop');
        await shootControls(page, `popup-phone-controls-${w}`, 'phone');
        await ctx.close();
    }

    const { ctx, page } = await login(browser, 1280);

    await openPopup(page);
    await applyState(page, 'state1-laptop', 'laptop',
        [['panel_x', 'center'], ['panel_y', 'bottom'], ['logo', 'rect']],
        [['pad', 40], ['inset', 60], ['gap', 20], ['name', 44], ['desc', 18], ['logo_size', 96]]);
    await shootPage(browser, 'state1-laptop');

    await openPopup(page);
    await applyState(page, 'state2-phone', 'phone',
        [['pill_at', 'top-center']],
        [['height_m', 200], ['inset_m', 20], ['gap_m', 24], ['card_pad_m', 20], ['name_m', 26], ['desc_m', 16], ['logo_size_m', 64]]);
    await shootPage(browser, 'state2-phone');

    await openPopup(page);
    await applyState(page, 'state3-right-top', 'laptop',
        [['panel_x', 'right'], ['panel_y', 'top'], ['pill_at', 'bottom-right']],
        [['height', 340], ['content', 45], ['name', 28], ['pad', 18], ['inset', 24]]);
    await shootPage(browser, 'state3-right-top');

    // Back to the shop's values: the page is the default again.
    await openPopup(page);
    await page.click('.kbb-qe__layout button.kbb-qe__link');
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/quick-edit/brand/') && r.request().method() === 'POST' && !r.url().endsWith('/preview')),
        page.click('.kbb-qe__btn--go'),
    ]);
    await page.waitForTimeout(600);
    await shootPage(browser, 'reset');

    await ctx.close();
    await browser.close();
    fs.writeFileSync(`${OUT}/numbers.txt`, `${lines.join('\n')}\n`);
})().catch((e) => { console.error(e); process.exit(1); });
