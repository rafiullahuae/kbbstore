<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The Stripe Connect PLATFORM application — Lane FG
|------------------------------------------------------------------------------
|
| ONE route. It is a read, and it returns the setup guide for registering a
| Stripe Connect application plus what this shop currently has stored for one.
|
| MOUNTED. routes/web.php requires this file inside the EXISTING admin-api
| group — the one that already carries `auth:admin` and NoStoreAdminApi —
| immediately after the file it extends:
|
|     require __DIR__.'/payments-connect.php';
|     require __DIR__.'/payments-connect-platform.php';     <-- this one
|
| Resulting path:
|
|     GET /admin-api/payments/stripe/connect/platform
|
| NOTHING IS CHAINED onto it. RouteRegistrar::middleware() REPLACES rather than
| appends, so a `->middleware(...)` here would silently drop NoStoreAdminApi
| from the group and let a browser cache a response describing this shop's
| payment configuration.
|
| ---------------------------------------------------------------------------
| WHY IT IS BEHIND auth:admin, WHEN ITS CONTENT IS A PUBLIC HOW-TO
|
| The guide half is prose anybody could read. The other half is not: it says
| whether this shop has a Connect application registered, which client ids it
| holds, and whether a platform secret key is stored. That is a description of
| how this shop's payments are wired and it belongs under the same capability
| as the screen that edits them — `payments.manage`, matched by the existing
| ['GET', 'admin-api/payments/stripe/connect/*'] rule in
| App\Support\AdminCapabilities::RULES. No new rule, and the rule it lands on
| is already listed BELOW the three POSTs beside it, so no write endpoint is
| reachable on a read capability.
|
| AdminCapabilities fails closed, so an unmapped route here would have been
| owner-only anyway. It is pinned by a test rather than left to that.
|
| NO SECRET IS RETURNED. The platform block is has_* booleans; the client ids
| are not secrets — they travel in the address bar of the popup — and the guide
| is static text.
|
| ---------------------------------------------------------------------------
| THE CALLBACK IS NOT IN THIS FILE, AND IS NOT BEING MOVED
|
| GET /admin-api/payments/stripe/connect/callback already exists in
| routes/payments-connect.php, inside the same admin-api group, and it stays
| there. It is worth writing down why, because "a public callback" is the
| conventional answer and it is the weaker one here.
|
| Stripe redirects the popup to the callback as a TOP-LEVEL GET NAVIGATION.
| Under SameSite=Lax — config/session.php's default, and this install's setting
| — the admin session cookie rides along with exactly that kind of request. So
| `auth:admin` is satisfied by the session the owner is already signed in with,
| and the callback gets a second lock for free: the OAuth `state` is not the
| only thing standing between a stranger's link and this shop's payment
| credentials.
|
| Taking the guard off would not make the flow work in more cases. The callback
| reads the state out of the SESSION, so it needs the session cookie either way;
| a request that cannot present the cookie has no state to compare against and
| is refused one line later. It would only widen what an unauthenticated
| request is allowed to reach before being refused.
|
| The one thing that would break it is SESSION_SAME_SITE=strict, under which no
| cross-site navigation carries the cookie — and under which an unguarded
| callback would fail just the same, at the state check instead of at the guard.
| StripeConnectPlatformTest pins the Lax assumption so a change to that setting
| fails a test rather than a payment.
|
| ---------------------------------------------------------------------------
| A `clear_caches_*` MIGRATION SHIPS WITH THIS PACKAGE
| (2026_11_17_000000_clear_caches_stripe_platform_connect.php). Without it the
| compiled route table on the live host does not contain this path, the panel
| renders, and its guide request 404s — which is exactly how packages
| 2.60.102-.106 shipped inert. It clears compiled Blade too, because the
| integrator edits resources/views/admin/app.blade.php in the same package
| (docs/FG-ADMIN-APP-BLOCKS.md) and a stale compiled console would paint the
| old panel over the new endpoint.
|
*/

use App\Http\Controllers\Admin\StripeConnectController;
use Illuminate\Support\Facades\Route;

Route::get('/payments/stripe/connect/platform', [StripeConnectController::class, 'platform']);
