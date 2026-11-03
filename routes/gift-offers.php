<?php

declare(strict_types=1);

use App\Http\Controllers\Store\GiftOfferController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Marketing -> Gift offers, the shop's side  (Lane GO)
|------------------------------------------------------------------------------
|
| Required from routes/web.php beside order-pay.php:
|
|     require __DIR__.'/gift-offers.php';
|
|   GET  /gift-offer/c/{token}      an email's "Claim my free gift": claims, never signs in
|   GET  /gift-offer/t/{token}.gif  the email's moving clock (GD, cached per minute)
|   POST /gift-offer/popup          the exit popup's "Claim it now" (CSRF, the basket's own range)
|   POST /gift-offer/event          the popup was shown / dismissed (one beacon each)
|
| Each throttle has its own counter (2.60.514: an unnamed throttle shares one
| per IP with every other unnamed throttle). Shipped with
| 2028_01_07_100100_clear_caches_gift_offers.php.
*/

Route::get('/gift-offer/c/{token}', [GiftOfferController::class, 'claim'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->middleware('throttle:20,1,gift-offer-claim');

Route::get('/gift-offer/t/{token}.gif', [GiftOfferController::class, 'clock'])
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->middleware('throttle:120,1,gift-offer-clock');

Route::post('/gift-offer/popup', [GiftOfferController::class, 'popup'])
    ->middleware('throttle:10,1,gift-offer-popup');

Route::post('/gift-offer/event', [GiftOfferController::class, 'event'])
    ->middleware('throttle:20,1,gift-offer-event');
