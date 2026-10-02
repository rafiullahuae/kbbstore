<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Orders -> (an order)  (Lane PU)
|------------------------------------------------------------------------------
|
| Mounted by routes/web.php with ONE line, inside the EXISTING admin-api group
| (the one carrying auth:admin and NoStoreAdminApi), beside the orders-admin.php
| require (integrator, 208e735):
|
|     require __DIR__.'/order-detail-admin.php';
|
| THAT GROUP AND NOTHING ELSE: every route here reads or writes an order --
| buyer names, emails, what they paid -- and /api/* is unauthenticated by
| design.
|
| Routes added (each its own row in App\Support\AdminCapabilities):
|
|     GET  /admin-api/orders/{id}/customer-orders  the "Order history" popup  orders.view
|     POST /admin-api/orders/{id}/mark-paid        "Mark as paid"             orders.payment
|     PUT  /admin-api/orders/{id}/customer         change the customer        orders.customer
|     GET  /admin-api/order-customer-search        its picker                 orders.customer
|
| No collision with the unconstrained GET /orders/{id}: every path above is
| either two segments below {id} or flat (order-customer-search), and `{id}`
| never matches a slash.
|
| A clear_caches_* migration ships with this package
| (2026_12_02_000000_clear_caches_order_detail_payment.php): without it the
| compiled route cache on the live host knows none of these paths and every
| control below 404s while the screen renders perfectly.
*/

use App\Http\Controllers\Admin\OrderDetailController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}/customer-orders', [OrderDetailController::class, 'customerOrders'])->whereNumber('id');
Route::post('/orders/{id}/mark-paid', [OrderDetailController::class, 'markPaid'])->whereNumber('id');
Route::put('/orders/{id}/customer', [OrderDetailController::class, 'updateCustomer'])->whereNumber('id');
Route::get('/order-customer-search', [OrderDetailController::class, 'customerSearch']);
