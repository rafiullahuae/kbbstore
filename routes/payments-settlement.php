<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Payment settlement admin API — capture (Lane O)
|------------------------------------------------------------------------------
|
| NOT YET WIRED. CLAUDE.md forbids editing routes/web.php, so this file ships
| with its mounting instruction here instead of mounted.
|
| INTEGRATOR: add ONE line to routes/web.php, inside the EXISTING `admin-api`
| group — the one opened at line 233 with
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which is itself nested inside the `Route::middleware('auth:admin')` group
| opened at line 161. Put it beside the other require lines in that group, next
| to `require __DIR__.'/payments-admin.php';` (line 284):
|
|     require __DIR__.'/payments-settlement.php';
|
| IT MUST STAY INSIDE THAT GROUP. These endpoints move money: capture takes a
| customer's funds and the settlement read exposes what a payment is worth.
| Mounted anywhere without `auth:admin` they are a way for a stranger to charge
| the store's customers, and mounted in routes/api.php they would be worse
| still — everything there is unauthenticated by design.
|
| The ORDER of the require matters only in that it must come before web.php's
| GET-only Route::fallback, which every route in this group already does.
|
| Resulting paths, matching the /orders/{id}/… convention the order screen
| already uses (AdminOrderController's own routes are lines 410–420):
|
|     GET  /admin-api/orders/{id}/settlement   capture state + refundable
|     POST /admin-api/orders/{id}/capture      take the authorised money
|
| Refund is deliberately NOT here. POST /admin-api/orders/{id}/refund already
| exists at routes/web.php line 412 and already points at
| AdminOrderController::refund; that method now routes through PaymentRefunder
| and really calls the gateway. A second refund route would be a second way to
| move money, and the whole point of the ceiling check is that there is one.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_17_010000_clear_caches_payment_capture.php). Without it the compiled
| route cache on the live host knows neither path and every Capture click 404s
| while the button renders perfectly.
|
*/

use App\Http\Controllers\Admin\PaymentSettlementController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}/settlement', [PaymentSettlementController::class, 'show'])
    ->where('id', '[0-9]+');

Route::post('/orders/{id}/capture', [PaymentSettlementController::class, 'capture'])
    ->where('id', '[0-9]+');
