<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CodRefusalsApiController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Store → Payments → Cash on delivery → Refused deliveries  (Lane CODR)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the `admin-api` group (web, auth:admin,
| NoStoreAdminApi, EnforceAdminCapability). Every row is a customer's email and
| phone, so nothing here may ever be mounted under /api.
|
|   GET  cod-refusals              list + switch          codrefusals.view
|   POST cod-refusals/settings     switch on / off        codrefusals.manage
|   POST cod-refusals/{id}/allow   Allow COD again        codrefusals.manage
|
| Both capabilities owner only (App\Support\AdminCapabilities), the same as the
| Payments screen they sit on; any other role is refused before the controller.
*/

Route::get('/cod-refusals', [CodRefusalsApiController::class, 'index']);
Route::post('/cod-refusals/settings', [CodRefusalsApiController::class, 'saveSettings']);
Route::post('/cod-refusals/{id}/allow', [CodRefusalsApiController::class, 'allow'])->whereNumber('id');
