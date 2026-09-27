<?php
/* Preview front controller — scratchpad only, never part of a package.
   Boots the worktree's app, mounts routes/payments-tamara.php the way the
   integrator is told to (so the three Tamara buttons are reachable before the
   require line exists in routes/web.php), and fakes api-sandbox.tamara.co
   because the sandbox's egress proxy blocks it outright. */
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

define('LARAVEL_START', microtime(true));

$base = '/home/user/kbbstore/.claude/worktrees/lane-pg1';
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$app->booted(function () use ($base) {
    // Exactly the mounting routes/payments-tamara.php asks for. One
    // middleware() call, not two -- RouteRegistrar::middleware() REPLACES.
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group($base.'/routes/payments-tamara.php');

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?: '';
        $method = strtoupper($request->method());

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

        return Http::response(['error_code' => 'unfaked_'.$method], 418);
    });
});

$app->handleRequest(Illuminate\Http\Request::capture());
