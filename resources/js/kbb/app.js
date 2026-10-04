/**
 * Store front-end entry point.
 *
 * Ported from the theme's kbb.js (568 lines) and split into modules. Deliberately
 * plain ES modules with no framework: the behaviour already exists and works, so
 * translating it line for line preserves it exactly. Rewriting it as components
 * would risk the display parity the whole port is built around.
 *
 * ── WHY THIS FILE DOES NOT IMPORT kbb.css, THOUGH IT USED TO ────────────────
 *
 * `import '../../css/kbb/kbb.css';` stood here, and it renamed the shipped
 * JavaScript bundle every time anybody edited a stylesheet.
 *
 * kbb.css is ALREADY a Vite entry of its own (vite.config.js) and the storefront
 * layout already asks for it by name — `@vite(['resources/css/kbb/kbb.css',
 * 'resources/js/kbb/app.js'])`, layouts/store.blade.php. The import made it a
 * second thing as well: a dependency of this chunk. Rollup folds a chunk's
 * dependencies into that chunk's content hash, so every kbb.css edit produced a
 * new `app-<hash>.js` whose bytes were, to the byte, the bytes of the old one.
 *
 * MEASURED. `app-DSE-434-.js` (commit 85fa244) and `app-iyd4z9xL.js` (fcebbc0)
 * are the same 45,971 bytes, md5 32ea782cd95d7c9505c931b4632a2e98 both. Nothing
 * under resources/js changed between those two commits; kbb.css did. Rebuilding
 * 85fa244's tree reproduces `app-DSE-434-.js` exactly, and replacing ONLY
 * kbb.css with fcebbc0's copy turns it into `app-iyd4z9xL.js` with the
 * JavaScript untouched. With this import gone, the same experiment leaves the
 * name where it was. See docs/rtl-audit.md §13.7.
 *
 * WHAT IT COST. 21 orphaned `app-*.js` files accumulated in public/build/assets
 * and were swept out in one commit (965b0f0); a package whose file list read
 * "app-xxxx.js" told the person applying it nothing about whether the
 * JavaScript had changed. CLAUDE.md's first landmine is a package built against
 * a stale tree that was applied anyway — a renamed file nobody can review is
 * how that happens twice.
 *
 * WHAT IT COSTS TO REMOVE. The 26-byte "empty css" banner comment Vite writes
 * at the top of a chunk that imported a stylesheet, and the `"css"` array that
 * the manifest carries on this entry — which Laravel's @vite deduplicates
 * against the explicit entry anyway, so the served <head> does not move by a
 * byte. BuiltAssetNamesAreStableTest pins both halves:
 * this file must not import a stylesheet, and every view that loads this bundle
 * must name kbb.css itself.
 */

import { initOverlay } from './overlay.js';
import { initToast } from './toast.js';
import { initSearch, initSearchStarter } from './search.js';
import { initMobileNav } from './mobile-nav.js';
import { initCart } from './cart.js';
import { initPdp, initGallery } from './pdp.js';
import { initCheckout } from './checkout.js';
import { initHome, initAccountPanel, initAccountPage, initReveal, initPasswordMeter } from './home.js';
import { initProductTabs, initReadMore } from './tabs.js';
import { initShop } from './shop.js';
import { initListingLoad } from './listing-load.js';
import { initFbt } from './fbt.js';
import { initNewsletter } from './newsletter.js';
import { initWishlist } from './wishlist.js';
import { initReviews } from './reviews.js';
import { initNavFit } from './nav-fit.js';
import { initAlsoLike } from './ymal.js';
// Lane RA: the admin bar and quick-edit pencil, LAST in STEPS. For a shopper
// it is one cookie test and nothing else; see admin-hint.js.
import { initAdminLayer } from './admin-hint.js';
// Lane RD: press feedback -- no grey box, a live response on every button and
// icon. Classes only; it returns at once when the owner chose Off. See press.js.
import { initPress } from './press.js';

/* ── EVERY STEP RUNS, WHATEVER ANY OTHER STEP DOES. ─────────────────────────
 *
 * This was 21 calls in a row with nothing between them. JavaScript stops a
 * function at the first error it does not catch, so ONE of these throwing --
 * on live data, a page shape the fixtures never had, an older cached partial
 * -- silently cancelled every step after it. initHome() is ninth: it is what
 * wires the mobile menu, so an error in any of the eight before it left the
 * menu button doing nothing at all, with no message anywhere a shopper sees.
 * The owner reported exactly that on his phone.
 *
 * Each step now runs on its own. A step that fails is reported to the console
 * by NAME -- so the next report says which one, rather than "the menu is
 * broken" -- and the other twenty carry on. The order is unchanged.
 */
// Lane PF: initReadMore, LAST, is the homepage About us's Read more — the
// product description's toggle (tabs.js toggleReadMore), shared, not copied.
const STEPS = [
    initOverlay,
    initToast,
    initSearch,
    initSearchStarter,
    initMobileNav,
    initCart,
    initPdp,
    initCheckout,
    initHome,
    initAccountPanel,
    initAccountPage,
    initReveal,
    initPasswordMeter,
    initProductTabs,
    initGallery,
    initShop,
    initListingLoad,
    initFbt,
    initNewsletter,
    initWishlist,
    initReviews,
    initNavFit,
    initAlsoLike,
    initAdminLayer,
    initPress,
    initReadMore,
];

const boot = () => {
    for (const step of STEPS) {
        try {
            step();
        } catch (error) {
            console.error('[kbb] ' + (step.name || 'a start-up step') + ' failed; the rest of the page still started.', error);
        }
    }
};

document.readyState === 'loading'
    ? document.addEventListener('DOMContentLoaded', boot)
    : boot();

/* Extended delivery reads a time zone to guess the shopper's country when the
   host does not pass one. Set once, from a browser API that needs no permission
   and makes no request; the checkout maps it server-side. Harmless when
   Extended is off — nothing reads it. */
try {
    if (!document.cookie.includes('kbb_tz=')) {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (zone) {
            document.cookie = 'kbb_tz=' + encodeURIComponent(zone)
                + ';path=/;max-age=31536000;SameSite=Lax';
        }
    }
} catch {
    /* Old browser, or time zone unavailable. The store's own country is used. */
}
