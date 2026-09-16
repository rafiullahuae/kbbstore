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

use App\Http\Controllers\Admin\CouponAdminApiController;
use App\Http\Controllers\Admin\CouponUsageApiController;
use Illuminate\Support\Facades\Route;

// Every coupon, with usage_count beside the limit governing it.
Route::get('/coupons', [CouponUsageApiController::class, 'index']);

// One coupon, and the redemptions against it. Numeric id only, so the literal
// path above can never be captured by this one.
Route::get('/coupons/{coupon}', [CouponUsageApiController::class, 'show'])
    ->whereNumber('coupon');

/*
|------------------------------------------------------------------------------
| Coupon management — Store → Coupons → the editor (Lane BT)
|------------------------------------------------------------------------------
|
| INTEGRATOR: NOTHING NEW TO WIRE. These are in the same file as the two routes
| above, so the single `require __DIR__ . '/coupons-admin.php';` line described
| at the top of this file mounts them too, inside the same
| ['web', 'auth:admin', NoStoreAdminApi::class] group and behind the same
| /admin-api prefix. There is no second require and no second group.
|
| WHY THEY ALL SIT UNDER /coupons/manage. The read-only report above already
| owns `/coupons` and `/coupons/{coupon}`. That second route carries
| ->whereNumber('coupon'), so the literal segment `manage` can never be captured
| by it however the two blocks are ordered — but the paths are nested under a
| word rather than added as siblings so that a future `/coupons/{something}`
| cannot start swallowing them either.
|
| Staying under `admin-api/coupons` is load-bearing for the tests, not just
| tidiness: Tests\Support\CouponsAdminRoutes::registered() selects routes by
| `str_starts_with($r->uri(), 'admin-api/coupons')`, and the three guard tests
| in tests/Feature/CouponUsageScreenTest.php drive every route it returns —
| unauthenticated, as a signed-in customer, and by inspecting the middleware
| stack. A path registered outside that prefix would be guarded in exactly the
| same way and checked by nobody.
|
| THE GUARD MATTERS MORE ON THESE THAN ON THE REPORT. The report leaks a
| customer list if it escapes; these WRITE. An unguarded POST /coupons/manage is
| a stranger minting a 100%-off code on a live shop, and an unguarded DELETE is
| a stranger removing the codes a campaign is running on.
|
| The parameter is named {coupon} on every route that takes one, which is what
| CouponsAdminRoutes::paths() substitutes into when it builds the list it drives.
|
| Compiled route cache: this block adds routes, so the package that ships it
| also ships database/migrations/2026_10_24_000000_clear_caches_coupon_admin.php.
| Without it the server keeps serving the old compiled route table, every path
| here 404s, and the screen says so in as many words rather than rendering as a
| silently empty list.
|
| (That sentence avoids the obvious phrasing on purpose.
| tests/Feature/RouteFileHeadersTest.php greps every routes/*.php for a header
| claiming its own file has yet to be mounted, and fails when web.php requires
| it anyway -- fourteen files once announced themselves that way while serving
| live traffic. This file IS required. Its regex cannot tell a sentence about a
| stale compiled cache from a sentence about the file's own status, and it is
| right not to try: the cheap fix is to describe the symptom instead.)
|
| Declaration order within this block: the literal paths come before the ones
| that take a parameter, so /coupons/manage/lookup is never read as a coupon id.
*/

// The editor's own list. Richer than the usage report's and sorted newest
// first, because this screen is "find the code I just made", not "find the
// code that is being given away".
Route::get('/coupons/manage', [CouponAdminApiController::class, 'index']);

// Product and category search for the Usage restriction pickers, and the
// reverse lookup that turns a saved selection back into names.
Route::get('/coupons/manage/lookup', [CouponAdminApiController::class, 'lookup']);

Route::post('/coupons/manage', [CouponAdminApiController::class, 'store']);

Route::get('/coupons/manage/{coupon}', [CouponAdminApiController::class, 'show'])
    ->whereNumber('coupon');

Route::put('/coupons/manage/{coupon}', [CouponAdminApiController::class, 'update'])
    ->whereNumber('coupon');

Route::delete('/coupons/manage/{coupon}', [CouponAdminApiController::class, 'destroy'])
    ->whereNumber('coupon');
