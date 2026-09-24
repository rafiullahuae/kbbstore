<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\Product;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks the shop whether it is actually a shop.
 *
 * WHY THIS EXISTS, 24 September 2026. `/_kbb-health` ran `SELECT 1` and
 * returned JSON. It never rendered a page. Package 2.60.260 removed a class
 * that every product tile resolves out of the container, and the endpoint
 * answered `{"ok":true}` in the same breath -- because PHP was running and the
 * database was up, which is all it had ever asked. The update was KEPT. The
 * home page, /shop, every category, every brand and every product page were
 * 500ing for an hour behind a health check that was still reporting green.
 *
 * `SELECT 1` proves the process booted. It cannot prove the application boots,
 * because nothing in it loads a controller, resolves a service out of the
 * container or compiles a Blade template -- and those are the three things an
 * update actually changes. So this renders real storefront pages, in the same
 * freshly booted process the runner reached over HTTP, and reports what came
 * back.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT ASSERTS, AND WHY EACH ONE IS NOT COPY
 * ---------------------------------------------------------------------------
 *
 * The hard constraint is that a lane must be free to rewrite every visible
 * string on the shop without turning the updater into a brick. So nothing here
 * pins wording, prices, labels or layout. Four properties, each true of any
 * working storefront page and false of a broken one:
 *
 *   1. STATUS IS EXACTLY 200. Catches the ordinary case: an exception anywhere
 *      in routing, the controller, the container or the view is caught by
 *      Laravel and rendered as 500. This alone would have caught 2.60.260.
 *      A 3xx is a failure too and the reason names the Location, because a
 *      storefront page that redirects is not a storefront page.
 *
 *   2. THE BODY IS HTML AND ENDS IN `</html>`. Catches the case status cannot:
 *      a real PHP fatal (not a throwable -- a parse error in an included file,
 *      an exhausted memory limit) during view rendering, where the output
 *      buffer is flushed with whatever had been emitted so far and the status
 *      stays 200. A truncated page is indistinguishable from a whole one by
 *      status code and obvious by its last fourteen bytes.
 *
 *   3. THE BODY CLEARS A LENGTH FLOOR. A 200 with an empty or near-empty body
 *      is not a rendered page. The floor is deliberately far below any real
 *      page so no legitimate edit can approach it.
 *
 *   4. THE PRODUCT PAGE CONTAINS ITS OWN PRODUCT'S SLUG. This is the one
 *      identity assertion, and it is data rather than copy: the slug is read
 *      out of the same database a moment earlier, and the page carries it in
 *      its canonical link, its og:url and its own form actions. It proves the
 *      page rendered THAT product rather than merely answering 200. A lane is
 *      free to change every word on the product page; it is not free to ship
 *      one that no longer knows which product it is.
 *
 * There is no equivalent datum for the home page. Its content is configurable
 * down to the section, so any assertion about what is ON it is an assertion
 * about copy. It gets 1-3 and nothing more, which is enough: the failure this
 * exists for took the home page to a 500.
 *
 * ---------------------------------------------------------------------------
 * AN EMPTY CATALOGUE MUST NOT FAIL IT
 * ---------------------------------------------------------------------------
 *
 * A shop with no visible products has no product page to render, and that is a
 * legitimate state -- a fresh install, or a catalogue mid-import. The product
 * check then reports `skipped` and the overall verdict stays ok. The checks
 * array still carries the skip, so the reason is visible rather than implied.
 *
 * For the same reason the product check tries up to PRODUCT_ATTEMPTS products
 * and passes if ANY of them renders. One product with bad data must not be able
 * to block every future update; a storefront whose product pages are ALL down
 * -- which is exactly what 2.60.260 did -- fails on the first three and is
 * caught.
 *
 * ---------------------------------------------------------------------------
 * COST
 * ---------------------------------------------------------------------------
 *
 * Two sub-requests through the HTTP kernel, once per update. No outbound
 * network: the runner has already paid for one real HTTP round trip to get
 * here, and this reuses that freshly booted process rather than asking the
 * server to call itself twice more, which deadlocks a single-worker PHP-FPM
 * pool.
 *
 * The process being freshly booted is what makes a sub-request worth anything:
 * the files the package just wrote are being loaded for the first time, which
 * is the thing under test. A controller, a container binding and a Blade
 * template all get compiled and run, and that is the whole of what `SELECT 1`
 * was not doing.
 *
 * Neither sub-request is terminated. Kernel::terminate() is what persists the
 * session and runs deferred work, and a health check has no business writing a
 * session row, sending queued mail or touching a shopper's cart. Nothing here
 * reads a response header that terminate() would set.
 *
 * WHAT IT DOES NOT CHECK: /shop, category pages, brand pages, the cart, the
 * checkout. 2.60.260 took all of them down and the home page and a product page
 * caught it, because they shared the broken component -- which is the usual
 * shape. A package that breaks only the checkout still passes this. The page
 * list is one array and adding to it costs one render each.
 */
final class StorefrontHealth
{
    /** Below this many bytes a 200 is not a rendered page. */
    private const MIN_BYTES = 512;

    /** How many visible products to try before calling product pages broken. */
    private const PRODUCT_ATTEMPTS = 3;

    /**
     * @return array{ok: bool, reason: string, checks: array<int, array<string, mixed>>}
     */
    public function check(Request $current): array
    {
        $checks = [];

        $checks[] = $this->database();

        if (config('kbb.health_deep', true)) {
            $checks[] = $this->homePage($current);
            $checks[] = $this->productPage($current);
        } else {
            $checks[] = [
                'check' => 'storefront',
                'ok' => true,
                'detail' => 'skipped: KBB_HEALTH_DEEP is off',
            ];
        }

        $failed = array_values(array_filter($checks, static fn (array $c): bool => $c['ok'] !== true));

        return [
            'ok' => $failed === [],
            'reason' => $failed === []
                ? 'ok'
                : implode('; ', array_map(
                    static fn (array $c): string => $c['check'].': '.$c['detail'],
                    $failed,
                )),
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    private function database(): array
    {
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');

            return ['check' => 'database', 'ok' => true, 'detail' => 'connected'];
        } catch (\Throwable $e) {
            return ['check' => 'database', 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function homePage(Request $current): array
    {
        $verdict = $this->render($current, $this->path('home'));

        return ['check' => 'home'] + $verdict;
    }

    /** @return array<string, mixed> */
    private function productPage(Request $current): array
    {
        try {
            $slugs = Product::query()
                ->visible()
                ->orderBy('id')
                ->limit(self::PRODUCT_ATTEMPTS)
                ->pluck('slug')
                ->all();
        } catch (\Throwable $e) {
            return ['check' => 'product', 'ok' => false, 'detail' => 'could not list products: '.$e->getMessage()];
        }

        $slugs = array_values(array_filter($slugs, static fn ($slug): bool => is_string($slug) && $slug !== ''));

        if ($slugs === []) {
            return [
                'check' => 'product',
                'ok' => true,
                'detail' => 'skipped: this shop has no visible products',
            ];
        }

        $failures = [];

        foreach ($slugs as $slug) {
            $verdict = $this->render($current, $this->path('product.show', ['slug' => $slug]), $slug);

            if ($verdict['ok'] === true) {
                return ['check' => 'product', 'ok' => true, 'detail' => $verdict['detail'], 'slug' => $slug];
            }

            $failures[] = $slug.' — '.$verdict['detail'];
        }

        return [
            'check' => 'product',
            'ok' => false,
            'detail' => sprintf(
                'none of the first %d visible product pages rendered (%s)',
                count($slugs),
                implode('; ', $failures),
            ),
        ];
    }

    /**
     * The path a named route serves, base path and all, with no host on it.
     *
     * Relative on purpose. The host is taken from the request that reached this
     * endpoint, below, rather than from APP_URL: that request has already been
     * served by this host, so a sub-request built on the same host cannot be
     * bounced by CanonicalHost -- which redirects every path but three, and
     * would otherwise turn a host misconfiguration into an update that always
     * rolls itself back.
     */
    private function path(string $name, array $parameters = []): string
    {
        try {
            return route($name, $parameters, false);
        } catch (\Throwable) {
            // A route that will not even generate is itself a failure; render()
            // reports it as a 404 rather than throwing out of the health check.
            return '/__kbb-health-unroutable';
        }
    }

    /**
     * Renders one page through the HTTP kernel and judges what came back.
     *
     * @param  string|null  $mustContain  a datum, never copy — see the class docblock
     * @return array{ok: bool, detail: string}
     */
    private function render(Request $current, string $path, ?string $mustContain = null): array
    {
        $url = $current->getSchemeAndHttpHost().'/'.ltrim($path, '/');

        /*
         * The container's bound request is swapped for the duration of the
         * sub-request and put back afterwards, whatever happens. Kernel::handle()
         * rebinds it and clears the resolved facades; without the restore the
         * response THIS endpoint is about to return would be built against the
         * wrong request, and in the test suite the following test would inherit
         * it.
         */
        $original = app()->bound('request') ? app('request') : null;

        try {
            $response = app(HttpKernel::class)->handle(Request::create($url, 'GET'));
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => get_class($e).': '.$e->getMessage()];
        } finally {
            if ($original instanceof Request) {
                app()->instance('request', $original);
            }
        }

        return $this->judge($response, $mustContain);
    }

    /** @return array{ok: bool, detail: string} */
    private function judge(Response $response, ?string $mustContain): array
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            return [
                'ok' => false,
                'detail' => 'HTTP '.$status.' redirect to '.(string) $response->headers->get('Location'),
            ];
        }

        if ($status !== 200) {
            return ['ok' => false, 'detail' => 'HTTP '.$status];
        }

        $type = (string) $response->headers->get('Content-Type');

        if (! str_contains(strtolower($type), 'text/html')) {
            return ['ok' => false, 'detail' => 'not HTML (Content-Type: '.($type !== '' ? $type : 'none').')'];
        }

        $body = $response->getContent();

        if (! is_string($body)) {
            return ['ok' => false, 'detail' => 'the response had no body'];
        }

        $bytes = strlen($body);

        if ($bytes < self::MIN_BYTES) {
            return ['ok' => false, 'detail' => 'body is only '.$bytes.' bytes'];
        }

        // A PHP fatal mid-render flushes the buffer and leaves the status at
        // 200. The closing tag is the cheapest proof the template finished.
        if (! str_ends_with(rtrim($body), '</html>')) {
            return ['ok' => false, 'detail' => 'the page stops before </html> — it rendered only '.$bytes.' bytes'];
        }

        if ($mustContain !== null && ! str_contains($body, $mustContain)) {
            return ['ok' => false, 'detail' => 'rendered, but does not mention "'.$mustContain.'"'];
        }

        return ['ok' => true, 'detail' => 'rendered '.$bytes.' bytes'];
    }
}
