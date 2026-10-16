<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Shop by Concern — Sync. (Lane SC)
|------------------------------------------------------------------------------
|
| Screen: resources/views/admin/partials/concern-sync-screen.blade.php.
| Controller: App\Http\Controllers\Admin\ConcernSyncApiController.
|
| INTEGRATOR — ONE LINE, INSIDE the admin-api group in routes/web.php (the
| group opened by Route::prefix('admin-api')->middleware(NoStoreAdminApi) and
| sitting inside Route::middleware('auth:admin')), beside the other lane files:
|
|     require __DIR__.'/concern-sync-admin.php';
|
| Capability `concerns.sync` (owner, manager) via AdminCapabilities::RULES for
| every path under admin-api/concern-sync; anything else is a 403. The POSTs
| share the named throttle bucket `concern-sync`, so they cannot spend the
| owner's Claude key in a loop and do not share a bucket with anything else.
| The package ships 2027_11_07_200100_clear_caches_lane_sc_concern_sync.php so
| the compiled route table picks these up.
*/

use App\Http\Controllers\Admin\ConcernSyncApiController;
use Illuminate\Support\Facades\Route;

Route::get('/concern-sync', [ConcernSyncApiController::class, 'show'])->name('admin.concern-sync');

Route::middleware('throttle:10,1,concern-sync')->group(function () {
    Route::post('/concern-sync/settings', [ConcernSyncApiController::class, 'settings'])->name('admin.concern-sync.settings');
    Route::post('/concern-sync/scan', [ConcernSyncApiController::class, 'scan'])->name('admin.concern-sync.scan');
    Route::post('/concern-sync/apply', [ConcernSyncApiController::class, 'apply'])->name('admin.concern-sync.apply');
});
