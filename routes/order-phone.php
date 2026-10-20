<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| "Update my number" — add a UAE mobile to one order from an email (2.60.562)
|------------------------------------------------------------------------------
|
| Mounted by routes/web.php with ONE line, beside order-pay.php, at the
| storefront level (the session and CSRF of the `web` stack, no `auth`: the
| shopper is usually a guest):
|
|     require __DIR__.'/order-phone.php';
|
| GET  /order-phone   the page the "Ask for UAE number" email opens
| POST /order-phone   save the number
|
| Both take ?order=<number>&t=<signed token> (App\Support\OrderLinks, purpose
| `order.phone`, 14 days) and answer a forged, expired or other-order token with
| the same 404. Not under /api/*. Throttled.
|
| Shipped with 2027_11_15_100100_clear_caches_order_phone_request.php.
*/

use App\Http\Controllers\Store\OrderPhoneController;
use Illuminate\Support\Facades\Route;

Route::get('/order-phone', [OrderPhoneController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('order-phone');

Route::post('/order-phone', [OrderPhoneController::class, 'save'])
    ->middleware('throttle:10,1')
    ->name('order-phone.save');
