/**
 * Checkout interactions.
 *
 * Rule 27: one delegated listener, and delivery rates are only re-fetched when
 * the destination actually changes — not on every keystroke in the address.
 */

import { setCartCount } from './cart.js';

export function initCheckout() {
    const form = document.getElementById('kbbCheckoutForm');
    if (!form) return;

    // Summary / Browsed pill tabs.
    document.addEventListener('click', async (event) => {
        const tab = event.target.closest('[data-stab]');
        if (tab) {
            document.querySelectorAll('[data-stab]').forEach((t) => t.classList.toggle('on', t === tab));
            document.querySelectorAll('[data-spanel]').forEach((p) =>
                p.classList.toggle('on', p.dataset.spanel === tab.dataset.stab));
            return;
        }

        /*
         * Mobile: expand the collapsed summary.
         *
         * The class belongs on .summary, not on .panels. Both rules that
         * actually implement the expansion are written against the summary --
         * `.summary.open .panels{max-height:1600px}` and
         * `.summary.open .peekfade{display:none}` -- so toggling `open` on
         * #kbbPanels set a class no selector matches. The button was live and
         * this handler did run; the panel simply stayed clipped at its 148px
         * peek under the fade, which is indistinguishable from a dead control.
         *
         * It took the Browsed tab down with it. That tab swaps panels inside
         * this same clipped box, so tapping it moved the pill and then showed
         * a list cut off after the first item and a half, with no way to open
         * the rest.
         */
        const viewToggle = event.target.closest('#kbbViewItems');
        if (viewToggle) {
            const opened = document.getElementById('kbbSummary')?.classList.toggle('open') ?? false;
            viewToggle.textContent = opened ? 'Hide full summary ▴' : 'View full summary ▾';
            viewToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
            return;
        }

        // Place order — both the summary button and the sticky bar submit the
        // one real form, so there is a single submission path.
        if (event.target.closest('[data-place]')) {
            event.preventDefault();
            if (form.reportValidity()) form.submit();
            return;
        }

        /*
         * Quantity steppers inside the summary.
         *
         * These used to post to the cart API and then reload the whole page.
         * That endpoint returns the drawer and the cart page — neither of which
         * is on screen here — so there was nothing to swap in, and a full
         * navigation was the only way to show the new figures. It cost every
         * field already typed into the form, the scroll position, and the
         * country the shopper had chosen.
         *
         * /checkout/line returns exactly the regions this page shows, so the
         * number changes where it was pressed and nothing else moves.
         */
        const q = event.target.closest('.co-q');
        if (q) {
            event.preventDefault();
            const row = q.closest('.ci');
            const current = Number(row?.querySelector('.qty span')?.textContent || 1);
            await changeLine(q, Number(q.dataset.key), current + Number(q.dataset.d));
            return;
        }

        // Remove a line entirely — the same change with a quantity of zero,
        // through the same endpoint. If this was the last item there is no
        // checkout left to repaint, and the server names /cart/ as where to go.
        const rm = event.target.closest('.co-rm');
        if (rm) {
            event.preventDefault();
            await changeLine(rm, Number(rm.dataset.key), 0);
            return;
        }

        /*
         * One-tap add from the Browsed panel.
         *
         * This branch used to read `.badd` with `data-add`. The markup has
         * always rendered `.baddbtn` with `data-kbb-add`, so the selector
         * matched nothing and the handler never ran even once — what actually
         * added the product was cart.js's global listener, which opens the
         * drawer. That is right everywhere else on the site and wrong here.
         */
        const badd = event.target.closest('[data-kbb-checkout-add]');
        if (badd) {
            event.preventDefault();
            await addBrowsed(badd);
            return;
        }

        // Coupon.
        if (event.target.closest('#kbb_apply_coupon')) {
            event.preventDefault();
            const field = document.getElementById('kbb_coupon_code');
            if (field?.value.trim()) await changeCoupon(field.value.trim(), false);
            return;
        }

        const hint = event.target.closest('.hint [data-code]');
        if (hint) {
            event.preventDefault();
            await changeCoupon(hint.dataset.code, false);
        }
    });

    /*
     * No reload on the address fields.
     *
     * Emirate is a free-text field, and a text input fires `change` when it
     * loses focus — so typing an emirate and tabbing away reloaded the whole
     * page and threw away everything already entered. Rates do not vary by
     * emirate: the shipping zones and Extended's countries are both keyed on
     * country alone, so nothing needs recalculating when it changes and
     * nothing reloads.
     *
     * Country is different since 2.51.0 — it is a real, changeable select,
     * and the whole delivery charge depends on it. That still does not mean
     * reloading: a full reload re-runs page()'s own country detection from
     * scratch and would throw away the very choice the shopper just made, along
     * with everything else already typed into the form. Instead this fetches
     * new rates for the chosen country and swaps in the delivery list and the
     * totals — the same fragments a reload would have shown, without losing
     * anything.
     */
    const countryField = document.getElementById('billing_country');

    countryField?.addEventListener('change', async () => {
        const slot = document.getElementById('kbbDeliverySlot');
        const state = document.getElementById('billing_state')?.value || '';

        if (slot) slot.innerHTML = '<div class="kbb-delivery-loading">Loading delivery options…</div>';

        try {
            const response = await fetch(window.KBB.routes.checkoutRates, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ country: countryField.value, state }),
            });
            const data = await response.json();

            if (!data.ok) {
                window.kbbToast?.(data.error || 'Could not update delivery for that country.');
                return;
            }

            if (slot) slot.innerHTML = data.deliveryHtml;

            // The free-shipping bar is per-country too — its threshold, its
            // percentage, and whether it reads as unlocked. Left alone here it
            // kept showing "unlocked" after switching to a country that still
            // charges for delivery, because the bar itself never moved when
            // the price above it did.
            document.querySelectorAll('.kbb-freeship-slot').forEach((el) => { el.innerHTML = data.freeshipHtml; });

            // Two copies of the totals exist on this page — the summary
            // column and the mobile box — so every match is updated, not just
            // the first found.
            document.querySelectorAll('.js-shipping').forEach((el) => { el.innerHTML = data.shipping; });
            document.querySelectorAll('.js-total').forEach((el) => { el.innerHTML = data.total; });
            // The Cash-on-delivery fee is flat and does not change with
            // country, but the total shown beside it does — kept in step so
            // switching country while COD is selected never shows a stale
            // fee-inclusive figure.
            if (data.totalWithFee) {
                document.querySelectorAll('.js-total-fee').forEach((el) => { el.innerHTML = data.totalWithFee; });
            }
            document.querySelectorAll('.js-subtotal').forEach((el) => { el.innerHTML = data.subtotal; });

            document.querySelectorAll('.js-vat').forEach((el) => {
                const row = el.closest('.sumrow');
                if (data.vat) {
                    el.innerHTML = data.vat.formatted;
                    if (row) row.style.display = '';
                } else if (row) {
                    row.style.display = 'none';
                }
            });
        } catch {
            window.kbbToast?.('Could not update delivery for that country — please try again.');
            if (slot) slot.innerHTML = '<div class="kbb-delivery-loading">Loading delivery options…</div>';
        }
    });

    /* ------------------------------------------------------------------ *
     * Browsed → bag, without moving anything the shopper is looking at.
     * ------------------------------------------------------------------ */

    /* Product ids with a request in flight. A second tap on the same row is
       ignored rather than queued: the intent of a double tap on "Add" is one
       product, not two, and the button is disabled for the round trip anyway —
       this covers the keyboard, the synthetic click and the impatient thumb
       that lands before `disabled` is painted. */
    const adding = new Set();

    let noteTimer = null;

    const addUrl = () => document.getElementById('kbbBrowsedList')?.dataset.addUrl || '';

    /* A failure is never silent and never costs the row. The message goes on
       the row itself, in words, and the product stays listed so the tap can be
       made again. */
    const rowError = (btn, message) => {
        const row = btn.closest('.bitem');

        if (!row) {
            window.kbbToast?.(message);
            return;
        }

        let err = row.querySelector('.berr');

        if (!err) {
            err = document.createElement('p');
            err.className = 'berr';
            err.setAttribute('role', 'alert');
            row.appendChild(err);
        }

        err.textContent = message;
    };

    const clearRowError = (btn) => { btn.closest('.bitem')?.querySelector('.berr')?.remove(); };

    /* Small, brief, and it costs no height — .baddnote is absolutely positioned
       in the Browsed heading, so nothing on the page moves when it appears.
       Writing into a role=status/aria-live=polite element is what announces it
       to a screen reader; the fade is CSS, and the CSS drops it under
       prefers-reduced-motion. */
    const noteAdded = () => {
        const note = document.getElementById('kbbBrowsedNote');
        if (!note) return;

        note.textContent = 'Added';
        note.classList.add('on');

        clearTimeout(noteTimer);
        noteTimer = setTimeout(() => {
            note.classList.remove('on');
            note.textContent = '';
        }, 1600);
    };

    /* Everything here is a string the server rendered or an integer it counted.
       No price is read out of the DOM and nothing is added up.

       Shared by the Browsed one-tap add and the summary's quantity steppers:
       both change what is in the bag, and both move the same regions. One
       applier, so the two cannot drift apart. */
    const applyFragments = (data) => {
        // The Browsed list and its badge come from one server-side collection,
        // so the row leaves only because the server put the product in the bag.
        const list = document.getElementById('kbbBrowsedList');
        if (list && typeof data.browsedHtml === 'string') list.innerHTML = data.browsedHtml;

        if (typeof data.browsedCount === 'number') {
            document.querySelectorAll('.bcount').forEach((el) => { el.textContent = String(data.browsedCount); });
        }

        const items = document.querySelector('#kbbSummary .co-items');
        if (items && data.itemsHtml) items.innerHTML = data.itemsHtml;

        // Both copies — the desktop summary and the mobile place-order box.
        // The order block opens with the free-delivery bar, so the bar, the
        // subtotal, the delivery line and every total move together.
        if (data.orderHtml) {
            document.querySelectorAll('.kbb-order-slot').forEach((el) => { el.innerHTML = data.orderHtml; });
        }

        // The mobile bag strip: a new thumbnail, a new ×n badge, a new count.
        if (typeof data.thumbsHtml === 'string') {
            document.querySelectorAll('.kbb-thumbs-slot').forEach((el) => { el.innerHTML = data.thumbsHtml; });
        }

        // The payment options, re-rendered against the new total by the same
        // request. Cash on delivery can leave or return as the total crosses
        // the PayShipRules window, and the selection is carried across when the
        // method is still offered.
        const payment = document.getElementById('payment');
        if (payment && data.paymentHtml) payment.innerHTML = data.paymentHtml;

        // The optional sticky bar keeps a total of its own, outside every slot.
        if (data.total) {
            document.querySelectorAll('.mpbar .js-total').forEach((el) => { el.innerHTML = data.total; });
        }

        // Both badges — the header one and the mobile tab bar's, which is the
        // only one a phone can see.
        setCartCount(data.count);

        // The panel body itself, through the drawer endpoint that already
        // exists — one renderer for the drawer rather than a second copy of
        // CartController's payload growing in the checkout controller. It is
        // closed while this runs, so the swap is invisible; it simply must not
        // be stale the next time the header cart is opened.
        window.kbbRefreshCart?.();
    };

    const applyBrowsedAdd = (data) => {
        applyFragments(data);
        noteAdded();
    };

    /* ------------------------------------------------------------------ *
     * Quantity and remove, in place.
     * ------------------------------------------------------------------ */

    /* The endpoint, already prefixed for this deployment — published by the
       page itself, the same way the Browsed list publishes its own. The script
       never builds a path, so a subdirectory install cannot produce one that
       escapes the app. */
    const lineUrl = () => window.KBB?.routes?.checkoutLine || '';
    const couponUrl = () => window.KBB?.routes?.checkoutCoupon || '';

    /* One line at a time. Two presses of + in quick succession are two writes
       in the order they were made, not a race the server has to referee, and
       the second lands against the quantity the first returned rather than the
       one still painted on screen. */
    let lineChain = Promise.resolve();

    async function sendLine(itemId, quantity) {
        const url = lineUrl();
        if (!url) return null;

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.KBB.csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                item_id: itemId,
                quantity: Math.max(0, Math.min(99, quantity)),
                country: document.getElementById('billing_country')?.value || '',
                state: document.getElementById('billing_state')?.value || '',
                // A choice, not an amount. Crossing the Cash-on-delivery window
                // in either direction is the server's call, not the browser's.
                payment_method: document.querySelector('input[name="payment_method"]:checked')?.value || '',
            }),
        });

        // A 419 from an expired session, a 500, or anything else that is not
        // JSON: a sentence, not a dead button.
        return response.json().catch(() => null) ?? null;
    }

    async function changeLine(btn, itemId, quantity) {
        if (!itemId || btn.disabled) return;

        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');

        const run = lineChain.then(() => sendLine(itemId, quantity));
        lineChain = run.catch(() => {});

        try {
            const data = await run;

            if (!data || data.ok !== true) {
                window.kbbToast?.((data && data.error) || 'Could not update your bag — please try again.');
                return;
            }

            // The last line has gone, so there is no checkout left to show.
            // The server names where to go; this is the only navigation that
            // remains, and it is a real change of page rather than a repaint.
            if (data.empty) {
                setCartCount(0);
                if (data.redirect) window.location.assign(data.redirect);
                return;
            }

            applyFragments(data);
        } catch {
            window.kbbToast?.('No connection — please try again.');
        } finally {
            // On success this button has already been replaced along with the
            // rest of the summary, so this lands on a detached node and costs
            // nothing.
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }

    /* Same shape as changeLine: the server returns this page's own fragments,
       so applying a code repaints in place instead of reloading and throwing
       away every field already typed. Falls back to the old reload-based path
       when the route is not published (an older cached layout). */
    async function changeCoupon(code, remove) {
        const url = couponUrl();
        if (!url) return post('/coupon', remove ? { remove: true } : { code });

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    code: code || '',
                    remove: !!remove,
                    country: document.getElementById('billing_country')?.value || '',
                    state: document.getElementById('billing_state')?.value || '',
                    payment_method: document.querySelector('input[name="payment_method"]:checked')?.value || '',
                }),
            });

            const data = await response.json().catch(() => null);

            if (!data) {
                window.kbbToast?.('Could not apply that code — please try again.');
                return;
            }

            // A rejected code still carries fragments, rendered from the
            // unchanged totals, so the error is shown beside current figures.
            // orderHtml is the one region fragments() always returns.
            if (typeof data.orderHtml === 'string') applyFragments(data);

            if (data.ok !== true) {
                window.kbbToast?.(data.error || 'That code could not be applied.');
                return;
            }

            if (data.message) window.kbbToast?.(data.message);
        } catch {
            window.kbbToast?.('No connection — please try again.');
        }
    }

    async function addBrowsed(btn) {
        const id = Number(btn.dataset.kbbCheckoutAdd);
        if (!id || btn.disabled || adding.has(id)) return;

        const url = addUrl();
        if (!url) return;

        adding.add(id);
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        clearRowError(btn);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    product_id: id,
                    country: document.getElementById('billing_country')?.value || '',
                    state: document.getElementById('billing_state')?.value || '',
                    // A choice, not an amount. The server decides whether it is
                    // still on offer at the new total.
                    payment_method: document.querySelector('input[name="payment_method"]:checked')?.value || '',
                }),
            });

            // A 419 from an expired session, a 500, or anything else that is
            // not JSON: still a sentence on the row rather than a dead button.
            const data = await response.json().catch(() => null);

            if (!data || data.ok !== true) {
                rowError(btn, (data && data.error)
                    || (response.status === 419
                        ? 'Your session expired — please reload the page.'
                        : 'Could not add that just now — please try again.'));
                return;
            }

            applyBrowsedAdd(data);
        } catch {
            rowError(btn, 'No connection — please try again.');
        } finally {
            adding.delete(id);
            // On success this button has already been replaced with the rest of
            // the list, so this lands on a detached node and costs nothing.
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }

    async function post(path, body) {
        try {
            const response = await fetch(`${window.KBB.routes.cartApi}${path}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify(body),
            });
            const data = await response.json();
            if (data.error) { window.kbbToast?.(data.error); return; }
            /* Coupons only, now that the steppers have an endpoint that returns
               this page's own fragments. A coupon changes the line discounts,
               the totals, the free-delivery bar and which payment methods are
               offered, and /api/cart/coupon renders none of those — so until it
               does, a reload is the honest way to keep every copy in step. */
            window.location.reload();
        } catch {
            window.kbbToast?.('Something went wrong — please try again.');
        }
    }
}
