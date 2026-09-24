<?php

declare(strict_types=1);

use App\Http\Controllers\StorefrontHealthController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| /_kbb-health
|------------------------------------------------------------------------------
|
| TO WIRE THIS UP: in routes/web.php, delete the `Route::get('/_kbb-health', ...)`
| closure (it is the block immediately after the admin route group, and it is the
| only thing in that file that names `_kbb-health`) and put this line where it
| stood:
|
|     require __DIR__.'/update-health.php';
|
| THE POSITION MATTERS. Laravel's RouteCollection is keyed by method and URI, so
| the LAST registration of `GET /_kbb-health` is the one that answers. Requiring
| this file without removing the closure would therefore work -- and would leave
| a dead closure in web.php that a later reader would reasonably believe was the
| live implementation. Remove it.
|
| The URI, the name and the token gate are all unchanged, so nothing that
| references this route has to move: CanonicalHost::EXEMPT_PREFIXES,
| CheckRedirects' exempt list, PageController's excluded slugs and
| RootSlugCollisionTest all name the string `_kbb-health` and all still hold.
|
| A route added to this application does not take effect until the compiled
| route cache is cleared, so the package that ships this ships a
| clear_caches_* migration with it.
|
*/

Route::get('/_kbb-health', StorefrontHealthController::class)->name('kbb.health');
