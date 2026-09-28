<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Sets  (Lane SET)
|------------------------------------------------------------------------------
|
| The Set: a product type, not a folder of products. A set IS a row in
| `products` with `type = 'set'`, plus rows in `product_set_items` for what is
| in the box. See database/migrations/2027_04_01_000000_sets_schema.php for the
| shape and the first-hand sweep of everything that branches on `products.type`.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/sets                   every set, with its member count
|     POST   /admin-api/sets                   create one
|     GET    /admin-api/sets/products          the member picker's search
|     GET    /admin-api/sets/{id}              one set and its members
|     PUT    /admin-api/sets/{id}              save the fields and the members
|     DELETE /admin-api/sets/{id}              delete it
|
| ── WHAT THE INTEGRATOR MUST WIRE ───────────────────────────────────────────
|
| ONE LINE, inside the EXISTING admin-api group in routes/web.php — the one
| opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| immediately after the catalog require, which is line 667:
|
|     // Catalog → Sets. A set is a `products` row with type='set' plus the
|     // product_set_items pivot; same group as the rest of Catalog.
|     require __DIR__.'/sets-admin.php';
|
| IT MUST GO INSIDE THAT GROUP. These endpoints create, reprice, republish and
| delete products, and GET /admin-api/sets/products lists the whole catalogue by
| name and SKU. Mounted anywhere else they are a public endpoint for rearranging
| the shop. They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| tests/Feature/SetRoutesWiredTest.php pins the FINISHED state — that
| routes/web.php requires this file EXACTLY ONCE. Zero is "built, never wired
| up", which is the shape this repository keeps finding; two registers every
| route twice. It does NOT assert the absence of the require, which is the
| assertion that has cost this project three round trips.
|
| ── ROUTE ORDER: /products BEFORE /{id} ─────────────────────────────────────
|
| {id} is constrained to digits below, so /sets/products could not be read as an
| id either way — and it is still registered first. Both guards, for the reason
| routes/catalog-admin.php gives about /categories/reorder: a 404 on this path
| looks exactly like a feature that was never shipped, and one of the two guards
| being enough is not a reason to have only one.
|
| ── CAPABILITIES. TWO, AND BOTH NEW ─────────────────────────────────────────
|
|   `sets.view`    reading the list, reading one set, searching for members.
|   `sets.manage`  every write: create, save, delete.
|
| NEITHER REUSES `catalog.manage`, which is the whole point of per-capability
| gating: granting somebody the Sets screen must not hand them the product
| editor, the category tree and the brands editor — and the day `catalog.manage`
| is narrowed, which is a reasonable thing to want, this must not narrow with it
| from another file with nothing to notice.
|
| SPLIT IN TWO for the reason `reviews.view` is split from `reviews.manage`:
| reading which sets exist, and repricing and republishing six products at once,
| are different acts.
|
| App\Support\AdminCapabilities::RULES puts THE WRITES ABOVE THE READS, because
| RULES is first-match-wins and a `GET admin-api/sets/**` rule listed first would
| resolve PUT /sets/7 — which reprices a live product — to `sets.view`. That is
| the shape of the quiz-leads and coupons/manage mistakes that file names.
|
| They fail closed with no code here: an admin route the map does not recognise
| resolves to null and EnforceAdminCapability turns null into 403 for everyone
| but the owner. They are mapped anyway, so "owner-only because somebody decided
| so" is on the record rather than "owner-only because nobody mapped it".
|
| ── THE CLEAR-CACHES MIGRATION ──────────────────────────────────────────────
|
| A route added by a package does nothing until the compiled route table is
| gone, so this ships alongside
| database/migrations/2027_04_01_000100_clear_caches_sets.php.
*/

use App\Http\Controllers\Admin\SetApiController;
use Illuminate\Support\Facades\Route;

Route::get('/sets', [SetApiController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('admin.sets.index');

Route::post('/sets', [SetApiController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.sets.store');

/*
 * ▲ ABOVE /sets/{id}. See the note on route order in the header.
 */
Route::get('/sets/products', [SetApiController::class, 'products'])
    ->middleware('throttle:120,1')
    ->name('admin.sets.products');

Route::get('/sets/{id}', [SetApiController::class, 'show'])
    ->where('id', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('admin.sets.show');

Route::put('/sets/{id}', [SetApiController::class, 'update'])
    ->where('id', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('admin.sets.update');

Route::delete('/sets/{id}', [SetApiController::class, 'destroy'])
    ->where('id', '[0-9]+')
    ->middleware('throttle:60,1')
    ->name('admin.sets.destroy');
