/**
 * Checkout interactions.
 *
 * Rule 27: one delegated listener, and delivery rates are only re-fetched when
 * the destination actually changes — not on every keystroke in the address.
 */

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

        // Quantity steppers inside the summary.
        const q = event.target.closest('.co-q');
        if (q) {
            event.preventDefault();
            const row = q.closest('.ci');
            const current = Number(row?.querySelector('.qty span')?.textContent || 1);
            await post('/update', { item_id: Number(q.dataset.key), quantity: current + Number(q.dataset.d) });
            return;
        }

        // Remove a line entirely. Same endpoint and reload-after pattern as
        // the quantity steppers above — one convention for every change that
        // touches the cart on this page. If this was the last item, page()
        // itself redirects to /cart/ on reload; nothing extra to handle here.
        const rm = event.target.closest('.co-rm');
        if (rm) {
            event.preventDefault();
            await post('/remove', { item_id: Number(rm.dataset.key) });
            return;
        }

        // One-tap add from the Browsed panel.
        const add = event.target.closest('.badd');
        if (add) {
            event.preventDefault();
            await post('/add', { product_id: Number(add.dataset.add), quantity: 1 });
            return;
        }

        // Coupon.
        if (event.target.closest('#kbb_apply_coupon')) {
            event.preventDefault();
            const field = document.getElementById('kbb_coupon_code');
            if (field?.value.trim()) await post('/coupon', { code: field.value.trim() });
            return;
        }

        const hint = event.target.closest('.hint [data-code]');
        if (hint) {
            event.preventDefault();
            await post('/coupon', { code: hint.dataset.code });
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
            // The summary, totals and mobile box all come from the server, so a
            // reload is the honest way to keep every copy in step.
            window.location.reload();
        } catch {
            window.kbbToast?.('Something went wrong — please try again.');
        }
    }
}
