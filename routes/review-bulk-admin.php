<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Review bulk-tools admin API — Reviews → Bulk Add, Reviews → Bulk Likes — Lane BD
|------------------------------------------------------------------------------
|
| HOW THIS FILE REACHES LIVE TRAFFIC. CLAUDE.md forbids this lane from editing
| routes/web.php, so the require line is the integrator's to add. ONE LINE,
| inside the EXISTING admin-api group in routes/web.php — the one opened by
|
|     Route::prefix('admin-api')->middleware(\App\Http\Middleware\NoStoreAdminApi::class)->group(function () {
|
| which itself sits inside the `Route::middleware('auth:admin')` group. Put it
| beside the two review requires already there:
|
|     // Store -> Reviews -> Bulk Add and Bulk Likes. On /admin-api/review-bulk/*,
|     // NOT under /reviews/, for the reason set out in this file's header.
|     require __DIR__.'/review-bulk-admin.php';
|
| IT MUST GO INSIDE THAT GROUP, and the reason is the WRITE side rather than the
| read. These endpoints create rows in `reviews` and raise the "helpful" counter
| on them. Mounted outside `auth:admin` that is an anonymous stranger writing
| arbitrary text onto any product page on the storefront — and, because those
| rows feed Store\ProductController::reviewSummary() and from there the
| schema.org aggregateRating in App\Support\Seo, writing it into the structured
| data Google reads for this shop as well.
|
| They are deliberately NOT in routes/api.php: everything there is
| unauthenticated by design (CLAUDE.md, "/api/* is unauthenticated").
|
| WHY THE PATHS ARE /review-bulk/* AND NOT /reviews/bulk-*.
|
| Not a style choice. routes/web.php already registers, BEFORE this file is
| required:
|
|     Route::get('/reviews',       [AdminController::class, 'reviews']);
|     Route::put('/reviews/{id}',  [AdminController::class, 'updateReview']);
|     Route::post('/reviews/bulk', [AdminController::class, 'bulkReviews']);
|
| `{id}` there carries NO numeric constraint and updateReview() types the
| parameter `int` under strict_types, so any PUT of the shape
| /admin-api/reviews/<word> matches that route first and dies with a TypeError
| and a 500 rather than reaching the controller it was aimed at. Lane BB
| measured exactly that — nineteen tests failing on it — and moved Review
| Settings to /admin-api/review-settings for the same reason. This file stays
| outside the /reviews/ prefix so that it cannot be caught by that route, or by
| the next one somebody adds to it, in either registration order, cached or not.
|
| Resulting paths:
|
|     GET   /admin-api/review-bulk/options    products to aim at, bounds, statuses
|     POST  /admin-api/review-bulk/add        create reviews in bulk
|     POST  /admin-api/review-bulk/likes      raise `helpful` in bulk
|
| AND A ROUTE CACHE NOTE. This adds three routes, so the package that ships it
| also ships database/migrations/2026_10_10_000000_clear_caches_review_bulk.php.
| Without it the compiled route table on the server does not know these paths
| and both screens answer 404 while every file is present and correct — the
| failure CLAUDE.md names first.
*/

use App\Http\Controllers\Admin\ReviewBulkApiController;
use Illuminate\Support\Facades\Route;

Route::get('/review-bulk/options', [ReviewBulkApiController::class, 'options']);
Route::post('/review-bulk/add', [ReviewBulkApiController::class, 'add']);
Route::post('/review-bulk/likes', [ReviewBulkApiController::class, 'likes']);
