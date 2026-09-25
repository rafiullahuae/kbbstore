<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import → "Articles at addresses this shop owns" — the PAGE (Lane U3)
|------------------------------------------------------------------------------
|
| NEEDS WIRING. CLAUDE.md makes routes/web.php the integrator's file, so this
| ships as its own file and the integrator adds ONE line, inside the EXISTING
| admin-api group — the one that already carries `auth:admin` and
| NoStoreAdminApi — directly beneath the file it belongs with:
|
|     require __DIR__.'/import-admin.php';
|     require __DIR__.'/import-articles-admin.php';
|     require __DIR__.'/import-articles-page.php';   // <- this file
|
| Resulting path:
|
|     GET /admin-api/import/article-addresses-page   the screen he reads
|
| It joins the two Lane A already mounted, which answer the same question in
| the two forms that are not a screen:
|
|     GET /admin-api/import/article-addresses        JSON
|     GET /admin-api/import/article-addresses.csv    the spreadsheet
|
| NOTHING IS CHAINED ONTO IT. RouteRegistrar::middleware() REPLACES rather than
| appends, so a `->middleware(...)` here would silently drop NoStoreAdminApi
| from the group — the note routes/urls-media-admin.php and
| routes/import-articles-admin.php both make, for the same reason.
|
| THE CAPABILITY IS THE EXISTING ONE AND THAT IS WHY THE PREFIX IS `/import/`.
| `AdminCapabilities::RULES` carries ['*', 'admin-api/import/**',
| 'data.import'] and `**` matches everything under it. A prefix of this lane's
| own would fall through to the closed owner-only default, which
| AdminCapabilityMapTest fails on by name. So the page is readable by exactly
| the people who can already run the import that produces these refusals.
|
| A GET, BECAUSE IT WRITES NOTHING. `ReservedArticleReport` opens `posts.csv`
| and `permalinks.csv`, reads each once and answers: no transaction, no
| checkpoint, no ledger row, and a live import part-way through does not
| notice. That is also why it is not a mode of the preview RUN, which is a run
| and has all three.
|
| FLAT PATH, NO ROUTE PARAMETERS. Everything the page needs is on the server
| already — the uploaded files and the importer's own rule — so nothing a
| caller sends is used to build anything.
|
| `-page` RATHER THAN `.html`, matching `/urls-media/progress-page` and
| `/import/history-page`, the two standalone admin pages this project already
| serves. A dotted suffix here would also collide with the `.csv` route's own
| shape for no gain.
|
| A clear_caches migration ships with this file:
| 2027_01_03_000000_clear_caches_article_addresses_page.php. routes/web.php is
| compiled on the server, so a route added by a package does not exist until
| bootstrap/cache/routes-*.php is gone — and the compiled VIEW cache matters
| here too, because this route's whole body is a Blade template.
|
*/

use App\Http\Controllers\Admin\ArticleAddressesPageController;
use Illuminate\Support\Facades\Route;

Route::get('/import/article-addresses-page', ArticleAddressesPageController::class);
