<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| The media sideloader and the live progress page  (Lane GD)
|------------------------------------------------------------------------------
|
| WHERE THIS GOES. Inside the SAME `admin-api` group in routes/web.php that
| already carries `auth:admin` and NoStoreAdminApi, directly beneath Lane GB's
| require, because these endpoints are the other half of the same screen:
|
|     require __DIR__.'/urls-media-admin.php';
|     require __DIR__.'/media-sideload-admin.php';    // <- this file
|
| Resulting paths:
|
|     GET  /admin-api/urls-media/progress           everything, one call, JSON
|     GET  /admin-api/urls-media/progress-page      the live page he opens
|     GET  /admin-api/urls-media/sideload.csv       every failure and its reason
|     POST /admin-api/urls-media/sideload           {action: fetch|stop|retry}
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop
| NoStoreAdminApi from the group — the note urls-media-admin.php makes, for the
| same reason.
|
| THE CAPABILITY RULE ALREADY EXISTS AND IS NOT ASSUMED. AdminCapabilities::RULES
| carries ['*', 'admin-api/urls-media/**', 'data.import'], and `**` matches every
| path under the prefix — which is exactly why these four were put under
| /urls-media/ rather than under a prefix of their own. A new prefix would have
| fallen through to the closed owner-only default and AdminCapabilityMapTest
| would have failed by name. The pairing is pinned in
| tests/Feature/GdMediaSideloaderTest.php so a future tidy-up of RULES that
| narrows that wildcard fails here rather than on a live host with no shell.
|
| WHY THE PAGE IS A GET UNDER admin-api AND NOT A NEW ADMIN PAGE ROUTE. The
| admin page routes live inline in routes/web.php, which this lane may not edit,
| and `$adminPath` is not in scope in a required file anyway. A page served from
| this group is guarded by the identical session guard, carries the same
| no-store headers (which is right for a live view), and needs no change to
| web.php beyond the one require above. It is a full HTML document with no build
| step and no dependency on the console bundle — see the controller's comment on
| why the view that has to be trustworthy when something is broken must not
| depend on the 20,000-line file that might be what is broken.
|
| WHY POST /sideload IS NOT A GET. It writes bytes into the web root. A GET that
| writes is fetched by a link prefetcher, a browser history restore and the
| host's own cache warmer — routes/import-admin.php's reasoning, and it is
| stronger here than there, because this one also makes outbound HTTP requests.
|
| FLAT PATHS, NO ROUTE PARAMETERS. Everything a call applies to arrives in the
| body and is validated against a fixed list. The same rule import-admin.php and
| urls-media-admin.php state, for the same reason: routes/web.php registers a
| great deal under admin-api and a single bare segment is the kind of name that
| collides.
|
| A clear_caches migration ships with this file —
| 2026_09_18_000001_clear_caches_media_sideloader.php — because routes/web.php is
| compiled on the server and the host has no shell, so these routes do not exist
| until bootstrap/cache/routes-*.php is gone. CLAUDE.md makes the pairing a
| convention for exactly this reason.
|
*/

use App\Http\Controllers\Admin\MediaSideloadApiController;
use Illuminate\Support\Facades\Route;

Route::get('/urls-media/progress', [MediaSideloadApiController::class, 'progress']);
Route::get('/urls-media/progress-page', [MediaSideloadApiController::class, 'page']);
Route::get('/urls-media/sideload.csv', [MediaSideloadApiController::class, 'failures']);

Route::post('/urls-media/sideload', [MediaSideloadApiController::class, 'sideload']);
