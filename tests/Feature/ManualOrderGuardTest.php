<?php

declare(strict_types=1);

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Support\Facades\Route;
use Tests\ManualOrders;

/*
|------------------------------------------------------------------------------
| Who can reach these endpoints
|------------------------------------------------------------------------------
|
| /admin-api/* is guarded by auth:admin and /api/* is not, so the single most
| expensive mistake available here is registering this lane's route file in the
| wrong place. These endpoints return customer names, emails, phone numbers and
| street addresses, and they create real orders against real money.
|
| Everything below is asserted against the ROUTES AS REGISTERED — routes are
| enumerated out of the router and each one's gathered middleware inspected —
| not against a list written out by hand, which would keep passing after
| somebody added a route and forgot to add it to the list.
*/

beforeEach(function () {
    ManualOrders::registerRoutes();
});

it('registers exactly the routes the lane documents, and no others', function () {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/manual-orders'))
        ->map(fn ($r) => implode(' ', array_diff($r->methods(), ['HEAD'])) . ' /' . $r->uri())
        ->values()
        ->sort()
        ->values()
        ->all();

    expect($registered)->toBe([
        'GET /admin-api/manual-orders/bootstrap',
        'GET /admin-api/manual-orders/customers',
        'GET /admin-api/manual-orders/products',
        'GET /admin-api/manual-orders/{order}/packing-list.csv',
        'POST /admin-api/manual-orders',
        'POST /admin-api/manual-orders/quote',
    ]);
});

it('puts every registered route behind auth:admin and NoStoreAdminApi', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/manual-orders'));

    expect($routes)->not->toBeEmpty();

    // Collected rather than asserted one at a time: a failure should name
    // every unguarded route, not stop at the first.
    $unguarded = [];

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        if (! in_array('auth:admin', $middleware, true)) {
            $unguarded[] = $route->uri() . ' — missing auth:admin';
        }

        if (! in_array(NoStoreAdminApi::class, $middleware, true)) {
            $unguarded[] = $route->uri() . ' — missing NoStoreAdminApi';
        }
    }

    expect($unguarded)->toBe([]);
});

it('refuses an anonymous request on every route', function () {
    foreach (ManualOrders::paths() as [$method, $path]) {
        $response = $this->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419], "{$method} {$path} let an anonymous caller through with {$response->getStatusCode()}");
    }
});

it('refuses a signed-in storefront customer on every route', function () {
    $customer = ManualOrders::customer();

    foreach (ManualOrders::paths() as [$method, $path]) {
        $response = $this->actingAs($customer, 'customer')->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419], "{$method} {$path} let a shopper through with {$response->getStatusCode()}");
    }
});

it('refuses a plain web-guard user on every route', function () {
    // The 'web' guard is a different provider entirely (users, not
    // admin_users). Being signed in there confers nothing here, and the
    // separation is the point: a back-office session must never be conferred
    // by a storefront or app login.
    $user = ManualOrders::webUser();

    foreach (ManualOrders::paths() as [$method, $path]) {
        $response = $this->actingAs($user, 'web')->json($method, $path);

        expect($response->getStatusCode())
            ->toBeIn([401, 403, 419], "{$method} {$path} let a web user through with {$response->getStatusCode()}");
    }
});

it('lets an admin through', function () {
    ManualOrders::shop();

    $this->actingAs(ManualOrders::admin(), 'admin')
        ->getJson('/admin-api/manual-orders/bootstrap')
        ->assertOk();
});

it('marks admin-api responses no-store, so shared hosting cannot cache a customer list', function () {
    ManualOrders::shop();
    ManualOrders::customer();

    $this->actingAs(ManualOrders::admin(), 'admin')
        ->getJson('/admin-api/manual-orders/customers?q=layla')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
});
