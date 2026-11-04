<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\GiftOffersController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Gift offers  (Lane GO)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the guarded `admin-api` group -- `web`,
| `auth:admin`, NoStoreAdminApi, EnforceAdminCapability -- beside
| free-gifts-admin.php:
|
|     require __DIR__.'/gift-offers-admin.php';
|
| Paths (all under /admin-api) and their capability (AdminCapabilities::RULES):
|
|   giftoffers.view    GET  gift-offers, gift-offers/products, gift-offers/report
|                      POST gift-offers/preview  (renders the draft it is sent; stores nothing)
|   giftoffers.manage  POST gift-offers/settings, gift-offers/mode, gift-offers/testers,
|                           gift-offers/place, gift-offers/ranges, gift-offers/test
|                      PUT/DELETE gift-offers/ranges/{id}
|
| Fails closed: a verb not listed is manage. Nothing here is on /api/*.
| Shipped with 2028_01_07_100100_clear_caches_gift_offers.php.
*/

Route::prefix('gift-offers')->group(function () {
    Route::get('/', [GiftOffersController::class, 'show']);
    Route::get('/products', [GiftOffersController::class, 'products'])->middleware('throttle:60,1,gift-offers-search');
    Route::get('/report', [GiftOffersController::class, 'report']);
    Route::post('/preview', [GiftOffersController::class, 'preview'])->middleware('throttle:60,1,gift-offers-preview');

    Route::post('/settings', [GiftOffersController::class, 'settings']);
    Route::post('/mode', [GiftOffersController::class, 'mode']);
    Route::post('/testers', [GiftOffersController::class, 'testers']);
    Route::post('/place', [GiftOffersController::class, 'place']);
    Route::post('/ranges', [GiftOffersController::class, 'saveRange']);
    Route::put('/ranges/{id}', [GiftOffersController::class, 'saveRange'])->whereNumber('id');
    Route::delete('/ranges/{id}', [GiftOffersController::class, 'deleteRange'])->whereNumber('id');
    Route::post('/test', [GiftOffersController::class, 'test'])->middleware('throttle:10,1,gift-offers-test');
});
