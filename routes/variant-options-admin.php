<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Product editor -> Options (a variable product's shades and sizes) -- Lane VPA
|------------------------------------------------------------------------------
|
| NOT YET LOADED: CLAUDE.md forbids lanes from editing routes/web.php. The
| integrator wires it with ONE line inside the existing admin-api group (the
| `Route::prefix('admin-api')->middleware(NoStoreAdminApi::class)` group inside
| `Route::middleware('auth:admin')`), next to the product editor's own require:
|
|     require __DIR__.'/product-editor-admin.php';
|     require __DIR__.'/variant-options-admin.php';   // Lane VPA: Options panel
|
| It must be inside that group: these rows create, reprice and delete what a
| shopper can buy. Capabilities (App\Support\AdminCapabilities):
|
|     GET /admin-api/product-variants/{id}   catalog.view
|     PUT /admin-api/product-variants/{id}   catalog.variants
|
| 2027_11_13_100100_clear_caches_variant_options clears the compiled route
| table so the new paths answer the moment the package is applied.
*/

use App\Http\Controllers\Admin\ProductVariantsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/product-variants/{id}', [ProductVariantsApiController::class, 'show'])->whereNumber('id');
Route::put('/product-variants/{id}', [ProductVariantsApiController::class, 'update'])->whereNumber('id');
