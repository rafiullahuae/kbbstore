/**
 * The product page's "Authenticity Guaranteed" panel and share bar. (Lane PW)
 *
 * Three jobs, and none of them measures the page:
 *
 *   1. Flip aria-expanded on the authenticity button. The CSS slides the panel
 *      from that one attribute (a grid row from 0fr to 1fr) — nothing here asks
 *      how tall anything is. CLAUDE.md rule 4; ProductTrustShareTest reads this
 *      file for the element-measuring APIs by name.
 *   2. Close it from the red ×, or Escape, and put focus back on the button
 *      that opened it, so a keyboard user is not left on an element that has
 *      just become invisible.
 *   3. Copy link (clipboard, with a fallback and a "Link copied" status) and
 *      the phone's own share sheet behind "More".
 *
 * Every string shown is read from a data-* attribute the server escaped, and
 * is written with textContent — never innerHTML.
 */

function initAuthenticity(root) {
    const btn = root.querySelector('.pts-auth-btn');
    const panel = btn ? document.getElementById(btn.getAttribute('aria-controls') || '') : null;
    const close = root.querySelector('.pts-auth-x');

    if (!btn || !panel) return;

    const isOpen = () => btn.getAttribute('aria-expanded') === 'true';

    const set = (open, returnFocus) => {
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open && returnFocus) btn.focus();
    };

    btn.addEventListener('click', () => set(!isOpen(), false));

    if (close) close.addEventListener('click', () => set(false, true));

    root.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isOpen()) {
            e.preventDefault();
            set(false, true);
        }
    });
}

let copiedTimer = 0;

function announce(status, ok, url) {
    if (!status) return;
    status.classList.toggle('fail', !ok);
    status.textContent = ok ? (status.dataset.ok || '') : `${status.dataset.fail || ''} ${url}`.trim();
    status.classList.add('on');
    window.clearTimeout(copiedTimer);
    copiedTimer = window.setTimeout(() => {
        status.classList.remove('on');
        // Emptied after the fade so the live region is quiet until next time.
        window.setTimeout(() => { status.textContent = ''; }, 220);
    }, ok ? 2200 : 6000);
}

function legacyCopy(text) {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.insetBlockStart = '0';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    area.remove();
    return ok;
}

function initShare(root) {
    const status = root.querySelector('.pts-copied');

    root.querySelectorAll('[data-pts-copy]').forEach((b) => {
        b.addEventListener('click', async () => {
            const url = b.getAttribute('data-pts-copy') || '';
            let ok = false;

            if (navigator.clipboard && window.isSecureContext) {
                try { await navigator.clipboard.writeText(url); ok = true; } catch (e) { ok = false; }
            }

            if (!ok) ok = legacyCopy(url);

            announce(status, ok, url);
        });
    });

    const more = root.querySelector('[data-pts-native]');

    // A phone-width screen with a share sheet. matchMedia asks the viewport a
    // question; it measures no element.
    if (more && typeof navigator.share === 'function' && window.matchMedia('(max-width: 880px)').matches) {
        more.hidden = false;
        more.addEventListener('click', () => {
            navigator.share({
                title: more.dataset.title || document.title,
                text: more.dataset.text || '',
                url: more.dataset.url || window.location.href,
            }).catch(() => {});
        });
    }
}

document.querySelectorAll('[data-pts-auth]').forEach(initAuthenticity);
document.querySelectorAll('.pts-share').forEach(initShare);
