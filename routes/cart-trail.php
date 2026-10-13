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
