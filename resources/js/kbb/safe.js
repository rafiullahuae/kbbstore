/**
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE ESCAPER PER CONTEXT, FOR EVERY STOREFRONT MODULE THAT BUILDS MARKUP.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * These four helpers were written inside `search.js` when that panel was found
 * putting four `/api/search` values into `innerHTML` with nothing around them
 * at all. Nothing about them was ever special to search: eighteen files under
 * `resources/js/kbb/` build HTML out of values this shop did not author, and
 * the sweep that followed found three more holes in three more files. They live
 * here now so there is one copy to keep in step with `App\Support\CssUrl` and
 * `App\Services\Banners::safeUrl()`, which answer the same questions on the
 * PHP side, in the same order, for the reasons those files record in full.
 *
 * ── WHY FOUR AND NOT ONE ────────────────────────────────────────────────────
 *
 * An HTML body, an HTML attribute, a URL attribute and a CSS `url()` are four
 * destinations and `escapeHtml()` is correct for only the first two:
 *
 *   escapeHtml   a name, a price, a label — anything that is TEXT when it
 *                lands, whether between tags or inside a quoted attribute.
 *
 *   safeHref     something that becomes an `href` or an `action`. Escaping
 *                alone is not enough: `javascript:alert(1)` contains no
 *                character `escapeHtml()` touches, so it survives escaping
 *                intact and runs on click. Refused ones become '#' and NEVER
 *                '' — an empty href is the CURRENT PAGE, so a refused link
 *                would silently reload the shop instead of doing nothing.
 *
 *   safeSrc      something that becomes a `src`. Same gate, different refusal:
 *                `src="#"` and `src=""` both resolve against the document and
 *                make the browser fetch this very page and try to decode it as
 *                an image, so a refused src is '' and the CALLER draws nothing
 *                at all. Test `safeSrc(u)` before emitting the element.
 *
 *   cssUrl       something that sits between `url('` and `')` INSIDE a style
 *                attribute, which is two parsers deep. escapeHtml() turns `'`
 *                into `&#39;`; the HTML parser turns it straight back into a
 *                live `'` BEFORE CSS ever reads the attribute, so the quote
 *                closes the `url(` and everything after it is read as further
 *                declarations. cssUrl() escapes for CSS first and for HTML
 *                second, which is the order the browser unwraps them in.
 *
 * ── WHAT IS REFUSED, AND WHAT IS MERELY ESCAPED ─────────────────────────────
 *
 * REFUSED (scheme gate): any scheme that is not in the allowlist, and the
 * protocol-relative `//evil.test/x.png`, which carries no scheme and is not
 * relative either — it is somebody else's host wearing this page's scheme.
 * A path, a query or a fragment names no scheme, so it cannot name one this
 * shop does not serve, and is allowed.
 *
 * MERELY ESCAPED: every delimiter that could close the context it sits in.
 * Nothing else is touched, which is the display-parity argument — an address
 * that was already safe comes back byte-identical and no picture that draws
 * today stops drawing. Measured, not assumed: see
 * tests/Feature/StorefrontJsEscapingTest.php, whose header carries the table
 * these helpers produced in node.
 *
 * ── TWO ALLOWLISTS, AND WHY THE SECOND ONE EXISTS ───────────────────────────
 *
 * `HREF_SCHEMES` is http/https and is the default. It is what an address out
 * of `/api/*` gets, because those are pictures and product pages.
 *
 * `LINK_SCHEMES` adds mailto and tel, and is passed explicitly by the mobile
 * navigation drawer only. That is not a loosening: the DESKTOP nav renders the
 * same menu rows through `Url::to()`, which passes mailto and tel through by
 * name, so refusing them in the drawer would break a menu item that works on
 * the same page today. It is the same allowlist `Banners::safeUrl()` uses for
 * an operator-typed href, for the same reason.
 */

/** The only schemes this shop serves a picture or a product page from. */
export const HREF_SCHEMES = ['http', 'https'];

/** As above, plus the two an operator legitimately types into a menu row. */
export const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

/**
 * Text on its way into an HTML body or a quoted attribute.
 *
 * `String(s)` and not `String(s ?? '')`, deliberately: this is the function
 * search.js has shipped since 2.60.306 and changing how it renders a null
 * would move bytes on a page. A caller that wants '' for a nullish value says
 * so at its own call site — reviews.js and mobile-nav.js both do.
 */
export function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/**
 * Is the scheme one this shop serves, read the way a browser will?
 *
 * Decode entities FIRST, then strip whitespace and control characters, and
 * only then match — a browser resolves `jav&#x09;ascript:` to a javascript
 * URL, so a check run on the raw string sees something starting "jav&" and
 * waves it through. The order is `App\Support\CssUrl::schemeIsServed()`'s and
 * that file carries the argument; these two must not drift.
 */
export function schemeIsServed(url, schemes = HREF_SCHEMES) {
    const probe = String(url)
        .replace(/&#x([0-9a-f]+);?/gi, (_, h) => String.fromCharCode(parseInt(h, 16)))
        .replace(/&#(\d+);?/g, (_, d) => String.fromCharCode(parseInt(d, 10)))
        .replace(/[\s\u0000-\u001F\u007F-\u009F]+/g, '')
        .toLowerCase();

    if (probe.startsWith('//')) return false;

    const m = probe.match(/^([a-z][a-z0-9+.-]*):/);

    // No scheme is a path, a query or a fragment: it cannot name one this
    // shop does not serve.
    return m ? schemes.includes(m[1]) : true;
}

/**
 * A URL that is about to become an `href` or an `action`.
 *
 * Refused ones become '#', never '' — an empty href is the current page, so a
 * refused link would silently reload the shop instead of doing nothing.
 */
export function safeHref(url, schemes = HREF_SCHEMES) {
    return (url && schemeIsServed(url, schemes)) ? escapeHtml(url) : '#';
}

/**
 * A URL that is about to become a `src`.
 *
 * '' for a refused or absent one, and the CALLER omits the element: '#' and ''
 * both resolve against the document, so either would make the browser fetch
 * this page and try to decode it as a picture. `#` is the right refusal for a
 * link and the wrong one for an image, which is why this is not safeHref().
 */
export function safeSrc(url, schemes = HREF_SCHEMES) {
    return (url && schemeIsServed(url, schemes)) ? escapeHtml(url) : '';
}

/**
 * A URL that is about to sit inside a CSS `url('…')` INSIDE an HTML attribute,
 * so it needs both escapes, CSS first.
 *
 * One replace() pass for the five delimiters, not five passes: a second pass
 * would re-scan the backslashes the first one wrote and turn \' into \\' — an
 * escaped backslash and a LIVE quote, which is the bug rather than the fix.
 * Then the C0 controls as hex escapes, because a raw newline ends a CSS string
 * and hands what follows to the parser as a fresh declaration. Refused
 * addresses return '' so the caller draws its gradient, never `url('')`.
 */
export function cssUrl(url) {
    if (!url || !schemeIsServed(url)) return '';

    const escaped = String(url)
        .replace(/[\\'"()]/g, (c) => '\\' + c)
        .replace(/[\u0000-\u001F\u007F]/g,
            (c) => '\\' + c.charCodeAt(0).toString(16) + ' ');

    return escapeHtml(escaped);
}
