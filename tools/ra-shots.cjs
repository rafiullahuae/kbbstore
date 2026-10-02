/*
 * Lane RA evidence: the storefront admin bar and the quick-edit pencil.
 *
 *   sh tools/ra-preview.sh 9300
 *   RA_BASE=http://127.0.0.1:9300 NODE_PATH=/opt/node22/lib/node_modules node tools/ra-shots.cjs
 *
 * Writes docs/ra-shots/*.png, docs/ra-shots/measurements.json and
 * docs/ra-shots/overview.png. getBoundingClientRect is this HARNESS's
 * instrument, never shipped to anyone.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.RA_BASE || 'http://127.0.0.1:9300';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, 'docs/ra-shots');
const ROOT = path.resolve(APP, 'storage/framework/testing/lane-ra-after/webroot');
const CAT = '/collections/skincare/sunscreens/';
const WIDTHS = [390, 1280];
const result = { base: BASE, widths: {} };
const shots = [];

fs.mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function watch(page, bag) {
    page.on('pageerror', (e) => bag.errors.push('pageerror: ' + String(e)));
    page.on('console', (m) => { if (m.type() === 'error') bag.errors.push('console: ' + m.text()); });
    page.on('request', (r) => bag.requests.push(r.method() + ' ' + r.url().replace(BASE, '')));
    page.on('response', (r) => { if (r.status() >= 400) bag.errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
}

async function shot(page, name, opts = {}) {
    const file = path.join(OUT, name + '.png');
    await page.screenshot({ path: file, fullPage: false, ...opts });
    shots.push(name);
}

const geometry = () => {
    const r = (sel) => {
        const el = document.querySelector(sel);
        if (!el || el.hidden) return null;
        const b = el.getBoundingClientRect();
        return { top: Math.round(b.top), left: Math.round(b.left), width: Math.round(b.width), height: Math.round(b.height), z: getComputedStyle(el).zIndex };
    };
    return {
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        htmlMarginTop: getComputedStyle(document.documentElement).marginTop,
        bar: r('#kbb-adm'),
        tab: r('#kbb-adm-tab'),
        header: r('body > header'),
        titleHeader: r('[data-kbb-title-header]'),
        pencil: r('.kbb-qe-pill'),
        modalBox: r('.kbb-qe__box'),
        barFont: document.querySelector('#kbb-adm') ? getComputedStyle(document.querySelector('#kbb-adm')).fontSize : null,
        barLinks: [...document.querySelectorAll('#kbb-adm .kbb-adm__links a, #kbb-adm .kbb-adm__links button')].map((a) => a.textContent.trim()),
        pencilFont: document.querySelector('.kbb-qe-pill') ? getComputedStyle(document.querySelector('.kbb-qe-pill')).fontSize : null,
    };
};

async function signIn(page) {
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });

    for (const width of WIDTHS) {
        const W = {};
        result.widths[width] = W;
        const vp = { width, height: width < 600 ? 844 : 900 };

        /* ----------------------------------------------------- the shopper */
        {
            const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
            const page = await ctx.newPage();
            const bag = { errors: [], requests: [] };
            watch(page, bag);
            await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
            await sleep(400);
            await shot(page, `01-shopper-category-${width}`);
            W.shopper = await page.evaluate(geometry);
            W.shopper.adminRequests = bag.requests.filter((r) => /admin-api|storefront-admin/.test(r));
            W.shopper.requestCount = bag.requests.length;
            W.shopper.errors = [...bag.errors];
            bag.errors.length = 0;
            // A FORGED hint: one 401, then the cookie is gone and nothing draws.
            await ctx.addCookies([{ name: 'kbb_ah', value: '1', url: BASE }]);
            bag.requests.length = 0;
            await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
            await sleep(500);
            W.forged = await page.evaluate(geometry);
            W.forged.adminRequests = bag.requests.filter((r) => /admin-api|storefront-admin/.test(r));
            W.forged.errors = [...bag.errors];
            W.forged.hintAfter = (await ctx.cookies()).filter((c) => c.name === 'kbb_ah').length;
            await shot(page, `02-forged-hint-category-${width}`);
            await ctx.close();
        }

        /* ------------------------------------------------------- the owner */
        const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const bag = { errors: [], requests: [] };
        watch(page, bag);
        await signIn(page);
        W.hintCookie = (await ctx.cookies()).filter((c) => c.name === 'kbb_ah').map((c) => ({ httpOnly: c.httpOnly, sameSite: c.sameSite, path: c.path }));

        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        await page.waitForSelector('#kbb-adm');
        await sleep(300);
        await shot(page, `03-owner-category-bar-pencil-${width}`);
        W.owner = await page.evaluate(geometry);

        // Collapsed, then open again.
        await page.click('.kbb-adm__hide');
        await sleep(250);
        await shot(page, `04-bar-collapsed-${width}`);
        W.collapsed = await page.evaluate(geometry);
        await page.reload({ waitUntil: 'networkidle' });
        await sleep(500);
        W.collapsedAfterReload = await page.evaluate(geometry);
        await page.click('#kbb-adm-tab');
        await sleep(250);
        W.reopened = await page.evaluate(geometry);

        // Scrolled: the sticky header parks under the bar.
        await page.evaluate(() => window.scrollTo(0, 700));
        await sleep(300);
        await shot(page, `05-scrolled-sticky-header-${width}`);
        W.scrolled = await page.evaluate(geometry);

        if (width >= 1000) {
            // nth=1 (Skincare): the Brands mega is display:none on this
            // fixture for everybody, bar or no bar.
            await page.hover('.mbar .navitem >> nth=1');
            await sleep(450);
            await shot(page, `06-mega-menu-with-bar-${width}`);
            W.mega = await page.evaluate(() => {
                const d = document.querySelectorAll('.mbar .navitem')[1].querySelector('.drop');
                const b = d.getBoundingClientRect();
                const bar = document.querySelector('#kbb-adm').getBoundingClientRect();
                return { dropTop: Math.round(b.top), barBottom: Math.round(bar.bottom), visible: getComputedStyle(d).visibility };
            });
            await page.mouse.move(5, 500);
            await sleep(300);
        } else {
            await page.evaluate(() => window.scrollTo(0, 0));
            await page.click('#burger');
            await sleep(500);
            await shot(page, `06-mobile-menu-over-bar-${width}`);
            W.mobileMenu = await page.evaluate(() => {
                const m = document.querySelector('#mmenu');
                const at = document.elementFromPoint(window.innerWidth - 60, 16);
                return { menuZ: getComputedStyle(m).zIndex, topRightIsBar: !!(at && at.closest('#kbb-adm')) };
            });
            await page.keyboard.press('Escape');
            await sleep(400);
        }

        // The cart panel opens OVER the bar, close button reachable.
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.click('[data-kbb-cart]');
        await sleep(700);
        await shot(page, `07-cart-panel-over-bar-${width}`);
        W.cartPanel = await page.evaluate(() => {
            const at = document.elementFromPoint(window.innerWidth - 30, 16);
            return { topRightElement: at ? (at.className && String(at.className).slice(0, 40)) || at.tagName : null, topRightIsBar: !!(at && at.closest('#kbb-adm')) };
        });
        await page.keyboard.press('Escape');
        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        await page.waitForSelector('.kbb-qe-pill');
        await sleep(200);

        /* -------------------------------------------------------- the pop-up */
        await page.evaluate(() => { window.__raNoReload = 'still-here'; });
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-qe__pv .kbb-th');
        await sleep(500);
        await shot(page, `08-modal-open-${width}`);
        W.modal = await page.evaluate(geometry);
        W.modal.activeId = await page.evaluate(() => document.activeElement && document.activeElement.id);

        // Drag-over state, with a real DataTransfer.
        await page.evaluate(() => {
            const dt = new DataTransfer();
            const drop = document.querySelector('.kbb-qe__drop');
            drop.dispatchEvent(new DragEvent('dragenter', { dataTransfer: dt, bubbles: true, cancelable: true }));
        });
        await sleep(150);
        await shot(page, `09-drag-over-${width}`);
        W.dragOver = await page.evaluate(() => document.querySelector('.kbb-qe__drop').classList.contains('is-over'));
        await page.evaluate(() => {
            const drop = document.querySelector('.kbb-qe__drop');
            drop.dispatchEvent(new DragEvent('dragleave', { bubbles: true }));
        });

        // Upload, slowed so the progress bar is visible: a real throttle.
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 40, downloadThroughput: 4e6, uploadThroughput: 12 * 1024 });
        const banner = path.join(ROOT, 'uploads/ra/new-banner.jpg');
        // Dropped, the way the owner will do it: a DataTransfer carrying the file.
        const buf = fs.readFileSync(banner).toString('base64');
        await page.evaluate((b64) => {
            const bin = atob(b64);
            const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            const file = new File([bytes], 'new-banner.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer();
            dt.items.add(file);
            const drop = document.querySelector('.kbb-qe__drop');
            drop.dispatchEvent(new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }));
        }, buf);
        await sleep(1500);
        await shot(page, `10-upload-progress-${width}`);
        W.uploadProgress = await page.evaluate(() => ({
            uploading: document.querySelector('.kbb-qe__drop').classList.contains('is-up'),
            bar: document.querySelector('.kbb-qe__bar i').style.width,
            saveDisabled: document.querySelector('.kbb-qe__btn--go').disabled,
        }));
        await page.waitForFunction(() => !document.querySelector('.kbb-qe__drop').classList.contains('is-up'), null, { timeout: 30000 });
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        await page.fill('#kbb-qe-title', 'Korean Sunscreens · Summer edit');
        await page.fill('#kbb-qe-sub', 'Light SPFs for the UAE sun');
        await sleep(1200);
        await shot(page, `11-modal-after-upload-preview-${width}`);
        W.previewImg = await page.evaluate(() => {
            const i = document.querySelector('.kbb-qe__pv img');
            return i ? i.getAttribute('src') : null;
        });

        // An error stays in the pop-up and changes nothing on the page.
        await page.fill('#kbb-qe-title', 'x'.repeat(301));
        await page.evaluate(() => document.querySelector('#kbb-qe-title').removeAttribute('maxlength'));
        await page.fill('#kbb-qe-title', 'x'.repeat(301));
        await page.click('.kbb-qe__btn--go');
        await page.waitForSelector('.kbb-qe__err.is-on');
        await sleep(200);
        await shot(page, `12-save-error-stays-open-${width}`);
        W.error = await page.evaluate(() => ({
            message: document.querySelector('.kbb-qe__err').textContent,
            modalOpen: !!document.querySelector('#kbb-qe'),
            pageTitle: document.querySelector('[data-kbb-title-header] .kbb-th__title').textContent,
        }));

        // Save: the header swaps in place, the toast shows, the pop-up closes.
        await page.fill('#kbb-qe-title', 'Korean Sunscreens · Summer edit');
        const t0 = Date.now();
        await page.click('.kbb-qe__btn--go');
        await page.waitForSelector('#kbb-qe', { state: 'detached' });
        W.saveMs = Date.now() - t0;
        await sleep(250);
        await shot(page, `13-saved-in-place-toast-${width}`);
        W.saved = await page.evaluate(() => ({
            noReload: window.__raNoReload,
            title: document.querySelector('[data-kbb-title-header] .kbb-th__title').textContent,
            sub: (document.querySelector('[data-kbb-title-header] .kbb-th__sub') || {}).textContent,
            img: (document.querySelector('[data-kbb-title-header] img') || {}).getAttribute && document.querySelector('[data-kbb-title-header] img').getAttribute('src'),
            toast: (document.querySelector('.kbb-qe-toast') || {}).textContent,
            pencilStillThere: !!document.querySelector('[data-kbb-title-header] .kbb-qe-pill'),
            headers: document.querySelectorAll('[data-kbb-title-header]').length,
            lock: document.documentElement.classList.contains('kbb-qe-lock'),
        }));
        // And a shopper loading the page now sees it.
        {
            const sctx = await browser.newContext({ viewport: vp });
            const sp = await sctx.newPage();
            await sp.goto(BASE + CAT, { waitUntil: 'networkidle' });
            W.shopperAfterSave = await sp.evaluate(() => ({
                title: document.querySelector('[data-kbb-title-header] .kbb-th__title').textContent,
                bar: !!document.querySelector('#kbb-adm'),
                pencil: !!document.querySelector('.kbb-qe-pill'),
            }));
            await sp.screenshot({ path: path.join(OUT, `14-shopper-sees-new-header-${width}.png`) });
            shots.push(`14-shopper-sees-new-header-${width}`);
            await sctx.close();
        }

        /* ------------------------------------------------------- brand pages */
        await page.goto(BASE + '/brands/ra-glow/', { waitUntil: 'networkidle' });
        await page.waitForSelector('.kbb-qe-pill');
        await sleep(300);
        await shot(page, `15-brand-bar-pencil-${width}`);
        W.brand = await page.evaluate(geometry);
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-qe__pv .kbb-th');
        await sleep(500);
        await shot(page, `16-brand-modal-${width}`);
        await page.fill('#kbb-qe-desc', 'Gentle, fragrance-free care -- edited from the shop.');
        await page.click('.kbb-qe__btn--go');
        await page.waitForSelector('#kbb-qe', { state: 'detached' });
        await sleep(300);
        await shot(page, `17-brand-saved-${width}`);
        W.brandSaved = await page.evaluate(() => (document.querySelector('[data-kbb-title-header] .kbb-th__desc') || {}).textContent);

        await page.goto(BASE + '/brands/ra-plain/', { waitUntil: 'networkidle' });
        await page.waitForSelector('.kbb-qe-pill');
        await sleep(300);
        await shot(page, `18-brand-without-header-pencil-${width}`);
        // A picture on a brand that had no header: the template decides where
        // a header goes, so the page reloads once, on its own. First width
        // only -- after it the brand HAS a header and the swap is in place.
        if (width === WIDTHS[0]) {
        await page.click('.kbb-qe-pill');
        await page.waitForSelector('.kbb-qe__pv-note, .kbb-qe__pv .kbb-th');
        await sleep(400);
        await shot(page, `18b-brand-without-header-modal-${width}`);
        await page.setInputFiles('.kbb-qe__drop input[type=file]', path.join(ROOT, 'uploads/ra/glow-banner.jpg'));
        await page.waitForFunction(() => !document.querySelector('.kbb-qe__drop').classList.contains('is-up') && document.querySelector('.kbb-qe__pv .kbb-th'), null, { timeout: 30000 });
        await sleep(500);
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('.kbb-qe__btn--go')]);
        await page.waitForSelector('.kbb-qe-pill');
        await page.evaluate(() => window.scrollTo(0, 0));
        await sleep(400);
        await shot(page, `18c-brand-header-appears-after-save-${width}`);
        W.plainBrandAfter = await page.evaluate(() => ({ header: !!document.querySelector('[data-kbb-title-header] img'), pencilOnHeader: !!document.querySelector('[data-kbb-title-header] .kbb-qe-pill') }));
        }

        /* ---------------------------------------------------- product page */
        await page.goto(BASE + '/product/py-sunscreens-0/', { waitUntil: 'networkidle' });
        await page.waitForSelector('#kbb-adm');
        await sleep(300);
        await shot(page, `19-product-edit-product-${width}`);
        W.product = await page.evaluate(geometry);

        /* ------------------------------------------- hand-off to the console */
        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        await page.waitForSelector('.kbb-adm__ctx');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('.kbb-adm__ctx')]);
        await sleep(1800);
        await shot(page, `20-console-opens-the-category-${width}`);
        W.handoff = await page.evaluate(() => ({ url: location.pathname + location.search + location.hash, dialog: !!document.querySelector('.ct-modal, [role=dialog]') }));

        /* ------------------------------------------------------------ log out */
        await page.goto(BASE + CAT, { waitUntil: 'networkidle' });
        await page.waitForSelector('.kbb-adm__logout');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('.kbb-adm__logout')]);
        await sleep(500);
        W.loggedOut = await page.evaluate(() => ({ path: location.pathname, bar: !!document.querySelector('#kbb-adm'), hint: /kbb_ah=/.test(document.cookie) }));
        await shot(page, `21-after-logout-same-page-${width}`);

        W.ownerErrors = bag.errors;
        await ctx.close();
    }

    fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(result, null, 2));

    // The overview: every shot on one sheet.
    const ctx = await browser.newContext({ viewport: { width: 1800, height: 1000 } });
    const page = await ctx.newPage();
    const cells = shots.map((s) => `<figure><img src="data:image/png;base64,${fs.readFileSync(path.join(OUT, s + '.png')).toString('base64')}"><figcaption>${s}</figcaption></figure>`).join('');
    await page.setContent(`<html><body style="margin:0;padding:16px;font:12px system-ui;background:#f4f1f2">
      <h1 style="font-size:18px;margin:0 0 12px">Lane RA — storefront admin bar and quick-edit pencil (390 / 1280)</h1>
      <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px">${cells}</div>
      <style>figure{margin:0;background:#fff;padding:6px;border-radius:8px}img{width:100%;height:200px;object-fit:cover;object-position:top;border:1px solid #ddd}figcaption{margin-top:4px;word-break:break-all}</style></body></html>`);
    await sleep(800);
    await page.screenshot({ path: path.join(OUT, 'overview.png'), fullPage: true });
    await browser.close();
    console.log(JSON.stringify(result, null, 1).slice(0, 6000));
})().catch((e) => { console.error(e); process.exit(1); });
