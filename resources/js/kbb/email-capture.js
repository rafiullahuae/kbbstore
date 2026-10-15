/**
 * Checkout: record the email once the field is left. (Lane CAP)
 *
 * The owner: "when user enter their email, i want to record that email in our
 * system ... even user don't create account or place the order. do it super
 * nicely without breaking anything." And his standing rule for this field: no
 * disturbance while typing.
 *
 * WHEN THIS RUNS AT ALL. checkout.js imports this chunk only when the page
 * publishes `KBB.routes.checkoutEmail`, which the server prints only while
 * Cart Tracking → Settings → "Record the email typed at checkout" is on and
 * nobody is signed in (App\Support\CheckoutEmailCapture::onForPage). Off, no
 * request is made for this file and nothing here exists.
 *
 * WHAT IT DOES, AND ALL IT DOES.
 *   - Nothing while typing: no listener on `input`, no timer.
 *   - Leaving the field (blur), or the field's own `change` while it is not
 *     focused (Lane EH's "Did you mean …@yahoo.com?" button writes the fix and
 *     fires `change`; so does autofill), sends the address ONCE if it looks
 *     like one. The same address is never sent twice; a corrected one is, and
 *     the server moves the cart to it (the yahoo.co → yahoo.com case).
 *   - One small POST with the page's CSRF token, keepalive so it survives the
 *     shopper moving on. Its answer is an empty 204 and is not read.
 *   - Never for a signed-in administrator (the kbb_ah hint).
 * No element, no style, no layout read.
 */
import { hasAdminHint } from './admin-hint.js';

/** The shape worth sending. The server decides with StorefrontEmail; this only spares it the obvious misses. */
export const looksLikeEmail = (v) => /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[A-Za-z]{2,}$/.test(v) && v.length <= 254;

export function startEmailCapture(url) {
    const field = document.getElementById('billing_email');
    const K = window.KBB;
    if (!field || !url || !K || !K.csrf || hasAdminHint(document.cookie)) return;

    let sent = '';

    const send = () => {
        const v = String(field.value || '').trim();
        if (!looksLikeEmail(v) || v.toLowerCase() === sent) return;
        sent = v.toLowerCase();
        const body = new FormData();
        body.append('_token', K.csrf);
        body.append('email', v);
        fetch(url, {
            method: 'POST', body, credentials: 'same-origin', keepalive: true,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => {});
    };

    field.addEventListener('blur', send);
    field.addEventListener('change', () => { if (document.activeElement !== field) send(); });
}
