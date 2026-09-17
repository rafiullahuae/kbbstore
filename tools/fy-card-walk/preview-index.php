<?php
/* Preview front controller — scratchpad only, never part of a package.
   It boots the worktree's app and fakes every Stripe host, because the
   sandbox's egress proxy blocks api.stripe.com outright. */
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

define('LARAVEL_START', microtime(true));

$base = '/home/user/kbb-wt/fy';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$state = '/tmp/kbbfy-intent.json';

$app->booted(function () use ($state) {
Http::fake(function (\Illuminate\Http\Client\Request $request) use ($state) {
    $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
    $method = strtoupper($request->method());

    /* The Stripe Customer a saved card hangs off. Lane FY's addition to this
       fake: without it, ticking "save this card" refuses to make a customer,
       the intent is opened without setup_future_usage, and the walk would
       silently be proving the fallback rather than the feature. */
    if ($method === 'POST' && $path === '/v1/customers') {
        parse_str($request->body(), $sent);

        return Http::response([
            'id' => 'cus_preview_1',
            'object' => 'customer',
            'email' => $sent['email'] ?? null,
        ], 200);
    }

    if ($method === 'POST' && $path === '/v1/payment_intents') {
        parse_str($request->body(), $sent);
        $n = (int) @file_get_contents('/tmp/kbbfy-seq') + 1;
        @file_put_contents('/tmp/kbbfy-seq', (string) $n);
        $intent = [
            'id' => 'pi_preview_'.$n,
            'object' => 'payment_intent',
            'client_secret' => 'pi_preview_'.$n.'_secret_previewvalue',
            'status' => 'requires_payment_method',
            'amount' => (int) ($sent['amount'] ?? 0),
            'currency' => $sent['currency'] ?? 'aed',
            'metadata' => $sent['metadata'] ?? [],
            // Echoed back so the walk can read what the page actually asked
            // for, and so a reuse read answers with the same shape.
            'customer' => $sent['customer'] ?? null,
            'setup_future_usage' => $sent['setup_future_usage'] ?? null,
        ];
        @file_put_contents($state.'.'.$intent['id'], json_encode($intent));
        return Http::response($intent, 200);
    }

    if ($method === 'GET' && str_starts_with($path, '/v1/payment_intents/')) {
        $id = basename($path);
        $intent = json_decode(@file_get_contents($state.'.'.$id) ?: '{}', true) ?: [];

        /*
         * WHAT THE CARD ACTUALLY DID, not a constant.
         *
         * This read is how the server checks the browser's report, and it is
         * also how abandonIntent() decides whether an intent may be cancelled.
         * Answering "succeeded" unconditionally made the second of those
         * always refuse — the walk's abandon scenario passed on assertions
         * that were true of a page which had not moved, while the order stayed
         *  and the basket stayed converted. The scenario now writes
         * what its stubbed card did and this reports the same thing.
         */
        $outcome = trim(@file_get_contents('/tmp/kbbfy-outcome') ?: 'succeeded');

        if ($outcome === 'succeeded' && ($intent['status'] ?? '') !== 'canceled') {
            $intent['status'] = 'succeeded';
            $intent['amount_received'] = $intent['amount'] ?? 0;
        }

        return Http::response($intent, 200);
    }

    if ($method === 'POST' && str_ends_with($path, '/cancel')) {
        $intent = json_decode(@file_get_contents($state) ?: '{}', true) ?: [];
        $intent['status'] = 'canceled';
        @file_put_contents($state.'.'.$intent['id'], json_encode($intent));
        return Http::response($intent, 200);
    }

    return Http::response(['error' => ['message' => 'unfaked '.$method.' '.$path]], 418);
});
});

/*
 * THE INTEGRATOR'S ONE LINE, SIMULATED.
 *
 * routes/checkout-card.php asks for a single  in routes/web.php,
 * which this lane may not write to. Without it both card endpoints 405 here,
 * and the browser walk would pass its "the shop was told" assertions on
 * requests the server rejected — the exact shape of a test proving nothing.
 * Registered in the same  group the file asks for.
 */
$app->booted(function () {
    \Illuminate\Support\Facades\Route::middleware('web')
        ->group('/home/user/kbb-wt/fy/routes/checkout-card.php');
});

$app->handleRequest(Request::capture());
