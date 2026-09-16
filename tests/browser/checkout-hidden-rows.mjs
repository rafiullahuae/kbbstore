/**
 * What `display` does the checkout actually compute for the rows it marked
 * hidden? — Lane CX.
 *
 * A CSS cascade claim cannot be settled by reading the stylesheet. The browser's
 * own sheet carries `[hidden]{display:none}`, and an author rule that sets
 * display beats it whatever its specificity, because author origin outranks
 * user-agent origin before specificity is consulted at all. `.kbb-checkout
 * .sumrow{display:flex}` and `.kbb-checkout .kbb-delivery-line{display:flex}`
 * are both such rules. Whether either element is on the shopper's screen is
 * therefore a question only a layout engine answers.
 *
 * This reports, it does not judge. It prints the computed `display` of every
 * element named to it at the viewport it was given, plus whether the element was
 * found at all and whether it carries the attribute. The PHP side decides what
 * those numbers mean, so the same script produces the "before" and the "after".
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const url = process.env.KBB_BROWSER_URL;
const width = parseInt(process.env.KBB_BROWSER_WIDTH || '1280', 10);
const height = parseInt(process.env.KBB_BROWSER_HEIGHT || '900', 10);
const selectors = JSON.parse(process.env.KBB_BROWSER_SELECTORS || '[]');

const out = { ok: false, width, height, url, elements: {} };

let browser;

try {
    browser = await chromium.launch({
        executablePath: process.env.KBB_BROWSER_CHROME,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({ viewport: { width, height } });
    const page = await context.newPage();

    await page.goto(url, { waitUntil: 'load', timeout: 30000 });

    // The stylesheet is a <link>; `load` has it, but give the engine a beat to
    // settle anything the page's own script does on DOMContentLoaded.
    await page.waitForTimeout(400);

    out.elements = await page.evaluate((list) => {
        const result = {};

        for (const selector of list) {
            const nodes = Array.from(document.querySelectorAll(selector));

            result[selector] = nodes.map((el) => {
                const rect = el.getBoundingClientRect();

                return {
                    display: getComputedStyle(el).display,
                    hiddenAttribute: el.hasAttribute('hidden'),
                    width: Math.round(rect.width),
                    height: Math.round(rect.height),
                    text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 90),
                };
            });
        }

        return result;
    }, selectors);

    out.ok = true;
} catch (error) {
    out.error = String((error && error.message) || error);
} finally {
    if (browser) {
        await browser.close().catch(() => {});
    }
}

process.stdout.write(JSON.stringify(out));
