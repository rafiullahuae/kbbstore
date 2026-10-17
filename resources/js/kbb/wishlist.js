/**
 * The wishlist heart — on product cards and the product gallery.
 *
 * The button, the route, and even the "filled" CSS state (`.heart.on`) all
 * already existed. Nothing here had ever been wired to a click: `[data-kbb-wish]`
 * had no listener anywhere in the JS, on any page, so every heart on the site
 * did nothing at all when clicked, despite `POST /wishlist/toggle` sitting
 * ready and working.
 *
 * The wishlist itself lives in an httpOnly cookie (Laravel's default), so
 * this can't just read `document.cookie` to know which hearts should start
 * filled — the browser still sends that cookie automatically with a
 * same-origin fetch, which is what `GET /wishlist/ids` is for.
 */

import { t } from './i18n.js';

let wishIds = null;

async function markSaved() {
    if (wishIds) return wishIds;

    try {
        const response = await fetch(window.KBB.routes.wishlistIds, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();
        wishIds = new Set((data.ids || []).map(Number));
    } catch {
        wishIds = new Set();
    }

    document.querySelectorAll('[data-kbb-wish]').forEach((btn) => {
        btn.classList.toggle('on', wishIds.has(Number(btn.dataset.kbbWish)));
    });

    return wishIds;
}

function setBadge(count) {
    document.querySelectorAll('#kbbWishCt').forEach((badge) => {
        badge.textContent = count;
        badge.style.display = count > 0 ? '' : 'none';
    });
}

/* The cart panel's heart tab (Lane HLB): the list, and the tab's heart --
   outlined in grey while the wishlist is empty, filled red once it is not
   (kbb.css, `.kc-wt .kc-wh`).

   SAFE: `data.pane` is a whole Blade view the server rendered
   (partials/cart-wish-pane), every value in it already through `{{ }}` -- the
   same contract as the drawer fragments cart.js swaps in. */
function setTabHeart(filled) {
    document.querySelectorAll('.kc-wt .kc-wh').forEach((svg) => {
        svg.classList.toggle('on', filled);
        svg.setAttribute('fill', filled ? 'currentColor' : 'none');
    });
}

function setPane(data) {
    const pane = document.getElementById('kcWish');
    if (pane && typeof data.pane === 'string') pane.innerHTML = data.pane;
    setTabHeart(data.count > 0);
}

/* Every heart for this product, not just the one clicked -- the same item's
   heart can appear twice on one page (a grid card and a "you may also like"
   strip), and both need to agree. */
function paint(id, saved) {
    document.querySelectorAll(`[data-kbb-wish="${id}"]`).forEach((el) => {
        el.classList.toggle('on', saved);
    });

    if (wishIds) {
        if (saved) wishIds.add(Number(id));
        else wishIds.delete(Number(id));
        setBadge(wishIds.size);
        setTabHeart(wishIds.size > 0);
    }
}

/* Products whose toggle is on its way to the server. A second tap on the same
   heart before the answer would race the first; it waits instead. */
const inFlight = new Set();

export function initWishlist() {
    markSaved();

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-kbb-wish]');
        if (!btn) return;

        event.preventDefault();

        const id = btn.dataset.kbbWish;
        if (inFlight.has(id)) return;
        inFlight.add(id);

        /* INSTANT (17 October 2026). The owner: "wishlist function gives
           delay, it's not adding instantly". The heart used to wait for the
           server's answer -- and, with the cart panel's heart tab, for the
           server to redraw that tab's list too. It now turns at the tap; the
           request follows, the server's answer is still the truth, and a
           refusal or a failed request puts the heart back as it was. */
        const was = btn.classList.contains('on');
        paint(id, !was);

        try {
            const response = await fetch(window.KBB.routes.wishlistToggle, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                // (Lane HLB) With the cart panel's heart tab on the page, the
                // reply to THIS request carries the tab's new list too -- no
                // request of its own.
                body: `product_id=${encodeURIComponent(id)}${document.getElementById('kcWish') ? '&pane=1' : ''}`,
            });
            const data = await response.json();

            if (!data.ok) {
                paint(id, was);
                window.kbbToast?.(data.error || t('store.js.wishlist_failed', 'Could not update your wishlist.'));
                return;
            }

            paint(id, data.saved);
            setBadge(data.count);
            setPane(data);
            window.kbbToast?.(data.saved
                ? t('store.js.wishlist_saved', 'Saved to your wishlist')
                : t('store.js.wishlist_removed', 'Removed from your wishlist'));
        } catch {
            paint(id, was);
            window.kbbToast?.(t('store.js.wishlist_retry', 'Could not update your wishlist — please try again.'));
        } finally {
            inFlight.delete(id);
        }
    });
}
