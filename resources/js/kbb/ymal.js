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
    document.querySelectorAll('[data-ymal]').forEach(setUp);
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
    /* (Lane RP2) A block may show fewer cards on one device than the other:
       the extras are display:none there, so "the end" is the last card that
       device shows. `data-ymal-n` is "laptop phone" — printed only when they
       differ — and the phone is the page's own max-width:900px. A media
       query answers which, not a measurement. */
    const counts = (track.getAttribute('data-ymal-n') || '').split(' ').map(Number);
    const phone = counts.length === 2 && window.matchMedia ? window.matchMedia('(max-width: 900px)') : null;
    const lastAt = (n) => cards[Math.min(cards.length, n || cards.length) - 1];
    const ends = phone ? [lastAt(counts[0]), lastAt(counts[1])] : [cards[cards.length - 1]];
    const lastNow = () => (phone && phone.matches ? ends[1] : ends[0]);
    const whole = new Map();
    const rtl = getComputedStyle(track).direction === 'rtl';
    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let atStart = true;
    let atEnd = cards.length < 2;
    const settle = () => { atEnd = cards.length < 2 || !!whole.get(lastNow()); };

    const paint = () => {
        if (prev) prev.disabled = atStart;
        if (next) next.disabled = atEnd;
    };

    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver((entries) => {
            entries.forEach((e) => {
                const full = e.isIntersecting && e.intersectionRatio > 0.95;
                if (e.target === first) atStart = full;
                whole.set(e.target, full);
            });
            settle();
            paint();
        }, { root: track, threshold: [0, 0.95, 1] });

        io.observe(first);
        ends.forEach((el) => { if (el && el !== first) io.observe(el); });
        if (phone && phone.addEventListener) phone.addEventListener('change', () => { settle(); paint(); });
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
