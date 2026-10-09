<?php
/*
 * Lane CO (checkout polish): the card-walk front controller, plus the lane's
 * own routes/checkout-sign-in.php mounted in the web group -- the line the
 * integrator adds to routes/web.php -- so the preview serves the sign-in
 * window's endpoints before that line exists.
 *
 * Lane CO's preview front controller: the worktree's app, with every Stripe
 * host faked (the sandbox's egress proxy blocks api.stripe.com, and a
 * developer machine wants no real one anyway). Development tooling only --
 * tools/ is not on UpdateGuard::ALLOWED_PREFIXES, so this never ships.
 *
 * Paths come from CO_APP / CO_STATE (tools/co-card-walk/preview.sh sets both),
 * never hardcoded: a harness that names a lane's worktree dies with it.
 *
 * The fake reads one control file, $CO_STATE/open: "refuse" makes the NEXT
 * POST /v1/payment_intents fail with a 401 (what a wrong test key gives) and
 * then resets itself to "ok". That is the owner's 8 October report's first
 * step, reproducible on demand.
 *
 * And one preview-only route, /__co/sold-out/{slug}, which marks a product
 * sold out after it is in the basket -- the shape of "fwee ... is sold out".
 */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

define('LARAVEL_START', microtime(true));

$base = getenv('CO_APP');
$state = getenv('CO_STATE');
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$app->booted(function () use ($state) {
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($state) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $method = strtoupper($request->method());
        $file = fn (string $id) => $state.'/intent-'.preg_replace('/[^a-z0-9_]/i', '', $id).'.json';

        if ($method === 'POST' && $path === '/v1/payment_intents') {
            if (trim((string) @file_get_contents($state.'/open')) === 'refuse') {
                file_put_contents($state.'/open', 'ok');

                return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: sk_test_****']], 401);
            }

            parse_str($request->body(), $sent);
            $n = (int) @file_get_contents($state.'/seq') + 1;
            file_put_contents($state.'/seq', (string) $n);
            $intent = [
                'id' => 'pi_co_'.$n, 'object' => 'payment_intent',
                'client_secret' => 'pi_co_'.$n.'_secret_preview',
                'status' => 'requires_payment_method',
                'amount' => (int) ($sent['amount'] ?? 0), 'currency' => $sent['currency'] ?? 'aed',
                'metadata' => $sent['metadata'] ?? [], 'customer' => null, 'setup_future_usage' => null,
            ];
            file_put_contents($file($intent['id']), json_encode($intent));

            return Http::response($intent, 200);
        }

        if ($method === 'POST' && preg_match('#^/v1/payment_intents/([^/]+)/cancel$#', $path, $m)) {
            $intent = json_decode((string) @file_get_contents($file($m[1])), true) ?: ['id' => $m[1]];
            $intent['status'] = 'canceled';
            file_put_contents($file($m[1]), json_encode($intent));

            return Http::response($intent, 200);
        }

        if ($method === 'GET' && preg_match('#^/v1/payment_intents/([^/]+)$#', $path, $m)) {
            $intent = json_decode((string) @file_get_contents($file($m[1])), true) ?: ['id' => $m[1]];

            // What the browser's stub answered is what the bank did: a card the
            // walk confirmed is `succeeded` when the server reads it back.
            if (($intent['status'] ?? '') !== 'canceled' && is_file($state.'/confirmed-'.$m[1])) {
                $intent['status'] = 'succeeded';
                $intent['amount_received'] = $intent['amount'] ?? 0;
            }

            return Http::response($intent, 200);
        }

        return Http::response(['error' => ['message' => 'unfaked '.$method.' '.$path]], 418);
    });

    if (! \Illuminate\Support\Facades\Route::has('checkout.signin') && is_file(base_path('routes/checkout-sign-in.php'))) {
        \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/checkout-sign-in.php'));
    }

    /* Lane CO: the seeded customer's own baskets emptied, so each walk signs
       in with nothing to merge (the in-place path) -- or, with ?keep=1 after
       adding one, with an account basket that forces the reload path. */
    /* Lane CO: Appearance -> Checkout page -> Desktop / Mobile · Layout ->
       "Space between blocks", both surfaces at once, for the gap walk. */
    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/block-gap/{px}', function (string $px) {
        app(\App\Services\CheckoutPage::class)->save(['d_block_gap' => (int) $px, 'm_block_gap' => (int) $px]);
        \App\Services\SettingsService::forgetMemo();

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/forget-carts', function () {
        $id = \App\Models\Customer::where('email', 'aisha@example.com')->value('id');
        \App\Models\Cart::where('customer_id', $id)->update(['status' => 'merged']);

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/sold-out/{slug}', function (string $slug) {
        \App\Models\Product::where('slug', $slug)->update(['stock_status' => 'outofstock']);

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/empty/{slug}', function (string $slug) {
        \App\Models\Product::where('slug', $slug)->update(['stock' => 0]);

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/in-stock/{slug}', function (string $slug) {
        \App\Models\Product::where('slug', $slug)->update(['stock_status' => 'instock', 'stock' => 8]);

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/confirmed/{id}', function (string $id) use ($state) {
        touch($state.'/confirmed-'.preg_replace('/[^a-z0-9_]/i', '', $id));

        return response('ok');
    });

    \Illuminate\Support\Facades\Route::middleware('web')->get('/__co/refuse-next', function () use ($state) {
        file_put_contents($state.'/open', 'refuse');

        return response('ok');
    });
});

/* Measured the same way on the BEFORE and AFTER preview: queries and server
   ms for the request, as two response headers the measure script reads. */
$coQueries = 0;
$app->booted(function () use (&$coQueries) {
    \Illuminate\Support\Facades\DB::listen(function () use (&$coQueries) { $coQueries++; });
});
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Co-Queries', (string) $coQueries);
$response->headers->set('X-Co-Ms', (string) round((microtime(true) - LARAVEL_START) * 1000, 1));
$response->send();
$kernel->terminate($request, $response);
