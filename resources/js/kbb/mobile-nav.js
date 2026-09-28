/**
 * Mobile drawer and second-level overlay. T-CHROME-13 / T-CHROME-14.
 *
 * Built client-side from window.KBB.nav, which the layout serialises from the
 * database — the theme hard-coded this tree in PHP.
 *
 * All hrefs come pre-prefixed from the server via window.KBB.base, so nothing
 * here constructs a path. That is what keeps the drawer working when the app is
 * served from a subdirectory.
 */

import { open, closeAll } from './overlay.js';
import { escapeHtml, safeHref, LINK_SCHEMES } from './safe.js';

/* The shared escaper, with this file's own nullish guard kept in front of it.
   `escape(item.badge)` has always rendered a missing badge as nothing rather
   than the string "undefined", and safe.js deliberately does not coalesce —
   search.js has shipped it uncoalesced since 2.60.306 and moving that would
   move bytes on a page. One line here is cheaper than a second escaper. */
const escape = (value) => escapeHtml(value ?? '');

/* A menu row's href.
 *
 * THE DEFECT: every href in this drawer was escaped and NONE of them was
 * scheme-checked, and link() below passes an absolute URL through by name — so
 * `javascript:alert(1)` in a menu row's url contains no character escape()
 * touches, arrived in the attribute intact, and ran on tap. The drawer is
 * built from window.KBB.nav, which the layout serialises out of `menu_items`;
 * the desktop nav renders the same rows in Blade, where `{{ }}` has exactly
 * the same blind spot.
 *
 * LINK_SCHEMES and not the default: the desktop nav's Url::to() passes mailto
 * and tel through by name, so refusing them here would break a menu item that
 * still works ten pixels away on the same page. It is the allowlist
 * Banners::safeUrl() uses for an operator-typed href, for that same reason. */
const navHref = (url) => safeHref(link(url), LINK_SCHEMES);

/*
 * U+2192 IS NOT A MIRRORED CHARACTER, so the bidi algorithm will not turn this
 * one round the way it turns the `‹` above it. Measured in Chromium, each
 * glyph centred in a fixed box and rendered in both directions so that only its
 * shape can differ: U+203A, U+2039, U+00BB and U+003E come back as their own
 * mirror in a right-to-left run; U+2192, U+2190, U+25B6, U+2794 and U+21A9 come
 * back identical. Read from the document rather than from a setting because
 * this file is served to both languages from one bundle, and it is the DIRECTION
 * that decides which way "onward" points, not the language: with the mirrored
 * layout switched off the page still reads left to right.
 */
const onward = () => (document.documentElement.getAttribute('dir') === 'rtl' ? '←' : '→');

const link = (url) => {
    const base = window.KBB?.base || '';
    const path = String(url ?? '/');
    if (/^([a-z][a-z0-9+.-]*:|\/\/)/i.test(path)) return path;
    return base + (path.startsWith('/') ? path : `/${path}`);
};

const renderSub = (item) => {
    const groups = (item.children || []).map((child) => {
        if (child.children && child.children.length) {
            const links = child.children
                .map((l) => `<a href="${navHref(l.url)}">${escape(l.label)}</a>`)
                .join('');
            return `<div class="msub-group"><div class="msub-gh">${escape(child.label)}</div>${links}</div>`;
        }
        return `<a href="${navHref(child.url)}">${escape(child.label)}</a>`;
    }).join('');

    return `
        <div class="msub-h">
            <button class="msub-back" type="button" data-kbb-msub-back>‹</button>
            <b>${escape(item.label)}</b>
        </div>
        <a class="msub-all" href="${navHref(item.url)}">Shop all ${escape(item.label)} ${onward()}</a>
        <div class="msub-body">${groups}</div>
    `;
};

export function initMobileNav() {
    const list = document.getElementById('mlist');
    const sub = document.getElementById('msub');
    const nav = window.KBB?.nav || [];
    if (!list) return;

    list.innerHTML = nav.map((item, index) => {
        const hasChildren = item.children && item.children.length;
        const icon = item.icon ? `<span class="mi">${escape(item.icon)}</span>` : '';
        const badge = item.badge ? `<span class="npill">${escape(item.badge)}</span>` : '';

        return hasChildren
            ? `<button class="mrow" type="button" data-kbb-msub="${index}">${icon}<span>${escape(item.label)}</span>${badge}<span class="chev">›</span></button>`
            : `<a class="mrow" href="${navHref(item.url)}">${icon}<span>${escape(item.label)}</span>${badge}</a>`;
    }).join('');

    list.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-kbb-msub]');
        if (!trigger || !sub) return;

        sub.innerHTML = renderSub(nav[Number(trigger.dataset.kbbMsub)]);
        sub.classList.add('on');
    });

    sub?.addEventListener('click', (event) => {
        if (event.target.closest('[data-kbb-msub-back]')) sub.classList.remove('on');
    });

    const search = document.querySelector('[data-kbb-msearch]');
    search?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && search.value.trim()) {
            closeAll();
            window.location.href = `${window.KBB.routes.shop}?s=${encodeURIComponent(search.value.trim())}`;
        }
    });

    window.kbbOpenDrawer = open;
}
