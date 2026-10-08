<?php

/*
|--------------------------------------------------------------------------
| Catalog → Products → edit → replaced pictures (Lane RPL)
|--------------------------------------------------------------------------
|
| ONE route: Undo for a picture a save of this product took off the server.
| The clean-up itself has no route of its own -- it happens inside
| POST /admin-api/product-editor-save/{id}, the save of THAT product, and
| nowhere else (App\Services\Media\PictureTrash).
|
| WIRING, for the integrator: inside the admin-api group of routes/web.php,
| beside the product editor's own file --
|
|     require __DIR__.'/product-photo-admin.php';
|
| Capability: catalog.manage, the product edit capability, by its own explicit
| line in App\Support\AdminCapabilities::RULES (the `product-editor-*\/*`
| wildcard would give it the same answer). {id} is digits only.
*/

use App\Http\Controllers\Admin\ProductEditorApiController;
use Illuminate\Support\Facades\Route;

Route::post('/product-editor-photo-undo/{id}', [ProductEditorApiController::class, 'photoUndo'])
    ->whereNumber('id');
