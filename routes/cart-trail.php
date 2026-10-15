<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Cart journey taps  (Lane CJ, 2.60.494)
|------------------------------------------------------------------------------
|
| The bag panel, the cart page and the checkout send their taps here in small
| batches through sendBeacon (resources/js/kbb/trail.js). Web group: it needs
| the basket cookie and the page's CSRF token. 60 a minute per address is far
| above what a person taps and still a ceiling for a script. Only a browser
| that holds a basket is recorded (App\Services\CartTracking\CartTrail).
| Required once from routes/web.php beside instant-nav.php.
*/

use Illuminate\Support\Facades\Route;

Route::post('/api/cart-trail', [\App\Http\Controllers\Store\CartTrailController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('cart.trail');

/*
| Lane CAP: the address typed into the checkout's email field, sent once when
| the field is left (resources/js/kbb/email-capture.js). Its OWN throttle
| bucket, named, like checkout-line.php's 'checkout-coupon': without the name
| it would share the cart-trail's per-address counter above. Twelve a minute is
| far beyond a person correcting a typo and a ceiling for a script; the
| per-cart cap and the bot rules are in App\Support\CheckoutEmailCapture.
*/
Route::post('/api/checkout-contact', [\App\Http\Controllers\Store\CheckoutEmailController::class, 'store'])
    ->middleware('throttle:12,1,checkout-email')
    ->name('checkout.email-capture');
