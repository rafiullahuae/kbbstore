<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Translation admin API — the Translation parent menu (Lane EP)
|------------------------------------------------------------------------------
|
| MOUNTED. routes/web.php requires this file INSIDE the existing admin-api
| group — the one already carrying `auth:admin` and NoStoreAdminApi — beside
| the other admin requires. What follows is the reasoning behind that mount,
| not a request for one.
|
| IT MUST STAY INSIDE THAT GROUP, and this is not a style preference:
|
|   POST /admin-api/translations/machine/run    SPENDS THE OWNER'S MONEY on a
|                                               third-party API, billed per
|                                               character to his own Google
|                                               Cloud account.
|   POST /admin-api/translations/settings       WRITES THE API KEY.
|   POST /admin-api/translations                writes text the storefront
|                                               renders to every shopper.
|
| Mounted unauthenticated, the first is a way for a stranger to run up a bill on
| the owner's card, and the third is a way to put arbitrary words on his product
| pages. The admin session guard is the only thing between those and the
| internet.
|
| Deliberately NOT in routes/api.php: everything there is unauthenticated by
| design, and CLAUDE.md records that group as having leaked three times.
|
| Resulting paths:
|
|     GET  /admin-api/translations/settings
|     POST /admin-api/translations/settings
|     GET  /admin-api/translations/progress?locale=ar
|     GET  /admin-api/translations/estimate?locale=ar
|     GET  /admin-api/translations?locale=ar&group=ui&missing=1&q=&limit=
|     POST /admin-api/translations
|     POST /admin-api/translations/publish
|     POST /admin-api/translations/machine/field
|     POST /admin-api/translations/machine/run
|
| ORDER MATTERS in this file. /translations/settings, /translations/progress and
| /translations/estimate are static segments and are declared before the bare
| GET /translations, so none of them can be read as a query on the list.
|
| The clear_caches_bilingual_foundation migration ships with this, because on
| this host a route that is not in the compiled table does not exist and there
| is no shell to run route:clear with.
*/

use App\Http\Controllers\Admin\TranslationsApiController;
use Illuminate\Support\Facades\Route;

Route::get('/translations/settings', [TranslationsApiController::class, 'settings']);
Route::post('/translations/settings', [TranslationsApiController::class, 'saveSettings']);

Route::get('/translations/progress', [TranslationsApiController::class, 'progress']);
Route::get('/translations/estimate', [TranslationsApiController::class, 'estimate']);

/*
 * The two that reach a paid third party, throttled.
 *
 * The admin guard already limits these to somebody who can log in, so the
 * throttle is not the primary defence — it is the ceiling on what a stolen
 * admin session, or a retry loop in the admin bundle, can charge to the owner's
 * Google account before anybody notices. A run of 2,000 fields is minutes of
 * work and thousands of characters; twelve a minute is far above any honest
 * use of a button whose result has to be read afterwards.
 *
 * /machine/field is the per-field button beside an Arabic box, so it is pressed
 * far more often and far more cheaply — one short string at a time.
 */
Route::post('/translations/machine/field', [TranslationsApiController::class, 'translateField'])
    ->middleware('throttle:60,1');

Route::post('/translations/machine/run', [TranslationsApiController::class, 'machineRun'])
    ->middleware('throttle:12,1');

Route::post('/translations/publish', [TranslationsApiController::class, 'publish']);

// LAST among the /translations routes: a bare GET and POST, after every static
// segment above.
Route::get('/translations', [TranslationsApiController::class, 'index']);
Route::post('/translations', [TranslationsApiController::class, 'store']);
