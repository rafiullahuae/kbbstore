/*
 * Where a shopper with a basket goes and leaves (Lane CJ, 2.60.494).
 *
 * The owner, 13 October 2026: "track ... from where customer left ... he
 * proceeded to checkout or not, where he clicked (for cart and checkout page)".
 * The pages themselves are already recorded server-side from the page-view
 * beacon (hit.js -> /api/viewed -> CartTrail::page). This adds what only the
 * browser sees: the taps on the bag panel, the cart page and the checkout, the
 * checkout steps, and the moment the page is closed or left.
 *
 * WHO. Only a shopper with something in the bag (the header badge, the same
 * count the server printed), and never a signed-in administrator. An empty bag
 * sends nothing at all.
 *
 * WHAT. A code and the page's path. NEVER anything typed: "entered their email"
 * is sent when the email field holds an address, not the address; "Place order
 * stopped" names the fields (their name attributes), never their contents.
 *
 * COST. Delegated listeners on document (one click, one change, one focusout),
 * one MutationObserver on the bag's class, no timer. Taps queue in memory and
 * go in ONE sendBeacon when the page is hidden, left or the queue reaches 8:
 * typically one small request per page, and none on a page with no taps
 * except the "left" itself. Nothing here measures layout.
 */
import { hasAdminHint } from './admin-hint.js';

const LEFT = 2;
const BACK = 3;
const PAY = { cod: 32, stripe: 33, tabby: 34, tamara: 35 };
const ADDRESS = ['billing_first_name', 'billing_phone', 'billing_address_1'];

/** Which code a click means, or 0. Pure, so the test can run it on markup. */
export function codeFor(el, inDrawer, onCheckout) {
    if (!el || !el.closest) return 0;
    const c = (s) => el.closest(s);
    if (inDrawer) {
        if (c('a.cobtn')) return 12;
        if (c('a.btn-ghost')) return 13;
        if (c('[data-kcrm]')) return 14;
        if (c('[data-kcq]')) return 15;
        if (c('[data-kctab="browsed"]')) return 17;
        if (c('.kc-coupon button')) return 18;
        if (c('a.kc-th, a[href*="/product/"]')) return 16;
        return 0;
    }
    if (onCheckout) {
        if (c('[data-place]')) return 39;
        if (c('[data-kbb-signin]')) return 37;
        if (c('#kbb_apply_coupon')) return 38;
        if (c('a.co-back, a[href$="/cart/"]')) return 41;
        if (c('a[href*="policy"], a[href*="terms"], a[href*="/delivery"], a[href*="refund"]')) return 44;
        return 0;
    }
    if (c('[data-kcprm]')) return 21;
    if (c('[data-kcpq]')) return 22;
    if (c('#kbbCartCoupon button, .coupon button')) return 23;
    if (c('a.cobtn[href*="/checkout"]')) return 20;
    if (c('a.conti')) return 24;
    return 0;
}

export function initTrail() {
    const K = window.KBB;
    if (!K || !K.csrf || !navigator.sendBeacon || hasAdminHint(document.cookie)) return;

    const drawer = document.getElementById('cart');
    const form = document.getElementById('kbbCheckoutForm');
    const cartPage = !form && !!document.querySelector('[data-kcpq], [data-kcprm]');
    const base = K.base || '';
    const path = location.pathname.slice(0, 300);
    const badge = document.getElementById('cartCt');
    const hasBag = () => !!form || cartPage || (parseInt(badge ? badge.textContent : K.cartCount, 10) || 0) > 0;

    let q = [];
    const once = new Set();
    let leaving = false;
    let lastPay = '';

    const flush = () => {
        if (!q.length || !hasBag()) { q = []; return; }
        const now = Date.now();
        const b = new FormData();
        b.append('_token', K.csrf);
        b.append('e', JSON.stringify(q.splice(0, 25).map(([c, d, t]) => [c, d, now - t])));
        navigator.sendBeacon(base + '/api/cart-trail', b);
    };
    const push = (code, detail, single) => {
        if (single) { if (once.has(code)) return; once.add(code); }
        q.push([code, detail || path, Date.now()]);
        if (q.length >= 8) flush();
    };

    // The bag panel opening and closing, from its own class: no change to cart.js.
    if (drawer && 'MutationObserver' in window) {
        let open = drawer.classList.contains('on');
        new MutationObserver(() => {
            const now = drawer.classList.contains('on');
            if (now === open) return;
            open = now;
            push(now ? 10 : 11);
        }).observe(drawer, { attributes: true, attributeFilter: ['class'] });
    }

    document.addEventListener('click', (e) => {
        const t = e.target;
        const inDrawer = !!(drawer && t && t.closest && drawer.contains(t));
        if (!inDrawer && !form && !cartPage) {
            // Elsewhere only the leave matters; a same-site link is a move, not a leave.
            if (t && t.closest && t.closest('a[href^="/"], a[href^="' + location.origin + '"]')) leaving = true;
            return;
        }
        const code = codeFor(t, inDrawer, !!form && !inDrawer);
        if (t && t.closest && t.closest('a[href]')) leaving = true;
        if (!code) return;
        push(code);
        if (code === 39 && form) {
            // After the page's own checks have run: which fields stopped the order.
            setTimeout(() => {
                if (!document.body.contains(form)) return;
                const names = [];
                form.querySelectorAll(':invalid, [aria-invalid="true"]').forEach((f) => {
                    if (f.name && names.indexOf(f.name) === -1) names.push(f.name);
                });
                if (names.length) push(40, names.slice(0, 12).join(','));
            }, 700);
        }
        if (code === 12 || code === 13 || code === 20 || code === 16) flush();
    }, true);

    if (form) {
        form.addEventListener('change', (e) => {
            const f = e.target;
            if (!f || !f.name) return;
            if (f.name === 'payment_method' && f.checked && f.value !== lastPay) { lastPay = f.value; push(PAY[f.value] || 36); }
            else if (f.name === 'shipping_method' && f.checked) push(42);
            else if (f.id === 'create_account' && f.checked) push(43, null, true);
        });
        form.addEventListener('focusout', (e) => {
            const f = e.target;
            if (!f || !f.name) return;
            if (f.name === 'billing_email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.value || '')) push(30, null, true);
            if (ADDRESS.indexOf(f.name) !== -1 || f.name === 'billing_state' || f.name === 'billing_city') {
                const full = ADDRESS.every((n) => { const x = form.elements[n]; return !x || (x.value || '').trim() !== ''; });
                if (full) push(31, null, true);
            }
        });
    }

    // One "left" per absence: hidden (another app, the phone locked, the tab
    // closed) or the page unloading, whichever comes first; "came back" when
    // the same page is shown again.
    let away = false;
    const gone = () => { if (!away && !leaving) { away = true; push(LEFT); } flush(); };
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') gone();
        else if (away) { away = false; push(BACK); }
    });
    window.addEventListener('pagehide', gone);
    // Back from the back-forward cache: the same page again, not a new view.
    window.addEventListener('pageshow', (e) => { if (e.persisted) { leaving = false; if (away) { away = false; push(BACK); } } });
}
