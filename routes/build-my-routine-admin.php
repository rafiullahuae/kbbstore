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

Route::get('/routine-products', [RoutinesApiController::class, 'products']);
Route::post('/routine-products/{id}', [RoutinesApiController::class, 'tag'])
    ->where('id', '[0-9]+');

Route::post('/routines/{concern}', [RoutinesApiController::class, 'saveRoutine'])
    ->where('concern', '[a-z0-9-]+');
