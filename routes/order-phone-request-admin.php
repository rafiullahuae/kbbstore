<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Orders -> (an order) -> Billing -> Phone -> "Ask for UAE number"
| (2.60.562)
|------------------------------------------------------------------------------
|
| Mounted by routes/web.php inside the admin-api group, beside
| order-pay-link-admin.php (auth:admin, NoStoreAdminApi,
| EnforceAdminCapability):
|
|     POST /admin-api/orders/{id}/phone-request     orders.manage
|
| Its own row in App\Support\AdminCapabilities; the controller checks again.
| Throttled per admin; the controller also refuses a second email for the same
| order inside ten minutes, and any repeat that does not say it is one.
|
| Shipped with 2027_11_15_100100_clear_caches_order_phone_request.php.
*/

use App\Http\Controllers\Admin\OrderPhoneRequestController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{id}/phone-request', [OrderPhoneRequestController::class, 'send'])->whereNumber('id')->middleware('throttle:20,1');
