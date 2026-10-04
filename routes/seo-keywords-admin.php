<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Store → SEO Keywords  (Lane KW)
|------------------------------------------------------------------------------
|
| HOW THIS FILE IS MOUNTED. One line in routes/web.php, inside the EXISTING
| admin-api group — the group that already carries `web`, `auth:admin` and
| NoStoreAdminApi — beside the other requires:
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|         ...
|         require __DIR__ . '/seo-keywords-admin.php';
|     });
|
| CLAUDE.md forbids a lane from editing routes/web.php, so the integrator adds
| that line. SeoKeywordsAdminTest pins the FINISHED state (the require present
| at most once — and exactly once after wiring) and registers the group itself
| for its own requests, the way tests/Support/SeoBackOfficeRoutes does.
|
| NEVER /api/*. The bank is a competitor's keyword plan and a sync step is
| real work on a shared host.
|
| CAPABILITIES (App\Support\AdminCapabilities): every GET is
| seo_keywords.view (owner, manager); every write — settings, the Search
| Console key, editing a page, applying a title, starting, stepping and undoing
| a sync — is seo_keywords.sync, owner only. Unmapped would fail closed to
| owner-only anyway; mapped, a manager can read the screen.
|
| THROTTLES. A sync step does up to twelve seconds of work and the screen calls
| it back to back, so 40 a minute is above any honest pace and far below one
| that hurts. Starting a sync and testing Search Console are rarer still.
*/

use App\Http\Controllers\Admin\SeoKeywordsApiController as K;
use Illuminate\Support\Facades\Route;

Route::get('/seo-keywords', [K::class, 'overview'])->middleware('throttle:60,1');
Route::get('/seo-keywords/bank', [K::class, 'bank'])->middleware('throttle:120,1');
Route::get('/seo-keywords/pages', [K::class, 'pages'])->middleware('throttle:120,1');
Route::put('/seo-keywords/pages', [K::class, 'savePage'])->middleware('throttle:60,1');
Route::post('/seo-keywords/apply', [K::class, 'apply'])->middleware('throttle:60,1');
Route::put('/seo-keywords/settings', [K::class, 'saveSettings'])->middleware('throttle:30,1');
Route::put('/seo-keywords/gsc', [K::class, 'saveGsc'])->middleware('throttle:10,1');
Route::delete('/seo-keywords/gsc', [K::class, 'forgetGsc'])->middleware('throttle:10,1');
Route::post('/seo-keywords/gsc/test', [K::class, 'testGsc'])->middleware('throttle:6,1');
Route::post('/seo-keywords/sync', [K::class, 'startSync'])->middleware('throttle:10,1');
Route::post('/seo-keywords/sync/{run}/step', [K::class, 'step'])->whereNumber('run')->middleware('throttle:40,1');
Route::post('/seo-keywords/undo', [K::class, 'undo'])->middleware('throttle:6,1');
