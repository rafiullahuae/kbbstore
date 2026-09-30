<?php

declare(strict_types=1);

use App\Support\AdminCapabilities;

/**
 * =============================================================================
 * THE ADMIN-API ADDRESSES THAT NEVER APPEAR IN THE CONSOLE'S SOURCE
 * =============================================================================
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * Closing the second door — an admin-guarded address that does not carry the
 * secret admin path is hidden rather than pointed at a login — changed what an
 * expired session does to anything the console NAVIGATES at rather than
 * fetches. Finding those was a scan of `resources/views/admin/` for
 * `admin-api`, and the scan found five of the nine.
 *
 * THE OTHER FOUR WERE INVISIBLE TO IT, because the address is never written in
 * the console at all. `Admin\InvoiceController::invoiceUrl()` and its three
 * siblings build `/admin-api/orders/{id}/invoice`, `/packing-slip`,
 * `/delivery-note` and `/shipping-label` on the SERVER, `AdminOrderController`
 * puts them on the order payload, and `app.blade.php` does
 *
 *     window.open(url, '_blank', 'noopener')
 *
 * on a variable. Grepping the console for `admin-api` finds nothing. They were
 * found by following `o.invoice_url` back through the JSON to the controller,
 * which is not a thing a scan does and not a thing the next lane will think to
 * do either.
 *
 * ── SO THIS IS THE SCAN THAT WOULD HAVE FOUND THEM ─────────────────────────
 *
 * It reads the SERVER, not the console: every place the application builds an
 * `admin-api` URL as a string and hands it out as data. The set is pinned, so a
 * new one is a red suite and a decision somebody makes on purpose — which is
 * the whole point, because the failure it guards against is silent. Nothing
 * errors when a console navigation lands on a 404; the owner simply loses his
 * screen and nobody hears about it.
 *
 * ── AND IT ASKS THE TWO QUESTIONS THAT MATTER ABOUT EACH ONE ───────────────
 *
 *   1. Is the address governed? A URL the server hands to a browser is a URL
 *      somebody will eventually navigate at, so it must be in
 *      `AdminCapabilities` under a capability of its own, or it is an
 *      unmapped admin route — which `AdminCapabilityMapTest` refuses for
 *      routes and which nothing refuses for a string.
 *
 *   2. Can the console tell before it navigates? Every one of these must
 *      accept the `?probe=1` question `App\Support\ExportProbe` answers, or an
 *      expired session is a blank page with no way back.
 */

/**
 * Every `admin-api` URL this application BUILDS as a string, found by reading
 * the source rather than by remembering.
 *
 * `app/` only: a URL written into a Blade template is in the console and the
 * ordinary scan finds it. What this is for is the ones that are not.
 *
 * @return array<string, string>  builder => the URL shape it produces
 */
function serverBuiltAdminUrls(): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (! str_contains($source, 'admin-api')) {
            continue;
        }

        /*
         * A string literal that starts an admin-api path. Concatenation after
         * it is deliberately not reconstructed -- the point is to NOTICE the
         * builder, not to compute its output, and a shape like
         * `'/admin-api/orders/' . $id . '/invoice'` is exactly the one a naive
         * scan of the console misses.
         */
        if (preg_match_all('#[\'"](/admin-api/[a-z0-9/_-]*)[\'"]#i', $source, $matches)) {
            foreach ($matches[1] as $path) {
                $found[$file->getFilename().' '.$path] = $path;
            }
        }
    }

    ksort($found);

    return $found;
}

it('names every admin-api address the server builds and hands out as data', function () {
    /*
     * ▲ THE PIN, AND IT FOUND FOUR THIS LANE'S FIRST SCAN HAD MISSED.
     *
     * Reading `resources/views/admin/` for `admin-api` found nine console
     * navigations. Reading the SERVER finds four more, because the address is
     * never written in the console at all:
     *
     *   InstagramAuth / InstagramController  /admin-api/instagram/start
     *                                        /admin-api/instagram/callback
     *   StripeConnect                        /admin-api/payments/stripe/connect/callback
     *
     * — OAuth legs, every one an admin-guarded GET at a public address, so each
     * used to answer an expired session with a 302 naming the secret admin path
     * and now answers 404. Two of them are navigated to by INSTAGRAM and by
     * STRIPE rather than by this console, so nothing here can ask a question
     * before the browser arrives; they are named in the third case below.
     *
     * A tenth builder is a red suite. That is the point: the failure this
     * guards against is silent — nothing errors when a console navigation
     * lands on a 404, the owner simply loses his screen.
     *
     * MUTATION NOTE. Add `Url::to('/admin-api/anything')` to any class under
     * app/ and this is red naming the file. RUN.
     */
    expect(array_keys(serverBuiltAdminUrls()))->toBe([
        'BulkDocumentController.php /admin-api/orders-bulk-documents',
        'BulkDocumentController.php /admin-api/orders/',
        'InstagramAuth.php /admin-api/instagram/callback',
        'InstagramController.php /admin-api/instagram/callback',
        'InstagramController.php /admin-api/instagram/start',
        'InvoiceController.php /admin-api/orders/',
        'PageCost.php /admin-api/stats',
        'ProductEditorApiController.php /admin-api/media/upload',
        'StripeConnect.php /admin-api/payments/stripe/connect/callback',
    ], 'the set of server-built admin-api URLs has changed');
});

it('governs every one of them with a capability of its own', function () {
    /*
     * A URL the server hands to a browser is a URL somebody will navigate at,
     * so it has to be as governed as a route the router carries.
     * AdminCapabilityMapTest refuses an unmapped ROUTE; nothing refuses an
     * unmapped string, which is what these are until they are resolved.
     *
     * MUTATION NOTE. Delete the `['GET', 'admin-api/orders/*\/invoice', …]` row
     * from AdminCapabilities and this is red naming that address. RUN.
     */
    $addresses = [
        'admin-api/orders-bulk-documents',
        'admin-api/orders/1/invoice',
        'admin-api/orders/1/packing-slip',
        'admin-api/orders/1/delivery-note',
        'admin-api/orders/1/shipping-label',
    ];

    foreach ($addresses as $uri) {
        expect(AdminCapabilities::forPath('GET', $uri))->not->toBeNull(
            "{$uri} is built by the server and handed to the console, and no capability governs it"
        );
    }
});

it('lets the console ask before it navigates at any of them', function () {
    /*
     * Every server-built address is opened with `window.open()`, which must
     * happen inside the click -- a popup opened from an async continuation is
     * blocked -- so the tab is opened FIRST and the question asked after it.
     * That only works if the address answers the question at all.
     *
     * MUTATION NOTE. Remove the ExportProbe::answer() call from
     * Admin\InvoiceController::invoice() and this is red: the probe streams the
     * invoice instead of answering, so the console can never tell a dead
     * session from a live one. RUN.
     */
    $admin = \App\Models\AdminUser::create([
        'name' => 'Server URL', 'email' => 'server-url-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    $order = \App\Models\Order::create([
        'order_number' => 'SBU-'.\Illuminate\Support\Str::random(6),
        'email' => 'buyer@example.com', 'status' => 'processing', 'currency' => 'AED',
        'subtotal' => 10000, 'discount_total' => 0, 'shipping_total' => 0,
        'fee_total' => 0, 'gift_fee' => 0, 'tax_total' => 0, 'total' => 10000,
    ]);

    $this->actingAs($admin, 'admin');

    foreach ([
        '/admin-api/orders-bulk-documents?type=invoice&ids='.$order->id,
        '/admin-api/orders/'.$order->id.'/invoice',
        '/admin-api/orders/'.$order->id.'/packing-slip',
        '/admin-api/orders/'.$order->id.'/delivery-note',
        '/admin-api/orders/'.$order->id.'/shipping-label',
    ] as $url) {
        $probe = $url.(str_contains($url, '?') ? '&' : '?').'probe=1';

        expect($this->getJson($probe)->json())->toBe(
            ['ok' => true],
            "{$url} cannot be asked whether the session is alive"
        );
    }
});

it('names the legs a third party navigates at, which nothing here can ask about first', function () {
    /*
     * ▲ FOUND BY THIS GUARD, AND HONESTLY NOT FIXABLE FROM THIS SIDE.
     *
     * `/admin-api/instagram/callback` and
     * `/admin-api/payments/stripe/connect/callback` are navigated to by
     * INSTAGRAM and by STRIPE, returning the owner's browser after he has
     * approved the connection. This application is not the one doing the
     * navigating, so there is no click to ask a question in front of.
     *
     * With an expired session they used to answer a 302 to the admin login —
     * which named the secret path to anyone who typed the address — and answer
     * 404 now. NEITHER IS A WORKING FLOW: the authorisation code is single-use
     * and the round trip has to be started again either way, so what is lost is
     * an explanation rather than a connection.
     *
     * WHAT MAKES IT SMALL is that the leg the owner presses — `/start` — is a
     * popup this console opens, so the dead session IS caught there, before he
     * is sent to Instagram at all, instead of after he comes back. Both
     * `/start` legs now answer `?probe=1` as the first statement of their
     * action, minting no state while they do it; DownloadSessionProbeTest's
     * last three cases are that, and DownloadNavigationGateTest is the console
     * half. He went, approved, came back and only then met the login; now he is
     * told before he goes.
     *
     * The addresses cannot simply move under the admin path, which is where a
     * redirect would still be safe: `InstagramAuth`'s own comment says why —
     * the redirect URI is registered with the provider and `admin_path` is a
     * setting the owner can change.
     *
     * PINNED SO THE SET CANNOT GROW. A third provider added with the same shape
     * is a decision somebody makes on purpose.
     */
    $unprobeable = [
        'admin-api/instagram/callback',
        'admin-api/payments/stripe/connect/callback',
    ];

    foreach ($unprobeable as $uri) {
        expect(AdminCapabilities::forPath('GET', $uri))->not->toBeNull(
            "{$uri} is reached by a third party's redirect and nothing governs it"
        );
    }

    // And they really are admin-guarded GETs at addresses outside the admin
    // path, which is what makes them answer 404 rather than a redirect.
    $legs = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (str_ends_with($route->uri(), '/callback') && str_starts_with($route->uri(), 'admin-api/')) {
            $legs[] = $route->uri();
        }
    }

    sort($legs);

    expect($legs)->toBe($unprobeable, 'a third provider now returns the browser to an admin-api address');
});
