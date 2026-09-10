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

export function initToast() {
    // Exposed so inline handlers ported from the theme keep working.
    window.kbbToast = toast;
}
