<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payment preflight — "have I set this gateway up correctly?" (Lane EF)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file inside the
| EXISTING `admin-api` group — the one that already carries `auth:admin` and
| NoStoreAdminApi, beside the other payments requires:
|
|     require __DIR__.'/payments-preflight.php';
|
| It must go beside `require __DIR__.'/payments-admin.php';` and
| `require __DIR__.'/payments-settlement.php';`, INSIDE that group and no
| further out. These endpoints describe which credentials a gateway is still
| missing and print the webhook URL, which together are a map of how to
| interfere with this shop's money. They are not as dangerous as the capture
| route next door — nothing here writes anything or moves a fil — but they are
| nowhere near public, and routes/api.php is unauthenticated by design.
|
| Resulting paths:
|
|     GET /admin-api/payments/preflight             every gateway
|     GET /admin-api/payments/preflight/{gateway}   one gateway
|
| ORDER MATTERS HERE, unusually. `payments-admin.php` defines
| GET /admin-api/payments, and Laravel matches in registration order; a static
| segment cannot be shadowed by that route, so either order works today. It is
| listed after payments-admin.php anyway so the three payments files read in
| the order the screen uses them.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_17_020000_clear_caches_payment_preflight.php). Without it the
| compiled route cache on the live host knows neither path, and the Check
| setup button 404s while rendering perfectly — which is precisely how two
| earlier packages on this project shipped inert.
|
| THE ADMIN SCREEN CONTROL THIS NEEDS, which this lane has NOT built because
| resources/views/admin/app.blade.php is owned elsewhere:
|
|   On each gateway tab in Store → Ecommerce → Payments, a button reading
|   "Check this setup", and a panel below it that renders the response:
|
|     - `missing_fields[]`  -> "Still needed: Webhook signing secret", listing
|                              the `label` of each. This is the single most
|                              useful line on the screen for an owner who has
|                              a half-filled form.
|     - `blocked_by[]`      -> shown as warnings when non-empty; an empty array
|                              means the gateway WOULD be offered at checkout
|                              right now.
|     - `webhook_url`       -> a copy-to-clipboard field, with the note that it
|                              is pasted into the provider's own dashboard.
|                              Null means "save this tab once and it will be
|                              generated for you".
|     - `mode`              -> the existing test/live selector; worth repeating
|                              here because `dry_run.url` shows which host that
|                              switch actually selected, and a sandbox key
|                              pointed at the live host is the commonest
|                              go-live failure.
|     - `dry_run`           -> a collapsed <pre> showing method, url, headers
|                              and body. Credentials are already replaced with
|                              «field as stored» server-side; the screen must
|                              print what it is given and must not try to
|                              pretty-print the body by parsing it.
|
|   The button is safe to press repeatedly: the request writes nothing and
|   reaches no provider.
|
*/

use App\Http\Controllers\Admin\PaymentPreflightController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/preflight', [PaymentPreflightController::class, 'index']);

Route::get('/payments/preflight/{gateway}', [PaymentPreflightController::class, 'show'])
    ->where('gateway', '[a-z]+');
