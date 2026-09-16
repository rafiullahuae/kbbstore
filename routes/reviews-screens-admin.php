<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Reviews screens admin API — Export/Import, Badge Themes, Rating Capsule,
| Assign/Duplicate — Lane BE
|------------------------------------------------------------------------------
|
| NOT YET MOUNTED BY THIS LANE. CLAUDE.md forbids this lane from editing
| routes/web.php, so the require line below is the integrator's to add. Until
| they do, nothing in this file serves traffic — the same arrangement Lane AM
| used for routes/reviews-admin.php and Lane BB for
| routes/review-settings-admin.php.
|
| ONE LINE, inside the EXISTING admin-api group in routes/web.php — the one
| opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| immediately after the review-settings require already there:
|
|     // Store -> Reviews -> Export/Import, Badge Themes, Rating Capsule and
|     // Assign/Duplicate: the four screens that were iframes to standalone
|     // files this repo has never shipped.
|     require __DIR__.'/reviews-screens-admin.php';
|
| IT MUST GO INSIDE THAT GROUP, and every one of these routes has its own
| reason:
|
|   /reviews-io/export     streams every reviewer's email address in one file.
|                          CLAUDE.md names `reviews.author_email` as data that
|                          has already leaked from this table in production.
|   /reviews-io/import     writes rows that appear on public product pages and
|                          feed the aggregateRating published to Google.
|                          Unauthenticated, it is a form for filling a stranger's
|                          shop with five-star reviews.
|   /review-badges         changes what every product page draws above the price.
|   /review-assign/*       lists unapproved reviews — a public directory of them
|                          — and moves them between products.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| WHY THE PATHS AVOID THE /reviews/ PREFIX ENTIRELY.
|
| routes/web.php already registers, BEFORE this file is required:
|
|     Route::put('/reviews/{id}', [AdminController::class, 'updateReview']);
|
| with no numeric constraint on {id}, and updateReview() types that parameter
| `int` under strict_types. Laravel dispatches the FIRST matching route, so any
| PUT this file registered under /reviews/<word> would never arrive: it would
| match that route with $id = 'settings'-shaped garbage and die with a TypeError
| and a 500. Lane BB measured exactly that — nineteen tests, one error — and
| moved its own screen to /review-settings for it.
|
| The same lesson, applied before it costs anything: `reviews-io`,
| `review-badges` and `review-assign` are distinct first segments, so no route
| here can be swallowed by that one or by `GET /reviews`, and none of them can
| swallow it either.
|
| Resulting paths:
|
|     GET    /admin-api/reviews-io/summary          what is here to export
|     GET    /admin-api/reviews-io/export           re-importable CSV
|     POST   /admin-api/reviews-io/import           upload; mode=check|import
|
|     GET    /admin-api/review-badges               the seven badge settings
|     PUT    /admin-api/review-badges               save
|     POST   /admin-api/review-badges/theme         apply a preset
|
|     GET    /admin-api/review-assign/reviews       find reviews to act on
|     GET    /admin-api/review-assign/products      find a destination product
|     POST   /admin-api/review-assign/move          move them
|     POST   /admin-api/review-assign/copy          copy them
|
| ORDER MATTERS WITHIN THIS FILE TOO. `/review-badges/theme` is registered
| BEFORE nothing that could swallow it — there is no `/review-badges/{x}` here
| at all, deliberately, so the literal cannot be shadowed by a later edit that
| adds one without noticing.
|
| AND A ROUTE CACHE NOTE. This adds routes, so the package that ships it also
| ships database/migrations/..._clear_caches_reviews_screens.php. Without it the
| compiled route table on the server does not know these paths and all four
| screens answer 404 while every file is present and correct — the failure
| CLAUDE.md names first.
*/

use App\Http\Controllers\Admin\ReviewAssignApiController;
use App\Http\Controllers\Admin\ReviewBadgeApiController;
use App\Http\Controllers\Admin\ReviewsIoApiController;
use Illuminate\Support\Facades\Route;

/* Reviews -> Export / Import */
Route::get('/reviews-io/summary', [ReviewsIoApiController::class, 'summary']);
Route::get('/reviews-io/export', [ReviewsIoApiController::class, 'export']);
Route::post('/reviews-io/import', [ReviewsIoApiController::class, 'import']);

/* Reviews -> Badge Themes and Reviews -> Rating Capsule (one set of settings) */
Route::get('/review-badges', [ReviewBadgeApiController::class, 'show']);
Route::put('/review-badges', [ReviewBadgeApiController::class, 'update']);
Route::post('/review-badges/theme', [ReviewBadgeApiController::class, 'applyTheme']);

/* Reviews -> Assign / Duplicate */
Route::get('/review-assign/reviews', [ReviewAssignApiController::class, 'reviews']);
Route::get('/review-assign/products', [ReviewAssignApiController::class, 'products']);
Route::post('/review-assign/move', [ReviewAssignApiController::class, 'move']);
Route::post('/review-assign/copy', [ReviewAssignApiController::class, 'copy']);
