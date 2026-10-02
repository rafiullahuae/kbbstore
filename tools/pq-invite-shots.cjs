/*
 * Store -> Customers -> Send account invite, photographed.          (Lane PQ)
 *
 *   list       Customers, "Guest checkout" chip, rows ticked, "Select all N
 *              matching this view" pressed, the bulk bar with the invite button
 *   modal      the dialog: counts, editable subject/body, placeholders, live
 *              preview of the real email; edited, previewed, saved as template
 *   send       confirm with the count, the batched progress, done (1280)
 *   ten-min    the dialog again straight after: everybody "invited in the
 *              last 10 minutes — skipped" (390)
 *   email      the email as actually sent (seeded through the real sender)
 *   welcome    the set-password page from that email's link, then signed in
 *   pills      the list on "Invited, not activated" and the Invited/Activated
 *              pills; one customer's page with its invite line
 *
 * Usage: sh tools/pq-invite-preview.sh   (prints the port)
 *        PQ_BASE=http://127.0.0.1:9520 node tools/pq-invite-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PQ_BASE || 'http://127.0.0.1:9520';
const APP = path.resolve(__dirname, '..');
const OUT = path.resolve(APP, process.env.PQ_OUT || 'storage/pq-logs/shots');
const WEBROOT = path.join(APP, 'storage/framework/testing/lane-pq-invite-preview/webroot');
const LINK = fs.readFileSync(path.join(WEBROOT, 'pq-link.txt'), 'utf8').trim();
const LINK2 = fs.readFileSync(path.join(WEBROOT, 'pq-link-2.txt'), 'utf8').trim();

async function signIn(page) {
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
}

async function customers(page, chip) {
    await page.evaluate(() => window.go('customers'));
    await page.waitForSelector('.chip[data-cuf]', { timeout: 15000 });
    if (chip) {
        await page.click(`.chip[data-cuf="${chip}"]`);
        await page.waitForTimeout(700);
    }
}

const sw = (page) => page.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth }));

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: CHROME });
    const measure = {};

    for (const [w, h] of [[1280, 900], [390, 844]]) {
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        const m = (measure[w] = { errors });

        await signIn(page);
        await customers(page, 'guest');
        m.guestChip = await page.textContent('.chip[data-cuf="guest"]');
        m.invitedChip = await page.textContent('.chip[data-cuf="invited"]');

        // Tick three rows, then "Select all N matching this view".
        const boxes = page.locator('[data-cusel]');
        for (let i = 0; i < 3; i++) await boxes.nth(i).click();
        m.bulkBarTicked = (await page.textContent('#cuInvite').catch(() => null));
        m.selectAllLabel = await page.textContent('#cuSelAll');
        await page.click('#cuSelAll');
        await page.waitForTimeout(200);
        m.bulkBarAll = await page.locator('#cuInvite').locator('..').innerText();
        m.list = await sw(page);
        await page.screenshot({ path: `${OUT}/01-customers-guests-selected-${w}.png`, fullPage: false });

        // The dialog.
        await page.click('#cuInvite');
        await page.waitForSelector('#kbiSubject', { timeout: 20000 });
        await page.waitForFunction(() => (document.getElementById('kbiFrame')?.getAttribute('srcdoc') || '').length > 100, null, { timeout: 15000 });
        await page.waitForTimeout(500);
        m.summary = await page.innerText('.kbi-sum');
        m.sendButton = await page.innerText('[data-kbi="send"]');
        m.modal = await sw(page);
        m.modalBox = await page.evaluate(() => { const r = document.getElementById('kbi').getBoundingClientRect(); return { width: Math.round(r.width), left: Math.round(r.left) }; });
        await page.screenshot({ path: `${OUT}/02-invite-modal-${w}.png`, fullPage: false });
        // The overlay scrolls inside a fixed box, so a tall viewport (same
        // width) is what lets one picture hold the whole dialog.
        await page.setViewportSize({ width: w, height: 2600 });
        await page.waitForTimeout(300);
        await page.locator('#kbi').screenshot({ path: `${OUT}/02-invite-modal-full-${w}.png` });
        await page.setViewportSize({ width: w, height: h });

        if (w === 1280) {
            // Edit: the preview follows; a template without the link is refused.
            await page.fill('#kbiBody', 'Hi {first_name},\n\nThis has no link in it.');
            await page.waitForFunction(() => !!document.querySelector('.kbi-err'), null, { timeout: 10000 });
            m.refusedProblem = await page.innerText('.kbi-err');
            m.sendDisabledWithoutLink = await page.$eval('[data-kbi="send"]', (b) => b.disabled);
            await page.screenshot({ path: `${OUT}/03-invite-modal-refused-no-link-${w}.png` });

            await page.fill('#kbiBody', 'Hi {first_name},\n\nWe have moved to a new website and your {shop_name} account is ready for {email}. Choose a password here:\n\n{set_password_link}\n\nThe link expires on {link_expires}.\n\nLove,\nThe {shop_name} team');
            await page.fill('#kbiSubject', '{first_name}, your {shop_name} account is ready');
            await page.waitForFunction(() => !document.querySelector('.kbi-err') && (document.getElementById('kbiPrevSubject')?.textContent || '').includes('your K Beauty Bliss account'), null, { timeout: 10000 });
            await page.waitForTimeout(400);
            m.previewSubjectAfterEdit = await page.textContent('#kbiPrevSubject');
            await page.screenshot({ path: `${OUT}/04-invite-modal-edited-live-preview-${w}.png` });

            const [save] = await Promise.all([
                page.waitForResponse((r) => r.url().includes('/invites/template') && r.request().method() === 'POST'),
                page.click('[data-kbi="save"]'),
            ]);
            m.saveStatus = save.status();

            // Send: confirm with the count, then the batches.
            await page.click('[data-kbi="send"]');
            m.confirm = await page.innerText('.kbi-f');
            await page.screenshot({ path: `${OUT}/05-invite-confirm-${w}.png` });
            await page.click('[data-kbi="go"]');
            await page.waitForSelector('.kbi-bar', { timeout: 15000 });
            await page.waitForFunction(() => /\b([4-9]\d|\d{3,})\b of/.test(document.querySelector('.kbi-b')?.innerText || ''), null, { timeout: 60000 });
            m.progressMid = await page.innerText('.kbi-b');
            await page.screenshot({ path: `${OUT}/06-invite-progress-${w}.png` });
            await page.waitForFunction(() => /^Done\./m.test(document.querySelector('.kbi-b')?.innerText || ''), null, { timeout: 600000 });
            m.progressDone = await page.innerText('.kbi-b');
            await page.screenshot({ path: `${OUT}/07-invite-done-${w}.png` });
            await page.click('[data-kbi="close"]');
            await page.waitForTimeout(1200);
        } else {
            // Straight after the 1280 send: the ten-minute rule, in the dialog,
            // then re-confirmed with the box, which is what makes Send live.
            await page.screenshot({ path: `${OUT}/08-invite-modal-ten-minute-rule-${w}.png` });
            await page.check('#kbiRecent');
            await page.waitForTimeout(300);
            m.summaryReconfirmed = await page.innerText('.kbi-sum');
            m.sendButtonReconfirmed = await page.innerText('[data-kbi="send"]');
            await page.setViewportSize({ width: w, height: 2600 });
            await page.waitForTimeout(300);
            await page.locator('#kbi').screenshot({ path: `${OUT}/08-invite-modal-reconfirmed-full-${w}.png` });
            await page.setViewportSize({ width: w, height: h });
            await page.click('[data-kbi="close"]');
        }

        // The pills, and the filter.
        await customers(page, 'invited');
        m.invitedChipAfter = await page.textContent('.chip[data-cuf="invited"]');
        m.pillsOnPage = await page.$$eval('#content .pill.amber, #content .pill.green', (p) => p.map((x) => x.textContent).slice(0, 6));
        m.listInvited = await sw(page);
        await page.screenshot({ path: `${OUT}/09-customers-invited-not-activated-${w}.png` });

        await customers(page, 'all');
        await page.fill('#cuSearch', 'Rania Ali');
        await page.waitForTimeout(900);
        await page.screenshot({ path: `${OUT}/10-customers-activated-pill-${w}.png` });
        await page.fill('#cuSearch', 'Grace Joseph');
        await page.waitForTimeout(900);
        await page.click('[data-cuview]');
        await page.waitForSelector('#cuNote', { timeout: 10000 });
        m.detailInvite = await page.locator('.fld', { hasText: 'Account invite' }).innerText();
        m.detail = await sw(page);
        await page.screenshot({ path: `${OUT}/11-customer-detail-invite-${w}.png` });

        // The email as sent.
        await page.goto(BASE + '/pq-sent-email.html', { waitUntil: 'networkidle' });
        m.email = await sw(page);
        await page.screenshot({ path: `${OUT}/12-sent-email-${w}.png`, fullPage: true });

        // The set-password page, from that email's link, in a fresh browser.
        const shopper = await browser.newContext({ viewport: { width: w, height: h } });
        const sp = await shopper.newPage();
        sp.on('pageerror', (e) => errors.push('shop: ' + String(e)));
        const resp = await sp.goto(w === 1280 ? LINK : LINK2, { waitUntil: 'networkidle' });
        m.welcomeStatus = resp.status();
        m.welcomeHeaders = { cacheControl: resp.headers()['cache-control'], referrer: resp.headers()['referrer-policy'] };
        m.welcome = await sw(sp);
        m.welcomeH1 = await sp.textContent('h1');
        m.welcomeH1Size = await sp.$eval('h1', (e) => getComputedStyle(e).fontSize);
        await sp.screenshot({ path: `${OUT}/13-set-password-page-${w}.png`, fullPage: true });

        if (w === 1280) {
            // The browser's own minlength would stop this before the server
            // saw it; switched off so the SERVER's refusal is what is shown.
            await sp.$eval('form[action$="/my-account/welcome"]', (f) => { f.noValidate = true; });
            await sp.fill('input[name=password]', 'short');
            await sp.fill('input[name=password_confirmation]', 'short');
            await Promise.all([sp.waitForNavigation({ waitUntil: 'networkidle' }), sp.click('button.go')]);
            m.tooShort = await sp.textContent('.auth-err').catch(() => null);
            await sp.screenshot({ path: `${OUT}/14-set-password-too-short-${w}.png`, fullPage: true });
            await sp.fill('input[name=password]', 'mariam-chose-this-1');
            await sp.fill('input[name=password_confirmation]', 'mariam-chose-this-1');
            await Promise.all([sp.waitForNavigation({ waitUntil: 'networkidle' }), sp.click('button.go')]);
            m.afterSetUrl = sp.url();
            await sp.screenshot({ path: `${OUT}/15-signed-in-after-set-${w}.png`, fullPage: false });
        } else {
            // Mariam's link is spent now (used at 1280): the one "no longer valid" page.
            await sp.goto(LINK, { waitUntil: 'networkidle' });
            m.spentText = await sp.textContent('.auth-err').catch(() => null);
            await sp.screenshot({ path: `${OUT}/16-set-password-link-used-${w}.png`, fullPage: true });
        }
        await shopper.close();

        if (w === 1280) {
            // Mariam now reads Activated.
            await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
            await customers(page, 'all');
            await page.fill('#cuSearch', 'Mariam Al Haddad');
            await page.waitForTimeout(900);
            m.mariamPills = await page.$$eval('#content tbody .pill', (p) => p.map((x) => x.textContent));
            await page.screenshot({ path: `${OUT}/17-customers-mariam-activated-${w}.png` });
        }

        await ctx.close();
    }

    await browser.close();
    fs.writeFileSync(`${OUT}/measure.json`, JSON.stringify(measure, null, 2));
    console.log(JSON.stringify(measure, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
