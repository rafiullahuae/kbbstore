<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| "Complete your order" — pay for one unpaid order from an email (Lane RL)
|------------------------------------------------------------------------------
|
| Mounted by routes/web.php with ONE line, at the storefront level beside the
| `require __DIR__.'/checkout-return.php';` line (NOT inside any group — it
| needs the session and CSRF of the `web` stack and no `auth`, because the
| shopper is usually a guest):
|
|     require __DIR__.'/order-pay.php';
|
| GET  /checkout/order-pay   the page the reminder and payment-failed emails open
| POST /checkout/order-pay   start paying with the chosen method
|
| Both take ?order=<number>&t=<signed token> (App\Support\OrderLinks, purpose
| `order.pay`, 7 days) and answer a forged, expired or other-order token with
| the same 404. Nothing here is under /api/*. Throttled because the POST opens
| a payment session at a provider.
|
| A clear_caches_* migration ships with it
| (2027_07_25_000200_clear_caches_order_emails_rl.php): without it the
| compiled route cache on the live host does not know these paths and every
| "Complete your order" button 404s.
*/

use App\Http\Controllers\Store\OrderPayController;
use Illuminate\Support\Facades\Route;

Route::get('/checkout/order-pay', [OrderPayController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('checkout.order-pay');

Route::post('/checkout/order-pay', [OrderPayController::class, 'start'])
    ->middleware('throttle:20,1')
    ->name('checkout.order-pay.start');
