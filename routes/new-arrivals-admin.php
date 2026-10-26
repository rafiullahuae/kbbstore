<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog -> New Arrivals (Lane NA)
|------------------------------------------------------------------------------
| Required from routes/web.php INSIDE the session-guarded admin-api group
| (Route::prefix('admin-api') within Route::middleware('auth:admin')), beside
| concern-sync-admin.php:
|
|     require __DIR__.'/new-arrivals-admin.php';
|
| Every path is mapped to `catalog.new_arrivals` in App\Support\AdminCapabilities
| (owner and manager), so any other role -- and any role added later -- gets
| 403: it fails closed. The writes are throttled; the reads are one request
| each (the grid only when Display is pressed).
*/

use App\Http\Controllers\Admin\NewArrivalsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/new-arrivals', [NewArrivalsApiController::class, 'show'])->name('admin.new-arrivals');
Route::get('/new-arrivals/grid', [NewArrivalsApiController::class, 'grid'])->name('admin.new-arrivals.grid');
Route::get('/new-arrivals/search', [NewArrivalsApiController::class, 'search'])
    ->middleware('throttle:60,1,new-arrivals-search')->name('admin.new-arrivals.search');

Route::middleware('throttle:30,1,new-arrivals')->group(function () {
    Route::post('/new-arrivals/settings', [NewArrivalsApiController::class, 'settings'])->name('admin.new-arrivals.settings');
    Route::post('/new-arrivals/save', [NewArrivalsApiController::class, 'save'])->name('admin.new-arrivals.save');
    Route::post('/new-arrivals/reset', [NewArrivalsApiController::class, 'reset'])->name('admin.new-arrivals.reset');
});
