<?php

declare(strict_types=1);

use App\Http\Controllers\Store\CartGiftsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The live free gifts box  (Lane FGL)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the api/cart group (web middleware: the
| basket is the cookie's). GET /api/cart/gifts?s=<fingerprint> answers 204 when
| the shopper's box is still true, or the fresh box -- see CartGiftsController.
| Its own throttle counter (an unnamed one shares the address's single counter
| with every ping on the site; 2.60.514). cart.js asks at most every 30 s. A
| clear_caches migration ships with it so the compiled route cache learns it.
*/
Route::get('/gifts', CartGiftsController::class)->middleware('throttle:30,1,cart-gifts');
