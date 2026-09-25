<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Build my routine — the admin API. Lane FM
|------------------------------------------------------------------------------
|
| The screen is resources/views/admin/partials/routines-screen.blade.php and the
| controller is App\Http\Controllers\Admin\RoutinesApiController.
|
| CLAUDE.md forbids this lane from editing routes/web.php, so the integrator
| wires it up. This note is the record of where that one line belongs.
|
| INTEGRATOR — ONE LINE, and it must go INSIDE the existing admin-api group in
| routes/web.php: the group opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside `Route::middleware('auth:admin')`. Put it directly
| after the product-editor require:
|
|     // Catalog → Build my routine: the role tagging, the routines and the
|     // module's settings. Same guarded group as the rest of admin-api.
|     require __DIR__.'/build-my-routine-admin.php';
|
| Resulting paths:
|
|     GET  /admin-api/routines                  the whole screen in one request
|     POST /admin-api/routines-settings         the module's settings
|     POST /admin-api/routines/{concern}        one routine's overrides
|     GET  /admin-api/routine-products          the tagging table: q, role, page
|     POST /admin-api/routine-products/{id}     tag one product
|
| WHY THE GROUP MATTERS. GET /admin-api/routine-products lists every product in
| the catalogue INCLUDING drafts and anything scheduled for a future date, with
| its SKU — the same inventory-of-the-shop exposure the Media Library note
| already argues, one table along. Not in routes/api.php for the reason
| CLAUDE.md states in one line: "/api/* is unauthenticated".
|
| CAPABILITIES. App\Support\AdminCapabilities::RULES gains five rows in this
| branch, writes above reads, because the wildcard read would otherwise be
| reached first and an `editor` on catalog.view could retag the catalogue. They
| are catalog.view / catalog.manage: this is merchandising — which product
| fills which step, and which coupon the strip names. The switch that can take
| the pages off the storefront is the module toggle on Store → Modules, which
| is store.settings and is not touched here.
|
| NAMING. `routines-settings` is a sibling path, not `routines/settings`, so it
| cannot collide with `routines/{concern}` however the two are ordered — the
| same shape ProductEditorApiController's `product-editor-*` family uses and for
| the same reason. No other route file claims either prefix; checked against
| every Route:: line in routes/.
|
| THE CACHE. database/migrations/2026_11_18_000002_clear_caches_build_my_routine.php
| ships with the package. On this host a route missing from the compiled table
| does not exist, and the screen would paint and then 404 on its first fetch.
*/

use App\Http\Controllers\Admin\RoutinesApiController;
use Illuminate\Support\Facades\Route;

Route::get('/routines', [RoutinesApiController::class, 'show']);
Route::post('/routines-settings', [RoutinesApiController::class, 'saveSettings']);

/*
 * The module switch, surfaced on the screen where the work is done — Lane Q,
 * round 3. `routines-module` is a SIBLING path, not `routines/module`, for the
 * reason the header gives about `routines-settings`: it cannot collide with
 * `routines/{concern}` however the two are ordered.
 *
 * It writes the same `module_toggles` key Store → Modules writes, through the
 * same SettingsService::setModule(). It carries its own capability rule,
 * store.settings rather than the catalog.* of everything else in this file,
 * because it publishes pages rather than merchandising them — see the
 * controller method's note. A clear_caches migration ships with it:
 * database/migrations/2026_12_27_000000_clear_caches_routine_module_switch.php.
 */
Route::post('/routines-module', [RoutinesApiController::class, 'saveModule']);

Route::get('/routine-products', [RoutinesApiController::class, 'products']);
Route::post('/routine-products/{id}', [RoutinesApiController::class, 'tag'])
    ->where('id', '[0-9]+');

/*
 * ── BULK TAGGING — Lane Q4 ───────────────────────────────────────────────────
 *
 * INTEGRATOR: this is the ONE new route in this round. It is inside the file
 * you already require, so there is NO new line in routes/web.php — the require
 * named in this file's header covers it. The exact line, unchanged:
 *
 *     require __DIR__.'/build-my-routine-admin.php';
 *
 * Resulting path:
 *
 *     POST /admin-api/routine-products-bulk    tag several products at once
 *
 * A SIBLING PATH, NOT `routine-products/bulk`, and the reason is this file's
 * own header: `routine-products/{id}` is constrained to `[0-9]+` so a literal
 * `bulk` segment could not be captured by it today — but a later lane relaxing
 * that constraint would silently turn this endpoint into a product id, and the
 * `routines-settings` / `routines-module` naming above exists so that ordering
 * can never be load-bearing. Same shape, same reason.
 *
 * CAPABILITY. Its own row in App\Support\AdminCapabilities::RULES, written
 * ABOVE the `routine-products/*` row that precedes it there:
 *
 *     ['POST', 'admin-api/routine-products-bulk', 'catalog.manage'],
 *
 * catalog.manage and not something new, because this is not a new power: it is
 * `POST /admin-api/routine-products/{id}` applied to a set, writing the same
 * two columns through the same vocabulary, and a role that may retag one
 * product may retag twenty. It does NOT get catalog.view — the wildcard read
 * below it would otherwise be reachable and an `editor` on view alone could
 * retag the catalogue twenty-five rows at a time, which is the exact hazard
 * the block's "writes above reads" note was written for.
 *
 * With that row missing the endpoint is OWNER-ONLY, not open —
 * AdminCapabilities::for() returns null for a route it does not recognise and
 * EnforceAdminCapability turns null into a 403. RoutineTaggingBulkTest pins
 * both halves.
 *
 * THE CACHE. database/migrations/2027_01_02_000000_clear_caches_routine_bulk_tagging.php
 * ships with the package: on this host a route missing from the compiled table
 * does not exist, and the screen would paint a selection bar whose first press
 * 404s.
 */
Route::post('/routine-products-bulk', [RoutinesApiController::class, 'bulk']);

Route::post('/routines/{concern}', [RoutinesApiController::class, 'saveRoutine'])
    ->where('concern', '[a-z0-9-]+');
