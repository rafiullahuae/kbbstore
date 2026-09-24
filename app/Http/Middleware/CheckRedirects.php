<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks the incoming path against admin-defined (or auto-created) redirects
 * before the request reaches routing. Has to run early, not as a fallback
 * for unmatched routes — a redirect must take priority even over a route
 * that *would* otherwise match something, since the whole reason a URL is
 * being redirected is that whatever's now at the old path isn't what the
 * admin wants a visitor landing on.
 *
 * Skipped entirely for admin/API/asset paths — a redirect rule is a
 * storefront-URL concept; checking it against every asset request would be
 * pure overhead for something that can never match one anyway.
 *
 * =============================================================================
 * IT IS NOW REGISTERED, AND FOR YEARS IT WAS NOT
 * =============================================================================
 *
 * The paragraph above was the intent from the day this class was written, and
 * it was not what the shop did. `bootstrap/app.php` never named this class and
 * the only live reader of the `redirects` table was the
 * `NotFoundHttpException` closure in `AppServiceProvider`. The consequence is
 * absolute and was measured rather than argued — `docs/GP-ADDRESSES-LAND.md`
 * carries the transcript, and `App\Services\Import\SourceReachability` and
 * `RedirectMap` are both built on it:
 *
 *     AN ADDRESS THAT DOES NOT 404 COULD NEVER BE REDIRECTED BY A ROW.
 *
 * Three rows pointing at `/PROOF-INERT/` were written for `/shop/`, a category
 * archive and a product address, all enabled, all matching `getPathInfo()`
 * byte for byte. `/shop/` still answered 200, the category still 301'd to its
 * own nested path, the product still answered 200. Not one of the three fired.
 * The old shop has five years of URLs, and every one of them that collides
 * with a slug this application serves kept serving the wrong page.
 *
 * WHY THE EARLIER ATTEMPT FAILED, since the comment it left behind reads like
 * evidence that registration cannot work. It was registered into the `web`
 * middleware GROUP, and a group runs AFTER the router has matched a route,
 * which is far too late to change which route matches. The global pipeline is
 * the only part that runs before routing. `SetLocaleFromPath` is in this file's
 * directory for exactly the same reason and says so in `bootstrap/app.php`.
 *
 * WHERE IT IS REGISTERED, AND WHY IT IS IN TWO PLACES. `AppServiceProvider::
 * boot()` prepends it to the global stack, and `bootstrap/app.php` appends it
 * to the same stack. `bootstrap/` is on `BuildPackage::NEVER_SHIP` and
 * `UpdateGuard` forbids it, so a registration written only there can never
 * reach the live server — that is not a hypothetical, it is how `/ar` 404'd on
 * the owner's shop for a month. `Kernel::prependMiddleware()` does an
 * `array_search` before it unshifts, so a host carrying both gets one copy.
 *
 * AFTER SetLocaleFromPath AND AFTER CanonicalHost, in that order, and the
 * ordering is load-bearing in both directions:
 *
 *   CanonicalHost first — there is no sense redirecting a path on a host the
 *   request is about to be forwarded off. It folds the path correction into
 *   its own hop by calling self::lookup(), so an old address arriving on the
 *   retired domain still costs the visitor exactly one 301.
 *
 *   SetLocaleFromPath before this — it strips `/ar` before the router sees the
 *   request, and `redirects.source` is stored without a locale segment. An
 *   Arabic visitor following an old link has to match the same row an English
 *   one does; Url::redirect() then puts the segment back on the way out.
 */
class CheckRedirects
{
    /**
     * The cached index of enabled `redirects.source` values.
     *
     * Also cleared by the clear_caches migration that ships the registration,
     * so applying a package cannot leave a stale index behind.
     */
    public const INDEX_KEY = 'kbb.redirects.sources.v1';

    /**
     * Above this many enabled rows the index is not built at all and every
     * request pays for its own SELECT instead.
     *
     * Not a guess about this shop — the real table is in the hundreds — but a
     * ceiling on what a single cache entry read on every storefront request is
     * allowed to weigh. A shop that somehow reaches it gets the slow answer
     * rather than an unbounded array unserialised on every page.
     */
    private const INDEX_CAP = 25000;

    /**
     * How far a chain is followed before it is called a loop.
     *
     * RedirectManager collapses chains at write time, so a real one is at most
     * a hop or two; anything past this is a cycle whether or not the walk has
     * closed it yet, and the safe answer is to serve the page.
     */
    private const MAX_HOPS = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $redirect = self::findMatch($request);

        if ($redirect === null) {
            return $next($request);
        }

        self::recordHit($redirect);

        /*
         * ═══════════════════════════════════════════════════════════════════
         * Url::redirect(), NOT THE BARE TARGET — and this is the one line in
         * this shop that decides where every old address lands
         * ═══════════════════════════════════════════════════════════════════
         *
         * `redirect($path)` hands a relative target to Laravel's UrlGenerator,
         * which does three things to it, all of them wrong here:
         *
         *   IT STRIPS THE TRAILING SLASH. `redirects.target` is stored in the
         *   canonical form U-01 defines — `/product-category/skincare/toners/`
         *   — and the Location header came out without it. Measured on a
         *   running server: FIFTEEN OF FIFTEEN redirects landed on an address
         *   whose own <link rel="canonical"> pointed somewhere else, so every
         *   old URL cost a crawler a 301 and then a canonical hop.
         *
         *   THE BASE PATH WAS ALREADY RIGHT, and saying so is a correction to
         *   docs/GB-MEDIA-AND-REDIRECTS.md §6.4, which implied it was not.
         *   UrlGenerator builds on the request ROOT, which on a subfolder
         *   mount already carries /kbb-upgrade — measured against a preview
         *   mounted exactly that way. Url::redirect() adds it once and knows
         *   APP_URL may already end in it, so the two agree; this line does
         *   not fix the base path because the base path was not broken.
         *
         *   IT DROPS THE READER'S LANGUAGE. SetLocaleFromPath strips /ar
         *   before the router sees the request, so an Arabic visitor following
         *   an old link matched the row and was then sent to the English page.
         *   Url::redirect() → Url::to() puts the segment back.
         *
         * This is not a new convention. PageController::legacyPost() already
         * does exactly this, for exactly these reasons, for /blog and
         * /skincare-guide/{slug}/ — which made the redirects TABLE the only
         * producer of a 301 in this application that still did it the other
         * way. See docs/GP-ADDRESSES-LAND.md for the before/after fetches.
         *
         * THE COST, stated because it is real: Url::redirect() builds on
         * APP_URL rather than on the request's host, so a wrong APP_URL sends
         * every redirect to the wrong host. That is already true of password
         * resets, payment webhooks and Stripe's callback, all of which go
         * through the same helper, so it is a precondition this shop already
         * has rather than a new one.
         */
        return redirect(\App\Support\Url::redirect($redirect->target), $redirect->code);
    }

    /**
     * The matching logic itself, factored out so the exception handler can
     * reuse it directly rather than duplicate it.
     *
     * The 404 handler in AppServiceProvider still calls this. That is belt and
     * braces rather than duplication now: this middleware answers first for
     * every path a row claims, so the closure only ever sees a 404 the table
     * has nothing for — but a host whose provider registration somehow did not
     * land keeps redirecting 404s exactly as it did before this was wired up,
     * which is the failure mode that has to stay boring.
     */
    public static function findMatch(Request $request): ?Redirect
    {
        if (!$request->isMethod('GET') || self::isExemptPath($request->path())) {
            return null;
        }

        // getPathInfo(), not path() — path() strips the trailing slash,
        // but this site's real URLs deliberately keep one (the same
        // WordPress-matching convention Url::to() documents as U-01), so
        // stripping it here would mean a redirect stored for "/foo/" could
        // never actually match the real incoming request for "/foo/".
        return self::lookup($request->getPathInfo());
    }

    /**
     * The one place the `redirects` table is read, and the one place a loop is
     * refused.
     *
     * `CanonicalHost::targetFor()` calls this rather than repeating the query,
     * and that is not tidiness: it consults the same table on the same column
     * to fold the path correction into its own hop, and the two answering
     * differently means a redirect that fires on the canonical host and not on
     * an alias, or a loop guarded on one and not the other. Asserted against
     * each other in tests/Feature/RedirectMiddlewareTest.php.
     *
     * $path is always a `getPathInfo()` — the spelling the client sent, base
     * path excluded, trailing slash intact.
     */
    public static function lookup(string $path): ?Redirect
    {
        if (!self::claimed($path)) {
            return null;
        }

        $row = self::row($path);

        if ($row === null || self::loops($path, $row)) {
            return null;
        }

        return $row;
    }

    public static function recordHit(Redirect $redirect): void
    {
        // Best-effort — a failure here must never block the actual
        // redirect from happening.
        try {
            DB::table('redirects')->where('id', $redirect->id)->update([
                /*
                 * `hits + 1` in SQL, not `$redirect->hits + 1` in PHP.
                 *
                 * The read-then-write form loses a count whenever two visitors
                 * follow the same old link at once, and until this class was
                 * registered that was nearly impossible to provoke: only a 404
                 * reached it. It now runs on addresses the shop serves, where
                 * concurrent hits are the normal case, so the counter the
                 * Redirects screen shows has to be able to survive them.
                 */
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Swallowed deliberately — see comment above.
        }
    }

    /**
     * Drop the cached source index.
     *
     * Called from Redirect::booted() on every model save and delete, which is
     * every write path in the application: RedirectsApiController,
     * UrlsMediaApiController, RedirectManager, ImportRedirects and the seed
     * migration all write through the model. An edit on the Redirects screen
     * is therefore live on the next request, exactly as it was before this
     * index existed — the same arrangement, and the same argument, as
     * ShippingService::flushZones().
     *
     * NOT covered, and named rather than hidden: a mass `->delete()` or
     * `->update()` through the query builder fires no model events. The only
     * one in the application is the `down()` of the Phase 9 seed migration,
     * and the clear_caches migration that ships with any package forgets this
     * key outright.
     */
    public static function flushIndex(): void
    {
        try {
            Cache::forget(self::INDEX_KEY);
        } catch (\Throwable $e) {
            // A migration can write this table before the cache store's own
            // table exists. Failing to drop an index that cannot yet have been
            // built must never fail the write that triggered it.
        }
    }

    /**
     * Does any enabled row claim this path?
     *
     * ── WHY AN INDEX AT ALL ──────────────────────────────────────────────
     *
     * This class now runs on EVERY storefront request, and the obvious
     * implementation asks the database on every one of them. That is one query
     * added to every page of the shop, which is precisely where
     * StorefrontQueryBudgetTest is a budget rather than a suggestion — and it
     * buys nothing, because the answer is "no row" for every address the shop
     * actually serves.
     *
     * So the set of enabled `source` values is cached whole and consulted in
     * memory. A storefront page now costs ZERO queries against `redirects`;
     * only a request that is about to be redirected pays for a SELECT, and
     * that request renders no page. Measured in
     * tests/Feature/RedirectMiddlewareTest.php, which asserts the count
     * directly rather than trusting this paragraph.
     *
     * SOURCES ONLY, not the whole row, and that is deliberate.
     * RedirectManager::autoCreate() repoints chains with a query-builder mass
     * update — `where('target', …)->update(['target' => …])` — which fires no
     * model events. Caching targets would go stale there and send a visitor to
     * an address that moved. `source` is not written by any eventless path.
     */
    private static function claimed(string $path): bool
    {
        $sources = self::sources();

        return $sources === null || isset($sources[$path]);
    }

    /** The index, or null when there is none and the table must be asked. */
    private static function sources(): ?array
    {
        $index = Cache::rememberForever(self::INDEX_KEY, static function (): array {
            $sources = Redirect::query()
                ->where('enabled', true)
                ->limit(self::INDEX_CAP + 1)
                ->pluck('source')
                ->all();

            if (count($sources) > self::INDEX_CAP) {
                return ['capped' => true, 'sources' => []];
            }

            return [
                'capped' => false,
                'sources' => array_fill_keys(array_map('strval', $sources), true),
            ];
        });

        // A cache entry written by an older build must never take the
        // storefront down — the same guard SettingsService and ShippingService
        // use. Dropping it means the next request rebuilds it.
        if (!is_array($index) || !array_key_exists('capped', $index) || !isset($index['sources']) || !is_array($index['sources'])) {
            self::flushIndex();

            return null;
        }

        return $index['capped'] === true ? null : $index['sources'];
    }

    private static function row(string $path): ?Redirect
    {
        return Redirect::query()->where('source', $path)->where('enabled', true)->first();
    }

    /**
     * Would following this row bounce the visitor forever?
     *
     * ── THE FAILURE MODE THIS CLASS INTRODUCED ───────────────────────────
     *
     * While the table was read only on a 404, a row pointing an address at
     * itself was a row that answered a 404 with a 301 to the same 404 — bad,
     * and bounded by the browser. Registered as middleware it is an address
     * that redirects to itself for ever, on a page the shop was serving
     * correctly a moment ago. `RedirectMap` line 425 already refuses to WRITE
     * one; rows written before it did are in the owner's database now, so the
     * refusal has to happen at read time as well.
     *
     * CHEAP IN THE CASE THAT MATTERS. A self-pointing row is caught with no
     * query at all, by the first `isset($seen[$next])`. A row pointing at an
     * address no other row claims — which is every honest redirect, because a
     * redirect points at a page — stops at `claimed()`, also with no query.
     * Only a genuine chain costs a SELECT per hop, and only on a request that
     * is being redirected rather than rendered.
     *
     * A target on another host cannot loop back into this application's path
     * space, so `targetPath()` answers '' for it and the walk stops.
     */
    private static function loops(string $path, Redirect $row): bool
    {
        $seen = [$path => true];
        $next = self::targetPath((string) $row->target);
        $hops = 0;

        while (true) {
            if ($next === '') {
                return false;
            }

            if (isset($seen[$next])) {
                return true;
            }

            if (++$hops > self::MAX_HOPS) {
                return true;
            }

            $seen[$next] = true;

            if (!self::claimed($next)) {
                return false;
            }

            $step = self::row($next);

            if ($step === null) {
                return false;
            }

            $next = self::targetPath((string) $step->target);
        }
    }

    /**
     * The part of a target that could collide with an incoming path, or '' if
     * nothing about it can.
     *
     * `redirects.source` is compared against getPathInfo(), which carries no
     * scheme, host, query string or fragment, so only the path of a target can
     * ever equal one.
     */
    private static function targetPath(string $target): string
    {
        if ($target === '') {
            return '';
        }

        // Absolute, protocol-relative, or a mailto:/tel: link — another host's
        // problem, and not a path this application can be asked for.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $target)) {
            return '';
        }

        $path = parse_url($target, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * Paths this middleware never looks at.
     *
     * The first six are the storefront-versus-machinery split this class was
     * written with: a redirect rule is a storefront-URL concept and checking
     * one against every asset request is pure overhead.
     *
     * The last three are new with the registration, and each is a real failure
     * this would otherwise be able to cause. They are the same three
     * CanonicalHost exempts, in the same order and for the same reasons:
     *
     *   _kbb-health      UpdateRunner fetches this over HTTP right after it
     *                    writes files, and rolls the update back if it does
     *                    not answer. A 301 here is an update that always
     *                    rolls itself back — and the row that caused it would
     *                    be applied by the same package.
     *
     *   import-chain     the loopback the background import calls to continue
     *                    itself. It is a POST, so the method check above
     *                    already covers it; named anyway, because the cost of
     *                    the belt is nothing and the cost of the braces
     *                    failing is an import that stops when the tab closes.
     *
     *   kbb-recover.php  the standalone recovery script. It does not boot
     *                    Laravel so it never reaches this middleware, but a
     *                    future reader moving it inside the application must
     *                    not quietly break the escape hatch.
     */
    private static function isExemptPath(string $path): bool
    {
        foreach ([
            'admin', 'admin-api', 'api', 'build', 'uploads', 'storage',
            '_kbb-health', 'import-chain', 'kbb-recover.php',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
