<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Site layout  (Lane: W1, site-width-system)
|------------------------------------------------------------------------------
|
| The site width, the side gutter, and how many product columns a row holds.
| App\Services\SiteLayout carries the schema, the argument for every default,
| and the census that says why there were fourteen page-container widths before
| there was one.
|
| Resulting paths:
|
|     GET  /admin-api/site-layout   the nine fields, grouped into two tabs
|     POST /admin-api/site-layout   save
|
| THIS GROUP AND NOTHING ELSE — the one in routes/web.php that already carries
| `web`, `auth:admin` and NoStoreAdminApi. /api/* there is unauthenticated, and
| this POST writes numbers that are printed into a stylesheet on every page of
| the shop.
|
| CAPABILITY. `sitelayout.manage`, held by owner, manager and editor: the same
| three that hold cartpage.manage, checkoutpage.manage and slimfooter.manage,
| and for the same reason — this is storefront appearance. Its own capability so
| that narrowing one never silently narrows another from a different file.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_02_10_000000_clear_caches_site_layout.php.
*/

use App\Http\Controllers\Admin\SiteLayoutApiController;
use Illuminate\Support\Facades\Route;

Route::get('/site-layout', [SiteLayoutApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.site-layout');

Route::post('/site-layout', [SiteLayoutApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.site-layout.save');
