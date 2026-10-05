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
|
| INTEGRATOR: a `require` inside the guarded admin-api group, directly under
| whatsapp-button-admin.php (tools/pwa-wire.php writes it). That group carries
| `web`, `auth:admin` and NoStoreAdminApi.
|
| CAPABILITY `siteapp.manage`, owner and manager, its own, mapped in
| App\Support\AdminCapabilities::RULES, which fails closed. This switch
| decides whether a service worker runs on every shopper's phone.
*/

use App\Http\Controllers\Admin\SiteAppApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-app', [SiteAppApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.site-app');

Route::post('/site-app', [SiteAppApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.site-app.save');
