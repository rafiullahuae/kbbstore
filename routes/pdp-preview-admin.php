<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Product page → Design previews  (Lane PDP)
|------------------------------------------------------------------------------
|
| NEEDS WIRING. CLAUDE.md makes routes/web.php the integrator's file, so this
| ships as its own file and the integrator adds ONE line, inside the EXISTING
| admin-api group — the one that already carries `auth:admin` and
| NoStoreAdminApi — beside the other catalog files:
|
|     require __DIR__.'/catalog-admin.php';
|     require __DIR__.'/pdp-preview-admin.php';      // <- this file
|
| Resulting paths:
|
|     GET /admin-api/catalog/pdp-preview                    the chooser
|     GET /admin-api/catalog/pdp-preview/{candidate}/{slug}  one drawing
|
| THE PREFIX IS `catalog/` FOR THE CAPABILITY, NOT FOR TIDINESS.
| App\Support\AdminCapabilities::RULES carries
|
|     ['GET', 'admin-api/catalog/**', 'catalog.view'],
|     ['*',   'admin-api/catalog/**', 'catalog.manage'],
|
| and `**` matches everything beneath it, so these two GETs are readable by
| exactly the roles that may already look at the catalogue and by nobody else.
| A prefix of this lane's own would fall through to the closed owner-only
| default, which AdminCapabilityMapTest fails on by name — and failing closed on
| a host with no shell is not a thing to discover after a package has shipped.
|
| NOTHING IS CHAINED ONTO THESE. RouteRegistrar::middleware() REPLACES the
| pending middleware rather than appending to it, so a `->middleware(...)` here
| would silently drop NoStoreAdminApi from the group.
|
| GETs, BECAUSE THEY WRITE NOTHING. The chooser runs one narrow SELECT; the
| drawing runs Store\ProductController::show() and then renders a different
| template with its data. No transaction, no setting, no ledger row.
|
| ▲ THESE ARE PROPOSALS AND THEY ARE MEANT TO BE DELETED. Four of the five
|   candidates go the day the owner picks one, and the fifth becomes an edit to
|   resources/views/store/product.blade.php rather than a sixth copy of it. When
|   that happens this file, app/Http/Controllers/Admin/PdpPreviewController.php,
|   resources/views/store/pdp-preview/ and tests/Feature/PdpPreviewTest.php go
|   together, and the storefront is untouched because it was never touched.
|
| A clear_caches migration ships with this file when it is packaged:
| routes/web.php is compiled on the server, so a route added by a package does
| not exist until bootstrap/cache/routes-*.php is gone — and the compiled VIEW
| cache matters here too, because everything this route serves is Blade.
*/

use App\Http\Controllers\Admin\PdpPreviewController;

Route::get('/catalog/pdp-preview', [PdpPreviewController::class, 'index']);

/*
 * `{candidate}` is NOT trusted because it is in a route pattern. The controller
 * checks it against PdpPreviewController::CANDIDATES before it is used, because
 * what it ends up naming is a VIEW FILE. The `where` below is belt and braces —
 * it keeps a slash or a dot out of the segment so nothing that reaches the
 * controller can look like a path at all.
 */
Route::get('/catalog/pdp-preview/{candidate}/{slug}', [PdpPreviewController::class, 'show'])
    ->where('candidate', '[a-z]+')
    ->where('slug', '[A-Za-z0-9\-_]+');
