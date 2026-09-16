<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Route;

/**
 * Lane DH — the storefront health check, and the guards around it.
 *
 * app/Http/Controllers/Admin/HealthApiController.php was written, finished, and
 * registered on no route at all: nothing in this application ever called it.
 * The two screens that should have been showing its answer — the dashboard's
 * health card and Safety → Debug & Monitor — were instead printing hand-typed
 * rows that said the site was fine whatever was happening to it. This file
 * covers the endpoint that now carries it.
 *
 * WHY THE ROUTE IS MOUNTED HERE. CLAUDE.md forbids this lane from editing
 * routes/web.php, so routes/health-admin.php ships for the integrator to
 * require inside the existing admin-api group. Every test below mounts it
 * exactly as that file's header says it must be mounted — `web`, `auth:admin`,
 * NoStoreAdminApi, prefix admin-api — so these assertions are a test OF the
 * wiring instruction, and they keep passing unchanged once the integrator
 * follows it. Laravel's route collection is keyed on method and URI, so the
 * registration below replaces rather than duplicates the mounted one.
 */
function hcMount(): void
{
    /*
     * One middleware() call, not two. RouteRegistrar::middleware() REPLACES
     * the attribute rather than appending to it, so the readable
     * ->middleware(['web','auth:admin'])->middleware(NoStoreAdminApi::class)
     * spelling silently dropped the guard and let an anonymous caller run the
     * check. That is worth knowing here because it is exactly the mistake the
     * integrator must not make in web.php: the require goes INSIDE the group
     * that already carries all three.
     */
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/health-admin.php'));
}

function hcUser(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'HC '.$role,
        'email' => 'hc-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/* ------------------------------------------------------------------ the guard */

it('refuses the health check to a caller who is not signed in', function () {
    hcMount();

    $response = test()->getJson('/admin-api/health');

    expect($response->status())->not->toBe(200);

    /*
     * And the refusal must not be a leak of its own. The endpoint's whole
     * value on a failure is the exception message and the file it came from;
     * an anonymous 401 that carried those would be a stack trace of the shop
     * handed to anyone who asked for it.
     */
    expect(str_contains($response->getContent(), '"results"'))
        ->toBeFalse('an anonymous caller was handed the health results');
});

it('refuses the health check to every role but the owner', function (string $role) {
    hcMount();

    $body = test()->actingAs(hcUser($role), 'admin')
        ->getJson('/admin-api/health')
        ->assertForbidden()
        ->json();

    expect($body['capability'])->toBe('system.diagnostics');
})->with(['manager', 'support', 'editor']);

it('maps the health route to diagnostics rather than to the dashboard', function () {
    /*
     * The card that runs this sits on the dashboard, which every role can
     * open, and it would have been easy to map the endpoint to dashboard.view
     * to match. What a FAILURE returns is why that would be wrong: the
     * exception message and the application file and line, which is the same
     * thing the raw error log gives and which is owner-only.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/health'))
        ->toBe('system.diagnostics');

    expect(AdminCapabilities::roleCan('manager', 'system.diagnostics'))->toBeFalse();
    expect(AdminCapabilities::roleCan('owner', 'system.diagnostics'))->toBeTrue();
});

it('rate-limits the check, because one call renders eight pages', function () {
    /*
     * A button someone can hold down. Each press renders every public page
     * inside the worker serving it, on a shared host with a handful of
     * workers, so the throttle is part of the route rather than a manner of
     * using it. Asserted through the middleware stack and not by reading the
     * route's middleware list, because a limit that is declared and not
     * applied is the failure this is guarding against.
     */
    hcMount();

    $owner = hcUser('owner');
    $last = null;

    for ($i = 0; $i < 7; $i++) {
        $last = test()->actingAs($owner, 'admin')->getJson('/admin-api/health');
    }

    expect($last->status())->toBe(429);
});

/* ------------------------------------------------------- what it actually says */

it('reports every public page it rendered, and says how many failed', function () {
    hcMount();
    test()->seed(\Database\Seeders\DemoCatalogueSeeder::class);

    $body = test()->actingAs(hcUser('owner'), 'admin')
        ->getJson('/admin-api/health')
        ->assertOk()
        ->json();

    expect($body['checked'])->toBe(8)
        ->and($body['failed'])->toBe(0)
        ->and($body['results'])->toHaveCount(8);

    $labels = array_column($body['results'], 'label');

    foreach (['Home', 'Shop', 'Product', 'Category', 'Cart', 'Checkout', 'Journal', 'Reviews'] as $page) {
        expect(in_array($page, $labels, true))
            ->toBeTrue("the check no longer opens the {$page} page");
    }

    foreach ($body['results'] as $row) {
        expect($row['ok'])->toBeTrue($row['label'].' returned HTTP '.$row['status']);
    }
});

it('names the error and the application line a failing page came from', function () {
    /*
     * THE ONE THAT MADE THIS LANE REWRITE THE PROBE.
     *
     * Kernel::handle() wraps its whole dispatch in catch (Throwable), reports
     * the exception and renders it into a 500. It does not rethrow, so the
     * `catch` the controller was built around could never fire, and the screen
     * could only ever have said "HTTP 500" — which is the one thing the owner
     * already knew. A panel that cannot say where is a panel worth less than
     * the log file it replaces.
     */
    hcMount();

    Route::get('/reviews', function () {
        throw new RuntimeException('the review wall fell over');
    })->name('review-wall');

    $body = test()->actingAs(hcUser('owner'), 'admin')
        ->getJson('/admin-api/health')
        ->assertOk()
        ->json();

    $row = collect($body['results'])->firstWhere('label', 'Reviews');

    expect($body['failed'])->toBe(1)
        ->and($row['ok'])->toBeFalse()
        ->and($row['status'])->toBe(500)
        ->and($row['error'])->toBe('the review wall fell over');

    /*
     * And the address is in the fault, not in the tool. The first version of
     * where() walked the trace for the first non-vendor frame, which for
     * anything raised in application code is HealthApiController itself —
     * every probe is on the stack of every failure it finds.
     */
    expect(str_contains((string) $row['where'], 'HealthApiController'))
        ->toBeFalse('the check reported its own file as the location of the fault');

    expect(str_contains((string) $row['where'], 'AdminHealthCheckTest.php'))
        ->toBeTrue('expected the throwing file, got: '.var_export($row['where'], true));
});

/* ------------------------------------- what a sub-request must not leave behind */

it('puts the container request back after rendering eight other requests', function () {
    /*
     * Kernel::sendRequestThroughRouter() opens with
     * $this->app->instance('request', $request) and clears the resolved
     * request facade. Nothing puts either back, so before this was fixed the
     * admin request that ASKED for the check finished it with request()
     * answering /reviews/ — the last page probed. Everything downstream of the
     * controller in that request, and every helper that reads the current URL,
     * was reading the wrong one.
     */
    hcMount();

    $seen = null;

    Route::middleware(['web', 'auth:admin'])
        ->get('/admin-api/kbb-health-request-probe', function () use (&$seen) {
            app(\App\Http\Controllers\Admin\HealthApiController::class)->run(request());
            $seen = app('request')->getPathInfo();

            return response()->json(['path' => $seen]);
        });

    test()->actingAs(hcUser('owner'), 'admin')
        ->getJson('/admin-api/kbb-health-request-probe')
        ->assertOk()
        ->assertJson(['path' => '/admin-api/kbb-health-request-probe']);
});

it('leaves the admin session pointing where it was', function () {
    /*
     * The cookies are copied on purpose — a cart page checked without the
     * shopper's cart is not the page that breaks — which means every probe
     * resolves the SAME session, and StartSession records the URL it just
     * served as that session's previous URL. Unrestored, one health check made
     * back() in the admin mean "the review wall".
     */
    hcMount();

    Route::middleware(['web', 'auth:admin'])
        ->get('/admin-api/kbb-health-session-probe', function () {
            request()->session()->setPreviousUrl('https://kbb.test/admin-api/somewhere');

            app(\App\Http\Controllers\Admin\HealthApiController::class)->run(request());

            return response()->json(['previous' => request()->session()->previousUrl()]);
        });

    test()->actingAs(hcUser('owner'), 'admin')
        ->getJson('/admin-api/kbb-health-session-probe')
        ->assertOk()
        ->assertJson(['previous' => 'https://kbb.test/admin-api/somewhere']);
});
