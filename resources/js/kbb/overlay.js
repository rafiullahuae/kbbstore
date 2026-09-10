/** Shared scrim and drawer open/close. T-CHROME-15. */

const OPEN_CLASS = 'on';

export const closeAll = () => {
    document.querySelectorAll('.drawer.on, .mnav.on, .msub.on').forEach((el) => el.classList.remove(OPEN_CLASS));
    document.getElementById('ov')?.classList.remove(OPEN_CLASS);
    document.body.classList.remove('kbb-locked');
};

export const open = (id) => {
    const panel = document.getElementById(id);
    if (!panel) return;

    panel.classList.add(OPEN_CLASS);
    document.getElementById('ov')?.classList.add(OPEN_CLASS);
    // Stops the page behind a drawer from scrolling on touch devices.
    document.body.classList.add('kbb-locked');
};

export function initOverlay() {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-kbb-open]');
        if (opener) {
            event.preventDefault();
            open(opener.dataset.kbbOpen);
            return;
        }

        if (event.target.closest('[data-kbb-close]')) {
            event.preventDefault();
            closeAll();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAll();
    });
}
