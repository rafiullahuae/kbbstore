<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\FreeGiftsController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Free gifts  (Lane FG)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the guarded `admin-api` group -- `web`,
| `auth:admin`, NoStoreAdminApi, EnforceAdminCapability -- beside
| push-admin.php:
|
|     require __DIR__.'/free-gifts-admin.php';
|
| Paths (all under /admin-api) and their capability (AdminCapabilities::RULES):
|
|   freegifts.view    GET  free-gifts, free-gifts/products
|   freegifts.manage  POST free-gifts/switch, free-gifts/settings,
|                     POST free-gifts/ranges, free-gifts/ranges/order,
|                     PUT/DELETE free-gifts/ranges/{id},
|                     POST free-gifts/products, free-gifts/products/{id}/gift-only
|
| Fails closed: a verb not listed is manage. Nothing here is on /api/*.
| Shipped with 2027_11_22_100200_clear_caches_free_gifts.php.
*/

Route::prefix('free-gifts')->group(function () {
    Route::get('/', [FreeGiftsController::class, 'show']);
    Route::get('/products', [FreeGiftsController::class, 'products'])->middleware('throttle:60,1,free-gifts-search');

    Route::post('/switch', [FreeGiftsController::class, 'switch']);
    Route::post('/settings', [FreeGiftsController::class, 'settings']);
    Route::post('/ranges', [FreeGiftsController::class, 'saveRange']);
    Route::post('/ranges/order', [FreeGiftsController::class, 'order']);
    Route::put('/ranges/{id}', [FreeGiftsController::class, 'saveRange'])->whereNumber('id');
    Route::delete('/ranges/{id}', [FreeGiftsController::class, 'deleteRange'])->whereNumber('id');
    Route::post('/products', [FreeGiftsController::class, 'createProduct']);
    Route::post('/products/{id}/gift-only', [FreeGiftsController::class, 'giftOnly'])->whereNumber('id');
});
