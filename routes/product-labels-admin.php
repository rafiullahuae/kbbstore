<?php

/*
|--------------------------------------------------------------------------
| Catalog -> Labels (Lane LBL)
|--------------------------------------------------------------------------
|
| The owner's own product labels and the rules that put them on products
| (App\Services\CustomLabels). Required from routes/web.php inside the guarded
| `admin-api` group, beside best-sellers-admin.php:
|
|     require __DIR__.'/product-labels-admin.php';   // Catalog -> Labels (Lane LBL)
|
|     GET    /admin-api/labels                  the list, with live product counts
|     GET    /admin-api/labels/index            the picker's search index (ETag)
|     GET    /admin-api/labels/{id}             one label and its rules
|     POST   /admin-api/labels                  create
|     PUT    /admin-api/labels/{id}             save
|     DELETE /admin-api/labels/{id}             delete
|     POST   /admin-api/labels/{id}/toggle      {active}
|     POST   /admin-api/labels/{id}/priority    {priority}
|     POST   /admin-api/labels/{id}/duplicate
|
| Capability `labels.manage` for every verb (AdminCapabilities), owner and
| manager; fails closed. Nothing here is on /api/*. Ships with
| database/migrations/2028_02_16_100100_clear_caches_product_labels.php.
*/

declare(strict_types=1);

use App\Http\Controllers\Admin\CustomLabelsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/labels', [CustomLabelsApiController::class, 'list'])->name('admin.labels');
Route::get('/labels/index', [CustomLabelsApiController::class, 'index'])->name('admin.labels.index');
Route::get('/labels/{id}', [CustomLabelsApiController::class, 'show'])->whereNumber('id')->name('admin.labels.show');

Route::middleware('throttle:60,1,custom-labels')->group(function () {
    Route::post('/labels', [CustomLabelsApiController::class, 'store'])->name('admin.labels.store');
    Route::put('/labels/{id}', [CustomLabelsApiController::class, 'update'])->whereNumber('id')->name('admin.labels.update');
    Route::delete('/labels/{id}', [CustomLabelsApiController::class, 'destroy'])->whereNumber('id')->name('admin.labels.destroy');
    Route::post('/labels/{id}/toggle', [CustomLabelsApiController::class, 'toggle'])->whereNumber('id')->name('admin.labels.toggle');
    Route::post('/labels/{id}/priority', [CustomLabelsApiController::class, 'priority'])->whereNumber('id')->name('admin.labels.priority');
    Route::post('/labels/{id}/duplicate', [CustomLabelsApiController::class, 'duplicate'])->whereNumber('id')->name('admin.labels.duplicate');
});
