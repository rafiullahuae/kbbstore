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
     * buffered response the comment at the four call sites stops being true —
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
