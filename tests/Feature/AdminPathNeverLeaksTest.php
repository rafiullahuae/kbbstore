<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Order;
use App\Services\AdminPathService;
use App\Support\GuestRedirect;
use Illuminate\Support\Facades\Route;

/**
 * =============================================================================
 * THE SECRET ADMIN ADDRESS WAS HANDED TO EVERY LOGGED-OUT SHOPPER
 * =============================================================================
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP ────────────────────────────────────────
 *
 * The owner, with a screenshot of extrabeauty.ae's own order-tracking page:
 *
 *     "on the tracking result page, the login link is going to administration
 *      login, which is only for ME. Please make sure all the login, account etc
 *      url must go to the user login page. /mr-cool link or any administration
 *      (my links) must not show anywhere."
 *
 * A guest tracked an order, clicked the "sign in to see all your orders" link
 * under the result, and arrived at `/<admin_path>/login` — the back-office sign
 * in, at the address the owner keeps secret. It was in their address bar, in
 * their browser history, and in the Referer of the next thing they clicked.
 *
 * ── WHERE IT CAME FROM ─────────────────────────────────────────────────────
 *
 * `bootstrap/app.php` carried one line with a comment that described a scope it
 * does not have:
 *
 *     // Unauthenticated back-office requests go to the admin login, not /login.
 *     $middleware->redirectGuestsTo(fn () => route('admin.login'));
 *
 * `redirectGuestsTo()` is application-wide. It sets three statics — on
 * Authenticate, AuthenticateSession and AuthenticationException — so EVERY
 * unauthenticated request to anything behind any guard answered with the admin
 * login. The link in `store/account/track.blade.php:107` was never wrong: it
 * points at `/my-account/orders/`, which is behind `auth:customer`, and the
 * redirect is what turned it into the back-office address. Every other guarded
 * account page did the same — the order list, an order's detail, the address
 * book, the verification notice. `routes/auth-customer.php:120` records the
 * defect in a comment and hands it to whoever owns `bootstrap/app.php`.
 *
 * It is a LEAK and not a broken link. `admin_path` is treated as a secret
 * everywhere else in this codebase: CLAUDE.md names it among the columns that
 * must never leave `settings` through `/api/*`, `Api\SettingController` keeps
 * it out of PUBLIC_KEYS, and `Mail\NewOrderAlert` refuses to print it into an
 * email for exactly this reason.
 *
 * ── WHAT EACH BLOCK BELOW IS HERE TO CATCH ─────────────────────────────────
 *
 *   1. The defect itself, at the address the owner reported: a guest asking for
 *      the order list is sent to the CUSTOMER login and the admin address is
 *      nowhere in the answer.
 *   2. The back office is unmoved. A guest asking for an `auth:admin` page
 *      still lands on the admin login, byte for byte as before.
 *   3. The decision is read off the GUARD and not off the path — so a shop that
 *      has moved its admin still gets both branches right. `admin_path` is
 *      configurable, and a `str_starts_with($path, 'admin')` test is the
 *      obvious wrong answer.
 *   4. The intended URL survives the bounce, so a shopper lands on the page
 *      they asked for rather than a dashboard.
 *   5. THE SWEEP. Every registered storefront GET route is requested logged
 *      out, and the admin address may not appear in the body or in any header
 *      of any of them. This is the guard that catches the NEXT one.
 *   6. The fix is registered from a file that can SHIP. `bootstrap/` is on
 *      BuildPackage::NEVER_SHIP and UpdateGuard's forbidden list, so a fix
 *      written only there could never reach the live shop — which is the exact
 *      way /ar, the redirect table and the cache headers each stayed dead on
 *      the server after being "fixed" in this repo.
 */

/** The admin login, as the router actually built it for this shop. */
function sekAdminLoginUrl(): string
{
    return route('admin.login');
}

/** The customer login: /my-account, which renders the sign-in form to a guest. */
function sekCustomerLoginUrl(): string
{
    return \App\Support\Url::to('/my-account/');
}

/**
 * Which guards a middleware entry names, read the way GuestRedirect reads them.
 *
 * The router resolves the `auth` ALIAS to the class before a route's middleware
 * list is read back, so an entry is `Illuminate\Auth\Middleware\Authenticate:admin`
 * and never the `auth:admin` that routes/web.php was written with. A test that
 * matched on the alias would find nothing and pass over everything.
 *
 * @return list<string>
 */
function sekGuardsOf(string $entry): array
{
    $colon = strpos($entry, ':');

    if ($colon === false) {
        return [];
    }

    $known = [
        'auth',
        \Illuminate\Auth\Middleware\Authenticate::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
    ];

    if (! in_array(substr($entry, 0, $colon), $known, true)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', substr($entry, $colon + 1)))));
}

/** Is this route asking to be authenticated against the back-office guard? */
function sekIsAdminGuarded(\Illuminate\Routing\Route $route): bool
{
    foreach ($route->gatherMiddleware() as $entry) {
        if (is_string($entry) && in_array('admin', sekGuardsOf($entry), true)) {
            return true;
        }
    }

    return false;
}

/**
 * Make the router's route collection writable again.
 *
 * The migration set runs `route:cache`, so the router is serving a
 * CompiledRouteCollection and a route added at runtime is never matched — it
 * answers 404 and the test passes for the wrong reason, or fails for one. The
 * same copy Tests\Support\UgcAdminRoutes does, and for the same reason.
 */
function sekUncompileRoutes(): void
{
    $router = \Illuminate\Support\Facades\Route::getFacadeRoot();
    $kept = new \Illuminate\Routing\RouteCollection();

    foreach ($router->getRoutes() as $route) {
        $kept->add($route);
    }

    $router->setRoutes($kept);
}

/** A customer with one order, so the guarded account pages have something to show. */
function sekCustomer(): Customer
{
    $customer = Customer::create([
        'email' => 'shopper@example.com',
        'password' => bcrypt('correct-horse-battery'),
        'first_name' => 'Sam',
        'last_name' => 'Shopper',
    ]);

    Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'SEK-1001',
        'email' => $customer->email,
        'status' => 'processing',
        'subtotal' => 10000,
        'total' => 10000,
    ]);

    return $customer;
}

it('sends a logged-out shopper asking for their orders to the CUSTOMER login', function () {
    /*
     * ▲ THE DEFECT, AT THE ADDRESS THE OWNER REPORTED.
     *
     * Before the fix this answered `302 -> http://localhost/admin/login` (and
     * `/mr-cool/login` on his shop). The assertion on the Location header is
     * the one that was red; the `not->toContain` below it is the one that says
     * why it matters.
     *
     * MUTATION NOTE. Put `bootstrap/app.php`'s old line back —
     * `$middleware->redirectGuestsTo(fn () => route('admin.login'))` — AND
     * remove the three `redirectUsing()` calls from AppServiceProvider::boot(),
     * and this is red on the first assertion with
     * "Failed asserting that two strings are equal" naming /admin/login. RUN.
     */
    sekCustomer();

    $response = $this->get('/my-account/orders');

    $response->assertRedirect(sekCustomerLoginUrl());

    expect($response->headers->get('Location'))
        ->not->toContain(trim(AdminPathService::current(), '/'));
});

it('sends a logged-out shopper off every other guarded account page the same way', function () {
    /*
     * The tracking page is where the owner saw it, but it was never the only
     * one: every page behind `auth:customer` bounced to the back office. Each
     * of these is a real address a shopper reaches from the account panel.
     */
    $customer = sekCustomer();
    $orderId = (int) $customer->orders()->value('id');

    $paths = [
        '/my-account/orders',
        '/my-account/orders/' . $orderId,
        '/my-account/edit-address',
        '/my-account/verify',
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertRedirect(sekCustomerLoginUrl());
    }

    // The loop must not silently check nothing.
    expect(count($paths))->toBe(4);
});

it('still sends a logged-out administrator to the admin login, unchanged', function () {
    /*
     * THE OTHER HALF, and the one rule 1 is about: the back office must answer
     * exactly what it answered before. A fix that sent an admin to the shopper's
     * login would be the same defect pointing the other way.
     *
     * MUTATION NOTE. Make GuestRedirect::wantsBackOffice() return false
     * unconditionally and this is red. RUN.
     */
    $adminPath = trim(AdminPathService::current(), '/');

    $this->get('/' . $adminPath)->assertRedirect(sekAdminLoginUrl());
    $this->get('/admin-api/security')->assertRedirect(sekAdminLoginUrl());
});

it('decides from the guard and not from the path, so a moved admin still works', function () {
    /*
     * `admin_path` is configurable — KBB_ADMIN_PATH, or the `admin_path`
     * settings row — so a shop that moved its admin must not fall through to
     * the wrong branch. That rules out the obvious implementation, a
     * str_starts_with() on the request path.
     *
     * The routes are registered HERE rather than asserted against web.php, the
     * way tests/Support/UgcAdminRoutes.php does it: these two exist to prove
     * the DECISION, and they deliberately live at addresses that have nothing
     * to do with where the admin is.
     *
     * MUTATION NOTE. Change GuestRedirect::wantsBackOffice() to
     * `str_starts_with($request->path(), trim(AdminPathService::current(), '/'))`
     * and both halves are red: /sek-lane/back-office is not under the admin path and
     * would get the shopper's login, while a customer page that happened to
     * start with the admin path would get the admin's. RUN.
     */
    sekUncompileRoutes();

    Route::middleware(['web', 'auth:admin'])->get('/sek-lane/back-office', fn () => 'never reached');
    Route::middleware(['web', 'auth:customer'])->get('/sek-lane/shop-floor', fn () => 'never reached');

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $this->get('/sek-lane/back-office')->assertRedirect(sekAdminLoginUrl());
    $this->get('/sek-lane/shop-floor')->assertRedirect(sekCustomerLoginUrl());
});

it('keeps the address the shopper asked for, so signing in lands them on it', function () {
    /*
     * A bounce that forgets where the shopper was going is a second defect
     * wearing the first one's clothes: they sign in and arrive at a dashboard,
     * having lost the order they clicked.
     *
     * Illuminate's Handler::unauthenticated() uses redirect()->guest(), which
     * stores `url.intended`; Store\CustomerAuthController::login() ends in
     * redirect()->intended('/my-account/'). Both halves already existed — this
     * asserts that the fix did not break the join between them.
     *
     * MUTATION NOTE. Change CustomerAuthController::login()'s last line to
     * `redirect('/my-account/')` and this is red. RUN.
     */
    $customer = sekCustomer();
    $orderId = (int) $customer->orders()->value('id');

    $this->get('/my-account/orders/' . $orderId)->assertRedirect(sekCustomerLoginUrl());

    $this->post('/my-account/login', [
        'email' => $customer->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(url('/my-account/orders/' . $orderId));
});

it('never puts the admin address in a storefront response', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE SWEEP, AND THE REASON THIS FILE IS MORE THAN FIVE ASSERTIONS.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The leak was a redirect, but the next one need not be. It could be an
     * href in a partial, a Location on an error page, an admin URL in a JSON
     * body or a header. So this walks the ROUTER — every GET route the
     * application registers that is not itself part of the back office — asks
     * for it as a logged-out stranger, and fails if the admin address turns up
     * in the body or in ANY response header.
     *
     * Parameterless routes only, deliberately: a route with a {slug} needs
     * fixture data this test has no business inventing, and
     * StorefrontRouteWalkTest already walks those for status codes. What is
     * swept here is every storefront address a stranger can type.
     *
     * MATCHED ON THE ABSOLUTE URLS AND NOT ON THE BARE SEGMENT. The default
     * admin path is the word "admin", which occurs in ordinary copy; the URLs
     * the router builds for it do not.
     *
     * MUTATION NOTE. Put the old `redirectGuestsTo(fn () => route('admin.login'))`
     * back and add `/my-account/orders` to the list below, and this is red on
     * the Location header. Or add
     * `<a href="{{ route('admin.login') }}">x</a>` to
     * resources/views/store/account/track.blade.php and it is red on the body.
     * RUN (both).
     */
    $needles = [
        sekAdminLoginUrl(),
        route('admin'),
    ];

    $adminPath = trim(AdminPathService::current(), '/');
    $swept = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = ltrim($route->uri(), '/');

        // A parameter needs a fixture; skip and let the walk test have those.
        if (str_contains($uri, '{')) {
            continue;
        }

        /*
         * A back-office route is not a storefront response, and it is skipped
         * by its GUARD rather than by its address — which is the same rule
         * GuestRedirect itself uses, and the only one that stays true on a shop
         * that has moved its admin. The routes this skips are pinned by name in
         * the case below, so the set cannot grow without somebody noticing.
         */
        if ($uri === $adminPath || str_starts_with($uri, $adminPath . '/')) {
            continue;
        }

        if (sekIsAdminGuarded($route)) {
            continue;
        }

        $response = $this->get('/' . $uri);
        $swept++;

        $haystack = $response->getContent() . "\n";

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $haystack .= $name . ': ' . $value . "\n";
            }
        }

        foreach ($needles as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse(
                "GET /{$uri} put the admin address ({$needle}) in front of a logged-out visitor."
            );
        }
    }

    // Guard against the loop silently sweeping nothing.
    expect($swept)->toBeGreaterThan(20);
});

it('registers the guest redirect from a file that can actually ship', function () {
    /*
     * ▲ THE HALF THAT DECIDES WHETHER THIS FIX EVER REACHES THE OWNER'S SHOP.
     *
     * `bootstrap/` is on BuildPackage::NEVER_SHIP and UpdateGuard's forbidden
     * list. This repo has been here three times — SetLocaleFromPath, the
     * redirect table and the cache headers were each complete, tested and dead
     * on the live server because their only registration was in
     * bootstrap/app.php. A security fix must not be the fourth.
     *
     * THE FINISHED STATE IS WHAT IS PINNED, not the absence of something: each
     * of the three statics Middleware::redirectTo() sets is set EXACTLY ONCE
     * from AppServiceProvider, which ships. Zero is "fixed in a file that can
     * never travel"; two is a second registration somebody added without
     * noticing the first.
     *
     * MUTATION NOTE. Delete the three redirectUsing() lines from
     * AppServiceProvider::boot() and this is red three times over. RUN.
     */
    $provider = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    foreach ([
        '\Illuminate\Auth\Middleware\Authenticate::redirectUsing($guests);',
        '\Illuminate\Session\Middleware\AuthenticateSession::redirectUsing($guests);',
        '\Illuminate\Auth\AuthenticationException::redirectUsing($guests);',
    ] as $line) {
        expect(substr_count($provider, $line))->toBe(
            1,
            "AppServiceProvider must carry exactly one `{$line}` — bootstrap/app.php cannot ship."
        );
    }

    // And the decision itself lives in app/, which ships too.
    expect(class_exists(GuestRedirect::class))->toBeTrue();
});

it('leaves usePublicPath in bootstrap/app.php exactly where it was', function () {
    /*
     * The load-bearing line at the end of bootstrap/app.php: the web root is a
     * DIFFERENT directory from the application root on the live server. This
     * lane edited that file, so it says so rather than hoping.
     */
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

    expect(substr_count($bootstrap, '->usePublicPath('))->toBe(1)
        ->and(substr_count($bootstrap, "'/home/u815237650/domains/easywebsol.com/public_html/kbb-upgrade'"))->toBe(1)
        ->and(substr_count($bootstrap, 'GuestRedirect::for($request)'))->toBe(1);
});

it('names every address outside the admin path that still answers a guest with the admin login', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * FOUND AND NOT FIXED — DELIBERATELY, AND PINNED SO IT CANNOT GROW.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * The sweep above skips routes that ask for the `admin` guard, because
     * those are the back office and answering them with the back-office login
     * is correct. But 398 of them do NOT live under the secret admin path: they
     * live at `admin-api/...`, which is a FIXED prefix, and at
     * `api/cart/debug`. A stranger who has never seen the admin address can ask
     * for one of those and read `admin_path` straight off the Location header:
     *
     *     curl -sI https://extrabeauty.ae/admin-api/security
     *     Location: https://extrabeauty.ae/mr-cool/login
     *
     * That is the same disclosure the storefront leak was, through a different
     * door, and it is not this lane's to close on its own initiative: the fix
     * — refuse to name the admin login unless the request is already under the
     * admin path — changes where a logged-out administrator lands on 397
     * endpoints, and the admin console's own session-expiry handling follows
     * that redirect. It is reported to the owner rather than decided here.
     *
     * WHAT THIS CASE IS FOR IS THE SET NOT GROWING. It is red the day somebody
     * mounts a new admin-guarded endpoint at a public address — which is how
     * this surface got to 398 in the first place.
     */
    $adminPath = trim(AdminPathService::current(), '/');
    $outside = [];

    foreach (Route::getRoutes() as $route) {
        if (! sekIsAdminGuarded($route)) {
            continue;
        }

        $uri = ltrim($route->uri(), '/');

        if ($uri === $adminPath || str_starts_with($uri, $adminPath . '/')) {
            continue;
        }

        $outside[] = str_starts_with($uri, 'admin-api/') || $uri === 'admin-api'
            ? 'admin-api/*'
            : $uri;
    }

    $outside = array_values(array_unique($outside));
    sort($outside);

    expect($outside)->toBe(['admin-api/*', 'api/cart/debug']);

    // And the one that is not under a back-office prefix at all really does
    // hand the address over today, which is the measurement behind the note.
    expect($this->get('/api/cart/debug')->headers->get('Location'))
        ->toBe(sekAdminLoginUrl());
});
