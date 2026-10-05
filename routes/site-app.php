<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The shop as a Home Screen app  (Lane PW, App -> Site App)
|------------------------------------------------------------------------------
|
|     GET /manifest.webmanifest      the web app manifest (/ar/... in Arabic,
|                                    via the global SetLocaleFromPath)
|     GET /sw.js                     the service worker, scope '/'
|     GET /site-app.js               registers it, after load
|     GET /site-app/icons/{name}.png the four icons, by allowlisted name
|     GET /offline                   the page shown when the network fails
|
| INTEGRATOR: a `require` INSIDE the stateless group at the top of
| routes/web.php, directly under wallet-domain.php (tools/pwa-wire.php writes
| it). That group drops the cookie and session middleware
| (SeoFilesController::STATELESS), so these public files never carry a
| Set-Cookie, and it sits above every catch-all route.
|
| Ships with a clear_caches migration: a route added to a cached route table
| does not exist until the cache is cleared.
*/

use App\Http\Controllers\Store\SiteAppController;
use Illuminate\Support\Facades\Route;

Route::get('/manifest.webmanifest', [SiteAppController::class, 'manifest'])->name('site-app.manifest');
Route::get('/sw.js', [SiteAppController::class, 'worker'])->name('site-app.worker');
Route::get('/site-app.js', [SiteAppController::class, 'script'])->name('site-app.script');
Route::get('/site-app/icons/{name}.png', [SiteAppController::class, 'icon'])
    ->where('name', '[a-z0-9-]{1,20}')
    ->name('site-app.icon');
Route::get('/offline', [SiteAppController::class, 'offline'])->name('site-app.offline');
