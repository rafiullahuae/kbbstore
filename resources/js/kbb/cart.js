/**
 * Cart interactions — drawer, cart page, coupons.
 *
 * Every action hits one endpoint that returns the re-rendered drawer AND the
 * re-rendered page in the same response, and both are swapped in. One server
 * render, two places updated, no chance of the drawer disagreeing with the page.
 *
 * Rule 27: event delegation from document rather than a listener per button, so
 * a cart of any size costs the same, and re-rendered markup needs no re-binding.
 */

import { open, closeAll } from './overlay.js';
import { toast } from './toast.js';
import { t } from './i18n.js';

/* Requests are queued, not dropped.
   Adding the same product twice in quick succession used to lose the second
   click entirely: the guard returned null while the first request was still in
   flight, so nothing was sent — and because the caller treats a null answer as
   a failure, it then closed the panel. The quantity never moved. */
let chain = Promise.resolve();

/* Only ask for the cart-page body when the cart page is actually on screen.
   Off the cart page that fragment was rendered server-side and discarded. */
const wantsPage = () => Boolean(document.getElementById('cartInner'));

const post = (path, body) => {
    // Each call waits for the one before it, so two clicks are two writes in the
    // order they were made rather than a race the server has to referee.
    const run = chain.then(() => send(path, body));
    chain = run.catch(() => {});

    return run;
};

const send = async (path, body) => {
    const page = document.getElementById('cartPage');
    page?.classList.add('busy');

    try {
        // The Browsed list is always rendered. Skipping it saved one small query
        // and emptied the Browsed tab on every quantity change, because the
        // fragment that comes back replaces the whole panel.
        const url = `${window.KBB.routes.cartApi}${path}`
            + (wantsPage() ? '?with_page=1' : '');

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.KBB.csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });

        const data = await response.json();
        apply(data);
        return data;
    } catch {
        toast(t('store.js.generic_error', 'Something went wrong — please try again.'));
        return null;
    } finally {
        page?.classList.remove('busy');
    }
};

/**
 * The cart count, everywhere it is shown.
 *
 * There are two badges, not one: #cartCt in the desktop header and #tabCartCt
 * in the mobile tab bar. Only the first was ever updated, so on a phone the
 * number beside the cart icon kept whatever it had been when the page loaded —
 * after every add, on every page. Exported because the checkout's one-tap add
 * needs the same two elements and must not grow its own copy of this.
 *
 * Each is rendered only when the count is above zero (`@if` in the Blade), so a
 * missing element is normal and not an error.
 */
export const setCartCount = (count) => {
    const n = Number(count) || 0;

    ['cartCt', 'tabCartCt'].forEach((id) => {
        const badge = document.getElementById(id);
        if (!badge) return;

        badge.textContent = String(n);
        // The header badge lays out as a grid; the tab-bar one does not, so
        // only the thing that was hidden is given its display back.
        badge.style.display = n ? (id === 'cartCt' ? 'grid' : '') : 'none';
    });
};

/** Swap in whatever the server sent back. */
/* A cheap placeholder using the drawer's own classes, so the panel has real
   structure the instant it opens rather than flashing empty. */
const fragment = () => document.querySelector('#cart .kc-fragment');

/* Behaviour set in Appearance → Cart panel, published on the panel element so
   the script needs no extra request and no global. */
const config = () => {
    try {
        return JSON.parse(document.getElementById('cart')?.dataset.cp || '{}');
    } catch {
        return {};
    }
};

/* Which drawer tab the shopper chose. The server always renders the fragment
   with Cart active, so without remembering this, adding from Browsed threw them
   back to the Cart list — and the tick that says the product is in the bag is on
   the Browsed row they were just looking at. */
let openTab = 'cart';

const showTab = () => {
    document.querySelectorAll('[data-kctab]').forEach(
        (t) => t.classList.toggle('on', t.dataset.kctab === openTab));

    const cart = document.getElementById('kcCart');
    const browsed = document.getElementById('kcBrowsed');
    if (cart) cart.style.display = openTab === 'browsed' ? 'none' : '';
    if (browsed) browsed.style.display = openTab === 'browsed' ? '' : 'none';
};

/* "Added" beside the product name for a moment. Applied after the swap, because
   the swap replaces the row and would take the note with it. Removed outright
   rather than faded — a note on its way out reads as part of the row. */
const noteAdded = (productId) => {
    const name = document.querySelector(`#kcBrowsed [data-brow="${productId}"] .kc-nm`);
    if (!name) return;

    name.querySelector('.kc-added')?.remove();

    const tag = document.createElement('span');
    tag.className = 'kc-added';
    tag.textContent = t('store.js.added', 'Added');
    name.appendChild(tag);

    setTimeout(() => tag.remove(), Number(config().noteMs) || 1400);
};

const skeleton = () => {
    const frag = fragment();
    if (!frag || frag.dataset.busy === '1') return;

    frag.dataset.busy = '1';
    frag.outerHTML = `<div class="kc-fragment" data-busy="1">
        <div class="kc-tabs"><button class="kc-tab on" type="button">Cart</button>
        <button class="kc-x" type="button" data-kbb-close>✕</button></div>
        <div class="dbody">${'<div class="kc-item kc-skel"><div class="kc-th"></div><div class="kc-mid"><span></span><span></span></div></div>'.repeat(2)}</div>
    </div>`;
};

const apply = (data) => {

    if (!data) return;

    const frag = fragment();
    if (frag && data.drawer) frag.outerHTML = data.drawer;

    const inner = document.getElementById('cartInner');
    if (inner && data.page) inner.innerHTML = data.page;   // null off the cart page

    setCartCount(data.count);

    // Same wording and brackets as the Blade, or the heading changes shape the
    // first time a quantity is touched.
    const lead = document.getElementById('cartLead');
    if (lead) lead.textContent = `(${data.count} ${data.count === 1 ? 'item' : 'items'})`;

    const notices = document.getElementById('kbbCartNotices');
    if (notices) notices.innerHTML = data.error ? `<div class="cart-note err">${data.error}</div>` : '';

    showTab();

    if (data.toast) toast(data.toast);
    else if (data.error) toast(data.error);
};

/**
 * The one way to add something to the bag.
 *
 * The product page and the frequently-bought-together block each had their own
 * copy of this: they posted to /add, dispatched an event nobody listened for,
 * and opened the panel without ever applying the HTML that came back — so the
 * panel opened showing whatever was in it before. Everything now goes through
 * here, which opens, posts and repaints in one place.
 */
export async function addToCart(body) {
    if (config().openOnAdd !== false) {
        skeleton();
        open('cart');
    }

    return post('/add', body);
}

export function initCart() {
    document.addEventListener('click', async (event) => {
        // Add to cart — product cards, and later the product page.
        const add = event.target.closest('[data-kbb-add]');
        if (add) {
            /* The checkout's Browsed tab is the one place an Add must be
               silent: no drawer, no jump back to Order summary, just the line
               appearing in the summary already on screen. checkout.js owns that
               and binds data-kbb-checkout-add on the same button.

               Declining here is what makes that possible. app.js boots
               initCart() before initCheckout(), so this listener is registered
               first on document; stopPropagation() over there runs too late to
               stop it, and stopImmediatePropagation() only silences listeners
               added after. The scope has to be refused at this end.

               Nothing else changes: product cards, the shop grid, quick view,
               the PDP and the search fragment all still open the drawer. */
            if (add.closest('#kbbBrowsedList')) return;

            event.preventDefault();
            add.disabled = true;

            // Open the drawer FIRST, with a skeleton, then fill it when the
            // response lands. Waiting for the round trip before opening is what
            // made adding to the cart feel slow — the work was the same, but
            // nothing moved on screen until it finished.
            const data = await addToCart({
                product_id: Number(add.dataset.kbbAdd),
                variant_id: Number(add.dataset.kbbVariant) || null,
                quantity: Number(add.dataset.kbbQty) || 1,
            });

            add.disabled = false;
            // Only close when the server refused — sold out, or gone. A null
            // answer means the request itself failed, and shutting the panel on
            // top of that just hides the toast that explains it.
            if (data && data.ok === false) closeAll();
            return;
        }

        // Quantity — the theme uses data-kcq in the drawer and data-kcpq on the
        // cart page, both carrying a -1/+1 delta in data-d.
        const step = event.target.closest('[data-kcq], [data-kcpq]');
        if (step) {
            event.preventDefault();
            const id = Number(step.dataset.kcq || step.dataset.kcpq);
            const row = step.closest('.kc-item, .ci');
            const current = Number(row?.querySelector('.kc-qty span, .qty span')?.textContent || 1);
            await post('/update', { item_id: id, quantity: current + Number(step.dataset.d) });
            return;
        }

        const remove = event.target.closest('[data-kcrm], [data-kcprm]');
        if (remove) {
            event.preventDefault();
            await post('/remove', { item_id: Number(remove.dataset.kcrm || remove.dataset.kcprm) });
            return;
        }

        if (event.target.closest('[data-kcpcoupon]')) {
            event.preventDefault();
            const field = document.getElementById('kbbCartCoupon');
            if (field?.value.trim()) await post('/coupon', { code: field.value.trim() });
            return;
        }

        const rmCode = event.target.closest('[data-kcpremovecoupon]');
        if (rmCode) {
            event.preventDefault();
            await post('/coupon', { remove: true });
            return;
        }

        // The coupon hint's code is clickable in the theme.
        const hint = event.target.closest('.cohint [data-code]');
        if (hint) {
            event.preventDefault();
            await post('/coupon', { code: hint.dataset.code });
            return;
        }

        // Drawer tabs: Cart / Browsed.
        const tab = event.target.closest('[data-kctab]');
        if (tab) {
            event.preventDefault();
            openTab = tab.dataset.kctab;
            showTab();
            return;
        }

        // One-tap add from the Browsed tab, then back to the Cart tab.
        const badd = event.target.closest('.kc-badd');
        if (badd) {
            event.preventDefault();
            if (badd.disabled) return;

            const productId = Number(badd.dataset.add);
            const data = await post('/add', { product_id: productId, quantity: 1 });

            // Stay put. The plus has become a tick on this row — that is what
            // tells the shopper it is in the bag — so moving them to the Cart
            // list would take them away from the thing that says so. Pressing
            // again adds another.
            if (data?.ok && config().note !== false) noteAdded(productId);
        }
    });

    /* Enter in the coupon field should apply it, not submit anything.
     *
     * The id here was `cartCoupon`, which is not rendered anywhere in this
     * application: the cart page's field is `#kbbCartCoupon`
     * (store/cart-inner.blade.php) and the drawer has no coupon input at all.
     * So this listener matched nothing, ever — and the field is not inside a
     * form, so the browser had no fallback either. A shopper typed a discount
     * code, pressed Enter, and absolutely nothing happened. */
    document.addEventListener('keydown', async (event) => {
        if (event.key === 'Enter' && event.target.id === 'kbbCartCoupon') {
            event.preventDefault();
            if (event.target.value.trim()) await post('/coupon', { code: event.target.value.trim() });
        }
    });

    window.kbbRefreshCart = async () => {
        try {
            const r = await fetch(`${window.KBB.routes.cartApi}/drawer`, { headers: { Accept: 'application/json' } });
            const d = await r.json();
            const frag = fragment();
            if (frag && d.html) frag.outerHTML = d.html;
            setCartCount(d.count);
        } catch { /* a stale badge is not worth breaking the page over */ }
    };
}
