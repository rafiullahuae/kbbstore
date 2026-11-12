<?php

/*
|--------------------------------------------------------------------------
| Catalog -> Best Sellers (2.60.661)
|--------------------------------------------------------------------------
|
| The /best-sellers/ list (the last three months' sellers, RecentBestSellers)
| as a grid: drag to reorder, the cross to remove, Save, Reset.
|
|     GET  /admin-api/best-sellers         the grid
|     POST /admin-api/best-sellers/save    {order: [ids], excluded: [ids]}
|     POST /admin-api/best-sellers/reset   back to the plain ranking
|
| Capability `catalog.best_sellers` (AdminCapabilities). Ships with
| database/migrations/2028_01_22_100000_clear_caches_best_sellers_admin.php.
*/

declare(strict_types=1);

use App\Http\Controllers\Admin\BestSellersApiController;
use Illuminate\Support\Facades\Route;

Route::get('/best-sellers', [BestSellersApiController::class, 'grid'])->name('admin.best-sellers');

Route::middleware('throttle:30,1,best-sellers')->group(function () {
    Route::post('/best-sellers/save', [BestSellersApiController::class, 'save'])->name('admin.best-sellers.save');
    Route::post('/best-sellers/reset', [BestSellersApiController::class, 'reset'])->name('admin.best-sellers.reset');
});
