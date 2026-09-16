<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Brands admin API — Lane E
|------------------------------------------------------------------------------
|
| LOADED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so
| the integrator wired it up. One line, inside the EXISTING admin-api group in
| routes/web.php — the one opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(...)
|
| which itself sits inside `Route::middleware('auth:admin')`. Put it beside the
| payments-admin require, at around line 284:
|
|     require __DIR__ . '/brands-admin.php';
|
| It must go inside that group. These routes create, rename and delete brands
| and write a logo URL that every storefront page can render; mounted anywhere
| else they would be a public endpoint for editing the catalogue.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| Resulting paths:
|
|     GET    /admin-api/brands
|     POST   /admin-api/brands
|     PUT    /admin-api/brands/{brand}
|     DELETE /admin-api/brands/{brand}
|
| Logo images are NOT uploaded here. The screen posts the file to the existing
| /admin-api/media/upload (Admin\MediaUploadController) and sends the URL it
| returns as the `logo` field, so there is exactly one upload path in the app
| and one place where the type, size and SVG rules live.
|
*/

use App\Http\Controllers\Admin\BrandsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/brands', [BrandsApiController::class, 'index']);
Route::post('/brands', [BrandsApiController::class, 'store']);
Route::put('/brands/{brand}', [BrandsApiController::class, 'update'])->where('brand', '[0-9]+');
Route::delete('/brands/{brand}', [BrandsApiController::class, 'destroy'])->where('brand', '[0-9]+');
