<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Addresses & pictures"  (Lane GB)
|------------------------------------------------------------------------------
|
| NOT YET WIRED. This file is inert until routes/web.php requires it, and
| CLAUDE.md forbids this lane from editing that file. The integrator adds ONE
| line, INSIDE THE EXISTING admin-api GROUP — the one that already carries
| `auth:admin` and NoStoreAdminApi — directly beneath the import require, which
| is the screen these endpoints belong to:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/urls-media-admin.php';       // <- this line
|
| The exact anchor and replacement are in docs/GB-MEDIA-AND-REDIRECTS.md, and
| the anchor there was checked to occur exactly once.
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
