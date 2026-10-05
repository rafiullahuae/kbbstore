/*
 * Lane BR4 screenshots: the Panel header on EVERY brand page -- a brand with
 * the owner's page banner (anua, like the live one) and one without
 * (plainbr4) -- in Chromium at 390 and 1280. Run against tools/br4-preview.sh:
 *
 *     node tools/br4-shots.cjs http://127.0.0.1:10481 docs/br4-shots before   # HEAD, before the change
 *     node tools/br4-shots.cjs http://127.0.0.1:10481 docs/br4-shots          # after
 *
 * Every number is read from the page by this script (boxes and computed
 * styles); the shop's own code measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10481';
const OUT = path.resolve(process.argv[3] || 'docs/br4-shots');
const MODE = process.argv[4] || 'after';
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
        const n = Math.round;
        const out = {
            h1: document.querySelectorAll('h1').length,
            h1text: [...document.querySelectorAll('h1')].map((e) => e.textContent.trim()).join(' / '),
            banner: document.querySelectorAll('.kbb-banner').length,
            th: document.querySelectorAll('[data-kbb-title-header]').length,
            sw: document.documentElement.scrollWidth,
        };
        const ph = q('.brw-ph');
        if (!ph) {
            const hero = q('.brw-hero');
            out.hero = hero ? `old row ${hero.className} ${n(r(hero).width)}x${n(r(hero).height)}` : 'none';
            const b = q('.kbb-banner');
            out.ban = b ? `${n(r(b).width)}x${n(r(b).height)}` : '';
            return out;
        }
        const panelEl = q('.brw-ph__panel'); const idEl = q('.brw-ph__id'); const descEl = q('.brw-ph__desc');
        const phone = cs(panelEl, 'display') === 'contents';
        const ban = r(q('.brw-ph__media'));
        const box = phone ? r(idEl) : r(panelEl);
        const logo = q('.brw-ph .brw-logo--lg');
        const more = q('.brw-ph__more');
        const clamp = q('.brw-ph__clamp');
        const img = q('.brw-ph__img');
        out.cls = ph.className;
        out.hdr = `${n(r(q('.brw-phw')).width)}x${n(r(q('.brw-phw')).height)}`;
        out.box = `${phone ? 'capsule' : 'panel'} ${n(box.width)}x${n(box.height)} at L${n(box.left - ban.left)} T${n(box.top - ban.top)} R${n(ban.right - box.right)} B${n(ban.bottom - box.bottom)}`;
        out.logo = logo && cs(logo, 'display') !== 'none' ? `${n(r(logo).width)}x${n(r(logo).height)}` : 'off';
        out.img = img ? img.getAttribute('src') : 'none';
        out.name = `${cs(q('.brw-ph__name'), 'fontSize')} align ${cs(q('.brw-ph__name'), 'textAlign')} name-centre-off ${n((r(q('.brw-ph__name')).left + r(q('.brw-ph__name')).width / 2) - (box.left + box.width / 2))}`;
        out.desc = descEl ? `${cs(descEl, 'fontSize')} align ${cs(descEl, 'textAlign')} card h${n(r(descEl).height)} text h${n(r(clamp || descEl).height)} lh ${cs(descEl, 'lineHeight')}` : 'none';
        out.more = more && cs(more, 'display') !== 'none' ? `"${more.innerText.trim()}" aria-expanded=${more.getAttribute('aria-expanded')}` : 'none';
        return out;
    });
}

function note(name, m) {
    const line = `${name} | h1 ${m.h1} (${m.h1text}) | .kbb-banner ${m.banner} title-header ${m.th} | `
        + (m.cls ? `hdr ${m.hdr} ${m.box} | logo ${m.logo} | name ${m.name} | desc ${m.desc} | toggle ${m.more} | img ${m.img}` : `${m.hero} banner ${m.ban}`)
        + ` | sw ${m.sw}`;
    lines.push(line);
    console.log(line);
}

async function shoot(browser, slug, name, after) {
    for (const w of WIDTHS) {
        const ctx = await ctxFor(browser, w);
        const page = await ctx.newPage();
        await page.goto(`${BASE}/brands/${slug}/`, { waitUntil: 'networkidle' });
        if (after) await after(page);
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

async function openPopup(page, slug) {
    await page.goto(`${BASE}/brands/${slug}/`, { waitUntil: 'networkidle' });
    await page.waitForSelector('.kbb-qe-pill', { timeout: 15000 });
    await page.click('.kbb-qe-pill');
    await page.waitForSelector('.kbb-qe__layout', { timeout: 15000 });
    await page.waitForSelector('.kbb-qe__pv-stage .brw-ph', { timeout: 15000 });
    await page.waitForTimeout(400);
}

const device = (page, d) => page.$eval(`input[name=kbb-qe-ly-device][value=${d}]`, (i) => { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); });
const pick = (page, key, v) => page.$eval(`input[name=kbb-qe-ly-${key}][value="${v}"]`, (i) => { i.checked = true; i.dispatchEvent(new Event('change', { bubbles: true })); });

async function save(page) {
    await Promise.all([
        page.waitForResponse((r) => r.url().includes('/quick-edit/brand/') && r.request().method() === 'POST' && !r.url().endsWith('/preview')),
        page.click('.kbb-qe__btn--go'),
    ]);
    await page.waitForTimeout(600);
}

/** The pop-up scrolled to the top of its Header layout, under the pinned preview. */
async function shootControls(page, name) {
    await page.$eval('.kbb-qe__dev', (e) => {
        const body = e.closest('.kbb-qe__body');
        const pin = body.querySelector('.kbb-qe__pv--pin');
        body.scrollTop += e.getBoundingClientRect().top - body.getBoundingClientRect().top - (pin ? pin.getBoundingClientRect().height : 0) - 40;
    });
    await page.waitForTimeout(250);
    await page.screenshot({ path: `${OUT}/${name}.png` });
}

const previewState = (page) => page.$$eval('.kbb-qe__pv-stage .brw-ph', (els) => els.map((e) => e.className));

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

    if (MODE === 'before') {
        lines.push('BEFORE -- this branch\'s HEAD (461ad1ce), same seed:');
        await shoot(browser, 'anua', 'before-anua-banner');
        await shoot(browser, 'plainbr4', 'before-plain');
        fs.writeFileSync(`${OUT}/numbers-before.txt`, `${lines.join('\n')}\n`);
        await browser.close();
        return;
    }

    lines.push('Lane BR4 -- measured in Chromium (Playwright), tools/br4-preview.sh + tools/br4-seed.php.',
        'anua = the owner\'s PAGE BANNER on (like the live Anua), no header_image, logo, long description; plainbr4 = no page banner, header_image, no logo;',
        'tintbr4 = a Tint page banner (no picture) with a heading and a line, no description.',
        'h1 = number of <h1> on the page; box = the laptop panel or the phone capsule and its distance from the banner\'s edges;',
        'desc h = the description\'s rendered height (2 lines clamped vs expanded); sw = documentElement.scrollWidth.', '');

    await shoot(browser, 'anua', 'default-anua-banner');
    await shoot(browser, 'plainbr4', 'default-plain');
    await shoot(browser, 'tintbr4', 'default-tint-banner');
    await shoot(browser, 'anua', 'expanded-anua-banner', async (page) => {
        await page.focus('.brw-ph__more');
        await page.keyboard.press('Enter');
        await page.waitForTimeout(150);
    });
    await shoot(browser, 'plainbr4', 'expanded-plain', async (page) => {
        await page.click('.brw-ph__more');
        await page.waitForTimeout(150);
    });

    // Arabic: RTL, the same clamp and toggle.
    for (const w of WIDTHS) {
        const ctx = await ctxFor(browser, w);
        const page = await ctx.newPage();
        const res = await page.goto(`${BASE}/ar/brands/anua/`, { waitUntil: 'networkidle' });
        await page.screenshot({ path: `${OUT}/arabic-anua-banner-${w}.png` });
        note(`arabic-anua-banner-${w} (HTTP ${res.status()}, dir=${await page.getAttribute('html', 'dir')})`, await measure(page));
        await ctx.close();
    }

    // The pop-up's new controls, Laptop then Phone, at both widths.
    for (const w of WIDTHS) {
        const { ctx, page } = await login(browser, w);
        await openPopup(page, 'anua');
        await device(page, 'laptop');
        await shootControls(page, `popup-laptop-${w}`);
        await device(page, 'phone');
        await shootControls(page, `popup-phone-${w}`);
        await ctx.close();
    }

    const { ctx, page } = await login(browser, 1280);

    // Logo on (this brand), both devices.
    await openPopup(page, 'anua');
    await pick(page, 'logo_show', 'on');
    await device(page, 'phone');
    await pick(page, 'logo_show_m', 'on');
    lines.push(`popup logo-on preview before Save (no request): ${JSON.stringify(await previewState(page))}`);
    await page.screenshot({ path: `${OUT}/popup-logo-on-1280.png` });
    await save(page);
    await shoot(browser, 'anua', 'logo-on-anua-banner');

    // Name left, description left (laptop), phone name top-left and text left.
    await openPopup(page, 'anua');
    await page.click('.kbb-qe__layout button.kbb-qe__link');
    await device(page, 'laptop');
    await pick(page, 'name_align', 'left');
    await pick(page, 'desc_align', 'left');
    await device(page, 'phone');
    await pick(page, 'pill_at', 'bottom-left');
    await pick(page, 'desc_align_m', 'left');
    lines.push(`popup name-left preview before Save (no request): ${JSON.stringify(await previewState(page))}`);
    await page.screenshot({ path: `${OUT}/popup-name-left-1280.png` });
    await save(page);
    await shoot(browser, 'anua', 'name-left-anua-banner');

    // Back to the shop's values.
    await openPopup(page, 'anua');
    await page.click('.kbb-qe__layout button.kbb-qe__link');
    await save(page);
    await shoot(browser, 'anua', 'reset-anua-banner');

    // Appearance -> Site layout -> Brand page.
    await page.goto(`${BASE}/admin/#sitelayout`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2500);
    await page.click('button:has-text("Brand page"), a:has-text("Brand page")');
    await page.waitForTimeout(800);
    const sec = await page.$('text=/laptop · show the brand logo/i');
    if (sec) {
        for (const [label, file] of [['laptop · show the brand logo', 'site-layout-brand-page-1280'], ['laptop · description lines', 'site-layout-brand-page-lines-1280']]) {
            const el = await page.$(`text=/${label}/i`);
            await el.evaluate((e) => e.scrollIntoView({ block: 'start' }));
            await page.waitForTimeout(300);
            await page.screenshot({ path: `${OUT}/${file}.png` });
        }
        lines.push('site-layout: the BR4 rows found on Appearance -> Site layout -> Brand page (site-layout-brand-page-*.png)');
    } else {
        lines.push('site-layout: "Show the brand logo" NOT found');
    }

    await ctx.close();
    await browser.close();
    fs.writeFileSync(`${OUT}/numbers.txt`, `${lines.join('\n')}\n`);
})().catch((e) => { console.error(e); process.exit(1); });
