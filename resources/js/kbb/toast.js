/** Transient confirmation message. T-CHROME-16. */

let timer = null;

export const toast = (message) => {
    const el = document.getElementById('toast');
    if (!el) return;

    el.textContent = message;
    el.classList.add('on');

    clearTimeout(timer);
    timer = setTimeout(() => el.classList.remove('on'), 1700);
};

/**
 * The "added" tick (Lane PI-B) — Appearance → Cart panel → Behaviour →
 * "When something is added" → Animated tick.
 *
 * The animation is entirely kbb.css's: this only restarts it. Removing `on`
 * and adding it back in the SAME frame would not restart a CSS animation, and
 * the usual trick — reading an element's size in between to force a reflow — is a
 * layout read this shop does not allow, so the class goes back on two frames
 * later instead. The message goes into the visually hidden status span, so a
 * screen reader still hears "Added to bag".
 */
let tickTimer = null;

export const tick = (message) => {
    const el = document.getElementById('kbbTick');
    if (!el) {
        toast(message);
        return;
    }

    const said = el.querySelector('.kbb-tick-msg');
    if (said) said.textContent = message || '';

    el.classList.remove('on');
    clearTimeout(tickTimer);

    requestAnimationFrame(() => requestAnimationFrame(() => {
        el.classList.add('on');
        // 450ms of animation, 600ms reduced-motion; then clean up for the next add.
        tickTimer = setTimeout(() => el.classList.remove('on'), 700);
    }));
};

export function initToast() {
    // Exposed so inline handlers ported from the theme keep working.
    window.kbbToast = toast;
}
