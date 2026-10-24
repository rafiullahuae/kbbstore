/**
 * THE FREE GIFTS BOX STAYS TRUE, SILENTLY (Lane FGL).
 *
 * The owner, 24 October: "the free gifts box must update silently at backend
 * during customer journey, to see what's actually available as gifts and as
 * per criteria ... i don't want to disturb the customer at all. and neither
 * compromise on the page speed or server load".
 *
 * Every box the shop can redraw (cart panel, cart page, checkout) carries
 * data-fgs, its fingerprint (FreeGifts::sig()); a basket with no gift yet has
 * an empty <template data-fgs="0">. Every cart write already sends fresh boxes
 * back. Between writes, a gift can run out (another order), come back, or the
 * owner can change the lists -- so this asks GET /api/cart/gifts?s=<print>,
 * which answers 204 with no body while the box is still right.
 *
 * WHEN IT ASKS -- only on the shopper's own moves, never on a timer:
 *   - the cart panel opens; the tab comes back into view or the window regains
 *     focus; a tap, a key or a scroll on a page that shows a box;
 *   - and at most once every 30 s (GAP), one request at a time, never while
 *     the tab is hidden or offline, never for a closed cart panel;
 *   - a page restored from the back/forward cache asks at once.
 * A page with no data-fgs (gifts off, or nothing drawn) never asks at all.
 *
 * WHAT IT CHANGES: only the box, in place. A box the shopper opened ("View
 * all") stays open; the cart page's box keeps its class; nothing scrolls,
 * nothing animates, nothing else on the page is touched. Gifts switched off
 * remove the box and the asking stops. No layout is measured.
 */
const GAP = 30000;
let at = Date.now();
let busy = false;

const boxes = () => Array.from(document.querySelectorAll('[data-fgs]'));

// A box inside the cart panel counts only while the panel is open; anywhere
// else (cart page, checkout) it is on the page the shopper is looking at.
const inSight = (el) => {
    const panel = el.closest('#cart');
    return !panel || panel.classList.contains('on');
};

/** A cart write just drew fresh boxes: the next check can wait a full GAP. */
export const giftsFresh = () => { at = Date.now(); };

const swap = (el, html) => {
    const page = el.classList.contains('fgb-page') || el.hasAttribute('data-fgp');
    const list = el.querySelector('.fgb-ls');
    const opened = !!list && !list.hidden;
    const tpl = document.createElement('template');
    tpl.innerHTML = html;   // a server-rendered partial, every value escaped there
    const next = tpl.content.firstElementChild;

    if (!next) { el.remove(); return; }

    if (page) {
        if (next.tagName === 'FIELDSET') next.classList.add('fgb-page');
        else next.setAttribute('data-fgp', '');
    }

    const th = next.querySelector('.fgb-th');
    const ls = next.querySelector('.fgb-ls');
    if (opened && th && ls) { th.hidden = true; ls.hidden = false; }

    el.replaceWith(next);
};

async function check(now) {
    if (busy || document.hidden || navigator.onLine === false) return;
    if (!now && Date.now() - at < GAP) return;

    const all = boxes();
    const seen = all.filter(inSight);
    if (!seen.length) return;

    // One print for the lot when they agree; when they differ (a box drawn
    // before a write elsewhere) ask for the box outright.
    const print = all.every((el) => el.dataset.fgs === seen[0].dataset.fgs) ? seen[0].dataset.fgs : '';

    busy = true;
    at = Date.now();

    try {
        const r = await fetch(`${window.KBB.routes.cartApi}/gifts?s=${encodeURIComponent(print)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (r.status !== 200) return;   // 204: still true; 429/5xx: try on a later move

        const d = await r.json();
        if (typeof d.html !== 'string') return;
        boxes().forEach((el) => swap(el, d.html));
    } catch { /* a box one check behind is not worth a word to the shopper */ } finally {
        busy = false;
    }
}

export function initGiftLive() {
    if (!window.KBB?.routes?.cartApi) return;

    const nudge = () => { if (Date.now() - at >= GAP) check(false); };

    ['pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, nudge, { passive: true }));
    // Capture, so a scroll inside the cart panel's own list counts too.
    document.addEventListener('scroll', nudge, { passive: true, capture: true });
    window.addEventListener('focus', nudge);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) nudge(); });
    window.addEventListener('pageshow', (e) => { if (e.persisted) check(true); });

    // The cart panel opening (overlay.js has already added .on by now: its
    // listener is registered first). A panel drawn minutes ago is checked the
    // moment it shows, unless a check or a cart write ran in the last GAP.
    document.addEventListener('click', (e) => {
        if (e.target.closest?.('[data-kbb-open="cart"]')) nudge();
    });
}
