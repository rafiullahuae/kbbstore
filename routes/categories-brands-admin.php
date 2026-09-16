<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Categories & Brands merchandising screen — Lane AQ
|------------------------------------------------------------------------------
|
| LOADED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so
| the integrator wired it up.
|
| INTEGRATOR — ONE LINE, and it must go INSIDE the existing admin-api group in
| routes/web.php: the group opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside `Route::middleware('auth:admin')`. Put it directly
| after the catalog-admin require (currently line 357):
|
|     // Store → Catalog → Categories & Brands: merge, redirects and the brand
|     // tree the merchandising screen needs. Same guarded group as the
|     // categories require above, for the same reason.
|     require __DIR__.'/categories-brands-admin.php';
|
| WHY THE GROUP MATTERS. /categories/{id}/merge folds one category into another
| and DELETES the source. /categories/redirects rewrites where indexed URLs
| point. Mounted outside auth:admin these are a public endpoint for dissolving
| the shop's navigation and hijacking its indexed URLs. Not in routes/api.php
| for the same reason — CLAUDE.md: "/api/* is unauthenticated".
|
| Resulting paths:
|
|     POST   /admin-api/categories/{category}/merge
|     GET    /admin-api/categories/redirects
|     DELETE /admin-api/categories/redirects/{redirect}
|
|     GET    /admin-api/brands-tree
|     POST   /admin-api/brands-tree/reorder
|
| NOTE ON ORDERING AND SHADOWING. routes/catalog-admin.php registers
| PUT/DELETE /categories/{category} constrained to [0-9]+. This file's
| /categories/redirects would be swallowed by a {category} route without that
| constraint, so the constraint is load-bearing, not decoration — and
| /categories/redirects is registered here with a literal segment that cannot
| match [0-9]+ either way. Both belts, same trousers, deliberately: this is the
| exact shape that made /orders/list unreachable behind /orders/{id}.
|
| The brand endpoints are named `brands-tree`, NOT nested under /brands, because
| routes/brands-admin.php already registers PUT/DELETE /brands/{brand} and a
| nested /brands/reorder would be decided by whichever require lands first.
| Flat, distinct names cannot collide however the integrator orders these lines.
|
| Images go through the existing /admin-api/media/upload, as everywhere else in
| this admin. No second upload path.
|
*/

use App\Http\Controllers\Admin\BrandsTreeApiController;
use App\Http\Controllers\Admin\CategoriesApiController;
use App\Http\Controllers\Admin\CategoryRedirectsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/categories/redirects', [CategoryRedirectsApiController::class, 'index']);
Route::delete('/categories/redirects/{redirect}', [CategoryRedirectsApiController::class, 'destroy'])
    ->where('redirect', '[0-9]+');

Route::post('/categories/{category}/merge', [CategoriesApiController::class, 'merge'])
    ->where('category', '[0-9]+');

Route::get('/brands-tree', [BrandsTreeApiController::class, 'index']);
Route::post('/brands-tree/reorder', [BrandsTreeApiController::class, 'reorder']);
