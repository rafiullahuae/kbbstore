/**
 * The storefront admin layer's LOADER — the only part of it a shopper's
 * browser ever runs. (Lane RA)
 *
 * The owner asked for a thin admin bar on every page and a pencil on every
 * category and brand header, "only administrator". None of it is in the
 * page's HTML: the HTML is the same document for everybody, and a cached copy
 * of a page that carried the bar would show the console's address to the next
 * shopper. So this step looks for one cookie — `kbb_ah`, set when an
 * administrator signs in (App\Support\StorefrontAdminHint) — and stops there
 * when it is absent, which for a shopper is always.
 *
 * WHAT A SHOPPER PAYS: one regular-expression test against document.cookie.
 * No request, no stylesheet, no element, no listener. The bar, the pencil and
 * their styles live in a separate chunk (admin/storefront-admin.js) that is
 * only fetched when the hint is there, and even then draws nothing until the
 * authenticated context endpoint says yes. A forged hint gets a 401 and the
 * chunk deletes the cookie.
 */

export const ADMIN_HINT = 'kbb_ah';

/** Is the hint present? Exported for the test that pins it. */
export const hasAdminHint = (cookie) => /(?:^|;\s*)kbb_ah=/.test(String(cookie || ''));

export function initAdminLayer() {
    if (!hasAdminHint(document.cookie)) return;

    import('./admin/storefront-admin.js')
        .then((m) => m.start())
        .catch((error) => console.error('[kbb] the admin bar could not start; the shop is unaffected.', error));
}
