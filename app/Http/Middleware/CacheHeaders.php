<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTML half of the cache strategy, stated rather than inherited.
 *
 * docs/IMAGE-PIPELINE-AND-CACHE.md §9 is the policy this enforces and the place
 * to read first. The short version, and the reason this class is small:
 *
 *   - hashed build assets and generated image variants want far-future
 *     immutable caching, and NOTHING IN PHP CAN GIVE IT TO THEM. On this host
 *     those files are served straight off disk by the web server and never
 *     reach the application, so their headers are a web-server matter. The
 *     policy lives here as a constant so that the .htaccess in
 *     docs/cache-headers.htaccess and this file cannot state different numbers,
 *     and CacheHeaderPolicyTest holds the two to each other.
 *   - HTML is the half PHP does control, and it is what this middleware sets.
 *
 * ── WHY STATE A POLICY THAT IS ALREADY THE BEHAVIOUR ──────────────────────
 *
 * Every storefront response already leaves here as `no-cache, private`, and
 * nothing chose that: Symfony's ResponseHeaderBag computes it whenever a
 * response carries no Cache-Control of its own. It is the right answer, and it
 * is right by accident. A later lane adding a `public, max-age=` anywhere in
 * the pipeline, or a caching layer being switched on, changes it silently — and
 * the cost of getting it wrong on THIS host is specific and known:
 * NoStoreAdminApi's own docblock records that shared hosting commonly caches
 * GET responses by default. A storefront page carries a cart badge, a signed-in
 * name and a CSRF token; served to a second shopper out of a shared cache, that
 * is one customer's basket shown to another.
 *
 * So the storefront's HTML says `private` explicitly, and a test asserts it on
 * a fetched page rather than on this source.
 *
 * ── AND WHERE `no-cache` IS NOT ENOUGH ────────────────────────────────────
 *
 * `no-cache` means "keep a copy, but revalidate before reusing it". For a shop
 * page that is right. For the pages that print a customer's own data — their
 * account, their addresses, their order history, a placed order's confirmation
 * — it is not: the copy is written to the browser's disk cache, and on a shared
 * or borrowed computer the back button after a sign-out can render it without
 * ever asking this server. `no-store` is the directive that says do not keep
 * it, and those paths get it.
 *
 * The list is matched on the path PREFIX and not on the route name, because a
 * route name is a thing a later lane can rename without noticing what else read
 * it, and because the storefront's own account URLs are already a settled
 * contract.
 *
 * ── WHAT IS DELIBERATELY NOT TOUCHED ──────────────────────────────────────
 *
 * Anything that already carries a Cache-Control. /admin-api/ has
 * NoStoreAdminApi, which is stricter and more specific, and two middlewares
 * setting the same header is how one of them ends up not mattering. Redirects,
 * errors and non-GET responses: a 301 is cacheable by its own rules and a POST
 * is not a document.
 */
class CacheHeaders
{
    /**
     * Far-future immutable, for content-addressed files.
     *
     * One year, which is the ceiling every major browser honours, plus
     * `immutable` so a reload does not revalidate it either. Safe ONLY because
     * both sets of files are content-addressed and can never change under a
     * URL: Vite writes `kbb-NawQuIF5.css`, and a new build is a new name;
     * uploads are named `Ymd-His-<random>.ext` and MediaUploadController cannot
     * overwrite one, so `img-cache/400/<that path>` is as fixed as its original.
     * CacheHeaderPolicyTest asserts both preconditions rather than trusting
     * them, because the day a build stops hashing is the day a year-long cache
     * becomes a year-long outage.
     *
     * NOT APPLIED BY THIS CLASS. It is here because it is the policy, and
     * because the .htaccess that does apply it is a text file nobody would
     * think to keep in step with a comment.
     */
    public const IMMUTABLE = 'public, max-age=31536000, immutable';

    /**
     * Storefront HTML: keep it, but never reuse it without asking.
     *
     * SYMFONY REORDERS AND RE-SPELLS THESE ON THE WAY OUT -- this one arrives
     * at the browser as `max-age=0, must-revalidate, no-cache, private`. The
     * value is a SET OF DIRECTIVES, not a string, and anything comparing it has
     * to say so; CacheHeaderPolicyTest compares the sorted set for exactly that
     * reason, and a test that compared the literal would be pinning Symfony's
     * sort order rather than this policy.
     */
    public const REVALIDATE = 'private, no-cache, max-age=0, must-revalidate';

    /** A page printing one customer's own data: do not keep it at all. */
    public const NO_STORE = 'no-store, no-cache, max-age=0, must-revalidate';

    /**
     * The storefront paths whose HTML is one customer's own, as FIRST SEGMENTS,
     * matched with or without a locale segment in front.
     *
     * These are the storefront's real addresses, read off routes/web.php rather
     * than guessed: /orders and /order belong to the ADMIN console and sit
     * behind the configured admin path, so they are not first segments here and
     * listing them would have protected nothing while looking like it did. The
     * admin's own HTML already sets no-store in Admin\PageController and this
     * class leaves anything with a Cache-Control of its own alone.
     *
     * /cart is on the list with the rest: it prints what is in the basket, and
     * a basket restored from a browser's disk cache after a sign-out on a
     * shared machine is the same disclosure as an order page, one purchase
     * earlier.
     */
    public const PRIVATE_PREFIXES = [
        'my-account',
        'my-wishlist',
        'wishlist',
        'cart',
        'checkout',
        'track-my-order',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable()) {
            return $response;
        }

        // Already decided by something closer to the route. NoStoreAdminApi is
        // the case that exists today and it is stricter than anything here.
        if ($response->headers->has('Cache-Control')
            && $response->headers->get('Cache-Control') !== '') {
            $existing = (string) $response->headers->get('Cache-Control');

            // Symfony's computed default is not a decision anyone made, so it
            // is not treated as one. Anything else is.
            if ($existing !== 'no-cache, private' && $existing !== 'no-cache') {
                return $response;
            }
        }

        /*
         * A PRIVATE PAGE IS PRIVATE WHATEVER IT ANSWERS, and that is the one
         * ordering decision in this class.
         *
         * /checkout redirects a visitor with an empty basket and /my-account
         * redirects a signed-out one, and a 302 carrying the customer's own
         * Location is still something a shared computer should not keep. So the
         * private list is consulted before the 200-and-HTML test, not after it.
         */
        if ($this->isPrivatePage($request)) {
            $response->headers->set('Cache-Control', self::NO_STORE);

            return $response;
        }

        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        $type = (string) $response->headers->get('Content-Type', '');

        if ($type !== '' && ! str_contains($type, 'text/html')) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::REVALIDATE);

        return $response;
    }

    /**
     * Is this one of the customer's own pages?
     *
     * THE LOCALE SPLIT IS BELT-AND-BRACES TODAY, AND IS SAID SO RATHER THAN
     * LEFT LOOKING LOAD-BEARING. SetLocaleFromPath is prepended to the global
     * stack and REWRITES the request before anything in the web group sees it,
     * so by here /ar/my-account/ already reads as /my-account/ and reading
     * $request->path() raw would give the same answer. Measured: deleting this
     * call changes nothing that any test can see, and the report for this lane
     * says so.
     *
     * It stays because the thing it depends on is an ORDERING -- one middleware
     * prepended globally, this one appended to a group -- and an ordering is
     * what a later change alters without anybody connecting it to a cache
     * header. A guard that reads the raw path protects English and quietly
     * stops protecting Arabic, which is the shape of defect this project keeps
     * finding in its own bilingual work, and it costs one function call.
     */
    private function isPrivatePage(Request $request): bool
    {
        [, $rest] = \App\Support\Locale::splitPath('/' . ltrim($request->path(), '/'));

        $first = strtok(trim($rest, '/'), '/');

        return $first !== false && in_array($first, self::PRIVATE_PREFIXES, true);
    }
}
