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
 */

import { addTogether } from './cart.js';
import { closeAll } from './overlay.js';
import { t } from './i18n.js';

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

export function initFbt() {
    const block = document.querySelector('[data-bt]');
    if (!block) return;

    const button = block.querySelector('[data-bt-buy]');
    const label = block.querySelector('.bt-label');
    const sum = block.querySelector('.bt-num');
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
        let total = 0;

        boxes().forEach((cb) => {
            const card = cb.closest('.bt-card');
            if (!card) return;
            card.classList.toggle('is-off', !cb.checked);
            if (cb.checked) { n += 1; total += priceOf(card); }
        });

        if (label) label.textContent = btLabel(n, block.dataset);
        if (button) button.disabled = busy || n === 0;
        if (sum) sum.textContent = formatMinor(total, exp, dec);
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
            const data = await Promise.race([addTogether(items), giveUp]);

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
    countView(block);
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
