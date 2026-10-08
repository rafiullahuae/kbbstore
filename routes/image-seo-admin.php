<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Catalog → Image SEO  (Lane IR)
|------------------------------------------------------------------------------
|
| Required by routes/web.php from inside the admin-api group (the integrator
| adds the line; tools/ir-wire.php applies docs/ir-wiring.json):
|
|     require __DIR__.'/image-seo-admin.php';
|
| Resulting routes, inside `auth:admin` and NoStoreAdminApi:
|
|     GET  /admin-api/image-seo                 filters' lists, rubric, recent runs
|     GET  /admin-api/image-seo/find            Find tab: one page, every picture planned
|     GET  /admin-api/image-seo/job             one run (?id=)
|     POST /admin-api/image-seo/selection       the selection bar's true counts (Lane IS2)
|     POST /admin-api/image-seo/preview         Rename tab preview, 50 products a call (writes nothing to the shop)
|     POST /admin-api/image-seo/start           Rename tab Start: a finished preview becomes a run
|     POST /admin-api/image-seo/step            one bounded slice of a run (~1.5 s), its live progress
|     POST /admin-api/image-seo/stop            pause a run
|     POST /admin-api/image-seo/undo            undo a rename or alt run (confirm=UNDO)
|     POST /admin-api/image-seo/alt-preview     ALT tab dry run
|     POST /admin-api/image-seo/alt-start       ALT tab Apply
|     POST /admin-api/image-seo/score           the score backfill, one batch
|
| Capability `media.image_seo` (AdminCapabilities::RULES), owner and manager,
| failing closed. FLAT PATHS, NO ROUTE PARAMETERS: the job id arrives in the
| body and is cast to an int, the rule urls-media-admin.php states. Nothing is
| chained onto these routes: RouteRegistrar::middleware() replaces rather than
| appends.
|
| Ships with 2027_10_08_120100_clear_caches_image_seo.php, for the compiled
| route table, the cached role map and the Media Library's compiled view; Lane
| IS2's /selection (which replaced /ids) with
| 2027_10_11_100100_clear_caches_image_seo_combined.php.
|
*/

use App\Http\Controllers\Admin\ImageSeoApiController;
use Illuminate\Support\Facades\Route;

Route::get('/image-seo', [ImageSeoApiController::class, 'show'])->name('admin.image-seo');
Route::get('/image-seo/find', [ImageSeoApiController::class, 'find'])->name('admin.image-seo.find');
Route::get('/image-seo/job', [ImageSeoApiController::class, 'job'])->name('admin.image-seo.job');
Route::post('/image-seo/selection', [ImageSeoApiController::class, 'selection'])->name('admin.image-seo.selection');
Route::post('/image-seo/preview', [ImageSeoApiController::class, 'preview'])->name('admin.image-seo.preview');
Route::post('/image-seo/start', [ImageSeoApiController::class, 'start'])->name('admin.image-seo.start');
Route::post('/image-seo/step', [ImageSeoApiController::class, 'step'])->name('admin.image-seo.step');
Route::post('/image-seo/stop', [ImageSeoApiController::class, 'stop'])->name('admin.image-seo.stop');
Route::post('/image-seo/undo', [ImageSeoApiController::class, 'undo'])->name('admin.image-seo.undo');
Route::post('/image-seo/alt-preview', [ImageSeoApiController::class, 'altPreview'])->name('admin.image-seo.alt-preview');
Route::post('/image-seo/alt-start', [ImageSeoApiController::class, 'altStart'])->name('admin.image-seo.alt-start');
Route::post('/image-seo/score', [ImageSeoApiController::class, 'score'])->name('admin.image-seo.score');
