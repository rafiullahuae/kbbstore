<?php

declare(strict_types=1);

use App\Http\Controllers\AutopilotApi\ShopDataController;
use App\Http\Middleware\AutopilotApiAuth;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The KBB Ads Autopilot read-only API  (Lane AP-API)
|------------------------------------------------------------------------------
|
| For the owner's Mac app (studio/ads-autopilot, engine/shop "shop-api"). A
| machine with a connection key, not a browser: required from routes/web.php
| INSIDE the `Route::withoutMiddleware(SeoFilesController::STATELESS)` group,
| beside `require __DIR__.'/marketing-catalog-feeds.php';` -- no session, no
| cookie, no CSRF. Every route sits behind AutopilotApiAuth with its scope;
| the key is created in Growth & Marketing -> Ads Autopilot.
|
|   GET autopilot-api/v1/ping          orders | products
|   GET autopilot-api/v1/orders        orders
|   GET autopilot-api/v1/orders/daily  orders
|   GET autopilot-api/v1/refunds       orders
|   GET autopilot-api/v1/products      products
|
| Ships with 2028_02_20_100100_clear_caches_ads_autopilot.php.
*/

Route::prefix('autopilot-api/v1')->group(function () {
    Route::get('/ping', [ShopDataController::class, 'ping'])->middleware(AutopilotApiAuth::class . ':ping');
    Route::get('/orders', [ShopDataController::class, 'orders'])->middleware(AutopilotApiAuth::class . ':orders');
    Route::get('/orders/daily', [ShopDataController::class, 'daily'])->middleware(AutopilotApiAuth::class . ':orders');
    Route::get('/refunds', [ShopDataController::class, 'refunds'])->middleware(AutopilotApiAuth::class . ':orders');
    Route::get('/products', [ShopDataController::class, 'products'])->middleware(AutopilotApiAuth::class . ':products');
});
