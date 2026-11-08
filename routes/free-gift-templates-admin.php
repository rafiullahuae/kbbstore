<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\FreeGiftTemplatesController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing → Free gifts → Ranges & gifts → Templates (Lane GT)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the guarded `admin-api` group, on the
| line after free-gifts-quick-admin.php:
|
|     require __DIR__.'/free-gift-templates-admin.php';
|
| Every path is under admin-api/free-gifts/**, so AdminCapabilities::RULES
| already maps it: GET -> freegifts.view, every other verb -> freegifts.manage.
| Fails closed. Nothing here is on /api/*.
| Shipped with 2028_01_15_100000_create_free_gift_templates.php and
| 2028_01_15_100100_clear_caches_free_gift_templates.php.
*/

Route::prefix('free-gifts/templates')->group(function () {
    Route::get('/', [FreeGiftTemplatesController::class, 'index']);
    Route::post('/', [FreeGiftTemplatesController::class, 'store']);
    Route::put('/{id}', [FreeGiftTemplatesController::class, 'update'])->whereNumber('id');
    Route::post('/{id}/rename', [FreeGiftTemplatesController::class, 'rename'])->whereNumber('id');
    Route::post('/{id}/apply', [FreeGiftTemplatesController::class, 'apply'])->whereNumber('id');
    Route::delete('/{id}', [FreeGiftTemplatesController::class, 'destroy'])->whereNumber('id');
});
