<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Products  (Lane AF)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so the
| file ships unmounted and the integrator adds ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/catalog-products-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Every route below reads or WRITES the catalogue.
| /catalog-products-save changes a price, a stock number and whether a product is
| on the storefront at all; the three bulk routes do the same across up to 200
| products in one request; /catalog-products-export hands the whole filtered
| catalogue over as a file, SKUs, cost-relevant order counts and all. /api/* in
| this app is unauthenticated BY DESIGN (CLAUDE.md), so mounting any of this
| there would be an anonymous write primitive over the shop's own prices.
| AdminCatalogProductsTest asserts the refusal on every route below, mounted
| exactly as this header describes, and reads the middleware back off the
| REGISTERED routes rather than trusting the harness.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_23_000000_clear_caches_catalog_products.php). Without it the compiled
| route cache on the live host knows none of these paths, and the failure is the
| quiet kind: the Products screen renders its chips, its inline cells and its
| export button perfectly and every request 404s.
|
| Routes added:
|
|     GET  /admin-api/catalog-products-list            the paginated, filtered, sorted list
|     GET  /admin-api/catalog-products-facets          brands and categories, for the pickers
|     GET  /admin-api/catalog-products-export          CSV of the CURRENT filtered view
|     GET  /admin-api/catalog-products-detail/{id}     one product, for the edit panel
|     POST /admin-api/catalog-products-save/{id}       inline cell and panel saves
|     POST /admin-api/catalog-products-bulk-status     publish / draft / private (guarded)
|     POST /admin-api/catalog-products-bulk-category   add / remove / replace  (replace guarded)
|     POST /admin-api/catalog-products-bulk-price      percent / amount / set / clear (confirmed)
|
| WHY THE PATHS ARE FLAT, AND NOT /catalog/products/list.
|
| routes/web.php already registers
|
|     GET  /admin-api/catalog/products                 (this same controller's index)
|     POST /admin-api/catalog/products/{product}/toggle-featured
|     GET  /admin-api/products/{id}                    (AdminController, NO constraint on {id})
|
| Laravel matches the first route registered, so a path that a wildcard route
| already covers is decided by where in a 500-line file somebody pasted a
| require line — and nothing about the resulting symptom points at the cause.
| The Orders lane was bitten by exactly that: `/orders/list` is matched by
| `/orders/{id}` and reaches AdminController::order('list'), whose signature is
| `int $id`. A flat prefix of its own cannot collide with any of the three
| patterns above, whatever order the requires end up in, so the integrator has
| one fewer way to ship an inert release.
|
| The two {id} routes below are under `catalog-products-detail` and
| `catalog-products-save`, prefixes no other route in this application uses, and
| both are constrained to digits so a stray path segment 404s rather than
| reaching a controller with a TypeError.
|
| WHAT STAYS WHERE IT IS. `GET /admin-api/catalog/products` — same controller,
| same index() — is left registered in routes/web.php and still answers. It is
| not this lane's file to edit, another lane's region of the admin view calls it
| to search products when adding a line to an order, and
| tests/Feature/SqlDialectGuardTest.php drives it by name. Its response is a
| superset of what it returned before: the old `counts.published`, `counts.out`,
| `price` and `sale_price` keys are all still there and still mean what they
| meant. `POST /admin-api/catalog/products/{product}/toggle-featured` likewise.
|
| WHAT IS DELIBERATELY ABSENT. There is no delete route here, bulk or single.
| Trashing a product is a decision about a row that orders point at through
| order_items.product_id, and this lane did not need it to make the screen
| usable; the chip that shows what is already in the trash is read-only. Adding
| one later is a deliberate act, not an omission to be quietly filled in.
|
| There is also no second product-editor endpoint pointed at the OLD
| /admin-api/products/{id} pair (AdminController::getProduct / updateProduct).
| Those are left exactly as they are, and see the note in AdminCatalogProductsTest
| about why nothing should be pointed at updateProduct until its owner fixes it:
| it validates `status` as `in:active,draft,archived`, and 'active' and
| 'archived' are values this schema has no concept of — a product saved through
| it with status 'active' disappears from the storefront, from every category
| page and from the sitemap, because Product::scopeVisible() filters on
| `status = 'publish'`. That controller is not this lane's to change.
|
*/

use App\Http\Controllers\Admin\CatalogProductsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/catalog-products-list', [CatalogProductsApiController::class, 'index']);
Route::get('/catalog-products-facets', [CatalogProductsApiController::class, 'facets']);
Route::get('/catalog-products-export', [CatalogProductsApiController::class, 'export']);

Route::get('/catalog-products-detail/{id}', [CatalogProductsApiController::class, 'show'])->whereNumber('id');
Route::post('/catalog-products-save/{id}', [CatalogProductsApiController::class, 'update'])->whereNumber('id');

Route::post('/catalog-products-bulk-status', [CatalogProductsApiController::class, 'bulkStatus']);
Route::post('/catalog-products-bulk-category', [CatalogProductsApiController::class, 'bulkCategory']);
Route::post('/catalog-products-bulk-price', [CatalogProductsApiController::class, 'bulkPrice']);
