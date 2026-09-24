<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Articles at addresses the shop owns"  (Lane A, Phase 13)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md makes routes/web.php the integrator's file, so this
| ships as its own file and needs ONE line adding, inside the EXISTING
| admin-api group — the one that already carries `auth:admin` and
| NoStoreAdminApi — directly beneath the import require, because these two
| endpoints belong to that screen:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/import-articles-admin.php';    // <- this file
|
| Resulting paths:
|
|     GET /admin-api/import/article-addresses      JSON: the three lists
|     GET /admin-api/import/article-addresses.csv  the spreadsheet he approves from
|
| NOTHING IS CHAINED ONTO THEM. RouteRegistrar::middleware() REPLACES rather
| than appends, so a `->middleware(...)` here would silently drop
| NoStoreAdminApi from the group — the note routes/urls-media-admin.php makes,
| for the same reason.
|
| THE CAPABILITY IS THE EXISTING ONE AND THAT IS WHY THE PREFIX IS `/import/`.
| AdminCapabilities::RULES carries ['*', 'admin-api/import/**', 'data.import'],
| and `**` matches everything under it. A prefix of this lane's own would have
| fallen through to the closed owner-only default, which AdminCapabilityMapTest
| fails on by name — a better outcome than a 403 on a host with no shell, but
| still a failure nobody needs. Both endpoints are therefore reachable by
| exactly the people who can already run the import that produces the same
| refusals.
|
| WHY BOTH ARE GETs. Neither writes. `ReservedArticleReport` opens `posts.csv`,
| reads it once and answers — no transaction, no checkpoint, no ledger row, and
| a live import part-way through does not notice. That is the whole reason this
| is not a mode of the preview RUN, which is a run and has all three.
|
| WHY THE CSV IS A DOWNLOAD AND NOT A PAGE. It quotes article titles straight
| out of the owner's WordPress database. `Content-Disposition: attachment`,
| `text/csv` and `nosniff`, exactly as /import/rejects does and for the same
| reason: a body a browser renders is a body a browser can be made to execute.
|
| FLAT PATHS, NO ROUTE PARAMETERS. Everything these two need is on the server
| already — the uploaded file and the importer's own rule — so nothing a caller
| sends is used to build anything.
|
| A clear_caches migration ships with this file:
| 2026_12_11_000000_clear_caches_article_addresses.php. routes/web.php is
| compiled on the server and the host has no shell, so a route added by a
| package does not exist until bootstrap/cache/routes-*.php is gone.
|
*/

use App\Http\Controllers\Admin\ImportApiController;
use Illuminate\Support\Facades\Route;

Route::get('/import/article-addresses', [ImportApiController::class, 'articleAddresses']);
Route::get('/import/article-addresses.csv', [ImportApiController::class, 'articleAddressesCsv']);
