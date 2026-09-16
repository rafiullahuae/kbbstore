<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog admin API — Categories and Attributes — Lane N
|------------------------------------------------------------------------------
|
| LOADED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so
| the integrator wired it up. One line, inside the EXISTING admin-api group in
| routes/web.php — the one opened at line 233 by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group opened at
| line 148. Put it immediately after the brands require, which is line 288:
|
|     // Category and attribute CRUD — the last two Catalog tabs that were
|     // hard-coded previews. Same group as brands, for the same reason.
|     require __DIR__.'/catalog-admin.php';
|
| It must go inside that group. These routes create, rename, re-parent and
| delete categories and attributes, and the delete endpoints detach products
| and variants from them. Mounted anywhere else they are a public endpoint for
| rearranging the shop — anyone could empty an archive page or strip every
| variant of the term that defines it.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| Resulting paths:
|
|     GET    /admin-api/categories
|     POST   /admin-api/categories
|     POST   /admin-api/categories/reorder
|     PUT    /admin-api/categories/{category}
|     DELETE /admin-api/categories/{category}
|
|     GET    /admin-api/attributes
|     POST   /admin-api/attributes
|     PUT    /admin-api/attributes/{attribute}
|     DELETE /admin-api/attributes/{attribute}
|     POST   /admin-api/attributes/{attribute}/values
|     PUT    /admin-api/attributes/{attribute}/values/{value}
|     DELETE /admin-api/attributes/{attribute}/values/{value}
|
| /categories/reorder is registered BEFORE /categories/{category}, and the id
| placeholders are constrained to digits. Either one alone would be enough;
| both are here because a POST to /categories/reorder matching the {category}
| route instead would be a 404 that looks like the reorder feature was never
| shipped.
|
| Images — the category image and a term's swatch image — are NOT uploaded
| here. The screen posts the file to the existing /admin-api/media/upload
| (Admin\MediaUploadController) and sends the URL it returns, so there is
| exactly one upload path in the app and one place where the type, size and SVG
| rules live. That is the same arrangement routes/brands-admin.php describes.
|
*/

use App\Http\Controllers\Admin\AttributesApiController;
use App\Http\Controllers\Admin\CategoriesApiController;
use Illuminate\Support\Facades\Route;

Route::get('/categories', [CategoriesApiController::class, 'index']);
Route::post('/categories', [CategoriesApiController::class, 'store']);
Route::post('/categories/reorder', [CategoriesApiController::class, 'reorder']);
Route::put('/categories/{category}', [CategoriesApiController::class, 'update'])->where('category', '[0-9]+');
Route::delete('/categories/{category}', [CategoriesApiController::class, 'destroy'])->where('category', '[0-9]+');

Route::get('/attributes', [AttributesApiController::class, 'index']);
Route::post('/attributes', [AttributesApiController::class, 'store']);
Route::put('/attributes/{attribute}', [AttributesApiController::class, 'update'])->where('attribute', '[0-9]+');
Route::delete('/attributes/{attribute}', [AttributesApiController::class, 'destroy'])->where('attribute', '[0-9]+');

Route::post('/attributes/{attribute}/values', [AttributesApiController::class, 'storeValue'])
    ->where('attribute', '[0-9]+');
Route::put('/attributes/{attribute}/values/{value}', [AttributesApiController::class, 'updateValue'])
    ->where('attribute', '[0-9]+')->where('value', '[0-9]+');
Route::delete('/attributes/{attribute}/values/{value}', [AttributesApiController::class, 'destroyValue'])
    ->where('attribute', '[0-9]+')->where('value', '[0-9]+');
