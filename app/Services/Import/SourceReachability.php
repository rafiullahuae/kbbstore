<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Support\CategoryPath;
use App\Support\LegacyCategoryUrls;
use App\Support\UrlScheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Will a stored redirect for this address ever actually fire?
 *
 * =============================================================================
 * THE ONE FACT THIS WHOLE CLASS RESTS ON — REWRITTEN, AND THE OLD ONE NAMED
 * =============================================================================
 *
 * THIS CLASS DOES NOT ANSWER "will a row fire". It answers **what this shop
 * does at this address today**, which is a different and more durable question,
 * and it is the question the four verdicts below actually describe.
 *
 * WHAT IT USED TO REST ON, in as many words: "the redirect table is consulted
 * only from the 404 handler, so an address that does not 404 cannot be
 * redirected by a row." That was true and **it is no longer true.**
 * `CheckRedirects` is registered in the global pipeline now and runs BEFORE the
 * router, so a row for an address this shop answers 200 for does fire. The
 * whole premise is recorded here rather than deleted, because it is what a
 * reader will reach for next time, and `docs/GP-ADDRESSES-LAND.md` §13 is the
 * transcript of it being disproved against a running server.
 *
 * WHAT DID NOT CHANGE, AND THE MEASUREMENT THAT STILL HOLDS. Against a running
 * server, with a category `toners` nested under `skincare`:
 *
 *     GET /product-category/toners/   → 301 → /product-category/skincare/toners
 *
 * with NO redirect row at all — `CategoryArchiveController` → `CategoryPath::
 * resolve()` does that itself. A row was then written for that exact source
 * pointing at `/PROOF-INERT/`, and the same request still answered 301 to
 * `/product-category/skincare/toners`. See `docs/GB-MEDIA-AND-REDIRECTS.md`.
 *
 * THAT MEASUREMENT IS STILL VALID, and it is worth being exact about which half
 * of it survived:
 *
 *   - STILL TRUE — THE APPLICATION'S OWN REDIRECT WINS. The 301 above is made
 *     by `CategoryPath::resolve()` INSIDE the controller, on a path the
 *     controller reaches, and nothing in this change moved that. `MOVED` is
 *     still "the shop moves this address by itself".
 *
 *   - NO LONGER TRUE — THE 200 CASE. "A row for an address the shop answers is
 *     inert" was the general rule that transcript was read as proving, and it
 *     is exactly what the global registration undid. A row for a `SERVED`
 *     address now fires from the pipeline before the router ever picks the
 *     route, so it does not merely fail to help: IT REPLACES A WORKING PAGE.
 *
 * WHICH IS WHY THE `why` STRINGS BELOW STATE THE OBSERVATION AND NOT THE
 * CONSEQUENCE. What a row would DO about a verdict is `RedirectMap::reachable()`'s
 * sentence, because that is the thing that changed and the thing that can change
 * again. A verdict that carried its own conclusion put "a stored redirect is
 * never reached" and "a row here WILL move it" into one sentence on the screen
 * the owner approves rows from.
 *
 * =============================================================================
 * HOW THE VERDICT IS REACHED
 * =============================================================================
 *
 * Not by dispatching the request. Running a storefront request from inside a
 * console command or an admin endpoint means re-entering the kernel with
 * another request's session, cache and locale state — and doing it once per
 * proposed row. What this does instead is ask the two things the storefront
 * itself asks, in the same order the router does:
 *
 *   1. `/product-category/…` is resolved by `CategoryPath::resolve()`, which is
 *      the exact call `CategoryArchiveController::show()` makes. Its three
 *      verdicts map one-for-one onto ours.
 *
 *   2. Everything else is matched against the REAL route table. A route WITH
 *      parameters cannot be judged from its URI alone, so the two that can
 *      plausibly claim an address this map proposes — a post, a product — are
 *      resolved against their own tables, and anything else is answered UNKNOWN.
 *      A route with NO parameters serves a page, with one exception that is
 *      handled rather than rounded off: the seven WordPress pages are literal
 *      routes carrying `->defaults('slug', …)`, and PageController::show()
 *      still 404s if the `pages` row is gone.
 *
 * UNKNOWN IS A REAL ANSWER and is not folded into `notfound`. Folding it in
 * would write a row on a guess; folding it into `served` would suppress a
 * redirect that might be the only thing standing between a URL and a 404. It
 * becomes a question for the owner, which is what an unknown is.
 *
 * WHY THE ROUTER AND NOT A LIST OF PATHS. A hard-coded list of "addresses the
 * shop serves" is a filter that rots: `/routines` shipped this month and
 * `/subscribe` the month before, and a list written today is wrong by the next
 * package. The route table cannot be out of date with itself.
 */
final class SourceReachability
{
    /**
     * Nothing serves this address: a redirect row here will fire.
     *
     * The one verdict the registration change did not touch. An address that
     * 404s was reached by the table from the 404 handler before and is reached
     * from the global pipeline now — it fires either way.
     */
    public const NOT_FOUND = 'notfound';

    /**
     * The application already moves this address on its own.
     *
     * "A row is inert", which this used to say, is the premise the class
     * comment records as gone: a row here now fires from the global pipeline
     * and OVERRIDES the shop's own hop rather than being ignored by it.
     */
    public const MOVED = 'moved';

    /** A real page answers here. Pointing it away is a change to a working page. */
    public const SERVED = 'served';

    /**
     * Routes that take no parameter and render nothing — they forward.
     *
     * Keyed on `Class@method` so the destination is stated once beside the
     * route that produces it. Both are the address scheme's retired doors.
     *
     * @var array<string, string>
     */
    private const PARAMETERLESS_REDIRECTS = [
        'PageController@blog' => UrlScheme::BLOG_BASE,
        'BrandController@legacyIndex' => UrlScheme::BRAND_BASE,
    ];

    /** A parameterised route claims it and this cannot say what it answers. */
    public const UNKNOWN = 'unknown';

    /**
     * @return array{status: string, why: string}
     */
    public function verdict(string $path): array
    {
        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/')) {
            return [
                'status' => self::UNKNOWN,
                'why' => 'this is not a root-relative path, and getPathInfo() is always one',
            ];
        }

        // The query string is not part of getPathInfo(), so it is not part of
        // what a redirect matches and not part of what the router sees either.
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

        /*
         * THE ARCHIVE, AT BOTH OF ITS ADDRESSES.
         *
         * The scheme moved category archives from /product-category/{path}/ to
         * /collections/{path}/. Both are asked here and they get DIFFERENT
         * answers, which is the whole point:
         *
         *   /collections/…      the archive. Served, moved (a non-canonical
         *                       nesting, or a category_redirects row) or gone.
         *
         *   /product-category/… the retired address.
         *                       CategoryArchiveController::show() 301s it onto
         *                       the canonical collections path, so it is MOVED
         *                       whenever the category resolves at all — never
         *                       SERVED. RedirectMap then DISCARDS its own
         *                       proposal for it with "this shop already sends
         *                       this address to exactly this destination",
         *                       which is the right answer: the shop's hop is
         *                       derived and follows a rename, and a written row
         *                       would go on pointing at import day's path.
         */
        if (str_starts_with($path, UrlScheme::COLLECTION_BASE)) {
            return $this->categoryVerdict(substr($path, strlen(UrlScheme::COLLECTION_BASE)));
        }

        if (str_starts_with($path, UrlScheme::LEGACY_COLLECTION_BASE)) {
            return $this->legacyCategoryVerdict(substr($path, strlen(UrlScheme::LEGACY_COLLECTION_BASE)));
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE DERIVED REDIRECT, AND WITHOUT IT THIS CLASS NOW LIES
         * ═══════════════════════════════════════════════════════════════════
         *
         * routeVerdict() below asks the router and the tables, in the order
         * the router asks them. That was the whole story until Lane SEO round
         * 1: `CheckRedirects` now DERIVES a 301 for the fifteen legacy flat
         * category addresses in `LegacyCategoryUrls::PATHS`, from the global
         * pipeline, BEFORE the router is reached.
         *
         * So the router's answer for `/toners/` is no longer the shop's
         * answer. Measured on this tree before this branch existed:
         *
         *     SourceReachability::verdict('/toners/')
         *       → notfound, "PageController::post() throws a 404 for a slug
         *          with no published post, so the redirect is reached"
         *
         * Every word of that was true when it was written and the address it
         * describes now answers 301. routeVerdict()'s own comment still says
         * "`/toners/` matches the blog catch-all `/{slug}/` and 404s, which is
         * precisely why a redirect for it works" — the same sentence, one
         * level down, and it is corrected there too.
         *
         * The cost of leaving it: `reachable()` reads `notfound` as "nothing
         * serves this, a row here is pure gain" and the import writes thirty
         * rows restating what the shop already does for itself. Measured at 38
         * `migrate` proposals on a fully imported tree, of which 30 were that.
         *
         * ── THE DERIVED RULE ONLY, NEVER THE TABLE ──────────────────────────
         *
         * `LegacyCategoryUrls::landingPath()` and NOT `CheckRedirects::lookup()`,
         * and the difference is not tidiness. lookup() consults the `redirects`
         * TABLE first, and this class exists to help decide what to write INTO
         * that table. Asking it would make the map's answer depend on its own
         * previous run: the first import writes a row, the second sees the row
         * and calls the address MOVED, and the reasons on the owner's screen
         * change between two runs that imported identical data. The derived
         * rule is a property of the shop's code and its categories, which is
         * exactly the class of fact this verdict is about.
         */
        $derived = LegacyCategoryUrls::landingPath($path);

        if ($derived !== null) {
            return [
                'status' => self::MOVED,
                'to' => $derived,
                'why' => 'this shop already forwards this address by itself, to '.$derived.' — it is one of the '
                    .'old flat category addresses in LegacyCategoryUrls::PATHS, and CheckRedirects derives the '
                    .'301 from the category that answers to it, with no row in the redirects table',
            ];
        }

        return $this->routeVerdict($path);
    }

    /**
     * The archive's own answer, from the class the archive controller calls.
     *
     * @return array{status: string, why: string}
     */
    private function categoryVerdict(string $rest): array
    {
        $resolved = CategoryPath::resolve($rest);

        if ($resolved['status'] === 'ok') {
            return [
                'status' => self::SERVED,
                'why' => 'this is a live category archive: CategoryPath::resolve() answers "ok" and the page '
                    .'renders with a 200',
            ];
        }

        if ($resolved['status'] === 'redirect') {
            /*
             * `to_path` and not `to`. `to` has been through `Url::to()`, so it
             * carries the base path and the reader's locale segment; `to_path`
             * is the bare path, which is the spelling `redirects.target` uses
             * and therefore the only one a proposal's target can be compared
             * against. Comparing the prefixed form would make every one of
             * these look like a DIFFERENT destination on a subfolder mount —
             * the same prefix trap this file's neighbours already carry a
             * warning about.
             */
            $toPath = trim((string) ($resolved['to_path'] ?? ''), '/');

            return [
                'status' => self::MOVED,
                'to' => $toPath === '' ? '' : UrlScheme::collection($toPath),
                'why' => 'CategoryArchiveController already answers 301 here on its own, to '
                    .((string) ($resolved['to'] ?? '')).' — the leaf category exists and CategoryPath::resolve() '
                    .'sends the request to its canonical nested path without consulting the redirects table',
            ];
        }

        return [
            'status' => self::NOT_FOUND,
            'why' => 'no category has this leaf and no category_redirects row covers the path, so the archive '
                .'404s and the 404 handler reaches the redirects table',
        ];
    }

    /**
     * The RETIRED archive address, /product-category/{path}/.
     *
     * Never SERVED: the shop no longer renders a page there under any
     * circumstances. It resolves the category and 301s onto the canonical
     * /collections/ path in one hop, or it 404s.
     *
     * `to` is the bare path, for the reason categoryVerdict() gives above: it
     * is compared against a proposal's target, and the prefixed form would look
     * like a different destination on a subfolder mount.
     *
     * @return array{status: string, why: string, to?: string}
     */
    private function legacyCategoryVerdict(string $rest): array
    {
        $resolved = CategoryPath::resolve($rest);

        $canonical = match ($resolved['status']) {
            'ok' => CategoryPath::canonicalPath($resolved['category']),
            'redirect' => trim((string) ($resolved['to_path'] ?? ''), '/'),
            default => '',
        };

        if ($canonical === '') {
            return [
                'status' => self::NOT_FOUND,
                'why' => 'no category has this leaf and no category_redirects row covers the path, so the '
                    .'retired archive address 404s and the 404 handler reaches the redirects table',
            ];
        }

        return [
            'status' => self::MOVED,
            'to' => UrlScheme::collection($canonical),
            'why' => 'this is the retired category address and CategoryArchiveController::show() already answers '
                .'301 here on its own, to '.UrlScheme::collection($canonical).' — it resolves the category first, '
                .'so the visitor makes one hop and lands on the canonical nested path',
        ];
    }

    /**
     * @return array{status: string, why: string}
     */
    private function routeVerdict(string $path): array
    {
        $route = $this->matchedRoute($path);

        if ($route === null) {
            return [
                'status' => self::NOT_FOUND,
                'why' => 'no route in this application matches this address, so it reaches the 404 handler and '
                    .'the redirect is consulted',
            ];
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * Route::fallback() IS NOT A ROUTE THAT ANSWERS, AND IT WAS BEING READ
         * AS ONE
         * ═══════════════════════════════════════════════════════════════════
         *
         * `RouteCollection::match()` returns the fallback route when nothing
         * else matched, and the fallback takes `{fallbackPlaceholder}` — a
         * parameter. So every address with more than one segment that this shop
         * does not serve fell through to the branch at the bottom of this
         * method and was answered UNKNOWN, "cannot tell what this address does":
         *
         *     /product-brand/anua/     an old brand archive  -> cannot tell
         *     /category/news/          an old post category  -> cannot tell
         *     /2019/04/some-post/      an old dated permalink -> cannot tell
         *
         * Every one of those 404s, plainly and observably, and NOT_FOUND is the
         * verdict that lets `RedirectMap` write the row. Instead they became
         * questions — and `docs/FV-IMPORT-AT-VOLUME.md` §10 is explicit that a
         * question list which is mostly noise is a question list nobody
         * finishes. On a real export that is every multi-segment address the old
         * site ever published under a prefix this shop does not have.
         *
         * Found by the brand archive: /product-brand/{slug}/ is exactly this
         * shape, and it is the one address family the address scheme most needs
         * a row for, because /brands/{slug}/ is a page that did not exist before.
         *
         * `isFallback` is Laravel's own flag on the route, set by
         * Route::fallback(), so this cannot drift from what the router means by
         * it.
         */
        if ($route->isFallback) {
            return [
                'status' => self::NOT_FOUND,
                'why' => 'only Route::fallback() matches this address, which is the router saying no route '
                    .'claims it — the request 404s and the redirect is consulted',
            ];
        }

        if ($route->parameterNames() === []) {
            /*
             * A PARAMETERLESS ROUTE THAT CAN STILL 404, which is the one case
             * where "the URI has no parameters" is not the whole answer.
             *
             * The storefront's seven WordPress pages are registered as literal
             * routes carrying `->defaults('slug', 'about')` and friends, and
             * `PageController::show()` does `firstOrFail()` on that slug. Delete
             * the `pages` row and `/about/` 404s while its route still matches.
             * Reading the route alone would answer "served" and put a redirect
             * that WOULD have worked into the ask bucket.
             *
             * The slug is taken from the route's own defaults, so this cannot
             * drift from the route list the way a hard-coded list of seven slugs
             * would.
             */
            $slug = (string) ($route->defaults['slug'] ?? '');

            if ($slug !== '' && str_contains((string) $route->getActionName(), 'PageController@show')) {
                return $this->rowVerdict(
                    Page::query()->where('slug', $slug)->where('status', 'published')->exists(),
                    'a published page',
                    'PageController::show() throws a 404 when no published page carries the slug this route '
                        .'defaults to, so the redirect is reached',
                );
            }

            /*
             * A PARAMETERLESS ROUTE THAT IS ITSELF A 301, which the address
             * scheme created two of: /skincare-guide/ and
             * /korean-skincare-brands/ are registered routes that render
             * nothing and redirect onto /blog/ and /brands/. Answering SERVED
             * for either would tell the owner a page answers at an address the
             * shop forwards, and RedirectMap would ask him whether to move a
             * page that is not there.
             */
            $forwards = self::PARAMETERLESS_REDIRECTS[$this->actionMethod($route)] ?? null;

            if ($forwards !== null) {
                return [
                    'status' => self::MOVED,
                    'to' => $forwards,
                    'why' => 'the route "'.($route->uri() === '' ? '/' : $route->uri()).'" renders no page: it is '
                        .'a 301 onto '.$forwards.', which the address scheme made the canonical address',
                ];
            }

            return [
                'status' => self::SERVED,
                /*
                 * THE OBSERVATION ONLY — the consequence is `reachable()`'s to
                 * state, and it changed. This used to end "and a stored
                 * redirect is never reached", which was true while the table
                 * was read from the 404 handler alone. `CheckRedirects` runs in
                 * the global pipeline now, BEFORE the router, so a row for this
                 * address fires — and `reachable()` appends exactly that to the
                 * reason the owner reads. Leaving the old ending here put both
                 * halves of a contradiction in one sentence on the screen he
                 * approves rows from.
                 */
                'why' => 'the route "'.($route->uri() === '' ? '/' : $route->uri()).'" claims this address with no '
                    .'parameters of its own, so the storefront answers it',
            ];
        }

        /*
         * A parameterised route. Its URI cannot say whether the controller will
         * find a row or abort(404), and the difference is the whole question.
         *
         * ▲ THE EXAMPLE THIS USED TO GIVE IS NO LONGER TRUE, and is corrected
         * rather than deleted because it is what a reader reaches for. It read:
         * "`/toners/` matches the blog catch-all `/{slug}/` and 404s, which is
         * precisely why a redirect for it works." Since Lane SEO round 1 that
         * address never reaches the router at all — the derived rule in
         * verdict() above answers it first. An address that still makes the
         * point is `/some-old-article-slug/`, which matches the same catch-all
         * and 404s when no post carries the slug.
         */
        /*
         * ═══════════════════════════════════════════════════════════════════
         * THE ROUTES THAT ARE THEMSELVES A 301, MATCHED ON THE CONTROLLER
         * METHOD RATHER THAN ON A ROUTE NAME
         * ═══════════════════════════════════════════════════════════════════
         *
         * The address scheme left three parameterised routes whose whole job is
         * to forward: /{slug}/ at the site root, /skincare-guide/{slug}/ with
         * /post/{slug}, and /brand/{slug}/ with /korean-skincare-brands/{slug}/.
         * None of them renders anything.
         *
         * They are told apart by their ACTION and not by `->name()`, and that is
         * deliberate: only one of the five carries a name at all, and a name is
         * a thing a later lane can move between routes without noticing this
         * file. The action is the method that does the work.
         *
         * The verdict is MOVED with a destination, not SERVED and not UNKNOWN,
         * because both of those are false in a way that costs the owner
         * something: SERVED would say a page answers at an address that
         * forwards, and UNKNOWN would put a settled address on his question
         * list. A slug naming nothing is NOT_FOUND — these routes 404 rather
         * than forwarding blindly.
         */
        $method = $this->actionMethod($route);
        $slug = $this->lastSegment($path);

        if ($method === 'PageController@rootArticle' || $method === 'PageController@legacyPost') {
            if ($slug === '' || $slug === 'post' || $slug === 'skincare-guide') {
                return [
                    'status' => self::MOVED,
                    'to' => UrlScheme::blogIndex(),
                    'why' => 'this retired journal address renders no page: it is a 301 onto '
                        .UrlScheme::blogIndex(),
                ];
            }

            return Post::query()->where('slug', $slug)->where('status', 'published')->exists()
                ? [
                    'status' => self::MOVED,
                    'to' => UrlScheme::article($slug),
                    'why' => 'this is a retired article address and the shop already forwards it by itself, in '
                        .'one hop, to '.UrlScheme::article($slug),
                ]
                : [
                    'status' => self::NOT_FOUND,
                    'why' => 'no published article carries this slug, so this address 404s rather than '
                        .'forwarding, and the redirect is reached',
                ];
        }

        if ($method === 'BrandController@legacyShow') {
            return \App\Models\Brand::query()->where('slug', $slug)->exists()
                ? [
                    'status' => self::MOVED,
                    'to' => UrlScheme::brand($slug),
                    'why' => 'this is a retired brand address and the shop already forwards it by itself, in one '
                        .'hop, to '.UrlScheme::brand($slug),
                ]
                : [
                    'status' => self::NOT_FOUND,
                    'why' => 'no brand carries this slug, so this address 404s rather than forwarding, and the '
                        .'redirect is reached',
                ];
        }

        $name = (string) $route->getName();

        if ($name === 'post') {
            return $this->rowVerdict(
                Post::query()->where('slug', $this->lastSegment($path))->where('status', 'published')->exists(),
                'a published post',
                'PageController::post() throws a 404 for a slug with no published post, so the redirect is reached',
            );
        }

        if ($name === 'product.show') {
            return $this->rowVerdict(
                Product::query()->where('slug', $this->lastSegment($path))->exists(),
                'a product',
                'the product controller 404s for an unknown slug, so the redirect is reached',
            );
        }

        return [
            'status' => self::UNKNOWN,
            'why' => 'the route "'.$route->uri().'" takes a parameter, so whether this address answers 200 or 404 '
                .'depends on what its controller finds — this cannot be settled without running it',
        ];
    }

    /**
     * @return array{status: string, why: string}
     */
    private function rowVerdict(bool $exists, string $noun, string $whenMissing): array
    {
        return $exists
            ? [
                'status' => self::SERVED,
                // The observation only, for routeVerdict()'s reason above: what
                // a row here would DO is reachable()'s sentence, not this one.
                'why' => 'this address is '.$noun.' on this shop today, so it answers 200',
            ]
            // $whenMissing is a NOT_FOUND reason and still ends "so the
            // redirect is reached", which is as true as it ever was: an address
            // that 404s is reached by the table from the 404 handler AND from
            // the global pipeline. Nothing about this half moved.
            : ['status' => self::NOT_FOUND, 'why' => $whenMissing];
    }

    /**
     * "PageController@rootArticle" from a matched route's action.
     *
     * The bare class name and the method, so this file does not carry a
     * namespace that a move would silently invalidate into a branch that never
     * fires — the dead-filter shape `Api\ProductController` already cost this
     * repository once.
     */
    private function actionMethod(\Illuminate\Routing\Route $route): string
    {
        $action = (string) $route->getActionName();

        if (! str_contains($action, '@')) {
            return '';
        }

        [$class, $method] = explode('@', $action, 2);

        return class_basename($class).'@'.$method;
    }

    private function lastSegment(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments === [] ? '' : (string) end($segments);
    }

    /**
     * The route the real router would pick, or null.
     *
     * `Request::create()` and not the live request: this runs inside a console
     * command and inside an admin POST, and in both the incoming request is
     * some other address entirely.
     */
    private function matchedRoute(string $path): ?\Illuminate\Routing\Route
    {
        try {
            return Route::getRoutes()->match(Request::create($path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }
    }
}
