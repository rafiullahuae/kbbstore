<?php
/* Preview front controller — scratchpad tooling, never part of a package.
   `tools/` is not on UpdateGuard::ALLOWED_PREFIXES and BuildPackage::NEVER_SHIP
   blocks it twice over, which is the point: the fake Tamara API below must never
   run anywhere near a real shop.

   api-sandbox.tamara.co is refused by this sandbox's egress proxy, so it is
   faked. What that proves is that the PANEL and the APPLICATION agree with each
   other; it does not prove Tamara agrees. */
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

define('LARAVEL_START', microtime(true));

/**
 * STANDS IN FOR THE ONE LINE THE INTEGRATOR ADDS.
 *
 * The panel reaches the browser through
 * `@include('admin.partials.order-release-hold')` at the foot of
 * resources/views/admin/app.blade.php, which is the integrator's file — three
 * lanes edit it at once and CLAUDE.md forbids a lane touching it. So the preview
 * appends the partial's rendered output itself, in exactly the place and order
 * that include puts it: after app.blade.php has closed its raw block and defined
 * window.go and toast().
 *
 * Same trick, and the same reason, as tools/tm-gateway-shots/preview-index.php.
 */
class OdPreviewInclude
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');

        if (! str_contains($type, 'text/html') || ! method_exists($response, 'getContent')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if (! str_contains($html, 'kbbAddNavEntry') || str_contains($html, 'orh-panel')) {
            return $response;
        }

        // ?odoff=1 renders the console WITHOUT this lane's partial, so a
        // baseline shot can be taken of exactly the same shop.
        if ($request->query('odoff') === '1') {
            return $response;
        }

        $partial = view('admin.partials.order-release-hold')->render();

        /*
         * THE LAST `</body>`, AND str_replace WOULD BE WRONG: banners-screen
         * builds an iframe document in a JavaScript string and that string
         * contains `</body>`, so a str_replace puts the partial inside a script
         * and closes it early. Blade's own @include, which is what ships,
         * appends at one known place and cannot do this.
         */
        $at = strrpos($html, '</body>');

        if ($at === false) {
            return $response;
        }

        $response->setContent(substr_replace($html, $partial, $at, 0));

        return $response;
    }
}

$base = '/home/user/lane-od';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$app->booted(function () {
    /* routes/payments-void.php is ALREADY required by routes/web.php on this
       branch — verified against the real router by ReleaseTheHoldTest rather
       than assumed, which is why nothing is mounted here. */
    Route::pushMiddlewareToGroup('web', OdPreviewInclude::class);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $method = strtoupper($request->method());

        /* TamaraGateway::void() reads the order back from Tamara before it
           cancels anything, and refuses unless the reference matches this
           shop's own order number. Answered from the seeded rows so the two
           orders cannot be confused with each other. */
        if ($method === 'GET' && preg_match('#/merchants/orders/([^/]+)$#', $path, $m) === 1) {
            $order = \App\Models\Order::withTrashed()->where('transaction_id', urldecode($m[1]))->first();

            return Http::response([
                'order_id' => urldecode($m[1]),
                'order_reference_id' => $order?->order_number,
                'status' => 'authorised',
                'total_amount' => ['amount' => 250.00, 'currency' => 'AED'],
            ], 200);
        }

        if ($method === 'POST' && str_ends_with($path, '/cancel')) {
            return Http::response(['order_id' => 'tam_od_release', 'cancel_id' => 'cancel_preview_7f3a'], 200);
        }

        return Http::response(['error_code' => 'unfaked_'.$method], 418);
    });
});

$app->handleRequest(Illuminate\Http\Request::capture());
