<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| RETIRED — Catalog → Products → Add product, and the product image  (was Lane AK)
|------------------------------------------------------------------------------
|
| THIS FILE REGISTERS NOTHING, ON PURPOSE. It is a tombstone, not a route file.
|
| Lane AT retired the duplicate product screens. The admin had grown three ways
| to write a product — Lane AF's inline detail panel, Lane AK's create form, and
| Lane AO's full editor — and the Edit button pointed at the oldest of them, so
| the newest screen looked as though it had never shipped. The full editor
| (routes/product-editor-admin.php) is the one that survives: it is the only one
| that can set a gallery, several categories, sanitised rich copy, SEO and a
| launch date, and its create and edit paths are the same form and the same
| validation, so they cannot drift.
|
| The three routes that used to live here are GONE:
|
|     POST /admin-api/catalog-product-slug      -> use /admin-api/product-editor-slug
|     POST /admin-api/catalog-product-create    -> use /admin-api/product-editor-create
|     POST /admin-api/catalog-product-image/{id}-> the editor's own image + gallery
|                                                  fields, saved through
|                                                  /admin-api/product-editor-save/{id}
|
| App\Http\Controllers\Admin\CatalogProductCreateApiController is deleted, so
| this file cannot be restored by un-commenting anything: the class it named no
| longer exists. That is deliberate. Two of those routes were WRITE endpoints
| over the shop's own catalogue — one added a row to `products` and could put it
| on the storefront in the same request, the other replaced the photograph on a
| product the shop was already selling — and an endpoint nobody calls is still
| an endpoint somebody can call.
|
| ---------------------------------------------------------------------------
| INTEGRATOR: DELETE THIS FILE, AND THE REQUIRE LINE THAT NAMES IT.
| ---------------------------------------------------------------------------
|
| routes/web.php line 386 (inside the admin-api group) reads:
|
|     require __DIR__.'/catalog-product-create-admin.php';
|
| Remove that line and delete this file. Both, in the same change.
|
| The file is a tombstone rather than simply deleted because CLAUDE.md forbids
| this lane from editing routes/web.php, and a `require` of a path that no longer
| exists is a fatal error, not a missing feature: it would take down the whole
| application — the storefront included — the moment the tree was deployed or a
| test booted it. An empty file that registers nothing is inert and leaves the
| removal itself a one-line change for whoever owns routes/web.php.
|
| tests/Feature/ProductEditorRetirementTest.php asserts, against the REGISTERED
| routes rather than against this file's text, that all three paths above now
| 404 and that the surviving editor's routes are still mounted behind
| `auth:admin`.
|
| A clear_caches migration ships with this package
| (2026_10_06_000000_clear_caches_retire_duplicate_editors.php). Removing routes
| needs the compiled route cache cleared exactly as adding them does: without it
| the live host goes on serving the retired paths from its cached route table,
| which is the whole point of removing them.
|
*/
