/**
 * Newsletter signup.
 *
 * The form posts on its own if this never runs — the server answers a plain post
 * with a redirect and a flash message, so a visitor with scripts blocked still
 * gets confirmation on the page. This only upgrades that to a toast without the
 * reload.
 */

import { toast } from './toast.js';

export function initNewsletter() {
    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-kbb-subscribe]');
        if (!form) return;

        const field = form.querySelector('input[name="email"]');
        const button = form.querySelector('button');
        if (!field || !field.value.trim()) return;

        event.preventDefault();
        if (button) button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.KBB.csrf,
                    Accept: 'application/json',
                },
                body: JSON.stringify({ email: field.value.trim(), source: 'homepage' }),
            });

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                toast(body.error || 'Could not sign you up — please try again.');
                return;
            }

            form.reset();
            toast(body.message || 'You are on the list ✓');
        } catch {
            // The network failed rather than the server refusing. Hand it back to
            // the browser so the address is not silently dropped.
            form.submit();
        } finally {
            if (button) button.disabled = false;
        }
    });
}
