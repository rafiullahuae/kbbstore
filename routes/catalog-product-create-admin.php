<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Products → Add product, and the product image  (Lane AK)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so the
| file ships unmounted and the integrator adds ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — immediately after the catalog-products
| require, so the two halves of the same screen stay together:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/catalog-products-admin.php';
|         // Add product, and the product image — the two holes 2.60.131 left in
|         // the Products screen. Same group, for the same reason.
|         require __DIR__.'/catalog-product-create-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Every route below WRITES the catalogue.
| /catalog-product-create adds a row to `products` and can put it on the
| storefront in the same request; /catalog-product-image/{id} changes the
| picture on a product the shop is already selling. /api/* in this app is
| unauthenticated BY DESIGN (CLAUDE.md), so mounting any of this there would be
| an anonymous write primitive over the shop's own catalogue — anyone who asked
| could add a product to the store, or replace a bestseller's photograph.
| AdminCatalogProductCreateTest asserts the refusal on every route below,
| mounted exactly as this header describes, for an anonymous caller, a
| signed-in storefront customer and a plain `web` user — and reads the
| middleware back off the REGISTERED routes rather than trusting the harness,
| because RouteRegistrar::middleware() REPLACES rather than appends and a
| harness that chains it twice guards nothing while reading as though it did.
|
| A `clear_caches_*` migration ships with this package
| (2026_09_24_000000_clear_caches_product_create.php). Routes, Blade and PHP all
| change here. Without it the compiled route cache on the live host knows none
| of these paths and the failure is the quiet kind: the Add product form renders
| perfectly, the operator fills it in, and Save 404s.
|
| Routes added:
|
|     POST /admin-api/catalog-product-slug          slug preview + availability
|     POST /admin-api/catalog-product-create        create one product
|     POST /admin-api/catalog-product-image/{id}    set / replace / remove the image
|
| WHY THE PATHS ARE FLAT, AND SINGULAR.
|
| routes/web.php already registers `GET /admin-api/products/{id}` with NO
| constraint on {id}, and `GET /admin-api/catalog/products`. Laravel matches the
| first route registered, so a path an existing wildcard already covers is
| decided by where in a 500-line file somebody pasted a require line, and
| nothing about the resulting symptom points at the cause — the Orders lane lost
| a release to exactly that (`/orders/list` matched `/orders/{id}` and reached a
| controller whose signature is `int $id`). A flat prefix of its own cannot
| collide whatever order the requires end up in.
|
| The prefix here is `catalog-product-` (SINGULAR), one character different from
| Lane AF's `catalog-products-` (plural), and that is deliberate rather than
| careless: the two are distinct literal path segments that cannot shadow each
| other, while a reader sorting routes/ alphabetically sees the two halves of
| the Products screen adjacent. Tests\Support\CatalogProductCreateRoutes filters
| on the prefix AND the controller, so neither lane's route sweeps up the
| other's and reports it as its own work.
|
| {id} is constrained to digits, so a stray path segment 404s rather than
| reaching a controller with a TypeError.
|
| WHY THERE IS NO UPLOAD ROUTE HERE. There is exactly one file-upload endpoint
| in this application — POST /admin-api/media/upload
| (Admin\MediaUploadController), already registered in routes/web.php — and the
| Add product form and the edit panel's image box both post to it, the same way
| the brand logo, the category image and the SEO share image do. A second upload
| path is the thing routes/brands-admin.php and routes/catalog-admin.php each
| went out of their way to avoid: two of them drift, and the one that drifts is
| the one with the content-type, size and SVG rules in it. What crosses the
| routes below is the URL that endpoint returned, never a file.
|
| WHY THERE IS NO SLUG ROUTE ON THE UPDATE SIDE. A product's slug is a live URL
| contract (U-01: /product/{slug}/) — a link Google holds and a line in
| customers' order histories. It is generated, shown and editable at CREATE
| time, on /catalog-product-create, and after that it is not a field. Lane AF's
| /catalog-products-save/{id} does not accept one either, and nothing here adds
| one.
|
| WHAT IS DELIBERATELY ABSENT. No delete route, single or bulk.
| order_items.product_id points at these rows. Out of scope, on purpose, and not
| an omission to be quietly filled in.
|
*/

use App\Http\Controllers\Admin\CatalogProductCreateApiController;
use Illuminate\Support\Facades\Route;

Route::post('/catalog-product-slug', [CatalogProductCreateApiController::class, 'slug']);
Route::post('/catalog-product-create', [CatalogProductCreateApiController::class, 'store']);

Route::post('/catalog-product-image/{id}', [CatalogProductCreateApiController::class, 'image'])
    ->whereNumber('id');
