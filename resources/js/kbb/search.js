/**
 * Header search suggestions.
 *
 * The endpoint existed only in name until now, so this is the first time the
 * box does anything. Debounced, keyboard-navigable, and it aborts a request
 * that a newer keystroke has already superseded.
 */


import { t, esc } from './i18n.js';

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

    loadStarter().then((data) => {
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
                + `<a data-sg-row href="${data.all_url}"><span class="sn">Search anyway for “${escapeHtml(data.query)}”</span></a>`);
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

        paintLeft(groups.map((g) => `<div class="sgh">${g.label}</div>`
            + g.items.map((it) => `<a data-sg-row href="${it.url}">`
                + `<span class="si" style="${it.image
                        ? `background:#fff url('${it.image}') center/cover`
                        : `background:${it.colour || 'linear-gradient(135deg,#ffd1e2,#ff9fc1)'}`}">${it.image ? '' : escapeHtml(it.initials || '')}</span>`
                + `<span class="sn">${escapeHtml(it.label)}`
                + (it.meta ? `<small>${escapeHtml(it.meta)}</small>` : '')
                + `</span>`
                + (it.price ? `<span class="sp">${escapeHtml(it.price)}</span>` : '')
                + `</a>`).join('')).join('')
            + `<a class="viewall" data-sg-row href="${data.all_url}">View all results <b>(${data.total} found)</b> <i>→</i></a>`);

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
        } catch (error) {
            if (error.name !== 'AbortError') close();
        }
    };

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(timer);

        if (q.length < 2) { close(); return; }

        timer = setTimeout(() => search(q), 180);
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

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}


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
let starterCache = null;

async function loadStarter() {
    if (starterCache) return starterCache;

    const base = (window.KBB && window.KBB.routes && window.KBB.routes.search) || '/api/search';
    const mobile = window.matchMedia('(max-width: 900px)').matches;

    try {
        const r = await fetch(`${base}/starter?mobile=${mobile ? 1 : 0}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!r.ok) return null;
        starterCache = await r.json();
        return starterCache;
    } catch (e) {
        return null;
    }
}

const chipRow = (items) =>
    `<div class="chips">${items.map((t) => `<a class="chip" data-sg-term="${escapeHtml(t)}">${escapeHtml(t)}</a>`).join('')}</div>`;

const productRow = (p) =>
    `<a class="sgi" href="${escapeHtml(p.url)}"><i${p.image ? ` style="background-image:url('${escapeHtml(p.image)}')"` : ''}></i>
       <span><b>${escapeHtml(p.name)}</b><small>${escapeHtml(p.brand || '')} · ${p.price}</small></span></a>`;

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

    input.addEventListener('focus', async () => {
        if (input.value.trim().length >= 2) return;   // results own the panel
        const data = await loadStarter();
        if (input.value.trim().length >= 2) return;   // typing won the race
        if (renderStarter(panel, data)) panel.classList.add('on');
    });

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
    input.addEventListener('input', async () => {
        if (input.value.trim().length !== 0) return;
        const data = await loadStarter();
        if (renderStarter(panel, data)) panel.classList.add('on');
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
