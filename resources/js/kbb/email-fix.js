/**
 * Checkout: "Did you mean jovana8b@yahoo.com?" under the email field. (Lane EH)
 *
 * The owner picked style A from docs/checkout-email-hint/ and set the rules:
 * "the dialogue box should appear only once user left the email field. during
 * write no any disturbance" and "i want super minimal non-disturbance stuff".
 * Switched on at Emails → Sending & delivery → At checkout; OFF by default.
 *
 * WHEN THIS RUNS AT ALL. checkout.js imports this chunk only when the page
 * carries `[data-efix]`, which the server prints only while the switch is on
 * (App\Support\CheckoutEmailFix). Off, the page has no marker, no request is
 * made for this file and nothing here exists.
 *
 * WHAT IT DOES, AND ALL IT DOES.
 *   - Leaving the email field (blur) asks kxeSuggest -- the admin's own,
 *     tested function -- for a fix. A correct or unknown address: nothing.
 *   - A fix: the line under the field (the grey "We send your order
 *     confirmation here." when that is on, else a hidden line in the same
 *     place) reads "Did you mean <local>@<domain>?", the domain a button.
 *   - Typing again puts the line back at once. Nothing else is redrawn while
 *     typing, and nothing is asked of the server: there is no request here.
 *   - The button writes the suggested address into the field and fires the
 *     field's own `input` and `change`, so checkout's validation, "Remember my
 *     details" and the pixels see it exactly as if it had been typed.
 *   - It never changes the field on its own and never touches submit.
 *
 * NO STYLESHEET. Its few rules are set through the element's style object
 * (CSSOM), which is not an inline <style> or style="" the CSP report counts,
 * and costs no request. No layout is measured: the one-line fit is flexbox
 * (the local part is the only thing allowed to shrink, with an ellipsis), not
 * script.
 *
 * Its words are the server's: `data-efix` carries the translated sentence with
 * `:email` where the address goes, so /ar reads "هل تقصدين :email؟" with the
 * address kept left-to-right inside <bdi dir="ltr">.
 */
import { kxeSuggest } from './email-suggest.js';

const PINK = '#c13e63';

function span(text, css) {
    const el = document.createElement('span');
    el.textContent = text;
    el.style.cssText = css;
    return el;
}

export function startEmailFix(line) {
    const field = document.getElementById('billing_email');
    if (!line || !field) return;

    const words = String(line.dataset.efix || '');
    const at = words.indexOf(':email');
    if (at < 0) return;

    // The grey line exactly as the server drew it, to put back.
    const grey = Array.from(line.childNodes);
    const greyHidden = line.hidden;
    let shown = null;

    line.setAttribute('aria-live', 'polite');

    const restore = () => {
        if (shown === null) return;
        line.replaceChildren(...grey);
        line.hidden = greyHidden;
        shown = null;
    };

    const show = (fixed) => {
        const cut = fixed.lastIndexOf('@');
        const row = span('', 'display:flex;align-items:baseline;white-space:nowrap;min-width:0;max-width:100%');
        const addr = document.createElement('bdi');
        addr.dir = 'ltr';
        addr.style.cssText = 'display:flex;align-items:baseline;min-width:0';
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = fixed.slice(cut + 1);
        button.dataset.efixUse = '';
        // A bigger target than the 12px of text, with no change to the line's height.
        button.style.cssText = 'flex:none;font:inherit;line-height:inherit;color:' + PINK + ';font-weight:700;background:none;border:0;'
            + 'padding:6px 0;margin:-6px 0;text-decoration:underline;text-underline-offset:2px;cursor:pointer';
        button.addEventListener('click', () => {
            field.value = fixed;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            restore();
        });
        addr.append(
            span(fixed.slice(0, cut), 'overflow:hidden;text-overflow:ellipsis;min-width:0'),
            span('@', 'flex:none'),
            button,
        );
        row.append(
            span(words.slice(0, at), 'flex:none;white-space:pre'),
            addr,
            span(words.slice(at + 6), 'flex:none;white-space:pre'),
        );
        line.replaceChildren(row);
        line.hidden = false;
        shown = fixed;
    };

    // While typing: put the line back, and nothing else.
    field.addEventListener('input', restore);

    // Leaving the field: the one moment anything is decided.
    field.addEventListener('blur', () => {
        const fixed = kxeSuggest(field.value);
        if (fixed === shown) return;   // already on show: never redraw under a tap
        restore();
        if (fixed && fixed !== field.value.trim()) show(fixed);
    });
}
