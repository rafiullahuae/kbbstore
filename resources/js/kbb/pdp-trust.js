/**
 * The product page's "Authenticity Guaranteed" panel (Lane PW) and the share
 * sheet the title's share icon opens (Lane QB).
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
 *   3. The share sheet: open it from any [data-share-open] button (Lane QA
 *      draws one beside the title; this listens on the document, so it works
 *      wherever that sits), close it from ×, the dimmed page, Escape or the
 *      phone's Back, keep focus inside it while it is open and hand it back
 *      to the button after; Copy (clipboard, a fallback, "Link copied"); and
 *      "More" — the device's own share menu, with the product PICTURE as a
 *      file where navigator.canShare() says the device takes one.
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

let toastTimer = 0;

function announce(status, ok, url) {
    if (!status) return;
    status.classList.toggle('fail', !ok);
    status.textContent = ok ? (status.dataset.ok || '') : `${status.dataset.fail || ''} ${url}`.trim();
    status.classList.add('on');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => {
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

/* ── the share sheet ───────────────────────────────────────────────────────
 *
 * OPEN: `hidden` comes off, then — two animation frames later, so the closed
 * transform has been painted once — `is-open` goes on and the CSS slides the
 * panel up (a phone) or fades the card in (a laptop). No layout is read to
 * make that happen; a frame is a frame.
 *
 * CLOSE: `is-open` comes off, the CSS slides it back down, and `hidden` goes
 * back on after the slide (at once under prefers-reduced-motion, where there
 * is no slide). Focus returns to the button that opened it.
 *
 * BACK ON A PHONE closes the sheet rather than leaving the page: opening
 * pushes one history entry (same URL, nothing else changes), Back pops it and
 * popstate closes the sheet. Closing any other way pops that entry itself, so
 * history is exactly as it was before the sheet opened.
 */
const sheet = document.getElementById('pdpShareSheet');
const SLIDE_MS = 340;
let opener = null;
let hideTimer = 0;
let pushed = false;
let shareFile = null;
let fileAsked = false;

const motionOff = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const isOpen = () => !!sheet && !sheet.hidden && sheet.classList.contains('is-open');

function focusables() {
    return Array.from(sheet.querySelectorAll('button, a[href]'))
        .filter((el) => !el.disabled && !el.closest('[hidden]'));
}

/* The picture as a File, fetched ONCE, when the sheet first opens — so that
   pressing More can call navigator.share() straight away, inside the tap's own
   user activation. Awaiting a download inside the tap is how Safari ends up
   refusing the share as "not triggered by the user". Same origin always (the
   server hands a root-relative path), so no CORS question arises. */
function prefetchFile() {
    if (fileAsked || !sheet) return;
    fileAsked = true;
    const more = sheet.querySelector('[data-share-native]');
    const path = more ? more.getAttribute('data-file') : '';
    if (!more || !path || typeof navigator.canShare !== 'function' || typeof File !== 'function') return;
    fetch(path, { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.blob() : Promise.reject(r.status)))
        .then((blob) => {
            const name = (path.split('/').pop() || 'product.jpg').replace(/\.[a-z0-9]+\.jpg$/i, '.jpg');
            const file = new File([blob], name, { type: 'image/jpeg' });
            try { if (navigator.canShare({ files: [file] })) shareFile = file; } catch (e) { shareFile = null; }
        })
        .catch(() => { shareFile = null; });
}

function openSheet(btn) {
    if (!sheet || isOpen()) return;
    opener = btn || null;
    window.clearTimeout(hideTimer);
    sheet.hidden = false;
    document.documentElement.classList.add('pdp-share-lock');
    if (opener) opener.setAttribute('aria-expanded', 'true');
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => sheet.classList.add('is-open')));
    const first = focusables()[0];
    if (first) first.focus({ preventScroll: true });
    try { window.history.pushState({ pdpShare: true }, ''); pushed = true; } catch (e) { pushed = false; }
    prefetchFile();
}

function closeSheet(fromHistory) {
    if (!sheet || sheet.hidden) return;
    sheet.classList.remove('is-open');
    document.documentElement.classList.remove('pdp-share-lock');
    const done = () => { sheet.hidden = true; };
    if (motionOff()) done(); else hideTimer = window.setTimeout(done, SLIDE_MS);
    if (opener) {
        opener.setAttribute('aria-expanded', 'false');
        opener.focus({ preventScroll: true });
    }
    if (pushed) {
        pushed = false;
        if (!fromHistory) window.history.back();
    }
}

async function copyLink(btn) {
    const url = btn.getAttribute('data-share-copy') || '';
    let ok = false;
    if (navigator.clipboard && window.isSecureContext) {
        try { await navigator.clipboard.writeText(url); ok = true; } catch (e) { ok = false; }
    }
    if (!ok) ok = legacyCopy(url);
    announce(sheet.querySelector('.pdp-share-toast'), ok, url);
}

function shareNative(btn) {
    const title = btn.dataset.title || document.title;
    const text = btn.dataset.text || '';
    const url = btn.dataset.url || window.location.href;
    // With the picture, the link rides in the text: several Android targets
    // drop `url` when files are present, and the link is what previews.
    const data = shareFile
        ? { files: [shareFile], title, text: `${text}\n${url}`.trim() }
        : { title, text, url };
    navigator.share(data).catch(() => {});
}

if (sheet) {
    // "More" only where the device has a share menu to open.
    const moreLi = sheet.querySelector('[data-share-more]');
    if (moreLi && typeof navigator.share === 'function') moreLi.hidden = false;

    // A phone (a coarse pointer) gets the app links — Messenger's own share
    // composer, Snapchat's camera with the link attached; a laptop keeps the
    // web forms the server put in href. matchMedia asks the device a question;
    // it measures no element.
    if (window.matchMedia('(pointer: coarse)').matches) {
        sheet.querySelectorAll('[data-app-href]').forEach((a) => {
            const app = a.getAttribute('data-app-href') || '';
            if (!/^(https:|fb-messenger:)/.test(app)) return;
            a.setAttribute('href', app);
            // An app scheme opened in a new tab leaves a blank tab behind.
            if (!app.startsWith('https:')) { a.removeAttribute('target'); }
        });
    }

    document.addEventListener('click', (e) => {
        const t = e.target instanceof Element ? e.target : null;
        if (!t) return;
        const trigger = t.closest('[data-share-open]');
        if (trigger) { e.preventDefault(); openSheet(trigger); return; }
        if (sheet.hidden) return;
        if (t.closest('[data-share-close]')) { e.preventDefault(); closeSheet(false); return; }
        const copy = t.closest('[data-share-copy]');
        if (copy) { copyLink(copy); return; }
        const more = t.closest('[data-share-native]');
        if (more && typeof navigator.share === 'function') shareNative(more);
    });

    document.addEventListener('keydown', (e) => {
        if (sheet.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); closeSheet(false); return; }
        if (e.key !== 'Tab') return;
        // Focus stays inside the dialog while it is open (aria-modal).
        const list = focusables();
        if (!list.length) return;
        const first = list[0];
        const last = list[list.length - 1];
        if (e.shiftKey && (document.activeElement === first || !sheet.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && (document.activeElement === last || !sheet.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    });

    window.addEventListener('popstate', () => { if (pushed) { pushed = false; closeSheet(true); } });
}

document.querySelectorAll('[data-pts-auth]').forEach(initAuthenticity);
