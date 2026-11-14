<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Translate & categorise → Arabic spacing. (Lane ARDX)
|------------------------------------------------------------------------------
|
| Controller: App\Http\Controllers\Admin\ArabicStructureApiController.
| Screen: the "Arabic spacing" panel in
| resources/views/admin/partials/recent-localise-screen.blade.php.
|
| INTEGRATOR — ONE LINE, INSIDE the admin-api group in routes/web.php, beside
| `require __DIR__.'/recent-localise-admin.php';`:
|
|     require __DIR__.'/ar-structure-admin.php';
|
| Capability `catalog.ar_structure` (owner, manager) via AdminCapabilities::RULES
| for every path under admin-api/ar-structure; anything else is a 403. The
| package ships 2028_02_12_100100_clear_caches_ar_structure.php.
*/

use App\Http\Controllers\Admin\ArabicStructureApiController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1,ar-structure')->group(function () {
    Route::get('/ar-structure', [ArabicStructureApiController::class, 'show'])->name('admin.ar-structure');
    Route::post('/ar-structure/apply', [ArabicStructureApiController::class, 'apply'])->name('admin.ar-structure.apply');
    Route::post('/ar-structure/undo', [ArabicStructureApiController::class, 'undo'])->name('admin.ar-structure.undo');
});
