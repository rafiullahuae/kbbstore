/**
 * Checkout interactions.
 *
 * Rule 27: one delegated listener, and delivery rates are only re-fetched when
 * the destination actually changes — not on every keystroke in the address.
 */

import { setCartCount } from './cart.js';
import { t, esc } from './i18n.js';

/**
 * The placeholder that fills the delivery slot while the rates are in flight.
 *
 * One function rather than the same string typed twice: the two call sites were
 * a literal each, which is how the two halves of one message end up saying
 * different things a release apart. esc() because the wording is an owner's to
 * correct and this builds markup out of it.
 */
const deliveryLoading = () =>
    '<div class="kbb-delivery-loading">'
    + esc(t('store.checkout.delivery_loading', 'Loading delivery options…'))
    + '</div>';

export function initCheckout() {
    const form = document.getElementById('kbbCheckoutForm');
    if (!form) return;

    window.kbbDiag?.('checkout.js', 'handler attached');

    rememberDetails(form);

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
            viewToggle.textContent = opened
                ? t('store.checkout.view_summary_close', 'Hide full summary ▴')
                : t('store.checkout.view_summary_open', 'View full summary ▾');
            viewToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
            return;
        }

        /*
         * Place order — both the summary button and the sticky bar submit the
         * one real form, so there is a single submission path.
         *
         * WHY THE INVALID FIELD IS TAKEN IN HAND HERE.
         *
         * `reportValidity()` alone is supposed to scroll the first invalid
         * control into view and focus it. On this page, at a phone viewport, it
         * does neither. Measured in Chromium at 390x844 with every field filled
         * but the email: pressing Place order took the page from scrollY 1868
         * to scrollY 0, left #billing_email at 883px — below the fold of an
         * 844px viewport — and left document.activeElement on <body>. So the
         * shopper tapped the button, the page jumped somewhere else, nothing
         * was highlighted, nothing was focused, and no order was placed. A
         * button that appears to do nothing is the single most expensive thing
         * a checkout can do.
         *
         * The cause is structural and is why this cannot be left to the
         * browser: the button lives in .kbb-mobile-order, which is rendered
         * AFTER the form fields in the document, so the control that needs
         * attention is always far above the one being pressed.
         *
         * scrollIntoView first, then focus. focus() alone scrolls too, but it
         * scrolls the minimum distance, which parks the field under the sticky
         * header; 'center' puts it where a person is looking. reportValidity()
         * is still called, and still last, so the browser's own bubble lands on
         * a field that is by then on screen.
         */
        if (event.target.closest('[data-place]')) {
            event.preventDefault();
            window.kbbDiag?.('checkout.js', 'saw the press');
            // The overlay's one answer, then the fallback (Lane PO hotfix).
            const check = window.KBB?.placeCheck;
            if (check && !check(event.target, 'checkout.js')) return;
            const invalid = form.querySelector(':invalid');

            if (invalid) {
                /* 'instant', not 'smooth'. kbb.css sets `html{scroll-behavior:
                   smooth}` globally, so an animated scroll is still in flight
                   when focus() and reportValidity() run a line later — and
                   reportValidity() then does its OWN minimal scroll, which
                   top-aligns the field under the sticky .co-head and undoes the
                   centring. Instant finishes first, so by the time the browser
                   looks, the field is already where it should be and it has
                   nothing to correct. The clearance under the sticky header is
                   scroll-margin-top in kbb-checkout.css, which is the property
                   for exactly this and also fixes anchor jumps. */
                invalid.scrollIntoView({ block: 'center', behavior: 'instant' });
                invalid.focus({ preventScroll: true });
                form.reportValidity();
                return;
            }

            if (form.reportValidity()) {
                /* A plain post sends the hidden _token, which Firefox may have
                   restored from before a login (stale: 419). This load's token
                   is in window.KBB.csrf, which no browser restores. */
                const token = form.querySelector('input[name="_token"]');
                if (token && window.KBB && window.KBB.csrf) token.value = window.KBB.csrf;
                form.submit();
            }
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
            await applyTyped();
            return;
        }

        // Remove, from the applied line under the box (Lane CP).
        if (event.target.closest('[data-kbb-coupon-remove]')) {
            event.preventDefault();
            await changeCoupon('', true);
            return;
        }

        // The coupon line at the top (Lane QK6): the same in-place apply as
        // typing the code into the box and pressing Apply -- the code goes in
        // the box too, so the box, the totals and the summary row all say it.
        const cline = event.target.closest('[data-kbb-cline]');
        if (cline) {
            event.preventDefault();
            await applyFromLine(cline);
            return;
        }

        const hint = event.target.closest('.hint [data-code]');
        if (hint) {
            event.preventDefault();
            // Into the box as well, so a refusal leaves the code there to fix.
            const field = document.getElementById('kbb_coupon_code');
            if (field) field.value = hint.dataset.code;
            await changeCoupon(hint.dataset.code, false);
        }
    });

    /*
     * ENTER IN THE COUPON BOX CAN NEVER PLACE THE ORDER (Lane CP).
     *
     * The box sits inside #kbbCheckoutForm, and two listeners on that form
     * (placing-overlay and stripe-elements) answer ANY `submit` by placing the
     * order -- deliberately, "Enter inside a field submits the form". Today the
     * keydown handler below stops Enter before the browser gets that far, and
     * the form has no submit button for implicit submission to use. Neither is
     * a guarantee: a phone keyboard's Go key, an autofill helper or a future
     * submit button in the form reaches `submit` without a keydown Enter, and
     * the shopper who meant "apply SAVE10" would have an order placed. Measured
     * in Chromium: form.requestSubmit() with the coupon box focused posted
     * /checkout/place.
     *
     * Capture on the DOCUMENT, so this runs before the form's own capture
     * listeners whatever order the scripts loaded in. Only a submit with no
     * submitter while the coupon box has focus is taken; Place order is a
     * type=button and never arrives here.
     */
    document.addEventListener('submit', (event) => {
        if (event.target !== form || event.submitter) return;
        if (document.activeElement?.id !== 'kbb_coupon_code') return;

        event.preventDefault();
        event.stopImmediatePropagation();
        applyTyped();
    }, true);

    /*
     * Enter in the discount-code field applies the code.
     *
     * There was no handler for this at all, and the field sits inside
     * #kbbCheckoutForm — a form with no submit button, so the browser's own
     * implicit submission declines too (more than one field blocks it). The
     * result was the most natural gesture on the page doing nothing whatever:
     * type GLOW30, press Enter, no discount, no error, no movement. Confirmed
     * in Chromium at 390 and 1280 — Apply worked, Enter did not.
     *
     * preventDefault regardless of whether the field has anything in it, so an
     * empty field can never become a stray form submission either.
     */
    document.addEventListener('keydown', async (event) => {
        if (event.key !== 'Enter' || event.target.id !== 'kbb_coupon_code') return;

        event.preventDefault();

        await applyTyped();
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

        if (slot) slot.innerHTML = deliveryLoading();

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
                window.kbbToast?.(data.error || t('store.js.delivery_failed', 'Could not update delivery for that country.'));
                return;
            }

            if (slot) slot.innerHTML = data.deliveryHtml;

            // "Free delivery over AED 199" beside the heading: the UAE only,
            // so it follows the country (Lane QK6). Server-rendered markup --
            // the wording escaped there, the amount Money::format() output.
            if (typeof data.deliveryNote === 'string') {
                const note = document.getElementById('kbbDeliveryNote');
                if (note) {
                    note.innerHTML = data.deliveryNote;
                    note.hidden = data.deliveryNote === '';
                }
            }

            // The free-shipping bar is per-country too — its threshold, its
            // percentage, and whether it reads as unlocked. Left alone here it
            // kept showing "unlocked" after switching to a country that still
            // charges for delivery, because the bar itself never moved when
            // the price above it did.
            document.querySelectorAll('.kbb-freeship-slot').forEach((el) => { el.innerHTML = data.freeshipHtml; });

            // The delivery promise under Place order is per-country as well.
            // textContent, not innerHTML: this is operator-supplied copy and
            // this file has no business turning it back into markup. An empty
            // answer hides the line rather than leaving an empty truck icon.
            if (typeof data.deliveryText === 'string') {
                document.querySelectorAll('.kbb-delivery-line').forEach((el) => {
                    el.hidden = data.deliveryText === '';
                    const span = el.querySelector('span');
                    if (span) span.textContent = data.deliveryText;
                });
            }

            // SAFE. Every *Html value on this endpoint is a Blade view the
            // server rendered, and `shipping` / `total` / `subtotal` /
            // `totalWithFee` / `vat.formatted` are Money::format() output,
            // which IS markup — `<span class="woocommerce-Price-amount">` with
            // an already-escaped currency symbol inside it. Escaping any of
            // them here would print the tags. The two values on this endpoint
            // that are NOT markup — `vat.label` and `deliveryText`, both
            // operator copy out of the settings table — go through textContent
            // above, which is the rule this file already follows.
            //
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

            // The VAT line, both halves of it. The rate can differ by country,
            // and the LABEL is where the percentage is printed — vat_label is
            // "You're paying VAT ({rate}%)". Updating only the amount was
            // invisible while one rate applied everywhere; with a per-country
            // rate set it left "You're paying VAT (5%)" sitting beside the 15%
            // figure. The endpoint has always sent the label; nothing read it.
            // THE LINE CAN MOVE, NOT ONLY CHANGE. The page carries two VAT
            // rows: `.vat-add` above the Total, where an exclusive tax belongs
            // because it is part of the sum, and `.vat-note` below it, where
            // an inclusive or printed-only figure belongs because it is a
            // portion OF the total. Exactly one is ever shown, and which one
            // is a property of the DESTINATION — so changing country from an
            // inclusive one to an exclusive one has to swap them. Leaving the
            // note in place under an exclusive total would print a column of
            // figures that does not add up to what is being charged.
            const vatAdded = !!(data.vat && data.vat.added);

            //
            // THE `hidden` ATTRIBUTE, not an inline style — Lane DE.
            //
            // This used to set `style.display`, and had to: `.kbb-checkout
            // .sumrow{display:flex}` is an author rule, and an author rule
            // beats the user agent's `[hidden]{display:none}` whatever its
            // specificity, so the attribute did nothing on this page.
            // store/checkout.blade.php now ships
            // `.kbb-checkout [hidden]{display:none!important}`, which outranks
            // an inline declaration as well, so the attribute is both the
            // clearer mechanism and the stronger one. The gift row and the
            // delivery line above already use it; this is the page hiding
            // things exactly one way.
            //
            // The inline display is REMOVED rather than blanked, so a row
            // rendered by a build that predates this one — an update package
            // applied without a rebuilt bundle — is still governed by the
            // attribute alone and cannot be left half-hidden by both.
            document.querySelectorAll('.js-vat-row').forEach((row) => {
                const wanted = !!data.vat && row.classList.contains('vat-add') === vatAdded;
                row.hidden = !wanted;
                row.style.removeProperty('display');
            });

            document.querySelectorAll('.js-vat').forEach((el) => {
                if (data.vat) el.innerHTML = data.vat.formatted;
            });

            // textContent, not innerHTML: this is operator-supplied copy out of
            // the settings table, and this file has no business turning it back
            // into markup — the same rule the delivery line above follows.
            if (data.vat && typeof data.vat.label === 'string') {
                document.querySelectorAll('.js-vat-label').forEach((el) => {
                    el.textContent = data.vat.label;
                });
            }

            // Lane QK12: with "Show VAT amount to customers" off the note row
            // carries only .js-vat-short -- no .js-vat for an amount to land
            // in -- and its sentence follows the new country's rate.
            if (data.vat && typeof data.vat.short === 'string') {
                document.querySelectorAll('.js-vat-short').forEach((el) => {
                    el.textContent = data.vat.short;
                });
            }
        } catch {
            window.kbbToast?.(t('store.js.delivery_retry', 'Could not update delivery for that country — please try again.'));
            if (slot) slot.innerHTML = deliveryLoading();
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

        note.textContent = t('store.js.added', 'Added');
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

        // The delivery options, re-quoted against the new basket: a quantity
        // can cross the free-delivery line (Lane QK6). The server picks the
        // first rate, which is the one the totals beside it were priced on.
        const slot = document.getElementById('kbbDeliverySlot');
        if (slot && typeof data.deliveryHtml === 'string') slot.innerHTML = data.deliveryHtml;

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
                'X-KBB-Hm': String(Math.round(performance.now())), // (Lane CT) see cart.js
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
                window.kbbToast?.((data && data.error) || t('store.js.bag_failed', 'Could not update your bag — please try again.'));
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

    /*
     * THE COUPON, IN PLACE (Lane CP).
     *
     * Same shape as changeLine: the server answers with this page's own
     * fragments and only those are swapped -- the order block, the mobile bag
     * strip, the sticky bar's total, and #payment ONLY when the new total
     * changes which methods are offered (the page tells it which are shown).
     * Every field the shopper typed, the chosen delivery and payment method and
     * any card element mounted in #payment stay exactly where they are.
     *
     * The answer is said under the box, in words the server wrote in the
     * shopper's language -- never a toast that is gone in 1.7 seconds, never an
     * alert. A refused code stays in the box to be corrected.
     *
     * No reload on any path. The fallback that used to post to the cart's own
     * coupon endpoint and then reload the page (when this route was not
     * published) is gone: a reload is exactly what throws away everything the
     * shopper typed, which is the complaint this answers.
     */
    let couponBusy = false;

    const couponMsg = () => {
        let el = document.getElementById('kbbCouponMsg');
        if (el) return el;

        const row = form.querySelector('.coupon .crow');
        if (!row) return null;

        el = document.createElement('p');
        el.id = 'kbbCouponMsg';
        el.className = 'co-cmsg is-new';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        // One line held open while the answer is on its way, made in the same
        // task as the tap, so the box grows with the gesture and not a beat
        // later when the answer lands (that would count as a layout shift).
        el.innerHTML = '&nbsp;';
        row.after(el);

        return el;
    };

    /* kind: 'ok' | 'err'. `html` is markup the server rendered (the applied
       line and its Remove, every value in it escaped there); `text` goes in as
       text and nothing else. */
    const sayCoupon = (kind, text, html) => {
        const el = couponMsg();
        if (!el) return;

        el.classList.remove('is-new', 'is-busy');
        el.classList.toggle('is-err', kind === 'err');
        el.classList.toggle('is-ok', kind === 'ok');

        if (typeof html === 'string' && html.trim() !== '') el.innerHTML = html;
        else el.textContent = text || '';
    };

    async function applyTyped() {
        const field = document.getElementById('kbb_coupon_code');
        const code = field?.value.trim() || '';

        if (!code) { field?.focus(); return; }

        await changeCoupon(code, false);
    }

    /*
     * The coupon line's pill (Lane QK6). A busy ring on the pill while the
     * answer is on its way; then "Coupon GLOW applied" in the line, or the
     * checkout's own reason under it. couponBusy refuses a second tap, and
     * aria-disabled rather than `disabled` keeps a keyboard user's focus.
     */
    async function applyFromLine(pill) {
        if (couponBusy) return;

        const code = pill.dataset.kbbCline || '';
        const line = document.getElementById('kbbCline');
        const err = line?.querySelector('.co-cl-err');
        const field = document.getElementById('kbb_coupon_code');

        if (field) field.value = code;
        if (err) { err.hidden = true; err.textContent = ''; }
        pill.setAttribute('aria-busy', 'true');
        pill.setAttribute('aria-disabled', 'true');

        try {
            const ok = await changeCoupon(code, false);
            if (!ok && err) {
                err.textContent = document.getElementById('kbbCouponMsg')?.textContent.trim() || '';
                err.hidden = err.textContent === '';
            }
        } finally {
            pill.removeAttribute('aria-busy');
            pill.removeAttribute('aria-disabled');
        }
    }

    /* The line follows the coupon on the order, whichever way it got there:
       applied from the line or typed into the box, removed with Remove. */
    const syncLine = (applied) => {
        const line = document.getElementById('kbbCline');
        const pill = line?.querySelector('[data-kbb-cline]');
        if (!line || !pill) return;

        const on = applied.toUpperCase() === (pill.dataset.kbbCline || '').toUpperCase();
        line.classList.toggle('is-done', on);
        if (on) {
            const err = line.querySelector('.co-cl-err');
            if (err) { err.hidden = true; err.textContent = ''; }
            // Written again once visible, so a screen reader announces it.
            const done = line.querySelector('.co-cl-donetxt');
            if (done) done.textContent = done.textContent;
            // Focus was on the pill, which is now hidden: keep the place.
            const doneLine = line.querySelector('.co-cl-done');
            if (doneLine && document.activeElement === pill) {
                doneLine.setAttribute('tabindex', '-1');
                doneLine.focus({ preventScroll: true });
            }
        }
    };

    async function changeCoupon(code, remove) {
        // One request at a time: a double tap, Enter then Apply, or Apply
        // while Remove is on its way is one change, not two racing writes.
        // Answers whether the change was made (Lane QK6's line reads it).
        if (couponBusy) return false;

        const field = document.getElementById('kbb_coupon_code');
        const btn = document.getElementById('kbb_apply_coupon');
        const url = couponUrl();

        couponBusy = true;
        // aria-disabled, not `disabled`: disabling the button that has focus
        // throws focus to <body>, which loses a keyboard shopper's place --
        // and Chrome then scrolled the coupon box (an overflow:hidden scroller,
        // for its decorative circle) 30px sideways, cutting the gift icon off.
        // couponBusy is what refuses the second press.
        btn?.setAttribute('aria-busy', 'true');
        btn?.setAttribute('aria-disabled', 'true');
        couponMsg()?.classList.add('is-busy');

        try {
            if (!url) throw new Error('no coupon route');

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
                    // Which methods are on screen, so an unchanged list is
                    // left alone. Ids only; the server decides what is offered.
                    offered: [...document.querySelectorAll('#payment input[name="payment_method"]')].map((r) => r.value),
                }),
            });

            const data = await response.json().catch(() => null);

            if (!data || data.ok !== true) {
                const said = data && typeof data.error === 'string' ? data.error : '';
                sayCoupon('err', said
                    || (response.status === 429 ? t('store.js.coupon_slow_down', 'Too many tries — please wait a minute and try again.')
                        : response.status === 419 ? t('store.js.session_expired', 'Your session expired — please reload the page.')
                            : t('store.js.coupon_failed', 'Could not apply that code — please try again.')));
                // Only a refusal of the code itself marks the box; a dropped
                // connection says nothing about what was typed.
                if (said && field && !remove) field.setAttribute('aria-invalid', 'true');
                return false;
            }

            if (typeof data.orderHtml === 'string') applyFragments(data);

            field?.removeAttribute('aria-invalid');
            if (remove && field) field.value = '';
            sayCoupon('ok', data.message || '', data.couponHtml);
            syncLine(remove ? '' : code);
            return true;
        } catch {
            sayCoupon('err', t('store.js.coupon_failed', 'Could not apply that code — please try again.'));
            return false;
        } finally {
            couponBusy = false;
            btn?.removeAttribute('aria-busy');
            btn?.removeAttribute('aria-disabled');
            document.getElementById('kbbCouponMsg')?.classList.remove('is-busy');
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
                    'X-KBB-Hm': String(Math.round(performance.now())), // (Lane CT) see cart.js
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
                        ? t('store.js.session_expired', 'Your session expired — please reload the page.')
                        : t('store.js.add_failed', 'Could not add that just now — please try again.')));
                return;
            }

            applyBrowsedAdd(data);
        } catch {
            rowError(btn, t('store.js.no_connection', 'No connection — please try again.'));
        } finally {
            adding.delete(id);
            // On success this button has already been replaced with the rest of
            // the list, so this lands on a detached node and costs nothing.
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
        }
    }

    /* ------------------------------------------------------------------ *
     * The sold-out dialog (Lane CO).
     *
     * Place order used to answer a sold-out line with red text under the card
     * fields; the owner asked for "a proper popup with remove from the list".
     * Both callers — the card form (stripe-elements) and the overlay that
     * places every other method (placing-overlay) — hand the refusal to
     * window.KBB.refused(body) below and keep their red text when it answers
     * false (an older bundle, or a browser with no <dialog>).
     *
     * A NATIVE <dialog> OPENED WITH showModal(): the rest of the page is inert
     * while it is up (the focus trap), Esc closes it (`cancel`), focus goes
     * back where it was on close, and the backdrop is the browser's. No
     * library, no measuring: the size is CSS (min() and dvh), and the markup
     * and its few rules are built on first use only, so a checkout that never
     * meets a sold-out line carries no byte of it.
     *
     * Every sentence arrives in `body` already in the shopper's language
     * (CheckoutController::soldOutAnswer()) and goes in through textContent.
     * ------------------------------------------------------------------ */
    /*
     * (Lane CO2, the owner's design) Each line: the picture, the product in
     * the shop's ink, a size smaller than before, and "is sold out" in the
     * sale red. The confirmation is a long frosted capsule -- the footer app
     * capsule's glass (.kfa-g: blur 12 + saturate 1.4, white hairline, pink
     * shadow) at the .82 white the menu panel uses where rows of text sit
     * under it (at .55 the order summary read through the sentence at 390),
     * solid white where backdrop-filter is missing --
     * with a green tick sitting half outside its end corner. Logical
     * properties throughout, so Arabic mirrors it. Sizes are fixed boxes:
     * nothing here is measured and nothing moves the page.
     */
    const SO_GLASS = 'background:rgba(255,255,255,.82);-webkit-backdrop-filter:blur(12px) saturate(1.4);backdrop-filter:blur(12px) saturate(1.4);border:1px solid rgba(255,255,255,.9);box-shadow:0 10px 30px -14px rgba(193,62,99,.55),inset 0 1px 0 #fff';
    const SO_CSS = '.kbb-so{margin:auto;border:0;border-radius:14px;padding:22px 20px 18px;width:min(440px,calc(100vw - 32px));max-height:calc(100dvh - 32px);overflow:auto;color:var(--ink,#2A2228);background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.25)}'
        + '.kbb-so::backdrop{background:rgba(20,24,22,.45)}'
        + '.kbb-so h2{margin:0 0 6px;font-size:18px;line-height:1.3}'
        + '.kbb-so p{margin:0 0 10px;font-size:14px;line-height:1.45}'
        + '.kbb-so ul{margin:0 0 14px;padding:0;list-style:none}'
        + '.kbb-so li{display:flex;align-items:center;gap:12px;padding:8px;margin:0 0 6px;border-radius:10px;background:#FFF6F8;font-size:13px;line-height:1.4}'
        + '.kbb-so li img,.kbb-so-ph{flex:none;width:44px;height:44px;border-radius:8px;object-fit:cover;background:#FCE0E8}'
        + '.kbb-so li b{font-weight:600;color:var(--ink,#2A2228)}'
        + '.kbb-so-r{color:var(--sale,#E23A4E);font-weight:600}'
        + '.kbb-so-act{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center}'
        + '.kbb-so button{min-height:44px;padding:0 20px;border:0;border-radius:99px;background:var(--pink,#C6395F);color:#fff;font:inherit;font-weight:700;cursor:pointer}'
        + '.kbb-so button[disabled]{opacity:.6;cursor:default}'
        + '.kbb-so a{min-height:44px;display:inline-flex;align-items:center;color:inherit;text-decoration:underline}'
        + '.kbb-so [role=status]:empty{display:none}'
        + '.kbb-so-cap{position:relative;display:flex;align-items:center;min-height:44px;margin:12px 0 4px;padding:10px 22px;padding-inline-end:28px;border-radius:999px;color:var(--ink,#2A2228);font-size:13px;font-weight:600;line-height:1.35;' + SO_GLASS + '}'
        + '.kbb-so-cap i{position:absolute;top:-14px;inset-inline-end:10px;width:26px;height:26px;border-radius:50%;background:var(--green,#2E9E6B);display:grid;place-items:center;box-shadow:0 4px 10px -3px rgba(46,158,107,.6),0 0 0 2px #fff}'
        + '.kbb-so-cap svg{width:14px;height:14px}'
        + '@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){.kbb-so-cap{background:#fff}}'
        + '.kbb-so-note{position:fixed;z-index:2147483000;inset-inline:16px;bottom:calc(96px + env(safe-area-inset-bottom,0px));display:flex;justify-content:center;pointer-events:none}'
        + '.kbb-so-note .kbb-so-cap{margin:0;max-width:520px;animation:kbbSoIn .22s ease-out}'
        + '.kbb-so-note:empty{display:none}'
        + '@media (min-width:900px){.kbb-so-note{bottom:32px}}'
        + '@keyframes kbbSoIn{from{opacity:0;transform:translateY(8px)}}'
        + '@media (prefers-reduced-motion:reduce){.kbb-so-note .kbb-so-cap{animation:none}}';

    /* The green tick, decorative (aria-hidden): the sentence beside it is
       what a screen reader announces. A constant, never data. */
    const SO_TICK = '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

    /* Turn an element into the frosted capsule: the sentence, then the tick. */
    function soCapsule(el, text) {
        el.className = 'kbb-so-cap';
        el.textContent = text;
        const tick = document.createElement('i');
        tick.innerHTML = SO_TICK;
        el.appendChild(tick);
        return el;
    }

    /* The page-level notice after "Remove and continue": one polite live
       region, made once, filled per message, emptied by ONE timer that is
       cleared on the next message — no loop, nothing left running. */
    let soNoteTimer = null;
    function soNotice(text) {
        let region = document.getElementById('kbbSoNote');
        if (!region) {
            region = document.createElement('div');
            region.id = 'kbbSoNote';
            region.className = 'kbb-so-note';
            region.setAttribute('role', 'status');
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
        }
        region.textContent = '';
        region.appendChild(soCapsule(document.createElement('p'), text));
        clearTimeout(soNoteTimer);
        soNoteTimer = setTimeout(() => { region.textContent = ''; }, 5000);
    }

    /* Only a picture on this shop: a root-relative path or this origin. */
    const soSameOrigin = (src) => {
        if (typeof src !== 'string' || src === '') return false;
        try { return new URL(src, window.location.href).origin === window.location.origin; } catch { return false; }
    };

    function openSoldOut(body) {
        const words = (body && body.dialog) || null;
        if (!words || typeof HTMLDialogElement !== 'function' || typeof words.url !== 'string' || words.url.charAt(0) !== '/') return false;

        if (!document.getElementById('kbbSoCss')) {
            const css = document.createElement('style');
            css.id = 'kbbSoCss';
            css.textContent = SO_CSS;
            document.head.appendChild(css);
        }

        document.getElementById('kbbSoldOut')?.remove();

        const lines = Array.isArray(body.lines) ? body.lines : [];
        const dialog = document.createElement('dialog');
        dialog.id = 'kbbSoldOut';
        dialog.className = 'kbb-so';
        dialog.setAttribute('aria-labelledby', 'kbbSoTitle');

        const add = (tag, text, parent = dialog) => {
            const el = document.createElement(tag);
            if (text) el.textContent = text;
            parent.appendChild(el);
            return el;
        };

        add('h2', words.title).id = 'kbbSoTitle';
        add('p', lines.length ? words.intro : (body.error || ''));
        const list = add('ul');
        lines.forEach((line) => {
            const li = add('li', '', list);

            if (soSameOrigin(line.img)) {
                const img = add('img', '', li);
                img.src = line.img;
                img.alt = '';
                img.width = 44;
                img.height = 44;
                img.decoding = 'async';
            } else {
                add('span', '', li).className = 'kbb-so-ph';
            }

            const copy = add('span', '', li);
            if (typeof line.name !== 'string') { copy.textContent = line.text; return; }
            if (line.before) add('span', line.before, copy).className = 'kbb-so-r';
            add('b', line.name, copy);
            if (line.after) add('span', line.after, copy).className = 'kbb-so-r';
        });
        const say = add('p');
        say.setAttribute('role', 'status');

        const act = add('div');
        act.className = 'kbb-so-act';
        const go = add('button', words.remove, act);
        go.type = 'button';
        go.hidden = lines.length === 0;
        const back = add('a', words.cart, act);
        back.href = words.cartUrl;

        go.addEventListener('click', async () => {
            go.disabled = true;
            say.textContent = words.working;

            let data = null;
            try {
                const response = await fetch(words.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': window.KBB.csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        item_ids: lines.map((line) => line.id),
                        country: document.getElementById('billing_country')?.value || '',
                        state: document.getElementById('billing_state')?.value || '',
                        payment_method: document.querySelector('input[name="payment_method"]:checked')?.value || '',
                    }),
                });
                data = await response.json().catch(() => null);
            } catch { data = null; }

            if (!data || data.ok !== true) {
                say.textContent = (data && data.error) || words.failed;
                go.disabled = false;
                return;
            }

            /* Nothing left to buy: said in the dialog, with the way to the
               shop, rather than a jump the shopper did not ask for. */
            if (data.empty) {
                setCartCount(0);
                list.remove();
                go.remove();
                soCapsule(say, data.message || words.empty);
                back.textContent = words.shop;
                back.href = words.shopUrl;
                back.focus();
                return;
            }

            /* The same regions the quantity stepper repaints: totals, delivery
               fee, payment options. Every field the shopper typed stays. */
            applyFragments(data);
            dialog.close();
            document.getElementById('payment')?.scrollIntoView({ block: 'center', behavior: 'instant' });
            soNotice(words.done);
        });

        dialog.addEventListener('close', () => { dialog.remove(); });

        document.body.appendChild(dialog);
        dialog.showModal();
        (go.hidden ? back : go).focus();

        return true;
    }

    /*
     * place()'s refusal, for the two inline scripts that post it
     * (stripe-elements, placing-overlay). They print the sentence first, so an
     * older bundle without this still says what went wrong; this then opens
     * the dialog for a sold-out basket (true: the caller clears its red line)
     * or, for a basket that is genuinely gone, adds the way to it under the
     * sentence. Kept here rather than in either partial so the checkout HTML
     * does not carry it twice.
     */
    window.KBB = window.KBB || {};
    window.KBB.refused = (body, line) => {
        if (body && body.code === 'sold_out') return openSoldOut(body);

        if (line && body && body.code === 'bag_gone' && typeof body.url === 'string' && body.url.charAt(0) === '/') {
            const a = document.createElement('a');
            a.href = body.url;
            a.textContent = body.link || body.url;
            line.append(' ', a);
        }

        return false;
    };
}

/* ------------------------------------------------------------------------ *
 * REMEMBER THE DETAILS ON THIS DEVICE (Lane PO).
 *
 * The owner: "the address fields etc should keep the data in user browser, so
 * user should not enter everything again n again. even wihout login."
 *
 * WHAT IS KEPT: name, phone, email, country, emirate / state, Area / Street,
 * Building, the town box where a country has one, and the delivery choice.
 * WHAT IS NEVER KEPT: the card (it lives in Stripe's iframes and this page
 * cannot read it anyway), the coupon, the account password, "save this card",
 * the payment method -- and the delivery NOTES and the gift message, which are
 * about one parcel and are the free-text boxes most likely to hold something a
 * shared device should not show the next person ("leave it with my
 * neighbour", a birthday message). Nothing here is a list of what NOT to read:
 * only the ids below are ever read, so a field added to the form tomorrow is
 * not kept until somebody adds it here on purpose.
 *
 * WHERE: this browser's localStorage, one versioned key. Same-origin by
 * construction -- no other site can read it, it is never sent anywhere, the
 * server neither reads nor writes it -- and every touch is in a try/catch,
 * because Safari's private mode and a blocked-storage setting both throw.
 *
 * WHEN IT IS WRITTEN: as each field is left (`change`), when the page is left,
 * and when the shop accepts an order (placing-overlay calls
 * window.KBB.remember.save() from confirmed() and leaving()). Never per
 * keystroke. Only while "Remember my details on this device" is ticked;
 * unticking erases the copy there and then.
 *
 * WHEN IT IS READ: once, as the page starts, before a shopper can have typed.
 * A box is filled only when it is EMPTY and the server put nothing in it --
 * an address a signed-in customer has saved, or what a refused submission
 * sent back, always wins -- and the address half only when the remembered
 * country is the country the page is already on, so a remembered Dubai villa
 * never lands under a Saudi country the shopper picked in the header. No
 * request is made and no event is raised: filling a box is not a change the
 * shopper made, so nothing re-prices and inline validation judges nothing.
 *
 * Measures no layout, builds no markup: values in through `.value`, the link
 * shown by a class.
 * ------------------------------------------------------------------------ */
const REMEMBER_KEY = 'kbb.checkout.details.v1';
const REMEMBER_DAYS = 365;
const REMEMBER_CONTACT = ['billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email'];
const REMEMBER_ADDRESS = ['billing_address_1', 'billing_address_2', 'billing_city', 'billing_state'];

const localStore = () => { try { return window.localStorage || null; } catch { return null; } };

function readRemembered() {
    const ls = localStore();
    if (!ls) return null;
    try {
        const kept = JSON.parse(ls.getItem(REMEMBER_KEY) || 'null');
        if (!kept || kept.v !== 1 || !kept.f || typeof kept.f !== 'object') return null;
        if (!(Date.now() - Number(kept.at) < REMEMBER_DAYS * 864e5)) { ls.removeItem(REMEMBER_KEY); return null; }
        return kept.f;
    } catch { return null; }
}

function forgetRemembered() {
    try { localStore()?.removeItem(REMEMBER_KEY); } catch { /* storage off: nothing kept */ }
}

/* The price printed in a delivery option's label ('' for a free one). */
const ratePrice = (radio) => {
    const label = radio && radio.id ? document.querySelector(`label[for="${radio.id}"]`) : null;
    return (label?.querySelector('.woocommerce-Price-amount')?.textContent || '').trim();
};

function rememberDetails(form) {
    const tick = document.getElementById('kbb_remember');
    const clear = document.getElementById('kbbRememberClear');

    // Switched off on Appearance -> Checkout page: keep nothing, hold nothing.
    if (!tick) { forgetRemembered(); return; }

    /* Only a box the shopper can see: never a password, never a hidden
       input, never one inside [hidden] or an inline display:none (the
       hidden account box is how Firefox's autofill stopped Place order). */
    const field = (id) => {
        const el = document.getElementById(id);
        if (!el || !form.contains(el) || el.type === 'hidden' || el.type === 'password' || el.disabled) return null;
        if (el.closest('[hidden]')) return null;
        for (let n = el; n && n !== form; n = n.parentElement) {
            if (n.style && n.style.display === 'none') return null;
        }
        return el;
    };

    const collect = () => {
        const f = {};
        [...REMEMBER_CONTACT, ...REMEMBER_ADDRESS, 'billing_country'].forEach((id) => {
            const el = field(id);
            const value = el ? String(el.value || '').trim().slice(0, 255) : '';
            if (value !== '') f[id] = value;
        });
        const ship = form.querySelector('input[name="shipping_method"]:checked');
        if (ship) f.shipping_method = String(ship.value).slice(0, 64);
        return f;
    };

    const save = () => {
        if (!tick.checked) return;
        const f = collect();
        if (Object.keys(f).length === 0) return;
        try { localStore()?.setItem(REMEMBER_KEY, JSON.stringify({ v: 1, at: Date.now(), f })); } catch { /* full or blocked */ }
    };

    /* ---- restore, once ---- */
    const kept = readRemembered();
    const restored = [];

    if (kept) {
        const serverFilled = [...REMEMBER_CONTACT, ...REMEMBER_ADDRESS].some((id) => {
            const el = field(id);
            if (!el) return false;
            if (el.tagName === 'SELECT') return [...el.options].some((o) => o.defaultSelected && o.value !== '');
            return String(el.defaultValue || '').trim() !== '';
        });
        const country = field('billing_country');
        const sameCountry = !!country && typeof kept.billing_country === 'string' && country.value === kept.billing_country;

        const fill = (id) => {
            const el = field(id);
            const value = kept[id];
            if (!el || typeof value !== 'string' || value === '' || el.value !== '') return;
            if (el.tagName === 'SELECT') {
                if (serverFilled || ![...el.options].some((o) => o.value === value && !o.disabled)) return;
            } else if (String(el.defaultValue || '') !== '') {
                return;
            }
            el.value = value;
            restored.push([el, value]);
        };

        REMEMBER_CONTACT.forEach(fill);
        if (sameCountry) REMEMBER_ADDRESS.forEach(fill);

        /* The delivery choice, only where it cannot move the total: the page
           priced its totals for the option it checked, and nothing re-prices
           when a radio changes, so a remembered option is taken only when its
           printed price is the checked one's. */
        if (sameCountry && !serverFilled && typeof kept.shipping_method === 'string') {
            const radios = [...form.querySelectorAll('input[name="shipping_method"]')];
            const want = radios.find((r) => r.value === kept.shipping_method);
            const now = radios.find((r) => r.checked);
            if (want && !want.checked && (!now || (now.defaultChecked && ratePrice(want) === ratePrice(now)))) {
                want.checked = true;
                restored.push([want, true]);
            }
        }

        /* The floating Place-order bar asks "is this placeable?" on input. */
        if (restored.length) form.dispatchEvent(new Event('input', { bubbles: true }));
    }

    if (clear && restored.length) {
        clear.classList.add('on');
        clear.removeAttribute('aria-hidden');
        clear.removeAttribute('tabindex');
    }

    /* ---- the shopper's say ---- */
    tick.addEventListener('change', (event) => {
        // Not a field of the order: nothing else on this page hears it.
        event.stopPropagation();
        if (tick.checked) save(); else forgetRemembered();
    });

    clear?.addEventListener('click', (event) => {
        event.preventDefault();
        forgetRemembered();
        // Empty what was filled from the copy and still says what it said.
        restored.forEach(([el, value]) => {
            if (el.type === 'radio') return;
            if (el.value === value) el.value = '';
        });
        clear.classList.remove('on');
        clear.setAttribute('aria-hidden', 'true');
        clear.setAttribute('tabindex', '-1');
        field('billing_first_name')?.focus({ preventScroll: true });
    });

    /* ---- writing ---- */
    const keptIds = new Set([...REMEMBER_CONTACT, ...REMEMBER_ADDRESS, 'billing_country']);
    let typed = false;
    form.addEventListener('input', (event) => { if (event.target && keptIds.has(event.target.id)) typed = true; });
    form.addEventListener('change', (event) => {
        const t = event.target;
        if (t && (keptIds.has(t.id) || t.name === 'shipping_method')) save();
    });
    // A box still focused when the page is left never fired `change`.
    window.addEventListener('pagehide', () => { if (typed) save(); });

    window.KBB = window.KBB || {};
    window.KBB.remember = { save };
}
