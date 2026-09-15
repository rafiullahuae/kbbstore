<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Manual orders — Store → New Order
|------------------------------------------------------------------------------
|
| INTEGRATOR: add exactly one line to routes/web.php, INSIDE the existing
|
|     Route::middleware(['web', 'auth:admin', NoStoreAdminApi::class])
|         ->prefix('admin-api')
|         ->group(function () { ... });
|
| block — the one that already mounts routes/orders-admin.php and
| routes/customers-admin.php — beside those requires:
|
|     require __DIR__ . '/manual-orders-admin.php';
|
| Mounting it THERE and nowhere else is what gives every route below:
|
|   * the web + auth:admin guard. These endpoints return customer names,
|     emails, phone numbers and street addresses, and they create real orders
|     against real money. There is no per-route authorisation in
|     AdminOrderController — it relies entirely on being inside that group.
|     /api/* is unauthenticated, so mounting this from routes/api.php would
|     publish the customer list.
|
|     ONE middleware() CALL, NOT TWO. RouteRegistrar::middleware() REPLACES the
|     pending middleware rather than appending, so ->middleware('web')
|     ->middleware('auth:admin') registers routes carrying auth:admin and NOT
|     web — or, reversed, no guard at all while reading as though it had one.
|   * the /admin-api prefix, which is why no path below repeats it.
|   * NoStoreAdminApi, so shared hosting cannot serve a cached copy of a
|     customer search or, worse, of a just-created order.
|
| The paths are shaped as /manual-orders/... rather than hanging off the
| existing /orders prefix on purpose: /orders/{id} is already taken, and
| /orders/new would be captured by that route's {id} placeholder ahead of
| anything declared later. A separate noun avoids the collision instead of
| depending on declaration order to break the tie.
|
| Routes are matched in declaration order, so the literal paths below come
| before any that take a parameter.
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

// The packer's document is the store's existing packing slip
// (routes/invoices-admin.php), not a second one from this lane.
