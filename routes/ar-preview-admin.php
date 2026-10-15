<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Private Arabic preview link — Translation → Settings (Lane AR, stage 2)
|------------------------------------------------------------------------------
|
| FOR THE INTEGRATOR: require this file INSIDE the existing admin-api group (the
| one carrying `auth:admin` and NoStoreAdminApi), beside
| `require __DIR__.'/translations-admin.php';`, and BEFORE it, so these static
| segments are declared ahead of the bare /translations routes:
|
|     require __DIR__.'/ar-preview-admin.php';
|
| Resulting paths, each mapped to `translations.preview` (owner only) in
| AdminCapabilities -- anyone else gets EnforceAdminCapability's 403:
|
|     POST /admin-api/translations/preview-link          make a link (1-30 days)
|     POST /admin-api/translations/preview-link/revoke   turn off every link
|
| Ships with a clear_caches migration (2027_11_02_100200), because on this host a
| route that is not in the compiled table does not exist.
*/

use App\Http\Controllers\Admin\ArabicPreviewApiController;
use Illuminate\Support\Facades\Route;

Route::post('/translations/preview-link/revoke', [ArabicPreviewApiController::class, 'revoke'])
    ->middleware('throttle:12,1');
Route::post('/translations/preview-link', [ArabicPreviewApiController::class, 'create'])
    ->middleware('throttle:12,1');
