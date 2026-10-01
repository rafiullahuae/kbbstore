/**
 * "Load more on scroll" for the product listings.               (Lane PI-B)
 *
 * Appearance → Site layout → Loading more products. Only when that is set to
 * "Load more on scroll" does the pager carry data-load="scroll", and only then
 * does any of this run; "Arrows" and "Load all" are server-side and need no
 * script at all.
 *
 * WHAT IT DOES. The pager (partials/listing-pager.blade.php) is an ordinary
 * set of links — the no-JavaScript fallback, unchanged. This takes it over:
 * hides the numbers, watches the pager with IntersectionObserver, and when it
 * comes within 600px of the viewport fetches the rel="next" page with
 * `kbbbatch=1`, which the same controller answers with that page's cards
 * (App\Support\ListingBatch). Grey placeholder cards stand in while it is in
 * flight. The address bar follows with history.replaceState(), so a reload or
 * a shared link lands on the page the shopper had reached. Any failure puts
 * the numbered links back.
 *
 * NOTHING HERE MEASURES LAYOUT. IntersectionObserver reports an intersection
 * the browser has already computed; no element is asked for its size or its
 * position, and the placeholders take the grid's own columns.
 */

const ROOT_MARGIN = '0px 0px 600px 0px';
const MAX_PLACEHOLDERS = 12;

/** A URL on this origin, or null — a batch is never fetched from anywhere else. */
function sameOrigin(href) {
    try {
        const url = new URL(href, window.location.href);

        return url.origin === window.location.origin ? url : null;
    } catch {
        return null;
    }
}

function placeholders(grid, count) {
    const made = [];

    for (let i = 0; i < count; i++) {
        const card = document.createElement('div');
        card.className = 'kbb-pskel';
        card.setAttribute('aria-hidden', 'true');
        card.append(document.createElement('i'), document.createElement('b'), document.createElement('b'));
        grid.append(card);
        made.push(card);
    }

    return made;
}

export function initListingLoad() {
    const pager = document.querySelector('.kbb-pager[data-load="scroll"]');

    if (!pager || !('IntersectionObserver' in window) || typeof window.fetch !== 'function') return;

    const grid = document.querySelector(pager.dataset.grid || '#grid');
    const nextLink = pager.querySelector('a[rel="next"]');

    if (!grid || !nextLink) return;

    let next = sameOrigin(nextLink.getAttribute('href'));

    if (!next) return;

    const status = pager.querySelector('.kbb-pager-status');
    const batch = Math.max(1, Math.min(MAX_PLACEHOLDERS, parseInt(pager.dataset.batch || '4', 10) || 4));
    let busy = false;
    let observer = null;

    const say = (text) => {
        if (status) status.textContent = text;
    };

    const giveBack = () => {
        observer?.disconnect();
        pager.classList.remove('is-auto');
        say('');
    };

    const load = async () => {
        if (busy || !next) return;

        busy = true;
        const waiting = placeholders(grid, batch);
        say(status?.dataset.loading || '');

        try {
            const url = new URL(next.href);
            url.searchParams.set('kbbbatch', '1');

            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error('batch ' + response.status);

            const data = await response.json();

            waiting.forEach((card) => card.remove());

            // A whole Blade view, rendered and escaped by Blade on the server —
            // raw on purpose, exactly as cart.js inserts data.drawer.
            grid.insertAdjacentHTML('beforeend', String(data.html || ''));

            const here = data.url ? sameOrigin(data.url) : null;

            if (here) window.history.replaceState(window.history.state, '', here.pathname + here.search);

            next = data.next ? sameOrigin(data.next) : null;
            say('');

            if (!next) {
                observer?.disconnect();
                pager.hidden = true;
            }
        } catch (error) {
            waiting.forEach((card) => card.remove());
            giveBack();
            console.error('[kbb] the next batch did not load; the page links are back.', error);
        } finally {
            busy = false;
        }

        // Still in view after the batch landed (a tall screen, a short batch):
        // observing again makes the browser report the intersection afresh.
        if (next && observer) {
            observer.unobserve(pager);
            observer.observe(pager);
        }
    };

    observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) load();
    }, { rootMargin: ROOT_MARGIN });

    pager.classList.add('is-auto');
    observer.observe(pager);
}
