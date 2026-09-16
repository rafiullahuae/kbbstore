<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → Import / Export  (Lane AD)
|------------------------------------------------------------------------------
|
| WIRED. This file is required from routes/web.php (or routes/api.php) and
| its routes serve live traffic. The wiring note below is kept as the
| record of where that require belongs.
|
| CLAUDE.md forbade this lane from editing routes/web.php, so the
| file shipped unmounted and the integrator added ONE line, inside the EXISTING
| admin-api group in routes/web.php — the group that already carries
| `auth:admin` and NoStoreAdminApi — beside the other requires (next to
| `require __DIR__.'/catalog-admin.php';` is a fine place):
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__.'/import-admin.php';
|     });
|
| THAT GROUP, AND NOTHING ELSE. This is not a style preference; each endpoint
| below is one of the three most dangerous shapes an unauthenticated route can
| have:
|
|   POST /import/upload   WRITES A CALLER-SUPPLIED FILE TO THE SERVER'S DISK.
|   POST /import/step     REWRITES THE CATALOGUE, THE CUSTOMER LIST AND THE
|                         ORDER HISTORY from that file.
|   GET  /import/rejects  HANDS BACK REFUSED ROWS — which quote customer email
|                         addresses, phone numbers and street addresses.
|
| Mounted anywhere unauthenticated, that is an anonymous write endpoint for the
| whole store plus a customer-data download. routes/api.php is unauthenticated
| by design in this application (CLAUDE.md), so these must never go there.
| tests/Feature/AdminImportScreenTest.php mounts this file exactly as the block
| above describes and asserts a 401 on every route in it.
|
| Resulting paths:
|
|     GET    /admin-api/import/status     everything the screen draws itself from
|     POST   /admin-api/import/upload     one or more CSV exports
|     POST   /admin-api/import/forget     drop one uploaded file
|     POST   /admin-api/import/start      begin a preview or a real import
|     POST   /admin-api/import/step       do one slice; called repeatedly
|     POST   /admin-api/import/stop       put the run down, undoing nothing
|     POST   /admin-api/import/reset      discard this screen's checkpoints
|     GET    /admin-api/import/rejects    every refused row, as a CSV download
|
| FLAT PATHS UNDER /import/, never /import on its own. routes/web.php registers
| a lot under admin-api and this lane cannot see all of it at once; a single
| segment is the kind of name that collides. Every path here carries a literal
| second segment, and none of them takes a route parameter at all — the entity
| a call applies to arrives in the body, validated against a fixed list of six,
| so no path segment is ever built from anything a caller sent.
|
| WHY step IS A POST WITH NO SIDE-EFFECT-FREE GET EQUIVALENT. It writes. A GET
| that imports rows would be fetched by a link prefetcher, a browser's history
| restore, or the host's own cache warmer, and each of those would run an import
| slice nobody asked for.
|
*/

use App\Http\Controllers\Admin\ImportApiController;
use Illuminate\Support\Facades\Route;

Route::get('/import/status', [ImportApiController::class, 'status']);
Route::get('/import/rejects', [ImportApiController::class, 'rejects']);

Route::post('/import/upload', [ImportApiController::class, 'upload']);
Route::post('/import/forget', [ImportApiController::class, 'forget']);
Route::post('/import/start', [ImportApiController::class, 'start']);
Route::post('/import/stop', [ImportApiController::class, 'stop']);
Route::post('/import/reset', [ImportApiController::class, 'reset']);

/*
 * The one endpoint that runs for a long time and writes while it does.
 *
 * Throttled at 120 a minute per admin session: far above anything the screen
 * asks for (a step takes seconds, so the browser cannot issue more than a
 * handful a minute), and low enough that a stuck retry loop in the console —
 * or a tab left open on a broken run — cannot turn into an unbounded stream of
 * import slices against the live database.
 */
Route::post('/import/step', [ImportApiController::class, 'step'])
    ->middleware('throttle:120,1');
