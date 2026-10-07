<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Stripe connect / disconnect — Lane EO
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file inside the EXISTING admin-api
| group — the one that already carries `auth:admin` and NoStoreAdminApi —
| beside the other payments requires:
|
|     require __DIR__.'/payments-admin.php';
|     require __DIR__.'/payments-settlement.php';
|     require __DIR__.'/payments-preflight.php';
|     require __DIR__.'/payments-connect.php';        <-- this one
|
| INSIDE that group and nowhere else. Every route here reads or writes live
| Stripe credentials; one of them takes a secret key in a request body and
| another wipes the shop off its payment provider. Mounted anywhere outside the
| admin guard they would be a public endpoint for pointing this shop's card
| payments at somebody else's Stripe account.
|
| routes/api.php would be the specifically wrong place: everything there is
| unauthenticated by design.
|
| NOTHING IS CHAINED onto these routes. RouteRegistrar::middleware() REPLACES
| rather than appends, so a `->middleware(...)` here would silently drop
| NoStoreAdminApi from the group and let a browser cache a response describing
| the payment configuration.
|
| Resulting paths:
|
|     GET  /admin-api/payments/stripe/connect/status        what is connected
|     POST /admin-api/payments/stripe/connect               one-paste connect
|     POST /admin-api/payments/stripe/connect/application   store the ca_... id
|     GET  /admin-api/payments/stripe/connect/start         opens the popup
|     GET  /admin-api/payments/stripe/connect/callback      Stripe returns here
|     POST /admin-api/payments/stripe/disconnect            reset
|
| ORDER MATTERS. `POST /payments/stripe/connect` and
| `POST /payments/stripe/connect/application` differ by a segment and cannot
| shadow each other, but the three GETs under `/connect/` are listed before the
| bare POST so the file reads in the order the screen uses it, and so nobody
| later adds `/connect/{something}` above them.
|
| ---------------------------------------------------------------------------
| THE CALLBACK IS A BROWSER NAVIGATION, NOT AN API CALL
|
| Stripe redirects the owner's popup to /connect/callback. It is a top-level
| GET, so the admin session cookie rides along under SameSite=Lax and
| `auth:admin` is satisfied by the session he is already signed in with. That is
| deliberate: an unauthenticated callback would be a URL anyone could fetch to
| push credentials into this shop, and the OAuth `state` alone should not be the
| only thing standing there.
|
| Its URL is the one that has to be whitelisted in the Stripe Connect
| application's settings. /admin-api/ is a FIXED prefix — it is not the
| configurable admin path — so the URL is stable across installs and does not
| change if the owner moves his console.
|
| ---------------------------------------------------------------------------
| CAPABILITIES
|
| All six are mapped to `payments.manage` in App\Support\AdminCapabilities, the
| same capability that guards the screen which edits these credentials by hand.
| AdminCapabilities fails closed, so an unmapped route here would have been
| owner-only anyway — which is the right answer, but an accidental one, and the
| coverage test would have failed it.
|
| ---------------------------------------------------------------------------
| A `clear_caches_*` MIGRATION SHIPS WITH THIS PACKAGE
| (2026_11_10_000000_clear_caches_stripe_connect.php). Without it the compiled
| route cache on the live host knows none of these paths and every button on the
| new panel 404s while rendering perfectly — which is exactly how packages
| 2.60.102-.106 shipped inert. It also clears compiled Blade, because
| resources/views/admin/stripe-connected.blade.php is new and app.blade.php is
| edited by the integrator in the same package.
|
*/

use App\Http\Controllers\Admin\StripeConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/stripe/connect/status', [StripeConnectController::class, 'status']);
Route::get('/payments/stripe/connect/start', [StripeConnectController::class, 'start']);
Route::get('/payments/stripe/connect/callback', [StripeConnectController::class, 'callback']);

Route::post('/payments/stripe/connect/application', [StripeConnectController::class, 'application']);
Route::post('/payments/stripe/connect', [StripeConnectController::class, 'store']);
Route::post('/payments/stripe/disconnect', [StripeConnectController::class, 'destroy']);

/*
|--------------------------------------------------------------------------
| Lane SR: the Stripe status block, automatic webhook setup and the payment
| log, all on Store -> Payments -> Stripe. In this file rather than one of their
| own because this one is already mounted in routes/web.php inside the admin
| group, so they need no wiring -- only the clear_caches migration that ships
| with them (2027_10_07_100100_clear_caches_stripe_settings.php). Their own
| capabilities, payments.stripe_webhook and payments.log, are mapped in
| App\Support\AdminCapabilities.
|--------------------------------------------------------------------------
*/

Route::get('/payments/stripe/webhook', [\App\Http\Controllers\Admin\StripeSettingsController::class, 'status']);
Route::post('/payments/stripe/webhook/setup', [\App\Http\Controllers\Admin\StripeSettingsController::class, 'setup']);
Route::get('/payments/stripe/log', [\App\Http\Controllers\Admin\StripeSettingsController::class, 'log']);
