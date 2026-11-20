<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AdsAutopilotApiController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Growth & Marketing -> Ads Autopilot  (Lane AP-API)
|------------------------------------------------------------------------------
|
| Required from routes/web.php inside the `admin-api` group (web, auth:admin,
| NoStoreAdminApi, EnforceAdminCapability), e.g. beside
| `require __DIR__.'/cod-refusals-admin.php';`.
|
|   GET  ads-autopilot                    autopilot.view
|   POST ads-autopilot/keys               autopilot.manage
|   POST ads-autopilot/keys/{id}/revoke   autopilot.manage
|   POST ads-autopilot/measurement        autopilot.manage
|   GET  ads-autopilot/costs              autopilot.view
|   POST ads-autopilot/costs              autopilot.manage
|
| Both capabilities owner only (App\Support\AdminCapabilities).
*/

Route::get('/ads-autopilot', [AdsAutopilotApiController::class, 'index']);
Route::post('/ads-autopilot/keys', [AdsAutopilotApiController::class, 'createKey']);
Route::post('/ads-autopilot/keys/{id}/revoke', [AdsAutopilotApiController::class, 'revokeKey'])->whereNumber('id');
Route::post('/ads-autopilot/measurement', [AdsAutopilotApiController::class, 'saveMeasurement']);
Route::get('/ads-autopilot/costs', [AdsAutopilotApiController::class, 'costs']);
Route::post('/ads-autopilot/costs', [AdsAutopilotApiController::class, 'saveCosts']);
