<?php

/**
 * routes/auth-customer.php — the file this lane cannot wire up itself.
 *
 * CLAUDE.md forbids editing routes/web.php, so the file ships unmounted with
 * instructions in its header, and the gap that leaves is the one PaymentRoutesTest
 * and MailRoutesTest were written to close: a mistake here surfaces only once the
 * integrator mounts it, in production, on a storefront page.
 *
 * The mistake that matters is not a 404. POST /my-account/verify/resend makes the
 * server send mail, and POST /my-account/forgot makes it send mail to an address
 * a stranger supplies. Mounted without their guards those are a spam relay on
 * this store's own sending reputation. So the guards are asserted, not assumed.
 */

use Illuminate\Support\Facades\Route;

/**
 * Load the route file and return only what it added.
 *
 * Diffing by object identity is what isolates the lane's routes from the ~200
 * the application already has. Same helper the payment and mail lanes use;
 * redeclared here because Pest gives each test file its own scope but shares
 * the process, and a duplicate function name would fatal.
 */
function customerAuthRoutes(): Illuminate\Support\Collection
{
    // Resolving the kernel is what registers the 'throttle' and 'auth'
    // middleware ALIASES on the router (Kernel::syncMiddlewareToRouter, called
    // from its constructor and nowhere else). A test that never makes a real
    // request never builds the kernel, 'auth' stays unresolved, and the guard
    // assertion passes for the wrong reason.
    app(Illuminate\Contracts\Http\Kernel::class);

    $before = Route::getRoutes()->getRoutes();

    Route::middleware('web')->group(base_path('routes/auth-customer.php'));

    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values();
}

it('registers exactly the paths it documents', function () {
    $paths = customerAuthRoutes()
        ->map(fn ($r) => implode('|', $r->methods()) . ' /' . $r->uri())
        ->sort()
        ->values()
        ->all();

    expect($paths)->toBe([
        'GET|HEAD /my-account/reset/{id}/{token}',
        'GET|HEAD /my-account/verify',
        'GET|HEAD /my-account/verify/{id}/{hash}',
        'POST /my-account/forgot',
        'POST /my-account/reset',
        'POST /my-account/verify/resend',
    ]);
});

it('does not redeclare the forgot page web.php already serves', function () {
    // The header says GET /my-account/forgot is left to web.php on purpose.
    // Two declarations of one path is how a later reader edits the one that
    // never runs, so the claim is pinned rather than trusted.
    $added = customerAuthRoutes()
        ->filter(fn ($r) => $r->uri() === 'my-account/forgot' && in_array('GET', $r->methods(), true));

    expect($added)->toBeEmpty();

    // And the route it defers to really is there.
    expect(Route::has('account.forgot'))->toBeTrue();
});

it('keeps the sending endpoints behind a guard', function () {
    $routes = customerAuthRoutes()->keyBy(fn ($r) => implode('|', $r->methods()) . ' /' . $r->uri());

    // Resend mails the SESSION's own account, so it takes no address and has no
    // enumeration surface — but only because it is signed-in-only.
    expect($routes['POST /my-account/verify/resend']->gatherMiddleware())->toContain('auth:customer');
    expect($routes['GET|HEAD /my-account/verify']->gatherMiddleware())->toContain('auth:customer');

    // The confirm link must NOT be guarded: it is opened from a mail client,
    // often on a device that was never signed in. Its authority is the MAC.
    expect($routes['GET|HEAD /my-account/verify/{id}/{hash}']->gatherMiddleware())
        ->not->toContain('auth:customer');

    // Neither may the reset pages be: a customer who has lost their password
    // cannot sign in to reach the form that fixes it.
    expect($routes['POST /my-account/forgot']->gatherMiddleware())->not->toContain('auth:customer');
    expect($routes['GET|HEAD /my-account/reset/{id}/{token}']->gatherMiddleware())->not->toContain('auth:customer');
    expect($routes['POST /my-account/reset']->gatherMiddleware())->not->toContain('auth:customer');
});

it('throttles every endpoint that can cause a send or a guess', function () {
    $routes = customerAuthRoutes()->keyBy(fn ($r) => implode('|', $r->methods()) . ' /' . $r->uri());

    foreach ([
        'POST /my-account/forgot',
        'POST /my-account/reset',
        'POST /my-account/verify/resend',
        'GET|HEAD /my-account/verify/{id}/{hash}',
    ] as $key) {
        $throttles = collect($routes[$key]->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

        expect($throttles)->toBeTrue($key . ' is not throttled');
    }
});

it('carries the web group, so the forms have a session and a CSRF token', function () {
    $routes = customerAuthRoutes();

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('web');
    }
});

it('constrains the ids and tokens at the router', function () {
    $routes = customerAuthRoutes()->keyBy(fn ($r) => implode('|', $r->methods()) . ' /' . $r->uri());

    expect($routes['GET|HEAD /my-account/reset/{id}/{token}']->wheres)
        ->toBe(['id' => '[0-9]+', 'token' => '[A-Za-z0-9]+']);

    expect($routes['GET|HEAD /my-account/verify/{id}/{hash}']->wheres)
        ->toBe(['id' => '[0-9]+', 'hash' => '[a-f0-9]{32}']);
});

it('ships a clear_caches migration, because it adds routes', function () {
    // CLAUDE.md: a route added by a package does nothing until the compiled
    // route cache is cleared. The symptom here is specific — the forgot form
    // already posts to /my-account/forgot and would keep 404ing.
    $migration = glob(base_path('database/migrations/*clear_caches_customer_auth.php'));

    expect($migration)->toHaveCount(1);

    $source = (string) file_get_contents($migration[0]);

    expect($source)->toContain("bootstrap/cache/routes-*.php")
        ->and($source)->toContain('bootstrap/cache/config.php');
});
