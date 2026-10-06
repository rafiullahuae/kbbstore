<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| App -> Site App  (Lane PW)
|------------------------------------------------------------------------------
|
|     GET  /admin-api/site-app    on/off, the app name, the icon preview and
|                                 the public addresses (manifest, worker)
|     POST /admin-api/site-app    save {on, name}
|     POST /admin-api/site-app/icon        the owner's own app icon or favicon,
|                                          multipart {kind: app|favicon, file} (Lane IC)
|     POST /admin-api/site-app/icon/reset  {kind}: back to the shipped icon (Lane IC)
|     POST /admin-api/site-app/update      publish an update to installed apps,
|                                          {en?, ar?, icon?} (Lane UA)
|     POST /admin-api/site-app/update/show {show}: the Update App row on/off (Lane UA)
|
| INTEGRATOR: a `require` inside the guarded admin-api group, directly under
| whatsapp-button-admin.php (tools/pwa-wire.php writes it). That group carries
| `web`, `auth:admin` and NoStoreAdminApi.
|
| CAPABILITY `siteapp.manage`, owner and manager, its own, mapped in
| App\Support\AdminCapabilities::RULES, which fails closed. This switch
| decides whether a service worker runs on every shopper's phone. The icon
| endpoints are admin-api/site-app/**, the same capability (Lane IC); shipped
| with 2027_08_28_100000_clear_caches_app_icons. The update endpoints are
| admin-api/site-app/** too (Lane UA); shipped with
| 2027_09_03_100100_clear_caches_site_app_update.
*/

use App\Http\Controllers\Admin\SiteAppApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-app', [SiteAppApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.site-app');

Route::post('/site-app', [SiteAppApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.site-app.save');

Route::post('/site-app/icon', [\App\Http\Controllers\Admin\AppIconController::class, 'siteUpload'])
    ->middleware('throttle:20,1')
    ->name('admin.site-app.icon');

Route::post('/site-app/icon/reset', [\App\Http\Controllers\Admin\AppIconController::class, 'siteReset'])
    ->middleware('throttle:20,1')
    ->name('admin.site-app.icon.reset');

Route::post('/site-app/update', [SiteAppApiController::class, 'publishUpdate'])
    ->middleware('throttle:30,1')
    ->name('admin.site-app.update');

Route::post('/site-app/update/show', [SiteAppApiController::class, 'showUpdate'])
    ->middleware('throttle:30,1')
    ->name('admin.site-app.update.show');
