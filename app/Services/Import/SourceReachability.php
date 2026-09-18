<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Support\CategoryPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Will a stored redirect for this address ever actually fire?
 *
 * =============================================================================
 * THE ONE FACT THIS WHOLE CLASS RESTS ON
 * =============================================================================
 *
 * In this application the redirect table is consulted **only from the 404
 * handler**. `CheckRedirects` is written as middleware and is NOT registered as
 * middleware — its own doc comment records that a redirect on a matched route
 * still returned 200 with that registration, from both boot() and register() —
 * so the live check is the `renderable(NotFoundHttpException …)` closure in
 * `AppServiceProvider`.
 *
 * The consequence is absolute and is not written down anywhere the map could
 * see it: **an address that does not 404 cannot be redirected by a row.** The
 * row is stored, the admin screen lists it, its `hits` counter stays at zero
 * forever, and nobody finds out. `KBB-Master-Plan.md` names this as a known
 * limitation of the Redirects screen for manual rows; nothing applied it to the
 * map the migration builds.
 *
 * MEASURED, NOT INFERRED. Against a running server, with a category `toners`
 * nested under `skincare`:
 *
 *     GET /product-category/toners/   → 301 → /product-category/skincare/toners
 *
 * with NO redirect row at all — `CategoryArchiveController` → `CategoryPath::
 * resolve()` does that itself. A row was then written for that exact source
 * pointing at `/PROOF-INERT/`, and the same request still answered 301 to
 * `/product-category/skincare/toners`. The row changed nothing. See
 * `docs/GB-MEDIA-AND-REDIRECTS.md` for the transcript.
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
    /** Nothing serves this address: a redirect row here will fire. */
    public const NOT_FOUND = 'notfound';

    /** The application already moves this address on its own. A row is inert. */
    public const MOVED = 'moved';

    /** A real page answers here. A row is inert, and pointing it away is a decision. */
    public const SERVED = 'served';

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

        if (preg_match('#^/product-category/(.*)$#', $path, $m) === 1) {
            return $this->categoryVerdict((string) $m[1]);
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
            return [
                'status' => self::MOVED,
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

            return [
                'status' => self::SERVED,
                'why' => 'the route "'.($route->uri() === '' ? '/' : $route->uri()).'" claims this address with no '
                    .'parameters of its own, so the storefront answers it and a stored redirect is never reached',
            ];
        }

        /*
         * A parameterised route. Its URI cannot say whether the controller will
         * find a row or abort(404), and the difference is the whole question —
         * `/toners/` matches the blog catch-all `/{slug}/` and 404s, which is
         * precisely why a redirect for it works.
         */
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
                'why' => 'this address is '.$noun.' on this shop today, so it answers 200 and the redirect is '
                    .'never reached',
            ]
            : ['status' => self::NOT_FOUND, 'why' => $whenMissing];
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
