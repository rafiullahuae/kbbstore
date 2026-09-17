/**
 * Frequently Bought Together: live total, add-all.
 *
 * The total recalculates from the checkboxes' data-price, which is display only
 * — the server recomputes every line when the items are actually added.
 */

import { addToCart } from './cart.js';
import { t } from './i18n.js';

export function initFbt() {
    const block = document.querySelector('.kbb-fbt');
    if (!block) return;

    const sum = block.querySelector('.kbb-fbt-sum');

    const recalc = () => {
        const total = [...block.querySelectorAll('.kbb-fbt-cb:checked')]
            .reduce((n, cb) => n + Number(cb.dataset.price || 0), 0);
        if (sum) sum.textContent = 'AED ' + (total % 1 === 0 ? total.toFixed(0) : total.toFixed(2));
    };

    block.addEventListener('change', (event) => {
        if (event.target.classList.contains('kbb-fbt-cb')) recalc();
    });

    block.addEventListener('click', async (event) => {
        if (!event.target.closest('.kbb-fbt-add')) return;

        const ids = [...block.querySelectorAll('.kbb-fbt-cb:checked')].map((cb) => Number(cb.value));
        if (!ids.length) { window.kbbToast?.(t('store.js.fbt_pick_one', 'Select at least one product.')); return; }

        const button = event.target.closest('.kbb-fbt-add');
        button.disabled = true;

        // One request per item, in sequence: the add endpoint is per-product and
        // sequential keeps the cart rows in a predictable order.
        try {
            // Through the cart module, which opens the panel and repaints it
            // from each answer. The old loop posted directly and then asked for
            // the drawer a second time, so the panel lagged a request behind.
            for (const id of ids) {
                await addToCart({ product_id: id, quantity: 1 });
            }
            window.kbbToast?.(t('store.js.fbt_added', ':count items added ✓', { count: ids.length }));
        } catch {
            window.kbbToast?.(t('store.js.fbt_failed', 'Could not add those — please try again.'));
        } finally {
            button.disabled = false;
        }
    });

    recalc();
}
