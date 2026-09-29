/**
 * Lane CR — what the cart page's set row actually measures, and where an open
 * popup lands.
 *
 * Reports only. It clicks a "What's inside" opener when asked to, then reads
 * back the geometry the PHP side decides about: document.documentElement.-
 * scrollWidth, the row's own box, and whether the set line's name and quantity
 * stepper are on the screen at all.
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';

const url = process.env.KBB_BROWSER_URL;
const width = parseInt(process.env.KBB_BROWSER_WIDTH || '1280', 10);
const height = parseInt(process.env.KBB_BROWSER_HEIGHT || '900', 10);
const cookie = process.env.KBB_BROWSER_CART_COOKIE || '';
const shot = process.env.KBB_BROWSER_SHOT || '';
const open = process.env.KBB_BROWSER_OPEN || '';   // '', 'first', 'last'
const dir = process.env.KBB_BROWSER_DIR || 'ltr';

const out = { ok: false, width, height, url, open };

let browser;

try {
    browser = await chromium.launch({
        executablePath: process.env.KBB_BROWSER_CHROME,
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });

    const context = await browser.newContext({ viewport: { width, height } });

    if (cookie !== '') {
        await context.addCookies([{
            name: 'kbb_cart', value: cookie, domain: '127.0.0.1', path: '/',
        }]);
    }

    const page = await context.newPage();
    await page.goto(url, { waitUntil: 'load', timeout: 30000 });
    await page.waitForTimeout(400);

    if (open === 'first' || open === 'last') {
        const buttons = await page.$$('.kbb-cartpage .items [data-kset-toggle]');
        const rowButtons = await page.$$('.kbb-cartpage .items .ci [data-kset-toggle]');
        const list = rowButtons.length ? rowButtons : buttons;
        const target = open === 'first' ? list[0] : list[list.length - 1];
        if (target) {
            await target.click();
            await page.waitForTimeout(250);
        }
    }

    out.measurements = await page.evaluate((info) => {
        const box = (el) => {
            if (!el) return null;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            return {
                x: Math.round(r.x), y: Math.round(r.y),
                w: Math.round(r.width), h: Math.round(r.height),
                display: cs.display, visibility: cs.visibility,
                fontSize: cs.fontSize, overflow: cs.overflow,
                onScreen: r.width > 0 && r.height > 0,
            };
        };

        const rows = Array.from(document.querySelectorAll('.kbb-cartpage .items .ci'));
        const setRow = document.querySelector('.kbb-cartpage .items .ci.ci-set');
        const items = document.querySelector('.kbb-cartpage .items');
        const pop = document.querySelector('.kset-pop.is-open') || document.querySelector('.kset-pop');

        const result = {
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
            rowCount: rows.length,
            itemsOverflow: items ? getComputedStyle(items).overflow : null,
            itemsRadius: items ? getComputedStyle(items).borderRadius : null,
            itemsBox: box(items),
            setRow: box(setRow),
            setName: box(setRow ? setRow.querySelector('.cn') : null),
            setNameText: setRow && setRow.querySelector('.cn') ? setRow.querySelector('.cn').textContent.trim() : null,
            setQty: box(setRow ? setRow.querySelector('.qty') : null),
            setFan: box(setRow ? setRow.querySelector('.kset-fan') : null),
            setBtn: box(setRow ? setRow.querySelector('.kset-btn') : null),
            plainRow: box(rows.find((r) => !r.classList.contains('ci-set')) || null),
            plainName: null,
            plainQty: null,
            popup: box(pop),
            popupOpen: pop ? pop.classList.contains('is-open') : null,
            popupPosition: pop ? getComputedStyle(pop).position : null,
            popupClipped: null,
        };

        const plain = rows.find((r) => !r.classList.contains('ci-set'));
        if (plain) {
            result.plainName = box(plain.querySelector('.cn'));
            result.plainQty = box(plain.querySelector('.qty'));
        }

        // Is the open popup inside the .items box, i.e. would overflow:hidden
        // have anything to clip? Reported, never judged here.
        if (pop && items) {
            const p = pop.getBoundingClientRect();
            const i = items.getBoundingClientRect();
            result.popupClipped = {
                popBottom: Math.round(p.bottom), itemsBottom: Math.round(i.bottom),
                overflowsBottom: Math.round(p.bottom - i.bottom),
                popRight: Math.round(p.right), itemsRight: Math.round(i.right),
                overflowsRight: Math.round(p.right - i.right),
                // What the engine actually paints: an element clipped away has
                // no box in the scrolling area of its clipping ancestor.
                visibleInViewport: p.top < info.height && p.bottom > 0 && p.left < info.width && p.right > 0,
            };

            /*
             * IS IT PAINTED? A clipped element still reports its full box from
             * getBoundingClientRect -- clipping is a paint-time effect, not a
             * layout one -- so the box alone cannot answer the owner's
             * question. document.elementFromPoint() does: it returns what the
             * engine would hit-test at a coordinate, and an overflow-clipped
             * pixel hit-tests to whatever is BEHIND the clip. Sampled 8px
             * inside the popup's own bottom edge.
             */
            const px = Math.round(p.left + Math.min(40, p.width / 2));
            const py = Math.round(p.bottom - 8);
            const hit = document.elementFromPoint(px, py);
            result.popupClipped.probe = { x: px, y: py,
                hit: hit ? (hit.className || hit.tagName) : null,
                insidePopup: !!(hit && (hit === pop || pop.contains(hit))) };
        }

        return result;
    }, { width, height });

    if (shot !== '') {
        await page.screenshot({ path: shot, fullPage: false });
        out.shot = shot;
    }

    out.ok = true;
} catch (e) {
    out.error = String(e && e.message ? e.message : e);
} finally {
    if (browser) await browser.close();
}

console.log(JSON.stringify(out, null, 2));
