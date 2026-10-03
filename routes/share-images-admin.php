<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Product page → Share · Link preview card → "Make all share
| pictures now"                                                     (2.60.367)
|------------------------------------------------------------------------------
|
| One POST, inside the admin-api group in routes/web.php (`web`, `auth:admin`,
| NoStoreAdminApi). App\Http\Controllers\Admin\ShareImagesApiController carries
| the reasoning; its capability is `shareimages.make`.
|
|     POST /admin-api/share-images   body: after=<last product id done, or 0>
|
*/

use App\Http\Controllers\Admin\ShareImagesApiController;
use Illuminate\Support\Facades\Route;

Route::post('/share-images', [ShareImagesApiController::class, 'run']);
