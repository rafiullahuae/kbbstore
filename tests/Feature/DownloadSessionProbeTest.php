<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use App\Support\ExportProbe;
use Illuminate\Support\Facades\Route;

/**
 * =============================================================================
 * CLOSING THE SECOND DOOR TOOK THE OWNER'S SCREEN AWAY WITH IT
 * =============================================================================
 *
 * ── WHAT IT LOOKED LIKE ────────────────────────────────────────────────────
 *
 * An admin-guarded address that does not carry the secret admin path answers a
 * browser with a plain 404 now, because the 302 it used to answer with named
 * `admin_path` in a Location header to anyone who typed `admin-api`.
 *
 * FOUR BUTTONS IN THE CONSOLE REACH SUCH AN ADDRESS BY NAVIGATING THE WHOLE
 * PAGE AT IT — the orders, customers, reviews and catalogue exports. With an
 * expired session the administrator used to land on the admin login and sign
 * back in. He now lands on a blank 404 **with the console gone from the
 * screen**, losing the list he was working on. That is strictly worse than what
 * it replaced.
 *
 * ── WHY NOT A BLOB ─────────────────────────────────────────────────────────
 *
 * The obvious repair — fetch the file through api() and hand over a blob URL —
 * is the wrong one, and the call sites said so before any of this:
 *
 *     "A normal navigation, not a fetch: the browser carries the same admin
 *      session cookie, the server refuses anyone without it, and the file lands
 *      in Downloads instead of in memory."
 *
 * All four actions return a `StreamedResponse`. A blob buffers the whole CSV in
 * the tab before a byte reaches the disk. The first case below pins the
 * streaming, so a future lane cannot quietly make it true that the export is
 * built in memory.
 *
 * ── SO THE BUTTON ASKS FIRST ───────────────────────────────────────────────
 *
 * `?probe=1` on the export's OWN address. Not a new endpoint: that would need a
 * route file, a line in routes/web.php, a clear_caches migration, a capability,
 * a row in AdminCapabilities and an entry in the test that enumerates every
 * admin route — and it would answer the wrong question. "Is any admin session
 * alive" is not what the button needs to know; "may THIS operator run THIS
 * export" is, and asking it here gets that for free because AdminCapabilities
 * matches on the route's URI and a query string is not part of it.
 */
function probeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Probe '.$role,
        'email' => 'probe-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** Every address the console navigates at, and the capability that governs it. */
function probeAddresses(): array
{
    return [
        'admin-api/orders-export' => 'orders.export',
        'admin-api/customers/export' => 'customers.export',
        'admin-api/reviews/export' => 'reviews.export',
        'admin-api/catalog-products-export' => 'catalog.export',
        'admin-api/orders-bulk-documents' => 'invoices.view',
        /*
         * ▲ THE TWELFTH, ADDED IN ROUND 4. Reviews -> Reviews.io -> Export
         * navigates the whole console at this address from
         * admin/partials/reviews-io-screen.blade.php. Three scans of
         * admin/app.blade.php never saw it; widening
         * DownloadNavigationGateTest's pinned set to the partials did.
         */
        'admin-api/reviews-io/export' => 'reviews.export',
    ];
}

it('answers a probe with a yes and nothing else at all', function () {
    /*
     * ▲ NOTHING ABOUT THE SHOP. The answer is carried entirely by the STATUS;
     * the body exists only because the console's api() wrapper ends in
     * `r.json()` and a 204 would make a successful probe throw. No count, no
     * echo of the filter, no row.
     *
     * MUTATION NOTE. Make ExportProbe::answer() return the real export instead
     * — delete its early return from any one action — and this is red: the
     * response is a CSV, not `{"ok":true}`. RUN.
     */
    $this->actingAs(probeAdmin(), 'admin');

    foreach (array_keys(probeAddresses()) as $uri) {
        $response = $this->getJson('/'.$uri.'?probe=1');

        $response->assertOk();

        expect($response->json())->toBe(
            ['ok' => true],
            "GET /{$uri}?probe=1 answered more than a yes: ".$response->getContent()
        );

        /*
         * `no-store`, because a cached yes from five minutes ago is exactly the
         * answer this must never give. NoStoreAdminApi already puts it on every
         * admin-api response, and ExportProbe sets it too rather than relying on
         * a middleware in another lane's group staying where it is.
         */
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('refuses a probe to a caller with no admin session, exactly as the export is refused', function () {
    /*
     * The probe is the first statement of an action that is already behind
     * `auth:admin`, so a caller who is not entitled never reaches ExportProbe
     * at all. It fails closed by construction rather than by intention.
     *
     * 401 and not 404 because the console asks with `Accept: application/json`,
     * which is the half of the second-door fix that was deliberately left
     * alone — see GuestRedirect::hidesTheAddressInstead().
     *
     * MUTATION NOTE. Move the ExportProbe::answer() call into a route closure
     * outside `auth:admin` and this is red: the probe answers 200 to a
     * stranger. RUN.
     */
    foreach (array_keys(probeAddresses()) as $uri) {
        $this->getJson('/'.$uri.'?probe=1')->assertStatus(401);
    }
});

it('refuses a probe to an operator whose role may not run that export', function () {
    /*
     * ▲ THE REASON THE PROBE IS A PARAMETER ON THE EXPORT AND NOT A SHARED
     *   LIVENESS ENDPOINT.
     *
     * A shared endpoint would answer "yes, a session is alive" and the button
     * would navigate to a 403 page — losing the console for the second reason
     * in a week. Asked here, the probe passes through the export's own
     * capability, so the operator is told before anything moves.
     *
     * `content` is the role this map gives none of these five capabilities;
     * the loop asserts that rather than trusting it.
     *
     * MUTATION NOTE. Give `content` the four export capabilities in
     * AdminCapabilities and this is red. RUN.
     */
    $operator = probeAdmin('content');

    $this->actingAs($operator, 'admin');

    foreach (probeAddresses() as $uri => $capability) {
        expect(AdminCapabilities::roleCan('content', $capability))->toBeFalse(
            "this case needs a role WITHOUT {$capability}; 'content' now has it"
        );

        $this->getJson('/'.$uri.'?probe=1')->assertStatus(403);
    }
});

it('leaves the export itself streamed, which is why the probe exists', function () {
    /*
     * ▲ THE THING THE PROBE IS PROTECTING. If a later lane converts these to a
     * buffered response the comment at the five call sites stops being true —
     * "the file lands in Downloads instead of in memory" — and the whole reason
     * for the probe evaporates.
     *
     * MUTATION NOTE. Change any export action's return to
     * `response()->make($csv)` and this is red. RUN.
     */
    $reflect = [
        [\App\Http\Controllers\Admin\OrdersApiController::class, 'export'],
        [\App\Http\Controllers\Admin\CustomersApiController::class, 'export'],
        [\App\Http\Controllers\Admin\ReviewsApiController::class, 'export'],
        [\App\Http\Controllers\Admin\CatalogProductsApiController::class, 'export'],
        // The twelfth navigation's action, added in round 4. Reviews.io's
        // export streams too, and the call site's own comment promises it:
        // "the response is a file download with a Content-Disposition on it".
        [\App\Http\Controllers\Admin\ReviewsIoApiController::class, 'export'],
    ];

    foreach ($reflect as [$class, $method]) {
        $type = (string) (new ReflectionMethod($class, $method))->getReturnType();

        expect(str_contains($type, 'StreamedResponse'))->toBeTrue(
            "{$class}::{$method}() no longer streams, and the navigation it is reached by assumes it does"
        );
    }
});

it('does not run the export when it is only being probed', function () {
    /*
     * FIRST STATEMENT, so a probe builds no query and no closure. A probe that
     * paid for the whole export before answering would make every export button
     * cost twice — on the catalogue, the most expensive read in the console.
     *
     * Measured as statements rather than asserted: a probe runs strictly fewer
     * than the export it stands in front of.
     *
     * MUTATION NOTE. Move the ExportProbe::answer() call to the END of
     * CatalogProductsApiController::export(), after the query is built, and
     * this is red. RUN.
     */
    $this->actingAs(probeAdmin(), 'admin');

    // A catalogue to export, or the export runs almost nothing and the
    // comparison below is between two numbers that mean nothing.
    for ($i = 0; $i < 5; $i++) {
        \App\Models\Product::create([
            'slug' => 'probe-'.$i.'-'.\Illuminate\Support\Str::random(6),
            'name' => 'Probe '.$i, 'type' => 'simple', 'status' => 'publish',
            'is_visible' => true, 'price' => 9000, 'stock_status' => 'instock',
        ]);
    }

    $probed = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$probed) { $probed++; });

    // Warm first: the first request of a process pays for memos the rest do not,
    // and without this the probe looks dearer than the export it stands in front
    // of. Measured: 3 against 2, the smaller request reporting the larger number.
    $this->getJson('/admin-api/catalog-products-export?probe=1')->assertOk();

    $probed = 0;
    $this->getJson('/admin-api/catalog-products-export?probe=1')->assertOk();
    $afterProbe = $probed;

    $probed = 0;
    $this->get('/admin-api/catalog-products-export')->assertOk()->streamedContent();
    $afterExport = $probed;

    expect($afterProbe)->toBeLessThan(
        $afterExport,
        "the probe ran {$afterProbe} statements and the export {$afterExport}; a probe must not pay for the export"
    );
});

it('is the export that decides, so a probe cannot reach an address the map does not cover', function () {
    /*
     * The probe adds NO new route, which is the whole point of putting it on
     * the export. `AdminCapabilityMapTest > it maps every admin route the
     * router actually carries` is what enforces that a new one would need a
     * capability; this asserts there is no new one to map.
     *
     * MUTATION NOTE. Register a `/admin-api/session-probe` route and this is
     * red — and so is AdminCapabilityMapTest, which is the point. RUN.
     */
    foreach (probeAddresses() as $uri => $capability) {
        expect(AdminCapabilities::forPath('GET', $uri))->toBe(
            $capability,
            "{$uri} is not in the capability map under {$capability}; the probe would be ungoverned"
        );
    }

    /*
     * And no route was added for it. Asserted as "the router carries no route
     * whose URI names this parameter" rather than "no route contains the word
     * probe", because `admin-api/cache/probe` is a real endpoint of another
     * lane's and has nothing to do with this.
     */
    $added = [];

    foreach (Route::getRoutes() as $route) {
        if (str_contains($route->uri(), ExportProbe::PARAM.'=') || str_ends_with($route->uri(), '/session-probe')) {
            $added[] = $route->uri();
        }
    }

    expect($added)->toBe([], 'the probe must be a parameter, never a route: '.implode(', ', $added));

        expect(ExportProbe::PARAM)->toBe('probe');
});

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * A TENTH AND AN ELEVENTH NAVIGATION, found after the five above shipped
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * DownloadNavigationGateTest reads the CONSOLE for `window.open` and
 * `location.href` rather than reading a list, and it turned up two more:
 *
 *   payStripeOauth()                   /admin-api/payments/stripe/connect/start
 *   instagram-screen's openPopup()     /admin-api/instagram/start
 *
 * (Lane IGR: the Instagram leg was retired with the Instagram API module at
 * the owner's request; its route is gone, so only Stripe's is asked below.)
 *
 * Both are admin-guarded GETs at addresses outside the secret admin path, so
 * both answer an expired session with a blank 404 in a popup, with nothing said
 * on the console behind it. Neither address is written anywhere this lane's
 * first scan looked -- one is in app.blade.php's payments section, the other in
 * a partial -- which is exactly why the guard reads the console instead.
 *
 * THESE ARE THE LEGS THE OWNER PRESSES. `/instagram/callback` and
 * `/payments/stripe/connect/callback` are navigated to by INSTAGRAM and by
 * STRIPE, so there is no click here to ask a question in front of; the fourth
 * case in ServerBuiltAdminUrlsTest argues that at length. Catching the dead
 * session at `/start` means the owner is told BEFORE he is sent to the
 * provider, approves, comes back, and finds the round trip was wasted.
 */
it('lets the console ask before it opens an OAuth popup', function () {
    /*
     * MUTATION NOTE. Remove the ExportProbe::answer() call from
     * Admin\StripeConnectController::start() and the first expectation is red:
     * the probe gets a redirect to Stripe, or the closing page, instead of an
     * answer -- so a dead session is indistinguishable from a live one and the
     * popup is left showing a 404. RUN.
     */
    $admin = probeAdmin('owner');
    $this->actingAs($admin, 'admin');

    foreach ([
        '/admin-api/payments/stripe/connect/start?mode=test&probe=1',
    ] as $url) {
        expect($this->getJson($url)->json())->toBe(
            ['ok' => true],
            "{$url} cannot be asked whether the session is alive"
        );
    }
});

it('mints no OAuth state when it is only being probed', function () {
    /*
     * ▲ THE REASON THE PROBE IS THE FIRST STATEMENT AND NOT A LATER ONE.
     *
     * Both `start` actions put an unguessable state in the session, with a TTL,
     * for the callback to compare against. A probe that reached that line would
     * either burn the TTL on a window nobody opened, or -- worse -- replace the
     * state a popup opened a moment earlier is about to come back with, which
     * turns a working connection into "that request has expired".
     *
     * MUTATION NOTE, AND THE FIRST DRAFT FAILED IT. Moving the
     * ExportProbe::answer() call in Admin\StripeConnectController::start() to
     * AFTER `$request->session()->put(StripeConnect::STATE_SESSION_KEY, ...)`
     * left the case GREEN, because nothing was configured and `authorizeUrl()`
     * refused before reaching the put at all. With the credentials below the
     * same mutation is RED naming the key. RUN.
     */
    $admin = probeAdmin('owner');
    $this->actingAs($admin, 'admin');

    /*
     * ▲ BOTH PROVIDERS ARE CONFIGURED FIRST, AND THE FIRST DRAFT OF THIS CASE
     * WAS NOT. Without credentials `authorizeUrl()` refuses before it mints
     * anything, so `start` never reached the `put()` this is about -- and
     * moving the probe to AFTER the state is minted left the case GREEN.
     * Measured: unconfigured, an unprobed `/start` answers 200 and 302 and sets
     * NEITHER key.
     */
    $stripe = \App\Models\PaymentProvider::firstOrNew(['id' => 'stripe']);
    $stripe->fill(['title' => 'Credit or debit card', 'enabled' => false, 'mode' => 'test', 'position' => 1])->save();
    $stripe->config = [
        'connect_client_id_test' => 'ca_PROBEGUARD1',
        'connect_client_secret_test' => 'sk_test_'.str_repeat('p', 24),
    ];
    $stripe->save();
    app(\App\Services\Payments\GatewayCredentials::class)->forget();

    $stateKeys = [
        'Stripe Connect' => \App\Services\Payments\StripeConnect::STATE_SESSION_KEY,
    ];

    /*
     * THE CONTROL. An unprobed call really does mint both, or the assertion
     * below is about nothing at all -- which is exactly what the first draft
     * turned out to be.
     */
    foreach ($stateKeys as $_ => $key) {
        session()->forget($key);
    }

    $this->get('/admin-api/payments/stripe/connect/start?mode=test');

    foreach ($stateKeys as $what => $key) {
        expect(session()->has($key))->toBeTrue("{$what} does not mint a state even unprobed, so this case asserts nothing");
    }

    // And now the probe, on a clean session.
    foreach ($stateKeys as $_ => $key) {
        session()->forget($key);
    }

    $this->getJson('/admin-api/payments/stripe/connect/start?mode=test&probe=1');

    foreach ($stateKeys as $what => $key) {
        expect(session()->has($key))->toBeFalse("a probe minted a {$what} state");
    }
});

it('refuses a probe on either OAuth leg to a caller with no admin session', function () {
    /*
     * Fails closed, by construction rather than by intention: the probe is the
     * first statement of an action that is already behind `auth:admin`, so a
     * caller with no session never reaches ExportProbe at all and gets the same
     * 401 every other admin-api XHR gets.
     *
     * MUTATION NOTE. The route FILES carry no middleware of their own -- the
     * group in routes/web.php that requires them does -- so the mutation is
     * there: mounting routes/payments-connect.php under `['web']` alone instead
     * of inside the `auth:admin` group makes the Stripe leg answer a guest.
     * RUN; red, "answered a guest".
     */
    foreach ([
        '/admin-api/payments/stripe/connect/start?mode=test&probe=1',
    ] as $url) {
        expect($this->getJson($url)->status())->toBe(401, "{$url} answered a guest");
    }
});

it('answers the same for an order that exists and one that never did', function () {
    /*
     * ▲ NO ID ORACLE, AND THIS REPOSITORY HAS PAID FOR ONE BEFORE.
     *
     * CLAUDE.md's known-gaps entry on Api\QuizController::expertRequest says it
     * in as many words: "branching differently on the two restores the id
     * oracle." The four order documents are probed at their OWN addresses,
     * which carry an order id -- so if the probe ran after `$this->find($id)`
     * it would answer "yes" for a real order and 404 for an invented one, and
     * anybody holding a stolen admin cookie could walk the id space counting
     * the shop's orders without ever fetching one.
     *
     * It does not, because ExportProbe::answer() is the FIRST STATEMENT: the
     * answer depends on the session and the capability and on nothing else at
     * all. That is the same reason it runs no query.
     *
     * The cost is real and is the right trade: a probe can say yes about an
     * order somebody else has since deleted, and the navigation then 404s in
     * its own tab. The console already has a fallback for a document it cannot
     * open, and a wrong "yes" about one order is worth far less than a reliable
     * count of every order.
     *
     * MUTATION NOTE. Move the ExportProbe::answer() call in
     * Admin\InvoiceController::invoice() to AFTER `$order = $this->find($id);`
     * and its `if ($order === null)` return, and this is red: the invented id
     * answers 404 while the real one answers 200. RUN.
     */
    $admin = probeAdmin('owner');
    $this->actingAs($admin, 'admin');

    $order = \App\Models\Order::create([
        'order_number' => 'ORACLE-'.\Illuminate\Support\Str::random(6),
        'email' => 'buyer@example.com', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 10000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 10000,
    ]);

    $invented = $order->getKey() + 90_000;
    expect(\App\Models\Order::find($invented))->toBeNull();

    foreach (['invoice', 'packing-slip', 'delivery-note', 'shipping-label'] as $doc) {
        $real = $this->getJson('/admin-api/orders/'.$order->getKey().'/'.$doc.'?probe=1');
        $fake = $this->getJson('/admin-api/orders/'.$invented.'/'.$doc.'?probe=1');

        expect($fake->status())->toBe(
            $real->status(),
            "{$doc} tells a real order id from an invented one by its status"
        )->and($fake->getContent())->toBe(
            $real->getContent(),
            "{$doc} tells a real order id from an invented one by its body"
        );
    }
});
