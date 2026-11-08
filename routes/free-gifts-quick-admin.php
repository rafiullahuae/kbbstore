<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\FreeGiftsQuickController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Free gifts → Ranges & gifts → Gift-only products (Lane GP)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the guarded `admin-api` group, on the
| line after free-gifts-admin.php:
|
|     require __DIR__.'/free-gifts-quick-admin.php';
|
| Every path is under admin-api/free-gifts/**, so AdminCapabilities::RULES
| already maps it: GET -> freegifts.view, every other verb -> freegifts.manage.
| Fails closed. Nothing here is on /api/*.
| Shipped with 2028_01_14_100000_clear_caches_free_gifts_quick.php.
*/

Route::prefix('free-gifts/quick')->group(function () {
    Route::get('/', [FreeGiftsQuickController::class, 'index']);
    Route::post('/', [FreeGiftsQuickController::class, 'store']);
    Route::post('/{id}', [FreeGiftsQuickController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [FreeGiftsQuickController::class, 'destroy'])->whereNumber('id');
});
