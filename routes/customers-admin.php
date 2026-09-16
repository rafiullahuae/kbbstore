<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Customers  (Lane T)
|------------------------------------------------------------------------------
|
| WIRED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so the
| file shipped unmounted and the integrator added ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/customers-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Everything below reads and writes personal data
| for every shopper the store has — name, email, phone, home city, order history
| — and /customers/export hands the whole filtered list over as a file in one
| request. Mounted outside `auth:admin` this is a customer-database leak with a
| download button on it, and /api/* in this app is unauthenticated by design, so
| it must never be mounted there. AdminCustomersTest asserts the refusal on
| every route below, mounted exactly as this header describes.
|
| Routes added:
|
|     GET    /admin-api/customers/list          the paginated, filtered list
|     GET    /admin-api/customers/export        CSV of the CURRENT filtered view
|     GET    /admin-api/customers/{id}          one customer: orders, addresses
|     POST   /admin-api/customers/{id}/note     the owner's private note
|     POST   /admin-api/customers/{id}/restore  undo a trashing
|     POST   /admin-api/customers/bulk-delete   trash a selection (guarded)
|     DELETE /admin-api/customers/{id}          trash one (guarded)
|
| WHY /list AND NOT /customers. routes/web.php already registers
| `GET /admin-api/customers` → AdminController@customers, the old unpaginated
| endpoint this screen replaces. Laravel matches the first route registered, so
| a second `GET admin-api/customers` here would silently never be reached and
| the new screen would keep rendering the old broken payload. Naming the list
| endpoint /customers/list means these coexist with no ordering hazard and no
| edit to a file this lane does not own.
|
| The old route is now dead code — nothing in the admin console calls it — and
| can be deleted from routes/web.php whenever the integrator likes, together
| with AdminController::customers(). It is left alone here deliberately: it is
| admin-gated, so leaving it costs nothing but a method, and removing a route
| someone else may still be pointing at is not this lane's call to make.
|
| The literal segments are registered before the {id} route so that /list and
| /export are never swallowed by it; whereNumber() on {id} makes that explicit
| rather than order-dependent.
|
*/

use App\Http\Controllers\Admin\CustomersApiController;
use Illuminate\Support\Facades\Route;

Route::get('/customers/list', [CustomersApiController::class, 'index']);
Route::get('/customers/export', [CustomersApiController::class, 'export']);

Route::post('/customers/bulk-delete', [CustomersApiController::class, 'bulkDestroy']);

Route::get('/customers/{id}', [CustomersApiController::class, 'show'])->whereNumber('id');
Route::post('/customers/{id}/note', [CustomersApiController::class, 'saveNote'])->whereNumber('id');
Route::post('/customers/{id}/restore', [CustomersApiController::class, 'restore'])->whereNumber('id');
Route::delete('/customers/{id}', [CustomersApiController::class, 'destroy'])->whereNumber('id');
