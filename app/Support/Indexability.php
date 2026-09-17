<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which storefront paths must never be indexed.
 *
 * WHY THIS EXISTS AS A LIST AND NOT AS A FLAG ON EACH PAGE.
 *
 * robots.txt (Store\SeoFilesController::robots) already says of these paths
 * "a cart, an account area and a wishlist are different for every visitor and
 * useless in a result". Every one of those pages was nevertheless serving
 * `<meta name="robots" content="index, follow">` with a self-referencing
 * canonical, verified against the rendered <head> on /cart, /my-account,
 * /my-wishlist, /track-my-order and /checkout. Two files disagreeing about the
 * same decision is how the disagreement survived: the robots.txt half was
 * written once and the page half was never written at all.
 *
 * That disagreement is not academic. A Disallow only stops the fetch; it does
 * not stop the URL being indexed from a link, and Google's own guidance is
 * that a URL it may not crawl is a URL whose noindex it can never see. The
 * outcome a shop actually gets is a bare, description-less "Your Bag" entry
 * ranking for its own brand name. The page has to say noindex itself, and
 * because these pages are also reachable through account links and email
 * links, the decision has to hold wherever the page is reached from.
 *
 * Keeping it as one prefix list rather than a noindex flag passed by each
 * controller is deliberate: /my-account already has seven routes across two
 * route files and more get added, and the failure mode of the per-controller
 * version is a new account screen that silently ships indexable. A prefix
 * cannot be forgotten by a route that did not exist when this was written.
 *
 * NOT in this list, on purpose:
 *
 *   - /shop and the category archives, including their filtered and paginated
 *     forms. Those are the pages that are supposed to rank, and Facets
 *     consolidates them with canonicals rather than hiding them.
 *   - /app, which sets its own noindex in PageController::app() because the
 *     reason there is different (its catalogue is invented, not private).
 *   - /reviews and /skin-quiz, which are real, public, indexable content.
 */
final class Indexability
{
    /**
     * Path prefixes, written exactly as a visitor's URL bar shows them — no
     * environment base path. Matching is done against getPathInfo(), which has
     * that prefix already removed, so this list stays correct on the staging
     * host that serves the app from /kbb-upgrade.
     */
    public const PRIVATE_PREFIXES = [
        '/cart',
        '/checkout',
        '/my-account',
        '/my-wishlist',
        '/wishlist',
        '/track-my-order',
    ];

    /**
     * Should the page served at this path carry noindex?
     *
     * A prefix matches the path itself and anything below it, and only at a
     * segment boundary: '/cart' must not deindex a product whose slug happens
     * to start with the same letters. /cartridge-cleanser/ is a legitimate
     * article URL under the root-level post route.
     *
     * ── A LOCALE SEGMENT IS STRIPPED FIRST ─────────────────────────────────
     *
     * Today's one caller — layouts/store.blade.php — passes
     * request()->getPathInfo(), which SetLocaleFromPath has already rewritten,
     * so it hands in '/checkout' on /ar/checkout and this list matched it
     * without knowing a second language existed. That is luck, not design: the
     * correctness of every noindex on the Arabic storefront rested on a caller
     * happening to read the path AFTER one particular middleware, and the next
     * caller — a route-list audit, a crawl-surface test, a controller reading
     * getRequestUri(), anything running before the router — would have asked
     * about '/ar/checkout' and been told, with a straight face, that the
     * checkout is a public page. A path that is private at /checkout and public
     * at /ar/checkout is the exact leak CLAUDE.md records this project having
     * shipped before.
     *
     * Locale::splitPath() only removes a segment that names a LIVE locale, so
     * with Arabic off this is a no-op on every input, and a product or article
     * slug is never mistaken for a language.
     */
    public static function isPrivate(string $path): bool
    {
        [, $path] = Locale::splitPath($path);

        $path = '/' . trim($path, '/');

        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
