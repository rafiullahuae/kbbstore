/**
 * Header search suggestions.
 *
 * The endpoint existed only in name until now, so this is the first time the
 * box does anything. Debounced, keyboard-navigable, and it aborts a request
 * that a newer keystroke has already superseded.
 */

import { t, esc } from './i18n.js';
import { escapeHtml, safeHref, cssUrl } from './safe.js';

/*
 * U+2192 IS NOT A MIRRORED CHARACTER. Measured in Chromium, each glyph centred
 * in a fixed box and rendered in both directions so only its shape can differ:
 * U+203A, U+2039, U+00BB and U+003E come back as their own mirror in a
 * right-to-left run; U+2192, U+2190, U+25B6, U+2794 and U+21A9 come back
 * identical. One bundle is served to both languages, so the glyph is read off
 * the document -- and off its DIRECTION rather than its language, because with
 * the mirrored layout switched off the page still reads left to right and
 * "onward" is still to the right.
 */
const onward = () => (document.documentElement.getAttribute('dir') === 'rtl' ? '←' : '→');

/* One shell for the whole panel: a close button and two columns. Both the
   starter and the results write into it rather than replacing it, so the
   right-hand column is never rebuilt while someone is typing. */
function ensureShell(panel) {
    if (panel.querySelector('.colA')) return;
    panel.innerHTML = '<button type="button" class="sgx" aria-label="' + esc(t('store.js.close_search', 'Close search')) + '">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round">'
        + '<path d="M6 6l12 12M18 6L6 18"/></svg></button>'
        + '<div class="colA"></div><div class="colB"></div>';
}
const setLeft = (panel, html) => {
    ensureShell(panel);
    const col = panel.querySelector('.colA');
    col.innerHTML = html;
    // The starter hides this column when it has nothing for it. Results write
    // here too, so anything written must bring it back — otherwise a phone
    // search renders into a column that is still display:none.
    col.hidden = html === '';
};
const setRight = (panel, html) => { ensureShell(panel); panel.querySelector('.colB').innerHTML = html; };

/* Loads (or reuses the already-cached) starter data and fills the right
   column with it if that hasn't happened yet. Called both from focusing
   the field and from the moment results come back, so the column is
   correct however the two requests happen to land relative to each other. */
function ensureRightColumn(panel) {
    if (panel.querySelector('.colB')?.innerHTML) return;

    const best = bestStarter(panel);
    (best ? Promise.resolve(best) : loadStarter()).then((data) => {
        if (!data || panel.querySelector('.colB')?.innerHTML) return;

        const trending = data.trending || [];
        const brands = data.brands || [];
        const mobile = window.matchMedia('(max-width: 900px)').matches;
        const wantBrands = panel.dataset.brandsPhone === '1';
        const showBrands = brands.length && (!mobile || wantBrands);

        setRight(panel, `<div class="sgh">Trending</div>${chipRow(trending)}`
            + (showBrands ? `<div class="sgh">Popular Brands</div>${chipRow(brands)}` : ''));

        // The phone is one column throughout; this only ever splits the
        // panel on a screen wide enough to hold both halves side by side.
        if (!mobile) panel.classList.add('two');
    });
}

export function initSearch() {
    const input = document.querySelector('.search-in input[type="search"]');
    if (!input) return;

    const wrap = input.closest('.search-wrap') || input.parentElement;
    let panel = document.getElementById('kbbSuggest');

    /* The theme already styles this: .sugg is shown with .on, and each row is
       an <a> containing .si (thumb), .sn (name, with a <small> for the meta)
       and .sp (price). Matching that contract means no new CSS at all. */
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'kbbSuggest';
        panel.className = 'sugg';
        wrap.appendChild(panel);
    }

    let timer = null;
    let controller = null;
    let index = -1;

    /* COUNTED ONCE, WHEN THE SEARCH SETTLES (Growth -> Search Terms).
       The panel asks on every keystroke; the server counts a term only when
       the request says `log=1`, which happens once per term per page: after
       1.5s with no more typing, on Enter, or when a result is picked. So
       "medicube" is counted and "me", "med", "medi" are not. The request is
       the same cached answer the panel already had. */
    let settle = null;
    const logged = new Set();
    const logTerm = (q) => {
        const key = (q || '').trim().toLowerCase();
        if (key.length < 2 || logged.has(key)) return;
        logged.add(key);
        try {
            fetch(`${window.KBB.routes.search}?q=${encodeURIComponent(key)}&log=1`, {
                headers: { Accept: 'application/json' },
                keepalive: true,
            }).catch(() => {});
        } catch (e) { /* counting must never break searching */ }
    };

    const close = () => { panel.classList.remove('on'); index = -1; };

    /*
     * The panel is built once and kept. Results replace the left column only,
     * so the trending and brand column never moves while typing — previously
     * the whole panel was rewritten, which threw that column away on the first
     * keystroke.
     */
    const paintLeft = (html) => {
        setLeft(panel, html);
        // Results occupy the whole panel on a phone, where there is one column.
        panel.querySelector('.colA').hidden = false;
    };

    const rows = () => [...panel.querySelectorAll('[data-sg-row]')];

    const highlight = () => {
        rows().forEach((r, i) => r.classList.toggle('on', i === index));
        rows()[index]?.scrollIntoView({ block: 'nearest' });
    };

    const render = (data) => {
        // The right column (Trending / Popular Brands) is meant to stay
        // exactly where it is through a search — it's the left column that
        // changes shape, from "Most searched" + "Popular now" to "Products"
        // + "Brands". This call makes sure that column is actually there:
        // if the starter fetch from focusing the field hasn't resolved yet,
        // this catches it up instead of leaving it empty until some later
        // search happens to land after that fetch finally finishes.
        ensureRightColumn(panel);

        if (!data.total) {
            paintLeft(`<div class="sgh">No matches</div>`
                + `<a data-sg-row href="${safeHref(data.all_url)}"><span class="sn">Search anyway for “${escapeHtml(data.query)}”</span></a>`);
            panel.classList.add('on');
            index = -1;
            return;
        }

        // Brands take a row each and push products off a small screen, so on a
        // phone they are left out unless the setting asks for them.
        const phone = window.matchMedia('(max-width: 900px)').matches;
        const wantBrands = panel.dataset.brandsPhone === '1';
        const groups = (phone && !wantBrands)
            ? data.groups.filter((g) => g.key !== 'brands')
            : data.groups;

        paintLeft(groups.map((g) => `<div class="sgh">${escapeHtml(g.label)}</div>`
            + g.items.map((it) => {
                /* The image goes through cssUrl(), which may refuse it — and a
                   refused address must fall to the gradient rather than draw
                   `url('')`, which makes the browser fetch this very page and
                   try to decode it as a picture. So the decision is made once,
                   here, and not inside the template. */
                const bg = cssUrl(it.image);

                return `<a data-sg-row href="${safeHref(it.url)}">`
                + `<span class="si" style="${bg
                        ? `background:#fff url('${bg}') center/cover`
                        : `background:${escapeHtml(it.colour || 'linear-gradient(135deg,#ffd1e2,#ff9fc1)')}`}">${bg ? '' : escapeHtml(it.initials || '')}</span>`
                + `<span class="sn">${escapeHtml(it.label)}`
                + (it.meta ? `<small>${escapeHtml(it.meta)}</small>` : '')
                + `</span>`
                + (it.price ? `<span class="sp">${escapeHtml(it.price)}</span>` : '')
                + `</a>`;
            }).join('')).join('')
            + `<a class="viewall" data-sg-row href="${safeHref(data.all_url)}">View all results <b>(${escapeHtml(data.total)} found)</b> <i>${onward()}</i></a>`);

        panel.classList.add('on');
        index = -1;
    };

    const search = async (q) => {
        // A newer keystroke makes the in-flight request pointless.
        controller?.abort();
        controller = new AbortController();

        try {
            const response = await fetch(`${window.KBB.routes.search}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            render(await response.json());
            clearTimeout(settle);
            settle = setTimeout(() => { if (input.value.trim() === q) logTerm(q); }, 1500);
        } catch (error) {
            if (error.name !== 'AbortError') close();
        }
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(timer);
        clearTimeout(settle);

        if (q.length < 2) { close(); return; }

        timer = setTimeout(() => search(q), 180);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') logTerm(input.value);
    });
    panel.addEventListener('click', (event) => {
        if (event.target.closest && event.target.closest('[data-sg-row]')) logTerm(input.value);
    });
    input.addEventListener('keydown', (event) => {
        if (!panel.classList.contains('on')) return;

        const all = rows();

        if (event.key === 'ArrowDown') { event.preventDefault(); index = (index + 1) % all.length; highlight(); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); index = (index - 1 + all.length) % all.length; highlight(); }
        else if (event.key === 'Enter' && index >= 0) { event.preventDefault(); all[index].click(); }
        else if (event.key === 'Escape') { close(); input.blur(); }
    });

    // Clicking away closes it; clicking inside must not, or the link never fires.
    document.addEventListener('click', (event) => {
        if (!wrap.contains(event.target)) close();
    });

    input.addEventListener('focus', () => {
        if (input.value.trim().length >= 2 && panel.innerHTML) panel.classList.add('on');
    });
}

/* ═══════════════════════════════════════════════════════════════
   THIS PANEL BUILDS HTML OUT OF /api/search AND SETS innerHTML.

   Every value above reaches the page through a template string, so an
   address carrying a double quote closed the attribute it sat in and the
   next token was read as a new attribute -- an event handler, on a panel
   every shopper opens. That is SCRIPT injection and not the CSS-context
   kind the Blade surfaces had; it was found while fixing those and is the
   bigger of the two.

   Nothing here was ever attacker-controlled while the owner typed his own
   product names and image paths. The WordPress import is about to write
   thousands of them from a database this shop did not author, which is why
   it is fixed now rather than noted.

   escapeHtml / schemeIsServed / safeHref / cssUrl WERE DEFINED HERE and now
   live in ./safe.js, unchanged. The sweep that followed this fix found the
   same three contexts in three more files, and four copies of a security
   helper is four things to keep in step with App\Support\CssUrl rather than
   one. Only the definitions moved; every call site above is the same call it
   was, and safe.js carries the argument the block here used to.
   ═══════════════════════════════════════════════════════════════ */


/* ═══════════════════════════════════════════════════════════════
   The panel before anything is typed.

   Clicking the field shows trending straight away. What the desktop
   panel does with its second column is a setting, because the honest
   answer depends on the shop:

     wide-then-two  one full-width panel, splitting once typing starts
     two-columns    products left, trending right, shape never changes
     recent-left    as above, with the week's most-searched terms first

   The phone is one column throughout, so none of this applies there.
   ═══════════════════════════════════════════════════════════════ */
/*
 * NO WAIT BETWEEN THE TAP AND THE PANEL. (2 October 2026) The owner: "the
 * default search tags box showing with little delays upon click on search
 * box. it must be shown immidiately". The focus handler awaited
 * /api/search/starter before it added `.on`, and the answer lived in a page
 * variable, so the first tap on EVERY page waited a full round trip.
 *
 * Now the panel paints in the same task as the focus, from the best data on
 * hand: this page's copy, else this tab's copy (sessionStorage), else the
 * trending words the header printed into #kbbSuggest[data-starter]. The fetch
 * still runs, in the background, and the panel is redrawn only if what came
 * back differs from what is on screen. tests/Feature/SearchStarterInstantTest.php.
 */
const STARTER_TTL = 5 * 60 * 1000;
const starterMobile = () => window.matchMedia('(max-width: 900px)').matches;
const starterKey = () => `kbb.starter.v1.${starterMobile() ? 'm' : 'd'}`;
let starterCache = {};
let starterInflight = {};

function storedStarter() {
    const key = starterKey();
    if (starterCache[key]) return starterCache[key];
    try {
        const kept = JSON.parse(sessionStorage.getItem(key) || 'null');
        if (kept && kept.data && Date.now() - kept.at < STARTER_TTL) {
            starterCache[key] = kept.data;
            return kept.data;
        }
    } catch (e) { /* storage blocked or malformed: fall through to the seed */ }
    return null;
}

/* The header's own copy: trending words only, so the panel can open before
   anything has been fetched. Popular products and brands arrive with the fetch. */
function seedStarter(panel) {
    try {
        const seed = JSON.parse(panel.dataset.starter || 'null');
        if (!seed || !Array.isArray(seed.trending)) return null;
        const trending = starterMobile() ? seed.trending.slice(0, seed.mobile || seed.trending.length) : seed.trending;
        return { trending, recent: [], popular: [], brands: [], layout: seed.layout, seed: true };
    } catch (e) {
        return null;
    }
}

const bestStarter = (panel) => storedStarter() || seedStarter(panel);

function loadStarter() {
    const key = starterKey();
    if (starterCache[key] && !starterCache[key].seed) return Promise.resolve(starterCache[key]);
    if (starterInflight[key]) return starterInflight[key];

    const base = (window.KBB && window.KBB.routes && window.KBB.routes.search) || '/api/search';

    starterInflight[key] = fetch(`${base}/starter?mobile=${starterMobile() ? 1 : 0}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
            if (data) {
                starterCache[key] = data;
                try { sessionStorage.setItem(key, JSON.stringify({ at: Date.now(), data })); } catch (e) { /* private mode */ }
            }
            return data;
        })
        .catch(() => null)
        .finally(() => { delete starterInflight[key]; });

    return starterInflight[key];
}

const chipRow = (items) =>
    `<div class="chips">${items.map((t) => `<a class="chip" data-sg-term="${escapeHtml(t)}">${escapeHtml(t)}</a>`).join('')}</div>`;

/* escapeHtml() alone was not enough on the image: it turns `'` into `&#39;`,
   which the HTML parser decodes back to a live quote BEFORE CSS reads the
   attribute — so the quote closed the url() and the rest of the address became
   declarations this shop did not write. cssUrl() escapes for CSS first. */
const productRow = (p) => {
    const bg = cssUrl(p.image);

    return `<a class="sgi" href="${safeHref(p.url)}"><i${bg ? ` style="background-image:url('${bg}')"` : ''}></i>
       <span><b>${escapeHtml(p.name)}</b><small>${escapeHtml(p.brand || '')} · ${escapeHtml(p.price)}</small></span></a>`;
};

function renderStarter(panel, data) {
    if (!data) return false;

    const trending = data.trending || [];
    const recent = data.recent || [];
    const popular = data.popular || [];
    const brands = data.brands || [];
    const mobile = window.matchMedia('(max-width: 900px)').matches;


    // Brands are a desktop nicety; on a phone they push the results down.
    const wantBrands = panel.dataset.brandsPhone === '1';
    const showBrands = brands.length && (!mobile || wantBrands);

    const right =
        `<div class="sgh">Trending</div>${chipRow(trending)}` +
        (showBrands ? `<div class="sgh">Popular Brands</div>${chipRow(brands)}` : '');

    const left =
        (data.layout === 'recent-left' && recent.length
            ? `<div class="sgh">Most searched this week</div>` +
              recent.map((t) => `<a class="sgi rec" data-sg-term="${escapeHtml(t)}"><em>${escapeHtml(t)}</em></a>`).join('')
            : '') +
        (popular.length ? `<div class="sgh">Popular right now</div>${popular.map(productRow).join('')}` : '');

    setRight(panel, right);

    // One column on a phone, and on the layout that starts wide. The right
    // column still holds the content; the panel simply does not split.
    const single = mobile || data.layout === 'wide-then-two' || !left;

    panel.classList.toggle('two', !single);
    setLeft(panel, single ? '' : left);
    panel.querySelector('.colA').hidden = single;

    return true;
}

export function initSearchStarter() {
    const input = document.querySelector('.search-in input[type="search"]');
    const panel = document.getElementById('kbbSuggest');
    if (!input || !panel) return;

    const shut = () => panel.classList.remove('on');

    /*
     * Three ways out, because a panel that covers the page with no way to
     * dismiss it traps whoever opened it.
     */
    /*
     * Two halves, and the split matters.
     *
     * pointerdown is taken first so the outside-click handler — also on
     * pointerdown, also capture — never sees it and cannot argue about whether
     * the button counts as inside the panel. But the panel is NOT closed here.
     *
     * Closing on pointerdown is what sent the shopper to a product page. A
     * preventDefault on pointerdown does not cancel the click that follows, and
     * the click's target is hit-tested when the finger lifts — by which time the
     * panel had already gone and whatever sat underneath it was what got
     * clicked. Leaving the panel up until the click means the button itself
     * receives that click, and nothing behind it does.
     */
    const swallow = (event) => {
        if (!event.target.closest || !event.target.closest('.sgx')) return;
        event.preventDefault();
        event.stopPropagation();
    };

    const onClose = (event) => {
        if (!event.target.closest || !event.target.closest('.sgx')) return;
        event.preventDefault();
        event.stopPropagation();
        shut();
        input.blur();
    };

    panel.addEventListener('pointerdown', swallow, true);
    panel.addEventListener('click', onClose, true);

    document.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) shut();
    });

    // Anything outside the field or the panel closes it. Capture phase, so a
    // click on a link underneath still closes even if that link stops the event.
    document.addEventListener('pointerdown', (event) => {
        if (panel.contains(event.target)) return;
        if (event.target.closest('.search-in')) return;
        shut();
    }, true);

    const starterOwnsPanel = () => input.value.trim().length < 2;   // results own it from 2 letters

    input.addEventListener('focus', () => {
        if (!starterOwnsPanel()) return;
        // Painted now, in this task. Nothing between the tap and the panel.
        const painted = bestStarter(panel);
        if (renderStarter(panel, painted)) panel.classList.add('on');
        // Redrawn only if the refresh differs from what is on screen; usually
        // it is exactly what the seed already painted.
        loadStarter().then((data) => {
            if (!data || JSON.stringify(data) === JSON.stringify(painted)) return;
            if (starterOwnsPanel() && panel.classList.contains('on')) renderStarter(panel, data);
        });
    });

    // Warm this tab's copy once the page is idle, so even the first tap on the
    // first page has the popular products as well as the trending words.
    if (!storedStarter()) {
        const warm = () => loadStarter();
        if ('requestIdleCallback' in window) window.requestIdleCallback(warm, { timeout: 4000 });
        else setTimeout(warm, 1500);
    }

    // Clicking a word searches for it rather than just filling the box.
    panel.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-sg-term]');
        if (!chip) return;
        event.preventDefault();
        input.value = chip.dataset.sgTerm;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    });

    // Emptying the field brings the starter back instead of leaving a blank.
    input.addEventListener('input', () => {
        if (input.value.trim().length !== 0) return;
        if (renderStarter(panel, bestStarter(panel))) panel.classList.add('on');
    });
}


/* ═══════════════════════════════════════════════════════════════
   Quick view.

   Everything this needs already existed and none of it was connected:
   product-card.blade.php renders the `.qv-btn` carrying data-kbb-qv,
   layouts/store.blade.php prints the `#kbbQv` shell and all of its CSS,
   and /quick-view/{id} answers with a rendered fragment — but no
   JavaScript anywhere in resources/js listened for the click. The button
   was inert on every grid in the store.

   Delegated from the document, so it covers every grid (shop, category,
   brand, search, the home rails) and any card rendered later, without
   each of them having to register anything of its own.

   The fragment injected carries `data-kbb-add`, which cart.js already
   picks up through its own delegated listener, so Add to cart inside the
   modal works with no further wiring.
   ═══════════════════════════════════════════════════════════════ */

/* Routes are prefixed by KBB_BASE_PATH on staging (/kbb-upgrade), so this
   path cannot be hardcoded. window.KBB.routes.home is Url::to('/') and
   carries that prefix; the endpoint is built from it. */
const kbbRoot = () =>
    ((window.KBB && window.KBB.routes && window.KBB.routes.home) || '/').replace(/\/+$/, '');

export function initQuickView() {
    const back = document.getElementById('kbbQv');

    // Module off at Store → Modules: the shell is not printed, and neither is
    // the button, so there is nothing to wire.
    if (!back) return;

    const slot = back.querySelector('.qv-slot');
    if (!slot) return;

    let controller = null;
    let opener = null;

    const reset = () => { slot.innerHTML = '<div class="qv-load">' + esc(t('store.quick_view.loading', 'Loading…')) + '</div>'; };

    const close = () => {
        if (back.hidden) return;

        back.hidden = true;
        controller?.abort();
        controller = null;
        reset();

        // Focus goes back to the card that opened it rather than being dropped
        // at the top of the document.
        opener?.focus?.();
        opener = null;
    };

    const open = async (id, button) => {
        opener = button;
        reset();
        back.hidden = false;
        back.querySelector('.qv-x')?.focus();

        // A second click while the first is still in flight wins.
        controller?.abort();
        controller = new AbortController();

        try {
            const response = await fetch(`${kbbRoot()}/quick-view/${encodeURIComponent(id)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            // A product that is not visible 404s here exactly as it does on its
            // own page, and nothing is rendered for it.
            if (!response.ok) { close(); return; }

            const data = await response.json();

            if (!data || !data.ok || !data.html) { close(); return; }

            // Server-rendered Blade on purpose: price, sale and stock rules stay
            // in one place instead of being restated in JavaScript.
            slot.innerHTML = data.html;
        } catch (error) {
            if (error.name !== 'AbortError') close();
        }
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest?.('[data-kbb-qv]');
        if (!button) return;

        // The card photo carries its own location.href handler; the button sits
        // on top of it and must not fall through to it.
        event.preventDefault();
        event.stopPropagation();

        const id = button.dataset.kbbQv;
        if (id) open(id, button);
    });

    // Three ways out: the ✕, the backdrop itself (never the panel on top of
    // it), and Escape.
    back.addEventListener('click', (event) => {
        if (event.target === back || event.target.closest?.('.qv-x')) {
            event.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });
}

/* Registered from this module rather than from app.js, which this lane does
   not own. app.js already imports search.js, so the listener is installed on
   load; the named export stays available if the integrator would rather add
   an explicit initQuickView() call to boot(). */
if (typeof document !== 'undefined') {
    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', initQuickView)
        : initQuickView();
}
