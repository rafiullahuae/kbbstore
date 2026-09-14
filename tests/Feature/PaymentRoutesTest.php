<?php

/**
 * Phase 11 — the two route files this lane cannot wire up itself.
 *
 * CLAUDE.md forbids editing routes/web.php and routes/api.php, so
 * routes/payments-webhooks.php and routes/payments-admin.php ship unmounted
 * with instructions in their headers. That leaves a gap: a mistake in either
 * file would not surface until the integrator wired it up and a provider's
 * first live callback 404'd.
 *
 * These tests load each file into a real Router and assert what it defines —
 * path, method, constraints and a resolvable controller action.
 *
 * Why not drive them over HTTP: in this app a route registered at runtime is
 * never matched by the kernel (even a bare `Route::post('/x')` answers 405,
 * because web.php's Route::fallback is GET-only and swallows the URI first),
 * so an HTTP-level test here would assert the harness, not the routes. The
 * dispatch path itself is covered directly against WebhookController in
 * PaymentWebhookTest.
 */

use App\Http\Controllers\Admin\PaymentsApiController;
use App\Http\Controllers\Payments\WebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Load a route file and return only the routes it added.
 *
 * The files use the Route facade, as every route file in this app does, so
 * they register against the application's own router. Diffing by object
 * identity is what isolates them from the ~200 routes already there.
 */
function routesFrom(string $file, callable $register): Illuminate\Support\Collection
{
    $before = Route::getRoutes()->getRoutes();

    $register(base_path('routes/' . $file));

    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values();
}

it('defines exactly one webhook route, POST only', function () {
    $routes = routesFrom('payments-webhooks.php', function (string $path) {
        // Mounted as the file header says: inside the api group, under /api.
        Route::middleware('api')->prefix('api')->group($path);
    });

    expect($routes)->toHaveCount(1);

    $route = $routes->first();

    expect($route->methods())->toBe(['POST'])
        ->and($route->uri())->toBe('api/payments/webhook/{gateway}/{secret}');
});

it('points the webhook route at the controller that checks for a verifier', function () {
    $route = routesFrom('payments-webhooks.php', function (string $path) {
        Route::prefix('api')->group($path);
    })->first();

    expect($route->getAction('controller'))
        ->toBe(WebhookController::class . '@handle');

    // The method exists, so a wired route cannot 500 on a typo'd action.
    expect(method_exists(WebhookController::class, 'handle'))->toBeTrue();
});

it('constrains the webhook secret so a short guess never reaches a handler', function () {
    $route = routesFrom('payments-webhooks.php', function (string $path) {
        Route::prefix('api')->group($path);
    })->first();

    $wheres = $route->wheres;

    expect($wheres)->toHaveKey('secret')->toHaveKey('gateway');

    // A generated secret passes; a short guess does not.
    expect(preg_match('/^' . $wheres['secret'] . '$/', 'whsec-stripe-' . str_repeat('a', 32)))->toBe(1)
        ->and(preg_match('/^' . $wheres['secret'] . '$/', 'short'))->toBe(0)
        // No slashes, so a secret cannot be used to walk the path.
        ->and(preg_match('/^' . $wheres['secret'] . '$/', 'aaaaaaaaaaaaaaaa/../x'))->toBe(0);

    expect(preg_match('/^' . $wheres['gateway'] . '$/', 'tabby'))->toBe(1)
        ->and(preg_match('/^' . $wheres['gateway'] . '$/', '../admin'))->toBe(0);
});

it('rate limits the webhook endpoint against secret guessing', function () {
    $route = routesFrom('payments-webhooks.php', function (string $path) {
        Route::prefix('api')->group($path);
    })->first();

    $throttle = collect($route->middleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:'));

    expect($throttle)->not->toBeNull();

    // Well above any real delivery rate: a provider retrying a backlog must
    // never be throttled into a lost payment.
    [, $limit] = explode(':', $throttle);
    expect((int) explode(',', $limit)[0])->toBeGreaterThanOrEqual(60);
});

it('defines the admin payments routes as a show/save pair', function () {
    $routes = routesFrom('payments-admin.php', function (string $path) {
        // As the header says: inside the existing guarded admin-api group.
        Route::prefix('admin-api')->group($path);
    });

    expect($routes)->toHaveCount(2);

    $byMethod = $routes->keyBy(fn ($r) => $r->methods()[0]);

    expect($byMethod->keys()->all())->toContain('GET', 'POST');

    expect($byMethod['GET']->uri())->toBe('admin-api/payments')
        ->and($byMethod['GET']->getAction('controller'))->toBe(PaymentsApiController::class . '@show');

    expect($byMethod['POST']->uri())->toBe('admin-api/payments')
        ->and($byMethod['POST']->getAction('controller'))->toBe(PaymentsApiController::class . '@save');
});

it('keeps the admin-api group the payments screen mounts into behind the guard', function () {
    // The group the integrator is told to mount into is already guarded. If
    // that ever stopped being true, mounting there would publish the store's
    // payment credentials, so it is asserted rather than assumed.
    $existing = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/pay-ship-rules' && $r->methods()[0] === 'GET');

    expect($existing)->not->toBeNull();
    expect($existing->middleware())->toContain('auth:admin');
});

it('the payments admin controller exposes the actions its routes name', function () {
    expect(method_exists(PaymentsApiController::class, 'show'))->toBeTrue()
        ->and(method_exists(PaymentsApiController::class, 'save'))->toBeTrue();
});
