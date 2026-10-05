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
 * flight. Any failure puts the numbered links back.
 *
 * ── THE ADDRESS STAYS PUT (2.60.405) ───────────────────────────────────────
 *
 * The owner: "i don't need that the url changed from pages 1-2-3 etc. like
 * this https://extrabeauty.ae/super-sale?page=2 i want the url must not
 * change, only the more products loads". The address bar used to follow each
 * batch with history.replaceState(); now it does that only when Appearance ->
 * Site layout -> "Show the page number in the address" is switched on (the
 * pager then carries data-url="follow"). Off by default, as he asked.
 *
 * Coming BACK from a product page: the browser's back-forward cache restores
 * the page as it was -- every batch, the scroll position -- with nothing for
 * this file to do. When a browser cannot keep the page, how many batches were
 * showing and where the shopper was are saved on `pagehide` (sessionStorage,
 * this tab only) and replayed on a back/forward visit: the same batches,
 * fetched one after another, then the same scroll position. No layout is
 * measured: scrollY is the window's own scroll offset.
 *
 * ── ONE BATCH AHEAD, ALWAYS (2.60.355) ─────────────────────────────────────
 *
 * The owner: "on slow internet it keeps displaying the grey loading stuff. i
 * want that somehow the products path etc should pre load upon page open."
 * Measured before this: the batch was not even ASKED FOR until the pager came
 * within 600px, so on a slow connection the grey cards stood for the whole
 * round trip, and the pictures only started after the cards went in.
 *
 * Now the next batch is fetched as soon as the page has finished loading
 * (window `load`, then an idle moment, at low priority, so it never competes
 * with the page's own pictures), and its pictures are warmed into the cache
 * through detached `Image` objects given the card's own `srcset`/`sizes` --
 * the browser picks the same file the card will ask for. When the shopper
 * gets near the end the batch is already here: it goes in with no grey cards
 * and its pictures come from the cache, and the one after it starts at once.
 * The grey cards remain only for a batch that is genuinely still on its way.
 * Save-Data (the phone's "data saver") keeps the batch prefetch -- a few KB of
 * HTML -- and skips the picture warming.
 *
 * NOTHING HERE MEASURES LAYOUT. IntersectionObserver reports an intersection
 * the browser has already computed; no element is asked for its size or its
 * position, and the placeholders take the grid's own columns.
 */

// Starts 1200px early (was 600px): with the batch already fetched, starting
// sooner costs nothing and hides the join completely on a fast flick.
const ROOT_MARGIN = '0px 0px 1200px 0px';
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
    const saveData = Boolean(navigator.connection && navigator.connection.saveData);
    let busy = false;
    let observer = null;
    let near = false;

    // The batch fetched ahead: { url, promise, data } -- `data` is set the
    // moment it lands, so "is it here yet?" is a property read, not a race.
    let ahead = null;

    // Batches put on the page so far, for the back-button restore above.
    let shown = 0;
    const followUrl = pager.dataset.url === 'follow';
    const memoKey = 'kbb.ll:' + window.location.pathname + window.location.search;

    const say = (text) => {
        if (status) status.textContent = text;
    };

    const giveBack = () => {
        observer?.disconnect();
        pager.classList.remove('is-auto');
        say('');
    };

    const fetchBatch = async (target) => {
        const url = new URL(target.href);
        url.searchParams.set('kbbbatch', '1');

        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            priority: 'low',
        });

        if (!response.ok) throw new Error('batch ' + response.status);

        return response.json();
    };

    // The batch's pictures, into the HTTP cache before its cards exist. A
    // <template> parses without fetching anything; each Image is given the
    // card's own sizes and srcset, so the file it downloads is the one the
    // card will choose. Attributes are read, nothing is measured.
    const warm = (html) => {
        if (saveData || !html) return;

        const parsed = document.createElement('template');
        parsed.innerHTML = html;

        parsed.content.querySelectorAll('img').forEach((card) => {
            const pic = new Image();
            pic.decoding = 'async';
            if ('fetchPriority' in pic) pic.fetchPriority = 'low';
            if (card.getAttribute('sizes')) pic.sizes = card.getAttribute('sizes');
            if (card.getAttribute('srcset')) pic.srcset = card.getAttribute('srcset');
            pic.src = card.getAttribute('src') || '';
        });
    };

    const fetchAhead = () => {
        // busy: load() is already fetching `next` and calls this itself when
        // it lands; asking now would fetch the same batch twice.
        if (ahead || busy || !next) return;

        const entry = { url: next.href, data: null, promise: null };
        entry.promise = fetchBatch(next).then((data) => {
            entry.data = data;
            warm(String(data.html || ''));

            return data;
        });
        // A failed prefetch is not a failure yet: load() asks again itself.
        entry.promise.catch(() => {
            if (ahead === entry) ahead = null;
        });
        ahead = entry;
    };

    // After the page's own pictures, in an idle moment.
    const whenQuiet = (task) => {
        const idle = () => (window.requestIdleCallback ? window.requestIdleCallback(task, { timeout: 1500 }) : window.setTimeout(task, 200));

        if (document.readyState === 'complete') idle();
        else window.addEventListener('load', idle, { once: true });
    };

    const load = async () => {
        if (busy || !next) return;

        busy = true;
        let waiting = [];

        try {
            const ready = ahead && ahead.url === next.href ? ahead : null;
            let data = ready ? ready.data : null;

            if (!data) {
                // Still on its way, or never asked for: the grey cards stand in.
                waiting = placeholders(grid, batch);
                say(status?.dataset.loading || '');
                data = ready ? await ready.promise.catch(() => fetchBatch(next)) : await fetchBatch(next);
            }

            ahead = null;
            waiting.forEach((card) => card.remove());
            waiting = [];

            // A whole Blade view, rendered and escaped by Blade on the server —
            // raw on purpose, exactly as cart.js inserts data.drawer.
            grid.insertAdjacentHTML('beforeend', String(data.html || ''));

            const here = data.url ? sameOrigin(data.url) : null;

            if (here && followUrl) window.history.replaceState(window.history.state, '', here.pathname + here.search);
            shown += 1;

            next = data.next ? sameOrigin(data.next) : null;
            say('');

            if (!next) {
                observer?.disconnect();
                pager.hidden = true;
            } else {
                // The one after this, straight away.
                fetchAhead();
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
        if (next && observer && near) {
            observer.unobserve(pager);
            observer.observe(pager);
        }
    };

    observer = new IntersectionObserver((entries) => {
        near = entries.some((entry) => entry.isIntersecting);
        if (near) load();
    }, { rootMargin: ROOT_MARGIN });

    pager.classList.add('is-auto');

    if (!followUrl) {
        window.addEventListener('pagehide', () => {
            try {
                if (shown > 0) window.sessionStorage.setItem(memoKey, JSON.stringify({ n: shown, y: Math.round(window.scrollY) }));
                else window.sessionStorage.removeItem(memoKey);
            } catch {
                // Private mode or storage off: back simply starts at the top.
            }
        });
    }

    // A back/forward visit the cache could not keep: the same batches, then the same place.
    let memo = null;
    try {
        const nav = window.performance?.getEntriesByType?.('navigation')?.[0];
        if (!followUrl && nav && nav.type === 'back_forward') memo = JSON.parse(window.sessionStorage.getItem(memoKey) || 'null');
        window.sessionStorage.removeItem(memoKey);
    } catch {
        memo = null;
    }

    if (memo && memo.n > 0) {
        (async () => {
            for (let i = 0; i < Math.min(memo.n, 40) && next; i++) await load();
            window.scrollTo(0, Math.max(0, Number(memo.y) || 0));
            observer.observe(pager);
            whenQuiet(fetchAhead);
        })();

        return;
    }

    observer.observe(pager);
    whenQuiet(fetchAhead);
}
