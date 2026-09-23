<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Footer  (Lane: slim-footer)
|------------------------------------------------------------------------------
|
| The slim bar at the foot of the cart page and the checkout — its words, its
| shape and the two switches that decide which of those pages draws it.
| App\Services\SlimFooter carries the schema and the argument for every default.
|
| Resulting paths:
|
|     GET  /admin-api/slim-footer   every field, grouped into the three tabs
|     POST /admin-api/slim-footer   save
|
| THAT GROUP AND NOTHING ELSE — the one that already carries `web`,
| `auth:admin` and NoStoreAdminApi. /api/* here is unauthenticated, and the POST
| rewrites words printed on the page every order is placed from.
|
| CAPABILITY. `slimfooter.manage`, held by owner, manager and editor: the same
| three that hold cartpage.manage and checkoutpage.manage, and for the same
| reason — this is storefront appearance. Its own capability so that narrowing
| one never silently narrows another from a different file.
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2026_12_05_000000_clear_caches_slim_footer.php.
*/

use App\Http\Controllers\Admin\SlimFooterApiController;
use Illuminate\Support\Facades\Route;

Route::get('/slim-footer', [SlimFooterApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('admin.slim-footer');

Route::post('/slim-footer', [SlimFooterApiController::class, 'save'])
    ->middleware('throttle:60,1')
    ->name('admin.slim-footer.save');
