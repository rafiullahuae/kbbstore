<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| "Buy these together" — the storefront's two POSTs.               (Lane RB)
|--------------------------------------------------------------------------
|
| Required from routes/web.php at the STOREFRONT level (no admin group), next
| to the `api/cart` group, so they carry the web middleware — session, the
| cart cookie and CSRF — like every other cart write:
|
|     require __DIR__.'/buy-together.php';
|
|   POST /api/cart/add-together   every ticked product, one request
|                                 (Store\CartController::addTogether)
|   POST /api/product-view        one view, for the "Most viewed" rule
|                                 (Store\ProductViewController::store)
|
| Both are throttled. 20 a minute for the add is far above a shopper pressing
| one button and far below anything that could hammer the basket; 30 a minute
| for the view beacon, which a browser sends at most once per product per day.
|
| A package that ships this also ships a clear_caches_* migration, because a
| compiled route table does not see a new route until it is cleared.
*/

Route::post('/api/cart/add-together', [\App\Http\Controllers\Store\CartController::class, 'addTogether'])
    ->middleware('throttle:20,1')
    ->name('cart.add-together');

Route::post('/api/product-view', [\App\Http\Controllers\Store\ProductViewController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('product.view-beacon');
