<?php
/*
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
        /* Lane PO: the network to Stripe is not ours, but it is in the
           timeline. A fixed, configurable stand-in for it (PO_STRIPE_MS), and
           the time spent is reported per request (X-Po-Stripe-Ms). */
        $poStart = microtime(true);
        usleep(((int) (getenv('PO_STRIPE_MS') ?: 0)) * 1000);
        $GLOBALS['poStripeMs'] = ($GLOBALS['poStripeMs'] ?? 0) + (microtime(true) - $poStart) * 1000;
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
/* Lane PO: what an SMTP round trip costs, per message (PO_MAIL_MS), and where
   it was paid -- before the response (X-Po-Mail-Ms) or after it (mail.log). */
$GLOBALS['poSent'] = false;
$app->booted(function () use ($state) {
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($e) use ($state) {
        $t = microtime(true);
        usleep(((int) (getenv('PO_MAIL_MS') ?: 0)) * 1000);
        $ms = (microtime(true) - $t) * 1000;
        if ($GLOBALS['poSent']) {
            file_put_contents($state.'/mail.log', 'after-response '.round($ms)." ms\n", FILE_APPEND);
        } else {
            $GLOBALS['poMailMs'] = ($GLOBALS['poMailMs'] ?? 0) + $ms;
            file_put_contents($state.'/mail.log', 'in-request '.round($ms)." ms\n", FILE_APPEND);
        }
    });
});
$app->booted(function () use (&$coQueries, $state) {
    \Illuminate\Support\Facades\DB::listen(function ($q) use (&$coQueries, $state) {
        $coQueries++;
        /* Lane PO: every query of a Place order, with its time, for the
           slow-query look (state/qlog.txt). */
        if (str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/checkout/place')) {
            file_put_contents($state.'/qlog.txt', ($GLOBALS['poSent'] ? 'AFTER ' : '').round($q->time, 2).' ms  '.substr(preg_replace('/\s+/', ' ', $q->sql), 0, 160)."\n", FILE_APPEND);
        }
    });
});
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Co-Queries', (string) $coQueries);
$response->headers->set('X-Co-Ms', (string) round((microtime(true) - LARAVEL_START) * 1000, 1));
$response->headers->set('X-Po-Stripe-Ms', (string) round($GLOBALS['poStripeMs'] ?? 0, 1));
$response->headers->set('X-Po-Mail-Ms', (string) round($GLOBALS['poMailMs'] ?? 0, 1));
/* php -S keeps the connection open until the script ends; under PHP-FPM,
   Symfony's send() calls fastcgi_finish_request() and the client has the
   answer before terminate() runs. A Content-Length gives the preview the same
   shape, so work deferred to terminate() is measured where it really lands. */
if (! $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse && ! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
    $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
}
$response->send();
$GLOBALS['poSent'] = true;
$kernel->terminate($request, $response);
/* Lane PO: every request's span, start to the end of terminate(), for the
   "who else was writing" question (state/req.log). */
file_put_contents($state.'/req.log', sprintf("%.3f %.3f %s %s\n", LARAVEL_START, microtime(true), $request->method(), $request->getRequestUri()), FILE_APPEND);
