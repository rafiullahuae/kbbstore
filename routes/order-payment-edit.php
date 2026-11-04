<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store -> Orders -> (an order): payment method and coupon  (2.60.641)
|------------------------------------------------------------------------------
| Mounted by routes/web.php with ONE line, inside the admin-api group (auth:admin,
| NoStoreAdminApi, EnforceAdminCapability), beside order-detail-admin.php:
|     require __DIR__.'/order-payment-edit.php';
| Routes (each its own row in App\Support\AdminCapabilities):
|     POST   /admin-api/orders/{id}/payment-method   change the method   orders.payment
|     POST   /admin-api/orders/{id}/coupon           apply a coupon      orders.money
|     DELETE /admin-api/orders/{id}/coupon           remove it           orders.money
| Shipped with 2028_01_09_100100_clear_caches_order_payment_edit.php.
*/

use App\Http\Controllers\Admin\OrderPaymentEditController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{id}/payment-method', [OrderPaymentEditController::class, 'paymentMethod'])->whereNumber('id');
Route::post('/orders/{id}/coupon', [OrderPaymentEditController::class, 'applyCoupon'])->whereNumber('id');
Route::delete('/orders/{id}/coupon', [OrderPaymentEditController::class, 'removeCoupon'])->whereNumber('id');
