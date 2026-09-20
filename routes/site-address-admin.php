<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Settings → Site address
|------------------------------------------------------------------------------
|
| LIVE. routes/web.php requires this file from inside the admin-api group:
|
|     require __DIR__.'/site-address-admin.php';
|
| Resulting routes, both inside `auth:admin` and NoStoreAdminApi:
|
|     GET  /admin-api/site-address
|     POST /admin-api/site-address
|     POST /admin-api/site-address/check
|
| NOT MAPPED IN AdminCapabilities, DELIBERATELY. That map fails closed — an
| unmapped route is owner-only — and deciding which address is the shop's real
| one, or switching the shop out of every search index, is an owner decision.
| SiteAddressApiController's class comment carries the argument.
|
| A clear_caches migration ships with this file for the usual reason and one
| unusual one: this package also registers a new global middleware from
| AppServiceProvider, and a stale compiled services.php is a shop where that
| middleware silently does not exist while the screen says it is switched on.
| See 2026_11_26_000001_clear_caches_site_address.php.
|
*/

use App\Http\Controllers\Admin\SiteAddressApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-address', [SiteAddressApiController::class, 'show'])->name('admin.site-address');
Route::post('/site-address', [SiteAddressApiController::class, 'save'])->name('admin.site-address.save');
Route::post('/site-address/check', [SiteAddressApiController::class, 'check'])->name('admin.site-address.check');
