<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Orders -> (an order) -> "Send order link"  (Lane OL)
|------------------------------------------------------------------------------
|
| Required from routes/order-detail-admin.php, so it sits inside the SAME
| admin-api group (auth:admin, NoStoreAdminApi, EnforceAdminCapability) that
| file is mounted in, and routes/web.php needs no new line.
|
|     POST /admin-api/orders/{id}/pay-link          orders.paylink
|     GET  /admin-api/order-pay-link-settings       orders.paylink.settings
|     PUT  /admin-api/order-pay-link-settings       orders.paylink.settings
|
| Each its own row in App\Support\AdminCapabilities; the controller checks
| again. Throttled: a link is minted and possibly emailed per press.
|
| Shipped with 2027_10_20_100000_clear_caches_order_pay_link.php: without it
| the compiled route cache on the live host does not know these paths.
*/

use App\Http\Controllers\Admin\OrderPayLinkController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{id}/pay-link', [OrderPayLinkController::class, 'share'])->whereNumber('id')->middleware('throttle:30,1');
Route::get('/order-pay-link-settings', [OrderPayLinkController::class, 'settings']);
Route::put('/order-pay-link-settings', [OrderPayLinkController::class, 'saveSettings'])->middleware('throttle:20,1');
