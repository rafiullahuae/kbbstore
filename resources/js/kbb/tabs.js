/**
 * Product details tabs.
 *
 * Every panel is rendered server-side, so the content is present and indexable
 * without JavaScript; this only switches which one is shown and expands the
 * clamped text. On mobile the same content is an accordion.
 */
import { t } from './i18n.js';

export function initProductTabs() {
    const root = document.getElementById('details');
    if (!root) return;

    const tabs = [...root.querySelectorAll('.dtab')];
    const panels = [...root.querySelectorAll('[data-panel]')];
    const rows = [...root.querySelectorAll('.macc-i')];

    const select = (index) => {
        tabs.forEach((t, i) => t.classList.toggle('on', i === index));
        panels.forEach((p, i) => {
            p.classList.toggle('on', i === index);
            // Collapse again when leaving a panel, so returning to it starts
            // clamped rather than remembering a half-read state.
            if (i !== index) {
                p.querySelector('.dcontent')?.classList.replace('open', 'clamp');
                const btn = p.querySelector('.readmore');
                if (btn) btn.textContent = t('store.product.read_more', 'Read more ↓');
            }
        });
    };

    root.addEventListener('click', (event) => {
        const tab = event.target.closest('.dtab');
        if (tab) { select(tabs.indexOf(tab)); return; }

        const more = event.target.closest('.readmore');
        if (more) {
            const content = more.previousElementSibling;
            const open = content.classList.toggle('open');
            content.classList.toggle('clamp', !open);
            more.textContent = open
                ? t('store.js.read_less', 'Read less ↑')
                : t('store.product.read_more', 'Read more ↓');
            return;
        }

        const head = event.target.closest('.macc-h');
        if (head) {
            const row = head.parentElement;
            const isOpen = row.classList.contains('open');
            rows.forEach((r) => {
                r.classList.remove('open');
                const pm = r.querySelector('.pm');
                if (pm) pm.textContent = '+';
            });
            if (!isOpen) {
                row.classList.add('open');
                const pm = row.querySelector('.pm');
                if (pm) pm.textContent = '−';
            }
        }
    });
}
