/** Shared scrim and drawer open/close. T-CHROME-15. */

const OPEN_CLASS = 'on';

export const closeAll = () => {
    document.querySelectorAll('.drawer.on, .mnav.on, .msub.on').forEach((el) => el.classList.remove(OPEN_CLASS));
    document.getElementById('ov')?.classList.remove(OPEN_CLASS);
    document.body.classList.remove('kbb-locked');
    // The shop's Filters drawer on a phone (Lane FP): its dimmed backdrop
    // carries data-kbb-close, and Esc lands here too.
    document.body.classList.remove('filters-open');
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
            // ON THE CART PAGE THE BAG ICON IS A RELOAD, NOT A PANEL (Lane QK5).
            // The owner: "the cart panel icon should not open the cart panel on
            // cart page at all. it will just refresh the cart page." #cartPage
            // is the cart view's own wrapper and is rendered nowhere else, so a
            // link here is left to navigate to its own href (the cart URL) and
            // anything that is not a link reloads. Every other page still opens
            // the drawer below.
            if (opener.dataset.kbbOpen === 'cart' && document.getElementById('cartPage')) {
                if (!(opener instanceof HTMLAnchorElement && opener.href)) location.reload();
                return;
            }
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
