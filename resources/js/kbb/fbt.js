/**
 * "Buy these together": the ticks, the count on the button, the total, and the
 * one request that adds every ticked product.                       (Lane RB)
 *
 * The owner: "whatever products are checked and user click on Buy 4 items
 * together, number 4 will count as per the user selection. if user selects 3,
 * then 3 will come on the button. and all those will add to the cart."
 *
 * NOTHING HERE MEASURES ANYTHING. It counts checked boxes, adds up integers the
 * server printed (data-price, in minor units) and writes two strings. The card
 * sizes are CSS; see kbb-product.css.
 *
 * The prices are DISPLAY ONLY. The server prices every line again when it is
 * added, and refuses a hidden, sold-out or option-less product by itself.
 *
 * ── LANE RE: THE CUT PRICE, THE SAVING, AND THE PEEK ─────────────────────────
 *
 * "give option to give discount upon 5 products purchse, 4 products and 3. so
 * the price will change upon user number of selections." The tier for each
 * count is printed on the section (data-tiers); bundleOff() takes it off each
 * ticked card with the server's own integer arithmetic
 * (App\Services\BuyTogetherPricing::unitOff), and the struck total, the
 * payable total and "You're saving AED 81" follow every tick. The basket
 * prices the bundle again on the server; nothing here is sent but product ids
 * and the page's own product id.
 *
 * The peek — "upon scroll this section must slightly animate and display the
 * half of the 5th product" — is ONE CLASS added by an IntersectionObserver the
 * first time the section is half on screen. The slide itself is CSS
 * (kbb-product.css, `btpeek`), its distance a percentage of the card. Still
 * nothing is measured.
 */

import { addTogether } from './cart.js';
import { closeAll } from './overlay.js';
import { t } from './i18n.js';
import { addToHit } from './hit.js';

/** How long the button may spin before it gives itself back. */
const GIVE_UP_MS = 20000;

/**
 * The button's words for `n` ticked products. Templates come from the section's
 * data attributes, which the server printed through __() — so the Arabic page
 * counts in Arabic.
 *
 *   0  → data-none   ("Tick at least one product"; the button is disabled)
 *   1  → data-one    ("Add 1 item to cart")
 *   n  → data-many   ("Buy :count items together")
 */
export function btLabel(n, tpl) {
    if (n <= 0) return tpl.none || '';
    if (n === 1) return tpl.one || '';
    return String(tpl.many || '').split(':count').join(String(n));
}

/**
 * A minor-unit integer as the shop prints it — App\Support\Money::amount(),
 * line for line: integer arithmetic at the currency's exponent, rounded half up
 * to the display decimals, a comma between thousands.
 */
export function formatMinor(minor, exp, dec) {
    const negative = minor < 0;
    const abs = Math.abs(Math.round(minor));
    const unit = 10 ** exp;
    let whole = Math.floor(abs / unit);
    let frac = abs % unit;
    const d = Math.max(0, Math.min(6, dec));

    if (d >= exp) {
        frac *= 10 ** (d - exp);
    } else {
        const scale = 10 ** (exp - d);
        frac = Math.floor((frac + Math.floor(scale / 2)) / scale);
        if (frac >= 10 ** d) { frac -= 10 ** d; whole += 1; }
    }

    let out = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (d > 0) out += '.' + String(frac).padStart(d, '0');

    return negative && (whole !== 0 || frac !== 0) ? '-' + out : out;
}

/**
 * What one unit at `unit` minor units is reduced BY at `pct` percent — the
 * server's App\Services\BuyTogetherPricing::unitOff(), line for line: the
 * reduced price rounded half up to the minor unit, then DOWN to a whole unit
 * of the currency (WholeDirhams::toward), so the page and the basket agree to
 * the fil.
 */
export function unitOff(unit, pct, exp) {
    const p = Math.max(0, Math.min(50, Math.round(Number(pct) || 0)));
    if (p === 0 || unit <= 0) return 0;
    const whole = 10 ** exp;
    const exact = Math.floor((unit * (100 - p) + 50) / 100);
    const reduced = whole <= 1 ? exact : Math.floor(exact / whole) * whole;
    return Math.max(0, Math.min(unit, unit - reduced));
}

/**
 * The three figures under the cards for the ticked prices: the struck total
 * (their own prices, before this section's discount), the payable total and
 * the saving -- the buy-together discount alone (2.60.361: "you're saving
 * calculate only the discounted price which is set for this buy together
 * section only"). A product's own sale is in its price, not in the saving.
 * `items` is a list of { now, reg } in minor units; `tiers` maps a count of
 * ticked products to its percent (3, 4, 5, 6).
 */
export function bundleTotals(items, tiers, exp) {
    const n = items.length;
    const pct = n >= 3 ? Number((tiers || {})[String(Math.min(6, n))] || 0) : 0;
    let now = 0;
    let off = 0;

    items.forEach((it) => {
        now += it.now;
        off += unitOff(it.now, pct, exp);
    });

    return { was: now, pay: now - off, save: off, pct };
}

export function initFbt() {
    const block = document.querySelector('[data-bt]');
    if (!block) return;

    const button = block.querySelector('[data-bt-buy]');
    const label = block.querySelector('.bt-label');
    const sum = block.querySelector('.bt-num');
    const wasBox = block.querySelector('.bt-was');
    const wasNum = block.querySelector('.bt-was-num');
    const saveBox = block.querySelector('.bt-save');
    const saveNum = block.querySelector('.bt-save-num');
    let tiers = {};
    try { tiers = JSON.parse(block.dataset.tiers || '{}'); } catch { tiers = {}; }
    const exp = Number(block.dataset.exp || 2);
    const dec = Number(block.dataset.dec || 2);
    const varInput = document.getElementById('kbbVarId');
    let variants = {};
    try { variants = JSON.parse(block.dataset.variants || '{}'); } catch { variants = {}; }
    let busy = false;

    const boxes = () => [...block.querySelectorAll('.bt-cb')];

    /* The product on the page follows the option chosen in the buy box: its
       card carries data-bt-var, and the price is that option's. */
    const priceOf = (card) => {
        if (card.hasAttribute('data-bt-var') && varInput && variants[varInput.value] !== undefined) {
            return Number(variants[varInput.value]) || 0;
        }
        return Number(card.dataset.price) || 0;
    };

    const refresh = () => {
        let n = 0;
        const ticked = [];

        boxes().forEach((cb) => {
            const card = cb.closest('.bt-card');
            if (!card) return;
            card.classList.toggle('is-off', !cb.checked);
            if (cb.checked) { n += 1; ticked.push({ now: priceOf(card) }); }
        });

        const t = bundleTotals(ticked, tiers, exp);

        if (label) label.textContent = btLabel(n, block.dataset);
        if (button) button.disabled = busy || n === 0;
        if (sum) sum.textContent = formatMinor(t.pay, exp, dec);
        if (wasNum) wasNum.textContent = formatMinor(t.was, exp, dec);
        if (wasBox) wasBox.hidden = !(t.was > t.pay);
        if (saveNum) saveNum.textContent = formatMinor(t.save, exp, dec);
        if (saveBox) saveBox.hidden = !(t.save > 0);
    };

    block.addEventListener('change', (event) => {
        if (event.target.classList?.contains('bt-cb')) refresh();
    });

    // A different option in the buy box: pdp.js writes #kbbVarId on the same
    // click, so read it once that handler has run.
    document.addEventListener('click', (event) => {
        if (event.target.closest?.('.variant')) setTimeout(refresh, 0);
    });

    const done = () => {
        busy = false;
        if (button) {
            button.classList.remove('is-busy');
            button.removeAttribute('aria-busy');
        }
        refresh();
    };

    block.addEventListener('click', async (event) => {
        if (!event.target.closest('[data-bt-buy]') || busy) return;

        const items = boxes().filter((cb) => cb.checked && !cb.disabled).map((cb) => {
            const card = cb.closest('.bt-card');
            const variant = card && card.hasAttribute('data-bt-var') && varInput ? Number(varInput.value) || null : null;
            return { product_id: Number(cb.value), variant_id: variant };
        });

        if (!items.length) return;

        busy = true;
        button.disabled = true;
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');

        /* NEVER A BUTTON THAT SPINS FOREVER. A request that has not answered in
           twenty seconds gives the button back with a sentence; if the answer
           does arrive later it is still applied by cart.js like any other. */
        let timer = null;
        const giveUp = new Promise((resolve) => {
            timer = setTimeout(() => resolve('timeout'), GIVE_UP_MS);
        });

        try {
            const data = await Promise.race([addTogether(items, Number(block.dataset.bt) || null), giveUp]);

            if (data === 'timeout') {
                window.kbbToast?.(t('store.js.add_failed', 'Could not add that just now — please try again.'));
            } else if (data && data.ok === false && !data.added_count) {
                // Nothing went in: the server's sentence is already a toast
                // (cart.js), and an open, unchanged panel would hide it.
                closeAll();
            }
        } finally {
            clearTimeout(timer);
            done();
        }
    });

    refresh();
    peek(block);
    countView(block);
}

/**
 * The one-time nudge that shows half of the fifth card. (Lane RE)
 *
 * Only when there IS a fifth (`bt-more`, printed by the server). The first
 * time at least 45% of the section is on screen the class goes on and the CSS
 * animation runs once; a thumb on the row first (or during it) ends it, so the
 * row never fights a swipe. Reduced motion: CSS draws a static peek instead and
 * this adds nothing. No observer support: nothing happens, which is the old
 * behaviour.
 */
function peek(block) {
    if (!block.classList.contains('bt-more') || typeof IntersectionObserver !== 'function') return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const rail = block.querySelector('.bt-rail');
    let done = false;

    const stop = () => {
        if (done) return;
        done = true;
        block.classList.remove('is-peek');
        block.classList.add('is-peeked');
        io.disconnect();
    };

    const io = new IntersectionObserver((entries) => {
        if (done || !entries.some((e) => e.isIntersecting)) return;
        done = true;
        io.disconnect();
        block.classList.add('is-peek');
        // The class stays: a removed animation would replay on the next add.
        block.addEventListener('animationend', () => block.classList.add('is-peeked'), { once: true });
    }, { threshold: 0.45 });

    io.observe(block);

    if (rail) {
        ['pointerdown', 'touchstart', 'wheel'].forEach((type) => {
            rail.addEventListener(type, () => {
                if (block.classList.contains('is-peek') && !block.classList.contains('is-peeked')) {
                    block.classList.remove('is-peek');
                    block.classList.add('is-peeked');
                }
                stop();
            }, { passive: true, once: true });
        });
    }
}

/**
 * One view of this product, counted for "Most viewed" — after the page has
 * loaded, at most once per product per browser per day, with sendBeacon so it
 * can never hold anything up. App\Support\ProductViews says what it costs.
 */
function countView(block) {
    const id = Number(block.dataset.bt);
    const url = block.dataset.viewUrl;
    if (!id || !url || !navigator.sendBeacon || !window.KBB?.csrf) return;

    // Lane AN: ride on the page's one beacon (hit.js) rather than send a
    // second request. Decided now, at start-up, before that beacon leaves;
    // if it has already gone, the old path below sends this one alone.
    const today = new Date().toISOString().slice(0, 10);
    let seenNow = [];
    try { seenNow = JSON.parse(localStorage.getItem('kbb_pv_' + today) || '[]'); } catch { seenNow = []; }
    if (Array.isArray(seenNow) && seenNow.includes(id)) return;
    if (addToHit('pv', id)) {
        try { localStorage.setItem('kbb_pv_' + today, JSON.stringify(seenNow.concat(id).slice(-200))); } catch { /* counted, not remembered */ }
        return;
    }

    const send = () => {
        const day = new Date().toISOString().slice(0, 10);
        const key = 'kbb_pv_' + day;
        let seen = [];
        try { seen = JSON.parse(localStorage.getItem(key) || '[]'); } catch { seen = []; }
        if (!Array.isArray(seen) || seen.includes(id)) return;

        const body = new FormData();
        body.append('_token', window.KBB.csrf);
        body.append('id', String(id));
        navigator.sendBeacon(url, body);

        try {
            localStorage.setItem(key, JSON.stringify(seen.concat(id).slice(-200)));
            for (let i = localStorage.length - 1; i >= 0; i -= 1) {
                const k = localStorage.key(i);
                if (k && k.startsWith('kbb_pv_') && k !== key) localStorage.removeItem(k);
            }
        } catch { /* private window: counted, just not remembered */ }
    };

    if (document.readyState === 'complete') setTimeout(send, 1500);
    else window.addEventListener('load', () => setTimeout(send, 1500), { once: true });
}
