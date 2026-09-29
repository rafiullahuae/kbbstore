<?php
/* Preview front controller — scratchpad tooling, never part of a package.
   `tools/` is not on UpdateGuard::ALLOWED_PREFIXES and BuildPackage::NEVER_SHIP
   blocks it twice over, which is the point: the fake provider APIs below must
   never run anywhere near a real shop.

   Boots the worktree's app and fakes the two provider hosts, because
   api-sandbox.tamara.co and api.tabby.ai are both refused by this sandbox's
   egress proxy. What this proves is that the SCREEN and the APPLICATION agree
   with each other; it does not prove Tamara or Tabby agree. */
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

define('LARAVEL_START', microtime(true));

/**
 * STANDS IN FOR THE ONE LINE THE INTEGRATOR ADDS.
 *
 * The screen reaches the browser through
 * `@include('admin.partials.tamara-connection-screen')` at the foot of
 * resources/views/admin/app.blade.php, which is the integrator's file — three
 * lanes edit it at once and CLAUDE.md forbids a lane touching it. So the
 * preview appends the partial's rendered output itself, in exactly the place
 * and order that include puts it: after app.blade.php has closed its raw block
 * and defined window.go, window.kbbAddNavEntry and toast().
 *
 * Same trick, and the same reason, as tools/pg1-tamara-shots/preview-index.php
 * mounting routes/payments-tamara.php before its require line existed. What it
 * proves is that the screen works where the include puts it; the include itself
 * is pinned by TamaraConsoleReachTest and EverythingIsMountedOnceTest.
 */
class TmPreviewInclude
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');

        if (! str_contains($type, 'text/html') || ! method_exists($response, 'getContent')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if (! str_contains($html, 'kbbAddNavEntry') || str_contains($html, 'tmw-wrap')) {
            return $response;
        }

        // ?tmoff=1 renders the console WITHOUT this lane's partial, so a
        // baseline shot can be taken of exactly the same shop.
        if ($request->query('tmoff') === '1') {
            return $response;
        }

        $partial = view('admin.partials.tamara-connection-screen')->render();

        /*
         * THE LAST `</body>`, AND str_replace WOULD HAVE BEEN WRONG.
         *
         * banners-screen.blade.php builds an iframe document in a JavaScript
         * string and that string contains `</body>`. A str_replace put this
         * partial INSIDE that script, which closed it early — banners' own
         * JavaScript then rendered as visible text at the foot of every admin
         * page and the console threw "Invalid or unexpected token". It looked
         * exactly like a defect in the partial and was a defect in this
         * harness; Blade's own @include, which is what actually ships, appends
         * at one known place and cannot do this.
         */
        $at = strrpos($html, '</body>');

        if ($at === false) {
            return $response;
        }

        $response->setContent(substr_replace($html, $partial, $at, 0));

        return $response;
    }
}

$base = '/home/user/lane-tm';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$app->booted(function () {
    /* routes/payments-tamara.php, payments-tabby.php and the rest are ALREADY
       required by routes/web.php on this branch — verified rather than assumed,
       and that is why nothing is mounted here. The pg1 harness had to mount the
       Tamara file itself because its require line did not exist yet. */
    Route::pushMiddlewareToGroup('web', TmPreviewInclude::class);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $method = strtoupper($request->method());

        /* ---------------------------------------------------------- Tamara */
        if ($method === 'POST' && $path === '/webhooks') {
            return Http::response(['webhook_id' => 'wh_preview_a1b2c3'], 201);
        }

        if ($method === 'DELETE' && str_starts_with($path, '/webhooks/')) {
            return Http::response([], 204);
        }

        if ($method === 'GET' && $path === '/checkout/payment-types') {
            return Http::response([
                ['name' => 'PAY_NOW', 'description' => 'Pay now',
                 'min_limit' => ['amount' => 1, 'currency' => 'AED'], 'max_limit' => ['amount' => 9000, 'currency' => 'AED']],
                ['name' => 'PAY_BY_LATER', 'description' => 'Pay in 30 days',
                 'min_limit' => ['amount' => 100, 'currency' => 'AED'], 'max_limit' => ['amount' => 5000, 'currency' => 'AED']],
            ], 200);
        }

        /* The sweep's reads. The seeded shop has one `pending` Tamara order that
           Tamara reports as `approved` — exactly the shape of an approval whose
           notification never arrived — so pressing the button marks it paid and
           the screen can be photographed saying so. */
        if ($method === 'GET' && str_starts_with($path, '/merchants/orders/reference-id/')) {
            return Http::response(['order_id' => 'tam_preview_missed'], 200);
        }

        if ($method === 'GET' && str_starts_with($path, '/merchants/orders/')) {
            return Http::response([
                'order_id' => 'tam_preview_missed',
                'order_reference_id' => \App\Models\Order::where('payment_method', 'tamara')
                    ->orderBy('id')->value('order_number'),
                'status' => 'approved',
                'total_amount' => ['amount' => 250.00, 'currency' => 'AED'],
            ], 200);
        }

        if ($method === 'POST' && str_ends_with($path, '/authorise')) {
            return Http::response(['order_id' => 'tam_preview_missed', 'status' => 'authorised'], 200);
        }

        /* ----------------------------------------------------------- Tabby */
        if ($path === '/api/v1/webhooks') {
            if ($method === 'GET') {
                return Http::response([], 200);
            }

            return Http::response(['id' => 'tabby_wh_preview'], 200);
        }

        if (str_starts_with($path, '/api/v1/webhooks/')) {
            return Http::response([], 204);
        }

        return Http::response(['error_code' => 'unfaked_'.$method], 418);
    });
});

$app->handleRequest(Illuminate\Http\Request::capture());
