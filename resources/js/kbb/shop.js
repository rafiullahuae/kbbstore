/**
 * Shop archive: column selector.
 *
 * Sort and every filter are plain links or a select with an inline handler,
 * exactly as the theme has them — they work without JavaScript, which is what
 * makes each filtered view a real, crawlable URL.
 */

export function initShop() {
    // (2.60.393) The phone's own 1 / 2 column buttons: a separate pin
    // (?mcols=) so a phone choice never moves a laptop's columns.
    const colselm = document.getElementById('colselm');
    colselm?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-m]');
        if (!button) return;
        const grid = document.getElementById('grid');
        const url = new URL(window.location.href);
        if (button.dataset.m === '1') { grid?.setAttribute('data-mcols', '1'); url.searchParams.set('mcols', '1'); }
        else { grid?.removeAttribute('data-mcols'); url.searchParams.delete('mcols'); }
        colselm.querySelectorAll('[data-m]').forEach((b) => b.classList.toggle('on', b === button));
        window.history.replaceState({}, '', url.toString());
    });

    const colsel = document.getElementById('colsel');
    if (!colsel) return;

    colsel.addEventListener('click', (event) => {
        const button = event.target.closest('[data-c]');
        if (!button) return;

        const cols = button.dataset.c;

        // Applied immediately so the change feels instant, then written to the
        // URL so it survives a reload and can be shared.
        document.getElementById('grid')?.setAttribute('data-cols', cols);
        colsel.querySelectorAll('[data-c]').forEach((b) => b.classList.toggle('on', b === button));

        const url = new URL(window.location.href);
        url.searchParams.set('cols', cols);
        window.history.replaceState({}, '', url.toString());
    });
}
