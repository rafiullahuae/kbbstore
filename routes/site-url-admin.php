<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Platform → Site address · the APP_URL mismatch banner
|------------------------------------------------------------------------------
|
| ▲ NOT LIVE UNTIL THE INTEGRATOR WIRES IT. routes/web.php is owned by the
| integrator and no lane may edit it. One line, INSIDE the `/admin-api` group
| that already carries `auth:admin` and NoStoreAdminApi, beside the existing
| site-address require at web.php:793:
|
|     require __DIR__.'/site-url-admin.php';
|
| It must sit inside that group. These routes declare no middleware of their
| own, and outside the group `POST /admin-api/site-url/adopt` would be an
| unauthenticated write that repoints every email and webhook this shop sends.
|
| Resulting routes:
|
|     GET  /admin-api/site-url          does the served address match APP_URL?
|     POST /admin-api/site-url/adopt    write the served address into .env
|
| NOT MAPPED IN AdminCapabilities, DELIBERATELY — the same call, for the same
| reason, as site-address-admin.php beside it. That map fails closed, so an
| unmapped admin route is owner-only, which is the strictest setting available
| and the right one for "move this shop to another domain". Naming a capability
| could only widen it. tests/Feature/SiteUrlMismatchTest.php pins that a
| non-owner admin is refused, so the property survives a later tidy-up of the
| map.
|
| A `clear_caches_*` migration ships with this file, per CLAUDE.md: a route
| added here does not exist until the compiled route cache is gone — and on this
| feature in particular, because the whole point of it is that a stale compiled
| bootstrap/cache/config.php is why writing .env can look like it did nothing.
|
*/

use App\Http\Controllers\Admin\SiteUrlApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-url', [SiteUrlApiController::class, 'show'])->name('admin.site-url');
Route::post('/site-url/adopt', [SiteUrlApiController::class, 'adopt'])->name('admin.site-url.adopt');
