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

export function initWishlist() {
    markSaved();

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('[data-kbb-wish]');
        if (!btn) return;

        event.preventDefault();

        if (btn.disabled) return;
        btn.disabled = true;

        const id = btn.dataset.kbbWish;

        try {
            const response = await fetch(window.KBB.routes.wishlistToggle, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: `product_id=${encodeURIComponent(id)}`,
            });
            const data = await response.json();

            if (!data.ok) {
                window.kbbToast?.(data.error || 'Could not update your wishlist.');
                return;
            }

            // Every heart for this product, not just the one clicked — the
            // same item's heart can appear twice on one page (a grid card and
            // a "you may also like" strip), and both need to agree.
            document.querySelectorAll(`[data-kbb-wish="${id}"]`).forEach((el) => {
                el.classList.toggle('on', data.saved);
            });

            if (wishIds) {
                if (data.saved) wishIds.add(Number(id));
                else wishIds.delete(Number(id));
            }

            setBadge(data.count);
            window.kbbToast?.(data.saved ? 'Saved to your wishlist' : 'Removed from your wishlist');
        } catch {
            window.kbbToast?.('Could not update your wishlist — please try again.');
        } finally {
            btn.disabled = false;
        }
    });
}
