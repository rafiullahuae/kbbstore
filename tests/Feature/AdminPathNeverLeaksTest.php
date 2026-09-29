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

    /*
     * ▲ PIN ADVANCED, DELIBERATELY. This line used to read
     *
     *     $this->get('/admin-api/security')->assertRedirect(sekAdminLoginUrl());
     *
     * and that redirect was the second door the admin address walked out of:
     * `admin-api` is a FIXED prefix anybody can guess, so a stranger who typed
     * it was told the secret. It answers 404 now, and the case below is where
     * that is asserted over the whole set rather than one endpoint.
     *
     * What is pinned HERE is the half that did not move: a route UNDER the
     * admin path still answers the admin login, because a requester who
     * reached it has already typed the secret.
     */
    $this->get('/' . $adminPath . '/kbb-health-log')->assertRedirect(sekAdminLoginUrl());
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
    $adminPath = trim(AdminPathService::current(), '/');

    sekUncompileRoutes();

    Route::middleware(['web', 'auth:admin'])->get('/' . $adminPath . '/sek-back-office', fn () => 'never reached');
    Route::middleware(['web', 'auth:customer'])->get('/sek-lane/shop-floor', fn () => 'never reached');

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    /*
     * ▲ PIN ADVANCED, DELIBERATELY. The back-office route here used to sit at
     * `/sek-lane/back-office` and this asserted it redirected to the admin
     * login. It does not any more, and that is the whole of round two: an
     * admin-guarded address that does NOT carry the secret is hidden rather
     * than pointed at a login — asserted over the real 397 in its own case
     * below. So the route moved under the admin path, which is where "this
     * requester already knows it" is still true and where the redirect is
     * still the right answer.
     */
    $this->get('/' . $adminPath . '/sek-back-office')->assertRedirect(sekAdminLoginUrl());
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

it('hides every admin address that does not already prove you know the secret', function () {
    /*
     * ═══════════════════════════════════════════════════════════════════════
     * THE SECOND DOOR, AND THE SWEEP THAT KEEPS IT SHUT.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * 398 admin-guarded endpoints do NOT live under the secret admin path: 397
     * `admin-api/...`, which is a fixed prefix anybody can guess, and
     * `api/cart/debug`. Every one of them answered a logged-out browser with
     *
     *     302 Location: https://extrabeauty.ae/mr-cool/login
     *
     * so `admin_path` was readable by anyone who could type `admin-api`. This
     * requests EVERY one of them as a logged-out stranger with a browser's own
     * Accept header and fails if the answer is anything but a 404, if it
     * carries a Location header at all, or if either admin URL appears in the
     * body or in any header.
     *
     * MUTATION NOTE. Make GuestRedirect::hidesTheAddressInstead() return false
     * unconditionally and this is red on the first endpoint it reaches, with
     * the Location naming the admin login. RUN.
     */
    $adminPath = trim(AdminPathService::current(), '/');
    $needles = [sekAdminLoginUrl(), route('admin')];
    $swept = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || ! sekIsAdminGuarded($route)) {
            continue;
        }

        $uri = ltrim($route->uri(), '/');

        // A route under the admin path keeps the redirect: whoever reached it
        // typed the secret and cannot be told anything they did not bring.
        if (in_array($adminPath, explode('/', $uri), true)) {
            continue;
        }

        if (str_contains($uri, '{')) {
            continue;
        }

        $response = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'])
            ->get('/' . $uri);

        $swept++;

        expect($response->status())->toBe(404, "GET /{$uri} must be hidden from a stranger, not pointed at a login.");
        expect($response->headers->get('Location'))->toBeNull("GET /{$uri} still carries a Location header.");

        $haystack = (string) $response->getContent();

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $haystack .= "\n" . $name . ': ' . $value;
            }
        }

        foreach ($needles as $needle) {
            expect(str_contains($haystack, $needle))->toBeFalse(
                "GET /{$uri} put the admin address ({$needle}) in front of a stranger."
            );
        }
    }

    expect($swept)->toBeGreaterThan(100, 'the admin-api set is 397 endpoints; this swept almost none of them');
});

it('leaves the console\'s own calls answering exactly what they answered', function () {
    /*
     * ▲ THE HALF THAT IS NOT ALLOWED TO MOVE, AND THE REASON THE SWEEP ABOVE
     *   IS SCOPED THE WAY IT IS.
     *
     * Handler::unauthenticated() answers a request that expects JSON with a
     * bare 401 and NO Location — measured before anything was changed — and
     * every one of the console's 43 fetch() calls to admin-api sets
     * `Accept: application/json`. So the console never saw the redirect, and
     * this asserts it still does not see the 404 either.
     *
     * IT MATTERS BECAUSE OF WHAT THE CONSOLE DOES WITH A 404. Thirty-three
     * screens read one from their own endpoints as "the endpoints are not in
     * this server's compiled route table yet — clear the route cache", 53
     * occurrences, because a package applied without its clear_caches
     * migration is a real fault on this shop. Three screens already read 401
     * as "your admin session has expired". Turning the console's 401 into a
     * 404 would send the owner to clear his caches over an expired login.
     *
     * MUTATION NOTE. Drop the `$request->expectsJson()` guard from
     * GuestRedirect::hidesTheAddressInstead() and this is red: the console's
     * own call answers 404. RUN.
     */
    foreach ([
        ['Accept' => 'application/json'],
        ['X-Requested-With' => 'XMLHttpRequest'],
    ] as $headers) {
        $response = $this->withHeaders($headers)->get('/admin-api/security');

        expect($response->status())->toBe(401)
            ->and($response->headers->get('Location'))->toBeNull()
            ->and($response->json('message'))->toBe('Unauthenticated.');
    }
});

it('still hides it when the shop has moved its admin somewhere else', function () {
    /*
     * The decision is read off the GUARD and off whether the address already
     * carries the secret — never a hard-coded prefix. Two routes registered
     * here, at addresses that have nothing to do with where the admin is: one
     * public, which must be hidden, and one under the configured admin path,
     * which must keep the login it has always answered.
     *
     * MUTATION NOTE. Replace the segment test in
     * GuestRedirect::hidesTheAddressInstead() with
     * `str_starts_with($uri, 'admin')` and this is red on the second half:
     * `admin-api`-shaped addresses would be read as "under the admin path" and
     * go on naming it. RUN.
     */
    $adminPath = trim(AdminPathService::current(), '/');

    sekUncompileRoutes();

    Route::middleware(['web', 'auth:admin'])->get('/sek-open/probe', fn () => 'never reached');
    Route::middleware(['web', 'auth:admin'])->get('/' . $adminPath . '/sek-probe', fn () => 'never reached');

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $browser = ['Accept' => 'text/html,*/*;q=0.8'];

    $this->withHeaders($browser)->get('/sek-open/probe')->assertNotFound();
    $this->withHeaders($browser)->get('/' . $adminPath . '/sek-probe')->assertRedirect(sekAdminLoginUrl());
});

it('names every console path that navigates the browser to an admin-api address', function () {
    /*
     * ▲ FOUND, MEASURED, AND NOT THIS LANE'S FILE TO FIX.
     *
     * Of the console's ways to reach an admin-api address, 43 are fetch()
     * calls and 4 are XMLHttpRequest uploads, and every one of the 47 sets
     * `Accept: application/json` — the wrapper in admin/app.blade.php, the
     * ~20 screen partials that carry their own, and the four upload paths,
     * which are exactly where a wrapper usually gets bypassed and here do not.
     * None of them is touched by any of this: they still answer 401.
     *
     * What is left are the BROWSER NAVIGATIONS, and every one is a download —
     * a navigation is how the browser is made to save a file, so they cannot
     * carry an Accept header the console chooses. Six call sites, nine
     * endpoints:
     *
     *   admin/app.blade.php:13031  window.open   /admin-api/orders-bulk-documents
     *   admin/app.blade.php:13215  location.href /admin-api/orders-export
     *   admin/app.blade.php:14646  location.href /admin-api/customers/export
     *   admin/app.blade.php:15745  location.href /admin-api/reviews/export
     *   admin/app.blade.php:19750  location.href /admin-api/catalog-products-export
     *   admin/app.blade.php:14082  window.open(url) where url is the SERVER'S
     *                              own /admin-api/orders/{id}/invoice,
     *                              /packing-slip, /delivery-note and
     *                              /shipping-label — built by
     *                              Admin\InvoiceController::invoiceUrl() and
     *                              friends and sent down on the order payload.
     *
     * ▲ THAT LAST ONE IS THE ONE A SCAN FOR `admin-api` IN THE CONSOLE DOES
     * NOT FIND, because the address never appears in the console's source at
     * all. It was found by following `o.invoice_url` back to the controller,
     * and it is four of the nine.
     *
     * With an expired session those nine used to land on the admin login and
     * now land on a 404. That is the price of closing the door; it is paid by
     * the administrator, never by a shopper, and the fix is to fetch them
     * through the console's own api() and hand the blob to the browser — an
     * edit to admin/app.blade.php, which is the integrator's file.
     *
     * THIS CASE IS THE COUNT, so the set cannot grow quietly: a seventh
     * navigation is a red suite and a decision somebody makes on purpose.
     *
     * MUTATION NOTE. Add `location.href = '/admin-api/anything'` anywhere in
     * admin/app.blade.php and this is red at 6 against 5. RUN.
     */
    $console = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    /*
     * `[^;)]` and not `[\s\S]`: olPrintDocs() breaks its window.open over four
     * lines so the pattern has to cross newlines, but it must NOT cross a `)`
     * or a `;` — allowed to, it runs from an unrelated `window.open(url, ...)`
     * on to the next admin-api string further down the file and reports a
     * navigation that is not there.
     */
    preg_match_all(
        '/(?:location(?:\.href)?\s*=|window\.open\s*\()[^;)]{0,200}?admin-api\/([a-z0-9\/-]+)/i',
        $console,
        $matches
    );

    $literal = array_values(array_unique($matches[1] ?? []));
    sort($literal);

    expect($literal)->toBe([
        'catalog-products-export',
        'customers/export',
        'orders-bulk-documents',
        'orders-export',
        'reviews/export',
    ], 'the set of admin-api addresses the console navigates to has changed');

    /*
     * And the four the console never spells out, because the server hands them
     * over on the order payload. Asserted at the source of the string rather
     * than at the console, which is the only place they exist.
     */
    foreach (['invoiceUrl', 'packingSlipUrl', 'deliveryNoteUrl', 'shippingLabelUrl'] as $builder) {
        $url = \App\Http\Controllers\Admin\InvoiceController::$builder(1);

        expect(str_contains($url, '/admin-api/'))->toBeTrue(
            "InvoiceController::{$builder}() no longer builds an admin-api URL; this note needs rewriting."
        );
    }

    // The console really does navigate to them rather than fetch them.
    expect(substr_count($console, "window.open(url, '_blank', 'noopener')"))->toBe(1);
});
