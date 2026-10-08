/**
 * "You may also like" — the carousel's arrows, its keyboard and its optional
 * autoplay.                                                      (Lane PS)
 *
 * EVERYTHING THAT DECIDES A SIZE IS CSS. A card is `(row − gaps) ÷ cards in
 * view` wide in kbb-product.css, the row snaps with scroll-snap, a phone
 * swipes it natively, and the focused track scrolls with the arrow keys
 * natively. This file adds only what CSS cannot: two buttons that move it, the
 * buttons going grey at either end, and autoplay when the owner switches it
 * on (Appearance → Product page → You may also like). Without this file the
 * carousel still works; it just has no working arrows.
 *
 * ── NO ELEMENT GEOMETRY IS READ TO SIZE ANYTHING ─────────────────────────────
 *
 *  · WHICH END WE ARE AT is asked of an IntersectionObserver on the first and
 *    the last card — the browser says when either is fully in view. No
 *    scrollLeft arithmetic, so it is right in RTL, where scrollLeft runs
 *    negative, without a branch for it.
 *
 *  · HOW FAR AN ARROW MOVES is the cart page's rule (partials/address-sheet,
 *    "A SCREENFUL LESS A SLIVER"): 86% of the row's visible width, and
 *    scroll-snap then settles it on a card's edge. That is how far to scroll
 *    when a button is pressed, not how big anything is, and it cannot affect a
 *    paint that has already happened.
 *
 *  · DIRECTION is the track's computed `direction`, read once: in a
 *    right-to-left page "next" is a NEGATIVE scrollBy.
 */
export function initAlsoLike() {
    document.querySelectorAll('[data-rp-tabs]').forEach(setUpTabs);
    document.querySelectorAll('[data-ymal]').forEach(setUp);
}

/* ── (Lane RP) BLOCK 1'S TWO TABS: "More from {brand}" | "More {category}" ──
   Both panels are in the HTML; the first is open. If the shopper clicked
   through from a brand or category listing, shop.js left that listing's path
   in sessionStorage (HINT) and the panel listing that path in its
   `data-rp-paths` opens instead. The stored value is only ever COMPARED with
   strings the server printed — never written into the page — and anything
   that is not a short absolute path is ignored. No request, no geometry read;
   the block is at the foot of the page, so the swap happens before it is in
   view. */
const HINT = 'kbb_rp_from';

function setUpTabs(root) {
    const tabs = Array.prototype.slice.call(root.querySelectorAll('[data-rp-tab]'));
    if (tabs.length < 2) return;

    const panelOf = (tab) => document.getElementById(tab.getAttribute('aria-controls') || '');

    const show = (tab, focus) => {
        tabs.forEach((t) => {
            const on = t === tab;
            const panel = panelOf(t);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
    };

    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => show(tab, false));
        // Arrow keys move along the tab list (WAI-ARIA tabs), mirrored in RTL.
        tab.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const rtl = getComputedStyle(root).direction === 'rtl';
            const step = (e.key === 'ArrowRight') !== rtl ? 1 : -1;
            e.preventDefault();
            show(tabs[(i + step + tabs.length) % tabs.length], true);
        });
    });

    let from = null;
    try {
        from = window.sessionStorage.getItem(HINT);
    } catch {
        from = null;
    }

    if (typeof from !== 'string' || from.length > 300 || from.charAt(0) !== '/') return;

    const hit = tabs.find((t) => {
        const panel = panelOf(t);
        return panel && (panel.getAttribute('data-rp-paths') || '').split(' ').indexOf(from) !== -1;
    });

    if (hit) show(hit, false);
}

function setUp(root) {
    const track = root.querySelector('[data-ymal-track]');
    const prev = root.querySelector('[data-ymal-prev]');
    const next = root.querySelector('[data-ymal-next]');

    if (!track || !track.firstElementChild) {
        return;
    }

    const cards = track.children;
    const first = cards[0];
    const last = cards[cards.length - 1];
    const rtl = getComputedStyle(track).direction === 'rtl';
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let atStart = true;
    let atEnd = cards.length < 2;

    const paint = () => {
        if (prev) prev.disabled = atStart;
        if (next) next.disabled = atEnd;
    };

    if ('IntersectionObserver' in window) {
        const ends = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                const whole = e.isIntersecting && e.intersectionRatio > 0.95;
                if (e.target === first) atStart = whole;
                if (e.target === last) atEnd = whole;
            });
            paint();
        }, { root: track, threshold: [0, 0.95, 1] });

        ends.observe(first);
        if (last !== first) ends.observe(last);
    } else {
        // No observer: leave both arrows usable rather than guessing.
        atStart = false;
        atEnd = false;
    }

    paint();

    const move = (dir) => {
        track.scrollBy({
            left: track.clientWidth * 0.86 * dir * (rtl ? -1 : 1),
            behavior: reduce ? 'auto' : 'smooth',
        });
    };

    if (prev) prev.addEventListener('click', () => { stop(); move(-1); });
    if (next) next.addEventListener('click', () => { stop(); move(1); });

    /* ── AUTOPLAY, OFF UNLESS THE OWNER SWITCHED IT ON ───────────────────────
       Never for a visitor who has asked for reduced motion. Paused while the
       pointer is over it or focus is inside it, and stopped for good the first
       time the shopper touches it, presses an arrow or uses a key: a carousel
       that carries on moving under someone who has started reading it is the
       thing everyone hates about carousels. Only while it is on screen and the
       tab is visible. */
    const seconds = Number(root.getAttribute('data-ymal-auto')) || 0;
    let timer = null;
    let stopped = seconds <= 0 || reduce;
    let hover = false;
    let onScreen = false;

    function stop() {
        stopped = true;
        if (timer) { clearInterval(timer); timer = null; }
    }

    const tick = () => {
        if (stopped || hover || !onScreen || document.hidden) return;

        if (atEnd) {
            track.scrollTo({ left: 0, behavior: 'smooth' });
        } else {
            move(1);
        }
    };

    if (!stopped) {
        timer = setInterval(tick, seconds * 1000);

        root.addEventListener('mouseenter', () => { hover = true; });
        root.addEventListener('mouseleave', () => { hover = false; });
        root.addEventListener('focusin', () => { hover = true; });
        root.addEventListener('focusout', () => { hover = false; });
        track.addEventListener('pointerdown', stop, { passive: true });
        track.addEventListener('touchstart', stop, { passive: true });
        track.addEventListener('wheel', stop, { passive: true });
        track.addEventListener('keydown', stop);

        if ('IntersectionObserver' in window) {
            new IntersectionObserver((entries) => {
                onScreen = entries.some((e) => e.isIntersecting);
            }).observe(track);
        } else {
            onScreen = true;
        }
    }
}
