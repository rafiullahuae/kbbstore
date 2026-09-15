<?php

/**
 * routes/mail-admin.php — the file this lane cannot wire up itself.
 *
 * CLAUDE.md forbids editing routes/web.php, so the file ships unmounted with
 * instructions in its header. That leaves a gap exactly like the one
 * PaymentRoutesTest was written to close: a mistake here would not surface
 * until the integrator mounted it, and the mistake that matters is not a 404.
 *
 * POST /admin-api/mail/test makes the server send an email to an address the
 * caller chooses. Mounted outside the guarded group that is an open relay --
 * someone else's spam, delivered by this store's mail server, on this store's
 * sending reputation, until the host suspends the account and takes the
 * storefront down with it. So the guard is asserted, not assumed.
 *
 * Why these are not ordinary HTTP tests: in this app a route registered at
 * runtime is never matched by the kernel -- web.php's Route::fallback swallows
 * the URI first -- so an HTTP-level test would assert the harness. These load
 * the file into the real Router and dispatch through it, which runs the genuine
 * middleware pipeline.
 */

use App\Http\Controllers\Admin\MailApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Load the route file and return only the routes it added.
 *
 * Diffing by object identity is what isolates them from the ~200 routes the
 * application already has. Same helper PaymentRoutesTest uses; redeclared here
 * because Pest loads each test file into its own scope but shares the process,
 * and a duplicate function name would fatal.
 */
function mailRoutesFrom(callable $register): Illuminate\Support\Collection
{
    /*
     * Resolve the HTTP kernel first, or none of this measures anything.
     *
     * The middleware ALIASES -- 'auth', 'throttle' -- are registered on the
     * router by Kernel::syncMiddlewareToRouter(), which runs in the kernel's
     * constructor and nowhere else. A test that never makes a real request
     * never builds the kernel, so 'auth' stays unresolved, the pipeline calls
     * $container->make('auth') and gets the AuthManager, and the guard test
     * fails with a 500 that looks like a bug in this lane rather than a missing
     * alias. Worth a line and a paragraph: a 500 instead of a 401 is still a
     * refusal, so this could as easily have passed for the wrong reason.
     */
    app(Illuminate\Contracts\Http\Kernel::class);

    $before = Route::getRoutes()->getRoutes();

    $register(base_path('routes/mail-admin.php'));

    return collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r, $before, true))
        ->values();
}

/** Mounted exactly as the file header tells the integrator to mount it. */
function mountedAsDocumented(): Illuminate\Support\Collection
{
    return mailRoutesFrom(function (string $path) {
        Route::middleware('auth:admin')->prefix('admin-api')->group($path);
    });
}

it('defines the three routes the mail screen needs and no others', function () {
    $routes = mountedAsDocumented();

    expect($routes)->toHaveCount(3);

    $uris = $routes->map(fn ($r) => $r->methods()[0] . ' ' . $r->uri())->all();

    expect($uris)->toContain('GET admin-api/mail')
        ->toContain('POST admin-api/mail')
        ->toContain('POST admin-api/mail/test');
});

it('points every route at an action that exists', function () {
    foreach (mountedAsDocumented() as $route) {
        $action = $route->getAction('controller');

        expect($action)->toStartWith(MailApiController::class . '@');

        [, $method] = explode('@', $action);

        // A wired route cannot 500 on a typo'd action.
        expect(method_exists(MailApiController::class, $method))->toBeTrue();
    }
});

it('keeps the admin-api group these mount into behind the admin guard', function () {
    // The group the integrator is told to mount into is already guarded. If
    // that ever stopped being true, mounting there would publish a send-mail
    // button to the internet, so it is asserted rather than assumed.
    $existing = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/payments' && $r->methods()[0] === 'GET');

    expect($existing)->not->toBeNull()
        ->and($existing->middleware())->toContain('auth:admin');
});

it('rejects an unauthenticated caller of the test-send endpoint', function () {
    mountedAsDocumented();

    // Through the real pipeline: auth:admin runs, finds no admin session and
    // refuses before the controller is ever constructed. Illuminate's routing
    // pipeline renders the AuthenticationException rather than letting it out,
    // so the refusal is a response — a 401 for an XHR, which is what the admin
    // bundle sends.
    $json = Request::create('/admin-api/mail/test', 'POST', ['to' => 'spam-target@example.com']);
    $json->headers->set('Accept', 'application/json');

    expect(app('router')->dispatch($json)->getStatusCode())->toBe(401);

    // And a plain form post is bounced to the admin login, not served.
    $plain = app('router')->dispatch(
        Request::create('/admin-api/mail/test', 'POST', ['to' => 'spam-target@example.com'])
    );

    expect($plain->getStatusCode())->toBe(302)
        ->and($plain->headers->get('Location'))->toContain('login');

    // Nothing was sent to the address the anonymous caller named.
    expect(app(App\Services\Mail\MailSettings::class)->lastTest())->toBeNull();
});

it('rejects an unauthenticated caller of the read and write endpoints too', function () {
    mountedAsDocumented();

    foreach ([['GET', '/admin-api/mail'], ['POST', '/admin-api/mail']] as [$method, $uri]) {
        $request = Request::create($uri, $method);
        $request->headers->set('Accept', 'application/json');

        expect(app('router')->dispatch($request)->getStatusCode())->toBe(401);
    }
});

it('rate limits the test-send', function () {
    $routes = mountedAsDocumented();

    $test = $routes->first(fn ($r) => $r->uri() === 'admin-api/mail/test');

    $throttle = collect($test->middleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:'));

    expect($throttle)->not->toBeNull();

    [, $limit] = explode(':', $throttle);
    [$attempts, $minutes] = array_pad(explode(',', $limit), 2, '1');

    // Low enough to be a real ceiling on what a stolen admin session, or a
    // stuck retry loop, can do with the store's sending reputation. A shared
    // host measures outbound volume in tens per hour.
    expect((int) $attempts)->toBeGreaterThan(0)
        ->and((int) $attempts)->toBeLessThanOrEqual(20)
        ->and((int) $minutes)->toBeGreaterThanOrEqual(1);
});

it('actually refuses the request once the rate limit is reached', function () {
    // The structural assertion above says the middleware is attached. This says
    // it engages -- mounted without the auth guard so the throttle is what is
    // being measured, not the login.
    RateLimiter::clear(sha1('127.0.0.1'));

    mailRoutesFrom(function (string $path) {
        Route::prefix('admin-api')->group($path);
    });

    $attempts = 0;

    // One more than the configured ceiling, whatever it is.
    $test = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/mail/test');
    $throttle = collect($test->middleware())->first(fn ($m) => str_starts_with((string) $m, 'throttle:'));
    [, $limit] = explode(':', $throttle);
    $ceiling = (int) explode(',', $limit)[0];

    $blocked = false;

    for ($i = 0; $i < $ceiling + 1; $i++) {
        $request = Request::create('/admin-api/mail/test', 'POST', ['to' => 'owner@example.com']);
        $request->headers->set('Accept', 'application/json');

        // The routing pipeline renders ThrottleRequestsException rather than
        // letting it out, so the refusal arrives as 429 Too Many Requests.
        if (app('router')->dispatch($request)->getStatusCode() === 429) {
            $blocked = true;

            break;
        }

        $attempts++;
    }

    expect($blocked)->toBeTrue()
        ->and($attempts)->toBe($ceiling);
});

it('documents where the integrator must mount the file', function () {
    // The file cannot mount itself and a header that stops matching reality is
    // how a route file gets wired into the wrong group.
    $header = (string) file_get_contents(base_path('routes/mail-admin.php'));

    expect($header)->toContain('admin-api')
        ->and($header)->toContain('auth:admin')
        ->and($header)->toContain("require __DIR__.'/mail-admin.php';");
});
