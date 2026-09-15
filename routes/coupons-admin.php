<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Coupon usage — Store → Coupons
|------------------------------------------------------------------------------
|
| INTEGRATOR: add exactly one line to routes/web.php, INSIDE the existing
|
|     Route::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
|         ->prefix('admin-api')
|         ->group(function () { ... });
|
| block — the one that already mounts routes/orders-admin.php,
| routes/customers-admin.php and routes/manual-orders-admin.php — beside those
| requires:
|
|     require __DIR__ . '/coupons-admin.php';
|
| Mounting it THERE and nowhere else is what gives both routes below:
|
|   * the web + auth:admin guard. THIS MATTERS MORE HERE THAN ALMOST ANYWHERE.
|     /admin-api/coupons/{id} returns the email address of every shopper who
|     has redeemed that code. Mounted from routes/api.php it would be a public
|     endpoint that hands out a customer list keyed by promotion — and /api/*
|     is unauthenticated, which CLAUDE.md states plainly and
|     tests/Feature/ApiSecurityTest.php exists to keep true. There is no
|     per-route authorisation inside CouponUsageApiController; it relies
|     entirely on being inside that group, exactly as AdminOrderController does.
|
|     ONE middleware() CALL, NOT TWO. RouteRegistrar::middleware() REPLACES the
|     pending middleware rather than appending, so ->middleware('web')
|     ->middleware('auth:admin') registers routes carrying auth:admin and NOT
|     web — or, reversed, no guard at all while reading as though it had one.
|   * the /admin-api prefix, which is why neither path below repeats it.
|   * NoStoreAdminApi, so shared hosting cannot serve a cached copy of one
|     shopper's redemption list to the next person who opens the screen.
|
| Routes are matched in declaration order, so the literal path comes before the
| one that takes a parameter.
|
| Compiled route cache: this file adds routes, so the package that ships it also
| ships database/migrations/2026_09_15_120000_clear_caches_coupon_redemption.php.
| Without that the server keeps serving the old compiled route table and both
| paths here 404 while looking perfectly correct in the repo — and a 404 on the
| list endpoint renders as a Coupons screen that is simply empty, which reads as
| "no coupons" rather than as an error.
|
*/

use App\Http\Controllers\Admin\CouponUsageApiController;
use Illuminate\Support\Facades\Route;

// Every coupon, with usage_count beside the limit governing it.
Route::get('/coupons', [CouponUsageApiController::class, 'index']);

// One coupon, and the redemptions against it. Numeric id only, so the literal
// path above can never be captured by this one.
Route::get('/coupons/{coupon}', [CouponUsageApiController::class, 'show'])
    ->whereNumber('coupon');
