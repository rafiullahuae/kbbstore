<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Appearance → Product grids  (Lane GS — Phase 23)
|------------------------------------------------------------------------------
|
| ONE reusable homepage product-grid section, used as many times as the owner
| likes. `app/Services/GridSections.php` is the module and carries the design
| argument; `database/migrations/2027_05_11_000000_create_grid_sections_table.php`
| carries the data decisions.
|
| Resulting paths, all under the existing admin-api prefix:
|
|     GET    /admin-api/grid-sections                     the screen's payload
|     POST   /admin-api/grid-sections                     create one (optionally from a preset)
|     POST   /admin-api/grid-sections/reorder             the instances among themselves
|     GET    /admin-api/grid-sections/products            the catalogue, for the manual picker
|     GET    /admin-api/grid-sections/{grid}              one instance and its controls
|     PUT    /admin-api/grid-sections/{grid}              save it
|     POST   /admin-api/grid-sections/{grid}/duplicate    copy it, as a draft
|     DELETE /admin-api/grid-sections/{grid}              delete it
|     GET    /admin-api/grid-sections/{grid}/preview      as the shop draws it
|     POST   /admin-api/grid-sections/{grid}/preview      from the UNSAVED buffer
|
| THIS FILE IS REQUIRED FROM routes/web.php BY THE INTEGRATOR, inside the
| existing admin-api group — the one that already carries `web`, `auth:admin`
| and NoStoreAdminApi — beside the other Appearance route files. The exact line,
| and it belongs next to the `banners-admin.php` require:
|
|     require __DIR__.'/grid-sections-admin.php';
|
| The guarded group is the security model and not a preference. CLAUDE.md:
| "/api/* is unauthenticated." Not one of these endpoints could live there —
| every one of them writes what the front page of the shop shows, the picker
| reads the catalogue, and the preview renders a storefront template from a
| draft the owner has not saved.
|
| ── ROUTE ORDER, AND THE TWO LITERALS THAT MUST COME FIRST ──────────────────
|
| `/grid-sections/reorder` and `/grid-sections/products` are LITERAL segments in
| the same position as `{grid}`. Laravel matches in registration order, so both
| are registered ABOVE the parameterised routes — and `{grid}` additionally
| carries `->whereNumber('grid')`, so neither word could match it even if the
| order were lost in a merge. Two guards rather than one, because the failure is
| silent: `POST /grid-sections/reorder` falling through to `{grid}` would 404 on
| a model called "reorder" and read to the owner as a broken button.
|
| ── THE CAPABILITY, AND WHY IT IS TWO AND NOT ONE ──────────────────────────
|
| `App\Support\AdminCapabilities` gains `gridsections.view` and
| `gridsections.manage`, and the RULES block names the WRITES ABOVE THE READS
| because that file is first-match-wins. Listed the other way round,
| `GET admin-api/grid-sections/**` would resolve every one of these paths and a
| read capability would be enough to delete an instance.
|
| It fails CLOSED by construction: `AdminCapabilities::for()` returns null for a
| route it does not recognise and `EnforceAdminCapability` turns a null into a
| 403 for everyone who is not an owner. A path added here tomorrow and never
| mapped is owner-only, not open.
|
| ── THROTTLES ──────────────────────────────────────────────────────────────
|
| `throttle:60,1` throughout, matching every other admin screen in this console,
| and `120,1` on the draft preview alone — the screen debounces a slider drag to
| 260ms and sixty a minute is a limit a legitimate drag can reach. Nothing here
| touches a third party and nothing here is expensive: the preview renders one
| Blade partial over at most two queries.
|
| ── AND CLEARING THE ROUTE CACHE IS NOT OPTIONAL ───────────────────────────
|
| A route added here does not exist until the compiled route table is rebuilt
| (CLAUDE.md). This round therefore ships
| database/migrations/2027_05_11_000100_clear_caches_grid_sections.php.
*/

use App\Http\Controllers\Admin\GridSectionApiController;
use Illuminate\Support\Facades\Route;

/* ── the two literal segments, above everything parameterised ───────────── */

Route::post('/grid-sections/reorder', [GridSectionApiController::class, 'reorder'])
    ->middleware('throttle:60,1')
    ->name('admin.gridsections.reorder');

Route::get('/grid-sections/products', [GridSectionApiController::class, 'products'])
    ->middleware('throttle:60,1')
    ->name('admin.gridsections.products');

/* ── one instance ───────────────────────────────────────────────────────── */

Route::post('/grid-sections/{grid}/duplicate', [GridSectionApiController::class, 'duplicate'])
    ->middleware('throttle:60,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.duplicate');

Route::get('/grid-sections/{grid}/preview', [GridSectionApiController::class, 'preview'])
    ->middleware('throttle:60,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.preview');

/*
 * The SAME path as a POST: the section drawn from the editor's unsaved buffer.
 *
 * A POST rather than a GET with a body, so it is `admin-api/grid-sections/**`
 * under the WRITE half of AdminCapabilities' first-match-wins list and
 * therefore `gridsections.manage`. That is the stricter of the two and it is
 * the right one: the payload is a draft of what the instance is about to
 * become, and a reader who may not write the instance has no business composing
 * one.
 */
Route::post('/grid-sections/{grid}/preview', [GridSectionApiController::class, 'previewDraft'])
    ->middleware('throttle:120,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.preview.draft');

Route::get('/grid-sections/{grid}', [GridSectionApiController::class, 'show'])
    ->middleware('throttle:60,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.show');

Route::put('/grid-sections/{grid}', [GridSectionApiController::class, 'update'])
    ->middleware('throttle:60,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.update');

Route::delete('/grid-sections/{grid}', [GridSectionApiController::class, 'destroy'])
    ->middleware('throttle:60,1')
    ->whereNumber('grid')
    ->name('admin.gridsections.destroy');

/* ── the list ───────────────────────────────────────────────────────────── */

Route::get('/grid-sections', [GridSectionApiController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('admin.gridsections');

Route::post('/grid-sections', [GridSectionApiController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('admin.gridsections.store');
