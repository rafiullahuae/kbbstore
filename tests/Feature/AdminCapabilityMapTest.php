<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Route;

/**
 * The capability map itself: its shape, its ordering, and its default.
 *
 * AdminRoleEnforcementTest covers what each role can and cannot reach over
 * HTTP. This file covers the two things that make that behaviour trustworthy
 * rather than coincidental:
 *
 *   1. The default is DENY. An admin route the map has never heard of is
 *      owner-only. This is the one the codebase has got wrong before —
 *      CouponService::withRules() read an unrecognised rule set as "no
 *      restriction" and shipped that way — so it is pinned against a route
 *      registered inside the test and deliberately absent from the map, rather
 *      than against an existing route somebody might map later and quietly
 *      turn this test into a tautology.
 *
 *   2. Ordering. RULES is a first-match-wins list, and a handful of pairs
 *      genuinely overlap: /customers/export is also /customers/{id}, and the
 *      first pays in somebody else's personal data. Each of those pairs is
 *      pinned by name below, so reordering the list to tidy it up fails here
 *      instead of on the live site.
 */
function acmUser(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'ACM '.$role,
        'email' => 'acm-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/**
 * A route that is real, is guarded by auth:admin, and is not in the map.
 *
 * Registered here rather than reusing an existing route on purpose: every
 * registered route IS mapped (the coverage test below asserts exactly that), so
 * there is no real route left that could stand in for "unknown".
 */
function acmRegisterUnmappedRoute(string $uri = 'admin-api/kbb-capability-probe'): void
{
    Route::middleware(['web', 'auth:admin'])
        ->get('/'.$uri, fn () => response()->json(['reached' => true]));
}

/* ------------------------------------------------------- the closed default */

it('refuses an unmapped admin route to every role but the owner', function (string $role) {
    acmRegisterUnmappedRoute();

    expect(AdminCapabilities::forPath('GET', 'admin-api/kbb-capability-probe'))
        ->toBeNull();

    test()->actingAs(acmUser($role), 'admin')
        ->get('/admin-api/kbb-capability-probe')
        ->assertForbidden();
})->with(['manager', 'support', 'editor']);

it('still lets the owner reach an unmapped admin route', function () {
    // The other half of the closed default, and the more important half: a
    // route nobody has mapped yet must still be usable by the person who can
    // fix it. The owner short-circuit in EnforceAdminCapability runs before the
    // map is consulted at all, which is what makes this true by construction
    // rather than by the map happening to be right.
    acmRegisterUnmappedRoute('admin-api/kbb-capability-probe-owner');

    test()->actingAs(acmUser('owner'), 'admin')
        ->get('/admin-api/kbb-capability-probe-owner')
        ->assertOk()
        ->assertJson(['reached' => true]);
});

it('names no capability at all when it refuses an unmapped route', function () {
    acmRegisterUnmappedRoute('admin-api/kbb-capability-probe-body');

    $body = test()->actingAs(acmUser('support'), 'admin')
        ->get('/admin-api/kbb-capability-probe-body')
        ->assertForbidden()
        ->json();

    expect($body['capability'])->toBeNull()
        ->and($body['error'])->toBe('forbidden');
});

it('leaves a route without auth:admin completely alone', function () {
    // The layer keys on the guard the route declares. A route with no admin
    // guard is not an admin route and must not acquire a 403 from this
    // middleware just because it lives under a familiar-looking path.
    // Two segments deliberately: routes/kbb-brands-blog.php ends in a catch-all
    // single root segment, and a one-segment probe registered from a test lands
    // after it and is read as an article slug.
    Route::middleware(['web'])->get('/admin-api/kbb-lookalike-open', fn () => response('open'));

    test()->actingAs(acmUser('support'), 'admin')
        ->get('/admin-api/kbb-lookalike-open')
        ->assertOk();

    test()->get('/admin-api/kbb-lookalike-open')->assertOk();
});

/* ------------------------------------------------------------- coverage */

it('maps every admin route the router actually carries', function () {
    // The closed default keeps an unmapped route safe; this keeps it from
    // silently becoming owner-only without anyone noticing. A lane that adds an
    // admin route lands here with the route named in the failure message.
    $unmapped = [];
    $total = 0;

    foreach (Route::getRoutes() as $route) {
        try {
            $middleware = $route->gatherMiddleware();
        } catch (\Throwable) {
            continue;
        }

        if (! in_array('auth:admin', $middleware, true)) {
            continue;
        }

        // The probes this file registers are meant to be unmapped.
        if (str_contains($route->uri(), 'kbb-capability-probe')) {
            continue;
        }

        $total++;

        if (AdminCapabilities::for($route) === null) {
            $unmapped[] = implode('|', array_diff($route->methods(), ['HEAD', 'OPTIONS']))
                .' /'.$route->uri();
        }
    }

    expect($total > 200)->toBeTrue("only {$total} admin routes were found; the router looks wrong");

    expect($unmapped === [])->toBeTrue(
        'these admin routes are owner-only by default because nothing maps them: '
        .implode(', ', $unmapped)
    );
});

it('references only capabilities that exist, everywhere it names one', function () {
    $known = array_keys(AdminCapabilities::CAPABILITIES);
    $bad = [];

    foreach (AdminCapabilities::ROUTE_NAMES as $name => $capability) {
        if (! in_array($capability, $known, true)) {
            $bad[] = "ROUTE_NAMES[{$name}] => {$capability}";
        }
    }

    foreach (AdminCapabilities::RULES as [$method, $pattern, $capability]) {
        if (! in_array($capability, $known, true)) {
            $bad[] = "RULES {$method} {$pattern} => {$capability}";
        }
    }

    expect($bad === [])->toBeTrue('unknown capabilities referenced: '.implode('; ', $bad));
});

it('gives the owner every capability and gives an unknown role none', function () {
    foreach (AdminCapabilities::CAPABILITIES as $capability => $roles) {
        expect(in_array('owner', $roles, true))
            ->toBeTrue("the owner is missing the {$capability} capability");
    }

    expect(AdminCapabilities::forRole('owner'))
        ->toBe(array_keys(AdminCapabilities::CAPABILITIES));

    expect(AdminCapabilities::forRole('wizard'))->toBe([])
        ->and(AdminCapabilities::forRole(null))->toBe([])
        ->and(AdminCapabilities::forRole(''))->toBe([]);

    expect(AdminCapabilities::roleCan('wizard', 'admin.access'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('owner', 'no.such.capability'))->toBeFalse()
        ->and(AdminCapabilities::roleCan('support', null))->toBeFalse();
});

it('names only roles it knows in every capability row', function () {
    foreach (AdminCapabilities::CAPABILITIES as $capability => $roles) {
        foreach ($roles as $role) {
            expect(in_array($role, AdminCapabilities::ROLES, true))
                ->toBeTrue("{$capability} names the role {$role}, which is not in ROLES");
        }
    }

    // And the vocabulary is still the one AdminController validates against.
    $controller = (string) file_get_contents(
        base_path('app/Http/Controllers/Admin/AdminController.php')
    );

    expect(str_contains($controller, 'in:owner,manager,support,editor'))
        ->toBeTrue('AdminController no longer validates the four roles this map is built on');
});

/* ------------------------------------------------------------- ordering */

it('gives the bulk exports their own capability and not the sibling read', function () {
    // Each of these pairs overlaps: the second pattern in each row also matches
    // the first path. First-match-wins is what separates them, so the order of
    // RULES is load-bearing here and only here.
    expect(AdminCapabilities::forPath('GET', 'admin-api/customers/export'))->toBe('customers.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/customers/{id}'))->toBe('customers.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/customers/list'))->toBe('customers.view');

    expect(AdminCapabilities::forPath('GET', 'admin-api/reviews/export'))->toBe('reviews.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/reviews/{review}'))->toBe('reviews.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/reviews-io/export'))->toBe('reviews.export')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/reviews-io/import'))->toBe('reviews.manage');

    expect(AdminCapabilities::forPath('GET', 'admin-api/newsletter/export'))->toBe('marketing.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/newsletter'))->toBe('marketing.manage');

    expect(AdminCapabilities::forPath('GET', 'admin-api/catalog-products-export'))->toBe('catalog.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/catalog-products-list'))->toBe('catalog.view');

    expect(AdminCapabilities::forPath('GET', 'admin-api/orders-export'))->toBe('orders.export')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders-list'))->toBe('orders.view');
});

it('separates reading an order from moving its money and from destroying it', function () {
    expect(AdminCapabilities::forPath('GET', 'admin-api/orders/{id}'))->toBe('orders.view')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders/{id}/detail'))->toBe('orders.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/notes'))->toBe('orders.manage')
        ->and(AdminCapabilities::forPath('PUT', 'admin-api/orders/{id}/status'))->toBe('orders.manage')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/refund'))->toBe('orders.money')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/capture'))->toBe('orders.money')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders/{id}/settlement'))->toBe('orders.money')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/orders/{id}/items/{itemId}'))->toBe('orders.money')
        ->and(AdminCapabilities::forPath('DELETE', 'admin-api/orders/{id}'))->toBe('orders.delete')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/orders/{id}/invoice'))->toBe('invoices.view');
});

it('keeps the single-segment wildcard from crossing a slash', function () {
    // The whole reason RULES is readable rather than a minefield. If '*' ever
    // starts matching across slashes, 'admin-api/orders/*' swallows every
    // refund, capture and settlement route below it and orders.money stops
    // existing in practice — which is precisely how /admin-api/reviews/settings
    // ended up as dead code behind an untyped PUT /reviews/{id}.
    expect(AdminCapabilities::forPath('DELETE', 'admin-api/orders/{id}'))->toBe('orders.delete')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/orders/{id}/refund'))->toBe('orders.money');

    // 'admin-api/demo' is exact and must not reach the import endpoints.
    expect(AdminCapabilities::forPath('GET', 'admin-api/demo'))->toBe('content.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/demo-content'))->toBe('data.import')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/demo-content/{type}/import'))->toBe('data.import');

    // The redirect ledger sits under /categories/ but is content, not catalogue.
    expect(AdminCapabilities::forPath('GET', 'admin-api/categories/redirects'))->toBe('content.manage')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/categories'))->toBe('catalog.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/categories/{category}/merge'))->toBe('catalog.manage');
});

it('matches console pages by route name, so the admin path can move', function () {
    // These URIs carry AdminPathService::current(), which the owner can change
    // from the admin itself. Matching them on path would mean the permission on
    // the updates screen depended on what the admin folder is called that week.
    expect(AdminCapabilities::ROUTE_NAMES['admin'])->toBe('admin.access')
        ->and(AdminCapabilities::ROUTE_NAMES['admin.updates.apply'])->toBe('updates.manage')
        ->and(AdminCapabilities::ROUTE_NAMES['admin.path.update'])->toBe('store.settings')
        ->and(AdminCapabilities::ROUTE_NAMES['kbb.health.log'])->toBe('system.diagnostics');

    foreach (AdminCapabilities::ROUTE_NAMES as $name => $capability) {
        expect(Route::has($name))->toBeTrue("ROUTE_NAMES lists {$name}, which is not a registered route");
    }
});

it('holds the admin-only cart debug endpoint, which lives outside admin-api', function () {
    // /api/cart/debug is guarded by auth:admin while sitting under the
    // storefront's own prefix. A prefix-based layer would have missed it; this
    // one finds it because it keys on the guard.
    expect(AdminCapabilities::forPath('GET', 'api/cart/debug'))->toBe('system.diagnostics');

    test()->actingAs(acmUser('manager'), 'admin')->get('/api/cart/debug')->assertForbidden();
    expect(test()->actingAs(acmUser('owner'), 'admin')->get('/api/cart/debug')->getStatusCode())
        ->not->toBe(403);
});
