<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Translate & categorise. (Lane LT)
|------------------------------------------------------------------------------
|
| Screen: resources/views/admin/partials/recent-localise-screen.blade.php.
| Controller: App\Http\Controllers\Admin\RecentLocaliseApiController.
|
| INTEGRATOR — ONE LINE, INSIDE the admin-api group in routes/web.php, beside
| `require __DIR__.'/concern-sync-admin.php';`:
|
|     require __DIR__.'/recent-localise-admin.php';
|
| Capability `catalog.localise` (owner, manager) via AdminCapabilities::RULES
| for every path under admin-api/recent-localise; anything else is a 403. The
| POSTs that can spend the owner's Claude key share the named throttle bucket
| `recent-localise`; inline edits have their own, roomier one. The package
| ships 2027_12_24_100000_clear_caches_recent_localise.php so the compiled
| route table picks these up.
*/

use App\Http\Controllers\Admin\RecentLocaliseApiController;
use Illuminate\Support\Facades\Route;

Route::get('/recent-localise', [RecentLocaliseApiController::class, 'show'])->name('admin.recent-localise');
Route::get('/recent-localise/scan', [RecentLocaliseApiController::class, 'scan'])->name('admin.recent-localise.scan');
Route::post('/recent-localise/review', [RecentLocaliseApiController::class, 'review'])->name('admin.recent-localise.review');

Route::middleware('throttle:30,1,recent-localise')->group(function () {
    Route::post('/recent-localise/run', [RecentLocaliseApiController::class, 'run'])->name('admin.recent-localise.run');
    Route::post('/recent-localise/retry', [RecentLocaliseApiController::class, 'retry'])->name('admin.recent-localise.retry');
    Route::post('/recent-localise/approve', [RecentLocaliseApiController::class, 'approve'])->name('admin.recent-localise.approve');
    Route::post('/recent-localise/concerns/suggest', [RecentLocaliseApiController::class, 'suggest'])->name('admin.recent-localise.suggest');
    Route::post('/recent-localise/concerns/apply', [RecentLocaliseApiController::class, 'applyConcerns'])->name('admin.recent-localise.concerns-apply');
});

Route::middleware('throttle:120,1,recent-localise-edit')->group(function () {
    Route::post('/recent-localise/save', [RecentLocaliseApiController::class, 'save'])->name('admin.recent-localise.save');
});
