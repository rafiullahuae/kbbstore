/**
 * Where is the Proceed to Checkout button once the phone's URL bar comes back?
 * — Lane "docked viewport".
 *
 * WHAT THIS MEASURES AND WHAT IT CANNOT.
 *
 * The reported bug needs a mobile browser to retract its URL bar on a downward
 * scroll and put it back on an upward one. Headless Chromium has no URL bar, so
 * it has no browser controls to retract, and `100dvh` can never differ from
 * `100lvh` in it. `page.setViewportSize()` is NOT the missing piece either: it
 * resizes the LAYOUT viewport, which is the one thing a URL bar does not touch,
 * and a `position:fixed; bottom:0` block follows it perfectly. Measured that
 * way the bug does not appear at all, which is how a real bug gets written off.
 *
 * So the browser supplies the layout — the real stylesheet, the real rows, the
 * real heights — and the one number it cannot produce is substituted: DVH, the
 * height the engine would report for `100dvh` with the URL bar out. The shipped
 * declaration is
 *
 *     bottom: calc(100lvh - 100dvh)
 *
 * and the `after` state below is that same expression with `100dvh` replaced by
 * DVH. `before` is `bottom:0`, the rule as it shipped. `natural` is the page
 * untouched, where lvh and dvh are equal and the fix must be a no-op.
 *
 * Everything reported is a raw rectangle. This script does not decide whether a
 * number is good; the PHP that drives it does, so the same script measures the
 * before and the after.
 */
import { chromium } from 'playwright';

const url = process.env.KBB_BROWSER_URL;
const width = parseInt(process.env.KBB_BROWSER_WIDTH || '393', 10);
// The layout viewport: the tall one, the height the page has with the URL bar
// retracted. This is what `100lvh` and the fixed-position containing block are.
const height = parseInt(process.env.KBB_BROWSER_HEIGHT || '640', 10);
// What `100dvh` would be with the URL bar back out. Chrome's toolbar on Android
// is 56 CSS px.
const dvh = parseInt(process.env.KBB_BROWSER_DVH || '584', 10);

const out = { ok: false, url, width, height, dvh, states: {} };

let browser;

try {
    browser = await chromium.launch({
        executablePath: process.env.KBB_BROWSER_CHROME,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    // newContext({ viewport }), not page.setViewportSize(): the same reason the
    // other browser scripts in this directory give — the setter does not take
    // in this environment and a measurement at the wrong size is worse than no
    // measurement.
    const context = await browser.newContext({ viewport: { width, height } });
    const page = await context.newPage();

    await page.goto(url, { waitUntil: 'load', timeout: 30000 });
    await page.waitForTimeout(400);

    out.supportsUnits = await page.evaluate(() => CSS.supports('bottom', 'calc(100lvh - 100dvh)'));

    // The bug is only reachable on a page long enough to scroll, which is what
    // "it only comes when u have more products" means. Go to the end of it.
    await page.evaluate(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(200);

    const measure = () => page.evaluate(() => {
        const box = (sel) => {
            const el = document.querySelector(sel);
            if (!el) return null;
            const r = el.getBoundingClientRect();
            return {
                top: Math.round(r.top * 10) / 10,
                bottom: Math.round(r.bottom * 10) / 10,
                height: Math.round(r.height * 10) / 10,
            };
        };

        const docked = document.querySelector('.cpg-docked');

        /*
         * What the PAGE'S OWN stylesheet declares for `bottom`, read out of the
         * CSSOM rather than off the element. getComputedStyle() resolves the
         * calc() to a length and in this engine that length is always 0px, so
         * it cannot tell the shipped rule from no rule at all -- and without
         * this the substitution below would be measuring a fix the script had
         * injected itself. Read before anything is injected; a block with two
         * `bottom` declarations reports the one that won.
         */
        let declaredBottom = null;

        for (const sheet of Array.from(document.styleSheets)) {
            let rules;
            try { rules = Array.from(sheet.cssRules || []); } catch { continue; }

            for (const rule of rules) {
                if (rule.selectorText && rule.selectorText.includes('.cpg-docked') && rule.style.bottom) {
                    declaredBottom = rule.style.bottom;
                }
            }
        }

        return {
            found: !!docked,
            declaredBottom,
            computedBottom: docked ? getComputedStyle(docked).bottom : null,
            computedPaddingBottom: docked ? getComputedStyle(docked).paddingBottom : null,
            scrollY: Math.round(window.scrollY),
            innerHeight: window.innerHeight,
            docked: box('.cpg-docked'),
            addrbar: box('.cpg-addrbar'),
            cobar: box('.cpg-cobar'),
            button: box('.cpg-cobar .cobtn'),
        };
    });

    out.states.natural = await measure();

    // `before`: the rule as it shipped — pinned to the bottom of the LAYOUT
    // viewport, wherever that has got to.
    await page.addStyleTag({
        content: `.kbb-cartpage.cpg-squeeze .cpg-docked{bottom:0 !important}`,
    });
    await page.waitForTimeout(120);
    out.states.before = await measure();

    // `after`: the shipped expression, with the one term this engine cannot
    // produce written out.
    await page.addStyleTag({
        content: `.kbb-cartpage.cpg-squeeze .cpg-docked{bottom:calc(100lvh - ${dvh}px) !important}`,
    });
    await page.waitForTimeout(120);
    out.states.after = await measure();

    out.ok = true;
} catch (error) {
    out.error = String((error && error.stack) || error);
} finally {
    if (browser) {
        await browser.close();
    }
}

process.stdout.write(JSON.stringify(out));
