<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Manual orders — Store → New Order
|------------------------------------------------------------------------------
|
| INTEGRATOR: add exactly one line to routes/web.php, INSIDE the existing
|
|     Route::prefix('admin-api')->middleware(NoStoreAdminApi::class)->group(function () {
|
| block — the one that already holds /stats, /products, /orders and the rest —
| directly after the three order routes near the end of that group:
|
|     require __DIR__ . '/manual-orders-admin.php';
|
| That group sits inside `Route::middleware('auth:admin')->group(...)`, so
| requiring the file THERE and nowhere else is what gives every route below:
|
|   * the auth:admin guard. These endpoints return customer names, emails,
|     phone numbers and street addresses, and they create real orders. There
|     is no per-route authorisation in AdminOrderController — it relies
|     entirely on being inside that group. /api/* is unauthenticated, so
|     requiring this from routes/api.php would publish the customer list.
|   * the /admin-api prefix, which is why no path below repeats it.
|   * NoStoreAdminApi, so shared hosting cannot serve a cached copy of a
|     customer search or, worse, of a just-created order.
|
| The paths are shaped as /manual-orders/... rather than hanging off the
| existing /orders prefix on purpose: /orders/{id} is already taken by
| AdminController::order, and /orders/new would be captured by that route's
| {id} placeholder ahead of anything declared later. A separate noun avoids
| that collision instead of depending on declaration order to break the tie.
|
| Routes are matched in declaration order, so the two literal paths below come
| before the one with a parameter.
|
| Compiled route cache: this file adds routes, so the package that ships it
| also ships database/migrations/2026_09_15_090000_clear_caches_manual_orders.php.
| Without that, the server keeps serving the old compiled route table and every
| path here 404s while looking perfectly correct in the repo.
|
*/

use App\Http\Controllers\Admin\AdminOrderController;
use Illuminate\Support\Facades\Route;

// Form vocabularies: statuses, payment methods, delivery countries, whether a
// confirmation email can be sent at all.
Route::get('/manual-orders/bootstrap', [AdminOrderController::class, 'bootstrap']);

// Pick an existing customer, or search the catalogue for a line item.
Route::get('/manual-orders/customers', [AdminOrderController::class, 'customers']);
Route::get('/manual-orders/products', [AdminOrderController::class, 'products']);

// Price the basket without saving. Fired on every change to a line, the
// destination or the coupon.
Route::post('/manual-orders/quote', [AdminOrderController::class, 'quote']);

// Create the order.
Route::post('/manual-orders', [AdminOrderController::class, 'store']);

// The packing list the inventory team works from.
Route::get('/manual-orders/{order}/packing-list.csv', [AdminOrderController::class, 'packingList'])
    ->whereNumber('order');
