/**
 * The front-end's half of the interface-string conversion (Lane EU, T2).
 *
 * ── WHY THE ENGLISH IS PASSED IN AT EVERY CALL SITE ─────────────────────────
 *
 * These modules are compiled by Vite into public/build, and this host has no
 * Node: the bundle is built off-server and uploaded, and CLAUDE.md records that
 * package.json has no `build` script and CI does not build assets. So a
 * translation lookup that had no fallback would be a blank toast the moment the
 * source and the shipped bundle were one release apart — which, here, is the
 * normal state of affairs rather than an accident.
 *
 * t('store.js.generic_error', 'Something went wrong — please try again.') is
 * therefore never worse than the literal it replaced. With the dictionary
 * absent it IS the literal it replaced, byte for byte.
 *
 * ── WHY THERE IS NO DICTIONARY ON AN ENGLISH PAGE ───────────────────────────
 *
 * resources/views/partials/js-strings.blade.php emits window.KBB_T only when
 * the page is NOT in the default locale. An English page is exactly the page it
 * was before this lane touched it, down to the byte, which is the acceptance
 * bar the two byte-identity tests hold; and an English shopper does not
 * download a table of English strings to look English strings up in.
 */

/**
 * The shopper's wording for a key, or the English written beside it.
 *
 * @param {string} key      a key in App\Services\Translation\InterfaceStrings
 * @param {string} english  the wording that shipped, and the fallback
 * @param {Object} [replace] named placeholders, :like => this
 * @returns {string}
 */
export function t(key, english, replace) {
    const table = (typeof window !== 'undefined' && window.KBB_T) || null;
    let out = (table && typeof table[key] === 'string' && table[key]) || english;

    if (replace) {
        // Longest key first, so :count_total is not eaten by :count.
        Object.keys(replace)
            .sort((a, b) => b.length - a.length)
            .forEach((name) => {
                out = out.split(':' + name).join(String(replace[name]));
            });
    }

    return out;
}

/**
 * HTML-escape a translated string before it goes into innerHTML.
 *
 * The values in window.KBB_T are typed by the shop owner in the admin, which is
 * one account rather than the public — but these two call sites build MARKUP
 * out of them, and a string that lands inside an attribute has to survive a
 * quote in it whatever its provenance. The Blade side escapes everything
 * through {{ }} for the same reason; this is the front-end's copy of that rule.
 *
 * @param {string} value
 * @returns {string}
 */
export function esc(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

export default t;
