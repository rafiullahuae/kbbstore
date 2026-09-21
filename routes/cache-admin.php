<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform → Cache  (Lane: cache-control)
|------------------------------------------------------------------------------
|
| The screen behind the owner's "i need a full cache settings at the top of the
| backend panel ... give full control of caching etc." One reading and three
| actions. App\Http\Controllers\Admin\CacheApiController carries the reasoning,
| including why the reading is the expensive half.
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/cache-admin.php';
|     });
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the file ships for
| the integrator to add that one line. The paragraph above describes WHERE the
| require belongs rather than claiming anything about whether it is there yet,
| so it reads correctly on both sides of that edit — RouteFileHeadersTest reads
| this file whole and a route file that web.php requires may not go on
| describing itself as awaiting the line.
|
| Resulting paths:
|
|     GET  /admin-api/cache          settings, the compiled caches on disk, the
|                                    cache store, and the Cache-Control a
|                                    storefront page answers with RIGHT NOW
|     POST /admin-api/cache          save the three settings
|     POST /admin-api/cache/clear    body: target=compiled|application|all
|     POST /admin-api/cache/probe    ask the web server for a built asset over
|                                    HTTP and report the header it sent
|
| THAT GROUP, AND NOTHING ELSE. /api/* in this app is unauthenticated by design.
| POST /cache/clear deletes this shop's compiled config, routes and views on
| demand; outside auth:admin that is a stranger with a loop, making every
| request on the site recompile the world on a host that charges the owner for
| the CPU. GET /cache renders a storefront page per call and reports how the
| install is configured. Neither belongs anywhere near routes/api.php.
|
| CAPABILITY. `cache.manage` — owner only, mapped in App\Support\
| AdminCapabilities. A new capability and not a reuse of `store.settings`,
| although both are owner-only today: these endpoints do something no settings
| screen does, which is to drop compiled code out from under a running shop,
| and mapping them onto the settings capability would mean that the day
| `store.settings` is widened to a manager, this widens with it silently.
| `system.diagnostics` was the other candidate and is the wrong shape — that
| one is a READ capability covering the error log and the schema, and three of
| these four routes are writes.
|
| THROTTLES, AND THEY ARE PART OF THE ROUTE RATHER THAN THE CONTROLLER. The GET
| renders a storefront page inside the worker answering it; `clear` runs three
| Artisan commands and an opcache_reset; `probe` makes an outbound HTTP request
| that can sit for its full eight-second timeout. None of those is expensive
| enough to refuse a person pressing a button, and all of them are expensive
| enough that a held key is the whole PHP worker pool. The numbers are far above
| any honest use and far below a rate that can take the site down.
|
| A clear_caches_* migration ships with this package, because a route added to
| routes/web.php does nothing until the compiled route table is gone — see
| database/migrations/2026_11_27_000001_clear_caches_cache_control.php. That
| migration matters more here than usual: this package also registers a
| middleware from AppServiceProvider, and a stale compiled services.php is a
| shop where the screen offers a switch that reaches nothing.
*/

use App\Http\Controllers\Admin\CacheApiController;
use Illuminate\Support\Facades\Route;

Route::get('/cache', [CacheApiController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('admin.cache');

Route::post('/cache', [CacheApiController::class, 'save'])
    ->middleware('throttle:30,1')
    ->name('admin.cache.save');

Route::post('/cache/clear', [CacheApiController::class, 'clear'])
    ->middleware('throttle:12,1')
    ->name('admin.cache.clear');

Route::post('/cache/probe', [CacheApiController::class, 'probeAssets'])
    ->middleware('throttle:12,1')
    ->name('admin.cache.probe');
