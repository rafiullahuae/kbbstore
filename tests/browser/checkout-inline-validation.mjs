/**
 * What the checkout actually marks, as a shopper fills it in — Lane FI.
 *
 * `inline_validation` is a script. Whether a field ends up red, green or
 * unmarked is a question about events, a layout engine and a class list, and
 * the Pest suite on this project has no browser in it: ModuleInlineValidationTest
 * can pin what the SERVER sends — that the style block, the script and the
 * config island are there with the switch on and absent with it off, and that
 * each of the three settings reaches the page — and it stops exactly where the
 * first keystroke begins. This is the other half.
 *
 * IT REPORTS, IT DOES NOT JUDGE, following tests/browser/checkout-hidden-rows.mjs:
 * it prints the class list and the hint of each named row after each scripted
 * interaction, and the reader decides what the numbers mean. The same script
 * therefore produces the "before" and the "after" of a change, and produces the
 * evidence for the module being on and for it being off without being edited
 * between the two.
 *
 * TWO DEFECTS IT WAS WRITTEN AFTER FINDING, both of which looked right in the
 * server output and were wrong on the page:
 *
 *   1. A row carrying NO validate-* class at all — "Delivery notes" is one of
 *      three on this checkout — was judged anyway. With no rule to break it came
 *      back clean and was given a green tick for being empty.
 *   2. A row carrying `validate-phone` and nothing else — the optional phone —
 *      was given the same tick, because `phone` has no opinion about an empty
 *      box and "no opinion" was being read as "approved".
 *
 * Both are the shop congratulating a shopper for doing nothing, and both make
 * the tick beside a field they really did fill in mean less. `cases` below
 * covers each by name.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 *
 * ── RUNNING IT ──────────────────────────────────────────────────────────────
 *
 *   KBB_IV_URL=http://127.0.0.1:8941/checkout/ \
 *   KBB_IV_CART=<the kbb_cart cookie value> \
 *   KBB_IV_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *   node tests/browser/checkout-inline-validation.mjs
 *
 * The cart cookie is required: /checkout/ redirects an empty basket back to the
 * shop, and a checkout that quietly has no cart looks exactly like a checkout
 * whose module is switched off — which would make this report say "off" about a
 * module that is on.
 */
import { chromium } from 'playwright';

const url = process.env.KBB_IV_URL;
const cart = process.env.KBB_IV_CART || '';
const width = parseInt(process.env.KBB_IV_WIDTH || '1280', 10);
const height = parseInt(process.env.KBB_IV_HEIGHT || '1000', 10);

/*
 * Each case is [name, what to do, which rows to report]. The "do" step always
 * ends by moving focus to a DIFFERENT field, because under the shipped default
 * a row is not judged until it is left — so a case that typed and then looked
 * would report "unmarked" about a module that works.
 */
const cases = [
    ['empty required address, left', async (p) => {
        await p.click('#billing_address_1');
        await p.click('#billing_city');
    }, ['billing_address_1_field']],

    ['optional phone, left empty', async (p) => {
        await p.click('#billing_phone');
        await p.click('#billing_city');
    }, ['billing_phone_field']],

    ['optional phone, a real number', async (p) => {
        await p.fill('#billing_phone', '0501234567');
        await p.click('#billing_city');
    }, ['billing_phone_field']],

    ['optional phone, not a number', async (p) => {
        await p.fill('#billing_phone', 'abc');
        await p.click('#billing_city');
    }, ['billing_phone_field']],

    ['delivery notes, no validate-* class of its own', async (p) => {
        await p.click('#customer_note');
        await p.click('#billing_city');
    }, ['customer_note_field']],

    ['email that is not an address', async (p) => {
        await p.fill('#billing_email', 'not-an-email');
        await p.click('#billing_city');
    }, ['billing_email_field']],

    /*
     * The one case that does NOT end by leaving the field, deliberately: it is
     * checking the half of the `blur` default that makes it defensible — once a
     * field has been judged, it is watched live, so a correction is confirmed
     * as it is typed rather than only after moving on.
     */
    ['the same email, corrected without leaving it', async (p) => {
        await p.click('#billing_email');
        await p.fill('#billing_email', 'rafi@example.com');
        await p.waitForTimeout(150);
    }, ['billing_email_field']],
];

const out = { ok: false, error: null, url, width, height, island: null, rows: [] };

let browser;

try {
    browser = await chromium.launch({
        executablePath: process.env.KBB_IV_CHROME,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({ viewport: { width, height } });

    if (cart) {
        const host = new URL(url).hostname;
        await context.addCookies([{ name: 'kbb_cart', value: cart, domain: host, path: '/' }]);
    }

    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'networkidle' });

    /*
     * Whether the module is on at all, read off the page rather than assumed
     * from whatever the database was left in. Every row below is meaningless
     * without it, and a report that did not say so could be read as "the module
     * marks nothing" when it means "the module is off".
     */
    out.island = await page.evaluate(() => {
        const styled = Array.from(document.querySelectorAll('style'))
            .some((s) => s.textContent.includes('kbb-iv-msg'));

        return { on: styled };
    });

    for (const [name, act, ids] of cases) {
        await act(page);
        await page.waitForTimeout(120);

        const seen = await page.evaluate((list) => list.map((id) => {
            const row = document.getElementById(id);

            if (!row) {
                return { id, found: false };
            }

            const el = row.querySelector('.input-text');
            const msg = row.querySelector('.kbb-iv-msg');

            return {
                id,
                found: true,
                // The validate-* classes the markup declares and the
                // woocommerce-* ones the module writes, side by side: the whole
                // question is whether the second follows from the first.
                classes: row.className.replace(/\s+/g, ' ').trim(),
                ariaInvalid: el ? el.getAttribute('aria-invalid') : null,
                describedBy: el ? el.getAttribute('aria-describedby') : null,
                hint: msg ? (msg.textContent || '').trim() : null,
                hintRole: msg ? msg.getAttribute('role') : null,
            };
        }), ids);

        out.rows.push({ case: name, seen });
    }

    out.ok = true;
} catch (error) {
    out.error = String((error && error.message) || error);
} finally {
    if (browser) {
        await browser.close().catch(() => {});
    }
}

process.stdout.write(JSON.stringify(out, null, 2));
