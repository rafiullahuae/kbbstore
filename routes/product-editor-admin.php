<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Products → Product editor  (Lane AO)
|------------------------------------------------------------------------------
|
| NOT WIRED YET. CLAUDE.md forbids this lane from editing routes/web.php, so the
| file ships unmounted and the integrator adds ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — after the catalog-products requires, so the
| Products screens stay together:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/catalog-products-admin.php';
|         require __DIR__.'/catalog-product-create-admin.php';
|         // The full product editor: gallery, categories, rich copy, SEO and
|         // scheduling. Same group, for the same reason.
|         require __DIR__.'/product-editor-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. Every route below reads or writes the catalogue,
| and two of them can put a product on the storefront in a single request.
| /api/* in this application is unauthenticated BY DESIGN — CLAUDE.md says so
| and the whole of tests/Feature/ApiSecurityTest.php exists because each of its
| cases leaked in production — so mounting any of this there would hand the
| public an anonymous write primitive over the shop's own catalogue: anyone at
| all could add a product, rewrite a bestseller's description, or schedule the
| entire catalogue into next year.
|
| ProductEditorTest asserts that refusal on EVERY route below, mounted exactly
| as this header describes, for an anonymous caller, a signed-in storefront
| customer and a plain `web` user — and it reads the middleware back off the
| REGISTERED routes rather than trusting the test harness, because
| RouteRegistrar::middleware() REPLACES rather than appends: a harness that
| chains it twice guards nothing while reading as though it does.
|
| A `clear_caches_*` migration ships with this package
| (2026_10_05_000002_clear_caches_product_editor.php). Routes, Blade, PHP
| classes and the schema all change here. Without it the compiled route cache on
| the live host knows none of these paths, and the failure is the quiet kind:
| the editor renders in full, the owner writes a description, uploads a gallery,
| sets a launch date — and Save 404s, having thrown all of it away.
|
| Routes added:
|
|     GET  /admin-api/product-editor-bootstrap      pickers + currency, once on open
|     GET  /admin-api/product-editor-list           searchable product picker
|     GET  /admin-api/product-editor-load/{id}      one product, the editor's projection
|     POST /admin-api/product-editor-slug           slug preview + availability (create only)
|     POST /admin-api/product-editor-create         create one product
|     POST /admin-api/product-editor-save/{id}      save an existing product
|
| WHY THE PATHS ARE FLAT, AND PREFIXED.
|
| routes/web.php already registers `GET /admin-api/products/{id}` with NO
| constraint on {id}, and `GET /admin-api/catalog/products`. Laravel matches the
| first route registered, so a path an existing wildcard already covers is
| decided by where in a 500-line file somebody pasted a require line, and nothing
| about the resulting symptom points at the cause — the Orders lane lost a
| release to exactly that (`/orders/list` matched `/orders/{id}` and reached a
| controller whose signature is `int $id`). A flat prefix of its own cannot
| collide whatever order the requires end up in.
|
| `product-editor-` is distinct from Lane AF's `catalog-products-` and Lane AK's
| `catalog-product-`, so none of the three can shadow another.
|
| {id} is constrained to digits, so a stray path segment 404s rather than
| reaching a controller with a TypeError.
|
| ---------------------------------------------------------------------------
| OVERLAP WITH LANE AK, WHICH THE INTEGRATOR SHOULD DECIDE RATHER THAN DISCOVER
| ---------------------------------------------------------------------------
|
| routes/catalog-product-create-admin.php (Lane AK, also unmounted) already
| offers /catalog-product-slug and /catalog-product-create. This file offers its
| own slug and create endpoints, and that is a genuine duplication rather than
| an oversight — stated here so it is a choice and not a surprise.
|
| The reason is that the owner asked for ONE screen that does the whole job
| ("check if we are missing on the add product page"), and a create form that
| cannot set a gallery, multiple categories, sanitised rich copy, SEO or a
| launch date would have to hand the operator straight to a second screen to
| finish the product. Create and edit here are the same form and the same
| validation path, which is also why they cannot drift.
|
| Both files are safe to mount together: the prefixes differ, so nothing
| collides, and Lane AK's endpoints keep working for anything already pointed at
| them. The recommendation is to mount both now and retire Lane AK's create once
| this screen has been used in anger — but either way the two must not be
| described as the same route, because they are not.
|
| WHY THERE IS NO UPLOAD ROUTE HERE. There is exactly one file-upload endpoint
| in this application — POST /admin-api/media/upload
| (Admin\MediaUploadController), already registered in routes/web.php — and the
| gallery, the main image and the SEO share image all post to it, the same way
| the brand logo and the category image do. A second upload path is the thing
| routes/brands-admin.php and routes/catalog-admin.php each went out of their way
| to avoid: two of them drift, and the one that drifts is the one with the
| content-type, size and SVG rules in it. What crosses the routes below is the
| URL that endpoint returned, never a file.
|
| WHY THERE IS NO SLUG FIELD ON THE SAVE SIDE. A product's slug is a live URL
| contract (U-01: /product/{slug}/) — a link Google holds and a line in
| customers' order histories. It is generated, shown and editable at CREATE
| time and after that it is not a field. Lane AF's /catalog-products-save/{id}
| does not accept one either, and nothing here adds one. The same goes for
| `wc_id` (U-02).
|
| WHAT IS DELIBERATELY ABSENT. No delete route, single or bulk.
| order_items.product_id points at these rows. Out of scope, on purpose, and not
| an omission to be quietly filled in.
|
*/

use App\Http\Controllers\Admin\ProductEditorApiController;
use Illuminate\Support\Facades\Route;

Route::get('/product-editor-bootstrap', [ProductEditorApiController::class, 'bootstrap']);
Route::get('/product-editor-list', [ProductEditorApiController::class, 'index']);

Route::get('/product-editor-load/{id}', [ProductEditorApiController::class, 'show'])
    ->whereNumber('id');

Route::post('/product-editor-slug', [ProductEditorApiController::class, 'slug']);
Route::post('/product-editor-create', [ProductEditorApiController::class, 'store']);

Route::post('/product-editor-save/{id}', [ProductEditorApiController::class, 'save'])
    ->whereNumber('id');
