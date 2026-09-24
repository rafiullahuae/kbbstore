<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Addresses & pictures"  (Lane GB)
|------------------------------------------------------------------------------
|
| LIVE. routes/web.php requires this file INSIDE THE EXISTING admin-api GROUP —
| the one already carrying `auth:admin` and NoStoreAdminApi — directly beneath
| the import require, which is the screen these endpoints belong to:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/urls-media-admin.php';       // <- this file
|
| Resulting paths:
|
|     GET  /admin-api/urls-media/status
|     GET  /admin-api/urls-media/map.csv
|     POST /admin-api/urls-media/redirects
|     POST /admin-api/urls-media/media
|     POST /admin-api/urls-media/decisions
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop NoStoreAdminApi
| from the group.
|
| AND THE CAPABILITY RULE IS REAL, not assumed: AdminCapabilities::RULES carries
| ['*', 'admin-api/urls-media/**', 'data.import'] — the same capability the
| import screen itself needs, because these endpoints ARE the migration. Without
| it all four default to owner-only, which AdminCapabilityMapTest fails on by
| name rather than leaving to be discovered by a 403 on a host with no shell.
|
| THAT GROUP, AND NOTHING ELSE. The reasoning is `routes/import-admin.php`'s and
| is not weaker here:
|
|   POST /urls-media/redirects  WRITES ROWS THAT MOVE EVERY VISITOR who lands on
|                               an address the shop does not serve. An anonymous
|                               caller with this endpoint can point the shop's
|                               indexed URLs anywhere.
|   POST /urls-media/media      REWRITES EVERY IMAGE PATH IN THE CATALOGUE.
|   GET  /urls-media/status     names the hosts the shop's images sit on and the
|                               addresses it cannot serve — a map of the
|                               migration's soft spots.
|   POST /urls-media/decisions  RECORDS AN APPROVAL, and an approval is what
|                               /urls-media/redirects then writes. It never takes
|                               a destination from the body — the map is
|                               re-derived on the request and an address it does
|                               not propose anything for is refused by name — but
|                               an anonymous caller who can approve the map is an
|                               anonymous caller who can decide where this shop's
|                               indexed addresses go.
|
| routes/api.php is unauthenticated by design in this application (CLAUDE.md),
| so none of these may go there.
|
| A clear_caches migration ships with this file. routes/web.php is compiled on
| the server and the host has no shell, so a new route does not exist until the
| route cache is cleared — 2026_11_21_000000_clear_caches_urls_and_media.php
| does that, following the convention CLAUDE.md sets out.
|
| Resulting paths:
|
|     GET  /admin-api/urls-media/status     the whole picture, one call
|     GET  /admin-api/urls-media/map.csv    every proposed row, all three buckets
|     POST /admin-api/urls-media/redirects  {action: write|rollback}
|     POST /admin-api/urls-media/media      {action: preview|apply|restore, hosts: []}
|     POST /admin-api/urls-media/decisions  {action: accept|reject|clear,
|                                            question?: <RedirectMap::QUESTIONS key>,
|                                            sources?: []}
|
| THE NEW ROUTE STILL NEEDS THE ROUTE CACHE CLEARED even though this file was
| already required: `route:cache` compiles the routes it found AT THE TIME, so a
| route added to a file that is already wired is a route the server does not
| have. 2026_12_12_000001_clear_caches_redirect_decisions.php does it.
|
| FLAT PATHS UNDER /urls-media/, never /urls-media on its own, and no route
| parameters anywhere — the same rule import-admin.php states, for the same
| reason: routes/web.php registers a lot under admin-api and a single segment is
| the kind of name that collides. Everything a call applies to arrives in the
| body and is validated against a fixed list.
|
| WHY map.csv IS A GET AND THE OTHER TWO ARE NOT. It writes nothing. Phase 13
| says the owner approves the three buckets before anything is applied, and a
| spreadsheet he can open beside the export is how that approval actually
| happens — the same shape as /import/rejects.
|
*/

use App\Http\Controllers\Admin\UrlsMediaApiController;
use Illuminate\Support\Facades\Route;

Route::get('/urls-media/status', [UrlsMediaApiController::class, 'status']);
Route::get('/urls-media/map.csv', [UrlsMediaApiController::class, 'map']);

Route::post('/urls-media/redirects', [UrlsMediaApiController::class, 'redirects']);
Route::post('/urls-media/media', [UrlsMediaApiController::class, 'media']);
Route::post('/urls-media/decisions', [UrlsMediaApiController::class, 'decisions']);
