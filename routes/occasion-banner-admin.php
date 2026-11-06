<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\OccasionBannerController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Appearance -> Occasion banner  (Lane OHB)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the guarded `admin-api` group -- `web`,
| `auth:admin`, NoStoreAdminApi, EnforceAdminCapability -- beside
| homepage-hub-admin.php:
|
|     require __DIR__.'/occasion-banner-admin.php';
|
| Paths (all under /admin-api) and their capability (AdminCapabilities::RULES):
|
|   occasionbanner.view    GET  occasion-banner, occasion-banner/products,
|                               occasion-banner/suggest
|   occasionbanner.manage  POST occasion-banner, occasion-banner/cutout
|
| Shipped with 2028_01_10_100100_clear_caches_occasion_banner.php.
*/

Route::prefix('occasion-banner')->group(function () {
    Route::get('/', [OccasionBannerController::class, 'show']);
    Route::get('/products', [OccasionBannerController::class, 'products'])->middleware('throttle:60,1,occasion-banner-search');
    Route::get('/suggest', [OccasionBannerController::class, 'suggest'])->middleware('throttle:30,1,occasion-banner-suggest');
    Route::post('/', [OccasionBannerController::class, 'save']);
    Route::post('/cutout', [OccasionBannerController::class, 'cutout'])->middleware('throttle:120,1,occasion-banner-cutout');
});
