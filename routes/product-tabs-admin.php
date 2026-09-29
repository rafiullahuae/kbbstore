<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Product tabs  (Lane PT)
|------------------------------------------------------------------------------
|
| Tabs the owner writes himself: global ones that appear on every product, tabs
| that exist on one product only, and one product's answer to an inherited tab
| — hidden here, or re-worded here. App\Support\ProductTabs is the design.
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| EXISTING admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other Catalog route files:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/product-editor-admin.php';
|         // Catalog → Product tabs: global and per-product tabs, same group.
|         require __DIR__.'/product-tabs-admin.php';
|     });
|
| THAT GROUP AND NOTHING ELSE. Every write below puts operator-authored HTML on
| a public product page — on EVERY product page, in the case of a global tab —
| and GET /product-tabs/search lists the catalogue by name. `/api/*` in this
| application is unauthenticated BY DESIGN (CLAUDE.md, and the whole of
| tests/Feature/ApiSecurityTest.php exists because each of its cases leaked in
| production), so mounting any of this there would hand the public an anonymous
| way to print markup on all seven hundred product pages at once.
|
| ── ROUTE ORDER: THE NAMED PATHS BEFORE {id} ────────────────────────────────
|
| /product-tabs/{id} is constrained to digits, so /product-tabs/search and
| /product-tabs/order cannot reach it whatever the order — but they are
| registered first anyway, which is the shape AdminCapabilityMapTest pins by
| name one layer down and the shape the Orders lane lost a release to not
| having.
|
| ── CAPABILITIES. TWO, AND BOTH NEW ─────────────────────────────────────────
|
|   `producttabs.view`    reading the tabs and searching for a product.
|   `producttabs.manage`  every write.
|
| Neither reuses an existing one. `catalog.manage` would have been the lazy
| answer and it is the wrong one in both directions: it would hand everyone who
| may edit a product the ability to print a paragraph on all seven hundred
| product pages, and it would mean the tab writer must also be allowed to
| change prices. The read half is split off the write half for the reason
| `reviews.view` is split from `reviews.manage` — the person who checks what a
| product's tabs say is not always the person who may rewrite them.
|
| App\Support\AdminCapabilities puts the WRITES ABOVE THE READS and RULES is
| first-match-wins, so a `GET admin-api/product-tabs/**` rule listed first
| would hand POST .../override out on a read capability. That is the shape of
| the quiz-leads and coupons/manage mistakes that file names.
|
| ── AND A clear_caches_* MIGRATION SHIPS WITH THIS PACKAGE ──────────────────
|
| 2027_04_25_000100_clear_caches_product_tabs.php. CLAUDE.md's convention: a
| route added by a package does nothing at all until the compiled route table
| is gone, and the failure is the quiet kind — the screen renders in full, the
| owner writes a tab, and Save 404s having thrown it away.
|
| Routes added:
|
|     GET    /admin-api/product-tabs                      the global tabs
|     POST   /admin-api/product-tabs                      create a global tab
|     GET    /admin-api/product-tabs/search               the product picker
|     POST   /admin-api/product-tabs/order                re-order, in one write
|     GET    /admin-api/product-tabs/product/{product}    one product's picture
|     POST   /admin-api/product-tabs/product/{product}    a tab of its own
|     POST   /admin-api/product-tabs/product/{product}/override
|                                                         hide / re-word / revert
|     PUT    /admin-api/product-tabs/{id}                 save one row
|     DELETE /admin-api/product-tabs/{id}                 delete one row
|
*/

use App\Http\Controllers\Admin\ProductTabsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/product-tabs/search', [ProductTabsApiController::class, 'search']);
Route::post('/product-tabs/order', [ProductTabsApiController::class, 'reorder']);

Route::get('/product-tabs/product/{product}', [ProductTabsApiController::class, 'forProduct'])
    ->whereNumber('product');

Route::post('/product-tabs/product/{product}', [ProductTabsApiController::class, 'storeForProduct'])
    ->whereNumber('product');

Route::post('/product-tabs/product/{product}/override', [ProductTabsApiController::class, 'override'])
    ->whereNumber('product');

Route::get('/product-tabs', [ProductTabsApiController::class, 'index']);
Route::post('/product-tabs', [ProductTabsApiController::class, 'store']);

Route::put('/product-tabs/{id}', [ProductTabsApiController::class, 'update'])
    ->whereNumber('id');

Route::delete('/product-tabs/{id}', [ProductTabsApiController::class, 'destroy'])
    ->whereNumber('id');
