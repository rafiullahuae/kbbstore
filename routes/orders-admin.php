<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Orders  (Lane V)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so the
| file ships unmounted and the integrator adds ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` (opened at line 174) and NoStoreAdminApi (opened at line 233) —
| beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/orders-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Every route below reads or writes order records:
| buyer names, emails, phones, cities, what they paid and what was refunded, and
| /orders-export hands the whole filtered list over as a file in one request.
| /api/* in this app is unauthenticated by design, so mounting this there would
| be a customer-database leak with a download button on it. The bulk routes
| additionally CHANGE order status and trash orders, which moves the store's own
| revenue figures. AdminOrdersTest asserts the refusal on every route below,
| mounted exactly as this header describes.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_22_000000_clear_caches_admin_orders.php). Without it the compiled
| route cache on the live host knows none of these paths, and the failure is the
| quiet kind: the Orders screen renders perfectly and every request 404s.
|
| Routes added:
|
|     GET  /admin-api/orders-list          the paginated, filtered, sorted list
|     GET  /admin-api/orders-export        CSV of the CURRENT filtered view
|     POST /admin-api/orders-bulk-status   set a status on a selection (guarded)
|     POST /admin-api/orders-bulk-delete   trash a selection (guarded)
|     POST /admin-api/orders-bulk-restore  undo a trashing for a selection
|
| WHY THESE PATHS ARE FLAT, AND NOT /orders/list.
|
| routes/web.php line 432 registers
|
|     Route::get('/orders/{id}', [AdminController::class, 'order']);
|
| with NO constraint on {id}. A route named `/orders/list` therefore matches
| that pattern, and which of the two answers a request is decided purely by
| which was REGISTERED first. Mounted below line 432 the list endpoint is
| swallowed whole — AdminController::order('list') is reached instead and dies
| with a TypeError, because its signature is `int $id`. Mounted above it, it
| works. That is a screen whose correctness depends on where in a 500-line file
| somebody pasted a require line, and nothing about the symptom points at the
| cause. Store → Customers got away with `/customers/list` only because web.php
| happens to have no `/customers/{id}` route at all.
|
| Flat names cannot collide with `/orders/{id}` or with `/orders/{id}/anything`
| whatever order the requires end up in, so the integrator has one fewer way to
| ship an inert package. AdminOrdersTest pins that none of these routes contains
| an `{id}` segment.
|
| `GET /admin-api/orders` → AdminController@orders, the old unpaginated endpoint
| this screen replaces, is likewise untouched and simply no longer read.
|
| WHAT IS DELIBERATELY ABSENT. There is NO order-detail route here and no
| capture, refund, note, item or single-order status route. All of those already
| exist and work:
|
|     GET    /admin-api/orders/{id}/detail      AdminOrderController@show
|     POST   /admin-api/orders/{id}/refund      AdminOrderController@refund
|     POST   /admin-api/orders/{id}/notes       AdminOrderController@addNote
|     DELETE /admin-api/orders/{id}             AdminOrderController@trash
|     POST   /admin-api/orders/{id}/restore     AdminOrderController@restore
|     PUT    /admin-api/orders/{id}/status      AdminController@updateOrderStatus
|     GET    /admin-api/orders/{id}/settlement  PaymentSettlementController@show
|     POST   /admin-api/orders/{id}/capture     PaymentSettlementController@capture
|
| The list's View button opens that existing detail screen. A second detail
| endpoint would be a second definition of what an order is, and a second way to
| move money; the whole point of the settlement work is that there is one.
|
| Every path below is a single literal segment, so none of them can be swallowed
| by, or swallow, the existing /orders/{id} routes whichever order the integrator
| puts the require lines in.
|
*/

use App\Http\Controllers\Admin\OrdersApiController;
use Illuminate\Support\Facades\Route;

Route::get('/orders-list', [OrdersApiController::class, 'index']);
Route::get('/orders-export', [OrdersApiController::class, 'export']);

Route::post('/orders-bulk-status', [OrdersApiController::class, 'bulkStatus']);
Route::post('/orders-bulk-delete', [OrdersApiController::class, 'bulkDestroy']);
Route::post('/orders-bulk-restore', [OrdersApiController::class, 'bulkRestore']);
